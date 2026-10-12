import { test, expect, type Page } from '@playwright/test'
import { recordResponses } from './record-responses'

/**
 * US4 transaction-correction journeys (T076): manager/senior/finance return
 * and corrected full-path restart; correcting a finance-approved transaction
 * is forbidden (FR-046).
 *
 * Conventions follow management-review.spec.ts: title-scoped rows, NcSelect
 * options by ARIA role, waiting on rows rather than fixed delays. Each test
 * builds its own sheet so journeys never depend on seeding order.
 *
 * Row resolution is deterministic: every journey resolves its own transaction
 * through the queue APIs by `sheetId` and opens exactly that row
 * (`queue-row-<id>`). Queues legitimately carry other users' and earlier
 * runs' rows, so first-row selection would drive a stranger's transaction.
 */

async function pickFirstOption(page: Page): Promise<void> {
	const listbox = page.getByRole('listbox').last()
	await expect(listbox, 'the select must open a listbox of options').toBeVisible()
	await listbox.getByRole('option').first().click()
}

async function pickOptionNamed(page: Page, name: RegExp): Promise<void> {
	const listbox = page.getByRole('listbox').last()
	await expect(listbox, 'the select must open a listbox of options').toBeVisible()
	await listbox.getByRole('option', { name }).click()
}

async function createAndSubmitSheet(page: Page, title: string): Promise<void> {
	await page.goto('/apps/pettycash/#/sheets')
	await page.click('[data-test="create-sheet-button"]')
	await page.fill('[data-test="sheet-title-input"]', title)
	await page.click('[data-test="sheet-account-select"]')
	await pickFirstOption(page)
	await page.click('[data-test="sheet-create-submit"]')
	await page.locator('[data-test="sheet-list-item"]:visible', { hasText: title }).first().click()
	await page.waitForSelector('[data-test="sheet-detail-view"]')
	await page.click('[data-test="create-transaction-btn"]')
	await page.fill('[data-test="expense-date-input"]', '2026-10-01')
	await page.fill('[data-test="amount-input"]', '50000')
	await page.fill('[data-test="description-input"]', 'T076 correction probe')
	await page.click('[data-test="cost-center-select"]')
	await pickOptionNamed(page, /E2E Cost/)
	await page.click('[data-test="expense-type-select"]')
	await pickFirstOption(page)
	await page.click('[data-test="transaction-save-draft"]')
	await page.click('[data-test="submit-sheet-button"]')
}

async function decide(page: Page, action: 'APPROVE' | 'REJECT' | 'RETURN', explanation: string): Promise<void> {
	await page.click('[data-testid="decision-select"]')
	const options = page.getByRole('option')
	const count = await options.count()
	let picked = false
	for (let i = 0; i < count; i++) {
		const text = ((await options.nth(i).innerText()) || '').toUpperCase()
		const FA = { APPROVE: ['APPROVE', 'تأیید'], REJECT: ['REJECT', 'رد'], RETURN: ['RETURN', 'بازگشت'] } as const
		if (FA[action].some((s) => text.includes(s))) {
			await options.nth(i).click()
			picked = true
			break
		}
	}
	expect(picked, `decision option ${action} must exist`).toBe(true)
	await page.fill('[data-testid="explanation-input"]', explanation)
	await page.click('[data-testid="submit-decision"]')
}

async function ocs(page: Page, method: string, path: string, body?: unknown, commandId?: string) {
	return page.evaluate(async ({ method, path, body, commandId }) => {
		const headers: Record<string, string> = { 'OCS-APIRequest': 'true', Accept: 'application/json', 'Content-Type': 'application/json' }
		if (commandId !== undefined) headers['X-PettyCash-Command-ID'] = commandId
		const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1${path}`, {
			method,
			headers,
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		const json = await response.json().catch(() => null)
		return { status: response.status, json }
	}, { method, path, body, commandId })
}

/** Our transaction's id at one review stage, matched by sheet. Searches every
 * page: the shared queues legitimately hold dozens of rows. */
async function ownTxAtStage(page: Page, stage: string, sheetId: string): Promise<string> {
	for (let i = 0; i < 12; i++) {
		let pageNo = 1
		for (;;) {
			const queue: any = await ocs(page, 'GET', `/queues/${stage}?page=${pageNo}&pageSize=100`)
			const items = ((queue?.json?.ocs?.data?.items ?? []) as any[])
			const found = items.find((t) => t.sheetId === sheetId)
			if (found?.id) return found.id as string
			if (items.length < 100) break
			pageNo++
		}
		await page.waitForTimeout(500)
	}
	throw new Error(`no ${stage}-queue transaction for sheet ${sheetId}`)
}

async function sheetIdByTitle(page: Page, title: string): Promise<string> {
	for (let i = 0; i < 12; i++) {
		const res: any = await ocs(page, 'GET', '/sheets?page=1&pageSize=100')
		const found = ((res?.json?.ocs?.data?.items ?? []) as any[]).find((s) => s.title === title)
		if (found?.id) return found.id as string
		await page.waitForTimeout(500)
	}
	throw new Error(`sheet titled "${title}" never appeared in the sheet list`)
}

/** Attaches one receipt to a returned (upload-eligible) transaction. */
async function uploadEvidence(page: Page, txId: string): Promise<void> {
	const done: { status: number, json: any } = await page.evaluate(async ({ txId }) => {
		const body = new FormData()
		body.append('file', new Blob(['T076 evidence bytes'], { type: 'text/plain' }), 't076-receipt.txt')
		const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1/transactions/${txId}/evidence`, {
			method: 'POST',
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json', 'X-PettyCash-Command-ID': `t076-ev-${Date.now()}` },
			body,
		})
		return { status: response.status, json: await response.json().catch(() => null) }
	}, { txId })
	expect(done.status, 'evidence upload must be accepted: ' + JSON.stringify(done.json)).toBeLessThan(300)
	expect(done.json?.ocs?.data?.id, 'upload must return the evidence id').not.toBe('')
}

/** Opens exactly the queue row for one transaction id (table or card layout),
 * walking the view's pager: the row may sit beyond page one. */
async function transactionStage(page: Page, id: string): Promise<string> {
	const res: any = await ocs(page, 'GET', `/transactions/${id}`)
	return res?.json?.ocs?.data?.stage ?? 'UNKNOWN'
}

/** Records a UI decision, then fails fast unless the stage actually advanced. */
async function decideAndAdvance(page: Page, txId: string, action: 'APPROVE' | 'REJECT' | 'RETURN', fromStage: string): Promise<void> {
	await decide(page, action, `T076 ${action} ${Date.now()}`)
	for (let i = 0; i < 12; i++) {
		const stage = await transactionStage(page, txId)
		if (stage !== fromStage && stage !== 'UNKNOWN') return
		await page.waitForTimeout(500)
	}
	const res: any = await ocs(page, 'GET', `/transactions/${txId}`)
	throw new Error(`UI ${action} on ${txId} never left stage ${fromStage}: ` + JSON.stringify(res?.json?.ocs?.data ?? res?.json).slice(0, 300))
}

async function openQueueRow(page: Page, navTestId: string, txId: string, detailSelector = '[data-testid="transaction-detail"]'): Promise<void> {
	await page.click(`[data-test="${navTestId}"]`)
	await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
	for (let p = 0; p < 10; p++) {
		const row = page.locator(`[data-testid="queue-row-${txId}"]:visible`)
		if (await row.count() > 0) {
			await row.locator('[data-testid="view-details-btn"]').click()
			await page.waitForSelector(detailSelector)
			return
		}
		const card = page.locator(`[data-testid="queue-card-${txId}"]:visible`)
		if (await card.count() > 0) {
			await card.locator('[data-testid="view-details-btn"]').click()
			await page.waitForSelector(detailSelector)
			return
		}
		const next = page.locator('[data-testid="next-page-btn"]')
		if ((await next.count()) === 0 || await next.isDisabled()) break
		await next.click()
		await page.waitForTimeout(800)
	}
	throw new Error(`queue row for transaction ${txId} not found on any page`)
}

const STAGE_FOR: Record<string, string> = { 'cost-center': 'COST_CENTER', senior: 'SENIOR', finance: 'FINANCE' }

async function decideOwn(page: Page, navTestId: string, stage: string, sheetId: string, action: 'APPROVE' | 'REJECT' | 'RETURN', explanation: string): Promise<void> {
	const txId = await ownTxAtStage(page, stage, sheetId)
	await openQueueRow(page, navTestId, txId)
	await page.waitForSelector('[data-testid="decision-form"]')
	await decide(page, action, explanation)
	const fromStage = STAGE_FOR[stage] as string
	for (let i = 0; i < 12; i++) {
		const now = await transactionStage(page, txId)
		if (now !== fromStage && now !== 'UNKNOWN') return
		await page.waitForTimeout(500)
	}
	throw new Error(`UI ${action} on ${txId} never left stage ${fromStage} (deciding as ${explanation})`)
}

/** FR-073: count every response this spec's browser sees, so the error-rate
 * figure covers the whole end-to-end suite and not only the specs that
 * happened to opt in. Attached per page rather than in `global-setup` so it
 * stays visible at the call site. No-op unless PETTYCASH_REQUEST_LOG is set.
 */
test.beforeEach(async ({ page }) => {
	recordResponses(page)
})

test.describe('transaction correction journeys', () => {
	test('a manager return lands in the returns queue with revision history intact', async ({ page }) => {
		const title = `T076 ManagerReturn ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createAndSubmitSheet(page, title)
		const sheetId = await sheetIdByTitle(page, title)

		await decideOwn(page, 'nav-review-cost-center', 'cost-center', sheetId, 'RETURN', 'T076 manager return: receipt unclear')

		await page.click('[data-test="nav-returns"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
		const returns: any = await ocs(page, 'GET', '/returns')
		const items = (returns?.json?.ocs?.data?.items ?? returns?.json?.ocs?.data ?? []) as any[]
		const ours = (Array.isArray(items) ? items : []).filter((t) => t.sheetId === sheetId)
		expect(ours.length, 'our returned transaction must be in the returns queue').toBeGreaterThan(0)
	})

	test('a senior return after cost-centre approval restarts the full path', async ({ page }) => {
		const title = `T076 SeniorReturn ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createAndSubmitSheet(page, title)
		const sheetId = await sheetIdByTitle(page, title)

		await decideOwn(page, 'nav-review-cost-center', 'cost-center', sheetId, 'APPROVE', 'cost centre approves')
		await decideOwn(page, 'nav-review-senior', 'senior', sheetId, 'RETURN', 'T076 senior return: amount disputed')

		// The purchaser corrects and resubmits: a new active revision re-enters
		// cost-centre review with prior revisions retained. The returns queue
		// opens the *review* detail (decision UI, no correction control); the
		// correction control lives on the purchaser's own transaction detail,
		// so the journey goes through both surfaces.
		const returns: any = await ocs(page, 'GET', '/returns')
		const items = (returns?.json?.ocs?.data?.items ?? returns?.json?.ocs?.data ?? []) as any[]
		const ours = (Array.isArray(items) ? items : []).find((t) => t.sheetId === sheetId)
		expect(ours?.id, 'our returned transaction must be resolvable').not.toBe('')
		// Resubmission requires active evidence on the transaction
		// (transaction-scoped per data-model.md:248), so attach it while the
		// transaction is returned and upload-eligible.
		await uploadEvidence(page, ours.id as string)
		await page.click('[data-test="nav-returns"]')
		await page.waitForSelector(`[data-testid="queue-row-${ours.id}"]:visible, [data-testid="queue-card-${ours.id}"]:visible`)
		// The returns queue opens the *review* detail (no correction
		// control), so the purchaser reaches their own transaction through
		// the sheet: one transaction per sheet here, hence the first row.
		// The purchaser corrects through the edit form twice: first the
		// filled draft is SAVED (the PATCH creates revision 2 as the working
		// revision), then, back on the transaction through the sheet, the
		// saved draft is RESUBMITTED (activating revision 2 and restarting
		// the full path). Saving alone routes back to the sheet; resubmitting
		// an unsaved fill would merely re-activate revision 1.
		const openOwnTransaction = async () => {
			await page.goto('/apps/pettycash/#/sheets')
			await page.locator('[data-test="sheet-list-item"]:visible', { hasText: title }).first().click()
			await page.waitForSelector('[data-test="sheet-detail-view"]')
			await page.locator('[data-test="view-transaction"]:visible').first().click()
			await page.waitForSelector('[data-test="transaction-detail-view"]')
		}
		await openOwnTransaction()
		await page.click('[data-test="create-correction-btn"]')
		await page.fill('[data-test="description-input"]', 'T076 corrected description')
		await page.click('[data-test="transaction-save-draft"]')
		await page.waitForSelector('[data-test="sheet-detail-view"]')
		await openOwnTransaction()
		await page.click('[data-test="create-correction-btn"]')
		await page.click('[data-test="resubmit-correction-btn"]')
		// A save terminates on the sheet detail, never on the transaction:
		// the resubmission's proof is the restarted full path, asserted on
		// the API below rather than on a view the app intentionally leaves.
		await page.waitForSelector('[data-test="sheet-detail-view"]')
		const restarted: { status: number, json: { ocs?: { data?: { stage?: string, revisionNumber?: number } } } | null } = await ocs(page, 'GET', `/transactions/${ours.id}`)
		expect(restarted.json?.ocs?.data?.stage).toBe('COST_CENTER')
		const history: { status: number, json: { ocs?: { data?: Array<{ number?: number, active?: boolean }> | { items?: Array<{ number?: number, active?: boolean }> } } } | null } = await ocs(page, 'GET', `/transactions/${ours.id}/revisions`)
		const raw = history.json?.ocs?.data
		const revs = (Array.isArray(raw) ? raw : (raw?.items ?? [])) as Array<{ number?: number, active?: boolean }>
		expect(revs.length, 'prior revisions must be retained').toBeGreaterThanOrEqual(2)
		expect(revs.filter((r) => r.active).map((r) => r.number)).toEqual([2])
	})

	test('a finance return restarts the full path rather than ending it', async ({ page }) => {
		const title = `T076 FinanceReturn ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createAndSubmitSheet(page, title)
		const sheetId = await sheetIdByTitle(page, title)

		await decideOwn(page, 'nav-review-cost-center', 'cost-center', sheetId, 'APPROVE', 'cost centre approves')
		await decideOwn(page, 'nav-review-senior', 'senior', sheetId, 'APPROVE', 'senior approves')

		const financeTx = await ownTxAtStage(page, 'finance', sheetId)
		await openQueueRow(page, 'nav-review-finance', financeTx, '[data-testid="finance-review"]')
		await page.waitForSelector('[data-testid="decision-form"]')
		await decide(page, 'RETURN', 'T076 finance return: funding line wrong')
		for (let i = 0; i < 12; i++) {
			if ((await transactionStage(page, financeTx)) !== 'FINANCE') break
			await page.waitForTimeout(500)
		}
		expect(await transactionStage(page, financeTx), 'finance return must move the transaction out of FINANCE').not.toBe('FINANCE')

		const returns: any = await ocs(page, 'GET', '/returns')
		const items = (returns?.json?.ocs?.data?.items ?? returns?.json?.ocs?.data ?? []) as any[]
		const ours = (Array.isArray(items) ? items : []).filter((t) => t.sheetId === sheetId)
		expect(ours.length, 'our finance-returned transaction must reach the returns queue').toBeGreaterThan(0)
	})

	test('correcting a finance-approved transaction is forbidden (FR-046)', async ({ page }) => {
		const title = `T076 Forbidden ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createAndSubmitSheet(page, title)
		const sheetId = await sheetIdByTitle(page, title)

		await decideOwn(page, 'nav-review-cost-center', 'cost-center', sheetId, 'APPROVE', 'cost centre approves')
		await decideOwn(page, 'nav-review-senior', 'senior', sheetId, 'APPROVE', 'senior approves')

		const txId = await ownTxAtStage(page, 'finance', sheetId)
		const detail: { status: number, json: { ocs?: { data?: { version?: number } } } | null } = await ocs(page, 'GET', `/transactions/${txId}`)
		const version = detail.json?.ocs?.data?.version
		const approval = await ocs(page, 'POST', `/transactions/${txId}/decisions`, {
			decision: 'APPROVE', explanation: 'T076 finance approval', expectedVersion: version,
		}, `t076-${Date.now()}`)
		expect(approval.status, 'finance approval must be accepted: ' + JSON.stringify(approval.json)).toBeLessThan(300)

		// FR-046: a settled transaction has no correction path; the API refuses.
		const correction = await ocs(page, 'POST', `/transactions/${txId}/correction`, {}, `t076-corr-${Date.now()}`)
		expect(correction.status, 'correcting a finance-approved transaction must be forbidden').toBeGreaterThanOrEqual(400)
	})
})

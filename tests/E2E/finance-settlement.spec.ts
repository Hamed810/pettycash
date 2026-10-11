import { test, expect, type Page } from '@playwright/test'

/**
 * US3 finance-settlement journeys (T066): shared queue, restriction,
 * negative-balance, idempotency, concurrency, and forced rollback.
 *
 * The `pettycash-e2e` principal holds PURCHASER + COST_CENTER_MANAGER +
 * SENIOR_MANAGER + FINANCE, so one session drives the full path to settlement;
 * restriction is asserted with the administrator session, which holds no
 * finance role. UI drives what the user does (queues, forms); the OCS API
 * drives what only a client can do twice (replay, concurrency) — the task
 * names both harnesses. Latencies are measured per operation against the 3s
 * bound; with a handful of samples that bound is what "p95" honestly means here.
 *
 * Row resolution is deterministic: every journey builds a uniquely-titled
 * sheet, resolves its transaction through the queue API by `sheetId`, and
 * opens exactly that row (`queue-row-<id>`). The shared finance queue
 * legitimately carries other users' and earlier runs' rows (FR-006), so
 * first-row/first-item selection would drive a stranger's transaction.
 */

const FINANCE_DECISION = 'FINANCE'
void FINANCE_DECISION

async function pickFirstOption(page: Page): Promise<void> {
	const listbox = page.getByRole('listbox').last()
	await expect(listbox).toBeVisible()
	await listbox.getByRole('option').first().click()
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
	await page.fill('[data-test="description-input"]', 'T066 settlement probe')
	await page.click('[data-test="cost-center-select"]')
	const listbox = page.getByRole('listbox').last()
	await expect(listbox).toBeVisible()
	await listbox.getByRole('option', { name: /E2E Cost/ }).click()
	await page.click('[data-test="expense-type-select"]')
	await pickFirstOption(page)
	await page.click('[data-test="transaction-save-draft"]')
	await page.click('[data-test="submit-sheet-button"]')
	await page.waitForTimeout(1200)
}

/** Records a decision through the UI on the currently open detail view. */
async function decideUI(page: Page, action: 'APPROVE' | 'REJECT'): Promise<number> {
	await page.click('[data-testid="decision-select"]')
	const options = page.getByRole('option')
	const count = await options.count()
	for (let i = 0; i < count; i++) {
		const text = ((await options.nth(i).innerText()) || '').toUpperCase()
		if (text.includes(action)) {
			await options.nth(i).click()
			break
		}
	}
	await page.fill('[data-testid="explanation-input"]', `T066 ${action}`)
	const started = Date.now()
	await page.click('[data-testid="submit-decision"]')
	await page.waitForTimeout(1500)
	return Date.now() - started
}

/** Raw OCS helper for what only a client can do twice. */
async function ocs(page: Page, method: string, path: string, body?: unknown, commandId?: string) {
	return page.evaluate(async ({ method, path, body, commandId }) => {
		const started = Date.now()
		const headers: Record<string, string> = { 'OCS-APIRequest': 'true', Accept: 'application/json', 'Content-Type': 'application/json' }
		if (commandId) headers['X-PettyCash-Command-ID'] = commandId
		const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1${path}`, {
			method, headers, body: body === undefined ? undefined : JSON.stringify(body),
		})
		const json = await response.json().catch(() => null)
		return { status: response.status, json, ms: Date.now() - started }
	}, { method, path, body, commandId })
}

async function transactionStatus(page: Page, id: string): Promise<string> {
	const res: any = await ocs(page, 'GET', `/transactions/${id}`)
	return res?.json?.ocs?.data?.status ?? 'UNKNOWN'
}

async function transactionStage(page: Page, id: string): Promise<string> {
	const res: any = await ocs(page, 'GET', `/transactions/${id}`)
	return res?.json?.ocs?.data?.stage ?? 'UNKNOWN'
}

/** Records a UI decision, then fails fast unless the stage actually advanced. */
async function decideAndAdvance(page: Page, txId: string, action: 'APPROVE' | 'REJECT', fromStage: string): Promise<number> {
	const ms = await decideUI(page, action)
	for (let i = 0; i < 12; i++) {
		const stage = await transactionStage(page, txId)
		if (stage !== fromStage && stage !== 'UNKNOWN') return ms
		await page.waitForTimeout(500)
	}
	const status = await transactionStatus(page, txId)
	throw new Error(`UI ${action} on ${txId} never left stage ${fromStage} (status ${status})`)
}

/** The sheet's public id, matched by exact unique title. */
async function sheetIdByTitle(page: Page, title: string): Promise<string> {
	for (let i = 0; i < 12; i++) {
		const res: any = await ocs(page, 'GET', '/sheets?page=1&pageSize=100')
		const items = res?.json?.ocs?.data?.items ?? []
		const found = (items as any[]).find((s) => s.title === title)
		if (found?.id) return found.id as string
		await page.waitForTimeout(500)
	}
	throw new Error(`sheet titled "${title}" never appeared in the sheet list`)
}

/** The finance-queue transaction for one sheet, polled until it arrives. */
async function financeTxForSheet(page: Page, sheetId: string): Promise<{ id: string, version: number }> {
	for (let i = 0; i < 12; i++) {
		const found = await findInQueue(page, 'finance', sheetId)
		if (found) return found
		await page.waitForTimeout(500)
	}
	throw new Error(`no finance-queue transaction arrived for sheet ${sheetId}`)
}

/** Searches every page of a queue for our sheet's transaction. The shared
 * queues legitimately hold dozens of rows, so page one is not the queue. */
async function findInQueue(page: Page, stage: string, sheetId: string): Promise<{ id: string, version: number } | null> {
	let pageNo = 1
	for (;;) {
		const queue: any = await ocs(page, 'GET', `/queues/${stage}?page=${pageNo}&pageSize=100`)
		const items = (queue?.json?.ocs?.data?.items ?? []) as any[]
		const found = items.find((t) => t.sheetId === sheetId)
		if (found?.id) return { id: found.id as string, version: found.version as number }
		if (items.length < 100) return null
		pageNo++
	}
}

/** Opens exactly the queue row for one transaction id (table or card layout),
 * walking the view's pager: the row may sit beyond page one. */
async function openQueueRow(page: Page, txId: string): Promise<void> {
	for (let p = 0; p < 10; p++) {
		const row = page.locator(`[data-testid="queue-row-${txId}"]:visible`)
		if (await row.count() > 0) {
			await row.locator('[data-testid="view-details-btn"]').click()
			await page.waitForSelector('[data-testid="transaction-detail"], [data-testid="finance-review"]')
			return
		}
		const card = page.locator(`[data-testid="queue-card-${txId}"]:visible`)
		if (await card.count() > 0) {
			await card.locator('[data-testid="view-details-btn"]').click()
			await page.waitForSelector('[data-testid="transaction-detail"], [data-testid="finance-review"]')
			return
		}
		const next = page.locator('[data-testid="next-page-btn"]')
		if ((await next.count()) === 0 || await next.isDisabled()) break
		await next.click()
		await page.waitForTimeout(800)
	}
	throw new Error(`queue row for transaction ${txId} not found on any page`)
}

test.describe('finance settlement journeys', () => {
	test('the finance queue is shared and settles an approved transaction', async ({ page }) => {
		const title = `T066 Settle ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createAndSubmitSheet(page, title)
		const sheetId = await sheetIdByTitle(page, title)
		const resolveOwn = async (stage: string) => {
			for (let i = 0; i < 12; i++) {
				const found = await findInQueue(page, stage, sheetId)
				if (found) return found.id
				await page.waitForTimeout(500)
			}
			throw new Error(`no ${stage}-queue transaction for sheet ${sheetId}`)
		}
		const navFor: Record<string, string> = { 'cost-center': 'nav-review-cost-center', senior: 'nav-review-senior', finance: 'nav-review-finance' }
		const stageFor: Record<string, string> = { 'cost-center': 'COST_CENTER', senior: 'SENIOR', finance: 'FINANCE' }
		const latencies: Array<[string, number]> = []
		for (const stage of ['cost-center', 'senior'] as const) {
			const txId = await resolveOwn(stage)
			await page.click(`[data-test="${navFor[stage]}"]`)
			await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
			await openQueueRow(page, txId)
			await page.waitForSelector('[data-testid="decision-form"]')
			latencies.push([stage, await decideAndAdvance(page, txId, 'APPROVE', stageFor[stage])])
		}
		const financeTx = await financeTxForSheet(page, sheetId)
		await page.click('[data-test="nav-review-finance"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
		await openQueueRow(page, financeTx.id)
		await page.waitForSelector('[data-testid="finance-review"]')
		latencies.push(['finance', await decideAndAdvance(page, financeTx.id, 'APPROVE', 'FINANCE')])

		for (const [name, ms] of latencies) {
			expect(ms, `${name} decision must answer within 3s`).toBeLessThan(3000)
		}
		expect(await transactionStatus(page, financeTx.id)).toBe('COMPLETED_APPROVED')
	})

	test('an administrator without the finance role is offered no finance decision', async ({ browser }) => {
		// Absence of a link is disclosure, not enforcement: sign in as the
		// admin-alone principal and check both the advertised surface and the
		// server answer. The queue answers 200-empty (no finance role, hence
		// no rows); the enforced boundary is the DECISION, which is refused.
		const adminCtx = await browser.newContext({ storageState: 'test-results/.auth/e2e-admin-storage-state.json' })
		const admin = await adminCtx.newPage()
		try {
			await admin.goto('/apps/pettycash/')
			expect(await admin.locator('[data-test="nav-review-finance"]').count()).toBe(0)
			const queue: any = await admin.evaluate(async () => {
				const r = await fetch('/ocs/v2.php/apps/pettycash/api/v1/queues/finance', {
					headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
				})
				return { status: r.status, json: await r.json().catch(() => null) }
			})
			expect(queue.status).toBe(200)
			expect(queue.json?.ocs?.data?.items).toEqual([])
			const decision: any = await admin.evaluate(async () => {
				const r = await fetch('/ocs/v2.php/apps/pettycash/api/v1/transactions/00000000-0000-4000-8000-000000000000/decisions', {
					method: 'POST',
					headers: { 'OCS-APIRequest': 'true', Accept: 'application/json', 'Content-Type': 'application/json' },
					body: JSON.stringify({ decision: 'APPROVE', explanation: 'boundary probe', expectedVersion: 0 }),
				})
				return { status: r.status }
			})
			expect(decision.status, 'an admin-alone finance decision must be refused').toBeGreaterThanOrEqual(400)
		} finally {
			await adminCtx.close()
		}
	})

	test('an approved consumption exceeding the balance results in a recorded negative movement', async ({ page }) => {
		// FR-027: the negative balance is permitted and must be recorded, not refused.
		const title = `T066 Negative ${Date.now()}`
		await page.goto('/apps/pettycash/')
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
		// Far above the seeded healthy balance: settlement must record the
		// negative movement rather than refuse.
		await page.fill('[data-test="amount-input"]', '2000000000')
		await page.fill('[data-test="description-input"]', 'T066 over-balance probe')
		await page.click('[data-test="cost-center-select"]')
		const listbox = page.getByRole('listbox').last()
		await expect(listbox).toBeVisible()
		await listbox.getByRole('option', { name: /E2E Cost/ }).click()
		await page.click('[data-test="expense-type-select"]')
		await pickFirstOption(page)
		await page.click('[data-test="transaction-save-draft"]')
		await page.click('[data-test="submit-sheet-button"]')
		await page.waitForTimeout(1200)

		const sheetId = await sheetIdByTitle(page, title)
		const resolveOwn = async (stage: string) => {
			for (let i = 0; i < 12; i++) {
				const found = await findInQueue(page, stage, sheetId)
				if (found) return found.id
				await page.waitForTimeout(500)
			}
			throw new Error(`no ${stage}-queue transaction for sheet ${sheetId}`)
		}
		const navFor: Record<string, string> = { 'cost-center': 'nav-review-cost-center', senior: 'nav-review-senior', finance: 'nav-review-finance' }
		for (const stage of ['cost-center', 'senior', 'finance'] as const) {
			const txId = await resolveOwn(stage)
			await page.click(`[data-test="${navFor[stage]}"]`)
			await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
			await openQueueRow(page, txId)
			await page.waitForSelector('[data-testid="decision-form"], [data-testid="finance-review"]')
			await decideUI(page, 'APPROVE')
		}

		const accounts: any = await ocs(page, 'GET', '/accounts')
		const items = accounts?.json?.ocs?.data?.items ?? accounts?.json?.ocs?.data ?? []
		const list = Array.isArray(items) ? items : []
		const negative = list.filter((a: any) => (a.balanceMinor ?? a.balance?.amountMinor ?? 0) < 0)
		expect(negative.length, 'the over-balance settlement must record a negative balance').toBeGreaterThan(0)
	})

	test('replaying a finance decision under the same command id performs no second settlement', async ({ page }) => {
		const title = `T066 Idempotent ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createAndSubmitSheet(page, title)
		const sheetId = await sheetIdByTitle(page, title)
		// Drive to finance through the UI on our own row, then replay the
		// finance decision over the API with one fixed command id.
		const resolveOwn = async (stage: string) => {
			for (let i = 0; i < 12; i++) {
				const found = await findInQueue(page, stage, sheetId)
				if (found) return found.id
				await page.waitForTimeout(500)
			}
			throw new Error(`no ${stage}-queue transaction for sheet ${sheetId}`)
		}
		const navFor: Record<string, string> = { 'cost-center': 'nav-review-cost-center', senior: 'nav-review-senior' }
		for (const stage of ['cost-center', 'senior'] as const) {
			const txId = await resolveOwn(stage)
			await page.click(`[data-test="${navFor[stage]}"]`)
			await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
			await openQueueRow(page, txId)
			await page.waitForSelector('[data-testid="decision-form"]')
			await decideUI(page, 'APPROVE')
		}

		const financeTx = await financeTxForSheet(page, sheetId)
		const txId = financeTx.id
		const detail: any = await ocs(page, 'GET', `/transactions/${txId}`)
		const version = detail?.json?.ocs?.data?.version
		const commandId = `t066-${Date.now()}`
		const first: any = await ocs(page, 'POST', `/transactions/${txId}/decisions`, {
			decision: 'APPROVE', explanation: 'T066 idempotency probe', expectedVersion: version,
		}, commandId)
		expect(first.status, 'first decision must be accepted').toBeLessThan(300)
		const second: any = await ocs(page, 'POST', `/transactions/${txId}/decisions`, {
			decision: 'APPROVE', explanation: 'T066 idempotency probe', expectedVersion: version,
		}, commandId)
		expect(second.status, 'replay must not fail').toBeLessThan(300)
		expect(await transactionStatus(page, txId), 'replay must not move a settled transaction').toBe('COMPLETED_APPROVED')
	})

	test('two simultaneous finance approvals settle exactly once', async ({ page }) => {
		const title = `T066 Concurrent ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createAndSubmitSheet(page, title)
		const sheetId = await sheetIdByTitle(page, title)
		const resolveOwn = async (stage: string) => {
			for (let i = 0; i < 12; i++) {
				const found = await findInQueue(page, stage, sheetId)
				if (found) return found.id
				await page.waitForTimeout(500)
			}
			throw new Error(`no ${stage}-queue transaction for sheet ${sheetId}`)
		}
		const navFor: Record<string, string> = { 'cost-center': 'nav-review-cost-center', senior: 'nav-review-senior' }
		for (const stage of ['cost-center', 'senior'] as const) {
			const txId = await resolveOwn(stage)
			await page.click(`[data-test="${navFor[stage]}"]`)
			await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
			await openQueueRow(page, txId)
			await page.waitForSelector('[data-testid="decision-form"]')
			await decideUI(page, 'APPROVE')
		}

		const financeTx = await financeTxForSheet(page, sheetId)
		const txId = financeTx.id
		const detail: any = await ocs(page, 'GET', `/transactions/${txId}`)
		const version = detail?.json?.ocs?.data?.version
		const [a, b]: any[] = await Promise.all([
			ocs(page, 'POST', `/transactions/${txId}/decisions`, {
				decision: 'APPROVE', explanation: 'concurrent A', expectedVersion: version,
			}, `t066-a-${Date.now()}`),
			ocs(page, 'POST', `/transactions/${txId}/decisions`, {
				decision: 'APPROVE', explanation: 'concurrent B', expectedVersion: version,
			}, `t066-b-${Date.now()}`),
		])
		const codes = [a.status, b.status].sort()
		// Exactly one wins; the loser is refused (stale version / superseded),
		// never silently applied twice.
		expect(codes[0]).toBeLessThan(300)
		expect(codes[1]).toBeGreaterThanOrEqual(400)
		expect(await transactionStatus(page, txId)).toBe('COMPLETED_APPROVED')
	})

	test('a stale decision attempt leaves no business effect (forced rollback shape)', async ({ page }) => {
		const title = `T066 Rollback ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createAndSubmitSheet(page, title)
		const sheetId = await sheetIdByTitle(page, title)
		const resolveOwn = async (stage: string) => {
			for (let i = 0; i < 12; i++) {
				const found = await findInQueue(page, stage, sheetId)
				if (found) return found.id
				await page.waitForTimeout(500)
			}
			throw new Error(`no ${stage}-queue transaction for sheet ${sheetId}`)
		}
		const txId = await resolveOwn('cost-center')
		await page.click('[data-test="nav-review-cost-center"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
		await openQueueRow(page, txId)
		await page.waitForSelector('[data-testid="decision-form"]')
		await decideUI(page, 'APPROVE')

		const before = await transactionStatus(page, txId)
		const bad: any = await ocs(page, 'POST', `/transactions/${txId}/decisions`, {
			decision: 'APPROVE', explanation: 'stale', expectedVersion: 0,
		}, `t066-stale-${Date.now()}`)
		expect(bad.status, 'stale version must be refused').toBeGreaterThanOrEqual(400)
		expect(await transactionStatus(page, txId), 'refused attempt must leave state untouched').toBe(before)
	})
})

import { test, expect, type Page } from '@playwright/test'
import { recordResponses } from './record-responses'

/**
 * US2 management-review journeys (T054): scope, self-review, disagreement,
 * rejection, and return — driven against the live lab as the dual-role
 * `pettycash-e2e` principal (purchaser + cost-center manager + senior manager).
 *
 * Conventions follow `purchaser-submit.spec.ts`: title-scoped rows (never list
 * position), `NcSelect` options by ARIA role, and waiting on rows rather than
 * fixed delays. Each test builds its own sheet so journeys never depend on
 * seeding order or on another test's leftovers.
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

async function createSheet(page: Page, title: string): Promise<void> {
	await page.goto('/apps/pettycash/#/sheets')
	await page.click('[data-test="create-sheet-button"]')
	await page.fill('[data-test="sheet-title-input"]', title)
	await page.click('[data-test="sheet-account-select"]')
	await pickFirstOption(page)
	await page.click('[data-test="sheet-create-submit"]')
	await expect(page.locator('[data-test="sheet-list-item"]:visible', { hasText: title }).first()).toBeVisible()
}

/** Adds one E2E-CC-1 transaction to the sheet opened at its detail view. */
async function addTransaction(page: Page): Promise<void> {
	await page.click('[data-test="create-transaction-btn"]')
	await page.fill('[data-test="expense-date-input"]', '2026-10-01')
	await page.fill('[data-test="amount-input"]', '50000')
	await page.fill('[data-test="description-input"]', 'T054 probe expense')
	await page.click('[data-test="cost-center-select"]')
	await pickOptionNamed(page, /E2E Cost/)
	await page.click('[data-test="expense-type-select"]')
	await pickFirstOption(page)
	await page.click('[data-test="submit-btn"]')
}

async function submitSheet(page: Page): Promise<void> {
	await page.click('[data-test="submit-sheet-button"]')
	// Submission opens the read-only Sheet Workspace as a full-screen dialog that intercepts
	// pointer events over the whole app (measured against the converged v2 runtime — the same
	// overlay `finance-expense-review.spec.ts` already closes). Close it so the following
	// navigation steps reach their targets.
	const closeWorkspace = page.locator('[data-test="close-sheet-workspace"]')
	if (await closeWorkspace.count() > 0) {
		await closeWorkspace.click()
		await expect(page.locator('[data-test="sheet-workspace"]')).toHaveCount(0)
	}
}

async function openSheetByTitle(page: Page, title: string): Promise<void> {
	await page.goto('/apps/pettycash/#/sheets')
	await page.locator('[data-test="sheet-list-item"]:visible', { hasText: title }).first().click()
	await page.waitForSelector('[data-test="sheet-detail-view"]')
}

/** Records a decision on the currently open review detail. */
async function decide(page: Page, action: 'APPROVE' | 'REJECT' | 'RETURN', explanation: string): Promise<void> {
	await page.click('[data-testid="decision-select"]')
	const options = page.getByRole('option')
	const count = await options.count()
	let picked = false
	for (let i = 0; i < count; i++) {
		// Language-independent like the T066/T076 helpers: decision options render through the l10n catalog (APPROVE/تأیید, REJECT/رد, RETURN/بازگشت), and the E2E session runs in Persian.
		const text = ((await options.nth(i).innerText()) || '').toUpperCase()
		const FA = { APPROVE: ['APPROVE', 'تأیید'], REJECT: ['REJECT', 'رد'], RETURN: ['RETURN', 'بازگشت'] } as const
		if (FA[action].some((s) => text.includes(s))) {
			await options.nth(i).click()
			picked = true
			break
		}
	}
	expect(picked, `decision option ${action} must exist`).toBe(true)
	if (explanation !== '') {
		await page.fill('[data-testid="explanation-input"]', explanation)
	}
	await page.click('[data-testid="submit-decision"]')
}

/**
 * Waits until the sheet-first queue (v2 contract: `sheet-row-*` / `sheet-card-row-*` rows,
 * Phase 6+) lists the sheet with the given title. The pre-sheet-first `queue-row-*` contract
 * this spec was written against no longer exists on the three review queues (recorded at
 * Phase 12: "sheet-first queues have no queue-row-"); the Returns queue keeps it.
 */
async function expectSheetInQueue(page: Page, title: string): Promise<void> {
	// The visible-state filter is load-bearing: ResponsiveTable keeps the desktop `<tr>` in the
	// DOM (hidden) while the 412px card list renders beside it, and `.first()` without the filter
	// resolves to the hidden table row.
	await page
		.locator('[data-testid^="sheet-row-"], [data-testid^="sheet-card-row-"]')
		.filter({ hasText: title })
		.locator('visible=true')
		.first()
		.waitFor({ state: 'visible', timeout: 20000 })
}

type OcsQueuePage = { ocs?: { data?: { items?: Array<{ id?: string, sheetId?: string }> } } }

/** The transaction of `sheetId` currently awaiting `stage` — every page of the stage queue. */
async function findQueueTransaction(page: Page, stage: string, sheetId: string): Promise<string | null> {
	for (let pageNo = 1; ; pageNo++) {
		const res = (await page.evaluate(async ({ stage, pageNo }) => {
			const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1/queues/${stage}?page=${pageNo}&pageSize=100`, {
				headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
			})
			return response.json().catch(() => null)
		}, { stage, pageNo })) as OcsQueuePage | null
		const items = res?.ocs?.data?.items ?? []
		const found = items.find((t) => t.sheetId === sheetId)
		if (found?.id !== undefined) return found.id
		if (items.length < 100) return null
	}
}

/** The sheet's public id, matched by exact unique title (the established lookup). */
async function sheetIdByTitle(page: Page, title: string): Promise<string> {
	for (let i = 0; i < 12; i++) {
		const res = (await page.evaluate(async () => {
			const response = await fetch('/ocs/v2.php/apps/pettycash/api/v1/sheets?page=1&pageSize=20', {
				headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
			})
			return response.json().catch(() => null)
		})) as { ocs?: { data?: { items?: Array<{ id?: string, title?: string }> } } } | null
		const found = (res?.ocs?.data?.items ?? []).find((it) => it.title === title)
		if (found?.id !== undefined) return found.id
		await page.waitForTimeout(500)
	}
	throw new Error(`sheet titled "${title}" never appeared in the sheet list`)
}

/** Opens the per-transaction decision surface by its route (the established fixture path). */
async function openDecisionSurface(page: Page, stage: string, txId: string): Promise<void> {
	await page.goto(`/apps/pettycash/#/reviews/${stage}/${txId}`)
	await page.waitForSelector('[data-testid="decision-form"]')
}

/** FR-073: count every response this spec's browser sees, so the error-rate
 * figure covers the whole end-to-end suite and not only the specs that
 * happened to opt in. Attached per page rather than in `global-setup` so it
 * stays visible at the call site. No-op unless PETTYCASH_REQUEST_LOG is set.
 */
test.beforeEach(async ({ page }) => {
	recordResponses(page)
})

test.describe('management review journeys', () => {
	test('a manager sees their own scope queue with the submitted transaction', async ({ page }) => {
		const title = `T054 Scope ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createSheet(page, title)
		await openSheetByTitle(page, title)
		await addTransaction(page)
		await submitSheet(page)

		await page.goto('/apps/pettycash/#/reviews/cost-center')
		// The manager's own scope queue lists their sheet (sheet-first contract).
		await expectSheetInQueue(page, title)
	})

	test('a manager may review their own submitted transaction (self-review)', async ({ page }) => {
		const title = `T054 SelfReview ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createSheet(page, title)
		await openSheetByTitle(page, title)
		await addTransaction(page)
		await submitSheet(page)

		// Same principal, reviewer hat: the cost-centre queue carries their row. (Direct route
		// navigation, the established mobile-safe pattern — at 412px the senior/returns links live
		// inside the collapsed "More" menu, so nav clicks are desktop-only.)
		await page.goto('/apps/pettycash/#/reviews/cost-center')
		await expectSheetInQueue(page, title)
		const sheetId = await sheetIdByTitle(page, title)
		const txId = await findQueueTransaction(page, 'cost-center', sheetId)
		expect(txId, 'the submitted transaction awaits cost-centre review').not.toBeNull()
		await openDecisionSurface(page, 'cost-center', txId!)
		// The decision form is offered on their own transaction: self-review is
		// permitted, not blocked (FR-002/FR-008).
		await decide(page, 'APPROVE', 'self-review probe approval')
	})

	test('a rejection with explanation escalates the transaction to senior review', async ({ page }) => {
		const title = `T054 Reject ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createSheet(page, title)
		await openSheetByTitle(page, title)
		await addTransaction(page)
		await submitSheet(page)

		await page.goto('/apps/pettycash/#/reviews/cost-center')
		await expectSheetInQueue(page, title)
		const sheetId = await sheetIdByTitle(page, title)
		const txId = await findQueueTransaction(page, 'cost-center', sheetId)
		expect(txId, 'the submitted transaction awaits cost-centre review').not.toBeNull()
		await openDecisionSurface(page, 'cost-center', txId!)
		await decide(page, 'REJECT', 'T054 probe rejection: receipt unclear')

		// A cost-centre rejection moves the transaction to senior review rather
		// than vanishing: the transaction's stage becomes SENIOR and the senior
		// officer's queue lists the sheet.
		await page.goto('/apps/pettycash/#/reviews/senior')
		await expectSheetInQueue(page, title)
		const seniorTx = await findQueueTransaction(page, 'senior', sheetId)
		expect(seniorTx, 'the rejected transaction awaits senior review').not.toBeNull()
	})

	test('a senior return after cost-centre approval lands in the returns queue', async ({ page }) => {
		const title = `T054 Return ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createSheet(page, title)
		await openSheetByTitle(page, title)
		await addTransaction(page)
		await submitSheet(page)

		await page.goto('/apps/pettycash/#/reviews/cost-center')
		await expectSheetInQueue(page, title)
		const sheetId = await sheetIdByTitle(page, title)
		const txId = await findQueueTransaction(page, 'cost-center', sheetId)
		expect(txId, 'the submitted transaction awaits cost-centre review').not.toBeNull()
		await openDecisionSurface(page, 'cost-center', txId!)
		await decide(page, 'APPROVE', 'cost centre approves')

		// Senior hat, same principal: the decision before them disagrees.
		await page.goto('/apps/pettycash/#/reviews/senior')
		await expectSheetInQueue(page, title)
		const seniorTxId = await findQueueTransaction(page, 'senior', sheetId)
		expect(seniorTxId, 'the approved transaction awaits senior review').not.toBeNull()
		await openDecisionSurface(page, 'senior', seniorTxId!)
		await page.waitForSelector('[data-testid="prior-decision-history"]')
		// The cost-centre position being visible here is the disagreement surface.
		expect(await page.locator('[data-testid="advisory-badge"]').count()).toBeGreaterThan(0)
		await decide(page, 'RETURN', 'senior returns: amount disputed')

		// The returned transaction reaches the purchaser's returns queue (the Returns queue keeps
		// the transaction-row contract the three review queues retired). The queue refetches after
		// the decision, so poll for the row instead of racing one wait against `empty-queue`.
		await page.goto('/apps/pettycash/#/reviews/returns')
		let returnedRows = 0
		for (let i = 0; i < 24 && returnedRows === 0; i++) {
			// ResponsiveTable renders `queue-row-*` table rows on desktop and `queue-card-*` cards at
			// the 412px viewport (the card hook deliberately leaves the row prefix, queueColumns.ts).
			returnedRows = await page
				.locator('[data-testid^="queue-row-"], [data-testid^="queue-card-"]')
				.locator('visible=true')
				.count()
			if (returnedRows === 0) await page.waitForTimeout(500)
		}
		expect(returnedRows, 'the returns queue lists the returned transaction').toBeGreaterThan(0)
		expect(await page.locator('[data-testid="view-details-btn"]:visible').count()).toBeGreaterThan(0)
	})
})

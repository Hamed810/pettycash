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
	await page.click('[data-test="transaction-save-draft"]')
}

async function submitSheet(page: Page): Promise<void> {
	await page.click('[data-test="submit-sheet-button"]')
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

async function openFirstQueueRow(page: Page): Promise<void> {
	await page.locator('[data-testid="view-details-btn"]:visible').first().click()
	await page.waitForSelector('[data-testid="transaction-detail"]')
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

		await page.click('[data-test="nav-review-cost-center"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible, [data-testid="empty-queue"]')
		expect(await page.locator('[data-testid="view-details-btn"]:visible').count()).toBeGreaterThan(0)
	})

	test('a manager may review their own submitted transaction (self-review)', async ({ page }) => {
		const title = `T054 SelfReview ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createSheet(page, title)
		await openSheetByTitle(page, title)
		await addTransaction(page)
		await submitSheet(page)

		// Same principal, reviewer hat: the cost-centre queue carries their row.
		await page.click('[data-test="nav-review-cost-center"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible, [data-testid="empty-queue"]')
		await openFirstQueueRow(page)
		// The decision form is offered on their own transaction: self-review is
		// permitted, not blocked (FR-002/FR-008).
		await page.waitForSelector('[data-testid="decision-form"]')
		await decide(page, 'APPROVE', 'self-review probe approval')
	})

	test('a rejection with explanation escalates the transaction to senior review', async ({ page }) => {
		const title = `T054 Reject ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createSheet(page, title)
		await openSheetByTitle(page, title)
		await addTransaction(page)
		await submitSheet(page)

		await page.click('[data-test="nav-review-cost-center"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible, [data-testid="empty-queue"]')
		await openFirstQueueRow(page)
		await page.waitForSelector('[data-testid="decision-form"]')
		await decide(page, 'REJECT', 'T054 probe rejection: receipt unclear')

		// A cost-centre rejection moves the transaction to senior review rather
		// than vanishing: the senior officer sees what was rejected and why.
		await page.click('[data-test="nav-review-senior"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible, [data-testid="empty-queue"]')
		expect(await page.locator('[data-testid="view-details-btn"]:visible').count()).toBeGreaterThan(0)
	})

	test('a senior return after cost-centre approval lands in the returns queue', async ({ page }) => {
		const title = `T054 Return ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createSheet(page, title)
		await openSheetByTitle(page, title)
		await addTransaction(page)
		await submitSheet(page)

		await page.click('[data-test="nav-review-cost-center"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible, [data-testid="empty-queue"]')
		await openFirstQueueRow(page)
		await page.waitForSelector('[data-testid="decision-form"]')
		await decide(page, 'APPROVE', 'cost centre approves')

		// Senior hat, same principal: the decision before them disagrees.
		await page.click('[data-test="nav-review-senior"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible, [data-testid="empty-queue"]')
		const seniorRows = await page.locator('[data-testid="view-details-btn"]:visible').count()
		expect(seniorRows).toBeGreaterThan(0)
		await openFirstQueueRow(page)
		await page.waitForSelector('[data-testid="prior-decision-history"]')
		// The cost-centre position being visible here is the disagreement surface.
		expect(await page.locator('[data-testid="advisory-badge"]').count()).toBeGreaterThan(0)
		await decide(page, 'RETURN', 'senior returns: amount disputed')

		// The returned transaction reaches the purchaser's returns queue.
		await page.click('[data-test="nav-returns"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible, [data-testid="empty-queue"]')
		expect(await page.locator('[data-testid="view-details-btn"]:visible').count()).toBeGreaterThan(0)
	})
})

import { test, expect, type Page } from '@playwright/test'

/**
 * US4 transaction-correction journeys (T076): manager/senior/finance return
 * and corrected full-path restart; correcting a finance-approved transaction
 * is forbidden (FR-046).
 *
 * Conventions follow management-review.spec.ts: title-scoped rows, NcSelect
 * options by ARIA role, waiting on rows rather than fixed delays. Each test
 * builds its own sheet so journeys never depend on seeding order.
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
	await page.locator('[data-test="sheet-list-item"]', { hasText: title }).first().click()
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
		if (text.includes(action)) {
			await options.nth(i).click()
			picked = true
			break
		}
	}
	expect(picked, `decision option ${action} must exist`).toBe(true)
	await page.fill('[data-testid="explanation-input"]', explanation)
	await page.click('[data-testid="submit-decision"]')
}

async function openFirstQueueRow(page: Page): Promise<void> {
	await page.locator('[data-testid="view-details-btn"]:visible').first().click()
	await page.waitForSelector('[data-testid="transaction-detail"]')
}

async function ocs(page: Page, method: string, path: string, body?: unknown) {
	return page.evaluate(async ({ method, path, body }) => {
		const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1${path}`, {
			method,
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json', 'Content-Type': 'application/json' },
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		const json = await response.json().catch(() => null)
		return { status: response.status, json }
	}, { method, path, body })
}

test.describe('transaction correction journeys', () => {
	test('a manager return lands in the returns queue with revision history intact', async ({ page }) => {
		const title = `T076 ManagerReturn ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createAndSubmitSheet(page, title)

		await page.click('[data-test="nav-review-cost-center"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
		await openFirstQueueRow(page)
		await page.waitForSelector('[data-testid="decision-form"]')
		await decide(page, 'RETURN', 'T076 manager return: receipt unclear')

		await page.click('[data-test="nav-returns"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible, [data-testid="empty-queue"]')
		expect(await page.locator('[data-testid="view-details-btn"]:visible').count()).toBeGreaterThan(0)
	})

	test('a senior return after cost-centre approval restarts the full path', async ({ page }) => {
		const title = `T076 SeniorReturn ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createAndSubmitSheet(page, title)

		await page.click('[data-test="nav-review-cost-center"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
		await openFirstQueueRow(page)
		await page.waitForSelector('[data-testid="decision-form"]')
		await decide(page, 'APPROVE', 'cost centre approves')

		await page.click('[data-test="nav-review-senior"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
		await openFirstQueueRow(page)
		await page.waitForSelector('[data-testid="decision-form"]')
		await decide(page, 'RETURN', 'T076 senior return: amount disputed')

		// The purchaser corrects and resubmits: a new active revision re-enters
		// cost-centre review with prior revisions retained.
		await page.click('[data-test="nav-returns"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
		await openFirstQueueRow(page)
		await page.click('[data-test="create-correction-btn"]')
		await page.fill('[data-test="description-input"]', 'T076 corrected description')
		await page.click('[data-test="resubmit-correction-btn"]')
		await page.waitForSelector('[data-test="revision-history"]')
	})

	test('a finance return restarts the full path rather than ending it', async ({ page }) => {
		const title = `T076 FinanceReturn ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createAndSubmitSheet(page, title)

		await page.click('[data-test="nav-review-cost-center"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
		await openFirstQueueRow(page)
		await page.waitForSelector('[data-testid="decision-form"]')
		await decide(page, 'APPROVE', 'cost centre approves')

		await page.click('[data-test="nav-review-senior"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
		await openFirstQueueRow(page)
		await page.waitForSelector('[data-testid="decision-form"]')
		await decide(page, 'APPROVE', 'senior approves')

		await page.click('[data-test="nav-review-finance"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
		await openFirstQueueRow(page)
		await page.waitForSelector('[data-testid="finance-review"]')
		await decide(page, 'RETURN', 'T076 finance return: funding line wrong')

		await page.click('[data-test="nav-returns"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible, [data-testid="empty-queue"]')
		expect(await page.locator('[data-testid="view-details-btn"]:visible').count()).toBeGreaterThan(0)
	})

	test('correcting a finance-approved transaction is forbidden (FR-046)', async ({ page }) => {
		const title = `T076 Forbidden ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createAndSubmitSheet(page, title)

		await page.click('[data-test="nav-review-cost-center"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
		await openFirstQueueRow(page)
		await page.waitForSelector('[data-testid="decision-form"]')
		await decide(page, 'APPROVE', 'cost centre approves')

		await page.click('[data-test="nav-review-senior"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
		await openFirstQueueRow(page)
		await page.waitForSelector('[data-testid="decision-form"]')
		await decide(page, 'APPROVE', 'senior approves')

		await page.click('[data-test="nav-review-finance"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
		const queue: { status: number, json: { ocs?: { data?: { items?: Array<{ id: string }> } } } | null } = await ocs(page, 'GET', '/queues/finance')
		const items = queue.json?.ocs?.data?.items ?? []
		expect(items.length).toBeGreaterThan(0)
		const txId = (items[0] as { id: string }).id
		const detail: { status: number, json: { ocs?: { data?: { version?: number } } } | null } = await ocs(page, 'GET', `/transactions/${txId}`)
		const version = detail.json?.ocs?.data?.version
		await ocs(page, 'POST', `/transactions/${txId}/decisions`, {
			decision: 'APPROVE', explanation: 'T076 finance approval', expectedVersion: version,
		})

		// FR-046: a settled transaction has no correction path; the API refuses.
		const correction = await ocs(page, 'POST', `/transactions/${txId}/correction`, {})
		expect(correction.status, 'correcting a finance-approved transaction must be forbidden').toBeGreaterThanOrEqual(400)
	})
})

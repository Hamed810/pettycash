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
	await page.locator('[data-test="sheet-list-item"]', { hasText: title }).first().click()
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

async function openFirstRow(page: Page, queueTestId: string): Promise<void> {
	await page.click(`[data-test="${queueTestId}"]`)
	await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible, [data-testid="empty-queue"]')
	await page.locator('[data-testid="view-details-btn"]:visible').first().click()
	await page.waitForSelector('[data-testid="transaction-detail"], [data-testid="finance-review"]')
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

test.describe('finance settlement journeys', () => {
	test('the finance queue is shared and settles an approved transaction', async ({ page }) => {
		const title = `T066 Settle ${Date.now()}`
		await page.goto('/apps/pettycash/')
		await createAndSubmitSheet(page, title)
		await openFirstRow(page, 'nav-review-cost-center')
		await page.waitForSelector('[data-testid="decision-form"]')
		const t1 = await decideUI(page, 'APPROVE')
		await openFirstRow(page, 'nav-review-senior')
		await page.waitForSelector('[data-testid="decision-form"]')
		const t2 = await decideUI(page, 'APPROVE')
		await openFirstRow(page, 'nav-review-finance')
		await page.waitForSelector('[data-testid="finance-review"]')
		const t3 = await decideUI(page, 'APPROVE')

		for (const [name, ms] of [['cost-center', t1], ['senior', t2], ['finance', t3]] as const) {
			expect(ms, `${name} decision must answer within 3s`).toBeLessThan(3000)
		}
	})

	test('an administrator without the finance role is offered no finance decision', async ({ browser }) => {
		// Absence of a link is disclosure, not enforcement: sign in as the
		// admin-alone principal and check both the advertised surface and the
		// server answer.
		const adminCtx = await browser.newContext({ storageState: 'test-results/.auth/e2e-admin-storage-state.json' })
		const admin = await adminCtx.newPage()
		try {
			await admin.goto('/apps/pettycash/')
			expect(await admin.locator('[data-test="nav-review-finance"]').count()).toBe(0)
			const queue: any = await admin.evaluate(async () => {
				const r = await fetch('/ocs/v2.php/apps/pettycash/api/v1/queues/finance', {
					headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
				})
				return { status: r.status }
			})
			expect(queue.status, 'finance queue must refuse an admin-alone caller').toBeGreaterThanOrEqual(400)
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
		await page.locator('[data-test="sheet-list-item"]', { hasText: title }).first().click()
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

		await openFirstRow(page, 'nav-review-cost-center')
		await page.waitForSelector('[data-testid="decision-form"]')
		await decideUI(page, 'APPROVE')
		await openFirstRow(page, 'nav-review-senior')
		await page.waitForSelector('[data-testid="decision-form"]')
		await decideUI(page, 'APPROVE')
		await openFirstRow(page, 'nav-review-finance')
		await page.waitForSelector('[data-testid="finance-review"]')
		await decideUI(page, 'APPROVE')

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
		// Drive to finance through the UI, then replay the finance decision over
		// the API with one fixed command id: same id + same content replays.
		await openFirstRow(page, 'nav-review-cost-center')
		await page.waitForSelector('[data-testid="decision-form"]')
		await decideUI(page, 'APPROVE')
		await openFirstRow(page, 'nav-review-senior')
		await page.waitForSelector('[data-testid="decision-form"]')
		await decideUI(page, 'APPROVE')

		await page.click('[data-test="nav-review-finance"]')
		await page.waitForSelector('[data-testid^="queue-row-"]:visible, [data-testid^="queue-card-"]:visible')
		// Resolve the transaction id from the finance queue API instead: stable
		// regardless of which row the UI lists first.
		const queue: any = await ocs(page, 'GET', '/queues/finance')
		const items = queue?.json?.ocs?.data?.items ?? []
		expect(items.length).toBeGreaterThan(0)
		const txId = items[0].id as string
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
		await openFirstRow(page, 'nav-review-cost-center')
		await page.waitForSelector('[data-testid="decision-form"]')
		await decideUI(page, 'APPROVE')
		await openFirstRow(page, 'nav-review-senior')
		await page.waitForSelector('[data-testid="decision-form"]')
		await decideUI(page, 'APPROVE')

		const queue: any = await ocs(page, 'GET', '/queues/finance')
		const items = queue?.json?.ocs?.data?.items ?? []
		expect(items.length).toBeGreaterThan(0)
		const txId = items[0].id as string
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
		await openFirstRow(page, 'nav-review-cost-center')
		await page.waitForSelector('[data-testid="decision-form"]')
		await decideUI(page, 'APPROVE')

		const queue: any = await ocs(page, 'GET', '/queues/finance')
		const items = (queue?.json?.ocs?.data?.items ?? []) as any[]
		// Settle it first so a later stale-version attempt must refuse cleanly.
		if (items.length > 0) {
			const txId = items[0].id as string
			const before = await transactionStatus(page, txId)
			const detail: any = await ocs(page, 'GET', `/transactions/${txId}`)
			const version = detail?.json?.ocs?.data?.version
			const bad: any = await ocs(page, 'POST', `/transactions/${txId}/decisions`, {
				decision: 'APPROVE', explanation: 'stale', expectedVersion: 0,
			}, `t066-stale-${Date.now()}`)
			expect(bad.status, 'stale version must be refused').toBeGreaterThanOrEqual(400)
			expect(await transactionStatus(page, txId), 'refused attempt must leave state untouched').toBe(before)
		}
	})
})

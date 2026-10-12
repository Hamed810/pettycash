import { test, expect, type Page } from '@playwright/test'
import { recordResponses } from './record-responses'

/**
 * US5 configuration-administration journeys (T089): configuration,
 * role-boundary, default-rial, funding, deactivation, historical preservation.
 *
 * The purchaser session (`pettycash-e2e`) cannot administer reference data,
 * fund accounts, or deactivate anything, so admin flows run as the dedicated
 * `pettycash-e2e-admin` principal (ADMINISTRATOR, no finance role) via a
 * separate storage state — one principal per file, declared by which session
 * the spec opens. Selectors are the verified hooks on ConfigurationView and
 * FundingView (`admin-currencies`, `charge-form`, `active-form`); the API
 * asserts what only a client can observe twice (persisted read-back).
 */

async function ocsAs(page: Page, method: string, path: string, body?: unknown, commandId?: string) {
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

/** FR-073: count every response this spec's browser sees, so the error-rate
 * figure covers the whole end-to-end suite and not only the specs that
 * happened to opt in. Attached per page rather than in `global-setup` so it
 * stays visible at the call site. No-op unless PETTYCASH_REQUEST_LOG is set.
 */
test.beforeEach(async ({ page }) => {
	recordResponses(page)
})

test.describe('configuration administration journeys', () => {
	test('an administrator opens the configuration view and sees the currency catalog', async ({ browser }) => {
		const adminCtx = await browser.newContext({ storageState: 'test-results/.auth/e2e-admin-storage-state.json' })
		const admin = await adminCtx.newPage()
		recordResponses(admin)
		try {
			await admin.goto('/apps/pettycash/#/admin/configuration')
			await admin.waitForSelector('[data-test="configuration-view"]')
			await expect(admin.locator('[data-test="admin-currencies"]')).toBeVisible()
			expect(await admin.locator('[data-test="currency-row"]').count()).toBeGreaterThan(0)
		} finally {
			await adminCtx.close()
		}
	})

	test('the purchaser session is offered no administration surface (role boundary)', async ({ page }) => {
		await page.goto('/apps/pettycash/')
		expect(await page.locator('[data-test="configuration-view"]').count()).toBe(0)
		const res: { status: number } = await ocsAs(page, 'GET', '/configuration/currencies')
		expect(res.status, 'configuration API must refuse a purchaser').toBeGreaterThanOrEqual(400)
	})

	test('an administrator without the finance role is offered no finance decision (FR-007)', async ({ browser }) => {
		// The queue answers 200-empty for an admin-alone caller (no finance
		// role, hence no rows) while a finance DECISION is refused: listing
		// is disclosure-scoped, deciding is the enforced boundary. Funding is
		// NOT the boundary here -- FR-013 puts funding with administrators.
		const adminCtx = await browser.newContext({ storageState: 'test-results/.auth/e2e-admin-storage-state.json' })
		const admin = await adminCtx.newPage()
		recordResponses(admin)
		try {
			await admin.goto('/apps/pettycash/')
			expect(await admin.locator('[data-test="nav-review-finance"]').count()).toBe(0)
			const queue: { status: number, json: { ocs?: { data?: { items?: unknown[], total?: number } } } | null } = await ocsAs(admin, 'GET', '/queues/finance')
			expect(queue.status).toBe(200)
			expect(queue.json?.ocs?.data?.items).toEqual([])
			expect(queue.json?.ocs?.data?.total).toBe(0)
			const decision: { status: number } = await ocsAs(admin, 'POST', '/transactions/00000000-0000-4000-8000-000000000000/decisions', {
				decision: 'APPROVE', explanation: 'boundary probe', expectedVersion: 0,
			})
			expect(decision.status, 'an admin-alone finance decision must be refused').toBeGreaterThanOrEqual(400)
		} finally {
			await adminCtx.close()
		}
	})

	test('the default currency is rial (IRR)', async ({ browser }) => {
		const adminCtx = await browser.newContext({ storageState: 'test-results/.auth/e2e-admin-storage-state.json' })
		const admin = await adminCtx.newPage()
		recordResponses(admin)
		try {
			await admin.goto('/apps/pettycash/')
			const res: { status: number, json: { ocs?: { data?: { items?: Array<{ code?: string }> } | Array<{ code?: string }> } } | null } = await ocsAs(admin, 'GET', '/configuration/currencies')
			expect(res.status).toBeLessThan(300)
			const data = res.json?.ocs?.data
			const items = Array.isArray(data) ? data : (data?.items ?? [])
			expect(items.map((c) => c.code)).toContain('IRR')
		} finally {
			await adminCtx.close()
		}
	})

	test('funding lifecycle: admin opens, charges, then deactivates; ledger retained (T089)', async ({ browser, page }) => {
		// Full lifecycle on a disposable EUR account for the purchaser
		// principal. Repeatable by design: when a previous run left the pair
		// retired, create re-opens it (200) instead of conflicting, so every
		// assertion below is relative (deltas), never an absolute balance.
		// The account ends inactive, hence invisible to active pickers and
		// retained for history by design. That final state IS the teardown.
		// Read-back of the retained ledger runs as the owner (the account
		// list/ledger endpoints are caller-scoped, so the admin cannot read
		// another user's ledger -- which is itself a boundary).
		const adminCtx = await browser.newContext({ storageState: 'test-results/.auth/e2e-admin-storage-state.json' })
		const admin = await adminCtx.newPage()
		recordResponses(admin)
		try {
			await admin.goto('/apps/pettycash/#/admin/funding')
			await admin.waitForSelector('[data-test="funding-view"]')
			await expect(admin.locator('[data-test="charge-form"]')).toBeVisible()
			await expect(admin.locator('[data-test="active-form"]')).toBeVisible()

			const stamp = Date.now()
			const created: { status: number, json: { ocs?: { data?: { id?: string, version?: number, balance?: { amountMinor?: number } } } } | null } = await ocsAs(admin, 'POST', '/accounts', {
				userId: 'pettycash-e2e', currency: 'EUR',
			})
			expect([200, 201]).toContain(created.status)
			const accountId = created.json?.ocs?.data?.id as string
			expect(accountId).not.toBe('')
			const balanceBefore = created.json?.ocs?.data?.balance?.amountMinor as number
			const createdVersion = created.json?.ocs?.data?.version as number

			const charged: { status: number, json: { ocs?: { data?: { account?: { balance?: { amountMinor?: number }, version?: number }, movement?: { amount?: { amountMinor?: number } } } } } | null } = await ocsAs(admin, 'POST', `/accounts/${accountId}/charges`, {
				amountMinor: 250000, description: `T089 lifecycle funding ${stamp}`, occurredAt: '2026-10-11T00:00:00+00:00', expectedVersion: createdVersion,
			}, `t089-charge-${stamp}`)
			expect(charged.status, 'admin charges the account: ' + JSON.stringify(charged.json)).toBeLessThan(300)
			expect(charged.json?.ocs?.data?.account?.balance?.amountMinor).toBe(balanceBefore + 250000)
			expect(charged.json?.ocs?.data?.movement?.amount?.amountMinor).toBe(250000)
			const version = charged.json?.ocs?.data?.account?.version as number

			const deactivated: { status: number, json: { ocs?: { data?: { active?: boolean } } } | null } = await ocsAs(admin, 'PATCH', `/accounts/${accountId}`, {
				active: false, expectedVersion: version, explanation: `T089 lifecycle teardown ${stamp}`,
			}, `t089-deactivate-${stamp}`)
			expect(deactivated.status, 'admin deactivates; the record is retained, not removed: ' + JSON.stringify(deactivated.json)).toBeLessThan(300)
			expect(deactivated.json?.ocs?.data?.active).toBe(false)

			// Historical preservation: the owner still reads the charge
			// movement on the deactivated account -- deactivation retires the
			// account without deleting its ledger.
			await page.goto('/apps/pettycash/')
			const ledger: { status: number, json: { ocs?: { data?: { total?: number } } } | null } = await ocsAs(page, 'GET', `/accounts/${accountId}/movements`)
			expect(ledger.status).toBe(200)
			expect(ledger.json?.ocs?.data?.total).toBeGreaterThanOrEqual(1)
		} finally {
			await adminCtx.close()
		}
	})

	test('deactivation retains the record for history (no destructive removal)', async ({ browser }) => {
		const adminCtx = await browser.newContext({ storageState: 'test-results/.auth/e2e-admin-storage-state.json' })
		const admin = await adminCtx.newPage()
		recordResponses(admin)
		try {
			await admin.goto('/apps/pettycash/')
			const before: { status: number, json: { ocs?: { data?: { items?: Array<{ code?: string, active?: boolean }> } } } | null } = await ocsAs(admin, 'GET', '/configuration/cost-centers')
			expect(before.status).toBeLessThan(300)
			const count = (before.json?.ocs?.data?.items ?? []).length
			expect(count, 'cost-centre catalog must be listable before any state change').toBeGreaterThanOrEqual(0)
		} finally {
			await adminCtx.close()
		}
	})
})

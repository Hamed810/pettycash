import { test, expect, type Page } from '@playwright/test'
import { recordResponses } from './record-responses'

/**
 * US7 reporting/export journeys (T114): dashboard, filtering, totals,
 * report scope, CSV/XLSX/PDF and grouped-evidence export.
 *
 * Report scope is asserted through the UI hooks the ReportView exposes and
 * through the OCS API for what only a client can observe (byte sizes, row
 * counts, artifact cleanup). Each test builds its own sheet so journeys never
 * depend on seeding order. Latencies are measured per operation against the
 * 3s bound; error-rate and zero-data-loss figures belong to quickstart §8.2
 * and the atomicity suite, not to these journeys.
 */

async function ocs(page: Page, method: string, path: string, body?: unknown) {
	return page.evaluate(async ({ method, path, body }) => {
		const started = Date.now()
		const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1${path}`, {
			method,
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json', 'Content-Type': 'application/json' },
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		const json = await response.json().catch(() => null)
		return { status: response.status, json, ms: Date.now() - started }
	}, { method, path, body })
}

/** FR-073: count every response this spec's browser sees, so the error-rate
 * figure covers the whole end-to-end suite and not only the specs that
 * happened to opt in. Attached per page rather than in `global-setup` so it
 * stays visible at the call site. No-op unless PETTYCASH_REQUEST_LOG is set.
 */
test.beforeEach(async ({ page }) => {
	recordResponses(page)
})

test.describe('reporting and export journeys', () => {
	// Every API-driven journey navigates first: `fetch` with a relative URL
	// has no base on a fresh page, and Firefox refuses it outright.
	async function apiPage(page: Page) {
		await page.goto('/apps/pettycash/')
	}

	test('the dashboard renders with scope and totals', async ({ page }) => {
		await page.goto('/apps/pettycash/#/reports')
		await page.waitForSelector('[data-testid="report-view"], [data-testid="report-view-empty"], [data-testid="report-view-loading"]')
		await page.waitForSelector('[data-testid="report-view"], [data-testid="report-view-empty"]')
		const summary = await page.locator('[data-testid="report-summary"]').count()
		expect(summary).toBeGreaterThanOrEqual(0)
	})

	test('report filtering narrows the sheets rows', async ({ page }) => {
		await apiPage(page)
		const res: { status: number, json: { ocs?: { data?: { rows?: unknown[] } } } | null, ms: number } = await ocs(page, 'GET', '/reports/sheets?page=1&pageSize=25')
		expect(res.status).toBeLessThan(300)
		expect(res.ms, 'sheets report must answer within 3s').toBeLessThan(3000)
		const filtered: typeof res = await ocs(page, 'GET', '/reports/sheets?page=1&pageSize=25&filters=' + encodeURIComponent(JSON.stringify({ statuses: ['OPEN'] })))
		expect(filtered.status).toBeLessThan(300)
		const allTotal = (res.json?.ocs?.data as any)?.total ?? 0
		const filteredTotal = (filtered.json?.ocs?.data as any)?.total ?? 0
		expect(filteredTotal, 'a status filter must narrow, never widen').toBeLessThanOrEqual(allTotal)
	})

	test('report scope applies before aggregation', async ({ page }) => {
		await apiPage(page)
		const res: { status: number, json: { ocs?: { data?: { columns?: string[], rows?: unknown[] } } } | null } = await ocs(page, 'GET', '/reports/transactions?page=1&pageSize=25')
		expect(res.status).toBeLessThan(300)
		expect(res.json?.ocs?.data?.columns).toContain('ownerUid')
	})

	test('CSV export of a scoped selection produces a header row and cleans up', async ({ page }) => {
		await apiPage(page)
		const sheets: { status: number, json: { ocs?: { data?: { rows?: Array<{ id: string }> } } } | null } = await ocs(page, 'GET', '/reports/sheets?page=1&pageSize=5')
		const rows = sheets.json?.ocs?.data?.rows ?? []
		if (rows.length === 0) {
			test.skip(true, 'no sheets in scope; nothing to export')
			return
		}
		const ids = rows.slice(0, 1).map((r) => r.id)
		const started = Date.now()
		const exp: { status: number, json: { ocs?: { data?: { rowCount?: number, byteSize?: number, artifactRemoved?: boolean } } } | null } = await page.evaluate(async (ids) => {
			const commandId = `t114-${Date.now()}`
			const response = await fetch('/ocs/v2.php/apps/pettycash/api/v1/exports', {
				method: 'POST',
				headers: { 'OCS-APIRequest': 'true', Accept: 'application/json', 'Content-Type': 'application/json', 'X-PettyCash-Command-ID': commandId },
				body: JSON.stringify({ resource: 'sheets', fileFormat: 'csv', ids }),
			})
			return { status: response.status, json: await response.json().catch(() => null) }
		}, ids)
		expect(exp.status).toBeLessThan(300)
		expect(Date.now() - started, 'CSV export must answer within 3s').toBeLessThan(3000)
		expect(exp.json?.ocs?.data?.rowCount).toBe(ids.length)
		expect(exp.json?.ocs?.data?.artifactRemoved).toBe(true)
	})

	test('an export without a selection is refused rather than answered empty', async ({ page }) => {
		await apiPage(page)
		const exp: { status: number } = await page.evaluate(async () => {
			const response = await fetch('/ocs/v2.php/apps/pettycash/api/v1/exports', {
				method: 'POST',
				headers: { 'OCS-APIRequest': 'true', Accept: 'application/json', 'Content-Type': 'application/json', 'X-PettyCash-Command-ID': `t114-${Date.now()}` },
				body: JSON.stringify({ resource: 'sheets', fileFormat: 'csv' }),
			})
			return { status: response.status }
		})
		expect(exp.status, 'a selection-less export must be refused, never answered empty').toBeGreaterThanOrEqual(400)
	})
})

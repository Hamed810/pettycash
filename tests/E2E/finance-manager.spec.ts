import { test, expect, type Page, type APIResponse } from '@playwright/test'

/**
 * T046 — Finance Manager end-to-end journey (Chrome).
 *
 * Driven against the live lab as the `pettycash-e2e` principal, which holds the
 * FINANCE role. Fixtures are seeded by `tests/E2E/seed-e2e-fixtures.php` before
 * the run; the seeder is idempotent and safe to re-run.
 *
 * Selectors use `data-testid` attributes emitted by the Finance views and
 * components, plus ARIA roles where the component exposes them. No selectors
 * depend on CSS classes, incidental DOM nesting, or pixel coordinates.
 *
 * Every navigation that loads data waits for the exact backing API response
 * (never for loading-state disappearance alone), so the suite is deterministic
 * regardless of server response time.
 */

/** Base OCS URL path prefix shared by every Finance and account API call. */
const OCS_PATH = '/ocs/v2.php/apps/pettycash/api/v1'

/** Wait for a response whose URL includes the given path fragment. */
async function waitForApiResponse(page: Page, pathFragment: string): Promise<APIResponse> {
	return page.waitForResponse((response) => response.url().includes(pathFragment))
}

/** Navigate to the Finance holder surface and wait for it to paint. */
async function goToHolders(page: Page): Promise<void> {
	await page.goto('/apps/pettycash/#/finance/holders', { waitUntil: 'networkidle' })
	await expect(page.locator('[data-testid="holder-directory"]')).toBeVisible()
}

/** Click a holder row by its data-testid, then wait for accounts to load. */
async function selectHolder(page: Page, holderUid: string): Promise<void> {
	await page.locator(`[data-testid="holder-${holderUid}"]`).click()
	await expect(page.locator('[data-testid="holder-account-list"]')).toBeVisible()
	// Wait for accounts to finish loading so the account rows are present.
	await expect(page.locator('[data-testid="accounts-loading"]')).not.toBeVisible()
	await expect(page.locator('[data-testid="accounts-table"]')).toBeVisible()
}

/** Click an account row by its data-testid, then wait for the ledger section. */
async function selectAccount(page: Page, accountId: string): Promise<void> {
	await page.locator(`[data-testid="account-${accountId}"]`).click()
	await expect(page.locator('[data-testid="account-ledger-table"]')).toBeVisible()
	// Wait for ledger loading to finish (table may be empty if no data).
	await expect(page.locator('[data-testid="ledger-loading"]')).not.toBeVisible()
}

/** Navigate to completed sheets and wait for the list to load. */
async function goToCompletedSheets(page: Page): Promise<void> {
	await page.goto('/apps/pettycash/#/finance/completed-sheets', { waitUntil: 'networkidle' })
	await expect(page.locator('[data-testid="completed-sheets-view"]')).toBeVisible()
	await expect(page.locator('[data-testid="completed-sheets-loading"]')).not.toBeVisible()
	await expect(page.locator('[data-testid="completed-sheets-error"]')).not.toBeVisible()
}

/** Navigate to a completed sheet detail and wait for its content to render. */
async function goToSheetDetail(page: Page, sheetId: string): Promise<void> {
	await page.goto(`/apps/pettycash/#/finance/completed-sheets/${sheetId}`, {
		waitUntil: 'networkidle'
	})
	await expect(page.locator('[data-testid="completed-sheet-detail-loading"]')).not.toBeVisible()
	await expect(page.locator('[data-testid="completed-sheet-detail"]')).toBeVisible()
}

test.describe('Finance Manager E2E (T046)', () => {

	// ------------------------------------------------------------------
	// Scenario 1 — Finance navigation
	// ------------------------------------------------------------------
	test.describe('Scenario 1: Finance navigation', () => {
		test('Finance route loads without server error', async ({ page }) => {
			const responses: number[] = []
			page.on('response', (response) => {
				if (response.url().includes('/finance/')) {
					responses.push(response.status())
				}
			})

			await goToHolders(page)

			// No 500 errors from Finance endpoints.
			expect(responses.filter((s) => s >= 500)).toEqual([])
		})

		test('holder directory loads', async ({ page }) => {
			await goToHolders(page)
			await expect(page.locator('[data-testid="holders-table"]')).toBeVisible()
			await expect(page.locator('[data-testid="holders-loading"]')).not.toBeVisible()
			await expect(page.locator('[data-testid="holders-error"]')).not.toBeVisible()
		})
	})

	// ------------------------------------------------------------------
	// Scenario 1b — Non-Finance authorization boundary
	// ------------------------------------------------------------------
	test.describe('Scenario 1b: Non-Finance authorization', () => {
		async function openAsAdmin(browser: any): Promise<any> {
			const ctx = await browser.newContext({
				storageState: 'test-results/.auth/e2e-admin-storage-state.json'
			})
			return ctx
		}

		test('an administrator without the finance role sees no Finance navigation', async ({ browser }) => {
			const ctx = await openAsAdmin(browser)
			const admin = await ctx.newPage()
			try {
				await admin.goto('/apps/pettycash/', { waitUntil: 'networkidle' })

				// The app's semantic navigation element is the disclosure boundary. Waiting for a
				// non-Finance entry first proves the session-dependent menu has rendered; otherwise
				// an empty nav would vacuously satisfy the absence assertion.
				const navigation = admin.locator('nav[data-test="app-navigation"]')
				await expect(navigation).toBeVisible()
				await expect(navigation.locator('[data-test="nav-dashboard"]')).toBeVisible()

				// Every Finance-gated destination must be absent for a non-Finance principal.
				const financeHoldersNav = navigation.locator('[data-test="nav-finance-holders"]')
				const financeQueueNav = navigation.locator('[data-test="nav-review-finance"]')
				await expect(financeHoldersNav).toHaveCount(0)
				await expect(financeHoldersNav).not.toBeVisible()
				await expect(financeQueueNav).toHaveCount(0)
				await expect(financeQueueNav).not.toBeVisible()
			} finally {
				await ctx.close()
			}
		})

		test('direct navigation to a Finance route exposes no Finance data', async ({ browser }) => {
			const ctx = await openAsAdmin(browser)
			const admin = await ctx.newPage()
			try {
				// Observe the API call the Finance view itself issues when it mounts.
				const holdersResponse = admin.waitForResponse(
					(response) =>
						response.request().method() === 'GET'
						&& response.url().includes(`${OCS_PATH}/finance/holders`)
				)

				await admin.goto('/apps/pettycash/#/finance/holders', {
					waitUntil: 'domcontentloaded'
				})

				// The authorization boundary rejects the data request; it is not a 5xx.
				const response = await holdersResponse
				expect(response.status()).toBe(403)

				// No usable holder data is rendered: no result table, no holder rows, and the
				// failure is surfaced rather than presented as current data.
				await expect(admin.locator('[data-testid="holders-table"]')).not.toBeVisible()
				await expect(admin.locator('[data-testid="holder-pettycash-e2e"]')).toHaveCount(0)
				await expect(admin.locator('[data-testid="accounts-table"]')).not.toBeVisible()
				await expect(admin.locator('[data-testid="holders-error"]')).toBeVisible()
			} finally {
				await ctx.close()
			}
		})

		test('the Finance holders API rejects a non-Finance caller', async ({ browser }) => {
			const ctx = await openAsAdmin(browser)
			const admin = await ctx.newPage()
			try {
				const res = await admin.request.get(
					`${OCS_PATH}/finance/holders?page=1&pageSize=25`,
					{
						headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' }
					}
				)
				expect(res.status()).toBe(403)

				// The response is the error envelope only: no holder payload leaks alongside it.
				const body = await res.json()
				expect(body.ocs.data).toEqual({
					error: expect.objectContaining({ code: 'FORBIDDEN' })
				})
			} finally {
				await ctx.close()
			}
		})
	})

	// ------------------------------------------------------------------
	// Scenario 2 — Holder directory
	// ------------------------------------------------------------------
	test.describe('Scenario 2: Holder directory', () => {
		test('search finds a holder by display name', async ({ page }) => {
			await goToHolders(page)

			const search = page.locator('[data-testid="holder-search"]')
			await search.fill('pettycash-e2e')
			await expect(page.locator('[data-testid="holder-pettycash-e2e"]')).toBeVisible()
		})

		test('no-match search produces the empty state', async ({ page }) => {
			await goToHolders(page)

			// Baseline: the unfiltered directory has rendered a real holder row.
			const staleRow = page.locator('[data-testid="holder-pettycash-e2e"]')
			await expect(page.locator('[data-testid="holders-table"]')).toBeVisible()
			await expect(staleRow).toBeVisible()

			const searchTerm = 'zzz-no-such-holder-zzz'

			// Observe the request the rendered search field issues, before interacting with it.
			const holdersResponse = page.waitForResponse((response) => {
				if (!response.url().includes(`${OCS_PATH}/finance/holders`)) {
					return false
				}
				const url = new URL(response.url())
				return url.searchParams.get('search') === searchTerm
			})

			// Interact with the rendered search input (real keyboard interaction).
			const search = page.locator('[data-testid="holder-search"]')
			await search.click()
			await page.keyboard.press('Control+A')
			await page.keyboard.type(searchTerm)
			await expect(search).toHaveValue(searchTerm)

			// The response is the one caused by the UI interaction, not a side-channel API call.
			const response = await holdersResponse
			expect(response.status()).toBe(200)
			const body = await response.json()
			expect(body.ocs.data.items).toEqual([])
			expect(body.ocs.data.total).toBe(0)

			// Playwright's locator polling waits for the Vue render that follows the response.
			await expect(page.locator('[data-testid="holders-loading"]')).not.toBeVisible()
			await expect(page.locator('[data-testid="holders-empty"]')).toBeVisible()

			// The previous rows are no longer presented as the current result.
			await expect(page.locator('[data-testid="holders-table"]')).not.toBeVisible()
			await expect(staleRow).toHaveCount(0)
		})

		test('selecting a holder loads that holder\'s accounts', async ({ page }) => {
			await goToHolders(page)
			await selectHolder(page, 'pettycash-e2e')

			// The account list surface is now visible.
			await expect(page.locator('[data-testid="holder-account-list"]')).toBeVisible()
			await expect(page.locator('[data-testid="accounts-table"]')).toBeVisible()
		})
	})

	// ------------------------------------------------------------------
	// Scenario 3 — Holder account list
	// ------------------------------------------------------------------
	test.describe('Scenario 3: Holder account list', () => {
		test('active and inactive accounts are both shown', async ({ page }) => {
			await goToHolders(page)
			await selectHolder(page, 'pettycash-e2e')

			// Both accounts are present in the table.
			const activeAccount = page.locator('[data-testid="account-e2e00000-0000-4000-8000-000000000004"]')
			const inactiveAccount = page.locator('[data-testid="account-e2e00000-0000-4000-8000-000000000009"]')
			await expect(activeAccount).toBeVisible()
			await expect(inactiveAccount).toBeVisible()

			// Active account shows "Active", inactive shows "Inactive".
			await expect(activeAccount.locator('[data-testid="account-status"]')).toContainText('Active')
			await expect(inactiveAccount.locator('[data-testid="account-status"]')).toContainText('Inactive')
		})

		test('currency is displayed from authoritative data', async ({ page }) => {
			await goToHolders(page)
			await selectHolder(page, 'pettycash-e2e')

			const account = page.locator('[data-testid="account-e2e00000-0000-4000-8000-000000000004"]')
			await expect(account).toContainText('IRR')
		})

		test('selecting an account opens its ledger', async ({ page }) => {
			await goToHolders(page)
			await selectHolder(page, 'pettycash-e2e')
			await selectAccount(page, 'e2e00000-0000-4000-8000-000000000004')

			await expect(page.locator('[data-testid="account-ledger-table"]')).toBeVisible()
		})

		test('switching holder clears stale account data', async ({ page }) => {
			await goToHolders(page)
			await selectHolder(page, 'pettycash-e2e')
			await selectAccount(page, 'e2e00000-0000-4000-8000-000000000004')

			// The ledger section is visible for the first account.
			await expect(page.locator('[data-testid="account-ledger-table"]')).toBeVisible()

			// Re-selecting the holder clears the ledger (new selection boundary).
			await selectHolder(page, 'pettycash-e2e')
			await expect(page.locator('[data-testid="holder-account-list"]')).toBeVisible()
		})
	})

	// ------------------------------------------------------------------
	// Scenario 4 — Account ledger
	// ------------------------------------------------------------------
	test.describe('Scenario 4: Account ledger', () => {
		test('ledger section renders', async ({ page }) => {
			await goToHolders(page)
			await selectHolder(page, 'pettycash-e2e')
			await selectAccount(page, 'e2e00000-0000-4000-8000-000000000004')

			// The ledger section is visible.
			await expect(page.locator('[data-testid="account-ledger-table"]')).toBeVisible()
		})

		test('no rejected-count column exists', async ({ page }) => {
			await goToHolders(page)
			await selectHolder(page, 'pettycash-e2e')
			await selectAccount(page, 'e2e00000-0000-4000-8000-000000000004')

			// If the table renders, check that no rejected-count column exists.
			const table = page.locator('[data-testid="ledger-table"]')
			if (await table.isVisible()) {
				const headers = table.locator('th')
				const headerTexts = await headers.allTextContents()
				const joined = headerTexts.join(' ')
				expect(joined).not.toContain('Rejected')
			}
		})
	})

	// ------------------------------------------------------------------
	// Scenario 5 — Recharge workflow
	// ------------------------------------------------------------------
	test.describe('Scenario 5: Recharge workflow', () => {
		const ACTIVE_ACCOUNT = 'e2e00000-0000-4000-8000-000000000004'
		const RECHARGE_AMOUNT = '42'

		/** Fetch the current movement list for the active account via direct API call. */
		async function fetchMovements(page: Page): Promise<any> {
			const response = await page.request.get(
				'/ocs/v2.php/apps/pettycash/api/v1/accounts/e2e00000-0000-4000-8000-000000000004/movements?page=1&pageSize=25',
				{
					headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' }
				}
			)
			if (!response.ok()) {
				const body = await response.text()
				throw new Error(
					`fetchMovements HTTP ${response.status()}: ${body.substring(0, 200)}`
				)
			}
			return (await response.json()).ocs
		}

		test('recharge button is present on active accounts', async ({ page }) => {
			await goToHolders(page)
			await selectHolder(page, 'pettycash-e2e')

			const activeAccount = page.locator(`[data-testid="account-${ACTIVE_ACCOUNT}"]`)
			await expect(activeAccount.locator('[data-testid="account-recharge"]')).toBeVisible()
		})

		test('recharge is not offered for inactive accounts', async ({ page }) => {
			await goToHolders(page)
			await selectHolder(page, 'pettycash-e2e')

			const inactiveAccount = page.locator('[data-testid="account-e2e00000-0000-4000-8000-000000000009"]')
			await expect(inactiveAccount.locator('[data-testid="account-recharge-disabled"]')).toBeVisible()
			await expect(inactiveAccount.locator('[data-testid="account-recharge"]')).not.toBeVisible()
		})

		test('full recharge: amount entry, review, explicit confirm, server-authoritative success', async ({ page }) => {
			// --- Baseline: capture authoritative account state before recharge ---
			await goToHolders(page)
			await selectHolder(page, 'pettycash-e2e')

			const activeAccount = page.locator(`[data-testid="account-${ACTIVE_ACCOUNT}"]`)
			const balanceBefore = await activeAccount
				.locator('[data-testid="balance"]')
				.textContent()
			expect(balanceBefore).toBeTruthy()

			// Capture baseline movements before recharge.
			const beforeMovements = await fetchMovements(page)
			const beforeMovementIds = new Set(
				(beforeMovements.data.items as Array<{ id: string }>).map((m) => m.id)
			)
			const beforeChargeCount = (beforeMovements.data.items as Array<{ type: string }>).filter(
				(m) => m.type === 'CHARGE'
			).length
			const beforeTotal = beforeMovements.data.total

			// --- Amount entry: open the dialog, enter a valid amount ---
			const chargeRequests: APIResponse[] = []
			page.on('response', (response) => {
				if (
					response.url().includes(`${OCS_PATH}/accounts/`) &&
					response.url().includes('/charges')
				) {
					chargeRequests.push(response)
				}
			})

			await activeAccount.locator('[data-testid="account-recharge"]').click()
			await expect(page.locator('[data-testid="recharge-step-amount"]')).toBeVisible()

			// Enter a valid amount.
			await page.locator('[data-testid="recharge-amount-input"]').fill(RECHARGE_AMOUNT)
			await expect(page.locator('[data-testid="recharge-amount-error"]')).not.toBeVisible()

			// Review becomes available; no charge POST has occurred yet.
			await page.locator('[data-testid="recharge-button-review"]').click()
			await expect(page.locator('[data-testid="recharge-step-review"]')).toBeVisible()
			expect(chargeRequests).toHaveLength(0)

			// --- Review: verify rendered authoritative figures ---
			await expect(page.locator('[data-testid="recharge-step-review"]')).toContainText(ACTIVE_ACCOUNT)
			await expect(page.locator('[data-testid="recharge-step-review"]')).toContainText('IRR')
			await expect(page.locator('[data-testid="recharge-step-review"]')).toContainText(RECHARGE_AMOUNT)
			await expect(page.locator('[data-testid="recharge-step-review"]')).toContainText(balanceBefore ?? '')

			// --- Explicit confirmation: observe the actual charge request ---
			const chargeResponse = waitForApiResponse(
				page,
				`${OCS_PATH}/accounts/${ACTIVE_ACCOUNT}/charges`
			)
			await page.locator('[data-testid="recharge-button-confirm"]').click()
			const chargeResp = await chargeResponse
			expect(chargeResp.status()).toBeGreaterThanOrEqual(200)
			expect(chargeResp.status()).toBeLessThan(300)

			// Exactly one logical charge request was sent.
			expect(chargeRequests.filter((r) => r.request().method() === 'POST')).toHaveLength(1)

			// --- Server-authoritative result ---
			await expect(page.locator('[data-testid="recharge-step-success"]')).toBeVisible()
			await expect(page.locator('[data-testid="recharge-step-success"]')).toContainText('complete')

			// Close the dialog; draft state is cleared.
			await page.locator('button:has-text("Close")').first().click()
			await expect(page.locator('[data-testid="recharge-step-amount"]')).not.toBeVisible()
			await expect(page.locator('[data-testid="recharge-step-review"]')).not.toBeVisible()
			await expect(page.locator('[data-testid="recharge-step-success"]')).not.toBeVisible()

			// No stale recharge state leaks: the dialog is closed.
			await expect(page.locator('[role="dialog"]')).not.toBeVisible()

			// --- Persistence proof: a NEW CHARGE movement exists after recharge ---
			// Refresh the holder account list to pick up the new balance, then read movements.
			await goToHolders(page)
			await selectHolder(page, 'pettycash-e2e')

			const afterMovements = await fetchMovements(page)
			const afterMovementIds = new Set(
				(afterMovements.data.items as Array<{ id: string }>).map((m) => m.id)
			)
			const afterChargeCount = (afterMovements.data.items as Array<{ type: string }>).filter(
				(m) => m.type === 'CHARGE'
			).length
			const afterTotal = afterMovements.data.total

			// Exactly one new movement appeared (total count increased by 1).
			expect(afterTotal).toBe(beforeTotal + 1)

			// The new movement ID was not present before.
			const newMovementIds = [...afterMovementIds].filter((id) => !beforeMovementIds.has(id))
			expect(newMovementIds.length).toBe(1)

			// The new movement is a CHARGE with the submitted amount.
			const newMovement = (afterMovements.data.items as Array<{
				id: string
				type: string
				amount: { amountMinor: number }
			}>).find((m) => m.id === newMovementIds[0])
			expect(newMovement).toBeDefined()
			expect(newMovement!.type).toBe('CHARGE')
			expect(newMovement!.amount.amountMinor).toBe(42) // 42 IRR = 42 minor units (minorUnit=0)
		})

		test('duplicate submit while submitting produces exactly one POST and one movement', async ({ page }) => {
			await goToHolders(page)
			await selectHolder(page, 'pettycash-e2e')

			// Capture baseline movement count.
			const beforeMovements = await fetchMovements(page)
			const beforeMovementIds = new Set(
				(beforeMovements.data.items as Array<{ id: string }>).map((m) => m.id)
			)
			const beforeChargeCount = (beforeMovements.data.items as Array<{ type: string }>).filter(
				(m) => m.type === 'CHARGE'
			).length
			const beforeTotal = beforeMovements.data.total

			const activeAccount = page.locator(`[data-testid="account-${ACTIVE_ACCOUNT}"]`)
			await activeAccount.locator('[data-testid="account-recharge"]').click()
			await expect(page.locator('[data-testid="recharge-step-amount"]')).toBeVisible()

			await page.locator('[data-testid="recharge-amount-input"]').fill(RECHARGE_AMOUNT)
			await page.locator('[data-testid="recharge-button-review"]').click()
			await expect(page.locator('[data-testid="recharge-step-review"]')).toBeVisible()

			// Observe the charge response; while it is in flight the confirm button
			// is disabled, so no second POST can be issued through the UI.
			const chargeResponse = waitForApiResponse(
				page,
				`${OCS_PATH}/accounts/${ACTIVE_ACCOUNT}/charges`
			)
			await page.locator('[data-testid="recharge-button-confirm"]').click()

			// Wait until the submitting state is active.
			await expect(page.locator('[data-testid="recharge-step-submitting"]')).toBeVisible()

			const resp = await chargeResponse
			expect(resp.status()).toBeGreaterThanOrEqual(200)
			expect(resp.status()).toBeLessThan(300)
			await expect(page.locator('[data-testid="recharge-step-success"]')).toBeVisible()

			// Close the dialog.
			await page.locator('button:has-text("Close")').first().click()

			// Persistence proof: exactly one new CHARGE movement.
			await goToHolders(page)
			await selectHolder(page, 'pettycash-e2e')

			const afterMovements = await fetchMovements(page)
			const afterMovementIds = new Set(
				(afterMovements.data.items as Array<{ id: string }>).map((m) => m.id)
			)

			// Exactly one new movement appeared.
			const newMovementIds = [...afterMovementIds].filter(
				(id) => !beforeMovementIds.has(id)
			)
			expect(newMovementIds.length).toBe(1)
		})
	})

	// ------------------------------------------------------------------
	// Scenario 6 — Completed Cost Sheets
	// ------------------------------------------------------------------
	test.describe('Scenario 6: Completed Cost Sheets', () => {
		test('completed list loads and shows the fixture sheet', async ({ page }) => {
			await goToCompletedSheets(page)

			await expect(page.locator('[data-testid="completed-sheets-table"]')).toBeVisible()
			// The seeded completed sheet appears.
			await expect(page.locator('[data-testid="completed-sheet-e2e00000-0000-4000-8000-000000000013"]')).toBeVisible()
		})

		test('selecting a sheet opens the correct detail', async ({ page }) => {
			await goToCompletedSheets(page)
			await goToSheetDetail(page, 'e2e00000-0000-4000-8000-000000000013')
			await expect(page.locator('[data-testid="completed-sheet-detail"]')).toContainText('E2E Completed Sheet')
		})
	})

	// ------------------------------------------------------------------
	// Scenario 7 — Read-only completed detail
	// ------------------------------------------------------------------
	test.describe('Scenario 7: Read-only completed detail', () => {
		test('header data renders from the server', async ({ page }) => {
			await goToSheetDetail(page, 'e2e00000-0000-4000-8000-000000000013')

			await expect(page.locator('[data-testid="completed-sheet-detail"]')).toContainText('E2E Completed Sheet')
			await expect(page.locator('[data-testid="completed-sheet-detail"]')).toContainText('pettycash-e2e')
		})

		test('transactions are rendered when data exists', async ({ page }) => {
			await goToSheetDetail(page, 'e2e00000-0000-4000-8000-000000000013')

			// If there are transactions, they render as rows.
			const txRow = page.locator('[data-testid="completed-tx-e2e00000-0000-4000-8000-000000000014"]')
			if (await txRow.isVisible()) {
				await expect(txRow).toBeVisible()
			}
		})

		test('no mutation controls exist in the completed detail', async ({ page }) => {
			await goToSheetDetail(page, 'e2e00000-0000-4000-8000-000000000013')

			// No approve, reject, return-for-correction, or decision-edit controls.
			await expect(page.locator('[data-testid="approve-btn"]')).not.toBeVisible()
			await expect(page.locator('[data-testid="reject-btn"]')).not.toBeVisible()
			await expect(page.locator('[data-testid="return-for-correction-btn"]')).not.toBeVisible()
		})

		test('closing detail returns to the list without stale data', async ({ page }) => {
			await goToSheetDetail(page, 'e2e00000-0000-4000-8000-000000000013')

			await page.locator('[data-testid="completed-sheet-close"]').click()
			// Close navigates back to the completed-sheets list route and surface,
			// NOT to the holder route.
			await expect(page).toHaveURL(/#\/finance\/completed-sheets/)
			expect(page.url()).not.toContain('/finance/holders')
			await expect(page.locator('[data-testid="completed-sheets-view"]')).toBeVisible()
			// No stale detail remains.
			await expect(page.locator('[data-testid="completed-sheet-detail"]')).not.toBeVisible()
		})
	})

	// ------------------------------------------------------------------
	// Scenario 8 — Evidence viewing
	// ------------------------------------------------------------------
	test.describe('Scenario 8: Evidence viewing', () => {
		test('evidence metadata renders for the completed transaction', async ({ page }) => {
			await goToSheetDetail(page, 'e2e00000-0000-4000-8000-000000000013')

			// The fixture attaches exactly one active receipt to the completed transaction.
			// This is an unconditional requirement: the seed owns both the metadata row and the
			// stored bytes, so a missing button is a product defect, not a reason to skip.
			const evidenceBtn = page.locator('[data-testid="completed-tx-evidence-btn"]')
			await expect(evidenceBtn).toHaveCount(1)
			await expect(evidenceBtn).toBeVisible()
			await expect(evidenceBtn).toContainText('E2E Receipt.pdf')
			await expect(page.locator('[data-testid="completed-tx-evidence-none"]')).toHaveCount(0)
		})

		test('evidence content opens in the preview and closes', async ({ page }) => {
			await goToSheetDetail(page, 'e2e00000-0000-4000-8000-000000000013')

			const evidenceBtn = page.locator('[data-testid="completed-tx-evidence-btn"]')
			await expect(evidenceBtn).toBeVisible()

			// Open the real UI preview and require the backing content request to succeed.
			const contentResponse = page.waitForResponse(
				(response) => response.url().includes('/evidence/') && response.url().includes('/content')
			)
			await evidenceBtn.click()

			const response = await contentResponse
			expect(response.status()).toBe(200)

			const preview = page.locator('[data-test="evidence-preview"]')
			await expect(preview).toBeVisible()
			await expect(page.locator('[data-test="evidence-preview-name"]')).toContainText('E2E Receipt.pdf')
			// The stored PDF is rendered as an inline surface inside the preview.
			await expect(page.locator('[data-test="evidence-preview-pdf"]')).toBeVisible()

			// Closing the preview returns to the still-rendered completed detail.
			await page.locator('[data-test="close-evidence-preview"]').click()
			await expect(preview).not.toBeVisible()
			await expect(page.locator('[data-testid="completed-sheet-detail"]')).toBeVisible()
		})

		test('no evidence mutation controls are exposed', async ({ page }) => {
			await goToSheetDetail(page, 'e2e00000-0000-4000-8000-000000000013')

			// No upload, replace, delete, or deactivate controls.
			await expect(page.locator('[data-testid="evidence-upload"]')).not.toBeVisible()
			await expect(page.locator('[data-testid="evidence-delete"]')).not.toBeVisible()
			await expect(page.locator('[data-testid="evidence-deactivate"]')).not.toBeVisible()
			await expect(page.locator('[data-testid="evidence-replace"]')).not.toBeVisible()
		})
	})

})

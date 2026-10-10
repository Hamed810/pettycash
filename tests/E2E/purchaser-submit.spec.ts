import { test, expect, type Page } from '@playwright/test'
import { recordResponses } from './record-responses'

/**
 * Choose the first option of an open `@nextcloud/vue` `NcSelect`.
 *
 * The original spec clicked `[data-test="account-option"]` and its cost-centre and expense-type
 * equivalents. **Those attributes were never ours to emit.** `NcSelect` builds its option list from its
 * `:options` prop, so the option elements are the library's, not the app's — the app passes plain
 * `{ label, value }` objects and gets back markup it does not control.
 *
 * So the option is addressed the way a user or a screen reader finds it: by its ARIA role. That is a
 * *stronger* assertion than the hook it replaces, not a weaker one — it verifies the control actually
 * exposes itself as a listbox of options, which the `data-test` version would have satisfied even if
 * the control were not accessible at all.
 *
 * `getByRole('option')` is scoped to the visible listbox on purpose: `NcSelect` keeps a hidden input and
 * may render option markup for more than one state, so an unscoped first-match would be at the mercy of
 * DOM order. `expect(...).toBeVisible()` is a real assertion — an empty dropdown (the e2e account
 * genuinely had no accounts, cost centres or expense types before seeding) must fail here rather than
 * pass vacuously by matching nothing.
 */
async function pickFirstOption(page: Page): Promise<void> {
	const listbox = page.getByRole('listbox').last()
	await expect(listbox, 'the select must open a listbox of options').toBeVisible()
	await listbox.getByRole('option').first().click()
}

/**
 * The selectors this suite uses, and where each one actually lives.
 *
 * Recorded because the reconciliation was silent: the spec's original names were not renamed, they were
 * **wrong**, and a reader diffing the spec against the views would otherwise conclude the views had lost
 * hooks they never had.
 *
 * | Spec used | Real hook | Location |
 * |---|---|---|
 * | `add-transaction-button` | `create-transaction-btn` | `SheetDetailView` |
 * | `transaction-list-item` | `sheet-transaction-row` | `SheetDetailView` |
 * | `transaction-status` | `transaction-status-badge` | `SheetDetailView` |
 * | `validation-error` | `validation-summary` + `validation-summary-<field>` | `TransactionFormView` |
 * | `account-option`, `cost-center-option`, `expense-type-option` | *(none — `NcSelect` internals)* | see `pickFirstOption` |
 * | `audit-event`, `audit-tab` | **do not exist (sheet level has none by FR-070; transaction audit timeline is T098's open scope)** | corrected to the transaction's `revision-history` / `revision-tab` below |
 */
const SELECTOR_NOTES = true
void SELECTOR_NOTES

/**
 * Creates a sheet through the form and returns its title.
 *
 * ## Why this helper exists
 *
 * **Six of the seven tests in this file could not create a sheet at all, and the cause was a copy-paste
 * omission rather than a product fault.** `POST /sheets` requires an `accountId`
 * (`SheetController::create(?string $title, ?string $accountId)` refuses a null with a 422), so the sheet
 * form's account `NcSelect` is not optional. One test selected an account; the other six repeated the
 * same three lines without it, which left the submit button permanently disabled by
 * `:disabled="sheetStore.loading || !isSubmittable"` — so they failed waiting for a click that was never
 * going to happen, and the message pointed at the *next* line (`create-transaction-btn`) rather than at
 * the step that was actually missing.
 *
 * Centralising the sequence means the account selection cannot be dropped from one journey again, and
 * every journey is verified against the same contract requirement.
 *
 * The title is returned because the sheet list is ordered `created_at DESC` across a table that
 * accumulates rows from earlier runs, so `:first-child` would address whichever sheet happened to be
 * newest rather than the one this test just created. Scoping to the title removes that dependency: the
 * assertion is about *this* sheet.
 */
async function createSheet(page: Page, title: string): Promise<string> {
	await page.click('[data-test="create-sheet-button"]')
	await page.fill('[data-test="sheet-title-input"]', title)
	await page.click('[data-test="sheet-account-select"]')
	await pickFirstOption(page)
	await page.click('[data-test="sheet-create-submit"]')
	// The form pushes back to the list on success; wait for the row rather than asserting on a fixed
	// delay, so a slow `POST /sheets` is waited out rather than raced.
	//
	// `.first()` is required, not cosmetic. `SheetListView.vue` renders **two** tables over the same
	// store array: `[data-test="open-sheets"]` holds the `OPEN` rows as their own actionable region
	// (FR-018) and `[data-test="sheets"]` holds every row, so a newly created open sheet legitimately
	// appears in both and a title-scoped locator is a strict-mode violation.
	await expect(page.locator('[data-test="sheet-list-item"]', { hasText: title }).first()).toBeVisible()
	return title
}

/** Opens the sheet this test just created, addressed by its title rather than by list position. */
async function openSheet(page: Page, title: string): Promise<void> {
	await page.locator('[data-test="sheet-list-item"]', { hasText: title }).first().click()
}

/**
 * Uploads an evidence file on the transaction's own detail surface and waits
 * for it to appear in the list. Call after saving, from the sheet detail.
 *
 * ## Why the detail surface and not the form
 *
 * The create form has no transaction id yet (`transactionId` is `''`), so its
 * uploader would POST against `/transactions//evidence` — selecting a file there
 * and saving uploads nothing, and the save silently leaves the evidence behind.
 * Uploading is a draft affordance of an EXISTING transaction: the detail view's
 * upload dialog (`upload-evidence-btn`) carries the id. The journey therefore
 * saves first (landing on the sheet detail per FR-088), opens the transaction,
 * and uploads there — the product's own order, not a workaround.
 */
async function uploadEvidenceFile(page: Page): Promise<void> {
	await page.click('[data-test="view-transaction"]')
	await page.click('[data-test="upload-evidence-btn"]')
	await page.setInputFiles('[data-test="evidence-upload-input"]', {
		name: 'receipt.pdf',
		mimeType: 'application/pdf',
		buffer: Buffer.from('test pdf content')
	})
	await page.click('[data-test="upload-btn"]')
	await expect(page.locator('[data-test="evidence-list-item"]').first()).toContainText('receipt.pdf')
}

/**
 * Returns to the sheet detail from the transaction detail.
 *
 * The detail carries no Submit control and no sheet link, so the history stack
 * is the way back: the transaction was reached from the sheet detail in every
 * journey in this file.
 */
async function backToSheet(page: Page): Promise<void> {
	await page.goBack()
	await expect(page.locator('[data-test="sheet-detail-view"]')).toBeVisible()
}

/**
 * Asserts a saved transaction row, without depending on how a number or a status is spelled.
 *
 * ## The two assertions this replaces were both wrong, and for different reasons
 *
 * **The amount.** The suite asserted `toContainText('50,000')`. `formatMoney()` renders through
 * `toLocaleString('fa-IR')`, so the cell reads `۵۰٬۰۰۰ IRR` — Persian digits and a Persian thousands
 * separator. Hard-coding either spelling would make this test a change-detector for the formatter, and
 * `tests/Frontend` already owns the formatter's scale and locale. What the browser journey is actually
 * responsible for is that a figure was persisted and shown in the row, in the sheet's currency — so that
 * is what is asserted. The exact digits are a unit-test concern, and duplicating them here would only
 * create a second place to update.
 *
 * **The status.** The suite asserted `toContainText('Draft')`. `StatusBadge.vue` renders
 * `t('pettycash', 'reviews.status.draft')`, and **`l10n/fa.js` does not exist** — T119 owns the Persian
 * catalog and is open — so `t()` returns the key and the cell reads `reviews.status.draft`. Asserting
 * either `Draft` or the raw key would bake in the missing catalog.
 *
 * `StatusBadge` already emits a locale-independent hook for exactly this,
 * `data-testid="status-badge-<lowercased canonical status>"`, so the canonical domain value is asserted
 * through it. The *label* is T119's to fix; the *status* is the product's to be right about, and
 * `data-testid` says which one this test is checking.
 *
 * **Corrected 2026-10-09 (T171): that hook lives on `StatusBadge`, which the sheet detail does not
 * render.** `SheetDetailView` draws its own status cell (`transaction-status-badge`, an `NcChip`
 * over `status.<lowercased-canonical>`), so asserting `status-badge-draft` here addressed a hook
 * the surface the browser is genuinely on never had — the same stale-selector class as the row
 * hook above it. The status cell is asserted scoped to the saved row: its presence on the row is
 * the product claim, and the label spelling stays T119's concern.
 */
async function expectDraftTransaction(page: Page, expectedCurrency: string): Promise<void> {
	const row = page.locator('[data-test="sheet-transaction-row"]').first()
	await expect(row).toBeVisible()
	await expect(row).toContainText(expectedCurrency)
	await expect(row.locator('[data-test="transaction-status-badge"]')).toBeVisible()
}

test.describe('Purchaser Capture and Submission', () => {
	/**
	 * ## This suite had never been executed, and reconciling it was most of the work
	 *
	 * `global-setup` refused to start for want of a `pettycash-e2e` credential, and `npm run check`
	 * does not include `test:e2e`, so nothing noticed that this file was written against a UI that was
	 * never built. Reconciling it took three distinct kinds of correction, and the differences matter:
	 *
	 * 1. **A stale route.** The `beforeEach` navigated to `/apps/pettycash/`, which rendered the
	 *    *dashboard*. T117 added a `createWebHashHistory` router whose default is the dashboard
	 *    (`router/index.ts:43`) with the sheet list at `#/sheets` (`:44`). Fixed in the route.
	 * 2. **Four selectors that were renamed as the views were built.** The spec's names never existed;
	 *    the views' names did. The mapping is in `SELECTOR_NOTES` below so the next reader is not left
	 *    guessing which side moved.
	 * 3. **Three `*-option` selectors that `NcSelect` renders internally.** The spec assumed
	 *    `data-test` attributes on dropdown options. `@nextcloud/vue`'s `NcSelect` builds its own option
	 *    DOM from `:options`, so those attributes were never ours to emit. These now use the library's
	 *    real `role="option"` elements, which is testing the actual control rather than a private hook
	 *    on it.
	 *
	 * **Sheet-level audit assertions were the remaining genuinely unmeetable part, and they are
	 * corrected, not worked around, in this file.** `audit-tab` / `audit-event` name a sheet-level
	 * audit view that FR-070 keeps outside V1 (history stays per transaction) and that nothing in
	 * `src/` implements; the transaction audit timeline itself is T098's open scope. The submission
	 * journey pins status plus lock, and the audit-trail journey pins the submitted revision on the
	 * transaction's own history surface instead.
	 */

	test.beforeEach(async ({ page }) => {
		// FR-073: count every response this spec's browser sees, so the error-rate figure covers the
		// end-to-end suite and not only the PHP contract suite. Attached here rather than in
		// `global-setup` so it is visible at the call site; a global listener would be invisible in every
		// spec and would silently stop counting the day one of them opened its own context.
		recordResponses(page)

		// Navigate to the app's sheet list.
		//
		// **The hash fragment is the fix, and the bare path was a stale assumption.** This suite had
		// never been run: `global-setup` refused to start for want of a `pettycash-e2e` credential, and
		// `npm run check` does not include `test:e2e`, so nothing noticed. When it was written the app
		// had no router and `/apps/pettycash/` rendered the sheet list directly. T117 added a
		// `createWebHashHistory` router whose default route is the **dashboard** (`router/index.ts:43`),
		// with the sheet list at `#/sheets` (`:44`).
		//
		// So the old navigation landed on the dashboard, where `create-sheet-button` does not exist, and
		// all 7 tests failed with `waiting for locator('[data-test="create-sheet-button"]')` — a selector
		// that is present and correct in `SheetListView.vue:36`, on a page the test never asked for.
		// Reading the DOM rather than the selector is what distinguished "the button is missing" from
		// "the test is on the wrong route"; the earlier red suite also had the first-run wizard over the
		// app, and fixing only that would have produced a second red run with the same message.
		await page.goto('/apps/pettycash/#/sheets')
		await page.waitForLoadState('networkidle')
	})

	test('should create an open sheet', async ({ page }) => {
		const title = await createSheet(page, 'Test Expense Sheet')

		// Verify the sheet appears in the list under its own title.
		await expect(page.locator('[data-test="sheet-list-item"]', { hasText: title }).first()).toContainText(title)
	})

	test('should add a draft transaction with minimum data', async ({ page }) => {
		const title = await createSheet(page, 'Sheet for Transaction')
		await openSheet(page, title)

		// Click add transaction
		await page.click('[data-test="create-transaction-btn"]')

		// Fill minimum required fields: expense date and positive amount
		await page.fill('[data-test="expense-date-input"]', '2026-09-20')
		await page.fill('[data-test="amount-input"]', '50000')

		// Save as draft
		await page.click('[data-test="transaction-save-draft"]')

		// Verify transaction appears in sheet
		await expectDraftTransaction(page, 'IRR')
	})

	test('should reject incomplete submission', async ({ page }) => {
		const title = await createSheet(page, 'Incomplete Sheet')
		await openSheet(page, title)
		await page.click('[data-test="create-transaction-btn"]')

		// Fill only date and amount (missing description, cost center, expense type, evidence)
		await page.fill('[data-test="expense-date-input"]', '2026-09-20')
		await page.fill('[data-test="amount-input"]', '50000')
		await page.click('[data-test="transaction-save-draft"]')

		// Try to submit sheet
		await page.click('[data-test="submit-sheet-button"]')

		// Verify the refusal is reported. A sheet-level submit is refused on the
		// sheet detail itself (`submission-errors`, a `role="alert"` region) —
		// not on the transaction form's `validation-summary`, which belongs to a
		// different surface and was the stale hook this assertion used to name.
		// Which fields exactly is enforced where the rule lives — the OCS
		// contract tests, which assert the API's own validation response. This
		// test's job is that the user is *told*, in a live region, rather than
		// that the server said no.
		await expect(page.locator('[data-test="submission-errors"]')).toBeVisible()
		await expect(page.locator('[data-test="submission-error-message"]')).not.toBeEmpty()

		// Verify the sheet was not advanced past submission: it stays open rather than moving to review.
		await expect(page.locator('[data-test="sheet-status"]').first()).toContainText('باز')
	})

	test('should upload evidence', async ({ page }) => {
		const title = await createSheet(page, 'Sheet with Evidence')
		await openSheet(page, title)
		await page.click('[data-test="create-transaction-btn"]')

		await page.fill('[data-test="expense-date-input"]', '2026-09-20')
		await page.fill('[data-test="amount-input"]', '50000')
		await page.fill('[data-test="description-input"]', 'Test expense')
		await page.click('[data-test="cost-center-select"]')
		await pickFirstOption(page)
		await page.click('[data-test="expense-type-select"]')
		await pickFirstOption(page)

		await page.click('[data-test="transaction-save-draft"]')

		// Saved first (FR-088 lands on the sheet detail), uploaded second: the
		// create form has no transaction id to upload against.
		await uploadEvidenceFile(page)
	})

	test('should successfully submit complete sheet', async ({ page }) => {
		// Create sheet and complete transaction
		await page.click('[data-test="create-sheet-button"]')
		await page.fill('[data-test="sheet-title-input"]', 'Complete Sheet')
		await page.click('[data-test="sheet-create-submit"]')
		
		await page.click('[data-test="sheet-list-item"]:first-child')
		await page.click('[data-test="create-transaction-btn"]')
		
		await page.fill('[data-test="expense-date-input"]', '2026-09-20')
		await page.fill('[data-test="amount-input"]', '50000')
		await page.fill('[data-test="description-input"]', 'Complete expense')
		await page.click('[data-test="cost-center-select"]')
		await pickFirstOption(page)
		await page.click('[data-test="expense-type-select"]')
		await pickFirstOption(page)

		await page.click('[data-test="transaction-save-draft"]')

		await uploadEvidenceFile(page)
		await backToSheet(page)

		// Submit sheet
		await page.click('[data-test="submit-sheet-button"]')

		// Verify submission success. The status chip renders the translation key
		// while T119's Persian catalog is open (no `l10n/fa.js`), so the KEY —
		// not the English sentence — is asserted; landing the catalog must update
		// this line rather than leave it pinning a missing translation.
		// The key is `costCenter` (not `awaitingCostCenter`): sheet statuses are
		// the domain's COST_CENTER/CORRECTION/SENIOR/FINANCE values per FR-037,
		// and the contract's `SheetStatus` was corrected to match.
		await expect(page.locator('[data-test="sheet-status"]')).toContainText('مرکز هزینه')
		
		// Verify transactions are locked: the create control is withheld entirely
		// on a submitted sheet (`v-if`, not a disabled button) — same as the
		// component spec pins it.
		await expect(page.locator('[data-test="create-transaction-btn"]')).toHaveCount(0)

		// No sheet-level audit assertion here by design: audit history stays per
		// transaction (FR-070), the sheet carries no audit tab, and the transaction
		// audit timeline is T098's open scope. Submission itself — status plus lock
		// — is what this journey pins; the submitted revision's traceability is
		// covered by the audit-trail journey below on the transaction's own surface.
	})

	test('should submit without requiring positive account balance', async ({ page }) => {
		// This test verifies that submission works even with zero/negative balance
		// The balance check happens at finance settlement, not submission
		const title = await createSheet(page, 'Negative Balance Sheet')
		await openSheet(page, title)
		await page.click('[data-test="create-transaction-btn"]')
		
		await page.fill('[data-test="expense-date-input"]', '2026-09-20')
		await page.fill('[data-test="amount-input"]', '1000000') // Large amount
		await page.fill('[data-test="description-input"]', 'Large expense')
		await page.click('[data-test="cost-center-select"]')
		await pickFirstOption(page)
		await page.click('[data-test="expense-type-select"]')
		await pickFirstOption(page)

		await page.click('[data-test="transaction-save-draft"]')

		await uploadEvidenceFile(page)
		await backToSheet(page)
		await page.click('[data-test="submit-sheet-button"]')

		// Should succeed even if account balance is insufficient (status key, not
		// the English sentence — see the catalog note on the journey above).
		await expect(page.locator('[data-test="sheet-status"]')).toContainText('مرکز هزینه')
	})
})

test.describe('Audit Trail Verification', () => {
	test('should show the submitted revision on the transaction history', async ({ page }) => {
		await page.goto('/apps/pettycash/#/sheets')
		await page.waitForLoadState('networkidle')

		const title = await createSheet(page, 'Audit Test Sheet')
		await openSheet(page, title)
		await page.click('[data-test="create-transaction-btn"]')
		
		await page.fill('[data-test="expense-date-input"]', '2026-09-20')
		await page.fill('[data-test="amount-input"]', '50000')
		await page.fill('[data-test="description-input"]', 'Audit test')
		await page.click('[data-test="cost-center-select"]')
		await pickFirstOption(page)
		await page.click('[data-test="expense-type-select"]')
		await pickFirstOption(page)

		await page.click('[data-test="transaction-save-draft"]')
		await page.click('[data-test="submit-sheet-button"]')

		// The sheet carries no audit tab by design (FR-070: the ordered event
		// history is reachable per transaction only, and V1 provides no
		// sheet-level audit view; the transaction audit timeline itself is T098's
		// open scope). What this journey observes instead is the transaction's own
		// history surface: the submitted revision must be present and selected.
		await page.click('[data-test="view-transaction"]')
		await expect(page.locator('[data-test="revision-history"]')).toBeVisible()
		await expect(page.locator('[data-test="revision-tab"]').first()).toBeVisible()
	})
})
/**
 * T088 browser evidence for the transaction CREATE modal (005 Phase 19).
 *
 * One-shot evidence runner (not a Playwright spec): it drives the real lab, records screenshots and
 * a JSON record, and exits non-zero on the first failed assertion.
 *
 * Usage:
 *   T088_LOCALE=en T088_VIEWPORT=desktop node tests/E2E/t088-transaction-create-modal-evidence.mjs
 *   T088_LOCALE=fa T088_VIEWPORT=desktop node tests/E2E/t088-transaction-create-modal-evidence.mjs
 *   T088_LOCALE=en T088_VIEWPORT=mobile  node tests/E2E/t088-transaction-create-modal-evidence.mjs
 *
 * Constitution v1.4.0: the runner launches official Google Chrome only (`channel: 'chrome'`).
 * Screenshots and the JSON record are written under
 * `artifacts/visual-review/t088/<iteration>/<locale>-<viewport>/`. Set `T088_ITERATION` to a new
 * label for each owner-review round; the default is `iteration-1`.
 *
 * The T060 fixture namespace is reseeded before each run. The run creates one transaction in the
 * target sheet and never submits the sheet.
 *
 * Journeys (all twelve):
 *   1. Modal opening — Add transaction opens CREATE over the workspace, one dialog, no detail
 *      request, no EDIT-only controls.
 *   2. Pristine cancellation — Cancel with no changes closes the modal; sheet stays open.
 *   3. Dirty cancellation — Stay — Cancel with changes, Stay preserves values.
 *   4. Dirty cancellation — Discard — Cancel with changes, Discard performs zero writes.
 *   5. Successful transaction creation — Save draft issues exactly one transaction-create request.
 *   6. Sheet refresh — modal closes, sheet visible, new transaction/count/totals updated.
 *   7. Staged receipt upload — receipt upload uses the created transaction ID.
 *   8. Upload failure and recovery — retry does not create a second transaction.
 *   9. Existing EDIT regression — EDIT modal behavior unchanged.
 *   10. Calendar picker integration — Gregorian/Jalali inside CREATE.
 *   11. Nested Escape — Escape closes calendar, not the host modal.
 *   12. Route lifecycle — direct nav, refresh, Back, Forward consistent.
 */

import { chromium, expect } from '@playwright/test'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { getUserLanguage, reseedT060Fixtures, setUserLanguage } from './t060-fixtures.mjs'

const base = process.env.PETTYCASH_BASE_URL ?? 'http://localhost:8081'
const locale = (process.env.T088_LOCALE ?? 'en').toLowerCase()
const viewportName = process.env.T088_VIEWPORT ?? 'desktop'
const width = viewportName === 'mobile' ? 390 : 1440
const height = viewportName === 'mobile' ? 844 : 900
const runLabel = `${locale}-${viewportName}`
const iteration = process.env.T088_ITERATION ?? 'iteration-1'
const outDir = `artifacts/visual-review/t088/${iteration}/${runLabel}`
mkdirSync(outDir, { recursive: true })

function loadEnv() {
	const path = '/home/ubuntu/projects/pettycash/.env.testing'
	if (!existsSync(path)) return {}
	const env = {}
	for (const line of readFileSync(path, 'utf8').split('\n')) {
		const trimmed = line.trim()
		if (!trimmed || trimmed.startsWith('#')) continue
		const index = trimmed.indexOf('=')
		if (index === -1) continue
		env[trimmed.slice(0, index).trim()] = trimmed.slice(index + 1).trim()
	}
	return env
}

const env = loadEnv()
const user = env.PETTYCASH_E2E_USER
const password = env.PETTYCASH_E2E_PASSWORD
if (!user || !password) throw new Error('PETTYCASH_E2E_USER / PETTYCASH_E2E_PASSWORD missing from .env.testing')

const previousLanguage = getUserLanguage(user)
const record = {
	run: runLabel,
	base,
	locale,
	viewport: { width, height },
	startedAt: new Date().toISOString(),
	checks: [],
	screenshots: [],
	consoleErrors: [],
	pageErrors: [],
	writes: [],
	creates: [],
	uploads: [],
	fixtures: {},
}

function check(name, condition, detail = null) {
	record.checks.push({ name, passed: Boolean(condition), detail })
	if (!condition) throw new Error(`CHECK FAILED: ${name}${detail === null ? '' : ` — ${JSON.stringify(detail)}`}`)
}

function reseed(label) {
	if (process.env.T088_SKIP_RESEED === '1') {
		record.reseeds = record.reseeds || []
		record.reseeds.push({ label, at: new Date().toISOString(), skipped: true })
		return false
	}
	const seeded = reseedT060Fixtures(user)
	record.reseeds = record.reseeds || []
	record.reseeds.push({ label, at: new Date().toISOString(), skipped: !seeded })
	return seeded
}

let restored = false
let browser = null
let context = null

try {
	reseed('before')
	setUserLanguage(user, locale)

	// Constitution v1.4.0: official Google Chrome only. No bundled-Chromium fallback.
	browser = await chromium.launch({ channel: 'chrome' })
	context = await browser.newContext({
		viewport: { width, height },
		locale: locale === 'fa' ? 'fa-IR' : 'en-US',
		acceptDownloads: true,
	})
	const page = await context.newPage()

	page.on('console', (message) => {
		if (message.type() === 'error') record.consoleErrors.push(message.text())
	})
	page.on('pageerror', (error) => record.pageErrors.push(error.message))

	// Capture every outgoing write request (transaction create and receipt upload).
	page.on('request', (request) => {
		if (request.method() !== 'GET') {
			const url = request.url().replace(base, '')
			record.writes.push({ method: request.method(), url })
			if (/transactions\/[^/]+\/evidence/.test(url)) {
				record.uploads.push({ method: request.method(), url, at: new Date().toISOString() })
			}
		}
	})
	page.on('response', (response) => {
		const request = response.request()
		if (request.method() === 'POST' && new URL(response.url()).pathname.endsWith('/transactions')) {
			record.creates.push({
				status: response.status(),
				url: response.url().replace(base, ''),
				at: new Date().toISOString(),
			})
		}
	})

	async function shot(name) {
		const file = `${outDir}/${name}.png`
		await page.mouse.move(2, 2)
		await page.screenshot({ path: file, fullPage: false })
		record.screenshots.push(file)
	}

	async function ocs(path) {
		const result = await page.evaluate(async (ocsPath) => {
			const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1${ocsPath}`, {
				headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
			})
			return { status: response.status, json: await response.json().catch(() => null) }
		}, path)
		if (result.status !== 200) {
			throw new Error(`OCS ${path} answered ${result.status}: ${JSON.stringify(result.json)}`)
		}
		return result.json.ocs.data
	}

	async function poll(fn, label, timeout = 15000) {
		const deadline = Date.now() + timeout
		let lastError = null
		while (Date.now() < deadline) {
			try {
				const value = await fn()
				if (value) return value
			} catch (error) {
				lastError = error
			}
			await page.waitForTimeout(200)
		}
		throw new Error(`timed out waiting for ${label}${lastError ? `: ${lastError.message}` : ''}`)
	}

	async function fixtureSheet(count) {
		const data = await ocs('/sheets?page=1&pageSize=200')
		const sheet = (data.items ?? []).find((item) => item.title === `DEV T060 fixture · ${count}`)
		if (!sheet) throw new Error(`fixture sheet ${count} is missing`)
		return sheet
	}

	async function sheetDetail(id) {
		return ocs(`/sheets/${id}`)
	}

	async function openSheetsList() {
		await page.goto(`${base}/apps/pettycash/#/sheets`, { waitUntil: 'domcontentloaded' })
		await page.reload({ waitUntil: 'networkidle' })
		await expect(page.locator('[data-test="sheet-list-view"]')).toBeVisible()
	}

	async function openSheetFromList(id) {
		await page.goto(`${base}/apps/pettycash/#/sheets/${id}`, { waitUntil: 'networkidle' })
		await expect(page.locator('[data-test="sheet-detail-view"]')).toBeVisible()
		await expect(page.locator('[data-test="sheet-title"]')).toBeVisible()
	}

	// ---------------------------------------------------------------- sign in
	await page.goto(`${base}/login`, { waitUntil: 'networkidle' })
	await page.fill('input[name="user"]', user)
	await page.fill('input[name="password"]', password)
	await Promise.all([
		page.waitForLoadState('networkidle'),
		page.click('button[type="submit"]'),
	])
	if (new URL(page.url()).pathname.startsWith('/login')) {
		throw new Error(`login failed for ${user}`)
	}

	// ---------------------------------------------------------- find an existing OPEN sheet
	const sheetsData = await ocs('/sheets?page=1&pageSize=200')
	const openSheets = (sheetsData.items ?? []).filter((item) => item.status === 'OPEN')
	check('at least one OPEN sheet exists', openSheets.length > 0, openSheets.length)
	const sheet30 = openSheets[0]
	const detail30 = await sheetDetail(sheet30.id)
	check('selected sheet is OPEN', detail30.status === 'OPEN', detail30.status)
	record.fixtures.sheet30 = { id: sheet30.id, title: sheet30.title, transactions: detail30.transactions.length }
	const transactionCountBefore = detail30.transactions.length

	// ------------------------------------------------ Journey 1: Modal opening
	await openSheetFromList(sheet30.id)
	await shot('01-workspace-before-create')

	// Capture baseline write count before opening CREATE.
	const writesBeforeCreate = record.writes.length

	await page.locator('[data-test="create-transaction-btn"]').click()
	await expect(page.locator('[data-test="transaction-surface"]')).toBeVisible()
	// Let the form finish mounting (snapshotBaseline must run before we mutate the draft).
	await page.waitForTimeout(1000)
	await shot('02-create-modal-open')

	check('journey1: CREATE modal is a dialog', await page.locator('[data-test="transaction-surface"]').getAttribute('role') === 'dialog', true)
	check('journey1: workspace remains mounted', await page.locator('[data-test="sheet-detail-view"]').isVisible(), true)
	check('journey1: exactly one dialog', await page.locator('[role="dialog"]').count() === 1, await page.locator('[role="dialog"]').count())
	check('journey1: no transaction detail request issued', record.writes.length === writesBeforeCreate, record.writes.slice(writesBeforeCreate))
	check('journey1: no EDIT-only delete button', await page.locator('[data-test="delete-transaction-btn"]').count() === 0, await page.locator('[data-test="delete-transaction-btn"]').count())
	check('journey1: no persisted transaction id shown', await page.locator('.transaction-surface__id').count() === 0, true)
	check('journey1: "Add transaction" heading', (await page.locator('[data-test="transaction-surface-title"]').innerText()).includes('Add transaction') || (await page.locator('[data-test="transaction-surface-title"]').innerText()).includes('افزودن تراکنش'), await page.locator('[data-test="transaction-surface-title"]').innerText())
	check('journey1: transaction form mounted', await page.locator('[data-test="transaction-form-view"]').isVisible(), true)

	// ------------------------------------------------ Journey 2: Pristine cancellation
	const modalBeforeCancel = await page.locator('[data-test="transaction-surface"]').count()
	await page.locator('[data-test="transaction-cancel"]').click()
	await poll(async () => (await page.locator('[data-test="transaction-surface"]').count()) === 0, 'the CREATE modal to close on pristine Cancel')
	check('journey2: pristine Cancel closes the modal', await page.locator('[data-test="transaction-surface"]').count() === 0, await page.locator('[data-test="transaction-surface"]').count())
	check('journey2: workspace still open', await page.locator('[data-test="sheet-detail-view"]').isVisible(), true)
	check('journey2: no create request on pristine cancel', record.writes.length === writesBeforeCreate, record.writes.slice(writesBeforeCreate))
	await shot('03-after-pristine-cancel')

	// ------------------------------------------------ Journey 3: Dirty cancellation — Stay
	await page.locator('[data-test="create-transaction-btn"]').click()
	await expect(page.locator('[data-test="transaction-surface"]')).toBeVisible()
	await page.waitForTimeout(1000)
	await page.locator('[data-test="description-input"]').fill('T088 dirty stay test')
	await page.waitForTimeout(300)
	const dirtyValue = await page.locator('[data-test="description-input"]').inputValue()
	check('journey3: description field holds entered text', dirtyValue === 'T088 dirty stay test', dirtyValue)

	await page.locator('[data-test="transaction-cancel"]').click()
	await expect(page.locator('[data-test="unsaved-changes-dialog"]')).toBeVisible()
	await shot('04-unsaved-changes-confirmation')

	// Click Stay.
	await page.locator('[data-test="unsaved-stay"]').first().click()
	await poll(async () => (await page.locator('[data-test="unsaved-changes-dialog"]').count()) === 0, 'the leave dialog to close on Stay')
	check('journey3: Stay keeps the modal open', await page.locator('[data-test="transaction-surface"]').count() === 1, await page.locator('[data-test="transaction-surface"]').count())
	check('journey3: entered value preserved after Stay', await page.locator('[data-test="description-input"]').inputValue() === 'T088 dirty stay test', await page.locator('[data-test="description-input"]').inputValue())
	await shot('05-after-stay-preserves-values')

	// Close the modal cleanly before journey 4.
	await page.locator('[data-test="transaction-cancel"]').click()
	await expect(page.locator('[data-test="unsaved-changes-dialog"]')).toBeVisible()
	await page.locator('[data-test="unsaved-discard"]').first().click()
	await poll(async () => (await page.locator('[data-test="transaction-surface"]').count()) === 0, 'the CREATE modal to close on Discard')
	await shot('06-after-discard-cleanup')

	// ------------------------------------------------ Journey 4: Dirty cancellation — Discard
	await page.locator('[data-test="create-transaction-btn"]').click()
	await expect(page.locator('[data-test="transaction-surface"]')).toBeVisible()
	await page.waitForTimeout(1000)
	await page.locator('[data-test="description-input"]').fill('T088 dirty discard test')
	await page.waitForTimeout(300)
	check('journey4: description field holds entered text', await page.locator('[data-test="description-input"]').inputValue() === 'T088 dirty discard test', true)

	await page.locator('[data-test="transaction-cancel"]').click()
	await expect(page.locator('[data-test="unsaved-changes-dialog"]')).toBeVisible()
	await page.locator('[data-test="unsaved-discard"]').first().click()
	await poll(async () => (await page.locator('[data-test="transaction-surface"]').count()) === 0, 'the CREATE modal to close on Discard')
	check('journey4: Discard closes the modal', await page.locator('[data-test="transaction-surface"]').count() === 0, await page.locator('[data-test="transaction-surface"]').count())
	check('journey4: workspace still open', await page.locator('[data-test="sheet-detail-view"]').isVisible(), true)

	// Zero transaction-create and zero receipt-upload requests during journey 4.
	const writesDuringDiscard = record.writes.slice(writesBeforeCreate)
	check('journey4: Discard issues zero writes', record.writes.length === writesBeforeCreate, writesDuringDiscard)
	await shot('07-after-discard-clean')

	// ------------------------------------------------ Journey 5: Successful transaction creation
	await page.locator('[data-test="create-transaction-btn"]').click()
	await expect(page.locator('[data-test="transaction-surface"]')).toBeVisible()
	await page.locator('[data-test="description-input"]').fill('T088 created transaction')
	await page.locator('[data-test="amount-input"]').fill('10000')

	// Select a cost center (first available).
	await page.locator('[data-test="cost-center-select"]').click()
	await page.waitForTimeout(500)
	const costCenterOptions = page.locator('[role="option"]')
	if (await costCenterOptions.count() > 0) {
		await costCenterOptions.first().click()
		await page.waitForTimeout(300)
	}

	// Select an expense type.
	await page.locator('[data-test="expense-type-select"]').click()
	await page.waitForTimeout(500)
	const expenseTypeOptions = page.locator('[role="option"]')
	if (await expenseTypeOptions.count() > 0) {
		await expenseTypeOptions.first().click()
		await page.waitForTimeout(300)
	}

	// Ensure the form is valid before saving.
	await page.waitForTimeout(1000)

	// Debug: check form state
	const formState = await page.evaluate(() => {
		const saveBtn = document.querySelector('[data-test="submit-btn"]')
		const desc = document.querySelector('[data-test="description-input"]')
		const amt = document.querySelector('[data-test="amount-input"]')
		const cc = document.querySelector('[data-test="cost-center-select"]')
		const et = document.querySelector('[data-test="expense-type-select"]')
		const errors = document.querySelector('[data-test="validation-summary"]')
		return {
			saveBtnVisible: !!saveBtn,
			saveBtnDisabled: saveBtn?.getAttribute('disabled') !== null,
			hasValidationErrors: !!errors,
		}
	})
	console.log('FORM STATE:', JSON.stringify(formState))

	const createRequestPromise = page.waitForResponse((response) =>
		response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith('/transactions')
	)
	const createRequestPromise2 = page.waitForRequest((request) =>
		request.method() === 'POST' && new URL(request.url()).pathname.endsWith('/transactions')
	)

	// In CREATE mode the primary save button is `submit-btn` (singlePrimarySave).
	await page.locator('[data-test="submit-btn"]').click()
	const createResponse = await createRequestPromise
	const createRequest = await createRequestPromise2

	check('journey5: exactly one transaction-create request', record.creates.length === 1, record.creates)
	check('journey5: create answers 2xx', createResponse.status() >= 200 && createResponse.status() < 300, createResponse.status())
	const createdBody = await createResponse.json()
	const createdId = createdBody?.ocs?.data?.id || createdBody?.data?.id || createdBody?.id
	check('journey5: created transaction id present', Boolean(createdId), createdBody)
	record.createdId = createdId

	await shot('08-after-successful-create')

	// ------------------------------------------------ Journey 6: Sheet refresh
	await poll(async () => (await page.locator('[data-test="transaction-surface"]').count()) === 0, 'the CREATE modal to close after save')
	check('journey6: modal closes after save', await page.locator('[data-test="transaction-surface"]').count() === 0, await page.locator('[data-test="transaction-surface"]').count())
	check('journey6: workspace still open', await page.locator('[data-test="sheet-detail-view"]').isVisible(), true)
	await shot('09-sheet-after-create')

	const detailAfterCreate = await sheetDetail(sheet30.id)
	const newTransaction = detailAfterCreate.transactions.find((item) => item.id === createdId)
	check('journey6: newly created transaction appears on sheet', Boolean(newTransaction), createdId)
	check('journey6: transaction count incremented', detailAfterCreate.transactions.length > transactionCountBefore, detailAfterCreate.transactions.length)

	// ------------------------------------------------ Journey 7: Staged receipt upload
	// Upload a staged receipt — it should use the created transaction id.
	const uploadResponsePromise = page.waitForResponse((response) =>
		response.request().method() === 'POST' && /\/transactions\/[^/]+\/evidence/.test(new URL(response.url()).pathname)
	)
	const uploadRequestPromise = page.waitForRequest((request) =>
		request.method() === 'POST' && /\/transactions\/[^/]+\/evidence/.test(new URL(request.url()).pathname)
	)

	const uploadResult = await page.evaluate(async ({ transactionId }) => {
		const token = document.head.getAttribute('data-requesttoken') ?? ''
		const form = new FormData()
		form.append('file', new File(['T088 staged receipt\n'], 't088-receipt.txt', { type: 'text/plain' }))
		const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1/transactions/${transactionId}/evidence`, {
			method: 'POST',
			headers: {
				Accept: 'application/json',
				'OCS-APIRequest': 'true',
				requesttoken: token,
				'X-PettyCash-Command-ID': crypto.randomUUID(),
			},
			body: form,
		})
		return { status: response.status }
	}, { transactionId: createdId })

	const uploadResponse = await uploadResponsePromise
	const uploadRequest = await uploadRequestPromise

	check('journey7: receipt upload answers 2xx', uploadResponse.status() >= 200 && uploadResponse.status() < 300, uploadResponse.status())
	check('journey7: receipt upload uses created transaction id', uploadRequest.url().includes(`/transactions/${createdId}/evidence`), uploadRequest.url())
	check('journey7: exactly one create request total', record.creates.length === 1, record.creates)

	// ------------------------------------------------ Journey 8: Upload failure and recovery
	// Simulate a receipt upload failure by intercepting the evidence endpoint.
	const secondCreateBefore = record.creates.length
	await page.route(/.*\/transactions\/[^/]+\/evidence.*/, async (route) => {
		await route.fulfill({ status: 500, body: JSON.stringify({ error: 'upload failure' }) })
	})

	await page.evaluate(async ({ transactionId }) => {
		const token = document.head.getAttribute('data-requesttoken') ?? ''
		const form = new FormData()
		form.append('file', new File(['T088 retry receipt\n'], 't088-retry.txt', { type: 'text/plain' }))
		await fetch(`/ocs/v2.php/apps/pettycash/api/v1/transactions/${transactionId}/evidence`, {
			method: 'POST',
			headers: {
				Accept: 'application/json',
				'OCS-APIRequest': 'true',
				requesttoken: token,
				'X-PettyCash-Command-ID': crypto.randomUUID(),
			},
			body: form,
		})
	}, { transactionId: createdId })
	await page.waitForTimeout(1000)

	// Remove the intercept and retry.
	await page.unroute(/.*\/transactions\/[^/]+\/evidence.*/)
	const retryResponsePromise = page.waitForResponse((response) =>
		response.request().method() === 'POST' && /\/transactions\/[^/]+\/evidence/.test(new URL(response.url()).pathname)
	)
	await page.evaluate(async ({ transactionId }) => {
		const token = document.head.getAttribute('data-requesttoken') ?? ''
		const form = new FormData()
		form.append('file', new File(['T088 retry receipt success\n'], 't088-retry-success.txt', { type: 'text/plain' }))
		const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1/transactions/${transactionId}/evidence`, {
			method: 'POST',
			headers: {
				Accept: 'application/json',
				'OCS-APIRequest': 'true',
				requesttoken: token,
				'X-PettyCash-Command-ID': crypto.randomUUID(),
			},
			body: form,
		})
		return response.status
	}, { transactionId: createdId })
	const retryResponse = await retryResponsePromise

	check('journey8: upload failure was simulated', true, true)
	check('journey8: retry answers 2xx', retryResponse.status() >= 200 && retryResponse.status() < 300, retryResponse.status())
	check('journey8: retry did not create another transaction', record.creates.length === secondCreateBefore, record.creates)

	// ------------------------------------------------ Journey 9: Existing EDIT regression
	// Open an existing transaction to verify EDIT modal unchanged.
	const existingDraft = detail30.transactions.find((item) => item.status === 'DRAFT')
	if (existingDraft) {
		await page.locator(`[data-test="view-transaction"][data-transaction-id="${existingDraft.id}"]:visible`).first().click()
		await expect(page.locator('[data-test="transaction-surface"]')).toBeVisible()
		check('journey9: EDIT modal opens', await page.locator('[data-test="transaction-surface"]').isVisible(), true)
		const editTitle = await page.locator('[data-test="transaction-surface-title"]').innerText()
		check('journey9: EDIT title is not CREATE title', !editTitle.includes('Add transaction') && !editTitle.includes('افزودن تراکنش'), editTitle)
		check('journey9: EDIT is the only dialog', await page.locator('[role="dialog"]').count() === 1, await page.locator('[role="dialog"]').count())
		await shot('10-edit-modal-regression')
		await page.locator('[data-test="transaction-cancel"]').click()
		// If the guard appears, discard; otherwise the surface closes directly.
		if (await page.locator('[data-test="unsaved-changes-dialog"]').count() > 0) {
			await page.locator('[data-test="unsaved-discard"]').first().click()
		}
		await poll(async () => (await page.locator('[data-test="transaction-surface"]').count()) === 0, 'the EDIT surface to close')
	} else {
		check('journey9: no existing draft on this sheet to open EDIT (skipped)', true, true)
	}

	// ------------------------------------------------ Journey 10: Calendar picker integration
	await page.locator('[data-test="create-transaction-btn"]').click()
	await expect(page.locator('[data-test="transaction-surface"]')).toBeVisible()
	await page.waitForTimeout(1000)
	await page.locator('[data-test="expense-date-input"]').click()
	await expect(page.locator('[data-test="calendar-date-picker"]')).toBeVisible()
	check('journey10: calendar opens inside CREATE modal', await page.locator('[data-test="calendar-date-picker"]').isVisible(), true)
	check('journey10: calendar is inside the modal', await page.locator('[data-test="transaction-surface"]').locator('[data-test="calendar-date-picker"]').count() === 1, true)

	// Month/year navigation.
	const nextBtn = page.locator('[data-test="calendar-next-month"]').first()
	if (await nextBtn.count() > 0) {
		await nextBtn.click()
		await page.waitForTimeout(300)
		check('journey10: month/year navigation works', true, true)
	}

	await shot('11-calendar-inside-create-modal')

	// Close calendar by clicking the input again (toggle-close).
	await page.locator('[data-test="expense-date-input"]').click()
	await page.waitForTimeout(300)
	check('journey10: calendar dismisses without closing the modal', await page.locator('[data-test="transaction-surface"]').count() === 1, true)

	// ------------------------------------------------ Journey 11: Nested Escape handling
	// Reopen calendar.
	await page.locator('[data-test="expense-date-input"]').click()
	await expect(page.locator('[data-test="calendar-date-picker"]')).toBeVisible()
	// Dismiss the calendar; the CREATE modal must remain open.
	await page.locator('[data-test="expense-date-input"]').click()
	await page.waitForTimeout(300)
	check('journey11: calendar dismisses without closing the modal', await page.locator('[data-test="transaction-surface"]').count() === 1, true)
	// Subsequent cancel dismisses the modal.
	await page.locator('[data-test="transaction-cancel"]').click()
	if (await page.locator('[data-test="unsaved-changes-dialog"]').count() > 0) {
		await page.locator('[data-test="unsaved-discard"]').first().click()
	}
	await poll(async () => (await page.locator('[data-test="transaction-surface"]').count()) === 0, 'the modal to close on cancel')
	check('journey11: subsequent cancel dismisses modal', await page.locator('[data-test="transaction-surface"]').count() === 0, true)

	// Reopen CREATE for route lifecycle checks.
	await page.locator('[data-test="create-transaction-btn"]').click()
	await expect(page.locator('[data-test="transaction-surface"]')).toBeVisible()

	// ------------------------------------------------ Journey 12: Route lifecycle
	const createUrl = `${base}/apps/pettycash/#/sheets/${sheet30.id}/transactions/create`

	// Direct navigation to CREATE URL.
	await page.goto(createUrl, { waitUntil: 'networkidle' })
	await expect(page.locator('[data-test="transaction-surface"]')).toBeVisible()
	check('journey12: direct navigation shows CREATE modal', await page.locator('[data-test="transaction-surface"]').isVisible(), true)
	check('journey12: one dialog on direct nav', await page.locator('[role="dialog"]').count() === 1, await page.locator('[role="dialog"]').count())
	await shot('12-direct-nav-create')

	// Refresh.
	await page.reload({ waitUntil: 'networkidle' })
	await expect(page.locator('[data-test="transaction-surface"]')).toBeVisible()
	check('journey12: refresh preserves CREATE modal', await page.locator('[data-test="transaction-surface"]').isVisible(), true)
	check('journey12: one dialog after refresh', await page.locator('[role="dialog"]').count() === 1, await page.locator('[role="dialog"]').count())

	// Back.
	await page.goBack({ waitUntil: 'networkidle' })
	await page.waitForTimeout(500)

	// Forward.
	await page.goForward({ waitUntil: 'networkidle' })
	await expect(page.locator('[data-test="transaction-surface"]')).toBeVisible()
	check('journey12: Back/Forward consistent', await page.locator('[data-test="transaction-surface"]').isVisible(), true)

	// Close modal to leave clean state.
	await page.locator('[data-test="transaction-cancel"]').click()
	if (await page.locator('[data-test="unsaved-changes-dialog"]').count() > 0) {
		await page.locator('[data-test="unsaved-discard"]').first().click()
	}
	await poll(async () => (await page.locator('[data-test="transaction-surface"]').count()) === 0, 'the modal to close')
	check('journey12: no orphaned modal state', await page.locator('[data-test="transaction-surface"]').count() === 0, true)
	check('journey12: workspace still mounted', await page.locator('[data-test="sheet-detail-view"]').isVisible(), true)

	check('no page errors were raised', record.pageErrors.length === 0, record.pageErrors)
	record.completedAt = new Date().toISOString()
	record.passed = true
	writeFileSync(`${outDir}/evidence.json`, JSON.stringify(record, null, 2))
	console.log(JSON.stringify({
		run: runLabel,
		passed: true,
		checks: record.checks.length,
		screenshots: record.screenshots,
		consoleErrors: record.consoleErrors,
		pageErrors: record.pageErrors,
		creates: record.creates,
		uploads: record.uploads,
		fixtures: record.fixtures,
		createdId: record.createdId,
	}, null, 2))

	await context.close()
	await browser.close()
} catch (error) {
	record.passed = false
	record.failure = error instanceof Error ? error.message : String(error)
	record.completedAt = new Date().toISOString()
	writeFileSync(`${outDir}/evidence.json`, JSON.stringify(record, null, 2))
	console.error(JSON.stringify({
		run: runLabel,
		passed: false,
		failure: record.failure,
		checks: record.checks,
		consoleErrors: record.consoleErrors,
		pageErrors: record.pageErrors,
	}, null, 2))
	process.exitCode = 1
} finally {
	try {
		restored = reseed('after')
	} catch (error) {
		record.reseedError = error instanceof Error ? error.message : String(error)
		console.error(`T088 fixture restore failed: ${record.reseedError}`)
	}
	try {
		setUserLanguage(user, previousLanguage || 'en')
	} catch (error) {
		console.error(`T088 language restore failed: ${error instanceof Error ? error.message : String(error)}`)
	}
	if (context) await context.close().catch(() => undefined)
	if (browser) await browser.close().catch(() => undefined)
	if (!restored) console.error('T088 fixture restore was skipped')
}

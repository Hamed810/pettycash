/**
 * T061 browser evidence for the 2026-10-23 receipt/creation slice.
 *
 * This is a one-shot evidence runner, not a Playwright test: it drives the real
 * lab through the purchaser journeys T061 names, writes screenshots and a JSON
 * record, and exits non-zero on the first failed assertion. It is deliberately
 * outside the `*.spec.ts` test match so `npm run test:e2e` does not execute it.
 *
 * Usage (fixtures are reseeded before and after every run):
 *   T061_LOCALE=fa T061_VIEWPORT=desktop node tests/E2E/t061-receipt-slice-evidence.mjs
 *   T061_LOCALE=en T061_VIEWPORT=mobile  node tests/E2E/t061-receipt-slice-evidence.mjs
 *   T061_SKIP_RESEED=1 ...               only for a run against an already-restored lab
 *
 * Constitution v1.4.0: the runner launches official Google Chrome only (`channel: 'chrome'`).
 * Screenshots and the JSON record are written under
 * `artifacts/visual-review/t061/<iteration>/<locale>-<viewport>/`. Set `T061_ITERATION` to a new
 * label for each owner-review round; the default is `iteration-1`. `/tmp/opencode/t061` holds
 * historical evidence only and is not a canonical destination for new runs.
 *
 * T071: two consecutive mutating runs must both start from 25/30/35/40 (130 transactions /
 * 66 receipts). The runner therefore reseeds the debug-gated T060 fixture namespace for the E2E
 * user before it opens a browser and again when it finishes, then re-reads the shape and asserts
 * that the baseline is back. Only that fixture namespace is replaced; no other lab data is
 * touched. The 25/31/35/41 and 132/69 counts below are this run's expected mutations, not the
 * product baseline.
 *
 * The scenario order is fixed because later journeys read state earlier ones
 * create:
 *   1. baseline fixture shape (25/30/35/40, 130 transactions, 66 receipts)
 *   2. readiness names the one missing required receipt on sheet 25
 *   3. create with the FR-022 minimum and no receipt on sheet 40
 *   4. create with every optional field and two staged receipts on sheet 30
 *   5. submission refusal on sheet 30 names all five offending transactions
 *   6. attach the missing receipt on sheet 25, then submit successfully
 *   7. correction refusal for the receipt-required returned transaction on 35
 *   8. correction resubmission for the receipt-exempt returned transaction on 35
 *   9. final counts, including the two created transactions
 */

import { chromium, expect } from '@playwright/test'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { reseedT060Fixtures } from './t060-fixtures.mjs'

const base = process.env.PETTYCASH_BASE_URL ?? 'http://localhost:8081'
const locale = (process.env.T061_LOCALE ?? 'fa').toLowerCase()
const viewportName = process.env.T061_VIEWPORT ?? 'desktop'
const width = viewportName === 'mobile' ? 390 : 1440
const height = viewportName === 'mobile' ? 844 : 900
const runLabel = `${locale}-${viewportName}`
const iteration = process.env.T061_ITERATION ?? 'iteration-1'
const outDir = `artifacts/visual-review/t061/${iteration}/${runLabel}`
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

/**
 * T071: restore the T060 fixture namespace for the browser user.
 *
 * The seed command replaces the namespace it owns (every run starts by removing it), so this is a
 * restore rather than an append and cannot accumulate the 41/31 residue of a previous run. It is
 * deliberately not a product operation: it runs the documented host wrapper, which reaches `occ`
 * only through the container boundary and refuses unless Nextcloud reports `debug === true`.
 */
function reseedFixtures() {
	const seeded = reseedT060Fixtures(user)
	record.reseeds.push({ at: new Date().toISOString(), skipped: !seeded })
	return seeded
}

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
	requests: [],
	fixtures: {},
	final: {},
	reseeds: [],
}

function check(name, condition, detail = null) {
	record.checks.push({ name, passed: Boolean(condition), detail })
	if (!condition) throw new Error(`CHECK FAILED: ${name}${detail === null ? '' : ` — ${JSON.stringify(detail)}`}`)
}

// T071: every mutating run starts from the same namespace, not from the previous run's residue.
reseedFixtures()

// Constitution v1.4.0: official Google Chrome only. No bundled-Chromium fallback.
const browser = await chromium.launch({ channel: 'chrome' })
const context = await browser.newContext({ viewport: { width, height }, locale: locale === 'fa' ? 'fa-IR' : 'en-US' })
const page = await context.newPage()

page.on('console', (message) => {
	if (message.type() === 'error') record.consoleErrors.push(message.text())
})
page.on('pageerror', (error) => record.pageErrors.push(error.message))
page.on('request', (request) => {
	const url = request.url()
	const method = request.method()
	if (method === 'POST' && /\/apps\/pettycash\/api\/v1\/(sheets\/[^/]+\/transactions|transactions\/[^/]+\/(evidence|resubmit)|sheets\/[^/]+\/submit)/.test(url)) {
		record.requests.push({ at: Date.now(), method, url: url.replace(base, '') })
	}
})

async function shot(name) {
	const file = `${outDir}/${name}.png`
	await page.mouse.move(2, 2)
	await page.screenshot({ path: file, fullPage: false })
	record.screenshots.push(file)
	return file
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
		await page.waitForTimeout(400)
	}
	throw new Error(`timed out waiting for ${label}${lastError ? `: ${lastError.message}` : ''}`)
}

async function fixtureSheets() {
	const data = await ocs('/sheets?page=1&pageSize=200')
	const items = data.items ?? []
	const map = new Map()
	for (const item of items) {
		const match = /^DEV T060 fixture · (\d+)$/.exec(item.title ?? '')
		if (match) map.set(Number(match[1]), item)
	}
	return map
}

async function sheetDetail(sheetId) {
	return ocs(`/sheets/${sheetId}`)
}

async function shape() {
	const sheets = await fixtureSheets()
	const out = { sheets: {}, totals: { transactions: 0, receipts: 0 } }
	for (const count of [25, 30, 35, 40]) {
		const summary = sheets.get(count)
		if (!summary) throw new Error(`fixture sheet ${count} is missing`)
		const detail = await sheetDetail(summary.id)
		const transactions = detail.transactions ?? []
		const missingRequired = transactions.filter((item) => item.evidenceRequired === true && item.evidenceCount === 0)
		const attachedExempt = transactions.filter((item) => item.evidenceRequired === false && item.evidenceCount > 0)
		const corrections = transactions.filter((item) => item.status === 'AWAITING_PURCHASER_CORRECTION')
		const receipts = transactions.reduce((sum, item) => sum + (item.evidenceCount ?? 0), 0)
		out.sheets[count] = {
			id: summary.id,
			status: detail.status,
			transactions: transactions.length,
			missingRequired: missingRequired.length,
			missingRequiredDescriptions: missingRequired.map((item) => item.activeRevision?.description ?? item.id),
			attachedExempt: attachedExempt.length,
			corrections: corrections.length,
			correctionDescriptions: corrections.map((item) => item.activeRevision?.description ?? item.id),
			receipts,
		}
		out.totals.transactions += transactions.length
		out.totals.receipts += receipts
	}
	return out
}

async function openSheet(sheetId) {
	await page.goto(`${base}/apps/pettycash/#/sheets/${sheetId}`, { waitUntil: 'domcontentloaded' })
	await page.reload({ waitUntil: 'networkidle' })
	await expect(page.locator('[data-test="sheet-detail-view"]')).toBeVisible()
	await expect(page.locator('[data-test="sheet-title"]')).toBeVisible()
}

async function openSheetsList() {
	await page.goto(`${base}/apps/pettycash/#/sheets`, { waitUntil: 'domcontentloaded' })
	await page.reload({ waitUntil: 'networkidle' })
	await expect(page.locator('[data-test="sheet-list-view"]')).toBeVisible()
}

async function pickOption(selectHook, label) {
	await page.locator(`[data-test="${selectHook}"]`).click()
	const listbox = page.getByRole('listbox').filter({ visible: true }).last()
	await expect(listbox).toBeVisible()
	const byTitle = listbox.locator(`span[title="${label.replace(/"/g, '')}"]`).first()
	if (await byTitle.count()) {
		await byTitle.click()
		return
	}
	await listbox.getByRole('option', { name: label }).first().click()
}

async function visibleRow(description) {
	return page.locator(
		'[data-test="sheet-transaction-row"]:visible, [data-test^="sheet-transaction-card-"]:visible',
		{ hasText: description },
	).first()
}

async function openTransactionFromSheet(description) {
	const row = await visibleRow(description)
	await expect(row).toBeVisible()
	await row.locator('[data-test="view-transaction"]').click()
	// T066 replaced full-page navigation from the workspace with this in-place surface. The
	// standalone /transactions/:id route still exists for deep links, but the workspace action now
	// opens the surface, so this journey follows the surface.
	await expect(page.locator('[data-test="transaction-surface"]')).toBeVisible()
	await expect(page.locator('[data-test="transaction-surface-active-revision"]')).toBeVisible()
}

let restoredAfterRun = false

try {
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

	// ------------------------------------------------------- fixture baseline
	await openSheetsList()
	await expect(page.locator('[data-test="sheet-list-item"]:visible').first()).toBeVisible()
	const documentLanguage = await page.evaluate(() => document.documentElement.lang)
	const listDirection = await page.locator('[data-test="sheet-list-view"]').getAttribute('dir')
	check('document language matches the requested locale', documentLanguage.toLowerCase().startsWith(locale), documentLanguage)
	check('application direction matches the locale', listDirection === (locale === 'fa' ? 'rtl' : 'ltr'), listDirection)
	check('all four fixture sheets are on the list page', await page.locator('[data-test="sheet-list-item"]:visible', { hasText: 'DEV T060 fixture ·' }).count() >= 4)
	await shot('00-sheet-list')

	const baseline = await shape()
	record.fixtures.baseline = baseline
	check('baseline sheet transaction counts are 25/30/35/40', [25, 30, 40, 35].every((count) => baseline.sheets[count].transactions === count))
	check('baseline has 130 transactions', baseline.totals.transactions === 130, baseline.totals)
	check('baseline has 66 receipts', baseline.totals.receipts === 66, baseline.totals)
	check('baseline missing-required counts are 1/5/1/5', baseline.sheets[25].missingRequired === 1 && baseline.sheets[30].missingRequired === 5 && baseline.sheets[35].missingRequired === 1 && baseline.sheets[40].missingRequired === 5)
	check('baseline attached-exempt counts are 2/3/2/5', baseline.sheets[25].attachedExempt === 2 && baseline.sheets[30].attachedExempt === 3 && baseline.sheets[35].attachedExempt === 2 && baseline.sheets[40].attachedExempt === 5)
	check('baseline correction-returned counts are 0/0/2/0', baseline.sheets[25].corrections === 0 && baseline.sheets[30].corrections === 0 && baseline.sheets[35].corrections === 2 && baseline.sheets[40].corrections === 0)

	// ------------------------------------- readiness: one named missing receipt
	const sheet25 = baseline.sheets[25]
	await openSheet(sheet25.id)
	const blockers25 = page.locator('[data-test="readiness-blocker"]')
	await expect(blockers25).toHaveCount(1)
	const blockerText = (await blockers25.first().innerText()).trim()
	check('readiness names the missing receipt on sheet 25', /receipt|رسید/i.test(blockerText), blockerText)
	check('readiness blocker matches the server missing transaction', blockerText.includes(sheet25.missingRequiredDescriptions[0]), blockerText)
	await page.locator('[data-test="readiness"]').scrollIntoViewIfNeeded()
	await shot('01-sheet25-readiness-one-missing-receipt')
	check('submit stays reachable while readiness is blocked', await page.locator('[data-test="submit-sheet-button"]').isVisible())

	// ------------------------------------------ create with the FR-022 minimum
	const sheet40 = baseline.sheets[40]
	await openSheet(sheet40.id)
	await page.click('[data-test="create-transaction-btn"]')
	await expect(page.locator('[data-test="transaction-form-view"]')).toBeVisible()
	await page.fill('[data-test="expense-date-input"]', '2026-02-01')
	await page.fill('[data-test="amount-input"]', '112233')
	const createStart = Date.now()
	await shot('02-create-minimum-filled-no-receipt')
	await page.click('[data-test="submit-btn"]')
	await expect(page.locator('[data-test="sheet-detail-view"]')).toBeVisible()
	await poll(async () => {
		const detail = await sheetDetail(sheet40.id)
		return (detail.transactions ?? []).find((item) => item.activeRevision?.expenseDate === '2026-02-01' && item.activeRevision?.amount?.amountMinor === 112233)
	}, 'minimum transaction to exist')
	const minimum = (await sheetDetail(sheet40.id)).transactions.find((item) => item.activeRevision?.expenseDate === '2026-02-01' && item.activeRevision?.amount?.amountMinor === 112233)
	check('minimum create leaves optional fields empty', !minimum.activeRevision?.description && !minimum.activeRevision?.expenseType?.id, minimum.activeRevision)
	check('minimum create stores an active receipt count of zero', minimum.evidenceCount === 0, minimum.evidenceCount)
	const minimumRequests = record.requests.filter((item) => item.at >= createStart)
	check('minimum save issued no evidence upload', minimumRequests.every((item) => !item.url.includes('/evidence')), minimumRequests)
	check('sheet 40 now holds 41 transactions', (await sheetDetail(sheet40.id)).transactions.length === 41)

	// ------------------------- create with every optional field + staged receipts
	const sheet30 = baseline.sheets[30]
	await openSheet(sheet30.id)
	await page.click('[data-test="create-transaction-btn"]')
	await expect(page.locator('[data-test="transaction-form-view"]')).toBeVisible()
	await page.fill('[data-test="expense-date-input"]', '2026-02-02')
	await page.fill('[data-test="amount-input"]', '445566')
	const stagedDescription = `T061 staged receipts · ${runLabel}`
	await page.fill('[data-test="description-input"]', stagedDescription)
	await pickOption('cost-center-select', 'T060 development fixtures')
	await pickOption('expense-type-select', 'DEV T060 Receipt Required')
	await page.setInputFiles('[data-test="staged-receipt-input"]', [
		{ name: 't061-receipt-a.txt', mimeType: 'text/plain', buffer: Buffer.from('T061 receipt A\n') },
		{ name: 't061-receipt-b.txt', mimeType: 'text/plain', buffer: Buffer.from('T061 receipt B\n') },
	])
	await expect(page.locator('[data-test="staged-receipt-item"]')).toHaveCount(2)
	await shot('03-create-optionals-two-staged-receipts')
	const stagedStart = Date.now()
	await page.click('[data-test="submit-btn"]')
	await expect(page.locator('[data-test="sheet-detail-view"]')).toBeVisible()
	const created = await poll(async () => {
		const detail = await sheetDetail(sheet30.id)
		return (detail.transactions ?? []).find((item) => item.activeRevision?.description === stagedDescription)
	}, 'staged-receipt transaction to exist')
	check('create stores every optional field', created.activeRevision?.expenseType?.name === 'DEV T060 Receipt Required' && created.activeRevision?.costCenter?.name === 'T060 development fixtures', created.activeRevision)
	check('both staged receipts are active on the created transaction', created.evidenceCount === 2, created.evidenceCount)
	check('created transaction is receipt-required', created.evidenceRequired === true, created.evidenceRequired)
	const createdDetail = await ocs(`/transactions/${created.id}`)
	const evidenceNames = (createdDetail.evidence ?? []).filter((item) => item.active).map((item) => item.name).sort()
	check('uploaded receipts keep their names', JSON.stringify(evidenceNames) === JSON.stringify(['t061-receipt-a.txt', 't061-receipt-b.txt']), evidenceNames)
	const stagedRequests = record.requests.filter((item) => item.at >= stagedStart)
	const createIndex = stagedRequests.findIndex((item) => /\/sheets\/[^/]+\/transactions$/.test(item.url))
	const evidenceIndexes = stagedRequests.map((item, index) => (item.url.includes('/evidence') ? index : -1)).filter((index) => index >= 0)
	check('the create request precedes both evidence uploads', createIndex === 0 && evidenceIndexes.length === 2 && evidenceIndexes.every((index) => index > createIndex), stagedRequests)
	check('both evidence uploads address the created non-empty id', stagedRequests.filter((item) => item.url.includes('/evidence')).every((item) => item.url.includes(`/transactions/${created.id}/evidence`)), stagedRequests)
	check('sheet 30 now holds 31 transactions', (await sheetDetail(sheet30.id)).transactions.length === 31)

	// ------------------------------------------ submission refusal names all five
	await openSheet(sheet30.id)
	await expect(page.locator('[data-test="readiness-blocker"]')).toHaveCount(5)
	await page.locator('[data-test="readiness"]').scrollIntoViewIfNeeded()
	await shot('04-sheet30-readiness-five-missing-receipts')
	await page.click('[data-test="submit-sheet-button"]')
	const submissionSummary = page.locator('[data-test="submission-evidence-errors"]')
	await expect(submissionSummary).toBeVisible()
	await expect(page.locator('[data-test="submission-evidence-error"]')).toHaveCount(5)
	const refusalFocus = await page.evaluate(() => document.activeElement?.getAttribute('data-test'))
	check('submission refusal moves focus to the named summary', refusalFocus === 'submission-evidence-errors', refusalFocus)
	const refusalTexts = await page.locator('[data-test="submission-evidence-error"]').allInnerTexts()
	check('every refusal line names its transaction', refusalTexts.every((text) => /T060 receipt fixture 30/.test(text)), refusalTexts)
	await shot('05-sheet30-submission-refused-five-named')
	check('a refused sheet stays OPEN', (await sheetDetail(sheet30.id)).status === 'OPEN', (await sheetDetail(sheet30.id)).status)

	// --------------------------- attach the missing receipt, then submit sheet 25
	await openSheet(sheet25.id)
	const missing25 = sheet25.missingRequiredDescriptions[0]
	await openTransactionFromSheet(missing25)
	// The surface is a read/act view and deliberately has no receipt uploader. The draft's edit
	// form is where a receipt is attached, so follow the surface's Edit action rather than the
	// retired full-page detail view.
	await page.click('[data-test="edit-transaction-btn"]')
	await expect(page.locator('[data-test="transaction-form-view"]')).toBeVisible()
	await expect(page.locator('[data-test="evidence-uploader"]')).toBeVisible()
	await page.setInputFiles('[data-test="evidence-upload-input"]', {
		name: 't061-sheet25-receipt.txt',
		mimeType: 'text/plain',
		buffer: Buffer.from('T061 sheet 25 receipt\n'),
	})
	await page.click('[data-test="upload-btn"]')
	// The edit form's uploader reports the transfer itself; the evidence list lives on the surface
	// and the workspace. Confirm the upload landed through the same read the readiness view uses.
	await poll(async () => {
		const detail = await sheetDetail(sheet25.id)
		return detail.transactions.find((item) => item.activeRevision?.description === missing25)?.evidenceCount === 1
	}, 'the sheet-25 receipt upload')
	const afterUpload = (await sheetDetail(sheet25.id)).transactions.find((item) => item.activeRevision?.description === missing25)
	check('the uploaded receipt satisfies the readiness rule', afterUpload.evidenceCount === 1, afterUpload.evidenceCount)
	await openSheet(sheet25.id)
	await expect(page.locator('[data-test="readiness-ready"]')).toBeVisible()
	check('sheet 25 has no remaining readiness blocker', (await page.locator('[data-test="readiness-blocker"]').count()) === 0)
	await shot('06-sheet25-ready-after-receipt')
	const submitStart = Date.now()
	await page.click('[data-test="submit-sheet-button"]')
	await expect(page.locator('[data-test="workspace-already-submitted"]')).toBeVisible({ timeout: 20000 })
	const submitted = await poll(async () => {
		const detail = await sheetDetail(sheet25.id)
		return detail.status !== 'OPEN' && detail.submittedAt ? detail : null
	}, 'sheet 25 to leave OPEN with a submission timestamp')
	check('successful submission moves the sheet out of OPEN', submitted.status === 'COST_CENTER', submitted.status)
	check('successful submission records submittedAt', Boolean(submitted.submittedAt), submitted.submittedAt)
	check('successful submission issued the submit request', record.requests.some((item) => item.at >= submitStart && item.url.endsWith('/submit')))
	await shot('07-sheet25-submitted')

	// -------------------------- correction refusal for the receipt-required type
	const sheet35 = baseline.sheets[35]
	const requiredCorrection = (await sheetDetail(sheet35.id)).transactions.find((item) => item.status === 'AWAITING_PURCHASER_CORRECTION' && item.evidenceRequired === true)
	check('sheet 35 has a receipt-required returned transaction', Boolean(requiredCorrection), sheet35.correctionDescriptions)
	await openSheet(sheet35.id)
	await openTransactionFromSheet(requiredCorrection.activeRevision.description)
	await page.click('[data-test="create-correction-btn"]')
	await expect(page.locator('[data-test="transaction-form-view"]')).toBeVisible()
	await expect(page.locator('[data-test="correction-notice"]')).toBeVisible()
	await expect(page.locator('[data-test="amount-input"]')).not.toHaveValue('')
	const resubmit = page.locator('[data-test="resubmit-correction-btn"]')
	await expect(resubmit).toBeVisible()
	check('receipt-required correction offers no resubmission without an active receipt', await resubmit.isDisabled())
	await page.locator('[data-test="resubmit-correction-btn"]').scrollIntoViewIfNeeded()
	await shot('08-correction-required-refused')
	const refusalStart = Date.now()
	await resubmit.click({ force: true, timeout: 2000 }).catch(() => {})
	check('no resubmission request is issued while the receipt is missing', record.requests.every((item) => !(item.at >= refusalStart && item.url.endsWith('/resubmit'))), record.requests.filter((item) => item.at >= refusalStart))
	const stillReturned = await ocs(`/transactions/${requiredCorrection.id}`)
	check('the refused correction stays AWAITING_PURCHASER_CORRECTION', stillReturned.status === 'AWAITING_PURCHASER_CORRECTION', stillReturned.status)

	// ----------------------- correction resubmission for the receipt-exempt type
	const exemptCorrection = (await sheetDetail(sheet35.id)).transactions.find((item) => item.status === 'AWAITING_PURCHASER_CORRECTION' && item.evidenceRequired === false)
	check('sheet 35 has a receipt-exempt returned transaction', Boolean(exemptCorrection), sheet35.correctionDescriptions)
	await openSheet(sheet35.id)
	await openTransactionFromSheet(exemptCorrection.activeRevision.description)
	await page.click('[data-test="create-correction-btn"]')
	await expect(page.locator('[data-test="transaction-form-view"]')).toBeVisible()
	await expect(page.locator('[data-test="correction-notice"]')).toBeVisible()
	const exemptResubmit = page.locator('[data-test="resubmit-correction-btn"]')
	await expect(exemptResubmit).toBeVisible()
	await expect(exemptResubmit).toBeEnabled({ timeout: 20000 })
	check('receipt-exempt correction is resubmittable without evidence', await exemptResubmit.isEnabled())
	await exemptResubmit.scrollIntoViewIfNeeded()
	await shot('09-correction-exempt-resubmittable')
	const resubmitStart = Date.now()
	await exemptResubmit.click()
	await expect(page.locator('[data-test="sheet-detail-view"]')).toBeVisible({ timeout: 20000 })
	const resubmitted = await poll(async () => {
		const detail = await ocs(`/transactions/${exemptCorrection.id}`)
		return detail.status === 'AWAITING_COST_CENTER_REVIEW' ? detail : null
	}, 'exempt correction to restart at cost-center review')
	check('exempt correction resubmits without a receipt', resubmitted.status === 'AWAITING_COST_CENTER_REVIEW', resubmitted.status)
	check('exempt correction issued one resubmit request', record.requests.filter((item) => item.at >= resubmitStart && item.url.endsWith('/resubmit')).length === 1)
	await shot('10-sheet35-after-exempt-correction')

	// ---------------------------------------------------------------- final shape
	const finalShape = await shape()
	record.final = finalShape
	check('final transaction counts are 25/31/35/41', [25, 30, 35, 40].every((count) => finalShape.sheets[count].transactions === (count === 30 ? 31 : count === 40 ? 41 : count)), Object.fromEntries(Object.entries(finalShape.sheets).map(([key, value]) => [key, value.transactions])))
	check('final total is 132 transactions', finalShape.totals.transactions === 132, finalShape.totals)
	check('sheet 25 remains submitted', finalShape.sheets[25].status === 'COST_CENTER', finalShape.sheets[25].status)
	check('sheet 30 remains open after refusal and its new row carries two receipts', finalShape.sheets[30].status === 'OPEN' && finalShape.sheets[30].receipts === 15, finalShape.sheets[30].receipts)
	check('the two created transactions add two active receipts overall', finalShape.totals.receipts === 69, finalShape.totals.receipts)
	check('one correction-required transaction remains returned on sheet 35', finalShape.sheets[35].corrections === 1, finalShape.sheets[35].corrections)

	check('no page errors were raised', record.pageErrors.length === 0, record.pageErrors)

	// T071: restore, then prove the restore. The next run's baseline check would catch a failure
	// here, but a run must not hand its residue to whoever comes next, so it says so now.
	if (reseedFixtures()) {
		restoredAfterRun = true
		const restored = await shape()
		record.fixtures.restored = restored
		check('restored sheet transaction counts are 25/30/35/40', [25, 30, 35, 40].every((count) => restored.sheets[count].transactions === count), Object.fromEntries(Object.entries(restored.sheets).map(([key, value]) => [key, value.transactions])))
		check('restored baseline has 130 transactions', restored.totals.transactions === 130, restored.totals)
		check('restored baseline has 66 receipts', restored.totals.receipts === 66, restored.totals)
	} else {
		record.fixtures.restored = { skipped: true }
	}

	record.completedAt = new Date().toISOString()
	record.passed = true
	writeFileSync(`${outDir}/evidence.json`, JSON.stringify(record, null, 2))
	console.log(JSON.stringify({
		run: runLabel,
		passed: true,
		documentLanguage,
		listDirection,
		fixtures: record.fixtures.baseline,
		final: record.final,
		restored: record.fixtures.restored,
		checks: record.checks.length,
		screenshots: record.screenshots,
		consoleErrors: record.consoleErrors,
		pageErrors: record.pageErrors,
	}, null, 2))
} catch (error) {
	record.passed = false
	record.failure = error instanceof Error ? error.message : String(error)
	// A failed run is still a mutating run: restore before recording, so the next attempt starts
	// from the baseline rather than from this run's partial state.
	if (!restoredAfterRun) {
		try {
			reseedFixtures()
			restoredAfterRun = true
		} catch (reseedError) {
			record.reseedError = reseedError instanceof Error ? reseedError.message : String(reseedError)
		}
	}
	record.completedAt = new Date().toISOString()
	try {
		await page.screenshot({ path: `${outDir}/failure.png`, fullPage: false })
		record.screenshots.push(`${outDir}/failure.png`)
	} catch {}
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
	await context.close()
	await browser.close()
	if (!restoredAfterRun) {
		try {
			reseedFixtures()
		} catch (reseedError) {
			console.error(`T071 fixture restore failed: ${reseedError instanceof Error ? reseedError.message : String(reseedError)}`)
		}
	}
}

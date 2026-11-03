/**
 * T072 browser evidence for 005 T064–T071.
 *
 * One-shot evidence runner (not a Playwright spec): it drives the real lab, records screenshots and
 * a JSON record, and exits non-zero on the first failed assertion.
 *
 * Usage:
 *   T072_LOCALE=en T072_VIEWPORT=desktop node tests/E2E/t072-transaction-surface-evidence.mjs
 *   T072_LOCALE=fa T072_VIEWPORT=mobile  node tests/E2E/t072-transaction-surface-evidence.mjs
 *
 * Constitution v1.4.0: the runner launches official Google Chrome only (`channel: 'chrome'`).
 * Screenshots and the JSON record are written under
 * `artifacts/visual-review/t072/<iteration>/<locale>-<viewport>/`. Set `T072_ITERATION` to a new
 * label for each owner-review round; the default is `iteration-1`. `/tmp/opencode/t072` holds
 * historical evidence only and is not a canonical destination for new runs.
 *
 * The T060 fixture namespace is reseeded before and after each run (T071). The run mutates it:
 * it uploads one SVG receipt, deletes draft transaction 01 (which has receipts, including the SVG)
 * and draft transaction 21 (which has none) from sheet 30, and never submits a sheet. The counts it
 * asserts are this run's expected mutations; the product baseline stays 25/30/35/40.
 *
 * Journeys:
 *   A. a list refetch while the sheet detail is loaded does not blank the workspace;
 *   B. a draft surface: one dialog, capability matrix, working-draft history, read-only and preview;
 *   C. inline-safe preview (text) and SVG download-only (no preview control);
 *   D. delete with evidence: one 204, same-id replay 204, new-id replay 404;
 *   E. delete without evidence: one 204;
 *   F. a returned transaction is read-only with Correction and no Edit/Delete.
 */

import { chromium, expect } from '@playwright/test'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { getUserLanguage, reseedT060Fixtures, setUserLanguage } from './t060-fixtures.mjs'

const base = process.env.PETTYCASH_BASE_URL ?? 'http://localhost:8081'
const locale = (process.env.T072_LOCALE ?? 'en').toLowerCase()
const viewportName = process.env.T072_VIEWPORT ?? 'desktop'
const width = viewportName === 'mobile' ? 390 : 1440
const height = viewportName === 'mobile' ? 844 : 900
const runLabel = `${locale}-${viewportName}`
const iteration = process.env.T072_ITERATION ?? 'iteration-1'
const outDir = `artifacts/visual-review/t072/${iteration}/${runLabel}`
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
	deletes: [],
	reseeds: [],
	fixtures: {},
}

function check(name, condition, detail = null) {
	record.checks.push({ name, passed: Boolean(condition), detail })
	if (!condition) throw new Error(`CHECK FAILED: ${name}${detail === null ? '' : ` — ${JSON.stringify(detail)}`}`)
}

function reseed(label) {
	const seeded = reseedT060Fixtures(user)
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
	page.on('request', (request) => {
		if (request.method() !== 'GET') {
			record.writes.push({ method: request.method(), url: request.url().replace(base, '') })
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
		await page.locator(`[data-sheet-open="${id}"]:visible`).first().click()
		await expect(page.locator('[data-test="sheet-detail-view"]')).toBeVisible()
		await expect(page.locator('[data-test="sheet-title"]')).toBeVisible()
	}

	async function openTransaction(description) {
		const row = page.locator(
			'[data-test="sheet-transaction-row"]:visible, [data-test^="sheet-transaction-card-"]:visible',
			{ hasText: description },
		).first()
		await expect(row).toBeVisible()
		await row.locator('[data-test="view-transaction"]').click()
		await expect(page.locator('[data-test="transaction-surface"]')).toBeVisible()
		// The surface opens on its own local loader; wait for the detail read to settle so the
		// action matrix is asserted against the loaded transaction, not the loading state.
		await expect(page.locator('[data-test="transaction-surface-active-revision"]')).toBeVisible()
	}

	async function closeSurface() {
		await page.locator('[data-test="transaction-surface"]').press('Escape')
		await expect(page.locator('[data-test="transaction-surface"]')).toHaveCount(0)
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

	// ---------------------------------------------------------- fixture lookup
	const sheet30 = await fixtureSheet(30)
	const sheet35 = await fixtureSheet(35)
	const sheet25 = await fixtureSheet(25)
	const detail30 = await sheetDetail(sheet30.id)
	const detail35 = await sheetDetail(sheet35.id)
	record.fixtures.baseline = {
		sheet30: { id: sheet30.id, transactions: detail30.transactions.length },
		sheet35: { id: sheet35.id, transactions: detail35.transactions.length },
	}
	check('fixture sheet 30 starts with 30 transactions', detail30.transactions.length === 30, detail30.transactions.length)
	check('fixture sheet 35 starts with 35 transactions', detail35.transactions.length === 35, detail35.transactions.length)

	const draftDescription = 'T060 receipt fixture 30 · transaction 01'
	const noEvidenceDescription = 'T060 receipt fixture 30 · transaction 21'
	const returnedDescription = 'T060 receipt fixture 35 · transaction 35'
	const draft = detail30.transactions.find((item) => item.activeRevision?.description === draftDescription)
	const noEvidence = detail30.transactions.find((item) => item.activeRevision?.description === noEvidenceDescription)
	const returned = detail35.transactions.find((item) => item.activeRevision?.description === returnedDescription)
	check('the draft-with-evidence fixture exists', Boolean(draft), draftDescription)
	check('the draft-without-evidence fixture exists', Boolean(noEvidence), noEvidenceDescription)
	check('the returned fixture exists', Boolean(returned), returnedDescription)
	check('the delete-with-evidence draft has receipts', draft.evidenceCount > 0, draft.evidenceCount)
	check('the second delete candidate has no receipts', noEvidence.evidenceCount === 0, noEvidence.evidenceCount)

	// ---------------------------------------- A. list refetch over loaded detail
	await openSheetsList()
	const language = await page.evaluate(() => document.documentElement.lang)
	const listDirection = await page.locator('[data-test="sheet-list-view"]').getAttribute('dir')
	check('document language matches the requested locale', language.toLowerCase().startsWith(locale), language)
	check('list direction matches the locale', listDirection === (locale === 'fa' ? 'rtl' : 'ltr'), listDirection)
	await shot('00-sheet-list')

	let listReads = 0
	page.on('request', (request) => {
		if (request.method() === 'GET' && /\/api\/v1\/sheets\?/.test(request.url())) listReads += 1
	})
	await openSheetFromList(sheet30.id)
	check('the workspace is a single dialog before a surface opens', await page.locator('[role="dialog"]').count() === 1, await page.locator('[role="dialog"]').count())
	const readsBeforeRefetch = listReads
	// The list is mounted underneath the workspace. Its filter form is the documented list-refresh
	// path; a programmatic submit is the only way to exercise it while the layer covers the list.
	await page.evaluate(() => {
		document.querySelector('[data-test="sheet-filter-apply"]')?.click()
	})
	await poll(() => listReads > readsBeforeRefetch, 'the background list refetch')
	await poll(async () => !(await page.locator('[data-test="sheet-loading"]').isVisible().catch(() => false)), 'no full-page loader')
	check('the loaded workspace stays rendered during a list refetch', await page.locator('[data-test="sheet-workspace"]').isVisible(), true)
	check('a list refetch does not show the full-page sheet loader', await page.locator('[data-test="sheet-loading"]').count() === 0, await page.locator('[data-test="sheet-loading"]').count())
	await shot('01-loaded-detail-during-list-refetch')

	// -------------------------------------- B. draft surface, matrix and history
	const writesBeforeSurface = record.writes.length
	await openTransaction(draftDescription)
	await expect(page.locator('[data-test="transaction-surface-title"]')).toBeVisible()
	check('the surface is addressed by ?transaction=', await page.evaluate(() => new URLSearchParams(location.hash.split('?')[1] ?? '').get('transaction')) === draft.id, page.url())
	check('opening the surface issues no write', record.writes.length === writesBeforeSurface, record.writes.slice(writesBeforeSurface))
	check('the transaction surface is the only dialog', await page.locator('[role="dialog"]').count() === 1, await page.locator('[role="dialog"]').count())
	check('a draft on an open sheet offers Edit', await page.locator('[data-test="edit-transaction-btn"]').isVisible(), true)
	check('a draft on an open sheet offers Delete', await page.locator('[data-test="delete-transaction-btn"]').isVisible(), true)
	check('a draft offers no Correction', await page.locator('[data-test="create-correction-btn"]').count() === 0, true)
	check('a draft is not marked read-only', await page.locator('[data-test="transaction-surface-readonly"]').count() === 0, true)
	check('a never-submitted draft shows working state', await page.locator('[data-test="transaction-surface-working-draft"]').isVisible(), true)
	check('a never-submitted draft shows no official revision rows', await page.locator('[data-test="transaction-surface-revision"]').count() === 0, await page.locator('[data-test="transaction-surface-revision"]').count())
	await shot('02-draft-surface-action-matrix')

	// ---------------------------------------------- C. inline-safe text preview
	const textRow = page.locator('[data-test="evidence-list-item"]', { hasText: 't060-receipt-01.txt' })
	await expect(textRow).toBeVisible()
	await textRow.locator('[data-test="preview-evidence-btn"]').click()
	await expect(page.locator('[data-test="evidence-preview"]')).toBeVisible()
	check('the preview is the only dialog', await page.locator('[role="dialog"]').count() === 1, await page.locator('[role="dialog"]').count())
	check('the surface beneath the preview is inert', await page.locator('[data-test="transaction-surface"]').getAttribute('inert') !== null, true)
	await expect(page.locator('[data-test="evidence-preview-text"]')).toBeVisible()
	const previewText = await page.locator('[data-test="evidence-preview-text"]').innerText()
	check('plain text preview shows the receipt text', previewText.includes('T060 receipt fixture 30'), previewText)
	await shot('03-inline-safe-text-preview')
	await page.locator('[data-test="evidence-preview"]').press('Escape')
	await expect(page.locator('[data-test="evidence-preview"]')).toHaveCount(0)
	check('closing the preview restores the surface dialog role', await page.locator('[data-test="transaction-surface"]').getAttribute('role') === 'dialog', await page.locator('[data-test="transaction-surface"]').getAttribute('role'))

	// --------------------------------------- C2. SVG is download-only in-app
	const upload = await page.evaluate(async ({ transactionId }) => {
		const token = document.head.getAttribute('data-requesttoken') ?? ''
		const form = new FormData()
		form.append('file', new File(
			['<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>window.__t072=1</script></svg>'],
			't072-download-only.svg',
			{ type: 'image/svg+xml' },
		))
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
		return { status: response.status, json: await response.json().catch(() => null) }
	}, { transactionId: draft.id })
	check('the SVG receipt uploads', upload.status >= 200 && upload.status < 300, upload)
	await closeSurface()
	await openTransaction(draftDescription)
	const svgRow = page.locator('[data-test="evidence-list-item"]', { hasText: 't072-download-only.svg' })
	await expect(svgRow).toBeVisible()
	check('an SVG has no preview control', await svgRow.locator('[data-test="preview-evidence-btn"]').count() === 0, true)
	check('an SVG keeps the download control', await svgRow.locator('[data-test="download-evidence-btn"]').isVisible(), true)
	const attachmentRequest = page.waitForRequest((request) => request.url().includes('/evidence/') && request.url().includes('disposition=attachment'))
	const download = page.waitForEvent('download')
	await svgRow.locator('[data-test="download-evidence-btn"]').click()
	await attachmentRequest
	const downloaded = await download
	check('an SVG downloads with its filename', downloaded.suggestedFilename() === 't072-download-only.svg', downloaded.suggestedFilename())
	await shot('04-svg-download-only')

	// ------------------------------- D. delete with evidence and replay answers
	const version = draft.version
	const writesBeforeDelete = record.writes.length
	await page.locator('[data-test="delete-transaction-btn"]').click()
	await expect(page.locator('[data-test="delete-transaction-confirm"]')).toBeVisible()
	check('the confirmation names the draft', (await page.locator('[data-test="delete-transaction-confirm"]').innerText()).includes(draftDescription), true)
	const deleteResponsePromise = page.waitForResponse((response) => response.request().method() === 'DELETE' && /\/transactions\/[^/]+$/.test(new URL(response.url()).pathname))
	const deleteRequestPromise = page.waitForRequest((request) => request.method() === 'DELETE' && /\/transactions\/[^/]+$/.test(new URL(request.url()).pathname))
	await page.locator('[data-test="confirm-delete-transaction"]').click()
	const deleteResponse = await deleteResponsePromise
	const deleteRequest = await deleteRequestPromise
	check('the confirmed delete answers 204', deleteResponse.status() === 204, deleteResponse.status())
	check('the confirmed delete sends exactly one DELETE', record.writes.length === writesBeforeDelete + 1, record.writes.slice(writesBeforeDelete))
	await poll(async () => (await page.locator('[data-test="transaction-surface"]').count()) === 0, 'the surface to close after a delete')
	check('the deleted row is gone from the workspace', await page.locator(`[data-test="view-transaction"][data-transaction-id="${draft.id}"]`).count() === 0, true)
	await shot('05-after-delete-with-evidence')

	const deleteHeaders = deleteRequest.headers()
	const commandId = deleteHeaders['x-pettycash-command-id']
	const payload = deleteRequest.postData()
	check('the delete carries a command id', Boolean(commandId), commandId)
	check('the delete carries the expected version', JSON.parse(payload).expectedVersion === version, payload)

	const replay = await page.evaluate(async ({ transactionId, command, body }) => {
		const token = document.head.getAttribute('data-requesttoken') ?? ''
		const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1/transactions/${transactionId}`, {
			method: 'DELETE',
			headers: {
				Accept: 'application/json',
				'OCS-APIRequest': 'true',
				requesttoken: token,
				'Content-Type': 'application/json',
				'X-PettyCash-Command-ID': command,
			},
			body,
		})
		return response.status
	}, { transactionId: draft.id, command: commandId, body: payload })
	check('the same command id and payload replay 204 after deletion', replay === 204, replay)

	const differentId = await page.evaluate(async ({ transactionId, body }) => {
		const token = document.head.getAttribute('data-requesttoken') ?? ''
		const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1/transactions/${transactionId}`, {
			method: 'DELETE',
			headers: {
				Accept: 'application/json',
				'OCS-APIRequest': 'true',
				requesttoken: token,
				'Content-Type': 'application/json',
				'X-PettyCash-Command-ID': crypto.randomUUID(),
			},
			body,
		})
		return response.status
	}, { transactionId: draft.id, body: payload })
	check('a new command id after deletion answers 404', differentId === 404, differentId)
	record.deletes.push({ transactionId: draft.id, version, commandId, replayStatus: replay, differentIdStatus: differentId })

	// ------------------------------------------ E. delete without evidence
	await openTransaction(noEvidenceDescription)
	check('the second draft has no evidence', await page.locator('[data-test="evidence-list-item"]').count() === 0, await page.locator('[data-test="evidence-list-item"]').count())
	const noEvidenceDelete = page.waitForResponse((response) => response.request().method() === 'DELETE' && response.url().includes(noEvidence.id))
	await page.locator('[data-test="delete-transaction-btn"]').click()
	await page.locator('[data-test="confirm-delete-transaction"]').click()
	check('a draft without evidence deletes with 204', (await noEvidenceDelete).status() === 204, true)
	await poll(async () => (await page.locator('[data-test="transaction-surface"]').count()) === 0, 'the surface to close after the second delete')
	await shot('06-after-delete-without-evidence')

	const afterDeletes = await sheetDetail(sheet30.id)
	check('sheet 30 lost exactly the two deleted drafts', afterDeletes.transactions.length === 28, afterDeletes.transactions.length)

	// ------------------------- F. returned transaction: Correct, never Edit/Delete
	await page.locator('[data-test="close-sheet-workspace"]').click()
	await expect(page.locator('[data-test="sheet-detail-view"]')).toHaveCount(0)
	await openSheetFromList(sheet35.id)
	await openTransaction(returnedDescription)
	check('a returned transaction offers Correction', await page.locator('[data-test="create-correction-btn"]').isVisible(), true)
	check('a returned transaction offers no Edit', await page.locator('[data-test="edit-transaction-btn"]').count() === 0, true)
	check('a returned transaction offers no Delete', await page.locator('[data-test="delete-transaction-btn"]').count() === 0, true)
	check('a returned transaction lists its official revision', await page.locator('[data-test="transaction-surface-revision"]').count() === 1, await page.locator('[data-test="transaction-surface-revision"]').count())
	check('a returned transaction with no correction draft has no working-correction block', await page.locator('[data-test="transaction-surface-working-correction"]').count() === 0, true)
	await shot('07-returned-transaction-readonly')
	await closeSurface()

	// ------------------------- G. submitted transaction is read-only with workflow
	await page.locator('[data-test="close-sheet-workspace"]').click()
	await expect(page.locator('[data-test="sheet-detail-view"]')).toHaveCount(0)

	const detail25 = await sheetDetail(sheet25.id)
	const missingReceipt = detail25.transactions.find((item) => item.evidenceRequired === true && item.evidenceCount === 0)
	check('sheet 25 has exactly one missing required receipt', Boolean(missingReceipt), missingReceipt?.id)
	const upload25 = await page.evaluate(async ({ transactionId }) => {
		const token = document.head.getAttribute('data-requesttoken') ?? ''
		const form = new FormData()
		form.append('file', new File(['T072 sheet 25 receipt\n'], 't072-sheet25-receipt.txt', { type: 'text/plain' }))
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
	}, { transactionId: missingReceipt.id })
	check('the missing sheet-25 receipt uploads', upload25.status >= 200 && upload25.status < 300, upload25)

	await openSheetFromList(sheet25.id)
	await page.locator('[data-test="submit-sheet-button"]').click()
	await poll(async () => (await sheetDetail(sheet25.id)).status !== 'OPEN', 'sheet 25 to leave OPEN')
	await poll(async () => (await page.locator('[data-test="read-only-badge"]').count()) > 0, 'the workspace read-only state')
	await shot('08-submitted-sheet-readonly-workspace')

	await page.locator('[data-test="view-transaction"]:visible').first().click()
	await expect(page.locator('[data-test="transaction-surface"]')).toBeVisible()
	await expect(page.locator('[data-test="transaction-surface-active-revision"]')).toBeVisible()
	check('a submitted transaction is marked read-only', await page.locator('[data-test="transaction-surface-readonly"]').isVisible(), true)
	check('a submitted transaction offers no Edit', await page.locator('[data-test="edit-transaction-btn"]').count() === 0, true)
	check('a submitted transaction offers no Delete', await page.locator('[data-test="delete-transaction-btn"]').count() === 0, true)
	check('a submitted transaction offers no Correction', await page.locator('[data-test="create-correction-btn"]').count() === 0, true)
	check('a submitted transaction shows its workflow stage', await page.locator('[data-test="transaction-surface-stage"]').isVisible(), true)
	check('a submitted transaction shows its decisions section', await page.locator('[data-test="transaction-surface-decisions"]').isVisible(), true)
	check('a submitted transaction shows no working-draft state', await page.locator('[data-test="transaction-surface-working-draft"]').count() === 0, true)
	await shot('09-submitted-transaction-readonly')
	await closeSurface()

	// ----------------------------------------- layout at the 390px viewport
	if (viewportName === 'mobile') {
		await openTransaction('T060 receipt fixture 25 · transaction 01')
		const overflow = await page.evaluate(() => ({
			scrollWidth: document.documentElement.scrollWidth,
			clientWidth: document.documentElement.clientWidth,
		}))
		check('the transaction surface has no horizontal scroll at 390px', overflow.scrollWidth <= overflow.clientWidth + 1, overflow)
		check('the surface remains the only dialog at 390px', await page.locator('[role="dialog"]').count() === 1, await page.locator('[role="dialog"]').count())
		const textRow = page.locator('[data-test="evidence-list-item"]').first()
		if (await textRow.locator('[data-test="preview-evidence-btn"]').count()) {
			await textRow.locator('[data-test="preview-evidence-btn"]').click()
			const previewOverflow = await page.evaluate(() => ({
				scrollWidth: document.documentElement.scrollWidth,
				clientWidth: document.documentElement.clientWidth,
			}))
			check('the preview has no horizontal scroll at 390px', previewOverflow.scrollWidth <= previewOverflow.clientWidth + 1, previewOverflow)
			check('the preview is the only dialog at 390px', await page.locator('[role="dialog"]').count() === 1, await page.locator('[role="dialog"]').count())
			await page.locator('[data-test="evidence-preview"]').press('Escape')
		}
		await closeSurface()
	}

	// ------------------------------------------------------- focus return
	await page.locator('[data-test="close-sheet-workspace"]').click()
	await expect(page.locator('[data-test="sheet-detail-view"]')).toHaveCount(0)
	await openSheetFromList(sheet30.id)
	const focusTarget = await page.locator('[data-test="view-transaction"]:visible').first().getAttribute('data-transaction-id')
	await page.locator(`[data-test="view-transaction"][data-transaction-id="${focusTarget}"]:visible`).first().click()
	await expect(page.locator('[data-test="transaction-surface"]')).toBeVisible()
	await closeSurface()
	await poll(async () => await page.evaluate((id) => {
		const active = document.activeElement
		return active instanceof HTMLElement && active.dataset.test === 'view-transaction' && active.dataset.transactionId === id
	}, focusTarget), 'focus to return to the invoking row', 3000)
	check('closing the surface returns focus to its row action', true, true)

	check('no page errors were raised', record.pageErrors.length === 0, record.pageErrors)
	record.completedAt = new Date().toISOString()
	record.passed = true
	writeFileSync(`${outDir}/evidence.json`, JSON.stringify(record, null, 2))
	console.log(JSON.stringify({
		run: runLabel,
		passed: true,
		language,
		listDirection,
		checks: record.checks.length,
		screenshots: record.screenshots,
		consoleErrors: record.consoleErrors,
		pageErrors: record.pageErrors,
		fixtures: record.fixtures,
		deletes: record.deletes,
		reseeds: record.reseeds,
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
	// T071: this runner mutates the fixture namespace; never hand its residue to the next run.
	try {
		restored = reseed('after')
	} catch (error) {
		record.reseedError = error instanceof Error ? error.message : String(error)
		console.error(`T072 fixture restore failed: ${record.reseedError}`)
	}
	try {
		setUserLanguage(user, previousLanguage || 'en')
	} catch (error) {
		console.error(`T072 language restore failed: ${error instanceof Error ? error.message : String(error)}`)
	}
	if (context) await context.close().catch(() => undefined)
	if (browser) await browser.close().catch(() => undefined)
	if (!restored) console.error('T072 fixture restore was skipped')
}

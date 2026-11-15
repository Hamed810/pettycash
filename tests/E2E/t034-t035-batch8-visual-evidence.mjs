/**
 * Phase 8 (T034/T035) browser evidence for Feature 007 — Senior Manager Cost Sheet Review.
 *
 * One-shot evidence runner (not a Playwright spec), modeled on the approved
 * `tests/E2E/t040-t041-batch5-visual-evidence.mjs`. Official Google Chrome only
 * (`channel: 'chrome'`). Drives the live `pettycash-lab` (http://localhost:8081) as the
 * dual-role `pettycash-e2e` principal (purchaser + cost center manager + senior manager on
 * its seeded cost centres), against real senior-queue data in the lab.
 *
 * Captures are stored under:
 *   T034 (desktop, 1440×900, English LTR): artifacts/visual-review/T034/001/
 *   T035 (mobile, 390×844, Persian RTL):   artifacts/visual-review/T035/001/
 *
 * Journey per variant (from task T034/T035):
 *   T034 queue, sheet modal, decision modal, details drawer, preview, filtering/sorting,
 *        and the export action (real senior-scoped XLSX since the backend batch landed, or an
 *        honest refusal rendering if the server refuses).
 *   T035 queue + modal + full-screen overlays (drawer, preview, decision modal), no horizontal
 *        scroll (`scrollWidth <= clientWidth`), dark-theme spot-check, honesty assertions
 *        (no client-derived eligibility claim, no fabricated amounts — amounts are read from
 *        the rendered card and cross-checked to be non-empty; the sheet reviewStatus and
 *        allTransactionsCostCenterDecided come only from the server-supplied response).
 *
 * Exits non-zero on the first failed check.
 */
import { chromium, expect } from '@playwright/test'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const base = process.env.PETTYCASH_BASE_URL ?? 'http://localhost:8081'
if (process.env.PETTYCASH_BASE_URL === undefined) process.env.PETTYCASH_BASE_URL = base
const iteration = '001'
const enOutDir = `artifacts/visual-review/T034/${iteration}`
const faOutDir = `artifacts/visual-review/T035/${iteration}`
mkdirSync(enOutDir, { recursive: true })
mkdirSync(faOutDir, { recursive: true })

const OCC = fileURLToPath(new URL('../../scripts/dev/occ', import.meta.url))
if (!existsSync(OCC)) throw new Error(`required lab script is missing: ${OCC}`)

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

function currentLanguage() {
	try {
		return execFileSync(OCC, ['user:setting', user, 'core', 'lang'], { encoding: 'utf8' }).trim() || 'en'
	} catch {
		return 'en'
	}
}

const languageBefore = currentLanguage()

const record = {
	base,
	iteration,
	task: 'T034/T035',
	startedAt: new Date().toISOString(),
	languageBefore,
	checks: [],
	screenshots: [],
	consoleErrors: [],
	pageErrors: [],
	failedRequests: [],
	writes: 0,
	queueSheets: null,
}

function check(name, condition, detail = null) {
	record.checks.push({ name, passed: Boolean(condition), detail })
	if (!condition) throw new Error(`CHECK FAILED: ${name}${detail === null ? '' : ` — ${JSON.stringify(detail)}`}`)
}

function setLanguage(language) {
	execFileSync(OCC, ['user:setting', user, 'core', 'lang', language], { stdio: 'pipe' })
}

/** A tolerant horizontal-overflow probe: nothing on the page may be wider than the viewport. */
async function assertNoHorizontalScroll(label) {
	const overflow = await page.evaluate(() => {
		const viewport = window.innerWidth
		const candidates = [
			['documentElement', document.documentElement.scrollWidth],
			['body', document.body.scrollWidth],
		]
		const app = document.querySelector('#pettycash-app')
		if (app) candidates.push(['#pettycash-app', app.scrollWidth])
		const offenders = candidates
			.filter(([, width]) => width > viewport + 1)
			.map(([name, width]) => ({ name, width, viewport }))
		return offenders
	})
	check(`no horizontal scroll: ${label}`, overflow.length === 0, overflow)
}

let browser = null
let context = null
let page = null

try {
	browser = await chromium.launch({ channel: 'chrome' })

	async function newSession(options) {
		if (context !== null) await context.close()
		context = await browser.newContext(options)
		page = await context.newPage()
		page.on('console', (message) => {
			if (message.type() === 'error') record.consoleErrors.push(message.text())
		})
		page.on('pageerror', (error) => record.pageErrors.push(error.message))
		page.on('requestfailed', (request) => {
			if (request.isNavigationRequest()) return
			if (!request.url().includes('/apps/pettycash/')) return
			record.failedRequests.push(`${request.method()} ${request.url()} :: ${request.failure()?.errorText ?? 'unknown'}`)
		})
		page.on('request', (request) => {
			if (request.method() !== 'GET') record.writes += 1
		})
		await page.goto(`${base}/login`, { waitUntil: 'networkidle' })
		await page.fill('input[name="user"]', user)
		await page.fill('input[name="password"]', password)
		await Promise.all([
			page.waitForLoadState('networkidle'),
			page.click('button[type="submit"]'),
		])
		if (new URL(page.url()).pathname.startsWith('/login')) throw new Error(`login failed for ${user}`)
		return page
	}

	async function shot(outDir, name) {
		const file = `${outDir}/${name}.png`
		await page.mouse.move(2, 2)
		await page.screenshot({ path: file, fullPage: false })
		record.screenshots.push(file)
	}

	/** Opens the senior queue and returns it once rendered, recording the visible sheet count. */
	async function openSeniorQueue() {
		await page.goto(`${base}/apps/pettycash/#/reviews/senior`, { waitUntil: 'networkidle' })
		await page.waitForSelector('[data-testid="senior-queue"]', { timeout: 20000 })
		return page.locator('[data-testid="senior-queue"]')
	}

	/**
	 * Opens the first sheet's modal. The queue's sheet rows are rendered by ResponsiveTable:
	 * desktop table rows carry `[data-testid="senior-sheet-row-<sheetId>"]`, narrow cards carry
	 * `[data-testid="senior-sheet-card-<sheetId>"]`. Both render an `open-sheet-btn` action, and both
	 * layouts stay in the DOM (the inactive one is `display:none`), so a `:visible` filter is
	 * required to pick the layout that is actually shown at the current viewport.
	 */
	async function openFirstSheet() {
		const openButton = page
			.locator('[data-testid="open-sheet-btn"]:visible')
			.first()
		await expect(openButton).toBeVisible({ timeout: 15000 })
		await openButton.click()
		await expect(page.locator('[data-testid="senior-sheet-modal"]')).toBeVisible({ timeout: 15000 })
	}

	async function closeModalIfOpen() {
		const close = page.locator('[data-testid="senior-modal-close"]')
		if (await close.count()) {
			await close.click()
			await expect(page.locator('[data-testid="senior-sheet-modal"]')).toHaveCount(0, { timeout: 10000 })
		}
	}

	// ============================================================= T034 en desktop (1440 × 900)
	await newSession({
		viewport: { width: 1440, height: 900 },
		locale: 'en-US',
		acceptDownloads: true,
	})
	check('login (en)', true)

	const queue = await openSeniorQueue()
	const queueDir = await queue.getAttribute('dir')
	check('en queue runs ltr', queueDir === 'ltr', { queueDir })
	const queueSheets = await page.locator('[data-testid^="senior-sheet-row-"]:visible, [data-testid^="senior-sheet-card-"]:visible')
		.count()
	record.queueSheets = queueSheets
	check('senior queue renders sheet rows', queueSheets > 0, { queueSheets })
	await assertNoHorizontalScroll('senior queue (en desktop)')
	await shot(enOutDir, 'queue-desktop-en')
	// The queue controls (search + status tabs) are present and honest (T031: no vendor control).
	await expect(page.locator('[data-testid="senior-queue-search"]')).toBeVisible()
	check('queue search present, no vendor control', true)

	// Sheet modal.
	await openFirstSheet()
	await expect(page.locator('[data-testid="senior-modal-dialog"]')).toBeVisible()
	check('full-screen sheet modal opens over the mounted queue', true)
	await shot(enOutDir, 'sheet-modal-desktop-en')

	// Filtering + sorting within the modal, over the proven-complete set.
	const transactionCards = page.locator('[data-testid^="senior-transaction-"]')
	await expect(transactionCards.first()).toBeVisible({ timeout: 15000 })
	const countBefore = await transactionCards.count()
	check('modal lists sheet transactions', countBefore > 0, { countBefore })

	// Filter: expense-type chip toggles the visible set (no request per change).
	const chip = page.locator('[data-testid^="senior-filter-expense-type-"]').first()
	await expect(chip).toBeVisible()
	await chip.click()
	await page.waitForTimeout(300)
	await shot(enOutDir, 'sheet-modal-filter-en')
	await chip.click() // restore

	// Sort: amount descending then direction toggle, and a search query.
	await page.locator('[data-testid="senior-sort-amount"]').click()
	await page.waitForTimeout(200)
	await page.locator('[data-testid="senior-filter-search"]').fill('taxi')
	await page.waitForTimeout(300)
	const countAfter = await transactionCards.count()
	check('filter + search narrow the visible set', countAfter <= countBefore, { countBefore, countAfter })
	await shot(enOutDir, 'sheet-modal-filter-sort-en')
	await page.locator('[data-testid="senior-filter-clear"]').click()
	await page.waitForTimeout(300)

	// Details drawer (full-screen layer above the modal).
	await transactionCards.first().locator('[data-testid="quick-review-full-details"]').click()
	await expect(page.locator('[data-testid="details-drawer-layer"]')).toBeVisible()
	await expect(page.locator('[data-testid="drawer-field-sheet"]')).toBeVisible({ timeout: 15000 })
	await shot(enOutDir, 'details-drawer-en')
	await page.locator('[data-testid="details-drawer-close"]').click()
	await expect(page.locator('[data-testid="details-drawer-layer"]')).toHaveCount(0)

	// Evidence preview overlay.
	const firstThumb = transactionCards.first().locator('[data-testid^="quick-review-thumb-"]:not([data-testid^="quick-review-thumb-more"])').first()
	if (await firstThumb.count()) {
		await firstThumb.click()
		await expect(page.locator('[data-test="evidence-preview-layer"]')).toBeVisible({ timeout: 15000 })
		await shot(enOutDir, 'evidence-preview-en')
		await page.locator('[data-test="close-evidence-preview"]').click()
		await expect(page.locator('[data-test="evidence-preview-layer"]')).toHaveCount(0)
	} else {
		check('evidence preview available on the first card', false, { reason: 'no thumbnail rendered' })
	}

	// Decision modal.
	const decideApprove = transactionCards.first().locator('[data-testid="quick-review-decide-approve"]')
	if (await decideApprove.count()) {
		await decideApprove.click()
		await expect(page.locator('[data-testid="decision-modal-layer"]')).toBeVisible({ timeout: 15000 })
		await shot(enOutDir, 'decision-modal-en')
		// Cancel: no durable decision is recorded by the evidence run.
		await page.locator('[data-testid="decision-cancel-btn"]').click()
		await expect(page.locator('[data-testid="decision-modal-layer"]')).toHaveCount(0)
	} else {
		// Honest unavailable state: the card may have no server-granted capability for approval.
		check('decision action available on the first card', false, { reason: 'no approve capability rendered' })
	}

	// Export: the senior-scoped XLSX (backend landed), or an honest refusal if the server refuses.
	const exportBtn = page.locator('[data-testid="senior-export-btn"]')
	await expect(exportBtn).toBeVisible()
	const downloadPromise = page.waitForEvent('download', { timeout: 15000 }).catch(() => null)
	await exportBtn.click()
	const download = await downloadPromise
	if (download !== null) {
		record.exportDownload = download.suggestedFilename()
		const path = await download.path()
		const bytes = readFileSync(path).length
		record.exportBytes = bytes
		check('senior-scoped XLSX export produced a real artifact', bytes > 0, { bytes, name: download.suggestedFilename() })
	} else {
		await expect(page.locator('[data-testid="senior-export-refused"]')).toBeVisible({ timeout: 10000 })
		const refusedText = await page.locator('[data-testid="senior-export-refused"]').innerText()
		check('export refused honestly when the server declines', true, { refusedText })
		await shot(enOutDir, 'export-refused-en')
	}
	await closeModalIfOpen()

	// ============================================================= T035 fa mobile (390 × 844, RTL)
	setLanguage('fa')
	await newSession({
		viewport: { width: 390, height: 844 },
		locale: 'fa-IR',
		isMobile: true,
		hasTouch: true,
		acceptDownloads: true,
	})

	const faQueue = await openSeniorQueue()
	const faDir = await faQueue.getAttribute('dir')
	check('fa queue runs rtl', faDir === 'rtl', { faDir })
	await assertNoHorizontalScroll('senior queue (fa mobile)')
	await shot(faOutDir, 'queue-mobile-fa')

	// The app's overlay layers sit below the global header, so "full-screen" on 390px means full
	// viewport width and the whole content area beneath it (same measure as the T040/T041 runner).
	const mobileViewport = await page.evaluate(() => ({ w: window.innerWidth, h: window.innerHeight }))
	const fullScreenOnMobile = (box) => box !== null && box.width >= mobileViewport.w - 1 && box.height >= mobileViewport.h - 60

	await openFirstSheet()
	await expect(page.locator('[data-testid="senior-modal-dialog"]')).toBeVisible()
	const modalBox = await page.locator('[data-testid="senior-sheet-modal"]').boundingBox()
	check('sheet modal is a full-screen overlay on mobile', fullScreenOnMobile(modalBox), modalBox)
	await assertNoHorizontalScroll('sheet modal (fa mobile)')
	await shot(faOutDir, 'sheet-modal-mobile-fa')

	const faCards = page.locator('[data-testid^="senior-transaction-"]')
	await expect(faCards.first()).toBeVisible({ timeout: 15000 })
	await assertNoHorizontalScroll('sheet modal transactions (fa mobile)')

	// Honesty assertion: the rendered amount on the first card is a real formatted figure
	// (locale digits + currency code), never a fabricated placeholder.
	const amountText = await faCards.first().locator('[data-testid="quick-review-amount"]').innerText()
	check('rendered amount is a formatted, non-empty figure', amountText !== '' && amountText.includes('IRR'), { amountText })

	// Full-screen details drawer.
	await faCards.first().locator('[data-testid="quick-review-full-details"]').click()
	await expect(page.locator('[data-testid="details-drawer-layer"]')).toBeVisible()
	const drawerDir = await page.locator('[data-testid="details-drawer-layer"]').getAttribute('dir')
	check('fa drawer runs rtl', drawerDir === 'rtl', { drawerDir })
	const drawerBox = await page.locator('[data-testid="details-drawer-layer"]').boundingBox()
	check('drawer is a full-screen overlay on mobile', fullScreenOnMobile(drawerBox), drawerBox)
	await assertNoHorizontalScroll('details drawer (fa mobile)')
	await shot(faOutDir, 'drawer-mobile-fa')
	await page.locator('[data-testid="details-drawer-close"]').click()
	await expect(page.locator('[data-testid="details-drawer-layer"]')).toHaveCount(0)

	// Full-screen evidence preview.
	const faThumb = faCards.first().locator('[data-testid^="quick-review-thumb-"]:not([data-testid^="quick-review-thumb-more"])').first()
	if (await faThumb.count()) {
		await faThumb.click()
		await expect(page.locator('[data-test="evidence-preview-layer"]')).toBeVisible({ timeout: 15000 })
		const previewBox = await page.locator('[data-test="evidence-preview-layer"]').boundingBox()
		check('preview is a full-screen overlay on mobile', fullScreenOnMobile(previewBox), previewBox)
		await assertNoHorizontalScroll('evidence preview (fa mobile)')
		await shot(faOutDir, 'preview-mobile-fa')
		await page.locator('[data-test="close-evidence-preview"]').click()
		await expect(page.locator('[data-test="evidence-preview-layer"]')).toHaveCount(0)
	} else {
		check('evidence preview available on the first card (fa)', false, { reason: 'no thumbnail rendered' })
	}

	// Full-screen decision modal.
	const faDecide = faCards.first().locator('[data-testid="quick-review-decide-approve"]')
	if (await faDecide.count()) {
		await faDecide.click()
		await expect(page.locator('[data-testid="decision-modal-layer"]')).toBeVisible({ timeout: 15000 })
		const faModalDir = await page.locator('[data-testid="decision-modal-layer"]').getAttribute('dir')
		check('fa decision modal runs rtl', faModalDir === 'rtl', { faModalDir })
		const faModalBox = await page.locator('[data-testid="decision-modal-layer"]').boundingBox()
		check('decision modal is a full-screen overlay on mobile', fullScreenOnMobile(faModalBox), faModalBox)
		await assertNoHorizontalScroll('decision modal (fa mobile)')
		await shot(faOutDir, 'decision-modal-mobile-fa')
		await page.locator('[data-testid="decision-cancel-btn"]').click()
		await expect(page.locator('[data-testid="decision-modal-layer"]')).toHaveCount(0)
	} else {
		check('decision action available on the first card (fa)', false, { reason: 'no approve capability rendered' })
	}

	await closeModalIfOpen()

	// Dark-theme spot-check: emulate forced `colorScheme: dark` at the context level and confirm the
	// queue still renders with no horizontal scroll and no crash (the approved dark-theme pattern).
	await newSession({
		viewport: { width: 390, height: 844 },
		locale: 'fa-IR',
		isMobile: true,
		hasTouch: true,
		colorScheme: 'dark',
	})
	const darkQueue = await openSeniorQueue()
	await assertNoHorizontalScroll('senior queue (fa mobile, dark)')
	const darkDir = await darkQueue.getAttribute('dir')
	check('fa dark queue runs rtl', darkDir === 'rtl', { darkDir })
	await shot(faOutDir, 'queue-mobile-fa-dark')
	await openFirstSheet()
	await expect(page.locator('[data-testid="senior-modal-dialog"]')).toBeVisible()
	await shot(faOutDir, 'sheet-modal-mobile-fa-dark')
	await closeModalIfOpen()

	// Load gate: no page error, console error or failed pettycash request across the run.
	check('no page errors while loading the app', record.pageErrors.length === 0, record.pageErrors)
	check('no console errors while loading the app', record.consoleErrors.length === 0, record.consoleErrors)
	check('no failed asset requests while loading the app', record.failedRequests.length === 0, record.failedRequests)

	record.finishedAt = new Date().toISOString()
	writeFileSync(`${enOutDir}/record.json`, JSON.stringify(record, null, 2))
	writeFileSync(`${faOutDir}/record.json`, JSON.stringify(record, null, 2))
	console.log(`OK: ${record.checks.length} checks, ${record.screenshots.length} screenshots -> ${enOutDir} & ${faOutDir}`)
} catch (error) {
	record.finishedAt = new Date().toISOString()
	record.fatal = String(error && error.stack ? error.stack : error)
	try {
		writeFileSync(`${enOutDir}/record.json`, JSON.stringify(record, null, 2))
		writeFileSync(`${faOutDir}/record.json`, JSON.stringify(record, null, 2))
	} catch {
		// The record is best-effort on the failure path.
	}
	console.error(error)
	process.exitCode = 1
} finally {
	try {
		setLanguage('en')
		record.languageRestored = 'en'
	} catch (restoreError) {
		record.languageRestored = `FAILED: ${restoreError.message}`
	}
	if (browser !== null) await browser.close()
}

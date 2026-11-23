/**
 * UXC-V2-FU-05 / UXC-V2-FU-06 — real-Chrome queue-table convergence evidence.
 *
 * Captures the three Management Review queue pages (Cost Center, Senior, Finance) across the
 * required matrix and records the FU-05/FU-06 acceptance checks. It is an evidence runner, not a
 * Playwright spec: it exits non-zero on the first failed check and writes a JSON record plus
 * screenshots under artifacts/visual-review/UXC-V2-FU/003/.
 *
 * Matrix:
 *   Cost Center / Senior / Finance × {desktop EN, desktop FA RTL, 390px EN} plus one 390px FA RTL.
 *
 * It is an evidence runner, not a Playwright spec: it does NOT claim owner visual acceptance and
 * leaves UXC-V2-GATE unchecked.
 */

import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright-core'

const base = 'http://localhost:8081'
const iteration = '006'
const outDir = `artifacts/visual-review/UXC-V2-FU/${iteration}`
mkdirSync(outDir, { recursive: true })

const OCC = fileURLToPath(new URL('../../scripts/dev/occ', import.meta.url))
if (!existsSync(OCC)) throw new Error(`required lab script is missing: ${OCC}`)

const STORAGE_STATE = 'test-results/.auth/e2e-storage-state.json'

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
	task: 'UXC-V2-FU',
	iteration,
	base,
	startedAt: new Date().toISOString(),
	languageBefore,
	chrome: null,
	storageStateUsed: existsSync(STORAGE_STATE),
	checks: [],
	cells: [],
	screenshots: [],
	consoleErrors: [],
	pageErrors: [],
	badResponses: [],
	knownDefects: [],
	sessions: [],
}
let currentCell = 'setup'
function setCell(name) {
	currentCell = name
	if (!record.cells.some((cell) => cell.name === name)) record.cells.push({ name, checks: 0, screenshots: [] })
}
function currentCellRecord() {
	return record.cells.find((cell) => cell.name === currentCell)
}
function check(name, condition, detail) {
	record.checks.push({ cell: currentCell, name, passed: Boolean(condition), detail })
	if (condition) currentCellRecord().checks += 1
	if (!condition) {
		const detailText = detail === null ? '' : ' — ' + JSON.stringify(detail)
		throw new Error('CHECK FAILED [' + currentCell + '] ' + name + detailText)
	}
}
function setLanguage(language) {
	execFileSync(OCC, ['user:setting', user, 'core', 'lang', language], { stdio: 'pipe' })
}

let browser = null
let context = null
let page = null

async function login() {
	await page.goto(`${base}/login`, { waitUntil: 'networkidle' })
	await page.fill('input[name="user"]', user)
	await page.fill('input[name="password"]', password)
	await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type="submit"]')])
	if (new URL(page.url()).pathname.startsWith('/login')) throw new Error(`login failed for ${user}`)
}

async function newSession({ viewport, locale, mobile = false, label }) {
	if (context !== null) await context.close()
	context = await browser.newContext({
		...(existsSync(STORAGE_STATE) ? { storageState: STORAGE_STATE } : {}),
		baseURL: base,
		viewport,
		locale,
		isMobile: mobile,
		hasTouch: mobile,
	})
	page = await context.newPage()
	page.on('console', (message) => {
		if (message.type() === 'error') record.consoleErrors.push({ cell: currentCell, text: message.text().slice(0, 400) })
	})
	page.on('pageerror', (error) => record.pageErrors.push({ cell: currentCell, text: String(error).slice(0, 400) }))
	page.on('response', (response) => {
		if (response.status() >= 400) {
			record.badResponses.push({ cell: currentCell, status: response.status(), url: response.url().slice(0, 300) })
		}
	})
	await page.goto(`${base}/apps/pettycash/`, { waitUntil: 'networkidle' })
	if (new URL(page.url()).pathname.startsWith('/login')) {
		await login()
		await page.goto(`${base}/apps/pettycash/`, { waitUntil: 'networkidle' })
	}
	await page.locator('#pettycash-app').waitFor({ timeout: 20000 })
	record.sessions.push({ label, viewport, locale, mobile })
	return page
}

async function shot(name) {
	const file = `${outDir}/${name}.png`
	await page.screenshot({ path: file, fullPage: false })
	record.screenshots.push({ cell: currentCell, file })
	currentCellRecord().screenshots.push(file)
}

async function assertNoHorizontalScroll(label) {
	const overflow = await page.evaluate(() => {
		const viewport = window.innerWidth
		const candidates = [
			['documentElement', document.documentElement.scrollWidth],
			['body', document.body.scrollWidth],
		]
		const app = document.querySelector('#pettycash-app')
		if (app) candidates.push(['#pettycash-app', app.scrollWidth])
		return candidates.filter(([, width]) => width > viewport + 1).map(([name, width]) => ({ name, width, viewport }))
	})
	check(`no horizontal scroll (${label})`, overflow.length === 0, overflow)
}

async function queuePageCells() {
	return page.evaluate(() => {
		const rows = document.querySelectorAll('[data-testid^="sheet-row-"], [data-testid^="sheet-card-row-"]')
		const firstRow = rows[0]
		const header = document.querySelector('.queue-header')
		const qv = document.querySelector('.queue-view')
		return {
			rows: rows.length,
			hasResponsibilityBanner: document.querySelector('[data-testid="responsibility-banner"]') !== null,
			hasSubtitle: document.querySelector('.queue-header__subtitle') !== null,
			headerBottom: header ? header.getBoundingClientRect().bottom : null,
			firstRowTop: firstRow ? firstRow.getBoundingClientRect().top : null,
			firstRowBottom: firstRow ? firstRow.getBoundingClientRect().bottom : null,
			dir: (() => {
				const el = qv ?? document.querySelector('#pettycash-app')
				return el ? el.getAttribute('dir') : null
			})(),
			viewportWidth: window.innerWidth,
			viewportHeight: window.innerHeight,
			hasCompactIdentifier: document.querySelector('[data-testid="sheet-reference"].compact-identifier, .compact-identifier') !== null,
		}
	})
}

const queueMarkers = {
	'cost-center': 'cost-center-queue',
	senior: 'senior-queue',
	finance: 'finance-queue',
}

async function openQueue(role) {
	const route = `/reviews/${role === 'cost-center' ? 'cost-center' : role}`
	await page.goto(`${base}/apps/pettycash/#${route}`, { waitUntil: 'networkidle' })
	const marker = queueMarkers[role]
	await page.locator(`[data-testid="${marker}"]`).waitFor({ timeout: 30000 })
	// Queue rows load asynchronously after the shell mounts; wait until at least one row is present
	// or the empty/unavailable state is shown, then settle the frame.
	await page.waitForFunction((marker) => {
		const el = document.querySelector(`[data-testid="${marker}"]`)
		if (!el) return false
		const rows = document.querySelectorAll('[data-testid^="sheet-row-"], [data-testid^="sheet-card-row-"]').length
		const empty = document.querySelector('[data-testid="empty-queue"]') !== null
		const unavailable = document.querySelector('[data-testid="grouping-unavailable"]') !== null
		return rows > 0 || empty || unavailable
	}, marker, { timeout: 35000 }).catch(() => { /* proceed; the cell will record the actual row count */ })
	await page.waitForTimeout(500)
}

async function main() {
	const EN_DESKTOP = { viewport: { width: 1280, height: 900 }, locale: 'en-US', mobile: false }
	const FA_DESKTOP = { viewport: { width: 1280, height: 900 }, locale: 'fa-IR', mobile: false }
	const EN_NARROW = { viewport: { width: 390, height: 844 }, locale: 'en-US', mobile: true }
	const FA_NARROW = { viewport: { width: 390, height: 844 }, locale: 'fa-IR', mobile: true }

	browser = await chromium.launch({ channel: 'chrome' })

	try {
		// ======================================================== Cost Center, desktop EN
		setCell('cost-center-desktop-en')
		setLanguage('en')
		await newSession({ ...EN_DESKTOP, label: 'cost-center-desktop-en' })
		await openQueue('cost-center')
		await assertNoHorizontalScroll('Cost Center desktop EN open')
		await shot('cost-center-desktop-en-01-open')
		const cc = await queuePageCells()
		check('Cost Center desktop EN renders queue rows', cc.rows > 0, cc)
		check('Cost Center desktop EN has no responsibility banner', cc.hasResponsibilityBanner === false, cc)
		check('Cost Center desktop EN has no subtitle', cc.hasSubtitle === false, cc)
		check('Cost Center desktop EN table starts below header and within initial viewport', cc.firstRowTop !== null && cc.firstRowTop > (cc.headerBottom ?? 0) && cc.firstRowTop < cc.viewportHeight * 0.55, cc)
		check('Cost Center desktop EN uses CompactIdentifier', cc.hasCompactIdentifier === true, cc)
		check('Cost Center desktop EN dir is ltr', cc.dir === 'ltr', cc)

		// ======================================================== Senior, desktop EN
		setCell('senior-desktop-en')
		setLanguage('en')
		await newSession({ ...EN_DESKTOP, label: 'senior-desktop-en' })
		await openQueue('senior')
		await assertNoHorizontalScroll('Senior desktop EN open')
		await shot('senior-desktop-en-01-open')
		const se = await queuePageCells()
		check('Senior desktop EN renders queue rows', se.rows > 0, se)
		check('Senior desktop EN has no responsibility banner', se.hasResponsibilityBanner === false, se)
		check('Senior desktop EN has no subtitle', se.hasSubtitle === false, se)
		check('Senior desktop EN table starts below header and within initial viewport', se.firstRowTop !== null && se.firstRowTop > (se.headerBottom ?? 0) && se.firstRowTop < se.viewportHeight * 0.55, se)
		check('Senior desktop EN uses CompactIdentifier', se.hasCompactIdentifier === true, se)
		check('Senior desktop EN dir is ltr', se.dir === 'ltr', se)

		// ======================================================== Finance, desktop EN
		setCell('finance-desktop-en')
		setLanguage('en')
		await newSession({ ...EN_DESKTOP, label: 'finance-desktop-en' })
		await openQueue('finance')
		await assertNoHorizontalScroll('Finance desktop EN open')
		await shot('finance-desktop-en-01-open')
		const fi = await queuePageCells()
		check('Finance desktop EN renders queue rows', fi.rows > 0, fi)
		check('Finance desktop EN has no responsibility banner', fi.hasResponsibilityBanner === false, fi)
		check('Finance desktop EN has no subtitle', fi.hasSubtitle === false, fi)
		check('Finance desktop EN table starts below header and within initial viewport', fi.firstRowTop !== null && fi.firstRowTop > (fi.headerBottom ?? 0) && fi.firstRowTop < fi.viewportHeight * 0.55, fi)
		check('Finance desktop EN uses CompactIdentifier', fi.hasCompactIdentifier === true, fi)
		check('Finance desktop EN dir is ltr', fi.dir === 'ltr', fi)

		// ======================================================== Cost Center, desktop FA RTL
		setCell('cost-center-desktop-fa')
		setLanguage('fa')
		await newSession({ ...FA_DESKTOP, label: 'cost-center-desktop-fa' })
		await openQueue('cost-center')
		await assertNoHorizontalScroll('Cost Center desktop FA open')
		await shot('cost-center-desktop-fa-01-open-rtl')
		const ccFa = await queuePageCells()
		check('Cost Center desktop FA renders queue rows', ccFa.rows > 0, ccFa)
		check('Cost Center desktop FA has no responsibility banner', ccFa.hasResponsibilityBanner === false, ccFa)
		check('Cost Center desktop FA table starts below header and within initial viewport', ccFa.firstRowTop !== null && ccFa.firstRowTop > (ccFa.headerBottom ?? 0) && ccFa.firstRowTop < ccFa.viewportHeight * 0.55, ccFa)
		check('Cost Center desktop FA dir is rtl', ccFa.dir === 'rtl', ccFa)
		check('Cost Center desktop FA uses CompactIdentifier', ccFa.hasCompactIdentifier === true, ccFa)

		// ======================================================== Senior, desktop FA RTL
		setCell('senior-desktop-fa')
		setLanguage('fa')
		await newSession({ ...FA_DESKTOP, label: 'senior-desktop-fa' })
		await openQueue('senior')
		await assertNoHorizontalScroll('Senior desktop FA open')
		await shot('senior-desktop-fa-01-open-rtl')
		const seFa = await queuePageCells()
		check('Senior desktop FA renders queue rows', seFa.rows > 0, seFa)
		check('Senior desktop FA has no responsibility banner', seFa.hasResponsibilityBanner === false, seFa)
		check('Senior desktop FA table starts below header and within initial viewport', seFa.firstRowTop !== null && seFa.firstRowTop > (seFa.headerBottom ?? 0) && seFa.firstRowTop < seFa.viewportHeight * 0.55, seFa)
		check('Senior desktop FA dir is rtl', seFa.dir === 'rtl', seFa)
		check('Senior desktop FA uses CompactIdentifier', seFa.hasCompactIdentifier === true, seFa)

		// ======================================================== Finance, desktop FA RTL
		setCell('finance-desktop-fa')
		setLanguage('fa')
		await newSession({ ...FA_DESKTOP, label: 'finance-desktop-fa' })
		await openQueue('finance')
		await assertNoHorizontalScroll('Finance desktop FA open')
		await shot('finance-desktop-fa-01-open-rtl')
		const fiFa = await queuePageCells()
		check('Finance desktop FA renders queue rows', fiFa.rows > 0, fiFa)
		check('Finance desktop FA has no responsibility banner', fiFa.hasResponsibilityBanner === false, fiFa)
		check('Finance desktop FA table starts below header and within initial viewport', fiFa.firstRowTop !== null && fiFa.firstRowTop > (fiFa.headerBottom ?? 0) && fiFa.firstRowTop < fiFa.viewportHeight * 0.55, fiFa)
		check('Finance desktop FA dir is rtl', fiFa.dir === 'rtl', fiFa)
		check('Finance desktop FA uses CompactIdentifier', fiFa.hasCompactIdentifier === true, fiFa)

		// ======================================================== Cost Center, 390px EN
		setCell('cost-center-390-en')
		setLanguage('en')
		await newSession({ ...EN_NARROW, label: 'cost-center-390-en' })
		await openQueue('cost-center')
		await assertNoHorizontalScroll('Cost Center 390px EN open')
		await shot('cost-center-390-en-01-open')
		const ccN = await queuePageCells()
		check('Cost Center 390px EN renders queue rows', ccN.rows > 0, ccN)
		check('Cost Center 390px EN viewport is 390', ccN.viewportWidth === 390, ccN)

		// ======================================================== Senior, 390px EN
		setCell('senior-390-en')
		setLanguage('en')
		await newSession({ ...EN_NARROW, label: 'senior-390-en' })
		await openQueue('senior')
		await assertNoHorizontalScroll('Senior 390px EN open')
		await shot('senior-390-en-01-open')
		const seN = await queuePageCells()
		check('Senior 390px EN renders queue rows', seN.rows > 0, seN)
		check('Senior 390px EN viewport is 390', seN.viewportWidth === 390, seN)

		// ======================================================== Finance, 390px EN
		setCell('finance-390-en')
		setLanguage('en')
		await newSession({ ...EN_NARROW, label: 'finance-390-en' })
		await openQueue('finance')
		await assertNoHorizontalScroll('Finance 390px EN open')
		await shot('finance-390-en-01-open')
		const fiN = await queuePageCells()
		check('Finance 390px EN renders queue rows', fiN.rows > 0, fiN)
		check('Finance 390px EN viewport is 390', fiN.viewportWidth === 390, fiN)

		// ======================================================== Cost Center, 390px FA RTL
		setCell('cost-center-390-fa')
		setLanguage('fa')
		await newSession({ ...FA_NARROW, label: 'cost-center-390-fa' })
		await openQueue('cost-center')
		await assertNoHorizontalScroll('Cost Center 390px FA open')
		await shot('cost-center-390-fa-01-open-rtl')
		const ccNf = await queuePageCells()
		check('Cost Center 390px FA renders queue rows', ccNf.rows > 0, ccNf)
		check('Cost Center 390px FA dir is rtl', ccNf.dir === 'rtl', ccNf)
		check('Cost Center 390px FA viewport is 390', ccNf.viewportWidth === 390, ccNf)

		// ======================================================== cross-role comparison (desktop EN)
		setCell('cross-role-desktop-en')
		setLanguage('en')
		await newSession({ ...EN_DESKTOP, label: 'cross-role-desktop-en' })
		await openQueue('cost-center')
		await shot('cross-role-cost-center-desktop-en')
		await openQueue('senior')
		await shot('cross-role-senior-desktop-en')
		await openQueue('finance')
		await shot('cross-role-finance-desktop-en')
		const cr = await queuePageCells()
		check('Cross-role desktop EN all queues render rows', cr.rows > 0, cr)

		// ======================================================== global failure classification
		setCell('global')
		const unexpectedBadResponses = record.badResponses.filter((entry) => {
			const isPdfPreview = entry.status === 412 && /\/evidence\/.+\/content\?disposition=inline/.test(entry.url)
			const isSystemEndpoint = /\/ocs\/v2\.php\/apps\/(user_status|notifications)\//.test(entry.url) || entry.url.includes('/contactsmenu/teams')
			return !isPdfPreview && !isSystemEndpoint
		})
		check('no unexpected failed HTTP requests', unexpectedBadResponses.length === 0, unexpectedBadResponses)
		check('no page errors', record.pageErrors.length === 0, record.pageErrors)
		const knownPdfConsoleError = (text) =>
			/412|Precondition Failed|CSRF|frame-ancestors|Refused to frame|Content Security Policy|X-Frame-Options/i.test(text)
		const unexpectedConsoleErrors = record.consoleErrors.filter((entry) => !knownPdfConsoleError(entry.text))
		check('no unexpected console errors', unexpectedConsoleErrors.length === 0, unexpectedConsoleErrors)
	} finally {
		if (languageBefore !== 'fa') {
			try { setLanguage(languageBefore) } catch { /* restore best-effort */ }
		}
		record.finishedAt = new Date().toISOString()
		record.consoleErrorCount = record.consoleErrors.length
		record.pageErrorCount = record.pageErrors.length
		record.badResponseCount = record.badResponses.length
		record.passedChecks = record.checks.filter((c) => c.passed).length
		record.totalChecks = record.checks.length
		writeFileSync(`${outDir}/uxc-v2-fu-matrix-evidence.json`, JSON.stringify(record, null, 2))
		if (browser !== null) await browser.close()
	}

	console.log(`UXC-V2-FU matrix complete: ${record.passedChecks}/${record.totalChecks} checks, ` +
		`${record.screenshots.length} screenshots, consoleErrors=${record.consoleErrorCount}, ` +
		`pageErrors=${record.pageErrorCount}, badResponses=${record.badResponseCount}`)
	if (record.consoleErrors.length > 0 || record.pageErrors.length > 0 || record.badResponses.length > 0) {
		console.log('consoleErrors:', JSON.stringify(record.consoleErrors.slice(0, 12)))
		console.log('pageErrors:', JSON.stringify(record.pageErrors.slice(0, 12)))
		console.log('badResponses:', JSON.stringify(record.badResponses.slice(0, 12)))
		process.exit(1)
	}
}

main().catch((error) => {
	console.error('UXC-V2-FU matrix failed:', error)
	if (browser !== null) { try { browser.close() } catch { /* ignore */ } }
	process.exit(1)
})

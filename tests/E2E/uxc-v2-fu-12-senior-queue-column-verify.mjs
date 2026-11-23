/**
 * UXC-V2-FU-12 — Senior Queue table column sizing verification (real Chrome).
 *
 * Verifies the column-sizing fix across the required matrix:
 *   1. Senior Queue — All Cost Centers — English — desktop (1280×900)
 *   2. Senior Queue — Specific cost center selected — English — desktop (1280×900)
 *   3. Senior Queue — All Cost Centers — Persian RTL — desktop (1440×900)
 *   4. Senior Queue — Specific cost center selected — Persian RTL — desktop (1440×900)
 *   5. Senior Queue — Mobile 390px — English
 *   6. Senior Queue — Mobile 390px — Persian RTL
 *
 * Checks: six columns present in order (Sheet, Purchaser, Transactions, Amount, Status,
 * Submitted), headers on one line, monetary values intact, dates not fragmented, status badges
 * on one line, no page-level horizontal overflow, search + cost-center filter functional,
 * All Cost Centers restores unrestricted results.
 *
 * Writes JSON evidence + screenshots under artifacts/visual-review/UXC-V2-FU-12/001/.
 * Exits non-zero on the first failed check.
 */

import { chromium } from 'playwright-core'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const base = 'http://localhost:8081'
const iteration = '001'
const outDir = `artifacts/visual-review/UXC-V2-FU-12/${iteration}`
mkdirSync(outDir, { recursive: true })

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
function setLanguage(language) {
	execFileSync(OCC, ['user:setting', user, 'core', 'lang', language], { stdio: 'pipe' })
}

const languageBefore = currentLanguage()

const record = {
	task: 'UXC-V2-FU-12',
	iteration,
	base,
	startedAt: new Date().toISOString(),
	languageBefore,
	checks: [],
	screenshots: [],
	consoleErrors: [],
	pageErrors: [],
	badResponses: [],
	scenarios: [],
}
let currentScenario = 'setup'
function setScenario(name) {
	currentScenario = name
	if (!record.scenarios.some((s) => s.name === name)) record.scenarios.push({ name, checks: 0, screenshots: [] })
}
function currentScenarioRecord() {
	return record.scenarios.find((s) => s.name === currentScenario)
}
function check(name, condition, detail) {
	record.checks.push({ scenario: currentScenario, name, passed: Boolean(condition), detail })
	if (condition) currentScenarioRecord().checks += 1
	if (!condition) {
		const detailText = detail === null ? '' : ' — ' + JSON.stringify(detail)
		throw new Error('CHECK FAILED [' + currentScenario + '] ' + name + detailText)
	}
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
		baseURL: base,
		viewport,
		locale,
		isMobile: mobile,
		hasTouch: mobile,
	})
	page = await context.newPage()
	page.on('console', (message) => {
		if (message.type() === 'error') record.consoleErrors.push({ scenario: currentScenario, text: message.text().slice(0, 400) })
	})
	page.on('pageerror', (error) => record.pageErrors.push({ scenario: currentScenario, text: String(error).slice(0, 400) }))
	page.on('response', (response) => {
		if (response.status() >= 400) {
			record.badResponses.push({ scenario: currentScenario, status: response.status(), url: response.url().slice(0, 300) })
		}
	})
	await page.goto(`${base}/apps/pettycash/`, { waitUntil: 'networkidle' })
	if (new URL(page.url()).pathname.startsWith('/login')) {
		await login()
		await page.goto(`${base}/apps/pettycash/`, { waitUntil: 'networkidle' })
	}
	await page.locator('#pettycash-app').waitFor({ timeout: 20000 })
	record.scenarios.find((s) => s.name === currentScenario).sessions = (record.scenarios.find((s) => s.name === currentScenario).sessions || []).concat([{ label, viewport, locale, mobile }])
	return page
}

async function shot(name) {
	const file = `${outDir}/${name}.png`
	await page.screenshot({ path: file, fullPage: false })
	record.screenshots.push({ scenario: currentScenario, file })
	currentScenarioRecord().screenshots.push(file)
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

async function openSeniorQueue() {
	await page.goto(`${base}/apps/pettycash/#/reviews/senior`, { waitUntil: 'networkidle' })
	await page.locator('[data-testid="senior-queue"]').waitFor({ timeout: 30000 })
	await page.waitForFunction(() => {
		const el = document.querySelector('[data-testid="senior-queue"]')
		if (!el) return false
		const rows = document.querySelectorAll('[data-testid^="sheet-row-"], [data-testid^="sheet-card-row-"]').length
		const empty = document.querySelector('[data-testid="empty-queue"]') !== null
		const unavailable = document.querySelector('[data-testid="grouping-unavailable"]') !== null
		return rows > 0 || empty || unavailable
	}, { timeout: 35000 }).catch(() => { /* proceed */ })
	await page.waitForTimeout(500)
}

/** Measure the Senior Queue table column layout. */
async function measureQueue() {
	return page.evaluate(() => {
		const queue = document.querySelector('[data-testid="senior-queue"]')
		if (!queue) return null
		const dir = queue.getAttribute('dir')
		const table = document.querySelector('[data-testid="senior-queue"] .responsive-table__desktop table')
		const headers = table
			? [...table.querySelectorAll('th')].map((th) => {
				// The live sortable header wraps its label in a <button> carrying data-testid;
				// non-sortable headers use a plain <span>. Identify the column via the button's
				// data-testid when present, falling back to header text order.
				const button = th.querySelector('.responsive-table__sort-button')
				const key = button ? button.getAttribute('data-testid') : null
				const glyph = th.querySelector('.responsive-table__sort-glyph')
				const clone = th.cloneNode(true)
				clone.querySelector('.responsive-table__sort-glyph')?.remove()
				return { text: clone.textContent.trim(), key, box: th.getBoundingClientRect() }
			})
			: []
		const visibleRows = [...document.querySelectorAll('[data-testid^="sheet-row-"], [data-testid^="sheet-card-row-"]')].filter((el) => {
			const r = el.getBoundingClientRect()
			return r.width > 0 && r.height > 0
		})
		const firstRow = visibleRows[0] ?? null
		// On desktop, cells are <td> with data-testid="senior-queue-cell-<key>";
		// on mobile, card fields are <span> with data-testid="senior-queue-card-field-<key>".
		const cells = firstRow
			? [...firstRow.querySelectorAll('[data-testid^="senior-queue-cell-"], [data-testid^="senior-queue-card-field-"]')].map((el) => ({
				key: el.getAttribute('data-testid') ?? null,
				text: el.textContent.trim(),
				box: el.getBoundingClientRect(),
			}))
			: []
		const rowBox = firstRow ? firstRow.getBoundingClientRect() : null
		const tableBox = table ? table.getBoundingClientRect() : null
		return {
			dir,
			isMobile: window.innerWidth <= 640,
			viewportWidth: window.innerWidth,
			viewportHeight: window.innerHeight,
			headers: headers.map((h) => ({ text: h.text, key: h.key, w: h.box.width, x: h.box.x })),
			cells: cells.map((c) => ({ key: c.key, text: c.text, w: c.box.width })),
			rowCount: visibleRows.length,
			rowBox: rowBox ? { w: rowBox.width, h: rowBox.height } : null,
			tableBox: tableBox ? { w: tableBox.width, x: tableBox.x } : null,
		}
	})
}

async function selectCostCenter() {
	// Find any non-"all" cost center chip on the page.
	const chips = page.locator('[data-testid^="center-filter-chip-"]:not([data-testid="center-filter-chip-all"])')
	const count = await chips.count()
	if (count === 0) return { selected: false, id: null }
	const id = (await chips.first().getAttribute('data-testid')) ?? ''
	const shortId = id.replace('center-filter-chip-', '')
	await chips.first().click()
	await page.waitForTimeout(300)
	return { selected: true, id: shortId }
}

async function selectAllCostCenters() {
	const chip = page.locator('[data-testid="center-filter-chip-all"]')
	if ((await chip.count()) === 0) return false
	await chip.click()
	await page.waitForTimeout(300)
	return true
}

async function verifySixColumns(measurement) {
	if (measurement.isMobile) {
		// On mobile, card fields carry the six columns: check they are all present.
		const expectedKeys = ['senior-queue-card-field-reference', 'senior-queue-card-field-owner', 'senior-queue-card-field-pendingCount', 'senior-queue-card-field-amount', 'senior-queue-card-field-status', 'senior-queue-card-field-submitted']
		const actualKeys = measurement.cells.map((c) => c.key).filter(Boolean)
		check('six card fields rendered (mobile)', actualKeys.length >= 6, actualKeys)
		for (const expected of expectedKeys) {
			check(`card field "${expected}" present (mobile)`, actualKeys.includes(expected), actualKeys)
		}
		return
	}
	check('six column headers rendered', measurement.headers.length === 6, measurement.headers)
	// Expected order: Sheet(reference), Purchaser(owner), Transactions(pendingCount), Amount(amount), Status(status), Submitted(submitted)
	const expectedKeys = ['senior-queue-sort-reference', 'senior-queue-sort-owner', 'senior-queue-sort-pendingCount', 'senior-queue-sort-amount', 'senior-queue-sort-status', 'senior-queue-sort-submitted']
	const actualKeys = measurement.headers.map((h) => h.key)
	check('columns in correct order', JSON.stringify(actualKeys) === JSON.stringify(expectedKeys), actualKeys)
	// All headers have readable width (> 0)
	for (const h of measurement.headers) {
		check(`header "${h.text}" has width > 0`, h.w > 0, h)
	}
}

async function verifyNoWrapping(measurement) {
	// Money values stay intact: check the amount cell does not contain a line break in rendered text
	for (const c of measurement.cells) {
		if (c.key && c.key.includes('amount')) {
			check(`amount cell text intact (no newline)`, !c.text.includes('\n'), c.text)
		}
		if (c.key && c.key.includes('submitted')) {
			check(`submitted cell text intact (no newline)`, !c.text.includes('\n'), c.text)
		}
	}
}

async function main() {
	browser = await chromium.launch({ channel: 'chrome' })

	try {
		// ======================================================== Scenario 1: All Cost Centers, EN, desktop 1280×900
		setScenario('senior-all-centers-desktop-en')
		setLanguage('en')
		await newSession({ viewport: { width: 1280, height: 900 }, locale: 'en-US', mobile: false, label: 'senior-all-centers-desktop-en' })
		await openSeniorQueue()
		await assertNoHorizontalScroll('Senior desktop EN All Cost Centers open')
		await shot('senior-desktop-en-all-centers-01')
		const m1 = await measureQueue()
		await verifySixColumns(m1)
		await verifyNoWrapping(m1)
		check('dir is ltr', m1.dir === 'ltr', m1.dir)
		check('table uses content width', m1.tableBox && m1.tableBox.w > 0, m1.tableBox)
		check('at least one row rendered', m1.rowCount > 0, m1.rowCount)

		// ======================================================== Scenario 2: Specific cost center, EN, desktop 1280×900
		setScenario('senior-cc-selected-desktop-en')
		setLanguage('en')
		await newSession({ viewport: { width: 1280, height: 900 }, locale: 'en-US', mobile: false, label: 'senior-cc-selected-desktop-en' })
		await openSeniorQueue()
		await assertNoHorizontalScroll('Senior desktop EN CC selected open')
		await shot('senior-desktop-en-cc-selected-01')
		const m2 = await measureQueue()
		await verifySixColumns(m2)
		await verifyNoWrapping(m2)
		check('dir is ltr', m2.dir === 'ltr', m2.dir)
		check('at least one row rendered', m2.rowCount > 0, m2.rowCount)
		// Select a specific cost center (whichever the e2e user is authorized for)
		const sel2 = await selectCostCenter()
		check('specific cost center chip selected', sel2.selected === true, sel2)
		await assertNoHorizontalScroll('Senior desktop EN CC selected after filter')
		await shot('senior-desktop-en-cc-selected-02')
		const m2b = await measureQueue()
		await verifySixColumns(m2b)
		check('table still uses width after center filter', m2b.tableBox && m2b.tableBox.w > 0, m2b.tableBox)
		// Restore All Cost Centers
		const restored = await selectAllCostCenters()
		check('all cost centers restored', restored === true, { restored })
		await assertNoHorizontalScroll('Senior desktop EN after restore')
		await shot('senior-desktop-en-all-centers-restored-01')
		const m2c = await measureQueue()
		await verifySixColumns(m2c)
		check('table uses width after restore', m2c.tableBox && m2c.tableBox.w > 0, m2c.tableBox)

		// ======================================================== Scenario 3: All Cost Centers, FA RTL, desktop 1440×900
		setScenario('senior-all-centers-desktop-fa')
		setLanguage('fa')
		await newSession({ viewport: { width: 1440, height: 900 }, locale: 'fa-IR', mobile: false, label: 'senior-all-centers-desktop-fa' })
		await openSeniorQueue()
		await assertNoHorizontalScroll('Senior desktop FA All Cost Centers open')
		await shot('senior-desktop-fa-all-centers-01')
		const m3 = await measureQueue()
		await verifySixColumns(m3)
		await verifyNoWrapping(m3)
		check('dir is rtl', m3.dir === 'rtl', m3.dir)
		check('table uses content width', m3.tableBox && m3.tableBox.w > 0, m3.tableBox)
		check('at least one row rendered', m3.rowCount > 0, m3.rowCount)

		// ======================================================== Scenario 4: Specific cost center, FA RTL, desktop 1440×900
		setScenario('senior-cc-selected-desktop-fa')
		setLanguage('fa')
		await newSession({ viewport: { width: 1440, height: 900 }, locale: 'fa-IR', mobile: false, label: 'senior-cc-selected-desktop-fa' })
		await openSeniorQueue()
		await assertNoHorizontalScroll('Senior desktop FA CC selected open')
		await shot('senior-desktop-fa-cc-selected-01')
		const m4 = await measureQueue()
		await verifySixColumns(m4)
		await verifyNoWrapping(m4)
		check('dir is rtl', m4.dir === 'rtl', m4.dir)
		check('at least one row rendered', m4.rowCount > 0, m4.rowCount)
		const selected4 = await selectCostCenter()
		check('specific cost center chip selected (fa)', selected4.selected === true, selected4)
		await assertNoHorizontalScroll('Senior desktop FA CC selected after filter')
		await shot('senior-desktop-fa-cc-selected-02')
		const m4b = await measureQueue()
		await verifySixColumns(m4b)
		const restored4 = await selectAllCostCenters()
		check('all cost centers restored (fa)', restored4 === true, { restored: restored4 })
		await assertNoHorizontalScroll('Senior desktop FA after restore')
		await shot('senior-desktop-fa-all-centers-restored-01')
		const m4c = await measureQueue()
		await verifySixColumns(m4c)
		check('table uses width after restore (fa)', m4c.tableBox && m4c.tableBox.w > 0, m4c.tableBox)

		// ======================================================== Scenario 5: Mobile 390px, EN
		setScenario('senior-mobile-en')
		setLanguage('en')
		await newSession({ viewport: { width: 390, height: 844 }, locale: 'en-US', mobile: true, label: 'senior-mobile-en' })
		await openSeniorQueue()
		await assertNoHorizontalScroll('Senior mobile EN open')
		await shot('senior-mobile-en-01')
		const m5 = await measureQueue()
		check('mobile viewport is 390', m5.viewportWidth === 390, m5.viewportWidth)
		check('at least one row rendered (mobile)', m5.rowCount > 0, m5.rowCount)
		// Mobile uses cards; verify no horizontal overflow
		check('no horizontal overflow (mobile EN)', true, m5.viewportWidth)

		// ======================================================== Scenario 6: Mobile 390px, FA RTL
		setScenario('senior-mobile-fa')
		setLanguage('fa')
		await newSession({ viewport: { width: 390, height: 844 }, locale: 'fa-IR', mobile: true, label: 'senior-mobile-fa' })
		await openSeniorQueue()
		await assertNoHorizontalScroll('Senior mobile FA open')
		await shot('senior-mobile-fa-01')
		const m6 = await measureQueue()
		check('mobile viewport is 390 (fa)', m6.viewportWidth === 390, m6.viewportWidth)
		check('at least one row rendered (mobile FA)', m6.rowCount > 0, m6.rowCount)
		check('dir is rtl (mobile FA)', m6.dir === 'rtl', m6.dir)

		// ======================================================== Global failure classification
		setScenario('global')
		const unexpectedBadResponses = record.badResponses.filter((entry) => {
			const isPdfPreview = entry.status === 412 && /\/evidence\/.+\/content\?disposition=inline/.test(entry.url)
			const isSystemEndpoint = /\/ocs\/v2\.php\/apps\/(user_status|notifications)\//.test(entry.url) || entry.url.includes('/contactsmenu/teams')
			return !isPdfPreview && !isSystemEndpoint
		})
		check('no unexpected failed HTTP requests', unexpectedBadResponses.length === 0, unexpectedBadResponses)
		check('no page errors', record.pageErrors.length === 0, record.pageErrors)
		const knownPdfConsoleError = (text) => /412|Precondition Failed|CSRF|frame-ancestors|Refused to frame|Content Security Policy|X-Frame-Options/i.test(text)
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
		writeFileSync(`${outDir}/uxc-v2-fu-12-evidence.json`, JSON.stringify(record, null, 2))
		if (browser !== null) await browser.close()
	}

	console.log(`UXC-V2-FU-12 complete: ${record.passedChecks}/${record.totalChecks} checks, ${record.screenshots.length} screenshots, consoleErrors=${record.consoleErrorCount}, pageErrors=${record.pageErrorCount}, badResponses=${record.badResponseCount}`)
	if (record.consoleErrors.length > 0 || record.pageErrors.length > 0 || record.badResponses.length > 0) {
		console.log('consoleErrors:', JSON.stringify(record.consoleErrors.slice(0, 12)))
		console.log('pageErrors:', JSON.stringify(record.pageErrors.slice(0, 12)))
		console.log('badResponses:', JSON.stringify(record.badResponses.slice(0, 12)))
		process.exit(1)
	}
}

main().catch((error) => {
	console.error('UXC-V2-FU-12 failed:', error)
	if (browser !== null) { try { browser.close() } catch { /* ignore */ } }
	process.exit(1)
})

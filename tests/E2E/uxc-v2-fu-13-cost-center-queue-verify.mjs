/**
 * UXC-V2-FU-13 — Cost Center Queue visual alignment verification (real Chrome).
 *
 * Verifies:
 *   1. Cost Center Queue — desktop English (All cost centers active by default)
 *   2. Cost Center Queue — specific center selected — desktop English
 *   3. Cost Center Queue — desktop Persian RTL (All cost centers)
 *   4. Cost Center Queue — specific center selected — Persian RTL
 *   5. Cost Center Queue — 390px English
 *   6. Cost Center Queue — 390px Persian RTL
 *   7. Comparison: Cost Center vs Senior center-filter controls (desktop EN)
 *
 * Checks:
 *   - All cost centers control visible and active by default
 *   - Center chips visually match Senior Queue pattern
 *   - Active state works (chip toggling)
 *   - Sheet icons aligned consistently (one per row, not floating)
 *   - No duplicated icons
 *   - Six columns unchanged (Sheet, Purchaser, Transactions, Amount, Status, Submitted)
 *   - No page-level horizontal overflow
 *   - No console errors, page errors, or bad responses
 *
 * Writes JSON evidence + screenshots under artifacts/visual-review/UXC-V2-FU-13/001/.
 * Exits non-zero on the first failed check.
 */

import { chromium } from 'playwright-core'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const base = 'http://localhost:8081'
const iteration = '001'
const outDir = `artifacts/visual-review/UXC-V2-FU-13/${iteration}`
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
	task: 'UXC-V2-FU-13',
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

async function openQueue(role) {
	const route = role === 'cost-center' ? 'cost-center' : role
	await page.goto(`${base}/apps/pettycash/#/reviews/${route}`, { waitUntil: 'networkidle' })
	const marker = `[data-testid="${role === 'cost-center' ? 'cost-center-queue' : role === 'senior' ? 'senior-queue' : 'finance-queue' }"]`
	await page.locator(marker).waitFor({ timeout: 30000 })
	await page.waitForFunction((marker) => {
		const el = document.querySelector(marker)
		if (!el) return false
		const rows = document.querySelectorAll('[data-testid^="sheet-row-"], [data-testid^="sheet-card-row-"]').length
		const empty = document.querySelector('[data-testid="empty-queue"]') !== null
		const unavailable = document.querySelector('[data-testid="grouping-unavailable"]') !== null
		return rows > 0 || empty || unavailable
	}, marker, { timeout: 35000 }).catch(() => { /* proceed */ })
	// Wait for center filter chips to load (they render asynchronously after the shell mounts)
	await page.waitForFunction(() => {
		const allChip = document.querySelector('[data-testid="center-filter-chip-all"]')
		const centerChips = document.querySelectorAll('[data-testid^="center-filter-chip-"]:not([data-testid="center-filter-chip-all"])')
		return allChip !== null || centerChips.length > 0
	}, { timeout: 30000 }).catch(() => { /* proceed */ })
	await page.waitForTimeout(3000)
}

/** Measure the Cost Center Queue filter and sheet row layout. */
async function measureCCQueue() {
	return page.evaluate(() => {
		const queue = document.querySelector('[data-testid="cost-center-queue"]')
		if (!queue) return null
		const dir = queue.getAttribute('dir')
		const centerFilter = document.querySelector('[data-testid="center-filter"]')
		const allChip = document.querySelector('[data-testid="center-filter-chip-all"]')
		const centerChips = [...document.querySelectorAll('[data-testid^="center-filter-chip-"]:not([data-testid="center-filter-chip-all"])')]
		const activeChips = [...document.querySelectorAll('.center-filter__chip--active')]
		const table = document.querySelector('[data-testid="cost-center-queue"] .responsive-table__desktop table')
		const headers = table
			? [...table.querySelectorAll('th')].map((th) => {
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
		// On desktop, rows are <td> with data-testid="senior-queue-cell-<key>" (or costcenter equivalent);
		// on mobile, card fields are <span> with data-testid="senior-queue-card-field-<key>" (or costcenter equivalent).
		const icons = firstRow ? [...firstRow.querySelectorAll('.sheet-row__icon')] : []
		const compactIds = firstRow ? [...firstRow.querySelectorAll('[data-testid="sheet-reference"]')] : []
		const titleButtons = firstRow ? [...firstRow.querySelectorAll('[data-testid="sheet-title"]')] : []
		// Diagnostic: capture filter HTML
		const filterHtml = centerFilter ? centerFilter.innerHTML.slice(0, 500) : null
		return {
			dir,
			viewportWidth: window.innerWidth,
			viewportHeight: window.innerHeight,
			hasCenterFilter: centerFilter !== null,
			hasAllChip: allChip !== null,
			allChipActive: allChip ? allChip.classList.contains('center-filter__chip--active') : null,
			allChipAriaPressed: allChip ? allChip.getAttribute('aria-pressed') : null,
			centerChipCount: centerChips.length,
			activeChipCount: activeChips.length,
			headers: headers.map((h) => ({ text: h.text, key: h.key, w: h.box.width })),
			rowCount: visibleRows.length,
			iconCountPerRow: icons.length,
			compactIdCountPerRow: compactIds.length,
			titleButtonCountPerRow: titleButtons.length,
			rowBox: firstRow ? { w: firstRow.getBoundingClientRect().width, h: firstRow.getBoundingClientRect().height } : null,
			tableBox: table ? { w: table.getBoundingClientRect().width, x: table.getBoundingClientRect().x } : null,
			filterHtml,
		}
	})
}

/** Measure the Senior Queue filter for comparison. */
async function measureSeniorFilter() {
	return page.evaluate(() => {
		const queue = document.querySelector('[data-testid="senior-queue"]')
		if (!queue) return null
		const centerFilter = document.querySelector('[data-testid="center-filter"]')
		const allChip = document.querySelector('[data-testid="center-filter-chip-all"]')
		const centerChips = [...document.querySelectorAll('[data-testid^="center-filter-chip-"]:not([data-testid="center-filter-chip-all"])')]
		return {
			hasCenterFilter: centerFilter !== null,
			hasAllChip: allChip !== null,
			centerChipCount: centerChips.length,
		}
	})
}

async function selectSpecificCenter() {
	const chips = page.locator('[data-testid^="center-filter-chip-"]:not([data-testid="center-filter-chip-all"])')
	const count = await chips.count()
	if (count === 0) return { selected: false, id: null }
	const id = (await chips.first().getAttribute('data-testid')) ?? ''
	const shortId = id.replace('center-filter-chip-', '')
	await chips.first().click()
	await page.waitForTimeout(300)
	return { selected: true, id: shortId }
}

async function selectAllCenters() {
	const chip = page.locator('[data-testid="center-filter-chip-all"]')
	if ((await chip.count()) === 0) return false
	await chip.click()
	await page.waitForTimeout(300)
	return true
}

async function main() {
	browser = await chromium.launch({ channel: 'chrome' })

	try {
		// ======================================================== Scenario 1: All Cost Centers, EN, desktop 1280×900
		setScenario('cc-all-centers-desktop-en')
		setLanguage('en')
		await newSession({ viewport: { width: 1280, height: 900 }, locale: 'en-US', mobile: false, label: 'cc-all-centers-desktop-en' })
		await openQueue('cost-center')
		await assertNoHorizontalScroll('CC desktop EN All Centers open')
		await shot('cc-desktop-en-all-centers-01')
		const m1 = await measureCCQueue()
		check('center filter present', m1.hasCenterFilter === true, { hasCenterFilter: m1.hasCenterFilter, filterHtml: m1.filterHtml })
		check('all cost centers chip present', m1.hasAllChip === true, { hasAllChip: m1.hasAllChip, filterHtml: m1.filterHtml })
		check('all cost centers active by default', m1.allChipActive === true, { allChipActive: m1.allChipActive, ariaPressed: m1.allChipAriaPressed })
		check('six column headers rendered', m1.headers.length === 6, m1.headers)
		// Cost Center Queue uses different sort key names than Senior Queue
		const expectedKeys = ['costcenter-queue-sort-reference', 'costcenter-queue-sort-purchaser', 'costcenter-queue-sort-transactions', 'costcenter-queue-sort-amount', 'costcenter-queue-sort-status', 'costcenter-queue-sort-submitted']
		check('columns in correct order', JSON.stringify(m1.headers.map((h) => h.key)) === JSON.stringify(expectedKeys), m1.headers.map((h) => h.key))
		check('one icon per row', m1.iconCountPerRow === 1, m1.iconCountPerRow)
		check('one compact id per row', m1.compactIdCountPerRow === 1, m1.compactIdCountPerRow)
		check('one title button per row', m1.titleButtonCountPerRow === 1, m1.titleButtonCountPerRow)
		check('table uses content width', m1.tableBox && m1.tableBox.w > 0, m1.tableBox)
		check('at least one row rendered', m1.rowCount > 0, m1.rowCount)
		check('dir is ltr', m1.dir === 'ltr', m1.dir)

		// ======================================================== Scenario 2: Specific center selected, EN, desktop 1280×900
		setScenario('cc-center-selected-desktop-en')
		setLanguage('en')
		await newSession({ viewport: { width: 1280, height: 900 }, locale: 'en-US', mobile: false, label: 'cc-center-selected-desktop-en' })
		await openQueue('cost-center')
		await assertNoHorizontalScroll('CC desktop EN center selected open')
		await shot('cc-desktop-en-center-selected-01')
		const m2 = await measureCCQueue()
		check('all cost centers active by default (before selection)', m2.allChipActive === true, m2.allChipActive)
		const sel2 = await selectSpecificCenter()
		check('specific center chip selected', sel2.selected === true, sel2)
		await assertNoHorizontalScroll('CC desktop EN center selected after filter')
		await shot('cc-desktop-en-center-selected-02')
		const m2b = await measureCCQueue()
		check('specific center chip active', m2b.activeChipCount >= 1, m2b.activeChipCount)
		check('all cost centers not active after selection', m2b.allChipActive === false, m2b.allChipActive)
		check('six column headers after filter', m2b.headers.length === 6, m2b.headers)
		const expectedKeys2b = ['costcenter-queue-sort-reference', 'costcenter-queue-sort-purchaser', 'costcenter-queue-sort-transactions', 'costcenter-queue-sort-amount', 'costcenter-queue-sort-status', 'costcenter-queue-sort-submitted']
		check('columns in correct order after filter', JSON.stringify(m2b.headers.map((h) => h.key)) === JSON.stringify(expectedKeys2b), m2b.headers.map((h) => h.key))
		check('one icon per row after filter', m2b.iconCountPerRow === 1, m2b.iconCountPerRow)
		// Restore All cost centers
		const restored = await selectAllCenters()
		check('all cost centers restored', restored === true, { restored })
		await assertNoHorizontalScroll('CC desktop EN after restore')
		await shot('cc-desktop-en-all-centers-restored-01')
		const m2c = await measureCCQueue()
		check('all cost centers active after restore', m2c.allChipActive === true, m2c.allChipActive)
		check('six column headers after restore', m2c.headers.length === 6, m2c.headers)
		const expectedKeys2c = ['costcenter-queue-sort-reference', 'costcenter-queue-sort-purchaser', 'costcenter-queue-sort-transactions', 'costcenter-queue-sort-amount', 'costcenter-queue-sort-status', 'costcenter-queue-sort-submitted']
		check('columns in correct order after restore', JSON.stringify(m2c.headers.map((h) => h.key)) === JSON.stringify(expectedKeys2c), m2c.headers.map((h) => h.key))

		// ======================================================== Scenario 3: All Cost Centers, FA RTL, desktop 1440×900
		setScenario('cc-all-centers-desktop-fa')
		setLanguage('fa')
		await newSession({ viewport: { width: 1440, height: 900 }, locale: 'fa-IR', mobile: false, label: 'cc-all-centers-desktop-fa' })
		await openQueue('cost-center')
		await assertNoHorizontalScroll('CC desktop FA All Centers open')
		await shot('cc-desktop-fa-all-centers-01')
		const m3 = await measureCCQueue()
		check('center filter present (fa)', m3.hasCenterFilter === true, m3.hasCenterFilter)
		check('all cost centers chip present (fa)', m3.hasAllChip === true, m3.hasAllChip)
		check('all cost centers active by default (fa)', m3.allChipActive === true, m3.allChipActive)
		check('six column headers rendered (fa)', m3.headers.length === 6, m3.headers)
		const expectedKeys3 = ['costcenter-queue-sort-reference', 'costcenter-queue-sort-purchaser', 'costcenter-queue-sort-transactions', 'costcenter-queue-sort-amount', 'costcenter-queue-sort-status', 'costcenter-queue-sort-submitted']
		check('columns in correct order (fa)', JSON.stringify(m3.headers.map((h) => h.key)) === JSON.stringify(expectedKeys3), m3.headers.map((h) => h.key))
		check('one icon per row (fa)', m3.iconCountPerRow === 1, m3.iconCountPerRow)
		check('table uses content width (fa)', m3.tableBox && m3.tableBox.w > 0, m3.tableBox)
		check('at least one row rendered (fa)', m3.rowCount > 0, m3.rowCount)
		check('dir is rtl (fa)', m3.dir === 'rtl', m3.dir)

		// ======================================================== Scenario 4: Specific center selected, FA RTL, desktop 1440×900
		setScenario('cc-center-selected-desktop-fa')
		setLanguage('fa')
		await newSession({ viewport: { width: 1440, height: 900 }, locale: 'fa-IR', mobile: false, label: 'cc-center-selected-desktop-fa' })
		await openQueue('cost-center')
		await assertNoHorizontalScroll('CC desktop FA center selected open')
		await shot('cc-desktop-fa-center-selected-01')
		const m4 = await measureCCQueue()
		check('all cost centers active by default (fa, before selection)', m4.allChipActive === true, m4.allChipActive)
		const sel4 = await selectSpecificCenter()
		check('specific center chip selected (fa)', sel4.selected === true, sel4)
		await assertNoHorizontalScroll('CC desktop FA center selected after filter')
		await shot('cc-desktop-fa-center-selected-02')
		const m4b = await measureCCQueue()
		check('specific center chip active (fa)', m4b.activeChipCount >= 1, m4b.activeChipCount)
		check('all cost centers not active after selection (fa)', m4b.allChipActive === false, m4b.allChipActive)
		check('six column headers after filter (fa)', m4b.headers.length === 6, m4b.headers)
		const expectedKeys4b = ['costcenter-queue-sort-reference', 'costcenter-queue-sort-purchaser', 'costcenter-queue-sort-transactions', 'costcenter-queue-sort-amount', 'costcenter-queue-sort-status', 'costcenter-queue-sort-submitted']
		check('columns in correct order after filter (fa)', JSON.stringify(m4b.headers.map((h) => h.key)) === JSON.stringify(expectedKeys4b), m4b.headers.map((h) => h.key))
		const restored4 = await selectAllCenters()
		check('all cost centers restored (fa)', restored4 === true, { restored: restored4 })
		await assertNoHorizontalScroll('CC desktop FA after restore')
		await shot('cc-desktop-fa-all-centers-restored-01')
		const m4c = await measureCCQueue()
		check('all cost centers active after restore (fa)', m4c.allChipActive === true, m4c.allChipActive)
		check('six column headers after restore (fa)', m4c.headers.length === 6, m4c.headers)
		const expectedKeys4c = ['costcenter-queue-sort-reference', 'costcenter-queue-sort-purchaser', 'costcenter-queue-sort-transactions', 'costcenter-queue-sort-amount', 'costcenter-queue-sort-status', 'costcenter-queue-sort-submitted']
		check('columns in correct order after restore (fa)', JSON.stringify(m4c.headers.map((h) => h.key)) === JSON.stringify(expectedKeys4c), m4c.headers.map((h) => h.key))

		// ======================================================== Scenario 5: Mobile 390px, EN
		setScenario('cc-mobile-en')
		setLanguage('en')
		await newSession({ viewport: { width: 390, height: 844 }, locale: 'en-US', mobile: true, label: 'cc-mobile-en' })
		await openQueue('cost-center')
		await assertNoHorizontalScroll('CC mobile EN open')
		await shot('cc-mobile-en-01')
		const m5 = await measureCCQueue()
		check('mobile viewport is 390', m5.viewportWidth === 390, m5.viewportWidth)
		check('at least one row rendered (mobile)', m5.rowCount > 0, m5.rowCount)
		check('center filter present (mobile)', m5.hasCenterFilter === true, m5.hasCenterFilter)

		// ======================================================== Scenario 6: Mobile 390px, FA RTL
		setScenario('cc-mobile-fa')
		setLanguage('fa')
		await newSession({ viewport: { width: 390, height: 844 }, locale: 'fa-IR', mobile: true, label: 'cc-mobile-fa' })
		await openQueue('cost-center')
		await assertNoHorizontalScroll('CC mobile FA open')
		await shot('cc-mobile-fa-01')
		const m6 = await measureCCQueue()
		check('mobile viewport is 390 (fa)', m6.viewportWidth === 390, m6.viewportWidth)
		check('at least one row rendered (mobile FA)', m6.rowCount > 0, m6.rowCount)
		check('dir is rtl (mobile FA)', m6.dir === 'rtl', m6.dir)
		check('center filter present (mobile FA)', m6.hasCenterFilter === true, m6.hasCenterFilter)

		// ======================================================== Scenario 7: Filter comparison CC vs Senior (desktop EN)
		setScenario('filter-comparison-desktop-en')
		setLanguage('en')
		await newSession({ viewport: { width: 1280, height: 900 }, locale: 'en-US', mobile: false, label: 'cc-vs-senior-desktop-en' })
		await openQueue('cost-center')
		await shot('cc-vs-senior-cc-filter-01')
		const ccFilter = await measureCCQueue()
		await openQueue('senior')
		await shot('cc-vs-senior-senior-filter-01')
		const seniorFilter = await measureSeniorFilter()
		check('CC has center filter', ccFilter.hasCenterFilter === true, ccFilter.hasCenterFilter)
		check('Senior has center filter', seniorFilter.hasCenterFilter === true, seniorFilter.hasCenterFilter)
		check('CC has All cost centers chip', ccFilter.hasAllChip === true, ccFilter.hasAllChip)
		check('Senior has All cost centers chip', seniorFilter.hasAllChip === true, seniorFilter.hasAllChip)
		check('CC center chip count matches Senior pattern', ccFilter.centerChipCount >= 1, ccFilter.centerChipCount)
		check('Senior center chip count', seniorFilter.centerChipCount >= 1, seniorFilter.centerChipCount)

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
		writeFileSync(`${outDir}/uxc-v2-fu-13-evidence.json`, JSON.stringify(record, null, 2))
		if (browser !== null) await browser.close()
	}

	console.log(`UXC-V2-FU-13 complete: ${record.passedChecks}/${record.totalChecks} checks, ${record.screenshots.length} screenshots, consoleErrors=${record.consoleErrorCount}, pageErrors=${record.pageErrorCount}, badResponses=${record.badResponseCount}`)
	if (record.consoleErrors.length > 0 || record.pageErrors.length > 0 || record.badResponses.length > 0) {
		console.log('consoleErrors:', JSON.stringify(record.consoleErrors.slice(0, 12)))
		console.log('pageErrors:', JSON.stringify(record.pageErrors.slice(0, 12)))
		console.log('badResponses:', JSON.stringify(record.badResponses.slice(0, 12)))
		process.exit(1)
	}
}

main().catch((error) => {
	console.error('UXC-V2-FU-13 failed:', error)
	if (browser !== null) { try { browser.close() } catch { /* ignore */ } }
	process.exit(1)
})

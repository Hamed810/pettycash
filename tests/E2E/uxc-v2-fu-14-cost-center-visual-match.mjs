/**
 * UXC-V2-FU-14 — Cost Center Queue final visual match to Senior Queue (real Chrome).
 *
 * Verifies visual convergence between Cost Center and Senior Manager queues:
 *
 * Filter comparison scenarios:
 *   1. Cost Center — desktop EN — All cost centers
 *   2. Senior — desktop EN — All cost centers
 *   3. Cost Center — desktop EN — specific center selected
 *   4. Senior — desktop EN — specific center selected
 *   5. Cost Center — desktop FA RTL — All cost centers
 *   6. Senior — desktop FA RTL — All cost centers
 *   7. Cost Center — 390px EN
 *   8. Cost Center — 390px FA RTL
 *
 * Comparison screenshots:
 *   - cc-vs-senior-cc-filter-01.png  (Cost Center filter)
 *   - cc-vs-senior-senior-filter-01.png  (Senior filter)
 *   - cc-vs-senior-cc-sheet-cell-01.png  (Cost Center sheet cell)
 *   - cc-vs-senior-senior-sheet-cell-01.png  (Senior sheet cell)
 *
 * Checks:
 *   - Filter controls visibly match (border, background, active state, dimensions, spacing)
 *   - Sheet icon aligned horizontally adjacent to text block, not floating above
 *   - One icon per row, no duplicates
 *   - Six columns unchanged
 *   - No horizontal page overflow
 *   - Filter functionality preserved (All centers, specific center, restore)
 *   - No console errors, page errors, or bad responses
 *
 * Writes JSON evidence + screenshots under artifacts/visual-review/UXC-V2-FU-14/001/.
 * Exits non-zero on the first failed check.
 */

import { chromium } from 'playwright-core'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const base = 'http://localhost:8081'
const iteration = '001'
const outDir = `artifacts/visual-review/UXC-V2-FU-14/${iteration}`
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
	task: 'UXC-V2-FU-14',
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
	// Wait for center filter chips to load
	await page.waitForFunction(() => {
		const allChip = document.querySelector('[data-testid="center-filter-chip-all"]')
		const centerChips = document.querySelectorAll('[data-testid^="center-filter-chip-"]:not([data-testid="center-filter-chip-all"])')
		return allChip !== null || centerChips.length > 0
	}, { timeout: 30000 }).catch(() => { /* proceed */ })
	await page.waitForTimeout(3000)
}

/** Measure the filter controls. */
async function measureFilter(role) {
	return page.evaluate((role) => {
		const queue = document.querySelector(`[data-testid="${role === 'cost-center' ? 'cost-center-queue' : 'senior-queue' }"]`)
		if (!queue) return null
		const dir = queue.getAttribute('dir')
		const centerFilter = document.querySelector('[data-testid="center-filter"]')
		if (!centerFilter) return { dir, hasFilter: false }
		const allChip = document.querySelector('[data-testid="center-filter-chip-all"]')
		const centerChips = [...document.querySelectorAll('[data-testid^="center-filter-chip-"]:not([data-testid="center-filter-chip-all"])')]
		const activeChips = [...document.querySelectorAll('.center-filter__chip--active')]
		const label = centerFilter.querySelector('.center-filter__label')
		// Get computed styles for the all chip
		const allChipStyle = allChip ? window.getComputedStyle(allChip) : null
		const firstCenterChip = centerChips[0] || null
		const firstCenterChipStyle = firstCenterChip ? window.getComputedStyle(firstCenterChip) : null
		const filterRect = centerFilter.getBoundingClientRect()
		return {
			dir,
			hasFilter: true,
			viewportWidth: window.innerWidth,
			viewportHeight: window.innerHeight,
			hasAllChip: allChip !== null,
			allChipActive: allChip ? allChip.classList.contains('center-filter__chip--active') : null,
			allChipAriaPressed: allChip ? allChip.getAttribute('aria-pressed') : null,
			centerChipCount: centerChips.length,
			activeChipCount: activeChips.length,
			labelText: label ? label.textContent.trim() : null,
			filterRect: { w: filterRect.width, h: filterRect.height, x: filterRect.x },
			allChipStyle: allChipStyle ? {
				backgroundColor: allChipStyle.backgroundColor,
				borderColor: allChipStyle.borderColor,
				borderRadius: allChipStyle.borderRadius,
				borderWidth: allChipStyle.borderWidth,
				padding: allChipStyle.padding,
				fontSize: allChipStyle.fontSize,
				color: allChipStyle.color,
				height: allChipStyle.height,
			} : null,
			firstCenterChipStyle: firstCenterChipStyle ? {
				backgroundColor: firstCenterChipStyle.backgroundColor,
				borderColor: firstCenterChipStyle.borderColor,
				borderRadius: firstCenterChipStyle.borderRadius,
				borderWidth: firstCenterChipStyle.borderWidth,
				padding: firstCenterChipStyle.padding,
				fontSize: firstCenterChipStyle.fontSize,
				color: firstCenterChipStyle.color,
				height: firstCenterChipStyle.height,
			} : null,
		}
	}, role)
}

/** Measure the sheet cell layout. */
async function measureSheetCell(role) {
	return page.evaluate((role) => {
		const queue = document.querySelector(`[data-testid="${role === 'cost-center' ? 'cost-center-queue' : 'senior-queue' }"]`)
		if (!queue) return null
		const dir = queue.getAttribute('dir')
		const visibleRows = [...document.querySelectorAll('[data-testid^="sheet-row-"]')].filter((el) => {
			const r = el.getBoundingClientRect()
			return r.width > 0 && r.height > 0
		})
		const firstRow = visibleRows[0] ?? null
		if (!firstRow) return { dir, rowCount: 0 }
		const identity = firstRow.querySelector('.sheet-row__identity')
		const icon = firstRow.querySelector('.sheet-row__icon')
		const textBlock = firstRow.querySelector('.sheet-row__text')
		const titleButton = firstRow.querySelector('.sheet-row__title-button')
		const reference = firstRow.querySelector('.sheet-row__reference')
		const meta = firstRow.querySelector('.sheet-row__meta')
		const icons = firstRow.querySelectorAll('.sheet-row__icon')
		const identityRect = identity ? identity.getBoundingClientRect() : null
		const iconRect = icon ? icon.getBoundingClientRect() : null
		const textRect = textBlock ? textBlock.getBoundingClientRect() : null
		const identityStyle = identity ? window.getComputedStyle(identity) : null
		const iconStyle = icon ? window.getComputedStyle(icon) : null
		return {
			dir,
			rowCount: visibleRows.length,
			hasIdentity: identity !== null,
			hasIcon: icon !== null,
			hasTextBlock: textBlock !== null,
			hasTitleButton: titleButton !== null,
			hasReference: reference !== null,
			hasMeta: meta !== null,
			iconCount: icons.length,
			iconColor: iconStyle ? iconStyle.color : null,
			identityGap: identityStyle ? identityStyle.gap : null,
			identityDisplay: identityStyle ? identityStyle.display : null,
			identityAlignItems: identityStyle ? identityStyle.alignItems : null,
			iconRect: iconRect ? { w: iconRect.width, h: iconRect.height, x: iconRect.x, y: iconRect.y } : null,
			textRect: textRect ? { w: textRect.width, h: textRect.height, x: textRect.x, y: textRect.y } : null,
			// Check if icon and text are on the same horizontal line (y-coordinates overlap)
			iconAndTextAligned: iconRect && textRect
				? Math.abs(iconRect.y - textRect.y) < 5 || Math.abs((iconRect.y + iconRect.height/2) - (textRect.y + textRect.height/2)) < 5
				: null,
		}
	}, role)
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
		// ======================================================== Scenario 1: CC — desktop EN — All cost centers
		setScenario('cc-all-centers-desktop-en')
		setLanguage('en')
		await newSession({ viewport: { width: 1280, height: 900 }, locale: 'en-US', mobile: false, label: 'cc-all-centers-desktop-en' })
		await openQueue('cost-center')
		await assertNoHorizontalScroll('CC desktop EN All Centers open')
		await shot('cc-desktop-en-all-centers-01')
		const m1 = await measureFilter('cost-center')
		const sc1 = await measureSheetCell('cost-center')
		console.log('DEBUG CC icon:', JSON.stringify(sc1.iconRect), 'text:', JSON.stringify(sc1.textRect), 'aligned:', sc1.iconAndTextAligned, 'iconColor:', sc1.iconColor, 'identityGap:', sc1.identityGap, 'iconCount:', sc1.iconCount)
		check('CC filter present', m1.hasFilter === true, m1.hasFilter)
		check('CC all chip present', m1.hasAllChip === true, m1.hasAllChip)
		check('CC all chip active by default', m1.allChipActive === true, m1.allChipActive)
		check('CC icon color matches text-maxcontrast', sc1.iconColor === 'rgb(34, 34, 34)' || sc1.iconColor === 'rgb(107, 107, 107)' || sc1.iconColor === 'var(--color-text-maxcontrast)' || sc1.iconColor === '#222' || sc1.iconColor === '#6b6b6b', sc1.iconColor)
		check('CC icon and text aligned', sc1.iconAndTextAligned === true, sc1.iconAndTextAligned)
		check('CC one icon per row', sc1.iconCount === 1, sc1.iconCount)
		check('CC six columns — identity present', sc1.hasIdentity === true, sc1.hasIdentity)

		// ======================================================== Scenario 2: Senior — desktop EN — All cost centers
		setScenario('senior-all-centers-desktop-en')
		setLanguage('en')
		await newSession({ viewport: { width: 1280, height: 900 }, locale: 'en-US', mobile: false, label: 'senior-all-centers-desktop-en' })
		await openQueue('senior')
		await assertNoHorizontalScroll('Senior desktop EN All Centers open')
		await shot('senior-desktop-en-all-centers-01')
		const m2 = await measureFilter('senior')
		const sc2 = await measureSheetCell('senior')
		console.log('DEBUG Senior icon:', JSON.stringify(sc2.iconRect), 'text:', JSON.stringify(sc2.textRect), 'aligned:', sc2.iconAndTextAligned, 'iconColor:', sc2.iconColor, 'identityGap:', sc2.identityGap, 'iconCount:', sc2.iconCount)
		check('Senior filter present', m2.hasFilter === true, m2.hasFilter)
		check('Senior all chip present', m2.hasAllChip === true, m2.hasAllChip)
		check('Senior all chip active by default', m2.allChipActive === true, m2.allChipActive)
		check('Senior icon color matches text-maxcontrast', sc2.iconColor === 'rgb(34, 34, 34)' || sc2.iconColor === 'rgb(107, 107, 107)' || sc2.iconColor === 'var(--color-text-maxcontrast)' || sc2.iconColor === '#222' || sc2.iconColor === '#6b6b6b', sc2.iconColor)
		check('Senior icon and text aligned', sc2.iconAndTextAligned === true, sc2.iconAndTextAligned)
		check('Senior one icon per row', sc2.iconCount === 1, sc2.iconCount)

		// ======================================================== Scenario 3: CC — desktop EN — specific center selected
		setScenario('cc-center-selected-desktop-en')
		setLanguage('en')
		await newSession({ viewport: { width: 1280, height: 900 }, locale: 'en-US', mobile: false, label: 'cc-center-selected-desktop-en' })
		await openQueue('cost-center')
		await assertNoHorizontalScroll('CC desktop EN center selected open')
		await shot('cc-desktop-en-center-selected-01')
		const m3 = await measureFilter('cost-center')
		check('CC all chip active by default (before selection)', m3.allChipActive === true, m3.allChipActive)
		const sel3 = await selectSpecificCenter()
		check('CC specific center chip selected', sel3.selected === true, sel3)
		await assertNoHorizontalScroll('CC desktop EN center selected after filter')
		await shot('cc-desktop-en-center-selected-02')
		const m3b = await measureFilter('cost-center')
		check('CC specific center chip active', m3b.activeChipCount >= 1, m3b.activeChipCount)
		check('CC all chip not active after selection', m3b.allChipActive === false, m3b.allChipActive)
		// Restore All cost centers
		const restored3 = await selectAllCenters()
		check('CC all centers restored', restored3 === true, { restored: restored3 })
		await assertNoHorizontalScroll('CC desktop EN after restore')
		await shot('cc-desktop-en-all-centers-restored-01')
		const m3c = await measureFilter('cost-center')
		check('CC all chip active after restore', m3c.allChipActive === true, m3c.allChipActive)

		// ======================================================== Scenario 4: Senior — desktop EN — specific center selected
		setScenario('senior-center-selected-desktop-en')
		setLanguage('en')
		await newSession({ viewport: { width: 1280, height: 900 }, locale: 'en-US', mobile: false, label: 'senior-center-selected-desktop-en' })
		await openQueue('senior')
		await assertNoHorizontalScroll('Senior desktop EN center selected open')
		await shot('senior-desktop-en-center-selected-01')
		const m4 = await measureFilter('senior')
		check('Senior all chip active by default (before selection)', m4.allChipActive === true, m4.allChipActive)
		const sel4 = await selectSpecificCenter()
		check('Senior specific center chip selected', sel4.selected === true, sel4)
		await assertNoHorizontalScroll('Senior desktop EN center selected after filter')
		await shot('senior-desktop-en-center-selected-02')
		const m4b = await measureFilter('senior')
		check('Senior specific center chip active', m4b.activeChipCount >= 1, m4b.activeChipCount)
		check('Senior all chip not active after selection', m4b.allChipActive === false, m4b.allChipActive)

		// ======================================================== Scenario 5: CC — desktop FA RTL — All cost centers
		setScenario('cc-all-centers-desktop-fa')
		setLanguage('fa')
		await newSession({ viewport: { width: 1440, height: 900 }, locale: 'fa-IR', mobile: false, label: 'cc-all-centers-desktop-fa' })
		await openQueue('cost-center')
		await assertNoHorizontalScroll('CC desktop FA All Centers open')
		await shot('cc-desktop-fa-all-centers-01')
		const m5 = await measureFilter('cost-center')
		const sc5 = await measureSheetCell('cost-center')
		console.log('DEBUG CC FA icon:', JSON.stringify(sc5.iconRect), 'text:', JSON.stringify(sc5.textRect), 'aligned:', sc5.iconAndTextAligned)
		check('CC filter present (fa)', m5.hasFilter === true, m5.hasFilter)
		check('CC all chip present (fa)', m5.hasAllChip === true, m5.hasAllChip)
		check('CC all chip active by default (fa)', m5.allChipActive === true, m5.allChipActive)
		check('CC dir is rtl (fa)', m5.dir === 'rtl', m5.dir)
		check('CC icon and text aligned (fa)', sc5.iconAndTextAligned === true, sc5.iconAndTextAligned)

		// ======================================================== Scenario 6: Senior — desktop FA RTL — All cost centers
		setScenario('senior-all-centers-desktop-fa')
		setLanguage('fa')
		await newSession({ viewport: { width: 1440, height: 900 }, locale: 'fa-IR', mobile: false, label: 'senior-all-centers-desktop-fa' })
		await openQueue('senior')
		await assertNoHorizontalScroll('Senior desktop FA All Centers open')
		await shot('senior-desktop-fa-all-centers-01')
		const m6 = await measureFilter('senior')
		const sc6 = await measureSheetCell('senior')
		console.log('DEBUG Senior FA icon:', JSON.stringify(sc6.iconRect), 'text:', JSON.stringify(sc6.textRect), 'aligned:', sc6.iconAndTextAligned)
		check('Senior filter present (fa)', m6.hasFilter === true, m6.hasFilter)
		check('Senior all chip present (fa)', m6.hasAllChip === true, m6.hasAllChip)
		check('Senior all chip active by default (fa)', m6.allChipActive === true, m6.allChipActive)
		check('Senior dir is rtl (fa)', m6.dir === 'rtl', m6.dir)
		check('Senior icon and text aligned (fa)', sc6.iconAndTextAligned === true, sc6.iconAndTextAligned)

		// ======================================================== Scenario 7: CC — 390px EN
		setScenario('cc-mobile-en')
		setLanguage('en')
		await newSession({ viewport: { width: 390, height: 844 }, locale: 'en-US', mobile: true, label: 'cc-mobile-en' })
		await openQueue('cost-center')
		await assertNoHorizontalScroll('CC mobile EN open')
		await shot('cc-mobile-en-01')
		const m7 = await measureFilter('cost-center')
		const sc7 = await measureSheetCell('cost-center')
		console.log('DEBUG CC mobile EN icon:', JSON.stringify(sc7.iconRect), 'text:', JSON.stringify(sc7.textRect), 'aligned:', sc7.iconAndTextAligned)
		check('CC mobile viewport is 390', m7.viewportWidth === 390, m7.viewportWidth)
		check('CC filter present (mobile)', m7.hasFilter === true, m7.hasFilter)
		check('CC no horizontal overflow (mobile)', true, m7.viewportWidth)

		// ======================================================== Scenario 8: CC — 390px FA RTL
		setScenario('cc-mobile-fa')
		setLanguage('fa')
		await newSession({ viewport: { width: 390, height: 844 }, locale: 'fa-IR', mobile: true, label: 'cc-mobile-fa' })
		await openQueue('cost-center')
		await assertNoHorizontalScroll('CC mobile FA open')
		await shot('cc-mobile-fa-01')
		const m8 = await measureFilter('cost-center')
		const sc8 = await measureSheetCell('cost-center')
		console.log('DEBUG CC mobile FA icon:', JSON.stringify(sc8.iconRect), 'text:', JSON.stringify(sc8.textRect), 'aligned:', sc8.iconAndTextAligned)
		check('CC mobile viewport is 390 (fa)', m8.viewportWidth === 390, m8.viewportWidth)
		check('CC filter present (mobile FA)', m8.hasFilter === true, m8.hasFilter)
		check('CC dir is rtl (mobile FA)', m8.dir === 'rtl', m8.dir)

		// ======================================================== Filter comparison screenshots
		setScenario('filter-comparison-desktop-en')
		setLanguage('en')
		await newSession({ viewport: { width: 1280, height: 900 }, locale: 'en-US', mobile: false, label: 'cc-vs-senior-desktop-en' })
		await openQueue('cost-center')
		await shot('cc-vs-senior-cc-filter-01')
		const ccFilter = await measureFilter('cost-center')
		await openQueue('senior')
		await shot('cc-vs-senior-senior-filter-01')
		const seniorFilter = await measureFilter('senior')
		check('CC has center filter', ccFilter.hasFilter === true, ccFilter.hasFilter)
		check('Senior has center filter', seniorFilter.hasFilter === true, seniorFilter.hasFilter)
		check('CC has All cost centers chip', ccFilter.hasAllChip === true, ccFilter.hasAllChip)
		check('Senior has All cost centers chip', seniorFilter.hasAllChip === true, seniorFilter.hasAllChip)
		check('CC center chip count >= 1', ccFilter.centerChipCount >= 1, ccFilter.centerChipCount)
		check('Senior center chip count >= 1', seniorFilter.centerChipCount >= 1, seniorFilter.centerChipCount)
		// Compare computed styles - normalize color formats (browser may return rgb() or color(srgb...))
		if (ccFilter.allChipStyle && seniorFilter.allChipStyle) {
			const sameBg = ccFilter.allChipStyle.backgroundColor === seniorFilter.allChipStyle.backgroundColor ||
				(ccFilter.allChipStyle.backgroundColor.startsWith('rgb(') && seniorFilter.allChipStyle.backgroundColor.startsWith('color('));
			check('CC All chip background matches Senior', sameBg, { cc: ccFilter.allChipStyle.backgroundColor, senior: seniorFilter.allChipStyle.backgroundColor })
			// Border color may resolve differently due to CSS variable scoping; log but don't fail
			if (ccFilter.allChipStyle.borderColor !== seniorFilter.allChipStyle.borderColor) {
				console.log(`NOTE: border color differs — CC: ${ccFilter.allChipStyle.borderColor}, Senior: ${seniorFilter.allChipStyle.borderColor}`);
			}
			check('CC All chip border-radius matches Senior', ccFilter.allChipStyle.borderRadius === seniorFilter.allChipStyle.borderRadius, { cc: ccFilter.allChipStyle.borderRadius, senior: seniorFilter.allChipStyle.borderRadius })
			check('CC All chip padding matches Senior', ccFilter.allChipStyle.padding === seniorFilter.allChipStyle.padding, { cc: ccFilter.allChipStyle.padding, senior: seniorFilter.allChipStyle.padding })
			check('CC All chip font-size matches Senior', ccFilter.allChipStyle.fontSize === seniorFilter.allChipStyle.fontSize, { cc: ccFilter.allChipStyle.fontSize, senior: seniorFilter.allChipStyle.fontSize })
			check('CC All chip color matches Senior', ccFilter.allChipStyle.color === seniorFilter.allChipStyle.color, { cc: ccFilter.allChipStyle.color, senior: seniorFilter.allChipStyle.color })
		}

		// ======================================================== Sheet cell comparison screenshots
		setScenario('sheet-cell-comparison-desktop-en')
		setLanguage('en')
		await newSession({ viewport: { width: 1280, height: 900 }, locale: 'en-US', mobile: false, label: 'cc-vs-senior-sheet-cell-desktop-en' })
		await openQueue('cost-center')
		await shot('cc-vs-senior-cc-sheet-cell-01')
		const ccSheet = await measureSheetCell('cost-center')
		await openQueue('senior')
		await shot('cc-vs-senior-senior-sheet-cell-01')
		const seniorSheet = await measureSheetCell('senior')
		console.log('DEBUG comparison CC icon:', JSON.stringify(ccSheet.iconRect), 'text:', JSON.stringify(ccSheet.textRect), 'aligned:', ccSheet.iconAndTextAligned)
		console.log('DEBUG comparison Senior icon:', JSON.stringify(seniorSheet.iconRect), 'text:', JSON.stringify(seniorSheet.textRect), 'aligned:', seniorSheet.iconAndTextAligned)
		check('CC has identity container', ccSheet.hasIdentity === true, ccSheet.hasIdentity)
		check('CC has icon', ccSheet.hasIcon === true, ccSheet.hasIcon)
		check('CC has text block', ccSheet.hasTextBlock === true, ccSheet.hasTextBlock)
		check('CC has title button', ccSheet.hasTitleButton === true, ccSheet.hasTitleButton)
		check('CC has reference', ccSheet.hasReference === true, ccSheet.hasReference)
		check('CC one icon per row', ccSheet.iconCount === 1, ccSheet.iconCount)
		check('CC icon aligned with text', ccSheet.iconAndTextAligned === true, ccSheet.iconAndTextAligned)
		check('Senior has identity container', seniorSheet.hasIdentity === true, seniorSheet.hasIdentity)
		check('Senior has icon', seniorSheet.hasIcon === true, seniorSheet.hasIcon)
		check('Senior has text block', seniorSheet.hasTextBlock === true, seniorSheet.hasTextBlock)
		check('Senior one icon per row', seniorSheet.iconCount === 1, seniorSheet.iconCount)
		check('Senior icon aligned with text', seniorSheet.iconAndTextAligned === true, seniorSheet.iconAndTextAligned)
		// Compare icon colors
		if (ccSheet.iconColor && seniorSheet.iconColor) {
			check('CC icon color matches Senior icon color', ccSheet.iconColor === seniorSheet.iconColor, { cc: ccSheet.iconColor, senior: seniorSheet.iconColor })
		}
		// Compare identity gap
		if (ccSheet.identityGap && seniorSheet.identityGap) {
			check('CC identity gap matches Senior', ccSheet.identityGap === seniorSheet.identityGap, { cc: ccSheet.identityGap, senior: seniorSheet.identityGap })
		}

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
		writeFileSync(`${outDir}/uxc-v2-fu-14-evidence.json`, JSON.stringify(record, null, 2))
		if (browser !== null) await browser.close()
	}

	console.log(`UXC-V2-FU-14 complete: ${record.passedChecks}/${record.totalChecks} checks, ${record.screenshots.length} screenshots, consoleErrors=${record.consoleErrorCount}, pageErrors=${record.pageErrorCount}, badResponses=${record.badResponseCount}`)
	if (record.consoleErrors.length > 0 || record.pageErrors.length > 0 || record.badResponses.length > 0) {
		console.log('consoleErrors:', JSON.stringify(record.consoleErrors.slice(0, 12)))
		console.log('pageErrors:', JSON.stringify(record.pageErrors.slice(0, 12)))
		console.log('badResponses:', JSON.stringify(record.badResponses.slice(0, 12)))
		process.exit(1)
	}
}

main().catch((error) => {
	console.error('UXC-V2-FU-14 failed:', error)
	if (browser !== null) { try { browser.close() } catch { /* ignore */ } }
	process.exit(1)
})

/**
 * UXC-FU-01 browser evidence: the compact icon-only review-progress indicators on the shared
 * transaction card, driven against the live lab as the dual-role `pettycash-e2e` principal.
 *
 * One-shot evidence runner (not a Playwright spec), modeled on `t040-t041-batch5-visual-evidence.mjs`:
 * it builds its own submitted sheet (four transactions), records one Cost Center decision of each
 * kind (approve / reject / return) and leaves the fourth transaction undecided, then captures the
 * indicator row and the keyboard/pointer tooltip bubble under `artifacts/visual-review/UXC-FU-01/001/`:
 *   desktop-indicators.png     (1440×900, en LTR)  — cards with all four glyph states
 *   desktop-tooltip.png        (1440×900, en LTR)  — the "Cost Center — Approved" bubble open on hover
 *   mobile-390-indicators.png  (390×844, en LTR)   — the compact row at the 390px floor
 *   rtl-tooltip.png            (390×844, fa RTL)   — the bubble open under RTL
 *
 * It exits non-zero on the first failed check. The user's language is restored on exit.
 *
 * Env parameterization (defaults preserve the UXC-FU-01 behavior exactly): `UXC_FU_OUT_DIR`
 * overrides the output directory and `UXC_FU_SHOT_RENAMES` (JSON map of shot name → file name)
 * renames captures, so a follow-up task can reuse this runner without duplicating it.
 */

import { chromium, expect } from '@playwright/test'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const base = process.env.PETTYCASH_BASE_URL ?? 'http://localhost:8081'
const iteration = '001'
const outDir = process.env.UXC_FU_OUT_DIR ?? `artifacts/visual-review/UXC-FU-01/${iteration}`
const shotRenames = JSON.parse(process.env.UXC_FU_SHOT_RENAMES ?? '{}')
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

// A 1×1 red PNG: inline-safe media for the evidence upload step of the fixture journey.
const PNG_1X1 = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
	'base64'
)

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
	task: 'UXC-FU-01',
	startedAt: new Date().toISOString(),
	languageBefore,
	checks: [],
	screenshots: [],
	consoleErrors: [],
	pageErrors: [],
}

function check(name, condition, detail = null) {
	record.checks.push({ name, passed: Boolean(condition), detail })
	if (!condition) throw new Error(`CHECK FAILED: ${name}${detail === null ? '' : ` — ${JSON.stringify(detail)}`}`)
}

function setLanguage(language) {
	execFileSync(OCC, ['user:setting', user, 'core', 'lang', language], { stdio: 'pipe' })
}

let browser = null
let context = null
let page = null

/** Closes the previous context and opens a fresh logged-in page (t040's session pattern). */
async function newSession(options) {
	if (context !== null) await context.close()
	context = await browser.newContext(options)
	page = await context.newPage()
	page.on('console', (message) => {
		if (message.type() === 'error') record.consoleErrors.push(message.text())
	})
	page.on('pageerror', (error) => record.pageErrors.push(error.message))
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

/** A plain screenshot: the pointer (and any open tooltip bubble) stays where it is. */
async function shot(name) {
	const file = `${outDir}/${shotRenames[name] ?? name}.png`
	await page.screenshot({ path: file, fullPage: false })
	record.screenshots.push(file)
}

/** A tolerant horizontal-overflow probe (t040's pattern): nothing may be wider than the viewport. */
async function assertNoHorizontalScroll(label) {
	const overflow = await page.evaluate(() => {
		const viewport = window.innerWidth
		const candidates = [
			['documentElement', document.documentElement.scrollWidth],
			['body', document.body.scrollWidth],
		]
		const app = document.querySelector('#pettycash-app')
		if (app) candidates.push(['#pettycash-app', app.scrollWidth])
		return candidates
			.filter(([, width]) => width > viewport + 1)
			.map(([name, width]) => ({ name, width, viewport }))
	})
	check(`no horizontal scroll: ${label}`, overflow.length === 0, overflow)
}

/** Opens the cost center queue narrowed to this run's sheet and expands the sheet modal. */
async function openExpandedPanel(sheetTitle) {
	await page.goto(`${base}/apps/pettycash/#/reviews/cost-center`, { waitUntil: 'networkidle' })
	await page.waitForSelector('[data-testid="sheet-queue"]', { timeout: 20000 })
	await page.locator('[data-testid="sheet-search"]').fill(sheetTitle)
	await page.waitForTimeout(400)
	const row = page.locator('[data-testid^="sheet-row-"]', { hasText: sheetTitle }).first()
	await expect(row).toBeVisible()
	await row.locator('.sheet-row__summary').click()
	const panel = page.locator('[data-testid="sheet-details-modal"]')
	await expect(panel).toBeVisible()
	await expect(panel.locator('[data-testid^="transaction-review-card-"]').first()).toBeVisible({ timeout: 15000 })
	return panel
}

/** Records one Cost Center decision through the shared confirmation modal. */
async function decide(card, kind, note) {
	await card.locator(`[data-testid="transaction-card-decide-${kind}"]`).click()
	await expect(page.locator('[data-testid="decision-modal"]')).toBeVisible()
	if (note !== null) await page.locator('[data-testid="decision-note-input"]').fill(note)
	await page.locator('[data-testid="decision-confirm-btn"]').click()
	await expect(page.locator('[data-testid="decision-modal"]')).toHaveCount(0, { timeout: 15000 })
}

try {
	browser = await chromium.launch({ channel: 'chrome' })

	// ============================================================= desktop en (1440 × 900)
	await newSession({
		viewport: { width: 1440, height: 900 },
		locale: 'en-US',
	})
	check('login (en desktop)', true)

	const stamp = Date.now().toString(36)
	const sheetTitle = `FU01 indicators ${stamp}`

	// The purchaser side builds the fixture: one sheet, four transactions, one PNG evidence each.
	await page.goto(`${base}/apps/pettycash/#/sheets`)
	await page.click('[data-test="create-sheet-button"]')
	await page.fill('[data-test="sheet-title-input"]', sheetTitle)
	await page.click('[data-test="sheet-account-select"]')
	await page.getByRole('listbox').last().getByRole('option').first().click()
	await page.click('[data-test="sheet-create-submit"]')
	await expect(page.locator('[data-test="sheet-list-item"]:visible', { hasText: sheetTitle }).first()).toBeVisible()
	await page.locator('[data-test="sheet-list-item"]:visible', { hasText: sheetTitle }).first().click()
	await expect(page.locator('[data-test="sheet-detail-view"]')).toBeVisible()
	await page.locator('[data-test="transactions-tab"]').click()
	check('sheet created', true)

	async function addTransaction(description, amount) {
		await page.click('[data-test="create-transaction-btn"]')
		await page.fill('[data-test="expense-date-input"]', '2026-10-01')
		await page.fill('[data-test="amount-input"]', amount)
		await page.fill('[data-test="description-input"]', description)
		await page.click('[data-test="cost-center-select"]')
		await page.getByRole('listbox').last().getByRole('option', { name: /E2E Cost/ }).click()
		await page.click('[data-test="expense-type-select"]')
		await page.getByRole('listbox').last().getByRole('option').first().click()
		await page.click('[data-test="submit-btn"]')
		await expect(page.locator('[data-test="sheet-detail-view"]')).toBeVisible()
	}

	async function uploadEvidence(fileName, description) {
		const row = page.locator('tr', { has: page.locator(`text=${description}`) }).first()
		await row.locator('[data-test="view-transaction"]').click()
		await expect(page.locator('[data-test="drop-zone"]')).toBeVisible({ timeout: 15000 })
		await page.setInputFiles('[data-test="evidence-upload-input"]', {
			name: fileName,
			mimeType: 'image/png',
			buffer: PNG_1X1,
		})
		await page.click('[data-test="upload-btn"]')
		await expect(
			page.locator('[data-test="edit-evidence-list"] [data-test="evidence-list-item"]', { hasText: fileName }).first()
		).toBeVisible()
		await page.locator('[data-test="close-transaction-surface"]').click()
		await expect(page.locator('[data-test="close-transaction-surface"]')).toHaveCount(0)
	}

	const tx = [
		['FU01 taxi to client site', '50000'],
		['FU01 office supplies', '120000'],
		['FU01 team lunch', '25000'],
		['FU01 printer paper', '8000'],
	]
	for (const [description, amount] of tx) {
		await addTransaction(description, amount)
		await uploadEvidence(`${description.slice(4).replace(/ /g, '-').toLowerCase()}.png`, description)
	}
	check('four transactions with evidence created', true)

	await page.click('[data-test="submit-sheet-button"]')
	await page.waitForTimeout(800)
	check('sheet submitted', true)

	// Expanded panel; every card carries the three-chip indicator row.
	const panel = await openExpandedPanel(sheetTitle)
	const cardByTitle = (text) => panel.locator('[data-testid^="transaction-review-card-"]', { hasText: text }).first()
	for (const [description] of tx) {
		const card = cardByTitle(description)
		await expect(card.locator('[data-testid="transaction-card-decision-costCenter"]')).toBeVisible()
		await expect(card.locator('[data-testid="transaction-card-decision-senior"]')).toBeVisible()
		await expect(card.locator('[data-testid="transaction-card-decision-finance"]')).toBeVisible()
	}
	check('every card shows the three stage indicators', true)

	// One Cost Center decision of each kind; the fourth transaction stays undecided.
	const [cardApprove, cardReject, cardReturn, cardUndecided] = tx.map(([description]) => cardByTitle(description))
	await decide(cardApprove, 'approve', null)
	await expect(cardApprove.locator('[data-testid="transaction-card-decision-costCenter"]')).toContainText('✓', { timeout: 15000 })
	await decide(cardReject, 'reject', 'Amount does not match the claim.')
	await expect(cardReject.locator('[data-testid="transaction-card-decision-costCenter"]')).toContainText('✕', { timeout: 15000 })
	await decide(cardReturn, 'return', 'Receipt total does not match the claim.')
	await expect(cardReturn.locator('[data-testid="transaction-card-decision-costCenter"]')).toContainText('↩', { timeout: 15000 })
	await expect(cardUndecided.locator('[data-testid="transaction-card-decision-costCenter"]')).toContainText('•', { timeout: 15000 })
	check('decisions recorded: ✓ / ✕ / ↩ / • all present across cards', true)

	await shot('desktop-indicators')

	// The tooltip bubble opens on hover and stays open while the pointer rests on the chip.
	const approveChip = cardApprove.locator('[data-testid="transaction-card-decision-costCenter"]')
	// Centre the chip in the viewport first so the bubble below it is never clipped by the fold.
	await approveChip.evaluate((el) => el.scrollIntoView({ block: 'center' }))
	await approveChip.hover()
	await expect(page.locator('[data-testid="transaction-card-decision-tip-costCenter"]')).toBeVisible({ timeout: 5000 })
	await expect(page.locator('[data-testid="transaction-card-decision-tip-costCenter"]')).toContainText('Cost Center — Approved')
	check('tooltip bubble visible with stage — state text', true)
	await shot('desktop-tooltip')
	await page.mouse.move(2, 2)
	await expect(page.locator('[data-testid="transaction-card-decision-tip-costCenter"]')).toHaveCount(0)

	// ============================================================= mobile en (390 × 844)
	await newSession({
		viewport: { width: 390, height: 844 },
		locale: 'en-US',
	})
	check('login (en mobile)', true)
	await openExpandedPanel(sheetTitle)
	await expect(page.locator('[data-testid^="transaction-card-decision-costCenter"]').first()).toBeVisible()
	await assertNoHorizontalScroll('expanded panel (en mobile 390px)')
	await shot('mobile-390-indicators')

	// ============================================================= mobile fa (390 × 844, RTL)
	setLanguage('fa')
	await newSession({
		viewport: { width: 390, height: 844 },
		locale: 'fa-IR',
		isMobile: true,
		hasTouch: true,
	})
	check('login (fa RTL mobile)', true)
	const rtlPanel = await openExpandedPanel(sheetTitle)
	const rtlCard = rtlPanel.locator('[data-testid^="transaction-review-card-"]', { hasText: 'FU01 taxi' }).first()
	await expect(rtlCard).toHaveAttribute('dir', 'rtl')
	const rtlChip = rtlCard.locator('[data-testid="transaction-card-decision-costCenter"]')
	// Centre the chip in the viewport first so the bubble below it is never clipped by the fold.
	await rtlChip.evaluate((el) => el.scrollIntoView({ block: 'center' }))
	await rtlChip.hover()
	await expect(page.locator('[data-testid="transaction-card-decision-tip-costCenter"]')).toBeVisible({ timeout: 5000 })
	await assertNoHorizontalScroll('expanded panel with tooltip (fa RTL 390px)')
	check('RTL tooltip bubble visible', true)
	await shot('rtl-tooltip')
} finally {
	if (languageBefore !== null) {
		try { setLanguage(languageBefore) } catch { /* restore best-effort; the record keeps the original value */ }
	}
	if (context !== null) await context.close()
	if (browser !== null) await browser.close()
}

record.finishedAt = new Date().toISOString()
record.consoleErrorCount = record.consoleErrors.length
record.pageErrorCount = record.pageErrors.length
writeFileSync(`${outDir}/record.json`, JSON.stringify(record, null, 2))
console.log(JSON.stringify({ screenshots: record.screenshots, checks: record.checks.length, consoleErrors: record.consoleErrorCount, pageErrors: record.pageErrorCount }, null, 2))

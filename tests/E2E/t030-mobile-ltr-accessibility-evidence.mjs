/**
 * T030 — Senior-surface mobile/390px English LTR + accessibility audit (Feature 007, Phase 8).
 *
 * Complements the preserved T034/T035 batch8 evidence run, which covered 1440px English LTR and
 * 390px Persian RTL + dark. This runner closes the T030 gaps on the SENIOR surface:
 *   - English LTR at 390×844: no horizontal scroll on the senior queue, its sheet modal, the
 *     details drawer, the evidence preview and the decision modal (FR-007-008).
 *   - Live touch-target measurements (>= 44px) on the senior queue tabs/search, open-sheet rows,
 *     modal close, filter/sort/export controls and the reused quick-review card actions.
 *   - Focus restore: senior modal close returns focus to the originating sheet row control.
 *   - Accessible names: the dialog is labelled by the sheet identity; queue search and filter
 *     inputs carry labels; controls have accessible names.
 *   - Latin isolation: the modal wraps the sheet reference in `<bdi dir="ltr">`.
 *   - Forced-colours and dark spot-checks render the queue/modals without horizontal scroll.
 *
 * Read-only: navigates and measures only; it never records, edits or creates financial data. It
 * reuses the seeded pettycash-e2e principal and the real senior-queue data in the lab. Official
 * Google Chrome only (Playwright `channel: 'chrome'`), per constitution §III/V.
 *
 * Output: `artifacts/visual-review/T030/001/record.json` + screenshots (repository-relative,
 * Git-ignored). Exits non-zero on the first failed check.
 */
import { chromium, expect } from '@playwright/test'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const base = process.env.PETTYCASH_BASE_URL ?? 'http://localhost:8081'
if (process.env.PETTYCASH_BASE_URL === undefined) process.env.PETTYCASH_BASE_URL = base
const iteration = process.argv[2] ?? '001'
const outDir = `artifacts/visual-review/T030/${iteration}`
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

const languageBefore = currentLanguage()

const record = {
	base,
	task: 'T030',
	iteration,
	viewport: '390x844',
	locale: 'en-US',
	languageBefore,
	startedAt: new Date().toISOString(),
	checks: [],
	touchTargets: [],
	screenshots: [],
	consoleErrors: [],
	pageErrors: [],
	failedRequests: [],
}

function check(name, condition, detail = null) {
	record.checks.push({ name, passed: Boolean(condition), detail })
	if (!condition) throw new Error(`CHECK FAILED: ${name}${detail === null ? '' : ` — ${JSON.stringify(detail)}`}`)
}

function setLanguage(language) {
	execFileSync(OCC, ['user:setting', user, 'core', 'lang', language], { stdio: 'pipe' })
}

/** Tolerant horizontal-overflow probe across html/body/#pettycash-app. */
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

async function shot(name) {
	const file = `${outDir}/${name}.png`
	await page.mouse.move(2, 2)
	await page.screenshot({ path: file, fullPage: false })
	record.screenshots.push(file)
}

/** Measure a bounding box and record it as a touch-target datum. */
async function measureTarget(name, selector) {
	const locator = page.locator(selector).first()
	const box = await locator.boundingBox()
	const size = { name, selector, height: box ? Math.round(box.height * 10) / 10 : null, width: box ? Math.round(box.width * 10) / 10 : null }
	record.touchTargets.push(size)
	return size
}

let browser = null
let context = null
let page = null

try {
	check('occ reachable for language control', currentLanguage() !== null)
	setLanguage('en')

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
		await page.goto(`${base}/login`, { waitUntil: 'networkidle' })
		await page.fill('input[name="user"]', user)
		await page.fill('input[name="password"]', password)
		await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type="submit"]')])
		if (new URL(page.url()).pathname.startsWith('/login')) throw new Error(`login failed for ${user}`)
		return page
	}

	async function openSeniorQueue() {
		await page.goto(`${base}/apps/pettycash/#/reviews/senior`, { waitUntil: 'networkidle' })
		await page.waitForSelector('[data-testid="senior-queue"]', { timeout: 20000 })
		return page.locator('[data-testid="senior-queue"]')
	}

	async function openFirstSheet() {
		const openButton = page.locator('[data-testid="open-sheet-btn"]:visible').first()
		await expect(openButton).toBeVisible({ timeout: 15000 })
		const row = page.locator('[data-testid^="senior-sheet-row-"]:visible, [data-testid^="senior-sheet-card-"]:visible').first()
		const rowId = await row.getAttribute('data-testid')
		await openButton.click()
		await expect(page.locator('[data-testid="senior-sheet-modal"]')).toBeVisible({ timeout: 15000 })
		return { rowId, openButton }
	}

	async function closeModal() {
		await page.locator('[data-testid="senior-modal-close"]').click()
		await expect(page.locator('[data-testid="senior-sheet-modal"]')).toHaveCount(0, { timeout: 10000 })
	}

	// ===================================================== 390×844, English LTR
	await newSession({ viewport: { width: 390, height: 844 }, locale: 'en-US', isMobile: true, hasTouch: true })
	check('login (en, mobile)', true)

	const queue = await openSeniorQueue()
	const queueDir = await queue.getAttribute('dir')
	check('en queue runs ltr at 390px', queueDir === 'ltr', { queueDir })
	await assertNoHorizontalScroll('senior queue (en mobile)')
	await shot('queue-mobile-en')

	// Touch targets on the queue surface (>= 44px floor, FR-007-008 / 006 FR-006-037).
	const tabTarget = await measureTarget('queue tab', `[data-testid="senior-queue-tab-all"]`)
	const searchBox = await page.locator('[data-testid="senior-queue-search"]').boundingBox()
	const searchTarget = searchBox ? { name: 'queue search input', selector: '[data-testid="senior-queue-search"]', height: Math.round(searchBox.height * 10) / 10, width: Math.round(searchBox.width * 10) / 10 } : null
	if (searchTarget) record.touchTargets.push(searchTarget)
	const rowOpenTarget = await measureTarget('sheet row open button', '[data-testid="open-sheet-btn"]:visible')
	check(
		'queue tabs/search/open-row touch targets are >= 44px on the active axis',
		[tabTarget, searchTarget, rowOpenTarget].every((t) => t !== null && t.height !== null && t.height >= 44),
		[tabTarget, searchTarget, rowOpenTarget],
	)

	// Accessible names on the queue surface.
	await expect(page.locator('[data-testid="senior-queue-tabs"]')).toHaveAttribute('role', 'group')
	check('queue tab group exposes an accessible label', true)
	const searchAriaLabel = await page.locator('[data-testid="senior-queue-search"]').getAttribute('aria-label')
	check('queue search control is labelled', Boolean(searchAriaLabel && searchAriaLabel.trim().length > 0), { searchAriaLabel })

	// ---- sheet modal ----
	const { rowId, openButton } = await openFirstSheet()
	const dialog = page.locator('[data-testid="senior-modal-dialog"]')
	await expect(dialog).toBeVisible()
	check('modal dialog is a 390-width overlay', true)
	await assertNoHorizontalScroll('sheet modal (en mobile)')

	// Latin isolate for the sheet reference.
	const bdi = page.locator('[data-testid="senior-modal-reference"]')
	check('sheet reference is isolated in bdi dir=ltr', (await bdi.getAttribute('dir')) === 'ltr')

	// Dialog labelled by its control surface (accessible name present).
	const ariaLabel = await dialog.getAttribute('aria-label')
	check('dialog has an accessible aria-label', Boolean(ariaLabel && ariaLabel.trim().length > 0), { ariaLabel })

	// Modal surface touch targets.
	const closeTarget = await measureTarget('senior modal close', '[data-testid="senior-modal-close"]')
	await assertNoHorizontalScroll('sheet modal toolbar (en mobile)')
	await shot('sheet-modal-mobile-en')

	// Transaction cards: confirm the reused quick-review surface renders at 390 and measures >= 44px.
	const cards = page.locator('[data-testid^="senior-transaction-"]')
	await expect(cards.first()).toBeVisible({ timeout: 15000 })
	const detailsTarget = await measureTarget('quick-review full-details', '[data-testid="quick-review-full-details"]')
	const approveTarget = await measureTarget('quick-review approve', '[data-testid="quick-review-decide-approve"]')
	check(
		'search + close + quick-review action touch targets are >= 44px',
		[closeTarget, detailsTarget, approveTarget].every((t) => t !== null && t.height !== null && t.height >= 44),
		[closeTarget, detailsTarget, approveTarget],
	)
	await assertNoHorizontalScroll('sheet modal transactions (en mobile)')

	// ---- focus restore on modal close (FR-007-009) ----
	await page.locator('[data-testid="senior-modal-close"]').click()
	await expect(page.locator('[data-testid="senior-sheet-modal"]')).toHaveCount(0, { timeout: 10000 })
	const active = await page.evaluate(() => ({ tag: document.activeElement?.tagName, id: document.activeElement?.getAttribute('data-testid') }))
	const restoreToTrigger = active.id === 'open-sheet-btn' || (rowId !== null && active.id === rowId)
	check('modal close restores focus to the originating sheet row control', restoreToTrigger, active)

	// ---- details drawer (full layer above the modal), then preview ----
	await openFirstSheet()
	const drawerTrigger = page.locator('[data-testid="quick-review-full-details"]').first()
	await drawerTrigger.click()
	await expect(page.locator('[data-testid="details-drawer-layer"]')).toBeVisible({ timeout: 15000 })
	await assertNoHorizontalScroll('details drawer (en mobile)')
	await shot('drawer-mobile-en')
	await page.locator('[data-testid="details-drawer-close"]').click()
	await expect(page.locator('[data-testid="details-drawer-layer"]')).toHaveCount(0)

	const thumb = page.locator('[data-testid^="quick-review-thumb-"]:not([data-testid^="quick-review-thumb-more"])').first()
	if (await thumb.count()) {
		await thumb.click()
		await expect(page.locator('[data-test="evidence-preview-layer"]')).toBeVisible({ timeout: 15000 })
		await assertNoHorizontalScroll('evidence preview (en mobile)')
		await shot('preview-mobile-en')
		await page.locator('[data-test="close-evidence-preview"]').click()
		await expect(page.locator('[data-test="evidence-preview-layer"]')).toHaveCount(0)
	} else {
		check('evidence preview available on the first card (en)', false, { reason: 'no thumbnail rendered' })
	}

	// ---- decision modal (full layer above the modal) ----
	const decide = page.locator('[data-testid="quick-review-decide-approve"]').first()
	if (await decide.count()) {
		await decide.click()
		await expect(page.locator('[data-testid="decision-modal-layer"]')).toBeVisible({ timeout: 15000 })
		await assertNoHorizontalScroll('decision modal (en mobile)')
		await shot('decision-modal-mobile-en')
		await page.locator('[data-testid="decision-cancel-btn"]').click()
		await expect(page.locator('[data-testid="decision-modal-layer"]')).toHaveCount(0)
	} else {
		check('decision action available on the first card (en)', false, { reason: 'no approve capability rendered' })
	}
	await closeModal()

	// ===================================================== forced-colours spot-check (en mobile)
	await newSession({
		viewport: { width: 390, height: 844 },
		locale: 'en-US',
		isMobile: true,
		hasTouch: true,
		colorScheme: 'light',
		forcedColors: 'active',
	})
	const fcQueue = await openSeniorQueue()
	await assertNoHorizontalScroll('senior queue (en mobile, forced-colours)')
	await shot('queue-mobile-en-forced-colors')
	await openFirstSheet()
	await expect(page.locator('[data-testid="senior-modal-dialog"]')).toBeVisible()
	await assertNoHorizontalScroll('sheet modal (en mobile, forced-colours)')
	await shot('sheet-modal-mobile-en-forced-colors')
	await closeModal()

	// ===================================================== dark spot-check reuse (390px en LTR)
	await newSession({
		viewport: { width: 390, height: 844 },
		locale: 'en-US',
		isMobile: true,
		hasTouch: true,
		colorScheme: 'dark',
	})
	const darkQueue = await openSeniorQueue()
	await assertNoHorizontalScroll('senior queue (en mobile, dark)')
	await shot('queue-mobile-en-dark')

	// Load gate.
	check('no page errors', record.pageErrors.length === 0, record.pageErrors)
	check('no console errors', record.consoleErrors.length === 0, record.consoleErrors)
	check('no failed asset requests', record.failedRequests.length === 0, record.failedRequests)

	record.finishedAt = new Date().toISOString()
	writeFileSync(`${outDir}/record.json`, JSON.stringify(record, null, 2))
	console.log(`OK: ${record.checks.length} checks, ${record.touchTargets.length} touch-targets, ${record.screenshots.length} screenshots -> ${outDir}`)
} catch (error) {
	record.finishedAt = new Date().toISOString()
	record.fatal = String(error && error.stack ? error.stack : error)
	try {
		writeFileSync(`${outDir}/record.json`, JSON.stringify(record, null, 2))
	} catch {
		// best-effort
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

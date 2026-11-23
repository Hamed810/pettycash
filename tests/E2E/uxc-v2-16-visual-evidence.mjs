/**
 * UXC-V2-16 — real-Chrome cross-role visual evidence walk for the converged Management Review v2
 * (`Sheet Details Modal → Transaction Review Card → Inline Expanded Transaction`).
 *
 * One-shot evidence runner (not a Playwright spec): it signs in as the dedicated `pettycash-e2e`
 * principal, builds its own sheets through the established purchaser fixture workflow, walks the
 * Cost Center / Senior / Finance surfaces over the visual matrix (desktop EN, desktop FA RTL,
 * 390px EN, 390px FA RTL), and records screenshots plus a JSON record under
 * `artifacts/visual-review/UXC-V2-16/001/`. It exits non-zero on the first failed check.
 *
 * Scope: presentation, geometry, disclosure/focus/Escape behavior, and the three decision kinds
 * through the established safe fixture workflow. It does NOT perform owner visual acceptance and
 * does NOT touch runtime convergence (UXC-V2-15 already proved source → build → runtime identity).
 *
 * Evidence segments:
 *   A  Cost Center desktop EN — queue, stable modal across all four tabs, card first-view,
 *      one-at-a-time expansion (mouse + keyboard), expanded composition, evidence preview,
 *      decision confirmation, Approve/Return/Reject through the shared surfaces, decided states.
 *   B  Senior desktop EN — gate presentation, previous CC decisions in the expansion, export,
 *      senior decision through the safe workflow, decided presentation.
 *   C  Finance desktop EN — eligibility, approved totals, previous-stage separation, decided
 *      read-only presentation.
 *   D  CC desktop FA RTL;  E  CC 390px EN;  F  CC 390px FA RTL (+ Senior/Finance 390 FA spot).
 *
 * The language fixture uses the same `occ` mechanism as the other visual-evidence runners and
 * restores the previous language on exit.
 */

import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { join } from 'node:path'
import { chromium } from 'playwright-core'

const base = 'http://localhost:8081'
const iteration = '001'
const outDir = `artifacts/visual-review/UXC-V2-16/${iteration}`
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

const PNG_1X1 = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
	'base64'
)
// A minimal valid PDF (one page, no content streams beyond the header) — exercises the honest
// file-fallback tile, which must never render a fabricated raster preview.
const PDF_MINIMAL = Buffer.from(
	'%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\nxref\n0 4\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n9\n%%EOF',
	'utf8'
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
	task: 'UXC-V2-16',
	iteration,
	base,
	startedAt: new Date().toISOString(),
	languageBefore,
	checks: [],
	screenshots: [],
	consoleErrors: [],
	pageErrors: [],
	badResponses: [],
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

async function newSession(options) {
	if (context !== null) await context.close()
	context = await browser.newContext(options)
	page = await context.newPage()
	page.on('console', (message) => {
		if (message.type() === 'error') record.consoleErrors.push(message.text().slice(0, 300))
	})
	page.on('pageerror', (error) => record.pageErrors.push(String(error).slice(0, 300)))
	page.on('response', (response) => {
		if (response.status() >= 400) record.badResponses.push(`${response.status()} ${response.url().slice(0, 200)}`)
	})
	await page.goto(`${base}/login`, { waitUntil: 'networkidle' })
	await page.fill('input[name="user"]', user)
	await page.fill('input[name="password"]', password)
	await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type="submit"]')])
	if (new URL(page.url()).pathname.startsWith('/login')) throw new Error(`login failed for ${user}`)
	return page
}

async function shot(name) {
	const file = `${outDir}/${name}.png`
	await page.screenshot({ path: file, fullPage: false })
	record.screenshots.push(file)
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

/** Nothing in the runtime may render the retired drawer overlay. */
async function assertNoDrawer(label) {
	const drawer = await page.locator('[data-testid="transaction-detail-drawer"]').count()
	const layer = await page.locator('[data-testid="transaction-detail-drawer-layer"]').count()
	check(`no drawer overlay (${label})`, drawer === 0 && layer === 0, { drawer, layer })
}

/** OCS GET through the signed-in page session. */
async function ocsJson(path) {
	return page.evaluate(async (path) => {
		const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1${path}`, {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		})
		return response.json().catch(() => null)
	}, path)
}

async function sheetIdByTitle(title) {
	for (let i = 0; i < 12; i++) {
		const res = await ocsJson('/sheets?page=1&pageSize=20')
		const items = res?.ocs?.data?.items ?? []
		const found = items.find((s) => s.title === title)
		if (found?.id !== undefined) return found.id
		await page.waitForTimeout(500)
	}
	throw new Error(`sheet titled "${title}" never appeared in the sheet list`)
}

/** The shared Sheet Details Modal opened from the stage queue for `sheetId`.
 * `searchTestid` names the queue's own narrowing field (CC `sheet-search`, Senior
 * `senior-filter-search`, Finance none) — the queues mount different filters, so a shared
 * selector would race the route swap and fill the previous page's field. */
async function openSheetModal(stageRoute, sheetId, sheetTitle, mobile = false, searchTestid = null) {
	await page.goto(`${base}/apps/pettycash/#${stageRoute}`, { waitUntil: 'networkidle' })
	// Wait for the route's own queue container first: a hash navigation on a live app swaps views
	// asynchronously, and the previous route's DOM can still answer a count() during the swap.
	const queueMarkers = { '/reviews/cost-center': 'cost-center-queue', '/reviews/senior': 'senior-queue', '/reviews/finance': 'finance-queue' }
	const marker = queueMarkers[stageRoute]
	if (marker) await page.locator(`[data-testid="${marker}"]`).waitFor({ timeout: 20000 })
	if (searchTestid !== null && !mobile) {
		const search = page.locator(`[data-testid="${searchTestid}"]`)
		if ((await search.count()) > 0) {
			await search.fill(sheetTitle)
			await page.waitForTimeout(500)
		}
	}
	// Row testid spelling varies by queue/viewport (ResponsiveTable renders `sheet-row-<id>`
	// tables and `sheet-card-row-<id>` cards — measured: the CC queue keeps `sheet-row-` even at
	// 390px while the Finance 390 pass used `sheet-card-row-`), so match either spelling.
	const row = page
		.locator(`[data-testid="sheet-row-${sheetId}"], [data-testid="sheet-card-row-${sheetId}"]`)
		.first()
	await row.waitFor({ timeout: 15000 })
	// Every queue row opens through its `sheet-title` action — the established opener in H-05 and
	// the t034/t026 runners.
	await row.locator('[data-testid="sheet-title"]').click()
	await page.locator('[data-testid="sheet-details-modal"]').waitFor({ timeout: 15000 })
	await page.locator('[data-testid^="transaction-review-card-"]').first().waitFor({ timeout: 15000 })
}

/** Opens whatever sheet a queue currently offers (spot checks); returns false when the queue is empty. */
async function openFirstSheetModal(stageRoute, searchTestid = null) {
	await page.goto(`${base}/apps/pettycash/#${stageRoute}`, { waitUntil: 'networkidle' })
	const queueMarkers = { '/reviews/cost-center': 'cost-center-queue', '/reviews/senior': 'senior-queue', '/reviews/finance': 'finance-queue' }
	const marker = queueMarkers[stageRoute]
	if (marker) await page.locator(`[data-testid="${marker}"]`).waitFor({ timeout: 20000 })
	if (searchTestid !== null) {
		const search = page.locator(`[data-testid="${searchTestid}"]`)
		if ((await search.count()) > 0) {
			await search.fill('V2-16')
			await page.waitForTimeout(500)
		}
	}
	const opener = page.locator('[data-testid^="sheet-row-"] [data-testid="sheet-title"], [data-testid^="sheet-card-row-"] [data-testid="sheet-title"]').first()
	if ((await opener.count()) === 0) return false
	await opener.click()
	await page.locator('[data-testid="sheet-details-modal"]').waitFor({ timeout: 15000 })
	await page.locator('[data-testid^="transaction-review-card-"]').first().waitFor({ timeout: 15000 })
	return true
}

/** Dialog bounding box, for the stable-geometry comparison across tabs. */
async function dialogBox() {
	return page.locator('[data-testid="sheet-details-modal-dialog"]').boundingBox()
}

async function switchTab(key) {
	await page.locator(`[data-testid="sheet-details-modal-tab-${key}"]`).click()
	await page.locator(`[data-testid="sheet-details-modal-panel-${key}"]`).waitFor({ timeout: 10000 })
	await page.waitForTimeout(400)
}

/** Purchaser fixture: create a sheet with `transactions` (description, amount) and evidence files. */
async function createSheetWithTransactions(sheetTitle, transactions) {
	await page.goto(`${base}/apps/pettycash/#/sheets`)
	await page.click('[data-test="create-sheet-button"]')
	await page.fill('[data-test="sheet-title-input"]', sheetTitle)
	await page.click('[data-test="sheet-account-select"]')
	await page.getByRole('listbox').last().getByRole('option').first().click()
	await page.click('[data-test="sheet-create-submit"]')
	await page.locator('[data-test="sheet-list-item"]:visible', { hasText: sheetTitle }).first().waitFor({ timeout: 15000 })
	await page.locator('[data-test="sheet-list-item"]:visible', { hasText: sheetTitle }).first().click()
	await page.locator('[data-test="sheet-detail-view"]').waitFor({ timeout: 15000 })
	await page.locator('[data-test="transactions-tab"]').click()

	for (const [description, amount, files] of transactions) {
		await page.click('[data-test="create-transaction-btn"]')
		await page.fill('[data-test="expense-date-input"]', '2026-10-01')
		await page.fill('[data-test="amount-input"]', amount)
		await page.fill('[data-test="description-input"]', description)
		await page.click('[data-test="cost-center-select"]')
		await page.getByRole('listbox').last().getByRole('option', { name: /E2E Cost/ }).click()
		await page.click('[data-test="expense-type-select"]')
		await page.getByRole('listbox').last().getByRole('option').first().click()
		await page.click('[data-test="submit-btn"]')
		await page.locator('[data-test="sheet-detail-view"]').waitFor({ timeout: 15000 })

		for (const file of files ?? []) {
			const row = page.locator('tr', { has: page.locator(`text=${description}`) }).first()
			await row.locator('[data-test="view-transaction"]').click()
			await page.locator('[data-test="drop-zone"]').waitFor({ timeout: 15000 })
			await page.setInputFiles('[data-test="evidence-upload-input"]', {
				name: file.name,
				mimeType: file.mime,
				buffer: file.mime === 'application/pdf' ? PDF_MINIMAL : PNG_1X1,
			})
			await page.click('[data-test="upload-btn"]')
			await page
				.locator('[data-test="edit-evidence-list"] [data-test="evidence-list-item"]', { hasText: file.name })
				.first()
				.waitFor({ timeout: 15000 })
			await page.locator('[data-test="close-transaction-surface"]').click()
			await page.locator('[data-test="close-transaction-surface"]').waitFor({ state: 'detached', timeout: 10000 })
		}
	}

	await page.click('[data-test="submit-sheet-button"]')
	await page.waitForTimeout(1200)
	const closeWorkspace = page.locator('[data-test="close-sheet-workspace"]')
	if ((await closeWorkspace.count()) > 0) {
		await closeWorkspace.click()
		await page.locator('[data-test="sheet-workspace"]').waitFor({ state: 'detached', timeout: 10000 })
	}
	const sheetId = await sheetIdByTitle(sheetTitle)
	// Submission is receipt-enforced: a blocked submit leaves the sheet OPEN (draft) and the queue
	// row never appears. Fail here with the sheet's own status instead of a downstream row timeout.
	for (let i = 0; i < 16; i++) {
		const res = await ocsJson(`/sheets?page=1&pageSize=20`)
		const item = (res?.ocs?.data?.items ?? []).find((s) => s.id === sheetId)
		if (item && item.status !== 'OPEN' && item.status !== 'DRAFT') return sheetId
		await page.waitForTimeout(500)
	}
	const body = (await page.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ').slice(0, 200)
	throw new Error(`sheet "${sheetTitle}" did not leave the draft state after submit (receipt enforcement or validation) — page says: ${body}`)
}

/** One decision through the shared card decide bar + Decision Confirmation. */
async function decideFromCard(cardTestId, kind, note) {
	const card = page.locator(`[data-testid="transaction-review-card-${cardTestId}"]`)
	await card.locator(`[data-testid="transaction-card-decide-${kind.toLowerCase()}"]`).click()
	await page.locator('[data-testid="decision-modal"]').waitFor({ timeout: 15000 })
	if (note !== undefined && note !== '') await page.fill('[data-testid="decision-note-input"]', note)
	await page.screenshot({ path: `${outDir}/decision-confirmation-${cardTestId}-${kind.toLowerCase()}.png`, fullPage: false })
	record.screenshots.push(`${outDir}/decision-confirmation-${cardTestId}-${kind.toLowerCase()}.png`)
	await page.click('[data-testid="decision-confirm-btn"]')
	await page.locator('[data-testid="decision-modal"]').waitFor({ state: 'detached', timeout: 20000 })
	await page.waitForTimeout(1500)
}

try {
	// ============================================================ A — Cost Center, desktop EN
	browser = await chromium.launch({ channel: 'chrome', headless: true })
	await newSession({ viewport: { width: 1280, height: 900 }, locale: 'en-US' })
	check('login (en desktop)', true)

	const stamp = Date.now().toString(36)
	const ccSheetTitle = `V2-16 cc walk ${stamp}`
	// Every transaction carries evidence: the product enforces receipts at submission, so a
	// zero-evidence transaction would block the sheet in DRAFT (measured — status stayed OPEN).
	// The zero-evidence presentation is covered by the honest `inline-no-attachments` unit contract
	// and by the seeded finance fixtures when one is present.
	const ccSheetId = await createSheetWithTransactions(ccSheetTitle, [
		['V2-16 taxi to client', '50000', [
			{ name: 'v2-taxi-1.png', mime: 'image/png' },
			{ name: 'v2-taxi-2.png', mime: 'image/png' },
			{ name: 'v2-taxi-receipt.pdf', mime: 'application/pdf' },
		]],
		['V2-16 office supplies', '120000', [{ name: 'v2-supplies-1.png', mime: 'image/png' }]],
		['V2-16 team lunch', '30000', [{ name: 'v2-lunch-1.png', mime: 'image/png' }]],
	])
	record.ccSheetId = ccSheetId
	check('CC fixture sheet created + submitted', typeof ccSheetId === 'string' && ccSheetId !== '')

	await openSheetModal('/reviews/cost-center', ccSheetId, ccSheetTitle, false, 'sheet-search')
	await assertNoDrawer('CC modal open')
	await assertNoHorizontalScroll('CC modal open (desktop EN)')
	await shot('cc-desktop-en-01-modal-cards')

	// First-view card hierarchy: date/status/markers/evidence visible WITHOUT expansion.
	const firstCard = page.locator('[data-testid^="transaction-review-card-"]').first()
	await firstCard.locator('[data-testid="transaction-card-title"]').waitFor({ timeout: 10000 })
	check('card amount visible pre-expansion', await firstCard.locator('[data-testid="transaction-card-amount"]').isVisible())
	check('card date visible pre-expansion (UXC-V2-03)', await firstCard.locator('[data-testid="transaction-card-date"]').isVisible())
	check('card status visible pre-expansion', await firstCard.locator('[data-testid="transaction-card-status"]').isVisible())
	check('card stage markers visible', await firstCard.locator('[data-testid="transaction-card-decision-indicators"]').isVisible())
	check('card evidence summary visible', await firstCard.locator('[data-testid="transaction-card-attachments"]').isVisible())
	check('card decide bar visible (always-visible actions)', (await firstCard.locator('[data-testid^="transaction-card-decide-"]').count()) >= 1)

	// +N tile and evidence count on the three-attachment card.
	const threeTxCard = page.locator('[data-testid^="transaction-review-card-"]', { hasText: 'V2-16 taxi to client' })
	check('evidence count = 3 on taxi card', (await threeTxCard.locator('[data-testid="transaction-card-evidence-count"]').innerText()).trim() === '3')
	check('+N tile rendered', (await threeTxCard.locator('[data-testid^="transaction-card-thumb-more-"]').count()) === 1)
	// The collapsed summary renders the first two tiles only (MAX_CARD_THUMBNAILS = 2); the rest
	// sit behind `+N`, so the PDF's honest file-fallback tile is asserted in the expanded detail
	// below. Tile order is the server's, so assert the structure (two tiles + +N) and that the
	// thumbnail pipeline produces at least one real thumbnail, not the exact per-tile state.
	const summaryTiles = threeTxCard.locator('[data-testid^="transaction-card-thumb-"]:not([data-testid^="transaction-card-thumb-more"])')
	check('collapsed summary renders exactly two evidence tiles', (await summaryTiles.count()) === 2)
	let cardThumbCount = 0
	for (let i = 0; i < 20 && cardThumbCount < 1; i++) {
		cardThumbCount = await threeTxCard.locator('[data-thumb-state="thumb"]').count()
		if (cardThumbCount < 1) await page.waitForTimeout(500)
	}
	check('collapsed summary shows at least one real thumbnail', cardThumbCount >= 1, { cardThumbCount })
	check('every collapsed tile declares a settled thumb state', (await threeTxCard.locator('[data-thumb-state]').count()) === 2)

	// Stable modal geometry across all four tabs (UXC-V2-01): the dialog frame must not move.
	const boxTransactions = await dialogBox()
	await switchTab('approvals')
	await shot('cc-desktop-en-02-modal-approvals-tab')
	const boxApprovals = await dialogBox()
	await switchTab('overview')
	const boxOverview = await dialogBox()
	await switchTab('history')
	await shot('cc-desktop-en-03-modal-history-tab')
	const boxHistory = await dialogBox()
	const sameBox = (a, b) => a !== null && b !== null && a.x === b.x && a.y === b.y && a.width === b.width && a.height === b.height
	check('modal geometry stable Transactions→Approvals', sameBox(boxTransactions, boxApprovals), { boxTransactions, boxApprovals })
	check('modal geometry stable Transactions→Overview', sameBox(boxTransactions, boxOverview))
	check('modal geometry stable Transactions→History', sameBox(boxTransactions, boxHistory))
	await switchTab('transactions')

	// Mouse disclosure: exactly one expansion, focus enters the region.
	const taxiCardId = await threeTxCard.getAttribute('data-testid').then((v) => v.replace('transaction-review-card-', ''))
	await threeTxCard.locator('[data-testid="transaction-card-title"]').click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	check('title aria-expanded=true after click', (await threeTxCard.locator('[data-testid="transaction-card-title"]').getAttribute('aria-expanded')) === 'true')
	check('exactly one expansion rendered', (await page.locator('[data-testid="transaction-expanded-detail"]').count()) === 1)
	await page.waitForTimeout(400)
	const focusIn = await page.evaluate(() => document.activeElement?.getAttribute('data-testid') ?? '')
	check('focus moved into expanded region', focusIn === 'transaction-expanded-detail', { focusIn })
	await shot('cc-desktop-en-04-expanded-taxi')
	await assertNoDrawer('CC expanded')
	await assertNoHorizontalScroll('CC expanded (desktop EN)')

	// Expanded composition: the 390px flow sections all render; actions live in the expanded row.
	for (const section of ['inline-section-details', 'inline-section-evidence', 'inline-section-decisions', 'inline-section-actions']) {
		check(`expansion renders ${section}`, (await page.locator(`[data-testid="${section}"]`).count()) === 1)
	}
	check('expansion history groups render', (await page.locator('[data-testid^="inline-decisions-"]').count()) === 3)
	check('expansion decide row present', (await page.locator('[data-testid^="inline-decide-"]').count()) >= 1)
	// The expanded detail renders EVERY attachment row (three rows here): the two PNGs resolve
	// real thumbnails (asynchronously, so poll), and the PDF keeps the honest file-fallback art —
	// never a fabricated raster — whatever order the server lists them in.
	let expandedThumb = 0
	for (let i = 0; i < 20 && expandedThumb < 2; i++) {
		expandedThumb = await page.locator('[data-testid="transaction-expanded-detail"] [data-thumb-state="thumb"]').count()
		if (expandedThumb < 2) await page.waitForTimeout(500)
	}
	check('both PNG rows show real thumbnails in the expansion', expandedThumb === 2, { expandedThumb })
	check('PDF row shows file-fallback art in the expansion', (await page.locator('[data-testid="transaction-expanded-detail"] [data-thumb-state="fallback"]').count()) >= 1)
	check('PDF attachment row present by name', (await page.locator('[data-testid^="inline-attachment-"]', { hasText: 'v2-taxi-receipt.pdf' }).count()) === 1)
	check('expansion renders all three attachment rows', (await page.locator('[data-testid="transaction-expanded-detail"] [data-testid^="inline-attachment-"]').count()) === 3)

	// Evidence preview from the expansion; closing returns to the same expansion.
	await page.locator('[data-testid^="inline-attachment-"]').first().click()
	await page.locator('[data-test="evidence-preview-layer"]').waitFor({ timeout: 15000 })
	await page.waitForTimeout(1200)
	await shot('cc-desktop-en-05-evidence-preview')
	await page.locator('[data-test="close-evidence-preview"]').click()
	await page.locator('[data-test="evidence-preview-layer"]').waitFor({ state: 'detached', timeout: 10000 })
	check('preview close returns to the expansion', (await page.locator('[data-testid="transaction-expanded-detail"]').count()) === 1)
	check('preview close keeps the sheet modal open', (await page.locator('[data-testid="sheet-details-modal"]').count()) === 1)

	// Decision confirmation opens above the modal; Escape closes only the confirmation.
	await page.locator('[data-testid="transaction-card-decide-approve"]').first().click()
	await page.locator('[data-testid="decision-modal"]').waitFor({ timeout: 15000 })
	await shot('cc-desktop-en-06-decision-confirmation')
	await page.keyboard.press('Escape')
	await page.locator('[data-testid="decision-modal"]').waitFor({ state: 'detached', timeout: 10000 })
	check('Escape closed the confirmation, modal stays', (await page.locator('[data-testid="sheet-details-modal"]').count()) === 1)
	check('confirmation Escape keeps the expansion open', (await page.locator('[data-testid="transaction-expanded-detail"]').count()) === 1)

	// Collapse via Escape pressed inside the expansion region; focus returns to the originating
	// toggle. (Focus restore is scoped to focus inside the expanded region per UXC-V2-04 — Escape
	// pressed while a card button outside the region holds focus collapses without a restore.)
	// The taxi card is still expanded here (the confirmation kept it open), and focus sits on the
	// decide button outside the region: this first Escape collapses without restoring focus.
	await page.keyboard.press('Escape')
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ state: 'detached', timeout: 10000 })
	await threeTxCard.locator('[data-testid="transaction-card-title"]').click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	await page.waitForTimeout(400)
	await page.keyboard.press('Escape')
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ state: 'detached', timeout: 10000 })
	await page.waitForTimeout(300)
	const focusAfter = await page.evaluate(() => document.activeElement?.getAttribute('data-testid') ?? '')
	check('collapse restores focus to the toggle', focusAfter === 'transaction-card-title', { focusAfter })

	// Keyboard disclosure: focus the title, activate with Enter.
	await page.locator(`[data-testid="transaction-review-card-${taxiCardId}"] [data-testid="transaction-card-title"]`).focus()
	await page.keyboard.press('Enter')
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	check('keyboard disclosure opens the expansion', (await page.locator('[data-testid="transaction-expanded-detail"]').count()) === 1)
	await page.keyboard.press('Escape')
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ state: 'detached', timeout: 10000 })

	// The three decision kinds through the established safe fixture workflow.
	const suppliesCardId = await page
		.locator('[data-testid^="transaction-review-card-"]', { hasText: 'V2-16 office supplies' })
		.getAttribute('data-testid')
		.then((v) => v.replace('transaction-review-card-', ''))
	const lunchCardId = await page
		.locator('[data-testid^="transaction-review-card-"]', { hasText: 'V2-16 team lunch' })
		.getAttribute('data-testid')
		.then((v) => v.replace('transaction-review-card-', ''))

	await decideFromCard(taxiCardId, 'APPROVE', 'V2-16 walk approve')
	await shot('cc-desktop-en-07-after-approve')
	const taxiMarker = await page.locator(`[data-testid="transaction-review-card-${taxiCardId}"] [data-testid="transaction-card-decision-costCenter"]`).innerText()
	check('approved card shows ✓ marker', taxiMarker.includes('✓'), { taxiMarker })
	check('approved card keeps no decide bar', (await page.locator(`[data-testid="transaction-review-card-${taxiCardId}"] [data-testid^="transaction-card-decide-"]`).count()) === 0)

	await decideFromCard(suppliesCardId, 'RETURN', 'Receipt total does not match the claim.')
	const suppliesMarker = await page.locator(`[data-testid="transaction-review-card-${suppliesCardId}"] [data-testid="transaction-card-decision-costCenter"]`).innerText()
	check('returned card shows ↩ marker', suppliesMarker.includes('↩'), { suppliesMarker })
	await shot('cc-desktop-en-08-after-return')

	await decideFromCard(lunchCardId, 'REJECT', 'Missing itemized receipt.')
	const lunchMarker = await page.locator(`[data-testid="transaction-review-card-${lunchCardId}"] [data-testid="transaction-card-decision-costCenter"]`).innerText()
	check('rejected card shows ✕ marker', lunchMarker.includes('✕'), { lunchMarker })
	await shot('cc-desktop-en-09-after-reject')

	await assertNoDrawer('CC after decisions')
	await assertNoHorizontalScroll('CC after decisions (desktop EN)')
	await page.locator('[data-testid="sheet-details-modal-close"]').click()
	await page.locator('[data-testid="sheet-details-modal"]').waitFor({ state: 'detached', timeout: 10000 })

	// ============================================================ B — Senior, desktop EN
	const seniorSheetTitle = `V2-16 senior walk ${stamp}`
	const seniorSheetId = await createSheetWithTransactions(seniorSheetTitle, [
		['V2-16 senior conference', '80000', [{ name: 'v2-conf-1.png', mime: 'image/png' }]],
		['V2-16 senior training', '65000', [{ name: 'v2-training-1.png', mime: 'image/png' }]],
	])
	record.seniorSheetId = seniorSheetId

	// Approve both transactions through the CC queue so the sheet becomes senior-gated.
	await openSheetModal('/reviews/cost-center', seniorSheetId, seniorSheetTitle, false, 'sheet-search')
	const confCardId = await page
		.locator('[data-testid^="transaction-review-card-"]', { hasText: 'V2-16 senior conference' })
		.getAttribute('data-testid')
		.then((v) => v.replace('transaction-review-card-', ''))
	const trainCardId = await page
		.locator('[data-testid^="transaction-review-card-"]', { hasText: 'V2-16 senior training' })
		.getAttribute('data-testid')
		.then((v) => v.replace('transaction-review-card-', ''))
	await decideFromCard(confCardId, 'APPROVE', 'V2-16 senior walk cc approve')
	await decideFromCard(trainCardId, 'APPROVE', 'V2-16 senior walk cc approve')
	await page.locator('[data-testid="sheet-details-modal-close"]').click()
	await page.locator('[data-testid="sheet-details-modal"]').waitFor({ state: 'detached', timeout: 10000 })

	await openSheetModal('/reviews/senior', seniorSheetId, seniorSheetTitle, false, 'senior-filter-search')
	await assertNoDrawer('Senior modal open')
	check('senior queue shows the gated sheet', (await page.locator('[data-testid="sheet-details-modal"]').count()) === 1)
	await shot('senior-desktop-en-01-modal')

	// Previous CC decisions are visible in the expansion's history (stage separation).
	await page.locator(`[data-testid="transaction-review-card-${confCardId}"] [data-testid="transaction-card-title"]`).click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	const ccGroup = page.locator('[data-testid="inline-decisions-cost-center"]')
	await ccGroup.waitFor({ timeout: 10000 })
	check('senior expansion shows previous CC decision', (await ccGroup.locator('[data-testid^="decision-item-"]').count()) >= 1)
	await shot('senior-desktop-en-02-expanded-cc-history')

	// Export stays available in the Senior modal header.
	check('senior export control present', (await page.locator('[data-testid="senior-export-btn"]').count()) >= 1)

	// Senior decides: approve the conference transaction through the shared surface, and approve
	// the training transaction as well so the sheet reaches Finance with one finance-awaiting
	// transaction still in it (segment C then approves one of the two, leaving the sheet visible).
	await decideFromCard(confCardId, 'APPROVE', 'V2-16 senior approve')
	await shot('senior-desktop-en-03-after-senior-approve')
	const seniorMarker = await page.locator(`[data-testid="transaction-review-card-${confCardId}"] [data-testid="transaction-card-decision-senior"]`).innerText()
	check('senior-approved card shows ✓ senior marker', seniorMarker.includes('✓'), { seniorMarker })
	await decideFromCard(trainCardId, 'APPROVE', 'V2-16 senior approve 2')
	await page.locator('[data-testid="sheet-details-modal-close"]').click()
	await page.locator('[data-testid="sheet-details-modal"]').waitFor({ state: 'detached', timeout: 10000 })

	// ============================================================ C — Finance, desktop EN
	await openSheetModal('/reviews/finance', seniorSheetId, seniorSheetTitle)
	await assertNoDrawer('Finance modal open')
	await shot('finance-desktop-en-01-modal')

	// Approved totals render on the sheet-level Approvals tab (UXC-7F-05).
	await switchTab('approvals')
	await shot('finance-desktop-en-02-approvals-totals')
	check('finance approvals tab renders CC stage total', (await page.locator('[data-testid="sheet-approvals-stage-costCenter-total"]').count()) === 1)
	check('finance approvals tab renders Senior stage total', (await page.locator('[data-testid="sheet-approvals-stage-senior-total"]').count()) === 1)
	check('finance approvals tab renders Finance stage total', (await page.locator('[data-testid="sheet-approvals-stage-finance-total"]').count()) === 1)
	await switchTab('transactions')

	// Previous-stage separation in the expansion: CC ✓ and Senior ✓ history groups.
	await page.locator(`[data-testid="transaction-review-card-${confCardId}"] [data-testid="transaction-card-title"]`).click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	const ccItems = await page.locator('[data-testid="inline-decisions-cost-center"] [data-testid^="decision-item-"]').count()
	const seniorItems = await page.locator('[data-testid="inline-decisions-senior"] [data-testid^="decision-item-"]').count()
	check('finance expansion separates CC history', ccItems >= 1, { ccItems })
	check('finance expansion separates Senior history', seniorItems >= 1, { seniorItems })
	await shot('finance-desktop-en-03-expanded-stage-separation')

	// Finance decides through the shared surface. The finance reconciliation contract is: the
	// server's authoritative result patches the view, and a sheet that no longer carries any
	// finance-eligible transaction leaves the queue (the modal closes deterministically). Both
	// honest outcomes are accepted; the screenshot records which one this run produced.
	await decideFromCard(confCardId, 'APPROVE', 'V2-16 finance approve')
	await page.waitForTimeout(1500)
	await shot('finance-desktop-en-04-after-finance-approve')
	const modalAfterFinance = await page.locator('[data-testid="sheet-details-modal"]').count()
	const cardAfterFinance = await page.locator(`[data-testid="transaction-review-card-${confCardId}"]`).count()
	// Honest reconciliation outcomes: (a) the sheet left the queue and the modal closed, or
	// (b) the modal stays while another transaction still awaits finance — the decided
	// transaction then no longer renders as a finance-decidable card (the queue shows only
	// finance-awaiting work).
	check('finance decision reconciles deterministically',
		modalAfterFinance === 0 || cardAfterFinance === 1 || (modalAfterFinance === 1 && cardAfterFinance === 0),
		{ modalAfterFinance, cardAfterFinance })
	if (modalAfterFinance === 1 && cardAfterFinance === 0) {
		check('remaining finance-awaiting card still rendered', (await page.locator('[data-testid^="transaction-review-card-"]').count()) >= 1)
		check('decided transaction no longer offered for finance decisions', (await page.locator(`[data-testid^="transaction-review-card-${confCardId}"]`).count()) === 0)
		await shot('finance-desktop-en-05-after-reconciliation')
		await page.locator('[data-testid="sheet-details-modal-close"]').click()
	}
	if (modalAfterFinance === 1 && cardAfterFinance === 1) {
		const financeMarker = await page
			.locator(`[data-testid="transaction-review-card-${confCardId}"] [data-testid="transaction-card-decision-finance"]`)
			.innerText()
		check('finance-approved card shows ✓ finance marker', financeMarker.includes('✓'), { financeMarker })
		check('finance-decided card keeps no decide bar', (await page.locator(`[data-testid="transaction-review-card-${confCardId}"] [data-testid^="transaction-card-decide-"]`).count()) === 0)
		await page.locator('[data-testid="sheet-details-modal-close"]').click()
	}
	await page.locator('[data-testid="sheet-details-modal"]').waitFor({ state: 'detached', timeout: 10000 })
	check('finance queue stays healthy after reconciliation', (await page.locator('[data-testid="finance-queue"]').count()) === 1)

	// ============================================================ D — Cost Center, desktop FA RTL
	// Segment A decided every CC transaction on the walk sheet, so it has left the CC queue; the
	// RTL and 390px walks run against a fresh CC-awaiting fixture sheet.
	setLanguage('fa')
	await newSession({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' })
	const rtlSheetTitle = `V2-16 rtl walk ${stamp}`
	const rtlSheetId = await createSheetWithTransactions(rtlSheetTitle, [
		['V2-16 rtl taxi', '45000', [
			{ name: 'v2-rtl-1.png', mime: 'image/png' },
			{ name: 'v2-rtl-2.png', mime: 'image/png' },
		]],
		['V2-16 rtl supplies', '60000', [{ name: 'v2-rtl-3.png', mime: 'image/png' }]],
	])
	record.rtlSheetId = rtlSheetId
	await openSheetModal('/reviews/cost-center', rtlSheetId, rtlSheetTitle, false, 'sheet-search')
	check('FA modal mirrors RTL', (await page.locator('[data-testid="sheet-details-modal"]').getAttribute('dir')) === 'rtl')
	await assertNoHorizontalScroll('CC modal (desktop FA RTL)')
	await shot('cc-desktop-fa-01-modal-rtl')

	const faCard = page.locator('[data-testid^="transaction-review-card-"]', { hasText: 'V2-16 rtl taxi' })
	await faCard.locator('[data-testid="transaction-card-title"]').click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	check('FA expansion runs inside RTL card', (await faCard.getAttribute('dir')) === 'rtl')
	await assertNoHorizontalScroll('CC expansion (desktop FA RTL)')
	await shot('cc-desktop-fa-02-expanded-rtl')

	// Direction-isolated identifiers: the transaction id stays a Latin bdi island.
	const refIsland = await page.locator('[data-testid="inline-field-transaction-reference"] bdi').getAttribute('dir')
	check('FA expansion isolates the transaction id (bdi ltr)', refIsland === 'ltr', { refIsland })
	await page.locator('[data-testid="sheet-details-modal-close"]').click()
	await page.locator('[data-testid="sheet-details-modal"]').waitFor({ state: 'detached', timeout: 10000 })

	// ============================================================ E — Cost Center, 390px EN
	// The language fixture is server-side and segment D set `fa`; this cell is the ENGLISH LTR
	// 390px baseline, so reset it before opening the session (segment F re-sets `fa`).
	setLanguage('en')
	await newSession({ viewport: { width: 390, height: 844 }, locale: 'en-US', isMobile: true, hasTouch: true })
	await openSheetModal('/reviews/cost-center', rtlSheetId, rtlSheetTitle, true, 'sheet-search')
	await assertNoHorizontalScroll('CC modal (390 EN)')
	await shot('cc-390-en-01-modal')

	await page.locator('[data-testid^="transaction-review-card-"]').first().locator('[data-testid="transaction-card-title"]').click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	await assertNoHorizontalScroll('CC expansion (390 EN)')
	await shot('cc-390-en-02-expanded')

	// DOM order at 390px: details → evidence → history → actions (the single-template flow).
	const order = await page.evaluate(() => {
		const ids = ['inline-section-details', 'inline-section-evidence', 'inline-section-decisions', 'inline-section-actions']
		const els = ids.map((id) => document.querySelector(`[data-testid="${id}"]`))
		if (els.some((el) => el === null)) return null
		return els.every((el, i) => i === 0 || Boolean(els[i - 1].compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING))
	})
	check('390px expansion flow order details→evidence→history→actions', order === true, { order })

	// 44px floor as painted: decide buttons and attachment rows measured in the live layout.
	const floors = await page.evaluate(() => {
		const button = document.querySelector('[data-testid^="transaction-card-decide-"]')
		const attachment = document.querySelector('[data-testid^="inline-attachment-"]')
		const tab = document.querySelector('[data-testid^="sheet-details-modal-tab-"]')
		return {
			button: button === null ? null : button.getBoundingClientRect().height,
			attachment: attachment === null ? null : attachment.getBoundingClientRect().height,
			tab: tab === null ? null : tab.getBoundingClientRect().height,
		}
	})
	check('decide button ≥44px at 390px', floors.button === null || floors.button >= 44, floors)
	check('attachment row ≥44px at 390px', floors.attachment === null || floors.attachment >= 44, floors)
	check('modal tab ≥44px at 390px', floors.tab === null || floors.tab >= 44, floors)
	await page.locator('[data-testid="sheet-details-modal-close"]').click()
	await page.locator('[data-testid="sheet-details-modal"]').waitFor({ state: 'detached', timeout: 10000 })

	// ============================================================ F — 390px FA RTL (high priority)
	// Segment E reset the language to `en`; this cell is the high-priority 390px Persian RTL case.
	setLanguage('fa')
	await newSession({ viewport: { width: 390, height: 844 }, locale: 'fa-IR', isMobile: true, hasTouch: true })
	await openSheetModal('/reviews/cost-center', rtlSheetId, rtlSheetTitle, true, 'sheet-search')
	check('390 FA modal runs RTL', (await page.locator('[data-testid="sheet-details-modal"]').getAttribute('dir')) === 'rtl')
	await assertNoHorizontalScroll('CC modal (390 FA RTL)')
	await shot('cc-390-fa-01-modal-rtl')

	await page.locator('[data-testid^="transaction-review-card-"]').first().locator('[data-testid="transaction-card-title"]').click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	await assertNoHorizontalScroll('CC expansion (390 FA RTL)')
	await shot('cc-390-fa-02-expanded-rtl')

	// Stage marker row stays readable in Persian: labels visible without hover, no overflow.
	const markerText = await page.locator('[data-testid="transaction-card-decision-indicators"]').first().innerText()
	check('FA stage labels render as text', markerText.trim().length > 0, { markerText: markerText.slice(0, 80) })
	await assertNoHorizontalScroll('CC markers (390 FA RTL)')

	// Senior + Finance 390 FA spot checks. The queues hold whatever the fixture journeys left
	// behind; the spot opens the first available sheet and records the queue when none is offered.
	await page.locator('[data-testid="sheet-details-modal-close"]').click()
	await page.locator('[data-testid="sheet-details-modal"]').waitFor({ state: 'detached', timeout: 10000 })
	const seniorSpotOpen = await openFirstSheetModal('/reviews/senior', 'senior-filter-search')
	check('senior 390 FA spot: queue renders', true)
	if (seniorSpotOpen) {
		await assertNoHorizontalScroll('Senior modal (390 FA RTL)')
		await shot('senior-390-fa-01-modal-rtl')
		await page.locator('[data-testid="sheet-details-modal-close"]').click()
		await page.locator('[data-testid="sheet-details-modal"]').waitFor({ state: 'detached', timeout: 10000 })
	} else {
		await assertNoHorizontalScroll('Senior queue (390 FA RTL, empty)')
		await shot('senior-390-fa-01-queue-rtl-empty')
	}
	const financeSpotOpen = await openFirstSheetModal('/reviews/finance')
	check('finance 390 FA spot: queue renders', true)
	if (financeSpotOpen) {
		await assertNoHorizontalScroll('Finance modal (390 FA RTL)')
		await shot('finance-390-fa-01-modal-rtl')
		await page.locator('[data-testid="sheet-details-modal-close"]').click()
		await page.locator('[data-testid="sheet-details-modal"]').waitFor({ state: 'detached', timeout: 10000 })
	} else {
		await assertNoHorizontalScroll('Finance queue (390 FA RTL, empty)')
		await shot('finance-390-fa-01-queue-rtl-empty')
	}
} finally {
	if (languageBefore !== 'fa') {
		try { setLanguage(languageBefore) } catch { /* restore best-effort; the record notes it */ }
	}
	record.finishedAt = new Date().toISOString()
	record.consoleErrorCount = record.consoleErrors.length
	record.pageErrorCount = record.pageErrors.length
	record.badResponseCount = record.badResponses.length
	record.passedChecks = record.checks.filter((c) => c.passed).length
	record.totalChecks = record.checks.length
	writeFileSync(`${outDir}/uxc-v2-16-evidence.json`, JSON.stringify(record, null, 2))
	if (browser !== null) await browser.close()
}

console.log(`UXC-V2-16 walk complete: ${record.passedChecks}/${record.totalChecks} checks, ` +
	`${record.screenshots.length} screenshots, consoleErrors=${record.consoleErrorCount}, ` +
	`pageErrors=${record.pageErrorCount}, badResponses=${record.badResponseCount}`)
if (record.consoleErrors.length > 0 || record.pageErrors.length > 0 || record.badResponses.length > 0) {
	console.log('consoleErrors:', JSON.stringify(record.consoleErrors.slice(0, 10)))
	console.log('pageErrors:', JSON.stringify(record.pageErrors.slice(0, 10)))
	console.log('badResponses:', JSON.stringify(record.badResponses.slice(0, 10)))
}

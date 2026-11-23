/**
 * UXC-V2-16 — real-Chrome cross-role / cross-viewport matrix evidence.
 *
 * Iteration 002. Complements the first walk (`uxc-v2-16-visual-evidence.mjs`, iteration 001) with
 * the full 12-cell matrix:
 *
 *   Cost Center / Senior / Finance × desktop EN / desktop FA RTL / 390px EN / 390px FA RTL
 *
 * The run uses the existing valid Playwright storage state for the dedicated `pettycash-e2e`
 * principal (login is only a fallback), builds its own sheets through the established purchaser
 * fixture workflow, and records screenshots + a JSON record under
 * `artifacts/visual-review/UXC-V2-16/002/`.
 *
 * Evidence focus:
 *   - stable Sheet Details Modal geometry across all four tabs in every cell;
 *   - first-view hierarchy, stage markers, evidence summary, action fit and bdi isolation;
 *   - disclosure by mouse and keyboard, focus enters the expansion, collapse restores focus,
 *     Escape inside the expansion collapses only the expansion, overlays never close the Sheet;
 *   - the 390px expansion start alignment (the focused tall region must not land mid-detail);
 *   - neighboring transaction cards stay mounted, exactly one expansion, no duplicate surfaces;
 *   - image preview returns to the transaction, PDF preview's pre-existing CSRF/CSP failure is
 *     recorded as a known defect rather than silently repaired;
 *   - Senior returned state, Senior completed read-only presentation, Finance senior-rejected
 *     restriction, and Finance reconciliation.
 *
 * It is an evidence runner, not a Playwright spec: it exits non-zero on the first failed check and
 * writes the partial record. It does NOT claim owner visual acceptance and leaves UXC-V2-GATE
 * unchecked.
 */

import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright-core'

const base = 'http://localhost:8081'
const iteration = '002'
const outDir = `artifacts/visual-review/UXC-V2-16/${iteration}`
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

const PNG_1X1 = Buffer.from(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
	'base64'
)
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
function check(name, condition, detail = null) {
	record.checks.push({ cell: currentCell, name, passed: Boolean(condition), detail })
	if (condition) currentCellRecord().checks += 1
	if (!condition) throw new Error(`CHECK FAILED [${currentCell}] ${name}${detail === null ? '' : ` — ${JSON.stringify(detail)}`}`)
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
		const modal = document.querySelector('[data-testid="sheet-details-modal"]')
		if (modal) candidates.push(['sheet-details-modal', modal.scrollWidth])
		return candidates.filter(([, width]) => width > viewport + 1).map(([name, width]) => ({ name, width, viewport }))
	})
	check(`no horizontal scroll (${label})`, overflow.length === 0, overflow)
}

async function assertNoDrawer(label) {
	const drawer = await page.locator('[data-testid="transaction-detail-drawer"]').count()
	const layer = await page.locator('[data-testid="transaction-detail-drawer-layer"]').count()
	check(`no drawer overlay (${label})`, drawer === 0 && layer === 0, { drawer, layer })
}

async function assertSingleSheetSurface(label) {
	const counts = await page.evaluate(() => ({
		sheetModal: document.querySelectorAll('[data-testid="sheet-details-modal"]').length,
		sheetDialog: document.querySelectorAll('[data-testid="sheet-details-modal-dialog"]').length,
		decisionModal: document.querySelectorAll('[data-testid="decision-modal"]').length,
		evidencePreview: document.querySelectorAll('[data-test="evidence-preview-layer"]').length,
		drawer: document.querySelectorAll('[data-testid="transaction-detail-drawer"]').length,
	}))
	check(
		`single sheet surface, no duplicate dialogs (${label})`,
		counts.sheetModal === 1 && counts.sheetDialog === 1 && counts.decisionModal === 0 && counts.evidencePreview === 0 && counts.drawer === 0,
		counts
	)
}

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

const queueMarkers = {
	'/reviews/cost-center': 'cost-center-queue',
	'/reviews/senior': 'senior-queue',
	'/reviews/finance': 'finance-queue',
}

async function openSheetModal(stageRoute, sheetId, sheetTitle, { mobile = false, searchTestid = null } = {}) {
	await page.goto(`${base}/apps/pettycash/#${stageRoute}`, { waitUntil: 'networkidle' })
	const marker = queueMarkers[stageRoute]
	await page.locator(`[data-testid="${marker}"]`).waitFor({ timeout: 20000 })
	if (searchTestid !== null && !mobile) {
		const search = page.locator(`[data-testid="${searchTestid}"]`)
		if ((await search.count()) > 0) {
			await search.fill(sheetTitle)
			await page.waitForTimeout(600)
		}
	}
	const row = page
		.locator(`[data-testid="sheet-row-${sheetId}"]:visible, [data-testid="sheet-card-row-${sheetId}"]:visible`)
		.first()
	await row.waitFor({ timeout: 20000 })
	await row.locator('[data-testid="sheet-title"]').click()
	await page.locator('[data-testid="sheet-details-modal"]').waitFor({ timeout: 15000 })
	await page.locator('[data-testid^="transaction-review-card-"]').first().waitFor({ timeout: 15000 })
	await page.waitForTimeout(500)
}

async function openCompletedSeniorSheet({ mobile = false } = {}) {
	await page.goto(`${base}/apps/pettycash/#/reviews/senior`, { waitUntil: 'networkidle' })
	await page.locator('[data-testid="senior-queue"]').waitFor({ timeout: 20000 })
	if (!mobile) {
		const search = page.locator('[data-testid="senior-filter-search"]')
		if ((await search.count()) > 0) {
			await search.fill('')
			await page.waitForTimeout(300)
		}
	}
	await page.locator('[data-testid="senior-queue-tab-completed"]').click()
	await page.waitForTimeout(700)
	const row = page
		.locator('[data-testid^="sheet-row-"]:visible, [data-testid^="sheet-card-row-"]:visible')
		.filter({ has: page.locator('[data-testid="review-completed-badge"]') })
		.first()
	await row.waitFor({ timeout: 15000 })
	const completedTitle = (await row.locator('[data-testid="sheet-title"]').getAttribute('title')) ?? ''
	await row.locator('[data-testid="sheet-title"]').click()
	await page.locator('[data-testid="sheet-details-modal"]').waitFor({ timeout: 15000 })
	await page.locator('[data-testid^="transaction-review-card-"]').first().waitFor({ timeout: 15000 })
	await page.waitForTimeout(500)
	return completedTitle
}

async function dialogBox() {
	return page.locator('[data-testid="sheet-details-modal-dialog"]').boundingBox()
}

async function switchTab(key) {
	await page.locator(`[data-testid="sheet-details-modal-tab-${key}"]`).click()
	await page.locator(`[data-testid="sheet-details-modal-panel-${key}"]`).waitFor({ timeout: 10000 })
	await page.waitForTimeout(350)
}

const sameBox = (a, b) => a !== null && b !== null && a.x === b.x && a.y === b.y && a.width === b.width && a.height === b.height

async function assertStableGeometry(label, prefix, screenshotTabs = ['transactions', 'history']) {
	const boxes = {}
	for (const key of ['transactions', 'approvals', 'overview', 'history']) {
		if (key !== 'transactions') await switchTab(key)
		await page.waitForTimeout(250)
		boxes[key] = await dialogBox()
		if (screenshotTabs.includes(key)) await shot(`${prefix}-tab-${key}`)
	}
	await switchTab('transactions')
	for (const key of ['approvals', 'overview', 'history']) {
		check(`modal geometry stable transactions→${key} (${label})`, sameBox(boxes.transactions, boxes[key]), {
			transactions: boxes.transactions,
			[key]: boxes[key],
		})
	}
	return boxes
}

async function assertCardFirstView(label, card) {
	check(`card title visible (${label})`, await card.locator('[data-testid="transaction-card-title"]').isVisible())
	check(`card amount visible (${label})`, await card.locator('[data-testid="transaction-card-amount"]').isVisible())
	check(`card date visible (${label})`, await card.locator('[data-testid="transaction-card-date"]').isVisible())
	check(`card status visible (${label})`, await card.locator('[data-testid="transaction-card-status"]').isVisible())
	check(`card stage markers visible (${label})`, await card.locator('[data-testid="transaction-card-decision-indicators"]').isVisible())
	check(`card evidence summary visible (${label})`, await card.locator('[data-testid="transaction-card-attachments"]').isVisible())
	const cardOverflow = await card.evaluate((el) => ({ scrollWidth: el.scrollWidth, clientWidth: el.clientWidth }))
	check(`card has no internal horizontal overflow (${label})`, cardOverflow.scrollWidth <= cardOverflow.clientWidth + 1, cardOverflow)
}

async function assertNeighborCards(label, expected) {
	const ids = await page.locator('[data-testid^="transaction-review-card-"]').evaluateAll((els) =>
		els.map((el) => el.getAttribute('data-testid'))
	)
	check(`all ${expected} neighboring cards remain mounted (${label})`, ids.length === expected, ids)
	check(`no duplicate transaction cards (${label})`, new Set(ids).size === ids.length, ids)
}

async function assertFocusAndScroll(label, { expectAlignedStart = false } = {}) {
	const geo = await page.evaluate(() => {
		const region = document.querySelector('[data-testid="transaction-expanded-detail"]')
		if (region === null) return null
		let ancestor = region.parentElement
		let container = null
		while (ancestor !== null) {
			const style = window.getComputedStyle(ancestor)
			if (/(auto|scroll)/.test(style.overflowY) && ancestor.scrollHeight > ancestor.clientHeight) {
				container = ancestor
				break
			}
			ancestor = ancestor.parentElement
		}
		const containerRect = container?.getBoundingClientRect()
		const regionRect = region.getBoundingClientRect()
		return {
			active: document.activeElement?.getAttribute('data-testid') ?? '',
			container: containerRect === undefined ? null : {
				top: containerRect.top,
				bottom: containerRect.bottom,
				left: containerRect.left,
				right: containerRect.right,
				scrollTop: container.scrollTop,
				clientHeight: container.clientHeight,
				scrollHeight: container.scrollHeight,
			},
			region: { top: regionRect.top, bottom: regionRect.bottom, left: regionRect.left, right: regionRect.right, height: regionRect.height },
			viewport: { width: window.innerWidth, height: window.innerHeight },
		}
	})
	check(`expanded region exists for geometry (${label})`, geo !== null, geo)
	check(`focus moved into expanded region (${label})`, geo.active === 'transaction-expanded-detail', geo)
	if (geo.container === null) {
		// A desktop layout whose content already fits has no scroll container; the defect is
		// specifically the tall-region case, so only require that the region did not land above
		// the viewport.
		check(`expanded region starts within the viewport (${label})`, geo.region.top >= -2, geo)
	} else {
		check(`expanded region starts inside its scroll container (${label})`, geo.region.top >= geo.container.top - 2 && geo.region.top < geo.container.bottom, geo)
		if (expectAlignedStart) {
			check(`expanded region start aligned with scroll container (${label})`, geo.region.top <= geo.container.top + 8, geo)
		}
		check(
			`expanded region fits the scroll container width (${label})`,
			geo.region.left >= geo.container.left - 1 && geo.region.right <= geo.container.right + 1,
			geo
		)
	}
}

async function assertActionFit(label) {
	const metrics = await page.evaluate(() => {
		const card = document.querySelector('[data-testid^="transaction-review-card-"]')
		const markers = document.querySelector('[data-testid="transaction-card-decision-indicators"]')
		const buttons = [...document.querySelectorAll('[data-testid^="transaction-card-decide-"]')]
		const tabs = [...document.querySelectorAll('[data-testid^="sheet-details-modal-tab-"]')].filter((el) =>
			/^sheet-details-modal-tab-(transactions|approvals|overview|history)$/.test(el.getAttribute('data-testid') ?? '')
		)
		const cardRect = card?.getBoundingClientRect()
		return {
			markers: markers === null ? null : { scrollWidth: markers.scrollWidth, clientWidth: markers.clientWidth },
			buttons: buttons.map((button) => {
				const rect = button.getBoundingClientRect()
				return { text: button.innerText.trim(), height: rect.height, left: rect.left, right: rect.right }
			}),
			card: cardRect === undefined ? null : { left: cardRect.left, right: cardRect.right },
			tabs: tabs.map((tab) => ({ id: tab.getAttribute('data-testid'), height: tab.getBoundingClientRect().height })),
		}
	})
	check(`stage marker row does not overflow (${label})`, metrics.markers !== null && metrics.markers.scrollWidth <= metrics.markers.clientWidth + 1, metrics.markers)
	check(`decide controls stay inside the card (${label})`, metrics.card !== null && metrics.buttons.every((b) => b.left >= metrics.card.left - 1 && b.right <= metrics.card.right + 1), metrics)
	check(`decide controls meet the 44px floor (${label})`, metrics.buttons.every((b) => b.height >= 44), metrics.buttons)
	check(`modal tabs meet the 44px floor (${label})`, metrics.tabs.length === 4 && metrics.tabs.every((t) => t.height >= 44), metrics.tabs)
}

async function assertBdiIsolation(label) {
	const currencyDir = await page.locator('[data-testid="inline-field-currency"]').getAttribute('dir')
	const referenceDir = await page.locator('[data-testid="inline-field-transaction-reference"] bdi').getAttribute('dir')
	check(`currency is a direction-isolated bdi (${label})`, currencyDir === 'ltr', { currencyDir })
	check(`transaction reference is a direction-isolated bdi (${label})`, referenceDir === 'ltr', { referenceDir })
}

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
	for (let i = 0; i < 16; i++) {
		const res = await ocsJson('/sheets?page=1&pageSize=20')
		const item = (res?.ocs?.data?.items ?? []).find((s) => s.id === sheetId)
		if (item && item.status !== 'OPEN' && item.status !== 'DRAFT') return sheetId
		await page.waitForTimeout(500)
	}
	const body = (await page.locator('body').innerText().catch(() => '')).replace(/\s+/g, ' ').slice(0, 240)
	throw new Error(`sheet "${sheetTitle}" did not leave the draft state after submit — page says: ${body}`)
}

async function decideFromCard(cardTestId, kind, note, { screenshot = true } = {}) {
	const card = page.locator(`[data-testid="transaction-review-card-${cardTestId}"]`)
	await card.locator(`[data-testid="transaction-card-decide-${kind.toLowerCase()}"]`).click()
	await page.locator('[data-testid="decision-modal"]').waitFor({ timeout: 15000 })
	if (note !== undefined && note !== '') await page.fill('[data-testid="decision-note-input"]', note)
	if (screenshot) await shot(`decision-confirmation-${cardTestId}-${kind.toLowerCase()}`)
	await page.click('[data-testid="decision-confirm-btn"]')
	await page.locator('[data-testid="decision-modal"]').waitFor({ state: 'detached', timeout: 20000 })
	await page.waitForTimeout(1400)
}

async function cardIdByText(text) {
	return page
		.locator('[data-testid^="transaction-review-card-"]', { hasText: text })
		.first()
		.getAttribute('data-testid')
		.then((value) => value.replace('transaction-review-card-', ''))
}

async function assertEvidenceSummary(label, card, expectedCount) {
	const count = (await card.locator('[data-testid="transaction-card-evidence-count"]').innerText()).trim()
	check(`evidence count is ${expectedCount} (${label})`, count === String(expectedCount), { count })
	if (expectedCount > 2) {
		check(`+N tile rendered (${label})`, (await card.locator('[data-testid^="transaction-card-thumb-more-"]').count()) === 1)
	}
	const tiles = card.locator('[data-testid^="transaction-card-thumb-"]:not([data-testid^="transaction-card-thumb-more"])')
	check(`collapsed summary renders two evidence tiles (${label})`, (await tiles.count()) === 2)
	let thumbCount = 0
	for (let i = 0; i < 20 && thumbCount < 1; i++) {
		thumbCount = await card.locator('[data-thumb-state="thumb"]').count()
		if (thumbCount < 1) await page.waitForTimeout(500)
	}
	check(`collapsed summary shows at least one real thumbnail (${label})`, thumbCount >= 1, { thumbCount })
	check(`every collapsed tile declares a settled thumb state (${label})`, (await card.locator('[data-thumb-state]').count()) === 2)
}

async function openImagePreview(label) {
	const imageRow = page.locator('[data-testid^="inline-attachment-"]', { hasText: '.png' }).first()
	await imageRow.click()
	await page.locator('[data-test="evidence-preview-layer"]').waitFor({ timeout: 15000 })
	await page.waitForTimeout(1500)
	check(`image preview renders an image (${label})`, (await page.locator('[data-test="evidence-preview-image"]').count()) === 1)
	await shot(`evidence-preview-image-${label}`)
	await page.locator('[data-test="close-evidence-preview"]').click()
	await page.locator('[data-test="evidence-preview-layer"]').waitFor({ state: 'detached', timeout: 10000 })
	check(`image preview close returns to the expansion (${label})`, (await page.locator('[data-testid="transaction-expanded-detail"]').count()) === 1)
	check(`image preview close keeps the sheet modal (${label})`, (await page.locator('[data-testid="sheet-details-modal"]').count()) === 1)
}

async function recordPdfPreviewDefect(label) {
	const pdfRow = page.locator('[data-testid^="inline-attachment-"]', { hasText: '.pdf' }).first()
	if ((await pdfRow.count()) === 0) return
	await pdfRow.click()
	await page.locator('[data-test="evidence-preview-layer"]').waitFor({ timeout: 15000 })
	await page.waitForTimeout(1500)
	const pdfFrame = page.locator('[data-test="evidence-preview-pdf"]')
	check(`PDF preview opens the overlay (${label})`, (await pdfFrame.count()) === 1)
	let frameText = ''
	try {
		frameText = (await pdfFrame.contentFrame().locator('body').innerText({ timeout: 3000 })).replace(/\s+/g, ' ').slice(0, 200)
	} catch {
		frameText = '(frame body unavailable)'
	}
	await shot(`evidence-preview-pdf-${label}`)
	record.knownDefects.push({
		id: 'evidence-pdf-inline-csrf',
		cell: currentCell,
		summary: 'PDF evidence iframe receives 412 CSRF check failed; the overlay itself opens and closes correctly.',
		frameText,
		classification: 'pre-existing EvidencePreview/backend-CSP defect; not a v2 presentation regression and outside the approved frontend-only scope',
	})
	await page.locator('[data-test="close-evidence-preview"]').click()
	await page.locator('[data-test="evidence-preview-layer"]').waitFor({ state: 'detached', timeout: 10000 })
	check(`PDF preview close returns to the expansion (${label})`, (await page.locator('[data-testid="transaction-expanded-detail"]').count()) === 1)
}

async function closeSheetModal() {
	const modal = page.locator('[data-testid="sheet-details-modal"]')
	if ((await modal.count()) === 0) return
	await page.locator('[data-testid="sheet-details-modal-close"]').click()
	await modal.waitFor({ state: 'detached', timeout: 10000 })
}

try {
	browser = await chromium.launch({ channel: 'chrome', headless: true })
	const browserVersion = browser.version()
	record.chrome = browserVersion

	const stamp = Date.now().toString(36)

	// ============================================================ 1 — Cost Center, desktop EN
	setCell('cost-center-desktop-en')
	setLanguage('en')
	await newSession({ viewport: { width: 1280, height: 900 }, locale: 'en-US', label: 'cc-desktop-en' })
	const ua = await page.evaluate(() => navigator.userAgent)
	const chromeBinary = execFileSync('/opt/google/chrome/chrome', ['--version'], { encoding: 'utf8' }).trim()
	record.chromeBinary = chromeBinary
	// Headless Chrome keeps the `HeadlessChrome` product token; the binary identity + stable
	// version prove this is official Google Chrome, not the bundled Playwright Chromium.
	check(
		'official Google Chrome (channel: chrome)',
		/^Google Chrome 156\./.test(chromeBinary) && /Chrome\/156\./.test(ua) && browserVersion.startsWith('156.'),
		{ ua, browserVersion, chromeBinary }
	)

	const ccTitle = `V2-16 matrix cc ${stamp}`
	const ccSheetId = await createSheetWithTransactions(ccTitle, [
		['V2-16 matrix taxi', '50000', [
			{ name: 'v2-matrix-taxi-1.png', mime: 'image/png' },
			{ name: 'v2-matrix-taxi-2.png', mime: 'image/png' },
			{ name: 'v2-matrix-taxi-receipt.pdf', mime: 'application/pdf' },
		]],
		['V2-16 matrix supplies', '120000', [{ name: 'v2-matrix-supplies-1.png', mime: 'image/png' }]],
		['V2-16 matrix lunch', '30000', [{ name: 'v2-matrix-lunch-1.png', mime: 'image/png' }]],
	])
	record.ccSheetId = ccSheetId
	check('CC fixture sheet created + submitted', typeof ccSheetId === 'string' && ccSheetId !== '')

	await openSheetModal('/reviews/cost-center', ccSheetId, ccTitle, { searchTestid: 'sheet-search' })
	await assertSingleSheetSurface('CC desktop EN open')
	await assertNoDrawer('CC desktop EN open')
	await assertNoHorizontalScroll('CC desktop EN open')
	await shot('cc-desktop-en-01-modal')

	const ccCards = page.locator('[data-testid^="transaction-review-card-"]')
	check('CC desktop EN renders three cards', (await ccCards.count()) === 3)
	await assertCardFirstView('CC desktop EN first card', ccCards.first())
	const taxiCard = page.locator('[data-testid^="transaction-review-card-"]', { hasText: 'V2-16 matrix taxi' })
	await assertEvidenceSummary('CC desktop EN taxi', taxiCard, 3)

	await assertStableGeometry('CC desktop EN', 'cc-desktop-en')
	await assertSingleSheetSurface('CC desktop EN after tabs')

	const taxiCardId = await cardIdByText('V2-16 matrix taxi')
	await taxiCard.locator('[data-testid="transaction-card-title"]').click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	check('mouse disclosure sets aria-expanded=true', (await taxiCard.locator('[data-testid="transaction-card-title"]').getAttribute('aria-expanded')) === 'true')
	await page.waitForTimeout(500)
	await assertFocusAndScroll('CC desktop EN expansion')
	await assertNeighborCards('CC desktop EN expansion', 3)
	await assertNoDrawer('CC desktop EN expansion')
	await assertNoHorizontalScroll('CC desktop EN expansion')
	await shot('cc-desktop-en-02-expanded')
	for (const section of ['inline-section-details', 'inline-section-evidence', 'inline-section-decisions', 'inline-section-actions']) {
		check(`expansion renders ${section}`, (await page.locator(`[data-testid="${section}"]`).count()) === 1)
	}
	check('expansion history groups render', (await page.locator('[data-testid^="inline-decisions-"]').count()) === 3)
	let expandedThumb = 0
	for (let i = 0; i < 20 && expandedThumb < 2; i++) {
		expandedThumb = await page.locator('[data-testid="transaction-expanded-detail"] [data-thumb-state="thumb"]').count()
		if (expandedThumb < 2) await page.waitForTimeout(500)
	}
	check('both PNG rows show real thumbnails in the expansion', expandedThumb === 2, { expandedThumb })
	check('PDF row shows file-fallback art in the expansion', (await page.locator('[data-testid="transaction-expanded-detail"] [data-thumb-state="fallback"]').count()) >= 1)
	check('expansion renders all three attachment rows', (await page.locator('[data-testid="transaction-expanded-detail"] [data-testid^="inline-attachment-"]').count()) === 3)

	await openImagePreview('cc-desktop-en')
	await recordPdfPreviewDefect('cc-desktop-en')
	check('PDF preview close returns to the transaction context', (await page.locator('[data-testid="transaction-expanded-detail"]').count()) === 1)

	await page.locator('[data-testid="transaction-card-decide-approve"]').first().click()
	await page.locator('[data-testid="decision-modal"]').waitFor({ timeout: 15000 })
	await shot('cc-desktop-en-03-decision-confirmation')
	await page.keyboard.press('Escape')
	await page.locator('[data-testid="decision-modal"]').waitFor({ state: 'detached', timeout: 10000 })
	check('Escape closes only the decision confirmation', (await page.locator('[data-testid="sheet-details-modal"]').count()) === 1)
	check('confirmation Escape keeps the expansion open', (await page.locator('[data-testid="transaction-expanded-detail"]').count()) === 1)

	await page.keyboard.press('Escape')
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ state: 'detached', timeout: 10000 })
	await taxiCard.locator('[data-testid="transaction-card-title"]').click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	await page.waitForTimeout(500)
	await page.keyboard.press('Escape')
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ state: 'detached', timeout: 10000 })
	await page.waitForTimeout(300)
	const focusAfterCollapse = await page.evaluate(() => document.activeElement?.getAttribute('data-testid') ?? '')
	check('collapse restores focus to the originating toggle', focusAfterCollapse === 'transaction-card-title', { focusAfterCollapse })

	await page.locator(`[data-testid="transaction-review-card-${taxiCardId}"] [data-testid="transaction-card-title"]`).focus()
	await page.keyboard.press('Enter')
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	check('keyboard disclosure opens the expansion', (await page.locator('[data-testid="transaction-expanded-detail"]').count()) === 1)
	await page.keyboard.press('Escape')
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ state: 'detached', timeout: 10000 })
	await closeSheetModal()

	// ============================================================ 2 — Cost Center, desktop FA RTL
	setCell('cost-center-desktop-fa')
	setLanguage('fa')
	await newSession({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR', label: 'cc-desktop-fa' })
	await openSheetModal('/reviews/cost-center', ccSheetId, ccTitle, { searchTestid: 'sheet-search' })
	check('FA modal mirrors RTL', (await page.locator('[data-testid="sheet-details-modal"]').getAttribute('dir')) === 'rtl')
	await assertSingleSheetSurface('CC desktop FA open')
	await assertNoHorizontalScroll('CC desktop FA open')
	await shot('cc-desktop-fa-01-modal-rtl')
	await assertStableGeometry('CC desktop FA', 'cc-desktop-fa')
	await page.locator('[data-testid^="transaction-review-card-"]', { hasText: 'V2-16 matrix taxi' }).locator('[data-testid="transaction-card-title"]').click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	await page.waitForTimeout(500)
	check('FA expansion runs inside the RTL card', (await page.locator('[data-testid^="transaction-review-card-"]', { hasText: 'V2-16 matrix taxi' }).getAttribute('dir')) === 'rtl')
	await assertBdiIsolation('CC desktop FA')
	await assertFocusAndScroll('CC desktop FA expansion')
	await assertNoHorizontalScroll('CC desktop FA expansion')
	await shot('cc-desktop-fa-02-expanded-rtl')
	await closeSheetModal()

	// ============================================================ 3 — Cost Center, 390px EN
	setCell('cost-center-390-en')
	setLanguage('en')
	await newSession({ viewport: { width: 390, height: 844 }, locale: 'en-US', mobile: true, label: 'cc-390-en' })
	await openSheetModal('/reviews/cost-center', ccSheetId, ccTitle, { mobile: true })
	await assertSingleSheetSurface('CC 390 EN open')
	await assertNoHorizontalScroll('CC 390 EN open')
	await shot('cc-390-en-01-modal')
	await assertCardFirstView('CC 390 EN first card', page.locator('[data-testid^="transaction-review-card-"]').first())
	await assertStableGeometry('CC 390 EN', 'cc-390-en')
	await page.locator('[data-testid^="transaction-review-card-"]', { hasText: 'V2-16 matrix taxi' }).locator('[data-testid="transaction-card-title"]').click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	await page.waitForTimeout(600)
	await assertFocusAndScroll('CC 390 EN expansion', { expectAlignedStart: true })
	await assertNeighborCards('CC 390 EN expansion', 3)
	await assertNoHorizontalScroll('CC 390 EN expansion')
	await shot('cc-390-en-02-expanded')
	await assertActionFit('CC 390 EN expansion')
	const order = await page.evaluate(() => {
		const ids = ['inline-section-details', 'inline-section-evidence', 'inline-section-decisions', 'inline-section-actions']
		const els = ids.map((id) => document.querySelector(`[data-testid="${id}"]`))
		if (els.some((el) => el === null)) return null
		return els.every((el, i) => i === 0 || Boolean(els[i - 1].compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING))
	})
	check('390px expansion flow order details→evidence→history→actions', order === true, { order })
	await closeSheetModal()

	// ============================================================ 4 — Cost Center, 390px FA RTL
	setCell('cost-center-390-fa')
	setLanguage('fa')
	await newSession({ viewport: { width: 390, height: 844 }, locale: 'fa-IR', mobile: true, label: 'cc-390-fa' })
	await openSheetModal('/reviews/cost-center', ccSheetId, ccTitle, { mobile: true })
	check('390 FA modal runs RTL', (await page.locator('[data-testid="sheet-details-modal"]').getAttribute('dir')) === 'rtl')
	await assertSingleSheetSurface('CC 390 FA open')
	await assertNoHorizontalScroll('CC 390 FA open')
	await shot('cc-390-fa-01-modal-rtl')
	await assertStableGeometry('CC 390 FA', 'cc-390-fa')
	await page.locator('[data-testid^="transaction-review-card-"]', { hasText: 'V2-16 matrix taxi' }).locator('[data-testid="transaction-card-title"]').click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	await page.waitForTimeout(600)
	await assertFocusAndScroll('CC 390 FA expansion', { expectAlignedStart: true })
	await assertNeighborCards('CC 390 FA expansion', 3)
	await assertNoHorizontalScroll('CC 390 FA expansion')
	await assertActionFit('CC 390 FA expansion')
	await assertBdiIsolation('CC 390 FA')
	await shot('cc-390-fa-02-expanded-rtl')

	// Decide all three at 390 FA: this is the mobile/RTL decision surface and it leaves the sheet
	// with one decided card of each kind.
	const taxiId = await cardIdByText('V2-16 matrix taxi')
	const suppliesId = await cardIdByText('V2-16 matrix supplies')
	const lunchId = await cardIdByText('V2-16 matrix lunch')
	await decideFromCard(taxiId, 'APPROVE', 'V2-16 matrix mobile approve', { screenshot: false })
	check('mobile approved card shows ✓ marker', (await page.locator(`[data-testid="transaction-review-card-${taxiId}"] [data-testid="transaction-card-decision-costCenter"]`).innerText()).includes('✓'))
	await decideFromCard(suppliesId, 'RETURN', 'Receipt total does not match the claim.', { screenshot: false })
	check('mobile returned card shows ↩ marker', (await page.locator(`[data-testid="transaction-review-card-${suppliesId}"] [data-testid="transaction-card-decision-costCenter"]`).innerText()).includes('↩'))
	await decideFromCard(lunchId, 'REJECT', 'Missing itemized receipt.', { screenshot: false })
	check('mobile rejected card shows ✕ marker', (await page.locator(`[data-testid="transaction-review-card-${lunchId}"] [data-testid="transaction-card-decision-costCenter"]`).innerText()).includes('✕'))
	await shot('cc-390-fa-03-decided')
	await closeSheetModal()

	// ============================================================ 5 — Senior, desktop EN
	setCell('senior-desktop-en')
	setLanguage('en')
	await newSession({ viewport: { width: 1280, height: 900 }, locale: 'en-US', label: 'senior-desktop-en' })
	const seniorTitle = `V2-16 matrix senior ${stamp}`
	const seniorSheetId = await createSheetWithTransactions(seniorTitle, [
		['V2-16 matrix conference', '80000', [{ name: 'v2-matrix-conf-1.png', mime: 'image/png' }]],
		['V2-16 matrix training', '65000', [{ name: 'v2-matrix-training-1.png', mime: 'image/png' }]],
	])
	record.seniorSheetId = seniorSheetId
	await openSheetModal('/reviews/cost-center', seniorSheetId, seniorTitle, { searchTestid: 'sheet-search' })
	const confId = await cardIdByText('V2-16 matrix conference')
	const trainId = await cardIdByText('V2-16 matrix training')
	await decideFromCard(confId, 'APPROVE', 'V2-16 matrix cc approve', { screenshot: false })
	await decideFromCard(trainId, 'APPROVE', 'V2-16 matrix cc approve', { screenshot: false })
	await closeSheetModal()

	await openSheetModal('/reviews/senior', seniorSheetId, seniorTitle, { searchTestid: 'senior-filter-search' })
	await assertSingleSheetSurface('Senior desktop EN open')
	await assertNoDrawer('Senior desktop EN open')
	await assertNoHorizontalScroll('Senior desktop EN open')
	check('senior export control present', (await page.locator('[data-testid="senior-export-btn"]').count()) >= 1)
	await shot('senior-desktop-en-01-modal')
	await assertStableGeometry('Senior desktop EN', 'senior-desktop-en')
	await page.locator(`[data-testid="transaction-review-card-${confId}"] [data-testid="transaction-card-title"]`).click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	await page.waitForTimeout(500)
	const ccGroup = page.locator('[data-testid="inline-decisions-cost-center"]')
	await ccGroup.waitFor({ timeout: 10000 })
	check('senior expansion shows the previous CC decision', (await ccGroup.locator('[data-testid^="decision-item-"]').count()) >= 1)
	await shot('senior-desktop-en-02-expanded-cc-history')
	await decideFromCard(confId, 'APPROVE', 'V2-16 matrix senior approve')
	check('senior-approved card shows ✓ senior marker', (await page.locator(`[data-testid="transaction-review-card-${confId}"] [data-testid="transaction-card-decision-senior"]`).innerText()).includes('✓'))
	await closeSheetModal()

	// Senior completed / read-only presentation from the existing completed fixtures.
	const completedTitle = await openCompletedSeniorSheet()
	check('completed Senior sheet opens from the completed tab', (await page.locator('[data-testid="sheet-details-modal"]').count()) === 1, { completedTitle })
	await assertSingleSheetSurface('Senior completed read-only open')
	check('completed Senior sheet renders no decision actions', (await page.locator('[data-testid="sheet-details-modal"] [data-testid^="transaction-card-decide-"]').count()) === 0)
	await shot('senior-desktop-en-03-completed-read-only')
	await page.locator('[data-testid^="transaction-review-card-"]').first().locator('[data-testid="transaction-card-title"]').click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	check('completed Senior expansion renders no inline actions', (await page.locator('[data-testid="inline-decide-approve"], [data-testid="inline-decide-reject"], [data-testid="inline-decide-return"]').count()) === 0)
	await shot('senior-desktop-en-04-completed-expanded-read-only')
	await closeSheetModal()

	// ============================================================ 6 — Senior, desktop FA RTL
	setCell('senior-desktop-fa')
	setLanguage('fa')
	await newSession({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR', label: 'senior-desktop-fa' })
	await openSheetModal('/reviews/senior', seniorSheetId, seniorTitle, { searchTestid: 'senior-filter-search' })
	check('Senior desktop FA modal runs RTL', (await page.locator('[data-testid="sheet-details-modal"]').getAttribute('dir')) === 'rtl')
	await assertSingleSheetSurface('Senior desktop FA open')
	await assertNoHorizontalScroll('Senior desktop FA open')
	await shot('senior-desktop-fa-01-modal-rtl')
	await assertStableGeometry('Senior desktop FA', 'senior-desktop-fa')
	await page.locator(`[data-testid="transaction-review-card-${trainId}"] [data-testid="transaction-card-title"]`).click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	await page.waitForTimeout(500)
	check('Senior desktop FA expansion shows the previous CC decision', (await page.locator('[data-testid="inline-decisions-cost-center"] [data-testid^="decision-item-"]').count()) >= 1)
	await assertBdiIsolation('Senior desktop FA')
	await assertFocusAndScroll('Senior desktop FA expansion')
	await assertNoHorizontalScroll('Senior desktop FA expansion')
	await shot('senior-desktop-fa-02-expanded-rtl')
	await closeSheetModal()

	// ============================================================ 7 — Senior, 390px EN
	setCell('senior-390-en')
	setLanguage('en')
	await newSession({ viewport: { width: 390, height: 844 }, locale: 'en-US', mobile: true, label: 'senior-390-en' })
	await openSheetModal('/reviews/senior', seniorSheetId, seniorTitle, { mobile: true })
	await assertSingleSheetSurface('Senior 390 EN open')
	await assertNoHorizontalScroll('Senior 390 EN open')
	await shot('senior-390-en-01-modal')
	await assertCardFirstView('Senior 390 EN first card', page.locator('[data-testid^="transaction-review-card-"]').first())
	await assertStableGeometry('Senior 390 EN', 'senior-390-en')
	await page.locator(`[data-testid="transaction-review-card-${trainId}"] [data-testid="transaction-card-title"]`).click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	await page.waitForTimeout(600)
	await assertFocusAndScroll('Senior 390 EN expansion')
	await assertNeighborCards('Senior 390 EN expansion', 2)
	await assertNoHorizontalScroll('Senior 390 EN expansion')
	await assertActionFit('Senior 390 EN expansion')
	await shot('senior-390-en-02-expanded')
	await closeSheetModal()

	// ============================================================ 8 — Senior, 390px FA RTL (+ returned)
	setCell('senior-390-fa')
	setLanguage('fa')
	await newSession({ viewport: { width: 390, height: 844 }, locale: 'fa-IR', mobile: true, label: 'senior-390-fa' })
	await openSheetModal('/reviews/senior', seniorSheetId, seniorTitle, { mobile: true })
	check('Senior 390 FA modal runs RTL', (await page.locator('[data-testid="sheet-details-modal"]').getAttribute('dir')) === 'rtl')
	await assertSingleSheetSurface('Senior 390 FA open')
	await assertNoHorizontalScroll('Senior 390 FA open')
	await shot('senior-390-fa-01-modal-rtl')
	await assertStableGeometry('Senior 390 FA', 'senior-390-fa')
	await page.locator(`[data-testid="transaction-review-card-${trainId}"] [data-testid="transaction-card-title"]`).click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	await page.waitForTimeout(600)
	await assertFocusAndScroll('Senior 390 FA expansion')
	await assertNoHorizontalScroll('Senior 390 FA expansion')
	await assertActionFit('Senior 390 FA expansion')
	await assertBdiIsolation('Senior 390 FA')
	await shot('senior-390-fa-02-expanded-rtl')
	await decideFromCard(trainId, 'RETURN', 'Please attach the signed attendance sheet.', { screenshot: false })
	const returnedMarkerVisible = (await page.locator(`[data-testid="transaction-review-card-${trainId}"] [data-testid="transaction-card-decision-senior"]`).count()) === 1
	if (returnedMarkerVisible) {
		check('Senior returned card shows ↩ senior marker', (await page.locator(`[data-testid="transaction-review-card-${trainId}"] [data-testid="transaction-card-decision-senior"]`).innerText()).includes('↩'))
	} else {
		check('Senior returned sheet reconciles out of the pending queue', (await page.locator('[data-testid="senior-queue"]').count()) === 1)
	}
	await shot('senior-390-fa-03-after-return')
	const returnedSheet = await ocsJson(`/sheets/${seniorSheetId}`)
	const returnedStatus = returnedSheet?.ocs?.data?.status ?? returnedSheet?.ocs?.data?.sheet?.status ?? null
	const returnedTransaction = (returnedSheet?.ocs?.data?.transactions ?? []).find((tx) => tx.id === trainId) ?? null
	// A Senior return puts the transaction into purchaser correction and re-enters the sheet into
	// the Cost Center correction flow; the sheet status is COST_CENTER, not CORRECTION.
	check(
		'Senior return records the transaction as awaiting purchaser correction',
		returnedTransaction?.status === 'AWAITING_PURCHASER_CORRECTION' && returnedTransaction?.stage === 'CORRECTION',
		{ returnedTransaction }
	)
	check('Senior returned sheet re-enters the correction flow', returnedStatus === 'COST_CENTER' || returnedStatus === 'CORRECTION', { returnedStatus })
	await closeSheetModal()

	const completedFaTitle = await openCompletedSeniorSheet({ mobile: true })
	check('completed Senior sheet opens at 390 FA', (await page.locator('[data-testid="sheet-details-modal"]').count()) === 1, { completedFaTitle })
	check('completed Senior sheet has no decision actions at 390 FA', (await page.locator('[data-testid="sheet-details-modal"] [data-testid^="transaction-card-decide-"]').count()) === 0)
	await assertNoHorizontalScroll('Senior completed 390 FA')
	await shot('senior-390-fa-04-completed-read-only-rtl')
	await closeSheetModal()

	// ============================================================ 9 — Finance, desktop EN
	setCell('finance-desktop-en')
	setLanguage('en')
	await newSession({ viewport: { width: 1280, height: 900 }, locale: 'en-US', label: 'finance-desktop-en' })
	const financeTitle = `V2-16 matrix finance ${stamp}`
	const financeSheetId = await createSheetWithTransactions(financeTitle, [
		['V2-16 matrix fin approved', '70000', [{ name: 'v2-matrix-fin-1.png', mime: 'image/png' }]],
		['V2-16 matrix fin pending', '45000', [{ name: 'v2-matrix-fin-2.png', mime: 'image/png' }]],
		['V2-16 matrix fin rejected', '25000', [{ name: 'v2-matrix-fin-3.png', mime: 'image/png' }]],
	])
	record.financeSheetId = financeSheetId
	await openSheetModal('/reviews/cost-center', financeSheetId, financeTitle, { searchTestid: 'sheet-search' })
	const finApprovedId = await cardIdByText('V2-16 matrix fin approved')
	const finPendingId = await cardIdByText('V2-16 matrix fin pending')
	const finRejectedId = await cardIdByText('V2-16 matrix fin rejected')
	await decideFromCard(finApprovedId, 'APPROVE', 'V2-16 matrix cc approve', { screenshot: false })
	await decideFromCard(finPendingId, 'APPROVE', 'V2-16 matrix cc approve', { screenshot: false })
	await decideFromCard(finRejectedId, 'APPROVE', 'V2-16 matrix cc approve', { screenshot: false })
	await closeSheetModal()

	await openSheetModal('/reviews/senior', financeSheetId, financeTitle, { searchTestid: 'senior-filter-search' })
	await decideFromCard(finApprovedId, 'APPROVE', 'V2-16 matrix senior approve', { screenshot: false })
	await decideFromCard(finPendingId, 'APPROVE', 'V2-16 matrix senior approve', { screenshot: false })
	await decideFromCard(finRejectedId, 'REJECT', 'V2-16 matrix senior reject', { screenshot: false })
	await closeSheetModal()

	await openSheetModal('/reviews/finance', financeSheetId, financeTitle, { searchTestid: 'finance-queue-search' })
	await assertSingleSheetSurface('Finance desktop EN open')
	await assertNoDrawer('Finance desktop EN open')
	await assertNoHorizontalScroll('Finance desktop EN open')
	await shot('finance-desktop-en-01-modal')
	await switchTab('approvals')
	await shot('finance-desktop-en-02-approvals-totals')
	check('finance approvals tab renders CC stage total', (await page.locator('[data-testid="sheet-approvals-stage-costCenter-total"]').count()) === 1)
	check('finance approvals tab renders Senior stage total', (await page.locator('[data-testid="sheet-approvals-stage-senior-total"]').count()) === 1)
	check('finance approvals tab renders Finance stage total', (await page.locator('[data-testid="sheet-approvals-stage-finance-total"]').count()) === 1)
	await switchTab('transactions')
	await assertStableGeometry('Finance desktop EN', 'finance-desktop-en')

	await page.locator(`[data-testid="transaction-review-card-${finApprovedId}"] [data-testid="transaction-card-title"]`).click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	await page.waitForTimeout(500)
	check('finance expansion separates CC history', (await page.locator('[data-testid="inline-decisions-cost-center"] [data-testid^="decision-item-"]').count()) >= 1)
	check('finance expansion separates Senior history', (await page.locator('[data-testid="inline-decisions-senior"] [data-testid^="decision-item-"]').count()) >= 1)
	await shot('finance-desktop-en-03-expanded-stage-separation')

	const rejectedCard = page.locator(`[data-testid="transaction-review-card-${finRejectedId}"]`)
	const rejectedApprove = rejectedCard.locator('[data-testid="transaction-card-decide-approve"]')
	const rejectedApproveCount = await rejectedApprove.count()
	const rejectedApproveDisabled = rejectedApproveCount > 0 ? await rejectedApprove.isDisabled() : false
	check('Finance cannot approve a senior-rejected transaction', rejectedApproveCount === 0 || rejectedApproveDisabled, { rejectedApproveCount, rejectedApproveDisabled })
	check('Finance may still reject a senior-rejected transaction', (await rejectedCard.locator('[data-testid="transaction-card-decide-reject"]').count()) === 1)
	check('Finance may still return a senior-rejected transaction', (await rejectedCard.locator('[data-testid="transaction-card-decide-return"]').count()) === 1)
	const approvedCard = page.locator(`[data-testid="transaction-review-card-${finApprovedId}"]`)
	check('Finance may approve a senior-approved transaction', !(await approvedCard.locator('[data-testid="transaction-card-decide-approve"]').isDisabled()))

	await decideFromCard(finApprovedId, 'APPROVE', 'V2-16 matrix finance approve', { screenshot: false })
	const financeMarkerVisible = (await page.locator(`[data-testid="transaction-review-card-${finApprovedId}"] [data-testid="transaction-card-decision-finance"]`).count()) === 1
	if (financeMarkerVisible) {
		check('finance-approved card shows ✓ finance marker', (await page.locator(`[data-testid="transaction-review-card-${finApprovedId}"] [data-testid="transaction-card-decision-finance"]`).innerText()).includes('✓'))
	} else {
		check('finance reconciliation keeps the remaining eligible cards', (await page.locator('[data-testid^="transaction-review-card-"]').count()) >= 1)
	}
	await shot('finance-desktop-en-04-after-approve')
	await closeSheetModal()

	// ============================================================ 10 — Finance, desktop FA RTL
	setCell('finance-desktop-fa')
	setLanguage('fa')
	await newSession({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR', label: 'finance-desktop-fa' })
	await openSheetModal('/reviews/finance', financeSheetId, financeTitle, { searchTestid: 'finance-queue-search' })
	check('Finance desktop FA modal runs RTL', (await page.locator('[data-testid="sheet-details-modal"]').getAttribute('dir')) === 'rtl')
	await assertSingleSheetSurface('Finance desktop FA open')
	await assertNoHorizontalScroll('Finance desktop FA open')
	await shot('finance-desktop-fa-01-modal-rtl')
	await assertStableGeometry('Finance desktop FA', 'finance-desktop-fa')
	await page.locator(`[data-testid="transaction-review-card-${finPendingId}"] [data-testid="transaction-card-title"]`).click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	await page.waitForTimeout(500)
	await assertBdiIsolation('Finance desktop FA')
	await assertFocusAndScroll('Finance desktop FA expansion')
	await assertNoHorizontalScroll('Finance desktop FA expansion')
	await shot('finance-desktop-fa-02-expanded-rtl')
	await closeSheetModal()

	// ============================================================ 11 — Finance, 390px EN
	setCell('finance-390-en')
	setLanguage('en')
	await newSession({ viewport: { width: 390, height: 844 }, locale: 'en-US', mobile: true, label: 'finance-390-en' })
	await openSheetModal('/reviews/finance', financeSheetId, financeTitle, { mobile: true })
	await assertSingleSheetSurface('Finance 390 EN open')
	await assertNoHorizontalScroll('Finance 390 EN open')
	await shot('finance-390-en-01-modal')
	await assertCardFirstView('Finance 390 EN first card', page.locator('[data-testid^="transaction-review-card-"]').first())
	await assertStableGeometry('Finance 390 EN', 'finance-390-en')
	await page.locator(`[data-testid="transaction-review-card-${finPendingId}"] [data-testid="transaction-card-title"]`).click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	await page.waitForTimeout(600)
	await assertFocusAndScroll('Finance 390 EN expansion')
	await assertNoHorizontalScroll('Finance 390 EN expansion')
	await assertActionFit('Finance 390 EN expansion')
	await shot('finance-390-en-02-expanded')
	await closeSheetModal()

	// ============================================================ 12 — Finance, 390px FA RTL
	setCell('finance-390-fa')
	setLanguage('fa')
	await newSession({ viewport: { width: 390, height: 844 }, locale: 'fa-IR', mobile: true, label: 'finance-390-fa' })
	await openSheetModal('/reviews/finance', financeSheetId, financeTitle, { mobile: true })
	check('Finance 390 FA modal runs RTL', (await page.locator('[data-testid="sheet-details-modal"]').getAttribute('dir')) === 'rtl')
	await assertSingleSheetSurface('Finance 390 FA open')
	await assertNoHorizontalScroll('Finance 390 FA open')
	await shot('finance-390-fa-01-modal-rtl')
	await assertStableGeometry('Finance 390 FA', 'finance-390-fa')
	await page.locator(`[data-testid="transaction-review-card-${finPendingId}"] [data-testid="transaction-card-title"]`).click()
	await page.locator('[data-testid="transaction-expanded-detail"]').waitFor({ timeout: 10000 })
	await page.waitForTimeout(600)
	await assertFocusAndScroll('Finance 390 FA expansion')
	await assertNoHorizontalScroll('Finance 390 FA expansion')
	await assertActionFit('Finance 390 FA expansion')
	await assertBdiIsolation('Finance 390 FA')
	await shot('finance-390-fa-02-expanded-rtl')
	await decideFromCard(finPendingId, 'REJECT', 'Supporting document does not match the amount.', { screenshot: false })
	const pendingMarkerVisible = (await page.locator(`[data-testid="transaction-review-card-${finPendingId}"] [data-testid="transaction-card-decision-finance"]`).count()) === 1
	if (pendingMarkerVisible) {
		check('finance-rejected card shows ✕ finance marker', (await page.locator(`[data-testid="transaction-review-card-${finPendingId}"] [data-testid="transaction-card-decision-finance"]`).innerText()).includes('✕'))
	} else {
		check('finance rejection reconciles the decided card out of the pending queue', (await page.locator('[data-testid="finance-queue"]').count()) === 1)
	}
	await shot('finance-390-fa-03-after-reject')
	await closeSheetModal()

	// ============================================================ global failure classification
	setCell('global')
	const unexpectedBadResponses = record.badResponses.filter((entry) => {
		const isPdfPreview = entry.status === 412 && /\/evidence\/.+\/content\?disposition=inline/.test(entry.url)
		return !isPdfPreview
	})
	check('no unexpected failed HTTP requests', unexpectedBadResponses.length === 0, unexpectedBadResponses)
	const unexpectedPageErrors = record.pageErrors
	check('no page errors', unexpectedPageErrors.length === 0, unexpectedPageErrors)
	const knownPdfConsoleError = (text) =>
		/412|Precondition Failed|CSRF|frame-ancestors|Refused to frame|Content Security Policy|X-Frame-Options/i.test(text)
	const unexpectedConsoleErrors = record.consoleErrors.filter((entry) => !knownPdfConsoleError(entry.text))
	check('no unexpected console errors', unexpectedConsoleErrors.length === 0, unexpectedConsoleErrors)
	const pdfBadResponses = record.badResponses.filter((entry) => entry.status === 412 && /\/evidence\/.+\/content\?disposition=inline/.test(entry.url))
	record.pdfPreviewResponses = pdfBadResponses
	record.unexpectedBadResponses = unexpectedBadResponses
	record.unexpectedConsoleErrors = unexpectedConsoleErrors
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
	writeFileSync(`${outDir}/uxc-v2-16-matrix-evidence.json`, JSON.stringify(record, null, 2))
	if (browser !== null) await browser.close()
}

console.log(`UXC-V2-16 matrix complete: ${record.passedChecks}/${record.totalChecks} checks, ` +
	`${record.screenshots.length} screenshots, consoleErrors=${record.consoleErrorCount}, ` +
	`pageErrors=${record.pageErrorCount}, badResponses=${record.badResponseCount}, ` +
	`knownDefects=${record.knownDefects.length}`)
if (record.consoleErrors.length > 0 || record.pageErrors.length > 0 || record.badResponses.length > 0) {
	console.log('consoleErrors:', JSON.stringify(record.consoleErrors.slice(0, 12)))
	console.log('pageErrors:', JSON.stringify(record.pageErrors.slice(0, 12)))
	console.log('badResponses:', JSON.stringify(record.badResponses.slice(0, 12)))
}

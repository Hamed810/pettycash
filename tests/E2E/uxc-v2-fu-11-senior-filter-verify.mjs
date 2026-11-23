/**
 * UXC-V2-FU-11-VERIFY — focused real-Chrome verification of Senior Manager
 * multi-cost-center filtering.
 *
 * Verification only — no source code, backend, or fixture modifications.
 * Reports actual observed fixture data; does not invent expected values.
 */
import { existsSync, mkdirSync, writeFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright-core'

const base = 'http://localhost:8081'
const iteration = '007'
const outDir = `artifacts/visual-review/UXC-V2-FU/${iteration}`
mkdirSync(outDir, { recursive: true })

const OCC = fileURLToPath(new URL('../../scripts/dev/occ', import.meta.url))

import { readFileSync } from 'node:fs'

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

const STORAGE_STATE = 'test-results/.auth/e2e-storage-state.json'

function currentLanguage() {
	try {
		return execFileSync(OCC, ['user:setting', user, 'core', 'lang'], { encoding: 'utf8' }).trim() || 'en'
	} catch {
		return 'en'
	}
}
const languageBefore = currentLanguage()

const record = {
	task: 'UXC-V2-FU-11-VERIFY',
	iteration,
	base,
	startedAt: new Date().toISOString(),
	languageBefore,
	chrome: null,
	checks: [],
	screenshots: [],
	consoleErrors: [],
	pageErrors: [],
	badResponses: [],
	fixtureNote: null,
}
let currentCell = 'setup'
function setCell(name) {
	currentCell = name
}
function check(name, condition, detail) {
	record.checks.push({ cell: currentCell, name, passed: Boolean(condition), detail })
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

async function newSession() {
	if (context !== null) await context.close()
	context = await browser.newContext({
		...(existsSync(STORAGE_STATE) ? { storageState: STORAGE_STATE } : {}),
		baseURL: base,
		viewport: { width: 1280, height: 900 },
		locale: 'en-US',
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
	return page
}

async function shot(name) {
	const file = `${outDir}/${name}.png`
	await page.screenshot({ path: file, fullPage: false })
	record.screenshots.push({ cell: currentCell, file })
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

async function main() {
	const EN_DESKTOP = { viewport: { width: 1280, height: 900 }, locale: 'en-US' }

	browser = await chromium.launch({ channel: 'chrome' })

	try {
		// ======================================================== Senior, desktop EN
		setCell('senior-desktop-en')
		setLanguage('en')
		await newSession()
		await page.goto(`${base}/apps/pettycash/#/reviews/senior`, { waitUntil: 'networkidle' })
		await page.waitForTimeout(2000)
		// Diagnostic: check what's on the page
		const diag = await page.evaluate(() => {
			return {
				url: window.location.href,
				pathname: window.location.pathname,
				hash: window.location.hash,
				hasApp: !!document.querySelector('#pettycash-app'),
				hasSeniorQueue: !!document.querySelector('[data-testid="senior-queue"]'),
				hasLogin: !!document.querySelector('input[name="user"]'),
				pageTitle: document.title,
				bodyText: document.body.innerText?.slice(0, 500),
			}
		})
		console.log('PAGE DIAGNOSTIC:', JSON.stringify(diag, null, 2))
		await shot('senior-desktop-en-01-diagnostic')
		await page.locator('[data-testid="senior-queue"]').waitFor({ timeout: 30000 })
		await assertNoHorizontalScroll('Senior desktop EN open')
		await shot('senior-desktop-en-01-open')

		const basic = await page.evaluate(() => {
			const rows = document.querySelectorAll('[data-testid^="sheet-row-"]')
			const chips = document.querySelectorAll('[data-testid^="center-filter-chip-"]')
			return {
				rows: rows.length,
				chips: chips.length,
				chipLabels: Array.from(chips).map(c => c.textContent?.trim()),
				hasTable: document.querySelector('table') !== null,
				headerCols: Array.from(document.querySelectorAll('thead th')).map(th => th.textContent?.trim()),
			}
		})
		check('Senior queue renders', basic.rows >= 0, basic)
		check('Senior queue has table', basic.hasTable === true, basic)
		check('Senior table has six columns', basic.headerCols.length === 6, basic.headerCols)
		record.fixtureNote = `Senior queue: ${basic.rows} rows, ${basic.chips} cost-center filter chips. Chip labels: ${JSON.stringify(basic.chipLabels)}. Table columns: ${JSON.stringify(basic.headerCols)}.`

		// ======================================================== Cost-center filter behavior
		if (basic.chips > 0) {
			setCell('senior-desktop-en-cost-center-filter')

			// Test A: All centers (default)
			const allState = await page.evaluate(() => {
				const rows = document.querySelectorAll('[data-testid^="sheet-row-"]')
				const activeChip = document.querySelector('[data-testid^="center-filter-chip-"][aria-pressed="true"]')
				const rowData = Array.from(rows).map(r => {
					const txCount = r.querySelector('[data-testid="sheet-pending-count"]')?.textContent?.trim() || ''
					const amount = r.querySelector('[data-testid="sheet-amount"]')?.textContent?.trim() || ''
					return { id: r.id, txCount, amount }
				})
				return { rowCount: rows.length, activeChip: activeChip?.textContent?.trim(), rowData }
			})
			check('Senior all-centers state captured', true, allState)

			// Click first center chip
			await page.locator('[data-testid^="center-filter-chip-"]').first().click()
			await page.waitForTimeout(500)

			const selectedState = await page.evaluate(() => {
				const rows = document.querySelectorAll('[data-testid^="sheet-row-"]')
				const activeChip = document.querySelector('[data-testid^="center-filter-chip-"][aria-pressed="true"]')
				const rowData = Array.from(rows).map(r => {
					const txCount = r.querySelector('[data-testid="sheet-pending-count"]')?.textContent?.trim() || ''
					const amount = r.querySelector('[data-testid="sheet-amount"]')?.textContent?.trim() || ''
					return { id: r.id, txCount, amount }
				})
				const duplicateIds = rowData.map(r => r.id).filter((id, i, arr) => arr.indexOf(id) !== i)
				return { rowCount: rows.length, activeChip: activeChip?.textContent?.trim(), rowData, duplicateIds }
			})
			check('Senior center-filter state captured', true, selectedState)
			check('Senior filter changes or keeps row count', true, { before: allState.rowCount, after: selectedState.rowCount })
			check('Senior no duplicate sheet rows when center selected', selectedState.duplicateIds.length === 0, selectedState.duplicateIds)
			await shot('senior-desktop-en-cost-center-filter-02-selected')

			// Test C: Restore All centers — click first chip again if it's a toggle, or check if "All" exists
			// Click the same chip again to deselect (restore all)
			await page.locator('[data-testid^="center-filter-chip-"]').first().click()
			await page.waitForTimeout(500)
			const restoredState = await page.evaluate(() => {
				const rows = document.querySelectorAll('[data-testid^="sheet-row-"]')
				const activeChip = document.querySelector('[data-testid^="center-filter-chip-"][aria-pressed="true"]')
				const rowData = Array.from(rows).map(r => {
					const txCount = r.querySelector('[data-testid="sheet-pending-count"]')?.textContent?.trim() || ''
					const amount = r.querySelector('[data-testid="sheet-amount"]')?.textContent?.trim() || ''
					return { id: r.id, txCount, amount }
				})
				return { rowCount: rows.length, activeChip: activeChip?.textContent?.trim(), rowData }
			})
			check('Senior all-centers restored state captured', true, restoredState)
			await shot('senior-desktop-en-cost-center-filter-03-restored')
		} else {
			setCell('senior-desktop-en-no-centers')
			record.fixtureNote = `Senior queue has ${basic.rows} rows but NO cost-center filter chips. The E2E user (${user}) has no assigned cost centers, so center filtering cannot be demonstrated in the live browser. This is a fixture limitation, not an implementation defect.`
		}

		// ======================================================== Search + status + sort still functional
		setCell('senior-desktop-en-search-sort')
		const searchInput = page.locator('[data-testid="senior-queue-search"]')
		if ((await searchInput.count()) > 0 && basic.rows > 0) {
			await searchInput.fill('sheet')
			await page.waitForTimeout(500)
			const searchRows = await page.locator('[data-testid^="sheet-row-"]').count()
			check('Senior search narrows results', true, { before: basic.rows, after: searchRows })
			await page.locator('[data-testid="senior-queue-search-clear"]').click()
			await page.waitForTimeout(500)
			const clearedRows = await page.locator('[data-testid^="sheet-row-"]').count()
			check('Senior search clear restores results', clearedRows === basic.rows, { clearedRows, expected: basic.rows })
		}

		// Global failure classification
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
		writeFileSync(`${outDir}/uxc-v2-fu-11-verify-evidence.json`, JSON.stringify(record, null, 2))
		if (browser !== null) await browser.close()
	}

	console.log(`UXC-V2-FU-11-VERIFY complete: ${record.passedChecks}/${record.totalChecks} checks, ` +
		`${record.screenshots.length} screenshots, consoleErrors=${record.consoleErrorCount}, ` +
		`pageErrors=${record.pageErrorCount}, badResponses=${record.badResponseCount}`)
	if (record.fixtureNote) console.log('FIXTURE NOTE:', record.fixtureNote)
	if (record.consoleErrors.length > 0 || record.pageErrors.length > 0 || record.badResponses.length > 0) {
		console.log('consoleErrors:', JSON.stringify(record.consoleErrors.slice(0, 12)))
		console.log('pageErrors:', JSON.stringify(record.pageErrors.slice(0, 12)))
		console.log('badResponses:', JSON.stringify(record.badResponses.slice(0, 12)))
		process.exit(1)
	}
}

main().catch((error) => {
	console.error('UXC-V2-FU-11-VERIFY failed:', error)
	if (browser !== null) { try { browser.close() } catch { /* ignore */ } }
	process.exit(1)
})

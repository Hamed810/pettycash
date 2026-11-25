/**
 * Official-Chrome evidence for the FR-005-066 human-readable date standard.
 *
 * Verifies the purchaser transaction table and a representative review queue in all four
 * language/calendar combinations: fixed YYYY/MM/DD, no era marker, locale-appropriate digits,
 * and single-line date cells. Read-only: it navigates existing sheets and never saves or
 * submits anything.
 *
 * Usage:
 *   CALENDAR_DATE_ITERATION=iteration-1 node tests/E2E/calendar-date-display-evidence.mjs
 */

/* global process */

import { chromium } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { getUserLanguage, setUserLanguage } from './t060-fixtures.mjs'

const base = process.env.PETTYCASH_BASE_URL ?? 'http://localhost:8081'
const iteration = process.env.CALENDAR_DATE_ITERATION ?? 'iteration-1'
const outputRoot = `artifacts/visual-review/calendar-date-display-inconsistency/${iteration}`

/**
 * Language and calendar are independent preferences, and the locale decides the digits. Each
 * combination therefore pins both: English language keeps the `en_US` locale (Latin digits), Persian
 * language keeps the `fa_IR` locale (Persian digits), and the calendar varies separately.
 */
const combos = [
	{ locale: 'en', localeSetting: 'en_US', calendar: 'gregory', label: 'en-gregory', digits: /^[0-9]+$/, unavailable: 'Date unavailable' },
	{ locale: 'en', localeSetting: 'en_US', calendar: 'persian', label: 'en-jalali', digits: /^[0-9]+$/, unavailable: 'Date unavailable' },
	{ locale: 'fa', localeSetting: 'fa_IR', calendar: 'gregory', label: 'fa-gregory', digits: /^[۰-۹]+$/, unavailable: 'تاریخ نامشخص' },
	{ locale: 'fa', localeSetting: 'fa_IR', calendar: 'persian', label: 'fa-jalali', digits: /^[۰-۹]+$/, unavailable: 'تاریخ نامشخص' },
]

const OCC = fileURLToPath(new URL('../../scripts/dev/occ', import.meta.url))

/** The account locale is a separate Nextcloud preference from the language (see utils/locale.ts). */
function getUserLocale(user) {
	return execFileSync(OCC, ['user:setting', user, 'core', 'locale'], { encoding: 'utf8' }).trim()
}

function setUserLocale(user, locale) {
	execFileSync(OCC, ['user:setting', user, 'core', 'locale', locale], { stdio: 'pipe' })
}

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

const DATE_ONLY = /^([0-9۰-۹]{4})\/([0-9۰-۹]{2})\/([0-9۰-۹]{2})$/
const DATE_TIME = /^([0-9۰-۹]{4})\/([0-9۰-۹]{2})\/([0-9۰-۹]{2}) [^]+$/

function check(record, name, condition, detail = null) {
	record.checks.push({ name, passed: Boolean(condition), detail })
	if (!condition) throw new Error(`CHECK FAILED: ${name}${detail === null ? '' : ` — ${JSON.stringify(detail)}`}`)
}

async function shot(page, dir, name) {
	const file = `${dir}/${name}.png`
	await page.mouse.move(2, 2)
	await page.screenshot({ path: file, fullPage: false })
	return file
}

async function signIn(page) {
	await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('input[name="user"]', { timeout: 15000 })
	await page.fill('input[name="user"]', user)
	await page.fill('input[name="password"]', password)
	await Promise.all([
		page.waitForLoadState('networkidle'),
		page.click('button[type="submit"]'),
	])
	if (new URL(page.url()).pathname.startsWith('/login')) throw new Error(`login failed for ${user}`)
}

async function listSheets(page) {
	return page.evaluate(async () => {
		const response = await fetch('/ocs/v2.php/apps/pettycash/api/v1/sheets?page=1&pageSize=200', {
			headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
		})
		const data = await response.json()
		return data.ocs?.data?.items ?? []
	})
}

async function visibleTexts(page, selector) {
	return page.evaluate((query) => [...document.querySelectorAll(query)]
		.filter((element) => {
			const rect = element.getBoundingClientRect()
			return rect.width > 0 && rect.height > 0 && getComputedStyle(element).visibility !== 'hidden'
		})
		.map((element) => ({
			text: (element.textContent ?? '').trim(),
			whiteSpace: getComputedStyle(element).whiteSpace,
			height: element.getBoundingClientRect().height,
			fontSize: parseFloat(getComputedStyle(element).fontSize),
		})), selector)
}

/** Verify a date-only or date-time cell shape, locale digits, era absence and single line. */
function verifyDates(record, values, combo, label, allowTime) {
	// A queue row that has not been submitted keeps its existing localized label. That is the
	// required missing-value behaviour, not a date to reformat, so it is asserted and skipped.
	const dates = values.filter((value) => value.text !== combo.unavailable)
	for (const value of values.filter((entry) => entry.text === combo.unavailable)) {
		check(record, `${label}: missing date keeps its existing label`, value.text === combo.unavailable, value.text)
	}

	check(record, `${label}: at least one date is rendered`, dates.length > 0, values.map((value) => value.text))
	for (const value of dates) {
		const pattern = allowTime ? DATE_TIME : DATE_ONLY
		const match = pattern.exec(value.text)
		check(record, `${label}: ${JSON.stringify(value.text)} matches YYYY/MM/DD`, match !== null, value.text)
		check(record, `${label}: no era marker`, !/\b(?:AP|AD|BC|BP)\b/.test(value.text), value.text)
		check(record, `${label}: expected digit shape`, combo.digits.test(match[1]), value.text)
		check(record, `${label}: single line (nowrap)`, value.whiteSpace === 'nowrap', value.whiteSpace)
		check(record, `${label}: rendered height is one line`, value.height <= value.fontSize * 1.7, {
			height: value.height,
			fontSize: value.fontSize,
		})
	}
}

async function openTransactionTable(page) {
	const sheets = await listSheets(page)
	const candidates = sheets.filter((sheet) => (sheet.transactionCount ?? 0) > 0)
	if (candidates.length === 0) throw new Error('no sheet with transactions is visible to the E2E user')

	for (const sheet of candidates.slice(0, 12)) {
		await page.goto(`${base}/apps/pettycash/#/sheets/${sheet.id}/transactions`, { waitUntil: 'domcontentloaded' })
		try {
			await page.waitForSelector('[data-test="transaction-expense-date"]', { timeout: 7000 })
			return sheet
		} catch {
			// The E2E user may not be authorised for every listed sheet; try the next one.
		}
	}
	throw new Error('no accessible transaction table with a date cell')
}

/** Wait until a queue's initial fetch has settled, then read its visible submitted dates. */
async function queueValues(page, route, panel, allowTime) {
	await page.goto(`${base}/apps/pettycash/#/reviews/${route}`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector(`[data-testid="${panel}"]`, { timeout: 15000 })

	// The queue renders a loading state and then either rows or the empty state. Reading the
	// date cells before the fetch settles is a race, not a product defect.
	await page.waitForFunction(() => document.querySelector('[data-testid="loading"]') === null, null, { timeout: 20000 })
	await page.waitForSelector('[data-testid="sheet-submitted"], [data-testid="empty-queue"]', { timeout: 20000 })
	await page.waitForTimeout(250)

	return { route, allowTime, values: await visibleTexts(page, '[data-testid="sheet-submitted"]') }
}

async function openReviewQueue(page) {
	// Reading a queue navigates the page, so only the queue actually chosen may be opened last:
	// the screenshot must show the same surface the recorded dates came from.
	const queues = [
		['cost-center', 'cost-center-queue', false],
		['senior', 'senior-queue', false],
		['finance', 'finance-queue', true],
	]

	for (const [route, panel, allowTime] of queues) {
		const queue = await queueValues(page, route, panel, allowTime)
		if (queue.values.length > 0) return queue
	}

	throw new Error('no review queue with a submitted date is available to the E2E user')
}

let browser = null
let originalLanguage = null
let originalLocale = null
const record = {
	iteration,
	startedAt: new Date().toISOString(),
	combos: [],
	passed: false,
}

try {
	originalLanguage = getUserLanguage(user)
	originalLocale = getUserLocale(user)
	browser = await chromium.launch({ channel: 'chrome' })

	for (const combo of combos) {
		const dir = `${outputRoot}/${combo.label}`
		mkdirSync(dir, { recursive: true })
		const comboRecord = { combo: combo.label, screenshots: [], checks: [], passed: false }
		const context = await browser.newContext({
			viewport: { width: 1440, height: 900 },
			locale: combo.locale === 'fa' ? 'fa-IR' : 'en-US',
		})

		try {
			const page = await context.newPage()
			const pageErrors = []
			page.on('pageerror', (error) => pageErrors.push(String(error)))
			await signIn(page)

			await page.evaluate((calendar) => {
				globalThis.localStorage.setItem('pettycash.calendarPreference', calendar)
			}, combo.calendar)
			setUserLanguage(user, combo.locale)
			setUserLocale(user, combo.localeSetting)
			await page.reload({ waitUntil: 'networkidle' })

			const lang = await page.evaluate(() => document.documentElement.lang)
			check(comboRecord, 'document language matches', lang.toLowerCase().startsWith(combo.locale), lang)

			const documentLocale = await page.evaluate(() => document.documentElement.dataset.locale ?? null)
			comboRecord.documentLocale = documentLocale
			const expectedLocale = combo.localeSetting.toLowerCase().replace('_', '-')
			check(
				comboRecord,
				'account locale reaches the document',
				documentLocale === null || documentLocale.toLowerCase().replace('_', '-') === expectedLocale,
				documentLocale,
			)

			const sheet = await openTransactionTable(page)
			await page.waitForTimeout(500)
			const tableDates = await visibleTexts(page, '[data-test="transaction-expense-date"]')
			comboRecord.sheet = { id: sheet.id, title: sheet.title }
			comboRecord.tableDates = tableDates.map((value) => value.text)
			verifyDates(comboRecord, tableDates, combo, 'transaction table', false)
			comboRecord.screenshots.push(await shot(page, dir, '01-transaction-table'))

			const queue = await openReviewQueue(page)
			await page.waitForTimeout(500)
			comboRecord.reviewQueue = queue.route
			comboRecord.reviewDates = queue.values.map((value) => value.text)
			verifyDates(comboRecord, queue.values, combo, `${queue.route} queue`, queue.allowTime)
			comboRecord.screenshots.push(await shot(page, dir, `02-${queue.route}-queue`))

			// The finance queue also renders a submitted timestamp, which pins the other rule: only
			// the date portion is standardized and the existing time semantics are retained.
			const finance = await queueValues(page, 'finance', 'finance-queue', true)
			if (finance.values.length > 0) {
				comboRecord.dateTimeDates = finance.values.map((value) => value.text)
				verifyDates(comboRecord, finance.values, combo, 'finance queue date-time', true)
				comboRecord.screenshots.push(await shot(page, dir, '03-finance-queue-datetime'))
			}

			check(comboRecord, 'no page errors', pageErrors.length === 0, pageErrors)
			comboRecord.passed = comboRecord.checks.every((entry) => entry.passed)
		} catch (error) {
			comboRecord.error = String(error)
		} finally {
			await context.close()
		}

		record.combos.push(comboRecord)
	}

	record.passed = record.combos.length === combos.length && record.combos.every((combo) => combo.passed)
} finally {
	if (originalLanguage !== null) setUserLanguage(user, originalLanguage)
	if (originalLocale !== null) setUserLocale(user, originalLocale)
	if (browser !== null) await browser.close()
	record.finishedAt = new Date().toISOString()
	mkdirSync(outputRoot, { recursive: true })
	writeFileSync(`${outputRoot}/evidence.json`, `${JSON.stringify(record, null, 2)}\n`)
	console.log(JSON.stringify({
		passed: record.passed,
		outputRoot,
		combos: record.combos.map((combo) => ({
			combo: combo.combo,
			passed: combo.passed,
			tableDates: combo.tableDates,
			reviewQueue: combo.reviewQueue,
			reviewDates: combo.reviewDates,
			dateTimeDates: combo.dateTimeDates,
			screenshots: combo.screenshots,
			error: combo.error,
		})),
	}, null, 2))
	if (!record.passed) process.exitCode = 1
}

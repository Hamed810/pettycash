/**
 * T084 Equivalent-Date Payload Verification (Standalone).
 *
 * Selects the same semantic date under both calendar systems and verifies
 * that the canonical expenseDate in the outgoing payload is identical.
 *
 * Equivalent dates:
 *   Jalali 1405-08-10  =  Gregorian 2026-11-01  →  expenseDate: "2026-11-01"
 *
 * Combos:
 *   English UI + Gregorian calendar → select Gregorian day 1 in November 2026
 *   Persian UI + Jalali calendar   → select Jalali day 10 in Aban 1405
 *
 * Usage:
 *   T084_EQ_ITERATION=eq-iteration-1 node tests/E2E/t084-equivalent-date-payload.mjs
 */

import { chromium } from '@playwright/test'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'

const base = process.env.PETTYCASH_BASE_URL ?? 'http://localhost:8081'
const iteration = process.env.T084_EQ_ITERATION ?? 'eq-iteration-1'

const combos = [
	{ locale: 'en', calendar: 'gregory', label: 'en-gregory', targetCanonical: '2026-11-01' },
	{ locale: 'fa', calendar: 'persian', label: 'fa-jalali', targetCanonical: '2026-11-01' },
]

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

function outDirFor(combo) {
	const dir = `artifacts/visual-review/t084/${iteration}/${combo.label}`
	mkdirSync(dir, { recursive: true })
	return dir
}

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
	if (new URL(page.url()).pathname.startsWith('/login')) {
		throw new Error(`login failed for ${user}`)
	}
}

async function setCalendarPreferenceInBrowser(page, calendar) {
	await page.evaluate((cal) => {
		globalThis.localStorage.setItem('pettycash.calendarPreference', cal)
	}, calendar)
	return await page.evaluate(() => globalThis.localStorage.getItem('pettycash.calendarPreference'))
}

async function openSheetsList(page) {
	await page.goto(`${base}/apps/pettycash/#/sheets`, { waitUntil: 'domcontentloaded' })
	await page.waitForSelector('[data-test="sheet-list-view"]', { timeout: 15000 })
}

async function findEditableSheet(page) {
	return page.evaluate(async () => {
		const response = await fetch('/ocs/v2.php/apps/pettycash/api/v1/sheets?page=1&pageSize=200', {
			headers: { Accept: 'application/json', 'OCS-APIRequest': 'true' },
		})
		const data = await response.json()
		const sheets = data.ocs?.data?.items ?? []
		const editable = sheets.find((s) => s.status === 'OPEN')
		if (!editable) throw new Error('No editable (OPEN) sheet found')
		return { id: editable.id, title: editable.title }
	})
}

async function openSheetDetail(page, sheetId) {
	await page.locator(`[data-sheet-open="${sheetId}"]:visible`).first().click()
	await page.waitForSelector('[data-test="sheet-detail-view"]', { timeout: 10000 })
}

async function startAddTransaction(page) {
	await page.locator('[data-test="create-transaction-btn"]').click()
	await page.waitForSelector('[data-test="transaction-form-view"]', { timeout: 10000 })
}

async function openExpenseDatePicker(page) {
	await page.locator('[data-test="expense-date-input"]').click()
	await page.waitForSelector('.calendar-date-picker-popover', { timeout: 10000 })
}

/** Navigate to target month and select target day in the calendar popup. */
async function selectTargetDate(page, combo) {
	const isGregorian = combo.calendar === 'gregory'

	if (isGregorian) {
		// Gregorian 2026-11-01: Calendar shows November 2026 by default.
		// Click day "1" in the current month.
		await page.evaluate(() => {
			const cells = document.querySelectorAll('[data-jalali-calendar-day]:not([data-outside-month])')
			for (const cell of cells) {
				if (cell.textContent.trim() === '1') {
					cell.click()
					break
				}
			}
		})
	} else {
		// Jalali 1405-08-10: Calendar shows Azar 1405 (month 9) by default.
		// Click previous month to go to Aban (month 8), then click day 10.
		await page.evaluate(() => {
			const btn = document.querySelector('[data-jalali-calendar-nav="previous"]')
			if (btn) btn.click()
		})
		await page.waitForTimeout(100)
		await page.evaluate(() => {
			const cells = document.querySelectorAll('[data-jalali-calendar-day]:not([data-outside-month])')
			for (const cell of cells) {
				if (cell.textContent.trim() === '10') {
					cell.click()
					break
				}
			}
		})
	}
	await page.waitForTimeout(500)
}

let browser = null

try {
	browser = await chromium.launch({ channel: 'chrome' })

	const overallRecord = {
		iteration,
		startedAt: new Date().toISOString(),
		combos: [],
		checks: [],
	}

	for (const combo of combos) {
		const dir = outDirFor(combo)
		const record = {
			combo: combo.label,
			locale: combo.locale,
			calendar: combo.calendar,
			screenshots: [],
			checks: [],
			createdPayload: null,
			passed: false,
		}

		const context = await browser.newContext({
			viewport: { width: 1440, height: 900 },
			locale: combo.locale === 'fa' ? 'fa-IR' : 'en-US',
			acceptDownloads: true,
		})

		let page
		try {
			page = await context.newPage()
			await signIn(page)

			const storedPref = await setCalendarPreferenceInBrowser(page, combo.calendar)
			check(record, 'calendar preference set in localStorage', storedPref === combo.calendar, storedPref)

			await openSheetsList(page)
			await shot(page, dir, '00-sheet-list')

			// ---------------------------------------------------------- CREATE journey
			const sheet = await findEditableSheet(page)
			await openSheetDetail(page, sheet.id)
			await shot(page, dir, '01-sheet-detail')

			await startAddTransaction(page)
			await page.waitForLoadState('networkidle')
			await page.waitForSelector('[data-test="transaction-form-view"] input[type="text"]', { timeout: 15000 })
			await shot(page, dir, '02-add-transaction-form')

			await page.locator('[data-test="amount-input"]').fill('1000')
			await page.locator('[data-test="description-input"]').fill('Test transaction')

			await openExpenseDatePicker(page)
			await shot(page, dir, '03-picker-open')

			const pickerSystem = await page.locator('[data-test="calendar-date-picker"]').getAttribute('data-system')
			const expectedSystem = combo.calendar === 'persian' ? 'jalali' : 'gregorian'
			check(record, 'picker uses the requested calendar system', pickerSystem === expectedSystem, pickerSystem)

			await selectTargetDate(page, combo)

			const popoverVisible = await page.locator('.calendar-date-picker-popover').count()
			check(record, 'picker closes after date selection', popoverVisible === 0, popoverVisible)

			await shot(page, dir, '04-date-selected')

			const visibleDate = await page.locator('[data-test="expense-date-input"]').inputValue()
			check(record, 'visible field has a date value', visibleDate.length > 0, visibleDate)

			const labelVisible = await page.locator('.calendar-date-picker__label').isVisible()
			check(record, 'visible expense-date label is present', labelVisible, labelVisible)

			// Capture the CREATE POST request
			const createRequestPromise = page.waitForRequest((request) =>
				request.method() === 'POST' && /\/sheets\/[^/]+\/transactions/.test(new URL(request.url()).pathname),
			)
			await page.locator('[data-test="submit-btn"]').click()
			const createRequest = await createRequestPromise
			try {
				await page.waitForResponse((response) => response.request() === createRequest)
			} catch {
				// Navigation may abort the response; request body already captured
			}

			record.createdPayload = createRequest.postData()
			if (record.createdPayload) {
				const payloadJson = JSON.parse(record.createdPayload)
				check(record, 'CREATE payload has expenseDate', typeof payloadJson.expenseDate === 'string' && payloadJson.expenseDate.length > 0, payloadJson.expenseDate)
				check(record, 'CREATE payload expenseDate equals canonical 2026-11-01', payloadJson.expenseDate === '2026-11-01', payloadJson.expenseDate)
				check(record, 'CREATE payload has no calendar metadata', !payloadJson.calendarSystem && !payloadJson.calendarPreference, JSON.stringify(payloadJson))
			} else {
				record.checks.push({ name: 'CREATE payload captured', passed: false, detail: 'no POST request body captured' })
			}

			record.passed = true
		} catch (error) {
			record.passed = false
			record.failure = error instanceof Error ? error.message : String(error)
			console.error(`Combo ${combo.label} failed:`, record.failure)
		} finally {
			if (page) await page.close().catch(() => undefined)
			await context.close().catch(() => undefined)
		}

		overallRecord.combos.push(record)

		writeFileSync(`${dir}/evidence.json`, JSON.stringify(record, null, 2))
		console.log(JSON.stringify({
			combo: combo.label,
			passed: record.passed,
			checks: record.checks.length,
			createdPayload: record.createdPayload,
			failure: record.failure,
		}, null, 2))
	}

	// Verify payload equality across combos
	const payloads = overallRecord.combos.map((c) => c.createdPayload)
	const allSameCanonical = payloads.every((p) => p && JSON.parse(p).expenseDate === '2026-11-01')
	check(overallRecord, 'all CREATE payloads have identical canonical expenseDate 2026-11-01', allSameCanonical, payloads)

	overallRecord.completedAt = new Date().toISOString()
	overallRecord.passed = overallRecord.combos.every((c) => c.passed) && allSameCanonical
	writeFileSync(`artifacts/visual-review/t084/${iteration}/evidence.json`, JSON.stringify(overallRecord, null, 2))

	if (!overallRecord.passed) {
		console.error('T084 equivalent-date verification: one or more combos failed')
		process.exitCode = 1
	} else {
		console.log('T084 equivalent-date verification: all combos passed — identical canonical expenseDate 2026-11-01 under both calendars')
	}

} catch (error) {
	console.error('T084 equivalent-date verification fatal error:', error instanceof Error ? error.message : String(error))
	process.exitCode = 1
} finally {
	try { await browser?.close() } catch (error) { /* ignore */ }
}

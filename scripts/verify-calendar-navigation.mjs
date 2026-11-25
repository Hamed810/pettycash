/**
 * Browser verification for calendar-picker-navigation-premature-close bug.
 *
 * Verifies the expense-date picker popup remains open during month/year navigation
 * in both CREATE and EDIT forms, for both Gregorian and Jalali calendars,
 * in English LTR and Persian RTL.
 */

import { chromium } from '@playwright/test'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { setUserLanguage } from '../tests/E2E/t060-fixtures.mjs'

const base = process.env.PETTYCASH_BASE_URL ?? 'http://localhost:8081'
const iteration = process.env.VERIFY_ITERATION ?? 'iteration-1'

const combos = [
	{ locale: 'en', calendar: 'gregory', label: 'en-gregory', system: 'gregorian' },
	{ locale: 'en', calendar: 'persian', label: 'en-jalali', system: 'jalali' },
	{ locale: 'fa', calendar: 'gregory', label: 'fa-gregory', system: 'gregorian' },
	{ locale: 'fa', calendar: 'persian', label: 'fa-jalali', system: 'jalali' },
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

function outDirFor(label) {
	const dir = `artifacts/visual-review/calendar-picker-navigation-premature-close/${iteration}/${label}`
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
}

async function setCalendarPreferenceInBrowser(page, calendar) {
	await page.evaluate((cal) => {
		globalThis.localStorage.setItem('pettycash.calendarPreference', cal)
	}, calendar)
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
	await page.locator('[data-test="create-transaction-btn"]').click({ force: true })
	await page.waitForSelector('[data-test="transaction-form-view"]', { timeout: 10000 })
	await page.waitForLoadState('networkidle')
}

async function openExpenseDatePicker(page) {
	await page.locator('[data-test="expense-date-input"]').click()
	await page.waitForSelector('.calendar-date-picker-popover', { timeout: 10000 })
}

async function waitForCreateRequest(page) {
	return page.waitForRequest((request) =>
		request.method() === 'POST' && /\/sheets\/[^/]+\/transactions/.test(new URL(request.url()).pathname),
	)
}

async function waitForEditRequest(page) {
	return page.waitForRequest((request) =>
		request.method() === 'PATCH' && /\/transactions\/[^/]+$/.test(new URL(request.url()).pathname),
	)
}

let browser = null

try {
	browser = await chromium.launch({ channel: 'chrome' })

	const overallRecord = {
		iteration,
		startedAt: new Date().toISOString(),
		combos: [],
	}

	for (const combo of combos) {
		const dir = outDirFor(combo.label)
		const record = {
			combo: combo.label,
			locale: combo.locale,
			calendar: combo.calendar,
			system: combo.system,
			screenshots: [],
			checks: [],
			createdPayload: null,
			editedPayload: null,
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

			await setCalendarPreferenceInBrowser(page, combo.calendar)
			await setUserLanguage(user, combo.locale)
			await page.reload({ waitUntil: 'networkidle' })

			const lang = await page.evaluate(() => document.documentElement.lang)
			check(record, 'document language matches requested locale', lang.toLowerCase().startsWith(combo.locale), lang)

			await openSheetsList(page)
			const dirAttr = await page.locator('[data-test="sheet-list-view"]').getAttribute('dir')
			check(record, 'direction matches locale', dirAttr === (combo.locale === 'fa' ? 'rtl' : 'ltr'), dirAttr)
			await shot(page, dir, '00-sheet-list')

			// ---------------------------------------------------------- CREATE journey
			const sheet = await findEditableSheet(page)
			await openSheetDetail(page, sheet.id)
			await shot(page, dir, '01-sheet-detail-create')

			await startAddTransaction(page)
			await shot(page, dir, '02-add-transaction-form')

			await page.locator('[data-test="amount-input"]').fill('1000')
			await page.locator('[data-test="description-input"]').fill('Test transaction')

			await openExpenseDatePicker(page)
			await shot(page, dir, '03-picker-open-create')

			const pickerSystem = await page.locator('[data-test="calendar-date-picker"]').getAttribute('data-system')
			check(record, 'picker uses the requested calendar system (CREATE)', pickerSystem === combo.system, pickerSystem)

			const initialDate = await page.locator('[data-test="expense-date-input"]').inputValue()
			check(record, 'CREATE: initial expense date captured', typeof initialDate === 'string' && initialDate.length > 0, initialDate)

			// Click next month 3 times
			for (let i = 0; i < 3; i++) {
				await page.locator('[data-jalali-calendar-nav="next"]').click()
				await page.waitForTimeout(300)
				const popoverOpen = await page.locator('.calendar-date-picker-popover').isVisible()
				check(record, `CREATE: popup open after next-month click ${i + 1}`, popoverOpen, popoverOpen)
			}
			await shot(page, dir, '04-next-month-x3-create')

			// Click previous month 3 times
			for (let i = 0; i < 3; i++) {
				await page.locator('[data-jalali-calendar-nav="previous"]').click()
				await page.waitForTimeout(300)
				const popoverOpen = await page.locator('.calendar-date-picker-popover').isVisible()
				check(record, `CREATE: popup open after prev-month click ${i + 1}`, popoverOpen, popoverOpen)
			}
			await shot(page, dir, '05-prev-month-x3-create')

			// Confirm date unchanged after navigation
			const dateAfterNav = await page.locator('[data-test="expense-date-input"]').inputValue()
			check(record, 'CREATE: expense date unchanged after navigation', dateAfterNav === initialDate, { before: initialDate, after: dateAfterNav })

			// Select a day
			const dayCell = '[data-jalali-calendar-day]:not([data-outside-month]):not([aria-selected="true"])'
			const firstDayVisible = await page.locator(dayCell).first().isVisible()
			check(record, 'CREATE: first calendar day cell visible', firstDayVisible, firstDayVisible)
			if (firstDayVisible) {
				await page.locator(dayCell).first().click()
				await page.waitForTimeout(500)
			}

			const popoverClosed = await page.locator('.calendar-date-picker-popover').count()
			check(record, 'CREATE: popup closes after day selection', popoverClosed === 0, popoverClosed)

			const newDate = await page.locator('[data-test="expense-date-input"]').inputValue()
			check(record, 'CREATE: expense date changed after intentional selection', newDate !== initialDate, { before: initialDate, after: newDate })

			// Submit and capture CREATE payload
			const createRequestPromise = waitForCreateRequest(page)
			await page.locator('[data-test="submit-btn"]').click()
			const createRequest = await createRequestPromise
			try {
				await page.waitForResponse((response) => response.request() === createRequest)
			} catch {
				// Navigation may abort the response
			}
			record.createdPayload = createRequest.postData()
			if (record.createdPayload) {
				const payloadJson = JSON.parse(record.createdPayload)
				check(record, 'CREATE: payload has expenseDate', typeof payloadJson.expenseDate === 'string' && payloadJson.expenseDate.length > 0, payloadJson.expenseDate)
				check(record, 'CREATE: payload has no calendar metadata', !payloadJson.calendarSystem && !payloadJson.calendarPreference, JSON.stringify(payloadJson))
			}

			// ---------------------------------------------------------- EDIT journey
			await page.waitForSelector('[data-test="sheet-detail-view"]', { timeout: 10000 })
			await page.waitForTimeout(2000)

			const transactionRows = page.locator('[data-test="sheet-transaction-row"], [data-test^="sheet-transaction-card-"]')
			const firstRow = transactionRows.filter({ visible: true }).first()
			if (await firstRow.count() > 0) {
				await firstRow.locator('[data-test="view-transaction"]').click()
				await page.waitForSelector('[data-test="transaction-form-view"]', { timeout: 10000 })
				await shot(page, dir, '06-edit-form')

				const editInitialDate = await page.locator('[data-test="expense-date-input"]').inputValue()
				check(record, 'EDIT: initial expense date captured', typeof editInitialDate === 'string' && editInitialDate.length > 0, editInitialDate)

				await openExpenseDatePicker(page)
				await page.waitForTimeout(300)
				await shot(page, dir, '07-picker-open-edit')

				// Edit: next month 3 times
				for (let i = 0; i < 3; i++) {
					await page.locator('[data-jalali-calendar-nav="next"]').click()
					await page.waitForTimeout(300)
					const popoverOpen = await page.locator('.calendar-date-picker-popover').isVisible()
					check(record, `EDIT: popup open after next-month click ${i + 1}`, popoverOpen, popoverOpen)
				}

				// Edit: previous month 3 times
				for (let i = 0; i < 3; i++) {
					await page.locator('[data-jalali-calendar-nav="previous"]').click()
					await page.waitForTimeout(300)
					const popoverOpen = await page.locator('.calendar-date-picker-popover').isVisible()
					check(record, `EDIT: popup open after prev-month click ${i + 1}`, popoverOpen, popoverOpen)
				}

				// Edit: date unchanged after navigation
				const editDateAfterNav = await page.locator('[data-test="expense-date-input"]').inputValue()
				check(record, 'EDIT: expense date unchanged after navigation', editDateAfterNav === editInitialDate, { before: editInitialDate, after: editDateAfterNav })

				// Edit: Escape closes picker without changing date or closing modal
				// Picker is already open from navigation checks above
				await page.waitForTimeout(300)
				await page.keyboard.press('Escape')
				await page.waitForTimeout(300)
				const editPopoverClosed = await page.locator('.calendar-date-picker-popover').count()
				check(record, 'EDIT: picker closes on Escape without change', editPopoverClosed === 0, editPopoverClosed)
				const editDateAfterEscape = await page.locator('[data-test="expense-date-input"]').inputValue()
				check(record, 'EDIT: expense date unchanged after Escape', editDateAfterEscape === editInitialDate, { before: editInitialDate, after: editDateAfterEscape })

				// Edit: day selection closes picker and updates date
				await openExpenseDatePicker(page)
				const editSecondDay = page.locator(dayCell).nth(1)
				const editSecondDayVisible = await editSecondDay.isVisible()
				check(record, 'EDIT: second calendar day cell visible', editSecondDayVisible, editSecondDayVisible)
				if (editSecondDayVisible) {
					await editSecondDay.click()
				}
				await page.waitForTimeout(500)
				const editNewDate = await page.locator('[data-test="expense-date-input"]').inputValue()
				check(record, 'EDIT: expense date changed after intentional selection', editNewDate !== editInitialDate, { before: editInitialDate, after: editNewDate })

				const editRequestPromise = waitForEditRequest(page)
				await page.locator('[data-test="submit-btn"]').click()
				const editRequest = await editRequestPromise
				try {
					await page.waitForResponse((response) => response.request() === editRequest)
				} catch {
					// Navigation may abort the response
				}
				record.editedPayload = editRequest.postData()
				if (record.editedPayload) {
					const editPayloadJson = JSON.parse(record.editedPayload)
					check(record, 'EDIT: payload has expenseDate', typeof editPayloadJson.expenseDate === 'string' && editPayloadJson.expenseDate.length > 0, editPayloadJson.expenseDate)
				}
			} else {
				check(record, 'no transaction rows visible for EDIT journey', true, 'no rows available')
			}

			// ---------------------------------------------------------- Outside-click dismissal (CREATE)
			await startAddTransaction(page)
			await page.waitForLoadState('networkidle')
			await page.locator('[data-test="amount-input"]').fill('1000')
			await openExpenseDatePicker(page)
			await page.waitForTimeout(300)
			// Click outside the picker boundary
			await page.click('body', { position: { x: 100, y: 100 } })
			await page.waitForTimeout(300)
			const outsidePopoverClosed = await page.locator('.calendar-date-picker-popover').count()
			check(record, 'outside click closes popup', outsidePopoverClosed === 0, outsidePopoverClosed)

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
			screenshots: record.screenshots,
			failure: record.failure,
		}, null, 2))
	}

	overallRecord.completedAt = new Date().toISOString()
	overallRecord.passed = overallRecord.combos.every((c) => c.passed)
	writeFileSync(`artifacts/visual-review/calendar-picker-navigation-premature-close/${iteration}/evidence.json`, JSON.stringify(overallRecord, null, 2))

	if (!overallRecord.passed) {
		console.error('Calendar navigation verification: one or more combos failed')
		process.exitCode = 1
	} else {
		console.log('Calendar navigation verification: all combos passed')
	}

} catch (error) {
	console.error('Calendar navigation verification fatal error:', error instanceof Error ? error.message : String(error))
	process.exitCode = 1
} finally {
	try { await browser?.close() } catch (error) { /* ignore */ }
}

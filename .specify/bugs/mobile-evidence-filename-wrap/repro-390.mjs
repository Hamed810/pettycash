/**
 * Read-only 390px reproduction for the evidence-list filename-wrapping bug.
 *
 * Measures the rendered geometry of the shared EvidenceList in both hosts that render it:
 *   1. the transaction editing form (the T075 surface, where T077's screenshot showed the defect);
 *   2. the transaction detail view (the read surface).
 *
 * Runs in official Google Chrome via Playwright `channel: 'chrome'`, at 390x844, in English LTR
 * and Persian RTL. It never uploads, removes, edits, or saves anything; the T060 fixture draft is
 * only read. The account language is restored in `finally`.
 */

import { chromium } from '@playwright/test'
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { getUserLanguage, setUserLanguage } from '/home/ubuntu/projects/pettycash/tests/E2E/t060-fixtures.mjs'

const base = process.env.PETTYCASH_BASE_URL ?? 'http://localhost:8081'
const VIEWPORT = { width: 390, height: 844 }

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
if (!user || !password) throw new Error('missing E2E credentials')

const MEASURE = () => {
	const px = (value) => Number.parseFloat(value) || 0
	const box = (element) => {
		if (!element) return null
		const rect = element.getBoundingClientRect()
		return {
			x: Math.round(rect.x * 10) / 10,
			y: Math.round(rect.y * 10) / 10,
			width: Math.round(rect.width * 10) / 10,
			height: Math.round(rect.height * 10) / 10,
			right: Math.round(rect.right * 10) / 10,
			bottom: Math.round(rect.bottom * 10) / 10,
		}
	}

	const list = document.querySelector('.evidence-list[data-test="evidence-list"]')
	if (!list) return { found: false }
	const item = list.querySelector('[data-test="evidence-list-item"]')
	if (!item) return { found: false, listOnly: true }

	const name = item.querySelector('[data-test="evidence-name"]')
	const body = item.querySelector('.evidence-item__body')
	const actions = item.querySelector('.evidence-item__actions')
	const icon = item.querySelector('.evidence-item__icon')
	const meta = item.querySelector('.evidence-item__meta')

	const nameStyle = getComputedStyle(name)
	const bodyStyle = getComputedStyle(body)
	const actionsStyle = getComputedStyle(actions)
	const itemStyle = getComputedStyle(item)

	let lineRects = []
	const range = document.createRange()
	range.selectNodeContents(name)
	lineRects = [...range.getClientRects()]
		.filter((rect) => rect.width > 0 && rect.height > 0)
		.map((rect) => Math.round(rect.width * 10) / 10)

	const lineHeight = px(nameStyle.lineHeight) || px(nameStyle.fontSize) * 1.2
	const lines = Math.max(1, Math.round(box(name).height / lineHeight))
	const text = name.textContent ?? ''

	const buttonBoxes = [...actions.querySelectorAll('button')].map((button) => ({
		label: button.getAttribute('aria-label') ?? button.textContent?.trim() ?? '',
		...box(button),
	}))
	const actionBox = box(actions)
	const itemBox = box(item)

	const chain = []
	let node = list.parentElement
	for (let depth = 0; node && depth < 6; depth += 1) {
		const style = getComputedStyle(node)
		chain.push({
			tag: node.tagName.toLowerCase(),
			test: node.getAttribute('data-test'),
			width: Math.round(node.getBoundingClientRect().width * 10) / 10,
			display: style.display,
			gridTemplateColumns: style.gridTemplateColumns,
			paddingInlineStart: style.paddingInlineStart,
			paddingInlineEnd: style.paddingInlineEnd,
			minWidth: style.minWidth,
		})
		node = node.parentElement
	}

	return {
		found: true,
		direction: getComputedStyle(list).direction,
		nameText: text,
		nameLength: text.length,
		lines,
		lineRects,
		style: {
			overflowWrap: nameStyle.overflowWrap,
			wordBreak: nameStyle.wordBreak,
			hyphens: nameStyle.hyphens,
			fontSize: nameStyle.fontSize,
			lineHeight: nameStyle.lineHeight,
			itemDisplay: itemStyle.display,
			itemFlexWrap: itemStyle.flexWrap,
			bodyMinWidth: bodyStyle.minWidth,
			bodyFlex: bodyStyle.flex,
			actionsFlexWrap: actionsStyle.flexWrap,
			actionsFlexShrink: actionsStyle.flexShrink,
			actionsWidth: actionsStyle.width,
			actionsMinWidth: actionsStyle.minWidth,
		},
		boxes: {
			list: box(list),
			item: itemBox,
			icon: box(icon),
			body: box(body),
			name: box(name),
			meta: box(meta),
			actions: actionBox,
			buttons: buttonBoxes,
		},
		overflow: {
			documentScrollWidth: document.documentElement.scrollWidth,
			documentClientWidth: document.documentElement.clientWidth,
			bodyScrollWidth: document.body.scrollWidth,
			innerWidth: window.innerWidth,
			listScrollWidth: list.scrollWidth,
			listClientWidth: list.clientWidth,
			itemScrollWidth: item.scrollWidth,
			itemClientWidth: item.clientWidth,
			actionsOverflowRow: actionBox ? actionBox.right > itemBox.right + 1 || actionBox.left < itemBox.left - 1 : null,
			anyButtonOutsideRow: buttonBoxes.some((button) => button.right > itemBox.right + 1 || button.left < itemBox.left - 1),
			anyButtonOutsideViewport: buttonBoxes.some((button) => button.right > window.innerWidth || button.left < 0),
		},
		ancestors: chain,
	}
}

const results = {
	task: 'bug-assessment:mobile-evidence-filename-wrap',
	base,
	viewport: VIEWPORT,
	browser: null,
	language: { before: null, during: null, restored: false },
	runs: [],
	errors: [],
}

let browser = null
try {
	results.language.before = getUserLanguage(user)
	browser = await chromium.launch({ channel: 'chrome' })
	results.browser = await browser.version()

	for (const language of ['en', 'fa']) {
		setUserLanguage(user, language)
		results.language.during = language

		const context = await browser.newContext({ viewport: VIEWPORT, locale: language === 'fa' ? 'fa-IR' : 'en-US' })
		const page = await context.newPage()
		page.on('pageerror', (error) => results.errors.push(error.message))
		try {
			await page.goto(`${base}/login`, { waitUntil: 'networkidle' })
			await page.fill('input[name="user"]', user)
			await page.fill('input[name="password"]', password)
			await Promise.all([
				page.waitForLoadState('networkidle'),
				page.click('button[type="submit"]'),
			])
			if (new URL(page.url()).pathname.startsWith('/login')) throw new Error(`login failed for ${user}`)

			const read = async (path) => {
				const result = await page.evaluate(async (ocsPath) => {
					const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1${ocsPath}`, {
						headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
					})
					return { status: response.status, json: await response.json().catch(() => null) }
				}, path)
				if (result.status !== 200) throw new Error(`OCS ${path} answered ${result.status}`)
				return result.json.ocs.data
			}

			const sheets = await read('/sheets?page=1&pageSize=200')
			const sheet = (sheets.items ?? []).find((entry) => entry.title === 'DEV T060 fixture · 30')
			const detail = await read(`/sheets/${sheet.id}`)
			const draft = (detail.transactions ?? []).find((entry) => entry.activeRevision?.description === 'T060 receipt fixture 30 · transaction 01')

			await page.goto(`${base}/apps/pettycash/?bugnav=${language}${Date.now()}#/sheets/${sheet.id}`, { waitUntil: 'networkidle' })
			const row = page.locator('[data-test="sheet-transaction-row"]:visible, [data-test^="sheet-transaction-card-"]:visible', { hasText: 'T060 receipt fixture 30 · transaction 01' }).first()
			await row.locator('[data-test="view-transaction"]').click()
			await page.locator('[data-test="transaction-form-view"]').waitFor({ state: 'visible' })
			await page.locator('[data-test="edit-evidence-list"]').scrollIntoViewIfNeeded()
			await page.waitForTimeout(150)
			const editor = await page.evaluate(MEASURE)
			results.runs.push({ language, host: 'transaction editing form', surfaceDir: await page.locator('[data-test="transaction-surface-layer"]').getAttribute('dir'), measurement: editor })

			await page.goto(`${base}/apps/pettycash/?bugdetail=${language}${Date.now()}#/transactions/${draft.id}`, { waitUntil: 'networkidle' })
			await page.locator('[data-test="transaction-detail-view"]').waitFor({ state: 'visible' })
			await page.locator('.evidence-list[data-test="evidence-list"]').scrollIntoViewIfNeeded()
			await page.waitForTimeout(150)
			const detailMeasure = await page.evaluate(MEASURE)
			results.runs.push({ language, host: 'transaction detail view', surfaceDir: await page.locator('[data-test="transaction-detail-view"]').getAttribute('dir'), measurement: detailMeasure })
		} catch (error) {
			results.errors.push(`${language}: ${error.message}`)
		} finally {
			await context.close().catch(() => undefined)
		}
	}
} finally {
	if (browser) await browser.close().catch(() => undefined)
	if (results.language.before) {
		setUserLanguage(user, results.language.before)
		results.language.restored = getUserLanguage(user) === results.language.before
	}
	const serialized = JSON.stringify(results, null, 2)
	writeFileSync('/home/ubuntu/projects/pettycash/.specify/bugs/mobile-evidence-filename-wrap/repro-390.json', serialized)
	console.log(serialized)
}


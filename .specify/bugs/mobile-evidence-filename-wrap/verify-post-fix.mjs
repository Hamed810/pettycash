/**
 * Post-fix verification for bug `mobile-evidence-filename-wrap`.
 *
 * Measures the rendered geometry of the shared EvidenceList in both hosts that render it:
 *   1. the transaction editing form (the T075 surface, where T077's screenshot showed the defect);
 *   2. the transaction detail view (the read surface).
 *
 * Runs in official Google Chrome via Playwright `channel: 'chrome'` at two viewports:
 *   - 390x844  — the defect's reproduction size, in English LTR and Persian RTL;
 *   - 1440x900 — the desktop regression size, asserting the mobile rule did not leak upward.
 *
 * Read-only: no upload, removal, edit, save, or fixture reseed. The T060 fixture draft is only read.
 * The account language is restored in `finally` and the restoration is asserted.
 *
 * Evidence goes to `artifacts/visual-review/t078/iteration-1/`; measurements are written next to
 * this file as `verify-post-fix.json`.
 */

import { chromium } from '@playwright/test'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { getUserLanguage, setUserLanguage } from '/home/ubuntu/projects/pettycash/tests/E2E/t060-fixtures.mjs'

const base = process.env.PETTYCASH_BASE_URL ?? 'http://localhost:8081'
const ARTIFACTS = '/home/ubuntu/projects/pettycash/artifacts/visual-review/t078/iteration-1'
const OUTPUT = '/home/ubuntu/projects/pettycash/.specify/bugs/mobile-evidence-filename-wrap/verify-post-fix.json'

const SCENARIOS = [
	{ id: 'mobile', viewport: { width: 390, height: 844 } },
	{ id: 'desktop', viewport: { width: 1440, height: 900 } },
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
if (!user || !password) throw new Error('missing E2E credentials')

const MEASURE = () => {
	const px = (value) => Number.parseFloat(value) || 0
	const round = (value) => Math.round(value * 10) / 10
	const box = (element) => {
		if (!element) return null
		const rect = element.getBoundingClientRect()
		return {
			x: round(rect.x),
			y: round(rect.y),
			width: round(rect.width),
			height: round(rect.height),
			right: round(rect.right),
			bottom: round(rect.bottom),
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
	const deactivation = item.querySelector('[data-test="deactivation-info"]')
	const badge = item.querySelector('[data-test="evidence-active-badge"]')

	const nameStyle = getComputedStyle(name)
	const bodyStyle = getComputedStyle(body)
	const actionsStyle = getComputedStyle(actions)
	const itemStyle = getComputedStyle(item)

	const range = document.createRange()
	range.selectNodeContents(name)
	const lineRects = [...range.getClientRects()]
		.filter((rect) => rect.width > 0 && rect.height > 0)
		.map((rect) => round(rect.width))

	const lineHeight = px(nameStyle.lineHeight) || px(nameStyle.fontSize) * 1.2
	const lines = Math.max(1, Math.round(box(name).height / lineHeight))
	const text = name.textContent ?? ''

	const buttonBoxes = [...actions.querySelectorAll('button')].map((button) => ({
		label: button.getAttribute('aria-label') ?? button.textContent?.trim() ?? '',
		...box(button),
	}))

	// DOM order is what grid auto-placement depends on: icon, body, actions.
	const children = [...item.children].map((child) => child.className)

	const itemBox = box(item)
	return {
		found: true,
		direction: getComputedStyle(list).direction,
		nameText: text,
		nameLength: text.length,
		lines,
		lineRects,
		childOrder: children,
		style: {
			overflowWrap: nameStyle.overflowWrap,
			fontSize: nameStyle.fontSize,
			lineHeight: nameStyle.lineHeight,
			itemDisplay: itemStyle.display,
			itemFlexWrap: itemStyle.flexWrap,
			itemGridTemplateColumns: itemStyle.gridTemplateColumns,
			itemAlignItems: itemStyle.alignItems,
			bodyMinWidth: bodyStyle.minWidth,
			bodyWidth: bodyStyle.width,
			actionsDisplay: actionsStyle.display,
			actionsGridColumn: actionsStyle.gridColumn,
			actionsJustifyContent: actionsStyle.justifyContent,
			actionsFlexWrap: actionsStyle.flexWrap,
		},
		boxes: {
			list: box(list),
			item: itemBox,
			icon: box(icon),
			body: box(body),
			name: box(name),
			meta: box(meta),
			deactivation: box(deactivation),
			badge: box(badge),
			actions: box(actions),
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
			actionsOverflowRow: box(actions)
				? box(actions).right > itemBox.right + 1 || box(actions).left < itemBox.left - 1
				: null,
			anyButtonOutsideRow: buttonBoxes.some(
				(button) => button.right > itemBox.right + 1 || button.left < itemBox.left - 1
			),
			anyButtonOutsideViewport: buttonBoxes.some(
				(button) => button.right > window.innerWidth || button.left < 0
			),
		},
	}
}

const results = {
	task: 'bug-fix:mobile-evidence-filename-wrap',
	base,
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

	for (const scenario of SCENARIOS) {
		mkdirSync(`${ARTIFACTS}/${scenario.id}`, { recursive: true })

		for (const language of ['en', 'fa']) {
			setUserLanguage(user, language)
			results.language.during = language

			const context = await browser.newContext({
				viewport: scenario.viewport,
				locale: language === 'fa' ? 'fa-IR' : 'en-US',
			})
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
				const draft = (detail.transactions ?? []).find(
					(entry) => entry.activeRevision?.description === 'T060 receipt fixture 30 · transaction 01'
				)

				// Host 1: transaction editing form.
				await page.goto(`${base}/apps/pettycash/?fixnav=${language}${Date.now()}#/sheets/${sheet.id}`, {
					waitUntil: 'networkidle',
				})
				const row = page
					.locator(
						'[data-test="sheet-transaction-row"]:visible, [data-test^="sheet-transaction-card-"]:visible',
						{ hasText: 'T060 receipt fixture 30 · transaction 01' }
					)
					.first()
				await row.locator('[data-test="view-transaction"]').click()
				await page.locator('[data-test="transaction-form-view"]').waitFor({ state: 'visible' })
				await page.locator('[data-test="edit-evidence-list"]').scrollIntoViewIfNeeded()
				await page.waitForTimeout(150)
				const editorList = page.locator('.evidence-list[data-test="evidence-list"]')
				results.runs.push({
					scenario: scenario.id,
					viewport: scenario.viewport,
					language,
					host: 'transaction editing form',
					surfaceDir: await page.locator('[data-test="transaction-surface-layer"]').getAttribute('dir'),
					measurement: await page.evaluate(MEASURE),
				})
				await editorList.screenshot({
					path: `${ARTIFACTS}/${scenario.id}/01-edit-evidence-${language}-${scenario.viewport.width}.png`,
				})

				// Host 2: transaction detail view.
				await page.goto(
					`${base}/apps/pettycash/?fixdetail=${language}${Date.now()}#/transactions/${draft.id}`,
					{ waitUntil: 'networkidle' }
				)
				await page.locator('[data-test="transaction-detail-view"]').waitFor({ state: 'visible' })
				await page.locator('.evidence-list[data-test="evidence-list"]').scrollIntoViewIfNeeded()
				await page.waitForTimeout(150)
				const detailList = page.locator('.evidence-list[data-test="evidence-list"]')
				results.runs.push({
					scenario: scenario.id,
					viewport: scenario.viewport,
					language,
					host: 'transaction detail view',
					surfaceDir: await page.locator('[data-test="transaction-detail-view"]').getAttribute('dir'),
					measurement: await page.evaluate(MEASURE),
				})
				await detailList.screenshot({
					path: `${ARTIFACTS}/${scenario.id}/02-detail-evidence-${language}-${scenario.viewport.width}.png`,
				})
			} catch (error) {
				results.errors.push(`${scenario.id}/${language}: ${error.message}`)
			} finally {
				await context.close().catch(() => undefined)
			}
		}
	}
} finally {
	if (browser) await browser.close().catch(() => undefined)
	if (results.language.before) {
		setUserLanguage(user, results.language.before)
		results.language.restored = getUserLanguage(user) === results.language.before
	}
	const serialized = JSON.stringify(results, null, 2)
	writeFileSync(OUTPUT, serialized)
	console.log(serialized)
}

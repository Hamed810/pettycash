/**
 * Post-fix check for the T076 regression boundary: the lifecycle chip on a submitted transaction.
 *
 * At mobile width the chip now shares the full-width action row with preview/download/remove.
 * If that cluster were wider than the row it would wrap, which is acceptable but must be visible
 * in the evidence rather than assumed. This measures every evidence row of a correction-stage
 * fixture at 390x844 in English LTR and Persian RTL.
 *
 * Read-only. Language is restored in `finally`.
 */

import { chromium } from '@playwright/test'
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { getUserLanguage, setUserLanguage } from '/home/ubuntu/projects/pettycash/tests/E2E/t060-fixtures.mjs'

const base = process.env.PETTYCASH_BASE_URL ?? 'http://localhost:8081'
const TRANSACTION_ID = process.env.PETTYCASH_LIFECYCLE_TX ?? '3e330f00-38c0-4f80-98d7-f5cd27011c67'
const ARTIFACTS = '/home/ubuntu/projects/pettycash/artifacts/visual-review/t078/iteration-1/mobile'
const OUTPUT = '/home/ubuntu/projects/pettycash/.specify/bugs/mobile-evidence-filename-wrap/verify-lifecycle-chip.json'

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

const MEASURE_ROWS = () => {
	const round = (value) => Math.round(value * 10) / 10
	const px = (value) => Number.parseFloat(value) || 0
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
	if (!list) return { found: false, rows: [] }

	const rows = [...list.querySelectorAll('[data-test="evidence-list-item"]')].map((item) => {
		const name = item.querySelector('[data-test="evidence-name"]')
		const body = item.querySelector('.evidence-item__body')
		const actions = item.querySelector('.evidence-item__actions')
		const chip = item.querySelector('[data-test="evidence-active-badge"]')
		const deactivation = item.querySelector('[data-test="deactivation-info"]')
		const nameStyle = getComputedStyle(name)
		const itemStyle = getComputedStyle(item)
		const lineHeight = px(nameStyle.lineHeight) || px(nameStyle.fontSize) * 1.2
		const itemBox = box(item)
		const actionsBox = box(actions)
		const buttons = [...actions.querySelectorAll('button')].map((button) => ({
			label: button.getAttribute('aria-label') ?? '',
			...box(button),
		}))

		return {
			name: name.textContent ?? '',
			lines: Math.max(1, Math.round(box(name).height / lineHeight)),
			direction: getComputedStyle(list).direction,
			itemDisplay: itemStyle.display,
			boxes: {
				item: itemBox,
				body: box(body),
				name: box(name),
				actions: actionsBox,
				chip: box(chip),
				deactivation: box(deactivation),
				buttons,
			},
			chip: chip ? chip.textContent?.trim() ?? '' : null,
			deactivationText: deactivation?.textContent?.trim() ?? null,
			actionsWrapped: actionsBox ? actionsBox.height > 45 : null,
			anyButtonOutsideRow: buttons.some(
				(button) => button.right > itemBox.right + 1 || button.left < itemBox.left - 1
			),
			anyButtonOutsideViewport: buttons.some(
				(button) => button.right > window.innerWidth || button.left < 0
			),
		}
	})

	return {
		found: true,
		direction: getComputedStyle(list).direction,
		list: box(list),
		documentScrollWidth: document.documentElement.scrollWidth,
		documentClientWidth: document.documentElement.clientWidth,
		rows,
	}
}

const results = {
	task: 'bug-fix:mobile-evidence-filename-wrap:lifecycle-chip',
	transactionId: TRANSACTION_ID,
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
	mkdirSync(ARTIFACTS, { recursive: true })

	for (const language of ['en', 'fa']) {
		setUserLanguage(user, language)
		results.language.during = language

		const context = await browser.newContext({
			viewport: { width: 390, height: 844 },
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
			if (new URL(page.url()).pathname.startsWith('/login')) throw new Error('login failed')

			await page.goto(
				`${base}/apps/pettycash/?lifecycle=${language}${Date.now()}#/transactions/${TRANSACTION_ID}`,
				{ waitUntil: 'networkidle' }
			)
			await page.locator('[data-test="transaction-detail-view"]').waitFor({ state: 'visible' })
			await page.locator('.evidence-list[data-test="evidence-list"]').scrollIntoViewIfNeeded()
			await page.waitForTimeout(150)

			const measurement = await page.evaluate(MEASURE_ROWS)
			results.runs.push({
				language,
				surfaceDir: await page.locator('[data-test="transaction-detail-view"]').getAttribute('dir'),
				measurement,
			})
			await page.locator('.evidence-list[data-test="evidence-list"]').screenshot({
				path: `${ARTIFACTS}/03-lifecycle-chip-${language}-390.png`,
			})

			// No fixture has an inactive row, and a submitted DRAFT on a non-OPEN sheet would render
			// chip + preview + download + remove (the widest cluster the component can produce). That
			// case does not exist in the lab, so it is measured by cloning the existing download
			// button inside this page only. No application state, data, or DOM on disk changes.
			const worstCase = await page.evaluate(() => {
				const item = document.querySelector('[data-test="evidence-list-item"]')
				const actions = item?.querySelector('.evidence-item__actions')
				const download = actions?.querySelector('[data-test="download-evidence-btn"]')
				if (!item || !actions || !download) return null

				const clone = download.cloneNode(true)
				actions.appendChild(clone)

				const round = (value) => Math.round(value * 10) / 10
				const box = (element) => {
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
				const itemBox = box(item)
				const actionsBox = box(actions)
				const buttons = [...actions.querySelectorAll('button')]

				return {
					synthetic: true,
					note: 'a third action button was cloned in the browser only; no persisted state changed',
					item: itemBox,
					actions: actionsBox,
					buttonCount: buttons.length,
					actionsWrapped: actionsBox.height > 45,
					itemScrollWidth: item.scrollWidth,
					itemClientWidth: item.clientWidth,
					actionsOverflowRow:
						actionsBox.right > itemBox.right + 1 || actionsBox.left < itemBox.left - 1,
					anyButtonOutsideRow: buttons.some(
						(button) => box(button).right > itemBox.right + 1 || box(button).left < itemBox.left - 1
					),
					anyButtonOutsideViewport: buttons.some(
						(button) => box(button).right > window.innerWidth || box(button).left < 0
					),
				}
			})
			results.runs[results.runs.length - 1].worstCase = worstCase
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
	writeFileSync(OUTPUT, serialized)
	console.log(serialized)
}

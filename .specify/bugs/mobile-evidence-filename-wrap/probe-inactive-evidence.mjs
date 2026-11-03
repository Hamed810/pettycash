/** Read-only probe: find a transaction whose detail payload contains an inactive evidence row. */

import { chromium } from '@playwright/test'
import { existsSync, readFileSync } from 'node:fs'

const base = process.env.PETTYCASH_BASE_URL ?? 'http://localhost:8081'

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
let browser = null
try {
	browser = await chromium.launch({ channel: 'chrome' })
	const context = await browser.newContext({ viewport: { width: 390, height: 844 } })
	const page = await context.newPage()
	try {
		await page.goto(`${base}/login`, { waitUntil: 'networkidle' })
		await page.fill('input[name="user"]', env.PETTYCASH_E2E_USER)
		await page.fill('input[name="password"]', env.PETTYCASH_E2E_PASSWORD)
		await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type="submit"]')])
		if (new URL(page.url()).pathname.startsWith('/login')) throw new Error('login failed')

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
		const candidates = []
		for (const sheet of sheets.items ?? []) {
			const sheetDetail = await read(`/sheets/${sheet.id}`)
			for (const transaction of sheetDetail.transactions ?? []) {
				if ((transaction.evidenceCount ?? 0) === 0) continue
				const detail = await read(`/transactions/${transaction.id}`)
				const evidence = detail.evidence ?? []
				if (!evidence.some((item) => !item.active)) continue
				candidates.push({
					sheetId: sheet.id,
					sheetTitle: sheet.title,
					sheetStatus: sheet.status,
					transactionId: transaction.id,
					transactionStatus: detail.status ?? transaction.status,
					description: transaction.activeRevision?.description,
					evidence: evidence.map((item) => ({
						name: item.name,
						active: item.active,
						deactivatedAt: item.deactivatedAt,
					})),
				})
			}
		}
		console.log(JSON.stringify({ candidates }, null, 2))
	} finally {
		await context.close().catch(() => undefined)
	}
} finally {
	if (browser) await browser.close().catch(() => undefined)
}

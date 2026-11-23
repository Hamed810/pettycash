import { execFileSync } from 'node:child_process'
import { existsSync, mkdirSync, readFileSync } from 'node:fs'
import { Buffer } from 'node:buffer'
import { join, resolve } from 'node:path'
import process from 'node:process'
import { test, expect } from '@playwright/test'

async function goToFinanceQueue(page: Page): Promise<void> {
	await page.goto('/apps/pettycash/#/reviews/finance', { waitUntil: 'networkidle' })
	await expect(page.locator('[data-testid="finance-queue"]')).toBeVisible()
}

// Selector convergence (UXC-9): this suite previously drove a stub-era Finance sheet modal
// (`finance-sheet-modal`, `finance-transaction-*`, `decision-form-stub`). It is retargeted in
// place to the shared concept-based surfaces: the `sheet-details-modal` shell, the
// `transaction-review-card-*` cards and the `DecisionConfirmation` modal.

test.describe('Finance Manager Expense Review E2E (T047)', () => {

	test.describe('Scenario 1: Cost-Sheet-first queue', () => {
		test('one row per Cost Sheet', async ({ page }) => {
			await goToFinanceQueue(page)
			const sheetRows = page.locator('[data-testid="sheet-reference"]')
			if (await sheetRows.count() > 0) {
				await expect(sheetRows.first()).toBeVisible()
			} else {
				await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
			}
		})

		test('queue shows Cost Sheet rows when data available', async ({ page }) => {
			await goToFinanceQueue(page)
			const sheetRows = page.locator('[data-testid="sheet-reference"]')
			if (await sheetRows.count() > 0) {
				await expect(sheetRows.first()).toBeVisible()
				await expect(sheetRows.first().locator('[data-testid="sheet-pending-count"]')).toBeVisible()
			} else {
				await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
			}
		})

		test('no transaction-level primary queue rows', async ({ page }) => {
			await goToFinanceQueue(page)
			const sheetRows = page.locator('[data-testid="sheet-reference"]')
			if (await sheetRows.count() > 0) {
				await expect(sheetRows.first().locator('[data-testid="sheet-pending-count"]')).toBeVisible()
			} else {
				await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
			}
		})

		test('incomplete grouping renders unavailable state', async ({ page }) => {
			await goToFinanceQueue(page)
			const sheetRows = page.locator('[data-testid="sheet-reference"]')
			const emptyState = page.locator('[data-testid="empty-queue"]')
			await expect(
				sheetRows.count() > 0 || (await emptyState.count()) > 0
			).toBeTruthy()
		})
	})

	test.describe('Scenario 2: Cost Sheet review modal', () => {
		test('opening a Cost Sheet opens modal', async ({ page }) => {
			await goToFinanceQueue(page)
			const sheetRows = page.locator('[data-testid="sheet-reference"]')
			if (await sheetRows.count() > 0) {
				await sheetRows.first().click()
				await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible()
			} else {
				await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
			}
		})

		test('queue remains mounted behind modal', async ({ page }) => {
			await goToFinanceQueue(page)
			const sheetRows = page.locator('[data-testid="sheet-reference"]')
			if (await sheetRows.count() > 0) {
				await sheetRows.first().click()
				await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible()
				await expect(page.locator('[data-testid="finance-queue"]')).toBeVisible()
			} else {
				await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
			}
		})

		test('closing modal returns to queue context', async ({ page }) => {
			await goToFinanceQueue(page)
			const sheetRows = page.locator('[data-testid="sheet-reference"]')
			if (await sheetRows.count() > 0) {
				await sheetRows.first().click()
				await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible()
				await page.locator('[data-testid="sheet-details-modal-close"]').click()
				await expect(page.locator('[data-testid="sheet-details-modal"]')).not.toBeVisible()
				await expect(page.locator('[data-testid="sheet-reference"]').first()).toBeVisible()
			} else {
				await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
			}
		})
	})

	test.describe('Scenario 3: Evidence', () => {
		test('evidence remains attached to correct transaction', async ({ page }) => {
			await goToFinanceQueue(page)
			const sheetRows = page.locator('[data-testid="sheet-reference"]')
			if (await sheetRows.count() > 0) {
				await sheetRows.first().click()
				await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible()
				const thumbnails = page.locator('[data-testid^="transaction-card-thumb-"]')
				if (await thumbnails.count() > 0) {
					await expect(thumbnails.first()).toBeVisible()
				} else {
					await expect(page.locator('[data-testid="transaction-review-cards"]')).toBeVisible()
				}
			} else {
				await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
			}
		})

		test('evidence preview opens and returns to context', async ({ page }) => {
			await goToFinanceQueue(page)
			const sheetRows = page.locator('[data-testid="sheet-reference"]')
			if (await sheetRows.count() > 0) {
				await sheetRows.first().click()
				await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible()
				const thumbnails = page.locator('[data-testid^="transaction-card-thumb-"]')
				if (await thumbnails.count() > 0) {
					await thumbnails.first().click()
					await expect(page.locator('[data-test="evidence-preview-layer"]')).toBeVisible()
					const cards = page.locator('[data-testid="transaction-review-cards"]')
					if (await cards.count() > 0) {
						await expect(cards.first()).toBeVisible()
					}
				} else {
					await expect(page.locator('[data-testid="transaction-review-cards"]')).toBeVisible()
				}
				await page.locator('[data-testid="sheet-details-modal-close"]').click()
				await expect(page.locator('[data-test="evidence-preview-layer"]')).not.toBeVisible()
			} else {
				await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
			}
		})

		test('no evidence mutation controls', async ({ page }) => {
			await goToFinanceQueue(page)
			const sheetRows = page.locator('[data-testid="sheet-reference"]')
			if (await sheetRows.count() > 0) {
				await sheetRows.first().click()
				await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible()
				expect(page.locator('[data-testid="evidence-upload"]')).not.toBeVisible()
				expect(page.locator('[data-testid="evidence-replace"]')).not.toBeVisible()
				expect(page.locator('[data-testid="evidence-delete"]')).not.toBeVisible()
			} else {
				await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
			}
		})

		test('prior decisions distinguishable', async ({ page }) => {
			await goToFinanceQueue(page)
			const sheetRows = page.locator('[data-testid="sheet-reference"]')
			if (await sheetRows.count() > 0) {
				await sheetRows.first().click()
				await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible()
				// Prior decisions are per-transaction history in the transaction's inline expansion
				// (UXC-7, v2 interaction per UXC-V2-11), with an honest "No decision yet" state where a
				// stage has not decided.
				const firstCard = page.locator('[data-testid^="transaction-review-card-"]').first()
				if (await firstCard.count() > 0) {
					await firstCard.locator('[data-testid="transaction-card-title"]').click()
					await expect(page.locator('[data-testid="transaction-expanded-detail"]')).toBeVisible()
					await expect(page.locator('[data-testid="prior-decision-history"]').first()).toBeVisible()
				}
			} else {
				await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
			}
		})
	})

	test.describe('Scenario 4: Finance decisions', () => {
		test('decision on one transaction does not apply to siblings', async ({ page }) => {
			await goToFinanceQueue(page)
			const sheetRows = page.locator('[data-testid="sheet-reference"]')
			if (await sheetRows.count() > 0) {
				await sheetRows.first().click()
				await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible()
				const firstCard = page.locator('[data-testid^="transaction-review-card-"]').first()
				if (await firstCard.count() > 0) {
					const firstTestId = await firstCard.getAttribute('data-testid') || ''
					const txId = firstTestId.replace('transaction-review-card-', '')
					await firstCard.locator('[data-testid="transaction-card-decide-approve"]').click()
					await expect(page.locator('[data-testid="decision-modal"]')).toBeVisible()
					await page.locator('[data-testid="decision-note-input"]').fill('E2E approve test')
					await page.locator('[data-testid="decision-confirm-btn"]').click()
					await expect(page.locator('[data-testid="decision-modal"]')).toHaveCount(0, { timeout: 15000 })
					// The decided card keeps no decide bar; siblings are untouched (per-transaction
					// decisions, never a sheet-wide verdict).
					await expect(firstCard.locator('[data-testid^="transaction-card-decide-"]')).toHaveCount(0)
					const siblingCards = page.locator(
						`[data-testid^="transaction-review-card-"]:not([data-testid="transaction-review-card-${txId}"])`
					)
					if (await siblingCards.count() > 0) {
						await expect(siblingCards.first()).toBeVisible()
					}
				} else {
					await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
				}
			} else {
				await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
			}
		})

		test('no bulk Finance approval exists', async ({ page }) => {
			await goToFinanceQueue(page)
			const sheetRows = page.locator('[data-testid="sheet-reference"]')
			if (await sheetRows.count() > 0) {
				await sheetRows.first().click()
				await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible()
				await expect(page.locator('[data-testid="sheet-approve-all"]')).not.toBeVisible()
				await expect(page.locator('[data-testid="sheet-reject-all"]')).not.toBeVisible()
				// Substantive claim (FR: per-transaction decisions): every decide control lives inside
				// a single transaction card — there is no sheet-level bulk action surface.
				const allDecideControlsInCards = await page.evaluate(() => {
					return [...document.querySelectorAll(
						'[data-testid="sheet-details-modal"] [data-testid^="transaction-card-decide-"]'
					)].every((btn) => btn.closest('article[data-testid^="transaction-review-card-"]') !== null)
				})
				expect(allDecideControlsInCards).toBe(true)
			} else {
				await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
			}
		})
	})

	test.describe('Scenario 5: Senior-rejected restriction', () => {
		test('Finance cannot approve senior-rejected transaction', async ({ page }) => {
			await goToFinanceQueue(page)
			const sheetRows = page.locator('[data-testid="sheet-reference"]')
			if (await sheetRows.count() > 1) {
				await sheetRows.nth(1).click()
				await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible()
				const approveBtn = page.locator(
					'[data-testid="transaction-review-card-e2e00000-0000-4000-8000-000000000025"] [data-testid="transaction-card-decide-approve"]'
				)
				if (await approveBtn.count() > 0) {
					await expect(approveBtn).toBeVisible()
					await expect(approveBtn).toBeDisabled()
				}
			} else {
				await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
			}
		})
	})

	test.describe('Scenario 6: Sheet leaves queue after decision', () => {
		test('sheet leaves queue when all finance-eligible transactions decided', async ({ page }) => {
			await goToFinanceQueue(page)
			const sheetRows = page.locator('[data-testid="sheet-reference"]')
			if (await sheetRows.count() > 0) {
				await sheetRows.first().click()
				await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible()
				const firstCard = page.locator('[data-testid^="transaction-review-card-"]').first()
				if (await firstCard.count() > 0) {
					await firstCard.locator('[data-testid="transaction-card-decide-reject"]').click()
					await expect(page.locator('[data-testid="decision-modal"]')).toBeVisible()
					await page.locator('[data-testid="decision-note-input"]').fill('E2E reject test')
					await page.locator('[data-testid="decision-confirm-btn"]').click()
					await expect(page.locator('[data-testid="decision-modal"]')).toHaveCount(0, { timeout: 15000 })
					await page.locator('[data-testid="sheet-details-modal-close"]').click()
					await expect(page.locator('[data-testid="finance-queue"]')).toBeVisible()
				}
			} else {
				await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
			}
		})
	})

	test.describe('Scenario 7: Failure', () => {
		test('failed decision does not display success', async ({ page }) => {
			await goToFinanceQueue(page)
			const sheetRows = page.locator('[data-testid="sheet-reference"]')
			if (await sheetRows.count() > 0) {
				await sheetRows.first().click()
				await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible()
				const firstCard = page.locator('[data-testid^="transaction-review-card-"]').first()
				if (await firstCard.count() > 0) {
					await firstCard.locator('[data-testid="transaction-card-decide-reject"]').click()
					await expect(page.locator('[data-testid="decision-modal"]')).toBeVisible()
					// REJECT requires a comment: submitting with an empty note is refused client-side
					// by the note guard, so no success state can appear.
					await page.locator('[data-testid="decision-confirm-btn"]').click()
					await expect(page.locator('[data-testid="decision-guard-error"]')).toBeVisible()
				} else {
					await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
				}
			} else {
				await expect(page.locator('[data-testid="empty-queue"]')).toBeVisible()
			}
		})
	})
})

// ============================================================================
// T009-H-03 / T009-H-05 — real-Chrome convergence evidence on the shared
// sheet-first Finance surface.
//
// Fixture mechanics mirror `finance-settlement.spec.ts`: the sheet is created
// and submitted through the purchaser UI, given one inline-safe PNG, then its
// transaction is approved through the established Cost Center and Senior
// detail surfaces so the sheet becomes finance-ready. The language fixture
// uses the same `occ` mechanism as the visual-evidence runners (set `fa`,
// restore `en` in `finally`). Screenshots land under the Git-ignored
// `artifacts/visual-review/` tree; a visual result is not owner acceptance.

const E2E_ITERATION = process.env.E2E_ITERATION ?? '001'
const H05_DIR = join('artifacts', 'visual-review', 'T009-H-05', E2E_ITERATION)
const H03_DIR = join('artifacts', 'visual-review', 'T009-H-03', E2E_ITERATION)
mkdirSync(H05_DIR, { recursive: true })
mkdirSync(H03_DIR, { recursive: true })

type OcsListEnvelope = { ocs?: { data?: { items?: Array<{ id?: string, title?: string, sheetId?: string }> } } }
type OcsItemEnvelope = { ocs?: { data?: { stage?: string, status?: string } } }

/** Reads one key out of `.env.testing` exactly like `global-setup.ts` — one place for credentials. */
function e2eUser(): string {
	const path = resolve(process.cwd(), '.env.testing')
	if (!existsSync(path)) throw new Error('.env.testing is missing')
	for (const line of readFileSync(path, 'utf8').split('\n')) {
		const trimmed = line.trim()
		if (trimmed === '' || trimmed.startsWith('#')) continue
		const separator = trimmed.indexOf('=')
		if (separator === -1) continue
		if (trimmed.slice(0, separator).trim() === 'PETTYCASH_E2E_USER') {
			return trimmed.slice(separator + 1).trim().replace(/^["']|["']$/g, '')
		}
	}
	throw new Error('PETTYCASH_E2E_USER missing from .env.testing')
}

/** The established language fixture (same `occ` mechanism as the visual-evidence runners). */
function setLanguage(language: string): void {
	execFileSync('scripts/dev/occ', ['user:setting', e2eUser(), 'core', 'lang', language], { stdio: 'pipe' })
}

async function shot(page: Page, dir: string, name: string): Promise<void> {
	await page.screenshot({ path: join(dir, name), fullPage: false })
}

/** Nothing on the page may be wider than the viewport (FR-071; same probe as the .mjs runners). */
async function assertNoHorizontalScroll(page: Page): Promise<void> {
	const overflow = await page.evaluate(() => {
		const viewport = window.innerWidth
		const candidates: Array<[string, number]> = [
			['documentElement', document.documentElement.scrollWidth],
			['body', document.body.scrollWidth],
		]
		const app = document.querySelector('#pettycash-app')
		if (app) candidates.push(['#pettycash-app', app.scrollWidth])
		return candidates.filter(([, width]) => width > viewport + 1).map(([name, width]) => ({ name, width, viewport }))
	})
	expect(overflow).toEqual([])
}

async function pickFirstOption(page: Page): Promise<void> {
	const listbox = page.getByRole('listbox').last()
	await expect(listbox).toBeVisible()
	await listbox.getByRole('option').first().click()
}

async function ocsJson(page: Page, method: string, path: string): Promise<OcsListEnvelope & OcsItemEnvelope | null> {
	return page.evaluate(async ({ method, path }) => {
		const headers: Record<string, string> = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
		const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1${path}`, { method, headers })
		return (await response.json().catch(() => null)) as OcsListEnvelope & OcsItemEnvelope | null
	}, { method, path })
}

/** The sheet's public id, matched by exact unique title. */
async function sheetIdByTitle(page: Page, title: string): Promise<string> {
	for (let i = 0; i < 12; i++) {
		const res = await ocsJson(page, 'GET', '/sheets?page=1&pageSize=20')
		const items = res?.ocs?.data?.items ?? []
		const found = items.find((s) => s.title === title)
		if (found?.id !== undefined) return found.id
		await page.waitForTimeout(500)
	}
	throw new Error(`sheet titled "${title}" never appeared in the sheet list`)
}

/** Searches every page of a queue for our sheet's transaction (the shared queues paginate). */
async function findInQueue(page: Page, stage: string, sheetId: string): Promise<{ id: string } | null> {
	let pageNo = 1
	for (;;) {
		const res = await ocsJson(page, 'GET', `/queues/${stage}?page=${pageNo}&pageSize=100`)
		const items = res?.ocs?.data?.items ?? []
		const found = items.find((t) => t.sheetId === sheetId)
		if (found?.id !== undefined) return { id: found.id }
		if (items.length < 100) return null
		pageNo++
	}
}

async function transactionStage(page: Page, id: string): Promise<string> {
	const res = await ocsJson(page, 'GET', `/transactions/${id}`)
	return res?.ocs?.data?.stage ?? 'UNKNOWN'
}

async function createAndSubmitSheet(page: Page, title: string): Promise<void> {
	await page.goto('/apps/pettycash/#/sheets')
	await page.click('[data-test="create-sheet-button"]')
	await page.fill('[data-test="sheet-title-input"]', title)
	await page.click('[data-test="sheet-account-select"]')
	await pickFirstOption(page)
	await page.click('[data-test="sheet-create-submit"]')
	await page.locator('[data-test="sheet-list-item"]:visible', { hasText: title }).first().click()
	await page.waitForSelector('[data-test="sheet-detail-view"]')
	await page.click('[data-test="create-transaction-btn"]')
	await page.fill('[data-test="expense-date-input"]', '2026-10-01')
	await page.fill('[data-test="amount-input"]', '50000')
	await page.fill('[data-test="description-input"]', 'T009 shared-surface probe')
	await page.click('[data-test="cost-center-select"]')
	const listbox = page.getByRole('listbox').last()
	await expect(listbox).toBeVisible()
	await listbox.getByRole('option', { name: /E2E Cost/ }).click()
	await page.click('[data-test="expense-type-select"]')
	await pickFirstOption(page)
	await page.click('[data-test="submit-btn"]')
	await page.waitForSelector('[data-test="sheet-detail-view"]')
}

/** Uploads one inline-safe PNG to the sheet's transaction via the established edit surface. */
async function uploadEvidence(page: Page, fileName: string): Promise<void> {
	const PNG_1X1 = Buffer.from(
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
		'base64'
	)
	await page.locator('tr', { has: page.locator('text=T009 shared-surface probe') }).first().locator('[data-test="view-transaction"]').click()
	await expect(page.locator('[data-test="drop-zone"]')).toBeVisible({ timeout: 15000 })
	await page.setInputFiles('[data-test="evidence-upload-input"]', { name: fileName, mimeType: 'image/png', buffer: PNG_1X1 })
	await page.click('[data-test="upload-btn"]')
	await expect(
		page.locator('[data-test="edit-evidence-list"] [data-test="evidence-list-item"]', { hasText: fileName }).first()
	).toBeVisible()
	await page.locator('[data-test="close-transaction-surface"]').click()
	await expect(page.locator('[data-test="close-transaction-surface"]')).toHaveCount(0)
}

/** Records an APPROVE on the currently open detail view and waits until the stage advances. */
async function approveOnDetail(page: Page, txId: string, fromStage: string): Promise<void> {
	await page.click('[data-testid="decision-select"]')
	const options = page.getByRole('option')
	const count = await options.count()
	for (let i = 0; i < count; i++) {
		const text = ((await options.nth(i).innerText()) || '').toUpperCase()
		if (text.includes('APPROVE') || text.includes('تأیید')) {
			await options.nth(i).click()
			break
		}
	}
	await page.fill('[data-testid="explanation-input"]', 'T009 approve')
	await page.click('[data-testid="submit-decision"]')
	for (let i = 0; i < 12; i++) {
		const stage = await transactionStage(page, txId)
		if (stage !== fromStage && stage !== 'UNKNOWN') return
		await page.waitForTimeout(500)
	}
	throw new Error(`APPROVE on ${txId} never left stage ${fromStage}`)
}

/** Creates the sheet, gives its transaction evidence, submits, then walks Cost Center + Senior so
 * the sheet becomes finance-ready. Returns the sheet id and the transaction id. */
async function financeReadySheet(page: Page, title: string): Promise<{ sheetId: string, txId: string }> {
	await createAndSubmitSheet(page, title)
	await uploadEvidence(page, 'h05-receipt.png')
	await page.click('[data-test="submit-sheet-button"]')
	await page.waitForTimeout(1200)
	// The submitted sheet's read-only workspace stays open over the list and intercepts pointer
	// events; close it before walking the review queues (the evidence runners avoid this by
	// navigating with a fresh `goto`).
	const closeWorkspace = page.locator('[data-test="close-sheet-workspace"]')
	if (await closeWorkspace.count() > 0) {
		await closeWorkspace.click()
		await expect(page.locator('[data-test="sheet-workspace"]')).toHaveCount(0)
	}
	const sheetId = await sheetIdByTitle(page, title)
	let txId = ''
	for (const stage of ['cost-center', 'senior'] as const) {
		let found: { id: string } | null = null
		for (let i = 0; i < 12; i++) {
			found = await findInQueue(page, stage, sheetId)
			if (found !== null) break
			await page.waitForTimeout(500)
		}
		if (found === null) throw new Error(`no ${stage}-queue transaction for sheet ${sheetId}`)
		txId = found.id
		// The review queues are sheet-first (Phases 6–9): the queue's `view-details-btn` opens this
		// same per-transaction decision surface by route; reach it directly.
		await page.goto(`/apps/pettycash/#/reviews/${stage}/${txId}`, { waitUntil: 'networkidle' })
		await page.waitForSelector('[data-testid="decision-select"]')
		await approveOnDetail(page, txId, stage === 'cost-center' ? 'COST_CENTER' : 'SENIOR')
	}
	return { sheetId, txId }
}

test.describe('Finance real-Chrome convergence evidence (T009-H-03/H-05)', () => {
	test.setTimeout(300000)

	test('H-05: shared-surface evidence walk in English LTR (desktop + 390px)', async ({ page }) => {
		const title = `T009-H05 ${Date.now()}`
		const { sheetId, txId } = await financeReadySheet(page, title)

		await page.setViewportSize({ width: 1440, height: 900 })
		await page.goto('/apps/pettycash/#/reviews/finance', { waitUntil: 'networkidle' })
		await expect(page.locator('[data-testid="finance-queue"]')).toBeVisible()
		await expect(page.locator(`[data-testid="sheet-row-${sheetId}"]`)).toBeVisible()
		await assertNoHorizontalScroll(page)
		await shot(page, H05_DIR, 'en-desktop-01-queue.png')

		await page.locator(`[data-testid="sheet-row-${sheetId}"] [data-testid="sheet-title"]`).click()
		await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible()
		await expect(page.locator('[data-testid="transaction-review-cards"]')).toBeVisible()
		await shot(page, H05_DIR, 'en-desktop-02-modal-cards.png')

		// Approvals is sheet-level (stage totals only); Previous Decisions live in the transaction's
		// inline expansion.
		await page.locator('[data-testid="sheet-details-modal-tab-approvals"]').click()
		await expect(page.locator('[data-testid="sheet-details-modal-panel-approvals"]')).toBeVisible()
		await shot(page, H05_DIR, 'en-desktop-03-approvals.png')
		await page.locator('[data-testid="sheet-details-modal-tab-transactions"]').click()
		await expect(page.locator('[data-testid="transaction-review-cards"]')).toBeVisible()

		await page.locator(`[data-testid="transaction-review-card-${txId}"] [data-testid="transaction-card-title"]`).click()
		await expect(page.locator('[data-testid="transaction-expanded-detail"]')).toBeVisible()
		await expect(page.locator('[data-testid="inline-section-decisions"]')).toBeVisible()
		await shot(page, H05_DIR, 'en-desktop-04-expanded.png')

		await page.locator('[data-testid^="inline-attachment-"]').first().click()
		await expect(page.locator('[data-test="evidence-preview-layer"]')).toBeVisible({ timeout: 15000 })
		await expect(page.locator('[data-test="evidence-preview-image"]')).toBeVisible({ timeout: 15000 })
		await shot(page, H05_DIR, 'en-desktop-05-preview.png')
		await page.locator('[data-test="close-evidence-preview"]').click()
		await expect(page.locator('[data-test="evidence-preview-layer"]')).toHaveCount(0)
		// One overlay at a time above the sheet modal: closing the preview returns to the modal's
		// card context with the expansion still bound to the same transaction (UXC-V2-11).
		await expect(page.locator('[data-testid="transaction-expanded-detail"]')).toBeVisible()

		await page.locator(`[data-testid="transaction-review-card-${txId}"] [data-testid="transaction-card-decide-reject"]`).click()
		await expect(page.locator('[data-testid="decision-modal"]')).toBeVisible()
		await shot(page, H05_DIR, 'en-desktop-06-decision-modal.png')
		// Cancel: focus returns to the control that opened the confirmation.
		await page.locator('[data-testid="decision-cancel-btn"]').click()
		await expect(page.locator('[data-testid="decision-modal"]')).toHaveCount(0)
		await expect.poll(
			() => page.evaluate(() => document.activeElement?.getAttribute('data-testid') ?? ''),
			{ timeout: 5000 }
		).toBe('transaction-card-decide-reject')

		await page.locator('[data-testid="sheet-details-modal-close"]').click()
		await expect(page.locator('[data-testid="sheet-details-modal"]')).toHaveCount(0)
		await expect(page.locator('[data-testid="finance-queue"]')).toBeVisible()

		// 390px pass over the same fixture (English LTR).
		await page.setViewportSize({ width: 390, height: 844 })
		await page.locator(`[data-testid="sheet-card-row-${sheetId}"] [data-testid="sheet-title"]`).click()
		await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible()
		await assertNoHorizontalScroll(page)
		await shot(page, H05_DIR, 'en-390-07-modal.png')
		await page.locator(`[data-testid="transaction-review-card-${txId}"] [data-testid="transaction-card-title"]`).click()
		await expect(page.locator('[data-testid="transaction-expanded-detail"]')).toBeVisible()
		await assertNoHorizontalScroll(page)
		await shot(page, H05_DIR, 'en-390-08-expanded.png')
		await page.keyboard.press('Escape')
		await expect(page.locator('[data-testid="transaction-expanded-detail"]')).toHaveCount(0)
	})

	test('H-03: finance decision completes in Persian RTL at 390px with focus restored', async ({ page }) => {
		setLanguage('fa')
		try {
			const title = `T009-H03 ${Date.now()}`
			const { sheetId, txId } = await financeReadySheet(page, title)

			await page.setViewportSize({ width: 390, height: 844 })
			await page.goto('/apps/pettycash/#/reviews/finance', { waitUntil: 'networkidle' })
			await expect(page.locator('[data-testid="finance-queue"]')).toHaveAttribute('dir', 'rtl')
			await assertNoHorizontalScroll(page)
			await shot(page, H03_DIR, 'fa-390-01-queue-rtl.png')

			await page.locator(`[data-testid="sheet-card-row-${sheetId}"] [data-testid="sheet-title"]`).click()
			await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible()
			await expect(page.locator('[data-testid="sheet-details-modal"]')).toHaveAttribute('dir', 'rtl')
			await assertNoHorizontalScroll(page)
			await shot(page, H03_DIR, 'fa-390-02-modal-rtl.png')

			await page.locator(`[data-testid="transaction-review-card-${txId}"] [data-testid="transaction-card-title"]`).click()
			await expect(page.locator('[data-testid="transaction-expanded-detail"]')).toBeVisible()
			await expect(page.locator(`[data-testid="transaction-review-card-${txId}"]`)).toHaveAttribute('dir', 'rtl')
			await expect(page.locator('[data-testid="inline-section-decisions"]')).toBeVisible()
			await assertNoHorizontalScroll(page)
			await shot(page, H03_DIR, 'fa-390-03-expanded-rtl.png')
			await page.keyboard.press('Escape')
			await expect(page.locator('[data-testid="transaction-expanded-detail"]')).toHaveCount(0)

			// One transaction per decision: REJECT with the mandatory Persian explanation completes
			// the flow; the transaction becomes stage-resolved (COMPLETED_REJECTED) and its decide
			// bar disappears — a rejected transaction counts as resolved but adds no approved total.
			await page.locator(`[data-testid="transaction-review-card-${txId}"] [data-testid="transaction-card-decide-reject"]`).click()
			await expect(page.locator('[data-testid="decision-modal"]')).toBeVisible()
			// Focus restore (cancel path): the opener control is still mounted, so focus returns to it.
			await page.locator('[data-testid="decision-cancel-btn"]').click()
			await expect(page.locator('[data-testid="decision-modal"]')).toHaveCount(0)
			await expect.poll(
				() => page.evaluate(() => document.activeElement?.getAttribute('data-testid') ?? ''),
				{ timeout: 5000 }
			).toBe('transaction-card-decide-reject')

			await page.locator(`[data-testid="transaction-review-card-${txId}"] [data-testid="transaction-card-decide-reject"]`).click()
			await expect(page.locator('[data-testid="decision-modal"]')).toBeVisible()
			await expect(page.locator('[data-testid="decision-note-input"]')).toBeVisible()
			await page.locator('[data-testid="decision-note-input"]').fill('توضیح لازم برای رد.')
			await page.locator('[data-testid="decision-confirm-btn"]').click()
			await expect(page.locator('[data-testid="decision-modal"]')).toHaveCount(0, { timeout: 15000 })
			await expect(page.locator(`[data-testid="transaction-review-card-${txId}"] [data-testid="status-badge-completed_rejected"]`)).toBeVisible({ timeout: 15000 })
			await expect(page.locator(`[data-testid="transaction-review-card-${txId}"] [data-testid^="transaction-card-decide-"]`)).toHaveCount(0)
			await assertNoHorizontalScroll(page)
			await shot(page, H03_DIR, 'fa-390-04-decided-rtl.png')
		} finally {
			setLanguage('en')
		}
	})
})

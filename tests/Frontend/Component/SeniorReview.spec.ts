// Senior Review - Vue Component Tests (Feature 007, User Story 1)
//
// US1: the Senior Manager sees a Cost-Sheet-based queue read from `GET /queues/senior/sheets`
// (including completed Finance-stage sheets). One sheet row per sheet, honest loading / empty /
// unavailable (with retry) states, "Review Completed" only when the server supplies it, and a sheet
// modal that opens over the still-mounted queue and restores focus to the originating row on close
// (FR-007-001/002/009/014/016/017; owners D1/D5/D8; UX-007-07).
//
// The harness is the same one `ManagementReview.spec.ts` uses, for the same reasons: the view calls
// `useRouter()` in `setup()` and renders a `RouterLink`, so a router is required; the transport is
// stubbed at the axios boundary so a store method this file did not think to stub cannot reach the
// network and hang the run. The Senior sheet modal is a teleported overlay, so modal interactions
// query `document.body` and each test clears the body afterwards.

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createRouter, createWebHashHistory, type Router } from 'vue-router'
import SeniorQueueView from '@/views/reviews/SeniorQueueView.vue'
import { useReviewsStore } from '@/stores/reviews'
import { routes } from '@/router'
import type { Decision, SeniorSheetListRow, TransactionSummary } from '@/types/api'

const ocsRequest = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@nextcloud/l10n')>()
	return { ...actual, getLanguage: () => 'fa', isRTL: () => true }
})

vi.mock('@nextcloud/axios', () => ({
	default: {
		request: (...args: unknown[]) => ocsRequest(...args),
		isAxiosError: (error: unknown) => Boolean((error as { isAxiosError?: boolean } | null)?.isAxiosError)
	}
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => `/ocs/v2.php${path}`,
	imagePath: (path: string) => `/apps/pettycash/${path}`,
	appName: 'pettycash',
	appVersion: '1.0.0'
}))

/** A well-formed but empty OCS envelope, so a mount-time load resolves instead of rejecting. */
function emptyOcsResponse(config: { url?: string }) {
	const url = config?.url ?? ''
	const data = /\/revisions(\?|$)/.test(url) ? [] : { items: [], page: 1, pageSize: 25, total: 0 }
	return { status: 200, data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data } } }
}

function createTestRouter(): Router {
	return createRouter({
		history: createWebHashHistory(),
		routes: routes.map(({ path, name, props }) => ({
			path,
			name,
			props,
			component: { template: '<div />' }
		}))
	})
}

const COST_CENTER_APPROVAL: Decision = {
	id: 'd-1', stage: 'COST_CENTER', decision: 'APPROVE',
	actor: { userId: 'mgr1', displayName: 'Manager 1', role: 'COST_CENTER_MANAGER' },
	decidedAt: '2026-09-20T10:00:00Z'
}

/** One `GET /queues/senior/sheets` row, with the server-supplied identity/status the view renders. */
function seniorSheetRow(id: string, overrides: Partial<SeniorSheetListRow> = {}): SeniorSheetListRow {
	return {
		id,
		title: `Sheet ${id}`,
		ownerId: 'user1',
		ownerDisplayName: 'User One',
		currency: 'IRR',
		submittedTotalMinor: 50000,
		submittedTotal: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
		submittedAt: '2026-09-20T10:00:00Z',
		version: 1,
		allTransactionsCostCenterDecided: true,
		reviewStatus: 'PENDING_REVIEW',
		latestActivityAt: '2026-09-20T10:00:00Z',
		progress: { awaitingSenior: 1, decidedBySenior: 0, total: 1 },
		...overrides,
	}
}

function seniorTransaction(id: string, overrides: Partial<TransactionSummary> = {}): TransactionSummary {
	return {
		id, sheetId: 'sheet-1', stage: 'SENIOR', status: 'AWAITING_SENIOR_REVIEW',
		finalOutcome: null, evidenceRequired: false, evidenceCount: 1, version: 0,
		latestDecision: COST_CENTER_APPROVAL,
		activeRevision: {
			id: `rev-${id}`, number: 1, expenseDate: '2026-09-20',
			amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
			description: `Expense ${id}`,
			costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center' },
			expenseType: { id: 'et-1', name: 'Travel' },
			createdBy: 'user1', createdAt: '2026-09-20T10:00:00Z', active: true
		},
		...overrides,
	}
}

describe('SeniorQueueView (US1)', () => {
	let pinia: Pinia
	let router: Router

	beforeEach(() => {
		pinia = createPinia()
		router = createTestRouter()
		setActivePinia(pinia)
		ocsRequest.mockReset()
		ocsRequest.mockImplementation((config: { url?: string }) =>
			// The per-sheet read is not needed to render the queue; tests that open the modal either
			// supply it explicitly or assert the honest unavailable state.
			/\/queues\/senior\/sheets\//.test(config?.url ?? '')
				? Promise.reject(new Error('senior sheet read unavailable'))
				: Promise.resolve(emptyOcsResponse(config))
		)
	})

	afterEach(() => {
		// The sheet modal is a teleported overlay; clear the body so it cannot leak into the next test.
		document.body.innerHTML = ''
	})

	async function mountSeniorQueue(): Promise<VueWrapper> {
		await router.push('/reviews/senior')
		await router.isReady()
		// Attached to the document so focus restore from the teleported modal can be verified.
		const wrapper = mount(SeniorQueueView, {
			global: { plugins: [pinia, router] },
			attachTo: document.body
		})
		await flushPromises()
		return wrapper
	}

	function seedSeniorQueue(items: SeniorSheetListRow[], overrides: Record<string, unknown> = {}) {
		const reviewsStore = useReviewsStore()
		reviewsStore.seniorQueue = { items, total: items.length, page: 1, pageSize: 100, ...overrides } as never
		reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
		return reviewsStore
	}

	/** One per-sheet read (`GET /queues/senior/sheets/{id}`) with the acting senior's rows. */
	function mockSeniorSheetReview(
		sheetId: string,
		transactions: Array<TransactionSummary & { seniorState: 'AWAITING_SENIOR_DECISION' | 'DECIDED_BY_SENIOR'; seniorDecision: Decision | null }>,
		sheetOverrides: Partial<SeniorSheetListRow> = {}
	) {
		ocsRequest.mockImplementation((config: { url?: string }) => {
			const url = config?.url ?? ''
			if (url.includes(`/queues/senior/sheets/${sheetId}`)) {
				return Promise.resolve({
					status: 200,
					data: {
						ocs: {
							meta: { status: 'ok', statuscode: 200 },
							data: {
								sheet: seniorSheetRow(sheetId, sheetOverrides),
								progress: {
									awaitingSenior: transactions.filter((row) => row.seniorState === 'AWAITING_SENIOR_DECISION').length,
									decidedBySenior: transactions.filter((row) => row.seniorState === 'DECIDED_BY_SENIOR').length,
									total: transactions.length,
								},
								transactions,
							}
						}
					}
				})
			}
			return Promise.resolve(emptyOcsResponse(config))
		})
	}

	it('renders one sheet row per server sheet-list row, with the server pending count', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-1', { progress: { awaitingSenior: 2, decidedBySenior: 0, total: 2 } }),
			seniorSheetRow('sheet-2', { progress: { awaitingSenior: 1, decidedBySenior: 0, total: 1 } }),
		])
		const wrapper = await mountSeniorQueue()

		// FR-007-001: one row per sheet, never a flat transaction table. `ResponsiveTable` emits the
		// per-field hooks in BOTH layouts (desktop `<tr>` + narrow card), so counts are scoped to the
		// table rows (`senior-sheet-row-`, the card carries the distinct `senior-sheet-card-` prefix).
		expect(wrapper.findAll('[data-testid^="senior-sheet-row-"]')).toHaveLength(2)
		expect(wrapper.findAll('[data-testid^="senior-sheet-row-"] [data-testid="sheet-pending-count"]')).toHaveLength(2)
		// The locale is Persian, so the rendered digits are Persian.
		expect(wrapper.find('[data-testid="senior-sheet-row-sheet-1"] [data-testid="sheet-pending-count"]').text().trim())
			.toBe('۲')
		expect(wrapper.find('[data-testid="senior-sheet-row-sheet-2"] [data-testid="sheet-pending-count"]').text().trim())
			.toBe('۱')
		// No flat transaction rows appear on the queue surface at all.
		expect(wrapper.findAll('[data-testid^="queue-row-"]')).toHaveLength(0)
	})

	it('counts only sheets with transactions awaiting this senior, excluding completed and returned sheets', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-awaiting', {
				progress: { awaitingSenior: 2, decidedBySenior: 0, total: 2 },
			}),
			// Returned by this senior: still PENDING_REVIEW but nothing awaits a senior decision.
			seniorSheetRow('sheet-returned', {
				progress: { awaitingSenior: 0, decidedBySenior: 1, total: 1 },
			}),
			// Completed rows stay in the table but never inflate the awaiting stats.
			seniorSheetRow('sheet-completed', {
				reviewStatus: 'REVIEW_COMPLETED',
				progress: { awaitingSenior: 0, decidedBySenior: 5, total: 5 },
			}),
		])
		const wrapper = await mountSeniorQueue()

		const values = wrapper.findAll('[data-testid="senior-stats"] .stat__value')
		expect(values).toHaveLength(2)
		// One sheet awaits a decision; two transactions await across it. The returned and completed
		// rows are present in the table but excluded from both figures.
		expect(values[0]?.text().trim()).toBe('۱')
		expect(values[1]?.text().trim()).toBe('۲')
		expect(wrapper.findAll('[data-testid^="senior-sheet-row-"]')).toHaveLength(3)
	})

	it('renders the server submission snapshot per currency and never a cross-currency total (006 FR-006-014)', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-irr', {
				submittedTotal: { amountMinor: 75000, currency: 'IRR', minorUnit: 0 },
			}),
			seniorSheetRow('sheet-usd', {
				currency: 'USD',
				submittedTotal: { amountMinor: 1250, currency: 'USD', minorUnit: 2 },
			}),
		])
		const wrapper = await mountSeniorQueue()

		// Scoped to the table rows: `ResponsiveTable` renders each cell hook in both the desktop `<tr>`
		// and the narrow card, so an unscoped count would double (2 rows × 2 layouts).
		const moneys = wrapper.findAll('[data-testid^="senior-sheet-row-"] [data-testid="sheet-amount"] .sheet-row__money')
		expect(moneys).toHaveLength(2)
		// Each per-currency snapshot is rendered, so both money cells are non-empty.
		expect(moneys.every((cell) => cell.text().trim() !== '')).toBe(true)
	})

	it('renders only the server sheet-list set and never a "Waiting for Cost Center Review" label (FR-007-013/016)', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-1'),
			seniorSheetRow('sheet-2'),
		])
		const wrapper = await mountSeniorQueue()

		expect(wrapper.findAll('[data-testid^="senior-sheet-row-"]')).toHaveLength(2)
		expect(wrapper.text().toLowerCase()).not.toContain('waiting for cost center review')
		// The manager's scope is the queue's own data: no transaction row for any other scope appears.
		expect(wrapper.findAll('[data-testid^="queue-row-"]')).toHaveLength(0)
	})

	it('renders an honest unavailable state with a retry when the set cannot be proven complete (owner D8)', async () => {
		seedSeniorQueue([seniorSheetRow('sheet-1')], { total: 999 })
		const wrapper = await mountSeniorQueue()

		expect(wrapper.find('[data-testid="grouping-unavailable"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="grouping-unavailable"]').text()).toContain('reviews.seniorSheet.unavailable.title')
		// No partial sheet rows are shown from an incomplete set — never a partial flat table either.
		expect(wrapper.findAll('[data-testid^="senior-sheet-row-"]')).toHaveLength(0)
		expect(wrapper.findAll('[data-testid^="queue-row-"]')).toHaveLength(0)
	})

	it('renders an empty state when the server proves the senior sheet list empty', async () => {
		seedSeniorQueue([])
		const wrapper = await mountSeniorQueue()

		expect(wrapper.find('[data-testid="empty-queue"]').exists()).toBe(true)
		expect(wrapper.findAll('[data-testid^="senior-sheet-row-"]')).toHaveLength(0)
	})

	it('renders a server-supplied "Review Completed" sheet from the list row, read-only and non-actionable (FR-007-017, owner D5)', async () => {
		// The completed Finance-stage sheet is present in the list itself; the queue must not need a
		// per-sheet prefetch to know its status or identity (UX-007-07).
		seedSeniorQueue([
			seniorSheetRow('sheet-1', {
				title: 'Completed Finance Sheet',
				reviewStatus: 'REVIEW_COMPLETED',
				progress: { awaitingSenior: 0, decidedBySenior: 2, total: 2 },
			}),
		])
		const wrapper = await mountSeniorQueue()

		expect(wrapper.find('[data-testid="review-completed-badge"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="review-pending-badge"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="senior-sheet-row-sheet-1"] [data-testid="sheet-pending-count"]').text().trim())
			.toBe('۰')

		// Read-only path: the completed sheet stays reachable through its title — a real, ENABLED
		// button (never a misleading disabled appearance) that opens the modal. The per-sheet read is
		// unavailable in this test, so the modal falls back to the list row's identity and says the
		// transaction read failed instead of pretending the sheet is empty.
		const completedTitle = wrapper.find('[data-testid="senior-sheet-row-sheet-1"] [data-testid="sheet-title"]')
		expect(completedTitle.exists()).toBe(true)
		expect(completedTitle.attributes('disabled')).toBeUndefined()
		await completedTitle.trigger('click')
		await flushPromises()
		expect(document.body.querySelector('[data-testid="senior-modal-dialog"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="senior-modal-title"]')?.textContent?.trim())
			.toBe('Completed Finance Sheet')
		expect(document.body.querySelector('[data-testid="senior-modal-unavailable"]')).not.toBeNull()
		expect(wrapper.find('[data-testid="open-sheet-btn"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="readonly-note"]').exists()).toBe(false)
	})

	it('opens the sheet modal over the still-mounted queue and restores focus to the originating row on close (FR-007-002/009)', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-1'),
			seniorSheetRow('sheet-2'),
		])
		mockSeniorSheetReview('sheet-2', [
			{ ...seniorTransaction('tx-2', { sheetId: 'sheet-2' }), seniorState: 'AWAITING_SENIOR_DECISION', seniorDecision: null },
		])
		const wrapper = await mountSeniorQueue()

		const secondRow = wrapper.find('[data-testid="senior-sheet-row-sheet-2"]')
		await secondRow.find('[data-testid="sheet-title"]').trigger('click')
		await flushPromises()

		// The queue stays mounted behind the single overlay.
		expect(wrapper.find('[data-testid="senior-queue"]').exists()).toBe(true)
		const dialog = document.body.querySelector('[data-testid="senior-modal-dialog"]')
		expect(dialog).not.toBeNull()
		expect(dialog?.getAttribute('role')).toBe('dialog')
		expect(dialog?.getAttribute('aria-modal')).toBe('true')
		// The per-sheet read supplies the acting senior's transaction set.
		expect(dialog?.querySelector('[data-testid="senior-modal-transactions"]')).not.toBeNull()
		expect(dialog?.querySelector('[data-testid="quick-review-card-tx-2"]')).not.toBeNull()

		const closeBtn = document.body.querySelector<HTMLElement>('[data-testid="senior-modal-close"]')
		expect(closeBtn).not.toBeNull()
		closeBtn?.click()
		await flushPromises()

		expect(document.body.querySelector('[data-testid="senior-sheet-modal"]')).toBeNull()
		expect(document.activeElement?.closest('[data-testid^="senior-sheet-row-"]')?.getAttribute('data-testid'))
			.toBe('senior-sheet-row-sheet-2')
	})

	it('removes the Actions column, the dedicated open button and the header role badge (mission UX corrections)', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-1'),
			seniorSheetRow('sheet-2'),
		])
		const wrapper = await mountSeniorQueue()

		// No Actions column in either layout (the desktop `<td>` hook would be `senior-queue-cell-actions`).
		expect(wrapper.find('[data-testid="senior-queue-cell-actions"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="senior-queue-card-field-actions"]').exists()).toBe(false)
		// No dedicated per-row "open sheet" button and no read-only note anywhere.
		expect(wrapper.find('[data-testid="open-sheet-btn"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="readonly-note"]').exists()).toBe(false)
		// The redundant header badge is gone; Refresh and the page title remain.
		expect(wrapper.find('[data-testid="role-badge"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="refresh-btn"]').exists()).toBe(true)
		// The title is the primary (still enabled) interaction for a pending row.
		expect(wrapper.find('[data-testid="senior-sheet-row-sheet-1"] [data-testid="sheet-title"]').exists()).toBe(true)
	})

	/** The visible sheet ids in table-row order (card rows carry a distinct prefix and are ignored). */
	function rowSheetIds(wrapper: VueWrapper): string[] {
		return wrapper.findAll('[data-testid^="senior-sheet-row-"]')
			.map((row) => (row.attributes('data-testid') ?? '').replace('senior-sheet-row-', ''))
	}

	it('sorts sheets by pending-transaction count, toggling direction on repeat activation', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-a', { progress: { awaitingSenior: 3, decidedBySenior: 0, total: 3 } }),
			seniorSheetRow('sheet-b', { progress: { awaitingSenior: 1, decidedBySenior: 0, total: 1 } }),
			seniorSheetRow('sheet-c', { progress: { awaitingSenior: 2, decidedBySenior: 0, total: 2 } }),
		])
		const wrapper = await mountSeniorQueue()

		// Insertion (server) order before any sort: a(3), b(1), c(2).
		expect(rowSheetIds(wrapper)).toEqual(['sheet-a', 'sheet-b', 'sheet-c'])

		const header = wrapper.find('[data-testid="senior-queue-sort-pendingCount"]')
		expect(header.exists()).toBe(true)
		expect(header.attributes('type')).toBe('button')

		// Ascending: numeric order by count, 1 → 2 → 3.
		await header.trigger('click')
		expect(rowSheetIds(wrapper)).toEqual(['sheet-b', 'sheet-c', 'sheet-a'])
		const th = header.element.closest('th')
		expect(th?.getAttribute('aria-sort')).toBe('ascending')

		// Repeat activation toggles to descending.
		await header.trigger('click')
		expect(rowSheetIds(wrapper)).toEqual(['sheet-a', 'sheet-c', 'sheet-b'])
		expect(th?.getAttribute('aria-sort')).toBe('descending')
	})

	it('sorts by sheet title with locale-aware comparison, newest direction last', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-charlie', { title: 'Charlie' }),
			seniorSheetRow('sheet-alpha', { title: 'Alpha' }),
			seniorSheetRow('sheet-bravo', { title: 'Bravo' }),
		])
		const wrapper = await mountSeniorQueue()

		await wrapper.find('[data-testid="senior-queue-sort-reference"]').trigger('click')
		expect(rowSheetIds(wrapper)).toEqual(['sheet-alpha', 'sheet-bravo', 'sheet-charlie'])
		await wrapper.find('[data-testid="senior-queue-sort-reference"]').trigger('click')
		expect(rowSheetIds(wrapper)).toEqual(['sheet-charlie', 'sheet-bravo', 'sheet-alpha'])
	})

	it('sorts by latest activity chronologically (ISO date order, not display strings)', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-old', { latestActivityAt: '2026-09-20T10:00:00Z' }),
			seniorSheetRow('sheet-mid', { latestActivityAt: '2026-09-22T10:00:00Z' }),
			seniorSheetRow('sheet-new', { latestActivityAt: '2026-09-25T10:00:00Z' }),
		])
		const wrapper = await mountSeniorQueue()

		await wrapper.find('[data-testid="senior-queue-sort-latestActivity"]').trigger('click')
		expect(rowSheetIds(wrapper)).toEqual(['sheet-old', 'sheet-mid', 'sheet-new'])
	})

	it('sorts amounts currency-first: currency groups are stable and amounts never mix across currencies', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-irr-big', {
				submittedTotal: { amountMinor: 100000, currency: 'IRR', minorUnit: 0 },
			}),
			seniorSheetRow('sheet-usd', {
				currency: 'USD',
				submittedTotal: { amountMinor: 2000, currency: 'USD', minorUnit: 2 },
			}),
			seniorSheetRow('sheet-irr-small', {
				submittedTotal: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
			}),
		])
		const wrapper = await mountSeniorQueue()

		await wrapper.find('[data-testid="senior-queue-sort-amount"]').trigger('click')
		// Currency-first, deterministic: the IRR group (50000 before 100000) precedes the USD row —
		// USD's 2000 is never numerically compared against IRR minor units.
		expect(rowSheetIds(wrapper)).toEqual(['sheet-irr-small', 'sheet-irr-big', 'sheet-usd'])
	})

	it('sorts status deterministically: pending before completed (mission deterministic order)', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-a', { reviewStatus: 'REVIEW_COMPLETED' }),
			seniorSheetRow('sheet-b'),
			seniorSheetRow('sheet-c'),
		])
		const wrapper = await mountSeniorQueue()

		await wrapper.find('[data-testid="senior-queue-sort-status"]').trigger('click')
		// Pending sheets first (b, c — tie broken deterministically by reference), completed last.
		expect(rowSheetIds(wrapper)).toEqual(['sheet-b', 'sheet-c', 'sheet-a'])
		await wrapper.find('[data-testid="senior-queue-sort-status"]').trigger('click')
		expect(rowSheetIds(wrapper)).toEqual(['sheet-a', 'sheet-b', 'sheet-c'])
	})

	it('keeps search and status tabs functional ahead of the sort (search → filter → sort)', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-alpha', { title: 'Alpha' }),
			seniorSheetRow('sheet-beta', { title: 'Beta', reviewStatus: 'REVIEW_COMPLETED' }),
			seniorSheetRow('sheet-gamma', { title: 'Gamma' }),
		])
		const wrapper = await mountSeniorQueue()

		// Ascending by title across the full set first.
		await wrapper.find('[data-testid="senior-queue-sort-reference"]').trigger('click')
		expect(rowSheetIds(wrapper)).toEqual(['sheet-alpha', 'sheet-beta', 'sheet-gamma'])

		// The completed tab filters to Beta, and the sort then orders only that surviving row.
		await wrapper.find('[data-testid="senior-queue-tab-completed"]').trigger('click')
		expect(rowSheetIds(wrapper)).toEqual(['sheet-beta'])

		// Back to "all", then a search term narrows the set the active sort reorders.
		await wrapper.find('[data-testid="senior-queue-tab-all"]').trigger('click')
		const search = wrapper.find('[data-testid="senior-queue-search"]')
		await search.setValue('amma')
		await flushPromises()
		expect(rowSheetIds(wrapper)).toEqual(['sheet-gamma'])
	})
})

describe('reviews store senior queue source (UX-007-07)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		ocsRequest.mockReset()
	})

	it('reads every page of the sheet-list endpoint with the contract maximum page size', async () => {
		ocsRequest.mockImplementation((config: { params?: { page?: number; pageSize?: number } }) => {
			const page = config.params?.page ?? 1
			const items = page === 1
				? [seniorSheetRow('sheet-1'), seniorSheetRow('sheet-2')]
				: [seniorSheetRow('sheet-3', { reviewStatus: 'REVIEW_COMPLETED' })]
			return Promise.resolve({
				status: 200,
				data: {
					ocs: {
						meta: { status: 'ok', statuscode: 200 },
						data: { items, page, pageSize: 2, total: 3 }
					}
				}
			})
		})

		const reviewsStore = useReviewsStore()
		await reviewsStore.fetchSeniorQueue(2)

		// The queue no longer derives sheets from `/queues/senior` transactions; it reads the
		// sheet-list endpoint across pages so a completed sheet on a later page is not truncated.
		expect(ocsRequest).toHaveBeenCalledTimes(2)
		expect(ocsRequest).toHaveBeenNthCalledWith(1, expect.objectContaining({
			url: expect.stringContaining('/queues/senior/sheets'),
			params: { page: 1, pageSize: 2 },
		}))
		expect(ocsRequest).toHaveBeenNthCalledWith(2, expect.objectContaining({
			url: expect.stringContaining('/queues/senior/sheets'),
			params: { page: 2, pageSize: 2 },
		}))
		expect(reviewsStore.seniorQueue.items.map((row) => row.id)).toEqual(['sheet-1', 'sheet-2', 'sheet-3'])
		expect(reviewsStore.seniorQueue.total).toBe(3)
	})

	it('uses page size 100 by default, the contract maximum for the sheet-list endpoint', async () => {
		ocsRequest.mockResolvedValue({
			status: 200,
			data: {
				ocs: {
					meta: { status: 'ok', statuscode: 200 },
					data: { items: [seniorSheetRow('sheet-1')], page: 1, pageSize: 100, total: 1 }
				}
			}
		})

		const reviewsStore = useReviewsStore()
		await reviewsStore.fetchSeniorQueue()

		expect(ocsRequest).toHaveBeenCalledWith(expect.objectContaining({
			url: expect.stringContaining('/queues/senior/sheets'),
			params: { page: 1, pageSize: 100 },
		}))
	})
})

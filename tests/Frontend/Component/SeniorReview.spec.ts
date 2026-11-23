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
import { useAuthStore } from '@/stores/auth'
import { useConfigurationStore } from '@/stores/configuration'
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

function seniorTransaction(
	id: string,
	overrides: Omit<Partial<TransactionSummary>, 'activeRevision'> & { activeRevision?: Partial<import('@/types/api').Revision> | null } = {}
): TransactionSummary {
	const base = {
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
	}
	return {
		...base,
		...overrides,
		activeRevision: overrides.activeRevision
			? { ...base.activeRevision, ...overrides.activeRevision }
			: base.activeRevision,
	} as TransactionSummary
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

	async function mountLocalQueue(): Promise<VueWrapper> {
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
	function seedSheetReviews(
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
		const wrapper = await mountLocalQueue()

		// FR-007-001: one row per sheet, never a flat transaction table. `ResponsiveTable` emits the
		// per-field hooks in BOTH layouts (desktop `<tr>` + narrow card), so counts are scoped to the
		// table rows (`sheet-row-`, the card carries the distinct `sheet-card-row-` prefix).
		expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(2)
		expect(wrapper.findAll('[data-testid^="sheet-row-"] [data-testid="sheet-pending-count"]')).toHaveLength(2)
		// The locale is Persian, so the rendered digits are Persian.
		expect(wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-pending-count"]').text().trim())
			.toBe('۲')
		expect(wrapper.find('[data-testid="sheet-row-sheet-2"] [data-testid="sheet-pending-count"]').text().trim())
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
		const wrapper = await mountLocalQueue()

		const values = wrapper.findAll('[data-testid="senior-stats"] .stat__value')
		expect(values).toHaveLength(2)
		// One sheet awaits a decision; two transactions await across it. The returned and completed
		// rows are present in the table but excluded from both figures.
		expect(values[0]?.text().trim()).toBe('۱')
		expect(values[1]?.text().trim()).toBe('۲')
		expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(3)
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
		const wrapper = await mountLocalQueue()

		// Scoped to the table rows: `ResponsiveTable` renders each cell hook in both the desktop `<tr>`
		// and the narrow card, so an unscoped count would double (2 rows × 2 layouts).
		const moneys = wrapper.findAll('[data-testid^="sheet-row-"] [data-testid="sheet-amount"] .sheet-row__money')
		expect(moneys).toHaveLength(2)
		// Each per-currency snapshot is rendered, so both money cells are non-empty.
		expect(moneys.every((cell) => cell.text().trim() !== '')).toBe(true)
	})

	it('renders only the server sheet-list set and never a "Waiting for Cost Center Review" label (FR-007-013/016)', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-1'),
			seniorSheetRow('sheet-2'),
		])
		const wrapper = await mountLocalQueue()

		expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(2)
		expect(wrapper.text().toLowerCase()).not.toContain('waiting for cost center review')
		// The manager's scope is the queue's own data: no transaction row for any other scope appears.
		expect(wrapper.findAll('[data-testid^="queue-row-"]')).toHaveLength(0)
	})

	it('renders an honest unavailable state with a retry when the set cannot be proven complete (owner D8)', async () => {
		seedSeniorQueue([seniorSheetRow('sheet-1')], { total: 999 })
		const wrapper = await mountLocalQueue()

		expect(wrapper.find('[data-testid="grouping-unavailable"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="grouping-unavailable"]').text()).toContain('reviews.seniorSheet.unavailable.title')
		// No partial sheet rows are shown from an incomplete set — never a partial flat table either.
		expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(0)
		expect(wrapper.findAll('[data-testid^="queue-row-"]')).toHaveLength(0)
	})

	it('renders an empty state when the server proves the senior sheet list empty', async () => {
		seedSeniorQueue([])
		const wrapper = await mountLocalQueue()

		expect(wrapper.find('[data-testid="empty-queue"]').exists()).toBe(true)
		expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(0)
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
		const wrapper = await mountLocalQueue()

		expect(wrapper.find('[data-testid="sheet-status-badge"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-pending-count"]').text().trim())
			.toBe('۰')

		// Read-only path: the completed sheet stays reachable through its title — a real, ENABLED
		// button (never a misleading disabled appearance) that opens the modal. The per-sheet read is
		// unavailable in this test, so the modal falls back to the list row's identity and says the
		// transaction read failed instead of pretending the sheet is empty.
		const completedTitle = wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-title"]')
		expect(completedTitle.exists()).toBe(true)
		expect(completedTitle.attributes('disabled')).toBeUndefined()
		await completedTitle.trigger('click')
		await flushPromises()
		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="sheet-details-modal-title"]')?.textContent?.trim())
			.toBe('Completed Finance Sheet')
		expect(document.body.querySelector('[data-testid="sheet-details-modal-unavailable"]')).not.toBeNull()
		expect(wrapper.find('[data-testid="open-sheet-btn"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="readonly-note"]').exists()).toBe(false)
	})

	it('opens the sheet modal over the still-mounted queue and restores focus to the originating row on close (FR-007-002/009)', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-1'),
			seniorSheetRow('sheet-2'),
		])
		seedSheetReviews('sheet-2', [
			{ ...seniorTransaction('tx-2', { sheetId: 'sheet-2' }), seniorState: 'AWAITING_SENIOR_DECISION', seniorDecision: null },
		])
		const wrapper = await mountLocalQueue()

		const secondRow = wrapper.find('[data-testid="sheet-row-sheet-2"]')
		await secondRow.find('[data-testid="sheet-title"]').trigger('click')
		await flushPromises()

		// The queue stays mounted behind the single overlay.
		expect(wrapper.find('[data-testid="senior-queue"]').exists()).toBe(true)
		const dialog = document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')
		expect(dialog).not.toBeNull()
		expect(dialog?.getAttribute('role')).toBe('dialog')
		expect(dialog?.getAttribute('aria-modal')).toBe('true')
		// The per-sheet read supplies the acting senior's transaction set.
		expect(dialog?.querySelector('[data-testid="transaction-review-cards"]')).not.toBeNull()
		expect(dialog?.querySelector('[data-testid="transaction-review-card-tx-2"]')).not.toBeNull()

		const closeBtn = document.body.querySelector<HTMLElement>('[data-testid="sheet-details-modal-close"]')
		expect(closeBtn).not.toBeNull()
		closeBtn?.click()
		await flushPromises()

		expect(document.body.querySelector('[data-testid="sheet-details-modal"]')).toBeNull()
		expect(document.activeElement?.closest('[data-testid^="sheet-row-"]')?.getAttribute('data-testid'))
			.toBe('sheet-row-sheet-2')
	})

	it('removes the Actions column, the dedicated open button and the header role badge (mission UX corrections)', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-1'),
			seniorSheetRow('sheet-2'),
		])
		const wrapper = await mountLocalQueue()

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
		expect(wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-title"]').exists()).toBe(true)
	})

	/** The visible sheet ids in table-row order (card rows carry a distinct prefix and are ignored). */
	function rowSheetIds(wrapper: VueWrapper): string[] {
		return wrapper.findAll('[data-testid^="sheet-row-"]')
			.map((row) => (row.attributes('data-testid') ?? '').replace('sheet-row-', ''))
	}

	it('sorts sheets by pending-transaction count, toggling direction on repeat activation', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-a', { progress: { awaitingSenior: 3, decidedBySenior: 0, total: 3 } }),
			seniorSheetRow('sheet-b', { progress: { awaitingSenior: 1, decidedBySenior: 0, total: 1 } }),
			seniorSheetRow('sheet-c', { progress: { awaitingSenior: 2, decidedBySenior: 0, total: 2 } }),
		])
		const wrapper = await mountLocalQueue()

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
		const wrapper = await mountLocalQueue()

		await wrapper.find('[data-testid="senior-queue-sort-reference"]').trigger('click')
		expect(rowSheetIds(wrapper)).toEqual(['sheet-alpha', 'sheet-bravo', 'sheet-charlie'])
		await wrapper.find('[data-testid="senior-queue-sort-reference"]').trigger('click')
		expect(rowSheetIds(wrapper)).toEqual(['sheet-charlie', 'sheet-bravo', 'sheet-alpha'])
	})

	it('sorts by submitted date chronologically (ISO date order, not display strings)', async () => {
		seedSeniorQueue([
			seniorSheetRow('sheet-old', { submittedAt: '2026-09-20T10:00:00Z' }),
			seniorSheetRow('sheet-mid', { submittedAt: '2026-09-22T10:00:00Z' }),
			seniorSheetRow('sheet-new', { submittedAt: '2026-09-25T10:00:00Z' }),
		])
		const wrapper = await mountLocalQueue()

		await wrapper.find('[data-testid="senior-queue-sort-submitted"]').trigger('click')
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
		const wrapper = await mountLocalQueue()

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
		const wrapper = await mountLocalQueue()

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
		const wrapper = await mountLocalQueue()

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

	it('renders the common queue column terminology and drops the legacy synonyms (FU-05)', async () => {
		seedSeniorQueue([seniorSheetRow('sheet-1')])
		const wrapper = await mountLocalQueue()

		expect(wrapper.find('[data-testid="senior-queue-sort-submitted"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="senior-queue-sort-latestActivity"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="senior-queue-sort-status"]').exists()).toBe(true)
		expect(wrapper.html()).not.toContain('Latest activity')
		expect(wrapper.html()).not.toContain('Review status')
	})
})

describe('Senior cross-role tab semantics — Approvals is sheet-level (UXC-7-01/02/03)', () => {
	let pinia: Pinia
	let router: Router

	beforeEach(() => {
		pinia = createPinia()
		router = createTestRouter()
		setActivePinia(pinia)
		ocsRequest.mockReset()
		ocsRequest.mockImplementation((config: { url?: string }) =>
			Promise.resolve(emptyOcsResponse(config))
		)
	})

	afterEach(() => {
		document.body.innerHTML = ''
	})

	function seedSeniorQueue(items: SeniorSheetListRow[], overrides: Record<string, unknown> = {}) {
		const reviewsStore = useReviewsStore()
		reviewsStore.seniorQueue = { items, total: items.length, page: 1, pageSize: 100, ...overrides } as never
		reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
		return reviewsStore
	}

	/** One per-sheet read (`GET /queues/senior/sheets/{id}`) with the acting senior's rows. */
	function seedSheetReviews(
		sheetId: string,
		transactions: Array<TransactionSummary & { seniorState: 'AWAITING_SENIOR_DECISION' | 'DECIDED_BY_SENIOR'; seniorDecision: Decision | null }>,
		sheetOverrides: Partial<SeniorSheetListRow> = {},
		/** UXC-7F-05: fields only the per-sheet read's sheet carries, e.g. `stageApprovedTotals`. */
		sheetExtras: Record<string, unknown> = {}
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
								sheet: { ...seniorSheetRow(sheetId, sheetOverrides), ...sheetExtras },
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

	async function mountLocalQueue(): Promise<VueWrapper> {
		await router.push('/reviews/senior')
		await router.isReady()
		const wrapper = mount(SeniorQueueView, {
			global: { plugins: [pinia, router] },
			attachTo: document.body
		})
		await flushPromises()
		return wrapper
	}

	async function openModal(sheetId: string): Promise<VueWrapper> {
		const wrapper = await mountLocalQueue()
		const row = wrapper.find(`[data-testid="sheet-row-${sheetId}"]`)
		await row.find('[data-testid="sheet-title"]').trigger('click')
		await flushPromises()
		return wrapper
	}

	async function openTab(key: 'transactions' | 'approvals' | 'overview' | 'history'): Promise<void> {
		await document.body.querySelector<HTMLElement>(`[data-testid="sheet-details-modal-tab-${key}"]`)!.click()
		await flushPromises()
	}

	/**
	 * This file renders the FA locale, so `formatMoney` emits Persian digits and the Persian
	 * thousands separator. Amount assertions normalize to ASCII digits first — the value itself,
	 * not its script, is what proves the server's figure was rendered.
	 */
	function asciiDigits(text: string | null | undefined): string {
		return (text ?? '')
			.replace(/[۰-۹]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
			.replace(/٬/g, ',')
	}

	it('exposes the same four canonical tabs as Cost Center and Finance (UXC-7-03)', async () => {
		seedSeniorQueue([seniorSheetRow('sheet-1')])
		seedSheetReviews('sheet-1', [
			{ ...seniorTransaction('tx-1'), seniorState: 'AWAITING_SENIOR_DECISION', seniorDecision: null },
		])
		await openModal('sheet-1')
		for (const key of ['transactions', 'approvals', 'overview', 'history'] as const) {
			expect(document.body.querySelector(`[data-testid="sheet-details-modal-tab-${key}"]`)).not.toBeNull()
		}
	})

	it('renders the server whole-sheet stageApprovedTotals for all three stages (UXC-7F-05)', async () => {
		seedSeniorQueue([seniorSheetRow('sheet-1')])
		const totals = {
			costCenterApproved: { amountMinor: 150000, currency: 'IRR', minorUnit: 0 },
			seniorApproved: { amountMinor: 100000, currency: 'IRR', minorUnit: 0 },
			financeApproved: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
		}
		// The senior-scoped rows' own decisions (a Senior approval here, an undecided row there)
		// would sum to far less client-side; the server's whole-sheet aggregate renders as-is.
		seedSheetReviews('sheet-1', [
			{ ...seniorTransaction('tx-a'), seniorState: 'DECIDED_BY_SENIOR', seniorDecision: { ...COST_CENTER_APPROVAL, id: 'd-sen-a', stage: 'SENIOR' } },
			{ ...seniorTransaction('tx-b'), seniorState: 'AWAITING_SENIOR_DECISION', seniorDecision: null },
		], {}, { stageApprovedTotals: totals })
		await openModal('sheet-1')
		await openTab('approvals')

		const panel = document.body.querySelector('[data-testid="sheet-details-modal-panel-approvals"]')
		expect(panel).not.toBeNull()
		expect(panel!.querySelector('[data-testid="sheet-approvals-summary"]')).not.toBeNull()
		// Canonical stage order, shared with Cost Center and Finance (cross-role requirement 15).
		const stageRows = [...panel!.querySelectorAll<HTMLElement>('[data-testid^="sheet-approvals-stage-"]')]
			.map((el) => el.getAttribute('data-testid'))
			.filter((id): id is string => id !== null && /^(sheet-approvals-stage-(costCenter|senior|finance))$/.test(id))
		expect(stageRows).toEqual([
			'sheet-approvals-stage-costCenter',
			'sheet-approvals-stage-senior',
			'sheet-approvals-stage-finance',
		])
		const stageTotal = (key: string) => panel!.querySelector(`[data-testid="sheet-approvals-stage-${key}-total"]`)
		const expected: Array<[string, string]> = [
			['costCenter', '150,000'],
			['senior', '100,000'],
			['finance', '50,000'],
		]
		for (const [key, amount] of expected) {
			expect(stageTotal(key)).not.toBeNull()
			expect(stageTotal(key)!.textContent).toContain('IRR')
			expect(asciiDigits(stageTotal(key)!.textContent)).toContain(amount)
			expect(panel!.querySelector(`[data-testid="sheet-approvals-stage-${key}-unavailable"]`)).toBeNull()
		}
		// No per-transaction decision history and no actor/comment data on the sheet-level tab
		// (cross-role requirement 17).
		expect(panel!.querySelector('[data-testid^="transaction-review-card-"]')).toBeNull()
		expect(panel!.querySelector('[data-testid^="sheet-transaction-"]')).toBeNull()
		expect(panel!.textContent).not.toContain('Manager 1')
	})

	it('keeps the Senior gate and read-only behavior on their server fields, not the approvals aggregate (UXC-7F-05)', async () => {
		// Cross-role requirement 7: the aggregate is presentation data for the Approvals tab only —
		// a completed sheet stays read-only because `reviewStatus` says so, and the Cost Center
		// completion gate stays the server's `allTransactionsCostCenterDecided` value.
		seedSeniorQueue([seniorSheetRow('sheet-1', { reviewStatus: 'REVIEW_COMPLETED' })])
		seedSheetReviews('sheet-1', [
			{ ...seniorTransaction('tx-a'), seniorState: 'DECIDED_BY_SENIOR', seniorDecision: { ...COST_CENTER_APPROVAL, id: 'd-sen-a', stage: 'SENIOR' } },
		], { reviewStatus: 'REVIEW_COMPLETED' }, {
			stageApprovedTotals: {
				costCenterApproved: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
				seniorApproved: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
				financeApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
			},
		})
		await openModal('sheet-1')
		await openTab('approvals')

		const panel = document.body.querySelector('[data-testid="sheet-details-modal-panel-approvals"]')
		expect(panel).not.toBeNull()
		const financeTotal = panel!.querySelector('[data-testid="sheet-approvals-stage-finance-total"]')
		expect(financeTotal).not.toBeNull()
		expect(asciiDigits(financeTotal!.textContent)).toContain('0')
		expect(panel!.querySelector('[data-testid="sheet-approvals-stage-finance-unavailable"]')).toBeNull()
		// The completed sheet's decision surface is unchanged: no decide controls on its card, and
		// none anywhere in the modal (including an expanded transaction's proximity row).
		const card = document.body.querySelector('[data-testid^="sheet-transaction-"]')
		if (card !== null) {
			expect(card.querySelector('[data-testid^="transaction-review-card-"]')).toBeNull()
		}
		expect(document.body.querySelector('[data-testid^="inline-decide-"]')).toBeNull()
		expect(document.body.querySelector('[data-testid^="transaction-card-decide-"]')).toBeNull()
	})

	it('renders every stage unavailable when the per-sheet read answers without stageApprovedTotals (UXC-7F-05)', async () => {
		seedSeniorQueue([seniorSheetRow('sheet-1')])
		// The read succeeded, but the payload carries no aggregate: the senior-scoped rows must
		// never be re-summed into a stand-in "whole-sheet" total.
		seedSheetReviews('sheet-1', [
			{ ...seniorTransaction('tx-a'), seniorState: 'DECIDED_BY_SENIOR', seniorDecision: { ...COST_CENTER_APPROVAL, id: 'd-sen-a', stage: 'SENIOR' } },
		])
		await openModal('sheet-1')
		await openTab('approvals')

		for (const stage of ['costCenter', 'senior', 'finance'] as const) {
			expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-unavailable"]`)).not.toBeNull()
			expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-total"]`)).toBeNull()
		}
	})

	it('does not re-derive stage totals from detail reads or patched decisions (UXC-7F-05)', async () => {
		// Cross-role requirement 9: corrected/current-revision semantics are the backend's. The
		// senior-scoped row below awaits this senior (so no client-side Senior sum exists), and the
		// server's aggregate disagrees with any sum over the visible rows — the tab must render the
		// server's figures untouched.
		seedSeniorQueue([seniorSheetRow('sheet-1')])
		seedSheetReviews('sheet-1', [
			{ ...seniorTransaction('tx-a'), seniorState: 'AWAITING_SENIOR_DECISION', seniorDecision: null },
		], {}, {
			stageApprovedTotals: {
				costCenterApproved: { amountMinor: 130000, currency: 'IRR', minorUnit: 0 },
				seniorApproved: { amountMinor: 130000, currency: 'IRR', minorUnit: 0 },
				financeApproved: { amountMinor: 130000, currency: 'IRR', minorUnit: 0 },
			},
		})
		await openModal('sheet-1')
		await openTab('approvals')

		for (const key of ['costCenter', 'senior', 'finance'] as const) {
			const total = document.body.querySelector(`[data-testid="sheet-approvals-stage-${key}-total"]`)
			expect(total).not.toBeNull()
			expect(asciiDigits(total!.textContent)).toContain('130,000')
		}
	})

	it('renders every stage honestly unavailable when the per-sheet read has not proven the set', async () => {
		seedSeniorQueue([seniorSheetRow('sheet-1')])
		// No per-sheet read is mocked, so the scoped transaction list is unproven.
		await openModal('sheet-1')
		await openTab('approvals')

		for (const stage of ['costCenter', 'senior', 'finance'] as const) {
			expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-unavailable"]`)).not.toBeNull()
			expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-total"]`)).toBeNull()
		}
	})

	it('Overview stays sheet-level and History stays an honest sheet-level empty state', async () => {
		seedSeniorQueue([seniorSheetRow('sheet-1', { reviewStatus: 'REVIEW_COMPLETED' })])
		seedSheetReviews('sheet-1', [
			{ ...seniorTransaction('tx-1'), seniorState: 'DECIDED_BY_SENIOR', seniorDecision: { ...COST_CENTER_APPROVAL, id: 'd-sen-1', stage: 'SENIOR' } },
		], { reviewStatus: 'REVIEW_COMPLETED' })
		await openModal('sheet-1')

		await openTab('overview')
		const overview = document.body.querySelector('[data-testid="senior-tab-overview"]')
		expect(overview).not.toBeNull()
		// Sheet-level identity from the authoritative list row; the purchaser is proven here.
		expect(overview!.textContent).toContain('Sheet sheet-1')
		expect(overview!.querySelector('[data-testid="senior-overview-purchaser"]')).not.toBeNull()
		expect(overview!.querySelector('[data-testid="senior-overview-status"]')!.textContent).toContain('reviews.seniorSheet.reviewCompleted')
		expect(overview!.querySelector('[data-testid^="transaction-review-card-"]')).toBeNull()

		await openTab('history')
		expect(document.body.querySelector('[data-testid="senior-tab-history-empty"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="senior-tab-history-empty"]')!.textContent)
			.toContain('reviews.sheetHistory.unavailable')
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

	/** FU-11.C: Senior multi-cost-center filtering. */
	describe('Senior cost-center filter', () => {
		let pinia: Pinia
		let router: Router

		beforeEach(() => {
			pinia = createPinia()
			router = createTestRouter()
			setActivePinia(pinia)
			ocsRequest.mockReset()
			ocsRequest.mockImplementation((config: { url?: string }) =>
				/\/queues\/senior\/sheets\//.test(config?.url ?? '')
					? Promise.reject(new Error('senior sheet read unavailable'))
					: Promise.resolve(emptyOcsResponse(config))
			)
		})

		afterEach(() => {
			document.body.innerHTML = ''
		})

		/** Seed the per-sheet read store directly so scoping data is available without API calls. */
		function seedSheetReviews(
			sheetId: string,
			transactions: TransactionSummary[]
		) {
			const reviewsStore = useReviewsStore()
			reviewsStore.$patch((state) => {
				if (!state.seniorSheetReviews) state.seniorSheetReviews = {}
				state.seniorSheetReviews[sheetId] = { transactions } as never
			})
		}

		async function mountLocalQueue(): Promise<VueWrapper> {
			await router.push('/reviews/senior')
			await router.isReady()
			const wrapper = mount(SeniorQueueView, {
				global: { plugins: [pinia, router] },
				attachTo: document.body
			})
			await flushPromises()
			return wrapper
		}
		it('renders filter chips for assigned cost centers only', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = { items: [seniorSheetRow('sheet-1')], total: 1, page: 1, pageSize: 100 } as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001', 'CC002']
			const configStore = useConfigurationStore()
			configStore.costCenters = [
				{ id: 'c1', code: 'CC001', name: 'Cost Center 1' },
				{ id: 'c2', code: 'CC002', name: 'Cost Center 2' },
				{ id: 'c3', code: 'CC003', name: 'Cost Center 3' },
			] as never
			seedSheetReviews('sheet-1', [seniorTransaction('tx-1', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center 1' } } }) as never])
			seedSheetReviews('sheet-2', [seniorTransaction('tx-2', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center 1' } } }) as never])
			const wrapper = await mountLocalQueue()
			expect(wrapper.find('[data-testid="center-filter-chip-CC001"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="center-filter-chip-CC002"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="center-filter-chip-CC003"]').exists()).toBe(false)
		})

		it('shows all sheets when no center is selected', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = { items: [seniorSheetRow('sheet-1'), seniorSheetRow('sheet-2')], total: 2, page: 1, pageSize: 100 } as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001']
			const configStore = useConfigurationStore()
			configStore.costCenters = [{ id: 'c1', code: 'CC001', name: 'Cost Center 1' }] as never
			seedSheetReviews('sheet-1', [seniorTransaction('tx-1', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' } } })] as never)
			seedSheetReviews('sheet-2', [seniorTransaction('tx-2', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' } } })] as never)
			const wrapper = await mountLocalQueue()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(2)
		})

		it('filters sheets to the selected cost center', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = { items: [seniorSheetRow('sheet-1'), seniorSheetRow('sheet-2')], total: 2, page: 1, pageSize: 100 } as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001', 'CC002']
			const configStore = useConfigurationStore()
			configStore.costCenters = [
				{ id: 'c1', code: 'CC001', name: 'Cost Center 1' },
				{ id: 'c2', code: 'CC002', name: 'Cost Center 2' },
			] as never
			const wrapper = await mountLocalQueue()
			// Seed after mount: refresh() clears seniorSheetReviews on mount, so seed once
			// the component is up and the initial clear has run.
			seedSheetReviews('sheet-1', [seniorTransaction('tx-1', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center 1' } } })] as never)
			seedSheetReviews('sheet-2', [seniorTransaction('tx-2', { activeRevision: { costCenter: { id: 'cc-2', code: 'CC002', name: 'Cost Center 2' } } })] as never)
			await flushPromises()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(2)
			await wrapper.find('[data-testid="center-filter-chip-CC001"]').trigger('click')
			await flushPromises()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)
			expect(wrapper.find('[data-testid="sheet-row-sheet-1"]').exists()).toBe(true)
		})

		it('scopes transaction count to the selected cost center', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = { items: [seniorSheetRow('sheet-1', { progress: { awaitingSenior: 3, decidedBySenior: 0, total: 3 } })], total: 1, page: 1, pageSize: 100 } as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001', 'CC002']
			const configStore = useConfigurationStore()
			configStore.costCenters = [
				{ id: 'c1', code: 'CC001', name: 'Cost Center 1' },
				{ id: 'c2', code: 'CC002', name: 'Cost Center 2' },
			] as never
			const wrapper = await mountLocalQueue()
			seedSheetReviews('sheet-1', [
				seniorTransaction('tx-1', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' } } }),
				seniorTransaction('tx-2', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' } } }),
				seniorTransaction('tx-3', { activeRevision: { costCenter: { id: 'cc-2', code: 'CC002', name: 'CC2' } } }),
			] as never)
			await flushPromises()
			expect(wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-pending-count"]').text().trim()).toBe('۳')
			await wrapper.find('[data-testid="center-filter-chip-CC001"]').trigger('click')
			await flushPromises()
			expect(wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-pending-count"]').text().trim()).toBe('۲')
		})

		it('scopes amount total to the selected cost center', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = { items: [seniorSheetRow('sheet-1')], total: 1, page: 1, pageSize: 100 } as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001', 'CC002']
			const configStore = useConfigurationStore()
			configStore.costCenters = [
				{ id: 'c1', code: 'CC001', name: 'Cost Center 1' },
				{ id: 'c2', code: 'CC002', name: 'Cost Center 2' },
			] as never
			const wrapper = await mountLocalQueue()
			seedSheetReviews('sheet-1', [
				seniorTransaction('tx-1', {
					activeRevision: {
						costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' },
						amount: { amountMinor: 300000, currency: 'IRR', minorUnit: 0 },
					},
				}),
				seniorTransaction('tx-2', {
					activeRevision: {
						costCenter: { id: 'cc-2', code: 'CC002', name: 'CC2' },
						amount: { amountMinor: 200000, currency: 'IRR', minorUnit: 0 },
					},
				}),
			] as never)
			await flushPromises()
			await wrapper.find('[data-testid="center-filter-chip-CC002"]').trigger('click')
			await flushPromises()
			const amountCell = wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-amount"]')
			expect(amountCell.text()).toContain('۲۰۰٬۰۰۰')
			expect(amountCell.text()).not.toContain('۵۰۰٬۰۰۰')
		})

		it('shows each sheet only once when filtered', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = { items: [seniorSheetRow('sheet-1'), seniorSheetRow('sheet-2')], total: 2, page: 1, pageSize: 100 } as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001']
			const configStore = useConfigurationStore()
			configStore.costCenters = [{ id: 'c1', code: 'CC001', name: 'Cost Center 1' }] as never
			seedSheetReviews('sheet-1', [seniorTransaction('tx-1', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' } } })] as never)
			seedSheetReviews('sheet-2', [seniorTransaction('tx-2', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' } } })] as never)
			const wrapper = await mountLocalQueue()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(2)
			expect(wrapper.find('[data-testid="sheet-row-sheet-1"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="sheet-row-sheet-2"]').exists()).toBe(true)
		})

		/** Deterministic scenario from the owner-approved requirement:
		 * Sheet with CC1=3tx/300k, CC2=2tx/200k, CC3=4tx/400k.
		 * All centers → 9 tx / 900,000 IRR; CC2 selected → 2 tx / 200,000 IRR.
		 */
		it('deterministic scenario: all centers shows sheet-wide totals, CC2 shows scoped totals', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = {
				items: [
					seniorSheetRow('sheet-1', {
						progress: { awaitingSenior: 9, decidedBySenior: 0, total: 9 },
						submittedTotal: { amountMinor: 900000, currency: 'IRR', minorUnit: 0 },
					}),
				],
				total: 1, page: 1, pageSize: 100,
			} as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001', 'CC002', 'CC003']
			const configStore = useConfigurationStore()
			configStore.costCenters = [
				{ id: 'c1', code: 'CC001', name: 'Cost Center 1' },
				{ id: 'c2', code: 'CC002', name: 'Cost Center 2' },
				{ id: 'c3', code: 'CC003', name: 'Cost Center 3' },
			] as never
			const wrapper = await mountLocalQueue()
			// Seed 9 transactions: 3×CC1 (100k each), 2×CC2 (100k each), 4×CC3 (100k each)
			seedSheetReviews('sheet-1', [
				seniorTransaction('tx-1', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' }, amount: { amountMinor: 100000, currency: 'IRR', minorUnit: 0 } } }),
				seniorTransaction('tx-2', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' }, amount: { amountMinor: 100000, currency: 'IRR', minorUnit: 0 } } }),
				seniorTransaction('tx-3', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' }, amount: { amountMinor: 100000, currency: 'IRR', minorUnit: 0 } } }),
				seniorTransaction('tx-4', { activeRevision: { costCenter: { id: 'cc-2', code: 'CC002', name: 'CC2' }, amount: { amountMinor: 100000, currency: 'IRR', minorUnit: 0 } } }),
				seniorTransaction('tx-5', { activeRevision: { costCenter: { id: 'cc-2', code: 'CC002', name: 'CC2' }, amount: { amountMinor: 100000, currency: 'IRR', minorUnit: 0 } } }),
				seniorTransaction('tx-6', { activeRevision: { costCenter: { id: 'cc-3', code: 'CC003', name: 'CC3' }, amount: { amountMinor: 100000, currency: 'IRR', minorUnit: 0 } } }),
				seniorTransaction('tx-7', { activeRevision: { costCenter: { id: 'cc-3', code: 'CC003', name: 'CC3' }, amount: { amountMinor: 100000, currency: 'IRR', minorUnit: 0 } } }),
				seniorTransaction('tx-8', { activeRevision: { costCenter: { id: 'cc-3', code: 'CC003', name: 'CC3' }, amount: { amountMinor: 100000, currency: 'IRR', minorUnit: 0 } } }),
				seniorTransaction('tx-9', { activeRevision: { costCenter: { id: 'cc-3', code: 'CC003', name: 'CC3' }, amount: { amountMinor: 100000, currency: 'IRR', minorUnit: 0 } } }),
			] as never)
			await flushPromises()

			// All centers: sheet-wide totals from the list row
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)
			expect(wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-pending-count"]').text().trim()).toBe('۹')
			const amountAll = wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-amount"]')
			expect(amountAll.text()).toContain('۹۰۰٬۰۰۰')

			// Select CC2: scoped to 2 transactions / 200,000 IRR
			await wrapper.find('[data-testid="center-filter-chip-CC002"]').trigger('click')
			await flushPromises()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)
			expect(wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-pending-count"]').text().trim()).toBe('۲')
			const amountCC2 = wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-amount"]')
			expect(amountCC2.text()).toContain('۲۰۰٬۰۰۰')
			expect(amountCC2.text()).not.toContain('۹۰۰٬۰۰۰')
		})

		it('excludes sheets without transactions in the selected center', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = {
				items: [seniorSheetRow('sheet-1'), seniorSheetRow('sheet-2')],
				total: 2, page: 1, pageSize: 100,
			} as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001', 'CC002']
			const configStore = useConfigurationStore()
			configStore.costCenters = [
				{ id: 'c1', code: 'CC001', name: 'Cost Center 1' },
				{ id: 'c2', code: 'CC002', name: 'Cost Center 2' },
			] as never
			const wrapper = await mountLocalQueue()
			seedSheetReviews('sheet-1', [seniorTransaction('tx-1', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' } } })] as never)
			seedSheetReviews('sheet-2', [seniorTransaction('tx-2', { activeRevision: { costCenter: { id: 'cc-2', code: 'CC002', name: 'CC2' } } })] as never)
			await flushPromises()

			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(2)

			// Select CC001: sheet-2 (only CC2) must not appear
			await wrapper.find('[data-testid="center-filter-chip-CC001"]').trigger('click')
			await flushPromises()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)
			expect(wrapper.find('[data-testid="sheet-row-sheet-1"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="sheet-row-sheet-2"]').exists()).toBe(false)
		})

		it('all centers restores unrestricted management-scope results', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = {
				items: [seniorSheetRow('sheet-1'), seniorSheetRow('sheet-2')],
				total: 2, page: 1, pageSize: 100,
			} as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001', 'CC002']
			const configStore = useConfigurationStore()
			configStore.costCenters = [
				{ id: 'c1', code: 'CC001', name: 'Cost Center 1' },
				{ id: 'c2', code: 'CC002', name: 'Cost Center 2' },
			] as never
			const wrapper = await mountLocalQueue()
			seedSheetReviews('sheet-1', [seniorTransaction('tx-1', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' } } })] as never)
			seedSheetReviews('sheet-2', [seniorTransaction('tx-2', { activeRevision: { costCenter: { id: 'cc-2', code: 'CC002', name: 'CC2' } } })] as never)
			await flushPromises()

			// Select CC001 → only sheet-1
			await wrapper.find('[data-testid="center-filter-chip-CC001"]').trigger('click')
			await flushPromises()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)

			// Select CC002 → only sheet-2
			await wrapper.find('[data-testid="center-filter-chip-CC002"]').trigger('click')
			await flushPromises()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)
			expect(wrapper.find('[data-testid="sheet-row-sheet-2"]').exists()).toBe(true)
		})

		it('combines center filter with search', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = {
				items: [seniorSheetRow('sheet-1', { title: 'Alpha Sheet' }), seniorSheetRow('sheet-2', { title: 'Beta Sheet' })],
				total: 2, page: 1, pageSize: 100,
			} as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001', 'CC002']
			const configStore = useConfigurationStore()
			configStore.costCenters = [
				{ id: 'c1', code: 'CC001', name: 'Cost Center 1' },
				{ id: 'c2', code: 'CC002', name: 'Cost Center 2' },
			] as never
			const wrapper = await mountLocalQueue()
			seedSheetReviews('sheet-1', [seniorTransaction('tx-1', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' } } })] as never)
			seedSheetReviews('sheet-2', [seniorTransaction('tx-2', { activeRevision: { costCenter: { id: 'cc-2', code: 'CC002', name: 'CC2' } } })] as never)
			await flushPromises()

			// Select CC001 → only sheet-1
			await wrapper.find('[data-testid="center-filter-chip-CC001"]').trigger('click')
			await flushPromises()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)

			// Search for "Beta" → sheet-1 doesn't match, so zero results
			const searchInput = wrapper.find('[data-testid="senior-queue-search"]')
			await searchInput.setValue('Beta')
			await flushPromises()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(0)
		})

		it('combines center filter with status tabs', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = {
				items: [
					seniorSheetRow('sheet-1', { reviewStatus: 'PENDING_REVIEW' }),
					seniorSheetRow('sheet-2', { reviewStatus: 'REVIEW_COMPLETED' }),
				],
				total: 2, page: 1, pageSize: 100,
			} as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001', 'CC002']
			const configStore = useConfigurationStore()
			configStore.costCenters = [
				{ id: 'c1', code: 'CC001', name: 'Cost Center 1' },
				{ id: 'c2', code: 'CC002', name: 'Cost Center 2' },
			] as never
			const wrapper = await mountLocalQueue()
			seedSheetReviews('sheet-1', [seniorTransaction('tx-1', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' } } })] as never)
			seedSheetReviews('sheet-2', [seniorTransaction('tx-2', { activeRevision: { costCenter: { id: 'cc-2', code: 'CC002', name: 'CC2' } } })] as never)
			await flushPromises()

			// Select CC001 and filter to completed → sheet-1 is pending, so zero results
			await wrapper.find('[data-testid="center-filter-chip-CC001"]').trigger('click')
			await flushPromises()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)

			await wrapper.find('[data-testid="senior-queue-tab-completed"]').trigger('click')
			await flushPromises()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(0)
		})

		// --- Issue A: All cost centers control ---

		it('shows "All cost centers" chip when user has assigned cost centers, selected by default', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = {
				items: [seniorSheetRow('sheet-1'), seniorSheetRow('sheet-2')],
				total: 2, page: 1, pageSize: 100,
			} as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001', 'CC002']
			const configStore = useConfigurationStore()
			configStore.costCenters = [
				{ id: 'c1', code: 'CC001', name: 'Cost Center 1' },
				{ id: 'c2', code: 'CC002', name: 'Cost Center 2' },
			] as never
			const wrapper = await mountLocalQueue()
			seedSheetReviews('sheet-1', [seniorTransaction('tx-1', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' } } })] as never)
			seedSheetReviews('sheet-2', [seniorTransaction('tx-2', { activeRevision: { costCenter: { id: 'cc-2', code: 'CC002', name: 'CC2' } } })] as never)
			await flushPromises()

			// "All cost centers" chip is visible and active (no center selected yet).
			expect(wrapper.find('[data-testid="center-filter-chip-all"]').exists()).toBe(true)
			const allChip = wrapper.find('[data-testid="center-filter-chip-all"]')
			expect(allChip.classes()).toContain('center-filter__chip--active')
			expect(allChip.attributes('aria-pressed')).toBe('true')

			// Both sheets visible (all centers selected by default).
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(2)
		})

		it('selecting "All cost centers" restores all sheets after a center is selected', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = {
				items: [seniorSheetRow('sheet-1'), seniorSheetRow('sheet-2')],
				total: 2, page: 1, pageSize: 100,
			} as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001', 'CC002']
			const configStore = useConfigurationStore()
			configStore.costCenters = [
				{ id: 'c1', code: 'CC001', name: 'Cost Center 1' },
				{ id: 'c2', code: 'CC002', name: 'Cost Center 2' },
			] as never
			const wrapper = await mountLocalQueue()
			seedSheetReviews('sheet-1', [seniorTransaction('tx-1', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' } } })] as never)
			seedSheetReviews('sheet-2', [seniorTransaction('tx-2', { activeRevision: { costCenter: { id: 'cc-2', code: 'CC002', name: 'CC2' } } })] as never)
			await flushPromises()

			// Select CC001 → only sheet-1.
			await wrapper.find('[data-testid="center-filter-chip-CC001"]').trigger('click')
			await flushPromises()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)
			expect(wrapper.find('[data-testid="center-filter-chip-CC001"]').classes()).toContain('center-filter__chip--active')

			// Select "All cost centers" → both sheets restored, no refresh.
			await wrapper.find('[data-testid="center-filter-chip-all"]').trigger('click')
			await flushPromises()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(2)
			expect(wrapper.find('[data-testid="sheet-row-sheet-1"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="sheet-row-sheet-2"]').exists()).toBe(true)
			// All chip active, CC chip inactive.
			expect(wrapper.find('[data-testid="center-filter-chip-all"]').classes()).toContain('center-filter__chip--active')
			expect(wrapper.find('[data-testid="center-filter-chip-CC001"]').classes()).not.toContain('center-filter__chip--active')
		})

		it('restores transaction counts and amounts when selecting "All cost centers"', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = {
				items: [seniorSheetRow('sheet-1', {
					progress: { awaitingSenior: 3, decidedBySenior: 0, total: 3 },
					submittedTotal: { amountMinor: 900000, currency: 'IRR', minorUnit: 0 },
				})],
				total: 1, page: 1, pageSize: 100,
			} as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001', 'CC002']
			const configStore = useConfigurationStore()
			configStore.costCenters = [
				{ id: 'c1', code: 'CC001', name: 'Cost Center 1' },
				{ id: 'c2', code: 'CC002', name: 'Cost Center 2' },
			] as never
			const wrapper = await mountLocalQueue()
			seedSheetReviews('sheet-1', [
				seniorTransaction('tx-1', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' }, amount: { amountMinor: 300000, currency: 'IRR', minorUnit: 0 } } }),
				seniorTransaction('tx-2', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' }, amount: { amountMinor: 300000, currency: 'IRR', minorUnit: 0 } } }),
				seniorTransaction('tx-3', { activeRevision: { costCenter: { id: 'cc-2', code: 'CC002', name: 'CC2' }, amount: { amountMinor: 300000, currency: 'IRR', minorUnit: 0 } } }),
			] as never)
			await flushPromises()

			// All centers: 3 transactions / 900,000 IRR.
			expect(wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-pending-count"]').text().trim()).toBe('۳')
			expect(wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-amount"]').text()).toContain('۹۰۰٬۰۰۰')

			// Select CC001 → 2 transactions / 600,000 IRR.
			await wrapper.find('[data-testid="center-filter-chip-CC001"]').trigger('click')
			await flushPromises()
			expect(wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-pending-count"]').text().trim()).toBe('۲')

			// Select "All cost centers" → back to 3 transactions / 900,000 IRR.
			await wrapper.find('[data-testid="center-filter-chip-all"]').trigger('click')
			await flushPromises()
			expect(wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-pending-count"]').text().trim()).toBe('۳')
			expect(wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-amount"]').text()).toContain('۹۰۰٬۰۰۰')
		})

		it('preserves search and status selections when switching between centers and All', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = {
				items: [
					seniorSheetRow('sheet-1', { title: 'Alpha Sheet', reviewStatus: 'PENDING_REVIEW' }),
					seniorSheetRow('sheet-2', { title: 'Beta Sheet', reviewStatus: 'REVIEW_COMPLETED' }),
				],
				total: 2, page: 1, pageSize: 100,
			} as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001', 'CC002']
			const configStore = useConfigurationStore()
			configStore.costCenters = [
				{ id: 'c1', code: 'CC001', name: 'Cost Center 1' },
				{ id: 'c2', code: 'CC002', name: 'Cost Center 2' },
			] as never
			const wrapper = await mountLocalQueue()
			seedSheetReviews('sheet-1', [seniorTransaction('tx-1', { activeRevision: { costCenter: { id: 'cc-1', code: 'CC001', name: 'CC1' } } })] as never)
			seedSheetReviews('sheet-2', [seniorTransaction('tx-2', { activeRevision: { costCenter: { id: 'cc-2', code: 'CC002', name: 'CC2' } } })] as never)
			await flushPromises()

			// Search for "Alpha" — only sheet-1 matches.
			const searchInput = wrapper.find('[data-testid="senior-queue-search"]')
			await searchInput.setValue('Alpha')
			await flushPromises()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)
			expect(wrapper.find('[data-testid="sheet-row-sheet-1"]').exists()).toBe(true)

			// Select CC002 — search "Alpha" still applies, sheet-1 doesn't have CC2 → zero results.
			await wrapper.find('[data-testid="center-filter-chip-CC002"]').trigger('click')
			await flushPromises()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(0)

			// Select "All cost centers" — search "Alpha" preserved, sheet-1 returns.
			await wrapper.find('[data-testid="center-filter-chip-all"]').trigger('click')
			await flushPromises()
			expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)
			expect(wrapper.find('[data-testid="sheet-row-sheet-1"]').exists()).toBe(true)
		})

		// --- Issue B: Independent cost-center loading ---

		it('loads assigned cost-center options without visiting Administration', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = {
				items: [seniorSheetRow('sheet-1')],
				total: 1, page: 1, pageSize: 100,
			} as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001', 'CC002']
			// Configuration store starts empty — simulating a direct navigation to /reviews/senior
			// without ever visiting /admin/configuration.
			const configStore = useConfigurationStore()
			configStore.costCenters = [] as never
			configStore.error = null

			// Stub listCostCenters so the view can load centers independently (it is #[NoAdminRequired]).
			ocsRequest.mockImplementation((config: { url?: string }) => {
				const url = config?.url ?? ''
				if (url.includes('/configuration/cost-centers')) {
					return Promise.resolve({
						status: 200,
						data: {
							ocs: {
								meta: { status: 'ok', statuscode: 200 },
								data: {
									items: [
										{ id: 'c1', code: 'CC001', name: 'Cost Center 1' },
										{ id: 'c2', code: 'CC002', name: 'Cost Center 2' },
									],
									page: 1, pageSize: 100, total: 2,
								}
							}
						}
					})
				}
				return Promise.resolve(emptyOcsResponse(config))
			})

			const wrapper = await mountLocalQueue()
			await flushPromises()

			// Assigned centers appear even though Administration was never visited.
			expect(wrapper.find('[data-testid="center-filter-chip-CC001"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="center-filter-chip-CC002"]').exists()).toBe(true)
			// "All cost centers" is selected by default.
			expect(wrapper.find('[data-testid="center-filter-chip-all"]').classes()).toContain('center-filter__chip--active')
		})

		it('does not expose cost centers not assigned to the current senior manager', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = {
				items: [seniorSheetRow('sheet-1')],
				total: 1, page: 1, pageSize: 100,
			} as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			// Only CC001 is assigned to this senior manager.
			authStore.managedCostCenterCodes = ['CC001']
			const configStore = useConfigurationStore()
			configStore.costCenters = [
				{ id: 'c1', code: 'CC001', name: 'Cost Center 1' },
				{ id: 'c2', code: 'CC002', name: 'Cost Center 2' },
				{ id: 'c3', code: 'CC003', name: 'Cost Center 3' },
			] as never
			const wrapper = await mountLocalQueue()
			await flushPromises()

			// Only assigned centers render chips.
			expect(wrapper.find('[data-testid="center-filter-chip-CC001"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="center-filter-chip-CC002"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="center-filter-chip-CC003"]').exists()).toBe(false)
		})

		it('handles loadCostCenters error gracefully', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.seniorQueue = {
				items: [seniorSheetRow('sheet-1')],
				total: 1, page: 1, pageSize: 100,
			} as never
			reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
			const authStore = useAuthStore()
			authStore.managedCostCenterCodes = ['CC001']
			const configStore = useConfigurationStore()
			configStore.costCenters = [] as never

			ocsRequest.mockImplementation((config: { url?: string }) => {
				const url = config?.url ?? ''
				if (url.includes('/configuration/cost-centers')) {
					return Promise.reject(new Error('cost centers unavailable'))
				}
				return Promise.resolve(emptyOcsResponse(config))
			})

			const wrapper = await mountLocalQueue()
			await flushPromises()

			// View still renders; no chips (no center data), but no crash.
			expect(wrapper.find('[data-testid="senior-queue"]').exists()).toBe(true)
			expect(wrapper.findAll('[data-testid^="center-filter-chip-"]')).toHaveLength(0)
		})
	})
})

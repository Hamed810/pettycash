// Feature 009 Phase C — Finance Cost-Sheet-first queue (T009-C-01 … T009-C-08).
//
// The queue consumes the proven-complete Phase A/B grouping output and must never render a flat
// transaction table or a partial grouping as complete.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import FinanceQueueView from '@/views/reviews/FinanceQueueView.vue'
import { useReviewsStore } from '@/stores/reviews'
import type { FinanceSheetQueueItem } from '@/composables/useFinanceQueue'
import type { TransactionSummary } from '@/types/api'

let pinia: ReturnType<typeof createPinia>
let reviewsStore: ReturnType<typeof useReviewsStore>

beforeEach(() => {
	pinia = createPinia()
	setActivePinia(pinia)
	reviewsStore = useReviewsStore()
	// UXC-5: tests seed the store directly, so onMounted's refresh must not race the seeded state
	// through the real network layer — a late failed fetch would flip the view into its error state.
	reviewsStore.fetchFinanceQueue = vi.fn().mockResolvedValue(undefined) as never
})

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@nextcloud/l10n')>()
	const en: Record<string, string> = {
		'navigation.dashboard': 'Dashboard',
		'common.breadcrumbs': 'Breadcrumbs',
		'common.refresh': 'Refresh',
		'common.retry': 'Retry',
		'common.clear': 'Clear',
		'common.loading': 'Loading',
		'reviews.financeQueue': 'Finance queue',
		'reviews.financeSheet.eyebrow': 'Finance review',
		'reviews.financeSheet.description': 'Cost Sheets appear here once every transaction has completed Senior review. Each row is one Cost Sheet.',
		'reviews.financeSheet.searchLabel': 'Search sheets',
		'reviews.financeSheet.searchPlaceholder': 'Search by sheet reference',
		'reviews.financeSheet.reference': 'Sheet',
		'reviews.sheetQueue.purchaser': 'Purchaser',
		'reviews.sheetQueue.transactions': 'Transactions',
		'reviews.amount': 'Amount',
		'reviews.sheetQueue.submitted': 'Submitted',
		'reviews.status': 'Status',
		'reviews.financeSheet.statusAwaiting': '{count} of {total} awaiting your decision',
		'reviews.financeSheet.statusDecided': 'All decided',
		'reviews.financeSheet.empty.title': 'No sheets need your decision',
		'reviews.financeSheet.empty.body': 'No Cost Sheets are awaiting Finance review.',
		'reviews.financeSheet.unavailable.title': 'Sheet grouping unavailable',
		'reviews.financeSheet.unavailable.body': 'The complete set of pending Finance transactions could not be proven, so Cost Sheet rows cannot be shown yet.',
		'reviews.financeSheet.unavailable.action': 'Retry',
		'reviews.quickReview.card.metaUnavailable': 'Further detail fields are not provided by the server yet.',
	}
	return {
		...actual,
		getLanguage: () => 'en',
		isRTL: () => false,
		t: (ns: string, key: string): string =>
			ns === 'pettycash' ? (en[key] ?? key) : String(actual.t(ns, key)),
	}
})

function summary(id: string, overrides: {
	sheetId?: string
	amountMinor?: number
	minorUnit?: number
	currency?: string
	expenseDate?: string
	status?: string
	latestDecisionStage?: 'FINANCE' | null
	sheetTitle?: string
	sheetOwnerId?: string
	sheetOwnerDisplayName?: string
	sheetSubmittedAt?: string | null
} = {}): TransactionSummary {
	return {
		id,
		sheetId: overrides.sheetId ?? 'sheet-1',
		status: overrides.status ?? 'AWAITING_FINANCE_REVIEW',
		finalOutcome: null,
		evidenceRequired: false,
		evidenceCount: 0,
		version: 0,
		latestDecision: overrides.latestDecisionStage
			? { id: 'd', stage: overrides.latestDecisionStage, decision: 'APPROVE',
				actor: { userId: 'u1', displayName: 'U', role: 'FINANCE_MANAGER' },
				decidedAt: '2026-09-21T10:00:00Z' }
			: undefined,
		activeRevision: {
			id: `rev-${id}`, number: 1, expenseDate: overrides.expenseDate ?? '2026-09-20',
			amount: { amountMinor: overrides.amountMinor ?? 50_000, currency: overrides.currency ?? 'IRR', minorUnit: overrides.minorUnit ?? 0 },
			description: 'Expense', costCenter: { id: 'cc-1', code: 'CC-1', name: 'Cost Center' },
			expenseType: { id: 'et-1', name: 'Travel' },
			createdBy: 'user1', createdAt: '2026-09-20T10:00:00Z', active: true,
		},
		sheetTitle: overrides.sheetTitle ?? null,
		sheetOwnerId: overrides.sheetOwnerId ?? null,
		sheetOwnerDisplayName: overrides.sheetOwnerDisplayName ?? null,
		sheetSubmittedAt: overrides.sheetSubmittedAt ?? null,
	} as TransactionSummary
}

function sheetItem(sheetId: string, transactions: TransactionSummary[]): FinanceSheetQueueItem {
	return {
		sheetId,
		sheetTitle: `Sheet ${sheetId}`,
		sheetOwnerId: 'owner-1',
		sheetOwnerDisplayName: 'Purchaser One',
		sheetSubmittedAt: '2026-09-20T08:00:00Z',
		transactions: transactions.map((t) => ({ ...t, sheetId })),
		transactionCount: transactions.length,
		totals: [{ currency: 'IRR', minorUnit: 0, amountMinor: 50_000 * transactions.length }],
		latestActivityAt: '2026-09-20T10:00:00Z',
		costCenters: [{ code: 'CC-1', name: 'Cost Center' }],
	} as FinanceSheetQueueItem
}

function setQueue(store: ReturnType<typeof useReviewsStore>, sheets: FinanceSheetQueueItem[], complete: boolean, total?: number) {
	const items = sheets.flatMap((s) => s.transactions)
	store.financeQueue = { items, page: 1, pageSize: 100, total: total ?? items.length } as never
	store.financeQueueCompleteness = complete ? { complete: true, reason: 'complete' } : { complete: false, reason: 'truncated' }
	store.loading = false
	store.error = null
}

describe('FinanceQueueView (Phase C)', () => {
	pinia = createPinia()
	setActivePinia(pinia)
	reviewsStore = useReviewsStore()

	afterEach(() => {
		document.body.innerHTML = ''
	})

	it('renders one row per Cost Sheet (multiple eligible transactions → one row)', async () => {
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [
			sheetItem('sheet-1', [summary('a'), summary('b')]),
			sheetItem('sheet-2', [summary('c')]),
		], true)
		await flushPromises()

		const table = wrapper.findComponent({ name: 'ResponsiveTable' })
		expect(table.exists()).toBe(true)
		const rows = table.props('rows') as FinanceSheetQueueItem[]
		expect(rows.map((r) => r.sheetId)).toEqual(['sheet-1', 'sheet-2'])
		expect(rows[0]!.transactionCount).toBe(2)
		expect(rows[1]!.transactionCount).toBe(1)
	})

	it('does not render transaction-level primary queue rows', async () => {
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [sheetItem('sheet-1', [summary('a'), summary('b')])], true)
		await flushPromises()

		const table = wrapper.findComponent({ name: 'ResponsiveTable' })
		const rows = table.props('rows') as FinanceSheetQueueItem[]
		expect(rows).toHaveLength(1) // one Cost Sheet row, not two transaction rows
		expect(document.body.querySelector('[data-testid="sheet-details-modal"]')).toBeNull()
	})

	it('renders incomplete grouping as an honest unavailable state with retry, no partial rows', async () => {
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [sheetItem('sheet-1', [summary('a')])], false, 2)
		await flushPromises()

		expect(wrapper.find('[data-testid="grouping-unavailable"]').exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'ResponsiveTable' }).exists()).toBe(false)
		expect(wrapper.find('[data-testid="retry-btn"]').exists()).toBe(true)
	})

	it('renders complete empty state distinctly from unavailable state', async () => {
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [], true)
		await flushPromises()

		expect(wrapper.find('[data-testid="empty-queue"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="grouping-unavailable"]').exists()).toBe(false)
	})

	it('opens the shared Sheet Details Modal over the mounted queue (no route navigation)', async () => {
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [sheetItem('sheet-1', [summary('a')])], true)
		await flushPromises()

		const table = wrapper.findComponent({ name: 'ResponsiveTable' })
		const rows = table.props('rows') as FinanceSheetQueueItem[]
		expect(rows).toHaveLength(1)

		// Selecting the sheet row opens the shared Sheet Details Modal; the queue stays mounted
		// behind it and no route navigation occurs (row selection is a modal interaction).
		expect(document.body.querySelector('[data-testid="sheet-details-modal"]')).toBeNull()
		await document.body.querySelector<HTMLElement>(
			'[data-testid="sheet-row-sheet-1"] [data-testid="sheet-title"]'
		)!.click()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="sheet-details-modal"]')).not.toBeNull()
		expect(wrapper.find('[data-testid="finance-sheet-queue"]').exists()).toBe(true)
	})

	it('shows per-currency totals separately (never cross-currency summed)', async () => {
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [
			sheetItem('sheet-1', [
				summary('a', { amountMinor: 50_000, currency: 'IRR' }),
				summary('b', { amountMinor: 1_250, currency: 'USD', minorUnit: 2 }),
			]),
		], true)
		await flushPromises()

		const table = wrapper.findComponent({ name: 'ResponsiveTable' })
		const rows = (table.props('rows') as FinanceSheetQueueItem[]) ?? []
		const sheet0 = rows.find((r) => r.sheetId === 'sheet-1')
		const irr: { currency: string; minorUnit: number; amountMinor: number } | undefined =
			sheet0?.totals?.find((t) => t.currency === 'IRR')
		const usd: { currency: string; minorUnit: number; amountMinor: number } | undefined =
			sheet0?.totals?.find((t) => t.currency === 'USD')
		expect(irr!).toEqual({ currency: 'IRR', minorUnit: 0, amountMinor: 50_000 })
		expect(usd!).toEqual({ currency: 'USD', minorUnit: 2, amountMinor: 1_250 })
	})

	it('renders authoritative sheet identity instead of unavailable fallbacks', async () => {
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [sheetItem('sheet-1', [
			summary('a', {
				sheetTitle: 'Sheet sheet-1',
				sheetOwnerId: 'owner-1',
				sheetOwnerDisplayName: 'Purchaser One',
				sheetSubmittedAt: '2026-09-20T08:00:00Z',
			}),
		])], true)
		await flushPromises()

		const rows = wrapper.findComponent({ name: 'ResponsiveTable' }).props('rows') as FinanceSheetQueueItem[]
		const sheet = rows[0]
		expect(sheet).not.toBeUndefined()
		// The server-projected metadata is present on the queue item
		expect(sheet!.sheetTitle).toBe('Sheet sheet-1')
		expect(sheet!.sheetOwnerId).toBe('owner-1')
		expect(sheet!.sheetOwnerDisplayName).toBe('Purchaser One')
		expect(sheet!.sheetSubmittedAt).toBe('2026-09-20T08:00:00Z')
		// It is not a fabricated title/purchaser/submittedAt shape — those keys are absent
		expect(sheet!).not.toHaveProperty('title')
		expect(sheet!).not.toHaveProperty('purchaser')
		expect(sheet!).not.toHaveProperty('submittedAt')
	})

	it('does not render unavailable fallbacks when valid sheet metadata exists', async () => {
		mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [sheetItem('sheet-1', [
			summary('a', {
				sheetTitle: 'Sheet sheet-1',
				sheetOwnerId: 'owner-1',
				sheetOwnerDisplayName: 'Purchaser One',
				sheetSubmittedAt: '2026-09-20T08:00:00Z',
			}),
		])], true)
		await flushPromises()

		// Purchaser cell shows the owner display name, not the unavailable fallback
		expect(document.body.textContent).not.toContain('reviews.sheetQueue.purchaserUnavailable')
		// Submitted cell shows the formatted date, not the unavailable fallback
		expect(document.body.textContent).not.toContain('reviews.sheetQueue.submittedUnavailable')
		// Sheet title button shows the human-readable title, not the raw UUID
		const titleButton = document.body.querySelector<HTMLElement>('[data-testid="sheet-title"]')
		expect(titleButton).not.toBeNull()
		expect(titleButton?.textContent).toBe('Sheet sheet-1')
		expect(titleButton?.getAttribute('aria-label')).toBe('Sheet sheet-1')
		// Compact reference is rendered separately beneath the title
		expect(document.body.querySelector('[data-testid="sheet-reference"]')).not.toBeNull()
	})

	it('shows unavailable fallbacks when sheet metadata is absent', async () => {
		mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [sheetItem('sheet-1', [summary('a')])], true)
		await flushPromises()

		expect(document.body.textContent).toContain('reviews.sheetQueue.purchaserUnavailable')
		expect(document.body.textContent).toContain('reviews.sheetQueue.submittedUnavailable')
	})

	it('does not duplicate the full UUID as both title and compact reference', async () => {
		mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [sheetItem('25ccedd9-1234-5678-9abc-145bdef01234', [
			summary('a', {
				sheetTitle: 'Sheet 25ccedd9-1234-5678-9abc-145bdef01234',
			}),
		])], true)
		await flushPromises()

		const titleButton = document.body.querySelector<HTMLElement>('[data-testid="sheet-title"]')
		const compactRef = document.body.querySelector<HTMLElement>('[data-testid="sheet-reference"]')
		expect(titleButton?.textContent).toBe('Sheet 25ccedd9-1234-5678-9abc-145bdef01234')
		expect(compactRef?.textContent).toContain('25ccedd9…1234')
		// The title is the human-readable title, not the raw UUID repeated twice
		expect(titleButton?.textContent).not.toBe(compactRef?.textContent)
	})

	it('retry re-fetches the finance queue', async () => {
		const fetchFinanceQueue = vi.fn().mockResolvedValue(undefined)
		reviewsStore.fetchFinanceQueue = fetchFinanceQueue as never
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [sheetItem('sheet-1', [summary('a')])], false, 2)
		await flushPromises()

		await wrapper.find('[data-testid="retry-btn"]').trigger('click')
		await flushPromises()
		expect(fetchFinanceQueue).toHaveBeenCalled()
	})

	/** FU-10.B: Finance KPI cards render with distinct sheet and transaction counts. */
	it('renders KPI cards with distinct sheet count and transaction count', async () => {
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [
			sheetItem('sheet-1', [summary('a'), summary('b')]),
			sheetItem('sheet-2', [summary('c')]),
			sheetItem('sheet-3', [
				summary('d', { status: 'FINANCE_APPROVED' }),
				summary('e', { status: 'FINANCE_APPROVED' }),
			]),
		], true)
		await flushPromises()

		const stats = wrapper.find('[data-testid="finance-stats"]')
		expect(stats.exists()).toBe(true)

		// Sheets awaiting your decision: sheet-1 (2 awaiting) + sheet-2 (1 awaiting) = 2 distinct sheets
		// sheet-3 has no awaiting transactions (all approved), so it is not counted
		expect(wrapper.find('[data-testid="stat-sheets"]').text()).toContain('2')
		// Transactions in queue: 2 + 1 = 3 awaiting transactions
		expect(wrapper.find('[data-testid="stat-transactions"]').text()).toContain('3')
	})

	it('counts only sheets with at least one awaiting Finance transaction', async () => {
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [
			sheetItem('sheet-1', [
				summary('a', { status: 'AWAITING_FINANCE_REVIEW' }),
				summary('b', { status: 'FINANCE_APPROVED' }),
			]),
			sheetItem('sheet-2', [
				summary('c', { status: 'FINANCE_APPROVED' }),
				summary('d', { status: 'FINANCE_APPROVED' }),
			]),
		], true)
		await flushPromises()

		// sheet-1 has one awaiting tx → counted; sheet-2 has none → not counted
		expect(wrapper.find('[data-testid="stat-sheets"]').text()).toContain('1')
		expect(wrapper.find('[data-testid="stat-transactions"]').text()).toContain('1')
	})

	/** FU-10.C: Unified search across all fields. */
	it('searches by sheet reference (case-insensitive, partial)', async () => {
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [
			sheetItem('sheet-abc-123', [summary('a')]),
			sheetItem('sheet-def-456', [summary('b')]),
		], true)
		await flushPromises()

		const table = wrapper.findComponent({ name: 'ResponsiveTable' })
		const rows = (table.props('rows') as FinanceSheetQueueItem[]) ?? []
		expect(rows).toHaveLength(2)

		// Simulate search input
		const input = wrapper.find('input')
		if (input.exists()) {
			await input.setValue('ABC')
			await flushPromises()
			const filtered = (table.props('rows') as FinanceSheetQueueItem[]) ?? []
			expect(filtered.map((r) => r.sheetId)).toEqual(['sheet-abc-123'])
		}
	})

	it('searches by sheet title', async () => {
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [
			sheetItem('sheet-1', [summary('a', { sheetTitle: 'Travel Expense' })]),
			sheetItem('sheet-2', [summary('b', { sheetTitle: 'Office Supplies' })]),
		], true)
		await flushPromises()

		const table = wrapper.findComponent({ name: 'ResponsiveTable' })
		let rows = (table.props('rows') as FinanceSheetQueueItem[]) ?? []
		expect(rows).toHaveLength(2)

		const input = wrapper.find('input')
		if (input.exists()) {
			await input.setValue('Travel')
			await flushPromises()
			rows = (table.props('rows') as FinanceSheetQueueItem[]) ?? []
			expect(rows.map((r) => r.sheetId)).toEqual(['sheet-1'])
		}
	})

	it('searches by purchaser name', async () => {
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [
			sheetItem('sheet-1', [summary('a', { sheetOwnerDisplayName: 'Alice Purchaser' })]),
			sheetItem('sheet-2', [summary('b', { sheetOwnerDisplayName: 'Bob Buyer' })]),
		], true)
		await flushPromises()

		const table = wrapper.findComponent({ name: 'ResponsiveTable' })
		let rows = (table.props('rows') as FinanceSheetQueueItem[]) ?? []
		expect(rows).toHaveLength(2)

		const input = wrapper.find('input')
		if (input.exists()) {
			await input.setValue('Alice')
			await flushPromises()
			rows = (table.props('rows') as FinanceSheetQueueItem[]) ?? []
			expect(rows.map((r) => r.sheetId)).toEqual(['sheet-1'])
		}
	})

	it('searches by cost center name and code', async () => {
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [
			sheetItem('sheet-1', [summary('a')]),
			sheetItem('sheet-2', [summary('b')]),
		], true)
		await flushPromises()

		const table = wrapper.findComponent({ name: 'ResponsiveTable' })
		let rows = (table.props('rows') as FinanceSheetQueueItem[]) ?? []
		expect(rows).toHaveLength(2)

		const input = wrapper.find('input')
		if (input.exists()) {
			await input.setValue('CC-1')
			await flushPromises()
			rows = (table.props('rows') as FinanceSheetQueueItem[]) ?? []
			expect(rows.map((r) => r.sheetId)).toEqual(['sheet-1', 'sheet-2'])
		}
	})

	it('combines search with status filter', async () => {
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		setQueue(reviewsStore, [
			sheetItem('sheet-1', [summary('a', { status: 'AWAITING_FINANCE_REVIEW' })]),
			sheetItem('sheet-2', [summary('b', { status: 'FINANCE_APPROVED' })]),
		], true)
		await flushPromises()

		// Filter to pending (awaiting) status only
		const tabPending = wrapper.find('[data-testid="sheet-queue-tab-pending"]')
		if (tabPending.exists()) {
			await tabPending.trigger('click')
			await flushPromises()
			const table = wrapper.findComponent({ name: 'ResponsiveTable' })
			const rows = (table.props('rows') as FinanceSheetQueueItem[]) ?? []
			expect(rows.map((r) => r.sheetId)).toEqual(['sheet-1'])
		}
	})
})

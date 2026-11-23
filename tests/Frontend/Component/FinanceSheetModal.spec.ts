// Feature 009 Phase D/E/F — Finance Cost Sheet review through the SHARED review primitives
// (UXC-5-01 … UXC-5-05, retargeted from the removed FinanceSheetModal suite).
//
// The queue stays mounted while the shared SheetDetailsModal overlays it; the Transactions tab
// renders one shared TransactionReviewCard per finance-eligible transaction with stage-separated
// previous-decision indicators and transaction-scoped evidence; the transaction title toggles the
// Inline Expanded Transaction (UXC-V2-11); decisions confirm through the shared
// DecisionConfirmation and reconcile from the server's authoritative result. The old
// FinanceSheetModal assertions are preserved against the shared concepts they now exercise.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import FinanceQueueView from '@/views/reviews/FinanceQueueView.vue'
import { useReviewsStore } from '@/stores/reviews'
import type { FinanceSheetQueueItem } from '@/composables/useFinanceQueue'
import type {
	Decision,
	DecisionResult,
	DecisionValue,
	StageApprovedTotals,
	TransactionDetail,
} from '@/types/api'

let pinia: ReturnType<typeof createPinia>
let reviewsStore: ReturnType<typeof useReviewsStore>

const dialogs = vi.hoisted(() => ({
	showSuccess: vi.fn()
}))

vi.mock('@nextcloud/dialogs', () => dialogs)

const http = vi.hoisted(() => ({
	commandId: vi.fn(() => 'cmd-1')
}))

vi.mock('@/services/http', () => http)

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@nextcloud/l10n')>()
	const en: Record<string, string> = {
		'navigation.dashboard': 'Dashboard',
		'common.breadcrumbs': 'Breadcrumbs',
		'common.refresh': 'Refresh',
		'common.retry': 'Retry',
		'common.clear': 'Clear',
		'common.loading': 'Loading',
		'common.close': 'Close',
		'common.notProvided': 'Not provided',
		'common.cancel': 'Cancel',
		'reviews.financeQueue': 'Finance queue',
		'reviews.financeSheet.eyebrow': 'Finance review',
		'reviews.financeSheet.description': 'Cost Sheets appear here once every transaction has completed Senior review. Each row is one Cost Sheet.',
		'reviews.financeSheet.reference': 'Sheet',
		'reviews.financeSheet.pendingCount': 'Pending',
		'reviews.financeSheet.latestActivity': 'Latest activity',
		'reviews.financeSheet.status': 'Review status',
		'reviews.financeSheet.modal.eyebrow': 'Finance review',
		'reviews.financeSheet.modal.transactions': 'Transactions',
		'reviews.financeSheet.modal.close': 'Close sheet',
		'reviews.financeSheet.modal.titleUnavailable': 'Title unavailable',
		'reviews.financeSheet.modal.ownerUnavailable': 'Owner unavailable',
		'reviews.financeSheet.modal.progress': '{decided} of {total} decided',
		'reviews.financeSheet.modal.loading': 'Loading',
		'reviews.financeSheet.modal.emptyTransactions': 'No transactions await finance review in this sheet.',
		'reviews.financeSheet.modal.unavailableTitle': 'Sheet transactions unavailable',
		'reviews.financeSheet.modal.unavailableBody': "The sheet's transactions could not be loaded.",
		'reviews.sheetQueue.sheetTitleUnavailable': 'Sheet title unavailable',
		'reviews.sheetQueue.purchaserUnavailable': 'Purchaser unavailable',
		'reviews.sheetQueue.submitted': 'Submitted',
		'reviews.sheetQueue.submittedUnavailable': 'Date unavailable',
		'reviews.sheetQueue.unavailableField': 'Not provided by the server',
		'reviews.sheetQueue.noCostCenter': 'Cost center unavailable',
		'reviews.sheetQueue.status': 'Status',
		'reviews.seniorSheet.filter.count': '{count} transactions',
		'reviews.seniorSheet.modal.progress': '{decided} of {total} decided',
		'workspace.tabTransactions': 'Transactions',
		'workspace.tabApprovals': 'Approvals',
		'workspace.tabOverview': 'Overview',
		'workspace.tabHistory': 'History',
		'workspace.tabs': 'Sheet sections',
		'reviews.noTransactionsInQueue': 'No transactions in this queue',
		'reviews.amount': 'Amount',
		'reviews.expenseDate': 'Expense date',
		'reviews.expenseType': 'Expense type',
		'reviews.evidence': 'Evidence',
		'reviews.costCenter': 'Cost center',
		'reviews.outcome.approved': 'Approved',
		'reviews.outcome.rejected': 'Rejected',
		'reviews.decision.approve': 'Approve',
		'reviews.decision.reject': 'Reject',
		'reviews.decision.return': 'Return',
		'reviews.decisionStage.costCenter': 'Cost Center',
		'reviews.decisionStage.senior': 'Senior Manager',
		'reviews.decisionStage.finance': 'Finance Manager',
		'reviews.decisionStage.noDecision': 'No decision yet',
		'reviews.priorDecisions': 'Prior decisions',
		'reviews.quickReview.card.awaiting': 'Awaiting review',
		'reviews.quickReview.card.transactionId': 'Transaction',
		'reviews.quickReview.card.noDescription': 'No description',
		'reviews.quickReview.card.fullDetails': 'Full details',
		'reviews.quickReview.card.more': 'more',
		'reviews.quickReview.card.moreNamed': 'Show {count} more attachments',
		'reviews.quickReview.card.opensPreviewNamed': 'Opens document preview: {name}',
		'reviews.quickReview.card.unavailable': 'Unavailable',
		'reviews.quickReview.card.metaUnavailable': 'Further detail fields are not provided by the server yet.',
		'reviews.quickReview.drawer.eyebrow': 'Full details',
		'reviews.quickReview.drawer.tabDescription': 'Description',
		'reviews.quickReview.drawer.attachments': 'Attachments',
		'reviews.quickReview.drawer.noAttachments': 'No attachments on this transaction.',
		'reviews.quickReview.drawer.decideLabel': 'Decide this transaction',
		'reviews.quickReview.drawer.decideNote': 'Decide from here or from the transaction card.',
		'reviews.quickReview.drawer.loading': 'Loading sheet details',
		'reviews.quickReview.loading': 'Loading sheet details',
		'reviews.quickReview.drawer.forbidden': "This transaction's details are not available to you.",
		'reviews.quickReview.drawer.notFound': 'This transaction is no longer in your review scope.',
		'reviews.quickReview.drawer.loadFailed': 'The transaction details could not be loaded.',
		'reviews.quickReview.drawer.tabsLabel': 'Transaction details sections',
		'reviews.quickReview.drawer.sheet': 'Sheet',
		'reviews.quickReview.drawer.purchasedBy': 'Purchased by',
		'reviews.quickReview.drawer.merchant': 'Merchant',
		'reviews.quickReview.drawer.paidOn': 'Paid on',
		'reviews.quickReview.drawer.method': 'Payment method',
		'reviews.quickReview.eyebrow': 'Quick review',
		'roles.purchaser': 'Purchaser',
		'transactions.expenseType': 'Expense type',
	}
	return {
		...actual,
		getLanguage: () => 'en',
		isRTL: () => false,
		t: (ns: string, key: string): string =>
			ns === 'pettycash' ? (en[key] ?? key) : String(actual.t(ns, key)),
	}
})

vi.mock('@/components/evidence/EvidencePreview.vue', () => ({
	default: {
		name: 'EvidencePreview',
		// The stub pins the real component's overlay selector (`data-test="evidence-preview-layer"`).
		template: '<div data-test="evidence-preview-layer" />',
		props: ['open', 'evidence', 'documents', 'index'],
		emits: ['close', 'navigate'],
	},
}))

const COST_CENTER_APPROVAL = {
	id: 'd-cc', stage: 'COST_CENTER', decision: 'APPROVE',
	actor: { userId: 'mgr1', displayName: 'Manager 1', role: 'COST_CENTER_MANAGER' },
	decidedAt: '2026-09-20T10:00:00Z',
} as Decision
const SENIOR_APPROVAL = {
	id: 'd-sen', stage: 'SENIOR', decision: 'APPROVE',
	actor: { userId: 'sr1', displayName: 'Senior 1', role: 'SENIOR_MANAGER' },
	decidedAt: '2026-09-21T10:00:00Z',
} as Decision

const FINANCE_ACTOR = {
	userId: 'fm1',
	displayName: 'Finance Manager',
	role: 'FINANCE_MANAGER',
}

function financeDecision(kind: DecisionValue): Decision {
	return {
		id: 'd-fin',
		stage: 'FINANCE',
		decision: kind,
		actor: FINANCE_ACTOR,
		decidedAt: '2026-09-22T10:00:00Z',
	}
}

function summary(id: string, overrides: {
	sheetId?: string
	amountMinor?: number
	currency?: string
	status?: string
	latestDecisionStage?: 'FINANCE' | null
} = {}): TransactionDetail {
	return {
		id,
		sheetId: overrides.sheetId ?? 'sheet-1',
		stage: 'FINANCE',
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
			id: `rev-${id}`, number: 1, expenseDate: '2026-09-20',
			amount: { amountMinor: overrides.amountMinor ?? 50_000, currency: overrides.currency ?? 'IRR', minorUnit: 0 },
			description: 'Expense ' + id, costCenter: { id: 'cc-1', code: 'CC-1', name: 'Cost Center' },
			expenseType: { id: 'et-1', name: 'Travel' },
			createdBy: 'user1', createdAt: '2026-09-20T10:00:00Z', active: true,
		},
	} as TransactionDetail
}

function detail(id: string, overrides: Record<string, unknown> = {}): TransactionDetail {
	return {
		...summary(id),
		allowedActions: { canApprove: true, canReject: true, canReturn: true },
		decisions: [COST_CENTER_APPROVAL, SENIOR_APPROVAL],
		evidence: [{
			id: `ev-${id}`, transactionId: id, revisionId: `rev-${id}`, name: `receipt-${id}.png`,
			mediaType: 'image/png', sizeBytes: 100, active: true, uploadedBy: 'user1',
			uploadedAt: '2026-09-20T10:00:00Z',
		}],
		...overrides,
	} as TransactionDetail
}

function decidedDetail(id: string, kind: DecisionValue): TransactionDetail {
	return detail(id, {
		status: kind === 'APPROVE' ? 'COMPLETED_APPROVED' : 'COMPLETED_REJECTED',
		decisions: [COST_CENTER_APPROVAL, SENIOR_APPROVAL, financeDecision(kind)],
		latestDecision: financeDecision(kind),
	})
}

function decisionResult(id: string, kind: DecisionValue): DecisionResult {
	return {
		decision: financeDecision(kind),
		transaction: decidedDetail(id, kind),
	}
}

function sheetItem(sheetId: string, transactions: TransactionDetail[], withMetadata: boolean = true): FinanceSheetQueueItem {
	return {
		sheetId,
		sheetTitle: withMetadata ? `Sheet ${sheetId}` : '',
		sheetOwnerId: withMetadata ? 'owner-1' : '',
		sheetOwnerDisplayName: withMetadata ? 'Purchaser One' : '',
		sheetSubmittedAt: withMetadata ? '2026-09-20T08:00:00Z' : null,
		transactions,
		transactionCount: transactions.length,
		totals: [{ currency: 'IRR', minorUnit: 0, amountMinor: transactions.length * 50_000 }],
		latestActivityAt: '2026-09-20T10:00:00Z',
		costCenters: [{ code: 'CC-1', name: 'Cost Center' }],
		// The view re-groups the flattened queue items, so the modal's aggregate is captured from
		// the transactions' own `stageApprovedTotals` payloads (stamp via `withSheetTotals`).
		stageApprovedTotals: null,
	} as FinanceSheetQueueItem
}

/** UXC-7F-05: the server's whole-sheet aggregate, stamped identically on every queue item. */
function stageTotals(overrides: Partial<StageApprovedTotals> = {}): StageApprovedTotals {
	return {
		costCenterApproved: { amountMinor: 100_000, currency: 'IRR', minorUnit: 0 },
		seniorApproved: { amountMinor: 50_000, currency: 'IRR', minorUnit: 0 },
		financeApproved: { amountMinor: 25_000, currency: 'IRR', minorUnit: 0 },
		...overrides,
	}
}

/** Stamps the aggregate onto each of the sheet's queue items, as `GET /queues/finance` does. */
function withSheetTotals(item: FinanceSheetQueueItem, totals: StageApprovedTotals | null): FinanceSheetQueueItem {
	const transactions = totals === null
		? item.transactions
		: item.transactions.map((transaction) => ({ ...transaction, stageApprovedTotals: totals }))
	return { ...item, transactions } as FinanceSheetQueueItem
}

function seedDetails(ids: string[]) {
	const store = useReviewsStore()
	const details: Record<string, TransactionDetail> = {}
	const reads: Record<string, string> = {}
	for (const id of ids) {
		details[id] = detail(id)
		reads[id] = 'loaded'
	}
	store.managerTransactionDetails = details as never
	store.managerTransactionReads = reads as never
}

function seedQueue(store: ReturnType<typeof useReviewsStore>, sheets: FinanceSheetQueueItem[]) {
	const items = sheets.flatMap((sheet) => sheet.transactions)
	store.financeQueue = { items, page: 1, pageSize: 100, total: items.length } as never
	store.financeQueueCompleteness = { complete: true, reason: 'complete' }
	store.loading = false
	store.error = null
}

describe('Finance shared Sheet review — content (Phase D retargeted)', () => {

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		reviewsStore = useReviewsStore()
		reviewsStore.fetchFinanceQueue = vi.fn().mockResolvedValue(undefined) as never
	})

	afterEach(() => {
		document.body.innerHTML = ''
		vi.restoreAllMocks()
	})

	/** Mounts the Finance queue, seeds the sheet, and opens the shared Sheet Details Modal. */
	async function mountModal(item: FinanceSheetQueueItem) {
		seedQueue(reviewsStore, [item])
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		await flushPromises()
		await document.body.querySelector<HTMLElement>(
			`[data-testid="sheet-row-${item.sheetId}"] [data-testid="sheet-title"]`
		)!.click()
		await flushPromises()
		return wrapper
	}

	it('renders one transaction card per finance-eligible transaction in the sheet', async () => {
		seedDetails(['a', 'b'])
		await mountModal(sheetItem('sheet-1', [summary('a'), summary('b')]))
		await flushPromises()

		const cards = document.body.querySelectorAll(
			'[data-testid="transaction-review-card-a"], [data-testid="transaction-review-card-b"]'
		)
		expect([...cards].map((el) => el.getAttribute('data-testid'))).toContain('transaction-review-card-a')
		expect([...cards].map((el) => el.getAttribute('data-testid'))).toContain('transaction-review-card-b')
		expect(cards.length).toBe(2)
	})

	it('does not render transactions from another sheet', async () => {
		seedDetails(['a'])
		await mountModal(sheetItem('sheet-1', [summary('a')]))
		await flushPromises()

		// Only sheet-1's transaction 'a' is in the opened sheet; transaction 'b' is absent.
		expect(document.body.querySelector('[data-testid="sheet-transaction-b"]')).toBeNull()
		expect(
			document.body.querySelectorAll('[data-testid="sheet-transaction-a"]')
		).toHaveLength(1)
	})

	it('shows prior Cost Center and Senior decisions distinctly, not collapsed (card indicators)', async () => {
		seedDetails(['a'])
		await mountModal(sheetItem('sheet-1', [summary('a')]))
		await flushPromises()

		const costCenter = document.body.querySelector('[data-testid="transaction-card-decision-costCenter"]')
		const senior = document.body.querySelector('[data-testid="transaction-card-decision-senior"]')
		const finance = document.body.querySelector('[data-testid="transaction-card-decision-finance"]')
		expect(costCenter).not.toBeNull()
		expect(senior).not.toBeNull()
		expect(finance).not.toBeNull()
		// Two decided stages and one undecided — three separate labelled indicators, never one status.
		expect(costCenter?.textContent).toContain('Approved')
		expect(senior?.textContent).toContain('Approved')
		expect(finance?.textContent).toContain('No decision yet')
	})

	it('shows existing Finance decision state distinctly (awaiting)', async () => {
		seedDetails(['a'])
		await mountModal(sheetItem('sheet-1', [
			summary('a', { status: 'AWAITING_FINANCE_REVIEW', latestDecisionStage: null }),
		]))
		await flushPromises()

		expect(document.body.querySelector('[data-testid="status-badge-awaiting_finance_review"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="transaction-card-decision-finance"]')?.textContent).toContain('No decision yet')
	})

	it('shows a decided Finance transaction state and withholds its decide bar', async () => {
		seedDetails(['a'])
		const store = useReviewsStore()
		store.managerTransactionDetails['a']!.latestDecision = financeDecision('APPROVE')
		await mountModal(sheetItem('sheet-1', [summary('a', { latestDecisionStage: 'FINANCE' })]))
		await flushPromises()

		const financeIndicator = document.body.querySelector('[data-testid="transaction-card-decision-finance"]')
		expect(financeIndicator?.textContent).toContain('Approved')
		expect(document.body.querySelector('[data-testid="transaction-card-decide-approve"]')).toBeNull()
	})

	it('keeps evidence associated with the correct transaction', async () => {
		seedDetails(['a', 'b'])
		await mountModal(sheetItem('sheet-1', [summary('a'), summary('b')]))
		await flushPromises()

		const cardA = document.body.querySelector('[data-testid="sheet-transaction-a"]')
		const cardB = document.body.querySelector('[data-testid="sheet-transaction-b"]')
		expect(cardA?.querySelector('[data-testid="transaction-card-thumb-ev-a"]')).not.toBeNull()
		expect(cardB?.querySelector('[data-testid="transaction-card-thumb-ev-b"]')).not.toBeNull()
		// Card A must not show transaction B's evidence
		expect(cardA?.querySelector('[data-testid="transaction-card-thumb-ev-b"]')).toBeNull()
	})

	it('expands one transaction in place without leaving the Sheet modal (UXC-V2-11)', async () => {
		seedDetails(['a'])
		await mountModal(sheetItem('sheet-1', [summary('a')]))
		await flushPromises()

		await document.body.querySelector<HTMLElement>('[data-testid="transaction-card-title"]')!.click()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="transaction-expanded-detail"]')).not.toBeNull()
		// The Sheet Details Modal stays open beneath the expansion — same Sheet review context.
		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).not.toBeNull()
	})

	it('renders no bulk decision control', async () => {
		seedDetails(['a', 'b'])
		await mountModal(sheetItem('sheet-1', [summary('a'), summary('b')]))
		await flushPromises()

		expect(document.body.querySelector('[data-testid*="bulk"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="finance-decide-all"]')).toBeNull()
		// The shell's header actions carry no decision control — decisions are per-transaction only.
		const headerActions = document.body.querySelector('[data-testid="sheet-details-modal-actions"]')
		expect(headerActions?.querySelector('[data-testid*="decide"]')).toBeNull()
	})

	it('shows unavailable identity fields honestly (title/purchaser/submitted)', async () => {
		seedDetails(['a'])
		await mountModal(sheetItem('sheet-1', [summary('a')], false))
		await flushPromises()

		const title = document.body.querySelector('[data-testid="sheet-details-modal-title"]')
		expect(title?.textContent).toContain('Sheet title unavailable')
		const owner = document.body.querySelector('[data-testid="sheet-details-modal-owner"]')
		expect(owner?.textContent).toContain('Owner unavailable')
	})

	it('closes the Sheet modal from its close button', async () => {
		seedDetails(['a'])
		await mountModal(sheetItem('sheet-1', [summary('a')]))
		await flushPromises()

		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).not.toBeNull()
		await document.body.querySelector<HTMLElement>('[data-testid="sheet-details-modal-close"]')!.click()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
	})

	it('closes the Sheet modal on Escape key', async () => {
		seedDetails(['a'])
		await mountModal(sheetItem('sheet-1', [summary('a')]))
		await flushPromises()

		document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')?.dispatchEvent(
			new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })
		)
		await flushPromises()
		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
	})
})

// ---------------------------------------------------------------------------
// Phase E — Independent transaction decisions through the shared DecisionConfirmation
// Phase F — Evidence and review context
// ---------------------------------------------------------------------------

describe('Finance shared Sheet review — decisions & evidence (Phase E/F retargeted)', () => {

	let fetchManagerTransactionDetail: ReturnType<typeof vi.fn>
	let clearManagerTransactionDetail: ReturnType<typeof vi.fn>
	let recordDecision: ReturnType<typeof vi.fn>

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		reviewsStore = useReviewsStore()
		reviewsStore.fetchFinanceQueue = vi.fn().mockResolvedValue(undefined) as never
		fetchManagerTransactionDetail = vi.fn().mockResolvedValue(null)
		clearManagerTransactionDetail = vi.fn()
		recordDecision = vi.fn()
		reviewsStore.fetchManagerTransactionDetail = fetchManagerTransactionDetail as never
		reviewsStore.clearManagerTransactionDetail = clearManagerTransactionDetail as never
		reviewsStore.recordDecision = recordDecision as never
	})

	afterEach(() => {
		document.body.innerHTML = ''
		vi.restoreAllMocks()
	})

	async function mountModal(item: FinanceSheetQueueItem) {
		seedQueue(reviewsStore, [item])
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		await flushPromises()
		await document.body.querySelector<HTMLElement>(
			`[data-testid="sheet-row-${item.sheetId}"] [data-testid="sheet-title"]`
		)!.click()
		await flushPromises()
		return wrapper
	}

	async function decideTransaction(transactionId: string, kind: DecisionValue): Promise<void> {
		// Click the decision action on this transaction's shared card → the shared confirmation opens.
		const btn = document.body.querySelector<HTMLElement>(
			`[data-testid="sheet-transaction-${transactionId}"] [data-testid="transaction-card-decide-${kind.toLowerCase()}"]`
		)
		expect(btn, `decide ${kind} control for ${transactionId}`).not.toBeNull()
		btn!.click()
		await flushPromises()

		// Reject/Return require an explanation (FR-033); Approve posts directly.
		if (kind !== 'APPROVE') {
			const note = document.body.querySelector<HTMLTextAreaElement>('[data-testid="decision-note-input"]')
			note!.value = 'ok'
			note!.dispatchEvent(new Event('input'))
			await flushPromises()
		}
		await document.body.querySelector<HTMLElement>('[data-testid="decision-confirm-btn"]')!.click()
		await flushPromises()
	}

	it('exposes decision controls only for the target transaction (E-01)', async () => {
		seedDetails(['a', 'b'])
		await mountModal(sheetItem('sheet-1', [summary('a'), summary('b')]))
		await flushPromises()

		expect(document.body.querySelector('[data-testid="sheet-transaction-a"] [data-testid="transaction-card-decide-approve"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="sheet-transaction-b"] [data-testid="transaction-card-decide-approve"]')).not.toBeNull()
		// Each card offers exactly its own three server-granted kinds.
		expect(
			document.body.querySelectorAll('[data-testid="sheet-transaction-a"] [data-testid^="transaction-card-decide-"]')
		).toHaveLength(3)
		expect(
			document.body.querySelectorAll('[data-testid="sheet-transaction-b"] [data-testid^="transaction-card-decide-"]')
		).toHaveLength(3)
	})

	it('does not expose an enabled Approve for a senior-rejected transaction (E-02)', async () => {
		seedDetails(['a'])
		const aDetail = reviewsStore.managerTransactionDetails['a']
		expect(aDetail).not.toBeNull()
		aDetail!.allowedActions = {
			canApprove: false,
			canReject: true,
			canReturn: true,
		}
		await mountModal(sheetItem('sheet-1', [summary('a', { status: 'SENIOR_REJECTED' })]))
		await flushPromises()

		const approve = document.body.querySelector<HTMLButtonElement>(
			'[data-testid="sheet-transaction-a"] [data-testid="transaction-card-decide-approve"]'
		)
		expect(approve).not.toBeNull()
		expect(approve!.disabled).toBe(true)
		// Reject/Return remain offered and enabled.
		expect(document.body.querySelector('[data-testid="sheet-transaction-a"] [data-testid="transaction-card-decide-reject"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="sheet-transaction-a"] [data-testid="transaction-card-decide-return"]')).not.toBeNull()
	})

	it('records exactly one per-transaction decision and does not mutate siblings (E-03, E-04)', async () => {
		recordDecision.mockResolvedValue(decisionResult('a', 'APPROVE'))
		seedDetails(['a', 'b'])
		await mountModal(sheetItem('sheet-1', [summary('a'), summary('b')]))
		await flushPromises()
		await decideTransaction('a', 'APPROVE')

		// Exactly one decision, for exactly one transaction.
		expect(recordDecision).toHaveBeenCalledTimes(1)
		expect(recordDecision).toHaveBeenCalledWith('a', expect.objectContaining({ decision: 'APPROVE' }), expect.anything())
		// The decided card keeps no decide bar; the sibling is untouched.
		expect(document.body.querySelector('[data-testid="sheet-transaction-a"] [data-testid="transaction-card-decide-approve"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="sheet-transaction-b"] [data-testid="transaction-card-decide-approve"]')).not.toBeNull()
	})

	it('opens the shared DecisionConfirmation for a finance-stage transaction (E-04)', async () => {
		seedDetails(['a'])
		await mountModal(sheetItem('sheet-1', [summary('a')]))
		await flushPromises()

		document.body.querySelector<HTMLElement>(
			'[data-testid="sheet-transaction-a"] [data-testid="transaction-card-decide-approve"]'
		)!.click()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="decision-modal"]')).not.toBeNull()
	})

	it('reconciles sheet progress from the server-authoritative result (E-05)', async () => {
		recordDecision.mockResolvedValue(decisionResult('a', 'APPROVE'))
		seedDetails(['a', 'b'])
		await mountModal(sheetItem('sheet-1', [summary('a'), summary('b')]))
		await flushPromises()
		await decideTransaction('a', 'APPROVE')
		await flushPromises()

		// The sheet progress reflects the authoritative post-decision state.
		const progress = document.body.querySelector('[data-testid="sheet-details-modal-progress"]')
		expect(progress?.textContent).toContain('decided')
		// The stale per-transaction detail was dropped for the authoritative refetch.
		expect(clearManagerTransactionDetail).toHaveBeenCalledWith('a')
	})

	it('keeps evidence bound to the correct transaction, no leakage between siblings (F-01, F-02)', async () => {
		seedDetails(['a', 'b'])
		await mountModal(sheetItem('sheet-1', [summary('a'), summary('b')]))
		await flushPromises()

		const cardA = document.body.querySelector('[data-testid="sheet-transaction-a"]')
		const cardB = document.body.querySelector('[data-testid="sheet-transaction-b"]')
		expect(cardA?.querySelector('[data-testid="transaction-card-thumb-ev-a"]')).not.toBeNull()
		expect(cardB?.querySelector('[data-testid="transaction-card-thumb-ev-b"]')).not.toBeNull()
		expect(cardA?.querySelector('[data-testid="transaction-card-thumb-ev-b"]')).toBeNull()
		expect(cardB?.querySelector('[data-testid="transaction-card-thumb-ev-a"]')).toBeNull()
	})

	it('opens the evidence preview for the correct transaction and returns to context (F-03)', async () => {
		seedDetails(['a'])
		await mountModal(sheetItem('sheet-1', [summary('a')]))
		await flushPromises()

		await document.body.querySelector<HTMLElement>('[data-testid="transaction-card-thumb-ev-a"]')!.click()
		await flushPromises()
		expect(document.body.querySelector('[data-test="evidence-preview-layer"]')).not.toBeNull()
		// The transaction card remains mounted behind the preview (context preserved).
		expect(document.body.querySelector('[data-testid="sheet-transaction-a"]')).not.toBeNull()
	})

	it('renders no evidence mutation controls (F-04)', async () => {
		seedDetails(['a'])
		await mountModal(sheetItem('sheet-1', [summary('a')]))
		await flushPromises()

		expect(document.body.querySelector('[data-testid="evidence-upload"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="evidence-replace"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="evidence-delete"]')).toBeNull()
	})

	it('keeps Cost Center / Senior / Finance decisions distinguishable in the expansion (F-05)', async () => {
		seedDetails(['a'])
		reviewsStore.managerTransactionDetails = {
			a: decidedDetail('a', 'APPROVE'),
		}
		const wrapper = await mountModal(sheetItem('sheet-1', [summary('a')]))
		await flushPromises()

		// Expand the transaction: the Previous Decisions section renders one group per stage.
		await document.body.querySelector<HTMLElement>('[data-testid="transaction-card-title"]')!.click()
		await flushPromises()

		const histories = wrapper.findAllComponents({ name: 'PriorDecisionHistory' })
		expect(histories.length).toBe(3)
		const stages = histories.flatMap((history) =>
			(history.props('decisions') as Array<{ stage: string }>).map((decision) => decision.stage))
		expect(stages).toContain('COST_CENTER')
		expect(stages).toContain('SENIOR')
		expect(stages).toContain('FINANCE')
		// Each stage renders separately; no stage is collapsed into another's group.
		expect(document.body.querySelector('[data-testid="inline-decisions-cost-center"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="inline-decisions-senior"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="inline-decisions-finance"]')).not.toBeNull()
	})

	it('keeps the expanded decide row gated by the server grant only (E-02, UXC-V2-11)', async () => {
		seedDetails(['a'])
		const aDetail = reviewsStore.managerTransactionDetails['a']
		aDetail!.allowedActions = { canApprove: false, canReject: true, canReturn: true }
		await mountModal(sheetItem('sheet-1', [summary('a', { status: 'SENIOR_REJECTED' })]))
		await flushPromises()

		await document.body.querySelector<HTMLElement>('[data-testid="transaction-card-title"]')!.click()
		await flushPromises()

		const approve = document.body.querySelector<HTMLButtonElement>('[data-testid="inline-decide-approve"]')
		expect(approve).not.toBeNull()
		expect(approve!.disabled).toBe(true)
		expect(document.body.querySelector('[data-testid="inline-decide-reject"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="inline-decide-return"]')).not.toBeNull()
	})
})

describe('Finance inline transaction expansion (UXC-V2-11)', () => {
	let pinia: ReturnType<typeof createPinia>
	let reviewsStore: ReturnType<typeof useReviewsStore>
	let recordDecision: ReturnType<typeof vi.fn>

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		reviewsStore = useReviewsStore()
		// Details are seeded into the store cache (the prefetch/confirm paths read from it), and the
		// queue/decision store methods are mocked directly — matching the sibling describes here.
		recordDecision = vi.fn()
		reviewsStore.recordDecision = recordDecision as never
		reviewsStore.fetchFinanceQueue = vi.fn().mockResolvedValue(undefined) as never
		reviewsStore.fetchManagerTransactionDetail = vi.fn(async (id: string) => reviewsStore.managerTransactionDetails[id] ?? null) as never
	})

	afterEach(() => {
		document.body.innerHTML = ''
		vi.restoreAllMocks()
	})

	/** Mounts the Finance queue, seeds the sheet, and opens the shared Sheet Details Modal. */
	async function mountModal(item: FinanceSheetQueueItem) {
		seedQueue(reviewsStore, [item])
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		await flushPromises()
		await document.body.querySelector<HTMLElement>(
			`[data-testid="sheet-row-${item.sheetId}"] [data-testid="sheet-title"]`
		)!.click()
		await flushPromises()
		return wrapper
	}

	async function openExpansion(id: string): Promise<void> {
		await document.body.querySelector<HTMLElement>(
			`[data-testid="transaction-review-card-${id}"] [data-testid="transaction-card-title"]`
		)!.click()
		await flushPromises()
	}

	it('opens exactly one expansion at a time and binds the inline detail to the selected transaction', async () => {
		seedDetails(['a', 'b'])
		await mountModal(sheetItem('sheet-1', [summary('a'), summary('b')]))
		await flushPromises()

		await openExpansion('a')
		const region = document.body.querySelector('[data-testid="transaction-expanded-detail"]')
		expect(region).not.toBeNull()
		expect(region?.querySelector('[data-testid="inline-field-sheet"]')).not.toBeNull()
		expect(document.body.querySelectorAll('[data-testid="sheet-details-modal-dialog"]')).toHaveLength(1)

		await openExpansion('b')
		const regions = document.body.querySelectorAll('[data-testid="transaction-expanded-detail"]')
		expect(regions).toHaveLength(1)
		expect(regions[0]?.querySelector('[data-testid="inline-field-sheet"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="transaction-review-card-a"] [data-testid="transaction-expanded-detail"]')).toBeNull()
	})

	it('keeps a Finance-decided transaction expanded read-only: details visible, decide row withheld, card keeps no decide bar', async () => {
		reviewsStore.managerTransactionDetails = { a: decidedDetail('a', 'APPROVE') } as never
		reviewsStore.managerTransactionReads = { a: 'loaded' } as never
		await mountModal(sheetItem('sheet-1', [summary('a', { status: 'FINANCE_APPROVED' })]))
		await flushPromises()

		await openExpansion('a')
		const region = document.body.querySelector('[data-testid="transaction-expanded-detail"]')
		expect(region).not.toBeNull()
		expect(region?.querySelector('[data-testid="inline-field-sheet"]')).not.toBeNull()
		// Resolved for Finance: no decide row in the expansion, no decide bar on the card.
		expect(document.body.querySelector('[data-testid="inline-decide-approve"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="transaction-card-decide-approve"]')).toBeNull()
		// The previous decisions stay visible in the expansion (transaction-level history).
		expect(document.body.querySelector('[data-testid="inline-decisions-finance"]')).not.toBeNull()
	})

	it('routes the expanded decide row through the same single confirmation and collapses on completion', async () => {
		seedDetails(['a'])
		recordDecision.mockResolvedValue(decisionResult('a', 'APPROVE'))
		await mountModal(sheetItem('sheet-1', [summary('a')]))
		await flushPromises()

		await openExpansion('a')
		await document.body.querySelector<HTMLElement>('[data-testid="inline-decide-approve"]')!.click()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="decision-modal"]')).not.toBeNull()

		await document.body.querySelector<HTMLElement>('[data-testid="decision-confirm-btn"]')!.click()
		await flushPromises()
		expect(recordDecision).toHaveBeenCalledTimes(1)
		expect(recordDecision).toHaveBeenCalledWith('a', {
			decision: 'APPROVE',
			explanation: '',
			expectedVersion: expect.anything(),
		}, { commandId: expect.any(String) })
		expect(document.body.querySelector('[data-testid="decision-modal"]')).toBeNull()
		// Deterministic post-decision state: the expansion collapsed with the confirmation.
		expect(document.body.querySelector('[data-testid="transaction-expanded-detail"]')).toBeNull()
	})

	it('keeps the legacy drawer unreachable through normal Finance interaction', async () => {
		seedDetails(['a', 'b'])
		await mountModal(sheetItem('sheet-1', [summary('a'), summary('b')]))
		await flushPromises()

		await openExpansion('a')
		await openExpansion('b')
		// A thumbnail still opens the shared preview overlay; the preview stays bound to transaction
		// a's own evidence set while the expansion stays mounted behind it.
		await document.body.querySelector<HTMLElement>('[data-testid="transaction-review-card-a"] [data-testid="transaction-card-thumb-ev-a"]')!.click()
		await flushPromises()
		expect(document.body.querySelector('[data-test="evidence-preview-layer"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="transaction-expanded-detail"]')).not.toBeNull()
		// Every interaction produced the expansion or a shared overlay — never a second dialog frame.
		expect(document.body.querySelectorAll('[data-testid="sheet-details-modal-dialog"]')).toHaveLength(1)
	})
})

describe('Cross-role tab semantics — Approvals is sheet-level (UXC-7-01/02/03)', () => {

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		reviewsStore = useReviewsStore()
		reviewsStore.fetchFinanceQueue = vi.fn().mockResolvedValue(undefined) as never
	})

	afterEach(() => {
		document.body.innerHTML = ''
		vi.restoreAllMocks()
	})

	/** Seeds the bounded detail cache with explicit details, so per-stage decisions are proven. */
	function seedDetailsFor(details: TransactionDetail[]) {
		const store = useReviewsStore()
		const cache: Record<string, TransactionDetail> = {}
		const reads: Record<string, string> = {}
		for (const item of details) {
			cache[item.id] = item
			reads[item.id] = 'loaded'
		}
		store.managerTransactionDetails = cache as never
		store.managerTransactionReads = reads as never
	}

	/** Mounts the Finance queue, seeds the sheet, and opens the shared Sheet Details Modal. */
	async function mountModal(item: FinanceSheetQueueItem) {
		seedQueue(reviewsStore, [item])
		const wrapper = mount(FinanceQueueView, {
			global: { plugins: [pinia], stubs: ['RouterLink'] },
			attachTo: document.body,
		})
		await flushPromises()
		await document.body.querySelector<HTMLElement>(
			`[data-testid="sheet-row-${item.sheetId}"] [data-testid="sheet-title"]`
		)!.click()
		await flushPromises()
		return wrapper
	}

	async function openTab(key: 'transactions' | 'approvals' | 'overview' | 'history'): Promise<void> {
		await document.body.querySelector<HTMLElement>(`[data-testid="sheet-details-modal-tab-${key}"]`)!.click()
		await flushPromises()
	}

	it('exposes the same four canonical tabs as Cost Center and Senior (UXC-7-03)', async () => {
		seedDetails(['a'])
		await mountModal(sheetItem('sheet-1', [summary('a')]))
		for (const key of ['transactions', 'approvals', 'overview', 'history'] as const) {
			expect(document.body.querySelector(`[data-testid="sheet-details-modal-tab-${key}"]`)).not.toBeNull()
		}
	})

	it('renders the queue-provided whole-sheet stageApprovedTotals for all three stages (UXC-7F-05)', async () => {
		seedDetails(['a', 'b'])
		// The queue items carry the identical whole-sheet aggregate; the grouped sheet consumes it.
		await mountModal(withSheetTotals(sheetItem('sheet-1', [summary('a'), summary('b')]), stageTotals()))
		await openTab('approvals')

		const panel = document.body.querySelector('[data-testid="sheet-details-modal-panel-approvals"]')
		expect(panel).not.toBeNull()
		// The shared sheet-level summary, with each workflow stage separately identified.
		expect(panel!.querySelector('[data-testid="sheet-approvals-summary"]')).not.toBeNull()
		// Canonical stage order, shared with Cost Center and Senior (cross-role requirement 15).
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
			['costCenter', '100,000'],
			['senior', '50,000'],
			['finance', '25,000'],
		]
		for (const [key, amount] of expected) {
			expect(stageTotal(key)).not.toBeNull()
			expect(stageTotal(key)!.textContent).toContain('IRR')
			expect(stageTotal(key)!.textContent).toContain(amount)
			expect(panel!.querySelector(`[data-testid="sheet-approvals-stage-${key}-unavailable"]`)).toBeNull()
		}
		// Per-transaction decision history has no place on the sheet-level tab: no transaction
		// cards, no decision sections, and no individual actor data — 'Manager 1' is a seeded
		// decision actor's display name, distinct from the canonical stage labels (cross-role 17).
		expect(panel!.querySelector('[data-testid^="transaction-review-card-"]')).toBeNull()
		expect(panel!.querySelector('[data-testid^="sheet-transaction-"]')).toBeNull()
		expect(panel!.querySelector('[data-testid^="inline-decisions-"]')).toBeNull()
		expect(panel!.textContent).not.toContain('Manager 1')
	})

	it('does not calculate whole-sheet values from the finance-visible transaction subset (UXC-7F-05)', async () => {
		// Cross-role requirement 11: the visible rows' decisions (one Finance-approved, one
		// senior-rejected, one returned) would re-derive a different set client-side; the server's
		// whole-sheet figures render untouched instead.
		const seniorReject = { ...SENIOR_APPROVAL, id: 'd-sen-rej', decision: 'REJECT' } as Decision
		const seniorReturn = { ...SENIOR_APPROVAL, id: 'd-sen-ret', decision: 'RETURN' } as Decision
		seedDetailsFor([
			detail('a', { decisions: [COST_CENTER_APPROVAL, SENIOR_APPROVAL, financeDecision('APPROVE')] }),
			detail('b', { decisions: [COST_CENTER_APPROVAL, seniorReject] }),
			detail('c', { decisions: [COST_CENTER_APPROVAL, seniorReturn] }),
		])
		await mountModal(withSheetTotals(sheetItem('sheet-1', [summary('a'), summary('b'), summary('c')]), stageTotals({
			costCenterApproved: { amountMinor: 999_999, currency: 'IRR', minorUnit: 0 },
			seniorApproved: { amountMinor: 888_888, currency: 'IRR', minorUnit: 0 },
			financeApproved: { amountMinor: 777_777, currency: 'IRR', minorUnit: 0 },
		})))
		await openTab('approvals')

		const expected: Array<[string, string]> = [
			['costCenter', '999,999'],
			['senior', '888,888'],
			['finance', '777,777'],
		]
		for (const [key, amount] of expected) {
			const total = document.body.querySelector(`[data-testid="sheet-approvals-stage-${key}-total"]`)
			expect(total).not.toBeNull()
			expect(total!.textContent).toContain(amount)
		}
	})

	it('same-Sheet items with identical aggregates render normally (UXC-7F-05)', async () => {
		seedDetails(['a', 'b'])
		// Both queue items carry the same aggregate (the server's guarantee); the sheet renders it
		// once, in the sheet's currency — never one total row per item.
		await mountModal(withSheetTotals(sheetItem('sheet-1', [summary('a'), summary('b')]), stageTotals()))
		await openTab('approvals')

		const panel = document.body.querySelector('[data-testid="sheet-details-modal-panel-approvals"]')
		expect(panel!.querySelectorAll('[data-testid="sheet-approvals-stage-costCenter-total"]')).toHaveLength(1)
		expect(panel!.querySelectorAll('[data-testid="sheet-approvals-stage-senior-total"]')).toHaveLength(1)
		expect(panel!.querySelectorAll('[data-testid="sheet-approvals-stage-finance-total"]')).toHaveLength(1)
		expect(panel!.querySelector('[data-testid="sheet-approvals-stage-costCenter-unavailable"]')).toBeNull()
	})

	it('renders every stage unavailable when the queue carries no aggregate (UXC-7F-05)', async () => {
		// No detail reads seeded and no aggregate on the items: nothing here may stand in for the
		// whole-sheet figures — the pre-7F derivation would have shown a Finance-stage answer.
		await mountModal(sheetItem('sheet-1', [summary('a'), summary('b')]))
		await openTab('approvals')

		for (const stage of ['costCenter', 'senior', 'finance'] as const) {
			expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-unavailable"]`)).not.toBeNull()
			expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-total"]`)).toBeNull()
		}
	})

	it('renders zero aggregates as known zeros, distinct from unavailable (UXC-7F-05)', async () => {
		await mountModal(withSheetTotals(sheetItem('sheet-1', [summary('a')]), stageTotals({
			costCenterApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
			seniorApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
			financeApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
		})))
		await openTab('approvals')

		for (const stage of ['costCenter', 'senior', 'finance'] as const) {
			const total = document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-total"]`)
			expect(total, `a zero ${stage} total is a real answer`).not.toBeNull()
			expect(total!.textContent).toContain('0')
			expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-unavailable"]`)).toBeNull()
			expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-none"]`)).toBeNull()
		}
	})

	it('conflicting same-Sheet aggregates never produce an arbitrary total (UXC-7F-05)', async () => {
		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		// Two queue items of one sheet disagree — an inconsistency the queue must not resolve by
		// picking a side: the aggregate is treated as unavailable and the conflict is reported.
		const item = sheetItem('sheet-1', [summary('a'), summary('b')])
		const conflicting = [
			{ ...item.transactions[0]!, stageApprovedTotals: stageTotals() },
			{ ...item.transactions[1]!, stageApprovedTotals: stageTotals({ costCenterApproved: { amountMinor: 999_999, currency: 'IRR', minorUnit: 0 } }) },
		]
		await mountModal({ ...item, transactions: conflicting } as FinanceSheetQueueItem)
		await openTab('approvals')

		for (const stage of ['costCenter', 'senior', 'finance'] as const) {
			expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-unavailable"]`)).not.toBeNull()
			expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-total"]`)).toBeNull()
		}
		expect(warn).toHaveBeenCalledWith(expect.stringContaining('conflicting stageApprovedTotals'))
	})

	it('keeps one single-currency total per stage even when visible rows mix currencies (UXC-7F-05)', async () => {
		// A Cost Sheet is single-currency; the server sends one Money per stage. Mixed-currency
		// rows in the finance-visible subset never turn into a per-currency total list here.
		await mountModal(withSheetTotals(sheetItem('sheet-1', [
			summary('a', { currency: 'EUR', amountMinor: 1200 }),
			summary('b', { currency: 'USD', amountMinor: 800 }),
		]), stageTotals()))
		await openTab('approvals')

		const panel = document.body.querySelector('[data-testid="sheet-details-modal-panel-approvals"]')
		const ccTotals = [...panel!.querySelectorAll('[data-testid="sheet-approvals-stage-costCenter-total"]')]
		expect(ccTotals).toHaveLength(1)
		expect(ccTotals[0]!.textContent).toContain('IRR')
		expect(ccTotals[0]!.textContent).not.toContain('EUR')
		expect(ccTotals[0]!.textContent).not.toContain('USD')
	})

	it('Overview stays sheet-level and History stays an honest sheet-level empty state', async () => {
		seedDetails(['a'])
		await mountModal(sheetItem('sheet-1', [summary('a')]))

		await openTab('overview')
		const overview = document.body.querySelector('[data-testid="finance-tab-overview"]')
		expect(overview).not.toBeNull()
		expect(overview!.querySelector('[data-testid^="transaction-review-card-"]')).toBeNull()

		await openTab('history')
		expect(document.body.querySelector('[data-testid="finance-tab-history-empty"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="finance-tab-history-empty"]')!.textContent)
			.toContain('reviews.sheetHistory.unavailable')
		expect(document.body.querySelector('[data-testid="finance-tab-history-empty"]')!.textContent)
			.not.toContain('reviews.noTransactionsInQueue')
	})
})

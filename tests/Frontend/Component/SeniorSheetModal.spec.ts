// Feature 007 Senior Cost Sheet review through the SHARED review primitives
// (retargeted at UXC-8-01 from the removed SeniorSheetModal suite; same pattern as the
// FinanceSheetModal.spec.ts retarget at UXC-5).
//
// The queue stays mounted while the shared SheetDetailsModal overlays it via the Senior stage
// adapter; the transaction list renders one shared TransactionReviewCard per transaction with the
// Cost Center decision at transaction level in the card's indicator row (UXC-8-03); the
// transaction title opens the shared full-details drawer; decisions confirm through the shared
// decision confirmation; evidence previews through the shared preview layer. The former SeniorSheetModal assertions are preserved against the live view they
// now exercise — the suite keeps its filename because UXC-2-05/UXC-2-06 designate it as the Senior
// decision-semantics regression suite.
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createRouter, createWebHashHistory, type Router } from 'vue-router'
import SeniorQueueView from '@/views/reviews/SeniorQueueView.vue'
import { routes } from '@/router'
import { useReviewsStore } from '@/stores/reviews'
import { ApiError } from '@/types/api'
import type {
	Decision,
	DecisionResult,
	DecisionValue,
	SeniorSheetListRow,
	TransactionDetail,
	TransactionSummary,
} from '@/types/api'

const ocsRequest = vi.hoisted(() => vi.fn())

const evidenceApi = vi.hoisted(() => ({ getEvidenceContent: vi.fn() }))
vi.mock('@/services/evidenceApi', () => ({ getEvidenceContent: evidenceApi.getEvidenceContent }))

/** T022/T023: the store's guarded XLSX export request, stubbed so the refusal path is controlled. */
const requestExportMock = vi.hoisted(() => vi.fn())
vi.mock('@/services/reportsApi', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@/services/reportsApi')>()
	return { ...actual, requestExport: requestExportMock }
})

const originalObjectURL = URL.createObjectURL

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

function ocsEnvelope(data: unknown) {
	return { status: 200, data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data } } }
}

const COST_CENTER_APPROVAL: Decision = {
	id: 'd-cc', stage: 'COST_CENTER', decision: 'APPROVE',
	actor: { userId: 'mgr1', displayName: 'Manager 1', role: 'COST_CENTER_MANAGER' },
	decidedAt: '2026-09-20T10:00:00Z',
}

function seniorDecision(kind: DecisionValue): Decision {
	return {
		id: 'd-sen', stage: 'SENIOR', decision: kind,
		actor: { userId: 'sr1', displayName: 'Senior Manager', role: 'SENIOR_MANAGER' },
		decidedAt: '2026-09-21T10:00:00Z',
	}
}

function revision(id: string) {
	return {
		id, number: 1, expenseDate: '2026-09-20',
		amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
		description: 'Expense',
		costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center' },
		expenseType: { id: 'et-1', name: 'Travel' },
		createdBy: 'user1', createdAt: '2026-09-20T10:00:00Z', active: true,
	}
}

function summary(id: string, overrides: {
	description?: string
	amountMinor?: number
	currency?: string
	expenseType?: { id: string; name: string }
	evidenceCount?: number
	expenseDate?: string
} = {}): TransactionSummary {
	return {
		id, sheetId: 'sheet-1',
		activeRevision: {
			...revision(`rev-${id}`),
			description: overrides.description ?? 'Expense',
			expenseDate: overrides.expenseDate ?? '2026-09-20',
			amount: {
				amountMinor: overrides.amountMinor ?? 50000,
				currency: overrides.currency ?? 'IRR',
				minorUnit: 0,
			},
			expenseType: overrides.expenseType ?? { id: 'et-1', name: 'Travel' },
		},
		stage: 'SENIOR', status: 'AWAITING_SENIOR_REVIEW', finalOutcome: null,
		latestDecision: COST_CENTER_APPROVAL, evidenceRequired: false,
		evidenceCount: overrides.evidenceCount ?? 1, version: 0,
	}
}

function detail(id: string, overrides: Record<string, unknown> = {}): TransactionDetail {
	return {
		...summary(id),
		allowedActions: { canApprove: true, canReject: true, canReturn: true },
		decisions: [COST_CENTER_APPROVAL],
		evidence: [{
			id: `ev-${id}`, transactionId: id, revisionId: `rev-${id}`, name: `receipt-${id}.png`,
			mediaType: 'image/png', sizeBytes: 100, active: true, uploadedBy: 'user1',
			uploadedAt: '2026-09-20T10:00:00Z',
		}],
		...overrides,
	}
}

function decidedResult(id: string, kind: DecisionValue = 'APPROVE'): DecisionResult {
	const decision = seniorDecision(kind)
	return {
		decision,
		// The server's own post-decision view: the CC approval stays byte-identical and the new
		// Senior decision is appended, so the no-overwrite invariant is observable here.
		transaction: detail(id, {
			version: 1,
			status: 'AWAITING_FINANCE_REVIEW',
			decisions: [COST_CENTER_APPROVAL, decision],
		}),
	}
}

/** One `GET /queues/senior/sheets` list row, with the server-supplied identity/status the view renders. */
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

/** One per-sheet read row: the transaction plus the Senior presentation state the read supplies. */
function modalRow(
	id: string,
	overrides: Parameters<typeof summary>[1] = {},
	seniorState: 'AWAITING_SENIOR_DECISION' | 'DECIDED_BY_SENIOR' = 'AWAITING_SENIOR_DECISION',
): TransactionSummary & { seniorState: 'AWAITING_SENIOR_DECISION' | 'DECIDED_BY_SENIOR'; seniorDecision: Decision | null } {
	return {
		...summary(id, overrides),
		seniorState,
		seniorDecision: seniorState === 'DECIDED_BY_SENIOR' ? seniorDecision('APPROVE') : null,
	}
}

describe('Senior sheet modal over the shared shell (US2)', () => {
	let pinia: Pinia
	let router: Router
	let reviewsStore: ReturnType<typeof useReviewsStore>
	let recordDecision: ReturnType<typeof vi.fn>

	beforeEach(() => {
		pinia = createPinia()
		router = createRouter({
			history: createWebHashHistory(),
			routes: routes.map(({ path, name, props }) => ({
				path,
				name,
				props,
				component: { template: '<div />' }
			}))
		})
		setActivePinia(pinia)
		reviewsStore = useReviewsStore()
		ocsRequest.mockReset()
		ocsRequest.mockImplementation((config: { url?: string }) => {
			// Tests either seed the per-sheet review cache (so the real store method returns it) or
			// mock `fetchSeniorSheetReview` explicitly; this fallback only absorbs unexpected reads.
			if (/\/transactions\//.test(config?.url ?? '')) {
				return Promise.resolve(ocsEnvelope({ items: [], page: 1, pageSize: 25, total: 0 }))
			}
			return Promise.resolve(ocsEnvelope({ items: [], page: 1, pageSize: 25, total: 0 }))
		})
		// The per-sheet read is a no-op by default: tests seed `seniorSheetReviews` directly (the
		// view's review computed reads that cache), and a forced post-decision refetch must not
		// clear the cache through this suite's generic axios fallback. The unavailable/loading/retry
		// states override this mock via `mountModalWithRead`.
		reviewsStore.fetchSeniorSheetReview = vi.fn().mockResolvedValue(undefined)
		recordDecision = vi.fn()
		reviewsStore.recordDecision = recordDecision as never
		requestExportMock.mockReset()
		// The preview layer fetches inline content through `getEvidenceContent` (a native fetch, not
		// axios); a blob and object-URL stub keep the inline renderers happy without a real request.
		evidenceApi.getEvidenceContent.mockReset().mockResolvedValue(new Blob(['tiny']))
		URL.createObjectURL = vi.fn(() => 'blob:preview') as unknown as typeof URL.createObjectURL
	})

	afterEach(() => {
		document.body.innerHTML = ''
		URL.createObjectURL = originalObjectURL
	})

	/** Seeds the guarded per-transaction detail cache the prefetch and confirm read from. */
	function seedDetails(ids: string[], overrides: Record<string, unknown> = {}) {
		const details: Record<string, TransactionDetail> = {}
		const reads: Record<string, string> = {}
		for (const id of ids) {
			details[id] = detail(id, overrides)
			reads[id] = 'loaded'
		}
		reviewsStore.managerTransactionDetails = details as never
		reviewsStore.managerTransactionReads = reads as never
	}

	function evidence(id: string, mediaType: string, extra: Record<string, unknown> = {}) {
		return {
			id, transactionId: 'tx-1', revisionId: 'rev-tx-1', name: `file-${id}`,
			mediaType, sizeBytes: 100, active: true, uploadedBy: 'user1',
			uploadedAt: '2026-09-20T10:00:00Z',
			...extra,
		}
	}

	/** Seeds one transaction whose detail carries the given returned evidence set (metadata only). */
	function seedDetailWithEvidence(evidenceList: Array<Record<string, unknown>>) {
		const details: Record<string, TransactionDetail> = {
			'tx-1': detail('tx-1', { evidence: evidenceList }),
		}
		reviewsStore.managerTransactionDetails = details as never
		reviewsStore.managerTransactionReads = { 'tx-1': 'loaded' } as never
	}

	/** Opens the preview at the document whose card thumbnail is clicked (FR-007-031). */
	async function openPreviewFromThumbnail(testId: string): Promise<void> {
		document.body.querySelector<HTMLButtonElement>(`[data-testid="${testId}"]`)?.click()
		await flushPromises()
	}

	async function mountSeniorQueue(): Promise<VueWrapper> {
		await router.push('/reviews/senior')
		await router.isReady()
		// Attached to the document so focus restore from the teleported overlays can be verified.
		return mount(SeniorQueueView, {
			global: { plugins: [pinia, router] },
			attachTo: document.body
		})
	}

	/**
	 * Seeds the senior queue with one row, mounts the live view, seeds the per-sheet review cache
	 * (after mount: the view's refresh clears cached reads), then opens sheet-1 from its row title.
	 */
	async function mountModal(
		row: Partial<SeniorSheetListRow> = {},
		transactions: Array<ReturnType<typeof modalRow>> = [],
		progress?: { awaitingSenior: number; decidedBySenior: number; total: number },
	): Promise<VueWrapper> {
		reviewsStore.seniorQueue = { items: [seniorSheetRow('sheet-1', row)], total: 1, page: 1, pageSize: 100 } as never
		reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
		const wrapper = await mountSeniorQueue()
		// `modalRow` already supplies the read's `seniorState`/`seniorDecision` presentation fields.
		const rows = transactions
		reviewsStore.seniorSheetReviews = {
			'sheet-1': {
				sheet: seniorSheetRow('sheet-1', row),
				progress: progress ?? {
					awaitingSenior: rows.filter((r) => r.seniorState === 'AWAITING_SENIOR_DECISION').length,
					decidedBySenior: rows.filter((r) => r.seniorState === 'DECIDED_BY_SENIOR').length,
					total: rows.length,
				},
				transactions: rows,
			},
		} as never
		await wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-title"]').trigger('click')
		await flushPromises()
		return wrapper
	}

	/**
	 * Opens the sheet with `fetchSeniorSheetReview` mocked instead of seeded — the unavailable,
	 * loading and retry states need to control the read itself.
	 */
	async function mountModalWithRead(
		read: (sheetId: string) => Promise<unknown>,
		row: Partial<SeniorSheetListRow> = {},
	): Promise<VueWrapper> {
		reviewsStore.seniorQueue = { items: [seniorSheetRow('sheet-1', row)], total: 1, page: 1, pageSize: 100 } as never
		reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
		reviewsStore.fetchSeniorSheetReview = vi.fn(read) as never
		const wrapper = await mountSeniorQueue()
		await wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-title"]').trigger('click')
		await flushPromises()
		return wrapper
	}

	function cardButton(transactionId: string, testId: string): HTMLButtonElement | null {
		return document.body.querySelector(
			`[data-testid="transaction-review-card-${transactionId}"] [data-testid="${testId}"]`
		)
	}

	/** Opens the shared confirmation modal for one transaction and returns its confirm button. */
	async function openConfirm(transactionId: string, kind: DecisionValue): Promise<HTMLButtonElement> {
		const trigger = cardButton(transactionId, kind === 'APPROVE' ? 'transaction-card-decide-approve' : `transaction-card-decide-${kind.toLowerCase()}`)
		expect(trigger, `a ${kind} trigger exists for ${transactionId}`).not.toBeNull()
		trigger?.focus()
		trigger?.click()
		await nextTick()
		await flushPromises()
		const modal = document.body.querySelector('[data-testid="decision-modal"]')
		expect(modal, 'the shared decision modal opens').not.toBeNull()
		const confirm = modal?.querySelector<HTMLButtonElement>('[data-testid="decision-confirm-btn"]')
		expect(confirm).not.toBeNull()
		return confirm as HTMLButtonElement
	}

	it('renders list-row identity and an honest unavailable state when the per-sheet read fails', async () => {
		// A list row carries no transactions; if its per-sheet read fails the modal must still show
		// the server's identity and say the transaction read failed — never fabricate an empty sheet.
		await mountModalWithRead(async () => {
			reviewsStore.seniorSheetReadStates = {
				...reviewsStore.seniorSheetReadStates,
				'sheet-1': 'unavailable',
			} as never
			return null
		}, { title: 'Senior travel review', ownerDisplayName: 'User One', reviewStatus: 'PENDING_REVIEW' })

		expect(document.body.querySelector('[data-testid="sheet-details-modal-title"]')?.textContent?.trim())
			.toBe('Senior travel review')
		expect(document.body.querySelector('[data-testid="sheet-details-modal-owner"]')?.textContent?.trim())
			.toBe('User One')
		expect(document.body.querySelector('[data-testid="sheet-details-modal-unavailable"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="sheet-details-modal-loading"]')).toBeNull()
		// (The live view renders the generic no-results empty content below the unavailable panel;
		// the orphan's separate proven-empty element no longer exists.)
	})

	it('shows a loading state while the per-sheet read is in flight', async () => {
		await mountModalWithRead(async (sheetId: string) => {
			reviewsStore.seniorSheetReadStates = {
				...reviewsStore.seniorSheetReadStates,
				[sheetId]: 'loading',
			} as never
			// Never settles: the modal must render the in-flight state, not an empty result.
			return new Promise(() => {})
		})

		expect(document.body.querySelector('[data-testid="sheet-details-modal-loading"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="sheet-details-modal-unavailable"]')).toBeNull()
	})

	it('retries the per-sheet read with force from the unavailable state', async () => {
		const read = vi.fn(async () => {
			reviewsStore.seniorSheetReadStates = {
				...reviewsStore.seniorSheetReadStates,
				'sheet-1': 'unavailable',
			} as never
			return null
		})
		await mountModalWithRead(read)

		const retry = document.body.querySelector<HTMLButtonElement>('[data-testid="sheet-details-modal-retry"]')
		expect(retry).not.toBeNull()
		retry?.click()
		await flushPromises()

		expect(read).toHaveBeenLastCalledWith('sheet-1', true)
	})

	it('keeps a completed sheet read-only through its server-supplied decided transactions', async () => {
		seedDetails(['tx-1'])
		await mountModal(
			{ reviewStatus: 'REVIEW_COMPLETED' },
			[modalRow('tx-1', {}, 'DECIDED_BY_SENIOR')],
			{ awaitingSenior: 0, decidedBySenior: 1, total: 1 },
		)

		// The card presents the server's decided state through the Senior stage indicator and offers
		// no decision action at all.
		const seniorIndicator = document.body.querySelector('[data-testid="transaction-card-decision-senior"]')
		expect(seniorIndicator).not.toBeNull()
		expect(seniorIndicator?.textContent ?? '').toContain('reviews.outcome.approved')
		expect(document.body.querySelector('[data-testid="transaction-review-card-tx-1"] [data-testid="transaction-card-decide-approve"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="transaction-review-card-tx-1"] [data-testid="transaction-card-decide-reject"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="transaction-review-card-tx-1"] [data-testid="transaction-card-decide-return"]')).toBeNull()
	})

	it('renders the sheet transactions as decisions-ready cards with the CC advisory at transaction level only', async () => {
		seedDetails(['tx-1', 'tx-2'])
		await mountModal({}, [modalRow('tx-1'), modalRow('tx-2')])

		// One card per transaction over the proven-complete set, none decided yet.
		expect(document.body.querySelector('[data-testid="transaction-review-card-tx-1"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="transaction-review-card-tx-2"]')).not.toBeNull()
		expect(document.body.querySelectorAll('[data-testid^="transaction-review-card-"]')).toHaveLength(2)

		// FR-034: the CC decision is visible to the senior at transaction level only — one Cost
		// Center indicator per transaction, never a sheet verdict.
		const ccIndicators = document.body.querySelectorAll('[data-testid="transaction-card-decision-costCenter"]')
		expect(ccIndicators).toHaveLength(2)
		expect(ccIndicators[0]?.textContent ?? '').toContain('reviews.outcome.approved')

		// The card's own decision actions are enabled only on the server-granted capability (T014).
		expect((cardButton('tx-1', 'transaction-card-decide-approve') as HTMLButtonElement).disabled).toBe(false)
		expect(document.body.querySelector('[data-testid="transaction-card-decision-senior"]')?.textContent ?? '')
			.not.toContain('reviews.outcome.approved')
	})

	it('shows the server-supplied sheet progress above the transaction list', async () => {
		await mountModal(
			{},
			[modalRow('tx-1'), modalRow('tx-2', {}, 'DECIDED_BY_SENIOR')],
			{ awaitingSenior: 1, decidedBySenior: 1, total: 2 },
		)

		// Progress comes from the per-sheet read, never from a client-derived card count.
		const progress = document.body.querySelector('[data-testid="sheet-details-modal-progress"]')
		expect(progress).not.toBeNull()
		expect(progress?.textContent ?? '').toContain('reviews.seniorSheet.modal.progress')
	})

	it('keeps sort and clear in the results row, and export in the header actions beside close', async () => {
		// The former section title + result count badge was restructured in the shared-shell
		// migration; the derived per-facet counts now live on the expense-type chips and the
		// composable suite covers the facet counting itself.
		await mountModal({}, [modalRow('tx-1')])

		// Sort and clear share one compact results row; export has moved to the modal header.
		const results = document.body.querySelector('[data-testid="senior-results-toolbar"]')
		expect(results).not.toBeNull()
		expect(results?.querySelector('[data-testid="senior-sort-panel"]')).not.toBeNull()
		expect(results?.querySelector('[data-testid="senior-filter-clear"]')).not.toBeNull()
		expect(results?.querySelector('[data-testid="senior-export-btn"]')).toBeNull()

		const exportButton = document.body.querySelector('[data-testid="senior-export-btn"]')
		const closeButton = document.body.querySelector('[data-testid="sheet-details-modal-close"]')
		expect(exportButton).not.toBeNull()
		expect(closeButton).not.toBeNull()
		// Both header actions are siblings, so the export sits beside the close control.
		expect(exportButton?.parentElement).toBe(closeButton?.parentElement)
		expect(exportButton?.parentElement?.getAttribute('data-testid')).toBe('sheet-details-modal-actions')

		// The filter panel owns the filter fields only.
		expect(document.body.querySelector('[data-testid="senior-filter-panel"] [data-testid="senior-filter-clear"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="senior-filter-panel"] [data-testid="senior-export-btn"]')).toBeNull()
	})

	it('records exactly one decision per action, lets the card re-render decided from the server response, and leaves the CC decision unchanged', async () => {
		recordDecision.mockResolvedValue(decidedResult('tx-1'))
		seedDetails(['tx-1'])
		await mountModal({}, [modalRow('tx-1')])

		const confirm = await openConfirm('tx-1', 'APPROVE')
		// Approve comment is optional: an empty note still posts.
		confirm.click()
		await flushPromises()

		// Exactly one decision recorded, with the expected optimistic version and a command identity
		// (FR-085); the payload carries only the decision fields — nothing touches the CC decision.
		expect(recordDecision).toHaveBeenCalledTimes(1)
		expect(recordDecision).toHaveBeenCalledWith('tx-1', {
			decision: 'APPROVE',
			explanation: '',
			expectedVersion: 0,
		}, { commandId: expect.any(String) })

		// The card re-renders from the server's `result.transaction`: the Senior indicator shows the
		// recorded outcome and the action bar is gone.
		const seniorIndicator = document.body.querySelector('[data-testid="transaction-card-decision-senior"]')
		expect(seniorIndicator?.textContent ?? '').toContain('reviews.outcome.approved')
		expect(document.body.querySelector('[data-testid="transaction-review-card-tx-1"] [data-testid="transaction-card-decide-approve"]')).toBeNull()
		// The confirmation modal is closed again.
		expect(document.body.querySelector('[data-testid="decision-modal"]')).toBeNull()

		// FR-007-004 / no-overwrite: the CC decision is still present and byte-identical — the Cost
		// Center indicator still shows the CC approval, and the server decision history keeps both
		// stages.
		expect(document.body.querySelector('[data-testid="transaction-card-decision-costCenter"]')?.textContent ?? '')
			.toContain('reviews.outcome.approved')
		expect(recordDecision.mock.calls[0]?.[1]).not.toHaveProperty('costCenterDecision')

		// The post-decision refresh reads the sheet-list endpoint through the store's default
		// all-pages call — not the old transaction queue parameters.
		expect(reviewsStore.fetchSeniorQueue).toHaveBeenCalledWith()
	})

	it('refuses a reject without an explanation and posts nothing until one is written (FR-033)', async () => {
		recordDecision.mockResolvedValue(decidedResult('tx-1', 'REJECT'))
		seedDetails(['tx-1'])
		await mountModal({}, [modalRow('tx-1')])

		const confirm = await openConfirm('tx-1', 'REJECT')
		confirm.click()
		await flushPromises()

		// The guard fires inside the shared modal; no decision is issued.
		expect(document.body.querySelector('[data-testid="decision-guard-error"]')).not.toBeNull()
		expect(recordDecision).not.toHaveBeenCalled()

		// Once the mandatory note is written the same confirmation posts exactly once.
		const note = document.body.querySelector<HTMLTextAreaElement>('[data-testid="decision-note-input"]')
		expect(note).not.toBeNull()
		if (note) {
			note.value = 'Amount does not match the receipt.'
			note.dispatchEvent(new Event('input', { bubbles: true }))
		}
		await nextTick()
		await flushPromises()
		confirm.click()
		await flushPromises()

		expect(recordDecision).toHaveBeenCalledTimes(1)
		expect(recordDecision).toHaveBeenCalledWith('tx-1', {
			decision: 'REJECT',
			explanation: 'Amount does not match the receipt.',
			expectedVersion: 0,
		}, { commandId: expect.any(String) })
	})

	it('records exactly one return with its mandatory explanation', async () => {
		recordDecision.mockResolvedValue(decidedResult('tx-1', 'RETURN'))
		seedDetails(['tx-1'])
		await mountModal({}, [modalRow('tx-1')])

		const confirm = await openConfirm('tx-1', 'RETURN')
		// Writing the note clears the guard so the same modal can submit.
		confirm.click()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="decision-guard-error"]')).not.toBeNull()
		expect(recordDecision).not.toHaveBeenCalled()

		const note = document.body.querySelector<HTMLTextAreaElement>('[data-testid="decision-note-input"]')
		if (note) {
			note.value = 'Please itemise the receipt.'
			note.dispatchEvent(new Event('input', { bubbles: true }))
		}
		await nextTick()
		await flushPromises()
		confirm.click()
		await flushPromises()

		expect(recordDecision).toHaveBeenCalledTimes(1)
		expect(recordDecision).toHaveBeenCalledWith('tx-1', {
			decision: 'RETURN',
			explanation: 'Please itemise the receipt.',
			expectedVersion: 0,
		}, { commandId: expect.any(String) })
	})

	it('keeps the modal open with its own words on a 409 superseded attempt and never re-renders a local decided state (FR-085, T014)', async () => {
		recordDecision.mockRejectedValue({ status: 409, body: { code: 'SUPERSEDED_ATTEMPT' } })
		seedDetails(['tx-1'])
		await mountModal({}, [modalRow('tx-1')])

		const confirm = await openConfirm('tx-1', 'APPROVE')
		confirm.click()
		await flushPromises()

		// The refusal is rendered distinctly (a version conflict is retryable, unlike a refusal) and
		// the modal stays open so the user can review the current version.
		const error = document.body.querySelector('[data-testid="decision-submit-error"]')
		expect(error).not.toBeNull()
		expect(error?.textContent ?? '').toContain('reviews.quickReview.decision.versionConflict')
		expect(document.body.querySelector('[data-testid="decision-modal"]')).not.toBeNull()
		// No local decided state is fabricated: the Senior indicator stays undecided and the card
		// still offers the decision actions.
		expect(document.body.querySelector('[data-testid="transaction-card-decision-senior"]')?.textContent ?? '')
			.not.toContain('reviews.outcome.approved')
		expect(document.body.querySelector('[data-testid="transaction-review-card-tx-1"] [data-testid="transaction-card-decide-approve"]')).not.toBeNull()
	})

	it('maps an authorization refusal to its own words (T014)', async () => {
		recordDecision.mockRejectedValue({ status: 403, body: { code: 'FORBIDDEN' } })
		seedDetails(['tx-1'])
		await mountModal({}, [modalRow('tx-1')])

		const confirm = await openConfirm('tx-1', 'APPROVE')
		confirm.click()
		await flushPromises()

		const error = document.body.querySelector('[data-testid="decision-submit-error"]')
		expect(error).not.toBeNull()
		expect(error?.textContent ?? '').toContain('reviews.quickReview.decision.forbidden')
	})

	it('expands full detail in place inside the modal, shows per-transaction CC and Senior history, and restores focus on collapse (UXC-V2-10)', async () => {
		seedDetails(['tx-1'])
		await mountModal({}, [modalRow('tx-1')])

		const detailsBtn = cardButton('tx-1', 'transaction-card-title')
		expect(detailsBtn).not.toBeNull()
		detailsBtn?.focus()
		detailsBtn?.click()
		await flushPromises()

		// The expansion opens in place inside the still-mounted modal — no second full-height layer,
		// with the shared decide row for the awaiting transaction (T013 semantics preserved).
		expect(document.body.querySelector('[data-testid="transaction-expanded-detail"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="sheet-details-modal"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="inline-decide-approve"]')).not.toBeNull()

		// Previous Decisions renders stage-separated per transaction (UXC-4-04) — the CC approval
		// here in the Cost Center group (FR-034 / FR-007-004).
		const ccGroup = document.body.querySelector('[data-testid="inline-decisions-cost-center"]')
		expect(ccGroup).not.toBeNull()
		expect(ccGroup?.querySelector('[data-testid="decision-item-d-cc"]')).not.toBeNull()
		// The group heading carries the canonical stage label; the decision item keeps its own
		// actor and outcome.
		expect(ccGroup?.textContent ?? '').toContain('reviews.decisionStage.costCenter')
		expect(document.body.querySelector('[data-testid="decision-item-d-cc"]')?.textContent ?? '')
			.toContain('reviews.decision.approve')

		// Collapsing the expansion restores focus to the toggle that opened it.
		document.body.querySelector<HTMLButtonElement>('[data-testid="transaction-card-title"]')?.click()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="transaction-expanded-detail"]')).toBeNull()
		expect(document.activeElement?.closest('[data-testid="transaction-review-card-tx-1"]')?.getAttribute('data-testid'))
			.toBe('transaction-review-card-tx-1')
	})

	it('shows the parent modal sheet header in the full-details drawer metadata (BUG-05)', async () => {
		// The additive Senior read carries the sheet header the modal header already renders; the
		// drawer must present the same title/owner instead of the unavailable fallbacks.
		seedDetails(['tx-1'])
		await mountModal(
			{ title: 'Senior travel review', ownerDisplayName: 'User One' },
			[modalRow('tx-1')],
		)

		// The parent header shows the read's title (controls kept / owner kept).
		expect(document.body.querySelector('[data-testid="sheet-details-modal-title"]')?.textContent?.trim())
			.toBe('Senior travel review')
		expect(document.body.querySelector('[data-testid="sheet-details-modal-owner"]')?.textContent?.trim())
			.toBe('User One')

		cardButton('tx-1', 'transaction-card-title')?.click()
		await flushPromises()
		await nextTick()

		const sheetField = document.body.querySelector('[data-testid="inline-field-sheet"]')
		expect(sheetField).not.toBeNull()
		expect(sheetField?.textContent ?? '').toContain('Senior travel review')
		expect(sheetField?.textContent ?? '').not.toContain('reviews.sheetQueue.sheetTitleUnavailable')
		const purchaserField = document.body.querySelector('[data-testid="inline-field-purchaser"]')
		expect(purchaserField?.textContent ?? '').toContain('User One')
		expect(purchaserField?.textContent ?? '').not.toContain('reviews.sheetQueue.purchaserUnavailable')
	})

	it('routes the expanded decide row through the same single confirmation modal (UXC-V2-10)', async () => {
		recordDecision.mockResolvedValue(decidedResult('tx-1'))
		seedDetails(['tx-1'])
		await mountModal({}, [modalRow('tx-1')])

		cardButton('tx-1', 'transaction-card-title')?.click()
		await flushPromises()
		document.body.querySelector<HTMLButtonElement>('[data-testid="inline-decide-approve"]')?.click()
		await nextTick()
		await flushPromises()

		expect(document.body.querySelector('[data-testid="decision-modal"]')).not.toBeNull()
		document.body.querySelector<HTMLButtonElement>('[data-testid="decision-confirm-btn"]')?.click()
		await flushPromises()

		expect(recordDecision).toHaveBeenCalledTimes(1)
		expect(recordDecision).toHaveBeenCalledWith('tx-1', {
			decision: 'APPROVE',
			explanation: '',
			expectedVersion: 0,
		}, { commandId: expect.any(String) })
	})

	describe('preview layer (US3)', () => {
		it('opens a large inline image preview at the exact document the thumbnail represents (T016)', async () => {
			seedDetailWithEvidence([evidence('ev-1', 'image/png')])
			await mountModal({}, [modalRow('tx-1')])

			// The card renders the returned evidence set as a thumbnail row (T016), never an inline
			// renderer of untrusted content.
			expect(document.body.querySelector('[data-testid="transaction-card-attachments"]')).not.toBeNull()
			expect(document.body.querySelector('[data-testid="transaction-card-thumb-ev-1"]')).not.toBeNull()

			await openPreviewFromThumbnail('transaction-card-thumb-ev-1')

			// The shared preview opens above the still-mounted modal (single overlay) and renders the
			// document inline from the guarded content read.
			expect(document.body.querySelector('[data-test="evidence-preview-layer"]')).not.toBeNull()
			expect(document.body.querySelector('[data-test="evidence-preview-image"]')).not.toBeNull()
			expect(evidenceApi.getEvidenceContent).toHaveBeenCalledWith('ev-1', 'inline')
			expect(document.body.querySelector('[data-test="preview-download-btn"]')).not.toBeNull()
			expect(document.body.querySelector('[data-testid="sheet-details-modal"]')).not.toBeNull()
		})

		it('renders a PDF with the inline viewer, not as a bare download link (T016)', async () => {
			seedDetailWithEvidence([evidence('ev-1', 'application/pdf')])
			await mountModal({}, [modalRow('tx-1')])

			await openPreviewFromThumbnail('transaction-card-thumb-ev-1')

			expect(document.body.querySelector('[data-test="evidence-preview-pdf"]')).not.toBeNull()
			expect(document.body.querySelector('[data-test="evidence-preview-image"]')).toBeNull()
		})

		it('shows an explicit download-only state for a type the server will not serve inline, with no inline renderer and no content fetch (C-16)', async () => {
			seedDetailWithEvidence([evidence('ev-1', 'application/octet-stream')])
			await mountModal({}, [modalRow('tx-1')])

			await openPreviewFromThumbnail('transaction-card-thumb-ev-1')

			expect(document.body.querySelector('[data-test="preview-download-only"]')).not.toBeNull()
			expect(document.body.querySelector('[data-test="evidence-preview-image"]')).toBeNull()
			expect(document.body.querySelector('[data-test="evidence-preview-pdf"]')).toBeNull()
			expect(document.body.querySelector('[data-test="preview-download-btn"]')).not.toBeNull()
			expect(evidenceApi.getEvidenceContent).not.toHaveBeenCalled()
		})

		it('shows 1 / N navigation across the transaction set, disabling at the ends (T017)', async () => {
			seedDetailWithEvidence([
				evidence('ev-1', 'image/png'),
				evidence('ev-2', 'image/jpeg'),
				evidence('ev-3', 'image/gif'),
			])
			await mountModal({}, [modalRow('tx-1')])

			await openPreviewFromThumbnail('transaction-card-thumb-ev-1')

			const prev = document.body.querySelector<HTMLButtonElement>('[data-test="preview-nav-prev"]')
			const next = document.body.querySelector<HTMLButtonElement>('[data-test="preview-nav-next"]')
			expect(prev).not.toBeNull()
			expect(next).not.toBeNull()
			expect(document.body.querySelector('[data-test="preview-counter"]')).not.toBeNull()
			// At the first document the previous direction is disabled, the next is enabled.
			expect(prev?.disabled).toBe(true)
			expect(next?.disabled).toBe(false)

			next?.click()
			await flushPromises()
			// In the middle both directions are available.
			expect(document.body.querySelector<HTMLButtonElement>('[data-test="preview-nav-prev"]')?.disabled).toBe(false)
			expect(document.body.querySelector<HTMLButtonElement>('[data-test="preview-nav-next"]')?.disabled).toBe(false)

			document.body.querySelector<HTMLButtonElement>('[data-test="preview-nav-next"]')?.click()
			await flushPromises()
			// At the last document the next direction disables again, and the viewer follows the
			// requested document.
			expect(document.body.querySelector<HTMLButtonElement>('[data-test="preview-nav-prev"]')?.disabled).toBe(false)
			expect(document.body.querySelector<HTMLButtonElement>('[data-test="preview-nav-next"]')?.disabled).toBe(true)
			expect(document.body.querySelector('[data-test="evidence-preview-name"]')?.textContent).toBe('file-ev-3')
		})

		it('does not fetch or render content for inactive evidence — the note is informational only (C-16.8)', async () => {
			seedDetailWithEvidence([evidence('ev-1', 'image/png', { active: false })])
			await mountModal({}, [modalRow('tx-1')])

			await openPreviewFromThumbnail('transaction-card-thumb-ev-1')

			expect(document.body.querySelector('[data-test="preview-inactive-note"]')).not.toBeNull()
			expect(document.body.querySelector('[data-test="evidence-preview-image"]')).toBeNull()
			// Download is offered only for active rows; an inactive preview is read-only.
			expect(document.body.querySelector('[data-test="preview-download-btn"]')).toBeNull()
			expect(evidenceApi.getEvidenceContent).not.toHaveBeenCalled()
		})

		it('renders a single-document set with both navigation directions disabled, rather than a spurious multi-document control', async () => {
			seedDetailWithEvidence([evidence('ev-1', 'image/png')])
			await mountModal({}, [modalRow('tx-1')])

			await openPreviewFromThumbnail('transaction-card-thumb-ev-1')

			const prev = document.body.querySelector<HTMLButtonElement>('[data-test="preview-nav-prev"]')
			const next = document.body.querySelector<HTMLButtonElement>('[data-test="preview-nav-next"]')
			expect(prev).not.toBeNull()
			expect(next).not.toBeNull()
			expect(prev?.disabled).toBe(true)
			expect(next?.disabled).toBe(true)
		})

		it('shows a transaction with no returned attachments as an explicit empty state in both the card and the expansion (no fabricated tile)', async () => {
			seedDetailWithEvidence([])
			await mountModal({}, [modalRow('tx-1')])

			// No fabricated thumbnail row on the card.
			expect(document.body.querySelector('[data-testid="transaction-card-attachments"]')).toBeNull()

			cardButton('tx-1', 'transaction-card-title')?.click()
			await flushPromises()

			expect(document.body.querySelector('[data-testid="transaction-expanded-detail"]')).not.toBeNull()
			expect(document.body.querySelector('[data-testid="inline-no-attachments"]')).not.toBeNull()
			expect(document.body.querySelector('[data-testid="inline-attachments"]')).toBeNull()
		})
	})

	// ---- US4 helpers (T023), scoped to the outer describe so both US4 blocks share them. ----
	function cardIds(): string[] {
		return [...document.body.querySelectorAll('li[data-testid^="sheet-transaction-"]')]
			.map((node) => (node.getAttribute('data-testid') ?? '').replace('sheet-transaction-', ''))
	}

	function typedInput(testId: string, value: string): void {
		const input = document.body.querySelector<HTMLInputElement>(`[data-testid="${testId}"]`)
		expect(input, testId).not.toBeNull()
		if (input) {
			input.value = value
			input.dispatchEvent(new Event('input', { bubbles: true }))
		}
	}

	function clickTestId(testId: string): void {
		const node = document.body.querySelector<HTMLElement>(`[data-testid="${testId}"]`)
		expect(node, testId).not.toBeNull()
		node?.click()
	}

	describe('inline transaction expansion (UXC-V2-10)', () => {
		async function openExpansion(id: string): Promise<void> {
			cardButton(id, 'transaction-card-title')?.click()
			await flushPromises()
		}

		it('opens exactly one expansion at a time and binds the inline detail to the selected transaction', async () => {
			seedDetails(['tx-1', 'tx-2'])
			await mountModal({}, [modalRow('tx-1'), modalRow('tx-2')])

			await openExpansion('tx-1')
			const region = document.body.querySelector('[data-testid="transaction-expanded-detail"]')
			expect(region).not.toBeNull()
			expect(region?.querySelector('[data-testid="inline-field-sheet"]')).not.toBeNull()
			expect(document.body.querySelectorAll('[data-testid="sheet-details-modal-dialog"]')).toHaveLength(1)

			// Opening B collapses A — one expanded transaction at a time.
			await openExpansion('tx-2')
			const regions = document.body.querySelectorAll('[data-testid="transaction-expanded-detail"]')
			expect(regions).toHaveLength(1)
			expect(regions[0]?.querySelector('[data-testid="inline-field-sheet"]')).not.toBeNull()
			// The collapsed card's toggle reports the disclosure state honestly.
			expect(cardButton('tx-1', 'transaction-card-title')?.getAttribute('aria-expanded')).toBe('false')
			expect(cardButton('tx-2', 'transaction-card-title')?.getAttribute('aria-expanded')).toBe('true')
		})

		it('collapses on Escape inside the expanded card and keeps the modal open — the sheet-wide gate context is untouched', async () => {
			seedDetails(['tx-1'])
			await mountModal({}, [modalRow('tx-1')])

			await openExpansion('tx-1')
			expect(document.body.querySelector('[data-testid="transaction-expanded-detail"]')).not.toBeNull()

			document.body.querySelector('[data-testid="transaction-expanded-detail"]')?.dispatchEvent(
				new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })
			)
			await flushPromises()
			expect(document.body.querySelector('[data-testid="transaction-expanded-detail"]')).toBeNull()
			// The sheet modal stays open with its gate-passed sheet context.
			expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).not.toBeNull()
		})

		it('keeps a completed sheet read-only in the expansion: details visible, decide row withheld', async () => {
			seedDetails(['tx-1'])
			await mountModal({ reviewStatus: 'REVIEW_COMPLETED' }, [modalRow('tx-1', {}, 'DECIDED_BY_SENIOR')], { awaitingSenior: 0, decidedBySenior: 1, total: 1 })
			const wrapper = document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')
			expect(wrapper).not.toBeNull()

			await openExpansion('tx-1')
			const region = document.body.querySelector('[data-testid="transaction-expanded-detail"]')
			expect(region).not.toBeNull()
			expect(region?.querySelector('[data-testid="inline-field-sheet"]')).not.toBeNull()
			// Read-only: no decide row renders from the expanded surface either.
			expect(document.body.querySelector('[data-testid="inline-decide-approve"]')).toBeNull()
		})

		it('keeps the legacy drawer unreachable through normal Senior interaction', async () => {
			seedDetails(['tx-1', 'tx-2'])
			await mountModal({}, [modalRow('tx-1'), modalRow('tx-2')])

			await openExpansion('tx-1')
			await openExpansion('tx-2')
			// A thumbnail still opens the shared preview overlay above the still-mounted expansion.
			cardButton('tx-2', 'transaction-card-thumb-ev-tx-2')?.click()
			await flushPromises()
			expect(document.body.querySelector('[data-test="evidence-preview-layer"]')).not.toBeNull()
			document.body.querySelector('[data-test="evidence-preview"]')?.dispatchEvent(
				new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })
			)
			await flushPromises()
			// Every interaction produced the expansion or a shared overlay — never a second dialog frame.
			expect(document.body.querySelectorAll('[data-testid="sheet-details-modal-dialog"]')).toHaveLength(1)
		})
	})

	describe('filter and sort (US4)', () => {
		it('searches by transaction id or description, narrowing only the matching cards, with the approved placeholder (T020, owner D7)', async () => {
			await mountModal({}, [
				modalRow('tx-1', { description: 'Office supplies' }),
				modalRow('tx-2', { description: 'Air ticket to Tehran' }),
			])

			typedInput('senior-filter-search', 'ticket')
			await nextTick()
			await flushPromises()
			expect(cardIds()).toEqual(['tx-2'])

			// The placeholder is exactly the approved copy — no vendor phrasing (FR-007-015/041).
			expect(document.body.querySelector<HTMLInputElement>('[data-testid="senior-filter-search"]')?.placeholder)
				.toBe('reviews.seniorSheet.filter.searchPlaceholder')

			// A search with no match flips to the explicit no-results state.
			typedInput('senior-filter-search', 'no-such-term')
			await nextTick()
			await flushPromises()
			expect(document.body.querySelector('[data-testid="senior-filter-no-results"]')).not.toBeNull()
			expect(document.body.querySelector('[data-testid="transaction-review-cards"]')).toBeNull()
		})

		it('toggles expense-type facets as a multi-select with the derived category counts (T020)', async () => {
			await mountModal({}, [
				modalRow('tx-1', { expenseType: { id: 'et-1', name: 'Supplies' } }),
				modalRow('tx-2', { expenseType: { id: 'et-2', name: 'Travel' } }),
			])

			clickTestId('senior-filter-expense-type-et-1')
			await nextTick()
			await flushPromises()
			expect(cardIds()).toEqual(['tx-1'])

			// Selecting a second type widens again — a multi-select, not a single pick.
			clickTestId('senior-filter-expense-type-et-2')
			await nextTick()
			await flushPromises()
			expect(cardIds().sort()).toEqual(['tx-1', 'tx-2'])

			// The derived count renders on the facet chip itself (the standalone count badge was
			// restructured into the chips at the shared-shell migration; the composable suite covers
			// the facet counting arithmetic itself).
			const chip = document.body.querySelector('[data-testid="senior-filter-expense-type-et-1"]')
			expect(chip?.textContent ?? '').toContain('Supplies')
		})

		it('removes the currency, amount and attachment filters while keeping search and expense type', async () => {
			await mountModal({}, [modalRow('tx-1', { evidenceCount: 2 })])

			// The approved toolbar keeps exactly these two filter controls.
			expect(document.body.querySelector('[data-testid="senior-filter-search"]')).not.toBeNull()
			expect(document.body.querySelector('[data-testid="senior-filter-expense-type-et-1"]')).not.toBeNull()

			// The owner-approved removal is observable: none of the retired controls render.
			for (const removed of [
				'senior-filter-amount',
				'senior-filter-currency',
				'senior-filter-amount-min',
				'senior-filter-amount-max',
				'senior-filter-amount-unit',
				'senior-filter-has-attachments',
			]) {
				expect(document.body.querySelector(`[data-testid="${removed}"]`), removed).toBeNull()
			}
		})

		it('defaults to transaction date ascending and makes each criterion a direction toggle (T021)', async () => {
			await mountModal({}, [
				modalRow('tx-1', { amountMinor: 20_000, expenseDate: '2026-09-21', expenseType: { id: 'et-1', name: 'Alpha' } }),
				modalRow('tx-2', { amountMinor: 5_000, expenseDate: '2026-09-20', expenseType: { id: 'et-2', name: 'Beta' } }),
				modalRow('tx-3', { amountMinor: 12_000, expenseDate: '2026-09-22', expenseType: { id: 'et-3', name: 'Gamma' } }),
			])

			// Default order: transaction date ascending (oldest first).
			expect(cardIds()).toEqual(['tx-2', 'tx-1', 'tx-3'])

			const activeButton = (key: string): HTMLButtonElement | null =>
				document.body.querySelector<HTMLButtonElement>(`[data-testid="senior-sort-${key}"]`)

			// The active criterion announces criterion + direction (accessible text) and shows an arrow.
			expect(activeButton('expenseDate')?.getAttribute('aria-pressed')).toBe('true')
			expect(activeButton('expenseDate')?.textContent ?? '')
				.toContain('reviews.seniorSheet.sort.directionAsc')
			expect(activeButton('expenseDate')?.querySelector('svg')).not.toBeNull()
			// Inactive criteria carry no arrow or direction text.
			expect(activeButton('amount')?.querySelector('svg')).toBeNull()
			expect(activeButton('amount')?.textContent ?? '')
				.not.toContain('reviews.seniorSheet.sort.directionAsc')
			// The separate sort-direction button is gone.
			expect(document.body.querySelector('[data-testid^="senior-sort-direction-"]')).toBeNull()

			// Activating the already-active criterion toggles to descending.
			clickTestId('senior-sort-expenseDate')
			await nextTick()
			await flushPromises()
			expect(cardIds()).toEqual(['tx-3', 'tx-1', 'tx-2'])
			expect(activeButton('expenseDate')?.textContent ?? '')
				.toContain('reviews.seniorSheet.sort.directionDesc')
			expect(activeButton('expenseDate')?.querySelector('svg')).not.toBeNull()

			// Switching criteria always starts ascending.
			clickTestId('senior-sort-amount')
			await nextTick()
			await flushPromises()
			expect(cardIds()).toEqual(['tx-2', 'tx-3', 'tx-1'])
			expect(activeButton('amount')?.textContent ?? '')
				.toContain('reviews.seniorSheet.sort.directionAsc')

			// A subsequent click on the same criterion alternates to descending.
			clickTestId('senior-sort-amount')
			await nextTick()
			await flushPromises()
			expect(cardIds()).toEqual(['tx-1', 'tx-3', 'tx-2'])

			// Switching again starts ascending, not carrying the previous direction.
			clickTestId('senior-sort-expenseType')
			await nextTick()
			await flushPromises()
			expect(cardIds()).toEqual(['tx-1', 'tx-2', 'tx-3'])
			expect(activeButton('expenseType')?.textContent ?? '')
				.toContain('reviews.seniorSheet.sort.directionAsc')
		})
	})

	describe('export (T022)', () => {
		function clickExport(): void {
			clickTestId('senior-export-btn')
		}

		it('disables the export while a request is in flight and blocks the scrim close (FR-007-002)', async () => {
			let rejectExport: (reason: unknown) => void = () => {}
			requestExportMock.mockReturnValueOnce(new Promise((_resolve, rejectPromise) => { rejectExport = rejectPromise }))
			await mountModal({}, [modalRow('tx-1')])

			clickExport()
			await nextTick()

			expect(requestExportMock).toHaveBeenCalledTimes(1)
			expect(document.body.querySelector<HTMLButtonElement>('[data-testid="senior-export-btn"]')?.disabled).toBe(true)
			expect(document.body.querySelector<HTMLButtonElement>('[data-testid="senior-export-btn"]')?.textContent ?? '')
				.toContain('reviews.seniorSheet.export.busy')

			// While exporting the scrim must not close the modal.
			document.body.querySelector('[data-testid="sheet-details-modal-scrim"]')
				?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
			await nextTick()
			expect(document.body.querySelector('[data-testid="sheet-details-modal"]')).not.toBeNull()

			rejectExport(new ApiError(422, {
				code: 'SENIOR_EXPORT_UNAVAILABLE',
				message: 'senior-scoped export unavailable',
				fieldErrors: {},
			}))
			await flushPromises()
			expect(document.body.querySelector<HTMLButtonElement>('[data-testid="senior-export-btn"]')?.disabled).toBe(false)
		})

		it('submits the filtered + sorted ids for the `senior-queue` resource and renders the refusal naming the missing capability — never an artifact as measured (FR-007-044)', async () => {
			requestExportMock.mockRejectedValue(
				new ApiError(422, { code: 'SENIOR_EXPORT_UNAVAILABLE', message: 'Senior-scoped XLSX export is not available yet', fieldErrors: {} })
			)
			await mountModal({}, [
				modalRow('tx-1', { amountMinor: 20_000, expenseType: { id: 'et-1', name: 'Supplies' } }),
				modalRow('tx-2', { amountMinor: 5_000, expenseType: { id: 'et-2', name: 'Travel' } }),
				modalRow('tx-3', { amountMinor: 12_000, expenseType: { id: 'et-1', name: 'Supplies' } }),
			])

			// Filter to the Supplies subset and order it ascending by amount.
			clickTestId('senior-filter-expense-type-et-1')
			await nextTick()
			await flushPromises()
			clickTestId('senior-sort-amount') // switching criterion starts ascending
			await nextTick()
			await flushPromises()
			expect(cardIds()).toEqual(['tx-3', 'tx-1'])

			clickExport()
			await flushPromises()

			// The exact ordered selection travels in `ExportRequest.ids`, guarded to the additive
			// `senior-queue` resource at the store's call-site boundary.
			expect(requestExportMock).toHaveBeenCalledTimes(1)
			expect(requestExportMock).toHaveBeenCalledWith(expect.objectContaining({
				resource: 'senior-queue',
				fileFormat: 'xlsx',
				ids: ['tx-3', 'tx-1'],
			}))

			// The refusal renders the capability reason; no export state claims a measured artifact
			// and the queue of cards stays intact behind it.
			const refused = document.body.querySelector('[data-testid="senior-export-refused"]')
			expect(refused).not.toBeNull()
			expect(refused?.textContent ?? '').toContain('reviews.seniorSheet.export.refusedTitle')
			expect(refused?.textContent ?? '').toContain('reviews.seniorSheet.export.refusedBody')
			expect(document.body.querySelector('[data-testid="senior-export-reason"]')?.textContent ?? '')
				.toContain('Senior-scoped XLSX export is not available yet')
			expect(document.body.querySelector('[data-testid="transaction-review-cards"]')).not.toBeNull()
		})
	})
})

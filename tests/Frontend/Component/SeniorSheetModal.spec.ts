// Senior Sheet Modal - Vue Component Tests (Feature 007, User Story 2)
//
// US2: from a sheet row the modal records decisions per transaction with quick decision and full
// detail; Cost Center decisions appear at transaction level only, never rolled into a sheet verdict.
//
// The modal reuses the shared card, details drawer and confirmation modal, whose own rules (the
// FR-033 explanation guard, one command identity per attempt, error wording) are verified in their
// own suites. This file asserts the *wiring boundary* of the senior flow: exactly one `recordDecision`
// per action, reject/return-without-comment refused with no decision issued, approve comment
// optional, the Cost Center decision unchanged after a Senior decision, the card re-rendering decided
// from the server's `result.transaction`, and a 409/superseded refusal keeping the modal open with
// its own words. Everything renders inside a Teleport to `document.body`, so interactions query the
// body and each test clears it afterwards.
//
// The harness mirrors `ManagementReview.spec.ts`: the language is pinned to Persian (RTL) with the
// real `t` (this file asserts catalogue keys, not translations), and the transport is stubbed at the
// axios boundary so an unthought-of store call cannot reach the network and hang the run.

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import SeniorSheetModal from '@/components/review/SeniorSheetModal.vue'
import { useReviewsStore } from '@/stores/reviews'
import { ApiError } from '@/types/api'
import type {
	Decision,
	DecisionResult,
	DecisionValue,
	SeniorSheetQueueItem,
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

function sheetItem(transactions: TransactionSummary[], extra: Record<string, unknown> = {}): SeniorSheetQueueItem {
	return {
		sheetId: 'sheet-1',
		transactions,
		transactionCount: transactions.length,
		totals: [{ currency: 'IRR', minorUnit: 0, amountMinor: transactions.length * 50000 }],
		latestActivityAt: '2026-09-20T10:00:00Z',
		costCenters: [{ id: 'cc-1', name: 'Cost Center' }],
		...extra,
	}
}

/** The server sheet summary carried by a `GET /queues/senior/sheets` list row. */
function sheetSummary(overrides: Record<string, unknown> = {}) {
	return {
		id: 'sheet-1', title: 'Senior travel review', ownerId: 'user1', ownerDisplayName: 'User One',
		currency: 'IRR', submittedTotalMinor: 50000,
		submittedTotal: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
		submittedAt: '2026-09-20T10:00:00Z', version: 1,
		allTransactionsCostCenterDecided: true, reviewStatus: 'PENDING_REVIEW',
		...overrides,
	}
}

describe('SeniorSheetModal (US2)', () => {
	let pinia: Pinia
	let reviewsStore: ReturnType<typeof useReviewsStore>
	let recordDecision: ReturnType<typeof vi.fn>

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		reviewsStore = useReviewsStore()
		ocsRequest.mockReset()
		ocsRequest.mockImplementation((config: { url?: string }) => {
			// The additive Senior sheet read (`[BD←BE1]`) does not exist yet: 404 → unavailable
			// identity fields (FR-007-014).
			if (/\/queues\/senior\/sheets\//.test(config?.url ?? '')) {
				return Promise.reject(new Error('senior sheet read unavailable'))
			}
			// Per-transaction detail reads resolve with an empty detail; tests seed the guarded cache
			// for the transactions they drive, so this fallback only absorbs background refetches.
			if (/\/transactions\//.test(config?.url ?? '')) {
				return Promise.resolve(ocsEnvelope({ items: [], page: 1, pageSize: 25, total: 0 }))
			}
			return Promise.resolve(ocsEnvelope({ items: [], page: 1, pageSize: 25, total: 0 }))
		})
		reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
		// Mirror the real store's classification: a failed per-sheet read is recorded as unavailable
		// so the modal can say so instead of rendering a fabricated empty sheet.
		reviewsStore.fetchSeniorSheetReview = vi.fn().mockImplementation(async (sheetId: string) => {
			reviewsStore.seniorSheetReadStates = {
				...reviewsStore.seniorSheetReadStates,
				[sheetId]: 'unavailable',
			} as never
			return null
		})
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

	/** Seeds the guarded per-transaction detail cache the modal prefetch and confirm read from. */
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

	async function mountModal(item: SeniorSheetQueueItem) {
		const wrapper = mount(SeniorSheetModal, {
			props: { open: false, item },
			global: { plugins: [pinia] },
			attachTo: document.body,
		})
		await wrapper.setProps({ open: true })
		await flushPromises()
		return wrapper
	}

	function cardButton(transactionId: string, testId: string): HTMLButtonElement | null {
		return document.body.querySelector(
			`[data-testid="quick-review-card-${transactionId}"] [data-testid="${testId}"]`
		)
	}

	/** Opens the shared confirmation modal for one transaction and returns its confirm button. */
	async function openConfirm(transactionId: string, kind: DecisionValue): Promise<HTMLButtonElement> {
		const trigger = cardButton(transactionId, kind === 'APPROVE' ? 'quick-review-decide-approve' : `quick-review-decide-${kind.toLowerCase()}`)
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
		await mountModal(sheetItem([], {
			sheet: sheetSummary(),
			title: 'Senior travel review',
			purchaser: 'User One',
			reviewStatus: 'PENDING_REVIEW',
		}))

		expect(document.body.querySelector('[data-testid="senior-modal-title"]')?.textContent?.trim())
			.toBe('Senior travel review')
		expect(document.body.querySelector('[data-testid="senior-modal-owner"]')?.textContent?.trim())
			.toBe('User One')
		expect(document.body.querySelector('[data-testid="senior-modal-unavailable"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="senior-modal-loading"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="senior-modal-empty-transactions"]')).toBeNull()
	})

	it('shows a loading state while the per-sheet read is in flight', async () => {
		reviewsStore.fetchSeniorSheetReview = vi.fn().mockImplementation(async (sheetId: string) => {
			reviewsStore.seniorSheetReadStates = {
				...reviewsStore.seniorSheetReadStates,
				[sheetId]: 'loading',
			} as never
			// Never settles: the modal must render the in-flight state, not an empty result.
			return new Promise(() => {})
		})

		await mountModal(sheetItem([], { sheet: sheetSummary() }))

		expect(document.body.querySelector('[data-testid="senior-modal-loading"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="senior-modal-unavailable"]')).toBeNull()
	})

	it('retries the per-sheet read with force from the unavailable state', async () => {
		await mountModal(sheetItem([], { sheet: sheetSummary() }))

		const retry = document.body.querySelector<HTMLButtonElement>('[data-testid="senior-modal-retry"]')
		expect(retry).not.toBeNull()
		retry?.click()
		await flushPromises()

		expect(reviewsStore.fetchSeniorSheetReview).toHaveBeenLastCalledWith('sheet-1', true)
	})

	it('keeps a completed sheet read-only through its server-supplied decided transactions', async () => {
		const completedSheet = sheetSummary({ reviewStatus: 'REVIEW_COMPLETED' })
		reviewsStore.seniorSheetReviews = {
			'sheet-1': {
				sheet: completedSheet,
				progress: { awaitingSenior: 0, decidedBySenior: 1, total: 1 },
				transactions: [{
					...summary('tx-1'),
					seniorState: 'DECIDED_BY_SENIOR',
					seniorDecision: seniorDecision('APPROVE'),
				}],
			},
		} as never
		seedDetails(['tx-1'])
		await mountModal(sheetItem([], { sheet: completedSheet, reviewStatus: 'REVIEW_COMPLETED' }))

		// The card presents the server's decided state and offers no decision action at all.
		expect(document.body.querySelector('[data-testid="quick-review-decision-note"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="quick-review-card-tx-1"] [data-testid="quick-review-decide-approve"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="quick-review-card-tx-1"] [data-testid="quick-review-decide-reject"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="quick-review-card-tx-1"] [data-testid="quick-review-decide-return"]')).toBeNull()
	})

	it('renders the sheet transactions as decisions-ready cards with the CC advisory at transaction level only', async () => {
		seedDetails(['tx-1', 'tx-2'])
		await mountModal(sheetItem([summary('tx-1'), summary('tx-2')]))

		// One card per transaction over the proven-complete set, none decided yet.
		expect(document.body.querySelector('[data-testid="quick-review-card-tx-1"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="quick-review-card-tx-2"]')).not.toBeNull()
		expect(document.body.querySelectorAll('[data-testid^="quick-review-card-"]')).toHaveLength(2)

		// FR-034: the CC decision is visible to the senior at transaction level only — one advisory
		// per transaction, never a sheet verdict.
		const advisories = document.body.querySelectorAll('[data-testid="senior-transaction-advisory"]')
		expect(advisories).toHaveLength(2)
		expect(advisories[0]?.textContent?.trim() ?? '').not.toBe('')

		// The card's own decision actions are enabled only on the server-granted capability (T014).
		expect((cardButton('tx-1', 'quick-review-decide-approve') as HTMLButtonElement).disabled).toBe(false)
		expect(document.body.querySelector('[data-testid="quick-review-decision-note"]')).toBeNull()
	})

	it('shows the server-supplied sheet progress and the per-transaction decision footer', async () => {
		reviewsStore.seniorSheetReviews = {
			'sheet-1': {
				sheet: sheetSummary(),
				progress: { awaitingSenior: 1, decidedBySenior: 1, total: 2 },
				transactions: [
					{ ...summary('tx-1'), seniorState: 'AWAITING_SENIOR_DECISION', seniorDecision: null },
					{ ...summary('tx-2'), seniorState: 'DECIDED_BY_SENIOR', seniorDecision: seniorDecision('APPROVE') },
				],
			},
		} as never
		await mountModal(sheetItem([summary('tx-1'), summary('tx-2')]))

		// Progress comes from the per-sheet read, never from a client-derived card count.
		const progress = document.body.querySelector('[data-testid="senior-modal-progress"]')
		expect(progress).not.toBeNull()
		expect(progress?.textContent ?? '').toContain('reviews.seniorSheet.modal.progress')

		// The Cost Center panel's decision note closes the transaction section with the same words.
		const footer = document.body.querySelector('[data-testid="senior-modal-footer"]')
		expect(footer).not.toBeNull()
		expect(footer?.textContent ?? '').toContain('reviews.quickReview.footerTitle')
		expect(footer?.textContent ?? '').toContain('reviews.quickReview.footerBody')
	})

	it('keeps the result count beside the section title, sort and clear in the results row, and export in the header', async () => {
		await mountModal(sheetItem([summary('tx-1')]))

		// The count badge sits on the section heading, like the approved reference's count badge.
		const head = document.body.querySelector('[data-testid="senior-modal-transactions-title"]')?.parentElement
		expect(head?.querySelector('[data-testid="senior-filter-count"]')).not.toBeNull()

		// Sort and clear share one compact results row; export has moved to the modal header.
		const results = document.body.querySelector('[data-testid="senior-results-toolbar"]')
		expect(results).not.toBeNull()
		expect(results?.querySelector('[data-testid="senior-sort-panel"]')).not.toBeNull()
		expect(results?.querySelector('[data-testid="senior-filter-clear"]')).not.toBeNull()
		expect(results?.querySelector('[data-testid="senior-export-btn"]')).toBeNull()

		const exportButton = document.body.querySelector('[data-testid="senior-export-btn"]')
		const closeButton = document.body.querySelector('[data-testid="senior-modal-close"]')
		expect(exportButton).not.toBeNull()
		expect(closeButton).not.toBeNull()
		// Both header actions are siblings, so the export sits beside the close control.
		expect(exportButton?.parentElement).toBe(closeButton?.parentElement)
		expect(exportButton?.parentElement?.getAttribute('data-testid')).toBe('senior-modal-actions')

		// The filter panel owns the filter fields only.
		expect(document.body.querySelector('[data-testid="senior-filter-panel"] [data-testid="senior-filter-count"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="senior-filter-panel"] [data-testid="senior-filter-clear"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="senior-filter-panel"] [data-testid="senior-export-btn"]')).toBeNull()
	})

	it('records exactly one decision per action, lets the card re-render decided from the server response, and leaves the CC decision unchanged', async () => {
		recordDecision.mockResolvedValue(decidedResult('tx-1'))
		seedDetails(['tx-1'])
		await mountModal(sheetItem([summary('tx-1')]))

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

		// The card re-renders from the server's `result.transaction`: decided note, no action bar.
		expect(document.body.querySelector('[data-testid="quick-review-decision-note"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="quick-review-card-tx-1"] [data-testid="quick-review-decide-approve"]')).toBeNull()
		// The confirmation modal is closed again.
		expect(document.body.querySelector('[data-testid="decision-modal"]')).toBeNull()

		// FR-007-004 / no-overwrite: the CC decision is still present and byte-identical — the
		// advisory still shows the CC approval, and the server decision history keeps both stages.
		expect(document.body.querySelector('[data-testid="senior-transaction-advisory"]')?.textContent ?? '')
			.toContain('reviews.decision.approve')
		expect(recordDecision.mock.calls[0]?.[1]).not.toHaveProperty('costCenterDecision')

		// The post-decision refresh reads the sheet-list endpoint through the store's default
		// all-pages call — not the old transaction queue parameters.
		expect(reviewsStore.fetchSeniorQueue).toHaveBeenCalledWith()
	})

	it('refuses a reject without an explanation and posts nothing until one is written (FR-033)', async () => {
		recordDecision.mockResolvedValue(decidedResult('tx-1', 'REJECT'))
		seedDetails(['tx-1'])
		await mountModal(sheetItem([summary('tx-1')]))

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
		await mountModal(sheetItem([summary('tx-1')]))

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
		await mountModal(sheetItem([summary('tx-1')]))

		const confirm = await openConfirm('tx-1', 'APPROVE')
		confirm.click()
		await flushPromises()

		// The refusal is rendered distinctly (a version conflict is retryable, unlike a refusal) and
		// the modal stays open so the user can review the current version.
		const error = document.body.querySelector('[data-testid="decision-submit-error"]')
		expect(error).not.toBeNull()
		expect(error?.textContent ?? '').toContain('reviews.quickReview.decision.versionConflict')
		expect(document.body.querySelector('[data-testid="decision-modal"]')).not.toBeNull()
		// No local decided state is fabricated: the card still offers the decision actions.
		expect(document.body.querySelector('[data-testid="quick-review-decision-note"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="quick-review-card-tx-1"] [data-testid="quick-review-decide-approve"]')).not.toBeNull()
	})

	it('maps an authorization refusal to its own words (T014)', async () => {
		recordDecision.mockRejectedValue({ status: 403, body: { code: 'FORBIDDEN' } })
		seedDetails(['tx-1'])
		await mountModal(sheetItem([summary('tx-1')]))

		const confirm = await openConfirm('tx-1', 'APPROVE')
		confirm.click()
		await flushPromises()

		const error = document.body.querySelector('[data-testid="decision-submit-error"]')
		expect(error).not.toBeNull()
		expect(error?.textContent ?? '').toContain('reviews.quickReview.decision.forbidden')
	})

	it('opens full detail one layer above the modal, shows per-transaction CC and Senior history, and restores focus on close', async () => {
		seedDetails(['tx-1'])
		await mountModal(sheetItem([summary('tx-1')]))

		const detailsBtn = cardButton('tx-1', 'quick-review-full-details')
		expect(detailsBtn).not.toBeNull()
		detailsBtn?.focus()
		detailsBtn?.click()
		await flushPromises()

		// The drawer opens above the still-mounted modal (single overlay), with decision controls for
		// the awaiting transaction (T013).
		expect(document.body.querySelector('[data-testid="details-drawer"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="senior-sheet-modal"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="details-drawer-footer"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="drawer-decide-approve"]')).not.toBeNull()

		// The history tab renders decisions per transaction — the CC approval here (FR-034 / FR-007-004).
		const historyTab = document.body.querySelector<HTMLButtonElement>('[data-testid="drawer-tab-history"]')
		expect(historyTab).not.toBeNull()
		historyTab?.click()
		await nextTick()
		expect(document.body.querySelector('[data-testid="decision-item-d-cc"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="decision-item-d-cc"]')?.textContent ?? '')
			.toContain('reviews.stage.cost_center')
		expect(document.body.querySelector('[data-testid="decision-item-d-cc"]')?.textContent ?? '')
			.toContain('reviews.decision.approve')

		// Closing the drawer restores focus to the control that opened it.
		document.body.querySelector<HTMLButtonElement>('[data-testid="details-drawer-close"]')?.click()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="details-drawer"]')).toBeNull()
		expect(document.activeElement?.closest('[data-testid="quick-review-card-tx-1"]')?.getAttribute('data-testid'))
			.toBe('quick-review-card-tx-1')
	})

	it('shows the parent modal sheet header in the full-details drawer metadata (BUG-05)', async () => {
		// The additive Senior read carries the sheet header the modal header already renders; the
		// drawer must present the same title/owner instead of the unavailable fallbacks.
		reviewsStore.seniorSheetReviews = {
			'sheet-1': {
				sheet: {
					id: 'sheet-1', title: 'Senior travel review', ownerId: 'user1', ownerDisplayName: 'User One',
					currency: 'IRR', submittedTotalMinor: 50000,
					submittedTotal: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
					submittedAt: '2026-09-20T10:00:00Z', version: 1,
					allTransactionsCostCenterDecided: true, reviewStatus: 'PENDING_REVIEW',
				},
				progress: { awaitingSenior: 1, decidedBySenior: 0, total: 1 },
				transactions: [{ ...summary('tx-1'), seniorState: 'AWAITING_SENIOR_DECISION', seniorDecision: null }],
			},
		} as never
		seedDetails(['tx-1'])
		await mountModal(sheetItem([summary('tx-1')]))

		// The parent header shows the read's title (controls kept / owner kept).
		expect(document.body.querySelector('[data-testid="senior-modal-title"]')?.textContent?.trim())
			.toBe('Senior travel review')
		expect(document.body.querySelector('[data-testid="senior-modal-owner"]')?.textContent?.trim())
			.toBe('User One')

		cardButton('tx-1', 'quick-review-full-details')?.click()
		await flushPromises()
		await nextTick()

		const sheetField = document.body.querySelector('[data-testid="drawer-field-sheet"]')
		expect(sheetField).not.toBeNull()
		expect(sheetField?.textContent ?? '').toContain('Senior travel review')
		expect(sheetField?.textContent ?? '').not.toContain('reviews.sheetQueue.sheetTitleUnavailable')
		const purchaserField = document.body.querySelector('[data-testid="drawer-field-purchaser"]')
		expect(purchaserField?.textContent ?? '').toContain('User One')
		expect(purchaserField?.textContent ?? '').not.toContain('reviews.sheetQueue.purchaserUnavailable')
	})

	it('routes the drawer footer decision through the same single confirmation modal', async () => {
		recordDecision.mockResolvedValue(decidedResult('tx-1'))
		seedDetails(['tx-1'])
		await mountModal(sheetItem([summary('tx-1')]))

		cardButton('tx-1', 'quick-review-full-details')?.click()
		await flushPromises()
		document.body.querySelector<HTMLButtonElement>('[data-testid="drawer-decide-approve"]')?.click()
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
			await mountModal(sheetItem([summary('tx-1')]))

			// The card renders the returned evidence set as a thumbnail row (T016), never an inline
			// renderer of untrusted content.
			expect(document.body.querySelector('[data-testid="quick-review-attachments"]')).not.toBeNull()
			expect(document.body.querySelector('[data-testid="quick-review-thumb-ev-1"]')).not.toBeNull()

			await openPreviewFromThumbnail('quick-review-thumb-ev-1')

			// The shared preview opens above the still-mounted modal (single overlay) and renders the
			// document inline from the guarded content read.
			expect(document.body.querySelector('[data-test="evidence-preview-layer"]')).not.toBeNull()
			expect(document.body.querySelector('[data-test="evidence-preview-image"]')).not.toBeNull()
			expect(evidenceApi.getEvidenceContent).toHaveBeenCalledWith('ev-1', 'inline')
			expect(document.body.querySelector('[data-test="preview-download-btn"]')).not.toBeNull()
			expect(document.body.querySelector('[data-testid="senior-sheet-modal"]')).not.toBeNull()
		})

		it('renders a PDF with the inline viewer, not as a bare download link (T016)', async () => {
			seedDetailWithEvidence([evidence('ev-1', 'application/pdf')])
			await mountModal(sheetItem([summary('tx-1')]))

			await openPreviewFromThumbnail('quick-review-thumb-ev-1')

			expect(document.body.querySelector('[data-test="evidence-preview-pdf"]')).not.toBeNull()
			expect(document.body.querySelector('[data-test="evidence-preview-image"]')).toBeNull()
		})

		it('shows an explicit download-only state for a type the server will not serve inline, with no inline renderer and no content fetch (C-16)', async () => {
			seedDetailWithEvidence([evidence('ev-1', 'application/octet-stream')])
			await mountModal(sheetItem([summary('tx-1')]))

			await openPreviewFromThumbnail('quick-review-thumb-ev-1')

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
			await mountModal(sheetItem([summary('tx-1')]))

			await openPreviewFromThumbnail('quick-review-thumb-ev-1')

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
			await mountModal(sheetItem([summary('tx-1')]))

			await openPreviewFromThumbnail('quick-review-thumb-ev-1')

			expect(document.body.querySelector('[data-test="preview-inactive-note"]')).not.toBeNull()
			expect(document.body.querySelector('[data-test="evidence-preview-image"]')).toBeNull()
			// Download is offered only for active rows; an inactive preview is read-only.
			expect(document.body.querySelector('[data-test="preview-download-btn"]')).toBeNull()
			expect(evidenceApi.getEvidenceContent).not.toHaveBeenCalled()
		})

		it('renders a single-document set with both navigation directions disabled, rather than a spurious multi-document control', async () => {
			seedDetailWithEvidence([evidence('ev-1', 'image/png')])
			await mountModal(sheetItem([summary('tx-1')]))

			await openPreviewFromThumbnail('quick-review-thumb-ev-1')

			const prev = document.body.querySelector<HTMLButtonElement>('[data-test="preview-nav-prev"]')
			const next = document.body.querySelector<HTMLButtonElement>('[data-test="preview-nav-next"]')
			expect(prev).not.toBeNull()
			expect(next).not.toBeNull()
			expect(prev?.disabled).toBe(true)
			expect(next?.disabled).toBe(true)
		})

		it('shows a transaction with no returned attachments as an explicit empty state in both the card and the drawer (no fabricated tile)', async () => {
			seedDetailWithEvidence([])
			await mountModal(sheetItem([summary('tx-1')]))

			// No fabricated thumbnail row on the card.
			expect(document.body.querySelector('[data-testid="quick-review-attachments"]')).toBeNull()

			cardButton('tx-1', 'quick-review-full-details')?.click()
			await flushPromises()

			expect(document.body.querySelector('[data-testid="details-drawer"]')).not.toBeNull()
			expect(document.body.querySelector('[data-testid="drawer-no-attachments"]')).not.toBeNull()
			expect(document.body.querySelector('[data-testid="drawer-attachments"]')).toBeNull()
		})
	})

	// ---- US4 helpers (T023), scoped to the outer describe so both US4 blocks share them. ----
	function cardIds(): string[] {
		return [...document.body.querySelectorAll('li[data-testid^="senior-transaction-"]')]
			.map((node) => (node.getAttribute('data-testid') ?? '').replace('senior-transaction-', ''))
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

	describe('filter and sort (US4)', () => {
		it('searches by transaction id or description, narrowing only the matching cards, with the approved placeholder (T020, owner D7)', async () => {
			await mountModal(sheetItem([
				summary('tx-1', { description: 'Office supplies' }),
				summary('tx-2', { description: 'Air ticket to Tehran' }),
			]))

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
			expect(document.body.querySelector('[data-testid="senior-modal-no-results"]')).not.toBeNull()
			expect(document.body.querySelector('[data-testid="senior-modal-transactions"]')).toBeNull()
		})

		it('toggles expense-type facets as a multi-select with the derived category counts (T020)', async () => {
			await mountModal(sheetItem([
				summary('tx-1', { expenseType: { id: 'et-1', name: 'Supplies' } }),
				summary('tx-2', { expenseType: { id: 'et-2', name: 'Travel' } }),
			]))

			clickTestId('senior-filter-expense-type-et-1')
			await nextTick()
			await flushPromises()
			expect(cardIds()).toEqual(['tx-1'])

			// Selecting a second type widens again — a multi-select, not a single pick.
			clickTestId('senior-filter-expense-type-et-2')
			await nextTick()
			await flushPromises()
			expect(cardIds().sort()).toEqual(['tx-1', 'tx-2'])

			// The count derives from the loaded set (never from a per-action request).
			expect(document.body.querySelector('[data-testid="senior-filter-count"]')).not.toBeNull()
		})

		it('removes the currency, amount and attachment filters while keeping search and expense type', async () => {
			await mountModal(sheetItem([summary('tx-1', { evidenceCount: 2 })]))

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
			await mountModal(sheetItem([
				summary('tx-1', { amountMinor: 20_000, expenseDate: '2026-09-21', expenseType: { id: 'et-1', name: 'Alpha' } }),
				summary('tx-2', { amountMinor: 5_000, expenseDate: '2026-09-20', expenseType: { id: 'et-2', name: 'Beta' } }),
				summary('tx-3', { amountMinor: 12_000, expenseDate: '2026-09-22', expenseType: { id: 'et-3', name: 'Gamma' } }),
			]))

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
			await mountModal(sheetItem([summary('tx-1')]))

			clickExport()
			await nextTick()

			expect(requestExportMock).toHaveBeenCalledTimes(1)
			expect(document.body.querySelector<HTMLButtonElement>('[data-testid="senior-export-btn"]')?.disabled).toBe(true)
			expect(document.body.querySelector<HTMLButtonElement>('[data-testid="senior-export-btn"]')?.textContent ?? '')
				.toContain('reviews.seniorSheet.export.busy')

			// While exporting the scrim must not close the modal.
			document.body.querySelector('[data-testid="senior-modal-scrim"]')
				?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
			await nextTick()
			expect(document.body.querySelector('[data-testid="senior-sheet-modal"]')).not.toBeNull()

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
			await mountModal(sheetItem([
				summary('tx-1', { amountMinor: 20_000, expenseType: { id: 'et-1', name: 'Supplies' } }),
				summary('tx-2', { amountMinor: 5_000, expenseType: { id: 'et-2', name: 'Travel' } }),
				summary('tx-3', { amountMinor: 12_000, expenseType: { id: 'et-1', name: 'Supplies' } }),
			]))

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
			expect(document.body.querySelector('[data-testid="senior-modal-transactions"]')).not.toBeNull()
		})
	})
})

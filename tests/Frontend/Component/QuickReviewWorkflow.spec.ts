// T028 — the quick-review workflow wiring in CostCenterQueueView.
//
// These tests exercise the seams the component specs cannot: the card → confirmation → POST →
// refresh chain, the guarded per-transaction detail reads behind the thumbnails and the drawer, the
// single-overlay rule, and the fact that an overlay cycle leaves the queue's own state (expanded
// sheet, search) untouched. The transport is stubbed at the axios boundary with a stateful server
// double, so a recorded decision actually changes what the next read answers.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createRouter, createWebHashHistory, type Router } from 'vue-router'
import CostCenterQueueView from '@/views/reviews/CostCenterQueueView.vue'
import { useReviewsStore } from '@/stores/reviews'
import { routes } from '@/router'

const l10n = vi.hoisted(() => ({
	getLanguage: vi.fn(() => 'en'),
	isRTL: vi.fn(() => false),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`)
}))

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@nextcloud/l10n')>()
	return { ...actual, getLanguage: l10n.getLanguage, isRTL: l10n.isRTL, imagePath: l10n.imagePath }
})

const ocsRequest = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({
	default: {
		request: (...args: unknown[]) => ocsRequest(...args),
		isAxiosError: (error: unknown) => Boolean((error as { isAxiosError?: boolean } | null)?.isAxiosError)
	}
}))

const dialogs = vi.hoisted(() => ({ showSuccess: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => dialogs)

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => `/ocs/v2.php${path}`,
	imagePath: l10n.imagePath,
	appName: 'pettycash',
	appVersion: '1.0.0'
}))

function ocsEnvelope(data: unknown) {
	return { status: 200, data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data } } }
}

function ocsErrorEnvelope(status: number, code: string, message = code): unknown {
	return {
		isAxiosError: true,
		response: {
			status,
			data: { ocs: { meta: { status: 'failure', statuscode: status }, data: { error: { code, message } } } }
		}
	}
}

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

function queueItem(id: string, sheetId = 'sheet-1', overrides: Record<string, unknown> = {}) {
	return {
		id, sheetId, stage: 'COST_CENTER', status: 'AWAITING_COST_CENTER_REVIEW',
		finalOutcome: null, evidenceRequired: true, evidenceCount: 2, version: 4,
		latestDecision: null,
		activeRevision: {
			id: 'rev-1', number: 1, expenseDate: '2026-09-20',
			amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
			description: 'Taxi to client site',
			costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center' },
			expenseType: { id: 'et-1', name: 'Travel' },
			createdBy: 'user1', createdAt: '2026-09-20T10:00:00Z', active: true
		},
		...overrides
	}
}

function evidence(id: string, name: string) {
	return {
		id, transactionId: 'tx-1', revisionId: 'rev-1', name,
		mediaType: name.endsWith('.pdf') ? 'application/pdf' : 'image/png',
		sizeBytes: 2048, active: true, uploadedBy: 'user1', uploadedAt: '2026-09-20T10:00:00Z'
	}
}

function transactionDetail(_version = 4, allowedActions: Record<string, boolean> = { canApprove: true, canReject: true, canReturn: true }) {
	return {
		...queueItem('tx-1'),
		allowedActions,
		decisions: [] as unknown[],
		evidence: [evidence('ev-1', 'receipt.png'), evidence('ev-2', 'invoice.pdf')]
	}
}

function sheetHeader(id = 'sheet-1') {
	return {
		id, title: `Sheet ${id}`, ownerId: 'user1', ownerDisplayName: 'User One',
		currency: 'IRR', status: 'COST_CENTER', submittedTotalMinor: 50000,
		submittedTotal: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
		submittedAt: '2026-09-20T10:00:00Z', version: 1
	}
}

/** A manager sheet review whose transactions carry the manager-scoped fields the view reads. */
function sheetReview(transactions: Record<string, unknown>[], overrides: Record<string, unknown> = {}) {
	return {
		sheet: sheetHeader(),
		progress: {
			awaitingManager: transactions.length,
			decidedByManager: 0,
			returnedForCorrection: 0,
			total: transactions.length,
			managerComplete: false
		},
		transactions: transactions.map((transaction) => ({
			...transaction,
			managerState: 'AWAITING_MANAGER_DECISION',
			managerDecision: null
		})),
		...overrides
	}
}

/**
 * A stateful server double. `state` stays live: a recorded decision mutates the detail and the
 * sheet review the way the real backend would, so the test can assert what the *next* read answers.
 */
function installServer(detail: Record<string, unknown>) {
	const state = { detail }

	ocsRequest.mockImplementation((config: { url?: string; method?: string; data?: unknown; headers?: Record<string, string> }) => {
		const url = config?.url ?? ''
		const sheetMatch = url.match(/\/queues\/cost-center\/sheets\/([^/?]+)/)
		if (sheetMatch && (!config.method || config.method === 'GET')) {
			const sheetId = decodeURIComponent(sheetMatch[1] ?? '')
			const decided = (state.detail as { status?: string }).status !== 'AWAITING_COST_CENTER_REVIEW'
			return Promise.resolve(ocsEnvelope({
				sheet: sheetHeader(sheetId),
				progress: decided
					? { awaitingManager: 0, decidedByManager: 1, returnedForCorrection: 0, total: 1, managerComplete: true }
					: { awaitingManager: 1, decidedByManager: 0, returnedForCorrection: 0, total: 1, managerComplete: false },
				transactions: [{
					...queueItem('tx-1', sheetId),
					version: (state.detail as { version?: number }).version,
					status: (state.detail as { status?: string }).status,
					managerState: decided ? 'DECIDED_BY_MANAGER' : 'AWAITING_MANAGER_DECISION',
					managerDecision: decided ? (state.detail as { decisions?: unknown[] }).decisions?.[0] ?? null : null
				}]
			}))
		}
		if (/\/transactions\/[^/?]+\/decisions$/.test(url) && config.method === 'POST') {
			const payload = (config.data ?? {}) as { decision?: string; explanation?: string; expectedVersion?: number }
			const current = state.detail as typeof detail & { version?: number }
			if (payload.expectedVersion !== current.version) {
				return Promise.reject(ocsErrorEnvelope(409, 'TRANSACTION_VERSION_CONFLICT'))
			}
			current.version = (current.version ?? 0) + 1
			current.status = payload.decision === 'APPROVE' ? 'COST_CENTER_APPROVED' : current.status
			current.decisions = [{
				id: 'd-1',
				stage: 'COST_CENTER',
				decision: payload.decision,
				explanation: payload.explanation,
				actor: { userId: 'manager1', displayName: 'Manager One', role: 'COST_CENTER_MANAGER' },
				decidedAt: '2026-09-21T10:30:00Z'
			}]
			return Promise.resolve(ocsEnvelope({ decision: payload.decision, transaction: current }))
		}
		const detailMatch = url.match(/\/transactions\/([^/?]+)$/)
		if (detailMatch && (!config.method || config.method === 'GET')) {
			return Promise.resolve(ocsEnvelope(state.detail))
		}
		return Promise.resolve(ocsEnvelope({ items: [], page: 1, pageSize: 25, total: 0 }))
	})

	return state
}

describe('Quick review workflow (T028)', () => {
	let pinia: Pinia
	let router: Router

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		router = createRouter({
			history: createWebHashHistory(),
			routes: routes.map(({ path, name, props }) => ({
				path, name, props, component: { template: '<div />' }
			}))
		})
		l10n.getLanguage.mockReturnValue('en')
		l10n.isRTL.mockReturnValue(false)
		dialogs.showSuccess.mockClear()
		ocsRequest.mockReset()
	})

	async function mountView(items: unknown[]): Promise<VueWrapper> {
		const reviewsStore = useReviewsStore()
		reviewsStore.costCenterQueue = { items, total: items.length, page: 1, pageSize: 500 } as never
		reviewsStore.fetchCostCenterQueue = vi.fn().mockResolvedValue(undefined)
		await router.push('/reviews/cost-center')
		await router.isReady()
		const wrapper = mount(CostCenterQueueView, {
			global: { plugins: [pinia, router] },
			attachTo: document.body
		})
		await flushPromises()
		return wrapper
	}

	/**
	 * UXC-6-01: selecting a sheet opens the shared Sheet Details Modal over the still-mounted
	 * queue. The modal teleports to `body`, so its content is asserted through `document.body`
	 * rather than the wrapper's own subtree.
	 */
	async function openSheetModal(wrapper: VueWrapper): Promise<void> {
		await wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-title"]').trigger('click')
		await flushPromises()
		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).not.toBeNull()
	}

	it('records an approval from the card: one POST with a command id, refreshed panel, toast', async () => {
		const state = installServer(transactionDetail())
		const wrapper = await mountView([queueItem('tx-1')])
		await openSheetModal(wrapper)

		// The card reads its evidence tiles from the guarded per-transaction detail read.
		expect(document.body.querySelector('[data-testid="transaction-card-attachments"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="transaction-card-thumb-ev-1"]')).not.toBeNull()

		await (document.body.querySelector('[data-testid="transaction-card-decide-approve"]') as HTMLElement).click()
		await flushPromises()

		// The confirmation is the only overlay open.
		expect(document.body.querySelector('[data-testid="decision-modal"]')).not.toBeNull()
		expect(document.body.querySelector('[data-test="evidence-preview-layer"]')).toBeNull()

		await (document.body.querySelector('[data-testid="decision-confirm-btn"]') as HTMLElement).click()
		await flushPromises()

		const calls = ocsRequest.mock.calls.filter((call: unknown[]) => {
			const config = call[0] as { url?: string; method?: string }
			return /\/decisions$/.test(config?.url ?? '') && config?.method === 'POST'
		})
		expect(calls).toHaveLength(1)
		const sent = (calls[0] as unknown[])[0] as { data: Record<string, unknown>; headers: Record<string, string> }
		expect(sent.data).toEqual({ decision: 'APPROVE', explanation: '', expectedVersion: 4 })
		// FR-085: every mutation carries a client-generated command identity.
		expect(sent.headers['X-PettyCash-Command-ID']).toMatch(UUID_V4)

		// The modal closed, the sheet read was refreshed (the card shows the server's decided
		// state), the detail read was refreshed, and the completion toast was shown.
		expect(document.body.querySelector('[data-testid="decision-modal"]')).toBeNull()
		const card = document.body.querySelector('[data-testid="sheet-transaction-tx-1"]')
		expect(card?.querySelector('[data-testid="transaction-card-decision-costCenter"]')?.textContent)
			.toContain('reviews.outcome.approved')
		expect(card?.querySelector('[data-testid="transaction-card-decide-approve"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="cc-review-progress"]')?.textContent)
			.toContain('reviews.quickReview.progress')
		expect(dialogs.showSuccess).toHaveBeenCalledTimes(1)
		expect(state.detail.version).toBe(5)

		// Close the sheet so this test's teleported overlay cannot leak into later tests' queries.
		await (document.body.querySelector('[data-testid="sheet-details-modal-close"]') as HTMLElement).click()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
	})

	it('keeps the confirmation open with its own words on a version conflict and posts nothing further', async () => {
		// The stale client (expectedVersion 4) meets a server that answers 409: the decision is not
		// recorded, the modal stays open with the typed note, and the panel behind it is untouched.
		ocsRequest.mockImplementation((config: { url?: string; method?: string }) => {
			const url = config?.url ?? ''
			if (/\/transactions\/[^/?]+\/decisions$/.test(url) && config.method === 'POST') {
				return Promise.reject(ocsErrorEnvelope(409, 'TRANSACTION_VERSION_CONFLICT'))
			}
			if (/\/transactions\/[^/?]+$/.test(url)) {
				return Promise.resolve(ocsEnvelope(transactionDetail(4)))
			}
			if (/\/queues\/cost-center\/sheets\//.test(url)) {
				return Promise.resolve(ocsEnvelope(sheetReview([queueItem('tx-1')])))
			}
			return Promise.resolve(ocsEnvelope({ items: [], page: 1, pageSize: 25, total: 0 }))
		})

		const wrapper = await mountView([queueItem('tx-1')])
		await openSheetModal(wrapper)
		await (document.body.querySelector('[data-testid="transaction-card-decide-reject"]') as HTMLElement).click()
		await flushPromises()
		const noteInput = document.body.querySelector<HTMLTextAreaElement>('[data-testid="decision-note-input"]')
		noteInput!.value = 'Amount does not match.'
		noteInput!.dispatchEvent(new Event('input'))
		await flushPromises()
		await (document.body.querySelector('[data-testid="decision-confirm-btn"]') as HTMLElement).click()
		await flushPromises()

		expect(document.body.querySelector('[data-testid="decision-modal"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="decision-submit-error"]')?.textContent)
			.toContain('reviews.quickReview.decision.versionConflict')
		expect((document.body.querySelector('[data-testid="decision-note-input"]') as HTMLTextAreaElement).value)
			.toBe('Amount does not match.')
		// The loaded panel behind the modal is untouched: no refresh was forced by the failure, and
		// the Cost Center stage still reads as undecided.
		expect(document.body.querySelector('[data-testid="transaction-card-decision-costCenter"]')?.textContent)
			.toContain('reviews.decisionStage.noDecision')
		expect(document.body.querySelector('[data-testid="transaction-card-decide-reject"]')).not.toBeNull()

		// Close the sheet so this test's teleported overlay cannot leak into later tests' queries.
		await (document.body.querySelector('[data-testid="decision-cancel-btn"]') as HTMLElement).click()
		await flushPromises()
		await (document.body.querySelector('[data-testid="sheet-details-modal-close"]') as HTMLElement).click()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
	})

	it('renders a refusal of the detail read as a refusal in the inline expansion (UXC-V2-09)', async () => {
		ocsRequest.mockImplementation((config: { url?: string; method?: string }) => {
			const url = config?.url ?? ''
			if (/\/transactions\/[^/?]+$/.test(url) && (!config.method || config.method === 'GET')) {
				return Promise.reject(ocsErrorEnvelope(403, 'FORBIDDEN'))
			}
			if (/\/queues\/cost-center\/sheets\//.test(url)) {
				return Promise.resolve(ocsEnvelope(sheetReview([queueItem('tx-1')])))
			}
			return Promise.resolve(ocsEnvelope({ items: [], page: 1, pageSize: 25, total: 0 }))
		})

		const wrapper = await mountView([queueItem('tx-1')])
		await openSheetModal(wrapper)

		await (document.body.querySelector('[data-testid="transaction-card-title"]') as HTMLElement).click()
		await flushPromises()

		expect(document.body.querySelector('[data-testid="inline-forbidden"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="inline-loading"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="inline-field-sheet"]')).toBeNull()
		// The refusal is the expansion's own honest state, rendered in place.

		// Collapse the expansion (the title toggles it), then close the sheet so this test's
		// markup cannot leak into later tests' queries.
		await (document.body.querySelector('[data-testid="transaction-card-title"]') as HTMLElement).click()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="transaction-expanded-detail"]')).toBeNull()
		await (document.body.querySelector('[data-testid="sheet-details-modal-close"]') as HTMLElement).click()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
	})

	it('keeps exactly one overlay open, keeps detail inline, and hands the layer over on a transition (UXC-V2-09)', async () => {
		installServer(transactionDetail())
		const wrapper = await mountView([queueItem('tx-1')])
		await openSheetModal(wrapper)

		const previewLayer = () => document.body.querySelector('[data-test="evidence-preview-layer"]')
		const confirmLayer = () => document.body.querySelector('[data-testid="decision-modal-layer"]')
		const expansion = () => document.body.querySelector('[data-testid="transaction-expanded-detail"]')

		// The expansion is inline detail, not a layer: it opens in place under the still-mounted
		// modal, and the sheet modal remains the only dialog frame in this view.
		await (document.body.querySelector('[data-testid="transaction-card-title"]') as HTMLElement).click()
		await flushPromises()
		expect(expansion()).not.toBeNull()
		expect(previewLayer()).toBeNull()
		expect(confirmLayer()).toBeNull()

		await (document.body.querySelector('[data-testid="transaction-card-thumb-ev-1"]') as HTMLElement).click()
		await flushPromises()
		expect(previewLayer()).not.toBeNull()
		expect(confirmLayer()).toBeNull()

		// With the modal, the expansion and the preview stacked at once, the nesting must not
		// introduce duplicate element ids (UXC-10-09): each surface owns a distinct labelled-title
		// id and `aria-labelledby` stays resolvable.
		const ids = [...document.body.querySelectorAll('[id]')].map((el) => el.id)
		expect(new Set(ids).size).toBe(ids.length)

		// Escape closes the preview; nothing is left open, and the Sheet modal stays beneath. The
		// expansion survives the overlay cycle — the preview returned to the same transaction.
		// The preview's dialog section owns the Escape handler; the layer wrapper does not.
		document.body.querySelector('[data-test="evidence-preview"]')?.dispatchEvent(
			new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })
		)
		await flushPromises()
		expect(previewLayer()).toBeNull()
		expect(confirmLayer()).toBeNull()
		expect(expansion()).not.toBeNull()
		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).not.toBeNull()

		// Collapse the expansion via its own toggle, then close the sheet so this test's markup
		// cannot leak into later tests' queries.
		await (document.body.querySelector('[data-testid="transaction-card-title"]') as HTMLElement).click()
		await flushPromises()
		expect(expansion()).toBeNull()
		await (document.body.querySelector('[data-testid="sheet-details-modal-close"]') as HTMLElement).click()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
	})

	it('preserves the queue state across an overlay cycle', async () => {
		installServer(transactionDetail())
		const wrapper = await mountView([
			queueItem('tx-1'),
			queueItem('tx-2', 'sheet-2')
		])
		await openSheetModal(wrapper)

		// Queue state before the overlay: sheet modal open, search narrowed.
		await wrapper.find('[data-testid="sheet-search"]').setValue('sheet-1')
		expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)

		await (document.body.querySelector('[data-testid="transaction-card-title"]') as HTMLElement).click()
		await flushPromises()
		await (document.body.querySelector('[data-testid="transaction-card-title"]') as HTMLElement).click()
		await flushPromises()

		// The Sheet review context survives the overlay cycle: the modal is still open over the
		// still-mounted, still-filtered queue.
		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).not.toBeNull()
		expect((wrapper.find('[data-testid="sheet-search"]').element as HTMLInputElement).value).toBe('sheet-1')
		expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)

		// Close the sheet so this test's teleported overlay cannot leak into later tests' queries.
		await (document.body.querySelector('[data-testid="sheet-details-modal-close"]') as HTMLElement).click()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
	})

	// T036's scroll-into-view wiring belonged to the inline sheet expansion, which UXC-6-01
	// replaced with the shared Sheet Details Modal: opening a sheet now focuses the shell's dialog
	// (UXC-1-05's tested contract) instead of scrolling an inline panel into view. No replacement
	// assertion exists because the behaviour itself was removed with the interaction.
})

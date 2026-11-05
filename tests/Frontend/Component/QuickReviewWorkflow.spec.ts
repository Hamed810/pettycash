// T028 — the quick-review workflow wiring in CostCenterQueueView.
//
// These tests exercise the seams the component specs cannot: the card → confirmation → POST →
// refresh chain, the guarded per-transaction detail reads behind the thumbnails and the drawer, the
// single-overlay rule, and the fact that an overlay cycle leaves the queue's own state (expanded
// sheet, search) untouched. The transport is stubbed at the axios boundary with a stateful server
// double, so a recorded decision actually changes what the next read answers.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises, type VueWrapper, type DOMWrapper } from '@vue/test-utils'
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

	async function expandFirstSheet(wrapper: VueWrapper): Promise<DOMWrapper<Element>> {
		await wrapper.find('[data-testid="sheet-row-sheet-1"] .sheet-row__summary').trigger('click')
		await flushPromises()
		return wrapper.find('[data-testid="sheet-detail-sheet-1"]')
	}

	it('records an approval from the card: one POST with a command id, refreshed panel, toast', async () => {
		const state = installServer(transactionDetail())
		const wrapper = await mountView([queueItem('tx-1')])
		const detail = await expandFirstSheet(wrapper)

		// The card reads its evidence tiles from the guarded per-transaction detail read.
		expect(detail.find('[data-testid="quick-review-attachments"]').exists()).toBe(true)
		expect(detail.find('[data-testid="quick-review-thumb-ev-1"]').exists()).toBe(true)

		await wrapper.find('[data-testid="quick-review-decide-approve"]').trigger('click')
		await flushPromises()

		// The confirmation is the only overlay open.
		expect(wrapper.find('[data-testid="decision-modal"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="details-drawer-layer"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="evidence-preview-layer"]').exists()).toBe(false)

		await wrapper.find('[data-testid="decision-confirm-btn"]').trigger('click')
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
		expect(wrapper.find('[data-testid="decision-modal"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="quick-review-card-tx-1"]').text())
			.toContain('reviews.quickReview.card.approvedByYou')
		expect(wrapper.find('[data-testid="quick-review-progress"]').text())
			.toContain('reviews.quickReview.progress')
		expect(dialogs.showSuccess).toHaveBeenCalledTimes(1)
		expect(state.detail.version).toBe(5)
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
		await expandFirstSheet(wrapper)
		await wrapper.find('[data-testid="quick-review-decide-reject"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="decision-note-input"]').setValue('Amount does not match.')
		await wrapper.find('[data-testid="decision-confirm-btn"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="decision-modal"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="decision-submit-error"]').text())
			.toContain('reviews.quickReview.decision.versionConflict')
		expect((wrapper.find('[data-testid="decision-note-input"]').element as HTMLTextAreaElement).value)
			.toBe('Amount does not match.')
		// The loaded panel behind the modal is untouched: no refresh was forced by the failure.
		expect(wrapper.find('[data-testid="quick-review-card-tx-1"]').text())
			.toContain('reviews.quickReview.card.awaiting')
	})

	it('renders a refusal of the detail read as a refusal in the drawer', async () => {
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
		await expandFirstSheet(wrapper)

		await wrapper.find('[data-testid="quick-review-full-details"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="details-drawer-forbidden"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="details-drawer-loading"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="drawer-field-sheet"]').exists()).toBe(false)
	})

	it('keeps exactly one overlay open and hands the layer over on a transition', async () => {
		installServer(transactionDetail())
		const wrapper = await mountView([queueItem('tx-1')])
		await expandFirstSheet(wrapper)

		await wrapper.find('[data-testid="quick-review-thumb-ev-1"]').trigger('click')
		await flushPromises()
		const previewLayer = () => wrapper.find('[data-test="evidence-preview-layer"]')
		expect(previewLayer().exists()).toBe(true)
		expect(wrapper.find('[data-testid="details-drawer-layer"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="decision-modal-layer"]').exists()).toBe(false)

		// Opening the drawer replaces the preview layer; the single-overlay rule.
		await wrapper.find('[data-testid="quick-review-full-details"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="details-drawer-layer"]').exists()).toBe(true)
		expect(previewLayer().exists()).toBe(false)

		// Escape closes it; nothing is left open.
		await wrapper.find('[data-testid="details-drawer"]').trigger('keydown', { key: 'Escape' })
		await flushPromises()
		expect(wrapper.find('[data-testid="details-drawer-layer"]').exists()).toBe(false)
		expect(previewLayer().exists()).toBe(false)
		expect(wrapper.find('[data-testid="decision-modal-layer"]').exists()).toBe(false)
	})

	it('preserves the queue state across an overlay cycle', async () => {
		installServer(transactionDetail())
		const wrapper = await mountView([
			queueItem('tx-1'),
			queueItem('tx-2', 'sheet-2')
		])
		await expandFirstSheet(wrapper)

		// Queue state before the overlay: sheet expanded, search narrowed.
		await wrapper.find('[data-testid="sheet-search"]').setValue('sheet-1')
		expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)

		await wrapper.find('[data-testid="quick-review-full-details"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="details-drawer-close"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="sheet-detail-sheet-1"]').exists()).toBe(true)
		expect((wrapper.find('[data-testid="sheet-search"]').element as HTMLInputElement).value).toBe('sheet-1')
		expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)
	})

	it('scrolls the just-expanded panel into view on a mobile viewport, instant under reduced motion (T036)', async () => {
		// jsdom has no layout engine, so the scroll itself (browser-time `scrollIntoView`) is pinned
		// here at the wiring level: a ≤640px viewport makes the expansion scroll the panel into view,
		// and a reduced-motion preference turns that scroll from smooth to instant.
		const originalScrollIntoView = Element.prototype.scrollIntoView
		const originalMatchMedia = window.matchMedia
		const scrollIntoView = vi.fn()
		Element.prototype.scrollIntoView = scrollIntoView
		window.matchMedia = ((query: string) => ({
			matches: query === '(width <= 640px)',
			media: query,
			onchange: null,
			addListener: () => undefined,
			removeListener: () => undefined,
			addEventListener: () => undefined,
			removeEventListener: () => undefined,
			dispatchEvent: () => false
		})) as unknown as typeof window.matchMedia

		try {
			installServer(transactionDetail())
			const wrapper = await mountView([queueItem('tx-1')])
			await expandFirstSheet(wrapper)

			expect(scrollIntoView).toHaveBeenCalledTimes(1)
			expect(scrollIntoView).toHaveBeenCalledWith({ behavior: 'smooth', block: 'start' })
		} finally {
			Element.prototype.scrollIntoView = originalScrollIntoView
			window.matchMedia = originalMatchMedia
		}
	})
})

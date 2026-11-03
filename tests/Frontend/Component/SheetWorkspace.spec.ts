import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory, type Router } from 'vue-router'
import SheetListView from '@/views/sheets/SheetListView.vue'
import SheetDetailView from '@/views/sheets/SheetDetailView.vue'
import { useSheetStore } from '@/stores/sheets'
import { routes } from '@/router'
import { formatNumber } from '@/utils/number'
import type { AuditEvent, Money, SheetDetail, SheetSummary, TransactionStatus, TransactionSummary } from '@/types/api'

const ocsRequest = vi.hoisted(() => vi.fn())

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

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, text: string, params?: Record<string, string | number>) =>
		params ? `${text} ${Object.values(params).join(' ')}` : text),
	n: vi.fn((_app: string, text: string) => text),
	getLanguage: vi.fn(() => 'fa'),
	isRTL: vi.fn(() => true),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`)
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'loading', 'type', 'ariaLabel'],
		emits: ['click'],
		template: '<button type="button" :disabled="disabled" v-bind="$attrs" @click="$emit(\'click\', $event)"><slot /></button>'
	},
	NcChip: {
		name: 'NcChip',
		props: ['variant'],
		template: '<span data-test="nc-chip" v-bind="$attrs"><slot /></span>'
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['heading', 'type'],
		template: '<div class="nc-note-card" v-bind="$attrs"><div class="nc-note-card__heading">{{ heading }}</div><div><slot /></div></div>'
	},
	NcIconSvgWrapper: {
		name: 'NcIconSvgWrapper',
		props: ['path', 'size'],
		template: '<span data-test="nc-icon" />'
	},
	NcDialog: {
		name: 'NcDialog',
		props: ['open', 'name', 'title'],
		emits: ['update:open'],
		template: '<div v-if="open"><slot /></div>'
	},
	NcActions: {
		name: 'NcActions',
		props: ['forceMenu', 'ariaLabel'],
		template: '<div class="nc-actions"><button type="button" :aria-label="ariaLabel" data-test="nc-actions-trigger" /><div><slot /></div></div>'
	},
	NcActionButton: {
		name: 'NcActionButton',
		emits: ['click'],
		template: '<button type="button" data-test="nc-action-button" @click="$emit(\'click\')"><slot /></button>'
	},
}))

const money = (amountMinor: number, currency = 'IRR', minorUnit = 0): Money => ({ amountMinor, currency, minorUnit })

function revisionFixture(overrides: Partial<TransactionSummary['activeRevision']> = {}) {
	return {
		id: 'rev-1',
		number: 1,
		expenseDate: '2026-03-01',
		amount: money(150000),
		description: 'Taxi to the airport',
		costCenter: { id: 'cc-1', name: 'Operations' },
		expenseType: { id: 'et-1', name: 'Transport' },
		createdBy: 'purchaser',
		createdAt: '2026-03-01T09:00:00Z',
		active: true,
		...overrides
	}
}

function transactionFixture(id: string, overrides: Partial<TransactionSummary> = {}): TransactionSummary {
	return {
		id,
		sheetId: 'sh-1',
		activeRevision: revisionFixture(),
		stage: 'DRAFT',
		status: 'DRAFT',
		finalOutcome: null,
		evidenceRequired: false,
		evidenceCount: 0,
		version: 1,
		...overrides
	}
}

function sheetFixture(overrides: Partial<SheetDetail> = {}): SheetDetail {
	return {
		id: 'sh-1',
		accountId: 'b4b0b3a4-1111-4c2d-9e0f-000000000001',
		title: 'March travel',
		ownerId: 'purchaser',
		ownerDisplayName: 'Sara Ahmadi',
		currency: 'IRR',
		status: 'OPEN',
		transactionCount: 2,
		totals: {
			current: money(300000),
			submitted: money(0),
			effectiveApproved: money(150000),
			effectiveRejected: money(0),
			effectivePending: money(150000),
			costCenterApproved: money(150000),
			seniorApproved: money(0),
			financeApproved: money(0),
		},
		createdAt: '2026-02-20T08:00:00Z',
		submittedAt: null,
		completedAt: null,
		firstExpenseDate: '2026-03-01',
		lastExpenseDate: '2026-03-05',
		activeRevisionNumber: 1,
		version: 2,
		allowedActions: {},
		transactions: [
			transactionFixture('tx-1'),
			transactionFixture('tx-2', {
				status: 'AWAITING_COST_CENTER_REVIEW' as TransactionStatus,
				stage: 'COST_CENTER'
			}),
		],
		...overrides
	}
}

function summaryOf(sheet: SheetDetail): SheetSummary {
	const { transactions: _transactions, allowedActions: _allowedActions, ...summary } = sheet
	return summary
}

function ocsOk(data: unknown) {
	return Promise.resolve({ data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data } } })
}

function pageOf<T>(items: T[], page = 1, pageSize = 20): { items: T[]; page: number; pageSize: number; total: number } {
	return { items, page, pageSize, total: items.length }
}

function auditFixture(overrides: Partial<AuditEvent> = {}): AuditEvent {
	return {
		id: 'ev-1',
		eventType: 'APPROVED',
		stage: 'COST_CENTER',
		decision: 'DECISION',
		actor: { userId: 'manager', displayName: 'Reza', role: 'COST_CENTER_MANAGER' },
		after: { decision: 'APPROVE', stage: 'COST_CENTER' },
		occurredAt: '2026-03-02T10:00:00Z',
		...overrides
	}
}

describe('sheet workspace (UI-PUR-005/006)', () => {
	let pinia = createPinia()
	let router: Router
	let wrappers: VueWrapper[] = []

	function track<T extends VueWrapper>(wrapper: T): T {
		wrappers.push(wrapper)
		return wrapper
	}

	function stubTransport(options: {
		sheet?: SheetDetail | null
		notFound?: boolean
		audit?: Record<string, (page: number) => AuditEvent[]>
	} = {}) {
		const detail = options.sheet ?? sheetFixture()
		ocsRequest.mockImplementation((config: { url?: string; method?: string }) => {
			const url = config?.url ?? ''
			if (config?.method && config.method !== 'GET') return ocsOk(detail)
			const audit = /\/transactions\/([^/?]+)\/audit\?.*page=(\d+)/.exec(url)
			if (audit) {
				const id = audit[1] as string
				const page = Number(audit[2] ?? 1)
				if (options.audit?.[id]) return ocsOk(pageOf(options.audit[id](page)))
				return ocsOk(pageOf([]))
			}
			if (/\/sheets\?/.test(url)) return ocsOk({ ...pageOf([summaryOf(detail)], 1, 25), total: 1 })
			if (/\/sheets\/[^/?]+(\?|$)/.test(url)) {
				if (options.notFound) {
					return Promise.reject(Object.assign(new Error('Request failed with status code 404'), {
						isAxiosError: true,
						response: { status: 404, data: { ocs: { meta: { status: 'failure', statuscode: 404 }, data: {} } } }
					}))
				}
				return ocsOk(detail)
			}
			if (/\/(accounts|configuration\/)/.test(url)) return ocsOk(pageOf([]))
			return ocsOk(pageOf([]))
		})
	}

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		router = createRouter({ history: createWebHashHistory(), routes })
		ocsRequest.mockReset()
	})

	afterEach(() => {
		for (const wrapper of wrappers) wrapper.unmount()
		wrappers = []
		document.body.innerHTML = ''
	})

	async function mountAt(path: string, component: unknown): Promise<VueWrapper> {
		await router.push(path)
		await router.isReady()
		const wrapper = track(mount(component as never, {
			global: { plugins: [pinia, router] },
			attachTo: document.body
		}))
		await flushPromises()
		return wrapper
	}

	it('renders the list underneath and the sheet as a modal dialog over it', async () => {
		stubTransport()
		const wrapper = await mountAt('/sheets/sh-1', SheetListView)

		expect(wrapper.find('[data-test="sheet-row"]').exists()).toBe(true)
		const dialog = wrapper.find('[data-test="sheet-workspace"]')
		expect(dialog.exists()).toBe(true)
		expect(dialog.attributes('role')).toBe('dialog')
		expect(dialog.attributes('aria-modal')).toBe('true')
		expect(dialog.attributes('aria-labelledby')).toBe('sheet-workspace-title')
		expect(wrapper.find('#sheet-workspace-title').text()).toBe('March travel')
	})

	it('moves focus into the dialog on mount', async () => {
		stubTransport()
		const wrapper = await mountAt('/sheets/sh-1', SheetListView)
		await flushPromises()

		expect(document.activeElement).toBe(wrapper.find('[data-test="sheet-workspace"]').element)
	})

	it('closes on Escape and returns focus to the row that opened it', async () => {
		stubTransport()
		const wrapper = await mountAt('/sheets', SheetListView)
		const opener = wrapper.find('[data-sheet-open="sh-1"]')
		expect(opener.exists()).toBe(true)

		await opener.trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-test="sheet-workspace"]').exists()).toBe(true)

		await wrapper.find('[data-test="sheet-workspace"]').trigger('keydown', { key: 'Escape' })
		await flushPromises()

		expect(router.currentRoute.value.name).toBe('sheets')
		expect(wrapper.find('[data-test="sheet-workspace"]').exists()).toBe(false)
		expect(document.activeElement).toBe(wrapper.find('[data-sheet-open="sh-1"]').element)
	})

	it('closes when the scrim is clicked', async () => {
		stubTransport()
		const wrapper = await mountAt('/sheets/sh-1', SheetListView)

		await wrapper.find('[data-test="sheet-workspace-scrim"]').trigger('click')
		await flushPromises()

		expect(router.currentRoute.value.name).toBe('sheets')
		expect(wrapper.find('[data-test="sheet-workspace"]').exists()).toBe(false)
	})

	it('contains Tab inside the dialog', async () => {
		stubTransport()
		const wrapper = await mountAt('/sheets/sh-1', SheetListView)
		const dialog = wrapper.find('[data-test="sheet-workspace"]')
		const focusable = Array.from(dialog.element.querySelectorAll<HTMLElement>('button:not([disabled])'))
			.filter((element) => {
				let node: HTMLElement | null = element
				while (node) {
					if (window.getComputedStyle(node).display === 'none') return false
					node = node.parentElement
				}
				return true
			})
		expect(focusable.length).toBeGreaterThan(1)
		const first = focusable[0] as HTMLElement
		const last = focusable[focusable.length - 1] as HTMLElement

		last.focus()
		await dialog.trigger('keydown', { key: 'Tab' })
		expect(document.activeElement).toBe(first)

		first.focus()
		await dialog.trigger('keydown', { key: 'Tab', shiftKey: true })
		expect(document.activeElement).toBe(last)
	})

	it('is a tablist whose arrow keys move selection and whose panels stay addressable', async () => {
		stubTransport()
		const wrapper = await mountAt('/sheets/sh-1', SheetListView)
		const tablist = wrapper.find('[role="tablist"]')
		expect(tablist.exists()).toBe(true)
		expect(wrapper.findAll('[role="tab"]')).toHaveLength(4)
		expect(wrapper.find('[data-test="transactions-tab"]').attributes('aria-selected')).toBe('true')

		await wrapper.find('[data-test="transactions-tab"]').trigger('keydown', { key: 'ArrowRight' })
		expect(wrapper.find('[data-test="approvals-tab"]').attributes('aria-selected')).toBe('true')
		expect(wrapper.find('[data-test="approvals-panel"]').attributes('role')).toBe('tabpanel')

		// v-show keeps every panel mounted, so a hidden panel's hooks remain queryable.
		expect(wrapper.find('[data-test="history-panel"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="overview-panel"]').exists()).toBe(true)
	})

	it('restores the selected tab when the same sheet is reopened', async () => {
		stubTransport()
		const first = await mountAt('/sheets/sh-1', SheetListView)
		await first.find('[data-test="history-tab"]').trigger('click')
		first.unmount()
		wrappers = wrappers.filter((wrapper) => wrapper !== first)

		const second = await mountAt('/sheets', SheetListView)
		await second.find('[data-sheet-open="sh-1"]').trigger('click')
		await flushPromises()
		expect(second.find('[data-test="history-tab"]').attributes('aria-selected')).toBe('true')
	})

	it('shows a not-found state instead of an empty workspace', async () => {
		stubTransport({ notFound: true })
		const wrapper = await mountAt('/sheets/sh-missing', SheetDetailView)

		expect(wrapper.find('[data-test="sheet-not-found"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="sheet-not-found-back"]').exists()).toBe(true)
	})

	// T070: the full-page loader is only for "there is no sheet yet". A re-read of the sheet that is
	// already on screen must not replace the workspace with "Loading sheet…", because the reader
	// would lose their tab and scroll position to a request that may only be refreshing totals.
	it('keeps the loaded workspace visible while the same sheet is re-read', async () => {
		stubTransport()
		const wrapper = await mountAt('/sheets/sh-1', SheetDetailView)
		expect(wrapper.find('[data-test="sheet-loading"]').exists()).toBe(false)

		let release: (value: unknown) => void = () => undefined
		ocsRequest.mockImplementationOnce(() => new Promise((resolve) => { release = resolve }))
		const store = useSheetStore()
		const reread = store.loadSheet('sh-1')
		await flushPromises()

		expect(store.detailLoadingId).toBe('sh-1')
		expect(wrapper.find('[data-test="sheet-loading"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="sheet-workspace"]').exists()).toBe(true)

		release({ data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data: sheetFixture() } } })
		await reread
		await flushPromises()
		expect(store.detailLoadingId).toBeNull()
	})

	it('renders the transaction table from the sheet payload with a view-only action', async () => {
		stubTransport()
		const wrapper = await mountAt('/sheets/sh-1', SheetListView)
		const rows = wrapper.findAll('[data-test="sheet-transaction-row"]')
		expect(rows).toHaveLength(2)
		expect(rows[0]?.text()).toContain('Taxi to the airport')

		const actions = wrapper.findAll('[data-test="view-transaction"]')
		expect(actions.length).toBeGreaterThanOrEqual(2)
		expect(wrapper.find('[data-test="edit-transaction"]').exists()).toBe(false)
	})

	it('never labels the two unreachable transaction statuses', async () => {
		stubTransport({
			sheet: sheetFixture({
				transactions: [
					transactionFixture('tx-1', { status: 'AWAITING_SENIOR_REVIEW' as TransactionStatus }),
					transactionFixture('tx-2', { status: 'AWAITING_FINANCE_REVIEW' as TransactionStatus }),
				]
			})
		})
		const wrapper = await mountAt('/sheets/sh-1', SheetListView)
		const badges = wrapper.findAll('[data-test="transaction-status-badge"]')
		expect(badges.length).toBeGreaterThanOrEqual(2)
		for (const badge of badges) expect(badge.text()).toBe('—')
	})

	it('names the missing server-enforced fields and a required receipt, never an unknown state', async () => {
		stubTransport({
			sheet: sheetFixture({
				transactions: [
					transactionFixture('tx-1', {
						evidenceRequired: true,
						evidenceCount: 0,
						activeRevision: revisionFixture({ description: '', costCenter: { id: '', name: '' }, expenseType: { id: '', name: '' } })
					})
				]
			})
		})
		const wrapper = await mountAt('/sheets/sh-1', SheetListView)

		expect(wrapper.find('[data-test="readiness"]').exists()).toBe(true)
		const blockers = wrapper.findAll('[data-test="readiness-blocker"]')
		const text = blockers.map((blocker) => blocker.text()).join(' ')
		expect(text).toContain('readiness.missingDescription')
		expect(text).toContain('readiness.missingCostCenter')
		expect(text).toContain('readiness.missingExpenseType')
		expect(text).toContain('readiness.missingReceipt')
		// The historical permanent "the server checks this, we do not know" note is gone: the payload
		// now carries the exact server-derived rule and the active count (T057).
		expect(wrapper.find('[data-test="readiness-evidence-unknown"]').exists()).toBe(false)
		// Readiness never disables the server-enforced action.
		expect(wrapper.find('[data-test="submit-sheet-button"]').exists()).toBe(true)
	})

	it('does not make a receipt blocker when the server says the type is receipt-exempt', async () => {
		stubTransport({
			sheet: sheetFixture({
				transactions: [
					transactionFixture('tx-1', { evidenceRequired: false, evidenceCount: 0 })
				]
			})
		})
		const wrapper = await mountAt('/sheets/sh-1', SheetListView)

		expect(wrapper.find('[data-test="readiness-blocker"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="readiness-ready"]').exists()).toBe(true)
	})

	it('does not make a receipt blocker when a required receipt is already active', async () => {
		stubTransport({
			sheet: sheetFixture({
				transactions: [
					transactionFixture('tx-1', { evidenceRequired: true, evidenceCount: 1 })
				]
			})
		})
		const wrapper = await mountAt('/sheets/sh-1', SheetListView)

		expect(wrapper.find('[data-test="readiness-blocker"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="readiness-ready"]').exists()).toBe(true)
	})

	it('maps a submission 422 evidence field error to the named transaction and focuses the summary', async () => {
		const detail = sheetFixture({
			transactions: [
				transactionFixture('tx-1', { evidenceRequired: true, evidenceCount: 0 })
			],
			transactionCount: 1
		})
		stubTransport({ sheet: detail })
		const wrapper = await mountAt('/sheets/sh-1', SheetListView)

		ocsRequest.mockImplementation((config: { url?: string; method?: string }) => {
			if (config?.method === 'POST' && /\/submit$/.test(config?.url ?? '')) {
				return Promise.reject(Object.assign(new Error('Request failed with status code 422'), {
					isAxiosError: true,
					response: {
						status: 422,
						data: {
							ocs: {
								meta: { status: 'failure', statuscode: 422 },
								data: {
									error: {
										code: 'VALIDATION_FAILED',
										message: 'Required receipts are missing',
										fieldErrors: {
											'transactions.tx-1.evidence': ['A receipt is required for this transaction']
										}
									}
								}
							}
						}
					}
				}))
			}
			return ocsOk(detail)
		})

		await wrapper.find('[data-test="submit-sheet-button"]').trigger('click')
		await flushPromises()

		const summary = wrapper.find('[data-test="submission-evidence-errors"]')
		expect(summary.exists()).toBe(true)
		expect(summary.text()).toContain('Taxi to the airport')
		expect(summary.text()).toContain('A receipt is required for this transaction')
		expect(summary.attributes('role')).toBe('alert')
		expect(summary.attributes('tabindex')).toBe('-1')
		expect(document.activeElement).toBe(summary.element)
		// The named refusal replaces the generic line; the two must not be presented side by side.
		expect(wrapper.find('[data-test="submission-errors"]').exists()).toBe(false)
	})

	it('shows only server-published approval figures and says counts are unavailable', async () => {
		stubTransport()
		const wrapper = await mountAt('/sheets/sh-1', SheetListView)
		await wrapper.find('[data-test="approvals-tab"]').trigger('click')

		const stages = wrapper.findAll('[data-test="approval-stage"]')
		expect(stages).toHaveLength(3)
		expect(stages[0]?.text()).toContain(formatNumber(150000))
		expect(wrapper.find('[data-test="approvals-counts-unavailable"]').exists()).toBe(true)
	})

	it('merges per-transaction history newest-first, reads the decision from `after`, and pages honestly', async () => {
		stubTransport({
			// T069: a never-submitted DRAFT has no official feed, so the merge fixture uses submitted
			// transactions. The draft boundary itself is asserted in DraftHistory.spec.ts.
			sheet: sheetFixture({
				transactions: [
					transactionFixture('tx-1', { status: 'AWAITING_COST_CENTER_REVIEW' as TransactionStatus, stage: 'COST_CENTER' }),
					transactionFixture('tx-2', { status: 'AWAITING_COST_CENTER_REVIEW' as TransactionStatus, stage: 'COST_CENTER' }),
				]
			}),
			audit: {
				'tx-1': (page) => page === 1
					? [
						auditFixture({ id: 'e-1', occurredAt: '2026-03-02T10:00:00Z' }),
						auditFixture({ id: 'e-3', eventType: 'SUBMITTED', decision: null, after: null, occurredAt: '2026-03-01T09:00:00Z' }),
					]
					: [auditFixture({ id: 'e-4', eventType: 'RETURNED', after: { decision: 'RETURN' }, occurredAt: '2026-02-28T09:00:00Z' })],
				'tx-2': () => [
					auditFixture({ id: 'e-2', eventType: 'REJECTED', after: { decision: 'REJECT' }, occurredAt: '2026-03-03T09:00:00Z' }),
				],
			}
		})
		// tx-1's feed claims one more event than page one carries.
		const original = ocsRequest.getMockImplementation()
		ocsRequest.mockImplementation((config: { url?: string; method?: string }) => {
			const match = /\/transactions\/([^/?]+)\/audit\?.*page=(\d+)/.exec(config?.url ?? '')
			if (match && match[1] === 'tx-1' && match[2] === '1') {
				return ocsOk({ ...pageOf([
					auditFixture({ id: 'e-1', occurredAt: '2026-03-02T10:00:00Z' }),
					auditFixture({ id: 'e-3', eventType: 'SUBMITTED', decision: null, after: null, occurredAt: '2026-03-01T09:00:00Z' }),
				]), total: 3 })
			}
			return original?.(config as never) ?? ocsOk(pageOf([]))
		})

		const wrapper = await mountAt('/sheets/sh-1', SheetListView)
		await wrapper.find('[data-test="history-tab"]').trigger('click')
		await flushPromises()

		const events = wrapper.findAll('[data-test="history-event"]')
		expect(events).toHaveLength(3)
		expect(events[0]?.text()).toContain('history.event.REJECTED')
		expect(events[1]?.text()).toContain('history.event.APPROVED')

		const decisions = wrapper.findAll('[data-test="history-event-decision"]').map((item) => item.text())
		expect(decisions).toContain('reviews.decision.approve')
		expect(decisions).toContain('reviews.decision.reject')
		expect(wrapper.find('[data-test="history-panel"]').text()).not.toContain('DECISION')
		expect(wrapper.find('[data-test="history-disclaimer"]').exists()).toBe(true)

		const more = wrapper.findAll('[data-test="history-load-more"]')
		expect(more).toHaveLength(1)
		await more[0]?.trigger('click')
		await flushPromises()

		expect(ocsRequest.mock.calls.some((call) => /\/transactions\/tx-1\/audit\?.*page=2/.test(String((call[0] as { url?: string })?.url ?? '')))).toBe(true)
		expect(wrapper.findAll('[data-test="history-event"]')).toHaveLength(4)
		expect(wrapper.findAll('[data-test="history-load-more"]')).toHaveLength(0)
	})
})

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory, type Router } from 'vue-router'
import TransactionFormView from '@/views/transactions/TransactionFormView.vue'
import { routes } from '@/router'
import type { SheetDetail, TransactionDetail } from '@/types/api'

const ocsRequest = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({
	default: {
		request: (...args: unknown[]) => ocsRequest(...args),
		isAxiosError: (error: unknown) => Boolean((error as { isAxiosError?: boolean } | null)?.isAxiosError)
	}
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => `/ocs/v2.php/apps/pettycash/api/v1${path}`,
	imagePath: (path: string) => `/apps/pettycash/${path}`,
	appName: 'pettycash',
	appVersion: '1.0.0'
}))

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (_match, key: string) => String(params?.[key] ?? `{${key}}`))),
	n: vi.fn((_app: string, text: string) => text),
	getLanguage: vi.fn(() => 'en'),
	isRTL: vi.fn(() => false),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`)
}))

const COST_CENTER_ID = '11111111-1111-4111-8111-111111111111'
const EXPENSE_TYPE_ID = '33333333-3333-4333-8333-333333333333'

function revisionFixture() {
	return {
		id: 'rev-1',
		number: 1,
		expenseDate: '2026-01-15',
		amount: { amountMinor: 1000, currency: 'IRR', minorUnit: 0 },
		description: 'Taxi',
		costCenter: { id: COST_CENTER_ID, code: 'ENG', name: 'Engineering' },
		expenseType: { id: EXPENSE_TYPE_ID, name: 'Office Supplies' },
		createdBy: 'employee1',
		createdAt: '2026-01-15T08:00:00Z',
		active: true
	}
}

function createdTransaction(id = 'tx-created'): TransactionDetail {
	return {
		id,
		sheetId: 'sheet-1',
		activeRevision: revisionFixture(),
		stage: 'DRAFT',
		status: 'DRAFT',
		finalOutcome: null,
		latestDecision: undefined,
		evidenceRequired: false,
		evidenceCount: 0,
		version: 0,
		allowedActions: {},
		decisions: [],
		evidence: []
	}
}

function sheetFixture(): SheetDetail {
	const money = (amountMinor: number) => ({ amountMinor, currency: 'IRR', minorUnit: 0 })
	return {
		id: 'sheet-1',
		accountId: 'b4b0b3a4-1111-4c2d-9e0f-000000000001',
		title: 'March travel',
		ownerId: 'employee1',
		ownerDisplayName: 'Employee One',
		currency: 'IRR',
		status: 'OPEN',
		transactionCount: 0,
		totals: {
			current: money(0),
			submitted: money(0),
			effectiveApproved: money(0),
			effectiveRejected: money(0),
			effectivePending: money(0),
			costCenterApproved: money(0),
			seniorApproved: money(0),
			financeApproved: money(0)
		},
		createdAt: '2026-01-01T00:00:00+00:00',
		submittedAt: null,
		completedAt: null,
		firstExpenseDate: null,
		lastExpenseDate: null,
		activeRevisionNumber: 0,
		version: 0,
		allowedActions: {},
		transactions: []
	}
}

function pageOf(items: unknown[]) {
	return { items, page: 1, pageSize: 25, total: items.length }
}

function ocsOk(data: unknown) {
	return { status: 200, data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data } } }
}

function createTestRouter(): Router {
	window.location.hash = ''
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

let createFailure: { status: number; body: Record<string, unknown> } | null = null
let createId = 'tx-created'
let uploadFailureCount = 0
const events: string[] = []

function stubTransport(): void {
	ocsRequest.mockImplementation((config: { url?: string; method?: string }) => {
		const method = (config?.method ?? 'GET').toUpperCase()
		const url = config?.url ?? ''
		if (method === 'POST' && /\/transactions$/.test(url)) {
			events.push('create')
			if (createFailure) {
				return Promise.reject(Object.assign(new Error(`Request failed with status code ${createFailure.status}`), {
					isAxiosError: true,
					response: {
						status: createFailure.status,
						data: {
							ocs: {
								meta: { status: 'failure', statuscode: createFailure.status },
								data: { error: createFailure.body }
							}
						}
					}
				}))
			}
			return Promise.resolve(ocsOk(createdTransaction(createId)))
		}
		if (/\/configuration\/cost-centers(\?|$)/.test(url)) {
			return Promise.resolve(ocsOk(pageOf([{ id: COST_CENTER_ID, code: 'ENG', name: 'Engineering', validFrom: '2026-01-01', validTo: null, active: true, version: 0 }])))
		}
		if (/\/configuration\/expense-types(\?|$)/.test(url)) {
			return Promise.resolve(ocsOk(pageOf([{ id: EXPENSE_TYPE_ID, title: 'Office Supplies', evidenceRequired: false, active: true, version: 0 }])))
		}
		if (/\/sheets\/[^/?]+(\?|$)/.test(url)) return Promise.resolve(ocsOk(sheetFixture()))
		return Promise.resolve(ocsOk(pageOf([])))
	})
}

function attachFiles(wrapper: VueWrapper, files: File[]): Promise<void> {
	const input = wrapper.find('[data-test="staged-receipt-input"]')
	Object.defineProperty(input.element, 'files', { configurable: true, value: files })
	return input.trigger('change')
}

async function mountCreate(): Promise<{ wrapper: VueWrapper; router: Router }> {
	const pinia = createPinia()
	setActivePinia(pinia)
	const router = createTestRouter()
	await router.push('/sheets/sheet-1/transactions/create')
	await router.isReady()
	const wrapper = mount(TransactionFormView, {
		global: { plugins: [pinia, router], stubs: { RouterLink: true } }
	})
	await flushPromises()
	return { wrapper, router }
}

async function save(wrapper: VueWrapper): Promise<void> {
	await wrapper.find('[data-test="expense-date-input"]').setValue('2026-01-15')
	await wrapper.find('[data-test="amount-input"]').setValue('1000')
	await wrapper.find('form').trigger('submit.prevent')
	await flushPromises()
}

function createCalls(): number {
	return events.filter((event) => event === 'create').length
}

describe('pre-save receipt staging (T054/T055)', () => {
	beforeEach(() => {
		createFailure = null
		createId = 'tx-created'
		uploadFailureCount = 0
		events.length = 0
		stubTransport()
		vi.stubGlobal('fetch', vi.fn((url: string, config: { body?: FormData }) => {
			events.push('upload')
			const file = config.body?.get('file')
			const name = file instanceof File ? file.name : 'unknown'
			const callIndex = events.filter((event) => event === 'upload').length
			if (callIndex <= uploadFailureCount) {
				return Promise.resolve({
					ok: false,
					json: async () => ({ ocs: { data: { error: { message: `storage refused ${name}` } } } })
				})
			}
			return Promise.resolve({
				ok: true,
				json: async () => ({ ocs: { data: { id: `ev-${callIndex}`, name, active: true } } })
			})
		}))
	})

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('shows and removes a multi-file queue without issuing a request', async () => {
		const { wrapper } = await mountCreate()
		await attachFiles(wrapper, [
			new File(['one'], 'receipt-one.pdf', { type: 'application/pdf' }),
			new File(['two'], 'receipt-two.png', { type: 'image/png' })
		])
		await wrapper.vm.$nextTick()

		expect(wrapper.findAll('[data-test="staged-receipt-item"]')).toHaveLength(2)
		expect(wrapper.text()).toContain('receipt-one.pdf')
		expect(wrapper.text()).toContain('receipt-two.png')
		expect(fetch).not.toHaveBeenCalled()
		expect(createCalls()).toBe(0)

		await wrapper.findAll('[data-test="staged-receipt-remove"]')[0]?.trigger('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.findAll('[data-test="staged-receipt-item"]')).toHaveLength(1)
		expect(wrapper.text()).not.toContain('receipt-one.pdf')
	})

	it('treats a non-empty queue as dirty and opens the unsaved-changes guard', async () => {
		const { wrapper } = await mountCreate()
		await attachFiles(wrapper, [new File(['one'], 'receipt-one.pdf', { type: 'application/pdf' })])
		await wrapper.vm.$nextTick()

		await wrapper.find('[data-test="transaction-cancel"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-test="unsaved-changes-dialog"]').exists()).toBe(true)
	})

	it('creates first, then uploads every staged file to the returned non-empty id', async () => {
		const { wrapper, router } = await mountCreate()
		await attachFiles(wrapper, [
			new File(['one'], 'receipt-one.pdf', { type: 'application/pdf' }),
			new File(['two'], 'receipt-two.png', { type: 'image/png' })
		])
		await wrapper.vm.$nextTick()

		await save(wrapper)

		expect(events).toEqual(['create', 'upload', 'upload'])
		expect(createCalls()).toBe(1)
		const calls = (fetch as unknown as { mock: { calls: Array<[string, { body?: FormData }]> } }).mock.calls
		expect(calls[0]?.[0]).toContain('/transactions/tx-created/evidence')
		expect(calls[1]?.[0]).toContain('/transactions/tx-created/evidence')
		expect(router.currentRoute.value.name).toBe('sheet-detail')
	})

	it('leaves the transaction saved when an upload fails, names the file, and retries without creating again', async () => {
		uploadFailureCount = 1
		const { wrapper } = await mountCreate()
		await attachFiles(wrapper, [
			new File(['one'], 'receipt-one.pdf', { type: 'application/pdf' }),
			new File(['two'], 'receipt-two.png', { type: 'image/png' })
		])
		await wrapper.vm.$nextTick()

		await save(wrapper)

		expect(createCalls()).toBe(1)
		const failures = wrapper.find('[data-test="staged-upload-failures"]')
		expect(failures.exists()).toBe(true)
		expect(failures.text()).toContain('receipt-one.pdf')
		expect(failures.text()).toContain('storage refused receipt-one.pdf')
		// Only the successful file was removed; the failed one is still queued and retryable.
		expect(wrapper.findAll('[data-test="staged-receipt-item"]')).toHaveLength(1)

		await wrapper.find('[data-test="retry-staged-uploads"]').trigger('click')
		await flushPromises()

		expect(createCalls()).toBe(1)
		expect(events.filter((event) => event === 'upload')).toHaveLength(3)
		expect(wrapper.find('[data-test="staged-upload-failures"]').exists()).toBe(false)
	})

	it('fails closed when the create response has no id and issues no upload', async () => {
		createId = ''
		const { wrapper } = await mountCreate()
		await attachFiles(wrapper, [new File(['one'], 'receipt-one.pdf', { type: 'application/pdf' })])
		await wrapper.vm.$nextTick()

		await save(wrapper)

		expect(createCalls()).toBe(1)
		expect(fetch).not.toHaveBeenCalled()
		expect(wrapper.find('[data-test="staged-upload-failures"]').text()).toContain('transactions.receiptUploadNoId')

		await wrapper.find('[data-test="retry-staged-uploads"]').trigger('click')
		await flushPromises()
		expect(fetch).not.toHaveBeenCalled()
		expect(createCalls()).toBe(1)
	})
})

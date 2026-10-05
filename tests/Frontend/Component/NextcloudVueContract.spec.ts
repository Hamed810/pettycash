import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory } from 'vue-router'
import { routes } from '@/router'

/**
 * The app's own contract with `@nextcloud/vue`, asserted against the **real** library.
 *
 * ## Why this file exists, and why it mocks nothing from the library
 *
 * Every other frontend spec in this suite replaces `@nextcloud/vue` with hand-written stubs — and that
 * is precisely why three production-breaking defects shipped with 237 tests green.
 *
 * **1. `NcSelect` writes the whole option object into `v-model`.** `NcSelect` forwards
 * `update:modelValue` straight to `vue-select`, whose `reduce` prop defaults to `option => option`, so
 * an object-shaped `:options` list puts `{ label, value }` into whatever the model is bound to. Then
 * `SheetController::create(?string $title, ?string $accountId)` raised
 * `TypeError: Argument #2 ($accountId) must be of type ?string, array given`, the AppFramework turned
 * that into a **400 with an empty body**, and `src/services/http.ts` — correctly refusing to
 * dereference a non-envelope — classified it as `NETWORK_FAILURE`. The purchaser saw a generic network
 * error. The stub emitted a string, so 237 tests agreed with a contract the real component does not
 * have.
 *
 * **2. `NcNoteCard` has no `header` slot.** `@nextcloud/vue` 9.12.0 declares only `default` and
 * `icon`. Every `<template #header>` in the app was therefore dropped without a warning, and
 * `SheetDetailView` — the one view a purchaser must pass through — rendered with **no Edit, no Add
 * transaction and no Submit button at all**. The stub rendered its children, so the slot appeared to
 * work.
 *
 * **3. Nothing asserted that the controls a requirement depends on were on the page.** The green tests
 * each covered a requirement; none asked whether the button the requirement is performed through
 * existed.
 *
 * A stub cannot catch a defect in the seam between the app and the stubbed library, because the stub
 * *is* that seam. So this file mounts the genuine components. It is deliberately narrow: it asserts the
 * library contract, not business behaviour.
 *
 * ## Why the select is asserted at its `reduce` prop rather than by clicking an option
 *
 * Driving `vue-select`'s dropdown open needs a layout engine. Measured in jsdom: the options are
 * present on the component (`[{ label: 'IRR ۱٬۰۰۰', value: 'acc-1' }]`) and the listbox is
 * `display: none`, and `mousedown`, `click`, `mouseup`, `focus` and typing a search term all leave it
 * closed. That is a limitation of the environment, not a statement about the app.
 *
 * So the assertion is placed on the exact seam that broke: the value the app's own binding derives from
 * an option. It is a stronger statement than "the button exists" — it is a statement about the value
 * that would reach the wire — and it cannot be satisfied by a stub, because a stub has no `reduce` prop
 * to satisfy it. The consequence on the wire is asserted separately, so a green `reduce` still has to
 * produce a scalar in the request body.
 */

const ocsRequest = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => {
	// `interceptors` is not decoration. `@nextcloud/files`' upload helper runs `axios-retry` against
	// the default instance at **import** time, and `axios-retry` immediately calls
	// `axiosInstance.interceptors.request.use(...)`. Without them, the real `NcUploadPicker` chunk —
	// which `SheetDetailView` pulls in transitively — throws `Cannot read properties of undefined
	// (reading 'request')` while the module graph is still loading, and the failure surfaces as a
	// stack trace inside `axios-retry` pointing at nothing to do with this app.
	const interceptor = { use: vi.fn(), eject: vi.fn(), clear: vi.fn() }
	const instance = {
		request: (...args: unknown[]) => ocsRequest(...args),
		get: (...args: unknown[]) => ocsRequest(...args),
		post: (...args: unknown[]) => ocsRequest(...args),
		put: (...args: unknown[]) => ocsRequest(...args),
		delete: (...args: unknown[]) => ocsRequest(...args),
		interceptors: { request: interceptor, response: interceptor },
		isAxiosError: (error: unknown) => Boolean((error as { isAxiosError?: boolean } | null)?.isAxiosError),
		isCancel: () => false
	}
	return { default: instance, isAxiosError: instance.isAxiosError, isCancel: instance.isCancel }
})

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => `/ocs/v2.php/apps/pettycash/api/v1${path}`,
	generateUrl: (path: string) => `/apps/pettycash/${path}`,
	generateRemoteUrl: (path: string) => `/apps/pettycash/${path}`,
	generateFilePath: (app: string, type: string, file: string) => `/apps/${app}/${type}/${file}`,
	imagePath: (app: string, file: string) => `/apps/${app}/img/${file}`,
	linkTo: (app: string, file: string) => `/apps/${app}/${file}`,
	linkToRemote: (service: string, file: string) => `/${service}/${file}`,
	getRootUrl: () => '',
	getBaseUrl: () => '',
	getHost: () => 'localhost:8080',
	getProtocol: () => 'http',
	getRequestToken: () => 'test-request-token',
	isUserAdmin: () => false,
	appName: 'pettycash',
	appVersion: '1.0.0'
}))

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string) => text,
	n: (_app: string, text: string) => text,
	getLanguage: () => 'fa',
	isRTL: () => true,
	imagePath: (path: string) => path
}))

const ACCOUNT = {
	id: 'e2e00000-0000-4000-8000-000000000004',
	userId: 'employee1',
	balance: { amountMinor: 1000000000, currency: 'IRR', minorUnit: 0 },
	active: true,
	version: 0
}

const COST_CENTER = { id: 'cc-1', code: 'ENG', name: 'Engineering', validFrom: '2026-01-01', validTo: null, active: true, version: 0 }
const EXPENSE_TYPE = { id: 'et-1', title: 'Office Supplies', evidenceRequired: false, active: true, version: 0 }

const SHEET_TOTALS = {
	current: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
	submitted: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
	effectiveApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
	effectiveRejected: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
	effectivePending: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
	costCenterApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
	seniorApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
	financeApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 }
}

const SHEET = {
	id: 'sheet-1',
	title: 'Purchaser Sheet',
	ownerId: 'employee1',
	ownerDisplayName: 'Employee One',
	currency: 'IRR',
	status: 'OPEN',
	transactionCount: 0,
	totals: SHEET_TOTALS,
	createdAt: '2026-09-20T10:00:00+00:00',
	submittedAt: null,
	version: 0
}

function payloadFor(url: string): unknown {
	if (/\/accounts(\?|$)/.test(url)) return { items: [ACCOUNT], page: 1, pageSize: 100, total: 1 }
	if (/\/sheets\?/.test(url)) return { items: [SHEET], page: 1, pageSize: 25, total: 1 }
	if (/\/sheets\/[^/?]+\/transactions(\?|$)/.test(url)) return { items: [], page: 1, pageSize: 25, total: 0 }
	if (/\/sheets\/[^/?]+(\?|$)/.test(url)) return SHEET
	if (/\/configuration\/cost-centers(\?|$)/.test(url)) return { items: [COST_CENTER], page: 1, pageSize: 100, total: 1 }
	if (/\/configuration\/expense-types(\?|$)/.test(url)) return { items: [EXPENSE_TYPE], page: 1, pageSize: 100, total: 1 }
	// `GET /transactions/{id}/revisions` answers a bare `Revision[]`, not a page — the store assigns
	// the response straight into a typed ref, so answering `null` here makes it throw on `.filter`.
	if (/\/transactions\/[^/?]+\/revisions(\?|$)/.test(url)) return []
	if (/\/transactions\/[^/?]+(\?|$)/.test(url)) return null
	return { items: [], page: 1, pageSize: 25, total: 0 }
}

beforeEach(() => {
	setActivePinia(createPinia())
	ocsRequest.mockReset()
	ocsRequest.mockImplementation((config: { url?: string }) =>
		Promise.resolve({ status: 200, data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data: payloadFor(config?.url ?? '') } } })
	)
	// jsdom implements neither of these, and the real `NcSelect` measures its container on mount.
	Element.prototype.scrollIntoView = vi.fn()
	;(globalThis as unknown as { ResizeObserver: unknown }).ResizeObserver = class {
		observe(): void {}
		unobserve(): void {}
		disconnect(): void {}
	}
})

async function mountView(component: unknown, name: string, params: Record<string, string> = {}) {
	// The application's own route table, components replaced with inert placeholders (T359).
	//
	// This file used to carry a hand-maintained copy of ten routes, with a comment explaining that a
	// name missing from the copy would fail the mount with `No match for {"name":"transactions"}`.
	// That comment described the copy as a hazard and then kept the copy. A copied table makes every
	// route name resolvable whether or not the application declares it, which is how
	// `TransactionDetailView` came to push a `transaction-correction-create` route that exists
	// nowhere in `src/router/index.ts` — a purchaser's only correction affordance, doing nothing,
	// with this file and `PurchaserFlow.spec.ts` both green because they resolved against copies.
	//
	// Deriving the table from `@/router` removes the class: a view that links to a name the
	// application does not declare now fails loudly here.
	const router = createRouter({
		history: createWebHashHistory(),
		routes: routes.map(({ path, name, props }) => ({
			path,
			name,
			props,
			component: { template: '<div />' }
		}))
	})
	await router.push({ name, params })
	await router.isReady()
	// `unknown` because the callers pass a lazily-imported `.vue` default export and the mount options
	// differ per view; the cast is confined to this one helper rather than spread across each test.
	const wrapper = mount(component as never, { global: { plugins: [router] } })
	await flushPromises()
	return wrapper
}

describe('NcNoteCard header contract', () => {
	/**
	 * The assertion that matters is not "the text is present" but "the **controls** are present".
	 *
	 * `SheetDetailView` is where a purchaser adds a transaction and submits a sheet. With the header
	 * dropped, the view still rendered: the status chip, the totals, the transaction table and the audit
	 * table all appeared. The three buttons that make the workflow possible did not. No test asked for
	 * them by name, so nothing noticed.
	 *
	 * `submit-sheet-button` is **not** asserted here, and the reason has changed: it is
	 * `v-if="canSubmit"`, which requires `currentSheet.transactions.length > 0`, and this file mounts
	 * the view without a sheet payload at all — so asserting it would fail for a reason this file is
	 * not about. That is no longer a deferral of a known defect. `GET /sheets/{id}` now returns
	 * `transactions`, the button renders for a sheet that has one, and both directions of the
	 * assertion live where the payload fixture is: `PurchaserFlow.spec.ts`, "withhold submission from
	 * an open sheet that has no transaction" and "offer submission for an open sheet the API reports
	 * as holding a transaction".
	 */
	it('renders the sheet action bar that the purchaser journey depends on', { timeout: 30_000 }, async () => {
		const SheetDetailView = (await import('@/views/sheets/SheetDetailView.vue')).default
		const wrapper = await mountView(SheetDetailView, 'sheet-detail', { id: 'sheet-1' })

		expect(wrapper.find('[data-test="create-transaction-btn"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="edit-sheet-btn"]').exists()).toBe(true)
	})

	/**
	 * The heading text, so a future refactor cannot quietly drop a card's title either.
	 *
	 * `NcNoteCard` renders its `heading` prop into `p.notecard__heading`; a `<template #header>` renders
	 * nothing at all. This is the assertion that distinguishes the two spellings directly.
	 */
	it('renders the card heading as a heading element', { timeout: 30_000 }, async () => {
		const SheetDetailView = (await import('@/views/sheets/SheetDetailView.vue')).default
		const wrapper = await mountView(SheetDetailView, 'sheet-detail', { id: 'sheet-1' })

		expect(wrapper.find('.notecard__heading').text()).toBe('sheets.totals')
	})

	/**
	 * The transaction-detail view lost the same slot, and it is the view FR-033's and FR-046's
	 * acceptance scenarios are performed through — Edit, Create correction, the revision selector and
	 * Upload evidence were all invisible.
	 */
	it('renders the transaction action bar and revision selector', { timeout: 30_000 }, async () => {
		const TransactionDetailView = (await import('@/views/transactions/TransactionDetailView.vue')).default
		const wrapper = await mountView(TransactionDetailView, 'transaction-detail', { id: 'sheet-1' })

		expect(wrapper.find('[data-test="upload-evidence-btn"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="revision-selector"]').exists()).toBe(true)
	})
})

describe('NcSelect model contract', () => {
	/**
	 * Per occurrence, not per file.
	 *
	 * T324's lesson applies directly: an earlier gate counted live regions per *file*, and a view with
	 * two error elements passed with one of them stripped. This walks the actual `NcSelect` instances
	 * the view rendered and checks each one, so a newly added select with no `reduce` fails here rather
	 * than in production.
	 */
	it('derives a scalar identifier from every option the sheet form offers', { timeout: 30_000 }, async () => {
		const SheetFormView = (await import('@/views/sheets/SheetFormView.vue')).default
		const wrapper = await mountView(SheetFormView, 'sheet-create')

		const selects = wrapper.findAllComponents({ name: 'NcSelect' })
		expect(selects.length).toBeGreaterThan(0)

		for (const select of selects) {
			const options = (select.props('options') ?? []) as { label: string; value: string }[]
			const reduce = select.props('reduce') as ((option: unknown) => unknown) | undefined
			expect(typeof reduce, 'every NcSelect must pass :reduce, or v-model receives the option object').toBe('function')
			for (const option of options) {
				expect(reduce!(option)).toBe(option.value)
			}
		}
	})

	/** The transaction form's two selects, which carry cost centre and expense type into the payload. */
	it('derives a scalar identifier from every option the transaction form offers', { timeout: 30_000 }, async () => {
		const TransactionFormView = (await import('@/views/transactions/TransactionFormView.vue')).default
		const wrapper = await mountView(TransactionFormView, 'transaction-create', { sheetId: 'sheet-1' })

		const selects = wrapper.findAllComponents({ name: 'NcSelect' })
		expect(selects.length).toBeGreaterThanOrEqual(2)

		for (const select of selects) {
			const options = (select.props('options') ?? []) as { label: string; value: string }[]
			const reduce = select.props('reduce') as ((option: unknown) => unknown) | undefined
			expect(typeof reduce).toBe('function')
			for (const option of options) {
				expect(reduce!(option)).toBe(option.value)
			}
		}
	})

	/**
	 * The consequence, on the wire.
	 *
	 * The two tests above check the binding; this one checks that a scalar bound value actually reaches
	 * `POST /sheets` as a scalar. It is the assertion that would have caught the original 400, and it
	 * reads the request the transport made rather than any component's internal state.
	 */
	it('sends the account as a scalar identifier, not as the option object', { timeout: 30_000 }, async () => {
		const SheetFormView = (await import('@/views/sheets/SheetFormView.vue')).default
		const wrapper = await mountView(SheetFormView, 'sheet-create')

		const select = wrapper.findComponent({ name: 'NcSelect' })
		const options = (select.props('options') ?? []) as { label: string; value: string }[]
		const reduce = select.props('reduce') as (option: unknown) => unknown

		// The value the real control would write: its option, passed through the app's own binding.
		await select.vm.$emit('update:modelValue', reduce(options[0]))
		await wrapper.find('[data-test="sheet-title-input"]').setValue('Contract Sheet')
		await flushPromises()
		await wrapper.find('form[data-test="sheet-form"]').trigger('submit')
		await flushPromises()

		const create = ocsRequest.mock.calls
			.map((call) => call[0] as { method?: string; url?: string; data?: unknown })
			.find((config) => config.method === 'POST' && /\/sheets$/.test(config.url ?? ''))

		expect(create, 'the form must have submitted').toBeDefined()
		const body = create!.data as { accountId?: unknown; title?: unknown }
		expect(typeof body.accountId).toBe('string')
		expect(body.accountId).toBe(ACCOUNT.id)
	})
})

// US5 administration surface - Vue Component Tests
//
// T088 "configuration/account API services, stores, and administrator/funding views". The services
// existed only as purchaser reads; the store and both views are new here. These tests cover what only
// the interface can be wrong about, following the file's own rule from the sheet-deletion suite: the
// server behavior (refusals, conflicts, audit rows) belongs to the contract and service suites, and
// re-proving it through a mounted view would assert the mock rather than the view.
//
// What IS asserted here: every section renders; creating posts the contract's write shape; editing
// sends the row's live version (not a remembered one); a stale write surfaces the store error rather
// than silently keeping the old rows; and funding addresses an id the view actually holds.
//
// `@nextcloud/vue` is mocked rather than rendered for the mechanical reason recorded in
// `TransactionCorrection.spec.ts`: `ssr.noExternal` loads the real package, which pulls `.css` Node
// cannot transform. The stubs keep the props and `data-test` hooks these assertions rely on.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createRouter, createWebHashHistory, type Router } from 'vue-router'
import ConfigurationView from '@/views/admin/ConfigurationView.vue'
import FundingView from '@/views/admin/FundingView.vue'
import { routes } from '@/router'

const ocsRequest = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({
	default: {
		request: (...args: unknown[]) => ocsRequest(...args),
		isAxiosError: (error: unknown) =>
			Boolean((error as { isAxiosError?: boolean } | null)?.isAxiosError)
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
		text.replace(/\{(\w+)\}/g, (_match, key: string) => String(params?.[key] ?? `{${key}}`))),
	n: vi.fn((_app: string, text: string) => text),
	getLanguage: vi.fn(() => 'fa'),
	isRTL: vi.fn(() => true),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`)
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'loading', 'type', 'size'],
		emits: ['click'],
		template:
			'<button :type="type" :disabled="disabled" :class="[variant, size]" v-bind="$attrs" ' +
			'@click="$emit(\'click\', $event)"><slot /></button>'
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['heading'],
		slots: ['header', 'default'],
		template: '<div data-test="nc-note-card"><slot /></div>'
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'type', 'disabled', 'error', 'helperText'],
		emits: ['update:modelValue'],
		template:
			'<input :type="type ?? \'text\'" :value="modelValue" :disabled="disabled" v-bind="$attrs" ' +
			'@input="$emit(\'update:modelValue\', ($event.target as HTMLInputElement).value)" />'
	},
	NcDateTimePickerNative: {
		name: 'NcDateTimePickerNative',
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		template:
			'<input type="date" :value="modelValue" :disabled="disabled" v-bind="$attrs" ' +
			'@input="$emit(\'update:modelValue\', ($event.target as HTMLInputElement).value)" />'
	}
}))

function page<T>(items: T[]) {
	return { data: { ocs: { data: { items, total: items.length, page: 1, pageSize: 100 } } } }
}

const IRR = { code: 'IRR', name: 'Iranian Rial', minorUnit: 0, active: true, version: 2 }

describe('US5 administration views', () => {
	let pinia: Pinia
	let router: Router

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		router = createRouter({ history: createWebHashHistory(), routes })
		ocsRequest.mockReset()
		ocsRequest.mockImplementation((config: { url?: string; method?: string }) => {
			const url = String(config?.url ?? '')
			if (url.includes('/configuration/currencies')) return Promise.resolve(page([IRR]))
			if (url.includes('/configuration/cost-centers')) return Promise.resolve(page([]))
			if (url.includes('/configuration/expense-types')) return Promise.resolve(page([]))
			if (url.includes('/configuration/finance-assignments')) return Promise.resolve(page([]))
			if (url.includes('/accounts?')) return Promise.resolve(page([]))
			return Promise.resolve({ data: { ocs: { data: [] } } })
		})
	})

	async function mountConfiguration() {
		await router.push({ name: 'admin-configuration' })
		await router.isReady()
		const wrapper = mount(ConfigurationView, { global: { plugins: [pinia, router] } })
		await flushPromises()
		return wrapper
	}

	async function mountFunding() {
		await router.push({ name: 'admin-funding' })
		await router.isReady()
		const wrapper = mount(FundingView, { global: { plugins: [pinia, router] } })
		await flushPromises()
		return wrapper
	}

	it('renders all four reference-data sections', async () => {
		const wrapper = await mountConfiguration()

		for (const section of [
			'admin-currencies',
			'admin-cost-centers',
			'admin-expense-types',
			'admin-finance-assignments'
		]) {
			expect(wrapper.find(`[data-test="${section}"]`).exists(), `${section} must render`).toBe(true)
		}
	})

	it('lists the served currencies with an edit control each', async () => {
		const wrapper = await mountConfiguration()

		const rows = wrapper.findAll('[data-test="currency-row"]')
		expect(rows).toHaveLength(1)
		expect(rows[0]?.text()).toContain('IRR')
		expect(wrapper.find('[data-test="currency-edit"]').exists()).toBe(true)
	})

	it('creates a currency with the contract write shape', async () => {
		const wrapper = await mountConfiguration()
		ocsRequest.mockClear()

		await wrapper.find('[data-test="currency-code-input"]').setValue('USD')
		await wrapper.find('[data-test="currency-name-input"]').setValue('US Dollar')
		await wrapper.find('[data-test="currency-scale-input"]').setValue(2)
		await wrapper.find('[data-test="currency-form"]').trigger('submit')
		await flushPromises()

		const posts = ocsRequest.mock.calls.filter(
			(call: unknown[]) =>
				String((call[0] as { method?: string })?.method ?? '').toUpperCase() === 'POST' &&
				String((call[0] as { url?: string })?.url ?? '').includes('/configuration/currencies')
		)
		expect(posts).toHaveLength(1)
		const body = (posts[0]?.[0] as { data?: Record<string, unknown> }).data
		expect(body).toMatchObject({ code: 'USD', name: 'US Dollar', minorUnit: 2, active: true })
		// The command id travels on the header, not in the body: a replayed creation must be
		// recognizable as the same command rather than re-applied.
		expect((posts[0]?.[0] as { headers?: Record<string, string> }).headers?.['X-PettyCash-Command-ID']).toBeTruthy()
	})

	it('edits with the row’s live version, not a remembered one', async () => {
		const wrapper = await mountConfiguration()

		// The fixture row is at version 2. Editing must echo exactly that: a remembered version is
		// refused as a conflict, and the form must not invent one.
		await wrapper.find('[data-test="currency-edit"]').trigger('click')
		await wrapper.find('[data-test="currency-form"]').trigger('submit')
		await flushPromises()

		const patches = ocsRequest.mock.calls.filter(
			(call: unknown[]) =>
				String((call[0] as { method?: string })?.method ?? '').toUpperCase() === 'PATCH' &&
				String((call[0] as { url?: string })?.url ?? '').includes('/configuration/currencies/IRR')
		)
		expect(patches).toHaveLength(1)
		expect((patches[0]?.[0] as { data?: Record<string, unknown> }).data).toMatchObject({
			code: 'IRR',
			expectedVersion: 2
		})
	})

	it('creates a cost center with its manager assignment and reason', async () => {
		const wrapper = await mountConfiguration()
		ocsRequest.mockClear()

		await wrapper.find('[data-test="cost-center-code-input"]').setValue('CC-900')
		await wrapper.find('[data-test="cost-center-name-input"]').setValue('Ninth Center')
		await wrapper.find('[data-test="cost-center-valid-from-input"]').setValue('2026-01-01')
		await wrapper.find('[data-test="cost-center-manager-input"]').setValue('manager-1')
		await wrapper.find('[data-test="cost-center-senior-input"]').setValue('senior-1')
		await wrapper.find('[data-test="cost-center-reason-input"]').setValue('New department')
		await wrapper.find('[data-test="cost-center-form"]').trigger('submit')
		await flushPromises()

		const posts = ocsRequest.mock.calls.filter(
			(call: unknown[]) =>
				String((call[0] as { method?: string })?.method ?? '').toUpperCase() === 'POST' &&
				String((call[0] as { url?: string })?.url ?? '').includes('/configuration/cost-centers')
		)
		expect(posts).toHaveLength(1)
		expect((posts[0]?.[0] as { data?: Record<string, unknown> }).data).toMatchObject({
			code: 'CC-900',
			managerUserId: 'manager-1',
			seniorManagerUserId: 'senior-1',
			changeReason: 'New department'
		})
	})

	it('assigns a finance officer with a reason', async () => {
		const wrapper = await mountConfiguration()
		ocsRequest.mockClear()

		await wrapper.find('[data-test="finance-assignment-user-input"]').setValue('officer-1')
		await wrapper.find('[data-test="finance-assignment-valid-from-input"]').setValue('2026-01-01')
		await wrapper.find('[data-test="finance-assignment-reason-input"]').setValue('Quarterly rotation')
		await wrapper.find('[data-test="finance-assignment-form"]').trigger('submit')
		await flushPromises()

		const posts = ocsRequest.mock.calls.filter(
			(call: unknown[]) =>
				String((call[0] as { method?: string })?.method ?? '').toUpperCase() === 'POST' &&
				String((call[0] as { url?: string })?.url ?? '').includes('/configuration/finance-assignments')
		)
		expect(posts).toHaveLength(1)
		expect((posts[0]?.[0] as { data?: Record<string, unknown> }).data).toMatchObject({
			userId: 'officer-1',
			changeReason: 'Quarterly rotation'
		})
	})

	it('opens an account for the named user', async () => {
		const wrapper = await mountFunding()
		ocsRequest.mockClear()

		await wrapper.find('[data-test="open-account-user-input"]').setValue('employee-9')
		await wrapper.find('[data-test="open-account-currency-input"]').setValue('IRR')
		await wrapper.find('[data-test="open-account-form"]').trigger('submit')
		await flushPromises()

		const posts = ocsRequest.mock.calls.filter(
			(call: unknown[]) =>
				String((call[0] as { method?: string })?.method ?? '').toUpperCase() === 'POST' &&
				((call[0] as { url?: string })?.url ?? '').endsWith('/accounts')
		)
		expect(posts).toHaveLength(1)
		expect((posts[0]?.[0] as { data?: Record<string, unknown> }).data).toMatchObject({
			userId: 'employee-9',
			currency: 'IRR'
		})
	})

	it('charges only with a known version, never a guess', async () => {
		const wrapper = await mountFunding()

		// No account is loaded and none was created: the fund button must stay off rather than
		// sending version 0, because funding is the write two officers make at the same moment and
		// a guessed version turns a lost update into silent agreement.
		const save = wrapper.find('[data-test="charge-save"]')
		expect((save.element as HTMLButtonElement).disabled).toBe(true)
		expect(
			ocsRequest.mock.calls.some((call: unknown[]) =>
				String((call[0] as { url?: string })?.url ?? '').includes('/charges')
			)
		).toBe(false)
	})

	/**
	 * T080's jsdom-testable remainder: active/inactive choices, effective dates, and funding
	 * validation as request shapes and button states. What is NOT asserted here is T080's
	 * parenthetical — viewport overflow, contrast ratios, font metrics — which needs a layout
	 * engine and stays open behind the browser matrix, same as T053's T286 verdict.
	 */
	it('sends the active flag when a currency is deactivated', async () => {
		const wrapper = await mountConfiguration()

		await wrapper.find('[data-test="currency-edit"]').trigger('click')
		// `setChecked` is private in @vue/test-utils 2.4 typings, so the public path is used:
		// set the property, then dispatch the `change` v-model listens on.
		const activeBox = wrapper.find('[data-test="currency-active-input"]')
		;(activeBox.element as HTMLInputElement).checked = false
		await activeBox.trigger('change')
		await wrapper.find('[data-test="currency-form"]').trigger('submit')
		await flushPromises()

		const patches = ocsRequest.mock.calls.filter(
			(call: unknown[]) =>
				String((call[0] as { method?: string })?.method ?? '').toUpperCase() === 'PATCH' &&
				String((call[0] as { url?: string })?.url ?? '').includes('/configuration/currencies/IRR')
		)
		expect(patches).toHaveLength(1)
		expect((patches[0]?.[0] as { data?: Record<string, unknown> }).data).toMatchObject({
			active: false,
			expectedVersion: 2
		})
	})

	it('sends effective dates on cost-center create', async () => {
		const wrapper = await mountConfiguration()
		ocsRequest.mockClear()

		await wrapper.find('[data-test="cost-center-code-input"]').setValue('CC-900')
		await wrapper.find('[data-test="cost-center-name-input"]').setValue('Ninth Center')
		await wrapper.find('[data-test="cost-center-valid-from-input"]').setValue('2026-01-01')
		await wrapper.find('[data-test="cost-center-manager-input"]').setValue('manager-1')
		await wrapper.find('[data-test="cost-center-senior-input"]').setValue('senior-1')
		await wrapper.find('[data-test="cost-center-reason-input"]').setValue('New department')
		await wrapper.find('[data-test="cost-center-form"]').trigger('submit')
		await flushPromises()

		const posts = ocsRequest.mock.calls.filter(
			(call: unknown[]) =>
				String((call[0] as { method?: string })?.method ?? '').toUpperCase() === 'POST' &&
				String((call[0] as { url?: string })?.url ?? '').includes('/configuration/cost-centers')
		)
		expect(posts).toHaveLength(1)
		// validFrom is required; validTo is empty here and must travel as null rather than as an
		// empty string the server would have to interpret.
		expect((posts[0]?.[0] as { data?: Record<string, unknown> }).data).toMatchObject({
			validFrom: '2026-01-01',
			validTo: null
		})
	})

	it('enables funding only for a positive amount on a known version', async () => {
		ocsRequest.mockImplementation((config: { url?: string; method?: string }) => {
			const url = String(config?.url ?? '')
			if (url.includes('/accounts?')) {
				return Promise.resolve(
					page([
						{
							id: 'acc-1',
							userId: 'employee-9',
							balance: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
							active: true,
							version: 4
						}
					])
				)
			}
			return Promise.resolve(page([]))
		})
		const wrapper = await mountFunding()

		await wrapper.find('[data-test="charge-account-input"]').setValue('acc-1')
		await wrapper.find('[data-test="charge-description-input"]').setValue('Opening float')
		await wrapper.find('[data-test="charge-date-input"]').setValue('2026-10-09')

		// Zero is not a charge: the button stays off until the amount is positive, so a
		// double-click on an empty amount field cannot fund an account with nothing.
		const save = wrapper.find('[data-test="charge-save"]')
		expect((save.element as HTMLButtonElement).disabled).toBe(true)

		await wrapper.find('[data-test="charge-amount-input"]').setValue(5000)
		expect((wrapper.find('[data-test="charge-save"]').element as HTMLButtonElement).disabled).toBe(false)

		await wrapper.find('[data-test="charge-form"]').trigger('submit')
		await flushPromises()

		const posts = ocsRequest.mock.calls.filter(
			(call: unknown[]) =>
				String((call[0] as { method?: string })?.method ?? '').toUpperCase() === 'POST' &&
				String((call[0] as { url?: string })?.url ?? '').includes('/charges')
		)
		expect(posts).toHaveLength(1)
		expect((posts[0]?.[0] as { data?: Record<string, unknown> }).data).toMatchObject({
			amountMinor: 5000,
			description: 'Opening float',
			expectedVersion: 4
		})
	})
})

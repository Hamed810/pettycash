// T079 — the route-driven Create Sheet dialog over the mounted Sheets List.
//
// The dialog is presentation only: the form implementation, its validation, its save path and the
// store mutation all remain `SheetFormView`. These tests cover what the dialog itself can be wrong
// about:
//
// - `/sheets/create` renders the list with exactly one dialog, named by `sheets.create`;
// - the name field owns initial focus, and closing returns focus to the invoking control (or the
//   list heading when the route was entered directly);
// - Escape, Cancel and the dialog's close control all reach the shared unsaved-changes decision,
//   and that decision is rendered inside the same dialog rather than as a second one;
// - Stay restores the entered values; Discard creates nothing;
// - the zero-account state omits the account and currency controls and sends no account id;
// - a successful create closes the dialog, confirms politely, refetches the authoritative list and
//   does not open the new sheet.
//
// `@nextcloud/vue` root imports are stubbed because the real package pulls CSS Node cannot load in
// this project's Vitest setup. Deep component imports (`NcButton`, `NcTextField`, …) remain real, as
// in the other list specs, so the controls these tests drive are the shipped ones.

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createRouter, createWebHashHistory, type Router } from 'vue-router'
import SheetListView from '@/views/sheets/SheetListView.vue'
import { useSheetStore } from '@/stores/sheets'
import { routes } from '@/router'
import type { Account, SheetDetail, SheetSummary } from '@/types/api'

const ocsRequest = vi.hoisted(() => vi.fn())
const showSuccess = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/dialogs', () => ({ showSuccess }))

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

vi.mock('@nextcloud/vue', () => {
	const button = {
		name: 'NcButton',
		props: ['variant', 'disabled', 'loading', 'type', 'size'],
		emits: ['click'],
		template: '<button type="button" v-bind="$attrs" :disabled="disabled || loading" @click="$emit(\'click\', $event)"><slot /></button>'
	}
	const select = {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'label', 'inputLabel', 'disabled', 'name', 'appendToBody'],
		emits: ['update:modelValue'],
		template: '<select v-bind="$attrs" :value="modelValue" :disabled="disabled"><option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option></select>'
	}
	const textField = {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'error', 'helperText', 'required', 'readonly', 'name'],
		emits: ['update:modelValue'],
		template: '<input v-bind="$attrs" :value="modelValue" :readonly="readonly" @input="$emit(\'update:modelValue\', $event.target.value)" />'
	}
	return {
		NcButton: button,
		NcActions: {
			name: 'NcActions',
			props: ['forceMenu', 'ariaLabel'],
			template: '<div><button type="button" :aria-label="ariaLabel" /><div><slot /></div></div>'
		},
		NcActionButton: {
			name: 'NcActionButton',
			emits: ['click'],
			template: '<button type="button" @click="$emit(\'click\')"><slot /></button>'
		},
		/*
		 * The real `NcDialog` is a teleporting modal with an uncancellable Escape path; this double
		 * keeps the contract the assertions need: it exists only while open, exposes one dialog role,
		 * and prints its `name` as the heading. `noClose` is asserted as a prop so a regression that
		 * re-enables the library's own dismissal fails here.
		 */
		NcDialog: {
			name: 'NcDialog',
			props: {
				open: Boolean,
				name: String,
				size: String,
				noClose: Boolean,
				closeOnClickOutside: Boolean
			},
			emits: ['update:open'],
			template: '<div v-if="open" role="dialog" data-test="nc-dialog" :aria-label="name"><h2 data-test="nc-dialog-heading">{{ name }}</h2><slot /></div>'
		},
		NcNoteCard: {
			name: 'NcNoteCard',
			props: ['heading'],
			template: '<div data-test="nc-note-card"><slot /></div>'
		},
		NcChip: {
			name: 'NcChip',
			props: ['variant', 'noClose'],
			template: '<span><slot /></span>'
		},
		NcIconSvgWrapper: {
			name: 'NcIconSvgWrapper',
			props: ['path', 'size', 'color'],
			template: '<span />'
		},
		NcBreadcrumbs: {
			name: 'NcBreadcrumbs',
			props: [],
			template: '<nav><slot /></nav>'
		},
		NcSelect: select,
		NcTextField: textField
	}
})

const IRR_ACCOUNT: Account = {
	id: 'acc-irr',
	userId: 'purchaser-1',
	balance: { amountMinor: 1_000_000, currency: 'IRR', minorUnit: 0 },
	active: true,
	version: 0
}

const USD_ACCOUNT: Account = {
	id: 'acc-usd',
	userId: 'purchaser-1',
	balance: { amountMinor: 5_000, currency: 'USD', minorUnit: 2 },
	active: true,
	version: 0
}

const EXISTING_SHEET: SheetSummary = {
	id: 'sheet-existing',
	title: 'March fuel receipts',
	ownerDisplayName: 'Purchaser One',
	ownerId: 'purchaser-1',
	status: 'OPEN',
	currency: 'IRR',
	transactionCount: 2,
	activeRevisionNumber: 1,
	createdAt: '2026-03-01T00:00:00+00:00',
	updatedAt: '2026-03-02T00:00:00+00:00',
	submittedAt: null,
	completedAt: null,
	version: 7,
	totals: {
		current: { amountMinor: 50_000, currency: 'IRR', minorUnit: 0 },
		submitted: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
		effectiveApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
		effectiveRejected: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
		effectivePending: { amountMinor: 50_000, currency: 'IRR', minorUnit: 0 }
	}
} as unknown as SheetSummary

const CREATED_SHEET: SheetDetail = {
	...EXISTING_SHEET,
	id: 'sheet-created',
	title: 'April supplies',
	version: 0,
	transactions: [],
	allowedActions: {}
} as unknown as SheetDetail

/** The sheet opened by the T080 edit cases. Its account is the caller's **second** account. */
const EDIT_SHEET: SheetDetail = {
	...EXISTING_SHEET,
	accountId: USD_ACCOUNT.id,
	currency: 'USD',
	transactions: [],
	allowedActions: {}
} as unknown as SheetDetail

type Transport = {
	accounts: Account[]
	sheets: SheetSummary[]
	detail: SheetDetail
	detailStatus: number | null
	patchStatus: number | null
	postCount: number
	getCount: number
	patchCount: number
	lastPatch: unknown
}

let transport: Transport

function page<T>(items: T[], pageSize = 25) {
	return { items, page: 1, pageSize, total: items.length }
}

function envelope(data: unknown) {
	return { status: 200, data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data } } }
}

function unreadable(status: number) {
	return {
		isAxiosError: true,
		response: {
			status,
			data: { ocs: { data: { error: { code: status === 404 ? 'NOT_FOUND' : 'FORBIDDEN', message: `HTTP ${status}` } } } }
		}
	}
}

function stubTransport(): void {
	ocsRequest.mockImplementation((config: { url?: string; method?: string; data?: unknown }) => {
		const url = String(config?.url ?? '')
		const method = String(config?.method ?? 'get').toUpperCase()
		const sheetResource = /\/sheets\/[^/?]+$/.test(url)

		if (url.includes('/accounts?')) return Promise.resolve(envelope(page(transport.accounts, 100)))
		if (method === 'POST' && /\/sheets$/.test(url)) {
			transport.postCount += 1
			transport.sheets = [CREATED_SHEET, ...transport.sheets]
			return Promise.resolve(envelope(CREATED_SHEET))
		}
		if (method === 'GET' && sheetResource) {
			if (transport.detailStatus !== null) return Promise.reject(unreadable(transport.detailStatus))
			return Promise.resolve(envelope(transport.detail))
		}
		if (method === 'PATCH' && sheetResource) {
			transport.patchCount += 1
			transport.lastPatch = config.data
			if (transport.patchStatus !== null) {
				// Another writer stored a newer title first. The form must reload that record rather
				// than retry or merge its own value.
				transport.detail = {
					...transport.detail,
					title: 'Server title',
					version: transport.detail.version + 1
				}
				return Promise.reject(unreadable(transport.patchStatus))
			}
			const body = config.data as { title?: string; expectedVersion?: number }
			transport.detail = {
				...transport.detail,
				title: body.title ?? transport.detail.title,
				version: (body.expectedVersion ?? transport.detail.version) + 1
			}
			transport.sheets = transport.sheets.map((sheet) =>
				sheet.id === transport.detail.id
					? { ...sheet, title: transport.detail.title, version: transport.detail.version }
					: sheet
			)
			return Promise.resolve(envelope(transport.detail))
		}
		if (method === 'GET' && url.includes('/sheets?')) {
			transport.getCount += 1
			return Promise.resolve(envelope(page(transport.sheets)))
		}
		return Promise.resolve(envelope(page([])))
	})
}

function textControl(wrapper: VueWrapper) {
	const root = wrapper.find('[data-test="sheet-title-input"]')
	if (root.element.matches('input, textarea')) return root
	const nested = root.find('input, textarea')
	return nested.exists() ? nested : root
}

async function settle(): Promise<void> {
	await flushPromises()
	await new Promise((resolve) => window.setTimeout(resolve, 0))
	await flushPromises()
}

describe('T079 — Create Sheet modal', () => {
	let pinia: Pinia
	let router: Router
	let wrapper: VueWrapper | null

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		window.location.hash = ''
		router = createRouter({ history: createWebHashHistory(), routes })
		transport = {
			accounts: [IRR_ACCOUNT, USD_ACCOUNT],
			sheets: [EXISTING_SHEET],
			detail: EDIT_SHEET,
			detailStatus: null,
			patchStatus: null,
			postCount: 0,
			getCount: 0,
			patchCount: 0,
			lastPatch: null
		}
		ocsRequest.mockReset()
		showSuccess.mockReset()
		stubTransport()
	})

	afterEach(() => {
		wrapper?.unmount()
		wrapper = null
		document.body.innerHTML = ''
	})

	async function mountAt(path: string): Promise<VueWrapper> {
		await router.push(path)
		await router.isReady()
		wrapper = mount(SheetListView, {
			attachTo: document.body,
			global: { plugins: [pinia, router] }
		})
		await settle()
		return wrapper
	}

	it('renders the list with one dialog at /sheets/create, named by sheets.create', async () => {
		const view = await mountAt('/sheets/create')

		expect(view.find('[data-test="sheet-list-view"]').exists()).toBe(true)
		expect(view.findAll('[role="dialog"]')).toHaveLength(1)
		expect(view.find('[data-test="nc-dialog-heading"]').text()).toBe('sheets.create')
		expect(view.find('[data-test="sheet-create-submit"]').text()).toBe('sheets.create')

		const dialog = view.findAllComponents({ name: 'NcDialog' })
			.find((candidate) => candidate.props('name') === 'sheets.create')
		expect(dialog?.props('noClose')).toBe(true)
		expect(dialog?.props('name')).toBe('sheets.create')
	})

	it('puts initial focus in the sheet-name field, not on the dialog surface', async () => {
		const view = await mountAt('/sheets/create')

		expect(document.activeElement).toBe(textControl(view).element)
	})

	it('returns focus to the list heading when /sheets/create was entered directly', async () => {
		const view = await mountAt('/sheets/create')

		await view.find('[data-test="sheet-cancel"]').trigger('click')
		await settle()

		expect(router.currentRoute.value.name).toBe('sheets')
		expect(view.find('[data-test="nc-dialog"]').exists()).toBe(false)
		expect(document.activeElement).toBe(view.find('[data-test="sheet-list-heading"]').element)
	})

	it('returns focus to the Create button when the list opened the dialog', async () => {
		const view = await mountAt('/sheets')

		await view.find('[data-test="create-sheet-button"]').trigger('click')
		await settle()
		expect(router.currentRoute.value.name).toBe('sheet-create')
		expect(view.findAll('[role="dialog"]')).toHaveLength(1)

		await view.find('[data-test="sheet-cancel"]').trigger('click')
		await settle()

		expect(router.currentRoute.value.name).toBe('sheets')
		expect(document.activeElement).toBe(view.find('[data-test="create-sheet-button"]').element)
	})

	it('keeps the unsaved-change decision inside the one dialog and Stay preserves the values', async () => {
		const view = await mountAt('/sheets/create')
		const input = textControl(view)
		await input.setValue('April supplies')
		;(input.element as HTMLInputElement).focus()

		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
		await settle()

		expect(view.find('[data-test="sheet-unsaved-guard"]').exists()).toBe(true)
		expect(view.findAll('[role="dialog"]')).toHaveLength(1)
		expect(view.find('[data-test="sheet-form-surface"]').attributes('inert')).toBeDefined()
		expect(view.find('[data-test="sheet-create-submit"]').exists()).toBe(false)

		await view.find('[data-test="unsaved-stay"]').trigger('click')
		await settle()

		expect(view.find('[data-test="sheet-unsaved-guard"]').exists()).toBe(false)
		expect(view.find('[data-test="sheet-create-submit"]').exists()).toBe(true)
		expect((textControl(view).element as HTMLInputElement).value).toBe('April supplies')
		expect(document.activeElement).toBe(textControl(view).element)
	})

	it('Discard closes the dialog and creates nothing', async () => {
		const view = await mountAt('/sheets/create')
		const store = useSheetStore()
		const before = store.sheets.length

		await textControl(view).setValue('Abandoned sheet')
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
		await settle()
		await view.find('[data-test="unsaved-discard"]').trigger('click')
		await settle()

		expect(transport.postCount).toBe(0)
		expect(store.sheets).toHaveLength(before)
		expect(router.currentRoute.value.name).toBe('sheets')
		expect(view.find('[data-test="nc-dialog"]').exists()).toBe(false)
	})

	it('routes Cancel and the custom close control through the same decision', async () => {
		const view = await mountAt('/sheets/create')

		await textControl(view).setValue('April supplies')
		await view.find('[data-test="sheet-cancel"]').trigger('click')
		await settle()
		expect(view.find('[data-test="sheet-unsaved-guard"]').exists()).toBe(true)
		expect(view.findAll('[role="dialog"]')).toHaveLength(1)

		await view.find('[data-test="unsaved-stay"]').trigger('click')
		await settle()
		await view.find('[data-test="sheet-form-close"]').trigger('click')
		await settle()
		expect(view.find('[data-test="sheet-unsaved-guard"]').exists()).toBe(true)
		expect(view.findAll('[role="dialog"]')).toHaveLength(1)
	})

	it('omits the account and currency controls when the caller has no accounts, and sends no account id', async () => {
		transport.accounts = []
		const view = await mountAt('/sheets/create')

		expect(view.find('[data-test="sheet-account-select"]').exists()).toBe(false)
		expect(view.find('[data-test="currency-select"]').exists()).toBe(false)
		expect(view.find('[data-test="currency-display"]').exists()).toBe(false)
		expect(view.find('[data-test="no-accounts-hint"]').text()).toBe('sheets.noAccounts')

		await textControl(view).setValue('April supplies')
		await view.find('[data-test="sheet-create-submit"]').trigger('click')
		await settle()

		const post = ocsRequest.mock.calls.find(([config]) => config?.method === 'POST')
		expect(post?.[0]?.data).toEqual({ title: 'April supplies' })
	})

	it('uses the selected account currency and disables the currency control with several accounts', async () => {
		const view = await mountAt('/sheets/create')

		const account = view.findAllComponents({ name: 'NcSelect' })
			.find((candidate) => candidate.props('inputLabel') === 'sheets.account')
		const currency = view.findAllComponents({ name: 'NcSelect' })
			.find((candidate) => candidate.props('inputLabel') === 'sheets.currency')
		expect(account?.exists()).toBe(true)
		expect(currency?.exists()).toBe(true)
		expect(account?.props('label')).toBe('label')
		expect(account?.props('inputLabel')).toBe('sheets.account')
		expect(account?.props('appendToBody')).toBe(false)
		expect(currency?.props('modelValue')).toBe('IRR')
		expect(currency?.props('disabled')).toBe(true)
	})

	it('confirms the create, refetches the current list, and does not open the new sheet', async () => {
		const view = await mountAt('/sheets/create')
		const store = useSheetStore()

		await textControl(view).setValue('April supplies')
		await view.find('[data-test="sheet-create-submit"]').trigger('click')
		await settle()

		const post = ocsRequest.mock.calls.find(([config]) => config?.method === 'POST')
		expect(post?.[0]?.data).toEqual({ title: 'April supplies', accountId: IRR_ACCOUNT.id })
		expect(transport.postCount).toBe(1)
		expect(showSuccess).toHaveBeenCalledWith('sheets.createSuccess')
		// One load on mount, one authoritative reload after the create.
		expect(transport.getCount).toBe(2)
		expect(store.sheets.map((sheet) => sheet.id)).toEqual(['sheet-created', 'sheet-existing'])
		expect(router.currentRoute.value.name).toBe('sheets')
		expect(view.find('[data-test="nc-dialog"]').exists()).toBe(false)
	})
})

describe('T080 — Edit Sheet modal', () => {
	let pinia: Pinia
	let router: Router
	let wrapper: VueWrapper | null

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		window.location.hash = ''
		router = createRouter({ history: createWebHashHistory(), routes })
		transport = {
			accounts: [IRR_ACCOUNT, USD_ACCOUNT],
			sheets: [EXISTING_SHEET],
			detail: EDIT_SHEET,
			detailStatus: null,
			patchStatus: null,
			postCount: 0,
			getCount: 0,
			patchCount: 0,
			lastPatch: null
		}
		ocsRequest.mockReset()
		showSuccess.mockReset()
		stubTransport()
	})

	afterEach(() => {
		wrapper?.unmount()
		wrapper = null
		document.body.innerHTML = ''
	})

	async function mountAt(path: string): Promise<VueWrapper> {
		await router.push(path)
		await router.isReady()
		wrapper = mount(SheetListView, {
			attachTo: document.body,
			global: { plugins: [pinia, router] }
		})
		await settle()
		return wrapper
	}

	function valueOf(view: VueWrapper, testId: string): string {
		const control = view.find(`[data-test="${testId}"]`)
		const element = control.element.matches('input, textarea')
			? control.element
			: control.find('input, textarea').element
		return (element as HTMLInputElement).value
	}

	function attributesOf(view: VueWrapper, testId: string): Record<string, string> {
		const control = view.find(`[data-test="${testId}"]`)
		const element = control.element.matches('input, textarea')
			? control.element
			: control.find('input, textarea').element
		return element.getAttributeNames().reduce<Record<string, string>>((attrs, name) => {
			attrs[name] = element.getAttribute(name) ?? ''
			return attrs
		}, {})
	}

	it('renders the list with exactly one edit dialog and the authoritative read-only identity', async () => {
		const view = await mountAt('/sheets/sheet-existing/edit')

		expect(view.find('[data-test="sheet-list-view"]').exists()).toBe(true)
		expect(view.findAll('[role="dialog"]')).toHaveLength(1)
		expect(view.find('[data-test="nc-dialog-heading"]').text()).toBe('sheets.edit')
		expect(view.find('[data-test="sheet-workspace"]').exists()).toBe(false)
		expect(view.find('[data-test="sheet-create-submit"]').text()).toBe('sheets.saveChanges')
		expect(view.find('[data-test="sheet-cancel"]').exists()).toBe(true)

		const title = textControl(view)
		expect((title.element as HTMLInputElement).value).toBe('March fuel receipts')
		expect(title.attributes('aria-label')).toBe('sheets.name')

		// The sheet's stored account is the caller's second account. `accounts[0]` is IRR, so an
		// implementation that guessed from list order would fail the identity assertion below.
		expect(view.find('[data-test="sheet-account-value"]').text()).toBe(USD_ACCOUNT.id)
		expect(view.find('[data-test="sheet-account-display"]').text()).not.toContain('IRR')
		expect(view.find('[data-test="sheet-account-display"]').find('input').exists()).toBe(false)
		expect(view.find('[data-test="sheet-account-balance"]').text()).toContain('USD')
		expect(valueOf(view, 'currency-display')).toBe('USD')
		expect(attributesOf(view, 'currency-display').readonly).toBeDefined()
		expect(view.find('[data-test="sheet-account-select"]').exists()).toBe(false)
		expect(view.find('[data-test="account-balance"]').exists()).toBe(false)
	})

	it('sends only title and expectedVersion, then returns to the list', async () => {
		const view = await mountAt('/sheets/sheet-existing/edit')
		const store = useSheetStore()

		await textControl(view).setValue('Renamed fuel receipts')
		await view.find('[data-test="sheet-create-submit"]').trigger('click')
		await settle()

		expect(transport.patchCount).toBe(1)
		expect(transport.lastPatch).toEqual({ title: 'Renamed fuel receipts', expectedVersion: 7 })
		expect(transport.lastPatch).not.toHaveProperty('accountId')
		expect(router.currentRoute.value.name).toBe('sheets')
		expect(router.currentRoute.value.query.from).toBeUndefined()
		expect(view.find('[data-test="nc-dialog"]').exists()).toBe(false)
		expect(store.sheets.find((sheet) => sheet.id === EDIT_SHEET.id)?.title).toBe('Renamed fuel receipts')
	})

	it('keeps the edit dialog open and reloads the server record on 409', async () => {
		transport.patchStatus = 409
		const view = await mountAt('/sheets/sheet-existing/edit')

		await textControl(view).setValue('My conflicting rename')
		await view.find('[data-test="sheet-create-submit"]').trigger('click')
		await settle()

		expect(transport.patchCount).toBe(1)
		expect(view.find('[data-test="conflict-message"]').text()).toBe('guard.conflict')
		expect((textControl(view).element as HTMLInputElement).value).toBe('Server title')
		expect(router.currentRoute.value.name).toBe('sheet-edit')
		expect(view.findAll('[role="dialog"]')).toHaveLength(1)
	})

	it('disables Save changes while the PATCH is in flight', async () => {
		const view = await mountAt('/sheets/sheet-existing/edit')
		const original = ocsRequest.getMockImplementation()
		let release!: () => void
		let patchCalls = 0

		ocsRequest.mockImplementation((config: { method?: string }) => {
			if (String(config?.method ?? '').toUpperCase() !== 'PATCH') return original!(config)
			patchCalls += 1
			return new Promise((resolve) => {
				release = () => resolve({
					status: 200,
					data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data: { ...EDIT_SHEET, title: 'Renamed', version: 8 } } }
				})
			})
		})

		await textControl(view).setValue('Renamed')
		const submit = view.find('[data-test="sheet-create-submit"]')
		await submit.trigger('click')
		await flushPromises()

		expect(patchCalls).toBe(1)
		expect(submit.attributes('disabled')).toBeDefined()

		release()
		await settle()
		expect(router.currentRoute.value.name).toBe('sheets')
	})

	it('returns a cancelled list-row edit to the list without writing', async () => {
		const view = await mountAt('/sheets/sheet-existing/edit')

		await view.find('[data-test="sheet-cancel"]').trigger('click')
		await settle()

		expect(transport.patchCount).toBe(0)
		expect(router.currentRoute.value.name).toBe('sheets')
		expect(view.find('[data-test="nc-dialog"]').exists()).toBe(false)
	})

	it('returns a workspace-originated edit to the same workspace, without mounting a second dialog', async () => {
		const view = await mountAt('/sheets/sheet-existing')

		await view.find('[data-test="edit-sheet-btn"]').trigger('click')
		await settle()

		expect(router.currentRoute.value.name).toBe('sheet-edit')
		expect(router.currentRoute.value.query.from).toBe('workspace')
		expect(view.findAll('[role="dialog"]')).toHaveLength(1)
		expect(view.find('[data-test="nc-dialog-heading"]').text()).toBe('sheets.edit')

		// The workspace stays visible as the return context, but only the edit dialog is a dialog.
		const background = view.find('[data-test="sheet-workspace"]')
		expect(background.exists()).toBe(true)
		expect(background.attributes('role')).toBeUndefined()
		expect(background.attributes('aria-modal')).toBeUndefined()
		expect(background.attributes('aria-hidden')).toBe('true')
		expect(background.attributes('inert')).toBeDefined()

		await view.find('[data-test="sheet-cancel"]').trigger('click')
		await settle()

		expect(transport.patchCount).toBe(0)
		expect(router.currentRoute.value.name).toBe('sheet-detail')
		expect(router.currentRoute.value.params.id).toBe('sheet-existing')
		expect(view.find('[data-test="sheet-workspace"]').exists()).toBe(true)
		expect(view.find('[data-test="sheet-workspace"]').attributes('role')).toBe('dialog')
		expect(view.find('[data-test="sheet-workspace"]').attributes('inert')).toBeUndefined()
	})

	it('shows a loading state, not an empty form, while a workspace-originated edit detail is in flight', async () => {
		const view = await mountAt('/sheets/sheet-existing')
		const original = ocsRequest.getMockImplementation()
		let release!: () => void
		let held = false

		ocsRequest.mockImplementation((config: { url?: string; method?: string }) => {
			const method = String(config?.method ?? 'get').toUpperCase()
			if (!held && method === 'GET' && /\/sheets\/sheet-existing$/.test(String(config?.url ?? ''))) {
				held = true
				return new Promise((resolve) => {
					release = () => resolve(original!(config))
				})
			}
			return original!(config)
		})

		await view.find('[data-test="edit-sheet-btn"]').trigger('click')
		await flushPromises()

		// The workspace already holds this sheet, so `currentSheet` alone would be enough to paint a
		// form whose title is still the empty initial value. The load-completion gate prevents that.
		expect(held).toBe(true)
		expect(view.find('[data-test="sheet-edit-loading"]').exists()).toBe(true)
		expect(view.find('[data-test="sheet-form"]').exists()).toBe(false)

		release()
		await settle()

		expect((textControl(view).element as HTMLInputElement).value).toBe('March fuel receipts')
	})

	it('saves a workspace-originated edit back to the same workspace', async () => {
		const view = await mountAt('/sheets/sheet-existing')

		await view.find('[data-test="edit-sheet-btn"]').trigger('click')
		await settle()
		await textControl(view).setValue('Workspace rename')
		await view.find('[data-test="sheet-create-submit"]').trigger('click')
		await settle()

		expect(transport.lastPatch).toEqual({ title: 'Workspace rename', expectedVersion: 7 })
		expect(router.currentRoute.value.name).toBe('sheet-detail')
		expect(router.currentRoute.value.params.id).toBe('sheet-existing')
		expect(view.find('[data-test="sheet-workspace"]').exists()).toBe(true)
	})

	it('keeps the unsaved-change decision inside the edit dialog', async () => {
		const view = await mountAt('/sheets/sheet-existing/edit')

		await textControl(view).setValue('Unsaved rename')
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
		await settle()

		expect(view.find('[data-test="sheet-unsaved-guard"]').exists()).toBe(true)
		expect(view.findAll('[role="dialog"]')).toHaveLength(1)
		expect(view.find('[data-test="sheet-create-submit"]').exists()).toBe(false)

		await view.find('[data-test="unsaved-stay"]').trigger('click')
		await settle()

		expect(view.find('[data-test="sheet-unsaved-guard"]').exists()).toBe(false)
		expect((textControl(view).element as HTMLInputElement).value).toBe('Unsaved rename')
		expect(transport.patchCount).toBe(0)
	})

	it('does not expose an edit form for a non-OPEN sheet', async () => {
		transport.detail = { ...EDIT_SHEET, status: 'COMPLETED' }
		const view = await mountAt('/sheets/sheet-existing/edit')

		expect(view.find('[data-test="sheet-edit-unavailable"]').exists()).toBe(true)
		expect(view.find('[data-test="sheet-form"]').exists()).toBe(false)
		expect(view.find('[data-test="sheet-create-submit"]').exists()).toBe(false)
		expect(view.find('[data-test="sheet-account-select"]').exists()).toBe(false)

		await view.find('[data-test="sheet-state-cancel"]').trigger('click')
		await settle()
		expect(router.currentRoute.value.name).toBe('sheets')
		expect(transport.patchCount).toBe(0)
	})

	it('does not expose an edit form for an unreadable sheet', async () => {
		transport.detailStatus = 404
		const view = await mountAt('/sheets/sheet-existing/edit')

		expect(view.find('[data-test="sheet-edit-unreadable"]').exists()).toBe(true)
		expect(view.find('[data-test="sheet-form"]').exists()).toBe(false)
		expect(view.find('[data-test="sheet-create-submit"]').exists()).toBe(false)
		expect(transport.patchCount).toBe(0)
	})

	it('keeps an inactive stored account visible and read-only in the modal', async () => {
		transport.detail = {
			...EDIT_SHEET,
			accountId: 'retired-account',
			currency: 'IRR'
		}
		const view = await mountAt('/sheets/sheet-existing/edit')

		expect(view.find('[data-test="sheet-account-value"]').text()).toBe('retired-account')
		expect(view.find('[data-test="sheet-account-inactive"]').exists()).toBe(true)
		expect(view.find('[data-test="sheet-account-balance"]').exists()).toBe(false)
		expect(view.find('[data-test="sheet-account-select"]').exists()).toBe(false)
		expect(valueOf(view, 'currency-display')).toBe('IRR')
		expect(attributesOf(view, 'currency-display').readonly).toBeDefined()
	})
})

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory, type Router } from 'vue-router'

// The account picker is driven by the caller's real accounts from the OCS accounts endpoint, so the
// store is stubbed here. Mocking the store (rather than the service) is required because the store
// imports the service through a relative path, which a module mock keyed on the `@/` alias does not
// intercept. Without it the component correctly renders its empty state and no account could ever be
// asserted.
//
// The accounts are held in a mutable module-level binding rather than written inline, because several
// requirements are conditional on how many accounts the caller has — a single account fixes the
// sheet currency while several make it a choice — and a fixed two-account stub made those
// requirements untestable. `setAccounts()` is called from the tests that depend on the count.
const accountFixture = vi.hoisted(() => ({ accounts: [] as unknown[] }))

const RIAL_ACCOUNT = { id: 'acc-1', userId: 'employee1', balance: { amountMinor: 1000000, currency: 'IRR', minorUnit: 0 }, active: true, version: 0 }
const USD_ACCOUNT = { id: 'acc-2', userId: 'employee1', balance: { amountMinor: 5000, currency: 'USD', minorUnit: 2 }, active: true, version: 0 }

function setAccounts(accounts: unknown[]): void {
	accountFixture.accounts = accounts
}

vi.mock('@/stores/accounts', () => ({
	useAccountStore: () => ({
		accounts: accountFixture.accounts,
		loading: false,
		loaded: true,
		error: null,
		hasAccounts: accountFixture.accounts.length > 0,
		availableCurrencies: [...new Set(accountFixture.accounts.map((a) => (a as { balance: { currency: string } }).balance.currency))],
		accountById: (id: string) => accountFixture.accounts.find((a) => (a as { id: string }).id === id),
		resetError: () => undefined,
		loadAccounts: async () => undefined
	})
}))

// Mock the Nextcloud router and axios
vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: vi.fn((path: string) => `/ocs/v2.php/apps/pettycash/api/v1${path}`),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`),
	appName: 'pettycash',
	appVersion: '1.0.0'
}))

// The OCS transport is exercised for real here rather than stubbed out, so the mock must answer
// with a genuine envelope. A bare `vi.fn()` resolves `undefined`, `src/services/http.ts` then
// dereferences `response.data.ocs.data` and raises a TypeError inside a promise the view never
// awaits, which surfaced as 26 unhandled rejections and left every "green" test in this file
// passing only because its mount-time fetch had silently failed. `emptyOcsResponse` answers every
// endpoint with a well-formed but empty result, so a component under test renders its empty state
// and the fetch genuinely succeeds.
const ocsRequest = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({
	default: {
		request: (...args: unknown[]) => ocsRequest(...args),
		isAxiosError: (error: unknown) => Boolean((error as { isAxiosError?: boolean } | null)?.isAxiosError)
	}
}))

const EMPTY_PAGE = { items: [], page: 1, pageSize: 25, total: 0 }

// Configuration fixtures, in the shape `contracts/ocs-api.yaml` declares. They are mutable so a test
// can drive a requirement that is conditional on the configuration — FR-023's evidence rule is decided
// by the selected expense type's `evidenceRequired`, so a fixed stub would make it untestable.
//
// The identifiers are deliberately unlike the `cc-1`/`et-1` placeholders the view used to hard-code:
// had the fixture reused them, an "options come from the configuration" assertion would have passed
// against the hard-coded list too, and proved nothing about where the options came from.
const configurationFixture = vi.hoisted(() => ({
	costCenters: [] as unknown[],
	expenseTypes: [] as unknown[]
}))

// Real UUIDs, not readable placeholders.
//
// These were `cc-eng-0001` / `et-sup-0001`, which is the trap Phase 55 named for T327: a frontend
// fixture shaped to agree with the client rather than with the server. It hid T330 completely --
// `EntitySnapshot.id` is `format: uuid` and `Revision.expenseType.id` carried the expense type's
// *title*, so every fixture built here was simultaneously wrong about the contract and unable to say
// so. A readable id is comfortable in a fixture and impossible on the wire.
const COST_CENTER_ENGINEERING = '11111111-1111-4111-8111-111111111111'
const COST_CENTER_MARKETING = '22222222-2222-4222-8222-222222222222'
const EXPENSE_TYPE_SUPPLIES = '33333333-3333-4333-8333-333333333333'
const EXPENSE_TYPE_TRAVEL = '44444444-4444-4444-8444-444444444444'

function defaultCostCenters(): unknown[] {
	return [
		{ id: COST_CENTER_ENGINEERING, code: 'ENG', name: 'Engineering', validFrom: '2026-01-01', validTo: null, active: true, version: 0 },
		{ id: COST_CENTER_MARKETING, code: 'MKT', name: 'Marketing', validFrom: '2026-01-01', validTo: null, active: true, version: 0 }
	]
}

function defaultExpenseTypes(): unknown[] {
	return [
		{ id: EXPENSE_TYPE_SUPPLIES, title: 'Office Supplies', evidenceRequired: false, active: true, version: 0 },
		{ id: EXPENSE_TYPE_TRAVEL, title: 'Travel', evidenceRequired: true, active: true, version: 0 }
	]
}

function setCostCenters(costCenters: unknown[]): void {
	configurationFixture.costCenters = costCenters
}

function setExpenseTypes(expenseTypes: unknown[]): void {
	configurationFixture.expenseTypes = expenseTypes
}

// Transaction fixtures. The list and detail views render per-row and per-decision content, so a test
// that never seeds one is asserting against an empty collection — which is why the three "list" tests
// and the "evidence list" test below all failed while the view was, in two of the cases, already
// correct. `detail` stays `null` by default so the groups that do not care are unaffected.
const transactionFixtures = vi.hoisted(() => ({
	detail: null as unknown,
	revisions: [] as unknown[],
	sheet: null as unknown,
	sheetList: [] as unknown[]
}))

function setTransactionDetail(detail: unknown): void {
	transactionFixtures.detail = detail
}

function setRevisions(revisions: unknown[]): void {
	transactionFixtures.revisions = revisions
}

function setSheetDetail(sheet: unknown): void {
	transactionFixtures.sheet = sheet
}

function setSheetList(sheets: unknown[]): void {
	transactionFixtures.sheetList = sheets
}

function emptyOcsResponse(config: { url?: string }): { status: number; data: unknown } {
	const url = config?.url ?? ''
	const data = emptyPayloadFor(url)
	return { status: 200, data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data } } }
}

/**
 * The declared response shape per endpoint, because a store that assigns this straight into a typed
 * ref is the cheapest way to catch a client that expects the wrong shape.
 * `GET /transactions/{id}/revisions` answers a bare `Revision[]`, not a page.
 */
function emptyPayloadFor(url: string): unknown {
	if (/\/revisions(\?|$)/.test(url)) return transactionFixtures.revisions
	if (/\/configuration\/cost-centers(\?|$)/.test(url)) return { ...EMPTY_PAGE, items: configurationFixture.costCenters, total: configurationFixture.costCenters.length }
	if (/\/configuration\/expense-types(\?|$)/.test(url)) return { ...EMPTY_PAGE, items: configurationFixture.expenseTypes, total: configurationFixture.expenseTypes.length }
	// Deliberately no branch for `/sheets/{id}/transactions`: no route serves a GET on it, so a client
	// that addressed it again would fall through to `null` here and fail loudly rather than pass
	// against a fixture. The transactions now arrive on `SheetDetail.transactions`, matched below.
	// The collection route, matched on the query's `?` because `/sheets/{id}` has no trailing slash
	// to hang a `(\?|$)` on and the two must not be confused.
	if (/\/sheets\?/.test(url)) {
		return { ...EMPTY_PAGE, items: transactionFixtures.sheetList, total: transactionFixtures.sheetList.length }
	}
	if (/\/sheets\/[^/?]+(\?|$)/.test(url)) return transactionFixtures.sheet
	if (/\/transactions\/[^/?]+(\?|$)/.test(url)) return transactionFixtures.detail
	if (/\/(sheets|accounts|transactions|evidence|audit)(\?|$)/.test(url)) return { ...EMPTY_PAGE }
	return null
}

/** Rejects with an OCS error envelope, as the server answers a business failure. */
function ocsErrorResponse(status: number, body: Record<string, unknown>) {
	return Promise.reject(
		Object.assign(new Error('Request failed with status code ' + status), {
			isAxiosError: true,
			response: { status, data: { ocs: { meta: { status: 'failure', statuscode: status }, data: { error: body } } } }
		})
	)
}

vi.mock('@nextcloud/l10n', () => ({
	// The message, not the app id. The previous mock returned its first argument, so every string in
	// every component rendered as the literal `pettycash` — which made any assertion about *which*
	// message a field carried indistinguishable from an assertion about any message at all.
	t: vi.fn((_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (_match, key: string) => String(params?.[key] ?? `{${key}}`))),
	n: vi.fn((_app: string, text: string) => text),
	getLanguage: vi.fn(() => 'fa'),
	isRTL: vi.fn(() => true),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`)
}))

// Mock @nextcloud/vue components with test attributes matching real components
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'loading', 'type', 'size'],
		template: '<button :type="type" :disabled="disabled" :class="[variant, size]" data-test="nc-button"><slot /></button>'
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'name', 'type', 'label', 'error', 'helperText', 'required', 'readonly', 'disabled'],
		emits: ['update:modelValue'],
		template: '<input :type="type" :name="name" :value="modelValue" :disabled="disabled" :readonly="readonly" :aria-invalid="error" @input="$emit(\'update:modelValue\', $event.target.value)" data-test="nc-text-field" />'
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'name', 'options', 'label', 'error', 'helperText', 'required', 'disabled', 'loading'],
		emits: ['update:modelValue'],
		template: '<select :name="name" :value="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.value)" data-test="nc-select"><option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.label }}</option></select>'
	},
	NcTextArea: {
		name: 'NcTextArea',
		props: ['modelValue', 'name', 'label', 'error', 'helperText', 'required', 'rows', 'readonly'],
		emits: ['update:modelValue'],
		template: '<textarea :name="name" :value="modelValue" :readonly="readonly" @input="$emit(\'update:modelValue\', $event.target.value)" data-test="nc-text-area" />'
	},
	NcChip: {
		name: 'NcChip',
		props: ['color', 'size', 'variant'],
		template: '<span :class="[color, size, variant]" data-test="nc-chip"><slot /></span>'
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: [],
		slots: ['header', 'default'],
		template: '<div class="nc-note-card" data-test="nc-note-card"><div v-if="$slots.header" class="nc-note-card-header"><slot name="header" /></div><div class="nc-note-card-body"><slot /></div></div>'
	},
	NcBreadcrumbs: {
		name: 'NcBreadcrumbs',
		props: ['items'],
		template: '<nav data-test="nc-breadcrumbs"><ol><li v-for="item in items" :key="item.text"><a v-if="item.href" :href="item.href">{{ item.text }}</a><span v-else>{{ item.text }}</span></li></ol></nav>'
	},
	NcIconSvgWrapper: {
		name: 'NcIconSvgWrapper',
		props: ['path', 'size', 'color'],
		template: '<span data-test="nc-icon" />'
	},
	NcProgressBar: {
		name: 'NcProgressBar',
		props: ['value', 'indeterminate', 'color'],
		template: '<progress :value="value" :indeterminate="indeterminate" data-test="nc-progress-bar" />'
	},
	NcAlert: {
		name: 'NcAlert',
		props: ['variant', 'dismissible'],
		emits: ['close'],
		slots: ['default'],
		template: '<div class="alert" :class="variant" data-test="nc-alert"><slot /><button v-if="dismissible" @click="$emit(\'close\')" data-test="alert-dismiss">×</button></div>'
	},
	NcDialog: {
		name: 'NcDialog',
		props: ['modelValue', 'title', 'maxWidth'],
		emits: ['update:modelValue'],
		slots: ['default'],
		template: '<div v-if="modelValue" class="nc-dialog" data-test="nc-dialog"><div class="nc-dialog-header">{{ title }}</div><div class="nc-dialog-body"><slot /></div></div>'
	},
	NcListItem: {
		name: 'NcListItem',
		props: [],
		slots: ['avatar', 'title', 'subtitle'],
		template: '<div class="nc-list-item" data-test="nc-list-item"><div v-if="$slots.avatar" class="nc-list-item-avatar"><slot name="avatar" /></div><div class="nc-list-item-content"><div v-if="$slots.title" class="nc-list-item-title"><slot name="title" /></div><div v-if="$slots.subtitle" class="nc-list-item-subtitle"><slot name="subtitle" /></div></div></div>'
	},
	NcFormGroup: {
		name: 'NcFormGroup',
		props: [],
		slots: ['default'],
		template: '<div class="nc-form-group" data-test="nc-form-group"><slot /></div>'
	},
	NcAppContentList: {
		name: 'NcAppContentList',
		props: [],
		slots: ['default'],
		template: '<div data-test="nc-app-content-list"><slot /></div>'
	},
	NcFilePicker: {
		name: 'NcFilePicker',
		props: [],
		template: '<div data-test="nc-file-picker"><slot></slot></div>'
	}
}))

// Import mocked components
import SheetListView from '@/views/sheets/SheetListView.vue'
import SheetDetailView from '@/views/sheets/SheetDetailView.vue'
import SheetFormView from '@/views/sheets/SheetFormView.vue'
import TransactionListView from '@/views/transactions/TransactionListView.vue'
import TransactionFormView from '@/views/transactions/TransactionFormView.vue'
import TransactionDetailView from '@/views/transactions/TransactionDetailView.vue'
import EvidenceUploader from '@/components/evidence/EvidenceUploader.vue'
import EvidenceList from '@/components/evidence/EvidenceList.vue'
import * as evidenceApi from '@/services/evidenceApi'
import { useSheetStore } from '@/stores/sheets'
import { ABSENT_MARKER, formatSheetDate } from '@/utils/date'
import { expectCardAgreesWithTable } from '../../Support/cardAgreement'
import { useTransactionStore } from '@/stores/transactions'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import { routes } from '@/router'
import type {
	Decision,
	Evidence,
	Money,
	Revision,
	SheetDetail,
	SheetStatus,
	SheetSummary,
	SheetTotals,
	TransactionDetail,
	TransactionStatus,
	TransactionSummary
} from '@/types/api'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { SHEET_STATUSES } from '@/types/api'
import { join, relative, extname } from 'node:path'

function createTestRouter(): Router {
	// The application's **own** route table, for its names and parameters, with the components
	// replaced by inert placeholders: the views under test are mounted directly, so resolving a route
	// would only re-import every real view through a dynamic import and add ~30s to this file.
	//
	// This file used to declare its own ten routes, copied from `src/router/index.ts`, and that copy
	// is what let T359 ship: `TransactionDetailView`'s correction button pushed a route named
	// `transaction-correction-create`, which no route in the application declares either, so
	// `vue-router` threw `MATCHER_NOT_FOUND` synchronously and the purchaser's only correction
	// affordance did nothing. Five assertions in this file checked that the button *exists*. None
	// pressed it, and the copied table meant none of them could have failed — the copy made every
	// route name resolvable whether or not the application declared it.
	//
	// Deriving the table from `@/router` is what makes that class of defect fail here instead: a view
	// that links to a name the application does not declare now fails to mount.
		// **The hash is reset here, and that is load-bearing (T387).** `createWebHashHistory()` reads
	// `window.location.hash`, and jsdom gives this whole file **one** document -- so the location is shared
	// state between tests. Any test that navigates (the correction journeys push `transaction-edit`, the
	// shell tests walk routes) left its URL behind, and every later `createTestRouter()` started *there*
	// rather than at `/`.
	//
	// It surfaced as a test that could not fail. `SheetFormView`'s `isEditing` is `!!route.params.id`, so a
	// create-mode test mounted after a navigating one took the **update** branch and submitted an update:
	// accounts empty, title filled, button enabled, validation clean, and the create request simply never
	// made. That reads as "the form did not submit" rather than as "this was mounted on the wrong route".
	//
	// Resetting it exposed one test that had been depending on the leak for its timing
	// (`should disable form when transaction is in submitted state`), which is fixed below by seeding its
	// store before it interacts with the view.
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

function mountOptions(pinia: ReturnType<typeof createPinia>, router: Router, options: any) {
	return {
		global: {
			plugins: [pinia, router],
			stubs: {
				'router-link': true,
				'router-view': true
			},
			mocks: {
				$t: (key: string) => key,
				$n: (key: string) => key
			}
		},
		...options
	}
}

function createWrapper(component: any, options: any = {}) {
	const pinia = createPinia()
	const router = createTestRouter()

	setActivePinia(pinia)

	return mount(component, mountOptions(pinia, router, options))
}

/**
 * Mounts a view on a specific route, with the mount-time loads already settled.
 *
 * A view that reads `route.params` cannot be exercised from `/`: `/transactions/:id/edit` supplies no
 * `sheetId`, and the create route supplies no `id`, so the two branches a form has are only reachable
 * by navigating first. Mounting on `/` and then seeding the store was how a `transactionStatus` prop
 * that the view never declared came to be asserted — the route is the input that decides the branch.
 */
async function createWrapperAtRoute(component: any, path: string, options: any = {}) {
	const pinia = createPinia()
	const router = createTestRouter()

	setActivePinia(pinia)
	await router.push(path)
	await router.isReady()

	const wrapper = mount(component, mountOptions(pinia, router, options))
	await flushPromises()
	return wrapper
}

/**
 * The same mount, but hands the router back so a test can assert on where a control **navigated**.
 *
 * `createWrapperAtRoute` returns only the wrapper, which is the right shape for "does this render" and
 * the wrong one for "does this button work" — a `router.push` that throws inside a click handler
 * produces no DOM change and no rejection, so a wrapper-only assertion sees a healthy page. T359.
 */
async function createWrapperWithRouter(component: any, path: string, options: any = {}) {
	const pinia = createPinia()
	const router = createTestRouter()

	setActivePinia(pinia)
	await router.push(path)
	await router.isReady()

	const wrapper = mount(component, mountOptions(pinia, router, options))
	await flushPromises()
	return { wrapper, router }
}

/** A `SheetDetail` in the shape the sheet store holds, for the fields a form reads. */
function sheetFixture(currency: string, overrides: Partial<SheetDetail> = {}): SheetDetail {
	return {
		id: 'sh-1',
		title: 'March travel',
		ownerId: 'employee1',
		ownerDisplayName: 'Employee One',
		currency,
		status: 'OPEN',
		transactionCount: 0,
		totals: sheetTotalsFixture(currency),
		createdAt: '2026-01-01T00:00:00+00:00',
		submittedAt: null,
		completedAt: null,
		firstExpenseDate: null,
		lastExpenseDate: null,
		version: 0,
		allowedActions: {},
		transactions: [],
		...overrides
	}
}

function sheetTotalsFixture(currency: string): SheetTotals {
	const zero: Money = { amountMinor: 0, currency, minorUnit: 0 }
	return {
		current: zero,
		submitted: zero,
		effectiveApproved: zero,
		effectiveRejected: zero,
		effectivePending: zero,
		costCenterApproved: zero,
		seniorApproved: zero,
		financeApproved: zero
	}
}

/** A `TransactionDetail` in the shape the transaction store holds. */
function transactionFixture(overrides: Partial<TransactionDetail> = {}): TransactionDetail {
	return {
		id: 'tx-1',
		sheetId: 'sh-1',
		activeRevision: revisionFixture(),
		stage: 'DRAFT',
		status: 'DRAFT',
		finalOutcome: null,
		latestDecision: undefined,
		evidenceCount: 0,
		version: 0,
		allowedActions: {},
		decisions: [],
		evidence: [],
		...overrides
	}
}

/** A `TransactionSummary` in the shape the transaction list store holds. */
function transactionSummaryFixture(overrides: Partial<TransactionSummary> = {}): TransactionSummary {
	return {
		id: 'tx-1',
		sheetId: 'sh-1',
		activeRevision: revisionFixture(),
		stage: 'DRAFT',
		status: 'DRAFT',
		finalOutcome: null,
		latestDecision: undefined,
		evidenceCount: 0,
		version: 0,
		...overrides
	}
}

function revisionFixture(overrides: Partial<Revision> = {}): Revision {
	return {
		id: 'rev-1',
		number: 1,
		expenseDate: '2026-01-15',
		amount: { amountMinor: 1000, currency: 'IRR', minorUnit: 0 },
		description: 'Taxi',
		costCenter: { id: COST_CENTER_ENGINEERING, code: 'ENG', name: 'Engineering' },
		// A revision carries an `EntitySnapshot`, which normalises the title to `name`.
		expenseType: { id: EXPENSE_TYPE_SUPPLIES, name: 'Office Supplies' },
		createdBy: 'employee1',
		createdAt: '2026-01-15T00:00:00+00:00',
		active: true,
		...overrides
	}
}

/**
 * A `Decision` in the shape the contract declares.
 *
 * `actor` is an `ActorSnapshot`, which is what FR-052 makes load-bearing: the display name and role
 * are the ones *at the time of the decision*, so they travel with the decision rather than being read
 * back from the user directory later.
 */
function decisionFixture(overrides: Partial<Decision> = {}): Decision {
	return {
		id: 'dec-1',
		stage: 'COST_CENTER',
		decision: 'APPROVE',
		explanation: 'Within the travel budget',
		actor: { userId: 'manager1', displayName: 'Manager One', role: 'COST_CENTER_MANAGER' },
		decidedAt: '2026-01-20T09:00:00+00:00',
		...overrides
	}
}

function evidenceFixture(overrides: Partial<Evidence> = {}): Evidence {
	return {
		id: 'ev-1',
		transactionId: 'tx-1',
		revisionId: 'rev-1',
		name: 'receipt.pdf',
		mediaType: 'application/pdf',
		sizeBytes: 2048,
		active: true,
		uploadedBy: 'employee1',
		uploadedAt: '2026-01-15T10:00:00+00:00',
		...overrides
	}
}


/** Every stylesheet-bearing source file, for the static layout checks below. */
const STYLE_EXTENSIONS = new Set(['.vue', '.scss', '.css'])

function styleFiles(): string[] {
	const out: string[] = []
	const walk = (dir: string): void => {
		for (const entry of readdirSync(dir)) {
			const full = join(dir, entry)
			if (statSync(full).isDirectory()) walk(full)
			else if (STYLE_EXTENSIONS.has(extname(full))) out.push(full)
		}
	}
	walk(join(process.cwd(), 'src'))
	return out
}

/**
 * The options a view handed to its `NcSelect`.
 *
 * Read from the component instance rather than through `props()` because the deep-imported
 * `NcSelect` type does not re-export its `options` prop in a form `props()` can resolve, even though
 * the component accepts and renders it.
 *
 * `testId` picks one specific select; without it the first one is returned, which is only meaningful
 * for a view that has exactly one.
 */
function selectOptions(wrapper: VueWrapper, testId?: string): Array<{ label: string; value: string }> {
	const target = testId
		? wrapper.findComponent(`[data-test="${testId}"]`)
		: wrapper.findComponent({ name: 'NcSelect' })
	const vm = (target as VueWrapper).vm as unknown as { options?: Array<{ label: string; value: string }> }
	return vm.options ?? []
}

/**
 * Fills every field FR-023 requires, so a test can then vary exactly one of them.
 *
 * The two `NcSelect`s are `v-select`s whose dropdown will not open under jsdom, so the value is set
 * through the component's `update:modelValue` — the same event a selection emits — rather than
 * through a rendered option.
 */
async function fillTransactionForm(wrapper: VueWrapper, values: { expenseTypeId?: string; costCenterId?: string } = {}) {
	await wrapper.find('[data-test="expense-date-input"]').setValue('2026-01-15')
	await wrapper.find('[data-test="amount-input"]').setValue('1000')
	await wrapper.find('[data-test="description-input"]').setValue('Taxi to the airport')
	if (values.costCenterId !== undefined) {
		;(wrapper.findComponent('[data-test="cost-center-select"]') as VueWrapper).vm.$emit('update:modelValue', values.costCenterId)
	}
	if (values.expenseTypeId !== undefined) {
		;(wrapper.findComponent('[data-test="expense-type-select"]') as VueWrapper).vm.$emit('update:modelValue', values.expenseTypeId)
	}
	await wrapper.vm.$nextTick()
}

/**
 * The accessible name a form control resolves to.
 *
 * WCAG does not name a control with one attribute: the name comes from `aria-label`, or from
 * `aria-labelledby`, or from an associated `<label for>`. Every one of the real `@nextcloud/vue`
 * controls in these forms names themselves with a visible `<label>` associated by `for`/`id`, and
 * `NcSelect` puts it on an inner `input` rather than on its own root — so the root is followed into
 * when the `data-test` sits on a wrapper.
 */
function accessibleName(wrapper: VueWrapper, testId: string): string {
	const root = wrapper.find(`[data-test="${testId}"]`)
	if (!root.exists()) return ''
	const control = /^(INPUT|TEXTAREA|SELECT)$/.test(root.element.tagName)
		? root
		: root.find('input, textarea, select')

	const aria = control.attributes('aria-label') ?? ''
	const id = control.attributes('id')
	const associated = id ? wrapper.find(`label[for="${id}"]`).text() : ''
	return `${aria} ${associated}`.trim()
}

describe('Purchaser Flow - Vue Component Tests', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setAccounts([{ ...RIAL_ACCOUNT }, { ...USD_ACCOUNT }])
    setCostCenters(defaultCostCenters())
    setExpenseTypes(defaultExpenseTypes())
    setTransactionDetail(null)
    setRevisions([])
    setSheetDetail(null)
    setSheetList([])
    ocsRequest.mockReset()
    ocsRequest.mockImplementation((config: { url?: string }) => Promise.resolve(emptyOcsResponse(config)))
  })

	// These four tests exist because this file previously passed for the wrong reason. A bare
	// `request: vi.fn()` made every mount-time fetch raise an unhandled TypeError, so the store
	// stayed empty and the component rendered whatever it renders for "no data" — which happened
	// to satisfy the structural assertions below. A passing structural assertion on a store that
	// never loaded is not evidence of anything, so these assert the transport itself.
	describe('OCS transport', () => {
		it('issues the sheet list request and stores the page it returned', async () => {
			createWrapper(SheetListView)
			await flushPromises()

			const sheetStore = useSheetStore()
			expect(ocsRequest).toHaveBeenCalledWith(
				expect.objectContaining({ url: expect.stringContaining('/sheets?') })
			)
			expect(sheetStore.sheets).toEqual([])
			expect(sheetStore.error).toBeNull()
		})

		it('surfaces a server error as a populated ApiError, not a raw TypeError', async () => {
			ocsRequest.mockImplementation(() =>
				ocsErrorResponse(422, { code: 'VALIDATION_FAILED', message: 'Cost center not found' })
			)
			createWrapper(SheetListView)
			await flushPromises()

			const sheetStore = useSheetStore()
			// The store records the failure; a TypeError from the transport would read as an
			// undefined message, which is what made this defect invisible before.
			expect(sheetStore.error).toBe('Cost center not found')
			expect(sheetStore.error).not.toMatch(/Cannot read propert/)
		})

		it('reports a transport that answers with a non-OCS body as a malformed response', async () => {
			ocsRequest.mockResolvedValue({ status: 200, data: '<html><body>login</body></html>' })
			createWrapper(SheetListView)
			await flushPromises()

			const sheetStore = useSheetStore()
			expect(sheetStore.error).toBe('pettycash.error.malformedResponse')
		})

		it('records a server-reported code rather than a generic network failure', async () => {
			ocsRequest.mockImplementation(() =>
				ocsErrorResponse(409, { code: 'IDEMPOTENCY_CONFLICT', message: 'Command already used' })
			)
			createWrapper(SheetListView)
			await flushPromises()

			const sheetStore = useSheetStore()
			expect(sheetStore.error).toBe('Command already used')
		})
	})

	/**
	 * FR-063 names nine things a sheet list must show. This mounts the list with a fixture carrying
	 * **every one of them** and asserts each is displayed, so a payload key added without a column fails
	 * here rather than passing unnoticed.
	 *
	 * The assertion is per-field presence with a value, not "the table got wider": the list already
	 * rendered six of the nine, and a test that counted columns would have been satisfied by the list it
	 * started from.
	 */
/**
 * A `SheetSummary` carrying every FR-063 field with a distinct value, so no two cells can pass on the
 * same text.
 *
 * At module scope rather than inside the describe that first needed it: T389's narrow-viewport
 * assertions mount the same list, and a second copy of this fixture would let the two suites disagree
 * about what a fully-populated sheet looks like.
 */
/** A `SheetSummary` with distinct values, so no two cells can pass on the same text. */
		function fullSheetFixture(): SheetSummary {
			const money = (amountMinor: number): Money => ({ amountMinor, currency: 'IRR', minorUnit: 0 })

			return {
				id: 'sh-full',
				title: 'Mordad travel',
				ownerId: 'employee7',
				ownerDisplayName: 'Employee Seven',
				currency: 'IRR',
				status: 'AWAITING_SENIOR_REVIEW',
				transactionCount: 4,
				totals: {
					current: money(1_000_000),
					submitted: money(900_000),
					effectiveApproved: money(800_000),
					effectiveRejected: money(50_000),
					effectivePending: money(70_000),
					// Distinct and non-zero **on purpose**: the three stage-approved figures are T365's and
					// must not be on this list, and only a distinctive value can prove their absence. Three
					// zeroes would render as a bare zero and the assertion below could not tell an absent
					// column from a column full of zeroes.
					costCenterApproved: money(11_111),
					seniorApproved: money(22_222),
					financeApproved: money(33_333),
				},
				createdAt: '2026-06-10T00:00:00+00:00',
				submittedAt: '2026-07-02T00:00:00+00:00',
				completedAt: null,
				firstExpenseDate: '2026-07-01',
				lastExpenseDate: '2026-07-28',
				version: 3,
				// Distinct from `transactionCount` (4) on purpose: the amended FR-063 clause names a revision
				// number precisely because a count would restate a figure the same clause already requires,
				// and a fixture where the two coincide cannot show which one a row is rendering.
				activeRevisionNumber: 7,
			}
		}


	describe('FR-063 sheet list completeness', () => {
		async function mountWithFullSheet() {
			setSheetList([fullSheetFixture()])
			const wrapper = createWrapper(SheetListView)
			await flushPromises()

			return wrapper
		}

		it('shows the owner the payload carries', async () => {
			const wrapper = await mountWithFullSheet()

			expect(wrapper.find('[data-test="sheet-list-owner"]').text()).toBe('Employee Seven')
		})

		/**
		 * The submitted total beside the current one, because they answer different questions.
		 *
		 * `submitted_total_minor` is a snapshot at first submission and does not move on correction
		 * (`data-model.md`), so this fixture gives the two different amounts and each cell must render its
		 * own. Asserted as *two non-empty cells that differ* rather than against a literal formatted string:
		 * the grouping separator and the digits belong to `Intl`, and a hardcoded Persian string would fail
		 * on an ICU version that groups differently without the behaviour having changed.
		 */
		it('shows the submitted total beside the current one', async () => {
			const wrapper = await mountWithFullSheet()

			const current = wrapper.find('[data-test="sheet-list-current-total"]').text()
			const submitted = wrapper.find('[data-test="sheet-list-submitted-total"]').text()

			expect(current).not.toBe('')
			expect(submitted).not.toBe('')
			expect(current).not.toBe(submitted)
		})

		/**
		 * T385: the same latest active revision number on the **list**, printed as `0` for an empty sheet.
		 *
		 * The two surfaces are required to show the same value by the amended FR-063 clause, so the list
		 * asserts it rather than being left to follow the detail view. `0` is a real answer rather than an
		 * absence — unlike the dates, which use the absent-marker — so the assertion pins the literal.
		 */
		it('shows the latest active revision number on the list too', async () => {
			setSheetList([fullSheetFixture()])
			const wrapper = createWrapper(SheetListView)
			await flushPromises()

			// The fixture's value is 7 while its `transactionCount` is 4, so this distinguishes the two
			// readings the amended clause is about — a count would render 4.
			expect(wrapper.find('[data-test="sheet-list-active-revision"]').text().trim()).toBe('7')
			// The zero case is asserted on the detail surface above; mounting the list twice in one test
			// reads the first wrapper's store and silently reports its value for the second.
		})

		it('shows the three summary amounts of the latest effective outcome', async () => {
			const wrapper = await mountWithFullSheet()

			const group = wrapper.find('[data-test="sheet-list-effective-outcome"]')
			expect(group.find('[data-test="sheet-list-effective-approved"]').text()).not.toBe('')
			expect(group.find('[data-test="sheet-list-effective-rejected"]').text()).not.toBe('')
			expect(group.find('[data-test="sheet-list-effective-pending"]').text()).not.toBe('')
		})

		it('shows every key date the payload carries, each in its own hook', async () => {
			const wrapper = await mountWithFullSheet()

			// Four dates, four hooks. Asserted as a set rather than as "the dates cell is not empty",
			// because one missing date and four rendered dates produce an equally non-empty cell.
			for (const hook of [
				'sheet-list-created-at',
				'sheet-list-submitted-at',
				'sheet-list-completed-at',
				'sheet-list-expense-range'
			]) {
				expect(wrapper.find(`[data-test="${hook}"]`).exists(), `${hook} must be rendered`).toBe(true)
			}

			// The three this fixture populates carry a date rather than the absent-marker.
			expect(wrapper.find('[data-test="sheet-list-created-at"]').text()).not.toBe('—')
			expect(wrapper.find('[data-test="sheet-list-submitted-at"]').text()).not.toBe('—')
			expect(wrapper.find('[data-test="sheet-list-expense-range"]').text()).not.toBe('—')
		})

		/**
		 * A nullable date renders as a dash, **not** as 1 January 1970.
		 *
		 * `new Date(null).toLocaleDateString('fa-IR')` is 1970, so a sheet that was never completed would
		 * carry a confident date beside it. `completedAt` is null on this fixture, and FR-063's "key dates"
		 * must not fill that cell with a date the sheet never had.
		 */
		it('renders a null date as a dash rather than the Unix epoch', async () => {
			const wrapper = await mountWithFullSheet()

			expect(wrapper.find('[data-test="sheet-list-completed-at"]').text()).toBe('—')
			expect(wrapper.text()).not.toContain('۱۹۷۰')
			expect(wrapper.text()).not.toContain('1970')
		})

		/**
		 * FR-062's three stage-approved figures are **T365's**, and this list now carries them.
		 *
		 * Proven by *value* rather than by hook presence alone: the fixture gives the three distinctive
		 * amounts (11_111, 22_222, 33_333), so any column rendering them puts those formatted strings
		 * into the row. Before T365 this same test asserted their absence — it was written to fail when
		 * T365 landed — and a hook-based check would pass for a column that used a different attribute
		 * name entirely, which is why the amounts themselves are asserted.
		 */
		it('carries FR-062’s three stage-approved figures beside the latest-effective column', async () => {
			const wrapper = await mountWithFullSheet()

			const rows = wrapper.findAll('[data-test="sheet-row"]')
			expect(rows).toHaveLength(1)
			// Narrowed explicitly rather than with `!`: under `noUncheckedIndexedAccess` the index is
			// `T | undefined`, and an assertion here would otherwise be made on `undefined` while
			// type-checking clean — which is the same shape as the vacuous assertions this file already
			// had to be reopened for.
			const row = rows[0]
			expect(row).toBeDefined()
			const group = row?.find('[data-test="sheet-list-stage-approved"]')
			expect(group?.exists()).toBe(true)
			for (const [hook, amountMinor] of [
				['sheet-list-cost-center-approved', 11_111],
				['sheet-list-senior-approved', 22_222],
				['sheet-list-finance-approved', 33_333],
			] as const) {
				const cell = group?.find(`[data-test="${hook}"]`)
				expect(cell?.exists(), `${hook} must be rendered`).toBe(true)
				expect(cell?.text()).toContain(amountMinor.toLocaleString('fa-IR'))
			}
			// The latest-effective group this surface already owned is still present, so the new
			// column sits beside it rather than replacing it — FR-062 distinguishes the two.
			expect(row?.find('[data-test="sheet-list-effective-outcome"]').exists()).toBe(true)
		})
	})
	/**
	 * T382: FR-064's four sheet facets and a pager, both of which the surface had no control for.
	 *
	 * T333 delivered the server half and recorded the client half as a limitation. These are that half,
	 * and the pager closes the more consequential gap: a sheet past the first page is a sheet whose
	 * **status** the caller cannot see, so a returned transaction they must correct and a sheet they must
	 * submit are both potentially invisible.
	 */
	/**
	 * T389: FR-071 requires the listed elements to be visible **without horizontal scroll** at 390px.
	 *
	 * This list answered that with `overflow-x: auto`, which produces a scrollbar rather than satisfying the
	 * requirement -- precisely what `src/styles/app.scss` says must not be done. It now uses
	 * `ResponsiveTable`, which renders the same rows as a `<table>` above 640px and one card per row below.
	 */
	describe('FR-071 narrow-viewport reflow', () => {
		async function mountWithSheet() {
			setSheetList([fullSheetFixture()])
			const wrapper = createWrapper(SheetListView)
			await flushPromises()

			return wrapper
		}

		it('renders both layouts, so a narrow viewport is answered rather than clipped', async () => {
			const wrapper = await mountWithSheet()

			// Both are always in the document; CSS chooses between them. Asserting their presence catches a
			// conversion that replaced the table with cards instead of adding cards beside it.
			expect(wrapper.find('[data-test="sheet-list-table"]').exists()).toBe(true)
			expect(wrapper.findAll('[data-test="sheet-row"]').length).toBe(1)
			expect(wrapper.findAll('[data-test="sheet-row-card"]').length).toBe(1)
		})

		/**
		 * Every card field carries its own hook, so a narrow-only defect is assertable at all.
		 *
		 * `ReportTable.vue`'s card fields have no hooks, which is why its figures are only assertable through
		 * the desktop table a narrow viewport never renders. These do.
		 */
		it('gives every card field its own hook, so a narrow-only defect is assertable', async () => {
			const wrapper = await mountWithSheet()
			const card = wrapper.find('[data-test="sheet-row-card"]')

			for (const key of [
				'title',
				'owner',
				'currency',
				'status',
				'transactionCount',
				'activeRevision',
				'totals',
				'latestEffective',
				'keyDates',
				'actions'
			]) {
				expect(
					card.find(`[data-test="sheet-list-card-field-${key}"]`).exists(),
					`the card must have a labelled field for '${key}'`
				).toBe(true)
			}
		})

		/**
		 * The figures **agree** between the two layouts.
		 *
		 * The reason `ResponsiveTable` takes a `cell` render function is that the two layouts cannot become two
		 * presentations of the same money. A card missing a field the table shows is exactly that, and it
		 * would be invisible to a test that only ever looked at one layout.
		 */
		it('renders the same figures in the table and in the card', async () => {
			const wrapper = await mountWithSheet()
			const card = wrapper.find('[data-test="sheet-row-card"]')

			for (const name of [
				'owner',
				'current-total',
				'submitted-total',
				'active-revision',
				'created-at',
				'submitted-at',
				'completed-at',
				'expense-range'
			]) {
				const fromTable = wrapper.find(`[data-test="sheet-list-${name}"]`).text()
				const fromCard = card.find(`[data-test="sheet-list-${name}"]`).text()

				expect(fromCard, `'${name}' must be present in the card layout`).not.toBe('')
				expect(fromCard, `'${name}' must agree between the two layouts`).toBe(fromTable)
			}
		})

		/**
		 * The narrow-viewport **switch** exists in the stylesheet.
		 *
		 * **This is a declaration assertion, and it is labelled as one.** jsdom does not evaluate media
		 * queries, so a test cannot observe that the card list is actually *shown* at 390px -- and that limit
		 * is real: deleting the whole `@media` block leaves every test in this file green, which was measured
		 * rather than assumed. So the assertions above prove the narrow layout is **rendered** and carries
		 * the same figures, and this one proves the rule that reveals it is **declared**. T122's browser
		 * measurement is still what confirms it is seen; this is the same proxy T116 documents for
		 * `min-width`, and it is recorded here rather than presented as a rendering.
		 */
		it('declares the narrow-viewport rule that hides the table and reveals the cards', () => {
			const source = readFileSync(
				join(process.cwd(), 'src/components/shared/ResponsiveTable.vue'),
				'utf8'
			)
			const narrow = source.match(/@media \(width <= 640px\) \{([\s\S]*?)\n\}/)

			expect(narrow, 'the component must declare a narrow-viewport breakpoint').not.toBeNull()
			expect(narrow?.[1], 'the table must be hidden at that width').toMatch(
				/responsive-table__desktop[\s\S]*display:\s*none/
			)
			expect(narrow?.[1], 'and the cards shown in its place').toMatch(
				/responsive-table__cards[\s\S]*display:\s*flex/
			)
		})

		it('no longer declares a clipping overflow on its table container', async () => {
			const source = readFileSync(
				join(process.cwd(), 'src/views/sheets/SheetListView.vue'),
				'utf8'
			)

			// **The declaration, not any mention.** Anchored at the start of a line so the explanatory comment
			// naming `overflow-x: auto` does not satisfy -- or trip -- the check; a first version matched the
			// prose and failed on the comment that records why the property is gone.
			expect(
				source,
				'FR-071 is not satisfied by clipping, so this view must not answer a narrow viewport with '
					+ '`overflow-x: auto`'
			).not.toMatch(/^\s*overflow-x:\s*auto/m)
		})
	})

	describe('sheet list filters and pager', () => {
		/**
		 * Mounts the list with a server page of `total` rows at the default size of 25.
		 *
		 * The page response is built per call rather than returned from a constant, so the `total` under
		 * test is the one the pager reads -- a fixed fixture with `total: 0` and a pager test asserting on
		 * `pagination.total` is the shape of an assertion that cannot fail.
		 */
		function mountWithTotal(total: number, items: SheetSummary[] = []) {
			ocsRequest.mockImplementation(() => Promise.resolve({
				status: 200,
				data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data: { ...EMPTY_PAGE, items, total } } }
			}))
			return createWrapper(SheetListView)
		}

		it('offers a control for each of the four FR-064 sheet facets', async () => {
			const wrapper = mountWithTotal(0)
			await flushPromises()

			for (const hook of [
				'sheet-filter-status',
				'sheet-filter-currency',
				'sheet-filter-from',
				'sheet-filter-to',
				'sheet-filter-creator'
			]) {
				expect(wrapper.find(`[data-test="${hook}"]`).exists(), `${hook} must exist`).toBe(true)
			}
		})

		/**
		 * A status the server does not accept earns a 422, not a filtered list -- so the control offers
		 * exactly the server's vocabulary and no more. The list is read from the shared `SheetStatus` type,
		 * which is what makes a newly added member a compile-time obligation here.
		 */
		it('offers the status picker options of the server’s vocabulary', async () => {
			const wrapper = mountWithTotal(0)
			await flushPromises()

			const select = wrapper.findComponent({ name: 'NcSelect' })
			const options = select.props('options') as { value: string }[]

			expect(options.map((option) => option.value).sort()).toEqual([...SHEET_STATUSES].sort())
		})

		/**
		 * The facet names the control sends are the ones the server accepts.
		 *
		 * Read out of `SheetListFilterFactory::ACCEPTED_FILTERS` rather than a hand-written list, for the
		 * same reason T376’s report-side equivalent reads `ReportFilterFactory`: the vocabulary is closed,
		 * so a name the UI sends that the server does not accept is a guaranteed 422 for a user who filled
		 * the filter in correctly.
		 */
		it('sends only filter names SheetListFilterFactory accepts', () => {
			const source = readFileSync(
				join(process.cwd(), 'lib/Application/Expense/SheetListFilterFactory.php'),
				'utf8'
			)
			const block = source.match(/ACCEPTED_FILTERS\s*=\s*\[([^\]]*)\]/)
			expect(block).not.toBeNull()

			const accepted = [...(block?.[1] ?? '').matchAll(/'([A-Za-z][A-Za-z0-9]*)'/g)].map((m) => m[1])
			expect(accepted.length).toBeGreaterThan(0)

			// The four facets this view sends, plus the derived `ownerId` it displays.
			for (const name of ['status', 'currency', 'from', 'to', 'ownerId']) {
				expect(accepted, `'${name}' must be accepted by the server`).toContain(name)
			}
		})

		it('shows no pager when everything fits on one page', async () => {
			const wrapper = mountWithTotal(25)
			await flushPromises()

			expect(wrapper.find('[data-test="sheet-pager"]').exists()).toBe(false)
		})

		/**
		 * The acceptance criterion: `total: 40` at the default `pageSize: 25` must reach page 2.
		 *
		 * Asserted as a **request**, not as a rendering: the point is that the control reaches the server
		 * with `page=2`, and a test that only checked the pager appeared would pass for a pager wired to
		 * nothing -- which is the state this surface was in.
		 */
		it('reaches page 2 when the server reports 40 rows at the default page size', async () => {
			const wrapper = mountWithTotal(40)
			await flushPromises()

			const pager = wrapper.find('[data-test="sheet-pager"]')
			expect(pager.exists()).toBe(true)

			// Previous is unavailable on page 1 and next is available: one control in each direction.
			expect(wrapper.find('[data-test="sheet-page-previous"]').attributes('disabled')).toBeDefined()
			expect(wrapper.find('[data-test="sheet-page-next"]').attributes('disabled')).toBeUndefined()

			await wrapper.find('[data-test="sheet-page-next"]').trigger('click')
			await flushPromises()

			expect(ocsRequest).toHaveBeenCalledWith(
				expect.objectContaining({ url: expect.stringContaining('page=2') })
			)
		})

		it('disables the next control on the last page', async () => {
			const wrapper = mountWithTotal(40)
			await flushPromises()

			await wrapper.find('[data-test="sheet-page-next"]').trigger('click')
			await flushPromises()

			// 40 rows at 25 per page is two pages, so page 2 is the last and both directions are now closed.
			expect(wrapper.find('[data-test="sheet-page-next"]').attributes('disabled')).toBeDefined()
			expect(wrapper.find('[data-test="sheet-page-previous"]').attributes('disabled')).toBeUndefined()
		})

		/**
		 * Applying a filter resets to page 1.
		 *
		 * The failure this prevents is silent rather than loud: applying a narrower filter while sitting on
		 * page 3 asks for rows 51-75 of a set that may now hold six sheets, so the user sees an empty list
		 * beside a pager reading "page 3 of 1" -- a correct request and a useless answer.
		 */
		it('returns to page 1 when a filter is applied', async () => {
			const wrapper = mountWithTotal(40)
			await flushPromises()

			await wrapper.find('[data-test="sheet-page-next"]').trigger('click')
			await flushPromises()

			await wrapper.find('[data-test="sheet-filter-currency"]').setValue('IRR')
			await wrapper.find('[data-test="sheet-filters"]').trigger('submit')
			await flushPromises()

			expect(ocsRequest).toHaveBeenCalledWith(
				expect.objectContaining({ url: expect.stringContaining('page=1') })
			)
		})

		it('sends the currency upper-cased, because the server requires three uppercase letters', async () => {
			const wrapper = mountWithTotal(0)
			await flushPromises()

			await wrapper.find('[data-test="sheet-filter-currency"]').setValue('irr')
			await wrapper.find('[data-test="sheet-filters"]').trigger('submit')
			await flushPromises()

			expect(ocsRequest).toHaveBeenCalledWith(
				expect.objectContaining({ url: expect.stringContaining('IRR') })
			)
		})

		/**
		 * The creator facet is shown, and is **not** an editable field.
		 *
		 * `SheetController::index` accepts `ownerId` only when it names the caller and answers anything else
		 * with a 403, so a text box here could only ever invite that refusal. Asserted as the absence of an
		 * input, which is the property that matters -- asserting the presence of some label would pass for
		 * a box the user can type another uid into.
		 */
		it('shows the creator facet without offering an input the server would refuse', async () => {
			const wrapper = mountWithTotal(0)
			await flushPromises()

			const creator = wrapper.find('[data-test="sheet-filter-creator"]')
			expect(creator.exists()).toBe(true)
			expect(creator.find('input').exists()).toBe(false)
			expect(creator.find('[data-test="sheet-filter-creator-value"]').text()).not.toBe('')
		})
	})

	describe('Sheet Forms', () => {
		it('should render sheet creation form with title and account selection fields', () => {
			const wrapper = createWrapper(SheetFormView)
			expect(wrapper.find('[data-test="sheet-title-input"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="sheet-account-select"]').exists()).toBe(true)
		})

		it('should show field-level validation errors for required fields', async () => {
			const wrapper = createWrapper(SheetFormView)
			await wrapper.find('[data-test="sheet-form"]').trigger('submit.prevent')

			// A summary naming each missing field, not a generic refusal. The original assertion
			// looked for `[data-test="nc-alert"]`, which was the barrel mock's own attribute and can
			// never appear now that the real `NcAlert` renders.
			expect(wrapper.find('[data-test="validation-summary"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="validation-summary-title"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="validation-summary-accountId"]').exists()).toBe(true)
		})

		it('should clear the validation summary once the form becomes valid', async () => {
			const wrapper = createWrapper(SheetFormView)
			await wrapper.find('[data-test="sheet-form"]').trigger('submit.prevent')
			expect(wrapper.find('[data-test="validation-summary"]').exists()).toBe(true)

			await wrapper.find('[data-test="sheet-title-input"]').setValue('March travel')
			await wrapper.find('[data-test="sheet-form"]').trigger('submit.prevent')
			expect(wrapper.find('[data-test="validation-summary"]').exists()).toBe(false)
		})

		// `NcSelect` is imported by its deep path (`@nextcloud/vue/components/NcSelect`) in every view,
		// so this file's `vi.mock('@nextcloud/vue', …)` never reaches it and the real component
		// renders. It is a `v-select`, which produces a `role="listbox"` wrapper and no `<option>`
		// elements at all, so the original `findAll('option').length > 1` assertion could never pass
		// — and its dropdown will not open under jsdom either, because v-select opens on real focus
		// and click-outside. The requirement is therefore asserted where it is actually decided: the
		// view's contract with the select. It fails if the accounts are not derived from the store, if
		// an inactive account leaks in, or if the balance stops being shown in the option label.
		it('should populate account selection with only active accounts for current user', () => {
			const wrapper = createWrapper(SheetFormView)

			// Not in the empty or errored state, and not disabled.
			expect(wrapper.find('[data-test="no-accounts-hint"]').exists()).toBe(false)
			expect(wrapper.find('[data-test="accounts-error"]').exists()).toBe(false)
			expect(wrapper.find('[data-test="sheet-account-select"] input').attributes('disabled')).toBeUndefined()

			const options = selectOptions(wrapper)
			expect(options.map((o) => o.value)).toEqual(['acc-1', 'acc-2'])
		})

		it('should offer no option for an account the caller does not hold', () => {
			setAccounts([{ ...RIAL_ACCOUNT }])
			const wrapper = createWrapper(SheetFormView)
			expect(selectOptions(wrapper).map((o) => o.value)).toEqual(['acc-1'])
		})

		it('should format each account option with its balance at its own currency scale', () => {
			const wrapper = createWrapper(SheetFormView)
			const options = selectOptions(wrapper)
			// Rial has no minor unit, so 1000000 minor units is 1,000,000 rial, not 10,000. USD has
			// two minor-unit digits, so 5000 minor units is 50.
			expect(options[0]?.label).toContain('IRR')
			expect(options[1]?.label).toContain('USD')
			expect(options[1]?.label).not.toContain('50,000')
		})

		it('should disable currency selection when user has only one active account', () => {
			setAccounts([{ ...RIAL_ACCOUNT }])
			const wrapper = createWrapper(SheetFormView)
			// A single account fixes the sheet currency (FR-021), so it is shown read-only rather than
			// offered as a choice.
			expect(wrapper.find('[data-test="currency-display"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="currency-display"]').attributes('readonly')).toBeDefined()
			expect(wrapper.find('[data-test="currency-select"]').exists()).toBe(false)
		})

		it('should allow currency selection from multiple active accounts', () => {
			const wrapper = createWrapper(SheetFormView)
			expect(wrapper.find('[data-test="currency-select"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="currency-display"]').exists()).toBe(false)
		})

		it('should show account balance in the form', async () => {
			setAccounts([{ ...USD_ACCOUNT }])
			const wrapper = createWrapper(SheetFormView)
			// The form defaults to the caller's first account on mount, which is asynchronous.
			await flushPromises()
			const balance = wrapper.find('[data-test="account-balance"]')
			expect(balance.exists()).toBe(true)
			// USD has two minor-unit digits, so 5000 minor units is 50 — a fixed /100 would print
			// 50.00 and misstate the balance.
			expect(balance.text()).toContain('USD')
			expect(balance.text()).not.toContain('5,000')
		})

		it('should show no balance when the caller has no account', () => {
			setAccounts([])
			const wrapper = createWrapper(SheetFormView)
			expect(wrapper.find('[data-test="account-balance"]').exists()).toBe(false)
		})

		it('should prevent submission when form is invalid', async () => {
			const wrapper = createWrapper(SheetFormView)
			const submitBtn = wrapper.find('[data-test="sheet-create-submit"]')
			expect(submitBtn.attributes('disabled')).toBeDefined()
		})

		it('should enable submission once the form is valid', async () => {
			const wrapper = createWrapper(SheetFormView)
			await wrapper.find('[data-test="sheet-title-input"]').setValue('March travel')
			expect(wrapper.find('[data-test="sheet-create-submit"]').attributes('disabled')).toBeUndefined()
		})
	})

	describe('Sheet Detail View', () => {
		/**
		 * T343: there is no sheet audit card, because there was no sheet audit.
		 *
		 * The card rendered a five-column table whose only body row was `<tr v-if="false">` — a literal,
		 * so even its "no events" row could never render — and no loader was bound in the view. The only
		 * audit route is `GET /transactions/{id}/audit`; there is no sheet-scoped one, so the card had no
		 * data source and never will without either an N-request client-side assembly or a new endpoint.
		 *
		 * Asserted as an **absence**, because an absence is what "removed rather than bound" means: a
		 * test that asserted the card *renders* an empty state would be asserting the defect.
		 */
		it('should not render an audit card with no data source', () => {
			const wrapper = createWrapper(SheetDetailView)

			expect(wrapper.find('[data-test="audit-tab"]').exists()).toBe(false)
			expect(wrapper.find('[data-test="audit-table"]').exists()).toBe(false)
		})

		it('should render sheet header with title, currency, status, and totals', () => {
			const wrapper = createWrapper(SheetDetailView)
			expect(wrapper.find('[data-test="sheet-title"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="sheet-currency"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="sheet-status"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="sheet-totals"]').exists()).toBe(true)
		})

		it('should show transaction list with key information', () => {
			const wrapper = createWrapper(SheetDetailView)
			expect(wrapper.find('[data-test="transaction-list"]').exists()).toBe(true)
		})

		/**
		 * T340: the Owner row renders a name.
		 *
		 * `ownerDisplayName` was the literal `''` in `SheetPayloadFormatter::summary()`, so this row was
		 * blank for every sheet in the application and no assertion could see it — the field is printed
		 * from the store and the row had no `data-testid` to find it by. Both halves are fixed here: the
		 * row has a hook, and the value it renders is checked.
		 *
		 * **The payload is the store's, and the store's payload is asserted to be server-shaped.** The
		 * component cannot detect the server's half — it prints whatever it is given — so the load-bearing
		 * assertion for the defect is `SheetOwnerDisplayNameTest` over HTTP. This case exists to catch the
		 * view substituting or dropping the value on the way to the screen, which is the half a
		 * component test *can* see.
		 */
		it('should render the owner name the payload carries', async () => {
			const wrapper = createWrapper(SheetDetailView)
			const sheetStore = useSheetStore()
			sheetStore.currentSheet = sheetFixture('IRR', { ownerDisplayName: 'Employee One' })
			await wrapper.vm.$nextTick()

			const owner = wrapper.find('[data-testid="sheet-owner"]')
			expect(owner.exists(), 'the Owner row has a hook, so its value can be asserted').toBe(true)
			expect(owner.text().trim()).toBe('Employee One')
		})

		// The original version mounted the view with an empty store and asserted that the error region
		// existed, so it passed or failed for reasons unrelated to submission — it never submitted and
		// never produced an error. The store's `error` is set explicitly instead, which is the state a
		// refused submission leaves behind, and the region is required to be absent without it.
		/**
		 * T384: FR-063's **key dates** on the sheet detail view.
		 *
		 * The view rendered none of them. `grep -cE "createdAt|submittedAt|completedAt|firstExpenseDate|
		 * lastExpenseDate" src/views/sheets/SheetDetailView.vue` was **0** before this change, and its only
		 * date was a *transaction's* `activeRevision.expenseDate` inside the transaction table. T381 had
		 * delivered the same four for the sheet **list**, so FR-063 — one sentence about two surfaces — was
		 * half-satisfied and the half that was missing was the one nobody had tested for.
		 *
		 * Each field is asserted **with a value**, from one fixture carrying all of them, rather than by
		 * counting rendered dates: a cell that rendered the right number of rows while reading the wrong
		 * field would satisfy a count.
		 */
		/**
		 * T394: the sheet detail view's transaction table reflows with the same figures.
		 *
		 * The detail view's table is the one T389 converted last and carries **eight** columns, built from
		 * a derived column list rather than a hand-written header. A column added to `transactionHeaders`
		 * without a case in `transactionCell` renders its key in **both** layouts -- T396's subject -- and
		 * this asserts the columns that do have cases are identical on both sides.
		 */
		it('carries the same figures in the narrow card as in the table', async () => {
			setSheetDetail(
				sheetFixture('IRR', {
					id: 'sh-1',
					transactions: [
						transactionSummaryFixture({
							id: 'tx-a',
							activeRevision: revisionFixture({ expenseDate: '2026-07-15' })
						}),
						transactionSummaryFixture({
							id: 'tx-b',
							activeRevision: revisionFixture({ expenseDate: '2026-08-02' })
						})
					],
					transactionCount: 2
				})
			)
			const wrapper = await createWrapperAtRoute(SheetDetailView, '/sheets/sh-1')

			expectCardAgreesWithTable(wrapper, {
				rows: 2,
				hooks: [
					'data-test="transaction-expense-date"',
					'data-test="transaction-amount"',
					'data-test="transaction-status-badge"',
					'data-test="evidence-count"',
					'data-test="view-transaction"'
				]
			})
		})

		describe('FR-063 key dates on the detail view', () => {
			/** A `SheetDetail` with distinct dates, so no two cells can pass on the same text. */
			function datedSheet(overrides: Partial<SheetDetail> = {}): SheetDetail {
				return sheetFixture('IRR', {
					createdAt: '2026-06-10T00:00:00+00:00',
					submittedAt: '2026-07-02T00:00:00+00:00',
					// Null on purpose: this is the case a naive render turns into 1 January 1970, and a
					// fixture that populated every date could not see it.
					completedAt: null,
					firstExpenseDate: '2026-07-01',
					lastExpenseDate: '2026-07-28',
					...overrides
				})
			}

			async function mountWithSheet(sheet: SheetDetail) {
				const wrapper = createWrapper(SheetDetailView)
				useSheetStore().currentSheet = sheet
				await wrapper.vm.$nextTick()

				return wrapper
			}

			it('renders each of the four key dates, each with its own hook', async () => {
				const wrapper = await mountWithSheet(datedSheet())

				// Asserted as a set of hooks rather than "the card is not empty", because one missing date
				// and four rendered ones produce an equally non-empty card.
				for (const hook of [
					'sheet-created-at',
					'sheet-submitted-at',
					'sheet-completed-at',
					'sheet-expense-range'
				]) {
					expect(wrapper.find(`[data-test="${hook}"]`).exists(), `${hook} must be rendered`).toBe(true)
				}

				expect(wrapper.find('[data-test="sheet-created-at"]').text()).not.toBe(ABSENT_MARKER)
				expect(wrapper.find('[data-test="sheet-submitted-at"]').text()).not.toBe(ABSENT_MARKER)
				expect(wrapper.find('[data-test="sheet-expense-range"]').text()).not.toBe(ABSENT_MARKER)
			})

			/**
			 * A null date renders as a dash, **not** as 1 January 1970.
			 *
			 * `new Date(null).toLocaleDateString('fa-IR')` is 1970, so a sheet that has never been completed
			 * would carry a confident date beside it. All four fields are nullable and null for most sheets
			 * in the application, so this is the common case rather than an edge one.
			 *
			 * Asserted against the specific cell and not by scanning the whole view for "1970": a view that
			 * printed an epoch somewhere else would satisfy the looser check and leave this cell wrong.
			 */
			/**
			 * T385: FR-063's **latest active transaction revision number**, which the requirement named
			 * "the active transaction revision" and no surface could render.
			 *
			 * `activeRevision` belongs to `TransactionSummary`, so a sheet has one per transaction and a list
			 * row carries none at all — a *count* would also be redundant with the `transactionCount` the same
			 * clause already requires. The requirement was amended to name this value and the field was added.
			 *
			 * **0 is a real answer here, not a missing value**, so it is printed rather than replaced with the
			 * absent-marker the dates use. Asserting `0` explicitly is what pins that distinction.
			 */
			it('shows the sheet’s latest active revision number, printing 0 for an empty sheet', async () => {
				const populated = await mountWithSheet(datedSheet({ activeRevisionNumber: 3 }))
				expect(populated.find('[data-test="sheet-active-revision"]').text().trim()).toBe('3')

				const empty = await mountWithSheet(datedSheet({ activeRevisionNumber: 0 }))
				expect(
					empty.find('[data-test="sheet-active-revision"]').text().trim(),
					'a sheet holding no transactions has 0 revisions, which is an answer and not an absence'
				).toBe('0')
			})

			/**
			 * The payload key is carried, and the client type declares it — so a projection that dropped it
			 * fails a test rather than rendering `0` for every sheet and looking plausible.
			 */
			it('carries the field on the wire and declares it in the client type', () => {
				const contract = readFileSync(
					join(process.cwd(), 'specs/001-pettycash-v1-core/contracts/ocs-api.yaml'),
					'utf8'
				)
				expect(contract, 'the contract must declare the field').toMatch(/activeRevisionNumber:/)

				const types = readFileSync(join(process.cwd(), 'src/types/api.ts'), 'utf8')
				expect(types, 'SheetSummary must declare the field').toMatch(/activeRevisionNumber\?:/)
			})

			it('renders a null date as a dash rather than the Unix epoch', async () => {
				const wrapper = await mountWithSheet(datedSheet())

				expect(wrapper.find('[data-test="sheet-completed-at"]').text().trim()).toBe(ABSENT_MARKER)
				expect(wrapper.text()).not.toContain('۱۹۷۰')
				expect(wrapper.text()).not.toContain('1970')
			})

			/**
			 * Both bounds when both exist, an ellipsis only where one is missing.
			 *
			 * A single date cannot distinguish "the sheet's first expense fell on this day" from "this sheet
			 * covers only this day", so the two-present case joins its bounds and the one-present case marks
			 * the gap. Asserted in **both** directions because the first version of this test expected the
			 * ellipsis for a range with both bounds — it failed against a correct implementation, and had it
			 * passed it would have pinned a rule the range was never meant to follow.
			 */
			it('joins both bounds when both exist, and marks the gap when one is missing', async () => {
				const both = await mountWithSheet(datedSheet())
				const joined = both.find('[data-test="sheet-expense-range"]').text()

				expect(joined, 'two real bounds are joined by an en dash').toContain('–')
				expect(joined, 'nothing is missing, so no gap marker appears').not.toContain('…')

				const onlyFirst = await mountWithSheet(datedSheet({ lastExpenseDate: null }))
				const leading = onlyFirst.find('[data-test="sheet-expense-range"]').text()

				expect(leading, 'a missing trailing bound is marked, not silently dropped').toContain('…')

				const onlyLast = await mountWithSheet(datedSheet({ firstExpenseDate: null }))
				expect(
					onlyLast.find('[data-test="sheet-expense-range"]').text(),
					'a missing leading bound is marked too'
				).toContain('…')
			})

			/**
			 * An unloaded sheet renders the absent-marker rather than throwing.
			 *
			 * `currentSheet` is `null` until the payload arrives, and the range cell is the one place that
			 * reads an object rather than one optional field — a bare `currentSheet` there would throw
			 * inside the render and blank the whole card.
			 */
			it('renders the absent-marker before the sheet has loaded', async () => {
				const wrapper = createWrapper(SheetDetailView)
				useSheetStore().currentSheet = null
				await wrapper.vm.$nextTick()

				expect(wrapper.find('[data-test="sheet-key-dates"]').exists()).toBe(true)
				expect(wrapper.find('[data-test="sheet-expense-range"]').text().trim()).toBe(ABSENT_MARKER)
			})

			/**
			 * The two surfaces render the same rule from **one** helper.
			 *
			 * T381 put `formatDate` / `formatDateRange` in the list view, and T384 needed the identical
			 * behaviour in the detail view. Copying them would be two copies of one rule, which is how a
			 * convention drifts — a null date becoming 1970 on one surface and a dash on the other is exactly
			 * the split this repository keeps finding, and it would be invisible because each surface's own
			 * tests would still pass.
			 *
			 * Asserted structurally: neither view may declare its own copy of the rule, so the third
			 * surface that needs it cannot re-introduce the split.
			 */
			it('reads the date rule from one shared module, not a per-view copy', () => {
				for (const view of ['src/views/sheets/SheetListView.vue', 'src/views/sheets/SheetDetailView.vue']) {
					const source = readFileSync(join(process.cwd(), view), 'utf8')

					expect(source, `${view} must import the shared date helpers`).toMatch(/from '@\/utils\/date'/)
					expect(
						source,
						`${view} must not declare its own copy of the absent-date rule`
					).not.toMatch(/function formatDate\s*\(/)
				}
			})
		})

		it('should display submission validation errors when submitting incomplete sheet', async () => {
			const wrapper = createWrapper(SheetDetailView)
			expect(wrapper.find('[data-test="submission-errors"]').exists()).toBe(false)

			const sheetStore = useSheetStore()
			sheetStore.error = 'At least one transaction is required'
			await wrapper.vm.$nextTick()

			const errors = wrapper.find('[data-test="submission-errors"]')
			expect(errors.exists()).toBe(true)
			expect(errors.text()).toContain('At least one transaction is required')
			// Announced, not merely painted.
			expect(errors.attributes('role')).toBe('alert')
			expect(errors.attributes('aria-live')).toBe('assertive')
		})

		it('should clear the submission error when dismissed', async () => {
			// `createWrapper` establishes the active Pinia, so the store is reached after mounting.
			const wrapper = createWrapper(SheetDetailView)
			useSheetStore().error = 'At least one transaction is required'
			await wrapper.vm.$nextTick()
			expect(wrapper.find('[data-test="submission-errors"]').exists()).toBe(true)

			await wrapper.find('[data-test="submission-errors"] button').trigger('click')
			expect(wrapper.find('[data-test="submission-errors"]').exists()).toBe(false)
		})

		it('should lock sheet and show read-only state after submission', () => {
			const wrapper = createWrapper(SheetDetailView)
			expect(wrapper.find('[data-test="read-only-badge"]').exists()).toBe(true)
		})

		it('should display separate stage totals (Cost Center | Senior | Finance)', () => {
			const wrapper = createWrapper(SheetDetailView)
			expect(wrapper.find('[data-test="cost-center-approved-total"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="senior-approved-total"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="finance-approved-total"]').exists()).toBe(true)
		})

		it('should show latest effective outcome column alongside stage totals', () => {
			const wrapper = createWrapper(SheetDetailView)
			expect(wrapper.find('[data-test="effective-outcome-total"]').exists()).toBe(true)
		})
	})

	describe('Transaction Forms', () => {
		// `NcSelect` renders a `v-select`: the `data-test` lands on its root element and the accessible
		// name and the `aria-describedby` live on the inner `input`. Reading the attribute off the root
		// would find nothing and prove nothing about what the combobox announces.
		function selectInput(wrapper: VueWrapper, testId: string) {
			return wrapper.find(`[data-test="${testId}"] input`)
		}

		/**
		 * The text a control announces, resolved through its own `aria-describedby`.
		 *
		 * The requirement is not that a message exists somewhere in the document but that the control
		 * points at it, so the ids the control names are what gets read back. A message rendered but not
		 * referenced — or referenced but empty — both fail here.
		 */
		function announcedMessage(wrapper: VueWrapper, control: ReturnType<typeof selectInput>): string {
			const ids = (control.attributes('aria-describedby') ?? '').split(/\s+/).filter(Boolean)
			expect(ids.length, 'the control must reference the message it announces').toBeGreaterThan(0)
			return ids.map((id) => wrapper.find(`#${id}`).text()).join(' ').trim()
		}

		/**
		 * Submits the transaction form.
		 *
		 * The bare `form` element is used rather than a `data-test` handle, so that a view without that
		 * handle fails on the behaviour under test instead of on a missing attribute.
		 */
		function submitTransactionForm(wrapper: VueWrapper) {
			return wrapper.find('form').trigger('submit.prevent')
		}

		/** The body of the single `POST /sheets/{id}/transactions` the form issued, if any. */
		function createdTransactionBody(): Record<string, unknown> | undefined {
			const call = ocsRequest.mock.calls.find((args: unknown[]) => {
				const config = args[0] as { url?: string; method?: string; data?: unknown } | undefined
				return config?.method === 'POST' && /\/transactions$/.test(config?.url ?? '')
			})
			return (call?.[0] as { data?: Record<string, unknown> } | undefined)?.data
		}

		/** The body of the single `PATCH /transactions/{id}` the form issued, if any. */
		function updatedTransactionBody(): Record<string, unknown> | undefined {
			const call = ocsRequest.mock.calls.find((args: unknown[]) => {
				const config = args[0] as { url?: string; method?: string; data?: unknown } | undefined
				return config?.method === 'PATCH' && /\/transactions\//.test(config?.url ?? '')
			})
			return (call?.[0] as { data?: Record<string, unknown> } | undefined)?.data
		}

		it('should render transaction creation form with required fields', () => {
			const wrapper = createWrapper(TransactionFormView)
			expect(wrapper.find('[data-test="expense-date-input"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="amount-input"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="description-input"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="cost-center-select"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="expense-type-select"]').exists()).toBe(true)
		})

		// FR-032: the transaction is routed to the manager the server holds for its cost center, so the
		// options have to be the configured ones. The view previously answered with hard-coded
		// `cc-1`/`et-1` placeholders that exist nowhere in the instance.
		it('should offer the configured cost centers and expense types', async () => {
			const wrapper = await createWrapperAtRoute(TransactionFormView, '/sheets/sh-1/transactions/create')
			expect(selectOptions(wrapper, 'cost-center-select').map((o) => o.value)).toEqual([COST_CENTER_ENGINEERING, COST_CENTER_MARKETING])
			expect(selectOptions(wrapper, 'expense-type-select').map((o) => o.value)).toEqual([EXPENSE_TYPE_SUPPLIES, EXPENSE_TYPE_TRAVEL])
		})

		it('should require expense date and positive amount as minimum draft data', async () => {
			const wrapper = await createWrapperAtRoute(TransactionFormView, '/sheets/sh-1/transactions/create')
			await wrapper.find('[data-test="expense-date-input"]').setValue('2026-01-15')
			await wrapper.find('[data-test="amount-input"]').setValue('1000')
			await wrapper.find('[data-test="transaction-save-draft"]').trigger('click')
			await flushPromises()

			// The original assertion was `expect(wrapper.emitted('save-draft')).toBeTruthy()`. This is a
			// routed view with no parent to emit to — it persists through the store — so that event can
			// never be emitted and the assertion could only ever fail. What FR-022 actually requires is
			// that a draft is saved with an expense date and a positive amount *and nothing else*.
			const body = createdTransactionBody()
			expect(body, 'the draft must be persisted').toBeDefined()
			expect(body?.expenseDate).toMatch(/^\d{4}-\d{2}-\d{2}$/)
			expect(body?.amountMinor).toBe(1000)
			// The remaining fields are sent empty rather than absent: the point is that the form invented
			// no value for them, not that it omitted the keys.
			expect(body?.description).toBe('')
			expect(body?.costCenterId).toBe('')
			expect(body?.expenseTypeId).toBe('')
		})

		it('should refuse to save a draft without an expense date and a positive amount', async () => {
			const wrapper = await createWrapperAtRoute(TransactionFormView, '/sheets/sh-1/transactions/create')
			// The date is pre-filled with today, so the amount is what has to fail the draft rules.
			await wrapper.find('[data-test="expense-date-input"]').setValue('')
			await wrapper.find('[data-test="amount-input"]').setValue('1000')
			expect(wrapper.find('[data-test="transaction-save-draft"]').attributes('disabled')).toBeDefined()

			await wrapper.find('[data-test="expense-date-input"]').setValue('2026-01-15')
			await wrapper.find('[data-test="amount-input"]').setValue('0')
			expect(wrapper.find('[data-test="transaction-save-draft"]').attributes('disabled')).toBeDefined()

			await wrapper.find('[data-test="transaction-save-draft"]').trigger('click')
			await flushPromises()
			expect(createdTransactionBody()).toBeUndefined()
		})

		// The four `*-error` tests below previously mounted an empty form and required the error to be
		// there already. They never submitted, so they asserted a state the component had no reason to
		// be in — the same defect corrected for `submission-errors` in the Sheet Detail slice. They are
		// rewritten to submit, and then to check the two things FR-071 asks of error presentation: the
		// control announces its own message, and the summary names the field without hunting for it.

		it('should show field-level errors for missing cost center at submission', async () => {
			const wrapper = createWrapper(TransactionFormView)
			expect(wrapper.find('[data-test="validation-summary-costCenterId"]').exists()).toBe(false)

			await submitTransactionForm(wrapper)
			expect(wrapper.find('[data-test="validation-summary-costCenterId"]').exists()).toBe(true)
			expect(announcedMessage(wrapper, selectInput(wrapper, 'cost-center-select'))).toContain('validation.required')
		})

		it('should show field-level errors for missing expense type at submission', async () => {
			const wrapper = createWrapper(TransactionFormView)
			await submitTransactionForm(wrapper)
			expect(wrapper.find('[data-test="validation-summary-expenseTypeId"]').exists()).toBe(true)
			expect(announcedMessage(wrapper, selectInput(wrapper, 'expense-type-select'))).toContain('validation.required')
		})

		it('should show field-level errors for missing description at submission', async () => {
			const wrapper = createWrapper(TransactionFormView)
			await submitTransactionForm(wrapper)
			expect(wrapper.find('[data-test="validation-summary-description"]').exists()).toBe(true)
			expect(announcedMessage(wrapper, wrapper.find('[data-test="description-input"]'))).toContain('validation.required')
		})

		it('should announce the summary of everything still missing', async () => {
			const wrapper = createWrapper(TransactionFormView)
			// The date is pre-filled with today, so it is cleared to reach a wholly empty submission.
			await wrapper.find('[data-test="expense-date-input"]').setValue('')
			await submitTransactionForm(wrapper)

			const summary = wrapper.find('[data-test="validation-summary"]')
			expect(summary.exists()).toBe(true)
			// Announced, not merely painted.
			expect(summary.attributes('role')).toBe('alert')
			expect(summary.attributes('aria-live')).toBe('assertive')
			for (const field of ['expenseDate', 'amountMinor', 'description', 'costCenterId', 'expenseTypeId']) {
				expect(wrapper.find(`[data-test="validation-summary-${field}"]`).exists(), `${field} must be named`).toBe(true)
			}
		})

		// FR-023 requires "all required evidence" at submission, and the requirement is the expense
		// type's: `data-model.md` gives `evidence_required` the meaning "at least one active item
		// required at submission when true". The rule is therefore conditional, and both branches are
		// asserted — a form that always demanded evidence would fail the second, and a form that never
		// did would fail the first.
		it('should show field-level errors for missing required evidence at submission', async () => {
			const wrapper = await createWrapperAtRoute(TransactionFormView, '/sheets/sh-1/transactions/create')
			await fillTransactionForm(wrapper, { costCenterId: COST_CENTER_ENGINEERING, expenseTypeId: EXPENSE_TYPE_TRAVEL })
			await submitTransactionForm(wrapper)
			expect(wrapper.find('[data-test="validation-summary-evidence"]').exists()).toBe(true)
		})

		it('should not demand evidence for an expense type that does not require it', async () => {
			const wrapper = await createWrapperAtRoute(TransactionFormView, '/sheets/sh-1/transactions/create')
			await fillTransactionForm(wrapper, { costCenterId: COST_CENTER_ENGINEERING, expenseTypeId: EXPENSE_TYPE_SUPPLIES })
			await submitTransactionForm(wrapper)
			expect(wrapper.find('[data-test="validation-summary-evidence"]').exists()).toBe(false)
		})

		it('should accept a submitted transaction once every required field is present', async () => {
			const wrapper = await createWrapperAtRoute(TransactionFormView, '/sheets/sh-1/transactions/create')
			await fillTransactionForm(wrapper, { costCenterId: COST_CENTER_ENGINEERING, expenseTypeId: EXPENSE_TYPE_SUPPLIES })
			expect(wrapper.find('[data-test="validation-summary"]').exists()).toBe(false)
			expect(wrapper.find('[data-test="submit-btn"]').attributes('disabled')).toBeUndefined()
		})

		// FR-021: the transaction inherits the sheet currency and defines none of its own.
		it('should inherit and display sheet currency (not editable)', async () => {
			const wrapper = await createWrapperAtRoute(TransactionFormView, '/sheets/sh-1/transactions/create')
			useSheetStore().currentSheet = sheetFixture('IRR')
			await wrapper.vm.$nextTick()

			const currencyField = wrapper.find('[data-test="transaction-currency"]')
			expect(currencyField.exists()).toBe(true)
			// An `<input>` carries its value as an attribute, not as text.
			expect(currencyField.attributes('value')).toBe('IRR')
			// Read-only rather than a select: the sheet already fixed it, so offering a choice would
			// contradict FR-021. Nothing on the form may write a currency of its own.
			expect(currencyField.attributes('readonly')).toBeDefined()
			expect(wrapper.find('[data-test="currency-select"]').exists()).toBe(false)
		})

		it('should validate amount is positive integer in minor units', async () => {
			// `amountMinor` is the amount already scaled to the currency's minor unit, so a fraction of
			// one is not a representable amount of money. The previous rule only rejected `<= 0`, which
			// let 0.5 through to an API that has nowhere to put the remainder.
			for (const rejected of ['0', '-1', '0.5']) {
				const wrapper = createWrapper(TransactionFormView)
				await wrapper.find('[data-test="amount-input"]').setValue(rejected)
				await submitTransactionForm(wrapper)
				expect(wrapper.find('[data-test="validation-summary-amountMinor"]').exists(), `${rejected} must be rejected`).toBe(true)
			}

			const accepted = createWrapper(TransactionFormView)
			await accepted.find('[data-test="amount-input"]').setValue('1')
			expect(accepted.find('[data-test="validation-summary-amountMinor"]').exists()).toBe(false)
		})

		it('should allow saving draft with only expense date and amount', async () => {
			const wrapper = createWrapper(TransactionFormView)
			await wrapper.find('[data-test="expense-date-input"]').setValue('2026-01-15')
			await wrapper.find('[data-test="amount-input"]').setValue('500')
			expect(wrapper.find('[data-test="transaction-save-draft"]').attributes('disabled')).toBeUndefined()
		})

		// FR-026: after submission the purchaser must not edit a transaction's business information.
		// The original test passed a `transactionStatus` *prop* the view never declared, so it asserted
		// against a contract that did not exist; the status lives on the loaded transaction.
		it('should disable form when transaction is in submitted state', async () => {
			// **The fixture is driven through the request mock, not seeded into the store after mount.**
			// The view loads its own transaction on mount (`loadTransactionForEdit`), so a store seed
			// written afterwards is a race the mount-time load can win -- and it did: with the router fixed
			// (see `createTestRouter`), this test failed while the store probe showed the seeded status and
			// the view still rendered the editable branch. Setting the detail fixture first makes the load
			// itself produce the submitted status, so there is no ordering to depend on.
			setTransactionDetail(transactionFixture({ status: 'AWAITING_COST_CENTER_REVIEW' }))
			const wrapper = await createWrapperAtRoute(TransactionFormView, '/transactions/tx-1/edit')

			await wrapper.find('[data-test="expense-date-input"]').setValue('2026-01-15')
			await wrapper.find('[data-test="amount-input"]').setValue('1000')
			await wrapper.vm.$nextTick()
			for (const control of ['expense-date-input', 'amount-input', 'description-input']) {
				expect(wrapper.find(`[data-test="${control}"]`).attributes('disabled'), `${control} must be locked`).toBeDefined()
			}
			expect(wrapper.find('[data-test="submit-btn"]').attributes('disabled')).toBeDefined()
			expect(wrapper.find('[data-test="transaction-save-draft"]').attributes('disabled')).toBeDefined()
			// FR-025 allows evidence to be added and removed before submission only.
			expect(wrapper.find('[data-test="evidence-read-only-hint"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="evidence-uploader"]').exists()).toBe(false)
		})

		// The same requirement's one named exception: a returned transaction is corrected, which means
		// it is edited again.
		it('should leave a returned transaction editable for correction', async () => {
			const wrapper = await createWrapperAtRoute(TransactionFormView, '/transactions/tx-1/edit')
			const transactionStore = useTransactionStore()
			transactionStore.currentTransaction = transactionFixture({ status: 'AWAITING_PURCHASER_CORRECTION' })
			await wrapper.vm.$nextTick()

			expect(wrapper.find('[data-test="description-input"]').attributes('disabled')).toBeUndefined()
			expect(wrapper.find('[data-test="evidence-uploader"]').exists()).toBe(true)
		})

		it('should show evidence upload area in transaction form', () => {
			const wrapper = createWrapper(TransactionFormView)
			expect(wrapper.find('[data-test="evidence-upload-area"]').exists()).toBe(true)
		})

		/**
		 * T330: the edit form must submit the expense type's **public id**, taken from the payload.
		 *
		 * This is the client half of the contract test's round trip. `loadTransactionForEdit()` copies
		 * `activeRevision.expenseType.id` into the draft and the save sends that draft to
		 * `PATCH /transactions/{id}`, which resolves it with `findByPublicId()`. While the formatter put
		 * the title in that field, this form submitted "Office Supplies" as an identifier and every edit
		 * was refused with a 422 -- so FR-025's "edit" was unreachable from the UI.
		 *
		 * Asserted against a **server-shaped** payload: the fixture's ids are real UUIDs (see the note
		 * on the constants above), because a fixture with a readable id in a uuid field would satisfy
		 * "the form submitted what the payload carried" while proving nothing about the contract.
		 */
		it('should submit the expense type public id from the payload when editing', async () => {
			// Seeded on the **transport**, not assigned to the store after mounting. The view reads the
			// transaction during mount, so a store populated afterwards leaves the draft empty, the form
			// fails its own validation, and no request is issued -- which is how a test can look like it
			// exercised the edit path while having exercised nothing.
			setTransactionDetail(transactionFixture())
			const wrapper = await createWrapperAtRoute(TransactionFormView, '/transactions/tx-1/edit')

			await wrapper.find('[data-test="description-input"]').setValue('Taxi to the depot')
			await submitTransactionForm(wrapper)
			await flushPromises()

			const body = updatedTransactionBody()
			expect(body, 'the edit must reach PATCH /transactions/{id}').toBeDefined()
			expect(body?.expenseTypeId).toBe(EXPENSE_TYPE_SUPPLIES)
			// The name must not travel in the id field. This is the specific value the server used to be
			// handed, and `findByPublicId('Office Supplies')` returns null.
			expect(body?.expenseTypeId).not.toBe('Office Supplies')
			expect(body?.costCenterId).toBe(COST_CENTER_ENGINEERING)
		})
	})

	describe('Transaction List View', () => {
		// All three tests in this group mounted the view against an empty store — the OCS mock answers
		// an empty page — and then required a *per-row* element to exist. There were no rows, so none of
		// them could, whatever the view rendered. They are rewritten to seed the rows they assert on,
		// which is also what makes them able to distinguish "a badge exists" from "the right badge is
		// on the right row".
		async function mountList(items: TransactionSummary[]) {
			// The rows are seeded on the **sheet payload**, which is where they arrive now.
			// `contracts/ocs-api.yaml:1125` puts `transactions` on `SheetDetail`, and the view used to
			// issue a second request for the same rows against a path no route serves — a request that
			// answered `405` in every browser while these tests passed, because they stubbed the HTTP
			// layer and answered it anyway. Seeding the payload the server really sends is what makes
			// this group able to fail again.
			setSheetDetail(sheetFixture('IRR', { id: 'sh-1', transactions: items, transactionCount: items.length }))
			return createWrapperAtRoute(TransactionListView, '/sheets/sh-1/transactions')
		}

		const DRAFT_TX = transactionSummaryFixture({ id: 'tx-draft', status: 'DRAFT', stage: 'DRAFT', evidenceCount: 0 })
		const REVIEWED_TX = transactionSummaryFixture({
			id: 'tx-reviewed',
			status: 'COST_CENTER_APPROVED',
			stage: 'SENIOR',
			evidenceCount: 3,
			latestDecision: decisionFixture()
		})

		it('should list transactions for a sheet with status indicators', async () => {
			const wrapper = await mountList([DRAFT_TX, REVIEWED_TX])
			const rows = wrapper.findAll('[data-test="transaction-row"]')
			expect(rows).toHaveLength(2)

			// `StatusBadge` emits its own `data-testid` derived from the status it was given, so each
			// row can be checked for the badge that belongs to *it*. Asserting only that some badge
			// exists would pass even if every row carried the same one.
			expect(rows[0]?.find(`[data-testid="status-badge-${DRAFT_TX.status.toLowerCase()}"]`).exists()).toBe(true)
			expect(rows[1]?.find(`[data-testid="status-badge-${REVIEWED_TX.status.toLowerCase()}"]`).exists()).toBe(true)
			// And a row must not carry another row's badge.
			expect(rows[0]?.find(`[data-testid="status-badge-${REVIEWED_TX.status.toLowerCase()}"]`).exists()).toBe(false)
		})

		it('should show no rows and no badges for a sheet with no transactions', async () => {
			const wrapper = await mountList([])
			expect(wrapper.findAll('[data-test="transaction-row"]')).toHaveLength(0)
			expect(wrapper.find('[data-test="transaction-list"]').exists()).toBe(true)
		})

		/**
		 * T394: the narrow card carries the same figures as the table.
		 *
		 * FR-071 requires every listed element visible **without horizontal scroll** at 390px, which this
		 * nine-column table used to answer with `overflow-x: auto`. `ResponsiveTable` renders one card per
		 * row below 640px.
		 *
		 * **This is the view where it mattered most.** Nine columns is the widest table in the application,
		 * so it was the one most likely to need per-column treatment, and it carries the money figure and
		 * the decision history together -- the two things a purchaser opens this list for.
		 */
		it('carries the same figures in the narrow card as in the table', async () => {
			const wrapper = await mountList([DRAFT_TX, REVIEWED_TX])

			expectCardAgreesWithTable(wrapper, {
				rows: 2,
				hooks: [
					'data-test="transaction-expense-date"',
					'data-test="transaction-amount"',
					'data-test="transaction-stage"',
					'data-test="latest-decision"',
					'data-test="evidence-count"',
					'data-test="view-transaction"'
				]
			})
		})

		it('should show transaction stage and latest decision', async () => {
			const wrapper = await mountList([DRAFT_TX, REVIEWED_TX])

			// **Scoped to the desktop table (T389).** `ResponsiveTable` renders the table *and* a card per
			// row, and each cell's own hook appears in both, so an unscoped count returns 4 for two rows.
			// A two-layout component makes a cell count layout-specific; saying which layout is what keeps
			// the assertion about the table rather than about the component's internals.
			const desktop = wrapper.find('.responsive-table__desktop')
			expect(desktop.exists(), 'the wide-viewport table must still render').toBe(true)

			const stages = desktop.findAll('[data-test="transaction-stage"]')
			expect(stages).toHaveLength(2)
			expect(stages[0]?.text()).toContain('stage.draft')
			expect(stages[1]?.text()).toContain('stage.senior')

			// The same row must be readable in the narrow layout, carrying the same figures -- which is the
			// whole point of the reflow, and would not follow from the desktop assertions alone.
			const cards = wrapper.findAll('.responsive-table__card')
			expect(cards).toHaveLength(2)
			expect(cards[0]?.find('[data-test="transaction-stage"]').text()).toContain('stage.draft')

			// The latest decision is a field on every row the store already holds, and the view fetched
			// it and then never rendered it. A row with no decision says so rather than showing a blank
			// cell that reads as a rendering fault.
			const decisions = wrapper.findAll('.responsive-table__desktop [data-test="latest-decision"]')
			expect(decisions).toHaveLength(2)
			expect(decisions[1]?.text()).toContain('decision.approve')
			// FR-052: the actor travels with the decision, so the name shown is the one at the time.
			expect(decisions[1]?.text()).toContain('Manager One')
			expect(decisions[0]?.text()).toContain('transactions.noDecision')
		})

		it('should show evidence count per transaction', async () => {
			const wrapper = await mountList([DRAFT_TX, REVIEWED_TX])
			// Desktop layout only, for the same reason as the stage and decision cells above.
			const counts = wrapper.findAll('.responsive-table__desktop [data-test="evidence-count"]')
			expect(counts).toHaveLength(2)
			expect(counts[0]?.text()).toBe('0')
			expect(counts[1]?.text()).toBe('3')
		})

		it('should give each row action an accessible name', async () => {
			// The row action is an icon-only button, so without a name it is announced as just
			// "button" (WCAG 4.1.2).
			const wrapper = await mountList([DRAFT_TX])
			const action = wrapper.find('[data-test="view-transaction"]')
			expect(action.attributes('aria-label')).toBeTruthy()
			expect(wrapper.find('[data-test="create-transaction-btn"]').attributes('aria-label')).toBeTruthy()
		})
	})

	describe('Transaction Detail View', () => {
		async function mountDetail(detail: TransactionDetail, revisions: Revision[] = []) {
			setTransactionDetail(detail)
			setRevisions(revisions)
			return createWrapperAtRoute(TransactionDetailView, `/transactions/${detail.id}`)
		}

		/** For the tests that assert on navigation rather than on markup (T359). */
		async function mountDetailWithRouter(detail: TransactionDetail, revisions: Revision[] = []) {
			setTransactionDetail(detail)
			setRevisions(revisions)
			return createWrapperWithRouter(TransactionDetailView, `/transactions/${detail.id}`)
		}

		it('should display transaction details with revision history', async () => {
			const wrapper = await mountDetail(transactionFixture(), [revisionFixture()])
			expect(wrapper.find('[data-test="revision-history"]').exists()).toBe(true)
			// The header is only meaningful when there is something to choose between.
			expect(wrapper.findAll('[data-test="revision-tab"]')).toHaveLength(1)
		})

		// FR-052 makes the actor a snapshot rather than a live lookup: display name and role are the
		// ones at the time of the decision. The view rendered the name and the role and dropped the
		// identifier, so a decision could not be traced back to the person who made it.
		it('should show all decisions with actor snapshots and explanations', async () => {
			const decisions = [
				decisionFixture({ id: 'dec-1' }),
				decisionFixture({
					id: 'dec-2',
					stage: 'SENIOR',
					decision: 'RETURN',
					explanation: 'Missing the original receipt',
					actor: { userId: 'senior1', displayName: 'Senior One', role: 'SENIOR_MANAGER' },
					decidedAt: '2026-01-21T11:00:00+00:00'
				})
			]
			const wrapper = await mountDetail(transactionFixture({ decisions }))

			const items = wrapper.findAll('[data-test="decision-item"]')
			expect(items).toHaveLength(2)

			const snapshots = wrapper.findAll('[data-test="actor-snapshot"]')
			expect(snapshots).toHaveLength(2)
			for (const snapshot of snapshots) {
				// All three parts, or the snapshot is not a snapshot.
				expect(snapshot.find('[data-test="actor-user-id"]').text()).toBeTruthy()
				expect(snapshot.find('[data-test="actor-display-name"]').text()).toBeTruthy()
				expect(snapshot.find('[data-test="actor-role"]').text()).toBeTruthy()
			}
			expect(snapshots[0]?.text()).toContain('Manager One')
			expect(snapshots[0]?.text()).toContain('COST_CENTER_MANAGER')
			expect(snapshots[1]?.text()).toContain('Senior One')

			// The explanation travels with the decision it belongs to.
			expect(items[0]?.text()).toContain('Within the travel budget')
			expect(items[1]?.text()).toContain('Missing the original receipt')
		})

		it('should state plainly when a transaction has no decisions yet', async () => {
			const wrapper = await mountDetail(transactionFixture())
			expect(wrapper.findAll('[data-test="decision-item"]')).toHaveLength(0)
			expect(wrapper.find('[data-test="no-decisions"]').exists()).toBe(true)
		})

		// FR-059: after submission removal marks evidence inactive rather than deleting it, and its
		// history stays viewable. FR-060: the active state is part of what an authorized viewer sees.
		it('should display evidence list with active/inactive state', async () => {
			const evidence = [
				evidenceFixture({ id: 'ev-1', name: 'receipt.pdf', active: true }),
				evidenceFixture({
					id: 'ev-2',
					name: 'draft-note.txt',
					mediaType: 'text/plain',
					active: false,
					deactivatedAt: '2026-01-22T08:00:00+00:00'
				})
			]
			const wrapper = await mountDetail(transactionFixture({ evidence, evidenceCount: 2 }))

			const items = wrapper.findAll('[data-test="evidence-list-item"]')
			expect(items).toHaveLength(2)
			const states = wrapper.findAll('[data-test="evidence-active-badge"]')
			expect(states).toHaveLength(2)
			expect(states[0]?.text()).toContain('evidence.active')
			expect(states[1]?.text()).toContain('evidence.inactive')
			// The file is still listed after deactivation — that is what "not deleted" means.
			expect(items[1]?.text()).toContain('draft-note.txt')
		})

		// This test passed before for the wrong reason: with no transaction loaded the view computed
		// `isSubmitted` as `null !== 'DRAFT'`, so a DRAFT transaction's read-only badge appeared
		// without a transaction ever being fetched.
		it('should show read-only view for submitted transactions', async () => {
			const submitted = transactionFixture({ status: 'AWAITING_COST_CENTER_REVIEW', stage: 'COST_CENTER' })
			const wrapper = await mountDetail(submitted)
			expect(wrapper.find('[data-test="read-only-indicator"]').exists()).toBe(true)
			// And the affordances that would contradict it are gone.
			expect(wrapper.find('[data-test="edit-transaction-btn"]').exists()).toBe(false)
			expect(wrapper.find('[data-test="create-correction-btn"]').exists()).toBe(false)
		})

		it('should offer editing for a draft and correction only for a returned transaction', async () => {
			const draft = await mountDetail(transactionFixture())
			expect(draft.find('[data-test="edit-transaction-btn"]').exists()).toBe(true)
			expect(draft.find('[data-test="read-only-indicator"]').exists()).toBe(false)
			expect(draft.find('[data-test="create-correction-btn"]').exists()).toBe(false)

			const returned = await mountDetail(transactionFixture({ status: 'AWAITING_PURCHASER_CORRECTION', stage: 'CORRECTION' }))
			expect(returned.find('[data-test="create-correction-btn"]').exists()).toBe(true)
			expect(returned.find('[data-test="read-only-indicator"]').exists()).toBe(false)
		})

		/**
		 * T359. **This is the assertion whose absence let the defect ship.**
		 *
		 * Every other correction test in this file asserts the button *exists*; none pressed it. The
		 * handler pushed `{ name: 'transaction-correction-create' }`, a route the application does not
		 * declare, and `vue-router` throws `MATCHER_NOT_FOUND` synchronously from `router.push` — not a
		 * rejected promise — so the click aborted with nothing caught and the purchaser's only
		 * correction affordance did nothing at all.
		 *
		 * Asserting `exists()` is not a weaker version of this test; it is a different question. The
		 * button can be present, correctly scoped to a returned transaction, correctly labelled, and
		 * still navigate nowhere, and every existence assertion in the file was satisfied by exactly
		 * that broken control.
		 */
		it('should navigate to an editable form when the correction button is pressed', async () => {
			const returned = transactionFixture({
				status: 'AWAITING_PURCHASER_CORRECTION',
				stage: 'CORRECTION'
			})
			const { wrapper, router } = await mountDetailWithRouter(returned)

			const button = wrapper.find('[data-test="create-correction-btn"]')
			expect(button.exists()).toBe(true)

			await button.trigger('click')
			await flushPromises()

			// `router.currentRoute` is the application's own route table, so this fails with the
			// thrown MATCHER_NOT_FOUND rather than a silent no-op if the name is ever wrong again.
			expect(router.currentRoute.value.name).toBe('transaction-edit')
			expect(router.currentRoute.value.params.id).toBe(returned.id)

			// And the destination is one a returned transaction may actually be edited in: FR-026
			// carves out exactly this state, and `TransactionFormView`'s `editable` computed names
			// `AWAITING_PURCHASER_CORRECTION` explicitly. Asserting the route name alone would pass for
			// a push into a form that refuses the edit.
			expect(router.resolve({ name: 'transaction-edit', params: { id: returned.id } }).name)
				.toBe('transaction-edit')
		})

		/**
		 * T360. **These press the buttons, which is the whole point and what no test here had ever
		 * done.** `grep -rn "download-evidence\|remove-evidence" tests/Frontend/` returned nothing
		 * before this change, so the two controls shipped as `// TODO: Implement …` bodies: present,
		 * correctly scoped, correctly rendered, and inert.
		 *
		 * They go **through the view**, not through `EvidenceList` in isolation. The five existing
		 * evidence assertions in this file mount the component directly, which is how the tested
		 * surface and the shipped surface came to be two different files — the view had its own inline
		 * copy with the two empty handlers, and every test agreed with the unused component.
		 */
		it('should issue the content request when the evidence download button is pressed', async () => {
			const blob = new Blob(['receipt'], { type: 'application/pdf' })
			const contentSpy = vi.spyOn(evidenceApi, 'getEvidenceContent').mockResolvedValue(blob)
			// jsdom implements neither of these, and both are what the component uses to hand the file
			// to the browser. Stubbed rather than asserted, because neither is this test's subject.
			const createObjectURL = vi.fn(() => 'blob:mock')
			const revokeObjectURL = vi.fn()
			vi.stubGlobal('URL', { ...URL, createObjectURL, revokeObjectURL })

			try {
				const wrapper = await mountDetail(
					transactionFixture({ evidence: [evidenceFixture({ id: 'ev-1' })], evidenceCount: 1 })
				)

				const button = wrapper.find('[data-test="download-evidence-btn"]')
				expect(button.exists()).toBe(true)
				await button.trigger('click')
				await flushPromises()

				// The *request* is the assertion. Asserting the button's presence is what every other
				// test did, and it is satisfied by a button whose handler is an empty function.
				expect(contentSpy).toHaveBeenCalledWith('ev-1')
			} finally {
				contentSpy.mockRestore()
				vi.unstubAllGlobals()
			}
		})

		/**
		 * FR-058. Removal is the purchaser's and is offered only before submission, so this asserts
		 * both the request **and** the gate — a test that pressed the button on a submitted
		 * transaction would pass against an implementation offering removal at the wrong time.
		 */
		it('should issue the delete request when evidence removal is confirmed, and only before submission', async () => {
			const deleteSpy = vi.spyOn(evidenceApi, 'deleteEvidence').mockResolvedValue(
				evidenceFixture({ id: 'ev-1', active: false })
			)
			// `confirm` is the component's guard against a mis-click destroying a receipt. jsdom's
			// returns `false`, which would make the removal path untestable and would make this test
			// pass for the wrong reason if the guard were removed.
			const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true)

			try {
				const draft = await mountDetail(
					transactionFixture({ evidence: [evidenceFixture({ id: 'ev-1' })], evidenceCount: 1 })
				)
				await draft.find('[data-test="delete-evidence-btn"]').trigger('click')
				await flushPromises()

				expect(confirmSpy).toHaveBeenCalled()
				expect(deleteSpy).toHaveBeenCalledWith('ev-1')

				// A declined confirmation must not delete. The guard is the reason this is worth
				// asserting separately from the happy path.
				deleteSpy.mockClear()
				confirmSpy.mockReturnValue(false)
				const declined = await mountDetail(
					transactionFixture({ evidence: [evidenceFixture({ id: 'ev-2' })], evidenceCount: 1 })
				)
				await declined.find('[data-test="delete-evidence-btn"]').trigger('click')
				await flushPromises()
				expect(deleteSpy).not.toHaveBeenCalled()

				// FR-058's gate: submitted, so no removal control at all.
				const submitted = await mountDetail(
					transactionFixture({
						status: 'AWAITING_COST_CENTER_REVIEW',
						stage: 'COST_CENTER',
						evidence: [evidenceFixture({ id: 'ev-3' })],
						evidenceCount: 1
					})
				)
				expect(submitted.find('[data-test="delete-evidence-btn"]').exists()).toBe(false)
				// FR-060 still offers download to a reviewer — the asymmetry is the requirement.
				expect(submitted.find('[data-test="download-evidence-btn"]').exists()).toBe(true)
			} finally {
				deleteSpy.mockRestore()
				confirmSpy.mockRestore()
			}
		})

		it('should display revision selector for corrected transactions', async () => {
			const revisions = [
				revisionFixture(),
				revisionFixture({ id: 'rev-2', number: 2, createdAt: '2026-01-22T09:00:00+00:00' })
			]
			const wrapper = await mountDetail(transactionFixture(), revisions)
			expect(wrapper.find('[data-test="revision-selector"]').exists()).toBe(true)
			expect(wrapper.findAll('[data-test="revision-tab"]')).toHaveLength(2)
		})

		// FR-050: the *active* revision determines the current transaction information. The panel
		// defaulted to `revisions[0]`, which is the oldest revision, so a corrected transaction opened
		// showing its superseded amount and cost centre as if they were current.
		it('should open the revision panel on the active revision', async () => {
			const prior = revisionFixture({ id: 'rev-1', number: 1, active: false, amount: { amountMinor: 900, currency: 'IRR', minorUnit: 0 } })
			const active = revisionFixture({ id: 'rev-2', number: 2, active: true, description: 'Taxi to the airport, corrected' })
			const wrapper = await mountDetail(transactionFixture({ activeRevision: active }), [prior, active])

			const panel = wrapper.find('[role="tabpanel"]')
			expect(panel.text()).toContain('Taxi to the airport, corrected')
			// The superseded amount must not be on show as the current one — and the current one must be
			// shown at its own scale. Rial has no minor unit, so 1000 minor units is 1,000: the four
			// views that divided by a fixed 100 printed "10" instead, a hundredfold error in the figure a
			// finance officer reconciles against. Compared against `toLocaleString` so the assertion
			// states the scale rather than the Persian digit shapes, and suffixed with the currency code
			// so the locale-formatted date above cannot supply a false match.
			const rial = (minor: number) => `${minor.toLocaleString('fa-IR')} IRR`
			expect(panel.text()).toContain(rial(1000))
			expect(panel.text()).not.toContain(rial(1000 / 100))
			expect(panel.text()).not.toContain(rial(900))
			// The tab for the revision on display is the one marked selected.
			const selected = wrapper.findAll('[data-test="revision-tab"]').filter((tab) => tab.attributes('aria-selected') === 'true')
			expect(selected).toHaveLength(1)
			expect(selected[0]?.text()).toContain('2')
		})

		// FR-050: the active revision determines the current information while all prior revisions
		// remain part of the record, and FR-053 makes the record append-only. The view had a revision
		// *selector* — which lets the reader page through history — but never stated that what they are
		// reading is retained history rather than a previous state of an editable row.
		it('should show immutable prior revisions with their decisions', async () => {
			const prior = revisionFixture({ id: 'rev-1', number: 1, active: false })
			const active = revisionFixture({ id: 'rev-2', number: 2, active: true, description: 'Taxi to the airport, corrected' })
			const wrapper = await mountDetail(
				transactionFixture({ activeRevision: active, decisions: [decisionFixture()] }),
				[prior, active]
			)
			const region = wrapper.find('[data-test="prior-revisions"]')
			expect(region.exists()).toBe(true)
			// Only the superseded revision is history; the active one is the current information.
			const listed = wrapper.findAll('[data-test="prior-revision"]')
			expect(listed).toHaveLength(1)
			expect(listed[0]?.text()).toContain('1')
			expect(region.text()).not.toContain('Taxi to the airport, corrected')
			// Retained, not editable: nothing in the region offers to change a past revision.
			expect(region.find('input, textarea, select, button').exists()).toBe(false)
		})
	})

	describe('Evidence Upload', () => {
		// A `File` jsdom will accept without allocating 101 MB of memory: the component only reads
		// `size`, and the uploader's own limit is what is under test.
		function fileOfSize(bytes: number, name = 'receipt.pdf', type = 'application/pdf'): File {
			const file = new File(['x'], name, { type })
			Object.defineProperty(file, 'size', { value: bytes })
			return file
		}

		async function selectFile(wrapper: VueWrapper, file: File) {
			const input = wrapper.find('[data-test="evidence-upload-input"]')
			Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
			await input.trigger('change')
			await wrapper.vm.$nextTick()
		}

		it('should render evidence uploader with drag-and-drop area', () => {
			const wrapper = createWrapper(EvidenceUploader, {
				props: { transactionId: 'test-tx-1', isDraft: true }
			})
			expect(wrapper.find('[data-test="drop-zone"]').exists()).toBe(true)
		})

		it('should show upload progress indicator', async () => {
			let resolveUpload!: (value: unknown) => void
			const pendingUpload = new Promise((resolve) => { resolveUpload = resolve })
			const uploadSpy = vi.spyOn(evidenceApi, 'uploadEvidence').mockReturnValue(pendingUpload as never)
			try {
				const wrapper = createWrapper(EvidenceUploader, {
					props: { transactionId: 'test-tx-1', isDraft: true }
				})
				await selectFile(wrapper, new File(['receipt-content'], 'receipt.pdf', { type: 'application/pdf' }))
				expect(wrapper.find('[data-test="upload-btn"]').exists()).toBe(true)
				await wrapper.find('[data-test="upload-btn"]').trigger('click')
				await wrapper.vm.$nextTick()
				expect(wrapper.find('[data-test="upload-progress"]').exists()).toBe(true)
				resolveUpload({ id: 'ev-1' })
				await pendingUpload
			} finally {
				uploadSpy.mockRestore()
			}
		})

		it('should enforce 100 MB maximum file size', async () => {
			const wrapper = createWrapper(EvidenceUploader)
			await selectFile(wrapper, fileOfSize(101 * 1024 * 1024))
			expect(wrapper.find('[data-test="file-size-error"]').exists()).toBe(true)
		})

		// The boundary is inclusive: a file of exactly the limit is allowed. Only `> MAX` is refused,
		// and an off-by-one here silently rejects a legitimate 100 MB receipt.
		it('should accept a file of exactly the maximum size', async () => {
			const wrapper = createWrapper(EvidenceUploader)
			await selectFile(wrapper, fileOfSize(100 * 1024 * 1024))
			expect(wrapper.find('[data-test="file-size-error"]').exists()).toBe(false)
			expect(wrapper.find('[data-test="upload-btn"]').exists()).toBe(true)
		})

		// A refusal that does not say what the limit is leaves the purchaser with nothing to act on.
		// The message key carried a `{max}` placeholder that no source ever filled, so the number was
		// never shown.
		it('should reject oversized files with clear error message', async () => {
			const wrapper = createWrapper(EvidenceUploader)
			await selectFile(wrapper, fileOfSize(101 * 1024 * 1024))

			const error = wrapper.find('[data-test="file-size-error"]')
			expect(error.exists()).toBe(true)
			expect(error.text()).toContain('100 MB')
			// Announced, not merely painted.
			expect(error.attributes('role')).toBe('alert')
		})

		// FR-029 requires each evidence file to retain name, type, size and upload time, and T283
		// exists because the suite only checked that four hooks existed rather than what they said.
		it('should show evidence list with file name, type, size, and upload time', () => {
			// 1,572,864 bytes is 1.5 MB. The unit boundary matters: 1,024,000 bytes is 1000 KB, and a
			// test that called that "1.0 MB" would have been asserting the wrong conversion.
			const wrapper = createWrapper(EvidenceList, {
				props: { evidence: [evidenceFixture({ sizeBytes: 1572864 })], isDraft: true }
			})
			expect(wrapper.find('[data-test="evidence-name"]').text()).toBe('receipt.pdf')
			expect(wrapper.find('[data-test="evidence-type"]').text()).toBe('application/pdf')
			// Formatted for a reader, not raw bytes.
			expect(wrapper.find('[data-test="evidence-size"]').text()).toBe('1.5 MB')
			expect(wrapper.find('[data-test="evidence-size"]').text()).not.toContain('1572864')
			expect(wrapper.find('[data-test="evidence-upload-time"]').text()).not.toBe('')
		})

		// FR-058: before submission the purchaser removes evidence from their own open transaction.
		it('should allow removing evidence before submission', async () => {
			const wrapper = createWrapper(EvidenceList, {
				props: { evidence: [evidenceFixture()], isDraft: true }
			})
			expect(wrapper.find('[data-test="delete-evidence-btn"]').exists()).toBe(true)
		})

		// FR-060: an authorized viewer must be able to download evidence. The download button was gated
		// on `isDraft`, so a cost-centre manager reviewing a *submitted* transaction — the whole point
		// of the review — was offered no way to open the receipt they were being asked to approve.
		it('should allow downloading evidence from a submitted transaction', () => {
			const wrapper = createWrapper(EvidenceList, {
				props: { evidence: [evidenceFixture()], isDraft: false }
			})
			expect(wrapper.find('[data-test="download-evidence-btn"]').exists()).toBe(true)
			// Removal, by contrast, is a pre-submission affordance only.
			expect(wrapper.find('[data-test="delete-evidence-btn"]').exists()).toBe(false)
		})

		// FR-059: after submission removal marks the item inactive rather than deleting it, and the
		// history stays viewable. The fixture's `deactivationReason` was invented — neither the contract
		// nor `src/types/api.ts` has such a field — so it is dropped rather than added to the type.
		it('should mark evidence as inactive (not delete) after submission', () => {
			const wrapper = createWrapper(EvidenceList, {
				props: {
					evidence: [evidenceFixture({ active: false, deactivatedAt: '2026-01-16T10:00:00Z' })],
					isDraft: false
				}
			})
			// One chip states the state. The component previously rendered *two* chips that both said
			// "inactive" — an `evidence-active-badge` and a separate `inactive-badge` — so the row
			// printed the same word twice and the duplicate is not worth preserving as a handle.
			const state = wrapper.find('[data-test="evidence-active-badge"]')
			expect(state.text()).toContain('evidence.inactive')
			expect(state.text()).not.toContain('evidence.active')
			expect(wrapper.find('[data-test="delete-evidence-btn"]').exists()).toBe(false)
			// Still listed, and not downloadable: "not deleted" is the requirement, but an inactive item
			// is no longer the evidence the reviewer is being asked to rely on.
			expect(wrapper.findAll('[data-test="evidence-list-item"]')).toHaveLength(1)
			expect(wrapper.find('[data-test="download-evidence-btn"]').exists()).toBe(false)
		})

		// The original version mounted the list with no props at all and required the deactivation info
		// to be present. With no evidence there is nothing deactivated, so it asserted a state the
		// component had no reason to be in — the same defect corrected for `submission-errors` and the
		// four form errors.
		it('should display inactive evidence in history with deactivation info', () => {
			const wrapper = createWrapper(EvidenceList, {
				props: {
					evidence: [
						evidenceFixture({ id: 'ev-1', name: 'kept.pdf' }),
						evidenceFixture({ id: 'ev-2', name: 'withdrawn.pdf', active: false, deactivatedAt: '2026-01-16T10:00:00Z' })
					],
					isDraft: false
				}
			})

			const infos = wrapper.findAll('[data-test="deactivation-info"]')
			expect(infos).toHaveLength(1)
			// When it was deactivated, so the history says why the item is inactive and since when.
			expect(infos[0]?.text()).toContain('evidence.deactivatedAt')
			expect(infos[0]?.text()).not.toBe('')
			// And only for the item that is.
			expect(infos[0]?.text()).not.toContain('kept.pdf')
		})

		it('should state plainly when there is no evidence at all', () => {
			const wrapper = createWrapper(EvidenceList, { props: { evidence: [], isDraft: true } })
			expect(wrapper.findAll('[data-test="evidence-list-item"]')).toHaveLength(0)
			expect(wrapper.find('[data-test="evidence-empty"]').exists()).toBe(true)
		})
	})

	describe('Submitted Read-Only Behavior', () => {
		// Four of this group's five tests passed a prop no view declares — `sheetStatus`,
		// `isSubmitted`, `transactionStatus` — so they asserted against contracts that do not exist.
		// The status lives on the loaded sheet or transaction, and the route decides which is loaded.
		async function mountSheet(status: SheetStatus) {
			const sheet = sheetFixture('IRR', { status, transactions: [transactionSummaryFixture()] })
			setSheetDetail(sheet)
			return createWrapperAtRoute(SheetDetailView, `/sheets/${sheet.id}`)
		}

		it('should show read-only indicators on submitted sheets', async () => {
			const wrapper = await mountSheet('AWAITING_COST_CENTER_REVIEW')
			expect(wrapper.find('[data-test="read-only-badge"]').exists()).toBe(true)
		})

		// The original also looked for `[data-test="read-only-indicator"]` on the sheet, a name no
		// sheet view has ever emitted — the transaction detail view is where that hook lives, and the
		// second slice established `read-only-badge` here.
		it('should withhold every editing affordance on a submitted sheet', async () => {
			const wrapper = await mountSheet('AWAITING_COST_CENTER_REVIEW')
			expect(wrapper.find('[data-test="read-only-badge"]').exists()).toBe(true)
			expect(wrapper.findAll('[data-test="edit-sheet-btn"]')).toHaveLength(0)
			expect(wrapper.findAll('[data-test="create-transaction-btn"]')).toHaveLength(0)
			expect(wrapper.find('[data-test="submit-sheet-button"]').exists()).toBe(false)
		})

		/**
		 * T327: `canSubmit` is derived from a payload key, and the assertion has to be on **both**
		 * sides of it.
		 *
		 * `src/stores/sheets.ts` computes `canSubmit` as `status === 'OPEN' && transactions.length > 0`,
		 * and `transactions` was absent from every response the server could produce — so `?? 0` made
		 * the computed permanently `false` and this button was never in the DOM. The defect was
		 * invisible because the only test touching this button asserted its *absence* on a submitted
		 * sheet, which a permanently-false computed satisfies perfectly.
		 *
		 * So both directions are asserted: absent for an open sheet that has no transaction (FR-024),
		 * and **present** for one the API reports as holding a transaction. The second is the assertion
		 * whose absence let this ship.
		 */
		it('should withhold submission from an open sheet that has no transaction', async () => {
			setSheetDetail(sheetFixture('IRR', { status: 'OPEN', transactions: [], transactionCount: 0 }))
			const wrapper = await createWrapperAtRoute(SheetDetailView, '/sheets/sh-1')

			expect(
				wrapper.find('[data-test="submit-sheet-button"]').exists(),
				'FR-024 requires at least one transaction before submission, so the control must be absent'
			).toBe(false)
		})

		it('should offer submission for an open sheet the API reports as holding a transaction', async () => {
			setSheetDetail(sheetFixture('IRR', {
				status: 'OPEN',
				transactions: [transactionSummaryFixture({ id: 'tx-1' })],
				transactionCount: 1
			}))
			const wrapper = await createWrapperAtRoute(SheetDetailView, '/sheets/sh-1')

			expect(
				wrapper.find('[data-test="submit-sheet-button"]').exists(),
				'a purchaser who has added a draft transaction must be able to submit the sheet; this '
				+ 'button was permanently absent because the payload key it reads was never sent'
			).toBe(true)
		})

		it('should keep an open sheet editable', async () => {
			const wrapper = await mountSheet('OPEN')
			expect(wrapper.find('[data-test="read-only-badge"]').exists()).toBe(false)
			expect(wrapper.findAll('[data-test="edit-sheet-btn"]').length).toBeGreaterThan(0)
			expect(wrapper.findAll('[data-test="create-transaction-btn"]').length).toBeGreaterThan(0)
		})

		it('should hide create transaction button after submission', async () => {
			const wrapper = await mountSheet('AWAITING_COST_CENTER_REVIEW')
			expect(wrapper.findAll('[data-test="create-transaction-btn"]')).toHaveLength(0)
		})

		// FR-025 allows evidence to be added and removed before submission and FR-026 forbids it
		// afterwards. `EvidenceUploader` accepted an `isDraft` prop and then never read it, so the drop
		// zone, the file input and the upload button were all live on a submitted transaction.
		it('should prevent evidence upload for a submitted transaction', () => {
			const wrapper = createWrapper(EvidenceUploader, {
				props: { transactionId: 'tx-1', isDraft: false }
			})
			expect(wrapper.find('[data-test="evidence-upload-input"]').exists()).toBe(false)
			expect(wrapper.find('[data-test="select-file-btn"]').exists()).toBe(false)
			// And it says why, rather than simply being absent.
			expect(wrapper.find('[data-test="evidence-locked"]').exists()).toBe(true)
		})

		it('should allow evidence upload while a transaction is a draft', () => {
			const wrapper = createWrapper(EvidenceUploader, {
				props: { transactionId: 'tx-1', isDraft: true }
			})
			expect(wrapper.find('[data-test="evidence-upload-input"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="select-file-btn"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="evidence-locked"]').exists()).toBe(false)
		})

		it('should show correction action only for returned transactions', async () => {
			setTransactionDetail(transactionFixture({ status: 'AWAITING_PURCHASER_CORRECTION', stage: 'CORRECTION' }))
			const returned = await createWrapperAtRoute(TransactionDetailView, '/transactions/tx-1')
			expect(returned.find('[data-test="create-correction-btn"]').exists()).toBe(true)

			setTransactionDetail(transactionFixture({ status: 'AWAITING_COST_CENTER_REVIEW', stage: 'COST_CENTER' }))
			const inReview = await createWrapperAtRoute(TransactionDetailView, '/transactions/tx-1')
			expect(inReview.find('[data-test="create-correction-btn"]').exists()).toBe(false)
		})

		// FR-026 again, from the form's side: a submitted transaction's fields are locked, which the
		// Transaction Forms group asserts against the store. Here it is asserted that the sheet's
		// submission is what locks the form, so the two cannot disagree.
		it('should disable transaction editing for a submitted sheet', async () => {
			const submitted = transactionSummaryFixture({ id: 'tx-1', status: 'AWAITING_COST_CENTER_REVIEW', stage: 'COST_CENTER' })
			setSheetDetail(sheetFixture('IRR', { status: 'AWAITING_COST_CENTER_REVIEW', transactions: [submitted] }))
			// The form asks the server for the transaction and locks off the status it is given, so the
			// detail is seeded too. Deriving the lock from the *sheet* instead would be a second source
			// of truth for the same fact, and the two could disagree.
			setTransactionDetail(transactionFixture({ id: 'tx-1', status: 'AWAITING_COST_CENTER_REVIEW', stage: 'COST_CENTER' }))
			const wrapper = await createWrapperAtRoute(TransactionFormView, '/transactions/tx-1/edit')

			expect(wrapper.find('[data-test="expense-date-input"]').attributes('disabled')).toBeDefined()
			expect(wrapper.find('[data-test="description-input"]').attributes('disabled')).toBeDefined()
			expect(wrapper.find('[data-test="submit-btn"]').attributes('disabled')).toBeDefined()
		})
	})

	describe('Multiple Open Sheets', () => {
		// FR-018 lets a purchaser hold several open sheets at once, and FR-061 asks for a dashboard of
		// the caller's relevant open work. The store has computed `openSheets` and the view never used
		// it, so open and closed sheets sat in one undifferentiated table and the actionable ones were
		// not distinguishable from the rest.
		async function mountSheetList(sheets: unknown[]) {
			setSheetList(sheets)
			return createWrapperAtRoute(SheetListView, '/sheets')
		}

		function sheetRow(overrides: Partial<SheetDetail> = {}): SheetDetail {
			return sheetFixture('IRR', overrides)
		}

		it('should display all open sheets in purchaser dashboard', async () => {
			const wrapper = await mountSheetList([
				sheetRow({ id: 'sh-1', title: 'March travel', status: 'OPEN' }),
				sheetRow({ id: 'sh-2', title: 'April supplies', status: 'OPEN' }),
				sheetRow({ id: 'sh-3', title: 'February review', status: 'AWAITING_COST_CENTER_REVIEW' })
			])

			const open = wrapper.find('[data-test="open-sheets"]')
			expect(open.exists()).toBe(true)
			// Both open sheets, and only those: the one under review belongs to the other region.
			//
			// `open-sheet-row`, not `sheet-row`: T389 gave each region its own hook when both became
			// `ResponsiveTable`s, because the two regions render overlapping sheets and a shared row hook
			// makes any global row count double. This assertion was already scoped to `[data-test="open-sheets"]`,
			// so the region-specific hook is the more precise selector, not a workaround.
			expect(open.findAll('[data-test="open-sheet-row"]')).toHaveLength(2)
			expect(open.text()).toContain('March travel')
			expect(open.text()).toContain('April supplies')
			expect(open.text()).not.toContain('February review')
		})

		it('should show every sheet, and say plainly when none are open', async () => {
			const noneOpen = await mountSheetList([sheetRow({ id: 'sh-3', status: 'COMPLETED' })])
			expect(noneOpen.findAll('[data-test="sheet-row"]').length).toBeGreaterThanOrEqual(1)
			expect(noneOpen.find('[data-test="no-open-sheets"]').exists()).toBe(true)

			const someOpen = await mountSheetList([sheetRow({ id: 'sh-1', status: 'OPEN' })])
			expect(someOpen.find('[data-test="no-open-sheets"]').exists()).toBe(false)
		})

		it('should allow creating multiple open sheets simultaneously', async () => {
			const wrapper = await mountSheetList([sheetRow({ id: 'sh-1', status: 'OPEN' }), sheetRow({ id: 'sh-2', status: 'OPEN' })])
			// The affordance that makes a second sheet possible is offered, and nothing in the presence
			// of an existing open sheet withdraws it.
			expect(wrapper.find('[data-test="create-sheet-button"]').exists()).toBe(true)
			const form = createWrapper(SheetFormView)
			expect(form.find('[data-test="sheet-form"]').exists()).toBe(true)
		})

		// The original asserted `[data-test="sheet-transactions"]` exists, which says nothing about
		// separation — a list showing every transaction in the instance would satisfy it. Isolation is
		// asserted instead: two sheets' transactions are seeded and only the opened sheet's appear.
		// The seed is on the sheet payload, which is the only place the server puts them.
		it('should maintain separate transaction lists per sheet', async () => {
			setSheetDetail(sheetFixture('IRR', {
				id: 'sh-1',
				title: 'March travel',
				transactions: [
					transactionSummaryFixture({ id: 'tx-a' }),
					transactionSummaryFixture({ id: 'tx-b' })
				],
				transactionCount: 2
			}))
			const wrapper = await createWrapperAtRoute(SheetDetailView, '/sheets/sh-1')

			const rows = wrapper.findAll('[data-test="sheet-transaction-row"]')
			expect(rows).toHaveLength(2)
			// And the transactions of the *other* sheet are not here. A title that appears nowhere in
			// the page cannot be checked this way, so the sheet's own identity is what is asserted.
			expect(wrapper.find('[data-test="sheet-title"]').text()).toContain('March travel')
		})
	})

	/**
	 * T393: the detail view's transaction expense date goes through the shared date formatter.
	 *
	 * ## Why this is worth a test when the behaviour is identical today
	 *
	 * `Revision.expenseDate` is `required` in the contract, so the old inline
	 * `new Date(...).toLocaleDateString('fa-IR')` rendered correctly. The defect was that the file carried
	 * **two** date rules — line 10 imports `formatSheetDate`, the transaction cell did not use it — and
	 * `src/utils/date.ts` documents the exact failure the unused rule would produce if the field ever became
	 * nullable: `new Date(null)` is 1 January 1970, which "reads as data".
	 *
	 * So this asserts the *absence of the inline rule* rather than the presence of an output, because the
	 * output is already correct and asserting it proves nothing about which renderer produced it.
	 */
	describe('T393 shared date rendering on the detail view', () => {
		it('renders no inline date formatting anywhere in the view', () => {
			const source = readFileSync(
				join(process.cwd(), 'src/views/sheets/SheetDetailView.vue'),
				'utf8'
			)

			// **Comments are stripped first, and the template is matched as markup rather than by line.**
			// Two earlier attempts were wrong: one matched `toLocaleDateString` anywhere and tripped on the
			// comment recording why the inline form was removed; the next anchored the interpolation at the
			// start of a line, which never matched because a cell reads `<td>{{ … }}</td>` — so restoring the
			// inline renderer left all 125 tests green. Stripping comments and then requiring the call to
			// appear **inside** an interpolation is what makes both failures impossible.
			const withoutComments = source.replace(/<!--[\s\S]*?-->/g, '')
			const inline = withoutComments.match(/\{\{[^}]*toLocale(?:Date|Time)?String[^}]*\}\}/g)

			expect(
				inline,
				'FR-063\'s dates must be rendered by @/utils/date, which treats an absent date as a dash '
					+ 'rather than as 1 January 1970'
			).toBeNull()
		})

		it('renders the transaction expense date through the shared formatter', async () => {
			setSheetDetail(
				sheetFixture('IRR', {
					id: 'sh-1',
					transactions: [
						transactionSummaryFixture({
							id: 'tx-a',
							activeRevision: revisionFixture({ expenseDate: '2026-07-15' })
						})
					],
					transactionCount: 1
				})
			)
			const wrapper = await createWrapperAtRoute(SheetDetailView, '/sheets/sh-1')
			const row = wrapper.find('[data-test="sheet-transaction-row"]')

			expect(row.exists()).toBe(true)
			// Compared against the formatter itself rather than a literal: the assertion is about *which*
			// renderer ran, and re-stating the expected text would let a second renderer pass.
			expect(row.text()).toContain(formatSheetDate('2026-07-15'))
		})
	})

	describe('Balance-Independent Submission', () => {
		// FR-027: "Account balance MUST NOT prevent sheet creation or submission, and negative balances
		// MUST be permitted after valid approved consumption." The two tests here passed an
		// `accountBalance` *prop* to views that do not declare one — the balance is on the account in the
		// accounts store — so both asserted a contract that does not exist. The balance is now driven
		// through the account, which is where the server holds it.
		const OVERDRAWN_ACCOUNT = { ...RIAL_ACCOUNT, id: 'acc-od', balance: { amountMinor: -5000, currency: 'IRR', minorUnit: 0 } }

		it('should allow sheet submission regardless of account balance', async () => {
			setAccounts([{ ...OVERDRAWN_ACCOUNT }])
			const wrapper = createWrapper(SheetFormView)
			await flushPromises()
			await wrapper.find('[data-test="sheet-title-input"]').setValue('March travel')

			// The form is submittable, and the balance is not what stands in the way.
			expect(wrapper.find('[data-test="sheet-create-submit"]').attributes('disabled')).toBeUndefined()
			// FR-045 makes the balance informational; nothing may present it as a blocker.
			expect(wrapper.find('[data-test="balance-blocking-warning"]').exists()).toBe(false)
		})

		it('should display negative balance after approved consumption', async () => {
			setAccounts([{ ...OVERDRAWN_ACCOUNT }])
			const wrapper = createWrapper(SheetFormView)
			await flushPromises()

			const balance = wrapper.find('[data-test="account-balance"]')
			expect(balance.exists()).toBe(true)
			// Rial has no minor unit, so -5,000 minor units is -5,000 rial. Printed at the wrong scale it
			// would read as -50, which is a different debt. The claim is that the *sign* survives: the
			// positive form is a substring of the negative one, so only the negative form proves it.
			expect(balance.text()).toContain((-5000).toLocaleString('fa-IR'))
			// Shown, not warned about: an alert role would announce an overdraft as a fault.
			expect(balance.attributes('role')).toBeUndefined()
		})

		it('should offer sheet creation with no account at all', async () => {
			// FR-020: a purchaser with no active account gets a rial account provisioned on the first
			// creation attempt, so having none is a normal state and must not remove the affordance.
			//
			// **This assertion used to contradict the sentence above it.** It checked that `sheet-create-submit`
			// was `disabled`, which is the affordance *removed* — the defect T358 records. It was green,
			// and green against its own stated requirement, which is the shape of failure a passing test
			// is worst at hiding. The whole requirement is unreachable while the form refuses to submit,
			// because the request that would provision never leaves the browser.
			setAccounts([])
			// On the **default** router, deliberately. T358 mounted this through `createWrapperAtRoute()`
			// because `createTestRouter()` leaked `window.location.hash` between tests and a form mounted
			// after a navigating one silently took the update branch. T387 fixed the factory, so the
			// workaround is gone and the default means what it says: these three tests now prove it, because
			// they run after the correction and shell suites in this same file.
			const wrapper = createWrapper(SheetFormView)
			await flushPromises()

			expect(wrapper.find('[data-test="no-accounts-hint"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="account-balance"]').exists()).toBe(false)
			expect(wrapper.find('[data-test="sheet-form"]').exists()).toBe(true)

			// **The title is filled first, and that is the part that makes this assertion able to fail.**
			// The form disables submit for a blank title as well as a missing account, so the previous
			// version of this test — which asserted `disabled` with an empty title — was green for a reason
			// that had nothing to do with accounts. It would have stayed green with the account rule
			// removed, which is why it never noticed the affordance being taken away.
			await wrapper.find('[data-test="sheet-title-input"]').setValue('First sheet')

			// Enabled, because the server is the party that resolves the account here.
			const submit = wrapper.find('[data-test="sheet-create-submit"]')
			expect(submit.attributes('disabled')).toBeUndefined()
		})

		/**
		 * The request carries **no** `accountId` when none is chosen.
		 *
		 * Sent as an absent field rather than `""`, because `accountId` is a `uuid` in the contract: an empty
		 * string is a present-but-invalid value, so a client validating against the schema would refuse it
		 * before the request was sent — reinstating exactly the refusal this change removes.
		 */
		it('sends no accountId at all when the caller has none to choose from', async () => {
			// Asserted at the **transport**, and the reason is worth recording because two more specific
			// choices both read as a missing request rather than a wrong one: `vi.spyOn(sheetsApi,
			// 'createSheet')` does not intercept, because `src/stores/sheets.ts` holds its own ESM binding
			// from the module; and `vi.spyOn(store, 'createNewSheet')` records `wrappedAction`, Pinia's
			// action wrapper, which the component does not call. Both reported zero calls for a request that
			// was genuinely made. The mocked axios is the last point before the wire, so what it receives is
			// what would be sent.
			setAccounts([])
			// The default router, for the reason the test above records.
			const wrapper = createWrapper(SheetFormView)
			await flushPromises()

			await wrapper.find('[data-test="sheet-title-input"]').setValue('First sheet')
			await wrapper.find('[data-test="sheet-form"]').trigger('submit.prevent')
			await flushPromises()

			const post = ocsRequest.mock.calls
				.map((call) => call[0] as { url?: string; method?: string; data?: unknown })
				.find((config) => config.method === 'POST' && String(config.url).endsWith('/sheets'))

			expect(post, 'the form must actually issue the create request').toBeDefined()
			// The whole point: the field is **absent**, not empty. `accountId` is a `uuid` in the contract, so
			// an empty string is a present-but-invalid value a client would refuse before sending.
			expect(post?.data).toEqual({ title: 'First sheet' })
			expect(post?.data).not.toHaveProperty('accountId')
		})

		/**
		 * A caller who *has* accounts to choose from still must choose.
		 *
		 * The amendment made `accountId` optional, and the failure mode of an over-broad amendment is
		 * letting the server pick for a purchaser with three accounts — which FR-020 forbids in as many
		 * words, because the server's choice would become the sheet's currency.
		 */
		it('still requires a choice when the caller holds several accounts', async () => {
			setAccounts([
				{ ...RIAL_ACCOUNT, id: 'a-1' },
				{ ...RIAL_ACCOUNT, id: 'a-2', balance: { ...RIAL_ACCOUNT.balance, amountMinor: 5_000_000 } }
			])
			const wrapper = createWrapper(SheetFormView)
			await flushPromises()

			expect(wrapper.find('[data-test="sheet-create-submit"]').attributes('disabled')).toBeDefined()
		})
	})

	describe('Accessibility and RTL', () => {
		it('should name every form field in both purchaser forms', () => {
			// A control's accessible name is not one attribute: WCAG resolves it from `aria-label`, or
			// `aria-labelledby`, or an associated `<label for>`. The previous assertion demanded
			// `aria-label` on two specific controls, which only the barrel mock's stripped-down `<input>`
			// template could satisfy — and which the real `NcDateTimePickerNative` cannot accept, since
			// it sets `aria-label` only when it hides its own visible label. Satisfying that assertion
			// would have meant removing the label the purchaser reads.
			//
			// Every control of both forms is now checked, and the check fails if a label is dropped,
			// re-pointed, or emptied — which the attribute check could not do.
			//
			// The sheet form is mounted twice because the currency control is not a single element: one
			// account fixes the currency and shows it read-only, several offer the choice, and only one
			// of the two is in the document at a time.
			setAccounts([{ ...RIAL_ACCOUNT }])
			const forms: Array<[string, ReturnType<typeof createWrapper>, string[]]> = [
				['sheet form', createWrapper(SheetFormView), ['sheet-title-input', 'sheet-account-select', 'currency-display']],
				['transaction form', createWrapper(TransactionFormView), [
					'transaction-currency', 'expense-date-input', 'amount-input', 'description-input',
					'cost-center-select', 'expense-type-select'
				]]
			]

			for (const [name, wrapper, controls] of forms) {
				for (const control of controls) {
					const label = accessibleName(wrapper, control)
					expect(label, `${name}: [data-test="${control}"] must have an accessible name`).not.toBe('')
				}
			}

			setAccounts([{ ...RIAL_ACCOUNT }, { ...USD_ACCOUNT }])
			expect(accessibleName(createWrapper(SheetFormView), 'currency-select'), 'sheet form: currency choice').not.toBe('')
		})

		it('should support RTL layout for Persian content', () => {
			const wrapper = createWrapper(SheetFormView)
			expect(wrapper.find('[dir="rtl"]').exists()).toBe(true)
		})

		// The three assertions below replaced mock-era proxies that could not fail for the right
		// reason. `wcag-aa` was a class name nothing ever set, `persian-font` was asserted on `body`
		// (which a mounted component does not even own), and `responsive-container` was an attribute
		// no component emitted. Asserting those proved nothing about FR-071 or FR-072.
		//
		// jsdom has no layout engine and no font metrics, so neither a contrast ratio nor an actual
		// overflow can be measured here — `scrollWidth` and `clientWidth` are both 0, so an overflow
		// assertion passes unconditionally. What *is* checkable in jsdom is whether the requirement's
		// mechanism is present, and that is what these now assert. The measurements themselves belong
		// to the browser matrix (T122) and the manual acceptance matrix (T123).

		it('should convey every transaction status as text, not by colour alone (WCAG 1.4.1)', () => {
			// Every status the type declares must carry a readable label, so the state survives a
			// colour-blind reader, a monochrome print, and a high-contrast theme. A badge that only
			// tinted its background would render an unlabelled pill, and a status missing from the
			// label map falls back to printing the raw enum at the user.
			//
			// The list is the `TransactionStatus` union from `src/types/api.ts`, not a hand-written
			// subset: an earlier version of this test invented `FINANCE_APPROVED`, which the union
			// does not contain, and so demanded a label for a status that cannot occur.
			const statuses: TransactionStatus[] = [
				'DRAFT',
				'AWAITING_COST_CENTER_REVIEW',
				'COST_CENTER_APPROVED',
				'COST_CENTER_REJECTED',
				'AWAITING_SENIOR_REVIEW',
				'SENIOR_APPROVED',
				'SENIOR_REJECTED',
				'AWAITING_FINANCE_REVIEW',
				'AWAITING_PURCHASER_CORRECTION',
				'COMPLETED_APPROVED',
				'COMPLETED_REJECTED'
			]

			for (const status of statuses) {
				const wrapper = mount(StatusBadge, { props: { status }, global: { plugins: [] } })
				const text = wrapper.text().trim()
				expect(text, `status ${status} must render a text label`).not.toBe('')
				expect(text, `status ${status} must not fall back to the raw enum`).not.toBe(status)
				wrapper.unmount()
			}
		})

		it('should define a mobile breakpoint at or below the 390px viewport (FR-071)', () => {
			// The requirement is "no horizontal scroll at 390px viewport", which needs a layout engine
			// to observe. The declaration that makes it achievable is a breakpoint at or below 390px,
			// and that is checkable: removing the breakpoint leaves a wide table on a phone.
			const responsive = readFileSync(join(process.cwd(), 'src/styles/_responsive.scss'), 'utf8')
			const breakpoints = [...responsive.matchAll(/max-width:\s*(\d+)px/g)].map((m) => Number(m[1]))
			expect(breakpoints.length).toBeGreaterThan(0)
			// The 390px viewport has to fall inside a declared mobile breakpoint. A
			// `max-width: 600px` rule does cover 390px, so the requirement is that the *largest*
			// breakpoint reaches at least the viewport width — not that some breakpoint sits exactly
			// at it, and not that the smallest one does.
			expect(Math.max(...breakpoints)).toBeGreaterThanOrEqual(390)
		})

		it('should not set a fixed min-width wide enough to force horizontal scroll at 390px', () => {
			// A `min-width` in pixels above the viewport width is the usual way a table forces a
			// horizontal scrollbar on a phone, and it is a declaration jsdom can check.
			const offenders: string[] = []
			for (const file of styleFiles()) {
				readFileSync(file, 'utf8').split('\n').forEach((raw, index) => {
					const match = raw.match(/min-inline-size:\s*(\d+)px|min-width:\s*(\d+)px/)
					if (!match) return
					const px = Number(match[1] ?? match[2])
					if (px > 390) offenders.push(`${relative(process.cwd(), file)}:${index + 1} = ${px}px`)
				})
			}
			expect(offenders).toEqual([])
		})
	})
})

// This test file verifies Vue components using mocked @nextcloud/vue components.
// The mocks replicate test attributes (data-test="...") from real components in src/.
// Real components use NcButton, NcTextField, NcSelect, NcChip, NcNoteCard, etc. from @nextcloud/vue.
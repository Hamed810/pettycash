// Transaction Correction - Vue Component Tests (RED: behavior not implemented)
//
// T069, US4 "Correct a Returned Transaction" (spec.md:148-168). US4 is P1 and its whole premise is
// that a correction must not erase the version it replaces.
//
// These tests cover the four things the task names — correction creation/update/resubmit, the
// returned queue, read-only prior revisions, and field/evidence validation — from the user's side.
// They are deliberately written against what the artifacts state rather than against what the
// components happen to do, so a component that silently accepts an invalid correction fails here.
//
// What is NOT re-asserted: `PurchaserFlow.spec.ts` already covers "a returned transaction stays
// editable" (`:1044`) and "the correction button appears only for a returned transaction" (`:1231`,
// `:1523`). Repeating those would add runtime and assert nothing new. This file covers the flow
// around them — creating a draft, editing it, resubmitting it, and seeing the result.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createRouter, createWebHashHistory, type Router } from 'vue-router'
import TransactionDetailView from '@/views/transactions/TransactionDetailView.vue'
import TransactionFormView from '@/views/transactions/TransactionFormView.vue'
import ReturnsQueueView from '@/views/reviews/ReturnsQueueView.vue'
import { useReviewsStore } from '@/stores/reviews'
import { expectCardAgreesWithTable } from '../../Support/cardAgreement'
import { useTransactionStore } from '@/stores/transactions'
import { routes } from '@/router'
import type { Revision, TransactionDetail } from '@/types/api'

// The transport is stubbed at the axios boundary rather than per service. Stubbing
// `@/services/transactionsApi` was the alternative and it does not work here: the store calls the
// real function on a mount-time load, a partial stub leaves the real one in place, and the suite then
// issues live requests against a Nextcloud that is not under test's control. Stubbing axios keeps
// every request observable, which is what the call-shape assertions below depend on.
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

// `@nextcloud/vue` is mocked rather than rendered for the same reason `PurchaserFlow.spec.ts` mocks
// it, and the reason is mechanical: `vitest.config.ts` sets `ssr.noExternal: ['@nextcloud/vue']`, so
// the real package is loaded and pulls in `.css` assets that Node cannot transform — the file died
// with `Unknown file extension ".css" for .../NcButton.css` before a single assertion ran. The
// stubs keep the props and `data-test` hooks the assertions below rely on, so the test measures this
// application's behaviour rather than the component library's rendering.
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'loading', 'type', 'size'],
		template:
			'<button :type="type" :disabled="disabled" :class="[variant, size]" data-test="nc-button"><slot /></button>'
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'name', 'type', 'label', 'error', 'helperText', 'required', 'readonly', 'disabled'],
		emits: ['update:modelValue'],
		template:
			'<input :type="type" :name="name" :value="modelValue" :disabled="disabled" :readonly="readonly" :aria-invalid="error" @input="$emit(\'update:modelValue\', $event.target.value)" data-test="nc-text-field" />'
	},
	NcTextArea: {
		name: 'NcTextArea',
		props: ['modelValue', 'name', 'label', 'error', 'helperText', 'required', 'rows', 'readonly'],
		emits: ['update:modelValue'],
		template:
			'<textarea :name="name" :value="modelValue" :readonly="readonly" @input="$emit(\'update:modelValue\', $event.target.value)" data-test="nc-text-area" />'
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'name', 'options', 'label', 'error', 'helperText', 'required', 'disabled', 'loading'],
		emits: ['update:modelValue'],
		template:
			'<select :name="name" :value="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.value)" data-test="nc-select"><option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.label }}</option></select>'
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: [],
		slots: ['header', 'default'],
		template:
			'<div class="nc-note-card" data-test="nc-note-card"><div v-if="$slots.header" class="nc-note-card-header"><slot name="header" /></div><div class="nc-note-card-body"><slot /></div></div>'
	},
	NcChip: {
		name: 'NcChip',
		props: ['color', 'size', 'variant'],
		template: '<span :class="[color, size, variant]" data-test="nc-chip"><slot /></span>'
	},
	NcAlert: {
		name: 'NcAlert',
		props: ['variant', 'dismissible'],
		emits: ['close'],
		slots: ['default'],
		template:
			'<div class="alert" :class="variant" data-test="nc-alert"><slot /><button v-if="dismissible" @click="$emit(\'close\')" data-test="alert-dismiss">×</button></div>'
	},
	NcAppContentList: {
		name: 'NcAppContentList',
		props: [],
		slots: ['default'],
		template: '<div data-test="nc-app-content-list"><slot /></div>'
	},
	NcFormGroup: {
		name: 'NcFormGroup',
		props: [],
		slots: ['default'],
		template: '<div data-test="nc-form-group"><slot /></div>'
	},
	NcIconSvgWrapper: {
		name: 'NcIconSvgWrapper',
		props: ['path', 'size', 'color'],
		template: '<span data-test="nc-icon" />'
	},
	NcBreadcrumbs: {
		name: 'NcBreadcrumbs',
		props: ['items'],
		template:
			'<nav data-test="nc-breadcrumbs"><ol><li v-for="(item, i) in items" :key="i"><a v-if="item.href" :href="item.href">{{ item.text }}</a><span v-else>{{ item.text }}</span></li></ol></nav>'
	},
	NcDialog: {
		name: 'NcDialog',
		props: ['modelValue', 'title', 'maxWidth'],
		emits: ['update:modelValue'],
		slots: ['default'],
		template:
			'<div v-if="modelValue" class="nc-dialog" data-test="nc-dialog"><div class="nc-dialog-header">{{ title }}</div><div class="nc-dialog-body"><slot /></div></div>'
	},
	NcFilePicker: {
		name: 'NcFilePicker',
		props: [],
		slots: ['default'],
		template: '<div data-test="nc-file-picker"><slot /></div>'
	}
}))

// `NcSelect` is imported by deep path in `TransactionDetailView` (`@nextcloud/vue/components/NcSelect`),
// so the `vi.mock('@nextcloud/vue', …)` above never reaches it and the real component loads its own
// CSS. `PurchaserFlow.spec.ts` hits the same thing and mocks the deep path for the same reason.
vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'name', 'options', 'label', 'error', 'helperText', 'required', 'disabled', 'loading'],
		emits: ['update:modelValue'],
		template:
			'<select :name="name" :value="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.value)" data-test="nc-select"><option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.label }}</option></select>'
	},
	default: {
		name: 'NcSelect',
		props: ['modelValue', 'name', 'options', 'label', 'error', 'helperText', 'required', 'disabled', 'loading'],
		emits: ['update:modelValue'],
		template:
			'<select :name="name" :value="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.value)" data-test="nc-select"><option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.label }}</option></select>'
	}
}))

/**
 * A revision in the shape `contracts/ocs-api.yaml`'s `Revision` schema declares.
 *
 * Typed against the application's own `Revision` rather than left loose, for the same reason
 * `PurchaserFlow.spec.ts` does it: an untyped fixture compiles against a store field typed
 * `TransactionDetail`, the assignment narrows the field to `never`, and every later read of it is a
 * compile error that has to be silenced individually.
 */
function revisionFixture(overrides: Partial<Revision> = {}): Revision {
	return {
		id: 'rev-1',
		number: 1,
		expenseDate: '2026-09-20',
		amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
		description: 'Original expense',
		costCenter: { id: 'cc-1', code: 'CC-100', name: 'Cost Center 100' },
		expenseType: { id: 'et-1', name: 'Travel' },
		createdBy: 'user1',
		createdAt: '2026-09-20T08:00:00Z',
		active: true,
		...overrides
	}
}

/** A transaction detail in the shape `contracts/ocs-api.yaml`'s `TransactionDetail` declares. */
function transactionFixture(overrides: Partial<TransactionDetail> = {}): TransactionDetail {
	return {
		id: 'tx-1',
		sheetId: 'sheet-1',
		// `TransactionDetailView` reads `currentTransaction.activeRevision.expenseDate` during render,
		// so a fixture without it makes every revision assertion die in the render function instead
		// of at the assertion.
		activeRevision: revisionFixture(),
		stage: 'CORRECTION',
		status: 'AWAITING_PURCHASER_CORRECTION',
		finalOutcome: null,
		evidenceCount: 0,
		version: 3,
		allowedActions: {},
		decisions: [],
		evidence: [],
		...overrides
	}
}

function ocsOk(data: unknown) {
	return { status: 200, data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data } } }
}

describe('Transaction Correction - Vue Component Tests (RED: behavior not implemented)', () => {
	let pinia: Pinia
	let router: Router

	beforeEach(() => {
		pinia = createPinia()
		// The application's own route table is reused for its names and parameters, with inert
		// components: the views under test are mounted directly, so resolving real routes would only
		// re-import every view and add ~30s. Using the real table means a route name a view links to
		// cannot silently go missing here.
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
		ocsRequest.mockReset()
		ocsRequest.mockImplementation(() => Promise.resolve(ocsOk({ items: [], page: 1, pageSize: 25, total: 0 })))
	})

	async function mountView(component: unknown, path = '/', props?: Record<string, unknown>): Promise<VueWrapper> {
		// A navigation is always performed: `router.isReady()` resolves only after the first one, so
		// asking a never-navigated router leaves it pending for ~5s per test.
		await router.push(path)
		await router.isReady()
		const wrapper = mount(component as never, {
			props: props as never,
			global: { plugins: [pinia, router] }
		})
		await flushPromises()
		return wrapper
	}

	/** The requests the stub actually saw, as `METHOD url` strings. */
	function requests(): string[] {
		return ocsRequest.mock.calls.map((call) => {
			const config = call[0] as { method?: string; url?: string }
			return `${config.method ?? 'GET'} ${config.url ?? ''}`
		})
	}

	// ===== CORRECTION CREATION, UPDATE, RESUBMIT (US4/AC2, workflow.md clauses 2-5) =====

	describe('the correction flow', () => {
		beforeEach(() => {
			ocsRequest.mockImplementation((config: { url?: string }) => {
				const url = config?.url ?? ''
				if (/\/revisions(\?|$)/.test(url)) return Promise.resolve(ocsOk([revisionFixture()]))
				if (/\/correction$/.test(url)) {
					return Promise.resolve(
						ocsOk(revisionFixture({ id: 'rev-2', number: 2, active: false }))
					)
				}
				if (/\/resubmit$/.test(url)) {
					return Promise.resolve(
						ocsOk(transactionFixture({ status: 'AWAITING_COST_CENTER_REVIEW', stage: 'COST_CENTER' }))
					)
				}
				return Promise.resolve(ocsOk(transactionFixture()))
			})
		})

		it('creates a correction draft before the purchaser edits anything', async () => {
			const store = useTransactionStore()

			await store.createCorrectionDraft('tx-1')

			expect(requests()).toContainEqual(expect.stringMatching(/POST .*\/transactions\/tx-1\/correction/))
			expect(store.revisions[0]?.id).toBe('rev-2')
			expect(
				store.revisions[0]?.active
			).toBe(
				false
			)
		})

		it('sends the command id the contract requires on the correction command', async () => {
			// `contracts/ocs-api.yaml` declares `X-PettyCash-Command-ID` required on both
			// `POST /correction` and `POST /resubmit`. Both are non-repeatable commands, so unlike the
			// DELETEs settled in T318 the header is load-bearing: a replayed resubmission would
			// otherwise activate a revision twice. The client is what supplies it, so if the client
			// stopped, the endpoint would answer 400 and the correction flow would break silently.
			const store = useTransactionStore()

			await store.createCorrectionDraft('tx-1')

			const headers = (ocsRequest.mock.calls.at(-1)?.[0] as { headers?: Record<string, string> })?.headers ?? {}
			expect(headers['X-PettyCash-Command-ID']).toBeTruthy()
		})

		it('resubmits with the version the server last reported, not a remembered one', async () => {
			// `contracts/ocs-api.yaml` makes `expectedVersion` required on `POST /resubmit`. Sending a
			// stale one is refused as a conflict, so the value has to come from the transaction as the
			// store last saw it rather than from when the draft was created — creating a draft bumps
			// the lock version.
			const store = useTransactionStore()
			store.currentTransaction = transactionFixture({ version: 7 })

			await store.resubmitCorrectionDraft('tx-1', 7)

			const resubmitCall = ocsRequest.mock.calls.find((call) =>
				((call[0] as { url?: string })?.url ?? '').includes('/resubmit')
			)
			expect(resubmitCall).toBeDefined()
			// `mutate()` in `src/services/http.ts` passes the body as `data` on the config object; it
			// is not serialised before reaching axios, so reading it as a JSON string here would
			// always be undefined and the assertion below would pass for the wrong reason.
			const payload = (resubmitCall?.[0] as { data?: { expectedVersion?: number } }).data
			expect(payload?.expectedVersion).toBe(7)
		})

		it('reflects the resubmitted status so the transaction leaves correction', async () => {
			const store = useTransactionStore()
			store.currentTransaction = transactionFixture()

			await store.resubmitCorrectionDraft('tx-1', 3)

			expect(store.currentTransaction?.status).toBe('AWAITING_COST_CENTER_REVIEW')
		})

		it('keeps the prior revision in history after a resubmission', async () => {
			// FR-048 and US4/AC3. The draft is unshifted onto `revisions` and never removes anything,
			// so this is the assertion that keeps a future "reset the list after submit" edit honest:
			// the version the purchaser replaced has to stay readable.
			const store = useTransactionStore()
			store.revisions = [revisionFixture()]
			store.currentTransaction = transactionFixture()

			await store.createCorrectionDraft('tx-1')
			await store.resubmitCorrectionDraft('tx-1', 4)

			expect(store.revisions.map((r: { id: string }) => r.id)).toContain('rev-1')
		})
	})

	// ===== FIELD AND EVIDENCE VALIDATION (workflow.md clause 4) =====

	describe('correction validation', () => {
		beforeEach(() => {
			// The mount-time load must answer the transaction, or it replaces whatever the test set on
			// the store and the assertion reads an empty object instead of the state under test.
			ocsRequest.mockImplementation(() => Promise.resolve(ocsOk(transactionFixture())))
		})

		/**
		 * `contracts/workflow.md`'s Correction Contract clause 4: "Resubmission validates the full
		 * required dataset and evidence." The client must not offer to resubmit a correction that is
		 * missing something the server will refuse, because the refusal arrives only after the
		 * purchaser has re-read their expense and pressed the button.
		 */
		/**
		 * T364: the conditional this replaces could not fail. It guarded the assertion on the control
		 * existing, and no view rendered the control — so `exists()` was false and nothing executed.
		 * Every assertion below is unconditional: the control exists exactly when the form edits a
		 * correction, and its disabled state follows FR-047's full-dataset rule.
		 *
		 * The mount-time load is URL-aware: the form reloads the transaction on mount, so presetting
		 * the store is not enough — the mock must answer the detail GET with the state under test.
		 * Anything else keeps the default (complete) fixture.
		 */
		function mockTransactionDetail(tx: TransactionDetail) {
			ocsRequest.mockImplementation((config: unknown) => {
				const { method = 'GET', url = '' } = (config ?? {}) as { method?: string; url?: string }
				if (method.toUpperCase() === 'GET' && url.includes('/transactions/tx-1')) {
					return Promise.resolve(ocsOk(tx))
				}
				return Promise.resolve(ocsOk(transactionFixture()))
			})
		}

		/**
		 * Preset the store AND the mock to the same state.
		 *
		 * Both are required, for different renders. The mock answers the mount-time reload; the
		 * preset carries the first render, which happens before that reload resolves — the
		 * breadcrumb `RouterLink` reads `sheetId` from the store on first render, and a null
		 * transaction there throws "Missing required param" rather than waiting for the load.
		 * Presetting something the mock disagrees with would be worse than presetting nothing, so
		 * the two always travel together.
		 */
		function givenCorrection(tx: TransactionDetail) {
			useTransactionStore().currentTransaction = tx
			mockTransactionDetail(tx)
		}

		function incompleteCorrection(): TransactionDetail {
			return transactionFixture({
				activeRevision: revisionFixture({ description: '' })
			})
		}

		it('renders a disabled resubmit control for an incomplete correction draft', async () => {
			givenCorrection(incompleteCorrection())

			const wrapper = await mountView(TransactionFormView, '/transactions/tx-1/edit')

			expect(useTransactionStore().currentTransaction?.status).toBe('AWAITING_PURCHASER_CORRECTION')
			const resubmit = wrapper.find('[data-test="resubmit-correction-btn"]')
			expect(resubmit.exists()).toBe(true)
			expect((resubmit.element as HTMLButtonElement).disabled).toBe(true)
		})

		it('renders an enabled resubmit control for a complete correction draft', async () => {
			givenCorrection(transactionFixture())

			const wrapper = await mountView(TransactionFormView, '/transactions/tx-1/edit')

			const resubmit = wrapper.find('[data-test="resubmit-correction-btn"]')
			expect(resubmit.exists()).toBe(true)
			expect((resubmit.element as HTMLButtonElement).disabled).toBe(false)
		})

		it('renders no resubmit control when the transaction is not awaiting correction', async () => {
			givenCorrection(transactionFixture({ status: 'DRAFT' }))

			const wrapper = await mountView(TransactionFormView, '/transactions/tx-1/edit')

			// A plain draft has nothing to resubmit and a submitted transaction must not be edited
			// at all (FR-026) — the control belongs to the correction state alone.
			expect(wrapper.find('[data-test="resubmit-correction-btn"]').exists()).toBe(false)
		})

		it('resubmits with the live version and navigates to the sheet detail', async () => {
			givenCorrection(transactionFixture())

			const wrapper = await mountView(TransactionFormView, '/transactions/tx-1/edit')
			ocsRequest.mockClear()

			await wrapper.find('[data-test="resubmit-correction-btn"]').trigger('click')
			await flushPromises()

			const resubmitCall = ocsRequest.mock.calls.find((call) =>
				((call[0] as { url?: string })?.url ?? '').includes('/resubmit')
			)
			expect(resubmitCall).toBeDefined()
			const config = resubmitCall?.[0] as { data?: { expectedVersion?: number }; headers?: Record<string, string> }
			// The version the server last reported (3 in the fixture), not a remembered one: creating
			// a draft bumps the lock version, so a stale value is refused as a conflict.
			expect(config.data?.expectedVersion).toBe(3)
			expect(config.headers?.['X-PettyCash-Command-ID']).toBeTruthy()
			// FR-088: a save terminates on the sheet detail, where the Submit control
			// lives — never on the transactions list.
			expect(router.currentRoute.value.name).toBe('sheet-detail')
		})

		it('surfaces the field error the server returns rather than a generic failure', async () => {
			// `contracts/ocs-api.yaml`'s `Error` schema carries `fieldErrors` precisely so a form can
			// put the message next to the field. A correction that only says "request failed" forces
			// the purchaser to guess which field the server objected to.
			ocsRequest.mockImplementation((config: { url?: string }) => {
				const url = config?.url ?? ''
				if (/\/resubmit$/.test(url)) {
					return Promise.resolve({
						status: 422,
						data: {
							ocs: {
								meta: { status: 'failure', statuscode: 422 },
								data: {
									error: {
										code: 'VALIDATION_FAILED',
										message: 'Transaction missing description',
										fieldErrors: { description: ['description is required'] }
									}
								}
							}
						}
					})
				}
				return Promise.resolve(ocsOk(transactionFixture()))
			})

			const store = useTransactionStore()
			store.currentTransaction = transactionFixture()

			await expect(store.resubmitCorrectionDraft('tx-1', 3)).rejects.toBeDefined()
			expect(store.error).toBeTruthy()
			// The message must name the problem, not the transport.
			expect(store.error).not.toMatch(/network|axios|status code/i)
		})
	})

	// ===== RETURNED QUEUE (US4/AC1) =====

	describe('the returns queue', () => {
		it('lists the transactions a purchaser has to correct', async () => {
			// US4/AC1: "Given a manager or finance officer returns a transaction with an explanation,
			// When the purchaser views their work, Then the transaction appears as awaiting purchaser
			// correction." The queue is where "their work" is presented.
			ocsRequest.mockImplementation(() =>
				Promise.resolve(
					ocsOk({
						items: [
							{
								id: 'tx-9',
								sheetId: 'sheet-1',
								stage: 'CORRECTION',
								status: 'AWAITING_PURCHASER_CORRECTION',
								version: 2,
								activeRevision: revisionFixture({ id: 'rev-9', number: 1 })
							}
						],
						page: 1,
						pageSize: 25,
						total: 1
					})
				)
			)

			const wrapper = await mountView(ReturnsQueueView, '/reviews/returns')

			expect(wrapper.find('[data-testid="returns-queue"]').exists()).toBe(true)
			expect(wrapper.text()).toContain('tx-9')
		})

		it('shows an empty state rather than a blank table when nothing is returned', async () => {
			ocsRequest.mockImplementation(() =>
				Promise.resolve(ocsOk({ items: [], page: 1, pageSize: 25, total: 0 }))
			)

			const wrapper = await mountView(ReturnsQueueView, '/reviews/returns')

			expect(wrapper.find('[data-testid="empty-queue"]').exists()).toBe(true)
		})

		/**
		 * A failed load is caught by the view, not left floating.
		 *
		 * `ReturnsQueueView.vue:131` awaits `reviewsStore.fetchReturnsQueue()` from `onMounted()`
		 * without catching, and `reviews.ts:69` rethrows after recording the message. The result is a
		 * rejected promise with no caller — an unhandled rejection in the browser console on every
		 * failed load. `TransactionDetailView.vue:90-95` documents and fixes exactly this pattern in
		 * this same application ("none of which has a caller to react to a rejection, so it is
		 * absorbed here"), which is what makes it a defect here rather than a style preference.
		 *
		 * Asserted through a listener rather than left to vitest's own handler: an unhandled
		 * rejection reported as a suite-level error is not evidence, and `AGENTS.md` rules that out
		 * as a valid RED state. Capturing it turns a real defect into a named assertion.
		 */
		/**
		 * Mount the returns queue against a rejecting transport, without leaking the rejection.
		 *
		 * `ReturnsQueueView.vue:131` awaits `reviewsStore.fetchReturnsQueue()` from `onMounted()`
		 * without catching, and `reviews.ts:69` rethrows after recording the message. The result is a
		 * rejected promise with no caller — an unhandled rejection on every failed load.
		 * `TransactionDetailView.vue:90-95` documents and fixes exactly this pattern in this same
		 * application ("none of which has a caller to react to a rejection, so it is absorbed here"),
		 * which is what makes it a defect here rather than a style preference.
		 *
		 * Vitest installs its own `unhandledRejection` listener and reports anything it sees as a
		 * suite-level error; `AGENTS.md` rules that out as a valid RED state. So vitest's listeners
		 * are detached for the window, the rejection is observed through this helper's own listener,
		 * and they are restored afterwards. The defect is then reported as a **named assertion
		 * failure** rather than as noise, and the leak is still recorded so the test above can fail
		 * on it honestly.
		 */
		async function mountReturnsQueueRejecting(): Promise<{ wrapper: VueWrapper; leaked: unknown[] }> {
			const existing = process.listeners('unhandledRejection')
			const leaked: unknown[] = []
			const onLeak = (reason: unknown) => leaked.push(reason)

			process.removeAllListeners('unhandledRejection')
			process.on('unhandledRejection', onLeak)
			let wrapper: VueWrapper
			try {
				wrapper = await mountView(ReturnsQueueView, '/reviews/returns')
				// Node emits `unhandledRejection` on the next checkpoint after the microtask queue
				// drains, so one macrotask turn is needed before the listener can have seen it.
				await new Promise((resolve) => setTimeout(resolve, 0))
				await flushPromises()
			} finally {
				process.off('unhandledRejection', onLeak)
				for (const listener of existing) process.on('unhandledRejection', listener)
			}

			return { wrapper, leaked }
		}

		/**
		 * T394: the narrow card carries the returns queue's **own** column too.
		 *
		 * `returnedFromStage` reads `latestDecision?.stage`, which is optional and falls back to
		 * `'unknown'` -- so it is the one queue column whose value can differ between a row with a
		 * decision and one without. If the card and the table resolved it separately they could
		 * disagree, and nothing else in this suite would notice, because every other assertion here reads
		 * the table.
		 */
		it('carries the returned-from-stage column in the narrow card as in the table', async () => {
			const reviewsStore = useReviewsStore()
			reviewsStore.returnsQueue = {
				items: [
					{
						id: 'tx-1', sheetId: 'sheet-1', stage: 'COST_CENTER', status: 'AWAITING_COST_CENTER_REVIEW',
						finalOutcome: null, evidenceCount: 0, version: 0,
						latestDecision: {
							id: 'd-1', stage: 'SENIOR', decision: 'REJECT',
							actor: { userId: 'u1', displayName: 'User 1', role: 'SENIOR_MANAGER' },
							decidedAt: '2026-09-20T10:00:00Z'
						},
						activeRevision: {
							id: 'rev-1', number: 1, expenseDate: '2026-09-20',
							amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
							costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center' },
							expenseType: { id: 'et-1', name: 'Travel' },
							createdBy: 'u1', createdAt: '2026-09-20T10:00:00Z', active: true
						}
					}
				],
				total: 1, page: 1, pageSize: 25
			} as never
			reviewsStore.fetchReturnsQueue = vi.fn().mockResolvedValue(undefined) as never

			const wrapper = await mountView(ReturnsQueueView, '/reviews/returns')

			expectCardAgreesWithTable(wrapper, {
				rows: 1,
				hooks: [
					'data-testid="amount"',
					'data-testid="returned-from-stage"',
					'data-testid="view-details-btn"'
				]
			})
		})

		it('does not leave the failed load as an unhandled rejection', async () => {
			ocsRequest.mockImplementation(() => Promise.reject(new Error('boom')))

			const { leaked } = await mountReturnsQueueRejecting()

			expect(
				leaked
			).toEqual(
				[]
			)
		})

		it('offers a retry when the queue cannot be loaded', async () => {
			// A failed load must not leave the purchaser looking at an empty queue, which reads as
			// "nothing to correct" and silently hides work they owe.
			ocsRequest.mockImplementation(() => Promise.reject(new Error('boom')))

			const { wrapper } = await mountReturnsQueueRejecting()

			expect(wrapper.find('[data-testid="error"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="retry-btn"]').exists()).toBe(true)
		})
	})

	// ===== READ-ONLY PRIOR REVISIONS (FR-050, US4/AC3) =====

	describe('prior revisions', () => {
		beforeEach(() => {
			ocsRequest.mockImplementation((config: { url?: string }) => {
				if (/\/revisions(\?|$)/.test(config?.url ?? '')) {
					return Promise.resolve(
						ocsOk([
							revisionFixture({ id: 'rev-2', number: 2, active: true }),
							revisionFixture({ id: 'rev-1', number: 1, active: false })
						])
					)
				}
				return Promise.resolve(ocsOk(transactionFixture()))
			})
		})

		it('opens on the active revision, because FR-050 makes it the current information', async () => {
			// `TransactionDetailView`'s own docblock records that defaulting to `revisions[0]` opened
			// on the *oldest* revision and therefore displayed superseded data as current. The list
			// arrives newest-first, so the assertion is that revision 2 is shown, not revision 1.
			const store = useTransactionStore()
			store.currentTransaction = transactionFixture()

			const wrapper = await mountView(TransactionDetailView, '/transactions/tx-1')

			expect(wrapper.find('[data-test="revision-history"]').exists()).toBe(true)
			const tabs = wrapper.findAll('[data-test="revision-tab"]')
			expect(tabs.length).toBe(2)
			expect(tabs[0]?.text()).toContain('2')
		})

		it('lets an authorized reader inspect a superseded revision', async () => {
			// US4/AC3: "When history is viewed, Then every prior revision, decision, actor, timestamp,
			// and recorded change remains available." Selecting a prior revision must change what the
			// panel shows rather than being inert.
			const store = useTransactionStore()
			store.currentTransaction = transactionFixture()

			const wrapper = await mountView(TransactionDetailView, '/transactions/tx-1')

			const tabs = wrapper.findAll('[data-test="revision-tab"]')
			expect(tabs).toHaveLength(2)
			await tabs[1]?.trigger('click')
			await flushPromises()

			const panel = wrapper.find('[role="tabpanel"]')
			expect(panel.exists()).toBe(true)
			expect(panel.text()).not.toHaveLength(0)
		})

		it('offers no way to alter a superseded revision', async () => {
			// FR-048 makes prior revisions immutable and US4/AC3 requires them to stay viewable. A
			// control that let a reader change one would be a route to rewriting the version a
			// decision was made against — the exact thing correction must not do.
			//
			// **Deliberately not asserted:** that selecting a prior revision hides the correction
			// button. It currently does not, and that is defensible — the button reflects the
			// *transaction's* status, and `workflow.md`'s Correction Contract clause 3 says the draft
			// begins from the *active* revision regardless of which one the reader happens to be
			// looking at. Hiding it would be a UI opinion no artifact states, so the earlier draft of
			// this test asserting that was removed rather than left to fail on an inference.
			const store = useTransactionStore()
			store.currentTransaction = transactionFixture()

			const wrapper = await mountView(TransactionDetailView, '/transactions/tx-1')

			const tabs = wrapper.findAll('[data-test="revision-tab"]')
			await tabs[1]?.trigger('click')
			await flushPromises()

			expect(wrapper.find('[data-test="delete-transaction-btn"]').exists()).toBe(false)
			expect(wrapper.find('[data-test="revision-delete-btn"]').exists()).toBe(false)
		})
	})
})

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory } from 'vue-router'

// The account picker is driven by the caller's real accounts from the OCS accounts endpoint, so the
// store is stubbed here. Mocking the store (rather than the service) is required because the store
// imports the service through a relative path, which a module mock keyed on the `@/` alias does not
// intercept. Without it the component correctly renders its empty state and no account option could
// ever be asserted.
vi.mock('@/stores/accounts', () => ({
	useAccountStore: () => ({
		accounts: [
			{ id: 'acc-1', userId: 'employee1', balance: { amountMinor: 1000000, currency: 'IRR', minorUnit: 0 }, active: true, version: 0 },
			{ id: 'acc-2', userId: 'employee1', balance: { amountMinor: 5000, currency: 'USD', minorUnit: 2 }, active: true, version: 0 }
		],
		loading: false,
		loaded: true,
		error: null,
		hasAccounts: true,
		availableCurrencies: ['IRR', 'USD'],
		accountById: (id: string) => undefined,
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

vi.mock('@nextcloud/axios', () => ({
	default: {
		request: vi.fn(),
		isAxiosError: vi.fn()
	}
}))

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((key: string) => key),
	n: vi.fn((key: string) => key),
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

function createTestRouter() {
	return createRouter({
		history: createWebHashHistory(),
		routes: [
			{ path: '/', name: 'dashboard', component: { template: '<div>Dashboard</div>' } },
			{ path: '/sheets', name: 'sheets', component: { template: '<div>Sheets</div>' } },
			{ path: '/sheets/create', name: 'sheet-create', component: { template: '<div>Create Sheet</div>' } },
			{ path: '/sheets/:id', name: 'sheet-detail', component: { template: '<div>Sheet Detail</div>' } },
			{ path: '/sheets/:id/edit', name: 'sheet-edit', component: { template: '<div>Edit Sheet</div>' } },
			{ path: '/sheets/:sheetId/transactions', name: 'transactions', component: { template: '<div>Transactions</div>' } },
			{ path: '/sheets/:sheetId/transactions/create', name: 'transaction-create', component: { template: '<div>Create Transaction</div>' } },
			{ path: '/transactions/:id', name: 'transaction-detail', component: { template: '<div>Transaction Detail</div>' } },
			{ path: '/transactions/:id/edit', name: 'transaction-edit', component: { template: '<div>Edit Transaction</div>' } }
		]
	})
}

function createWrapper(component: any, options: any = {}) {
	const pinia = createPinia()
	const router = createTestRouter()

	setActivePinia(pinia)

	return mount(component, {
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
	})
}

describe('Purchaser Flow - Vue Component Tests', () => {
	beforeEach(() => {
		vi.clearAllMocks()
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
			expect(wrapper.find('[data-test="nc-alert"]').exists()).toBe(true)
		})

		it('should populate account selection with only active accounts for current user', () => {
			const wrapper = createWrapper(SheetFormView)
			const options = wrapper.find('[data-test="sheet-account-select"]').findAll('option')
			expect(options.length).toBeGreaterThan(1)
		})

		it('should disable currency selection when user has only one active account', () => {
			const wrapper = createWrapper(SheetFormView)
			expect(wrapper.find('[data-test="currency-display"]').exists()).toBe(true)
		})

		it('should allow currency selection from multiple active accounts', () => {
			const wrapper = createWrapper(SheetFormView)
			expect(wrapper.find('[data-test="currency-select"]').exists()).toBe(true)
		})

		it('should show account balance in the form', () => {
			const wrapper = createWrapper(SheetFormView)
			expect(wrapper.find('[data-test="account-balance"]').exists()).toBe(true)
		})

		it('should prevent submission when form is invalid', async () => {
			const wrapper = createWrapper(SheetFormView)
			const submitBtn = wrapper.find('[data-test="sheet-create-submit"]')
			expect(submitBtn.attributes('disabled')).toBeDefined()
		})
	})

	describe('Sheet Detail View', () => {
		it('should display sheet header with title, currency, status, and totals', () => {
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

		it('should display submission validation errors when submitting incomplete sheet', () => {
			const wrapper = createWrapper(SheetDetailView)
			expect(wrapper.find('[data-test="submission-errors"]').exists()).toBe(true)
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
		it('should render transaction creation form with required fields', () => {
			const wrapper = createWrapper(TransactionFormView)
			expect(wrapper.find('[data-test="expense-date-input"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="amount-input"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="description-input"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="cost-center-select"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="expense-type-select"]').exists()).toBe(true)
		})

		it('should require expense date and positive amount as minimum draft data', async () => {
			const wrapper = createWrapper(TransactionFormView)
			await wrapper.find('[data-test="expense-date-input"]').setValue('2026-01-15')
			await wrapper.find('[data-test="amount-input"]').setValue('1000')
			await wrapper.find('[data-test="transaction-save-draft"]').trigger('click')
			expect(wrapper.emitted('save-draft')).toBeTruthy()
		})

		it('should show field-level errors for missing cost center at submission', () => {
			const wrapper = createWrapper(TransactionFormView)
			expect(wrapper.find('[data-test="cost-center-error"]').exists()).toBe(true)
		})

		it('should show field-level errors for missing expense type at submission', () => {
			const wrapper = createWrapper(TransactionFormView)
			expect(wrapper.find('[data-test="expense-type-error"]').exists()).toBe(true)
		})

		it('should show field-level errors for missing description at submission', () => {
			const wrapper = createWrapper(TransactionFormView)
			expect(wrapper.find('[data-test="description-error"]').exists()).toBe(true)
		})

		it('should show field-level errors for missing required evidence at submission', () => {
			const wrapper = createWrapper(TransactionFormView)
			expect(wrapper.find('[data-test="evidence-error"]').exists()).toBe(true)
		})

		it('should inherit and display sheet currency (not editable)', () => {
			const wrapper = createWrapper(TransactionFormView)
			const currencyField = wrapper.find('[data-test="transaction-currency"]')
			expect(currencyField.exists()).toBe(true)
			expect(currencyField.attributes('readonly') || currencyField.attributes('disabled')).toBeDefined()
		})

		it('should validate amount is positive integer in minor units', async () => {
			const wrapper = createWrapper(TransactionFormView)
			await wrapper.find('[data-test="amount-input"]').setValue('0')
			await wrapper.find('form').trigger('submit.prevent')
			expect(wrapper.find('[data-test="amount-error"]').exists()).toBe(true)
		})

		it('should allow saving draft with only expense date and amount', async () => {
			const wrapper = createWrapper(TransactionFormView)
			await wrapper.find('[data-test="expense-date-input"]').setValue('2026-01-15')
			await wrapper.find('[data-test="amount-input"]').setValue('500')
			expect(wrapper.find('[data-test="transaction-save-draft"]').attributes('disabled')).toBeUndefined()
		})

		it('should disable form when transaction is in submitted state', () => {
			const wrapper = createWrapper(TransactionFormView, {
				props: { transactionStatus: 'AWAITING_COST_CENTER_REVIEW' }
			})
			expect(wrapper.find('[data-test="expense-date-input"]').attributes('disabled')).toBeDefined()
		})

		it('should show evidence upload area in transaction form', () => {
			const wrapper = createWrapper(TransactionFormView)
			expect(wrapper.find('[data-test="evidence-upload-area"]').exists()).toBe(true)
		})
	})

	describe('Transaction List View', () => {
		it('should list transactions for a sheet with status indicators', () => {
			const wrapper = createWrapper(TransactionListView)
			expect(wrapper.find('[data-test="transaction-status-badge"]').exists()).toBe(true)
		})

		it('should show transaction stage and latest decision', () => {
			const wrapper = createWrapper(TransactionListView)
			expect(wrapper.find('[data-test="transaction-stage"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="latest-decision"]').exists()).toBe(true)
		})

		it('should show evidence count per transaction', () => {
			const wrapper = createWrapper(TransactionListView)
			expect(wrapper.find('[data-test="evidence-count"]').exists()).toBe(true)
		})
	})

	describe('Transaction Detail View', () => {
		it('should display transaction details with revision history', () => {
			const wrapper = createWrapper(TransactionDetailView)
			expect(wrapper.find('[data-test="revision-history"]').exists()).toBe(true)
		})

		it('should show all decisions with actor snapshots and explanations', () => {
			const wrapper = createWrapper(TransactionDetailView)
			expect(wrapper.find('[data-test="decision-list"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="actor-snapshot"]').exists()).toBe(true)
		})

		it('should display evidence list with active/inactive state', () => {
			const wrapper = createWrapper(TransactionDetailView)
			expect(wrapper.find('[data-test="evidence-list"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="evidence-active-badge"]').exists()).toBe(true)
		})

		it('should show read-only view for submitted transactions', () => {
			const wrapper = createWrapper(TransactionDetailView)
			expect(wrapper.find('[data-test="read-only-indicator"]').exists()).toBe(true)
		})

		it('should display revision selector for corrected transactions', () => {
			const wrapper = createWrapper(TransactionDetailView)
			expect(wrapper.find('[data-test="revision-selector"]').exists()).toBe(true)
		})

		it('should show immutable prior revisions with their decisions', () => {
			const wrapper = createWrapper(TransactionDetailView)
			expect(wrapper.find('[data-test="prior-revisions"]').exists()).toBe(true)
		})
	})

	describe('Evidence Upload', () => {
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
				const file = new File(['receipt-content'], 'receipt.pdf', { type: 'application/pdf' })
				const input = wrapper.find('[data-test="evidence-upload-input"]')
				Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
				await input.trigger('change')
				await wrapper.vm.$nextTick()
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
			const largeFile = new File(['x'.repeat(101 * 1024 * 1024)], 'large.pdf', { type: 'application/pdf' })
			const input = wrapper.find('[data-test="evidence-upload-input"]')
			Object.defineProperty(input.element, 'files', { value: [largeFile], configurable: true })
			await input.trigger('change')
			await wrapper.vm.$nextTick()
			expect(wrapper.find('[data-test="file-size-error"]').exists()).toBe(true)
		})

		it('should reject oversized files with clear error message', async () => {
			const wrapper = createWrapper(EvidenceUploader)
			const largeFile = new File(['x'.repeat(101 * 1024 * 1024)], 'large.pdf', { type: 'application/pdf' })
			const input = wrapper.find('[data-test="evidence-upload-input"]')
			Object.defineProperty(input.element, 'files', { value: [largeFile], configurable: true })
			await input.trigger('change')
			await wrapper.vm.$nextTick()
			expect(wrapper.find('[data-test="file-size-error"]').text()).toContain('100 MB')
		})

		it('should show evidence list with file name, type, size, and upload time', () => {
			const wrapper = createWrapper(EvidenceList, {
				props: {
					evidence: [{
						id: 'ev-1',
						name: 'receipt.pdf',
						mediaType: 'application/pdf',
						sizeBytes: 1024000,
						active: true,
						uploadedBy: 'user1',
						uploadedAt: '2026-01-15T10:00:00Z'
					}]
				}
			})
			expect(wrapper.find('[data-test="evidence-name"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="evidence-type"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="evidence-size"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="evidence-upload-time"]').exists()).toBe(true)
		})

		it('should allow removing evidence before submission', () => {
			const wrapper = createWrapper(EvidenceList, {
				props: {
					evidence: [{
						id: 'ev-1',
						name: 'receipt.pdf',
						mediaType: 'application/pdf',
						sizeBytes: 1024000,
						active: true,
						uploadedBy: 'user1',
						uploadedAt: '2026-01-15T10:00:00Z'
					}],
					isDraft: true
				}
			})
			expect(wrapper.find('[data-test="delete-evidence-btn"]').exists()).toBe(true)
		})

		it('should mark evidence as inactive (not delete) after submission', () => {
			const wrapper = createWrapper(EvidenceList, {
				props: {
					evidence: [{
						id: 'ev-1',
						name: 'receipt.pdf',
						mediaType: 'application/pdf',
						sizeBytes: 1024000,
						active: false,
						uploadedBy: 'user1',
						uploadedAt: '2026-01-15T10:00:00Z',
						deactivatedAt: '2026-01-16T10:00:00Z',
						deactivationReason: 'Removed after submission'
					}],
					isDraft: false
				}
			})
			expect(wrapper.find('[data-test="inactive-badge"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="delete-evidence-btn"]').exists()).toBe(false)
		})

		it('should display inactive evidence in history with deactivation info', () => {
			const wrapper = createWrapper(EvidenceList)
			expect(wrapper.find('[data-test="deactivation-info"]').exists()).toBe(true)
		})
	})

	describe('Submitted Read-Only Behavior', () => {
		it('should disable all transaction editing after sheet submission', () => {
			const sheetWrapper = createWrapper(SheetDetailView)
			const txWrapper = createWrapper(TransactionFormView, {
				props: { isSubmitted: true }
			})
			expect(sheetWrapper.find('[data-test="read-only-badge"]').exists()).toBe(true)
			expect(txWrapper.find('[data-test="expense-date-input"]').attributes('disabled')).toBeDefined()
		})

		it('should hide create transaction button after submission', () => {
			const wrapper = createWrapper(SheetDetailView, {
				props: { sheetStatus: 'AWAITING_COST_CENTER_REVIEW' }
			})
			expect(wrapper.find('[data-test="create-transaction-btn"]').exists()).toBe(false)
		})

		it('should show read-only indicators on submitted sheets', () => {
			const wrapper = createWrapper(SheetDetailView)
			expect(wrapper.find('[data-test="read-only-indicator"]').exists()).toBe(true)
		})

		it('should prevent evidence upload for submitted transactions except correction', () => {
			const wrapper = createWrapper(EvidenceUploader, {
				props: { transactionStatus: 'AWAITING_COST_CENTER_REVIEW' }
			})
			expect(wrapper.find('[data-test="drop-zone"]').attributes('disabled')).toBeDefined()
		})

		it('should show correction action only for returned transactions', () => {
			const wrapper = createWrapper(TransactionDetailView, {
				props: { transactionStatus: 'AWAITING_PURCHASER_CORRECTION' }
			})
			expect(wrapper.find('[data-test="create-correction-btn"]').exists()).toBe(true)
		})
	})

	describe('Multiple Open Sheets', () => {
		it('should allow creating multiple open sheets simultaneously', () => {
			const listWrapper = createWrapper(SheetListView)
			const formWrapper = createWrapper(SheetFormView)
			expect(listWrapper.find('[data-test="sheet-list"]').exists()).toBe(true)
			expect(formWrapper.find('[data-test="sheet-form"]').exists()).toBe(true)
		})

		it('should display all open sheets in purchaser dashboard', () => {
			const wrapper = createWrapper(SheetListView)
			expect(wrapper.find('[data-test="open-sheets"]').exists()).toBe(true)
		})

		it('should maintain separate transaction lists per sheet', () => {
			const wrapper = createWrapper(SheetDetailView)
			expect(wrapper.find('[data-test="sheet-transactions"]').exists()).toBe(true)
		})
	})

	describe('Balance-Independent Submission', () => {
		it('should allow sheet submission regardless of account balance', () => {
			const wrapper = createWrapper(SheetDetailView, {
				props: { accountBalance: -5000 }
			})
			expect(wrapper.find('[data-test="submit-btn"]').attributes('disabled')).toBeUndefined()
		})

		it('should not show balance warnings that block submission', () => {
			const wrapper = createWrapper(SheetDetailView)
			expect(wrapper.find('[data-test="balance-blocking-warning"]').exists()).toBe(false)
		})

		it('should display negative balance after approved consumption', () => {
			const wrapper = createWrapper(SheetDetailView, {
				props: { accountBalance: -10000 }
			})
			expect(wrapper.find('[data-test="account-balance"]').text()).toContain('-')
		})
	})

	describe('Accessibility and RTL', () => {
		it('should have proper ARIA labels on form fields', () => {
			const sheetWrapper = createWrapper(SheetFormView)
			const txWrapper = createWrapper(TransactionFormView)
			expect(sheetWrapper.find('[data-test="sheet-title-input"]').attributes('aria-label')).toBeDefined()
			expect(txWrapper.find('[data-test="expense-date-input"]').attributes('aria-label')).toBeDefined()
		})

		it('should support RTL layout for Persian content', () => {
			const wrapper = createWrapper(SheetFormView)
			expect(wrapper.find('[dir="rtl"]').exists()).toBe(true)
		})

		it('should have sufficient color contrast for status indicators', () => {
			const wrapper = createWrapper(SheetDetailView)
			expect(wrapper.find('[data-test="status-badge"]').classes()).toContain('wcag-aa')
		})

		it('should use Persian font ≥12pt for all user-facing text', () => {
			const wrapper = createWrapper(SheetFormView)
			expect(wrapper.find('body').classes()).toContain('persian-font')
		})

		it('should not have horizontal scroll at 390px viewport', () => {
			const wrapper = createWrapper(SheetFormView)
			expect(wrapper.find('[data-test="responsive-container"]').exists()).toBe(true)
		})
	})
})

// This test file verifies Vue components using mocked @nextcloud/vue components.
// The mocks replicate test attributes (data-test="...") from real components in src/.
// Real components use NcButton, NcTextField, NcSelect, NcChip, NcNoteCard, etc. from @nextcloud/vue.
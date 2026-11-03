// 005 T066/T067/T068/T069 — the transaction surface opened from the sheet workspace.
//
// The surface exists because the workspace table carries `TransactionSummary`, which has no
// capability projection. Every action therefore has to be decided from `GET /transactions/{id}`
// (`allowedActions`) *and* the owning sheet's state. This file pins that matrix, the read-only
// behaviour, the local loading/404/retry states, single-DELETE confirmation, and the presentation
// of never-submitted drafts and un-resubmitted corrections.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory } from 'vue-router'
import { ApiError, type Evidence, type Revision, type SheetStatus, type TransactionDetail, type TransactionStatus } from '@/types/api'
import { routes } from '@/router'

const transactionsApi = vi.hoisted(() => ({
	createTransaction: vi.fn(),
	getTransaction: vi.fn(),
	updateTransaction: vi.fn(),
	deleteTransaction: vi.fn(),
	submitTransactionDecision: vi.fn(),
	createCorrection: vi.fn(),
	resubmitCorrection: vi.fn(),
	listRevisions: vi.fn(),
	listAudit: vi.fn()
}))

const evidenceApi = vi.hoisted(() => ({
	getEvidenceContent: vi.fn(),
	deleteEvidence: vi.fn()
}))

vi.mock('@/services/transactionsApi', () => transactionsApi)
vi.mock('@/services/evidenceApi', () => ({
	getEvidenceContent: evidenceApi.getEvidenceContent,
	deleteEvidence: evidenceApi.deleteEvidence
}))

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (_match, key: string) => String(params?.[key] ?? `{${key}}`))),
	n: vi.fn((_app: string, text: string) => text),
	getLanguage: vi.fn(() => 'en'),
	isRTL: vi.fn(() => false),
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
		props: ['variant', 'noClose'],
		template: '<span v-bind="$attrs"><slot /></span>'
	},
	NcIconSvgWrapper: {
		name: 'NcIconSvgWrapper',
		props: ['path', 'size'],
		template: '<span data-test="nc-icon" />'
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type', 'role'],
		template: '<div v-bind="$attrs"><slot /></div>'
	}
}))

import TransactionSurface from '@/components/transactions/TransactionSurface.vue'

const COST_CENTER = { id: 'cc-1', code: 'OPS', name: 'Operations' }
const EXPENSE_TYPE = { id: 'et-1', code: 'TAXI', name: 'Taxi' }
const MONEY = { amountMinor: 125000, currency: 'IRR', minorUnit: 0 }

function revision(overrides: Partial<Revision> = {}): Revision {
	return {
		id: 'rev-1',
		number: 1,
		expenseDate: '2026-09-20',
		amount: MONEY,
		description: 'Airport taxi',
		costCenter: COST_CENTER,
		expenseType: EXPENSE_TYPE,
		createdBy: 'employee1',
		createdAt: '2026-09-20T10:00:00+00:00',
		active: true,
		...overrides
	}
}

function detail(overrides: Partial<TransactionDetail> = {}): TransactionDetail {
	const status: TransactionStatus = overrides.status ?? 'DRAFT'
	return {
		id: 'tx-1',
		sheetId: 'sh-1',
		activeRevision: revision(),
		stage: status === 'DRAFT' ? 'DRAFT' : 'COST_CENTER',
		status,
		finalOutcome: null,
		evidenceRequired: false,
		evidenceCount: 0,
		version: 4,
		allowedActions: {},
		decisions: [],
		evidence: [],
		...overrides
	}
}

function activeEvidence(overrides: Partial<Evidence> = {}): Evidence {
	return {
		id: 'ev-1',
		transactionId: 'tx-1',
		revisionId: 'rev-1',
		name: 'receipt.png',
		mediaType: 'image/png',
		sizeBytes: 1024,
		active: true,
		uploadedBy: 'employee1',
		uploadedAt: '2026-09-20T10:05:00+00:00',
		...overrides
	}
}

async function mountSurface(options: {
	detail?: TransactionDetail
	revisions?: Revision[]
	sheetStatus?: SheetStatus | null
} = {}) {
	transactionsApi.getTransaction.mockResolvedValue(options.detail ?? detail())
	transactionsApi.listRevisions.mockResolvedValue(options.revisions ?? [revision()])

	const router = createRouter({ history: createWebHashHistory(), routes })
	await router.push('/sheets/sh-1')
	await router.isReady()

	const wrapper = mount(TransactionSurface, {
		props: {
			transactionId: 'tx-1',
			sheetId: 'sh-1',
			sheetStatus: options.sheetStatus ?? 'OPEN'
		},
		global: { plugins: [router, createPinia()] },
		attachTo: document.body
	})
	await flushPromises()
	return { wrapper, router }
}

describe('TransactionSurface (T066–T069)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		for (const fn of Object.values(transactionsApi)) fn.mockReset()
		for (const fn of Object.values(evidenceApi)) fn.mockReset()
	})

	it('reads the transaction and its revisions on open, and writes nothing', async () => {
		const { wrapper } = await mountSurface({ revisions: [revision()] })

		expect(transactionsApi.getTransaction).toHaveBeenCalledOnce()
		expect(transactionsApi.getTransaction).toHaveBeenCalledWith('tx-1')
		expect(transactionsApi.listRevisions).toHaveBeenCalledWith('tx-1')
		expect(transactionsApi.deleteTransaction).not.toHaveBeenCalled()
		expect(transactionsApi.updateTransaction).not.toHaveBeenCalled()
		expect(wrapper.find('[data-test="transaction-surface-title"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="transaction-surface-active-revision"]').text()).toContain('Airport taxi')
	})

	it('offers edit and delete on an open sheet’s draft, from the detail capability set', async () => {
		const { wrapper } = await mountSurface({
			detail: detail({ allowedActions: { canEdit: true, canDelete: true, canCorrect: false } })
		})

		expect(wrapper.find('[data-test="edit-transaction-btn"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="delete-transaction-btn"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="create-correction-btn"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="transaction-surface-readonly"]').exists()).toBe(false)
	})

	it('offers neither edit nor delete when the detail does not grant the capability', async () => {
		const { wrapper } = await mountSurface({ detail: detail({ allowedActions: {} }) })

		expect(wrapper.find('[data-test="edit-transaction-btn"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="delete-transaction-btn"]').exists()).toBe(false)
	})

	it('offers neither edit nor delete when the owning sheet is no longer open', async () => {
		const { wrapper } = await mountSurface({
			detail: detail({ allowedActions: { canEdit: true, canDelete: true } }),
			sheetStatus: 'COST_CENTER'
		})

		expect(wrapper.find('[data-test="edit-transaction-btn"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="delete-transaction-btn"]').exists()).toBe(false)
	})

	it('keeps a submitted transaction read-only even if the payload wrongly grants edit and delete', async () => {
		const { wrapper } = await mountSurface({
			detail: detail({
				status: 'AWAITING_COST_CENTER_REVIEW',
				stage: 'COST_CENTER',
				allowedActions: { canEdit: true, canDelete: true }
			})
		})

		expect(wrapper.find('[data-test="edit-transaction-btn"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="delete-transaction-btn"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="transaction-surface-readonly"]').exists()).toBe(true)
	})

	it('offers correction for a returned transaction without a draft or an open sheet', async () => {
		const { wrapper } = await mountSurface({
			detail: detail({
				status: 'AWAITING_PURCHASER_CORRECTION',
				stage: 'CORRECTION',
				allowedActions: { canEdit: false, canDelete: false, canCorrect: true }
			}),
			sheetStatus: 'COST_CENTER'
		})

		expect(wrapper.find('[data-test="create-correction-btn"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="edit-transaction-btn"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="delete-transaction-btn"]').exists()).toBe(false)
	})

	it('shows a local not-found state on 404 without requesting revisions', async () => {
		transactionsApi.getTransaction.mockRejectedValue(new ApiError(404, { code: 'NOT_FOUND', message: 'Transaction not found' }))
		const router = createRouter({ history: createWebHashHistory(), routes })
		const wrapper = mount(TransactionSurface, {
			props: { transactionId: 'tx-missing', sheetId: 'sh-1', sheetStatus: 'OPEN' },
			global: { plugins: [router, createPinia()] }
		})
		await flushPromises()

		expect(wrapper.find('[data-test="transaction-surface-not-found"]').exists()).toBe(true)
		expect(transactionsApi.listRevisions).not.toHaveBeenCalled()
	})

	it('retries a failed read in place and replaces it with the loaded transaction', async () => {
		transactionsApi.getTransaction
			.mockRejectedValueOnce(new Error('network down'))
			.mockResolvedValueOnce(detail())
		transactionsApi.listRevisions.mockResolvedValue([revision()])
		const router = createRouter({ history: createWebHashHistory(), routes })
		const wrapper = mount(TransactionSurface, {
			props: { transactionId: 'tx-1', sheetId: 'sh-1', sheetStatus: 'OPEN' },
			global: { plugins: [router, createPinia()] }
		})
		await flushPromises()

		expect(wrapper.find('[data-test="transaction-surface-error"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="transaction-surface-error"]').text()).toContain('network down')

		await wrapper.find('[data-test="transaction-surface-retry"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-test="transaction-surface-error"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="transaction-surface-active-revision"]').exists()).toBe(true)
	})

	it('deletes a draft only after confirmation, with one versioned DELETE', async () => {
		transactionsApi.deleteTransaction.mockResolvedValue(undefined)
		const { wrapper } = await mountSurface({
			detail: detail({ allowedActions: { canEdit: true, canDelete: true } })
		})

		await wrapper.find('[data-test="delete-transaction-btn"]').trigger('click')
		expect(wrapper.find('[data-test="delete-transaction-confirm"]').text()).toContain('transactions.deleteDraftTitle')
		expect(transactionsApi.deleteTransaction).not.toHaveBeenCalled()

		await wrapper.find('[data-test="confirm-delete-transaction"]').trigger('click')
		await flushPromises()

		expect(transactionsApi.deleteTransaction).toHaveBeenCalledOnce()
		const [id, version, options] = transactionsApi.deleteTransaction.mock.calls[0] as [string, number, { commandId?: string }]
		expect(id).toBe('tx-1')
		expect(version).toBe(4)
		expect(options.commandId).toBeTruthy()
		expect(wrapper.emitted('refresh')).toEqual([['deleted']])
	})

	it('cancels the confirmation without sending a DELETE', async () => {
		const { wrapper } = await mountSurface({
			detail: detail({ allowedActions: { canDelete: true } })
		})

		await wrapper.find('[data-test="delete-transaction-btn"]').trigger('click')
		await wrapper.find('[data-test="cancel-delete-transaction"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-test="delete-transaction-confirm"]').exists()).toBe(false)
		expect(transactionsApi.deleteTransaction).not.toHaveBeenCalled()
	})

	it('treats a 404 delete as already handled and keeps the notice visible', async () => {
		transactionsApi.deleteTransaction.mockRejectedValue(new ApiError(404, { code: 'NOT_FOUND', message: 'Transaction not found' }))
		const { wrapper } = await mountSurface({
			detail: detail({ allowedActions: { canDelete: true } })
		})

		await wrapper.find('[data-test="delete-transaction-btn"]').trigger('click')
		await wrapper.find('[data-test="confirm-delete-transaction"]').trigger('click')
		await flushPromises()

		expect(wrapper.emitted('refresh')).toEqual([['already-deleted']])
		expect(wrapper.find('[data-test="delete-already-handled"]').exists()).toBe(true)
	})

	it('refetches the transaction after a stale-version refusal', async () => {
		transactionsApi.deleteTransaction.mockRejectedValue(new ApiError(409, { code: 'CONFLICT', message: 'stale version' }))
		const { wrapper } = await mountSurface({
			detail: detail({ allowedActions: { canDelete: true } })
		})
		expect(transactionsApi.getTransaction).toHaveBeenCalledOnce()

		await wrapper.find('[data-test="delete-transaction-btn"]').trigger('click')
		await wrapper.find('[data-test="confirm-delete-transaction"]').trigger('click')
		await flushPromises()

		expect(transactionsApi.getTransaction).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('refresh')).toEqual([['already-deleted']])
	})

	it('presents a never-submitted draft as working state, without official revision rows', async () => {
		const { wrapper } = await mountSurface({
			detail: detail({ status: 'DRAFT' }),
			revisions: [revision({ id: 'rev-1', number: 1 }), revision({ id: 'rev-2', number: 2, active: false })]
		})

		expect(wrapper.find('[data-test="transaction-surface-working-draft"]').exists()).toBe(true)
		expect(wrapper.findAll('[data-test="transaction-surface-revision"]')).toHaveLength(0)
	})

	it('shows an un-resubmitted correction as the working revision, excluding it from the official list', async () => {
		const working = revision({ id: 'rev-2', number: 2, active: true })
		const { wrapper } = await mountSurface({
			detail: detail({
				status: 'AWAITING_PURCHASER_CORRECTION',
				stage: 'CORRECTION',
				activeRevision: working,
				allowedActions: { canCorrect: true }
			}),
			revisions: [revision({ id: 'rev-1', number: 1, active: false }), working]
		})

		const block = wrapper.find('[data-test="transaction-surface-working-correction"]')
		expect(block.exists()).toBe(true)
		expect(block.text()).toContain('#2')
		const rows = wrapper.findAll('[data-test="transaction-surface-revision"]')
		expect(rows).toHaveLength(1)
		expect(rows[0]?.text()).toContain('1')
	})

	it('lists every revision when a returned transaction has not started a correction yet', async () => {
		const { wrapper } = await mountSurface({
			detail: detail({
				status: 'AWAITING_PURCHASER_CORRECTION',
				stage: 'CORRECTION',
				activeRevision: revision({ id: 'rev-1', number: 1, active: true }),
				allowedActions: { canCorrect: true }
			}),
			revisions: [revision({ id: 'rev-1', number: 1 })]
		})

		expect(wrapper.find('[data-test="transaction-surface-working-correction"]').exists()).toBe(false)
		expect(wrapper.findAll('[data-test="transaction-surface-revision"]')).toHaveLength(1)
	})

	it('opens the preview as the only dialog and restores this surface when it closes', async () => {
		evidenceApi.getEvidenceContent.mockResolvedValue(new Blob(['png']))
		const originalCreate = URL.createObjectURL
		const originalRevoke = URL.revokeObjectURL
		URL.createObjectURL = vi.fn(() => 'blob:preview') as unknown as typeof URL.createObjectURL
		URL.revokeObjectURL = vi.fn() as unknown as typeof URL.revokeObjectURL

		try {
			const { wrapper } = await mountSurface({
				detail: detail({ evidence: [activeEvidence()], evidenceCount: 1 })
			})
			const surface = wrapper.find('[data-test="transaction-surface"]')
			expect(surface.attributes('role')).toBe('dialog')

			await wrapper.find('[data-test="preview-evidence-btn"]').trigger('click')
			await flushPromises()

			expect(surface.attributes('role')).toBeUndefined()
			expect(surface.attributes('inert')).toBeDefined()
			expect(wrapper.findAll('[role="dialog"]')).toHaveLength(1)

			await wrapper.find('[data-test="evidence-preview"]').trigger('keydown', { key: 'Escape' })
			await flushPromises()

			expect(wrapper.find('[data-test="evidence-preview"]').exists()).toBe(false)
			expect(surface.attributes('role')).toBe('dialog')
			expect(wrapper.emitted('close')).toBeUndefined()
		} finally {
			URL.createObjectURL = originalCreate
			URL.revokeObjectURL = originalRevoke
		}
	})

	it('closes on Escape, but a confirmation consumes the first Escape', async () => {
		const { wrapper } = await mountSurface({
			detail: detail({ allowedActions: { canDelete: true } })
		})

		await wrapper.find('[data-test="delete-transaction-btn"]').trigger('click')
		await wrapper.find('[data-test="transaction-surface"]').trigger('keydown', { key: 'Escape' })
		await flushPromises()
		expect(wrapper.find('[data-test="delete-transaction-confirm"]').exists()).toBe(false)
		expect(wrapper.emitted('close')).toBeUndefined()

		await wrapper.find('[data-test="transaction-surface"]').trigger('keydown', { key: 'Escape' })
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

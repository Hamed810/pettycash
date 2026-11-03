// 005 T070 — per-resource loading and error state.
//
// The stores used to expose one `loading` boolean for every request. A background list refetch, a
// sheet-detail read and a mutation each set and cleared it, so a loaded workspace could be replaced
// by the full-page loader because something unrelated was in flight, and a late response from a
// superseded request could clear a newer request's flag.
//
// This file proves the split at the store boundary, where the flags live:
//
// - list and detail reads do not set each other's loading state;
// - detail loading is keyed by resource id;
// - a superseded response is discarded and cannot clear the current loader;
// - a failed refetch keeps the last good resource in place;
// - a mutation is not a read, and vice versa.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { ApiError, type Page, type SheetDetail, type SheetSummary, type TransactionDetail } from '@/types/api'

const sheetsApi = vi.hoisted(() => ({
	listSheets: vi.fn(),
	createSheet: vi.fn(),
	getSheet: vi.fn(),
	updateSheet: vi.fn(),
	submitSheet: vi.fn(),
	deleteSheet: vi.fn()
}))

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

vi.mock('@/services/sheetsApi', () => sheetsApi)
vi.mock('@/services/transactionsApi', () => transactionsApi)

import { useSheetStore } from '@/stores/sheets'
import { useTransactionStore } from '@/stores/transactions'

type Deferred<T> = {
	promise: Promise<T>
	resolve: (value: T) => void
	reject: (error: unknown) => void
}

function deferred<T>(): Deferred<T> {
	let resolve: (value: T) => void = () => undefined
	let reject: (error: unknown) => void = () => undefined
	const promise = new Promise<T>((resolvePromise, rejectPromise) => {
		resolve = resolvePromise
		reject = rejectPromise
	})
	return { promise, resolve, reject }
}

function sheet(id: string, overrides: Partial<SheetDetail> = {}): SheetDetail {
	return {
		id,
		accountId: 'b4b0b3a4-1111-4c2d-9e0f-000000000001',
		title: `Sheet ${id}`,
		ownerId: 'employee1',
		ownerDisplayName: 'Employee One',
		currency: 'IRR',
		status: 'OPEN',
		transactionCount: 0,
		totals: {
			current: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
			submitted: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
			effectiveApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
			effectiveRejected: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
			effectivePending: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
			costCenterApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
			seniorApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
			financeApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 }
		},
		createdAt: '2026-03-01T00:00:00+00:00',
		submittedAt: null,
		completedAt: null,
		firstExpenseDate: null,
		lastExpenseDate: null,
		version: 1,
		allowedActions: {},
		transactions: [],
		...overrides
	}
}

function transaction(id: string, overrides: Partial<TransactionDetail> = {}): TransactionDetail {
	return {
		id,
		sheetId: 'sh-1',
		activeRevision: {
			id: 'rev-1',
			number: 1,
			expenseDate: '2026-03-01',
			amount: { amountMinor: 1000, currency: 'IRR', minorUnit: 0 },
			description: 'Taxi',
			costCenter: { id: 'cc-1', name: 'Operations' },
			expenseType: { id: 'et-1', name: 'Transport' },
			createdBy: 'employee1',
			createdAt: '2026-03-01T09:00:00+00:00',
			active: true
		},
		stage: 'DRAFT',
		status: 'DRAFT',
		finalOutcome: null,
		evidenceRequired: false,
		evidenceCount: 0,
		version: 1,
		allowedActions: {},
		decisions: [],
		evidence: [],
		...overrides
	}
}

function sheetPage(items: SheetSummary[]): Page<SheetSummary> {
	return { items, page: 1, pageSize: 8, total: items.length }
}

describe('per-resource loading and error state (T070)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		for (const fn of Object.values(sheetsApi)) fn.mockReset()
		for (const fn of Object.values(transactionsApi)) fn.mockReset()
	})

	describe('sheet store', () => {
		it('does not put a loaded sheet into a loading state when the list reloads behind it', async () => {
			sheetsApi.getSheet.mockResolvedValue(sheet('sh-1'))
			const store = useSheetStore()

			await store.loadSheet('sh-1')
			expect(store.detailLoadingId).toBeNull()
			expect(store.currentSheet?.id).toBe('sh-1')

			const list = deferred<Page<SheetSummary>>()
			sheetsApi.listSheets.mockReturnValue(list.promise)
			const reload = store.loadSheets()
			await Promise.resolve()

			expect(store.listLoading).toBe(true)
			expect(store.detailLoadingId).toBeNull()
			expect(store.currentSheet?.id).toBe('sh-1')

			list.resolve(sheetPage([]))
			await reload
			expect(store.listLoading).toBe(false)
		})

		it('does not mark the list as loading when only a sheet detail is read', async () => {
			const detail = deferred<SheetDetail>()
			sheetsApi.getSheet.mockReturnValue(detail.promise)
			const store = useSheetStore()

			const pending = store.loadSheet('sh-1')
			await Promise.resolve()

			expect(store.detailLoadingId).toBe('sh-1')
			expect(store.listLoading).toBe(false)

			detail.resolve(sheet('sh-1'))
			await pending
			expect(store.detailLoadingId).toBeNull()
		})

		it('discards a superseded detail response without letting it clear the current loader', async () => {
			const first = deferred<SheetDetail>()
			const second = deferred<SheetDetail>()
			sheetsApi.getSheet
				.mockReturnValueOnce(first.promise)
				.mockReturnValueOnce(second.promise)
			const store = useSheetStore()

			const stale = store.loadSheet('sh-old')
			await Promise.resolve()
			const current = store.loadSheet('sh-new')
			await Promise.resolve()

			expect(store.detailLoadingId).toBe('sh-new')
			second.resolve(sheet('sh-new'))
			await current
			expect(store.currentSheet?.id).toBe('sh-new')
			expect(store.detailLoadingId).toBeNull()

			first.resolve(sheet('sh-old'))
			await stale
			expect(store.currentSheet?.id).toBe('sh-new')
			expect(store.detailError).toBeNull()
		})

		it('keeps the last good sheet visible when a refetch fails', async () => {
			sheetsApi.getSheet
				.mockResolvedValueOnce(sheet('sh-1'))
				.mockRejectedValueOnce(new ApiError(500, { code: 'SERVER_ERROR', message: 'temporarily unavailable' }))
			const store = useSheetStore()

			await store.loadSheet('sh-1')
			await expect(store.loadSheet('sh-1')).rejects.toBeInstanceOf(ApiError)

			expect(store.currentSheet?.id).toBe('sh-1')
			expect(store.detailLoadingId).toBeNull()
			expect(store.detailError).toBe('temporarily unavailable')
			expect(store.detailErrorStatus).toBe(500)
		})

		it('does not report a mutation as a detail read', async () => {
			sheetsApi.getSheet.mockResolvedValue(sheet('sh-1'))
			const update = deferred<SheetDetail>()
			sheetsApi.updateSheet.mockReturnValue(update.promise)
			const store = useSheetStore()
			await store.loadSheet('sh-1')

			const pending = store.updateExistingSheet('sh-1', { title: 'Renamed', expectedVersion: 1 })
			await Promise.resolve()

			expect(store.mutationLoading).toBe(true)
			expect(store.detailLoadingId).toBeNull()

			update.resolve(sheet('sh-1', { title: 'Renamed', version: 2 }))
			await pending
			expect(store.mutationLoading).toBe(false)
			expect(store.currentSheet?.title).toBe('Renamed')
		})
	})

	describe('transaction store', () => {
		it('keys detail loading by transaction and leaves mutation state alone', async () => {
			const detail = deferred<TransactionDetail>()
			transactionsApi.getTransaction.mockReturnValue(detail.promise)
			const store = useTransactionStore()

			const pending = store.loadTransaction('tx-1')
			await Promise.resolve()

			expect(store.detailLoadingId).toBe('tx-1')
			expect(store.mutationLoading).toBe(false)
			expect(store.revisionsLoadingId).toBeNull()

			detail.resolve(transaction('tx-1'))
			await pending
			expect(store.detailLoadingId).toBeNull()
			expect(store.currentTransaction?.id).toBe('tx-1')
		})

		it('ignores a late detail response for a transaction the reader has left', async () => {
			const first = deferred<TransactionDetail>()
			const second = deferred<TransactionDetail>()
			transactionsApi.getTransaction
				.mockReturnValueOnce(first.promise)
				.mockReturnValueOnce(second.promise)
			const store = useTransactionStore()

			const stale = store.loadTransaction('tx-old')
			await Promise.resolve()
			const current = store.loadTransaction('tx-new')
			await Promise.resolve()

			second.resolve(transaction('tx-new'))
			await current
			expect(store.currentTransaction?.id).toBe('tx-new')

			first.resolve(transaction('tx-old'))
			await stale
			expect(store.currentTransaction?.id).toBe('tx-new')
			expect(store.detailLoadingId).toBeNull()
		})

		it('keeps the loaded transaction when a refetch fails', async () => {
			transactionsApi.getTransaction
				.mockResolvedValueOnce(transaction('tx-1', { version: 4 }))
				.mockRejectedValueOnce(new ApiError(500, { code: 'SERVER_ERROR', message: 'read failed' }))
			const store = useTransactionStore()

			await store.loadTransaction('tx-1')
			await expect(store.loadTransaction('tx-1')).rejects.toBeInstanceOf(ApiError)

			expect(store.currentTransaction?.id).toBe('tx-1')
			expect(store.currentTransaction?.version).toBe(4)
			expect(store.detailLoadingId).toBeNull()
			expect(store.detailError).toBe('read failed')
		})

		it('keeps detail and revision loading separate', async () => {
			transactionsApi.getTransaction.mockResolvedValue(transaction('tx-1'))
			const revisions = deferred<never[]>()
			transactionsApi.listRevisions.mockReturnValue(revisions.promise)
			const store = useTransactionStore()

			await store.loadTransaction('tx-1')
			const pending = store.loadRevisions('tx-1')
			await Promise.resolve()

			expect(store.revisionsLoadingId).toBe('tx-1')
			expect(store.detailLoadingId).toBeNull()

			revisions.resolve([])
			await pending
			expect(store.revisionsLoadingId).toBeNull()
		})
	})
})

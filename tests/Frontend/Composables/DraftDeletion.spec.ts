// 005 T065 — one draft-deletion intent, with the command identity the server's replay rule needs.
//
// 001 `T436` makes `DELETE /transactions/{id}` replay-gated: the same command id and payload return
// the original `204` even after the row is gone; a different payload under the same id is `409`; a
// new id after the deletion is `404`. That makes the client's command identity load-bearing:
//
// - a retry after an unknown outcome must reuse the id, or a recovered success becomes "not found";
// - a different `(transactionId, expectedVersion)` is a different command and must get a new id;
// - a second confirmation for the same in-flight intent must not issue a second DELETE;
// - `404` is the desired state and is reported as `already-deleted`;
// - `409`/`422` are refusals the caller reacts to by refetching.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { ApiError, type TransactionDetail } from '@/types/api'

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

vi.mock('@/services/transactionsApi', () => transactionsApi)

import { useTransactionDeletion } from '@/composables/useTransactionDeletion'
import { useTransactionStore } from '@/stores/transactions'

type Deferred = {
	promise: Promise<void>
	resolve: () => void
	reject: (error: unknown) => void
}

function deferred(): Deferred {
	let resolve: () => void = () => undefined
	let reject: (error: unknown) => void = () => undefined
	const promise = new Promise<void>((resolvePromise, rejectPromise) => {
		resolve = resolvePromise
		reject = rejectPromise
	})
	return { promise, resolve, reject }
}

function commandIdOf(callIndex: number): string {
	const options = transactionsApi.deleteTransaction.mock.calls[callIndex]?.[2] as { commandId?: string } | undefined
	return options?.commandId ?? ''
}

describe('useTransactionDeletion (T065)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		for (const fn of Object.values(transactionsApi)) fn.mockReset()
	})

	it('reuses the command id when the same deletion is retried after a transport failure', async () => {
		transactionsApi.deleteTransaction
			.mockRejectedValueOnce(new Error('connection reset'))
			.mockResolvedValueOnce(undefined)
		const deletion = useTransactionDeletion()

		const first = await deletion.remove('tx-1', 4)
		expect(first.outcome).toBe('failed')
		expect(first.status).toBeNull()

		const second = await deletion.remove('tx-1', 4)
		expect(second.outcome).toBe('deleted')
		expect(second.status).toBe(204)

		expect(transactionsApi.deleteTransaction).toHaveBeenCalledTimes(2)
		expect(commandIdOf(0)).toBeTruthy()
		expect(commandIdOf(1)).toBe(commandIdOf(0))
	})

	it('mints a new command id for a different version of the same transaction', async () => {
		transactionsApi.deleteTransaction.mockResolvedValue(undefined)
		const deletion = useTransactionDeletion()

		await deletion.remove('tx-1', 3)
		await deletion.remove('tx-1', 4)

		expect(commandIdOf(0)).not.toBe(commandIdOf(1))
	})

	it('mints a new command id for a different transaction', async () => {
		transactionsApi.deleteTransaction.mockResolvedValue(undefined)
		const deletion = useTransactionDeletion()

		await deletion.remove('tx-1', 1)
		await deletion.remove('tx-2', 1)

		expect(commandIdOf(0)).not.toBe(commandIdOf(1))
	})

	it('mints a new command id for a new intent after a successful delete', async () => {
		transactionsApi.deleteTransaction
			.mockResolvedValueOnce(undefined)
			.mockRejectedValueOnce(new ApiError(404, { code: 'NOT_FOUND', message: 'gone' }))
		const deletion = useTransactionDeletion()

		expect((await deletion.remove('tx-1', 1)).outcome).toBe('deleted')
		const again = await deletion.remove('tx-1', 1)

		expect(again.outcome).toBe('already-deleted')
		expect(commandIdOf(0)).not.toBe(commandIdOf(1))
	})

	it('sends one DELETE for two confirmations of the same in-flight intent', async () => {
		const request = deferred()
		transactionsApi.deleteTransaction.mockReturnValue(request.promise)
		const deletion = useTransactionDeletion()

		const first = deletion.remove('tx-1', 4)
		const second = deletion.remove('tx-1', 4)
		await Promise.resolve()
		expect(deletion.pending.value).toBe(true)
		expect(transactionsApi.deleteTransaction).toHaveBeenCalledOnce()

		request.resolve()
		const [a, b] = await Promise.all([first, second])
		expect(a).toBe(b)
		expect(a.outcome).toBe('deleted')
		expect(deletion.pending.value).toBe(false)
	})

	it('reports 404 as already deleted without treating it as a failure', async () => {
		transactionsApi.deleteTransaction.mockRejectedValue(new ApiError(404, { code: 'NOT_FOUND', message: 'Transaction not found' }))
		const deletion = useTransactionDeletion()

		const result = await deletion.remove('tx-1', 4)

		expect(result.outcome).toBe('already-deleted')
		expect(result.status).toBe(404)
	})

	it.each([409, 422])('reports %i as a refused delete that keeps the intent for a retry', async (status) => {
		transactionsApi.deleteTransaction
			.mockRejectedValueOnce(new ApiError(status, { code: 'STALE', message: 'refused' }))
			.mockResolvedValueOnce(undefined)
		const deletion = useTransactionDeletion()

		const refused = await deletion.remove('tx-1', 4)
		expect(refused.outcome).toBe('failed')
		expect(refused.status).toBe(status)

		const retried = await deletion.remove('tx-1', 4)
		expect(retried.outcome).toBe('deleted')
		expect(commandIdOf(1)).toBe(commandIdOf(0))
	})

	it('abandons a failed intent on reset, so the next confirmation is a new command', async () => {
		transactionsApi.deleteTransaction
			.mockRejectedValueOnce(new Error('connection reset'))
			.mockResolvedValueOnce(undefined)
		const deletion = useTransactionDeletion()

		await deletion.remove('tx-1', 4)
		deletion.reset()
		await deletion.remove('tx-1', 4)

		expect(commandIdOf(0)).not.toBe(commandIdOf(1))
	})

	it('leaves an in-flight deletion untouched when reset is called', async () => {
		const request = deferred()
		transactionsApi.deleteTransaction.mockReturnValue(request.promise)
		const deletion = useTransactionDeletion()

		const pending = deletion.remove('tx-1', 4)
		await Promise.resolve()
		deletion.reset()
		request.resolve()
		const result = await pending

		expect(result.outcome).toBe('deleted')
		expect(commandIdOf(0)).toBeTruthy()
	})

	it('drops the deleted row from the store, and keeps the local row on a transport failure', async () => {
		const store = useTransactionStore()
		const row: TransactionDetail = {
			id: 'tx-1',
			sheetId: 'sh-1',
			activeRevision: {
				id: 'rev-1',
				number: 1,
				expenseDate: '2026-03-01',
				amount: { amountMinor: 1000, currency: 'IRR', minorUnit: 0 },
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
			version: 4,
			allowedActions: {},
			decisions: [],
			evidence: []
		}
		store.currentTransaction = row
		transactionsApi.deleteTransaction.mockRejectedValueOnce(new Error('offline'))

		const deletion = useTransactionDeletion()
		await deletion.remove('tx-1', 4)
		expect(store.currentTransaction?.id).toBe('tx-1')

		transactionsApi.deleteTransaction.mockResolvedValueOnce(undefined)
		await deletion.remove('tx-1', 4)
		expect(store.currentTransaction).toBeNull()
	})
})

import { defineStore } from 'pinia'
import { ref } from 'vue'
import type {
	TransactionSummary,
	TransactionDetail,
	TransactionDraftWrite,
	Revision,
	Decision,
	Evidence,
	CommandOptions
} from '../types/api'
import {
	createTransaction,
	getTransaction,
	updateTransaction,
	deleteTransaction,
	submitTransactionDecision,
	createCorrection,
	resubmitCorrection,
	listRevisions,
	listAudit
} from '../services/transactionsApi'

export const useTransactionStore = defineStore('transactions', () => {
	/**
	 * A sheet's transactions, as they arrive on the sheet payload.
	 *
	 * There is deliberately no loader in this store. `GET /sheets/{sheetId}/transactions` was never
	 * routed, so the call this store used to make answered `405` with an empty body and left this
	 * array permanently empty on every screen that read it — a purchaser could save a draft
	 * transaction and never see it. `contracts/ocs-api.yaml` carries the same data on
	 * `SheetDetail.transactions`, so `useSheetStore` is the single source and both views read it from
	 * there. This ref remains only so a mutation that happens without a sheet reload still has
	 * somewhere to record itself; nothing renders it.
	 */
	const transactions = ref<TransactionSummary[]>([])
	const currentTransaction = ref<TransactionDetail | null>(null)
	const revisions = ref<Revision[]>([])
	const decisions = ref<Decision[]>([])
	const evidence = ref<Evidence[]>([])
	const loading = ref(false)
	const error = ref<string | null>(null)

	function resetError() { error.value = null }

	async function loadTransaction(transactionId: string) {
		loading.value = true
		resetError()
		try {
			currentTransaction.value = await getTransaction(transactionId)
			return currentTransaction.value
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to load transaction'
			throw e
		} finally {
			loading.value = false
		}
	}

	async function loadRevisions(transactionId: string) {
		try {
			revisions.value = await listRevisions(transactionId)
			return revisions.value
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to load revisions'
			throw e
		}
	}

	async function loadAudit(transactionId: string, page = 1, pageSize = 25) {
		try {
			return await listAudit(transactionId, page, pageSize)
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to load audit'
			throw e
		}
	}

	async function createNewTransaction(sheetId: string, data: TransactionDraftWrite, options: CommandOptions = {}) {
		loading.value = true
		resetError()
		try {
			// The created transaction is returned rather than pushed into `transactions`: the sheet
			// payload is where the list comes from, and `SheetDetailView` reloads the sheet after a
			// save. Appending here as well would leave two copies with no rule for which one wins.
			return await createTransaction(sheetId, data, options)
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to create transaction'
			throw e
		} finally {
			loading.value = false
		}
	}

	async function updateExistingTransaction(transactionId: string, data: TransactionDraftWrite, expectedVersion: number, options: CommandOptions = {}) {
		loading.value = true
		resetError()
		try {
			const tx = await updateTransaction(transactionId, data, expectedVersion, options)
			const index = transactions.value.findIndex(t => t.id === transactionId)
			if (index !== -1) transactions.value[index] = tx
			if (currentTransaction.value?.id === transactionId) currentTransaction.value = tx
			return tx
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to update transaction'
			throw e
		} finally {
			loading.value = false
		}
	}

	async function deleteExistingTransaction(transactionId: string, expectedVersion: number, options: CommandOptions = {}) {
		loading.value = true
		resetError()
		try {
			await deleteTransaction(transactionId, expectedVersion, options)
			if (currentTransaction.value?.id === transactionId) currentTransaction.value = null
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to delete transaction'
			throw e
		} finally {
			loading.value = false
		}
	}

	/**
	 * Record a decision and write the returned `transaction` into the store.
	 *
	 * **The decision itself is returned, not the transaction (T344).** This used to be typed and behaved
	 * as if the endpoint answered `TransactionDetail`, which is what the unrouted, dead
	 * `TransactionController::decide()` returned; `review#decide` — the route that serves this path —
	 * answers `DecisionResult`, so the store was writing a whole transaction where the decision was and
	 * no caller could read the decision or the account effect at all. Writing `result.transaction` makes
	 * the store incapable of the old mistake: there is no bare transaction in scope to write.
	 *
	 * Still exported with **no consumer in `src/`** — `DecisionForm.vue` calls `reviewsStore.recordDecision`.
	 * That is recorded rather than hidden: the export exists so the decision path has one owner, and the
	 * route-dispatch check now proves the endpoint it addresses is the one the server serves.
	 */
	async function submitDecision(transactionId: string, decision: 'APPROVE' | 'REJECT' | 'RETURN', explanation: string | undefined, expectedVersion: number, options: CommandOptions = {}) {
		loading.value = true
		resetError()
		try {
			const result = await submitTransactionDecision(transactionId, decision, explanation, expectedVersion, options)
			const updated = result.transaction
			const index = transactions.value.findIndex(t => t.id === transactionId)
			if (index !== -1) transactions.value[index] = updated
			if (currentTransaction.value?.id === transactionId) currentTransaction.value = updated
			return result
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to submit decision'
			throw e
		} finally {
			loading.value = false
		}
	}

	async function createCorrectionDraft(transactionId: string, options: CommandOptions = {}) {
		loading.value = true
		resetError()
		try {
			const revision = await createCorrection(transactionId, options)
			revisions.value.unshift(revision)
			return revision
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to create correction'
			throw e
		} finally {
			loading.value = false
		}
	}

	async function resubmitCorrectionDraft(transactionId: string, expectedVersion: number, options: CommandOptions = {}) {
		loading.value = true
		resetError()
		try {
			const tx = await resubmitCorrection(transactionId, expectedVersion, options)
			const index = transactions.value.findIndex(t => t.id === transactionId)
			if (index !== -1) transactions.value[index] = tx
			if (currentTransaction.value?.id === transactionId) currentTransaction.value = tx
			return tx
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to resubmit correction'
			throw e
		} finally {
			loading.value = false
		}
	}

	function clearCurrentTransaction() { currentTransaction.value = null }
	function clearError() { error.value = null }

	return {
		transactions,
		currentTransaction,
		revisions,
		decisions,
		evidence,
		loading,
		error,
		loadTransaction,
		loadRevisions,
		loadAudit,
		createNewTransaction,
		updateExistingTransaction,
		deleteExistingTransaction,
		submitDecision,
		createCorrectionDraft,
		resubmitCorrectionDraft,
		clearCurrentTransaction,
		clearError
	}
})
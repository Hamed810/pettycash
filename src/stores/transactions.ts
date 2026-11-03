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
import { ApiError } from '../types/api'
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

/**
 * Transaction state with per-resource loading/error state and request identities (005 T070).
 *
 * A single `loading` boolean answered "is anything happening?" for a detail read, a revision read, a
 * save and a delete at once. The transaction surface therefore could not show a local loader without
 * also claiming the form was busy, and a late detail response could overwrite a newer one. The
 * separate refs below are the minimum needed for the surface to answer its own questions:
 *
 * - `detailLoadingId` — which transaction's detail read is in flight.
 * - `revisionsLoadingId` — which transaction's revision read is in flight.
 * - `mutationLoading` — a write (save, submit, correction, delete).
 * - `evidenceLoading` — reserved for the evidence list's own reads; evidence upload/download state
 *   stays local to its component because it is not shared between surfaces.
 *
 * `error` remains the last failure for existing readers; `detailError`/`revisionsError`/
 * `mutationError` are the independent categories new code renders.
 */
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

	const detailLoadingId = ref<string | null>(null)
	const revisionsLoadingId = ref<string | null>(null)
	const mutationLoading = ref(false)
	const evidenceLoading = ref(false)

	const detailError = ref<string | null>(null)
	const detailErrorStatus = ref<number | null>(null)
	const revisionsError = ref<string | null>(null)
	const mutationError = ref<string | null>(null)
	const error = ref<string | null>(null)

	let detailToken = 0
	let revisionsToken = 0
	let revisionsTransactionId: string | null = null

	/**
	 * The in-flight delete per transaction, so a double confirmation cannot issue a second DELETE.
	 *
	 * Keyed by transaction rather than global: two different drafts may legitimately be deleted at
	 * once, but one transaction has exactly one delete intent at a time. The same promise is returned
	 * to a second caller, which is what makes a double activation harmless rather than merely fast.
	 */
	const pendingDeletes = new Map<string, Promise<void>>()

	function resetError() {
		error.value = null
		detailError.value = null
		detailErrorStatus.value = null
		revisionsError.value = null
		mutationError.value = null
	}

	async function loadTransaction(transactionId: string) {
		const token = ++detailToken
		detailLoadingId.value = transactionId
		detailError.value = null
		detailErrorStatus.value = null
		try {
			const detail = await getTransaction(transactionId)
			// A superseded response is discarded *and* must not touch currentTransaction: it lost the
			// race, and applying it would show the record the user navigated away from.
			if (token !== detailToken) return currentTransaction.value
			currentTransaction.value = detail
			return detail
		} catch (e: unknown) {
			const err = e as Error
			if (token === detailToken) {
				detailError.value = err.message ?? 'Failed to load transaction'
				detailErrorStatus.value = e instanceof ApiError ? e.status : null
				error.value = detailError.value
			}
			throw e
		} finally {
			if (token === detailToken) detailLoadingId.value = null
		}
	}

	async function loadRevisions(transactionId: string) {
		const token = ++revisionsToken
		revisionsLoadingId.value = transactionId
		revisionsError.value = null
		if (revisionsTransactionId !== transactionId) {
			// Never show another transaction's revisions while this one's are loading.
			revisions.value = []
			revisionsTransactionId = transactionId
		}
		try {
			const loaded = await listRevisions(transactionId)
			if (token !== revisionsToken) return revisions.value
			revisions.value = loaded
			return loaded
		} catch (e: unknown) {
			const err = e as Error
			if (token === revisionsToken) {
				revisionsError.value = err.message ?? 'Failed to load revisions'
				error.value = revisionsError.value
			}
			throw e
		} finally {
			if (token === revisionsToken) revisionsLoadingId.value = null
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
		mutationLoading.value = true
		resetError()
		try {
			// The created transaction is returned rather than pushed into `transactions`: the sheet
			// payload is where the list comes from, and `SheetDetailView` reloads the sheet after a
			// save. Appending here as well would leave two copies with no rule for which one wins.
			return await createTransaction(sheetId, data, options)
		} catch (e: unknown) {
			const err = e as Error
			mutationError.value = err.message ?? 'Failed to create transaction'
			error.value = mutationError.value
			throw e
		} finally {
			mutationLoading.value = false
		}
	}

	async function updateExistingTransaction(transactionId: string, data: TransactionDraftWrite, expectedVersion: number, options: CommandOptions = {}) {
		mutationLoading.value = true
		resetError()
		try {
			const tx = await updateTransaction(transactionId, data, expectedVersion, options)
			const index = transactions.value.findIndex(t => t.id === transactionId)
			if (index !== -1) transactions.value[index] = tx
			if (currentTransaction.value?.id === transactionId) currentTransaction.value = tx
			return tx
		} catch (e: unknown) {
			const err = e as Error
			mutationError.value = err.message ?? 'Failed to update transaction'
			error.value = mutationError.value
			throw e
		} finally {
			mutationLoading.value = false
		}
	}

	/**
	 * Delete a draft exactly once per in-flight intent.
	 *
	 * The transport layer already accepts the bodyless `204` (T064). What is added here is
	 * single-flight: a second activation while the first request is unanswered resolves with the
	 * *same* promise instead of sending a second DELETE. A 404 is rethrown so the caller can present
	 * "already deleted" rather than a generic failure; the row is dropped from the local list in that
	 * case because the server has authoritatively said it does not exist.
	 */
	async function deleteExistingTransaction(transactionId: string, expectedVersion: number, options: CommandOptions = {}): Promise<void> {
		const inFlight = pendingDeletes.get(transactionId)
		if (inFlight) return inFlight

		const run = (async () => {
			mutationLoading.value = true
			resetError()
			try {
				await deleteTransaction(transactionId, expectedVersion, options)
				transactions.value = transactions.value.filter(t => t.id !== transactionId)
				if (currentTransaction.value?.id === transactionId) currentTransaction.value = null
			} catch (e: unknown) {
				if (e instanceof ApiError && e.status === 404) {
					// The desired state already holds. Record it, and keep it distinguishable from a
					// transport failure so the caller can say "already deleted".
					transactions.value = transactions.value.filter(t => t.id !== transactionId)
					if (currentTransaction.value?.id === transactionId) currentTransaction.value = null
				}
				const err = e as Error
				mutationError.value = err.message ?? 'Failed to delete transaction'
				error.value = mutationError.value
				throw e
			} finally {
				mutationLoading.value = false
			}
		})()

		pendingDeletes.set(transactionId, run)
		try {
			await run
		} finally {
			pendingDeletes.delete(transactionId)
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
		mutationLoading.value = true
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
			mutationError.value = err.message ?? 'Failed to submit decision'
			error.value = mutationError.value
			throw e
		} finally {
			mutationLoading.value = false
		}
	}

	async function createCorrectionDraft(transactionId: string, options: CommandOptions = {}) {
		mutationLoading.value = true
		resetError()
		try {
			const revision = await createCorrection(transactionId, options)
			revisions.value.unshift(revision)
			return revision
		} catch (e: unknown) {
			const err = e as Error
			mutationError.value = err.message ?? 'Failed to create correction'
			error.value = mutationError.value
			throw e
		} finally {
			mutationLoading.value = false
		}
	}

	async function resubmitCorrectionDraft(transactionId: string, expectedVersion: number, options: CommandOptions = {}) {
		mutationLoading.value = true
		resetError()
		try {
			const tx = await resubmitCorrection(transactionId, expectedVersion, options)
			const index = transactions.value.findIndex(t => t.id === transactionId)
			if (index !== -1) transactions.value[index] = tx
			if (currentTransaction.value?.id === transactionId) currentTransaction.value = tx
			return tx
		} catch (e: unknown) {
			const err = e as Error
			mutationError.value = err.message ?? 'Failed to resubmit correction'
			error.value = mutationError.value
			throw e
		} finally {
			mutationLoading.value = false
		}
	}

	function clearCurrentTransaction() { currentTransaction.value = null }
	function clearError() {
		error.value = null
		detailError.value = null
		detailErrorStatus.value = null
		revisionsError.value = null
		mutationError.value = null
	}

	return {
		transactions,
		currentTransaction,
		revisions,
		decisions,
		evidence,
		detailLoadingId,
		revisionsLoadingId,
		mutationLoading,
		evidenceLoading,
		detailError,
		detailErrorStatus,
		revisionsError,
		mutationError,
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

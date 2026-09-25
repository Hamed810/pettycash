import { defineStore } from 'pinia'
import { ref } from 'vue'
import type {
	TransactionSummary,
	TransactionDetail,
	TransactionDraftWrite,
	Revision,
	Decision,
	Evidence,
	TransactionFilters,
	CommandOptions
} from '../types/api'
import {
	listTransactions,
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
	const transactions = ref<TransactionSummary[]>([])
	const currentTransaction = ref<TransactionDetail | null>(null)
	const revisions = ref<Revision[]>([])
	const decisions = ref<Decision[]>([])
	const evidence = ref<Evidence[]>([])
	const loading = ref(false)
	const error = ref<string | null>(null)
	const pagination = ref({ page: 1, pageSize: 25, total: 0 })
	const filters = ref<TransactionFilters>({})

	function resetError() { error.value = null }

	async function loadTransactions(sheetId: string, params: { page?: number; pageSize?: number; filters?: TransactionFilters } = {}) {
		loading.value = true
		resetError()
		try {
			const { page = 1, pageSize = 25, filters: newFilters } = params
			if (newFilters) filters.value = newFilters
			pagination.value.page = page
			pagination.value.pageSize = pageSize

			const response = await listTransactions(sheetId, { page, pageSize, filters: filters.value })
			transactions.value = response.items
			pagination.value.total = response.total
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to load transactions'
			throw e
		} finally {
			loading.value = false
		}
	}

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
			const tx = await createTransaction(sheetId, data, options)
			transactions.value.unshift(tx)
			pagination.value.total += 1
			return tx
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
			transactions.value = transactions.value.filter(t => t.id !== transactionId)
			pagination.value.total = Math.max(0, pagination.value.total - 1)
			if (currentTransaction.value?.id === transactionId) currentTransaction.value = null
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to delete transaction'
			throw e
		} finally {
			loading.value = false
		}
	}

	async function submitDecision(transactionId: string, decision: 'APPROVE' | 'REJECT' | 'RETURN', explanation: string | undefined, expectedVersion: number, options: CommandOptions = {}) {
		loading.value = true
		resetError()
		try {
			const tx = await submitTransactionDecision(transactionId, decision, explanation, expectedVersion, options)
			const index = transactions.value.findIndex(t => t.id === transactionId)
			if (index !== -1) transactions.value[index] = tx
			if (currentTransaction.value?.id === transactionId) currentTransaction.value = tx
			return tx
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
		pagination,
		filters,
		loadTransactions,
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
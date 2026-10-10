import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { AuditEvent, Revision } from '../types/api'
import { listTransactionAudit, listTransactionRevisions } from '../services/auditApi'

/**
 * The audit-timeline state for one transaction (T097).
 *
 * A loading/error/data store like the sibling feature stores: it records the
 * failure in its own `error` ref for the view to render, and rethrows so the
 * caller that triggered the load can react. No business-rule authority lives
 * here — visibility is enforced server-side (FR-055), and a 404 for an
 * out-of-scope transaction arrives as `error`, never as an empty timeline that
 * could be mistaken for "no history yet".
 */
export const useAuditStore = defineStore('audit', () => {
	const transactionId = ref<string | null>(null)
	const items = ref<AuditEvent[]>([])
	const revisions = ref<Revision[]>([])
	const loading = ref(false)
	const error = ref<string | null>(null)
	const pagination = ref({ page: 1, pageSize: 25, total: 0 })

	function resetError() { error.value = null }

	function reset() {
		transactionId.value = null
		items.value = []
		revisions.value = []
		pagination.value = { page: 1, pageSize: 25, total: 0 }
		resetError()
	}

	async function loadAudit(id: string, page = 1, pageSize = 25) {
		loading.value = true
		resetError()
		try {
			const response = await listTransactionAudit(id, page, pageSize)
			transactionId.value = id
			items.value = response.items
			pagination.value = { page: response.page, pageSize: response.pageSize, total: response.total }
			return response
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to load audit history'
			throw e
		} finally {
			loading.value = false
		}
	}

	async function loadRevisions(id: string) {
		loading.value = true
		resetError()
		try {
			const result = await listTransactionRevisions(id)
			transactionId.value = id
			revisions.value = result
			return result
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to load revisions'
			throw e
		} finally {
			loading.value = false
		}
	}

	return {
		transactionId,
		items,
		revisions,
		loading,
		error,
		pagination,
		reset,
		resetError,
		loadAudit,
		loadRevisions
	}
})

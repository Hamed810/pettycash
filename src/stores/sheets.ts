import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { ApiError, type SheetSummary, type SheetDetail, type SheetCreate, type SheetUpdate, type SheetFilters, type CommandOptions } from '../types/api'
import { listSheets, createSheet, getSheet, updateSheet, submitSheet, deleteSheet } from '../services/sheetsApi'

/**
 * Sheet state with **per-resource** loading and error state (005 T070 / FR-005-044).
 *
 * ## Why one `loading` boolean was wrong
 *
 * The store used a single boolean for every request. A list refetch, a sheet-detail read and a
 * submission all set and cleared it, so `SheetDetailView`'s `isLoading` could not tell "the sheet
 * I am showing is being reloaded" from "the list is paging behind this dialog" — and replaced the
 * loaded workspace with the full-page *Loading sheet…* state. A late response from a superseded
 * request could also clear the flag while a newer request was still in flight.
 *
 * The flags below are separate because the questions are separate:
 *
 * - `listLoading` — the sheet list/pager.
 * - `detailLoadingId` — the id whose detail read is in flight, `null` when none. Identifier rather
 *   than boolean so a workspace can ask "is *this* sheet loading?" instead of "is anything loading?".
 * - `mutationLoading` — a create/update/submit/delete command.
 *
 * `error` is retained as the last failure of any kind because existing surfaces and tests read it;
 * the category refs are what new code renders, so a list failure cannot appear inside the workspace.
 */
export const useSheetStore = defineStore('sheets', () => {
	const sheets = ref<SheetSummary[]>([])
	const currentSheet = ref<SheetDetail | null>(null)

	const listLoading = ref(false)
	const detailLoadingId = ref<string | null>(null)
	const mutationLoading = ref(false)
	const detailLoading = computed(() => detailLoadingId.value !== null)

	const listError = ref<string | null>(null)
	const detailError = ref<string | null>(null)
	const detailErrorStatus = ref<number | null>(null)
	const mutationError = ref<string | null>(null)
	const error = ref<string | null>(null)
	/**
	 * The server's per-field errors from the last failed command.
	 *
	 * Submission's receipt refusal is the reason this exists: FR-091 names each offending transaction
	 * as `transactions.<transactionId>.evidence`, and a string-only store error would erase that
	 * structure before the workspace could name the rows (T058). It is cleared with `error` so a stale
	 * refusal cannot outlive a successful retry.
	 */
	const errorFieldErrors = ref<Record<string, string[]>>({})
	// The approved list pages eight rows ("Showing 1 to 8 of 24 sheets" in the reference), which is
	// what keeps the pager inside the reference desktop viewport. The server accepts `pageSize` as a
	// request parameter, so this is list presentation, not a backend contract.
	const pagination = ref({ page: 1, pageSize: 8, total: 0 })
	const filters = ref<SheetFilters>({})

	const canSubmit = computed(() => currentSheet.value?.status === 'OPEN' && (currentSheet.value?.transactions?.length ?? 0) > 0)
	const openSheets = computed(() => sheets.value.filter(s => s.status === 'OPEN'))
	const submittedSheets = computed(() => sheets.value.filter(s => s.status !== 'OPEN'))

	/**
	 * Request identities. A response is applied only when it still owns the newest token for its
	 * resource, so a slow first page cannot overwrite a newer second page, and its `finally` cannot
	 * clear a flag that a newer request now owns.
	 */
	let listToken = 0
	let detailToken = 0

	function resetError() {
		error.value = null
		listError.value = null
		detailError.value = null
		detailErrorStatus.value = null
		mutationError.value = null
		errorFieldErrors.value = {}
	}

	function recordFailure(message: string, target: 'list' | 'detail' | 'mutation') {
		if (target === 'list') listError.value = message
		if (target === 'detail') detailError.value = message
		if (target === 'mutation') mutationError.value = message
		error.value = message
	}

	async function loadSheets(params: { page?: number; pageSize?: number; filters?: SheetFilters } = {}) {
		const token = ++listToken
		listLoading.value = true
		// A list read clears only the list error: a submission refusal shown in the workspace must
		// survive a background refetch behind it.
		listError.value = null
		error.value = null
		try {
			const { page = 1, pageSize = 8, filters: newFilters } = params
			if (newFilters) filters.value = newFilters
			pagination.value.page = page
			pagination.value.pageSize = pageSize

			const response = await listSheets({ page, pageSize, filters: filters.value })
			if (token !== listToken) return
			sheets.value = response.items
			pagination.value.total = response.total
		} catch (e: unknown) {
			const err = e as Error
			const message = err.message ?? 'Failed to load sheets'
			if (token === listToken) recordFailure(message, 'list')
			throw e
		} finally {
			if (token === listToken) listLoading.value = false
		}
	}

	async function loadSheet(sheetId: string) {
		const token = ++detailToken
		detailLoadingId.value = sheetId
		detailError.value = null
		detailErrorStatus.value = null
		// The full-page failure state is a detail failure; a stale mutation message from another
		// command must not pre-empt it.
		error.value = null
		try {
			const sheet = await getSheet(sheetId)
			if (token !== detailToken) return currentSheet.value
			currentSheet.value = sheet
			return sheet
		} catch (e: unknown) {
			const err = e as Error
			const message = err.message ?? 'Failed to load sheet'
			if (token === detailToken) {
				recordFailure(message, 'detail')
				detailErrorStatus.value = e instanceof ApiError ? e.status : null
			}
			throw e
		} finally {
			if (token === detailToken) detailLoadingId.value = null
		}
	}

	async function createNewSheet(data: SheetCreate, options: CommandOptions = {}) {
		mutationLoading.value = true
		resetError()
		try {
			const sheet = await createSheet(data, options)
			sheets.value.unshift(sheet)
			pagination.value.total += 1
			return sheet
		} catch (e: unknown) {
			const err = e as Error
			recordFailure(err.message ?? 'Failed to create sheet', 'mutation')
			throw e
		} finally {
			mutationLoading.value = false
		}
	}

	async function updateExistingSheet(sheetId: string, data: SheetUpdate, options: CommandOptions = {}) {
		mutationLoading.value = true
		resetError()
		try {
			const sheet = await updateSheet(sheetId, data, options)
			const index = sheets.value.findIndex(s => s.id === sheetId)
			if (index !== -1) sheets.value[index] = sheet
			if (currentSheet.value?.id === sheetId) currentSheet.value = sheet
			return sheet
		} catch (e: unknown) {
			const err = e as Error
			recordFailure(err.message ?? 'Failed to update sheet', 'mutation')
			throw e
		} finally {
			mutationLoading.value = false
		}
	}

	async function submitCurrentSheet(sheetId: string, expectedVersion: number, options: CommandOptions = {}) {
		mutationLoading.value = true
		resetError()
		try {
			const sheet = await submitSheet(sheetId, expectedVersion, options)
			const index = sheets.value.findIndex(s => s.id === sheetId)
			if (index !== -1) sheets.value[index] = sheet
			if (currentSheet.value?.id === sheetId) currentSheet.value = sheet
			return sheet
		} catch (e: unknown) {
			const err = e as Error
			recordFailure(err.message ?? 'Failed to submit sheet', 'mutation')
			if (e instanceof ApiError) errorFieldErrors.value = e.body.fieldErrors ?? {}
			throw e
		} finally {
			mutationLoading.value = false
		}
	}

	/**
	 * FR-086: delete an own sheet that has never been submitted.
	 *
	 * **The list is reloaded rather than the row spliced out.** The cascade removes the sheet *and* its draft
	 * transactions, revisions and stored evidence files, so the only reliable proof that all of that happened is
	 * a fresh read — and it is what keeps the list's totals and count honest, which a local splice would not.
	 * `currentSheet` is cleared because a deleted sheet must not remain readable through the detail view.
	 *
	 * The command id is part of the delete intent (FR-084): callers pass one stable id and reuse it if the
	 * response is lost. The bodyless `204` is handled by `deleteSheet` through `mutateVoid` (T064).
	 */
	async function deleteOwnSheet(sheetId: string, expectedVersion: number, options: CommandOptions = {}) {
		mutationLoading.value = true
		resetError()
		try {
			await deleteSheet(sheetId, expectedVersion, options)
			if (currentSheet.value?.id === sheetId) currentSheet.value = null
			await loadSheets({ page: pagination.value.page, pageSize: pagination.value.pageSize })
		} catch (e: unknown) {
			const err = e as Error
			recordFailure(err.message ?? 'Failed to delete sheet', 'mutation')
			throw e
		} finally {
			mutationLoading.value = false
		}
	}

	function clearCurrentSheet() { currentSheet.value = null }

	function clearError() {
		error.value = null
		errorFieldErrors.value = {}
	}

	return {
		sheets,
		currentSheet,
		listLoading,
		detailLoading,
		detailLoadingId,
		mutationLoading,
		listError,
		detailError,
		detailErrorStatus,
		mutationError,
		error,
		errorFieldErrors,
		pagination,
		filters,
		canSubmit,
		openSheets,
		submittedSheets,
		loadSheets,
		loadSheet,
		createNewSheet,
		updateExistingSheet,
		submitCurrentSheet,
		deleteOwnSheet,
		clearCurrentSheet,
		clearError
	}
})

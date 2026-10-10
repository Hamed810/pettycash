import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { SheetSummary, SheetDetail, SheetCreate, SheetFilters, CommandOptions } from '../types/api'
import { listSheets, createSheet, getSheet, updateSheet, submitSheet, deleteSheet } from '../services/sheetsApi'

export const useSheetStore = defineStore('sheets', () => {
	const sheets = ref<SheetSummary[]>([])
	const currentSheet = ref<SheetDetail | null>(null)
	const loading = ref(false)
	const error = ref<string | null>(null)
	const pagination = ref({ page: 1, pageSize: 25, total: 0 })
	const filters = ref<SheetFilters>({})

	const canSubmit = computed(() => currentSheet.value?.status === 'OPEN' && (currentSheet.value?.transactions?.length ?? 0) > 0)
	const openSheets = computed(() => sheets.value.filter(s => s.status === 'OPEN'))
	const submittedSheets = computed(() => sheets.value.filter(s => s.status !== 'OPEN'))

	function resetError() { error.value = null }

	async function loadSheets(params: { page?: number; pageSize?: number; filters?: SheetFilters } = {}) {
		loading.value = true
		resetError()
		try {
			const { page = 1, pageSize = 25, filters: newFilters } = params
			if (newFilters) filters.value = newFilters
			pagination.value.page = page
			pagination.value.pageSize = pageSize

			const response = await listSheets({ page, pageSize, filters: filters.value })
			sheets.value = response.items
			pagination.value.total = response.total
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to load sheets'
			throw e
		} finally {
			loading.value = false
		}
	}

	async function loadSheet(sheetId: string) {
		loading.value = true
		resetError()
		try {
			currentSheet.value = await getSheet(sheetId)
			return currentSheet.value
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to load sheet'
			throw e
		} finally {
			loading.value = false
		}
	}

	async function createNewSheet(data: SheetCreate, options: CommandOptions = {}) {
		loading.value = true
		resetError()
		try {
			const sheet = await createSheet(data, options)
			sheets.value.unshift(sheet)
			pagination.value.total += 1
			return sheet
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to create sheet'
			throw e
		} finally {
			loading.value = false
		}
	}

	async function updateExistingSheet(sheetId: string, data: SheetCreate, expectedVersion: number, options: CommandOptions = {}) {
		loading.value = true
		resetError()
		try {
			const sheet = await updateSheet(sheetId, data, expectedVersion, options)
			const index = sheets.value.findIndex(s => s.id === sheetId)
			if (index !== -1) sheets.value[index] = sheet
			if (currentSheet.value?.id === sheetId) currentSheet.value = sheet
			return sheet
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to update sheet'
			throw e
		} finally {
			loading.value = false
		}
	}

	async function submitCurrentSheet(sheetId: string, expectedVersion: number, options: CommandOptions = {}) {
		loading.value = true
		resetError()
		try {
			const sheet = await submitSheet(sheetId, expectedVersion, options)
			const index = sheets.value.findIndex(s => s.id === sheetId)
			if (index !== -1) sheets.value[index] = sheet
			if (currentSheet.value?.id === sheetId) currentSheet.value = sheet
			return sheet
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to submit sheet'
			throw e
		} finally {
			loading.value = false
		}
	}

	/**
	 * FR-086: delete an own sheet that has never been submitted.
	 *
	 * **The list is reloaded rather than the row spliced out.** The cascade removes the sheet *and* its draft
	 * transactions, revisions and stored evidence files, so the only reliable proof that all of that happened is
	 * a fresh read — and it is what keeps the list's totals and count honest, which a local splice would not.
	 * `currentSheet` is cleared because a deleted sheet must not remain readable through the detail view.
	 */
	async function deleteOwnSheet(sheetId: string, expectedVersion: number, options: CommandOptions = {}) {
		loading.value = true
		resetError()
		try {
			await deleteSheet(sheetId, expectedVersion, options)
			if (currentSheet.value?.id === sheetId) currentSheet.value = null
			await loadSheets({ page: pagination.value.page, pageSize: pagination.value.pageSize })
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? 'Failed to delete sheet'
			throw e
		} finally {
			loading.value = false
		}
	}

	function clearCurrentSheet() { currentSheet.value = null }

	function clearError() { error.value = null }

	return {
		sheets,
		currentSheet,
		loading,
		error,
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
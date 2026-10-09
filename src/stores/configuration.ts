import { defineStore } from 'pinia'
import { ref } from 'vue'
import type {
	CommandOptions,
	CostCenter,
	CostCenterWrite,
	Currency,
	CurrencyWrite,
	ExpenseType,
	ExpenseTypeWrite,
	FinanceAssignment,
	FinanceAssignmentWrite
} from '../types/api'
import {
	listCurrencies,
	createCurrency,
	updateCurrency,
	listCostCenters,
	createCostCenter,
	updateCostCenter,
	listExpenseTypes,
	createExpenseType,
	updateExpenseType,
	listFinanceAssignments,
	createFinanceAssignment
} from '../services/configurationApi'

/**
 * T088: the administrator's reference-data state.
 *
 * One store for the four reference collections rather than one per resource, because the admin
 * surface renders them together and the loading/error shape is identical: four near-identical
 * stores would differ only in the service function they call. Each action reloads its own list
 * after writing, so an optimistic-lock refusal (`409`) leaves the displayed rows at the versions
 * the server actually holds rather than at values a failed write invented.
 */
export const useConfigurationStore = defineStore('configuration', () => {
	const currencies = ref<Currency[]>([])
	const costCenters = ref<CostCenter[]>([])
	const expenseTypes = ref<ExpenseType[]>([])
	const financeAssignments = ref<FinanceAssignment[]>([])
	const loading = ref(false)
	const error = ref<string | null>(null)

	function resetError() { error.value = null }

	async function run<T>(label: string, operation: () => Promise<T>): Promise<T> {
		loading.value = true
		resetError()
		try {
			return await operation()
		} catch (e: unknown) {
			const err = e as Error
			error.value = err.message ?? `Failed: ${label}`
			throw e
		} finally {
			loading.value = false
		}
	}

	async function loadCurrencies() {
		const response = await run('load currencies', () => listCurrencies())
		currencies.value = response.items
	}

	async function saveCurrency(data: CurrencyWrite, version?: number, options: CommandOptions = {}) {
		if (version === undefined) {
			await run('create currency', () => createCurrency(data, options))
		} else {
			await run('update currency', () => updateCurrency(data.code, data, version, options))
		}
		await loadCurrencies()
	}

	async function loadCostCenters() {
		const response = await run('load cost centers', () => listCostCenters())
		costCenters.value = response.items
	}

	async function saveCostCenter(id: string | null, data: CostCenterWrite, version: number, options: CommandOptions = {}) {
		if (id === null) {
			await run('create cost center', () => createCostCenter(data, options))
		} else {
			await run('update cost center', () => updateCostCenter(id, data, version, options))
		}
		await loadCostCenters()
	}

	async function loadExpenseTypes() {
		const response = await run('load expense types', () => listExpenseTypes())
		expenseTypes.value = response.items
	}

	async function saveExpenseType(
		title: string | null,
		data: ExpenseTypeWrite,
		version: number,
		options: CommandOptions = {}
	) {
		if (title === null) {
			await run('create expense type', () => createExpenseType(data, options))
		} else {
			await run('update expense type', () => updateExpenseType(title, data, version, options))
		}
		await loadExpenseTypes()
	}

	async function loadFinanceAssignments() {
		const response = await run('load finance assignments', () => listFinanceAssignments())
		financeAssignments.value = response.items
	}

	async function assignFinance(data: FinanceAssignmentWrite, options: CommandOptions = {}) {
		await run('assign finance officer', () => createFinanceAssignment(data, options))
		await loadFinanceAssignments()
	}

	function clearError() { error.value = null }

	return {
		currencies,
		costCenters,
		expenseTypes,
		financeAssignments,
		loading,
		error,
		loadCurrencies,
		saveCurrency,
		loadCostCenters,
		saveCostCenter,
		loadExpenseTypes,
		saveExpenseType,
		loadFinanceAssignments,
		assignFinance,
		clearError
	}
})

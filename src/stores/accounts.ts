import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { Account } from '../types/api'
import { listAccounts } from '../services/accountsApi'

export const useAccountStore = defineStore('accounts', () => {
	const accounts = ref<Account[]>([])
	const loading = ref(false)
	const loaded = ref(false)
	const error = ref<string | null>(null)

	/** A purchaser with no account is a normal state, not a failure, so the form renders an empty state. */
	const hasAccounts = computed(() => accounts.value.length > 0)

	/**
	 * The currencies a new sheet may use. FR-021 makes every transaction inherit the sheet
	 * currency, and the sheet currency comes from the selected account, so this is exactly the set
	 * of account currencies the caller holds.
	 */
	const availableCurrencies = computed(() =>
		[...new Set(accounts.value.map((a) => a.balance.currency))]
	)

	function accountById(id: string): Account | undefined {
		return accounts.value.find((a) => a.id === id)
	}

	function resetError() {
		error.value = null
	}

	async function loadAccounts(force = false) {
		if (loaded.value && !force) return
		loading.value = true
		resetError()
		try {
			const page = await listAccounts()
			accounts.value = page.items
			loaded.value = true
		} catch (e: unknown) {
			error.value = e instanceof Error ? e.message : String(e)
		} finally {
			loading.value = false
		}
	}

	return {
		accounts,
		loading,
		loaded,
		error,
		hasAccounts,
		availableCurrencies,
		accountById,
		resetError,
		loadAccounts
	}
})

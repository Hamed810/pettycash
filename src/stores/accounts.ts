import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { Account, AccountActiveUpdate, AccountCharge, AccountCreate, Movement } from '../types/api'
import { listAccounts, listMovements, createAccount, chargeAccount, updateAccount } from '../services/accountsApi'

export const useAccountStore = defineStore('accounts', () => {
	const accounts = ref<Account[]>([])
	const movements = ref<Movement[]>([])
	/** The account whose ledger is on screen, or `null` before one is chosen. */
	const selectedAccountId = ref<string | null>(null)
	const movementPage = ref(1)
	const movementPageSize = ref(25)
	const movementTotal = ref(0)
	const loading = ref(false)
	const movementsLoading = ref(false)
	const loaded = ref(false)
	const error = ref<string | null>(null)
	const movementsError = ref<string | null>(null)

	/** A purchaser with no account is a normal state, not a failure, so the form renders an empty state. */
	const hasAccounts = computed(() => accounts.value.length > 0)

	/**
	 * The account whose ledger is on screen.
	 *
	 * Falls back to the caller's first account so that opening the view needs no selection step: an
	 * account picker that must be chosen before anything renders is one more thing between a
	 * purchaser and their balance.
	 */
	const selectedAccount = computed(
		() =>
			accounts.value.find((a) => a.id === selectedAccountId.value) ??
			accounts.value[0] ??
			null
	)

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

	/**
	 * Load one page of the selected account's ledger.
	 *
	 * Errors are kept apart from the account list's own error on purpose: the list is what tells a
	 * purchaser they have an account at all, and a ledger that failed to load must not read as
	 * "you have no account" or as "your balance is zero". FR-075 requires a clear error and a manual
	 * retry, and there is no auto-retry anywhere in this path.
	 */
	async function loadMovements(page = 1) {
		const account = selectedAccount.value
		if (!account) {
			movements.value = []
			movementsError.value = null
			movementTotal.value = 0
			return
		}
		movementsLoading.value = true
		movementsError.value = null
		try {
			const result = await listMovements(account.id, {
				page,
				pageSize: movementPageSize.value
			})
			movements.value = result.items
			movementPage.value = result.page
			movementTotal.value = result.total
		} catch (e: unknown) {
			// The page is emptied so a stale ledger is never shown next to a fresh error, which would
			// read as the current state of the account.
			movements.value = []
			movementsError.value = e instanceof Error ? e.message : String(e)
		} finally {
			movementsLoading.value = false
		}
	}

	function selectAccount(id: string) {
		selectedAccountId.value = id
	}

	/**
	 * T088: the funding writes — open, charge, deactivate/reactivate.
	 *
	 * Each reloads the caller's own list afterwards, so a balance the server changed is read back
	 * rather than assumed. Note the reachable shape this supports: creation returns the account
	 * (id and version in hand), and charge/deactivation act on a known id — there is no endpoint
	 * listing another user's accounts, so cross-user funding addresses an id from the creation
	 * response or pasted from the accounts report, never from a lookup this store cannot perform.
	 */
	async function openAccount(data: AccountCreate) {
		loading.value = true
		resetError()
		try {
			const account = await createAccount(data)
			loaded.value = false
			await loadAccounts()
			return account
		} catch (e: unknown) {
			error.value = e instanceof Error ? e.message : String(e)
			throw e
		} finally {
			loading.value = false
		}
	}

	async function fundAccount(accountId: string, data: AccountCharge) {
		loading.value = true
		resetError()
		try {
			const result = await chargeAccount(accountId, data)
			loaded.value = false
			await loadAccounts()
			return result
		} catch (e: unknown) {
			error.value = e instanceof Error ? e.message : String(e)
			throw e
		} finally {
			loading.value = false
		}
	}

	async function setAccountActive(accountId: string, data: AccountActiveUpdate, expectedVersion: number) {
		loading.value = true
		resetError()
		try {
			const account = await updateAccount(accountId, data, expectedVersion)
			loaded.value = false
			await loadAccounts()
			return account
		} catch (e: unknown) {
			error.value = e instanceof Error ? e.message : String(e)
			throw e
		} finally {
			loading.value = false
		}
	}

	return {
		accounts,
		movements,
		selectedAccountId,
		movementPage,
		movementPageSize,
		movementTotal,
		loading,
		movementsLoading,
		loaded,
		error,
		movementsError,
		hasAccounts,
		selectedAccount,
		availableCurrencies,
		accountById,
		resetError,
		loadAccounts,
		loadMovements,
		selectAccount,
		openAccount,
		fundAccount,
		setAccountActive
	}
})

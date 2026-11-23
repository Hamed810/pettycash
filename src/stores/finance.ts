import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type {
	CompletedSheetDetail,
	CompletedSheetSummary,
	FinanceLedgerRow,
	HolderAccountSummary,
	HolderSummary,
	Money
} from '../types/api'
import { ApiError, type AccountCharge } from '../types/api'
import {
	listHolders,
	listHolderAccounts,
	fetchLedger,
	listCompletedSheets,
	getCompletedSheet,
	type FinanceListParams,
	type HolderListParams
} from '../services/financeApi'
import { chargeAccount } from '../services/accountsApi'
import { parseMoneyInput, type MoneyInputError, type MoneyParseResult } from '../utils/moneyInput'

/**
 * The Finance module's read state (Feature 008, T025) — one store with isolated slices.
 *
 * ## Slice isolation is the stale-data defence
 *
 * Each surface owns its own loading/error/page refs, following `stores/accounts.ts`'s split, so a
 * ledger failure never reads as "you have no holders" and vice versa. Selection boundaries are the
 * second defence: **choosing a holder synchronously clears the account page, the selected account,
 * and the whole ledger slice** before the account read is even issued, and the same applies at the
 * account→ledger and sheet→detail boundaries. A view can therefore never present the previous
 * selection's data beside the new selection's identity.
 *
 * The third defence is the request identity check: an in-flight response is applied only when the
 * selection it was requested for is still the current one. A slow holder-accounts response that
 * resolves after the user has already chosen a different holder is dropped rather than rendered.
 * There is deliberately no general concurrency framework — one captured selection per request,
 * which is all a paginated read surface needs.
 *
 * ## What this store does not do
 *
 * No recharge state lives here yet: the recharge draft/review/submit state machine is T027's, and
 * its write is the existing `chargeAccount()` client unchanged. No slice derives a balance, a
 * count, or a financial value — every figure rendered from these slices came from a server
 * response (FR-008-051).
 */
export const useFinanceStore = defineStore('finance', () => {
	// --- holder directory slice ---
	const holders = ref<HolderSummary[]>([])
	const holdersPage = ref(1)
	const holdersPageSize = ref(25)
	const holdersTotal = ref(0)
	const holdersSearch = ref('')
	const holdersLoading = ref(false)
	const holdersError = ref<string | null>(null)

	// --- holder accounts slice (selected holder) ---
	const selectedHolderUid = ref<string | null>(null)
	const selectedHolderName = ref<string | null>(null)
	const holderAccounts = ref<HolderAccountSummary[]>([])
	const holderAccountsPage = ref(1)
	const holderAccountsTotal = ref(0)
	const holderAccountsLoading = ref(false)
	const holderAccountsError = ref<string | null>(null)

	// --- ledger slice (selected account) ---
	const selectedAccountId = ref<string | null>(null)
	const ledgerAccount = ref<HolderAccountSummary | null>(null)
	const ledgerRows = ref<FinanceLedgerRow[]>([])
	const ledgerPage = ref(1)
	const ledgerPageSize = ref(25)
	const ledgerTotal = ref(0)
	const ledgerLoading = ref(false)
	const ledgerError = ref<string | null>(null)

	// --- completed Cost Sheet history slices ---
	const completedSheets = ref<CompletedSheetSummary[]>([])
	const completedSheetsPage = ref(1)
	const completedSheetsTotal = ref(0)
	const completedSheetsLoading = ref(false)
	const completedSheetsError = ref<string | null>(null)

	const completedSheetId = ref<string | null>(null)
	const completedSheetDetail = ref<CompletedSheetDetail | null>(null)
	const completedSheetLoading = ref(false)
	const completedSheetError = ref<string | null>(null)

	// --- recharge slice (T027) ---
	//
	// Isolated from holder/ledger/history state: selecting another holder or account clears it.
	// No financial value here is client-derived — every figure comes from the account payload or
	// the authoritative charge response.

	/** Account currently being recharged (null when no recharge flow is active). */
	const rechargeAccountId = ref<string | null>(null)
	/** Holder identity shown in the review step. */
	const rechargeHolderName = ref<string | null>(null)
	/** Raw amount text the user typed (major units). */
	const rechargeAmountText = ref('')
	/** Parsed minor-unit amount, null until a valid parse. */
	const rechargeAmountMinor = ref<number | null>(null)
	/** Destination account's currency code. */
	const rechargeCurrency = ref<string | null>(null)
	/** Destination account's minor-unit scale. */
	const rechargeMinorUnit = ref(0)
	/** Current server-provided balance of the destination account. */
	const rechargeBalance = ref<Money | null>(null)
	/** expectedVersion from the freshly loaded account payload. */
	const rechargeExpectedVersion = ref<number | null>(null)
	/** Last parse rejection reason, or null when the amount parses. */
	const rechargeValidationReason = ref<MoneyInputError | null>(null)

	/** 'idle' | 'review' | 'submitting' | 'success' | 'error' | 'unknown' */
	const rechargeStatus = ref<'idle' | 'review' | 'submitting' | 'success' | 'error' | 'unknown'>('idle')
	/** Server error message preserved on failure; never converted into success. */
	const rechargeError = ref<string | null>(null)
	/** Idempotency key for the current logical recharge; null until confirmation. */
	const rechargeCommandId = ref<string | null>(null)

	/**
	 * Monotonic request token for the in-flight charge, so a stale response cannot flip the
	 * workflow out of `submitting` after the user has already retried or abandoned.
	 */
	let rechargeRequest = 0

	/**
	 * Start a recharge draft against the given account.
	 *
	 * All draft fields come from the account payload — no value is invented, and no balance is
	 * derived from any displayed string.
	 */
	function startRecharge(account: HolderAccountSummary, holderName: string | null): void {
		rechargeAccountId.value = account.id
		rechargeHolderName.value = holderName
		rechargeCurrency.value = account.currency
		rechargeMinorUnit.value = account.minorUnit
		rechargeBalance.value = account.balance
		rechargeExpectedVersion.value = account.version
		rechargeAmountText.value = ''
		rechargeAmountMinor.value = null
		rechargeValidationReason.value = null
		rechargeStatus.value = 'idle'
		rechargeError.value = null
		rechargeCommandId.value = null
	}

	/**
	 * Enter or change the recharge amount.
	 *
	 * Parses through `parseMoneyInput` (BigInt, no floating point) and records the rejection
	 * reason when the input cannot become a valid `amountMinor`. The confirm action is gated on
	 * a successful parse, so an invalid amount can never reach confirmation.
	 */
	function setRechargeAmount(text: string): void {
		rechargeAmountText.value = text
		const result: MoneyParseResult = parseMoneyInput(text, rechargeMinorUnit.value)
		if (result.ok) {
			rechargeAmountMinor.value = result.amountMinor
			rechargeValidationReason.value = null
		} else {
			rechargeAmountMinor.value = null
			rechargeValidationReason.value = result.reason
		}
	}

	/** True when the draft has a valid, positive, in-scale amount. */
	const canConfirm = computed(() =>
		rechargeStatus.value === 'idle' && rechargeAmountMinor.value !== null && rechargeAmountMinor.value > 0
	)

	/**
	 * Explicit confirmation: move from draft/review into submission.
	 *
	 * Generates one `commandId` for this logical recharge. The same key is reused for an
	 * unknown-result retry; only a new intended recharge (after a definitive success or failure)
	 * receives a new key. No request is sent here — confirmation only arms the submission.
	 */
	function confirmRecharge(): void {
		if (!canConfirm.value) return
		rechargeStatus.value = 'review'
		rechargeCommandId.value = crypto.randomUUID ? crypto.randomUUID() : String(Date.now())
	}

	/**
	 * Submit the confirmed recharge through the existing `chargeAccount()` client.
	 *
	 * Sends `expectedVersion` unchanged from the account payload and the stored `commandId` as
	 * the idempotency key. A response older than the latest confirmation is dropped.
	 */
	async function submitRecharge(): Promise<void> {
		if (rechargeStatus.value !== 'review' || rechargeAmountMinor.value === null || rechargeCommandId.value === null) return
		if (rechargeAccountId.value === null || rechargeExpectedVersion.value === null) return

		const request = ++rechargeRequest
		rechargeStatus.value = 'submitting'
		rechargeError.value = null

		const payload: AccountCharge = {
			amountMinor: rechargeAmountMinor.value,
			description: 'Recharge',
			occurredAt: new Date().toISOString(),
			expectedVersion: rechargeExpectedVersion.value
		}

		try {
			const result = await chargeAccount(rechargeAccountId.value, payload, {
				commandId: rechargeCommandId.value,
				expectedVersion: rechargeExpectedVersion.value
			})
			// A superseded confirmation must not flip state out of `submitting`.
			if (request !== rechargeRequest) return

			// Server-authoritative: trust the returned account and movement, never a local sum.
			rechargeStatus.value = 'success'
			rechargeBalance.value = result.account.balance
			rechargeExpectedVersion.value = result.account.version
		} catch (e: unknown) {
			if (request !== rechargeRequest) return

			const message = e instanceof Error ? e.message : String(e)
			// A network/unknown outcome (status 0 from the HTTP client) keeps the same command id
			// available for retry; a definitive server failure (4xx/5xx) consumes it.
			if (e instanceof ApiError && e.status === 0) {
				rechargeStatus.value = 'unknown'
				rechargeError.value = message
				return
			}

			rechargeStatus.value = 'error'
			rechargeError.value = message
		}
	}

	/**
	 * Retry the same logical recharge after an unknown result.
	 *
	 * Reuses the stored `commandId` so the backend's idempotent replay returns the first outcome
	 * rather than applying a second charge. Only available when the outcome is `unknown`.
	 */
	async function retryRecharge(): Promise<void> {
		if (rechargeStatus.value !== 'unknown' || rechargeCommandId.value === null) return
		// Re-arm for submission so submitRecharge() can issue the charge with the same key.
		rechargeStatus.value = 'review'
		await submitRecharge()
	}

	/**
	 * Start a brand-new intended recharge.
	 *
	 * Replaces the command id so the new submission cannot be replayed as the previous one.
	 */
	function newIntendedRecharge(): void {
		rechargeCommandId.value = null
		rechargeStatus.value = 'idle'
		rechargeError.value = null
		rechargeAmountText.value = ''
		rechargeAmountMinor.value = null
		rechargeValidationReason.value = null
	}

	/** Reset the recharge flow entirely, clearing all draft state. */
	function cancelRecharge(): void {
		rechargeAccountId.value = null
		rechargeHolderName.value = null
		rechargeAmountText.value = ''
		rechargeAmountMinor.value = null
		rechargeCurrency.value = null
		rechargeMinorUnit.value = 0
		rechargeBalance.value = null
		rechargeExpectedVersion.value = null
		rechargeValidationReason.value = null
		rechargeStatus.value = 'idle'
		rechargeError.value = null
		rechargeCommandId.value = null
	}

	/**
	 * Clear the recharge draft when the user selects a different holder or account.
	 *
	 * A prior account's draft must never leak into a newly selected account.
	 */
	function clearRechargeOnAccountSwitch(): void {
		cancelRecharge()
	}

	/**
	 * Monotonic request tokens per selection-sensitive slice.
	 *
	 * A response older than the latest issued request for its slice is discarded. Selection
	 * clearing handles the synchronous boundary; this handles the asynchronous one — the response
	 * that arrives after the user moved on.
	 */
	let holderAccountsRequest = 0
	let ledgerRequest = 0
	let completedSheetRequest = 0

	function errorOf(e: unknown): string {
		return e instanceof Error ? e.message : String(e)
	}

	// --- holder directory ---

	async function loadHolders(page = 1, pageSize = holdersPageSize.value): Promise<void> {
		holdersLoading.value = true
		holdersError.value = null
		try {
			const params: HolderListParams = { page, pageSize }
			const search = holdersSearch.value.trim()
			if (search !== '') params.search = search
			const result = await listHolders(params)
			holders.value = result.items
			holdersPage.value = result.page
			holdersPageSize.value = result.pageSize
			holdersTotal.value = result.total
		} catch (e: unknown) {
			// The page is emptied so a failed load never renders stale holders as current.
			holders.value = []
			holdersTotal.value = 0
			holdersError.value = errorOf(e)
		} finally {
			holdersLoading.value = false
		}
	}

	/** Set the directory search and reload page 1. An empty term clears the filter. */
	async function searchHolders(term: string): Promise<void> {
		holdersSearch.value = term
		await loadHolders(1)
	}

	// --- holder selection and its accounts ---

	/**
	 * Select a holder and load its accounts.
	 *
	 * The dependent slices are cleared **before** the request is issued, so the ledger of a
	 * previously selected account is never on screen under the new holder's identity.
	 */
	async function selectHolder(uid: string | null, displayName: string | null = null): Promise<void> {
		selectedHolderUid.value = uid
		selectedHolderName.value = displayName
		// Selection boundary: dependent state is replaced synchronously, for both the clear and
		// the reload path — the previous holder's accounts must never be on screen under the new
		// holder's identity, not even while the new page is in flight.
		selectedAccountId.value = null
		ledgerAccount.value = null
		ledgerRows.value = []
		ledgerPage.value = 1
		ledgerTotal.value = 0
		ledgerError.value = null
		// Switching holders clears any in-flight recharge draft — a prior account's draft must
		// never leak into a newly selected holder.
		clearRechargeOnAccountSwitch()
		holderAccountsRequest++
		holderAccounts.value = []
		holderAccountsPage.value = 1
		holderAccountsTotal.value = 0
		holderAccountsError.value = null
		if (uid === null) {
			// No in-flight request owns this flag any more: its identity check now fails, so it
			// would never clear it. Clearing here keeps "no holder selected" from rendering as a
			// perpetual load.
			holderAccountsLoading.value = false
			return
		}
		await loadHolderAccounts(1)
	}

	async function loadHolderAccounts(page = 1): Promise<void> {
		const uid = selectedHolderUid.value
		if (uid === null) {
			holderAccounts.value = []
			holderAccountsTotal.value = 0
			return
		}
		const request = ++holderAccountsRequest
		holderAccountsLoading.value = true
		holderAccountsError.value = null
		try {
			const params: FinanceListParams = { page, pageSize: 25 }
			const result = await listHolderAccounts(uid, params)
			// A response for a holder that is no longer selected is dropped, not rendered.
			if (selectedHolderUid.value !== uid || request !== holderAccountsRequest) return
			holderAccounts.value = result.items
			holderAccountsPage.value = result.page
			holderAccountsTotal.value = result.total
		} catch (e: unknown) {
			if (selectedHolderUid.value !== uid || request !== holderAccountsRequest) return
			holderAccounts.value = []
			holderAccountsTotal.value = 0
			holderAccountsError.value = errorOf(e)
		} finally {
			// Only the owning request may clear the flag it set.
			if (selectedHolderUid.value === uid && request === holderAccountsRequest) {
				holderAccountsLoading.value = false
			}
		}
	}

	// --- account selection and its ledger ---

	/**
	 * Select an account within the current holder and load its ledger.
	 *
	 * Clears the previous ledger synchronously; the account summary arrives with the ledger page
	 * and is stored as `ledgerAccount`, so the header never shows one account's identity beside
	 * another account's movements.
	 */
	async function selectAccount(accountId: string | null): Promise<void> {
		selectedAccountId.value = accountId
		ledgerRequest++
		ledgerAccount.value = null
		ledgerRows.value = []
		ledgerPage.value = 1
		ledgerTotal.value = 0
		ledgerError.value = null
		// Selecting a different account invalidates any recharge draft against the previous one.
		clearRechargeOnAccountSwitch()
		if (accountId === null) {
			// Same stuck-flag rule as `selectHolder(null)`: the superseded request can no longer
			// clear its own loading flag, so the selection boundary does it.
			ledgerLoading.value = false
			return
		}
		await loadLedger(1)
	}

	async function loadLedger(page = 1): Promise<void> {
		const accountId = selectedAccountId.value
		if (accountId === null) return
		const request = ++ledgerRequest
		ledgerLoading.value = true
		ledgerError.value = null
		try {
			const result = await fetchLedger(accountId, { page, pageSize: ledgerPageSize.value })
			if (selectedAccountId.value !== accountId || request !== ledgerRequest) return
			ledgerAccount.value = result.account
			ledgerRows.value = result.items
			ledgerPage.value = result.page
			ledgerPageSize.value = result.pageSize
			ledgerTotal.value = result.total
		} catch (e: unknown) {
			if (selectedAccountId.value !== accountId || request !== ledgerRequest) return
			// Emptied so a failed page never renders the previous account's rows as current.
			ledgerRows.value = []
			ledgerTotal.value = 0
			ledgerError.value = errorOf(e)
		} finally {
			if (selectedAccountId.value === accountId && request === ledgerRequest) {
				ledgerLoading.value = false
			}
		}
	}

	// --- completed Cost Sheet history ---

	async function loadCompletedSheets(page = 1): Promise<void> {
		completedSheetsLoading.value = true
		completedSheetsError.value = null
		try {
			const result = await listCompletedSheets({ page, pageSize: 25 })
			completedSheets.value = result.items
			completedSheetsPage.value = result.page
			completedSheetsTotal.value = result.total
		} catch (e: unknown) {
			completedSheets.value = []
			completedSheetsTotal.value = 0
			completedSheetsError.value = errorOf(e)
		} finally {
			completedSheetsLoading.value = false
		}
	}

	/**
	 * Open one completed sheet's read-only detail.
	 *
	 * The previous detail is cleared synchronously — a slow response for sheet A must never be
	 * presented as sheet B's history — and the response is applied only while its sheet is still
	 * the open one.
	 */
	async function openCompletedSheet(sheetId: string): Promise<void> {
		completedSheetRequest++
		const request = completedSheetRequest
		completedSheetId.value = sheetId
		completedSheetDetail.value = null
		completedSheetError.value = null
		completedSheetLoading.value = true
		try {
			const detail = await getCompletedSheet(sheetId)
			if (completedSheetId.value !== sheetId || request !== completedSheetRequest) return
			completedSheetDetail.value = detail
		} catch (e: unknown) {
			if (completedSheetId.value !== sheetId || request !== completedSheetRequest) return
			completedSheetError.value = errorOf(e)
		} finally {
			if (completedSheetId.value === sheetId && request === completedSheetRequest) {
				completedSheetLoading.value = false
			}
		}
	}

	function closeCompletedSheet(): void {
		completedSheetRequest++
		completedSheetId.value = null
		completedSheetDetail.value = null
		completedSheetError.value = null
		completedSheetLoading.value = false
	}

	/** Clear every slice's error (view retry affordances call this before re-issuing). */
	function resetErrors(): void {
		holdersError.value = null
		holderAccountsError.value = null
		ledgerError.value = null
		completedSheetsError.value = null
		completedSheetError.value = null
		rechargeError.value = null
	}

	/** True once any holder has been selected — the directory → accounts journey state. */
	const hasSelectedHolder = computed(() => selectedHolderUid.value !== null)

	return {
		// holder directory
		holders,
		holdersPage,
		holdersPageSize,
		holdersTotal,
		holdersSearch,
		holdersLoading,
		holdersError,
		loadHolders,
		searchHolders,
		// holder accounts
		selectedHolderUid,
		selectedHolderName,
		holderAccounts,
		holderAccountsPage,
		holderAccountsTotal,
		holderAccountsLoading,
		holderAccountsError,
		hasSelectedHolder,
		selectHolder,
		loadHolderAccounts,
		// ledger
		selectedAccountId,
		ledgerAccount,
		ledgerRows,
		ledgerPage,
		ledgerPageSize,
		ledgerTotal,
		ledgerLoading,
		ledgerError,
		selectAccount,
		loadLedger,
		// completed history
		completedSheets,
		completedSheetsPage,
		completedSheetsTotal,
		completedSheetsLoading,
		completedSheetsError,
		loadCompletedSheets,
		completedSheetId,
		completedSheetDetail,
		completedSheetLoading,
		completedSheetError,
		openCompletedSheet,
		closeCompletedSheet,
		// shared
		resetErrors,
		// recharge (T027) — isolated workflow state
		rechargeAccountId,
		rechargeHolderName,
		rechargeAmountText,
		rechargeAmountMinor,
		rechargeCurrency,
		rechargeMinorUnit,
		rechargeBalance,
		rechargeExpectedVersion,
		rechargeValidationReason,
		rechargeStatus,
		rechargeError,
		rechargeCommandId,
		canConfirm,
		startRecharge,
		setRechargeAmount,
		confirmRecharge,
		submitRecharge,
		retryRecharge,
		newIntendedRecharge,
		cancelRecharge,
		clearRechargeOnAccountSwitch
	}
})

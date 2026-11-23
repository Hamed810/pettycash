import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

// The store is tested against its own client module boundary: `@/services/financeApi` is mocked,
// so these tests assert the store's state machine (slices, selection boundaries, stale-response
// protection) and not the wire — the wire is `tests/Frontend/financeApi.spec.ts`'s subject.
vi.mock('@/services/financeApi', () => ({
	listHolders: vi.fn(),
	listHolderAccounts: vi.fn(),
	fetchLedger: vi.fn(),
	listCompletedSheets: vi.fn(),
	getCompletedSheet: vi.fn()
}))

import {
	listHolders,
	listHolderAccounts,
	fetchLedger,
	listCompletedSheets,
	getCompletedSheet
} from '@/services/financeApi'
import { useFinanceStore } from '@/stores/finance'
import type {
	CompletedSheetDetail,
	CompletedSheetSummary,
	FinanceHolderAccountsPage,
	FinanceLedgerPage,
	FinanceLedgerRow,
	HolderAccountSummary,
	HolderSummary,
	Page
} from '@/types/api'

const listHoldersMock = vi.mocked(listHolders)
const listHolderAccountsMock = vi.mocked(listHolderAccounts)
const fetchLedgerMock = vi.mocked(fetchLedger)
const listCompletedSheetsMock = vi.mocked(listCompletedSheets)
const getCompletedSheetMock = vi.mocked(getCompletedSheet)

function holderPage(items: HolderSummary[], total = items.length): Page<HolderSummary> {
	return { items, page: 1, pageSize: 25, total }
}

function holder(uid: string): HolderSummary {
	return { userId: uid, displayName: `Holder ${uid}`, accountCount: 1, activeAccountCount: 1, status: 'ACTIVE' }
}

function accountPage(uid: string, accounts: HolderAccountSummary[]): FinanceHolderAccountsPage {
	return {
		holder: { userId: uid, displayName: `Holder ${uid}` },
		items: accounts,
		page: 1,
		pageSize: 25,
		total: accounts.length
	}
}

function account(id: string): HolderAccountSummary {
	return {
		id,
		userId: 'alice',
		currency: 'IRR',
		minorUnit: 0,
		balance: { amountMinor: 150000, currency: 'IRR', minorUnit: 0 },
		active: true,
		version: 3,
		movementCount: 2,
		lastMovementAt: '2026-11-15T10:00:00+00:00'
	}
}

function ledgerPage(accountId: string, rows: FinanceLedgerRow[]): FinanceLedgerPage {
	return { account: account(accountId), items: rows, page: 1, pageSize: 25, total: rows.length }
}

function ledgerRow(id: string): FinanceLedgerRow {
	return {
		id,
		type: 'CHARGE',
		occurredAt: '2026-11-10T09:30:00+00:00',
		description: 'Monthly funding',
		sheet: null,
		approved: null,
		recharge: { amountMinor: 500000, currency: 'IRR', minorUnit: 0 },
		expense: null,
		balanceAfter: { amountMinor: 500000, currency: 'IRR', minorUnit: 0 },
		sourceRevisionId: null
	}
}

function sheetSummary(id: string): CompletedSheetSummary {
	return {
		id,
		reference: id,
		title: 'October travel',
		purchaser: { userId: 'alice', displayName: 'Alice' },
		totalTransactionCount: 10,
		approvedTransactionCount: 5,
		rejectedTransactionCount: 5,
		postedAmount: { amountMinor: 125000, currency: 'IRR', minorUnit: 0 },
		postingCount: 5,
		completedAt: '2026-11-16T08:00:00+00:00',
		status: 'COMPLETED_WITH_REJECTIONS'
	}
}

function sheetDetail(id: string): CompletedSheetDetail {
	return {
		...sheetSummary(id),
		accountId: 'acc-1',
		currency: 'IRR',
		minorUnit: 0,
		createdAt: '2026-11-01T08:00:00+00:00',
		firstSubmittedAt: '2026-11-02T08:00:00+00:00',
		financeStartedAt: '2026-11-10T08:00:00+00:00',
		transactions: []
	}
}

/** A manually resolved promise, for driving responses out of order. */
function deferred<T>() {
	let resolve!: (value: T) => void
	let reject!: (reason: unknown) => void
	const promise = new Promise<T>((res, rej) => {
		resolve = res
		reject = rej
	})
	return { promise, resolve, reject }
}

beforeEach(() => {
	setActivePinia(createPinia())
	listHoldersMock.mockReset()
	listHolderAccountsMock.mockReset()
	fetchLedgerMock.mockReset()
	listCompletedSheetsMock.mockReset()
	getCompletedSheetMock.mockReset()
})

describe('holder directory slice', () => {
	it('loads holders and pagination', async () => {
		listHoldersMock.mockResolvedValue(holderPage([holder('alice'), holder('bob')], 2))
		const store = useFinanceStore()
		await store.loadHolders()
		expect(store.holders).toHaveLength(2)
		expect(store.holdersTotal).toBe(2)
		expect(store.holdersPage).toBe(1)
		expect(store.holdersLoading).toBe(false)
		expect(store.holdersError).toBeNull()
	})

	it('exposes a loading state while the request is in flight', async () => {
		const pending = deferred<Page<HolderSummary>>()
		listHoldersMock.mockReturnValue(pending.promise)
		const store = useFinanceStore()
		const loading = store.loadHolders()
		expect(store.holdersLoading).toBe(true)
		pending.resolve(holderPage([]))
		await loading
		expect(store.holdersLoading).toBe(false)
	})

	it('records an error state and empties the page on failure', async () => {
		listHoldersMock.mockRejectedValue(new Error('pettycash.error.network'))
		const store = useFinanceStore()
		await store.loadHolders()
		expect(store.holdersLoading).toBe(false)
		expect(store.holdersError).toBe('pettycash.error.network')
		expect(store.holders).toEqual([])
	})

	it('updates pagination on page changes', async () => {
		listHoldersMock.mockResolvedValue({ ...holderPage([holder('carol')], 51), page: 3 })
		const store = useFinanceStore()
		await store.loadHolders(3)
		expect(store.holdersPage).toBe(3)
		expect(store.holdersTotal).toBe(51)
		expect(listHoldersMock).toHaveBeenCalledWith({ page: 3, pageSize: 25 })
	})
})

describe('holder selection boundary', () => {
	it('selecting a holder loads its accounts and holder identity', async () => {
		listHolderAccountsMock.mockResolvedValue(accountPage('alice', [account('acc-1'), account('acc-2')]))
		const store = useFinanceStore()
		await store.selectHolder('alice', 'Holder alice')
		expect(store.selectedHolderUid).toBe('alice')
		expect(store.selectedHolderName).toBe('Holder alice')
		expect(store.hasSelectedHolder).toBe(true)
		expect(store.holderAccounts).toHaveLength(2)
		expect(store.holderAccountsError).toBeNull()
	})

	it('selecting a holder clears the previous holder accounts synchronously', async () => {
		listHolderAccountsMock.mockResolvedValue(accountPage('alice', [account('acc-1')]))
		const store = useFinanceStore()
		await store.selectHolder('alice')
		expect(store.holderAccounts).toHaveLength(1)

		// The new selection replaces the dependent state before the read resolves.
		const pending = deferred<FinanceHolderAccountsPage>()
		listHolderAccountsMock.mockReturnValue(pending.promise)
		const selecting = store.selectHolder('bob')
		expect(store.holderAccounts).toEqual([])
		expect(store.holderAccountsPage).toBe(1)
		expect(store.holderAccountsTotal).toBe(0)
		pending.resolve(accountPage('bob', [account('acc-9')]))
		await selecting
		expect(store.holderAccounts).toHaveLength(1)
	})

	it('selecting a holder clears the ledger and selected account', async () => {
		listHolderAccountsMock.mockResolvedValue(accountPage('alice', [account('acc-1')]))
		fetchLedgerMock.mockResolvedValue(ledgerPage('acc-1', [ledgerRow('m-1')]))
		const store = useFinanceStore()
		await store.selectHolder('alice')
		await store.selectAccount('acc-1')
		expect(store.ledgerRows).toHaveLength(1)
		expect(store.selectedAccountId).toBe('acc-1')

		const pending = deferred<FinanceHolderAccountsPage>()
		listHolderAccountsMock.mockReturnValue(pending.promise)
		const selecting = store.selectHolder('bob')
		expect(store.selectedAccountId).toBeNull()
		expect(store.ledgerRows).toEqual([])
		expect(store.ledgerAccount).toBeNull()
		expect(store.ledgerTotal).toBe(0)
		pending.resolve(accountPage('bob', []))
		await selecting
	})

	it('clears everything when the holder selection is removed', async () => {
		listHolderAccountsMock.mockResolvedValue(accountPage('alice', [account('acc-1')]))
		const store = useFinanceStore()
		await store.selectHolder('alice')
		await store.selectHolder(null)
		expect(store.selectedHolderUid).toBeNull()
		expect(store.hasSelectedHolder).toBe(false)
		expect(store.holderAccounts).toEqual([])
	})

	it('accounts error state empties the page and never reads as success', async () => {
		listHolderAccountsMock.mockRejectedValue(new Error('pettycash.error.forbidden'))
		const store = useFinanceStore()
		await store.selectHolder('alice')
		expect(store.holderAccountsError).toBe('pettycash.error.forbidden')
		expect(store.holderAccounts).toEqual([])
	})
})

describe('stale response protection', () => {
	it('drops a holder-accounts response for a holder that is no longer selected', async () => {
		const aliceResponse = deferred<FinanceHolderAccountsPage>()
		listHolderAccountsMock.mockImplementation((uid: string) =>
			uid === 'alice' ? aliceResponse.promise : Promise.resolve(accountPage('bob', [account('acc-bob')]))
		)
		const store = useFinanceStore()
		const aliceLoad = store.selectHolder('alice')
		// The user moves on before alice's slow response arrives.
		await store.selectHolder('bob')
		aliceResponse.resolve(accountPage('alice', [account('acc-stale')]))
		await aliceLoad
		expect(store.selectedHolderUid).toBe('bob')
		expect(store.holderAccounts.map((a) => a.id)).toEqual(['acc-bob'])
		// The stale response must also not surface as an error against the current selection.
		expect(store.holderAccountsError).toBeNull()
	})

	it('drops a ledger response for an account that is no longer selected', async () => {
		listHolderAccountsMock.mockResolvedValue(accountPage('alice', [account('acc-1'), account('acc-2')]))
		const staleLedger = deferred<FinanceLedgerPage>()
		fetchLedgerMock.mockImplementation((id: string) =>
			id === 'acc-1'
				? staleLedger.promise
				: Promise.resolve(ledgerPage('acc-2', [ledgerRow('m-2')]))
		)
		const store = useFinanceStore()
		await store.selectHolder('alice')
		const staleLoad = store.selectAccount('acc-1')
		await store.selectAccount('acc-2')
		staleLedger.resolve(ledgerPage('acc-1', [ledgerRow('m-stale')]))
		await staleLoad
		expect(store.selectedAccountId).toBe('acc-2')
		expect(store.ledgerRows.map((r) => r.id)).toEqual(['m-2'])
		expect(store.ledgerError).toBeNull()
	})

	it('drops a completed-detail response for a sheet that is no longer open', async () => {
		const staleDetail = deferred<CompletedSheetDetail>()
		getCompletedSheetMock.mockImplementation((id: string) =>
			id === 'sheet-a'
				? staleDetail.promise
				: Promise.resolve(sheetDetail('sheet-b'))
		)
		const store = useFinanceStore()
		const staleOpen = store.openCompletedSheet('sheet-a')
		await store.openCompletedSheet('sheet-b')
		staleDetail.resolve(sheetDetail('sheet-a'))
		await staleOpen
		expect(store.completedSheetId).toBe('sheet-b')
		expect(store.completedSheetDetail?.id).toBe('sheet-b')
		expect(store.completedSheetError).toBeNull()
		expect(store.completedSheetLoading).toBe(false)
	})
})

describe('ledger slice', () => {
	it('loads the ledger with the account summary from the response', async () => {
		listHolderAccountsMock.mockResolvedValue(accountPage('alice', [account('acc-1')]))
		fetchLedgerMock.mockResolvedValue(ledgerPage('acc-1', [ledgerRow('m-1'), ledgerRow('m-2')]))
		const store = useFinanceStore()
		await store.selectHolder('alice')
		await store.selectAccount('acc-1')
		expect(store.ledgerRows).toHaveLength(2)
		expect(store.ledgerAccount?.id).toBe('acc-1')
		expect(store.ledgerLoading).toBe(false)
	})

	it('records a ledger error state without presenting stale rows', async () => {
		listHolderAccountsMock.mockResolvedValue(accountPage('alice', [account('acc-1')]))
		fetchLedgerMock.mockRejectedValueOnce(new Error('pettycash.error.notFound'))
		const store = useFinanceStore()
		await store.selectHolder('alice')
		await store.selectAccount('acc-1')
		expect(store.ledgerError).toBe('pettycash.error.notFound')
		expect(store.ledgerRows).toEqual([])
		// A retry that succeeds restores the rows and clears the error.
		fetchLedgerMock.mockResolvedValue(ledgerPage('acc-1', [ledgerRow('m-1')]))
		await store.loadLedger(1)
		expect(store.ledgerError).toBeNull()
		expect(store.ledgerRows).toHaveLength(1)
	})

	it('selecting no account clears the ledger', async () => {
		listHolderAccountsMock.mockResolvedValue(accountPage('alice', [account('acc-1')]))
		fetchLedgerMock.mockResolvedValue(ledgerPage('acc-1', [ledgerRow('m-1')]))
		const store = useFinanceStore()
		await store.selectHolder('alice')
		await store.selectAccount('acc-1')
		await store.selectAccount(null)
		expect(store.selectedAccountId).toBeNull()
		expect(store.ledgerRows).toEqual([])
	})
})

describe('completed Cost Sheet history slices', () => {
	it('loads the completed list and pagination', async () => {
		listCompletedSheetsMock.mockResolvedValue({
			items: [sheetSummary('sheet-1')],
			page: 2,
			pageSize: 25,
			total: 30
		})
		const store = useFinanceStore()
		await store.loadCompletedSheets(2)
		expect(store.completedSheets).toHaveLength(1)
		expect(store.completedSheetsPage).toBe(2)
		expect(store.completedSheetsTotal).toBe(30)
	})

	it('records a completed-list error state', async () => {
		listCompletedSheetsMock.mockRejectedValue(new Error('pettycash.error.network'))
		const store = useFinanceStore()
		await store.loadCompletedSheets()
		expect(store.completedSheetsError).toBe('pettycash.error.network')
		expect(store.completedSheets).toEqual([])
	})

	it('opening a sheet clears the previous detail synchronously and stores the new detail', async () => {
		getCompletedSheetMock.mockResolvedValueOnce(sheetDetail('sheet-a'))
		const store = useFinanceStore()
		await store.openCompletedSheet('sheet-a')
		expect(store.completedSheetDetail?.id).toBe('sheet-a')

		const pending = deferred<CompletedSheetDetail>()
		getCompletedSheetMock.mockReturnValue(pending.promise)
		const opening = store.openCompletedSheet('sheet-b')
		expect(store.completedSheetDetail).toBeNull()
		pending.resolve(sheetDetail('sheet-b'))
		await opening
		expect(store.completedSheetDetail?.id).toBe('sheet-b')
	})

	it('records a detail error state and closes cleanly', async () => {
		getCompletedSheetMock.mockRejectedValue(new Error('pettycash.error.notFound'))
		const store = useFinanceStore()
		await store.openCompletedSheet('sheet-x')
		expect(store.completedSheetError).toBe('pettycash.error.notFound')
		expect(store.completedSheetDetail).toBeNull()
		store.closeCompletedSheet()
		expect(store.completedSheetId).toBeNull()
		expect(store.completedSheetError).toBeNull()
		expect(store.completedSheetLoading).toBe(false)
	})
})

describe('resetErrors()', () => {
	it('clears every slice error', async () => {
		listHoldersMock.mockRejectedValueOnce(new Error('e1'))
		listCompletedSheetsMock.mockRejectedValueOnce(new Error('e2'))
		const store = useFinanceStore()
		await store.loadHolders()
		await store.loadCompletedSheets()
		expect(store.holdersError).toBe('e1')
		expect(store.completedSheetsError).toBe('e2')
		store.resetErrors()
		expect(store.holdersError).toBeNull()
		expect(store.completedSheetsError).toBeNull()
	})
})

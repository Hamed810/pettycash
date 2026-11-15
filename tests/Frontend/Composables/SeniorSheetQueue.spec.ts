// Feature 007 (T004): Senior queue derivation ships the same completeness/grouping/honesty rules as
// the shared `useSheetQueue` (single guard, never a second implementation) plus the pure modal
// filter/sort model. Directory note: the ledger's `Composable/` label is a typo — the repository
// convention is `tests/Frontend/Composables/`, and Vitest discovers `tests/Frontend/**/*.spec.ts`.
import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import {
	adaptSeniorSheetListRows,
	buildSeniorSheetQueue,
	checkSeniorSheetQueueCompleteness,
	deriveExpenseTypeFacets,
	filterSeniorSheetsByStatus,
	filterSeniorTransactions,
	searchSeniorSheets,
	sortSeniorTransactions,
} from '@/composables/useSeniorQueue'
import type { SeniorFilterState, SeniorSheetListRow, SeniorSort, TransactionSummary } from '@/types/api'

/** Code lines of the composable (comments excluded) — used by the no-vendor honesty assertion. */
function readComposableSource(): string[] {
	return readFileSync('src/composables/useSeniorQueue.ts', 'utf8')
		.split('\n')
		.filter((line) => !(/^\s*(\/\/|\*|\/\*)/.test(line) || line.trim() === ''))
}


function transaction(
	id: string,
	overrides: {
		sheetId?: string
		createdAt?: string
		amountMinor?: number
		currency?: string
		minorUnit?: number
		costCenter?: { id: string; code?: string; name: string }
		expenseType?: { id: string; name: string }
		description?: string
		evidenceCount?: number
		expenseDate?: string
	} = {}
): TransactionSummary {
	return {
		id,
		sheetId: overrides.sheetId ?? 'sheet-1',
		stage: 'SENIOR',
		status: 'AWAITING_SENIOR_REVIEW',
		finalOutcome: null,
		evidenceRequired: false,
		evidenceCount: overrides.evidenceCount ?? 0,
		version: 0,
		activeRevision: {
			id: `rev-${id}`,
			number: 1,
			expenseDate: overrides.expenseDate ?? '2026-10-01',
			amount: {
				amountMinor: overrides.amountMinor ?? 50_000,
				currency: overrides.currency ?? 'IRR',
				minorUnit: overrides.minorUnit ?? 0,
			},
			description: overrides.description ?? 'Pending expense',
			costCenter: {
				id: overrides.costCenter?.id ?? `cc-${overrides.costCenter?.code ?? 'CC-1'}`,
				code: overrides.costCenter?.code,
				name: overrides.costCenter?.name ?? 'Cost Center One',
			},
			expenseType: {
				id: overrides.expenseType?.id ?? 'et-1',
				name: overrides.expenseType?.name ?? 'Supplies',
			},
			createdBy: 'user1',
			createdAt: overrides.createdAt ?? '2026-10-01T10:00:00+00:00',
			active: true,
		},
	} as TransactionSummary
}

describe('checkSeniorSheetQueueCompleteness (shared guard)', () => {
	it('accepts a response that returned every row the server counted', () => {
		expect(checkSeniorSheetQueueCompleteness({ items: [transaction('a')], total: 1 }, 500))
			.toEqual({ complete: true, reason: 'complete' })
	})

	it('accepts an empty response only when the server counted nothing', () => {
		expect(checkSeniorSheetQueueCompleteness({ items: [], total: 0 }, 500))
			.toEqual({ complete: true, reason: 'empty' })
		expect(checkSeniorSheetQueueCompleteness({ items: [], total: 5 }, 500))
			.toEqual({ complete: false, reason: 'truncated' })
	})

	it('refuses a response whose total proves a later page exists', () => {
		expect(checkSeniorSheetQueueCompleteness({ items: [transaction('a')], total: 30 }, 500))
			.toEqual({ complete: false, reason: 'truncated' })
	})

	it('accepts a full page when the all-pages fetch covered the server total', () => {
		// `fetchAllSeniorSheets` follows `total` across pages, so a full page is no longer treated as
		// possibly truncated; only a set shorter than `total` is refused.
		const items = Array.from({ length: 500 }, (_, index) => transaction(`tx-${index}`))
		expect(checkSeniorSheetQueueCompleteness({ items, total: 500 }, 500))
			.toEqual({ complete: true, reason: 'complete' })
	})

	it('refuses a response without a usable total', () => {
		expect(checkSeniorSheetQueueCompleteness({ items: [transaction('a')], total: Number.NaN }, 500))
			.toEqual({ complete: false, reason: 'no-total' })
	})
})

describe('adaptSeniorSheetListRows', () => {
	function listRow(id: string, overrides: Partial<SeniorSheetListRow> = {}): SeniorSheetListRow {
		return {
			id,
			title: `Sheet ${id}`,
			ownerId: 'user1',
			ownerDisplayName: 'User One',
			currency: 'IRR',
			submittedTotalMinor: 75_000,
			submittedTotal: { amountMinor: 75_000, currency: 'IRR', minorUnit: 0 },
			submittedAt: '2026-10-01T10:00:00Z',
			version: 1,
			allTransactionsCostCenterDecided: true,
			reviewStatus: 'PENDING_REVIEW',
			latestActivityAt: '2026-10-02T10:00:00Z',
			progress: { awaitingSenior: 2, decidedBySenior: 1, total: 3 },
			...overrides,
		}
	}

	it('maps the server list row to the queue presentation without inventing transactions', () => {
		const row = listRow('sheet-1')
		const [item] = adaptSeniorSheetListRows([row])

		expect(item).toMatchObject({
			sheetId: 'sheet-1',
			sheet: row,
			transactions: [],
			transactionCount: 2,
			latestActivityAt: '2026-10-02T10:00:00Z',
			title: 'Sheet sheet-1',
			purchaser: 'User One',
			reviewStatus: 'PENDING_REVIEW',
			submittedAt: '2026-10-01T10:00:00Z',
		})
		// The amount cell is the server's submission snapshot, never a sum of absent transactions.
		expect(item?.totals).toEqual([{ currency: 'IRR', minorUnit: 0, amountMinor: 75_000 }])
		expect(item?.costCenters).toEqual([])
	})

	it('keeps a completed sheet with its honest zero pending count and no fabricated total', () => {
		const [item] = adaptSeniorSheetListRows([
			listRow('sheet-completed', {
				reviewStatus: 'REVIEW_COMPLETED',
				submittedTotal: null,
				submittedTotalMinor: null,
				progress: { awaitingSenior: 0, decidedBySenior: 3, total: 3 },
			}),
		])

		expect(item?.reviewStatus).toBe('REVIEW_COMPLETED')
		expect(item?.transactionCount).toBe(0)
		expect(item?.totals).toEqual([])
	})

	it('searches the list-row title and owner supplied by the server', () => {
		const items = adaptSeniorSheetListRows([
			listRow('sheet-alpha', { title: 'Travel October', ownerDisplayName: 'Ada Lovelace' }),
			listRow('sheet-beta', { title: 'Office supplies', ownerDisplayName: 'Grace Hopper' }),
		])

		expect(searchSeniorSheets(items, 'october').map((item) => item.sheetId)).toEqual(['sheet-alpha'])
		expect(searchSeniorSheets(items, 'grace').map((item) => item.sheetId)).toEqual(['sheet-beta'])
	})
})

describe('buildSeniorSheetQueue', () => {
	it('creates one row per sheet and counts de-duplicated transactions', () => {
		const sheets = buildSeniorSheetQueue([
			transaction('a', { sheetId: 'sheet-1' }),
			transaction('b', { sheetId: 'sheet-1' }),
			transaction('b', { sheetId: 'sheet-1', amountMinor: 99_999 }),
			transaction('c', { sheetId: 'sheet-2' }),
		])

		expect(sheets).toHaveLength(2)
		const first = sheets.find((sheet) => sheet.sheetId === 'sheet-1')
		expect(first?.transactionCount).toBe(2)
		expect(first?.transactions.map((item) => item.id).sort()).toEqual(['a', 'b'])
		// The first copy wins — a duplicate is never double-counted.
		expect(first?.transactions.find((item) => item.id === 'b')?.activeRevision.amount.amountMinor).toBe(50_000)
	})

	it('keeps an unresolvable sheet reference as its own explicit row', () => {
		const sheets = buildSeniorSheetQueue([
			transaction('a', { sheetId: '' }),
			transaction('b', { sheetId: 'sheet-2' }),
		])

		expect(sheets.map((sheet) => sheet.sheetId)).toEqual(['', 'sheet-2'])
	})

	it('sums each currency separately and never across currencies (006 FR-006-014)', () => {
		const [sheet] = buildSeniorSheetQueue([
			transaction('a', { amountMinor: 50_000, currency: 'IRR', minorUnit: 0 }),
			transaction('b', { amountMinor: 25_000, currency: 'IRR', minorUnit: 0 }),
			transaction('c', { amountMinor: 1_250, currency: 'USD', minorUnit: 2 }),
		])

		expect(sheet?.totals).toEqual([
			{ currency: 'IRR', minorUnit: 0, amountMinor: 75_000 },
			{ currency: 'USD', minorUnit: 2, amountMinor: 1_250 },
		])
	})

	it('collects the Senior cost-centre shape (id and name), distinct and sorted', () => {
		const [sheet] = buildSeniorSheetQueue([
			transaction('a', { costCenter: { id: 'cc-1', name: 'Operations' } }),
			transaction('b', { costCenter: { id: 'cc-1', name: 'Operations' } }),
			transaction('c', { costCenter: { id: 'cc-2', name: 'Travel' } }),
		])

		expect(sheet?.costCenters).toEqual([
			{ id: 'cc-1', name: 'Operations' },
			{ id: 'cc-2', name: 'Travel' },
		])
	})

	it('never fabricates sheet-wide eligibility or completion (FR-007-013 / owner D5)', () => {
		const [first] = buildSeniorSheetQueue([transaction('a'), transaction('b')])
		if (!first) throw new Error('expected a sheet row')

		// The derived row exposes exactly the approved presentation fields — never a backend-gate
		// fact, a sheet verdict, or a fabricated completed status at the group level.
		expect(Object.keys(first).sort()).toEqual(
			['costCenters', 'latestActivityAt', 'sheetId', 'totals', 'transactionCount', 'transactions'].sort()
		)
		expect(first).not.toHaveProperty('allTransactionsCostCenterDecided')
		expect(first).not.toHaveProperty('reviewStatus')
		expect(first).not.toHaveProperty('status')

		// The per-transaction statuses that legitimately appear come from the loaded response; the
		// queue itself never supplies the owner-D1 "Waiting for Cost Center Review" sheet label and
		// never derives "Review Completed".
		const rendered = (JSON.stringify(first) ?? '').toLowerCase()
		expect(rendered).not.toContain('waiting for cost center review')
		expect(rendered).not.toContain('review completed')
	})

	it('keeps one presentation row per transaction — decisions are never rolled up (FR-007-004)', () => {
		const sheets = buildSeniorSheetQueue([
			transaction('a', { sheetId: 's' }),
			transaction('b', { sheetId: 's' }),
			transaction('c', { sheetId: 's' }),
		])
		const [sheet] = sheets

		// A sheet row is an aggregated GROUP, but its transactions stay individual rows: the
		// derivation never collapses them into a sheet-level verdict.
		expect(sheet?.transactions).toHaveLength(3)
		expect(sheet?.transactions.map((item) => item.id).sort()).toEqual(['a', 'b', 'c'])
	})
})

describe('searchSeniorSheets', () => {
	const sheets = buildSeniorSheetQueue([
		transaction('a', { sheetId: 'e9c2f997-sheet', costCenter: { id: 'cc-1', name: 'Development' } }),
		transaction('b', { sheetId: 'f2fd300-sheet', costCenter: { id: 'cc-2', name: 'Operations' } }),
	])

	it('searches the sheet reference case-insensitively', () => {
		expect(searchSeniorSheets(sheets, 'E9C2').map((sheet) => sheet.sheetId)).toEqual(['e9c2f997-sheet'])
	})

	it('searches the cost-centre name', () => {
		expect(searchSeniorSheets(sheets, 'operations').map((sheet) => sheet.sheetId)).toEqual(['f2fd300-sheet'])
	})

	it('returns everything for a blank query and nothing for a miss', () => {
		expect(searchSeniorSheets(sheets, '   ')).toHaveLength(2)
		expect(searchSeniorSheets(sheets, 'purchaser-name')).toEqual([])
	})
})

describe('filterSeniorSheetsByStatus', () => {
	const sheets = buildSeniorSheetQueue([
		transaction('a', { sheetId: 's-1' }),
		transaction('b', { sheetId: 's-2' }),
		transaction('c', { sheetId: 's-3' }),
	])

	it('keeps everything on the All tab', () => {
		expect(filterSeniorSheetsByStatus(sheets, 'all', () => 'PENDING_REVIEW')).toHaveLength(3)
	})

	it('keeps only server-supplied completed sheets on the completed tab', () => {
		const completed = filterSeniorSheetsByStatus(
			sheets,
			'completed',
			(item) => (item.sheetId === 's-2' ? 'REVIEW_COMPLETED' : 'PENDING_REVIEW')
		)
		expect(completed.map((sheet) => sheet.sheetId)).toEqual(['s-2'])
	})

	it('treats an unproven status as pending — a completed status is never assumed (FR-007-013)', () => {
		const awaiting = filterSeniorSheetsByStatus(sheets, 'pending', () => null)
		expect(awaiting.map((sheet) => sheet.sheetId)).toEqual(['s-1', 's-2', 's-3'])

		const completed = filterSeniorSheetsByStatus(sheets, 'completed', () => null)
		expect(completed).toEqual([])
	})
})

describe('filterSeniorTransactions', () => {
	const rows = [
		transaction('tx-1', {
			description: 'Office supplies',
			expenseType: { id: 'et-1', name: 'Supplies' },
			amountMinor: 20_000,
			currency: 'IRR',
			minorUnit: 0,
			evidenceCount: 2,
		}),
		transaction('tx-2', {
			description: 'Air ticket to Tehran',
			expenseType: { id: 'et-2', name: 'Travel' },
			amountMinor: 5_000,
			currency: 'IRR',
			minorUnit: 0,
			evidenceCount: 0,
		}),
		transaction('tx-3', {
			description: 'Software subscription',
			expenseType: { id: 'et-3', name: 'Software' },
			amountMinor: 1_250,
			currency: 'USD',
			minorUnit: 2,
			evidenceCount: 1,
		}),
	]

	function make(filter: SeniorFilterState): SeniorFilterState {
		return filter
	}

	it('searches by transaction id or description, case-insensitive', () => {
		expect(filterSeniorTransactions(rows, make({ query: 'TX-2' })).map((row) => row.id)).toEqual(['tx-2'])
		expect(filterSeniorTransactions(rows, make({ query: 'air ticket' })).map((row) => row.id)).toEqual(['tx-2'])
		expect(filterSeniorTransactions(rows, make({ query: 'software' })).map((row) => row.id)).toEqual(['tx-3'])
		expect(filterSeniorTransactions(rows, make({ query: 'does-not-exist' }))).toEqual([])
	})

	it('filters by expense-type multi-select', () => {
		expect(filterSeniorTransactions(rows, make({ expenseTypeIds: ['et-1', 'et-3'] })).map((row) => row.id))
			.toEqual(['tx-1', 'tx-3'])
	})

	it('keeps only the approved filter fields — search and expense type', () => {
		// The owner-approved toolbar removed the currency, amount and attachment filters; their
		// state must not survive in the pure model either.
		const source = readComposableSource().join('\n')
		for (const obsolete of ['amountMinMinor', 'amountMaxMinor', 'hasAttachments', 'currencyCodes']) {
			expect(source, obsolete).not.toContain(obsolete)
		}
	})

	it('applies no vendor field, filter, or placeholder anywhere (owner D7)', () => {
		// A vendor key cannot survive the typed filter model…
		const state: SeniorFilterState = {
			query: 'x',
			description: 'x',
			expenseTypeIds: ['et-1'],
		}
		expect(Object.keys(state).some((key) => key.toLowerCase().includes('vendor'))).toBe(false)

		// …and no code line of the composable names a vendor field at all (owner D7).
		const source = readComposableSource()
		expect(source.some((line) => /vendor/i.test(line))).toBe(false)
	})
})

describe('deriveExpenseTypeFacets', () => {
	it('derives quick category counts from the filtered set', () => {
		const rows = [
			transaction('a', { expenseType: { id: 'et-1', name: 'Supplies' } }),
			transaction('b', { expenseType: { id: 'et-2', name: 'Travel' } }),
			transaction('c', { expenseType: { id: 'et-1', name: 'Supplies' } }),
		]
		expect(deriveExpenseTypeFacets(rows, {})).toEqual([
			{ id: 'et-1', name: 'Supplies', count: 2 },
			{ id: 'et-2', name: 'Travel', count: 1 },
		])
	})

	it('keeps every category visible while one is selected (type filter itself never hides a facet)', () => {
		const rows = [
			transaction('a', { expenseType: { id: 'et-1', name: 'Supplies' } }),
			transaction('b', { expenseType: { id: 'et-2', name: 'Travel' } }),
		]
		expect(deriveExpenseTypeFacets(rows, { expenseTypeIds: ['et-1'] })).toEqual([
			{ id: 'et-1', name: 'Supplies', count: 1 },
			{ id: 'et-2', name: 'Travel', count: 1 },
		])
	})
})

describe('sortSeniorTransactions', () => {
	const rows = [
		transaction('b', { amountMinor: 5_000, currency: 'IRR', expenseDate: '2026-10-02', expenseType: { id: 't', name: 'Travel' } }),
		transaction('a', { amountMinor: 20_000, currency: 'IRR', expenseDate: '2026-10-01', expenseType: { id: 's', name: 'Software' } }),
		transaction('c', { amountMinor: 5_000, currency: 'USD', expenseDate: '2026-10-03', expenseType: { id: 'u', name: 'Supplies' } }),
	]

	it('sorts by amount ascending and descending, then currency code, then id', () => {
		const sort: SeniorSort = { key: 'amount', direction: 'asc' }
		expect(sortSeniorTransactions(rows, sort).map((row) => row.id)).toEqual(['b', 'c', 'a'])

		const desc: SeniorSort = { key: 'amount', direction: 'desc' }
		expect(sortSeniorTransactions(rows, desc).map((row) => row.id)).toEqual(['a', 'b', 'c'])
	})

	it('sorts by expense date using ISO8601 string order', () => {
		const asc: SeniorSort = { key: 'expenseDate', direction: 'asc' }
		expect(sortSeniorTransactions(rows, asc).map((row) => row.id)).toEqual(['a', 'b', 'c'])

		const desc: SeniorSort = { key: 'expenseDate', direction: 'desc' }
		expect(sortSeniorTransactions(rows, desc).map((row) => row.id)).toEqual(['c', 'b', 'a'])
	})

	it('sorts by expense type name ascending and descending', () => {
		const asc: SeniorSort = { key: 'expenseType', direction: 'asc' }
		expect(sortSeniorTransactions(rows, asc).map((row) => row.id)).toEqual(['a', 'c', 'b'])

		const desc: SeniorSort = { key: 'expenseType', direction: 'desc' }
		expect(sortSeniorTransactions(rows, desc).map((row) => row.id)).toEqual(['b', 'c', 'a'])
	})

	it('breaks ties stably by transaction id', () => {
		const ties = [
			transaction('b', { amountMinor: 5_000, currency: 'USD', expenseDate: '2026-10-03', expenseType: { id: 'u', name: 'Supplies' } }),
			transaction('a', { amountMinor: 5_000, currency: 'USD', expenseDate: '2026-10-03', expenseType: { id: 'u', name: 'Supplies' } }),
		]
		const asc: SeniorSort = { key: 'amount', direction: 'asc' }
		expect(sortSeniorTransactions(ties, asc).map((row) => row.id)).toEqual(['a', 'b'])
	})

	it('does not mutate the input, and does not re-request anything (pure)', () => {
		const before = rows.map((row) => row.id).join(',')
		const sorted = sortSeniorTransactions(rows, { key: 'expenseType', direction: 'desc' })
		expect(rows.map((row) => row.id).join(',')).toBe(before)
		expect(sorted.length).toBe(rows.length)
	})
})

describe('US4 filter + sort composition and two-level placement (T023)', () => {
	it('composes filter then sort into the exact ordered ids the export selection submits (T021/T022)', () => {
		const rows = [
			transaction('tx-1', {
				description: 'Office supplies',
				expenseType: { id: 'et-1', name: 'Supplies' },
				amountMinor: 20_000,
				currency: 'IRR',
				evidenceCount: 1,
				expenseDate: '2026-10-02',
			}),
			transaction('tx-2', {
				description: 'Laptop',
				expenseType: { id: 'et-1', name: 'Supplies' },
				amountMinor: 30_000,
				currency: 'IRR',
				evidenceCount: 1,
				expenseDate: '2026-10-01',
			}),
			transaction('tx-3', {
				description: 'Flight',
				expenseType: { id: 'et-2', name: 'Travel' },
				amountMinor: 5_000,
				currency: 'IRR',
				evidenceCount: 1,
				expenseDate: '2026-10-03',
			}),
			transaction('tx-4', {
				description: 'Coffee',
				expenseType: { id: 'et-1', name: 'Supplies' },
				amountMinor: 2_000,
				currency: 'IRR',
				evidenceCount: 0,
				expenseDate: '2026-10-04',
			}),
		]

		const filtered = filterSeniorTransactions(rows, { expenseTypeIds: ['et-1'] })
		expect(filtered.map((row) => row.id)).toEqual(['tx-1', 'tx-2', 'tx-4'])

		const sorted = sortSeniorTransactions(filtered, { key: 'amount', direction: 'asc' })
		// The export selection/order is exactly this filtered + sorted sequence (FR-007-044).
		expect(sorted.map((row) => row.id)).toEqual(['tx-4', 'tx-1', 'tx-2'])
	})

	it('keeps the queue at the sheet level — search and status tabs only, never transaction controls (owner D2/FR-007-041)', () => {
		const sheets = buildSeniorSheetQueue([
			transaction('a', { sheetId: 's-1', description: 'office supplies', expenseType: { id: 'et-1', name: 'Supplies' } }),
			transaction('b', { sheetId: 's-2', description: 'air travel', expenseType: { id: 'et-2', name: 'Travel' } }),
		])

		// The queue surface narrows SHEETS only: a transaction-only term matches nothing at the queue
		// even when a sheet carries it, because the sheet row exposes no transaction vocabulary.
		expect(searchSeniorSheets(sheets, 'air travel')).toEqual([])
		// Status is server-supplied too — the completed tab never invents completion.
		expect(filterSeniorSheetsByStatus(sheets, 'completed', () => null)).toEqual([])

		// The same term DOES match the transaction-level model, which belongs to the modal's set.
		const all = [...(sheets[0]?.transactions ?? []), ...(sheets[1]?.transactions ?? [])]
		expect(filterSeniorTransactions(all, { query: 'air travel' }).map((row) => row.id)).toEqual(['b'])
	})
})

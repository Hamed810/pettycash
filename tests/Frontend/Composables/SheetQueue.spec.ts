import { describe, expect, it } from 'vitest'
import {
	buildSheetQueue,
	checkSheetQueueCompleteness,
	dedupeTransactions,
	searchSheetQueue,
	sortSheetQueue,
	totalPendingByCurrency,
} from '@/composables/useSheetQueue'
import type { TransactionSummary } from '@/types/api'

function transaction(
	id: string,
	overrides: {
		sheetId?: string
		createdAt?: string
		amountMinor?: number
		currency?: string
		minorUnit?: number
		costCenter?: { code: string; name: string }
		description?: string
	} = {}
): TransactionSummary {
	return {
		id,
		sheetId: overrides.sheetId ?? 'sheet-1',
		stage: 'COST_CENTER',
		status: 'AWAITING_COST_CENTER_REVIEW',
		finalOutcome: null,
		evidenceRequired: false,
		evidenceCount: 0,
		version: 0,
		activeRevision: {
			id: `rev-${id}`,
			number: 1,
			expenseDate: '2026-10-01',
			amount: {
				amountMinor: overrides.amountMinor ?? 50_000,
				currency: overrides.currency ?? 'IRR',
				minorUnit: overrides.minorUnit ?? 0,
			},
			description: overrides.description ?? 'Pending expense',
			costCenter: {
				id: `cc-${overrides.costCenter?.code ?? 'CC-1'}`,
				code: overrides.costCenter?.code ?? 'CC-1',
				name: overrides.costCenter?.name ?? 'Cost Center One',
			},
			expenseType: { id: 'et-1', name: 'Supplies' },
			createdBy: 'user1',
			createdAt: overrides.createdAt ?? '2026-10-01T10:00:00+00:00',
			active: true,
		},
	} as TransactionSummary
}

describe('checkSheetQueueCompleteness', () => {
	it('accepts a response that returned every row the server counted', () => {
		expect(checkSheetQueueCompleteness({ items: [transaction('a')], total: 1 }, 500))
			.toEqual({ complete: true, reason: 'complete' })
	})

	it('accepts an empty response only when the server counted nothing', () => {
		expect(checkSheetQueueCompleteness({ items: [], total: 0 }, 500))
			.toEqual({ complete: true, reason: 'empty' })
		expect(checkSheetQueueCompleteness({ items: [], total: 5 }, 500))
			.toEqual({ complete: false, reason: 'truncated' })
	})

	it('refuses a response whose total proves a later page exists', () => {
		expect(checkSheetQueueCompleteness({ items: [transaction('a'), transaction('b')], total: 30 }, 500))
			.toEqual({ complete: false, reason: 'truncated' })
	})

	it('refuses a full page even when total agrees with it', () => {
		const items = Array.from({ length: 500 }, (_, index) => transaction(`tx-${index}`))
		expect(checkSheetQueueCompleteness({ items, total: 500 }, 500))
			.toEqual({ complete: false, reason: 'at-threshold' })
	})

	it('refuses a response without a usable total', () => {
		expect(checkSheetQueueCompleteness({ items: [transaction('a')], total: Number.NaN }, 500))
			.toEqual({ complete: false, reason: 'no-total' })
	})
})

describe('dedupeTransactions', () => {
	it('counts a transaction once even when the response repeats it', () => {
		const unique = dedupeTransactions([
			transaction('a'),
			transaction('b'),
			transaction('a', { amountMinor: 9_999 }),
		])

		expect(unique.map((item) => item.id)).toEqual(['a', 'b'])
		expect(unique[0]?.activeRevision.amount.amountMinor).toBe(50_000)
	})
})

describe('buildSheetQueue', () => {
	it('creates one row per sheet and counts de-duplicated transactions', () => {
		const sheets = buildSheetQueue([
			transaction('a', { sheetId: 'sheet-1' }),
			transaction('b', { sheetId: 'sheet-1' }),
			transaction('b', { sheetId: 'sheet-1' }),
			transaction('c', { sheetId: 'sheet-2' }),
		])

		expect(sheets).toHaveLength(2)
		const first = sheets.find((sheet) => sheet.sheetId === 'sheet-1')
		expect(first?.transactionCount).toBe(2)
		expect(first?.transactions.map((item) => item.id).sort()).toEqual(['a', 'b'])
	})

	it('keeps an unresolvable sheet reference as its own explicit row', () => {
		const sheets = buildSheetQueue([
			transaction('a', { sheetId: '' }),
			transaction('b', { sheetId: 'sheet-2' }),
		])

		expect(sheets.map((sheet) => sheet.sheetId)).toEqual(['', 'sheet-2'])
	})

	it('sums each currency separately and never across currencies', () => {
		const [sheet] = buildSheetQueue([
			transaction('a', { sheetId: 'sheet-1', amountMinor: 50_000, currency: 'IRR', minorUnit: 0 }),
			transaction('b', { sheetId: 'sheet-1', amountMinor: 25_000, currency: 'IRR', minorUnit: 0 }),
			transaction('c', { sheetId: 'sheet-1', amountMinor: 1_250, currency: 'USD', minorUnit: 2 }),
		])

		expect(sheet?.totals).toEqual([
			{ currency: 'IRR', minorUnit: 0, amountMinor: 75_000 },
			{ currency: 'USD', minorUnit: 2, amountMinor: 1_250 },
		])
	})

	it('uses the newest parseable transaction timestamp as latest activity', () => {
		const [sheet] = buildSheetQueue([
			transaction('a', { createdAt: '2026-10-01T10:00:00+00:00' }),
			transaction('b', { createdAt: '2026-10-03T10:00:00+00:00' }),
			transaction('c', { createdAt: 'not-a-date' }),
		])

		expect(sheet?.latestActivityAt).toBe('2026-10-03T10:00:00.000Z')
		expect(sheet?.transactions[0]?.id).toBe('b')
	})

	it('reports no latest activity rather than fabricating one', () => {
		const [sheet] = buildSheetQueue([transaction('a', { createdAt: '' })])
		expect(sheet?.latestActivityAt).toBeNull()
	})

	it('collects the cost centres present in the pending set only', () => {
		const [sheet] = buildSheetQueue([
			transaction('a', { costCenter: { code: 'CC-1', name: 'Operations' } }),
			transaction('b', { costCenter: { code: 'CC-1', name: 'Operations' } }),
			transaction('c', { costCenter: { code: 'CC-2', name: 'Travel' } }),
		])

		expect(sheet?.costCenters).toEqual([
			{ code: 'CC-1', name: 'Operations' },
			{ code: 'CC-2', name: 'Travel' },
		])
	})
})

describe('searchSheetQueue', () => {
	const sheets = buildSheetQueue([
		transaction('a', { sheetId: 'e9c2f997-sheet', costCenter: { code: 'DEV-T060', name: 'Development' } }),
		transaction('b', { sheetId: 'f2fd300-sheet', costCenter: { code: 'E2E-CC-1', name: 'Operations' } }),
	])

	it('searches the sheet reference case-insensitively', () => {
		expect(searchSheetQueue(sheets, 'E9C2').map((sheet) => sheet.sheetId)).toEqual(['e9c2f997-sheet'])
	})

	it('searches the cost centre code and name', () => {
		expect(searchSheetQueue(sheets, 'dev-t060').map((sheet) => sheet.sheetId)).toEqual(['e9c2f997-sheet'])
		expect(searchSheetQueue(sheets, 'operations').map((sheet) => sheet.sheetId)).toEqual(['f2fd300-sheet'])
	})

	it('returns everything for a blank query and nothing for a miss', () => {
		expect(searchSheetQueue(sheets, '   ')).toHaveLength(2)
		expect(searchSheetQueue(sheets, 'purchaser-name')).toEqual([])
	})
})

describe('sortSheetQueue', () => {
	const newest = buildSheetQueue([
		transaction('a', { sheetId: 'a', createdAt: '2026-10-01T10:00:00+00:00' }),
		transaction('b', { sheetId: 'b', createdAt: '2026-10-03T10:00:00+00:00' }),
		transaction('c', { sheetId: 'c', createdAt: '2026-10-02T10:00:00+00:00' }),
	])
	const mixed = [
		...newest.filter((sheet) => sheet.sheetId === 'a'),
		...buildSheetQueue([
			transaction('b1', { sheetId: 'b', createdAt: '2026-10-03T10:00:00+00:00' }),
			transaction('b2', { sheetId: 'b', createdAt: '2026-10-03T09:00:00+00:00' }),
		]),
	]

	it('orders by latest activity newest or oldest first', () => {
		expect(sortSheetQueue(newest, 'newest').map((sheet) => sheet.sheetId)).toEqual(['b', 'c', 'a'])
		expect(sortSheetQueue(newest, 'oldest').map((sheet) => sheet.sheetId)).toEqual(['a', 'c', 'b'])
	})

	it('orders by pending transaction count', () => {
		expect(sortSheetQueue(mixed, 'mostTransactions').map((sheet) => sheet.sheetId)).toEqual(['b', 'a'])
	})

	it('orders by sheet reference', () => {
		expect(sortSheetQueue(newest, 'reference').map((sheet) => sheet.sheetId)).toEqual(['a', 'b', 'c'])
	})
})

describe('totalPendingByCurrency', () => {
	it('aggregates every sheet without merging currencies', () => {
		const sheets = buildSheetQueue([
			transaction('a', { sheetId: 'a', amountMinor: 10_000, currency: 'IRR', minorUnit: 0 }),
			transaction('b', { sheetId: 'b', amountMinor: 2_500, currency: 'USD', minorUnit: 2 }),
			transaction('c', { sheetId: 'b', amountMinor: 5_000, currency: 'IRR', minorUnit: 0 }),
		])

		expect(totalPendingByCurrency(sheets)).toEqual([
			{ currency: 'IRR', minorUnit: 0, amountMinor: 15_000 },
			{ currency: 'USD', minorUnit: 2, amountMinor: 2_500 },
		])
	})
})

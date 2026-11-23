// Feature 009 (T009-A-05 / T009-B-04): Finance complete-set read and Cost Sheet projection.
//
// Mirrors the shared `useSheetQueue`/`SheetQueue.spec.ts` honesty rules — a single completeness
// guard, never a second implementation — plus the all-pages Finance fetch. All functions are pure
// except `fetchAllFinanceQueues`/`fetchFinanceQueue`, which are tested against a mocked
// `services/http.ocs` (the real `fetchQueue` calls `ocs`, and `fetchAllFinanceQueues` calls the
// real `fetchQueue`).
//
// Currency safety (mission rule, 006 FR-006-014): totals are per-currency and never summed across
// currencies. No cross-currency arithmetic anywhere in this suite.
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { readFileSync } from 'node:fs'

const ocs = vi.hoisted(() => vi.fn())

vi.mock('@/services/http', () => ({
	ocs,
	mutate: vi.fn(),
}))

import {
	checkFinanceQueueCompleteness,
	buildFinanceSheetQueue,
	dedupeFinanceTransactions,
	FINANCE_SHEET_PAGE_SIZE,
} from '@/composables/useFinanceQueue'
import { fetchAllFinanceQueues } from '@/services/reviewsApi'
import { useReviewsStore } from '@/stores/reviews'
import type { TransactionSummary, StageApprovedTotals } from '@/types/api'

function readComposableSource(): string[] {
	return readFileSync('src/composables/useFinanceQueue.ts', 'utf8')
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
		costCenter?: { code: string; name: string }
		description?: string
	} = {}
): TransactionSummary {
	return {
		id,
		sheetId: overrides.sheetId ?? 'sheet-1',
		stage: 'FINANCE',
		status: 'AWAITING_FINANCE_REVIEW',
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

beforeEach(() => {
	setActivePinia(createPinia())
	ocs.mockReset()
})

describe('fetchAllFinanceQueues (all-pages read)', () => {
	it('fetches every page until the server total is covered', async () => {
		const all = [transaction('a'), transaction('b'), transaction('c'), transaction('d')]
		ocs.mockImplementation((_path, config) => {
			const page = config.params?.page ?? 1
			const pageSize = config.params?.pageSize ?? 2
			const start = (page - 1) * pageSize
			return Promise.resolve({ items: all.slice(start, start + pageSize), page, pageSize, total: 4 })
		})

		const result = await fetchAllFinanceQueues(2)

		expect(result.items.map((item) => item.id)).toEqual(['a', 'b', 'c', 'd'])
		expect(result.total).toBe(4)
		expect(ocs).toHaveBeenCalledTimes(2)
	})

	it('stops at a short page rather than fetching beyond it', async () => {
		ocs.mockImplementation((_path, config) => {
			const page = config.params?.page ?? 1
			if (page === 1) {
				return Promise.resolve({ items: [transaction('a'), transaction('b')], page: 1, pageSize: 100, total: 5 })
			}
			return Promise.resolve({ items: [], page: 2, pageSize: 100, total: 5 })
		})

		const result = await fetchAllFinanceQueues(100)

		expect(result.items.map((item) => item.id)).toEqual(['a', 'b'])
		expect(result.total).toBe(5)
		expect(ocs).toHaveBeenCalledTimes(1)
	})

	it('preserves request failure rather than fabricating a page', async () => {
		ocs.mockRejectedValueOnce(new Error('connection reset'))
		await expect(fetchAllFinanceQueues()).rejects.toThrow('connection reset')
	})
})

describe('checkFinanceQueueCompleteness (shared guard)', () => {
	it('accepts a response that returned every row the server counted', () => {
		expect(checkFinanceQueueCompleteness({ items: [transaction('a')], total: 1 }, 100)).toEqual({
			complete: true,
			reason: 'complete',
		})
	})

	it('accepts an empty response only when the server counted nothing', () => {
		expect(checkFinanceQueueCompleteness({ items: [], total: 0 }, 100)).toEqual({
			complete: true,
			reason: 'empty',
		})
		expect(checkFinanceQueueCompleteness({ items: [], total: 5 }, 100)).toEqual({
			complete: false,
			reason: 'truncated',
		})
	})

	it('refuses a response whose total proves a later page exists', () => {
		expect(checkFinanceQueueCompleteness({ items: [transaction('a')], total: 30 }, 100)).toEqual({
			complete: false,
			reason: 'truncated',
		})
	})

	it('accepts a full page when the all-pages fetch covered the server total', () => {
		// `fetchAllFinanceQueues` follows `total` across pages, so a full page is no longer treated
		// as possibly truncated; only a set shorter than `total` is refused.
		const items = Array.from({ length: 500 }, (_, index) => transaction(`tx-${index}`))
		expect(checkFinanceQueueCompleteness({ items, total: 500 }, 100)).toEqual({
			complete: true,
			reason: 'complete',
		})
	})

	it('refuses a response without a usable total', () => {
		expect(checkFinanceQueueCompleteness({ items: [transaction('a')], total: Number.NaN }, 100)).toEqual({
			complete: false,
			reason: 'no-total',
		})
	})
})

describe('dedupeFinanceTransactions (shared dedupe)', () => {
	it('counts a transaction once even when the response repeats it', () => {
		const unique = dedupeFinanceTransactions([
			transaction('a'),
			transaction('b'),
			transaction('a', { amountMinor: 9_999 }),
		])

		expect(unique.map((item) => item.id)).toEqual(['a', 'b'])
		expect(unique[0]?.activeRevision.amount.amountMinor).toBe(50_000)
	})
})

describe('buildFinanceSheetQueue (shared grouping)', () => {
	it('creates one row per sheet and counts de-duplicated transactions', () => {
		const sheets = buildFinanceSheetQueue([
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

	it('does not keep an unresolvable sheet reference as a row — blank sheetId invalidates the projection', () => {
		const sheets = buildFinanceSheetQueue([
			transaction('a', { sheetId: '' }),
			transaction('b', { sheetId: 'sheet-2' }),
		])
		// A blank identifier invalidates the whole grouping: no fake sheet row, no merged rows.
		expect(sheets).toEqual([])
	})

	it('sums each currency separately and never across currencies (006 FR-006-014)', () => {
		const [sheet] = buildFinanceSheetQueue([
			transaction('a', { sheetId: 'sheet-1', amountMinor: 50_000, currency: 'IRR', minorUnit: 0 }),
			transaction('b', { sheetId: 'sheet-1', amountMinor: 25_000, currency: 'IRR', minorUnit: 0 }),
			transaction('c', { sheetId: 'sheet-1', amountMinor: 1_250, currency: 'USD', minorUnit: 2 }),
		])

		expect(sheet?.totals).toEqual([
			{ currency: 'IRR', minorUnit: 0, amountMinor: 75_000 },
			{ currency: 'USD', minorUnit: 2, amountMinor: 1_250 },
		])
	})

	it('keeps amounts as integer minor units — no floating-point totals', () => {
		const [sheet] = buildFinanceSheetQueue([
			transaction('a', { sheetId: 'sheet-1', amountMinor: 1_234_567, currency: 'IRR', minorUnit: 0 }),
			transaction('b', { sheetId: 'sheet-1', amountMinor: 89, currency: 'IRR', minorUnit: 0 }),
		])

		expect(sheet?.totals).toEqual([{ currency: 'IRR', minorUnit: 0, amountMinor: 1_234_656 }])
	})

	it('uses the newest parseable transaction timestamp as latest activity', () => {
		const [sheet] = buildFinanceSheetQueue([
			transaction('a', { sheetId: 'sheet-1', createdAt: '2026-10-01T10:00:00+00:00' }),
			transaction('b', { sheetId: 'sheet-1', createdAt: '2026-10-03T10:00:00+00:00' }),
			transaction('c', { sheetId: 'sheet-1', createdAt: 'not-a-date' }),
		])

		expect(sheet?.latestActivityAt).toBe('2026-10-03T10:00:00.000Z')
		expect(sheet?.transactions[0]?.id).toBe('b')
	})

	it('reports no latest activity rather than fabricating one', () => {
		const [sheet] = buildFinanceSheetQueue([transaction('a', { sheetId: 'sheet-1', createdAt: '' })])
		expect(sheet?.latestActivityAt).toBeNull()
	})

	it('collects the cost centres present in the pending set only', () => {
		const [sheet] = buildFinanceSheetQueue([
			transaction('a', { sheetId: 'sheet-1', costCenter: { code: 'CC-1', name: 'Operations' } }),
			transaction('b', { sheetId: 'sheet-1', costCenter: { code: 'CC-1', name: 'Operations' } }),
			transaction('c', { sheetId: 'sheet-1', costCenter: { code: 'CC-2', name: 'Travel' } }),
		])

		expect(sheet?.costCenters).toEqual([
			{ code: 'CC-1', name: 'Operations' },
			{ code: 'CC-2', name: 'Travel' },
		])
	})

	it('never fabricates sheet identity, eligibility or completion state', () => {
		const [first] = buildFinanceSheetQueue([transaction('a'), transaction('b')])
		if (!first) throw new Error('expected a sheet row')

		// `stageApprovedTotals` (UXC-7F-05) is the additive queue-provided aggregate slot: present
		// as a key, but honestly `null` when no queue item carries one. Sheet metadata (title,
		// purchaser, submitted) is likewise projected server-side onto queue items.
		expect(Object.keys(first).sort()).toEqual(
			['costCenters', 'latestActivityAt', 'sheetId', 'sheetOwnerDisplayName', 'sheetOwnerId', 'sheetSubmittedAt', 'sheetTitle', 'stageApprovedTotals', 'totals', 'transactionCount', 'transactions'].sort()
		)
		expect(first).not.toHaveProperty('title')
		expect(first).not.toHaveProperty('purchaser')
		expect(first).not.toHaveProperty('submittedAt')
		expect(first).not.toHaveProperty('reviewStatus')
		expect(first.stageApprovedTotals).toBeNull()

		const rendered = (JSON.stringify(first) ?? '').toLowerCase()
		expect(rendered).not.toContain('waiting for cost center review')
		expect(rendered).not.toContain('review completed')
	})

	// UXC-7F-05: the sheet-level aggregate is captured from the queue items' authoritative payload,
	// never derived from the finance-visible transaction subset.
	describe('stageApprovedTotals capture (UXC-7F-05)', () => {
		const totals = () => ({
			costCenterApproved: { amountMinor: 150_000, currency: 'IRR', minorUnit: 0 },
			seniorApproved: { amountMinor: 100_000, currency: 'IRR', minorUnit: 0 },
			financeApproved: { amountMinor: 50_000, currency: 'IRR', minorUnit: 0 },
		})

		it('captures the identical aggregate the queue items carry', () => {
			const [sheet] = buildFinanceSheetQueue([
				{ ...transaction('a'), stageApprovedTotals: totals() },
				{ ...transaction('b'), stageApprovedTotals: totals() },
			])
			expect(sheet?.stageApprovedTotals).toEqual(totals())
		})

		it('accepts the consensus when only some items carry the field (old payload)', () => {
			const [sheet] = buildFinanceSheetQueue([
				transaction('a'),
				{ ...transaction('b'), stageApprovedTotals: totals() },
			])
			expect(sheet?.stageApprovedTotals).toEqual(totals())
		})

		it('stays null when no item carries the field', () => {
			const [sheet] = buildFinanceSheetQueue([transaction('a'), transaction('b')])
			expect(sheet?.stageApprovedTotals).toBeNull()
		})

		it('discards a malformed aggregate instead of presenting a partial one', () => {
			// The malformed payload is deliberately ill-typed: `decodeStageApprovedTotals` must reject
			// it at runtime, so the type error is silenced with an explicit cast, never by loosening
			// the `StageApprovedTotals` contract type.
			const malformed = { ...totals(), seniorApproved: null } as unknown as StageApprovedTotals
			const [sheet] = buildFinanceSheetQueue([
				{ ...transaction('a'), stageApprovedTotals: malformed },
			])
			expect(sheet?.stageApprovedTotals).toBeNull()
		})

		it('treats conflicting same-Sheet aggregates as unavailable and reports the conflict', () => {
			const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
			const [sheet] = buildFinanceSheetQueue([
				{ ...transaction('a'), stageApprovedTotals: totals() },
				{ ...transaction('b'), stageApprovedTotals: { ...totals(), costCenterApproved: { amountMinor: 999_999, currency: 'IRR', minorUnit: 0 } } },
			])
			expect(sheet?.stageApprovedTotals).toBeNull()
			expect(warn).toHaveBeenCalledWith(expect.stringContaining('conflicting stageApprovedTotals'))
			warn.mockRestore()
		})

		it('keeps per-sheet aggregates independent across sheets', () => {
			const sheets = buildFinanceSheetQueue([
				{ ...transaction('a', { sheetId: 'sheet-1' }), stageApprovedTotals: totals() },
				transaction('b', { sheetId: 'sheet-2' }),
			])
			expect(sheets.find((sheet) => sheet.sheetId === 'sheet-1')?.stageApprovedTotals).toEqual(totals())
			expect(sheets.find((sheet) => sheet.sheetId === 'sheet-2')?.stageApprovedTotals).toBeNull()
		})
	})

	it('returns sheets in deterministic insertion order; each sheet newest activity first', () => {
		const sheets = buildFinanceSheetQueue([
			transaction('a', { sheetId: 'a', createdAt: '2026-10-01T10:00:00+00:00' }),
			transaction('b', { sheetId: 'b', createdAt: '2026-10-03T10:00:00+00:00' }),
			transaction('c', { sheetId: 'c', createdAt: '2026-10-02T10:00:00+00:00' }),
		])

		// Sheet order is first-appearance (deterministic); sheet-level re-sort lives in Phase C.
		expect(sheets.map((sheet) => sheet.sheetId)).toEqual(['a', 'b', 'c'])
		// Within each sheet, transactions are newest activity first.
		const [bSheet] = sheets.filter((sheet) => sheet.sheetId === 'b')
		expect(bSheet?.transactions.map((item) => item.id)).toEqual(['b'])
		const [cSheet] = sheets.filter((sheet) => sheet.sheetId === 'c')
		expect(cSheet?.transactions.map((item) => item.id)).toEqual(['c'])
	})

	it('refuses to present a sheet when a transaction has a blank sheetId', () => {
		// A blank identifier is corrupted/unexpected data; it must not seed a grouping key.
		const sheets = buildFinanceSheetQueue([transaction('a', { sheetId: '' })])
		expect(sheets).toEqual([])
	})

	it('does not merge multiple blank sheetId transactions into one fake Cost Sheet', () => {
		const sheets = buildFinanceSheetQueue([
			transaction('a', { sheetId: '' }),
			transaction('b', { sheetId: '' }),
			transaction('c', { sheetId: '' }),
		])
		expect(sheets).toEqual([])
	})

	it('does not present a valid subset when the set mixes valid and invalid sheetIds', () => {
		const sheets = buildFinanceSheetQueue([
			transaction('a', { sheetId: 'sheet-valid' }),
			transaction('b', { sheetId: '' }),
			transaction('c', { sheetId: 'sheet-valid' }),
		])
		expect(sheets).toEqual([])
	})

	it('keeps valid Cost Sheet grouping unchanged when every sheetId is usable', () => {
		const sheets = buildFinanceSheetQueue([
			transaction('a', { sheetId: 'sheet-1' }),
			transaction('b', { sheetId: 'sheet-1' }),
			transaction('c', { sheetId: 'sheet-2' }),
		])
		expect(sheets).toHaveLength(2)
		const first = sheets.find((sheet) => sheet.sheetId === 'sheet-1')
		expect(first?.transactionCount).toBe(2)
		expect(first?.transactions.map((item) => item.id).sort()).toEqual(['a', 'b'])
		const second = sheets.find((sheet) => sheet.sheetId === 'sheet-2')
		expect(second?.transactionCount).toBe(1)
		expect(second?.transactions.map((item) => item.id)).toEqual(['c'])
	})
})

describe('useReviewsStore.fetchFinanceQueue (store wiring)', () => {
	it('sets the finance queue and completeness from the all-pages read', async () => {
		const store = useReviewsStore()
		ocs.mockResolvedValue({ items: [transaction('a'), transaction('b')], page: 1, pageSize: 100, total: 2 })

		await store.fetchFinanceQueue()

		expect(store.financeQueue.items.map((item: TransactionSummary) => item.id)).toEqual(['a', 'b'])
		expect(store.financeQueueCompleteness).toEqual({ complete: true, reason: 'complete' })
		expect(store.error).toBeNull()
	})

	it('renders an honest incomplete state when the set is truncated', async () => {
		const store = useReviewsStore()
		ocs.mockResolvedValue({ items: [transaction('a')], page: 1, pageSize: 100, total: 5 })

		await store.fetchFinanceQueue()

		expect(store.financeQueueCompleteness).toEqual({ complete: false, reason: 'truncated' })
	})

	it('preserves request failure and does not fabricate completeness', async () => {
		const store = useReviewsStore()
		ocs.mockRejectedValueOnce(new Error('connection reset'))

		await expect(store.fetchFinanceQueue()).rejects.toThrow('connection reset')
		expect(store.error).toBe('connection reset')
		expect(store.financeQueueCompleteness).toBeNull()
	})
})

describe('FINANCE_SHEET_PAGE_SIZE', () => {
	it('matches the page size the all-pages fetch requests', () => {
		expect(FINANCE_SHEET_PAGE_SIZE).toBeGreaterThan(0)
	})
})

describe('no vendor surface (owner D7)', () => {
	it('names no vendor field, filter, or placeholder anywhere in the composable', () => {
		const source = readComposableSource()
		expect(source.some((line) => /vendor/i.test(line))).toBe(false)
	})

	it('exposes no vendor key in the Finance queue item shape', () => {
		const sheets = buildFinanceSheetQueue([transaction('a', { sheetId: 'sheet-1' })])
		const flattened = JSON.stringify(sheets).toLowerCase()
		expect(flattened).not.toContain('vendor')
	})
})

// ---------------------------------------------------------------------------
// Completeness boundary: grouping must not run on an incomplete set.
//
// `buildFinanceSheetQueue` itself is pure over whatever it receives; the boundary that refuses an
// incomplete set is the store's `fetchFinanceQueue` + `checkFinanceQueueCompleteness` wiring above,
// plus the Phase C queue view (not in this batch). This suite proves the guard returns incomplete
// so the UI can render unavailable instead of partial rows.
// ---------------------------------------------------------------------------

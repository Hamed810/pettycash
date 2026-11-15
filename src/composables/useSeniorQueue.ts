// Feature 007: the Senior Manager's Cost-Sheet-based queue derivation.
//
// Deliberately **extends** `useSheetQueue.ts` — the shared completeness guard, de-duplication,
// grouping, per-currency totals and latest-activity derivation are reused, never re-implemented in
// parallel. This module only shapes those results into the Senior presentation and adds the pure
// per-transaction filter/sort model the sheet modal runs over its proven-complete set.
//
// All functions are pure and fire no network requests: filtered/sorted views operate on the loaded,
// proven-complete set (plan §Filtering Model / §Sorting Model). The modal's filter model is limited to
// the approved toolbar — transaction search and expense-type multi-select; vendor is out of scope
// (owner D7, `FR-007-015`) — no vendor field, filter, or placeholder appears anywhere here.
import type { SeniorFilterState, SeniorSheetListRow, SeniorSheetQueueItem, SeniorSort, TransactionSummary } from '@/types/api'
import {
	buildSheetQueue,
	SHEET_QUEUE_PAGE_SIZE,
	type SheetQueueCompleteness,
} from './useSheetQueue'

// Re-exported for the view: the page size the Senior queue requests for its sheet-list reads.
export { SHEET_QUEUE_PAGE_SIZE }

/**
 * Whether the senior sheet-list fetch is the manager's whole reachable set.
 *
 * Unlike the shared single-response guard, the senior list is fetched **across all pages**
 * (`reviewsApi.fetchAllSeniorSheets`), so the "at-threshold" rule (a full page may be truncated)
 * does not apply: the client keeps paging until it has covered the server's `total`. Completeness
 * therefore has two conditions — the server's `total` is a usable number, and the accumulated rows
 * cover it. Rows short of `total` mean a page was lost (loop bound hit or a server inconsistency),
 * and that set renders the honest unavailable state (owner D8) rather than a partial table.
 */
export function checkSeniorSheetQueueCompleteness(
	page: { items: readonly unknown[]; total: number },
	_requestedPageSize?: number
): SheetQueueCompleteness {
	if (!Number.isFinite(page.total) || page.total < 0) {
		return { complete: false, reason: 'no-total' }
	}

	const returned = page.items.length
	if (returned === 0) {
		return page.total === 0
			? { complete: true, reason: 'empty' }
			: { complete: false, reason: 'truncated' }
	}

	if (returned < page.total) {
		return { complete: false, reason: 'truncated' }
	}

	return { complete: true, reason: 'complete' }
}

/**
 * Adapt one `GET /queues/senior/sheets` row into the presentation `SeniorSheetQueueItem`.
 *
 * The queue's data source is now the server's sheet list, so identity (`title`/`purchaser`/
 * `reviewStatus`), the awaiting count and the submitted money all come straight from the row —
 * never fabricated and never derived client-side (owner D5, FR-007-017). The row carries no
 * transactions, so `transactions` is empty here: the acting senior's transaction list arrives only
 * through the per-sheet read when the modal opens, and the amount cell uses the sheet's `submittedTotal`
 * (the server's submission snapshot, not a sum of the senior-scoped transactions — FR-007-011/017).
 * Cost centres are not disclosed by the list row, so no cost-centre term is invented for search.
 * The full row is retained as `sheet` so the modal can render real identity even if its per-sheet
 * transaction read is unavailable.
 */
export function adaptSeniorSheetListRows(rows: readonly SeniorSheetListRow[]): SeniorSheetQueueItem[] {
	return rows.map((row) => ({
		sheetId: row.id,
		sheet: row,
		transactions: [],
		// The row's senior-scoped "awaiting any senior" count — the honest pending set this surface
		// lists; a completed sheet reports 0 (nothing awaits) rather than a fabricated figure.
		transactionCount: row.progress.awaitingSenior,
		totals: row.submittedTotal
			? [{
				currency: row.submittedTotal.currency,
				minorUnit: row.submittedTotal.minorUnit,
				amountMinor: row.submittedTotal.amountMinor,
			}]
			: [],
		latestActivityAt: row.latestActivityAt,
		costCenters: [],
		title: row.title,
		purchaser: row.ownerDisplayName,
		reviewStatus: row.reviewStatus,
		submittedAt: row.submittedAt,
	}))
}

export interface SeniorCostCenter {
	id: string
	name: string
}

function collectSeniorCostCenters(transactions: readonly TransactionSummary[]): SeniorCostCenter[] {
	const seen = new Set<string>()
	const centers: SeniorCostCenter[] = []

	for (const transaction of transactions) {
		const costCenter = transaction.activeRevision?.costCenter
		if (!costCenter) continue
		const key = costCenter.id || costCenter.name
		if (!key || seen.has(key)) continue
		seen.add(key)
		centers.push({ id: costCenter.id ?? '', name: costCenter.name ?? '' })
	}

	return centers.sort((a, b) => (a.id || a.name).localeCompare(b.id || b.name))
}

/**
 * Group the de-duplicated Senior-scoped pending set into one presentation row per sheet.
 *
 * Grouping, de-duplication, per-currency totals and latest activity come from the shared
 * `buildSheetQueue`; only the cost-centre projection is re-shaped to the Senior item's `id`/`name`
 * (the shared row carries `code`/`name`). A blank sheet reference stays its own explicit row.
 */
export function buildSeniorSheetQueue(items: readonly TransactionSummary[]): SeniorSheetQueueItem[] {
	return buildSheetQueue(items).map((sheet) => ({
		sheetId: sheet.sheetId,
		transactions: sheet.transactions,
		transactionCount: sheet.transactionCount,
		totals: sheet.totals,
		latestActivityAt: sheet.latestActivityAt,
		costCenters: collectSeniorCostCenters(sheet.transactions),
	}))
}

/**
 * Queue-level (sheet-level) search over the proven-complete set (owner D2).
 *
 * The shared `searchSheetQueue` matches the CC-shaped cost-centre row (`{code, name}`), so the
 * Senior search adapts the same matching semantics for the Senior row (`{id, name}`): sheet
 * reference, title/purchaser supplied by the sheet list, cost-centre name/id when a legacy
 * transaction-derived row carries them, plus any caller-supplied terms. Transaction-level controls
 * are never duplicated at the queue (FR-007-041), and no vendor term exists (owner D7).
 */
export function searchSeniorSheets(
	items: readonly SeniorSheetQueueItem[],
	query: string,
	additionalTerms: (item: SeniorSheetQueueItem) => readonly (string | null | undefined)[] = () => []
): SeniorSheetQueueItem[] {
	const needle = query.trim().toLocaleLowerCase()
	if (needle === '') return [...items]

	return items.filter((item) =>
		item.sheetId.toLocaleLowerCase().includes(needle)
		|| (item.title ?? '').toLocaleLowerCase().includes(needle)
		|| (item.purchaser ?? '').toLocaleLowerCase().includes(needle)
		|| item.costCenters.some((center) =>
			center.name.toLocaleLowerCase().includes(needle)
			|| center.id.toLocaleLowerCase().includes(needle)
		)
		|| additionalTerms(item).some((term) =>
			typeof term === 'string' && term.toLocaleLowerCase().includes(needle)
		)
	)
}

/**
 * Queue-level (sheet-level) status filter tabs (owner D2/D5, T019).
 *
 * `reviewStatusOf` supplies each sheet's presentation status from the loaded response only — the
 * queue item itself never carries one (never derived client-side, FR-007-013). A sheet whose status
 * the server has not proven (`null`/absent) is classified as *pending*: it is shown on the Awaiting
 * tab and never on the Review completed tab, because a completed status must be supplied (never
 * assumed).
 */
export function filterSeniorSheetsByStatus(
	items: readonly SeniorSheetQueueItem[],
	status: 'all' | 'pending' | 'completed',
	reviewStatusOf: (item: SeniorSheetQueueItem) => 'PENDING_REVIEW' | 'REVIEW_COMPLETED' | null | undefined
): SeniorSheetQueueItem[] {
	if (status === 'all') return [...items]

	return items.filter((item) => {
		const reviewStatus = reviewStatusOf(item)
		if (status === 'completed') {
			return reviewStatus === 'REVIEW_COMPLETED'
		}
		return reviewStatus !== 'REVIEW_COMPLETED'
	})
}

/**
 * Per-transaction filter for the sheet modal over the proven-complete set (owner D2/D7).
 *
 * Search is by transaction id or description only, plus an expense-type multi-select. Pure and
 * local — no request per change.
 */
export function filterSeniorTransactions(
	transactions: readonly TransactionSummary[],
	filter: SeniorFilterState
): TransactionSummary[] {
	const query = (filter.query ?? '').trim().toLocaleLowerCase()
	const descriptionQuery = (filter.description ?? '').trim().toLocaleLowerCase()
	const expenseTypeIds = filter.expenseTypeIds ?? []

	return transactions.filter((transaction) => {
		const revision = transaction.activeRevision

		if (query !== '') {
			const haystack = `${transaction.id} ${revision?.description ?? ''}`.toLocaleLowerCase()
			if (!haystack.includes(query)) return false
		}
		if (descriptionQuery !== '' && !(revision?.description ?? '').toLocaleLowerCase().includes(descriptionQuery)) {
			return false
		}
		if (expenseTypeIds.length > 0 && !expenseTypeIds.includes(revision?.expenseType?.id ?? '')) {
			return false
		}

		return true
	})
}

interface AmountScalar {
	amountMinor: number
	currency: string
}

function amountScalar(transaction: TransactionSummary): AmountScalar | null {
	const amount = transaction.activeRevision?.amount
	if (!amount || !Number.isFinite(amount.amountMinor)) return null
	return { amountMinor: amount.amountMinor, currency: amount.currency }
}

function compareSenior(a: TransactionSummary, b: TransactionSummary, sort: SeniorSort): number {
	const direction = sort.direction === 'asc' ? 1 : -1
	let primary = 0

	switch (sort.key) {
		case 'amount': {
			const aAmount = amountScalar(a)
			const bAmount = amountScalar(b)
			if (aAmount && bAmount) {
				const byMinor = (aAmount.amountMinor - bAmount.amountMinor) * direction
				// Amount comparison is meaningful within a currency; the currency code is the
				// secondary key and is compared in a fixed (ascending) order.
				primary = byMinor !== 0 ? byMinor : aAmount.currency.localeCompare(bAmount.currency)
				break
			}
			if (aAmount && !bAmount) { primary = -1; break }
			if (!aAmount && bAmount) { primary = 1; break }
			break
		}
		case 'expenseDate': {
			// ISO8601/date-string compare is locale-independent (plan §Sorting Model).
			const aDate = a.activeRevision?.expenseDate ?? ''
			const bDate = b.activeRevision?.expenseDate ?? ''
			if (aDate === '' && bDate !== '') { primary = 1; break }
			if (aDate !== '' && bDate === '') { primary = -1; break }
			if (aDate !== bDate) { primary = (aDate < bDate ? -1 : 1) * direction; break }
			break
		}
		case 'expenseType': {
			const aName = a.activeRevision?.expenseType?.name ?? ''
			const bName = b.activeRevision?.expenseType?.name ?? ''
			if (aName === '' && bName !== '') { primary = 1; break }
			if (aName !== '' && bName === '') { primary = -1; break }
			const byName = aName.localeCompare(bName)
			if (byName !== 0) { primary = byName * direction; break }
			break
		}
	}

	// Stable secondary tiebreak keeps ordering deterministic (plan §Sorting Model).
	return primary !== 0 ? primary : a.id.localeCompare(b.id)
}

/**
 * Local sort for the modal over the proven-complete set (owner D2).
 *
 * One active key at a time (amount / expenseDate / expenseType), each ascending or descending, with a
 * stable secondary tiebreak by transaction id. Never re-requests the network and never re-derives
 * amounts. The result order feeds both the UI and the export selection/order.
 */
export function sortSeniorTransactions(
	transactions: readonly TransactionSummary[],
	sort: SeniorSort
): TransactionSummary[] {
	return [...transactions].sort((a, b) => compareSenior(a, b, sort))
}

/**
 * Derived "quick expense-type category" facet counts (T003/T020).
 *
 * Counts derive from the loaded, filtered set with the active filters applied (except the
 * expense-type filter itself, so the categories stay visible while one is chosen). This is a derived
 * facet over the server's own `expenseType` names — never a new vocabulary (plan §Filtering Model).
 */
export interface SeniorExpenseTypeFacet {
	id: string
	name: string
	count: number
}

export function deriveExpenseTypeFacets(
	transactions: readonly TransactionSummary[],
	filter: SeniorFilterState
): SeniorExpenseTypeFacet[] {
	const scoped = filterSeniorTransactions(transactions, { ...filter, expenseTypeIds: undefined })
	const byType = new Map<string, { name: string; count: number }>()

	for (const transaction of scoped) {
		const expenseType = transaction.activeRevision?.expenseType
		if (!expenseType) continue
		const existing = byType.get(expenseType.id)
		if (existing) {
			existing.count += 1
		} else {
			byType.set(expenseType.id, { name: expenseType.name ?? '', count: 1 })
		}
	}

	return [...byType.entries()]
		.map(([id, value]) => ({ id, name: value.name, count: value.count }))
		.sort((a, b) => a.name.localeCompare(b.name))
}

// ---------------------------------------------------------------------------
// Queue-level (sheet-level) column sorting (Feature 007 desktop UX correction).
//
// The shared modal sort (`sortSeniorTransactions`) orders transactions inside one sheet; this orders
// the QUEUE's sheet rows. It stays in the same pure module so the sort model is one place: these
// functions fire no network requests, never mutate, and operate on the proven-complete set — the
// same completeness the shared guard proves before any row is drawn (`checkSeniorSheetQueueCompleteness`).
//
// Currency safety (mission correction, consistent with 006 FR-006-014): a sheet's `totals` are
// per-currency and never summed across currencies. The amount sort is a documented **currency-first**
// policy: the row sorts by a deterministic representation (the total whose currency code is
// lexicographically smallest); currency codes are compared in fixed ascending order and amounts are
// numerically compared only when the two rows share that same currency. No conversion, no
// cross-currency numeric comparison, no invented "sheet total".
// ---------------------------------------------------------------------------

export type SeniorQueueSortKey =
	| 'reference'
	| 'owner'
	| 'pendingCount'
	| 'amount'
	| 'latestActivity'
	| 'status'

export type SeniorQueueSortDirection = 'asc' | 'desc'

export interface SeniorQueueSort {
	key: SeniorQueueSortKey
	direction: SeniorQueueSortDirection
}

/**
 * Per-sheet the column sort needs, supplied by the caller from the loaded additive Senior read —
 * never derived client-side (the queue item itself carries none of these). Keeps the pure comparator
 * free of the store/view so it is unit-testable and reusable.
 */
export interface SeniorSheetSortAccess {
	title: string | null
	purchaser: string | null
	status: 'PENDING_REVIEW' | 'REVIEW_COMPLETED' | null | undefined
}

/**
 * The one deterministic amount scalar per sheet for the amount sort: the per-currency total whose
 * currency code is lexicographically smallest, or `null` when the sheet carries no totals.
 */
function sheetAmountScalar(sheet: SeniorSheetQueueItem): { currency: string; amountMinor: number } | null {
	return sheet.totals.reduce<{ currency: string; amountMinor: number } | null>((min, total) => {
		if (min === null || total.currency < min.currency) {
			return { currency: total.currency, amountMinor: total.amountMinor }
		}
		return min
	}, null)
}

function compareSeniorSheets(
	a: SeniorSheetQueueItem,
	b: SeniorSheetQueueItem,
	sort: SeniorQueueSort,
	access: (sheet: SeniorSheetQueueItem) => SeniorSheetSortAccess
): number {
	const direction = sort.direction === 'asc' ? 1 : -1
	const aAccess = access(a)
	const bAccess = access(b)
	let primary = 0

	switch (sort.key) {
		case 'reference': {
			// Human-readable title first (locale-aware); a sheet whose title the server has not
			// supplied falls back to its reference (sheet id), so it still lands deterministically.
			const aTitle = aAccess.title?.trim() ?? ''
			const bTitle = bAccess.title?.trim() ?? ''
			const aValue = aTitle !== '' ? aTitle : a.sheetId
			const bValue = bTitle !== '' ? bTitle : b.sheetId
			if (aValue === '' && bValue !== '') { primary = 1; break }
			if (aValue !== '' && bValue === '') { primary = -1; break }
			const byTitle = aValue.localeCompare(bValue)
			if (byTitle !== 0) { primary = byTitle * direction; break }
			break
		}
		case 'owner': {
			const aName = aAccess.purchaser?.trim() ?? ''
			const bName = bAccess.purchaser?.trim() ?? ''
			if (aName === '' && bName !== '') { primary = 1; break }
			if (aName !== '' && bName === '') { primary = -1; break }
			const byName = aName.localeCompare(bName)
			if (byName !== 0) { primary = byName * direction; break }
			break
		}
		case 'pendingCount': {
			const byCount = a.transactionCount - b.transactionCount
			if (byCount !== 0) { primary = byCount * direction; break }
			break
		}
		case 'amount': {
			const aAmount = sheetAmountScalar(a)
			const bAmount = sheetAmountScalar(b)
			if (aAmount && bAmount) {
				const byCurrency = aAmount.currency.localeCompare(bAmount.currency)
				// Currency-first: the currency code order is fixed (ascending) and is never reversed by
				// the requested direction, so currency groups stay stable and amounts are never compared
				// across currencies (documented non-converting policy, 006 FR-006-014).
				if (byCurrency !== 0) { primary = byCurrency; break }
				const byMinor = aAmount.amountMinor - bAmount.amountMinor
				if (byMinor !== 0) { primary = byMinor * direction; break }
				break
			}
			if (aAmount && !bAmount) { primary = -1; break }
			if (!aAmount && bAmount) { primary = 1; break }
			break
		}
		case 'latestActivity': {
			// ISO8601 string compare is locale-independent and chronological (plan §Sorting Model).
			const aDate = a.latestActivityAt ?? ''
			const bDate = b.latestActivityAt ?? ''
			if (aDate === '' && bDate !== '') { primary = 1; break }
			if (aDate !== '' && bDate === '') { primary = -1; break }
			if (aDate !== bDate) { primary = (aDate < bDate ? -1 : 1) * direction; break }
			break
		}
		case 'status': {
			// Deterministic status order: PENDING (0) before COMPLETED (1). A sheet whose status the
			// server has not supplied is treated as pending — the same classification the status tabs
			// already use (`filterSeniorSheetsByStatus`), so the two never disagree.
			const aStatus = aAccess.status === 'REVIEW_COMPLETED' ? 1 : 0
			const bStatus = bAccess.status === 'REVIEW_COMPLETED' ? 1 : 0
			const byStatus = aStatus - bStatus
			if (byStatus !== 0) { primary = byStatus * direction; break }
			break
		}
	}

	// Stable secondary tiebreak keeps the order deterministic (plan §Sorting Model): the reference
	// compares in a fixed order regardless of which direction the primary key sorts, so equal keys
	// never reorder across renders or change with the active filter/search.
	return primary !== 0 ? primary : a.sheetId.localeCompare(b.sheetId)
}

/**
 * Queue-level column sort over the proven-complete set (Feature 007 desktop UX correction).
 *
 * Pure and local: no request per reorder, no mutation, and it always operates on the FULL
 * proven-complete set the caller passes (the caller first applies search + status filters, then
 * sorts — completeness guards stay untouched). `access` supplies each sheet's presentation fields
 * from the loaded additive Senior read so the comparator stays pure. One active key at a time, each
 * ascending or descending, with a stable deterministic tiebreak by sheet reference.
 */
export function sortSeniorSheets(
	items: readonly SeniorSheetQueueItem[],
	sort: SeniorQueueSort,
	access: (sheet: SeniorSheetQueueItem) => SeniorSheetSortAccess
): SeniorSheetQueueItem[] {
	return [...items].sort((a, b) => compareSeniorSheets(a, b, sort, access))
}

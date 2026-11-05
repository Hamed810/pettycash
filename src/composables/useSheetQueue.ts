import type { TransactionSummary } from '@/types/api'

/**
 * The largest single page this queue will request before it stops claiming completeness.
 *
 * `GET /queues/cost-center` is paginated and `ManagementReviewService::getCostCenterQueue`
 * paginates **per managed cost centre** before merging the lists. A manager assigned to the same
 * cost centre more than once therefore receives the same transaction more than once, and a page
 * offset is applied independently to each assignment. Grouping across a partial response would
 * invent sheet counts, so the queue only groups a response it can prove is the whole set.
 */
export const SHEET_QUEUE_PAGE_SIZE = 500

/** One currency's pending total inside a sheet. Amounts from different currencies never mix. */
export interface SheetAmountTotal {
	currency: string
	minorUnit: number
	amountMinor: number
}

/** A cost centre as it appears in the manager's pending set. */
export interface SheetCostCenter {
	code: string
	name: string
}

/**
 * A presentation projection, not a persisted entity.
 *
 * Every member is derived from the manager's proven-complete pending transactions. Nothing here
 * carries a sheet title, purchaser, submitted date or sheet-wide completion state, because the
 * server does not expose them to this stage and the queue must not approximate them.
 */
export interface SheetQueueItem {
	/** The owning sheet's public uuid, or `''` when the summary carried none. */
	sheetId: string
	/** De-duplicated pending transactions, newest activity first. */
	transactions: TransactionSummary[]
	transactionCount: number
	totals: SheetAmountTotal[]
	/** Newest parseable `activeRevision.createdAt`; `null` when no transaction carries one. */
	latestActivityAt: string | null
	costCenters: SheetCostCenter[]
}

export type SheetQueueSort = 'newest' | 'oldest' | 'mostTransactions' | 'reference'

export interface SheetQueueCompleteness {
	complete: boolean
	/** Why a response cannot be treated as the whole pending set. */
	reason: 'complete' | 'empty' | 'at-threshold' | 'truncated' | 'no-total'
}

interface QueuePageLike {
	items: readonly TransactionSummary[]
	total: number
}

/**
 * Whether a queue response is the manager's entire pending set.
 *
 * Three conditions, each closing a different way a partial set could be mistaken for the whole:
 *
 * - the server's `total` must not exceed the rows actually returned (otherwise a later page exists);
 * - fewer rows than the requested page size must have arrived (a full page may be truncated, even
 *   when the server's `total` happens to agree with it);
 * - `total` must be a finite number (an absent/NaN total cannot prove anything).
 */
export function checkSheetQueueCompleteness(
	page: QueuePageLike,
	requestedPageSize: number = SHEET_QUEUE_PAGE_SIZE
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

	if (page.total > returned) {
		return { complete: false, reason: 'truncated' }
	}

	if (returned >= requestedPageSize) {
		return { complete: false, reason: 'at-threshold' }
	}

	return { complete: true, reason: 'complete' }
}

/**
 * The same transaction can appear twice in one response when the actor holds overlapping
 * assignments to the same cost centre. One transaction is one decision, so the queue counts it
 * once; the first copy wins and order is preserved.
 */
export function dedupeTransactions(items: readonly TransactionSummary[]): TransactionSummary[] {
	const seen = new Set<string>()
	const unique: TransactionSummary[] = []

	for (const item of items) {
		if (seen.has(item.id)) continue
		seen.add(item.id)
		unique.push(item)
	}

	return unique
}

function timestampOf(transaction: TransactionSummary): number | null {
	const raw = transaction.activeRevision?.createdAt
	if (typeof raw !== 'string' || raw.trim() === '') return null
	const parsed = Date.parse(raw)
	return Number.isNaN(parsed) ? null : parsed
}

function latestTimestamp(transactions: readonly TransactionSummary[]): number | null {
	let latest: number | null = null
	for (const transaction of transactions) {
		const parsed = timestampOf(transaction)
		if (parsed === null) continue
		if (latest === null || parsed > latest) latest = parsed
	}
	return latest
}

function collectTotals(transactions: readonly TransactionSummary[]): SheetAmountTotal[] {
	const byCurrency = new Map<string, SheetAmountTotal>()

	for (const transaction of transactions) {
		const money = transaction.activeRevision?.amount
		if (!money || !Number.isFinite(money.amountMinor) || !Number.isFinite(money.minorUnit)) continue
		const key = `${money.currency}\u0000${money.minorUnit}`
		const existing = byCurrency.get(key)
		if (existing) {
			existing.amountMinor += money.amountMinor
		} else {
			byCurrency.set(key, {
				currency: money.currency,
				minorUnit: money.minorUnit,
				amountMinor: money.amountMinor,
			})
		}
	}

	return [...byCurrency.values()].sort((a, b) => a.currency.localeCompare(b.currency))
}

function collectCostCenters(transactions: readonly TransactionSummary[]): SheetCostCenter[] {
	const seen = new Set<string>()
	const centers: SheetCostCenter[] = []

	for (const transaction of transactions) {
		const costCenter = transaction.activeRevision?.costCenter
		if (!costCenter) continue
		const key = costCenter.code || costCenter.name || costCenter.id
		if (!key || seen.has(key)) continue
		seen.add(key)
		centers.push({ code: costCenter.code ?? '', name: costCenter.name ?? '' })
	}

	return centers.sort((a, b) => (a.code || a.name).localeCompare(b.code || b.name))
}

/**
 * Group de-duplicated pending transactions by sheet. Order inside a sheet is newest activity
 * first; order of sheets is the caller's `sortSheetQueue`.
 */
export function buildSheetQueue(items: readonly TransactionSummary[]): SheetQueueItem[] {
	const groups = new Map<string, TransactionSummary[]>()

	for (const transaction of dedupeTransactions(items)) {
		const key = typeof transaction.sheetId === 'string' ? transaction.sheetId : ''
		const group = groups.get(key)
		if (group) {
			group.push(transaction)
		} else {
			groups.set(key, [transaction])
		}
	}

	return [...groups.entries()].map(([sheetId, transactions]) => {
		const sorted = [...transactions].sort((a, b) => {
			const aTime = timestampOf(a) ?? Number.NEGATIVE_INFINITY
			const bTime = timestampOf(b) ?? Number.NEGATIVE_INFINITY
			return bTime - aTime
		})
		const latest = latestTimestamp(sorted)

		return {
			sheetId,
			transactions: sorted,
			transactionCount: sorted.length,
			totals: collectTotals(sorted),
			latestActivityAt: latest === null ? null : new Date(latest).toISOString(),
			costCenters: collectCostCenters(sorted),
		}
	})
}

/**
 * Local search over a proven-complete set.
 *
 * Sheet titles and purchasers are not in the queue response, so the placeholder and this matcher
 * name only what they can actually search: the sheet reference and the cost centre. A caller that
 * has loaded the manager-scoped sheet read may pass those real fields through `additionalTerms`;
 * the queue itself still never searches a transaction description, because that would match a row
 * the manager cannot see identified.
 */
export function searchSheetQueue(
	items: readonly SheetQueueItem[],
	query: string,
	additionalTerms: (item: SheetQueueItem) => readonly (string | null | undefined)[] = () => []
): SheetQueueItem[] {
	const needle = query.trim().toLocaleLowerCase()
	if (needle === '') return [...items]

	return items.filter((item) =>
		item.sheetId.toLocaleLowerCase().includes(needle)
		|| item.costCenters.some((center) =>
			center.code.toLocaleLowerCase().includes(needle)
			|| center.name.toLocaleLowerCase().includes(needle)
		)
		|| additionalTerms(item).some((term) =>
			typeof term === 'string' && term.toLocaleLowerCase().includes(needle)
		)
	)
}

/** Local ordering, never a request: the response is already the complete pending set. */
export function sortSheetQueue(items: readonly SheetQueueItem[], sort: SheetQueueSort): SheetQueueItem[] {
	const copy = [...items]

	switch (sort) {
		case 'oldest':
			return copy.sort((a, b) => compareActivity(a, b, true, false))
		case 'mostTransactions':
			return copy.sort((a, b) => b.transactionCount - a.transactionCount || compareActivity(a, b, false, false))
		case 'reference':
			return copy.sort((a, b) => a.sheetId.localeCompare(b.sheetId))
		case 'newest':
		default:
			return copy.sort((a, b) => compareActivity(a, b, false, false))
	}
}

function compareActivity(a: SheetQueueItem, b: SheetQueueItem, ascending: boolean, nullsFirst: boolean): number {
	const aTime = a.latestActivityAt === null ? null : Date.parse(a.latestActivityAt)
	const bTime = b.latestActivityAt === null ? null : Date.parse(b.latestActivityAt)

	if (aTime === null && bTime === null) return a.sheetId.localeCompare(b.sheetId)
	if (aTime === null) return nullsFirst ? -1 : 1
	if (bTime === null) return nullsFirst ? 1 : -1

	const delta = ascending ? aTime - bTime : bTime - aTime
	return delta === 0 ? a.sheetId.localeCompare(b.sheetId) : delta
}

/** Aggregate totals across every sheet, still separated by currency and minor unit. */
export function totalPendingByCurrency(items: readonly SheetQueueItem[]): SheetAmountTotal[] {
	return collectTotals(items.flatMap((item) => item.transactions))
}

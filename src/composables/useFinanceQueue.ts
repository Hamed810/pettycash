// Feature 009: the Finance Manager's Cost-Sheet-based queue derivation.
//
// Deliberately **extends** `useSheetQueue.ts` — the shared completeness guard, de-duplication,
// grouping, per-currency totals and latest-activity derivation are reused, never re-implemented in
// parallel. This module only shapes those results into the Finance presentation.
//
// All functions are pure and fire no network requests: the complete-set read lives in
// `reviewsApi.fetchAllFinanceQueues`, and grouping/completeness run over the loaded,
// proven-complete set (plan §Complete Grouping).
//
// Currency safety (mission rule, 006 FR-006-014): totals are per-currency and never summed across
// currencies. No cross-currency arithmetic anywhere in this module.
import type { StageApprovedTotals, TransactionSummary } from '@/types/api'
import { decodeStageApprovedTotals } from '@/utils/stageApprovals'
import {
	buildSheetQueue,
	dedupeTransactions,
	type SheetAmountTotal,
	type SheetCostCenter,
	type SheetQueueCompleteness,
} from './useSheetQueue'

/** The page size the Finance all-pages fetch requests (matches `reviewsApi.FINANCE_SHEET_PAGE_SIZE`). */
export const FINANCE_SHEET_PAGE_SIZE = 100

/**
 * A Finance Cost Sheet queue row: one presentation row per Cost Sheet over the de-duplicated,
 * proven-complete finance-eligible set. Not a persisted entity; the sheet-level identity and
 * submission metadata are projected by the server from the owning Cost Sheet and carried on each
 * queue item, never approximated client-side.
 */
export interface FinanceSheetQueueItem {
	/** The owning sheet's public uuid, or `''` when the summary carried none. */
	sheetId: string
	/** The owning sheet's human-readable title, projected server-side from `ExpenseSheet::title`. */
	sheetTitle: string
	/** The owning sheet's purchaser/owner uid, projected server-side from `ExpenseSheet::ownerUid`. */
	sheetOwnerId: string
	/** The purchaser's current display name, resolved through the `UserDirectory`. */
	sheetOwnerDisplayName: string
	/** The sheet's original submission timestamp (`ExpenseSheet::firstSubmittedAt`), or `null`. */
	sheetSubmittedAt: string | null
	/** De-duplicated finance-eligible transactions, newest activity first. */
	transactions: TransactionSummary[]
	transactionCount: number
	/** Per-currency totals; amounts from different currencies never mix. */
	totals: SheetAmountTotal[]
	/** Newest parseable `activeRevision.createdAt`; `null` when no transaction carries one. */
	latestActivityAt: string | null
	costCenters: SheetCostCenter[]
	/**
	 * The owning Cost Sheet's whole-sheet stage-approved aggregate (owner decision, 2026-11-20),
	 * captured from the finance queue items' server payload — never derived from the
	 * finance-visible transaction subset.
	 *
	 * `null` is the honest-unavailable value and covers every case the queue cannot prove: no item
	 * of the sheet carried the field (old payload), the carried value was malformed, or the sheet's
	 * items disagreed with each other (see `stageApprovedTotalsOfSheet`).
	 */
	stageApprovedTotals: StageApprovedTotals | null
}

export type FinanceSheetAmountTotal = SheetAmountTotal
export type FinanceSheetCostCenter = SheetCostCenter
export type FinanceQueueCompleteness = SheetQueueCompleteness

/**
 * Whether the accumulated Finance queue response is the finance-eligible pending set.
 *
 * Unlike the single-response guard, the Finance list is fetched **across all pages**
 * (`reviewsApi.fetchAllFinanceQueues`), so the "at-threshold" rule (a full page may be truncated)
 * does not apply: the client keeps paging until it has covered the server's `total`. Completeness
 * therefore has two conditions — the server's `total` is a usable number, and the accumulated rows
 * cover it. Rows short of `total` mean a page was lost (loop bound hit or a server inconsistency),
 * and that set renders the honest unavailable state rather than a partial table.
 */
export function checkFinanceQueueCompleteness(
	page: { items: readonly TransactionSummary[]; total: number },
	_requestedPageSize?: number
): FinanceQueueCompleteness {
	if (!Number.isFinite(page.total) || page.total < 0) {
		return { complete: false, reason: 'no-total' }
	}

	const returned = page.items.length
	if (returned === 0) {
		return page.total === 0 ? { complete: true, reason: 'empty' } : { complete: false, reason: 'truncated' }
	}

	if (returned < page.total) {
		return { complete: false, reason: 'truncated' }
	}

	return { complete: true, reason: 'complete' }
}

/**
 * The same transaction can appear more than once in one response (overlapping Finance assignments
 * or page overlap). One transaction is one decision, so the queue counts it once; the first copy
 * wins and order is preserved. Reuses the shared `dedupeTransactions` — no Finance-prefixed copy.
 */
export function dedupeFinanceTransactions(items: readonly TransactionSummary[]): TransactionSummary[] {
	return dedupeTransactions(items)
}

/**
 * Whether a Cost Sheet identifier is usable for authoritative grouping.
 *
 * `TransactionSummary.sheetId` is typed as `string` — present and never null — but the contract
 * does not guarantee it is non-empty. A blank or whitespace-only identifier is corrupted or
 * unexpected runtime data, not a legitimate sheet, and must not seed a grouping key.
 */
function isUsableSheetId(sheetId: string): boolean {
	return typeof sheetId === 'string' && sheetId.trim() !== ''
}

/**
 * Group de-duplicated finance-eligible transactions by sheet. Order inside a sheet is newest
 * activity first; order of sheets is newest activity first, tiebreak by sheet reference.
 *
 * A Cost Sheet grouping is valid only when every transaction carries a usable authoritative
 * Cost Sheet identifier. A blank/unresolvable sheetId must not merge unrelated transactions into
 * one fake Cost Sheet, nor be presented as a partial group. When any transaction carries an
 * unusable identifier the whole projection is unavailable: no Cost Sheet rows are emitted.
 *
 * Grouping, de-duplication, per-currency totals and latest activity all come from the shared
 * `buildSheetQueue`; no Finance-prefixed grouping logic is duplicated.
 */
export function buildFinanceSheetQueue(items: readonly TransactionSummary[]): FinanceSheetQueueItem[] {
	if (items.some((transaction) => !isUsableSheetId(transaction.sheetId))) {
		return []
	}
	return buildSheetQueue(items).map((group) => {
		// All queue items of one Cost Sheet carry identical server-projected sheet metadata;
		// the first transaction's values represent the sheet. The comment on `sheetTitle` in
		// `FinanceSheetQueueItem` documents why these are authoritative and never approximated.
		// A group always carries at least one transaction (buildSheetQueue only creates groups
		// from pushed items), so the first element is guaranteed present.
		const first = group.transactions[0]!
		return {
			...group,
			sheetTitle: first.sheetTitle ?? '',
			sheetOwnerId: first.sheetOwnerId ?? '',
			sheetOwnerDisplayName: first.sheetOwnerDisplayName ?? '',
			sheetSubmittedAt: first.sheetSubmittedAt ?? null,
			stageApprovedTotals: stageApprovedTotalsOfSheet(group.sheetId, group.transactions),
		}
	})
}

/**
 * Whether two decoded aggregates are the same sheet-level figures, field by field.
 *
 * A structural comparison on the three `Money` fields — not a reference or JSON-string identity —
 * so two independently decoded copies of the same server payload count as consistent while any
 * differing currency, scale or amount counts as a conflict.
 */
function sameStageApprovedTotals(a: StageApprovedTotals, b: StageApprovedTotals): boolean {
	const same = (m: StageApprovedTotals['costCenterApproved'], n: StageApprovedTotals['costCenterApproved']) =>
		m.currency === n.currency && m.minorUnit === n.minorUnit && m.amountMinor === n.amountMinor
	return same(a.costCenterApproved, b.costCenterApproved)
		&& same(a.seniorApproved, b.seniorApproved)
		&& same(a.financeApproved, b.financeApproved)
}

/**
 * One sheet's authoritative aggregate from its queue items' payloads (UXC-7F-05).
 *
 * The server stamps every item of one Cost Sheet with the identical whole-sheet value, so the
 * consensus of the items that carry a well-formed one is the sheet's aggregate; items without the
 * field are an old/incomplete payload, not a contradiction. Two items that decode to different
 * figures are a genuine inconsistency: no arbitrary choice is presented as the sheet's total — the
 * aggregate becomes `null` (honest unavailable) and the inconsistency is reported through the
 * console diagnostic.
 */
function stageApprovedTotalsOfSheet(sheetId: string, transactions: readonly TransactionSummary[]): StageApprovedTotals | null {
	let consensus: StageApprovedTotals | null = null
	for (const transaction of transactions) {
		const decoded = decodeStageApprovedTotals(transaction.stageApprovedTotals)
		if (decoded === null) continue
		if (consensus === null) {
			consensus = decoded
			continue
		}
		if (!sameStageApprovedTotals(consensus, decoded)) {
			// No frontend diagnostic surface exists beyond the console; the honest unavailable
			// rendering of the Approvals tab is the user-visible outcome either way.
			console.warn(
				`[pettycash] finance queue items for sheet ${sheetId} carry conflicting stageApprovedTotals; the aggregate is treated as unavailable.`
			)
			return null
		}
	}
	return consensus
}

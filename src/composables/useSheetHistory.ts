import { computed, reactive } from 'vue'
import { listTransactionAudit } from '@/services/auditApi'
import type { AuditEvent, TransactionStatus } from '@/types/api'

/**
 * The workspace History tab's data, assembled from the **only** history endpoints the API
 * publishes: one paginated audit feed per transaction (`GET /transactions/{id}/audit`).
 *
 * There is no sheet-scoped audit endpoint, so this is deliberately not presented as a sheet
 * history. Each loaded page is bounded, and every feed that holds more events than it has loaded
 * is reported separately so the tab can offer a further request instead of implying it showed
 * everything.
 *
 * ## T069 — the draft boundary
 *
 * A never-submitted `DRAFT` has working revisions, not official history. Its audit feed is not
 * requested at all, so no draft event can appear here. For a submitted transaction, legacy
 * `REVISION_CREATED`/`TRANSACTION_CHANGED` events written while the transaction was still a draft
 * are hidden: they describe draft working state, which the official timeline does not contain.
 * Nothing is deleted or rewritten — this is a read-side projection only.
 */
const PAGE_SIZE = 20

/** Events that describe the pre-submission working state, not official history. */
export const LEGACY_DRAFT_EVENT_TYPES = new Set(['REVISION_CREATED', 'TRANSACTION_CHANGED'])

export function isLegacyDraftEvent(event: Pick<AuditEvent, 'eventType' | 'stage'>): boolean {
	return event.stage === 'DRAFT' && LEGACY_DRAFT_EVENT_TYPES.has(event.eventType)
}

interface FeedState {
	items: AuditEvent[]
	page: number
	total: number
	loading: boolean
	error: string | null
}

export interface HistoryTransaction {
	id: string
	label: string
	/** Drives T069's draft boundary. Absent is treated as "history eligible" for older callers. */
	status?: TransactionStatus
}

export interface MergedHistoryEvent extends AuditEvent {
	transactionId: string
	transactionLabel: string
}

function emptyFeed(): FeedState {
	return { items: [], page: 0, total: 0, loading: false, error: null }
}

/** A timestamp that can be sorted: unparsable values sort last rather than becoming 1970. */
function occurredAtValue(event: AuditEvent): number {
	const value = Date.parse(event.occurredAt)
	return Number.isNaN(value) ? 0 : value
}

export function useSheetHistory() {
	const feeds = reactive<Record<string, FeedState>>({})
	const labels = reactive<Record<string, string>>({})
	const statuses = reactive<Record<string, TransactionStatus | undefined>>({})

	async function loadPage(transactionId: string, page: number): Promise<void> {
		const feed = feeds[transactionId] ?? (feeds[transactionId] = emptyFeed())
		if (feed.loading) return
		feed.loading = true
		feed.error = null
		try {
			const response = await listTransactionAudit(transactionId, page, PAGE_SIZE)
			// A later page can overlap an earlier one after new events are written. Deduplicating by
			// event id keeps the merged list from showing the same event twice.
			const known = new Set(feed.items.map((event) => event.id))
			feed.items = [...feed.items, ...response.items.filter((event) => !known.has(event.id))]
			feed.page = page
			feed.total = response.total
		} catch (error: unknown) {
			feed.error = (error as Error).message ?? 'history load failed'
		} finally {
			feed.loading = false
		}
	}

	/**
	 * Loads page one for every submitted transaction that has not been read yet. A draft is never
	 * eligible: its revisions are working state, so there is no official feed to fetch.
	 */
	function ensureLoaded(transactions: HistoryTransaction[]): void {
		for (const transaction of transactions) {
			labels[transaction.id] = transaction.label
			statuses[transaction.id] = transaction.status
			if (transaction.status === 'DRAFT') continue
			if (!feeds[transaction.id]) {
				feeds[transaction.id] = emptyFeed()
				void loadPage(transaction.id, 1)
			}
		}
	}

	function loadMore(transactionId: string): void {
		const feed = feeds[transactionId]
		if (!feed || feed.loading || feed.items.length >= feed.total) return
		void loadPage(transactionId, feed.page + 1)
	}

	const merged = computed<MergedHistoryEvent[]>(() => {
		const events: MergedHistoryEvent[] = []
		for (const [transactionId, feed] of Object.entries(feeds)) {
			// Belt and braces: a skipped draft has no feed, but a status change must not resurrect one.
			if (statuses[transactionId] === 'DRAFT') continue
			for (const event of feed.items) {
				if (isLegacyDraftEvent(event)) continue
				events.push({
					...event,
					transactionId,
					transactionLabel: labels[transactionId] ?? transactionId,
				})
			}
		}
		// Deterministic across transactions: newest first, then transaction id, then event id. Two
		// requests answered in a different order therefore cannot reorder the list under the reader.
		return events.sort((left, right) => {
			const byTime = occurredAtValue(right) - occurredAtValue(left)
			if (byTime !== 0) return byTime
			const byTransaction = left.transactionId.localeCompare(right.transactionId)
			if (byTransaction !== 0) return byTransaction
			return left.id.localeCompare(right.id)
		})
	})

	const loading = computed(() => Object.values(feeds).some((feed) => feed.loading))
	const loaded = computed(() => Object.values(feeds).reduce((total, feed) => total + feed.items.length, 0))
	const available = computed(() => Object.values(feeds).reduce((total, feed) => total + feed.total, 0))
	const errors = computed(() => Object.entries(feeds)
		.filter(([, feed]) => !!feed.error)
		.map(([transactionId, feed]) => ({ transactionId, message: feed.error as string })))
	const truncated = computed(() => Object.entries(feeds)
		.filter(([, feed]) => feed.items.length < feed.total)
		.map(([transactionId, feed]) => ({
			transactionId,
			label: labels[transactionId] ?? transactionId,
			loaded: feed.items.length,
			total: feed.total,
		})))
	const hasLoaded = computed(() => Object.keys(feeds).length > 0)
	/** How many transactions the tab actually has an official history to show for. */
	const tracked = computed(() => Object.values(statuses).filter((status) => status !== 'DRAFT').length)

	return {
		feeds,
		merged,
		loading,
		loaded,
		available,
		errors,
		truncated,
		hasLoaded,
		tracked,
		ensureLoaded,
		loadMore,
	}
}

import { h, type VNode } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import { formatMoney } from '@/utils/money'
import type { TransactionSummary } from '@/types/api'

/**
 * The columns the four review queues share, and the cells for all but the one they disagree on.
 *
 * ## Why this exists (T389)
 *
 * FR-071 requires every listed element to be "visible without horizontal scroll at 390px viewport". These
 * four tables answered that with `overflow-x: auto`, which produces a scrollbar rather than satisfying the
 * requirement — exactly what `src/styles/app.scss` says must not be done.
 *
 * Measured before writing this file: the four tables differ in **six lines total**, and all six are the
 * single column each queue disagrees about — `stage` for cost centre, `advisoryDecision` for senior,
 * `seniorDecision` for finance, `returnedFromStage` for returns. Everything else is identical.
 *
 * That is the shape this module takes: the shared six are declared and rendered **once**, and each view
 * supplies only the column it owns. The alternative — four view-level copies of the same seven columns —
 * is how the narrow layout would drift, and `ResponsiveTable`'s docblock already records the cost of
 * letting two layouts disagree about the same figures.
 *
 * @see src/components/shared/ResponsiveTable.vue for the two-layout switch this feeds.
 */

/** A translated column header plus the key the cells are rendered by. */
export interface QueueColumn {
	key: string
	label: string
}

/**
 * The six columns every queue carries, in display order.
 *
 * A function rather than a constant because the labels are translated: evaluating `t()` at module scope
 * would capture whatever locale was active when the module first loaded, so a language change would leave
 * these headers in the previous language while the rest of the page re-rendered.
 */
export function sharedQueueColumns(): QueueColumn[] {
	return [
		{ key: 'transactionId', label: t('pettycash', 'reviews.transactionId') },
		{ key: 'sheetId', label: t('pettycash', 'reviews.sheetId') },
		{ key: 'costCenter', label: t('pettycash', 'reviews.costCenter') },
		{ key: 'amount', label: t('pettycash', 'reviews.amount') },
		{ key: 'status', label: t('pettycash', 'reviews.status') },
		{ key: 'actions', label: t('pettycash', 'common.actions') }
	]
}

/**
 * The columns of one queue: the shared six, with the view's own column inserted where it belongs.
 *
 * `at` is an index rather than "append", because the queue that differs is not always last — finance and
 * senior put their decision where the shared `stage` column sits.
 */
export function queueColumns(own: QueueColumn, at: number): QueueColumn[] {
	const columns = sharedQueueColumns()
	columns.splice(at, 0, own)

	return columns
}

/**
 * One shared cell.
 *
 * ## Why the test hooks are here rather than in the views
 *
 * `data-testid="amount"` and `data-testid="view-details-btn"` are asserted by every queue's existing
 * suite. Declaring them alongside the cell that renders them means a view cannot adopt these columns and
 * silently lose the hook its tests find the amount by — which is exactly what happened to the row hook
 * when `SheetListView` first moved onto `ResponsiveTable`.
 *
 * ## Why `openDetail` is a parameter
 *
 * The click behaviour belongs to the view, not here: a card layout and a table row must invoke the *same*
 * navigation, and two copies of it would be two things to keep in step.
 *
 * @param item    the transaction this row shows
 * @param column  the column being rendered
 * @param openDetail navigation callback, shared by both layouts
 */
export function sharedQueueCell(
	item: TransactionSummary,
	column: QueueColumn,
	openDetail: (id: string) => void
): VNode | string | number {
	switch (column.key) {
		case 'transactionId':
			// The row's own identity, as the queue's filter scope sees it.
			return h('span', { 'data-testid': 'transaction-id' }, item.id)
		case 'sheetId':
			return h('span', { 'data-testid': 'sheet-id' }, item.sheetId)
		case 'costCenter':
			return h('span', null, item.activeRevision.costCenter.name)
		case 'amount':
			return h('span', { class: 'money', 'data-testid': 'amount' }, formatMoney(item.activeRevision.amount))
		case 'status':
			return h(StatusBadge, { status: item.status })
		case 'actions':
			return h(
				NcButton,
				{
					variant: 'tertiary',
					'data-testid': 'view-details-btn',
					'onClick': () => openDetail(item.id)
				},
				() => t('pettycash', 'common.view')
			)
		default:
			// A column with no case is a new column nobody rendered, not an empty cell. Printing the key
			// makes it visible in both layouts and assertable, instead of silently blank.
			return h('span', { 'data-testid': `queue-unrendered-${column.key}` }, column.key)
	}
}

/**
 * The row hook, so both layouts stay addressable and a row count stays a count.
 *
 * **Per-row, and the exact shape the existing suites already use.** Every queue asserted rows with
 * `[data-testid^="queue-row-"]`, matching an id built from the record's own identity, so this is a *function*
 * rather than a static string — see `ResponsiveTable`'s `rowTestId` for why that distinction mattered. It is
 * declared once here rather than per view so the four cannot drift, which is the same reason the six shared
 * columns are.
 */
export function queueRowTestId(item: TransactionSummary): string {
	return `queue-row-${item.id}`
}

/**
 * The card's own row hook, kept off the `queue-row-` prefix on purpose.
 *
 * The existing suites count rows with `[data-testid^="queue-row-"]`. A card hook derived by appending
 * `-card` still matches that prefix, so every count doubled — "expected 2, received 4" — which points at
 * nothing that changed. A different prefix leaves the selector meaning one thing: rows of the table.
 */
export function queueCardRowTestId(item: TransactionSummary): string {
	return `queue-card-${item.id}`
}

/** The Vue key, which falls back to the index rather than to a name another row could share. */
export function queueRowKey(item: TransactionSummary, index: number): string {
	return item.id || `queue-row-${index}`
}
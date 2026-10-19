<script setup lang="ts">
import { direction } from '@/utils/direction'
import { computed, h, nextTick, onMounted, ref, type VNode } from 'vue'
import { useRouter } from 'vue-router'
// UI-PUR-008: the overflow menu is imported from the package root, like `DeleteSheetDialog`'s
// `NcDialog`: it is a composite (trigger + popover) and the root entry is the public surface for it.
import { NcActionButton, NcActions } from '@nextcloud/vue'
import { useSheetStore } from '@/stores/sheets'
import NcButton from '@nextcloud/vue/components/NcButton'
import DeleteSheetDialog from '@/components/shared/DeleteSheetDialog.vue'
import AsyncState from '@/components/shared/AsyncState.vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ResponsiveTable from '@/components/shared/ResponsiveTable.vue'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import { mdiPlus } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import type { AsyncStatus } from '@/composables/useAsyncAction'
import { ABSENT_MARKER, formatSheetDate as formatDate } from '@/utils/date'
import { formatMoney } from '@/utils/money'
import { formatNumber } from '@/utils/number'
import { selectOptionKey, selectOptionValue } from '@/utils/selectOption'
import {
	isReturningToSheetList,
	readScrollOffset,
	rememberSheetListScroll,
	restoreScrollOffset,
	takeSheetListScroll,
} from '@/utils/sheetListSession'
import { SHEET_STATUSES, type Money, type SheetFilters, type SheetSummary } from '@/types/api'

const router = useRouter()
const sheetStore = useSheetStore()

/**
 * UI-PUR-004: whether this mount is a return from a sheet surface.
 *
 * Read once during setup, from the navigation the router recorded. The list is remounted on return,
 * so component state cannot answer "did the reader come back?" — only the navigation can.
 */
const restoringContext = isReturningToSheetList()

/**
 * UI-PUR-004: the restored page no longer exists.
 *
 * Deleting the last row of the last page, or a filter applied in another tab, can leave the stored
 * page number beyond the new last page. The list then loads the last page and says so, rather than
 * showing an empty page under a pager that reads "page 4 of 2".
 */
const pageClamped = ref(false)

/**
 * UI-PUR-004: the list's own root, used to resolve the element that actually scrolls it.
 *
 * Nextcloud 34 does not scroll `#content` (it is `overflow: clip`); the app wrapper scrolls. Walking
 * up from this element finds the real container without hardcoding a layout id, and it works for the
 * narrow card layout as well as the table.
 */
const listRoot = ref<HTMLElement | null>(null)

/**
 * The four FR-064 sheet facets, as a draft the Apply button submits.
 *
 * **Held separately from `sheetStore.filters`** because the store's copy is the *applied* filter: a draft
 * the user is still typing into must not change what the list is showing, and conflating them is how a
 * filter bar ends up showing one thing while the request behind it asks another. `applyFilters()` is the
 * only path from one to the other.
 */
const draft = ref<SheetFilters>({ ...sheetStore.filters })

const statusOptions = computed(() =>
	SHEET_STATUSES.map((status) => ({ label: t('pettycash', `sheets.status.${status}`), value: status }))
)

/**
 * The creator facet is shown, not typed.
 *
 * FR-064 resolves *"creator"* to a sheet's owner, which `SheetController::index` then treats as the
 * **scope** of this list rather than a predicate on it: `ownerId` is accepted only when it names the
 * caller, and naming anybody else is a 403. So an editable creator box could only ever hold the caller's
 * own uid, and any other value earns a refusal rather than a filtered list.
 *
 * **A text box here would be worse than no control**, because it invites exactly the value the server
 * refuses. The facet is rendered as the resolved value instead, which is what it is — a list is always
 * filtered by creator, and this states which one. Recorded rather than assumed: if a manager ever needs
 * to filter across creators, that is a scope change with an authorization decision behind it, not a UI
 * control.
 */
const creatorScope = computed(() => draft.value.ownerId ?? '')

/**
 * A blank facet back to "no filter", as a trimmed scalar.
 *
 * `SheetFilters` members are all scalars, so unlike the report filter bar's list-valued facets there is
 * no array/scalar distinction to get wrong here — but the trim still matters, because
 * `SheetListFilterFactory` treats `"  "` as absent while the request would carry a whitespace value that
 * no control displays.
 */
function orUndefined(raw: string | number | undefined): string | undefined {
	const trimmed = String(raw ?? '').trim()
	return trimmed === '' ? undefined : trimmed
}

/** Emits only the facets the user filled in, under the names `SheetListFilterFactory` accepts. */
function collect(): SheetFilters {
	const collected: SheetFilters = {}

	const status = orUndefined(draft.value.status)
	if (status) collected.status = status

	const currency = orUndefined(draft.value.currency)
	if (currency) collected.currency = currency.toUpperCase()

	const from = orUndefined(draft.value.from)
	if (from) collected.from = from

	const to = orUndefined(draft.value.to)
	if (to) collected.to = to

	return collected
}

/**
 * Applies the draft, always from **page 1**.
 *
 * Resetting the page is the part that is easy to leave out. Applying a narrower filter while sitting on
 * page 3 asks the server for rows 51-75 of a set that may now hold six sheets, so the user is shown an
 * empty list next to a pager reading "page 3 of 1" — a correct request and a useless answer. Any filter
 * change invalidates the previous page number, so the reset is in the same method as the request rather
 * than left to the caller.
 */
async function applyFilters(): Promise<void> {
	pageClamped.value = false
	await reloadList(1, collect())
}

async function resetFilters(): Promise<void> {
	draft.value = {}
	pageClamped.value = false
	await reloadList(1, {})
}

/** The last page the current total supports, never below 1 for an empty result. */
const totalPages = computed(() =>
	Math.max(1, Math.ceil(sheetStore.pagination.total / Math.max(1, sheetStore.pagination.pageSize)))
)

const currentPage = computed(() => sheetStore.pagination.page)
const canGoPrevious = computed(() => currentPage.value > 1)
const canGoNext = computed(() => currentPage.value < totalPages.value)

/**
 * Moves a page, carrying the applied filter with it.
 *
 * The filter is re-sent rather than left to the store's retained copy, so the page request is explicit
 * about everything it depends on. `pagination.pageSize` is read from the store rather than hardcoded,
 * because the store is what recorded the size the current total was measured at — a pager that assumed 25
 * would mis-report the page count the moment a size control changed it.
 */
async function goToPage(page: number): Promise<void> {
	pageClamped.value = false
	await reloadList(page, sheetStore.filters)
}

/**
 * The sheet list's six columns, in the approved order (UI-PUR-002).
 *
 * **Sheet, Status, Transactions, Recorded Amount, Latest Approved Amount, First Submitted.** The
 * previous list had eleven columns, four of them restating the record rather than supporting a
 * decision: the owner (this list is always scoped to the caller, so the column repeated the scope), the
 * currency (printed inside every amount), the active revision number and the key-date group (FR-063
 * values that remain on the detail and transaction surfaces). None of those fields was removed from the
 * payload; they were removed from a table whose job is to answer "what is this sheet, what state is it
 * in, what does it hold, and when did it first reach finance?".
 *
 * **Declared once so `ResponsiveTable` can label both layouts from the same list.** FR-071 requires the
 * table to reflow at a narrow viewport rather than scroll, and the reflowed layout is a card per row whose
 * fields are labelled. A label written into each `<th>` *and* into each card field is two statements of
 * one rule, and they drift — which is how the narrow layout ends up saying "Total" where the wide one says
 * "Totals".
 */
const LIST_COLUMNS = computed(() => [
	{ key: 'sheet', label: t('pettycash', 'sheets.sheet') },
	{ key: 'status', label: t('pettycash', 'sheets.status') },
	{ key: 'transactionCount', label: t('pettycash', 'sheets.transactionCount') },
	{ key: 'recordedAmount', label: t('pettycash', 'sheets.currentTotal') },
	// The latest-effective figure names the column; FR-062's stage approvals stay beside it inside the
	// same cell, each labelled, because a merged headline erases a senior approval finance later rejected.
	{ key: 'latestApproved', label: t('pettycash', 'sheets.effectiveApproved') },
	{ key: 'firstSubmitted', label: t('pettycash', 'sheets.firstSubmitted') },
])

/**
 * One list cell, rendered identically by the table and by the narrow-viewport card.
 *
 * A render function rather than template markup because the two layouts must show the **same** figures:
 * duplicating the content would make the narrow layout a second, silently divergent presentation of the
 * same money and dates — the defect this component exists to close.
 *
 * The composite cells (totals, latest-effective, key dates, actions) are built by named helpers so this
 * switch reads as a column list rather than a tree of `h()` calls.
 */
/**
 * The open-sheets region's six columns.
 *
 * **A second, smaller list rather than a subset of {@link LIST_COLUMNS}.** The region deliberately omits the
 * fields T381/T384/T385 added below it — it is a prompt to act on sheets still open, not a second sheet
 * list — so sharing the wider list would print figures here that the region exists to omit. Two lists, one
 * per surface, and each cell renderer below says which.
 */
const OPEN_SHEET_COLUMNS = computed(() => [
	{ key: 'title', label: t('pettycash', 'sheets.title') },
	{ key: 'currency', label: t('pettycash', 'sheets.currency') },
	{ key: 'transactionCount', label: t('pettycash', 'sheets.transactionCount') },
	{ key: 'totals', label: t('pettycash', 'sheets.totals') },
	{ key: 'createdAt', label: t('pettycash', 'sheets.createdAt') },
])

/**
 * One open-sheets cell, rendered identically by the table and by the narrow-viewport card.
 *
 * **Narrower than {@link listCell} on purpose**: the current total is printed as the bare figure this
 * region has always shown, with no submitted total, latest-effective group or key dates — the fields the
 * main table below carries and this region omits. A shared renderer would have forced one or the other.
 */
function openSheetCell(item: SheetSummary, column: { key: string }): VNode {
	switch (column.key) {
	case 'title':
		// UI-PUR-008: the actions moved from a dedicated column into the sheet cell, in the same
		// position as the main table's, so the two surfaces behave alike instead of one having a
		// menu and the other a row of buttons.
		return h('div', { class: 'sheet-identity' }, [
			h('div', { class: 'sheet-identity__text' }, [
				h(
					NcButton,
					{ variant: 'tertiary', onClick: () => goToDetail(item.id), 'data-test': 'open-sheet-item' },
					() => item.title
				),
			]),
			sheetActionsMenu(item),
		])
	case 'currency':
		return h('span', null, item.currency)
	case 'transactionCount':
		return h('span', null, item.transactionCount)
	case 'totals':
		return h('span', { 'data-test': 'open-sheet-total' }, formatMoney(item.totals.current))
	case 'createdAt':
		return h('span', { 'data-test': 'open-sheet-created-at' }, formatDate(item.createdAt))
	default:
		return h('span', { 'data-test': `open-sheet-unrendered-${column.key}` }, column.key)
	}
}

function sheetRowKey(item: SheetSummary, index: number): string {
	return item.id || `sheet-row-${index}`
}

/**
 * UI-PUR-008: the row's overflow menu.
 *
 * ## Why a menu rather than inline buttons
 *
 * The approved surface gives each row one three-dot affordance. Two text buttons plus a delete button
 * per row made every row a control bar, and at 390px that is the horizontal-overflow failure FR-071
 * forbids. The menu is also where the actions can be *contextual*: a submitted sheet has no edit or
 * delete action at all, so the same trigger opens one item or three depending on the row.
 *
 * ## Why the items are status-gated here, not only server-side
 *
 * FR-009 forbids relying on the interface to enforce a rule, and the server refuses an edit or delete of
 * anything but an `OPEN` sheet regardless. Hiding the item is not enforcement — it is honesty: a control
 * that is always refused teaches the purchaser that the application does not know its own state. The
 * gate reads the row's own status because that is the same fact the server checks.
 *
 * ## Why the trigger carries the sheet's name
 *
 * Every row's menu has the same icon, and a screen reader announcing "Actions, button" twenty-five times
 * gives the user no way to tell which sheet they are acting on. The accessible name includes the title
 * (`sheets.actionsNamed`), and the item labels do too (`sheets.viewNamed`, `sheets.editNamed`,
 * `sheets.deleteNamed`) — those keys already existed for the inline buttons and keep working unchanged.
 *
 * `force-menu` is deliberate: without it `NcActions` renders the items inline when there is room, which
 * would put a "View/Edit/Delete" row back on the wide layout and make the trigger's shape depend on the
 * viewport width.
 */
function sheetActionsMenu(item: SheetSummary): VNode {
	const actions: VNode[] = [
		h(
			NcActionButton,
			{
				'aria-label': t('pettycash', 'sheets.viewNamed', { name: item.title }),
				onClick: () => goToDetail(item.id),
				'data-test': 'view-sheet',
			},
			() => t('pettycash', 'common.view')
		),
	]

	if (item.status === 'OPEN') {
		actions.push(
			h(
				NcActionButton,
				{
					'aria-label': t('pettycash', 'sheets.editNamed', { name: item.title }),
					onClick: () => goToEdit(item.id),
					'data-test': 'edit-sheet',
				},
				() => t('pettycash', 'common.edit')
			),
			h(
				NcActionButton,
				{
					'aria-label': t('pettycash', 'sheets.deleteNamed', { name: item.title }),
					onClick: () => askDelete(item),
					'data-test': 'delete-sheet',
				},
				() => t('pettycash', 'common.delete')
			)
		)
	}

	return h(
		NcActions,
		{
			forceMenu: true,
			'aria-label': t('pettycash', 'sheets.actionsNamed', { name: item.title }),
			'data-test': 'sheet-actions-trigger',
		},
		{ default: () => actions }
	)
}

/**
 * One labelled amount inside a composite amount cell.
 *
 * The label is per figure rather than per column: three money values stacked under one heading would be
 * three unlabelled numbers, and the reader cannot tell which is approved, which is rejected and which is
 * still pending. This is FR-062 / D-04's requirement in one place.
 */
function amountFigure(money: Money, testId: string, label: string): VNode {
	return h('span', { class: 'amount-figure' }, [
		h('span', { class: 'amount', 'data-test': testId }, formatMoney(money)),
		h('small', { class: 'text-caption' }, label),
	])
}

/**
 * One list cell, rendered identically by the table and by the narrow-viewport card.
 *
 * A render function rather than template markup because the two layouts must show the **same** figures:
 * duplicating the content would make the narrow layout a second, silently divergent presentation of the
 * same money and dates — the defect this component exists to close.
 *
 * The composite cells (sheet identity, recorded amount, latest approved) are built by named helpers so
 * this switch reads as a column list rather than a tree of `h()` calls.
 */
function listCell(item: SheetSummary, column: { key: string }): VNode {
	switch (column.key) {
	// UI-PUR-002/008: title above the id, with the row's actions beside them. This is the only cell
	// in the main table that carries a control, so the table needs no action column of its own — a
	// seventh column whose header says "actions" is the redundant column the card removes.
	case 'sheet':
		return h('div', { class: 'sheet-identity' }, [
			h('div', { class: 'sheet-identity__text' }, [
				h(
					NcButton,
					{ variant: 'tertiary', onClick: () => goToDetail(item.id), 'data-test': 'sheet-list-item' },
					() => item.title
				),
				h('small', { class: 'sheet-identity__id', 'data-test': 'sheet-list-id' }, item.id),
			]),
			sheetActionsMenu(item),
		])
	case 'status':
		return h(StatusBadge, { status: item.status })
	case 'transactionCount':
		// `0` is a real answer and is printed; only a value the server did not send is absent.
		return h(
			'span',
			{ 'data-test': 'sheet-list-transaction-count' },
			formatNumber(item.transactionCount)
		)
	case 'recordedAmount':
		// FR-063's recorded total, with the submitted snapshot beneath it. `submitted_total_minor`
		// is captured at first submission and does not move on correction, so the two figures
		// answer different questions. A never-submitted sheet has no submitted total at all: the
		// payload's `submittedAt` is null, and a dash says "not yet" where "0 IRR" would read as a
		// successful submission of nothing (UI-PUR-002 AC5).
		return h('div', { class: 'amounts-cell' }, [
			h(
				'span',
				{ class: 'amount amount--strong', 'data-test': 'sheet-list-current-total' },
				formatMoney(item.totals.current)
			),
			h(
				'small',
				{ class: 'text-caption', 'data-test': 'sheet-list-submitted-total' },
				`${t('pettycash', 'sheets.submittedTotal')}: ${
					item.submittedAt ? formatMoney(item.totals.submitted) : ABSENT_MARKER
				}`
			),
		])
	case 'latestApproved':
		// FR-062 (D-04): the latest-effective figure and the three stage approvals stay separate,
		// each with its own label, inside this one column. Merging them into a single headline would
		// erase a senior approval that finance later rejected — the exact distinction FR-062 asks
		// both surfaces to preserve.
		return h('div', { class: 'amounts-cell' }, [
			h('div', { class: 'amount-group', 'data-test': 'sheet-list-effective-outcome' }, [
				amountFigure(
					item.totals.effectiveApproved,
					'sheet-list-effective-approved',
					t('pettycash', 'sheets.effectiveApproved')
				),
				amountFigure(
					item.totals.effectiveRejected,
					'sheet-list-effective-rejected',
					t('pettycash', 'sheets.effectiveRejected')
				),
				amountFigure(
					item.totals.effectivePending,
					'sheet-list-effective-pending',
					t('pettycash', 'sheets.effectivePending')
				),
			]),
			h('div', { class: 'amount-group', 'data-test': 'sheet-list-stage-approved' }, [
				amountFigure(
					item.totals.costCenterApproved,
					'sheet-list-cost-center-approved',
					t('pettycash', 'sheets.costCenterApproved')
				),
				amountFigure(
					item.totals.seniorApproved,
					'sheet-list-senior-approved',
					t('pettycash', 'sheets.seniorApproved')
				),
				amountFigure(
					item.totals.financeApproved,
					'sheet-list-finance-approved',
					t('pettycash', 'sheets.financeApproved')
				),
			]),
		])
	case 'firstSubmitted':
		// FR-063's first-submission date, in the sixth column. The hook keeps its old name so the
		// specs that find the value by hook keep finding it in its new column.
		return h('span', { 'data-test': 'sheet-list-submitted-at' }, formatDate(item.submittedAt))
	default:
		// A column with no case is a new column someone forgot to render, not a blank cell. Rendering
		// its key means it is visible and testable rather than silently empty in both layouts.
		return h('span', { 'data-test': `sheet-list-unrendered-${column.key}` }, column.key)
	}
}

/**
 * UI-PUR-002: the list's loading/empty/error state, in the shared `AsyncState` shape.
 *
 * `loading`/`error` only take over the whole card when there are **no rows to keep showing**: a pager
 * move or a refresh keeps the current rows (with `ResponsiveTable`'s `aria-busy`) instead of blanking
 * the table to a spinner, and an action failure (a refused delete, a rejected filter) leaves the data
 * where it is and is reported beside it. That split is why the error alert below is conditioned on
 * "there are rows"; a load failure with nothing to show goes through `AsyncState` exactly once.
 */
const listStatus = computed<AsyncStatus>(() => {
	if (sheetStore.loading && sheetStore.sheets.length === 0) return 'loading'
	if (sheetStore.error && sheetStore.sheets.length === 0) return 'error'
	return 'idle'
})

const listEmpty = computed(
	() => !sheetStore.loading && !sheetStore.error && sheetStore.sheets.length === 0
)

/**
 * "No sheets yet" and "no sheets match" are different answers.
 *
 * The first invites the reader to create one; the second invites them to widen the filter. Showing the
 * creation prompt after a filter that matched nothing is the interface lying about the cause.
 */
const listEmptyMessage = computed(() =>
	Object.keys(sheetStore.filters).length > 0
		? t('pettycash', 'sheets.emptyFiltered')
		: t('pettycash', 'sheets.emptyAll')
)

/**
 * UI-PUR-004: loads one page, then clamps a page number the answer no longer supports.
 *
 * Every caller goes through here rather than calling the store directly, so the clamp cannot be
 * forgotten by the "apply filters" path while being remembered by the pager path. A failure is not
 * swallowed: the store records it in `error`, which `AsyncState` / the alert renders.
 */
async function reloadList(page: number, filters: SheetFilters = sheetStore.filters): Promise<void> {
	try {
		await sheetStore.loadSheets({ page, pageSize: sheetStore.pagination.pageSize, filters })
	} catch {
		return
	}
	await clampToValidPage()
}

/**
 * UI-PUR-004: moves to the last page the current total supports when the requested one is gone.
 *
 * `totalPages` is never below 1, so deleting the only row lands on page 1 (the empty state) rather than
 * on page 0. The notice is raised only when a page was actually substituted.
 */
async function clampToValidPage(): Promise<void> {
	if (sheetStore.pagination.page <= totalPages.value) return
	try {
		await sheetStore.loadSheets({
			page: totalPages.value,
			pageSize: sheetStore.pagination.pageSize,
			filters: sheetStore.filters,
		})
		pageClamped.value = true
	} catch {
		// The fallback load failed too; `error` is already set and rendered.
	}
}

onMounted(async () => {
	if (restoringContext) {
		// UI-PUR-004: same page, same filters — and the rows are fetched again, never replayed from a
		// cache, because this screen is where a purchaser decides whether a sheet can still be edited.
		draft.value = { ...sheetStore.filters }
		await reloadList(sheetStore.pagination.page)
		await nextTick()
		restoreScrollOffset(takeSheetListScroll(), listRoot.value)
		return
	}

	// A fresh visit starts a fresh list: page 1, no filters, top of the page. Anything else would make
	// the sidebar's "Sheets" entry open wherever the last visit happened to end.
	draft.value = {}
	pageClamped.value = false
	rememberSheetListScroll(0)
	await reloadList(1, {})
	restoreScrollOffset(0, listRoot.value)
})

/**
 * The sheet the confirmation dialog is open for, and nothing else.
 *
 * Held as the whole row rather than as an id so the dialog can **name the sheet** in its body — FR-086 requires
 * the confirmation to say which sheet is about to go, and a purchaser with three open sheets cannot otherwise tell.
 * `null` means no dialog is open, so Cancel needs no separate flag.
 */
const sheetPendingDeletion = ref<SheetSummary | null>(null)

/**
 * UI-PUR-004: every route from this screen into a sheet records the offset on the way out.
 *
 * Capturing on the way *out* (rather than in a leave-guard) is what makes the restore independent of
 * how the reader returns: the forward navigation always goes through one of these three functions,
 * and the router decides on the return whether that offset is still this list's.
 */
function goToCreate() {
	rememberSheetListScroll(readScrollOffset(listRoot.value))
	router.push({ name: 'sheet-create' })
}

function goToDetail(sheetId: string) {
	rememberSheetListScroll(readScrollOffset(listRoot.value))
	router.push({ name: 'sheet-detail', params: { id: sheetId } })
}

function goToEdit(sheetId: string) {
	rememberSheetListScroll(readScrollOffset(listRoot.value))
	router.push({ name: 'sheet-edit', params: { id: sheetId } })
}

/**
 * FR-086: stage a sheet for deletion rather than deleting it on click.
 *
 * Opening the dialog is the whole point of this function — the cascade destroys stored evidence files that cannot
 * be recovered, so the delete must never be one unconfirmed click away from a row button. Nothing is mutated here;
 * the sheet being staged is the only state, so choosing Cancel cannot leave anything behind.
 */
function askDelete(sheet: SheetSummary) { sheetPendingDeletion.value = sheet }

async function confirmDelete() {
	const sheet = sheetPendingDeletion.value
	if (!sheet) return
	sheetPendingDeletion.value = null
	try {
		await sheetStore.deleteOwnSheet(sheet.id, sheet.version)
		// UI-PUR-004: deleting the last row of the last page leaves the reader on a page that no longer
		// exists; move to the new last page instead of showing an empty page under a stale pager.
		await clampToValidPage()
	} catch {
		// `sheetStore.error` is rendered by this view (beside the rows, or by `AsyncState`).
	}
}

</script>

<template>
	<div ref="listRoot" class="sheet-list-view" :dir="direction" data-test="sheet-list-view">
		<div class="header-row">
			<h1 class="mb-0">{{ t('pettycash', 'sheets.title') }}</h1>
			<NcButton
				variant="primary"
				:aria-label="t('pettycash', 'sheets.create')"
				data-test="create-sheet-button"
				@click="goToCreate"
			>
				<NcIconSvgWrapper :path="mdiPlus" :size="16" class="mr-2" />
				{{ t('pettycash', 'sheets.create') }}
			</NcButton>
		</div>

		<!--
			FR-064's four sheet facets: status, date, currency and creator. T333 delivered the server half
			of these and recorded the client half as a limitation; these controls are that half.

			Every name here is one of `SheetListFilterFactory::ACCEPTED_FILTERS` -- `status`, `ownerId`,
			`currency`, `from`, `to`. That vocabulary is **closed**: an unrecognised value is refused with a
			422 naming the accepted set rather than ignored, because an ignored filter returns the
			unfiltered answer and the caller cannot tell the difference.
		-->
		<form
			class="sheet-filters"
			:dir="direction"
			:aria-label="t('pettycash', 'sheets.filters')"
			data-test="sheet-filters"
			@submit.prevent="applyFilters"
		>
			<div class="sheet-filters__grid">
				<NcSelect
					:model-value="draft.status ?? null"
					:options="statusOptions"
					:reduce="selectOptionValue"
					:get-option-key="selectOptionKey"
					:clearable="true"
					:label="t('pettycash', 'sheets.filter.status')"
					data-test="sheet-filter-status"
					@update:model-value="draft.status = orUndefined($event)"
				/>
				<NcTextField
					:model-value="draft.currency ?? ''"
					:label="t('pettycash', 'sheets.filter.currency')"
					data-test="sheet-filter-currency"
					@update:model-value="draft.currency = orUndefined($event)"
				/>
				<label class="sheet-filters__field">
					<span class="sheet-filters__label">{{ t('pettycash', 'sheets.filter.from') }}</span>
					<input
						:model-value="draft.from ?? ''"
						type="date"
						data-test="sheet-filter-from"
						@input="draft.from = ($event.target as HTMLInputElement).value"
					>
				</label>
				<label class="sheet-filters__field">
					<span class="sheet-filters__label">{{ t('pettycash', 'sheets.filter.to') }}</span>
					<input
						:model-value="draft.to ?? ''"
						type="date"
						data-test="sheet-filter-to"
						@input="draft.to = ($event.target as HTMLInputElement).value"
					>
				</label>
				<!--
					The creator facet, rendered rather than typed. See `creatorScope` for why an editable box
					would only ever invite the 403 the server issues for naming anybody but the caller.
				-->
				<div class="sheet-filters__field" data-test="sheet-filter-creator">
					<span class="sheet-filters__label">{{ t('pettycash', 'sheets.filter.creator') }}</span>
					<span class="sheet-filters__scope" data-test="sheet-filter-creator-value">
						{{ creatorScope || t('pettycash', 'sheets.filter.creatorSelf') }}
					</span>
				</div>
			</div>

			<div class="sheet-filters__actions">
				<NcButton variant="secondary" data-test="sheet-filter-reset" @click="resetFilters">
					{{ t('pettycash', 'sheets.filters.reset') }}
				</NcButton>
				<NcButton variant="primary" type="submit" data-test="sheet-filter-apply">
					{{ t('pettycash', 'sheets.filters.apply') }}
				</NcButton>
			</div>
		</form>

		<!--
			FR-018 lets a purchaser hold several open sheets at once and FR-061 asks for a dashboard of
			the caller's relevant open work. The open sheets are the actionable ones — a transaction can
			still be added to them and they can still be submitted — so they are listed as their own
			region instead of sitting in one undifferentiated table. The store already computed
			`openSheets`; the view simply never used it.
		-->
		<NcNoteCard :heading="t('pettycash', 'sheets.openSheets')" data-test="open-sheets">
			<ResponsiveTable
				v-if="sheetStore.openSheets.length"
				:columns="OPEN_SHEET_COLUMNS"
				:rows="sheetStore.openSheets"
				:cell="openSheetCell"
				:row-key="sheetRowKey"
				:caption="t('pettycash', 'sheets.openSheets')"
				test-id="open-sheets-table"
				test-id-prefix="open-sheet"
				row-test-id="open-sheet-row"
			/>
			<p v-else class="empty-hint" data-test="no-open-sheets">
				{{ t('pettycash', 'sheets.noOpenSheets') }}
			</p>
		</NcNoteCard>

		<NcNoteCard data-test="sheet-list">
			<!--
				FR-071 (T389): this table used to sit in `overflow-x: auto`, which produces a horizontal
				scrollbar at 390px rather than satisfying the requirement -- exactly what
				`src/styles/app.scss` says must not be done. `ResponsiveTable` renders the same rows as a
				`<table>` above 640px and as one card per row below it, from **one** column list and **one**
				cell renderer, so the narrow layout cannot become a second presentation of the same figures.

				The `open-sheets` card above keeps its own table and its own narrow-viewport handling: it is a
				separate surface, and T389 lists it separately rather than widening this one.

				UI-PUR-002: the loading/empty/error states around it are the shared `AsyncState` component,
				not a fourth hand-rolled block. The two empty messages ("nothing yet" vs "nothing matches")
				are supplied by the view, because only the view knows whether a filter is applied.
			-->
			<AsyncState
				:status="listStatus"
				:error="sheetStore.error"
				:empty="listEmpty"
				:empty-message="listEmptyMessage"
				retry-test-id="sheet-list-retry"
				@retry="reloadList(sheetStore.pagination.page)"
			>
				<ResponsiveTable
					:columns="LIST_COLUMNS"
					:rows="sheetStore.sheets"
					:cell="listCell"
					:row-key="sheetRowKey"
					:loading="sheetStore.loading"
					:caption="t('pettycash', 'sheets.title')"
					test-id="sheet-list-table"
					test-id-prefix="sheet-list"
					row-test-id="sheet-row"
				/>
			</AsyncState>
		</NcNoteCard>

		<!--
			The pager, driven by the total the server reported.

			## Why this is not a cosmetic addition

			A sheet beyond the first page is a sheet whose **status** the caller cannot see, so a returned
			transaction they must correct and a sheet they must submit are both potentially invisible.
			FR-073's documented V1 scale is 100 active users, so 25 sheets per purchaser is inside the
			stated operating envelope rather than beyond it -- the default page size is not protecting
			anyone from paging.

			## Why previous/next and not a row of page numbers

			`total` is unbounded -- a purchaser with hundreds of sheets would render hundreds of buttons, and
			that is the FR-071 failure (everything visible without horizontal scroll at 390px) arriving from
			the other direction. Two controls plus a position readout stay one row at any total.
		-->
		<nav
			v-if="totalPages > 1"
			class="sheet-pager"
			:aria-label="t('pettycash', 'sheets.pager')"
			data-test="sheet-pager"
		>
			<NcButton
				:disabled="!canGoPrevious || sheetStore.loading"
				data-test="sheet-page-previous"
				@click="goToPage(currentPage - 1)"
			>
				{{ t('pettycash', 'sheets.pager.previous') }}
			</NcButton>
			<span class="sheet-pager__position" data-test="sheet-page-position">
				<!-- UI-PUR-001: the numerals are formatted like every other number on the screen. `t()`
				     substitutes its parameters verbatim, so passing the raw integers here is what printed
				     Latin digits in an otherwise Persian page. -->
				{{ t('pettycash', 'sheets.pager.position', { page: formatNumber(currentPage), total: formatNumber(totalPages) }) }}
			</span>
			<NcButton
				:disabled="!canGoNext || sheetStore.loading"
				data-test="sheet-page-next"
				@click="goToPage(currentPage + 1)"
			>
				{{ t('pettycash', 'sheets.pager.next') }}
			</NcButton>
		</nav>

		<!--
			UI-PUR-004: the restored page no longer exists.

			Not an error: the list is correct and usable, the reader just needs to know why the page
			number moved. It is a warning beside the pager rather than a replacement for the list.
		-->
		<NcNoteCard v-if="pageClamped" type="warning" data-test="sheet-page-clamped">
			{{ t('pettycash', 'sheets.pageClamped') }}
		</NcNoteCard>

		<!--
			UI-PUR-002: an action failure (a refused delete, a failed filter) is reported beside the rows
			it did not change. A *load* failure with no rows is `AsyncState`'s error instead, so the two
			never render the same failure twice.
		-->
		<div
			v-if="sheetStore.error && sheetStore.sheets.length > 0"
			class="alert alert--error"
			role="alert"
		>
			<span>{{ sheetStore.error }}</span>
			<button type="button" :aria-label="t('pettycash', 'common.dismiss')" @click="sheetStore.clearError">×</button>
		</div>

		<!--
			FR-086's confirmation, on the list surface. Mounted here rather than inside the actions cell so the
			dialog is a single instance for the whole table: one per row would mean one per `NcButton` in a
			`cell()` render function, and a dialog whose opener it cannot identify is exactly the case the focus
			restoration in `DeleteSheetDialog` handles explicitly.
		-->
		<DeleteSheetDialog
			:sheet-id="sheetPendingDeletion?.id ?? ''"
			:sheet-title="sheetPendingDeletion?.title ?? ''"
			:open="sheetPendingDeletion !== null"
			:busy="sheetStore.loading"
			@cancel="sheetPendingDeletion = null"
			@confirm="confirmDelete"
		/>
	</div>
</template>

<style scoped>
.sheet-list-view {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

/*
 * UI-PUR-002: `NcNoteCard` renders its slot in an **unnamed child `div`**, and the card is a flex
 * column, so that div is a flex item whose automatic minimum size is its content's min-content size —
 * the six-column table. The card therefore stretched to the table instead of the table reflowing to
 * the card, and the columns past the `max-width: 1200px` application shell were painted outside it and
 * were unreachable at every desktop width.
 *
 * Making the slot shrinkable is the whole fix: the table keeps its `width: 100%` and wraps its cells
 * instead of overflowing. It is also the only fix the repository allows here — T389 and
 * `fr071NarrowViewport.spec.ts` forbid answering an overflow with `overflow-x: auto|scroll`, because
 * FR-071 requires the overflow to be *fixed*, not scrolled.
 */
:deep(.notecard > div) {
	min-width: 0;
}

.empty-hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.header-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 0.5rem;
}

/*
 * The filter bar and the pager.
 *
 * Both use `auto-fit`/`minmax` rather than a fixed column count, so the controls reflow to the viewport
 * instead of forcing one: FR-071 requires everything visible without horizontal scroll at 390px, and a
 * four-column grid is what would break that. `flex-wrap` on the action rows is the same rule one level
 * down.
 */
.sheet-filters {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding: 0.75rem;
	border: 1px solid var(--nc-color-border, #dbdbdb);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--nc-color-background, #fff);
}

.sheet-filters__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 0.75rem;
}

.sheet-filters__field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.sheet-filters__label {
	font-size: var(--font-size-floor);
	color: var(--nc-color-text-maxcontrast, #6b6b6b);
}

/*
 * The resolved creator scope.
 *
 * Italic is not used for "this is fixed" — a visual convention a screen reader cannot announce and that
 * has no contrast guarantee. The value is simply presented as text beside its label, and the reason it is
 * not an input lives in the component's script.
 */
.sheet-filters__scope {
	font-size: var(--font-size-floor);
	color: var(--nc-color-text-maxcontrast, #6b6b6b);
}

.sheet-filters__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	justify-content: flex-start;
}

.sheet-pager {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.75rem;
	justify-content: center;
	padding: 0.5rem 0;
}

.sheet-pager__position {
	font-size: var(--font-size-floor);
	color: var(--nc-color-text-maxcontrast, #6b6b6b);
}

.nc-table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--font-size-floor);
}

.nc-table th,
.nc-table td {
	padding: 0.75rem;
	text-align: start;
	border-bottom: 1px solid var(--nc-color-outline-variant, #e0e0e0);
}

.nc-table th {
	font-weight: 600;
	color: var(--nc-color-on-surface-variant, #616161);
	background-color: var(--nc-color-surface-container-lowest, #fff);
}

.nc-table tbody tr:hover {
	background-color: var(--nc-color-surface-container-low, #fafafa);
}

/*
 * UI-PUR-002: the sheet cell — title, id, and the row's overflow menu on one line.
 *
 * `flex-wrap` keeps the menu beside the title when there is room and lets it fall under the title at
 * 390px rather than squeezing the title to an ellipsis. The id is monospaced and muted: it is a
 * reference, not a title, and the title must stay the element the eye lands on.
 *
 * `:deep()` is required, not stylistic. `listCell` is invoked by `ResponsiveTable` during **its**
 * render pass, so the nodes it returns carry ResponsiveTable's scope id, not this view's — a plain
 * scoped selector like `.sheet-identity` never matches them, and the styles silently do nothing.
 */
:deep(.sheet-identity) {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 0.5rem;
}

:deep(.sheet-identity__text) {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

:deep(.sheet-identity__id) {
	color: var(--color-text-maxcontrast);
	font-family: var(--font-face-monospace, monospace);
	overflow-wrap: anywhere;
}

/*
 * The two money columns.
 *
 * A column stack rather than a row: the recorded amount sits above its submitted snapshot, and the
 * latest-approved column holds two groups of three labelled figures each (FR-062 / D-04). `tabular-nums`
 * keeps the digits aligned so two rows can be compared by eye.
 */
:deep(.amounts-cell) {
	display: flex;
	flex-direction: column;
	gap: 0.35rem;
}

:deep(.amount-group) {
	display: flex;
	flex-wrap: wrap;
	gap: 0.75rem;
}

:deep(.amount-figure) {
	display: flex;
	flex-direction: column;
	gap: 0.05rem;
}

:deep(.amount) {
	font-variant-numeric: tabular-nums;
}

:deep(.amount--strong) {
	font-weight: 600;
}

/*
 * UI-PUR-002: the table must fit the shell by reflowing, and an unbreakable word is what stops it.
 *
 * The English render currently falls back to raw message keys (`sheets.effectiveApproved`), which are
 * longer than any real label and cannot wrap at a space — they set the column's min-content width and
 * pushed two columns past the shell edge. `overflow-wrap: anywhere` (not `break-word`, which does not
 * change min-content) lets a long word break only when it genuinely cannot fit, so translated copy is
 * untouched while the fallback state stays inside the shell.
 */
:deep(th),
:deep(.text-caption),
:deep(.amount) {
	overflow-wrap: anywhere;
}

.alert {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.75rem 1rem;
	border-radius: 4px;
}

.alert--error {
	background-color: var(--nc-color-error-container, #fce4ec);
	color: var(--nc-color-on-error-container, #c62828);
}

.alert button {
	background: none;
	border: none;
	font-size: 1.5rem;
	cursor: pointer;
	color: inherit;
	opacity: 0.7;
}

.alert button:hover {
	opacity: 1;
}

	.mr-2 {
		margin-inline-end: 0.5rem;
	}

	.text-center {
		text-align: center;
	}

	.py-8 {
		padding-top: 2rem; padding-bottom: 2rem;
	}
</style>
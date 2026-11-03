<script setup lang="ts">
import { direction } from '@/utils/direction'
import { computed, h, nextTick, onMounted, ref, watch, type VNode } from 'vue'
import { useRoute, useRouter } from 'vue-router'
// UI-PUR-008: the overflow menu is imported from the package root, like `DeleteSheetDialog`'s
// `NcDialog`: it is a composite (trigger + popover) and the root entry is the public surface for it.
import { NcActionButton, NcActions } from '@nextcloud/vue'
import { useSheetStore } from '@/stores/sheets'
import NcButton from '@nextcloud/vue/components/NcButton'
import DeleteSheetDialog from '@/components/shared/DeleteSheetDialog.vue'
import AsyncState from '@/components/shared/AsyncState.vue'
import SheetFormDialog from '@/components/sheets/SheetFormDialog.vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ResponsiveTable from '@/components/shared/ResponsiveTable.vue'
import SheetDetailView from '@/views/sheets/SheetDetailView.vue'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import { mdiChevronLeft, mdiChevronRight, mdiDotsVertical, mdiMagnify, mdiPlus, mdiRefresh, mdiWalletOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import type { AsyncStatus } from '@/composables/useAsyncAction'
import { formatSheetDate as formatDate } from '@/utils/date'
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
import { SHEET_STATUSES, type SheetFilters, type SheetSummary } from '@/types/api'

const router = useRouter()
const route = useRoute()
const sheetStore = useSheetStore()

/**
 * UI-PUR-005: the detail is an overlay over this list, so the list keeps its rows and scroll.
 *
 * T080: `sheet-edit` carries an `:id` too, but only a workspace-originated edit keeps the workspace
 * mounted behind the modal. A list-row or direct edit renders the list behind it. The route name and
 * the explicit provenance marker decide this; the bare presence of a parameter cannot, because it is
 * true for both routes.
 */
const isWorkspaceOpen = computed(() =>
	route.name === 'sheet-detail'
	|| (route.name === 'sheet-edit' && route.query.from === 'workspace')
)

/**
 * T079: `/sheets/create` is the list with the Create Sheet dialog open.
 *
 * The route, not local state, decides whether the dialog is mounted. That is what keeps the URL
 * addressable and the browser's Back button meaningful: closing the dialog navigates to `/sheets`,
 * and arriving at `/sheets/create` opens it again.
 */
const isCreateOpen = computed(() => route.name === 'sheet-create')
/** T080: the edit route is the same list with the shared edit modal open. */
const isEditOpen = computed(() => route.name === 'sheet-edit')
const createButton = ref<InstanceType<typeof NcButton> | null>(null)
const listHeading = ref<HTMLElement | null>(null)
/**
 * Which control takes focus when the dialog closes. A click on the list's Create button returns to
 * that button; a direct visit to `/sheets/create` has no invoking button, so the list heading is the
 * honest place to resume.
 */
const createFocusTarget = ref<'button' | 'heading'>(isCreateOpen.value ? 'heading' : 'button')

function focusCreateReturnTarget(): void {
	const target = createFocusTarget.value === 'heading'
		? listHeading.value
		: (createButton.value?.$el as HTMLElement | undefined)
	target?.focus()
}

watch(isCreateOpen, async (open, wasOpen) => {
	if (!wasOpen || open) return
	await nextTick()
	// `AppRoot` focuses the app heading after the same route change. A zero-delay turn runs after
	// that handler has resumed, so the control the user came from wins.
	window.setTimeout(focusCreateReturnTarget, 0)
})
const lastOpenedSheetId = ref<string | null>(null)

watch(() => route.params.id, (id, previous) => {
	if (!previous || id) return
	const opened = lastOpenedSheetId.value
	lastOpenedSheetId.value = null
	void nextTick(() => {
		// A surface opened from a row returns focus to that row. A direct `/sheets/:id[/edit]` visit
		// has no invoking row, so the list heading is the honest place to resume (T080 direct-entry
		// behavior, matching the create dialog's direct-entry contract).
		const row = opened
			? document.querySelector<HTMLElement>(`[data-sheet-open="${opened}"]`)
			: null
		;(row ?? listHeading.value)?.focus()
	})
})

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

// The catalogue spells the sheet-status keys in lower case (`sheets.status.open`) while the API's
// enum values are upper case, so the key is built from the lower-cased member: passing the enum
// verbatim looks up a key the catalogue does not carry and prints the raw key as the user's chosen
// value.
const statusOptions = computed(() =>
	SHEET_STATUSES.map((status) => ({
		label: t('pettycash', `sheets.status.${status.toLowerCase()}`),
		value: status,
	}))
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

/**
 * The approved toolbar's secondary facet panel.
 *
 * The approved toolbar carries three controls (search, status, account). Two of them name values the
 * list endpoint does not accept, and three of FR-064's four facets have no slot in that row. This flag
 * is the one piece of state the "More filters" control drives, so the row keeps the approved shape
 * while every supported facet stays reachable.
 */
const moreFiltersOpen = ref(false)

/**
 * Applies the status facet the moment it changes, without waiting for an Apply click.
 *
 * The approved toolbar gives Status the only active slot in its row, and the prototype it was drawn
 * from narrows the rows as the value changes. Waiting for a submit button that the row no longer
 * displays would leave the select looking broken, so the apply path is the same one the form's submit
 * uses — page 1, the whole draft — and only the trigger is different.
 */
async function onStatusChange(value: string | number | null | undefined): Promise<void> {
	draft.value.status = orUndefined(value ?? undefined)
	await applyFilters()
}

/** The last page the current total supports, never below 1 for an empty result. */
const totalPages = computed(() =>
	Math.max(1, Math.ceil(sheetStore.pagination.total / Math.max(1, sheetStore.pagination.pageSize)))
)

const currentPage = computed(() => sheetStore.pagination.page)
const canGoPrevious = computed(() => currentPage.value > 1)
const canGoNext = computed(() => currentPage.value < totalPages.value)

/**
 * The page buttons the numbered pager renders.
 *
 * The approved pager draws one round button per page instead of a "page 3 of 9" readout. A page count
 * is unpredictable — a purchaser whose list grows past the first screen — so the window is bounded
 * rather than rendered in full: a pager that grows a button per page would repeat the
 * horizontal-overflow failure FR-071 exists to prevent, at desktop widths rather than only at 390px.
 * Five is the window the pagers around Nextcloud use, and it is wide enough that a page is usually
 * reachable in one step.
 */
const pageNumbers = computed(() => {
	const total = totalPages.value
	const window = 5
	if (total <= window) return Array.from({ length: total }, (_, index) => index + 1)

	const end = Math.min(total, Math.max(window, currentPage.value + 2))
	const start = Math.max(1, end - window + 1)
	return Array.from({ length: end - start + 1 }, (_, index) => start + index)
})

/** The visible range, for the "showing X to Y of Z" readout beside the pager. */
const rangeStart = computed(() => sheetStore.pagination.total === 0
	? 0
	: (currentPage.value - 1) * sheetStore.pagination.pageSize + 1)
const rangeEnd = computed(() => (currentPage.value - 1) * sheetStore.pagination.pageSize + sheetStore.sheets.length)

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
	// The approved headers draw the paired sort arrows on every column. Sorting itself is
	// **server-owned** (`GET /sheets` has no order parameter): `BE-CR-002` is unapproved and
	// `FR-005-012` forbids sorting the loaded page client-side, so under the visual-first
	// directive (2026-10-21) the affordance is drawn **disabled** -- the visual is complete and
	// clearly non-operational, with its reason in the title and the unavailable note under the
	// toolbar. Connecting it later is a prop away, with no redesign.
	{ key: 'sheet', label: t('pettycash', 'sheets.sheet'), sortTitle: t('pettycash', 'sheets.filter.sortUnavailable') },
	{ key: 'status', label: t('pettycash', 'sheets.status'), sortTitle: t('pettycash', 'sheets.filter.sortUnavailable') },
	{ key: 'transactionCount', label: t('pettycash', 'sheets.transactionCount'), sortTitle: t('pettycash', 'sheets.filter.sortUnavailable') },
	{ key: 'recordedAmount', label: t('pettycash', 'sheets.currentTotal'), sortTitle: t('pettycash', 'sheets.filter.sortUnavailable') },
	// The approved list answers one question at a time. The stage breakdown remains on the sheet's
	// Overview tab; repeating six figures in a row made the table unreadable and pushed the last two
	// columns outside the surface.
	{ key: 'latestApproved', label: t('pettycash', 'sheets.effectiveApproved'), sortTitle: t('pettycash', 'sheets.filter.sortUnavailable') },
	{ key: 'firstSubmitted', label: t('pettycash', 'sheets.firstSubmitted'), sortTitle: t('pettycash', 'sheets.filter.sortUnavailable') },
])

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
				closeAfterClick: true,
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
					// T080: the list remains mounted behind the edit dialog, so an open row menu would
					// be painted above the modal. The action dismisses its own menu on selection.
					closeAfterClick: true,
					'data-test': 'edit-sheet',
				},
				() => t('pettycash', 'common.edit')
			),
			h(
				NcActionButton,
				{
					'aria-label': t('pettycash', 'sheets.deleteNamed', { name: item.title }),
					onClick: () => askDelete(item),
					closeAfterClick: true,
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
		{
			default: () => actions,
			icon: () => h(NcIconSvgWrapper, { path: mdiDotsVertical, size: 20 }),
		}
	)
}

/**
 * One list cell, rendered identically by the table and by the narrow-viewport card.
 *
 * A render function rather than template markup because the two layouts must show the **same** figures:
 * duplicating the content would make the narrow layout a second, silently divergent presentation of the
 * same money and dates — the defect this component exists to close.
 *
 * The approved list is deliberately quiet. Recorded and latest-approved amounts are headline values;
 * the stage breakdown lives in the sheet workspace's Overview tab, where a reader who needs it is
 * already looking at the sheet. Repeating six figures per row was the visual failure the redesign
 * exists to remove.
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
					{ variant: 'tertiary', onClick: () => goToDetail(item.id), 'data-test': 'sheet-list-item', 'data-sheet-open': item.id, title: item.title },
					() => item.title
				),
				// The approved cell marks the reference line with the sheet's own glyph. The ID is
				// metadata rather than the row's primary text, and the glyph is what keeps the line from
				// reading as a muted second copy of the title above it.
				h('small', { class: 'sheet-identity__id', 'data-test': 'sheet-list-id', title: item.id }, [
					h(
						NcIconSvgWrapper,
						{ path: mdiWalletOutline, size: 15, 'aria-hidden': 'true', class: 'sheet-identity__id-icon' }
					),
					h('span', { class: 'sheet-identity__id-text' }, item.id),
				]),
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
		return h(
			'span',
			{ class: 'amount amount--strong', 'data-test': 'sheet-list-current-total' },
			formatMoney(item.totals.current)
		)
	case 'latestApproved':
		return h('div', { class: 'amounts-cell', 'data-test': 'sheet-list-effective-outcome' }, [
			h(
				'span',
				{
					class: ['amount', { 'amount--success': item.totals.effectiveApproved.amountMinor > 0 }],
					'data-test': 'sheet-list-effective-approved',
				},
				formatMoney(item.totals.effectiveApproved)
			),
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
	if (sheetStore.listLoading && sheetStore.sheets.length === 0) return 'loading'
	if (sheetStore.listError && sheetStore.sheets.length === 0) return 'error'
	return 'idle'
})

const listEmpty = computed(
	() => !sheetStore.listLoading && !sheetStore.listError && sheetStore.sheets.length === 0
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
	createFocusTarget.value = 'button'
	rememberSheetListScroll(readScrollOffset(listRoot.value))
	router.push({ name: 'sheet-create' })
}

/**
 * T079: the form emitted after the sheet was stored.
 *
 * The store's temporary `unshift` is not the list's authority. Close the dialog, confirm the create
 * politely, then reload the current page with the current filters so the rows and the pager describe
 * what the server actually holds. The new sheet is deliberately not opened: a purchaser who is
 * creating several sheets in a row stays where they can see them.
 */
async function handleCreated(): Promise<void> {
	const page = sheetStore.pagination.page
	const filters = { ...sheetStore.filters }
	await router.push({ name: 'sheets' })
	try {
		const { showSuccess } = await import('@nextcloud/dialogs')
		showSuccess(t('pettycash', 'sheets.createSuccess'))
	} catch {
		// The toast is a courtesy; a toast failure must not make a stored sheet look unsaved.
	}
	await reloadList(page, filters)
}

function onCreated(): void {
	void handleCreated()
}

function goToDetail(sheetId: string) {
	rememberSheetListScroll(readScrollOffset(listRoot.value))
	lastOpenedSheetId.value = sheetId
	router.push({ name: 'sheet-detail', params: { id: sheetId } })
}

function goToEdit(sheetId: string) {
	rememberSheetListScroll(readScrollOffset(listRoot.value))
	// Remember the row as the return-focus target. The edit route has no `?from=workspace`, so the
	// shared form returns to this list when the modal is saved or cancelled.
	lastOpenedSheetId.value = sheetId
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
		<div class="header-row sheet-list-header">
			<div class="sheet-list-header__text">
				<h1 ref="listHeading" class="mb-0" tabindex="-1" data-test="sheet-list-heading">{{ t('pettycash', 'sheets.title') }}</h1>
				<p class="sheet-list-subtitle">{{ t('pettycash', 'sheets.subtitle') }}</p>
			</div>
			<NcButton
				ref="createButton"
				variant="primary"
				class="create-sheet-button"
				:aria-label="t('pettycash', 'sheets.newSheet')"
				data-test="create-sheet-button"
				@click="goToCreate"
			>
				<NcIconSvgWrapper :path="mdiPlus" :size="16" class="mr-2" />
				{{ t('pettycash', 'sheets.newSheet') }}
			</NcButton>
		</div>

		<!--
			The approved toolbar. Its row carries three controls — search, status, account — and a reset
			action; the facets FR-064 supports that the row has no slot for stay reachable behind "More
			filters", and nothing here overrides the endpoint.

			Every filter name this form can send is one of `SheetListFilterFactory::ACCEPTED_FILTERS` --
			`status`, `ownerId`, `currency`, `from`, `to`. That vocabulary is **closed**: an unrecognised
			value is refused with a 422 naming the accepted set rather than ignored, because an ignored
			filter returns the unfiltered answer and the caller cannot tell the difference.

			Search and account are drawn and **disabled**, on an explicit visual-first decision: the
			endpoint accepts neither, and a control that accepts text and changes nothing is the
			misleading filter the decision forbids.
		-->
		<form
			class="sheet-filters"
			:dir="direction"
			:aria-label="t('pettycash', 'sheets.filters')"
			data-test="sheet-filters"
			@submit.prevent="applyFilters"
		>
			<div class="sheet-filters__toolbar">
				<!--
					The approved row's first control. `GET /sheets` reads no search value and the filter
					factory refuses everything outside its closed set, so the box is drawn **disabled**: a
					control that accepts text and changes nothing is the misleading filter the approved
					decision forbids.
				-->
				<label class="sheet-filters__field sheet-filters__field--search">
					<!-- No visible label in the approved row: the placeholder names the box, and the
					     accessible name is supplied below. -->
					<span class="sheet-filters__search" :title="t('pettycash', 'sheets.filter.unavailable')">
						<NcIconSvgWrapper :path="mdiMagnify" :size="18" class="sheet-filters__search-icon" />
						<input
							class="sheet-filters__search-input"
							type="search"
							disabled
							:placeholder="t('pettycash', 'sheets.filter.searchPlaceholder')"
							:aria-label="t('pettycash', 'sheets.filter.search')"
							data-test="sheet-filter-search-input"
						>
					</span>
				</label>

				<label class="sheet-filters__field">
					<span class="sheet-filters__label">{{ t('pettycash', 'sheets.filter.status') }}</span>
					<NcSelect
						:model-value="draft.status ?? null"
						:options="statusOptions"
						:reduce="selectOptionValue"
						:get-option-key="selectOptionKey"
						:clearable="true"
						:placeholder="t('pettycash', 'sheets.filter.all')"
						:aria-label="t('pettycash', 'sheets.filter.status')"
						data-test="sheet-filter-status"
						@update:model-value="onStatusChange($event)"
					/>
				</label>

				<label class="sheet-filters__field">
					<span class="sheet-filters__label">{{ t('pettycash', 'sheets.account') }}</span>
					<NcSelect
						:model-value="null"
						:options="[]"
						disabled
						:placeholder="t('pettycash', 'sheets.filter.all')"
						:aria-label="t('pettycash', 'sheets.account')"
						:title="t('pettycash', 'sheets.filter.unavailable')"
						data-test="sheet-filter-account"
					/>
				</label>

				<div class="sheet-filters__actions">
					<NcButton
						variant="secondary"
						data-test="sheet-filter-more"
						:aria-expanded="moreFiltersOpen ? 'true' : 'false'"
						@click="moreFiltersOpen = !moreFiltersOpen"
					>
						{{ t('pettycash', 'sheets.filters.more') }}
					</NcButton>
					<NcButton variant="secondary" data-test="sheet-filter-reset" @click="resetFilters">
						<NcIconSvgWrapper :path="mdiRefresh" :size="16" />
						{{ t('pettycash', 'sheets.filters.reset') }}
					</NcButton>
				</div>
			</div>

			<p class="sheet-filters__notice" data-test="sheet-filter-notice">
				{{ t('pettycash', 'sheets.filter.unavailableNote') }}
			</p>

			<!--
				The facets the approved row has no slot for. They are **supported** by the endpoint, so a
				panel the user has to open must still carry them: the panel is always mounted and only
				visually collapsed, which keeps the facet hooks in the DOM and reachable by keyboard and
				test alike.
			-->
			<div class="sheet-filters__more" :class="{ 'sheet-filters__more--open': moreFiltersOpen }">
				<NcTextField
					:model-value="draft.currency ?? ''"
					:label="t('pettycash', 'sheets.filter.currency')"
					data-test="sheet-filter-currency"
					@update:model-value="draft.currency = orUndefined($event)"
				/>
				<label class="sheet-filters__field">
					<span class="sheet-filters__label">{{ t('pettycash', 'sheets.filter.createdFrom') }}</span>
					<input
						:value="draft.from ?? ''"
						type="date"
						data-test="sheet-filter-from"
						@input="draft.from = orUndefined(($event.target as HTMLInputElement).value)">
					<small v-if="draft.from" class="sheet-filters__hint" data-test="sheet-filter-from-hint">
						{{ formatDate(draft.from) }}
					</small>
				</label>
				<label class="sheet-filters__field">
					<span class="sheet-filters__label">{{ t('pettycash', 'sheets.filter.createdTo') }}</span>
					<input
						:value="draft.to ?? ''"
						type="date"
						data-test="sheet-filter-to"
						@input="draft.to = orUndefined(($event.target as HTMLInputElement).value)">
					<small v-if="draft.to" class="sheet-filters__hint" data-test="sheet-filter-to-hint">
						{{ formatDate(draft.to) }}
					</small>
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

				<NcButton variant="primary" type="submit" data-test="sheet-filter-apply">
					{{ t('pettycash', 'sheets.filters.apply') }}
				</NcButton>
			</div>
		</form>

		<!--
			The approved list is a single table. The earlier OPEN-first note card above the table
			duplicated the same sheets and the same money, and it made the page read as two products.
			An OPEN sheet is still visible in this table (its status column says so); the server keeps
			ordering authority, so the client does not pretend to reorder the page.

			UI-PUR-002: the loading/empty/error states around it are the shared `AsyncState` component,
			not a fourth hand-rolled block. The two empty messages ("nothing yet" vs "nothing matches")
			are supplied by the view, because only the view knows whether a filter is applied.
		-->
		<section class="sheet-list-panel" data-test="sheets">
			<AsyncState
				:status="listStatus"
				:error="sheetStore.listError"
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
					:loading="sheetStore.listLoading"
					:caption="t('pettycash', 'sheets.title')"
					test-id="sheet-list-table"
					test-id-prefix="sheet-list"
					row-test-id="sheet-row"
				/>
			</AsyncState>
		</section>

		<!--
			The list footer: the server's count on the inline-start edge, the pager on the inline-end.

			## Why the footer exists at all

			A sheet beyond the first page is a sheet whose **status** the caller cannot see, so a returned
			transaction they must correct and a sheet they must submit are both potentially invisible.
			One row carries both halves: `sheet-range` says how much of the list is on screen, the pager
			moves within it.

			## Why the buttons are a window, not the full set

			The approved pager draws one button per page, and `total` is unbounded -- a purchaser with
			hundreds of sheets would render hundreds of buttons, which is the FR-071 failure (everything
			visible without horizontal scroll at 390px) arriving from the other direction. `pageNumbers`
			bounds the row to five buttons, and the chevrons cover the pages the window is not showing.
		-->
		<div class="sheet-list-foot">
			<p v-if="sheetStore.pagination.total > 0" class="sheet-range" data-test="sheet-range">
				{{ t('pettycash', 'sheets.showing', {
					from: formatNumber(rangeStart),
					to: formatNumber(rangeEnd),
					total: formatNumber(sheetStore.pagination.total)
				}) }}
			</p>

			<nav
				v-if="totalPages > 1"
				class="sheet-pager"
				:aria-label="t('pettycash', 'sheets.pager')"
				data-test="sheet-pager"
			>
			<!-- The approved pager is one round button per page flanked by two chevrons, and no "page 3 of 9"
			     readout: `sheet-range` above already says how much of the list is on screen. The chevron
			     glyphs flip for RTL, because the previous page sits on the right in a Persian reading
			     order rather than being a mirrored arrow over an unchanged direction. -->
			<button
				type="button"
				class="sheet-pager__chevron"
				:disabled="!canGoPrevious || sheetStore.listLoading"
				:aria-label="t('pettycash', 'sheets.pager.previous')"
				data-test="sheet-page-previous"
				@click="goToPage(currentPage - 1)"
			>
				<NcIconSvgWrapper :path="direction === 'rtl' ? mdiChevronRight : mdiChevronLeft" :size="20" />
			</button>
			<button
				v-for="page in pageNumbers"
				:key="page"
				type="button"
				class="sheet-pager__page"
				:class="{ 'sheet-pager__page--active': page === currentPage }"
				:aria-current="page === currentPage ? 'page' : undefined"
				:disabled="sheetStore.listLoading"
				:data-test="`sheet-page-${page}`"
				@click="goToPage(page)"
			>
				{{ formatNumber(page) }}
			</button>
			<button
				type="button"
				class="sheet-pager__chevron"
				:disabled="!canGoNext || sheetStore.listLoading"
				:aria-label="t('pettycash', 'sheets.pager.next')"
				data-test="sheet-page-next"
				@click="goToPage(currentPage + 1)"
			>
				<NcIconSvgWrapper :path="direction === 'rtl' ? mdiChevronLeft : mdiChevronRight" :size="20" />
			</button>
			</nav>
		</div>

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
			:busy="sheetStore.mutationLoading"
			@cancel="sheetPendingDeletion = null"
			@confirm="confirmDelete"
		/>

		<!-- T079/T080: one route-driven modal over the list it belongs to. The two routes are
		     mutually exclusive, so only one SheetFormDialog can be mounted. -->
		<SheetFormDialog
			v-if="isCreateOpen"
			:open="isCreateOpen"
			@created="onCreated"
		/>
		<SheetFormDialog
			v-if="isEditOpen"
			:open="isEditOpen"
		/>

		<!-- UI-PUR-005: the sheet detail is a large workspace over this list. T080's route-name
		     check above keeps it unmounted while the edit modal is open. -->
		<SheetDetailView v-if="isWorkspaceOpen" />
	</div>
</template>

<style scoped>
.sheet-list-view {
	display: flex;
	flex-direction: column;
	gap: 1.25rem;

	/*
	 * The approved surface keys this screen's primary actions to its own green. The global primary
	 * is Nextcloud blue, so the green is defined once here as a local variable and re-keyed onto the
	 * tokens the shared components actually read, rather than hardcoded into each component.
	 */
	--pettycash-brand: #008c67;
	--pettycash-brand-hover: #007b5a;
}

.sheet-list-header {
	align-items: flex-start;
}

.sheet-list-header h1 {
	margin: 0;
	font-size: 2rem;
	font-weight: 700;
	line-height: 1.2;
}

.sheet-list-header__text {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	min-inline-size: 0;
}

/*
 * On a wrapped mobile header the button gets a full line of its own; without `nowrap` its
 * label breaks into "ایجاد / برگه" even though the line has room for the phrase.
 *
 * The approved design colours this action green. The variables below are the ones
 * `NcButton`'s primary rules read (`--color-primary-element[-hover|-text]`), so overriding them
 * here re-keys the shared component rather than restyling it.
 */
.create-sheet-button {
	--color-primary-element: var(--pettycash-brand);
	--color-primary-element-hover: var(--pettycash-brand-hover);
	--color-primary-element-text: #fff;

	min-inline-size: 10.5rem;
}

.create-sheet-button :deep(.button-vue__text) {
	display: inline-flex;
	align-items: center;
	gap: 0.35rem;
	white-space: nowrap;
}

.create-sheet-button :deep(.button-vue__text .icon-vue) {
	display: inline-flex;
}

.sheet-list-subtitle,
.sheet-range {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

/*
 * The approved list sits in a card with a slightly more present edge than the flat default, so the
 * table reads as one object on the page instead of lines floating on the background.
 */
.sheet-list-panel {
	overflow: hidden;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	box-shadow: 0 1px 2px rgb(0 0 0 / 4%), 0 2px 6px rgb(0 0 0 / 5%);
}

.header-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 0.5rem;
}

/*
 * The filter surface, drawn as the approved toolbar.
 *
 * One row: the search field takes the free space, the two selects hold a compact width, and the
 * action pair is pushed to the inline-end. `flex-wrap` rather than a fixed column count, because
 * FR-071 requires everything to reflow without horizontal scroll at 390px and a five-column grid is
 * what broke that.
 */
.sheet-filters {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding: 0;
	border: 0;
	background-color: transparent;

	/* The panel's Apply action is this screen's primary action too, so it reads from the same
	   brand green as the header CTA instead of introducing a second primary colour. */
	--color-primary-element: var(--pettycash-brand);
	--color-primary-element-hover: var(--pettycash-brand-hover);
	--color-primary-element-text: #fff;
}

.sheet-filters__toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 0.75rem;
}

/* A flex item is allowed to grow past its basis; every control must still be able to shrink. */
.sheet-filters__toolbar > * {
	min-inline-size: 0;
}

/*
 * The approved row's opt-in facets: currency, the two dates and the creator, with the Apply action
 * they still need. The panel is always mounted so the facet hooks stay in the DOM; only the visual
 * state is toggled by `moreFiltersOpen`.
 */
.sheet-filters__more {
	display: none;
}

.sheet-filters__more--open {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 0.75rem;
	padding: 0.75rem 0 0;
	border-block-start: 1px solid var(--color-border);
}

/*
 * Disabled on purpose: `GET /sheets` accepts neither a search nor an account facet. Muted rather
 * than 50% transparent, so the value never drops below the contrast a real control needs.
 */
.sheet-filters__search {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	min-block-size: 2.6rem;
	padding: 0 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
}

.sheet-filters__search-icon {
	color: var(--color-text-maxcontrast);
}

.sheet-filters__search-input {
	flex: 1 1 auto;
	min-inline-size: 0;
	border: 0;
	background-color: transparent;
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
}

.sheet-filters__search-input:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
	border-radius: var(--border-radius-small);
}

.sheet-filters__search:has(input:disabled) {
	background-color: var(--color-background-hover);
	cursor: not-allowed;
}

.sheet-filters__notice {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

/*
 * At narrow widths the search field keeps its basis and the row wraps; the action pair drops onto a
 * full row of its own rather than squeezing the selects.
 */
@media (width <= 1100px) {
	.sheet-filters__actions {
		inline-size: 100%;
		justify-content: flex-start;
	}
}

@media (width <= 640px) {
	.sheet-list-header {
		display: grid;
		grid-template-columns: minmax(0, 1fr) auto;
		gap: 0.6rem 1rem;
		align-items: start;
	}

	.sheet-list-header__text {
		display: contents;
	}

	.sheet-list-header h1 {
		grid-column: 1;
		grid-row: 1;
	}

	.sheet-list-subtitle {
		grid-column: 1 / -1;
		grid-row: 2;
	}

	.create-sheet-button {
		grid-column: 2;
		grid-row: 1;
		min-inline-size: 0;
	}

	/* The creator facet is the caller themselves; the server refuses any other value. It is
	   already stated by the page context, so the mobile layout spends no row on it. */
	.sheet-filters__field[data-test='sheet-filter-creator'] {
		display: none;
	}

	.sheet-filters__field--search {
		/* The search field takes the free space; at 390px there is almost none, so it takes the row. */
		flex-basis: 100%;
	}

	.sheet-filters__actions {
		inline-size: 100%;
	}

	/* When the header row wraps, the single action lands on the main-start edge by default.
	   Keep the primary action at the inline-end, as it is in the unwrapped desktop header. */
	.header-row > :last-child {
		margin-inline-start: auto;
	}
}

.sheet-filters__field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	min-inline-size: 0;
}

/* The two selects keep the compact width the approved row gives them; the search field takes the
   rest. A flex item's basis is a target, not a floor, so the shrink rule above still applies. */
.sheet-filters__toolbar > .sheet-filters__field:not(.sheet-filters__field--search) {
	flex: 0 1 11rem;
}

.sheet-filters__toolbar .sheet-filters__field--search {
	flex: 1 1 18rem;
}

.sheet-filters__field input {
	inline-size: 100%;
	box-sizing: border-box;
}

.sheet-filters__field :deep(.nc-select) {
	inline-size: 100%;
	min-inline-size: 0;
}

.sheet-filters__label {
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

/* The chosen instant in the reader's own calendar, beside the native picker's ISO value. */
.sheet-filters__hint {
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

/*
 * The resolved creator scope.
 *
 * Italic is not used for "this is fixed" — a visual convention a screen reader cannot announce and that
 * has no contrast guarantee. The value is simply presented as text beside its label, and the reason it is
 * not an input lives in the component's script.
 */
.sheet-filters__scope {
	display: flex;
	align-items: center;
	min-block-size: 2.4rem;
	padding: 0.4rem 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
}

.sheet-filters__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	justify-content: flex-end;
	margin-inline-start: auto;
}

.sheet-filters__field :deep(input),
.sheet-filters__field :deep(.select__select),
.sheet-filters__field :deep(.nc-select) {
	min-block-size: 2.4rem;
}

/*
 * The approved footer: the count on the inline-start edge, the pager on the inline-end, one row
 * while there is room for both.
 */
.sheet-list-foot {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem 1rem;
	justify-content: space-between;
}

/*
 * The approved pager: square-ish numbered buttons between two chevrons.
 *
 * `8px` matches the approved surface's field radius, not a pill: the pager's buttons read as form
 * controls there rather than chips.
 */
.sheet-pager {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.4rem;
	padding: 0;
}

.sheet-pager__chevron,
.sheet-pager__page {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-inline-size: 2.25rem;
	min-block-size: 2.25rem;
	padding: 0;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
	font-weight: 600;
	cursor: pointer;
}

.sheet-pager__chevron:disabled,
.sheet-pager__page:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.sheet-pager__chevron:hover:not(:disabled),
.sheet-pager__page:hover:not(:disabled) {
	border-color: var(--color-border-dark, var(--color-border));
	background-color: var(--color-background-hover);
}

.sheet-pager__page--active {
	border-color: var(--pettycash-brand);
	background-color: var(--pettycash-brand);
	color: #fff;
}

.sheet-pager__page--active:hover:not(:disabled) {
	border-color: var(--pettycash-brand-hover);
	background-color: var(--pettycash-brand-hover);
}

/*
 * The table lives inside `ResponsiveTable`, so these selectors must cross that component's scope.
 * The old `.nc-table` rules never matched: `ResponsiveTable` renders a bare `<table>`, which is why
 * the list had no row rhythm and every header competed with its neighbour.
 */
.sheet-list-panel :deep(.responsive-table__desktop table) {
	inline-size: 100%;
	border-collapse: collapse;
	font-size: var(--font-size-floor);
	table-layout: fixed;
}

.sheet-list-panel :deep(.responsive-table__desktop thead) {
	background-color: var(--color-background-dark);
}

.sheet-list-panel :deep(.responsive-table__desktop th) {
	padding: 0.7rem 0.625rem;
	text-align: start;
	font-weight: 600;
	color: var(--color-main-text);

	/* The label may wrap within its own cell: with `table-layout: fixed` a nowrap header that
	   could not fit its disabled sort affordance spilled over the neighbouring header's box. */
}

.sheet-list-panel :deep(.responsive-table__desktop td) {
	padding: 0.5rem 0.625rem;
	text-align: start;
	vertical-align: middle;
	border-block-start: 1px solid var(--color-border);
}

.sheet-list-panel :deep(.responsive-table__desktop tbody tr:hover) {
	background-color: var(--color-background-hover);
}

/* The approved table gives the status pill and the date cell the room their content needs; the
   money columns hold a fixed rhythm and the sheet column absorbs what is left. The proportions
   follow the approved reference as closely as the 16px typography floor allows: the mock draws
   "Latest Approved Amount" at a smaller size, while here the label itself measures ~200px before
   the disabled sort affordance, so the two money columns take a little more and the sheet/status
   columns give up the difference. The measurable outcome at 1440px is the one that matters: no
   header wraps inside its cell. */
.sheet-list-panel :deep(.responsive-table__desktop th:nth-child(1)) { inline-size: 19.5%; }
.sheet-list-panel :deep(.responsive-table__desktop th:nth-child(2)) { inline-size: 14%; }
.sheet-list-panel :deep(.responsive-table__desktop th:nth-child(3)) { inline-size: 13%; }
.sheet-list-panel :deep(.responsive-table__desktop th:nth-child(4)) { inline-size: 17%; }
.sheet-list-panel :deep(.responsive-table__desktop th:nth-child(5)) { inline-size: 21.5%; }
.sheet-list-panel :deep(.responsive-table__desktop th:nth-child(6)) { inline-size: 15%; }

.sheet-list-panel :deep(.responsive-table__card) {
	display: grid;
	grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
	gap: 0.75rem 1.25rem;
	padding: 1rem;
}

.sheet-list-panel :deep(.responsive-table__field) {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 0.15rem;
}

.sheet-list-panel :deep(.responsive-table__field:nth-child(1)) {
	grid-column: 1;
	grid-row: 1;
}

.sheet-list-panel :deep(.responsive-table__field:nth-child(2)) {
	grid-column: 2;
	grid-row: 1;
	align-items: flex-end;
	text-align: end;
}

.sheet-list-panel :deep(.responsive-table__field:nth-child(3)) {
	grid-column: 1;
	grid-row: 2;
}

.sheet-list-panel :deep(.responsive-table__field:nth-child(4)) {
	grid-column: 2;
	grid-row: 2;
}

.sheet-list-panel :deep(.responsive-table__field:nth-child(5)) {
	grid-column: 1;
	grid-row: 3;
}

.sheet-list-panel :deep(.responsive-table__field:nth-child(6)) {
	grid-column: 2;
	grid-row: 3;
}

.sheet-list-panel :deep(.responsive-table__field:nth-child(1) .responsive-table__label),
.sheet-list-panel :deep(.responsive-table__field:nth-child(2) .responsive-table__label) {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

.sheet-list-panel :deep(.responsive-table__label) {
	color: var(--color-text-maxcontrast);
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
.sheet-list-panel :deep(.sheet-identity) {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: 0.5rem;
	min-inline-size: 0;
}

.sheet-list-panel :deep(.sheet-identity__text) {
	display: flex;
	flex-direction: column;
	flex: 1 1 7rem;
	min-inline-size: 0;
}

/* The reference rows keep one line per title. A sheet named longer than its column must not paint
   across the status pill or cover its own action menu, so the desktop title is clamped to one line
   with an ellipsis; the button keeps the full accessible name and carries the name as a hover title.
   The clamp is used instead of `text-overflow: ellipsis` because the latter does not paint the
   ellipsis for a Latin title inside the RTL layout -- the string was silently cut instead. The
   mobile card is exempt: there the title may use two lines inside its card. */
.sheet-list-panel :deep(.responsive-table__desktop .sheet-identity .button-vue__text) {
	display: -webkit-box;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: 1;
	overflow: hidden;
	white-space: normal;
}

/* The clamp otherwise stops at the last whole word ("Sheet for..."), which discards most of a
   long title. Character-level breaking fills the line before the ellipsis, matching the
   reference's truncation density. It is limited to LTR text: under RTL the same rule clips the
   bidi run at both ends, so RTL keeps the word-boundary clamp and its single trailing ellipsis. */
.sheet-list-panel :deep(.responsive-table__desktop .sheet-identity .button-vue__text:dir(ltr)) {
	word-break: break-all;
}

.sheet-list-panel :deep(.responsive-table__desktop .sheet-identity .button-vue) {
	max-inline-size: 100%;
}

.sheet-list-panel :deep(.responsive-table__desktop .sheet-identity .button-vue__wrapper) {
	min-inline-size: 0;
}

/* The reference stacks a 16px title over a ~13px reference line with tight leading. The title is
   still a real button (`NcButton`), whose text wrapper inherits the core's `normal` leading; pinning
   the line box here removes the half-line of dead space per row without touching the type scale. */
.sheet-list-panel :deep(.sheet-identity .button-vue__wrapper),
.sheet-list-panel :deep(.sheet-identity .button-vue__text) {
	line-height: 1.25;
}

@media (width <= 480px) {
	.sheet-list-panel :deep(.sheet-identity .button-vue__text) {
		white-space: normal;
		text-align: start;
		overflow-wrap: anywhere;
	}
}


/* The title is the row's primary action; strip the button chrome so it reads like the reference's
   row label while keeping the real button semantics and keyboard behaviour. */
.sheet-list-panel :deep(.sheet-identity .button-vue) {
	min-block-size: 0;
	padding: 0;
	color: var(--color-main-text);
	font-weight: 700;
	text-align: start;
}

.sheet-list-panel :deep(.sheet-identity .button-vue__text) {
	font-weight: 700;
}

/*
 * The approved cell puts the sheet's glyph ahead of its reference number. The line is a flex row so
 * the icon holds still while the number itself ellipsises.
 */
.sheet-list-panel :deep(.sheet-identity__id) {
	display: flex;
	align-items: center;
	gap: 0.3rem;
	max-inline-size: 22ch;
	overflow: hidden;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	line-height: 1.25;
}

.sheet-list-panel :deep(.sheet-identity__id-icon) {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	opacity: 0.85;

	/* Core's `.icon-vue` floor is 34px: the wrapper, not the 15px glyph, was setting the height of
	   the whole reference line. Pin the box to the glyph so the line can be as tall as its text. */
	min-inline-size: 15px;
	min-block-size: 15px;
	inline-size: 15px;
	block-size: 15px;
}

.sheet-list-panel :deep(.sheet-identity__id-text) {
	overflow: hidden;
	text-overflow: ellipsis;
}

.sheet-list-panel :deep(.amounts-cell) {
	display: flex;
	flex-direction: column;
	gap: 0.35rem;
}

/*
 * Money in the approved list: the recorded figure is the green outcome colour, the latest approved
 * figure stays neutral -- the pair reads "recorded" against "settled" rather than two competing
 * greens. Both stay bold and tabular, because a money column whose digits drift is unreadable.
 */
.sheet-list-panel :deep(.amount) {
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	font-weight: 700;
	white-space: nowrap;
}

.sheet-list-panel :deep(.amount--strong) {
	color: var(--color-success-text, var(--color-success));
}

/*
 * The approved status chip is a pill with a leading dot, not Nextcloud's rectangular chip. Scoped
 * here rather than in `StatusBadge` so every other surface keeps the shared chip; the dot inherits
 * `currentColor`, so each variant keeps its own hue in both themes. Uppercase matches the approved
 * chip and is a no-op in Persian, which has no letter case to transform.
 */
.sheet-list-panel :deep(.status-badge) {
	border-radius: 999px;
	letter-spacing: 0.02em;
	text-transform: uppercase;
}

.sheet-list-panel :deep(.status-badge)::before {
	content: '';
	flex: 0 0 auto;
	inline-size: 7px;
	block-size: 7px;
	border-radius: 50%;
	background-color: currentcolor;
}

.alert {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.75rem 1rem;
	border-radius: var(--border-radius-small);
}

.alert--error {
	background-color: var(--color-background-hover);
	color: var(--color-error);
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
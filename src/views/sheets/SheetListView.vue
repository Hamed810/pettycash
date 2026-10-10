<script setup lang="ts">
import { computed, h, onMounted, ref, type VNode } from 'vue'
import { useRouter } from 'vue-router'
import { useSheetStore } from '@/stores/sheets'
import NcButton from '@nextcloud/vue/components/NcButton'
import DeleteSheetDialog from '@/components/shared/DeleteSheetDialog.vue'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import StatusBadge from '@/components/shared/StatusBadge.vue'
// `mdiEye` and `mdiPencil` are gone with the template: the action buttons moved into the
// `listCell`/`openSheetCell` render functions, which label the buttons with translated text rather than
// an icon glyph.
import { mdiPlus } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { formatMoney } from '@/utils/money'
import { formatSheetDate as formatDate, formatSheetDateRange as formatDateRange } from '@/utils/date'
import { selectOptionKey, selectOptionValue } from '@/utils/selectOption'
import ResponsiveTable from '@/components/shared/ResponsiveTable.vue'
import { SHEET_STATUSES, type SheetFilters, type SheetSummary } from '@/types/api'

const router = useRouter()
const sheetStore = useSheetStore()

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
	await sheetStore.loadSheets({ page: 1, filters: collect() })
}

async function resetFilters(): Promise<void> {
	draft.value = {}
	await sheetStore.loadSheets({ page: 1, filters: {} })
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
	await sheetStore.loadSheets({
		page,
		pageSize: sheetStore.pagination.pageSize,
		filters: sheetStore.filters
	})
}

/**
 * The sheet list's columns, in display order.
 *
 * **Declared once so `ResponsiveTable` can label both layouts from the same list.** FR-071 requires the
 * table to reflow at a narrow viewport rather than scroll, and the reflowed layout is a card per row whose
 * fields are labelled. A label written into each `<th>` *and* into each card field is two statements of
 * one rule, and they drift — which is how the narrow layout ends up saying "Total" where the wide one says
 * "Totals".
 */
const LIST_COLUMNS = computed(() => [
	{ key: 'title', label: t('pettycash', 'sheets.title') },
	{ key: 'owner', label: t('pettycash', 'sheets.owner') },
	{ key: 'currency', label: t('pettycash', 'sheets.currency') },
	{ key: 'status', label: t('pettycash', 'sheets.status') },
	{ key: 'transactionCount', label: t('pettycash', 'sheets.transactionCount') },
	{ key: 'activeRevision', label: t('pettycash', 'sheets.activeRevision') },
	{ key: 'totals', label: t('pettycash', 'sheets.totals') },
	// FR-062's latest-effective column, distinct from the per-stage one below: the headline follows
	// each transaction's latest effective outcome, while the stage columns preserve the differing
	// manager, senior-manager and finance decisions beside it.
	{ key: 'latestEffective', label: t('pettycash', 'sheets.latestEffectiveOutcome') },
	// FR-062 (T365): the three stage-approved figures as their own column, beside the
	// latest-effective one rather than merged into it — a senior approval that finance later
	// rejects must stay visible as a senior approval, which a single headline cannot show.
	{ key: 'stageApproved', label: t('pettycash', 'sheets.stageApproved') },
	{ key: 'keyDates', label: t('pettycash', 'sheets.keyDates') },
	{ key: 'actions', label: t('pettycash', 'sheets.actions') },
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
	{ key: 'actions', label: t('pettycash', 'sheets.actions') },
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
			return h(
				NcButton,
				{ variant: 'tertiary', onClick: () => goToDetail(item.id), 'data-test': 'open-sheet-item' },
				() => item.title
			)
		case 'currency':
			return h('span', null, item.currency)
		case 'transactionCount':
			return h('span', null, item.transactionCount)
		case 'totals':
			return h('span', { 'data-test': 'open-sheet-total' }, formatMoney(item.totals.current))
		case 'createdAt':
			return h('span', { 'data-test': 'open-sheet-created-at' }, formatDate(item.createdAt))
		case 'actions':
			return h('div', { class: 'actions-menu' }, [
				h(
					NcButton,
					{
						variant: 'tertiary',
						'aria-label': t('pettycash', 'sheets.viewNamed', { name: item.title }),
						onClick: () => goToDetail(item.id),
						'data-test': 'view-sheet',
					},
					() => t('pettycash', 'common.view')
				),
				h(
					NcButton,
					{
						variant: 'tertiary',
						'aria-label': t('pettycash', 'sheets.editNamed', { name: item.title }),
						onClick: () => goToEdit(item.id),
						'data-test': 'edit-sheet',
					},
					() => t('pettycash', 'common.edit')
				),
				// FR-086's delete control, on the **open-sheets** region.
				//
				// A second renderer, not a duplicate of the one in `listCell`: the view splits open sheets into
				// their own region with their own cell function, so a control added only to the main table would be
				// **absent from the one surface where every sheet is deletable** — found by running the interface
				// test, not by reading the template.
				//
				// The `status === 'OPEN'` condition is redundant here, because this region only ever holds open
				// sheets, and is kept deliberately: the control's visibility must follow the rule the server
				// enforces, not the accident of which region a sheet happens to appear in.
				item.status === 'OPEN'
					? h(
							NcButton,
							{
								variant: 'error',
								'aria-label': t('pettycash', 'sheets.deleteNamed', { name: item.title }),
								onClick: () => askDelete(item),
								'data-test': 'delete-sheet',
							},
							() => t('pettycash', 'common.delete')
						)
					: null,
			])
		default:
			return h('span', { 'data-test': `open-sheet-unrendered-${column.key}` }, column.key)
	}
}

function sheetRowKey(item: SheetSummary, index: number): string {
	return item.id || `sheet-row-${index}`
}

function listCell(item: SheetSummary, column: { key: string }): VNode {
	switch (column.key) {
		case 'title':
			return h(
				NcButton,
				{ variant: 'tertiary', onClick: () => goToDetail(item.id), 'data-test': 'sheet-list-item' },
				() => item.title
			)
		case 'status':
			return h(StatusBadge, { status: item.status })
		case 'totals':
			// `submitted_total_minor` is a snapshot at first submission and does not move on correction
			// (`data-model.md`), so the two figures answer different questions and both are shown.
			return h('div', { class: 'totals-cell' }, [
				h('span', { 'data-test': 'sheet-list-current-total' }, formatMoney(item.totals.current)),
				h('small', { class: 'text-caption' }, t('pettycash', 'sheets.currentTotal')),
				h(
					'small',
					{ class: 'text-caption', 'data-test': 'sheet-list-submitted-total' },
					`${t('pettycash', 'sheets.submittedTotal')}: ${formatMoney(item.totals.submitted)}`
				),
			])
		case 'latestEffective':
			return h('div', { class: 'totals-cell', 'data-test': 'sheet-list-effective-outcome' }, [
				h('span', { 'data-test': 'sheet-list-effective-approved' }, formatMoney(item.totals.effectiveApproved)),
				h('small', { class: 'text-caption' }, t('pettycash', 'sheets.effectiveApproved')),
				h('span', { 'data-test': 'sheet-list-effective-rejected' }, formatMoney(item.totals.effectiveRejected)),
				h('small', { class: 'text-caption' }, t('pettycash', 'sheets.effectiveRejected')),
				h('span', { 'data-test': 'sheet-list-effective-pending' }, formatMoney(item.totals.effectivePending)),
				h('small', { class: 'text-caption' }, t('pettycash', 'sheets.effectivePending')),
			])
		case 'stageApproved':
			// FR-062 (T365): the per-stage columns the detail view renders as chips, here as one
			// list cell so the table and the narrow card show the same three figures — the narrow
			// layout renders every column `listCell` produces, so a table-only addition would be a
			// second, silently divergent presentation.
			return h('div', { class: 'totals-cell', 'data-test': 'sheet-list-stage-approved' }, [
				h('span', { 'data-test': 'sheet-list-cost-center-approved' }, formatMoney(item.totals.costCenterApproved)),
				h('small', { class: 'text-caption' }, t('pettycash', 'sheets.costCenterApproved')),
				h('span', { 'data-test': 'sheet-list-senior-approved' }, formatMoney(item.totals.seniorApproved)),
				h('small', { class: 'text-caption' }, t('pettycash', 'sheets.seniorApproved')),
				h('span', { 'data-test': 'sheet-list-finance-approved' }, formatMoney(item.totals.financeApproved)),
				h('small', { class: 'text-caption' }, t('pettycash', 'sheets.financeApproved')),
			])
		case 'keyDates':
			return h('div', { class: 'dates-cell', 'data-test': 'sheet-list-key-dates' }, [
				h('span', { class: 'dates-cell__row' }, [
					h('small', { class: 'text-caption' }, t('pettycash', 'sheets.createdAt')),
					h('span', { 'data-test': 'sheet-list-created-at' }, formatDate(item.createdAt)),
				]),
				h('span', { class: 'dates-cell__row' }, [
					h('small', { class: 'text-caption' }, t('pettycash', 'sheets.submittedAt')),
					h('span', { 'data-test': 'sheet-list-submitted-at' }, formatDate(item.submittedAt)),
				]),
				h('span', { class: 'dates-cell__row' }, [
					h('small', { class: 'text-caption' }, t('pettycash', 'sheets.completedAt')),
					h('span', { 'data-test': 'sheet-list-completed-at' }, formatDate(item.completedAt)),
				]),
				h('span', { class: 'dates-cell__row' }, [
					h('small', { class: 'text-caption' }, t('pettycash', 'sheets.expenseRange')),
					h('span', { 'data-test': 'sheet-list-expense-range' }, formatDateRange(item)),
				]),
			])
		case 'actions':
			return h('div', { class: 'actions-menu' }, [
				h(
					NcButton,
					{
						variant: 'tertiary',
						'aria-label': t('pettycash', 'sheets.viewNamed', { name: item.title }),
						onClick: () => goToDetail(item.id),
						'data-test': 'view-sheet',
					},
					() => t('pettycash', 'common.view')
				),
				item.status === 'OPEN'
					? h(
						NcButton,
						{
							variant: 'tertiary',
							'aria-label': t('pettycash', 'sheets.editNamed', { name: item.title }),
							onClick: () => goToEdit(item.id),
							'data-test': 'edit-sheet',
						},
						() => t('pettycash', 'common.edit')
					)
					: null,
				// FR-086's delete control, shown **only** while the sheet is still `OPEN`.
				//
				// The condition is on the row's own status rather than on a separate capability, because the
				// server refuses a submitted, completed, or completed-with-rejections sheet (FR-026, FR-044).
				// A control that is always refused teaches the purchaser the application does not know its own
				// state — and the refusal stays enforced server-side regardless, since FR-009 forbids relying
				// on the interface to enforce a rule. Hiding it is what keeps the interface honest about it.
				item.status === 'OPEN'
					? h(
							NcButton,
							{
								variant: 'error',
								'aria-label': t('pettycash', 'sheets.deleteNamed', { name: item.title }),
								onClick: () => askDelete(item),
								'data-test': 'delete-sheet',
							},
							() => t('pettycash', 'common.delete')
						)
					: null,
			])
		// `0` for a sheet holding no transactions is a real answer, not a missing value, so it is printed
		// rather than replaced with the absent-marker the dates use.
		case 'activeRevision':
			return h('span', { 'data-test': 'sheet-list-active-revision' }, item.activeRevisionNumber ?? 0)
		case 'owner':
			return h('span', { 'data-test': 'sheet-list-owner' }, item.ownerDisplayName)
		case 'currency':
			return h('span', null, item.currency)
		case 'transactionCount':
			return h('span', null, item.transactionCount)
		default:
			// A column with no case is a new column someone forgot to render, not a blank cell. Rendering
			// its key means it is visible and testable rather than silently empty in both layouts.
			return h('span', { 'data-test': `sheet-list-unrendered-${column.key}` }, column.key)
	}
}

onMounted(() => {
	// The store records the failure in `error`, which this view renders in its alert. A mount-time
	// load has no caller to react to the rejection, so it is absorbed here rather than surfacing as
	// an unhandled promise rejection in the user's console.
	sheetStore.loadSheets().catch(() => undefined)
})

/**
 * The sheet the confirmation dialog is open for, and nothing else.
 *
 * Held as the whole row rather than as an id so the dialog can **name the sheet** in its body — FR-086 requires
 * the confirmation to say which sheet is about to go, and a purchaser with three open sheets cannot otherwise tell.
 * `null` means no dialog is open, so Cancel needs no separate flag.
 */
const sheetPendingDeletion = ref<SheetSummary | null>(null)

function goToCreate() { router.push({ name: 'sheet-create' }) }
function goToDetail(sheetId: string) { router.push({ name: 'sheet-detail', params: { id: sheetId } }) }
function goToEdit(sheetId: string) { router.push({ name: 'sheet-edit', params: { id: sheetId } }) }

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
	await sheetStore.deleteOwnSheet(sheet.id, sheet.version)
}

</script>

<template>
	<div class="sheet-list-view" dir="rtl" data-test="sheet-list-view">
		<div class="header-row">
			<h1 class="mb-0">{{ t('pettycash', 'sheets.title') }}</h1>
			<NcButton
				variant="primary"
				:aria-label="t('pettycash', 'sheets.create')"
				@click="goToCreate"
				data-test="create-sheet-button"
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
			dir="rtl"
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
			-->
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
				{{ t('pettycash', 'sheets.pager.position', { page: currentPage, total: totalPages }) }}
			</span>
			<NcButton
				:disabled="!canGoNext || sheetStore.loading"
				data-test="sheet-page-next"
				@click="goToPage(currentPage + 1)"
			>
				{{ t('pettycash', 'sheets.pager.next') }}
			</NcButton>
		</nav>

		<div v-if="sheetStore.error" class="alert alert--error" role="alert">
			<span>{{ sheetStore.error }}</span>
			<button type="button" @click="sheetStore.clearError" aria-label="Dismiss">×</button>
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
			@confirm="confirmDelete" />
	</div>
</template>

<style scoped>
.sheet-list-view {
	display: flex;
	flex-direction: column;
	gap: 1rem;
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

.totals-cell {
	display: flex;
	flex-direction: column;
	gap: 0.125rem;
}

/*
 * One "key dates" cell holding four labelled dates.
 *
 * `display: flex` on the cell rather than a grid, and the row allowed to wrap, because the four dates
 * are one concept and a wide-enough row depends on which of them are present — a sheet that has never
 * been submitted has no `submittedAt` to print. A fixed grid with four tracks would reserve the space
 * for values that are deliberately absent.
 */
.dates-cell {
	display: flex;
	flex-direction: column;
	gap: 0.125rem;
}

.dates-cell__row {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 0.25rem;
}

.actions-menu {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
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
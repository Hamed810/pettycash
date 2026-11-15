<template>
	<div class="queue-view" :dir="direction" data-testid="senior-queue">
		<nav class="breadcrumbs" :aria-label="t('pettycash', 'common.breadcrumbs')">
			<ol class="breadcrumbs__list">
				<li class="breadcrumbs__item">
					<RouterLink :to="{ name: 'dashboard' }">{{ t('pettycash', 'navigation.dashboard') }}</RouterLink>
				</li>
				<li class="breadcrumbs__item" aria-current="page">{{ t('pettycash', 'reviews.seniorQueue') }}</li>
			</ol>
		</nav>

		<header class="queue-header">
			<div class="queue-header__intro">
				<p class="queue-header__eyebrow">{{ t('pettycash', 'reviews.seniorSheet.eyebrow') }}</p>
				<h2 class="queue-header__title">{{ t('pettycash', 'reviews.seniorQueue') }}</h2>
				<p class="queue-header__subtitle">{{ t('pettycash', 'reviews.seniorSheet.description') }}</p>
			</div>
			<div class="queue-header__aside">
				<NcButton :disabled="loading" data-testid="refresh-btn" @click="refresh">
					<template #icon>
						<NcIconSvgWrapper :path="mdiRefresh" />
					</template>
					{{ t('pettycash', 'common.refresh') }}
				</NcButton>
			</div>
		</header>

		<div v-if="loading" class="queue-state" data-testid="loading">
			<NcLoadingIcon :size="44" />
			<p>{{ t('pettycash', 'common.loading') }}</p>
		</div>

		<div v-else-if="error" class="queue-state" data-testid="error">
			<NcIconSvgWrapper :path="mdiAlertCircle" :size="44" class="queue-state__icon--error" />
			<p>{{ error }}</p>
			<NcButton data-testid="retry-btn" @click="refresh">
				{{ t('pettycash', 'common.retry') }}
			</NcButton>
		</div>

		<!--
			Owners D8 / FR-007-016: a senior-pending set the shared completeness guard cannot
			prove whole renders an honest unavailable state with a retry. It must NEVER fall back
			to a partial flat transaction table — there is no flat fallback in this view at all.
		-->
		<div v-else-if="!completeness.complete" class="queue-state queue-state--unavailable" data-testid="grouping-unavailable">
			<NcIconSvgWrapper :path="mdiAlertCircle" :size="44" class="queue-state__icon--warning" />
			<h3>{{ t('pettycash', 'reviews.seniorSheet.unavailable.title') }}</h3>
			<p class="queue-state__message">{{ t('pettycash', 'reviews.seniorSheet.unavailable.body') }}</p>
			<NcButton data-testid="retry-btn" @click="refresh">
				{{ t('pettycash', 'reviews.seniorSheet.unavailable.action') }}
			</NcButton>
		</div>

		<template v-else>
			<section class="responsibility" :aria-label="t('pettycash', 'reviews.seniorSheet.banner.title')" data-testid="responsibility-banner">
				<span class="responsibility__icon" aria-hidden="true">
					<NcIconSvgWrapper :path="mdiCheckCircle" :size="20" />
				</span>
				<div class="responsibility__body">
					<p class="responsibility__title">{{ t('pettycash', 'reviews.seniorSheet.banner.title') }}</p>
					<p class="responsibility__text">{{ t('pettycash', 'reviews.seniorSheet.banner.body') }}</p>
				</div>
			</section>

			<div v-if="sheetItems.length === 0" data-testid="empty-queue">
				<NcEmptyContent
					:name="t('pettycash', 'reviews.seniorSheet.empty.title')"
					:description="t('pettycash', 'reviews.seniorSheet.empty.body')"
				/>
			</div>

			<template v-else>
				<section class="summary" :aria-label="t('pettycash', 'reviews.seniorSheet.stats.label')" data-testid="senior-stats">
					<article class="stat">
						<div class="stat__top">
							<span class="stat__label">
								<span class="stat__icon stat__icon--primary" aria-hidden="true">
									<NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="16" />
								</span>
								{{ t('pettycash', 'reviews.seniorSheet.stats.sheetsAwaiting.label') }}
							</span>
						</div>
						<span class="stat__value">{{ formatNumber(awaitingSheetCount) }}</span>
						<span class="stat__hint">{{ t('pettycash', 'reviews.seniorSheet.stats.sheetsAwaiting.hint', { count: formatNumber(awaitingTransactionCount) }) }}</span>
					</article>
					<article class="stat">
						<div class="stat__top">
							<span class="stat__label">
								<span class="stat__icon stat__icon--review" aria-hidden="true">
									<NcIconSvgWrapper :path="mdiCheckCircle" :size="16" />
								</span>
								{{ t('pettycash', 'reviews.seniorSheet.stats.transactionsPending.label') }}
							</span>
						</div>
						<span class="stat__value">{{ formatNumber(awaitingTransactionCount) }}</span>
						<span class="stat__hint">{{ t('pettycash', 'reviews.seniorSheet.stats.transactionsPending.hint', { count: formatNumber(awaitingSheetCount) }) }}</span>
					</article>
				</section>

				<div class="sheet-panel" data-testid="senior-sheet-queue">
					<div class="senior-queue-toolbar" data-testid="senior-queue-toolbar">
						<div class="senior-queue-search" data-testid="senior-queue-search-field">
							<input
								type="search"
								class="senior-queue-search__input"
								:value="sheetSearchQuery"
								:placeholder="t('pettycash', 'reviews.seniorSheet.searchPlaceholder')"
								:aria-label="t('pettycash', 'reviews.seniorSheet.searchLabel')"
								data-testid="senior-queue-search"
								@input="onQueueSearchInput"
							>
							<NcButton
								v-if="sheetSearchQuery !== ''"
								variant="tertiary"
								size="small"
								data-testid="senior-queue-search-clear"
								@click="clearQueueSearch"
							>
								{{ t('pettycash', 'reviews.seniorSheet.clearSearch') }}
							</NcButton>
						</div>

						<div
							class="senior-queue-tabs"
							role="group"
							:aria-label="t('pettycash', 'reviews.seniorSheet.tabsLabel')"
							data-testid="senior-queue-tabs"
						>
							<button
								v-for="tab in QUEUE_TABS"
								:key="tab.value"
								type="button"
								class="senior-queue-tabs__tab"
								:class="{ 'senior-queue-tabs__tab--active': sheetStatusTab === tab.value }"
								:data-testid="`senior-queue-tab-${tab.value}`"
								@click="setQueueTab(tab.value)"
							>
								{{ t('pettycash', tab.labelKey) }}
							</button>
						</div>
					</div>

					<div v-if="visibleSheets.length === 0" class="senior-queue-no-results" data-testid="senior-queue-no-results">
						{{ t('pettycash', 'reviews.sheetQueue.noResults') }}
					</div>

					<div v-else class="sheet-card">
						<!--
							FR-071 / T389: the sheet rows live in `ResponsiveTable`, the single copy of the
							desktop-table + narrow-card switch. The columns and cells are the local adapter
							below (mirroring `queueColumns.ts` for the transaction queues) so the two layouts
							render the same figures from one `cell()`.
						-->
						<ResponsiveTable
							:columns="sheetColumns()"
							:rows="visibleSheets"
							:cell="sheetCell"
							:row-key="sheetRowKey"
							:caption="queueCaption"
							:sort="sheetSort"
							:on-sort="setSheetSort"
							test-id="senior-sheet-table"
							test-id-prefix="senior-queue"
							:row-test-id="sheetRowTestId"
							:card-row-test-id="sheetCardRowTestId"
						/>
					</div>
				</div>
			</template>
		</template>

		<SeniorSheetModal
			:open="openSheetItem !== null"
			:item="openSheetItem"
			@close="closeSheetModal"
		/>
	</div>
</template>

<script setup lang="ts">
import { computed, h, nextTick, onMounted, ref, type VNode } from 'vue'
import { RouterLink } from 'vue-router'
import { t } from '@nextcloud/l10n'
import { mdiAlertCircle, mdiCheckCircle, mdiFileDocumentOutline, mdiRefresh } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ResponsiveTable from '@/components/shared/ResponsiveTable.vue'
import { direction } from '@/utils/direction'
import { formatMoney } from '@/utils/money'
import { formatNumber } from '@/utils/number'
import { formatSheetDateTime } from '@/utils/date'
import { ABSENT_MARKER } from '@/utils/date'
import SeniorSheetModal from '@/components/review/SeniorSheetModal.vue'
import {
	adaptSeniorSheetListRows,
	checkSeniorSheetQueueCompleteness,
	filterSeniorSheetsByStatus,
	searchSeniorSheets,
	sortSeniorSheets,
} from '@/composables/useSeniorQueue'
import type { SeniorQueueSortKey, SeniorSheetSortAccess } from '@/composables/useSeniorQueue'
import type { SeniorManagerSheetReview, SeniorSheetQueueItem } from '@/types/api'
import { useReviewsStore } from '@/stores/reviews'

const reviewsStore = useReviewsStore()

const seniorSheetQueue = computed(() => reviewsStore.seniorQueue)
const loading = computed(() => reviewsStore.loading)
const error = computed(() => reviewsStore.error)

/**
 * The Senior queue's completeness guard. `fetchAllSeniorSheets` already followed the server's
 * `total` across pages, so a set that still falls short is a real truncation and renders the honest
 * unavailable state — never a partial table (D8).
 */
const completeness = computed(() => checkSeniorSheetQueueCompleteness(seniorSheetQueue.value))

const sheetItems = computed(() => adaptSeniorSheetListRows(seniorSheetQueue.value.items))

const seniorSheetReviews = computed(() => reviewsStore.seniorSheetReviews)

function seniorSheetReview(sheet: SeniorSheetQueueItem): SeniorManagerSheetReview | null {
	if (sheet.sheetId === '') return null
	const review = seniorSheetReviews.value[sheet.sheetId]
	if (!review || !review.sheet) return null
	return review
}

/**
 * Identity is supplied by the sheet-list row itself. The per-sheet read is only a fallback for a
 * row whose list payload lacked a field (for example a legacy transaction-derived row); it is not
 * required to render the queue and is no longer prefetched for every sheet.
 */
function sheetTitle(sheet: SeniorSheetQueueItem): string {
	const title = sheet.title?.trim() || seniorSheetReview(sheet)?.sheet.title?.trim() || ''
	return title !== '' ? title : t('pettycash', 'reviews.seniorSheet.modal.titleUnavailable')
}

function purchaserName(sheet: SeniorSheetQueueItem): string {
	return sheet.purchaser?.trim() || seniorSheetReview(sheet)?.sheet.ownerDisplayName?.trim() || ''
}

function purchaserInitials(sheet: SeniorSheetQueueItem): string {
	const name = purchaserName(sheet)
	if (name === '') return ''
	return name.split(/\s+/).slice(0, 2).map((p) => p.charAt(0)).join('').toLocaleUpperCase()
}

/**
 * Stats count only sheets that still have a transaction awaiting this senior's decision.
 *
 * Completed sheets remain in the list (and in the table) but must not inflate the "awaiting"
 * figures; their server pending count is 0. A `PENDING_REVIEW` sheet with zero awaiting
 * transactions (for example one this senior returned and that now awaits purchaser correction) is
 * still listed, but it is not "awaiting your decision" and must not inflate either stat.
 */
const pendingSheets = computed(() =>
	sheetItems.value.filter((sheet) => !isCompleted(sheet) && sheet.transactionCount > 0)
)

/** Sheet count for the stats section. */
const awaitingSheetCount = computed(() => pendingSheets.value.length)

/** Transaction count for the stats section. */
const awaitingTransactionCount = computed(() =>
	pendingSheets.value.reduce((sum, sheet) => sum + sheet.transactionCount, 0)
)

/**
 * T019 — sheet-level search + status tabs (owner D2/D5).
 *
 * Both are purely local over the proven-complete set: no network request per change, and no
 * transaction-level control at the queue (FR-007-041). Search matches the sheet reference and cost
 * centres, plus any real sheet fields (title/purchaser) the additive Senior read has supplied;
 * status always comes from the server-supplied `reviewStatus` — never derived client-side.
 */
const sheetSearchQuery = ref('')
const sheetStatusTab = ref<'all' | 'pending' | 'completed'>('all')

/**
 * Feature 007 desktop UX correction — queue column sorting (mission item 7).
 *
 * The active key + direction are local UI state over the proven-complete set; the comparator is the
 * pure `sortSeniorSheets` in `useSeniorQueue.ts` (currency-first, stable deterministic tiebreak).
 * Search and status tabs are applied FIRST, then the sort reorders only the surviving rows — the
 * completeness guards and the "honest unavailable" state are untouched.
 */
const sheetSortKey = ref<SeniorQueueSortKey | null>(null)
const sheetSortDirection = ref<'asc' | 'desc'>('asc')

const sheetSort = computed(() =>
	sheetSortKey.value === null ? null : { key: sheetSortKey.value, direction: sheetSortDirection.value }
)

/**
 * Repeated activation toggles the direction of the already-active column; activating a new column
 * starts ascending.
 */
function setSheetSort(key: string): void {
	if (sheetSortKey.value === key) {
		sheetSortDirection.value = sheetSortDirection.value === 'asc' ? 'desc' : 'asc'
		return
	}
	sheetSortKey.value = key as SeniorQueueSortKey
	sheetSortDirection.value = 'asc'
}

/** The pure comparator's per-sheet access over the list row's server-supplied fields. */
function sheetSortAccess(sheet: SeniorSheetQueueItem): SeniorSheetSortAccess {
	if (sheet.sheetId === '') return { title: null, purchaser: null, status: null }
	const summary = sheet.sheet ?? reviewsStore.seniorSheetReviews[sheet.sheetId]?.sheet
	return {
		title: summary?.title ?? sheet.title ?? null,
		purchaser: summary?.ownerDisplayName ?? sheet.purchaser ?? null,
		status: summary?.reviewStatus ?? sheet.reviewStatus ?? null,
	}
}

const QUEUE_TABS: ReadonlyArray<{ value: 'all' | 'pending' | 'completed'; labelKey: string }> = [
	{ value: 'all', labelKey: 'reviews.seniorSheet.tabAll' },
	{ value: 'pending', labelKey: 'reviews.seniorSheet.tabAwaiting' },
	{ value: 'completed', labelKey: 'reviews.seniorSheet.tabCompleted' },
]

const visibleSheets = computed(() => {
	const searched = searchSeniorSheets(sheetItems.value, sheetSearchQuery.value)
	const filtered = filterSeniorSheetsByStatus(
		searched,
		sheetStatusTab.value,
		(sheet) => sheet.reviewStatus ?? null
	)
	const key = sheetSortKey.value
	if (key === null) return filtered
	return sortSeniorSheets(filtered, { key, direction: sheetSortDirection.value }, sheetSortAccess)
})

function onQueueSearchInput(event: Event): void {
	sheetSearchQuery.value = (event.target as HTMLInputElement).value
}

function clearQueueSearch(): void {
	sheetSearchQuery.value = ''
}

function setQueueTab(tab: 'all' | 'pending' | 'completed'): void {
	sheetStatusTab.value = tab
}

/** The sheet opened in the single overlay; the queue stays mounted and inert behind it (FR-007-002). */
const openSheetItem = ref<SeniorSheetQueueItem | null>(null)

/**
 * D5 / FR-007-017: "Review Completed" is a presentation status supplied ONLY by the server
 * (`SeniorSheetSummary.reviewStatus`, carried directly by each sheet-list row). It is never derived
 * client-side; a row without a proven status is treated as pending.
 */
function isCompleted(sheet: SeniorSheetQueueItem): boolean {
	const supplied = sheet.reviewStatus ?? sheet.sheet?.reviewStatus
	return supplied === 'REVIEW_COMPLETED'
}

function lastActivity(sheet: SeniorSheetQueueItem): string {
	return sheet.latestActivityAt ? formatSheetDateTime(sheet.latestActivityAt) : ABSENT_MARKER
}

function shortenedReference(sheet: SeniorSheetQueueItem): string {
	if (sheet.sheetId === '') return t('pettycash', 'reviews.seniorSheet.unresolvedReference')
	return sheet.sheetId.length > 12 ? `${sheet.sheetId.slice(0, 8)}…` : sheet.sheetId
}

/**
 * The sheet rows' `ResponsiveTable` adapter (FR-071 / T389).
 *
 * Mirrors `queueColumns.ts` for the transaction queues: one translated column list and one `cell()`
 * render function, so `ResponsiveTable` renders the desktop table and the narrow cards from the *same*
 * cells and the two layouts cannot disagree about a figure.
 */
interface SheetColumn {
	key: string
	label: string
	/** Opt-in to the local header sort (`ResponsiveTable` renders a real button + `aria-sort`). */
	sortable?: boolean
}

/** A function rather than a constant so the headers follow a language change (as `sharedQueueColumns`). */
function sheetColumns(): SheetColumn[] {
	return [
		{ key: 'reference', label: t('pettycash', 'reviews.seniorSheet.reference'), sortable: true },
		{ key: 'owner', label: t('pettycash', 'reviews.seniorSheet.owner'), sortable: true },
		{ key: 'pendingCount', label: t('pettycash', 'reviews.seniorSheet.pendingCount'), sortable: true },
		{ key: 'amount', label: t('pettycash', 'reviews.amount'), sortable: true },
		{ key: 'latestActivity', label: t('pettycash', 'reviews.seniorSheet.latestActivity'), sortable: true },
		{ key: 'status', label: t('pettycash', 'reviews.seniorSheet.status'), sortable: true },
	]
}

const queueCaption = t('pettycash', 'reviews.seniorQueue')

function sheetCell(sheet: SeniorSheetQueueItem, column: SheetColumn): VNode | string | number {
	switch (column.key) {
	case 'reference':
		return h(
			'span',
			{ class: 'sheet-row__identity' },
			[
				h('span', { class: 'sheet-row__icon', 'aria-hidden': 'true' }, [
					h(NcIconSvgWrapper, { path: mdiFileDocumentOutline, size: 20 }),
				]),
				h('span', { class: 'sheet-row__text' }, [
					// The human-readable title is THIS row's primary interaction (mission UX correction):
					// a real, keyboard-accessible button that opens the sheet modal over the still-mounted
					// queue, mirroring the Sheets page's clickable title (`SheetListView.listCell`, a
					// tertiary NcButton). It stays ENABLED for completed sheets so they open in the modal's
					// read-only presentation — never a misleading disabled appearance here.
					h(
						NcButton,
						{
							class: 'sheet-row__title-button',
							variant: 'tertiary',
							'data-testid': 'sheet-title',
							title: sheetTitle(sheet),
							'aria-label': sheetTitle(sheet),
							onClick: () => openSheet(sheet),
						},
						() => sheetTitle(sheet)
					),
					h('span', {
						class: 'sheet-row__reference',
						title: sheet.sheetId || undefined,
						'data-testid': 'sheet-reference',
					}, shortenedReference(sheet)),
				]),
			]
		)
	case 'owner': {
		const name = purchaserName(sheet)
		const initials = purchaserInitials(sheet)
		return h(
			'span',
			{ class: 'sheet-row__owner', 'data-testid': 'sheet-owner' },
			name !== ''
				? [
					initials
						? h('span', { class: 'sheet-row__owner-avatar', 'aria-hidden': 'true' }, initials)
						: null,
					h('span', { class: 'sheet-row__owner-name' }, name),
				].filter(Boolean)
				: t('pettycash', 'reviews.seniorSheet.modal.ownerUnavailable')
		)
	}
	case 'pendingCount':
		return h('span', { class: 'sheet-row__count', 'data-testid': 'sheet-pending-count' }, [
			formatNumber(sheet.transactionCount),
		])
	case 'amount':
		return h('span', { class: 'sheet-row__amount', 'data-testid': 'sheet-amount' }, [
			...sheet.totals.map((total) =>
				h(
					'span',
					{ key: `${total.currency}-${total.minorUnit}`, class: 'sheet-row__money' },
					formatMoney(total)
				)
			),
		])
	case 'latestActivity':
		return h('span', { class: 'sheet-row__activity', 'data-testid': 'sheet-activity' }, [
			lastActivity(sheet),
		])
	case 'status':
		return h('span', { class: 'sheet-row__status', 'data-testid': 'sheet-status' }, [
			isCompleted(sheet)
				? h(
					'span',
					{ class: 'status-badge status-badge--completed', 'data-testid': 'review-completed-badge' },
					t('pettycash', 'reviews.seniorSheet.reviewCompleted')
				)
				: h(
					'span',
					{ class: 'status-badge status-badge--pending', 'data-testid': 'review-pending-badge' },
					t('pettycash', 'reviews.seniorSheet.roleBadge')
				),
		])
	default:
		// A column with no case is a new column nobody rendered, not an empty cell.
		return h('span', { 'data-testid': `sheet-unrendered-${column.key}` }, column.key)
	}
}

/** The `<tr>` hook; the card keeps its own prefix so a row count stays a count (ResponsiveTable). */
function sheetRowTestId(sheet: SeniorSheetQueueItem): string {
	return `senior-sheet-row-${sheet.sheetId || 'unresolved'}`
}

function sheetCardRowTestId(sheet: SeniorSheetQueueItem): string {
	return `senior-sheet-card-${sheet.sheetId || 'unresolved'}`
}

function sheetRowKey(sheet: SeniorSheetQueueItem, index: number): string {
	return sheet.sheetId || `senior-sheet-row-${index}`
}

/** No route navigation: the single overlay opens over the still-mounted queue (FR-007-002). */
function openSheet(sheet: SeniorSheetQueueItem): void {
	const item = sheet
	openSheetItem.value = item
	originSheetId = sheet.sheetId || 'unresolved'
}

/** Close restores focus to the originating sheet row (FR-007-009). */
async function closeSheetModal(): Promise<void> {
	const origin = originSheetId
	openSheetItem.value = null
	originSheetId = null
	if (origin === null) return
	await nextTick()
	// The row is a `<tr>` on desktop and a card `<li>` at ≤640px (ResponsiveTable). Both carry their
	// own hook, and only one is rendered per breakpoint; focus the *visible* title trigger so restore
	// works on the 390px card layout, not the hidden desktop `<tr>` (whose focus lands nowhere).
	const candidates = document.querySelectorAll<HTMLElement>(
		`[data-testid="senior-sheet-row-${origin}"] [data-testid="sheet-title"], [data-testid="senior-sheet-card-${origin}"] [data-testid="sheet-title"]`
	)
	const trigger = Array.from(candidates).find((el) => {
		const rect = el.getBoundingClientRect()
		return rect.width > 0 && rect.height > 0
	})
	if (trigger) {
		trigger.focus()
		return
	}
	// Fallback (unit tests / non-layout DOM): there is no measurable "visible" trigger, so return to
	// the desktop row's own title button rather than the non-focusable `<tr>`.
	const row = document.querySelector<HTMLElement>(`[data-testid="senior-sheet-row-${origin}"]`)
	const rowTrigger = row?.querySelector<HTMLElement>('[data-testid="sheet-title"]')
	if (rowTrigger) {
		rowTrigger.focus()
		return
	}
	row?.focus()
}

let originSheetId: string | null = null

async function refresh(): Promise<void> {
	try {
		await reviewsStore.fetchSeniorQueue()
		openSheetItem.value = null
		// The list rows carry identity/status directly. Drop cached per-sheet reads so the next modal
		// open fetches the sheet's current transactions instead of showing a pre-refresh snapshot.
		reviewsStore.clearSeniorSheetReviews()
	} catch {
		// The store exposes the failure; the template renders it.
	}
}

onMounted(() => {
	void refresh()
})
</script>

<style scoped>
.queue-view {
	/* Direction comes from the `:dir="direction"` binding only (see direction.ts / UI-PUR-001). A
	   hardcoded `direction` here would override the attribute and force RTL even in English LTR,
	   the exact layout bug the shared utility exists to prevent. Same policy as CostCenterQueueView. */
}

.breadcrumbs {
	margin-block-end: 16px;
}

.breadcrumbs__list {
	display: flex;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
	font-size: var(--font-size-floor);
}

.breadcrumbs__item + .breadcrumbs__item::before {
	content: '/';
	margin-inline-end: 8px;
	color: var(--color-text-maxcontrast);
}

.queue-header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	margin-block-end: 16px;
}

.queue-header__eyebrow {
	margin: 0 0 4px;
	font-size: var(--font-size-floor);
	font-weight: 700;
	letter-spacing: 0.06em;
	text-transform: uppercase;
	color: var(--color-primary-element);
}

.queue-header__title {
	font-size: var(--font-size-title);
	font-weight: 700;
	margin: 0;
	line-height: 1.2;
}

.queue-header__subtitle {
	margin: 6px 0 0;
	max-inline-size: 62ch;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.queue-header__aside {
	display: flex;
	align-items: center;
	gap: 12px;
}

.responsibility {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	padding: 12px 16px;
	margin-block-end: 16px;
	border: 1px solid color-mix(in srgb, var(--color-primary-element) 22%, var(--color-border));
	border-radius: 12px;
	background-color: color-mix(in srgb, var(--color-primary-element) 5%, var(--color-main-background));
}

.responsibility__icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: none;
	inline-size: 32px;
	block-size: 32px;
	border-radius: 9px;
	background-color: var(--color-main-background);
	color: var(--color-primary-element);
}

.responsibility__body {
	flex: 1 1 320px;
	min-inline-size: 0;
}

.responsibility__title {
	margin: 0;
	font-size: var(--font-size-emphasis);
	line-height: 1.3;
}

.responsibility__text {
	margin: 2px 0 0;
	max-inline-size: 80ch;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

/*
 * Compact statistics cards (mission UX correction item 4): smaller padding, tighter internal gaps
 * and a smaller icon box cut the two cards' vertical footprint while keeping the approved hierarchy —
 * small label, prominent count, short supporting hint. No data or formatting changes; the narrow
 * layout keeps its own single-column grid at ≤640px.
 */
.summary {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 10px;
	margin-block-end: 14px;
}

.stat {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-inline-size: 0;
	padding: 10px 14px;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	background-color: var(--color-main-background);
}

.stat__top {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 6px;
}

.stat__label {
	display: flex;
	align-items: center;
	gap: 6px;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	font-weight: 600;
	line-height: 1.3;
}

.stat__icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: none;
	inline-size: 24px;
	block-size: 24px;
	border-radius: 6px;
}

.stat__icon--primary {
	background-color: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
	color: var(--color-primary-element);
}

.stat__icon--review {
	background-color: color-mix(in srgb, var(--pettycash-review) 14%, transparent);
	color: var(--pettycash-review);
}

.stat__value {
	font-size: 24px;
	font-weight: 700;
	line-height: 1.1;
	overflow-wrap: anywhere;
}

.stat__hint {
	display: flex;
	align-items: center;
	gap: 4px;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.stat__hint::before {
	content: '';
	flex: none;
	inline-size: 6px;
	block-size: 6px;
	border-radius: 50%;
	background-color: var(--color-success);
}

.sheet-panel {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
}

.queue-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 32px 0;
	text-align: center;
}

.queue-state__icon--error {
	color: var(--color-error-text);
}

.queue-state__icon--warning {
	color: var(--color-warning-text);
}

.queue-state h3 {
	margin: 8px 0 0;
	font-size: 1rem;
	font-weight: 600;
}

.queue-state__message {
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
}

.sheet-card {
	margin-block-start: 4px;
}

/* The row cells are built by `sheetCell()` with `h()` and rendered inside `ResponsiveTable`
   (child component scope), so the scoped selectors below would not reach them. Just as the narrow
   card's touch-target rule does, these use `:deep()` to style the same classes the view passes
   through its `cell` prop — the single place that defines the desktop table and narrow cards. */
:deep(.sheet-row__identity) {
	display: flex;
	align-items: center;
	gap: 10px;
	min-width: 0;
}

:deep(.sheet-row__icon) {
	display: inline-flex;
	color: var(--color-text-maxcontrast);
	flex: none;
}

:deep(.sheet-row__text) {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

/* The clickable title (a tertiary NcButton, mirroring the Sheets page pattern). Constrain it to the
   identity cell so a long title can never force the row past the table's width; the reference line
   below keeps its own truncation. */
:deep(.sheet-row__title-button) {
	max-width: 100%;
	min-width: 0;
	font-size: var(--font-size-floor);
	font-weight: 600;
	color: var(--color-main-text);
}

/* Latin isolate: the public-id prefix is a left-to-right identifier even inside an RTL page
   (T030, same policy as the Cost Center baseline's `.sheet-row__reference`). The 16px floor is
   FR-071: the secondary reference reads as muted (not smaller) against the 600-weight title, so the
   hierarchy survives without a sub-floor size. */
:deep(.sheet-row__reference) {
	direction: ltr;
	unicode-bidi: isolate;
	text-align: start;
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

:deep(.sheet-row__owner) {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
	overflow: hidden;
	font-size: var(--font-size-floor);
}

:deep(.sheet-row__owner-avatar) {
	flex: none;
	inline-size: 26px;
	block-size: 26px;
	border-radius: 50%;
	display: grid;
	place-items: center;
	background-color: color-mix(in srgb, var(--color-primary-element) 15%, transparent);
	color: var(--color-primary-element);

	/* No font-size here: the initials inherit the owner cell's `--font-size-floor` (16px), keeping
	   the decorative issue-avatar within the FR-071 floor (no user-facing text below 16px). */
	font-weight: 700;
}

:deep(.sheet-row__owner-name) {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

:deep(.sheet-row__count),
:deep(.sheet-row__activity),
:deep(.sheet-row__status) {
	display: block;
	text-align: end;
}

:deep(.sheet-row__amount) {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 2px;
}

:deep(.sheet-row__money) {
	font-family: 'Noto Sans Mono', monospace;
}

:deep(.sheet-row__activity) {
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

:deep(.status-badge) {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-large, 8px);
	font-size: var(--font-size-floor);
	font-weight: 600;
}

:deep(.status-badge--pending) {
	background-color: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
}

:deep(.status-badge--completed) {
	background-color: var(--color-success);
	color: var(--color-primary-text);
}

.senior-queue-toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	margin-block-end: 12px;
}

.senior-queue-search {
	display: flex;
	align-items: center;
	gap: 6px;
	min-width: min(280px, 100%);
}

.senior-queue-search__input {
	flex: 1;
	min-width: 0;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
}

.senior-queue-tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.senior-queue-tabs__tab {
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
	cursor: pointer;
}

.senior-queue-tabs__tab--active {
	border-color: var(--color-primary);
	background-color: var(--color-primary-light, var(--color-primary));
	color: var(--color-primary-text);
}

.senior-queue-no-results {
	margin: 0;
	padding: 24px 0;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

/* 390px floor: same as the Cost Center baseline's approved narrow layout (T036) — fan the status tabs
   wide and keep each tap area ≥44px tall (FR-007-042, touch targets). */
@media (width <= 640px) {
	.queue-header {
		flex-direction: column;
		align-items: flex-start;
	}

	.queue-header__eyebrow {
		font-size: var(--font-size-floor);
	}

	.queue-header__title {
		font-size: var(--font-size-title);
	}

	.queue-header__aside {
		width: 100%;
	}

	.responsibility {
		align-items: flex-start;
		padding: 11px;
	}

	.responsibility__icon {
		flex: none;
	}

	.summary {
		grid-template-columns: 1fr;
		gap: 8px;
	}

	.senior-queue-tabs {
		inline-size: 100%;
	}

	.senior-queue-tabs__tab {
		flex: 1 1 auto;
		justify-content: center;
		min-block-size: 44px;
	}

	.senior-queue-search {
		min-width: 100%;
	}

	.senior-queue-search__input {
		min-block-size: 44px;
	}

	/* T030 / mission UX: the title button on a narrow card is now the real tap target — the card
	   `<li>` is purely presentational (no role/tabindex/click), so the NcButton's default 34px height
	   would sit below the 44px mobile floor. The title cell renders under ResponsiveTable's scope, so
	   reach it from this component's root via `:deep()`. The hook differs by layout — `-cell-` on the
	   desktop `<td>`, `-card-field-` on the narrow card `<span>` — so the rule names both. */
	:deep([data-testid="senior-queue-cell-reference"] .button-vue),
	:deep([data-testid="senior-queue-card-field-reference"] .button-vue) {
		min-block-size: 44px;
	}
}
</style>

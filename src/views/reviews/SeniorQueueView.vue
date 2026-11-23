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

						<div class="center-filter" data-testid="center-filter">
							<span class="center-filter__label">
								{{ t('pettycash', 'reviews.seniorSheet.costCenterFilter.label') }}
							</span>
							<button
								v-if="filterableCenters.length > 0"
								type="button"
								class="center-filter__chip"
								:class="{ 'center-filter__chip--active': selectedCenterId === '' }"
								:aria-pressed="selectedCenterId === ''"
								data-testid="center-filter-chip-all"
								@click="selectedCenterId = ''"
							>
								{{ t('pettycash', 'reviews.seniorSheet.costCenterFilter.all') }}
							</button>
							<button
								v-for="center in filterableCenters"
								:key="center.id"
								type="button"
								class="center-filter__chip"
								:class="{ 'center-filter__chip--active': selectedCenterId === center.id }"
								:aria-pressed="selectedCenterId === center.id"
								:data-testid="`center-filter-chip-${center.id}`"
								@click="selectedCenterId = center.id"
							>
								{{ center.name }}
							</button>
						</div>
					</div>

					<div v-if="visibleSheets.length === 0" class="senior-queue-no-results" data-testid="senior-queue-no-results">
						{{ t('pettycash', 'reviews.sheetQueue.noResults') }}
					</div>

					<div v-else class="sheet-card">
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

		<!--
			Shared Sheet Details Modal (Senior stage). The queue stays mounted behind the modal.
			Stage-specific identity, metrics, actions, tab content and overlays come from the
			Senior stage adapter below; the shell owns only dialog semantics, focus, tabs and
			RTL/LTR responsive structure.
		-->
		<SheetDetailsModal
			:open="openSheetItem !== null"
			:adapter="seniorAdapter"
			@close="closeSheetModal"
		>
			<template #header-actions>
				<NcButton
					:disabled="submitting || visibleEntries.length === 0"
					data-testid="senior-export-btn"
					@click="exportXlsx"
				>
					{{ submitting
						? t('pettycash', 'reviews.seniorSheet.export.busy')
						: t('pettycash', 'reviews.seniorSheet.export.label') }}
				</NcButton>
			</template>

			<template #tab-transactions>
				<div v-if="reviewUnavailable" class="sheet-details-modal__state" data-testid="sheet-details-modal-unavailable">
					<h4 class="sheet-details-modal__state-title">{{ t('pettycash', 'reviews.seniorSheet.modal.unavailableTitle') }}</h4>
					<p class="sheet-details-modal__state-body">{{ t('pettycash', 'reviews.seniorSheet.modal.unavailableBody') }}</p>
					<NcButton data-testid="sheet-details-modal-retry" @click="retryReview">
						{{ t('pettycash', 'common.retry') }}
					</NcButton>
				</div>
				<div v-else-if="reviewLoading" class="sheet-details-modal__state" data-testid="sheet-details-modal-loading">
					<NcLoadingIcon :size="44" />
					<p>{{ t('pettycash', 'reviews.seniorSheet.modal.loading') }}</p>
				</div>
				<div v-else-if="visibleEntries.length > 0" class="senior-transactions-toolbar" data-testid="senior-transactions-toolbar">
					<div class="senior-filter" data-testid="senior-filter-panel">
						<label class="senior-filter__field senior-filter__field--search" data-testid="senior-filter-search-field">
							<span class="senior-filter__label">{{ t('pettycash', 'reviews.seniorSheet.filter.searchLabel') }}</span>
							<input
								type="search"
								class="senior-filter__input"
								:value="filter.query ?? ''"
								:placeholder="t('pettycash', 'reviews.seniorSheet.filter.searchPlaceholder')"
								data-testid="senior-filter-search"
								@input="onSearchInput"
							>
						</label>

						<div class="senior-filter__field senior-filter__field--types" data-testid="senior-filter-expense-types">
							<span class="senior-filter__label">{{ t('pettycash', 'reviews.seniorSheet.filter.expenseTypes') }}</span>
							<div class="senior-filter__chips">
								<button
									v-for="facet in expenseTypeFacets"
									:key="facet.id"
									type="button"
									class="senior-filter__chip"
									:class="{ 'senior-filter__chip--active': isExpenseTypeSelected(facet.id) }"
									:aria-pressed="isExpenseTypeSelected(facet.id)"
									:data-testid="`senior-filter-expense-type-${facet.id}`"
									@click="toggleExpenseType(facet.id)"
								>
									{{ facet.name }} · {{ formatNumber(facet.count) }}
								</button>
							</div>
						</div>
					</div>

					<div class="senior-results" data-testid="senior-results-toolbar">
						<div class="senior-sort" data-testid="senior-sort-panel">
							<span class="senior-filter__label">{{ t('pettycash', 'reviews.seniorSheet.sort.controlLabel') }}</span>
							<div class="senior-sort__row">
								<button
									v-for="key in SORT_KEYS"
									:key="key"
									type="button"
									class="senior-sort__button"
									:class="{ 'senior-sort__button--active': sort.key === key }"
									:aria-pressed="sort.key === key"
									:data-testid="`senior-sort-${key}`"
									@click="onSortKey(key)"
								>
									<span>{{ sortLabel(key) }}</span>
									<template v-if="sort.key === key">
										<NcIconSvgWrapper
											class="senior-sort__direction-icon"
											:path="sortDirectionIcon"
											:size="16"
											aria-hidden="true"
										/>
										<span class="senior-sort__direction-text">{{ directionLabel }}</span>
									</template>
								</button>
							</div>
						</div>

						<div class="senior-results__actions">
							<NcButton
								variant="tertiary"
								size="small"
								data-testid="senior-filter-clear"
								@click="clearFilters"
							>
								{{ t('pettycash', 'reviews.seniorSheet.filter.clear') }}
							</NcButton>
						</div>
					</div>

					<div v-if="exportRefused" class="senior-export__refused" role="alert" data-testid="senior-export-refused">
						<h4 class="senior-export__refused-title">{{ t('pettycash', 'reviews.seniorSheet.export.refusedTitle') }}</h4>
						<p class="senior-export__refused-body">{{ t('pettycash', 'reviews.seniorSheet.export.refusedBody') }}</p>
						<p v-if="exportReason" class="senior-export__reason" data-testid="senior-export-reason">{{ exportReason }}</p>
					</div>
				</div>

				<ul v-if="visibleEntries.length > 0" class="senior-transactions" data-testid="transaction-review-cards">
					<li
						v-for="entry in visibleEntries"
						:key="entry.transaction.id"
						class="senior-transaction"
						:data-testid="`sheet-transaction-${entry.transaction.id}`"
					>
						<!--
							The canonical shared transaction card (UXC-8-03, v2 interaction per UXC-V2-10). Its
							stage indicator row carries the Cost Center decision at transaction level (FR-034 /
							FR-007-004) — the former custom advisory paragraph is superseded by the card's own
							`costCenter` indicator. The title toggles this card's Inline Expanded Transaction in
							place — no second overlay, no route change; the expansion data comes from the same
							guarded per-transaction detail read, and a completed sheet stays read-only.
						-->
						<TransactionReviewCard
							:transaction="entry.transaction"
							:evidence="transactionEvidence(entry.transaction.id)"
							:stage-decisions="stageDecisionsFor(entry)"
							:allowed-actions="isSheetCompleted ? null : transactionDetail(entry.transaction.id)?.allowedActions ?? null"
							:read-only="isSheetCompleted"
							:show-actions="entry.seniorState === 'AWAITING_SENIOR_DECISION'"
							:busy="submittingTransactionId === entry.transaction.id"
							expandable
							:expanded="expandedTransactionId === entry.transaction.id"
							:detail="expandedDetail"
							:detail-read-state="expandedReadState"
							:detail-sheet="expandedSheet"
							:detail-stage="inlineStage"
							:suppress-status-badge="true"
							@toggle-expand="toggleExpansion"
							@open-preview="(evidenceId) => openPreviewFromCard(entry, evidenceId)"
							@decide="(kind) => openConfirmForTransaction(entry.transaction.id, kind)"
						/>
					</li>
				</ul>

				<NcEmptyContent
					v-else
					:name="t('pettycash', 'reviews.seniorSheet.filter.noResults')"
					data-testid="senior-filter-no-results"
				/>
			</template>

			<!--
				Approvals (UXC-7-01): the sheet-level aggregate surface — the server's whole-sheet
				approved totals per workflow stage, consumed authoritatively from the per-sheet read
				(UXC-7F-05). Per-transaction decision history stays in each transaction's Previous
				Decisions section inside the inline expansion only.
			-->
			<template #tab-approvals>
				<SheetApprovalsSummary :stages="approvalsStages" />
			</template>

			<!--
				Overview (UXC-7-03): sheet-level identity and summary from the authoritative list row
				and per-sheet read — honest unavailable markers where a field is not proven.
			-->
			<template #tab-overview>
				<dl class="senior-overview" data-testid="senior-tab-overview">
					<div class="senior-overview__item">
						<dt>{{ t('pettycash', 'reviews.sheetQueue.sheet') }}</dt>
						<dd>
							{{ sheetTitleLabel }}
							<CompactIdentifier :value="referenceLabel ?? ''" class="senior-overview__reference" />
						</dd>
					</div>
					<div class="senior-overview__item">
						<dt>{{ t('pettycash', 'reviews.sheetQueue.purchaser') }}</dt>
						<dd data-testid="senior-overview-purchaser">{{ ownerLabel }}</dd>
					</div>
					<div class="senior-overview__item">
						<dt>{{ t('pettycash', 'reviews.sheetQueue.submitted') }}</dt>
						<dd data-testid="senior-overview-submitted">
							{{ submittedLabel?.value ?? t('pettycash', 'reviews.sheetQueue.submittedUnavailable') }}
						</dd>
					</div>
					<div class="senior-overview__item">
						<dt>{{ t('pettycash', 'reviews.amount') }}</dt>
						<dd data-testid="senior-overview-totals">
							<span
								v-for="total in openSheetItem?.totals ?? []"
								:key="`${total.currency}-${total.minorUnit}`"
								class="senior-overview__money"
							>
								<bdi>{{ formatMoney(total) }}</bdi>
							</span>
							<template v-if="(openSheetItem?.totals ?? []).length === 0">{{ ABSENT_MARKER }}</template>
						</dd>
					</div>
					<div class="senior-overview__item">
						<dt>{{ t('pettycash', 'reviews.seniorSheet.pendingCount') }}</dt>
						<dd data-testid="senior-overview-pending-count">{{ formatNumber(openSheetItem?.transactionCount ?? 0) }}</dd>
					</div>
					<div class="senior-overview__item">
						<dt>{{ t('pettycash', 'reviews.seniorSheet.latestActivity') }}</dt>
						<dd data-testid="senior-overview-latest-activity">
							{{ openSheetItem?.latestActivityAt ? formatSheetDateTime(openSheetItem.latestActivityAt) : ABSENT_MARKER }}
						</dd>
					</div>
					<div class="senior-overview__item">
						<dt>{{ t('pettycash', 'reviews.sheetQueue.status') }}</dt>
						<dd data-testid="senior-overview-status">{{ seniorOverviewStatus }}</dd>
					</div>
				</dl>
			</template>

			<!-- No sheet-level event history exists under the Senior reads — honestly empty (UXC-7-03). -->
			<template #tab-history>
				<div class="sheet-details-modal__empty" data-testid="senior-tab-history-empty">
					{{ t('pettycash', 'reviews.sheetHistory.unavailable') }}
				</div>
			</template>

			<!--
				Evidence Preview and Decision Confirmation remain the only overlays above the sheet modal
				(UXC-V2-10): the transaction detail lives in the card's inline expansion, not in a second
				full-height layer. A completed sheet stays read-only in the expansion as everywhere else.
			-->
			<template #overlays>
				<EvidencePreview
					:open="activeOverlay === 'preview'"
					:evidence="previewEvidence"
					:documents="previewDocuments"
					:index="previewIndex"
					@close="closeOverlay"
					@navigate="navigatePreview"
				/>

				<DecisionConfirmation
					:open="activeOverlay === 'confirm'"
					:decision="confirmDecision"
					:transaction="confirmSnapshot"
					:expected-version="confirmExpectedVersion"
					:allowed-actions="confirmAllowedActions"
					@close="closeOverlay"
					@decided="onDecided"
				/>
			</template>
		</SheetDetailsModal>
	</div>
</template>

<script setup lang="ts">
import { computed, h, nextTick, onBeforeUnmount, onMounted, ref, type VNode, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { t } from '@nextcloud/l10n'
import {
	mdiAlertCircle,
	mdiArrowDown,
	mdiArrowUp,
	mdiCheckCircle,
	mdiFileDocumentOutline,
	mdiRefresh,
} from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ResponsiveTable from '@/components/shared/ResponsiveTable.vue'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import SheetDetailsModal from '@/components/review/SheetDetailsModal.vue'
import SheetApprovalsSummary from '@/components/review/SheetApprovalsSummary.vue'
import TransactionReviewCard, { type TransactionReviewCardStageDecisions } from '@/components/review/TransactionReviewCard.vue'
import CompactIdentifier from '@/components/review/CompactIdentifier.vue'
import type { TransactionInlineDetailStageConfig } from '@/components/review/TransactionInlineDetail.vue'
import DecisionConfirmation, { type DecisionConfirmationTransaction } from '@/components/review/DecisionConfirmation.vue'
import EvidencePreview from '@/components/evidence/EvidencePreview.vue'
import { direction } from '@/utils/direction'
import { formatMoney } from '@/utils/money'
import { formatNumber } from '@/utils/number'
import { formatSheetDate, formatSheetDateTime, ABSENT_MARKER } from '@/utils/date'
import {
	authoritativeStageApprovals,
	unprovenApprovalsStages,
	type ApprovalsStageList,
} from '@/utils/stageApprovals'
import {
	adaptSeniorSheetListRows,
	checkSeniorSheetQueueCompleteness,
	deriveExpenseTypeFacets,
	filterSeniorSheetsByStatus,
	filterSeniorTransactions,
	searchSeniorSheets,
	sortSeniorSheets,
	sortSeniorTransactions,
} from '@/composables/useSeniorQueue'
import type {
	SeniorQueueSortKey,
	SeniorSheetSortAccess,
} from '@/composables/useSeniorQueue'
import { useReviewsStore } from '@/stores/reviews'
import { useAuthStore } from '@/stores/auth'
import { useConfigurationStore } from '@/stores/configuration'
import { exportArtifactBlob } from '@/services/reportsApi'
import type {
	Decision,
	DecisionResult,
	DecisionValue,
	Evidence,
	ManagerTransactionReadState,
	SeniorFilterState,
	SeniorManagerSheetReview,
	SeniorManagerTransactionState,
	SeniorSheetQueueItem,
	SeniorSort,
	SeniorSortKey,
	TransactionDetail,
	TransactionSummary,
} from '@/types/api'

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

const sheetItems = computed(() => {
	const rows = adaptSeniorSheetListRows(seniorSheetQueue.value.items)
	const costCentersById = sheetCostCentersById.value
	return rows.map((row) => ({
		...row,
		costCenters: costCentersById.get(row.sheetId) ?? row.costCenters
	}))
})

const seniorSheetReviews = computed(() => reviewsStore.seniorSheetReviews)

const authStore = useAuthStore()
const configurationStore = useConfigurationStore()

/** Cost centers within the current user's authorized management scope, with readable names. */
const filterableCenters = computed(() => {
	const codes = authStore.costCenterCodes
	const byCode = new Map<string, { id: string; name: string }>()
	for (const center of configurationStore.costCenters) {
		const key = center.code || center.id
		if (key) byCode.set(key, { id: key, name: center.name ?? key })
	}
	const assigned: { id: string; name: string }[] = []
	for (const code of codes) {
		const center = byCode.get(code)
		if (center) assigned.push(center)
	}
	return assigned.sort((a, b) => a.name.localeCompare(b.name))
})

/** Per-sheet transactions from the authoritative senior sheet read (may be empty until the read answers). */
const sheetTransactionsById = computed(() => {
	const map = new Map<string, TransactionSummary[]>()
	for (const [sheetId, review] of Object.entries(seniorSheetReviews.value)) {
		if (review && Array.isArray(review.transactions)) map.set(sheetId, review.transactions)
	}
	return map
})

/** Per-sheet cost centers derived from the senior sheet read's transactions. */
const sheetCostCentersById = computed(() => {
	const map = new Map<string, { id: string; name: string; code: string }[]>()
	for (const [sheetId, transactions] of sheetTransactionsById.value.entries()) {
		const seen = new Set<string>()
		const centers: { id: string; name: string; code: string }[] = []
		for (const tx of transactions) {
			const costCenter = tx.activeRevision?.costCenter
			if (!costCenter) continue
			const key = costCenter.code || costCenter.id || costCenter.name || ''
			if (!key || seen.has(key)) continue
			seen.add(key)
			centers.push({ id: key, name: costCenter.name ?? key, code: costCenter.code ?? '' })
		}
		map.set(sheetId, centers.sort((a, b) => a.name.localeCompare(b.name)))
	}
	return map
})

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
const selectedCenterId = ref('')
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
	const statusFiltered = filterSeniorSheetsByStatus(
		searched,
		sheetStatusTab.value,
		(sheet) => sheet.reviewStatus ?? null
	)
	const centerFiltered = selectedCenterId.value === ''
		? statusFiltered
		: statusFiltered.filter((sheet) => sheetCostCentersById.value.get(sheet.sheetId)?.some((center) => center.id === selectedCenterId.value) ?? false)
	const scoped = selectedCenterId.value === ''
		? centerFiltered
		: centerFiltered.map((sheet) => scopeSeniorSheet(sheet))
	const key = sheetSortKey.value
	if (key === null) return scoped
	return sortSeniorSheets(scoped, { key, direction: sheetSortDirection.value }, sheetSortAccess)
})

/** Scope a sheet row's transaction count and amounts to the selected cost center. */
function scopeSeniorSheet(sheet: SeniorSheetQueueItem): SeniorSheetQueueItem {
	const transactions = sheetTransactionsById.value.get(sheet.sheetId) ?? []
	const scopedTx = transactions.filter((tx) => (tx.activeRevision?.costCenter?.code || tx.activeRevision?.costCenter?.id) === selectedCenterId.value)
	const byCurrency = new Map<string, { currency: string; minorUnit: number; amountMinor: number }>()
	for (const tx of scopedTx) {
		const money = tx.activeRevision?.amount
		if (!money || !Number.isFinite(money.amountMinor) || !Number.isFinite(money.minorUnit)) continue
		const key = `${money.currency}\u0000${money.minorUnit}`
		const existing = byCurrency.get(key)
		if (existing) existing.amountMinor += money.amountMinor
		else byCurrency.set(key, { currency: money.currency, minorUnit: money.minorUnit, amountMinor: money.amountMinor })
	}
	return {
		...sheet,
		transactionCount: scopedTx.length,
		totals: [...byCurrency.values()].sort((a, b) => a.currency.localeCompare(b.currency)),
	}
}

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
		{ key: 'pendingCount', label: t('pettycash', 'reviews.sheetQueue.transactions'), sortable: true },
		{ key: 'amount', label: t('pettycash', 'reviews.amount'), sortable: true },
		{ key: 'status', label: t('pettycash', 'reviews.status'), sortable: true },
		{ key: 'submitted', label: t('pettycash', 'reviews.sheetQueue.submitted'), sortable: true },
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
					h(CompactIdentifier, {
						value: sheet.sheetId,
						'data-testid': 'sheet-reference',
						class: 'sheet-row__reference',
					}),
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
	case 'submitted':
		return h('span', { class: 'sheet-row__submitted', 'data-testid': 'sheet-submitted' }, [
			sheet.submittedAt ? formatSheetDate(sheet.submittedAt) : t('pettycash', 'reviews.sheetQueue.submittedUnavailable'),
		])
	case 'status':
		return h('span', { class: 'sheet-row__status', 'data-testid': 'sheet-status' }, [
			h('span', { 'data-testid': 'sheet-status-badge' }, [
			h(StatusBadge, {
				status: 'COST_CENTER',
				variant: sheet.transactionCount > 0 ? 'warning' : 'success',
				label: sheet.transactionCount > 0
					? t('pettycash', 'reviews.sheetQueue.awaiting')
					: t('pettycash', 'reviews.seniorSheet.reviewCompleted'),
			}),
			]),
			spanReturnedIndicator(sheet),
		])
	default:
		// A column with no case is a new column nobody rendered, not an empty cell.
		return h('span', { 'data-testid': `sheet-unrendered-${column.key}` }, column.key)
	}
}

function spanReturnedIndicator(sheet: SeniorSheetQueueItem): VNode | null {
	let count = 0
	for (const tx of sheet.transactions) {
		const detail = transactionDetail(tx.id)
		if (!detail) continue
		if (detail.decisions?.some((d) => d.stage === 'SENIOR' && d.decision === 'RETURN')) count += 1
	}
	if (count === 0) return null
	return h('span', { class: 'sheet-row__returned' }, count === 1
		? t('pettycash', 'reviews.sheetQueue.returnedTransactionOne')
		: t('pettycash', 'reviews.sheetQueue.returnedTransactionMany', { count: formatNumber(count) }))
}

/** The `<tr>` hook; the card keeps its own prefix so a row count stays a count (ResponsiveTable). */
function sheetRowTestId(sheet: SeniorSheetQueueItem): string {
	return `sheet-row-${sheet.sheetId || 'unresolved'}`
}

function sheetCardRowTestId(sheet: SeniorSheetQueueItem): string {
	return `sheet-card-row-${sheet.sheetId || 'unresolved'}`
}

function sheetRowKey(sheet: SeniorSheetQueueItem, index: number): string {
	return sheet.sheetId || `sheet-row-${index}`
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
		`[data-testid="sheet-row-${origin}"] [data-testid="sheet-title"], [data-testid="sheet-card-row-${origin}"] [data-testid="sheet-title"]`
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
	const row = document.querySelector<HTMLElement>(`[data-testid="sheet-row-${origin}"]`)
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
		reviewsStore.clearSeniorSheetReviews()
		await prefetchSeniorSheetReviews()
	} catch {
		// The store exposes the failure; the template renders it.
	}
}

async function prefetchSeniorSheetReviews(): Promise<void> {
	const items = seniorSheetQueue.value.items
	await Promise.allSettled(items.map((row) => reviewsStore.fetchSeniorSheetReview(row.id)))
	void prefetchTransactionDetails(
		items.flatMap((row) => {
			const review = reviewsStore.seniorSheetReviews[row.id]
			return review?.transactions?.map((t) => t.id) ?? []
		})
	)
}

const costCentersLoaded = ref(false)

onMounted(() => {
	void refresh()
	// Issue B: cost-center options must not depend on visiting /admin/configuration.
	// configurationStore.costCenters is populated there, but the Senior queue must load
	// its assigned centers independently. listCostCenters() is #[NoAdminRequired] and
	// caller-scoped; filterableCenters further narrows to authStore.costCenterCodes.
	if (!configurationStore.costCenters.length && !costCentersLoaded.value) {
		costCentersLoaded.value = true
		void configurationStore.loadCostCenters().catch(() => {
			// Error is stored in configurationStore.error; view renders without chips.
		})
	}
})

// ---------------------------------------------------------------------------
// Senior stage adapter — configuration passed to the shared SheetDetailsModal.
// The shell never references Senior business rules; every value below is stage-specific.
// ---------------------------------------------------------------------------

/** The additive Senior sheet review (per-sheet read), or null when not yet loaded. */
const review = computed<SeniorManagerSheetReview | null>(() => {
	if (!openSheetItem.value || openSheetItem.value.sheetId === '') return null
	const candidate = reviewsStore.seniorSheetReviews[openSheetItem.value.sheetId]
	if (!candidate || !candidate.sheet || !Array.isArray(candidate.transactions)) return null
	return candidate
})

/** The list row already carries the full server sheet summary. */
const itemSheet = computed(() => openSheetItem.value?.sheet ?? null)

const sheetReadState = computed<ManagerTransactionReadState | 'idle'>(() => {
	if (!openSheetItem.value || openSheetItem.value.sheetId === '') return 'idle'
	return reviewsStore.seniorSheetReadStates[openSheetItem.value.sheetId] ?? 'idle'
})

const reviewTransactions = computed(() => review.value?.transactions ?? [])

const reviewByTransactionId = computed(() => {
	const map = new Map<string, TransactionSummary & { seniorState: SeniorManagerTransactionState; seniorDecision: Decision | null }>()
	for (const row of reviewTransactions.value) map.set(row.id, row)
	return map
})

/**
 * Post-decision re-render source (FR-007-021): the server's own `result.transaction` replaces the
 * stale queue row, so the card shows its decided state from the response that recorded it — never a
 * client-invented status. Keyed by transaction id so several decisions in one session each land once.
 */
const patchedTransactions = ref<Record<string, TransactionDetail>>({})

/**
 * A transaction's Senior presentation state.
 *
 * - the additive Senior read's own `seniorState`/`seniorDecision` fields, when the backend supplies
 *   them (`[BD←BE1]`), are authoritative;
 * - a freshly recorded decision's server-returned `result.transaction` supplies the decision (its
 *   `decisions[]`), and the transaction then no longer awaits the senior;
 * - a row from the proven-complete senior-pending set awaits by construction — that is the definition
 *   of the queue, not a fabricated claim (FR-007-011).
 */
interface ModalTransactionEntry {
	transaction: TransactionSummary
	seniorState: SeniorManagerTransactionState
	seniorDecision: Decision | null
}

const entries = computed<ModalTransactionEntry[]>(() => {
	const rows = reviewTransactions.value.length > 0
		? reviewTransactions.value
		: (openSheetItem.value?.transactions ?? [])
	return rows.map((raw) => {
		const id = raw.id
		const patched = patchedTransactions.value[id]
		if (patched) {
			const seniorDecision = latestSeniorDecision(patched)
			return {
				transaction: patched,
				seniorState: seniorDecision ? 'DECIDED_BY_SENIOR' : 'AWAITING_SENIOR_DECISION',
				seniorDecision,
			}
		}
		const reviewRow = reviewByTransactionId.value.get(id)
		if (reviewRow) {
			return { transaction: reviewRow, seniorState: reviewRow.seniorState, seniorDecision: reviewRow.seniorDecision }
		}
		return { transaction: raw, seniorState: 'AWAITING_SENIOR_DECISION', seniorDecision: null }
	})
})

function latestSeniorDecision(transaction: { decisions?: readonly Decision[] }): Decision | null {
	if (!Array.isArray(transaction.decisions)) return null
	const senior = transaction.decisions.filter((item) => item.stage === 'SENIOR')
	return senior[senior.length - 1] ?? null
}

/**
 * A list-derived row has no transactions of its own, so an empty `entries` set means either the
 * per-sheet read is still in flight or it failed — never that the sheet is empty until the server
 * has actually answered with an empty list.
 */
const reviewLoading = computed(() =>
	entries.value.length === 0 && sheetReadState.value === 'loading'
)

const reviewUnavailable = computed(() =>
	entries.value.length === 0
	&& (sheetReadState.value === 'unavailable'
		|| sheetReadState.value === 'forbidden'
		|| sheetReadState.value === 'notFound')
)

/** D5 / FR-007-017: the server-supplied presentation status; never derived client-side. */
const isSheetCompleted = computed(() =>
	(review.value?.sheet.reviewStatus
		?? itemSheet.value?.reviewStatus
		?? openSheetItem.value?.reviewStatus) === 'REVIEW_COMPLETED'
)

/*
 * T020/T021 — local filter + sort over the proven-complete set, with no request per change.
 *
 * The pure model lives in `useSeniorQueue.ts` and is unit-tested there; this wires the approved
 * toolbar controls (transaction search + expense-type multi-select) to it and keeps ONE derived
 * order (`visibleEntries`) that feeds both the rendered cards and the export selection (T022).
 * Vendor is out of scope (owner D7): no vendor term, field or placeholder appears.
 */
const SORT_KEYS: SeniorSortKey[] = ['amount', 'expenseDate', 'expenseType']

const filter = ref<SeniorFilterState>({})
const sort = ref<SeniorSort>({ key: 'expenseDate', direction: 'asc' })

const expenseTypeFacets = computed(() =>
	deriveExpenseTypeFacets(entries.value.map((entry) => entry.transaction), filter.value)
)

/**
 * The one derived order: filter, then sort, then re-attach each transaction's presentation entry.
 * Both the rendered cards and the export `ids` read from this, so what the user exports is exactly
 * what the modal shows (FR-007-044).
 */
const visibleEntries = computed<ModalTransactionEntry[]>(() => {
	const byId = new Map(entries.value.map((entry) => [entry.transaction.id, entry]))
	const filtered = filterSeniorTransactions(entries.value.map((entry) => entry.transaction), filter.value)
	const sorted = sortSeniorTransactions(filtered, sort.value)
	return sorted
		.map((transaction) => byId.get(transaction.id))
		.filter((entry): entry is ModalTransactionEntry => entry !== undefined)
})

function onSearchInput(event: Event): void {
	filter.value = { ...filter.value, query: (event.target as HTMLInputElement).value }
}

function isExpenseTypeSelected(id: string): boolean {
	return (filter.value.expenseTypeIds ?? []).includes(id)
}

function toggleExpenseType(id: string): void {
	const current = filter.value.expenseTypeIds ?? []
	const next = current.includes(id) ? current.filter((value) => value !== id) : [...current, id]
	filter.value = { ...filter.value, expenseTypeIds: next.length > 0 ? next : undefined }
}

function clearFilters(): void {
	filter.value = {}
}

const sortLabel = (key: SeniorSortKey): string => t('pettycash', `reviews.seniorSheet.sort.${key}`)
const directionLabel = computed(() => sort.value.direction === 'asc'
	? t('pettycash', 'reviews.seniorSheet.sort.directionAsc')
	: t('pettycash', 'reviews.seniorSheet.sort.directionDesc'))
const sortDirectionIcon = computed(() => sort.value.direction === 'asc' ? mdiArrowUp : mdiArrowDown)

/**
 * One active criterion. Activating the active criterion again toggles its direction; switching to a
 * different criterion always starts ascending (owner-approved toolbar behaviour).
 */
function onSortKey(key: SeniorSortKey): void {
	if (sort.value.key === key) {
		sort.value = { key, direction: sort.value.direction === 'asc' ? 'desc' : 'asc' }
		return
	}
	sort.value = { key, direction: 'asc' }
}

/*
 * T022 — the guarded XLSX export of the filtered + sorted selection.
 */
const exporting = computed(() => reviewsStore.seniorExport.status === 'exporting')
const exportRefused = computed(() => reviewsStore.seniorExport.status === 'refused')
const exportReason = computed(() => {
	const reason = reviewsStore.seniorExport.status === 'refused' ? reviewsStore.seniorExport.reason : null
	return reason !== null && reason !== '' && reason !== 'pettycash.error.export' ? reason : null
})

async function exportXlsx(): Promise<void> {
	if (exporting.value || visibleEntries.value.length === 0) return
	// The exact ordered selection the user sees; never an owner-scoped fallback.
	const ids = visibleEntries.value.map((entry) => entry.transaction.id)
	const result = await reviewsStore.exportSeniorSelection(ids)
	if (!result) return // refusal is settled and rendered by the store (FR-007-044); nothing is exported.
	// [BD←BE2]: the server returned a real Senior-scoped artifact — present exactly those bytes.
	const blob = exportArtifactBlob(result)
	const url = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = `pettycash-senior-sheet-${openSheetItem.value?.sheetId || 'sheet'}.xlsx`
	link.click()
	URL.revokeObjectURL(url)
}

/** Senior-specific identity for the shared modal header. */
const referenceLabel = computed(() => {
	const id = openSheetItem.value?.sheetId ?? ''
	return id !== '' ? id : t('pettycash', 'reviews.seniorSheet.unresolvedReference')
})

const sheetTitleLabel = computed(() => {
	const title = review.value?.sheet?.title?.trim()
		|| itemSheet.value?.title?.trim()
		|| openSheetItem.value?.title?.trim()
		|| ''
	return title !== '' ? title : t('pettycash', 'reviews.seniorSheet.modal.titleUnavailable')
})

const ownerLabel = computed(() => {
	const owner = review.value?.sheet?.ownerDisplayName?.trim()
		|| itemSheet.value?.ownerDisplayName?.trim()
		|| openSheetItem.value?.purchaser?.trim()
		|| ''
	return owner !== '' ? owner : t('pettycash', 'reviews.seniorSheet.modal.ownerUnavailable')
})

const submittedLabel = computed<{ value: string; available: boolean } | null>(() => {
	const at = review.value?.sheet?.submittedAt
		?? itemSheet.value?.submittedAt
		?? openSheetItem.value?.submittedAt
		?? null
	if (at === null || at === '') return null
	return { value: formatSheetDateTime(at), available: true }
})

/**
 * The per-sheet read's own progress counters, shown as the same compact line the Cost Center
 * panel uses. It is server-supplied; no client-side completion is derived from the card states.
 */
const progressLabel = computed<string | null>(() => {
	const progress = review.value?.progress
	if (!progress) return null
	return t('pettycash', 'reviews.seniorSheet.modal.progress', {
		decided: formatNumber(progress.decidedBySenior),
		total: formatNumber(progress.total),
	})
})

/**
 * FR-007-004 / FR-034: the Cost Center decision is advisory at transaction level only — read from the
 * current revision's own Cost Center decision, never from `latestDecision` (which is simply the last
 * decision and may belong to a later stage — a completed sheet's `latestDecision` is the Senior or
 * Finance decision). When there is no Cost Center decision yet no value is fabricated: a Senior or
 * Finance decision is never labelled as if it were the Cost Center advisory.
 */
function latestCostCenterDecision(transaction: TransactionSummary): Decision | null {
	const sources: ReadonlyArray<readonly Decision[] | undefined> = [
		(transaction as TransactionSummary & { decisions?: readonly Decision[] }).decisions,
		transactionDetail(transaction.id)?.decisions,
	]
	for (const decisions of sources) {
		if (!Array.isArray(decisions)) continue
		if (decisions.length === 0) continue
		const cc = decisions.filter((item) => item.stage === 'COST_CENTER')
		return cc[cc.length - 1] ?? null
	}
	const latest = transaction.latestDecision
	return latest?.stage === 'COST_CENTER' ? latest : null
}

/**
 * Per-stage previous decisions for the shared card's indicator row (UXC-8-03), derived only from
 * authoritative data — mirrors the Finance view's helper: the guarded detail read's (or patched
 * result's) `decisions[]`, the per-sheet read's own `seniorDecision` for the acting stage, and the
 * summary's `latestDecision` for its own stage. A stage with no known decision stays `null` — the
 * indicator then reads "No decision yet"; nothing is ever guessed.
 */
function stageDecisionsFor(entry: ModalTransactionEntry): TransactionReviewCardStageDecisions {
	const transaction = entry.transaction
	const decisions = (transaction as TransactionSummary & { decisions?: readonly Decision[] }).decisions
		?? transactionDetail(transaction.id)?.decisions
		?? []
	const latest = transaction.latestDecision ?? null
	const fallbackForStage = (stage: Decision['stage']): Decision | null =>
		latest?.stage === stage ? latest : null
	const latestForStage = (stage: Decision['stage']): Decision | null => {
		const matches = decisions.filter((decision) => decision.stage === stage)
		return matches.length > 0 ? matches[matches.length - 1]! : null
	}
	return {
		costCenter: latestCostCenterDecision(transaction),
		senior: entry.seniorDecision ?? latestForStage('SENIOR') ?? fallbackForStage('SENIOR'),
		finance: latestForStage('FINANCE') ?? fallbackForStage('FINANCE'),
	}
}

/**
 * Per-transaction detail: the guarded cache (`managerTransactionDetails`) and read-state map carry
 * the transaction's `allowedActions` capability grant, its `evidence[]` set and its `version` — the
 * three inputs the card, thumbnails and confirmation modal need (T014, T016).
 */
function transactionDetail(transactionId: string): TransactionDetail | null {
	return reviewsStore.managerTransactionDetails[transactionId] ?? null
}

function transactionEvidence(transactionId: string): Evidence[] {
	return transactionDetail(transactionId)?.evidence ?? []
}

async function ensureTransactionDetail(transactionId: string): Promise<TransactionDetail | null> {
	return reviewsStore.fetchManagerTransactionDetail(transactionId)
}

/**
 * Bounded prefetch of the sheet's per-transaction details (evidence + capability grants), mirroring
 * the Cost Center queue's 4-worker pattern so a wide sheet cannot flood the OCS endpoint. Guarded
 * store reads: a failed transaction never blocks its neighbours and never drops the sheet.
 */
async function prefetchTransactionDetails(transactionIds?: readonly string[]): Promise<void> {
	const ids = [...new Set((transactionIds ?? entries.value.map((entry) => entry.transaction.id)).filter((id): id is string => id !== ''))]
	let nextIndex = 0
	const workers = Array.from({ length: Math.min(4, ids.length) }, async () => {
		while (nextIndex < ids.length) {
			const id = ids[nextIndex]
			if (id === undefined) return
			nextIndex += 1
			await reviewsStore.fetchManagerTransactionDetail(id)
		}
	})
	await Promise.all(workers)
}

// ---------------------------------------------------------------------------
// Overlay state (EvidencePreview, DecisionConfirmation) + the inline expansion
// (UXC-V2-10). The overlays stay one layer at a time above the modal and the
// shell's scrim is blocked while submitting; the transaction detail now lives in
// the card's inline expansion instead of a second full-height layer.
// ---------------------------------------------------------------------------

type Overlay = 'none' | 'preview' | 'confirm'

const activeOverlay = ref<Overlay>('none')
const confirmDecision = ref<DecisionValue | null>(null)
const confirmTransactionId = ref('')
/** The one Inline Expanded Transaction (UXC-V2-10); `null` collapses every card. */
const expandedTransactionId = ref<string | null>(null)
const previewTransactionId = ref('')
const previewIndex = ref(0)
const submittingTransactionId = ref<string | null>(null)

/** Zero or one expanded transaction; toggling the same card collapses it. */
function toggleExpansion(transactionId: string): void {
	const next = expandedTransactionId.value === transactionId ? null : transactionId
	expandedTransactionId.value = next
	if (next !== null) void ensureTransactionDetail(next)
}

const expandedDetail = computed<TransactionDetail | null>(() =>
	expandedTransactionId.value !== null ? transactionDetail(expandedTransactionId.value) : null)

const expandedReadState = computed<ManagerTransactionReadState>(() =>
	expandedTransactionId.value !== null
		? reviewsStore.managerTransactionReads[expandedTransactionId.value] ?? 'loading'
		: 'loading')

/** The sheet header facts for the expanded details — the open sheet's own server read. */
const expandedSheet = computed(() => review.value?.sheet ?? itemSheet.value ?? null)

const expandedEntry = computed<ModalTransactionEntry | null>(() =>
	expandedTransactionId.value !== null
		? entries.value.find((entry) => entry.transaction.id === expandedTransactionId.value) ?? null
		: null)

const expandedSeniorState = computed<SeniorManagerTransactionState>(() =>
	expandedEntry.value?.seniorState ?? 'AWAITING_SENIOR_DECISION')

/**
 * The current stage's action surface for the expanded proximity row (UXC-8-04): offered only while
 * the transaction still awaits a Senior decision and the sheet is not completed; withheld once
 * decided or on a read-only completed sheet. Which kinds the server actually grants is gated inside
 * the shared decide row from `allowedActions` — this config adds no rule of its own. The offered
 * kinds keep the legacy Senior drawer's display order.
 */
const inlineStage = computed<TransactionInlineDetailStageConfig | null>(() => {
	if (expandedDetail.value === null) return null
	if (isSheetCompleted.value || expandedSeniorState.value !== 'AWAITING_SENIOR_DECISION') {
		return { actionsVisible: false, actions: [] }
	}
	return { actionsVisible: true, actions: ['REJECT', 'RETURN', 'APPROVE'] }
})

const previewDocuments = computed<Evidence[]>(() => transactionEvidence(previewTransactionId.value))
const previewEvidence = computed<Evidence | null>(() => previewDocuments.value[previewIndex.value] ?? null)

const confirmSnapshot = computed<DecisionConfirmationTransaction | null>(() => {
	const detail = transactionDetail(confirmTransactionId.value)
	if (!detail) return null
	return {
		id: detail.id,
		title: (detail.activeRevision?.description?.trim() || t('pettycash', 'reviews.quickReview.card.noDescription')),
		sheetReference: openSheetItem.value?.sheetId || detail.sheetId,
		amount: detail.activeRevision?.amount ?? null,
		expenseDate: detail.activeRevision?.expenseDate ?? null,
	}
})

const confirmExpectedVersion = computed<number | undefined>(() =>
	transactionDetail(confirmTransactionId.value)?.version)

const confirmAllowedActions = computed(() =>
	transactionDetail(confirmTransactionId.value)?.allowedActions)

function closeOverlay(): void {
	activeOverlay.value = 'none'
	submittingTransactionId.value = null
}

/**
 * A thumbnail opens the preview at exactly that document; a tile whose detail read failed cannot
 * open an empty overlay, so it expands the transaction, whose inline region shows the honest read
 * state.
 */
async function openPreviewFromCard(entry: ModalTransactionEntry, evidenceId: string): Promise<void> {
	const detail = await ensureTransactionDetail(entry.transaction.id)
	const index = detail?.evidence.findIndex((item) => item.id === evidenceId) ?? -1
	if (detail && index >= 0) {
		previewTransactionId.value = entry.transaction.id
		previewIndex.value = index
		activeOverlay.value = 'preview'
		return
	}
	expandedTransactionId.value = entry.transaction.id
}

function navigatePreview(index: number): void {
	previewIndex.value = index
}

/**
 * The confirmation posts exactly one decision through the shared modal (which owns the
 * FR-085 idempotency header and FR-033 guard). Without the guarded detail read there is neither a
 * version to be optimistic against nor a capability grant to honour, so the inline expansion —
 * whose read state says what happened — opens instead of a modal that cannot submit.
 */
async function openConfirmForTransaction(transactionId: string, kind: DecisionValue): Promise<void> {
	if (isSheetCompleted.value || submittingTransactionId.value !== null) return
	const detail = await ensureTransactionDetail(transactionId)
	if (detail === null) {
		expandedTransactionId.value = transactionId
		return
	}
	confirmDecision.value = kind
	confirmTransactionId.value = transactionId
	submittingTransactionId.value = transactionId
	activeOverlay.value = 'confirm'
}

/**
 * Decision recorded (FR-007-021): close the layer, re-render the card from the server's
 * `result.transaction`, drop the now-stale per-transaction detail, and refetch the sheet read and the
 * queue so counts and states come from the server, never from a client-side patch. The toast words
 * come from the approved flow's completion state.
 */
async function onDecided(result: DecisionResult): Promise<void> {
	const transactionId = result.transaction.id
	const sheetId = openSheetItem.value?.sheetId ?? result.transaction.sheetId

	activeOverlay.value = 'none'
	submittingTransactionId.value = null
	expandedTransactionId.value = null
	patchedTransactions.value = { ...patchedTransactions.value, [transactionId]: result.transaction }
	reviewsStore.clearManagerTransactionDetail(transactionId)

	void (async () => {
		try {
			await reviewsStore.fetchSeniorSheetReview(sheetId, true)
		} catch {
			// The refreshed read is preferred but not required; the card keeps its decided state.
		}
		try {
			await reviewsStore.fetchSeniorQueue()
		} catch {
			// The queue's own error state carries the failure; the store keeps its previous set.
		}
		void reviewsStore.fetchManagerTransactionDetail(transactionId, true)
	})()

	try {
		const { showSuccess } = await import('@nextcloud/dialogs')
		showSuccess(t('pettycash', 'reviews.quickReview.decision.toastText'))
	} catch {
		// The decision is recorded and the layer refreshed; a toast is presentation, not evidence.
	}
}

/**
 * The per-sheet read supplies the proven-complete transaction rows and their Senior presentation
 * state; the list row alone carries no transactions. The detail prefetch runs only after that read
 * settles, because the transaction ids to prefetch are exactly the ones it returns.
 */
async function loadReview(force = false): Promise<void> {
	if (!openSheetItem.value || openSheetItem.value.sheetId === '') return
	try {
		await reviewsStore.fetchSeniorSheetReview(openSheetItem.value.sheetId, force)
	} catch {
		// The store classifies the failure into `seniorSheetReadStates`; the modal renders it.
	}
	await prefetchTransactionDetails()
}

function retryReview(): void {
	void loadReview(true)
}

/** True while a decision or export is in flight — the shell's scrim must not close then. */
const submitting = computed(() => exporting.value || submittingTransactionId.value !== null)

/**
 * The Senior stage adapter passed to `SheetDetailsModal`. The shell consumes identity, metrics,
 * read-only state and submitting state; all business rules (filter, sort, export, decisions,
 * overlays) are provided through the template slots above.
 */
/**
 * Sheet-level approved monetary totals per workflow stage (UXC-7-01), from the server's
 * whole-sheet `stageApprovedTotals` on the per-sheet review read (UXC-7F-05, owner decision
 * 2026-11-20) — never per-transaction decision history, which stays in each transaction's inline
 * expansion, and never a client-side sum over the senior-scoped transaction subset presented as a
 * whole-sheet figure.
 *
 * With the per-sheet read missing (failed or still loading) or the field absent/malformed (old
 * payload), every stage renders honestly unavailable; corrected/current-revision semantics stay
 * server-side. A decoded zero total is a real answer, rendered as a known zero.
 */
const approvalsStages = computed<ApprovalsStageList>(() =>
	review.value !== null
		? authoritativeStageApprovals(review.value.sheet.stageApprovedTotals) ?? unprovenApprovalsStages()
		: unprovenApprovalsStages()
)

/** Senior Overview tab (UXC-7-03): sheet-level status from the server's presentation value. */
const seniorOverviewStatus = computed(() => {
	if (isSheetCompleted.value) return t('pettycash', 'reviews.seniorSheet.reviewCompleted')
	const status = review.value?.sheet.reviewStatus
		?? itemSheet.value?.reviewStatus
		?? openSheetItem.value?.reviewStatus
	return status ?? ABSENT_MARKER
})

const seniorAdapter = computed(() => ({
	stage: 'senior',
	identity: {
		reference: referenceLabel.value,
		eyebrow: t('pettycash', 'reviews.seniorSheet.modal.eyebrow'),
		title: sheetTitleLabel.value,
		owner: ownerLabel.value,
		submitted: submittedLabel.value?.value ?? null,
		status: isSheetCompleted.value
			? t('pettycash', 'reviews.seniorSheet.reviewCompleted')
			: (review.value?.sheet.reviewStatus
				?? itemSheet.value?.reviewStatus
				?? openSheetItem.value?.reviewStatus
				?? null),
		currency: review.value?.sheet.currency ?? null,
		totals: openSheetItem.value?.totals ?? [],
		progress: progressLabel.value !== null
			? { decided: review.value?.progress.decidedBySenior ?? 0, total: review.value?.progress.total ?? 0 }
			: null,
	},
	metrics: {
		transactionCount: openSheetItem.value?.transactionCount ?? null,
	},
	readOnly: isSheetCompleted.value,
	submitting: submitting.value,
}))

function onKeydown(event: KeyboardEvent): void {
	if (openSheetItem.value !== null && event.key === 'Escape' && !submitting.value) {
		// The expanded card, preview and confirmation layers stop their own Escape; reaching this
		// listener means no sub-overlay is open and no expansion holds the event, so the modal
		// itself is the top layer.
		closeSheetModal()
	}
}

/**
 * Dangling-expansion guard (UXC-V2-10 state lifecycle): if the expanded transaction leaves the
 * sheet's server-authoritative set (refreshed per-sheet read, reconciliation), the expansion
 * collapses instead of rendering a stale region against data the server no longer presents.
 */
watch(
	() => entries.value.map((entry) => entry.transaction.id),
	(ids) => {
		if (expandedTransactionId.value !== null && !ids.includes(expandedTransactionId.value)) {
			expandedTransactionId.value = null
		}
	}
)

watch(
	() => openSheetItem.value,
	(next) => {
		if (next) {
			activeOverlay.value = 'none'
			confirmTransactionId.value = ''
			expandedTransactionId.value = null
			confirmDecision.value = null
			submittingTransactionId.value = null
			filter.value = {}
			sort.value = { key: 'expenseDate', direction: 'asc' }
			reviewsStore.resetSeniorExport()
			void loadReview()
			void nextTick(() => {
				// Focus restoration to the originating row is handled by closeSheetModal on close.
				// On open, the shell focuses the dialog itself.
			})
			window.addEventListener('keydown', onKeydown)
		} else {
			window.removeEventListener('keydown', onKeydown)
		}
	}
)

onBeforeUnmount(() => {
	window.removeEventListener('keydown', onKeydown)
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
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	align-items: baseline;
	padding: 0;
	margin-block-end: 10px;
}

.stat {
	display: flex;
	flex-direction: row;
	gap: 8px;
	align-items: center;
	min-inline-size: 0;
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background-color: var(--color-main-background);
}

.stat__top {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 6px;
	width: 100%;
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
	inline-size: 20px;
	block-size: 20px;
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
	font-size: 16px;
	font-weight: 700;
	line-height: 1.15;
	white-space: nowrap;
	overflow-wrap: normal;
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
	color: var(--color-text-maxcontrast);
}

/* Latin isolate: the public-id prefix is a left-to-right identifier even inside an RTL page
   (T030, same policy as the Cost Center baseline's `.sheet-row__reference`). */
:deep(.sheet-row__reference) {
	direction: ltr;
	unicode-bidi: isolate;
	text-align: start;
	font-family: monospace;
	font-size: var(--font-size-floor);
	font-weight: 500;
	overflow-wrap: anywhere;
	color: var(--color-text-maxcontrast);
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
	font-variant-numeric: tabular-nums;
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

:deep(.sheet-row__returned) {
	font-size: var(--font-size-floor);
	font-weight: 400;
	color: var(--color-text-maxcontrast);
	opacity: 0.7;
	margin-block-start: 2px;
	display: block;
	text-align: end;
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

/* Senior transactions-tab toolbar styles (filter + sort), scoped to this view. */
:deep(.senior-transactions-toolbar) {
	display: flex;
	flex-direction: column;
	gap: 14px;
	margin-block-end: 16px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-background-hover);
}

:deep(.senior-filter) {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

:deep(.senior-filter__field) {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

:deep(.senior-filter__label) {
	font-size: var(--font-size-floor);
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

:deep(.senior-filter__input) {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
}

:deep(.senior-filter__input:disabled) {
	opacity: 0.5;
	cursor: not-allowed;
}

:deep(.senior-filter__chips) {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

:deep(.senior-filter__chip) {
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
	cursor: pointer;
}

:deep(.senior-filter__chip--active) {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element);
	color: var(--color-primary-text);
}

:deep(.senior-results) {
	display: flex;
	flex-direction: column;
	gap: 14px;
}

:deep(.senior-filter__count) {
	padding: 2px 8px;
	border-radius: 999px;
	background-color: var(--color-background-hover);
	font-size: var(--font-size-floor);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

:deep(.senior-sort) {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

:deep(.senior-sort__row) {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

:deep(.senior-sort__button) {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
	cursor: pointer;
}

:deep(.senior-sort__direction-icon) {
	flex: 0 0 auto;
}

:deep(.senior-sort__direction-text) {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
	border: 0;
}

:deep(.senior-sort__button--active) {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element);
	color: var(--color-primary-text);
}

:deep(.senior-filter__chip:focus-visible),
:deep(.senior-sort__button:focus-visible) {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

:deep(.senior-export__refused) {
	width: 100%;
	padding: 10px 12px;
	border: 1px solid var(--color-error-border, var(--color-border));
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-error-background, var(--color-background-hover));
}

:deep(.senior-export__refused-title) {
	margin: 0 0 4px;
	font-size: var(--font-size-floor);
	font-weight: 600;
	color: var(--color-error-text);
}

:deep(.senior-export__refused-body),
:deep(.senior-export__reason) {
	margin: 0;
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

:deep(.senior-export__reason) {
	margin-block-start: 4px;
}

/* Desktop: the toolbar reads as two compact rows — filter fields, then results — so the
   proven-complete cards start near the top of the dialog instead of below a tall wall of empty
   filter space. */
@media (width >= 992px) {
	:deep(.senior-transactions-toolbar) {
		gap: 6px;
		padding: 10px 14px;
	}

	:deep(.senior-filter) {
		flex-flow: row wrap;
		align-items: flex-end;
		gap: 6px 16px;
	}

	:deep(.senior-filter__field) {
		gap: 3px;
	}

	:deep(.senior-filter__label) {
		line-height: 1.2;
	}

	:deep(.senior-filter__field--search) {
		flex: 1 1 300px;
		min-width: 260px;
	}

	:deep(.senior-filter__field--types) {
		flex: 2 1 420px;
		min-width: 320px;
	}

	:deep(.senior-filter__chip),
	:deep(.senior-sort__button) {
		min-block-size: 30px;
		padding: 5px 10px;
		border-radius: 999px;
	}

	:deep(.senior-results) {
		flex-flow: row wrap;
		align-items: center;
		justify-content: space-between;
		gap: 10px;
		padding-block-start: 6px;
		border-block-start: 1px solid var(--color-border);
	}

	:deep(.senior-results__actions) {
		display: flex;
		flex: 0 0 auto;
		align-items: center;
		gap: 10px;
		margin-inline-start: auto;
	}

	:deep(.senior-sort) {
		flex-flow: column;
		align-items: flex-start;
		gap: 6px;
	}

	:deep(.senior-sort__row) {
		flex-wrap: nowrap;
	}

	:deep(.senior-transactions) {
		gap: 14px;
	}

	:deep(.senior-transaction) {
		gap: 0;
		min-width: 0;
		border: 1px solid var(--color-border);
		border-radius: 12px;
		overflow: hidden;
		background-color: var(--color-main-background);
	}

	:deep(.senior-transaction .transaction-review-card) {
		border: 0;
		border-radius: 0;
	}
}

/* Mobile touch targets (T030, same ≥44px floor as the Cost Center baseline): the custom filter
   chips and sort buttons are hand-rolled (padding alone is ~28px), so give them a proper tap area
   on narrow screens. */
@media (width <= 640px) {
	:deep(.senior-filter__chip),
	:deep(.senior-sort__button) {
		min-block-size: 44px;
	}

	:deep(.senior-filter__input) {
		min-block-size: 44px;
	}
}

/* Overview tab (UXC-7-03): same definition-list language as the Finance overview. */
.senior-overview {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 12px 16px;
	margin: 0;
}

.senior-overview__item dt {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	font-weight: 600;
}

.senior-overview__item dd {
	margin: 2px 0 0;
	min-inline-size: 0;
	overflow-wrap: anywhere;
	font-weight: 500;
}

.senior-overview__money {
	display: block;
	font-family: 'Noto Sans Mono', monospace;
	font-variant-numeric: tabular-nums;
}

.senior-overview__reference {
	margin-inline-start: 6px;
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

.center-filter {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin-block-start: 8px;
}

.center-filter__label {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	white-space: nowrap;
}

.center-filter__chip {
	padding: 2px 8px;
	border: 1px solid var(--color-border);
	border-radius: 999px;
	background-color: var(--color-main-background);
	font-size: var(--font-size-floor);
	cursor: pointer;
	white-space: nowrap;
	direction: ltr;
	unicode-bidi: isolate;
}

.center-filter__chip--active {
	border-color: var(--color-primary-element);
	background-color: color-mix(in srgb, var(--color-primary-element) 10%, transparent);
	color: var(--color-primary-element);
	font-weight: 600;
}

.center-filter__chip:focus-visible {
    outline: 2px solid var(--color-primary-element);
    outline-offset: 2px;
}

/* Table column sizing (UXC-V2-FU-12): ensure the table uses available width
   and data columns never collapse below a readable floor. Sheet and Purchaser
   flex to fill remaining space. */
:deep(.responsive-table__desktop table) {
    width: 100%;
}

:deep(.responsive-table__desktop th[data-testid="senior-queue-sort-pendingCount"]),
:deep(.responsive-table__desktop td[data-testid="senior-queue-cell-pendingCount"]) {
    min-width: 100px;
}

:deep(.responsive-table__desktop th[data-testid="senior-queue-sort-amount"]),
:deep(.responsive-table__desktop td[data-testid="senior-queue-cell-amount"]) {
    min-width: 130px;
}

:deep(.responsive-table__desktop th[data-testid="senior-queue-sort-status"]),
:deep(.responsive-table__desktop td[data-testid="senior-queue-cell-status"]) {
    min-width: 100px;
}

:deep(.responsive-table__desktop th[data-testid="senior-queue-sort-submitted"]),
:deep(.responsive-table__desktop td[data-testid="senior-queue-cell-submitted"]) {
    min-width: 110px;
}

/* Monetary values stay intact: no wrapping between amount and currency */
:deep(.responsive-table__desktop .sheet-row__money) {
    white-space: nowrap;
}

/* Dates stay readable: no fragment wrapping */
:deep(.responsive-table__desktop .sheet-row__submitted) {
    white-space: nowrap;
}
</style>

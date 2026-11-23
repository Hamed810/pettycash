<template>
	<div class="queue-view" :dir="direction" data-testid="cost-center-queue">
		<nav class="breadcrumbs" :aria-label="t('pettycash', 'common.breadcrumbs')">
			<ol class="breadcrumbs__list">
				<li class="breadcrumbs__item">
					<RouterLink :to="{ name: 'dashboard' }">{{ t('pettycash', 'navigation.dashboard') }}</RouterLink>
				</li>
				<li class="breadcrumbs__item" aria-current="page">{{ t('pettycash', 'reviews.costCenterQueue') }}</li>
			</ol>
		</nav>

		<header class="queue-header">
			<div class="queue-header__intro">
				<p class="queue-header__eyebrow">{{ t('pettycash', 'reviews.sheetQueue.eyebrow') }}</p>
				<h2 class="queue-header__title">
					{{ viewMode === 'sheets' ? t('pettycash', 'reviews.sheetQueue.title') : t('pettycash', 'reviews.sheetQueue.transactionView') }}
				</h2>
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

		<section v-if="viewMode === 'sheets'" class="sheet-panel" data-testid="sheet-queue">
			<div v-if="loading" class="queue-state" data-testid="loading">
				<NcLoadingIcon :size="44" />
				<p>{{ t('pettycash', 'reviews.sheetQueue.loading') }}</p>
			</div>

			<div v-else-if="error" class="queue-state" data-testid="error">
				<NcIconSvgWrapper :path="mdiAlertCircle" :size="44" class="queue-state__icon--error" />
				<p>{{ error }}</p>
				<NcButton data-testid="retry-btn" @click="refresh">
					{{ t('pettycash', 'common.retry') }}
				</NcButton>
			</div>

			<div v-else-if="!completeness.complete" class="queue-state queue-state--unavailable" data-testid="grouping-unavailable">
				<NcIconSvgWrapper :path="mdiAlertCircle" :size="44" class="queue-state__icon--warning" />
				<h3>{{ t('pettycash', 'reviews.sheetQueue.unavailable.title') }}</h3>
				<p class="queue-state__message">{{ t('pettycash', 'reviews.sheetQueue.unavailable.body') }}</p>
				<NcButton data-testid="open-transactions-btn" @click="showTransactions">
					{{ t('pettycash', 'reviews.sheetQueue.unavailable.action') }}
				</NcButton>
			</div>

			<NcEmptyContent
				v-else-if="visibleSheets.length === 0 && searchQuery.trim() === ''"
				:name="t('pettycash', 'reviews.sheetQueue.empty.title')"
				:description="t('pettycash', 'reviews.sheetQueue.empty.body')"
				data-testid="empty-queue"
			/>

			<template v-else>
				<div class="summary" data-testid="queue-summary">
					<article class="stat" data-testid="stat-sheets">
						<div class="stat__top">
							<span class="stat__label">{{ t('pettycash', 'reviews.sheetQueue.stats.sheets') }}</span>
							<span class="stat__icon stat__icon--primary" aria-hidden="true">
								<NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="20" />
							</span>
						</div>
						<strong class="stat__value">{{ formatNumber(awaitingSheetCount) }}</strong>
						<span class="stat__hint stat__hint--accent">
							{{ t('pettycash', 'reviews.sheetQueue.stats.sheetsHint', { count: formatNumber(awaitingTransactionCount) }) }}
						</span>
					</article>

					<article class="stat" data-testid="stat-transactions">
						<div class="stat__top">
							<span class="stat__label">{{ t('pettycash', 'reviews.sheetQueue.stats.transactions') }}</span>
							<span class="stat__icon stat__icon--review" aria-hidden="true">
								<NcIconSvgWrapper :path="mdiClipboardCheckOutline" :size="20" />
							</span>
						</div>
						<strong class="stat__value">{{ formatNumber(awaitingTransactionCount) }}</strong>
						<span class="stat__hint">{{ t('pettycash', 'reviews.sheetQueue.stats.transactionsHint') }}</span>
					</article>
				</div>

			<div class="senior-queue-toolbar" data-testid="senior-queue-toolbar">
						<div class="senior-queue-search" data-testid="senior-queue-search-field">
							<input
								type="search"
								class="senior-queue-search__input"
								:value="searchQuery"
								:placeholder="t('pettycash', 'reviews.sheetQueue.searchPlaceholder')"
								:aria-label="t('pettycash', 'reviews.sheetQueue.searchLabel')"
								data-testid="sheet-search"
								@input="onQueueSearchInput"
							>
							<NcButton
								v-if="searchQuery !== ''"
								variant="tertiary"
								size="small"
								data-testid="senior-queue-search-clear"
								@click="clearQueueSearch"
							>
								{{ t('pettycash', 'reviews.sheetQueue.clearSearch') }}
							</NcButton>
						</div>

						<div
							class="senior-queue-tabs"
							role="group"
							:aria-label="t('pettycash', 'reviews.sheetQueue.tabsLabel')"
							data-testid="senior-queue-tabs"
						>
							<button
								v-for="tab in tabs"
								:key="tab.key"
								type="button"
								class="senior-queue-tabs__tab"
								:class="{ 'senior-queue-tabs__tab--active': sheetStatusTab === tab.key }"
								:data-testid="`sheet-queue-tab-${tab.key}`"
								@click="setQueueTab(tab.key)"
							>
								{{ t('pettycash', tab.labelKey) }}
							</button>
						</div>

						<div class="center-filter" data-testid="center-filter">
							<span class="center-filter__label">
								{{ t('pettycash', 'reviews.sheetQueue.costCenterFilter.label') }}
							</span>
							<button
								v-if="filterableCenters.length > 0"
								type="button"
								class="center-filter__chip"
								:class="{ 'center-filter__chip--active': selectedCenterCode === '' }"
								:aria-pressed="selectedCenterCode === ''"
								data-testid="center-filter-chip-all"
								@click="selectedCenterCode = ''"
							>
								{{ t('pettycash', 'reviews.sheetQueue.costCenterFilter.all') }}
							</button>
							<button
								v-for="center in filterableCenters"
								:key="center.code"
								type="button"
								class="center-filter__chip"
								:class="{ 'center-filter__chip--active': selectedCenterCode === center.code }"
								:aria-pressed="selectedCenterCode === center.code"
								:data-testid="`center-filter-chip-${center.code}`"
								@click="selectedCenterCode = center.code"
							>
								{{ center.name }}
							</button>
						</div>
					</div>

					<div v-if="visibleSheets.length === 0" class="senior-queue-no-results" data-testid="no-search-results">
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
							test-id="sheet-table"
							test-id-prefix="costcenter-queue"
							:row-test-id="sheetRowTestId"
							:card-row-test-id="sheetCardRowTestId"
						/>
					</div>
			</template>
		</section>

		<section v-else class="sheet-panel" data-testid="transaction-queue">
			<div class="sheet-card__header sheet-card__header--actions">
				<NcButton data-testid="back-to-sheets-btn" @click="showSheets">
					{{ t('pettycash', 'reviews.sheetQueue.backToSheets') }}
				</NcButton>
			</div>

			<div v-if="loading" class="queue-state" data-testid="loading-transactions">
				<NcLoadingIcon :size="44" />
				<p>{{ t('pettycash', 'common.loading') }}</p>
			</div>

			<div v-else-if="error" class="queue-state" data-testid="error-transactions">
				<NcIconSvgWrapper :path="mdiAlertCircle" :size="44" class="queue-state__icon--error" />
				<p>{{ error }}</p>
				<NcButton data-testid="retry-transactions-btn" @click="fetchTransactions(currentPage)">
					{{ t('pettycash', 'common.retry') }}
				</NcButton>
			</div>

			<NcEmptyContent
				v-else-if="!hasTransactions"
				:name="t('pettycash', 'reviews.costCenterQueue')"
				:description="t('pettycash', 'reviews.noTransactionsInQueue')"
				data-testid="empty-transactions"
			/>

			<template v-else>
				<ResponsiveTable
					:columns="queueColumns"
					:rows="transactionQueue.items"
					:cell="queueCell"
					:row-key="queueRowKey"
					:caption="queueCaption"
					test-id="queue-table"
					test-id-prefix="costcenter-queue"
					:row-test-id="queueRowTestId"
					:card-row-test-id="queueCardRowTestId"
					:loading="loading"
				/>

				<nav v-if="pageCount > 1" class="queue-pagination" data-testid="queue-pagination">
					<NcButton
						:disabled="currentPage <= 1"
						data-testid="prev-page-btn"
						@click="fetchTransactions(currentPage - 1)"
					>
						{{ t('pettycash', 'common.previous') }}
					</NcButton>
					<span data-testid="page-indicator">{{ currentPage }} / {{ pageCount }}</span>
					<NcButton
						:disabled="currentPage >= pageCount"
						data-testid="next-page-btn"
						@click="fetchTransactions(currentPage + 1)"
					>
						{{ t('pettycash', 'common.next') }}
					</NcButton>
				</nav>
			</template>
		</section>

		<!--
			The shared Sheet Details Modal (UXC-6-01) over the still-mounted queue: Cost Center identity,
			tabs and overlays arrive through the adapter and slots, and the shell owns the canonical
			structure, focus lifecycle and single-overlay rules. The inline sheet expansion is replaced;
			no route navigation and no second full modal.
		-->
		<SheetDetailsModal
			:open="openSheetItem !== null"
			:adapter="costCenterAdapter"
			@close="closeSheetModal"
		>
			<!--
				The manager sheet read is the only authoritative source for the sheet's title, purchaser
				and submission instant; until it answers, the shell renders its honest unavailable states.
			-->
			<template #summary-metrics>
				<div
					v-for="(total, idx) in openSheetItem?.totals ?? []"
					:key="idx"
					class="cc-metric"
				>
					<bdi dir="ltr" class="cc-metric__currency">{{ total.currency }}</bdi>
					<span class="cc-metric__amount">{{ formatMoney(total) }}</span>
				</div>
				<div v-if="openSheetReview" class="cc-metric__progress" data-testid="cc-review-progress">
					{{ t('pettycash', 'reviews.quickReview.progress', {
						decided: formatNumber(openSheetReview.progress.decidedByManager),
						total: formatNumber(openSheetReview.progress.total),
					}) }}
					<span v-if="openSheetReview.progress.returnedForCorrection > 0">
						· {{ t('pettycash', 'reviews.quickReview.returnedCount', {
							count: formatNumber(openSheetReview.progress.returnedForCorrection),
						}) }}
					</span>
				</div>
			</template>

			<template #tab-transactions>
				<div v-if="openSheetItem && isDetailLoading(openSheetItem)" class="cc-review-state" data-testid="sheet-details-modal-loading">
					<NcLoadingIcon :size="28" />
					<p>{{ t('pettycash', 'reviews.quickReview.loading') }}</p>
				</div>

				<!-- One unavailable sheet must not read as an empty one: the failure states stay distinct. -->
				<div v-else-if="openSheetItem && !sheetReview(openSheetItem)" class="cc-review-state cc-review-state--unavailable" data-testid="sheet-details-modal-unavailable">
					<NcIconSvgWrapper :path="mdiAlertCircle" :size="28" class="queue-state__icon--warning" />
					<p>{{ t('pettycash', 'reviews.quickReview.detailsUnavailable') }}</p>
					<p class="cc-review-note">{{ t('pettycash', 'reviews.quickReview.fallbackNote') }}</p>
				</div>

				<template v-else-if="openSheetItem">
					<ul class="cc-transactions" data-testid="transaction-review-cards">
						<li
							v-for="transaction in panelTransactions(openSheetItem)"
							:key="transaction.id"
							:data-testid="`sheet-transaction-${transaction.id}`"
						>
							<!--
								The shared compact review card (UXC-6-02, v2 interaction per UXC-V2-09): summary
								fields, transaction-scoped evidence summary, stage-separated previous-decision
								indicators, always-visible decide bar, and the title that toggles this card's
								Inline Expanded Transaction in place — no second overlay, no route change. Decide
								actions come only from the server's `allowedActions`; a transaction this manager
								already decided keeps no decide bar. The expansion data comes from the same
								guarded per-transaction detail read.
							-->
							<TransactionReviewCard
								:transaction="transaction"
								:evidence="transactionEvidence(transaction.id)"
								:stage-decisions="stageDecisionsFor(transaction)"
								:allowed-actions="transactionDetail(transaction.id)?.allowedActions ?? null"
								:busy="submittingTransactionId === transaction.id"
								:show-actions="transaction.managerState === 'AWAITING_MANAGER_DECISION'"
								expandable
								:expanded="expandedTransactionId === transaction.id"
								:detail="expandedDetail"
								:detail-read-state="expandedReadState"
								:detail-sheet="expandedSheet"
								:detail-stage="inlineStage"
								@toggle-expand="toggleExpansion"
								@open-preview="(evidenceId) => openPreviewFromCard(transaction, evidenceId)"
								@decide="(kind) => openConfirm(transaction, kind)"
							/>
						</li>
					</ul>

					<p v-if="panelTransactions(openSheetItem).length === 0" class="cc-review-note" data-testid="sheet-details-modal-no-transactions">
						{{ t('pettycash', 'reviews.quickReview.noTransactions') }}
					</p>
				</template>
			</template>

			<!--
				Approvals is the sheet-level aggregate surface (never per-transaction history, which lives
				in each transaction's inline expansion): the server's whole-sheet approved totals per
				workflow stage, consumed authoritatively from the manager read (UXC-7-01, UXC-7F-05).
				Progress counts and per-currency sheet amounts remain in the header metrics.
			-->
			<template #tab-approvals>
				<SheetApprovalsSummary :stages="approvalsStages" />
			</template>

			<template #tab-overview>
				<dl v-if="openSheetItem" class="cc-aggregate" data-testid="cc-tab-overview">
					<div class="cc-aggregate__item">
						<dt>{{ t('pettycash', 'reviews.sheetQueue.sheet') }}</dt>
						<dd>
							<span :class="{ 'cc-aggregate__unavailable': !sheetTitleAvailable(openSheetItem) }">
								{{ sheetTitle(openSheetItem) }}
							</span>
							<CompactIdentifier :value="openSheetItem.sheetId" class="cc-aggregate__reference" />
						</dd>
					</div>
					<div class="cc-aggregate__item">
						<dt>{{ t('pettycash', 'reviews.sheetQueue.purchaser') }}</dt>
						<dd>
							{{ purchaserName(openSheetItem) || t('pettycash', 'reviews.sheetQueue.purchaserUnavailable') }}
						</dd>
					</div>
					<div class="cc-aggregate__item">
						<dt>{{ t('pettycash', 'reviews.sheetQueue.submitted') }}</dt>
						<dd>{{ sheetSubmittedLabel(openSheetItem) }}</dd>
					</div>
					<div class="cc-aggregate__item">
						<dt>{{ t('pettycash', 'reviews.costCenter') }}</dt>
						<dd>{{ costCenterLabel(openSheetItem) }}</dd>
					</div>
					<div class="cc-aggregate__item">
						<dt>{{ t('pettycash', 'reviews.sheetQueue.yourTransactions') }}</dt>
						<dd>{{ formatNumber(openSheetItem.transactionCount) }}</dd>
					</div>
					<div class="cc-aggregate__item">
						<dt>{{ t('pettycash', 'reviews.sheetQueue.status') }}</dt>
						<dd>{{ sheetStatusLabel(openSheetItem) }}</dd>
					</div>
				</dl>
			</template>

			<!-- No sheet-level event history exists under the Cost Center reads — honestly empty (UXC-7-03). -->
			<template #tab-history>
				<div class="sheet-details-modal__empty" data-testid="cc-tab-history-empty">
					{{ t('pettycash', 'reviews.sheetHistory.unavailable') }}
				</div>
			</template>

			<!--
				Evidence Preview and Decision Confirmation remain the only overlays above the sheet modal
				(UXC-V2-09): the transaction detail lives in the card's inline expansion, not in a second
				full-height layer. The queue stays mounted behind it.
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
					:transaction="confirmTransaction"
					:expected-version="confirmVersion"
					:allowed-actions="confirmAllowedActions"
					@close="closeOverlay"
					@decided="onDecided"
				/>
			</template>
		</SheetDetailsModal>
	</div>
</template>

<script setup lang="ts">
import { direction } from '@/utils/direction'
import { computed, h, nextTick, onMounted, ref, watch, type VNode } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { t } from '@nextcloud/l10n'
import {
	mdiAlertCircle,
	mdiClipboardCheckOutline,
	mdiFileDocumentOutline,
	mdiRefresh,
} from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import DecisionConfirmation from '@/components/review/DecisionConfirmation.vue'
import TransactionReviewCard, { type TransactionReviewCardStageDecisions } from '@/components/review/TransactionReviewCard.vue'
import SheetApprovalsSummary from '@/components/review/SheetApprovalsSummary.vue'
import SheetDetailsModal, { type SheetDetailsModalAdapter } from '@/components/review/SheetDetailsModal.vue'
import CompactIdentifier from '@/components/review/CompactIdentifier.vue'
import type { TransactionInlineDetailStageConfig } from '@/components/review/TransactionInlineDetail.vue'
import ResponsiveTable from '@/components/shared/ResponsiveTable.vue'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import EvidencePreview from '@/components/evidence/EvidencePreview.vue'
import {
	queueColumns as queueColumnsAround,
	queueCardRowTestId,
	queueRowKey,
	queueRowTestId,
	sharedQueueCell,
	type QueueColumn,
} from '@/components/review/queueColumns'
import { useReviewsStore } from '@/stores/reviews'
import { useAuthStore } from '@/stores/auth'
import { useConfigurationStore } from '@/stores/configuration'
import { formatSheetDate } from '@/utils/date'
import { formatMoney } from '@/utils/money'
import {
	authoritativeStageApprovals,
	unprovenApprovalsStages,
	type ApprovalsStageList,
} from '@/utils/stageApprovals'
import { formatNumber } from '@/utils/number'
import {
	SHEET_QUEUE_PAGE_SIZE,
	buildSheetQueue,
	checkSheetQueueCompleteness,
	searchSheetQueue,
	type SheetAmountTotal,
	type SheetQueueItem,
} from '@/composables/useSheetQueue'
import type { AllowedActions, Decision, DecisionResult, DecisionValue, Evidence, ManagerSheetReview, ManagerSheetSummary, ManagerTransactionReadState, ManagerTransactionSummary, Money, TransactionDetail, TransactionSummary } from '@/types/api'

type SheetTab = 'all' | 'pending' | 'completed'
type DetailState = 'pending' | 'loaded' | 'failed'

type SheetQueueSortKey = 'reference' | 'purchaser' | 'transactions' | 'amount' | 'status' | 'submitted'

interface SheetQueueSortAccess {
	title: string | null
	purchaser: string | null
	amount: { currency: string; amountMinor: number } | null
	status: 'PENDING_REVIEW' | 'REVIEW_COMPLETED' | null
	submitted: string | null
}

const router = useRouter()
const reviewsStore = useReviewsStore()
const sheetTitle = (sheet: SheetQueueItem): string => {
	const title = sheetReview(sheet)?.sheet.title?.trim() ?? ''
	return title !== '' ? title : t('pettycash', 'reviews.sheetQueue.sheetTitleUnavailable')
}

const viewMode = ref<'sheets' | 'transactions'>('sheets')
const searchQuery = ref('')
const selectedCenterCode = ref('')
const openSheetItem = ref<SheetQueueItem | null>(null)
const sheetStatusTab = ref<SheetTab>('all')
/**
 * Per-sheet manager-read state. Kept beside the store's cache so a failed sheet is visible as
 * unavailable instead of being retried on every render, and so the "In progress" count is not
 * published until every sheet has answered.
 */
const detailState = ref<Record<string, DetailState>>({})
const detailRequests = new Map<string, Promise<void>>()

const transactionQueue = computed(() => reviewsStore.costCenterQueue)
const loading = computed(() => reviewsStore.loading)
const error = computed(() => reviewsStore.error)

const completeness = computed(() => checkSheetQueueCompleteness(transactionQueue.value, SHEET_QUEUE_PAGE_SIZE))

const allSheets = computed(() => buildSheetQueue(transactionQueue.value.items))
const awaitingSheetCount = computed(() => allSheets.value.length)
const awaitingTransactionCount = computed(() => allSheets.value.reduce((sum, sheet) => sum + sheet.transactionCount, 0))

const authStore = useAuthStore()
const configurationStore = useConfigurationStore()

/** Cost centers within the current user's authorized management scope, with readable names. */
const filterableCenters = computed(() => {
	const codes = authStore.costCenterCodes
	const byCode = new Map<string, { code: string; name: string }>()
	for (const center of configurationStore.costCenters) {
		const key = center.code || center.id
		if (key) byCode.set(key, { code: key, name: center.name ?? key })
	}
	const assigned: { code: string; name: string }[] = []
	for (const code of codes) {
		const center = byCode.get(code)
		if (center) assigned.push(center)
	}
	return assigned.sort((a, b) => a.name.localeCompare(b.name))
})

const costCentersLoaded = ref(false)

const managerSheetReviews = computed(() => reviewsStore.managerSheetReviews)

/**
 * The manager-scoped read for one queue row, or `null` while it is loading/failed.
 *
 * A `null` result is never treated as "no transactions": the queue's own pending set remains the
 * fallback, so a failed detail read cannot hide work that the manager still has to decide.
 */
function sheetReview(sheet: SheetQueueItem): ManagerSheetReview | null {
	if (sheet.sheetId === '') return null
	const review = managerSheetReviews.value[sheet.sheetId]
	// A malformed payload is treated as unavailable rather than trusted field by field.
	if (!review || !review.sheet || !review.progress) return null
	return review
}

const tabs = computed<Array<{ key: SheetTab; labelKey: string }>>(() => [
	{ key: 'all', labelKey: 'reviews.seniorSheet.tabAll' },
	{ key: 'pending', labelKey: 'reviews.seniorSheet.tabAwaiting' },
	{ key: 'completed', labelKey: 'reviews.seniorSheet.tabCompleted' },
])

const visibleSheets = computed(() => {
	const searched = searchSheetQueue(allSheets.value, searchQuery.value, (sheet) => {
		const review = sheetReview(sheet)
		return [review?.sheet.title, review?.sheet.ownerDisplayName]
	})
	const statusOf = (sheet: SheetQueueItem): 'PENDING_REVIEW' | 'REVIEW_COMPLETED' =>
		panelTransactions(sheet).some((tx) => tx.managerState === 'AWAITING_MANAGER_DECISION')
			? 'PENDING_REVIEW'
			: 'REVIEW_COMPLETED'
	const filtered = sheetStatusTab.value === 'all'
		? searched
		: sheetStatusTab.value === 'completed'
			? searched.filter((s) => statusOf(s) === 'REVIEW_COMPLETED')
			: searched.filter((s) => statusOf(s) !== 'REVIEW_COMPLETED')
	const centerFiltered = selectedCenterCode.value === ''
		? filtered
		: filtered.filter((sheet) => sheet.costCenters.some((center) => center.code === selectedCenterCode.value))
	const key = sheetSortKey.value
	if (key === null) return centerFiltered
	return sortSheetQueueByColumn(centerFiltered, { key, direction: sheetSortDirection.value }, sheetSortAccess)
})

function onQueueSearchInput(event: Event): void {
	searchQuery.value = (event.target as HTMLInputElement).value
}

function clearQueueSearch(): void {
	searchQuery.value = ''
}

function setQueueTab(tab: SheetTab): void {
	sheetStatusTab.value = tab
}

/** Per-sheet the column sort needs, supplied from the row's own fields and the manager read. */
function sheetAmountScalar(sheet: SheetQueueItem): { currency: string; amountMinor: number } | null {
	const totals = selectedCenterCode.value === '' ? sheet.totals : scopedSheetTotals(sheet)
	const min = totals.reduce<{ currency: string; amountMinor: number } | null>((acc, total) => {
		if (acc === null || total.currency < acc.currency) return { currency: total.currency, amountMinor: total.amountMinor }
		return acc
	}, null)
	return min
}

/** Transactions of this sheet scoped to the selected cost center (all when none is selected). */
function scopedSheetTransactions(sheet: SheetQueueItem): TransactionSummary[] {
	if (selectedCenterCode.value === '') return sheet.transactions
	return sheet.transactions.filter((tx) => tx.activeRevision?.costCenter?.code === selectedCenterCode.value)
}

/** Per-currency totals scoped to the selected cost center. */
function scopedSheetTotals(sheet: SheetQueueItem): SheetAmountTotal[] {
	const byCurrency = new Map<string, SheetAmountTotal>()
	for (const tx of scopedSheetTransactions(sheet)) {
		const money = tx.activeRevision?.amount
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

function sortSheetQueueByColumn(
	items: readonly SheetQueueItem[],
	sort: { key: SheetQueueSortKey; direction: 'asc' | 'desc' },
	access: (sheet: SheetQueueItem) => SheetQueueSortAccess
): SheetQueueItem[] {
	const copy = [...items]
	const direction = sort.direction === 'asc' ? 1 : -1

	copy.sort((a, b) => {
		const aa = access(a)
		const ba = access(b)
		let primary = 0

		switch (sort.key) {
			case 'reference': {
				const aValue = aa.title?.trim() !== '' ? aa.title!.trim() : a.sheetId
				const bValue = ba.title?.trim() !== '' ? ba.title!.trim() : b.sheetId
				if (aValue === '' && bValue !== '') { primary = 1; break }
				if (aValue !== '' && bValue === '') { primary = -1; break }
				primary = aValue.localeCompare(bValue)
				break
			}
			case 'purchaser': {
				const aName = aa.purchaser?.trim() ?? ''
				const bName = ba.purchaser?.trim() ?? ''
				if (aName === '' && bName !== '') { primary = 1; break }
				if (aName !== '' && bName === '') { primary = -1; break }
				primary = aName.localeCompare(bName)
				break
			}
			case 'transactions': {
				primary = a.transactionCount - b.transactionCount
				break
			}
			case 'amount': {
				const aAmt = aa.amount
				const bAmt = ba.amount
				if (aAmt === null && bAmt !== null) { primary = 1; break }
				if (aAmt !== null && bAmt === null) { primary = -1; break }
				if (aAmt !== null && bAmt !== null) {
					if (aAmt.amountMinor !== bAmt.amountMinor) primary = aAmt.amountMinor - bAmt.amountMinor
					else if (aAmt.currency !== bAmt.currency) primary = aAmt.currency.localeCompare(bAmt.currency)
				}
				break
			}
			case 'status': {
				const aStatus = aa.status ?? 'PENDING_REVIEW'
				const bStatus = ba.status ?? 'PENDING_REVIEW'
				primary = aStatus.localeCompare(bStatus)
				break
			}
			case 'submitted': {
				const aTime = aa.submitted === null ? null : Date.parse(aa.submitted)
				const bTime = ba.submitted === null ? null : Date.parse(ba.submitted)
				if (aTime === null && bTime === null) primary = 0
				else if (aTime === null) primary = 1
				else if (bTime === null) primary = -1
				else primary = aTime - bTime
				break
			}
		}
		return primary * direction
	})
	return copy
}

/** Column descriptors for the shared ResponsiveTable; mirrors Senior's order. */
function sheetColumns(): { key: string; label: string; sortable: boolean }[] {
	return [
		{ key: 'reference', label: t('pettycash', 'reviews.sheetQueue.sheet'), sortable: true },
		{ key: 'purchaser', label: t('pettycash', 'reviews.sheetQueue.purchaser'), sortable: true },
		{ key: 'transactions', label: t('pettycash', 'reviews.sheetQueue.transactions'), sortable: true },
		{ key: 'amount', label: t('pettycash', 'reviews.amount'), sortable: true },
		{ key: 'status', label: t('pettycash', 'reviews.sheetQueue.status'), sortable: true },
		{ key: 'submitted', label: t('pettycash', 'reviews.sheetQueue.submitted'), sortable: true },
	]
}

function sheetRowKey(sheet: SheetQueueItem): string {
	return sheet.sheetId || 'unresolved'
}

function sheetRowTestId(sheet: SheetQueueItem): string {
	return `sheet-row-${sheet.sheetId || 'unresolved'}`
}

function sheetCardRowTestId(sheet: SheetQueueItem): string {
	return `sheet-card-row-${sheet.sheetId || 'unresolved'}`
}

function sheetCell(sheet: SheetQueueItem, column: { key: string; label: string }): VNode | string | number {
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
						h('span', { class: 'sheet-row__meta' }, costCenterLabel(sheet)),
					]),
				]
			)
		case 'purchaser': {
			const name = purchaserName(sheet)
			const initials = purchaserInitials(sheet)
			return h(
				'span',
				{ class: 'sheet-row__owner', 'data-testid': 'sheet-purchaser' },
				name !== ''
					? [
						initials
							? h('span', { class: 'sheet-row__owner-avatar', 'aria-hidden': 'true' }, initials)
							: null,
						h('span', { class: 'sheet-row__owner-name', 'data-testid': 'sheet-purchaser-name' }, name),
					].filter(Boolean)
					: t('pettycash', 'reviews.sheetQueue.purchaserUnavailable')
			)
		}
		case 'transactions':
			return h('span', { class: 'sheet-row__count', 'data-testid': 'sheet-transaction-count' }, [
				formatNumber(scopedSheetTransactions(sheet).length),
			])
		case 'amount':
			return h('span', { class: 'sheet-row__amount', 'data-testid': 'sheet-amount' }, [
				...scopedSheetTotals(sheet).map((total) =>
					h(
						'span',
						{ key: `${total.currency}-${total.amountMinor}`, class: 'sheet-row__money' },
						formatMoney(total)
					)
				),
			])
		case 'status':
			return h('span', { class: 'sheet-row__status', 'data-testid': 'sheet-status' }, [
				h('span', { 'data-testid': 'sheet-status-badge' }, [
					h(StatusBadge, {
						status: 'COST_CENTER',
						variant: panelTransactions(sheet).some((tx) => tx.managerState === 'AWAITING_MANAGER_DECISION')
							? 'warning'
							: 'success',
						label: panelTransactions(sheet).some((tx) => tx.managerState === 'AWAITING_MANAGER_DECISION')
							? t('pettycash', 'reviews.sheetQueue.awaiting')
							: t('pettycash', 'reviews.seniorSheet.reviewCompleted'),
					}),
				]),
				sheetReturnedCount(sheet) > 0
					? h('span', { class: 'sheet-row__returned' }, sheetReturnedCount(sheet) === 1
						? t('pettycash', 'reviews.sheetQueue.returnedTransactionOne')
						: t('pettycash', 'reviews.sheetQueue.returnedTransactionMany', { count: formatNumber(sheetReturnedCount(sheet)) }))
					: null,
			])
		case 'submitted':
			return h('span', { class: 'sheet-row__submitted', 'data-testid': 'sheet-submitted' }, [
				sheetSubmittedLabel(sheet),
			])
		default:
			return h('span', { 'data-testid': `sheet-unrendered-${column.key}` }, column.key)
	}
}

const currentPage = computed(() => transactionQueue.value.page)
const pageCount = computed(() => Math.max(1, Math.ceil(transactionQueue.value.total / Math.max(1, transactionQueue.value.pageSize))))
const hasTransactions = computed(() => transactionQueue.value.items.length > 0)

const sheetSortKey = ref<SheetQueueSortKey | null>(null)
const sheetSortDirection = ref<'asc' | 'desc'>('asc')

const sheetSort = computed(() =>
	sheetSortKey.value === null ? null : { key: sheetSortKey.value, direction: sheetSortDirection.value }
)

function setSheetSort(key: string): void {
	if (sheetSortKey.value === key) {
		sheetSortDirection.value = sheetSortDirection.value === 'asc' ? 'desc' : 'asc'
		return
	}
	sheetSortKey.value = key as SheetQueueSortKey
	sheetSortDirection.value = 'asc'
}

function sheetSortAccess(sheet: SheetQueueItem): SheetQueueSortAccess {
	return {
		title: sheetTitle(sheet) || null,
		purchaser: purchaserName(sheet) || null,
		amount: sheetAmountScalar(sheet),
		status: panelTransactions(sheet).some((tx) => tx.managerState === 'AWAITING_MANAGER_DECISION')
			? 'PENDING_REVIEW'
			: 'REVIEW_COMPLETED',
		submitted: sheet.latestActivityAt ?? null,
	}
}

async function loadManagerDetail(sheetId: string, force = false): Promise<void> {
	if (sheetId === '') return
	if (!force && (detailState.value[sheetId] === 'loaded' || managerSheetReviews.value[sheetId])) return

	const inFlight = detailRequests.get(sheetId)
	if (inFlight) return inFlight

	detailState.value = { ...detailState.value, [sheetId]: 'pending' }
	const request = (async () => {
		try {
			await reviewsStore.fetchManagerSheetReview(sheetId, force)
			detailState.value = { ...detailState.value, [sheetId]: 'loaded' }
		} catch {
			// One unavailable sheet must not turn the whole queue into an error state.
			detailState.value = { ...detailState.value, [sheetId]: 'failed' }
		} finally {
			detailRequests.delete(sheetId)
		}
	})()

	detailRequests.set(sheetId, request)
	return request
}

/** Bounded prefetch: the queue can hold many sheets and each manager read is a separate request. */
async function prefetchManagerDetails(sheetIds: readonly string[]): Promise<void> {
	const ids = [...new Set(sheetIds.filter((id) => id !== ''))]
	let nextIndex = 0
	const workers = Array.from({ length: Math.min(4, ids.length) }, async () => {
		while (nextIndex < ids.length) {
			const id = ids[nextIndex]
			if (id === undefined) return
			nextIndex += 1
			await loadManagerDetail(id)
		}
	})

	await Promise.all(workers)
}

async function fetchSheets(): Promise<void> {
	try {
		await reviewsStore.fetchCostCenterQueue({
			stage: 'cost-center',
			page: 1,
			pageSize: SHEET_QUEUE_PAGE_SIZE,
		})
		openSheetItem.value = null
		closeOverlay()
		reviewsStore.clearManagerSheetReviews()
		detailState.value = {}
		await prefetchManagerDetails(allSheets.value.map((sheet) => sheet.sheetId))
		void prefetchTransactionDetails(allSheets.value.flatMap((sheet) => sheet.transactions.map((t) => t.id)))
	} catch {
		// The store exposes the failure; the template renders it.
	}
}

async function fetchTransactions(page = 1): Promise<void> {
	try {
		await reviewsStore.fetchCostCenterQueue({
			stage: 'cost-center',
			page,
			pageSize: 25,
		})
	} catch {
		// The store exposes the failure; the template renders it.
	}
}

function refresh(): void {
	if (viewMode.value === 'sheets') {
		void fetchSheets()
	} else {
		void fetchTransactions(currentPage.value)
	}
}

async function showTransactions(): Promise<void> {
	viewMode.value = 'transactions'
	await fetchTransactions(1)
}

async function showSheets(): Promise<void> {
	viewMode.value = 'sheets'
	await fetchSheets()
}

/**
 * Opens the shared Sheet Details Modal over the still-mounted queue (UXC-6-01). The sheet's
 * manager read starts immediately, and its cards' evidence tiles and capability grants come from
 * the per-transaction detail reads, so the bounded prefetch starts with the sheet.
 */
function openSheet(sheet: SheetQueueItem): void {
	if (sheet.sheetId === '') return
	openSheetItem.value = sheet
	void loadManagerDetail(sheet.sheetId)
	void prefetchTransactionDetails(panelTransactions(sheet).map((transaction) => transaction.id))
}

/** Closing restores focus to the originating sheet row, so a keyboard user is not dropped. */
async function closeSheetModal(): Promise<void> {
	const origin = openSheetItem.value?.sheetId ?? null
	openSheetItem.value = null
	if (origin === null || origin === '') return
	await nextTick()
	const row = document.querySelector<HTMLElement>(`[data-testid="sheet-row-${origin}"] .sheet-row__summary`)
	row?.focus()
}

function isDetailLoading(sheet: SheetQueueItem): boolean {
	return sheet.sheetId !== '' && detailState.value[sheet.sheetId] === 'pending'
}

function costCenterLabel(sheet: SheetQueueItem): string {
	if (sheet.costCenters.length === 0) return t('pettycash', 'reviews.sheetQueue.noCostCenter')
	return sheet.costCenters.map((center) => center.code || center.name).filter(Boolean).join(' · ')
}

function sheetTitleAvailable(sheet: SheetQueueItem): boolean {
	return (sheetReview(sheet)?.sheet.title?.trim() ?? '') !== ''
}

function purchaserName(sheet: SheetQueueItem): string {
	return sheetReview(sheet)?.sheet.ownerDisplayName?.trim() ?? ''
}

function purchaserInitials(sheet: SheetQueueItem): string {
	const name = purchaserName(sheet)
	if (name === '') return ''
	return name
		.split(/\s+/)
		.slice(0, 2)
		.map((part) => part.charAt(0))
		.join('')
		.toLocaleUpperCase()
}

function sheetSubmittedAt(sheet: SheetQueueItem): string | null {
	return sheetReview(sheet)?.sheet.submittedAt ?? null
}

function sheetSubmittedLabel(sheet: SheetQueueItem): string {
	const submittedAt = sheetSubmittedAt(sheet)
	return submittedAt === null
		? t('pettycash', 'reviews.sheetQueue.submittedUnavailable')
		: formatSheetDate(submittedAt)
}

function sheetStatusLabel(sheet: SheetQueueItem): string {
	const awaiting = panelTransactions(sheet).some((tx) => tx.managerState === 'AWAITING_MANAGER_DECISION')
	return awaiting
		? t('pettycash', 'reviews.sheetQueue.awaiting')
		: t('pettycash', 'reviews.seniorSheet.reviewCompleted')
}

function sheetReturnedCount(sheet: SheetQueueItem): number {
	let count = 0
	for (const tx of panelTransactions(sheet)) {
		if (tx.managerState !== 'AWAITING_MANAGER_DECISION') continue
		const detail = transactionDetail(tx.id)
		if (!detail) continue
		if (detail.decisions?.some((d) => d.stage === 'COST_CENTER' && d.decision === 'RETURN')) count += 1
	}
	return count
}

/**
 * The panel shows every transaction in this manager's sheet scope once the manager read has
 * answered. While it has not, the queue's proven pending set is the fallback — never an empty list.
 */
function panelTransactions(sheet: SheetQueueItem): ManagerTransactionSummary[] {
	const review = sheetReview(sheet)
	if (review && Array.isArray(review.transactions)) return review.transactions

	return sheet.transactions.map((transaction): ManagerTransactionSummary => ({
		...transaction,
		managerState: 'AWAITING_MANAGER_DECISION',
		managerDecision: null,
	}))
}

function openTransaction(transactionId: string): void {
	router.push({ name: 'review-detail', params: { transactionId, stage: 'cost-center' } })
}

/*
 * T028 — the Batch 4 overlay state machine.
 *
 * Exactly one overlay is ever open: the evidence preview or the decision confirmation. A single
 * `activeOverlay` value is the whole rule — two `v-if`s keyed off separate booleans is how two
 * overlays end up stacked, and the approved design keeps one overlay at a time with each
 * transition replacing the previous layer.
 */
type OverlayState = 'none' | 'preview' | 'confirm'

interface ConfirmSnapshot {
	id: string
	title: string
	sheetReference: string
	amount: Money | null
	expenseDate: string | null
}

const activeOverlay = ref<OverlayState>('none')

const previewTransactionId = ref('')
const previewIndex = ref(0)

/** The one Inline Expanded Transaction (UXC-V2-09); `null` collapses every card. */
const expandedTransactionId = ref<string | null>(null)

const confirmTransaction = ref<ConfirmSnapshot | null>(null)
const confirmDecision = ref<DecisionValue | null>(null)
const confirmVersion = ref(0)
const confirmAllowedActions = ref<AllowedActions | undefined>(undefined)

/** Set while the confirmation for this transaction is in flight, so its card cannot start another. */
const submittingTransactionId = ref<string | null>(null)

const managerTransactionDetails = computed(() => reviewsStore.managerTransactionDetails)
const managerTransactionReads = computed(() => reviewsStore.managerTransactionReads)

function transactionDetail(transactionId: string): TransactionDetail | null {
	return managerTransactionDetails.value[transactionId] ?? null
}

/** The transaction's returned evidence set; an unread or failed read yields none — never a guess. */
function transactionEvidence(transactionId: string): Evidence[] {
	return transactionDetail(transactionId)?.evidence ?? []
}

/**
 * Reads one transaction's detail through the guarded store channel unless a good copy is cached.
 * Returns `null` when the read ends in refusal, absence or failure — callers route to a surface
 * that renders the read state rather than opening an overlay with nothing to show.
 */
async function ensureTransactionDetail(transactionId: string): Promise<TransactionDetail | null> {
	return reviewsStore.fetchManagerTransactionDetail(transactionId)
}

/** Locates a queue transaction (with its sheet row) across every sheet's panel. */
function findManagerTransaction(transactionId: string): { transaction: ManagerTransactionSummary; sheet: SheetQueueItem } | null {
	for (const sheet of allSheets.value) {
		const transaction = panelTransactions(sheet).find((item) => item.id === transactionId)
		if (transaction) return { transaction, sheet }
	}
	return null
}

/**
 * Bounded prefetch of the expanded panel's per-transaction details (evidence + capabilities),
 * mirroring the sheet prefetch's 4-worker pattern so a wide panel cannot flood the OCS endpoint.
 * Guarded store reads: a failed transaction never blocks its neighbours.
 */
async function prefetchTransactionDetails(transactionIds: readonly string[]): Promise<void> {
	const ids = [...new Set(transactionIds.filter((id) => id !== ''))]
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

function closeOverlay(): void {
	activeOverlay.value = 'none'
	submittingTransactionId.value = null
}

// ---------------------------------------------------------------------------
// Inline Expanded Transaction (UXC-V2-09): the drawer's detail surface now lives
// inside the card's disclosure. Exactly one transaction is expanded at a time
// because this view owns the single `expandedTransactionId`; the card only renders
// what it is told. Escape/focus lifecycle belongs to the shared card.
// ---------------------------------------------------------------------------

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
		? managerTransactionReads.value[expandedTransactionId.value] ?? 'loading'
		: 'loading')

/** The sheet header facts for the expanded details; the read answers them honestly or not at all. */
const expandedSheet = computed<ManagerSheetSummary | null>(() => {
	const sheetId = expandedTransactionId.value !== null
		? findManagerTransaction(expandedTransactionId.value)?.sheet.sheetId
		: undefined
	return sheetId !== undefined && sheetId !== '' ? managerSheetReviews.value[sheetId]?.sheet ?? null : null
})

/**
 * The current stage's action surface for the expanded proximity row (UXC-6-03): offered while the
 * transaction still awaits this manager's decision; withheld once decided or returned. Which kinds
 * the server actually grants is gated inside the shared decide row from `allowedActions` — this
 * config adds no rule.
 */
const inlineStage = computed<TransactionInlineDetailStageConfig | null>(() => {
	if (expandedDetail.value === null) return null
	const manager = expandedTransactionId.value !== null
		? findManagerTransaction(expandedTransactionId.value)?.transaction ?? null
		: null
	if (manager && manager.managerState !== 'AWAITING_MANAGER_DECISION') {
		return { actionsVisible: false, actions: [] }
	}
	return { actionsVisible: true, actions: ['REJECT', 'RETURN', 'APPROVE'] }
})

/**
 * Opens the preview from a card tile. The evidence set lives in the per-transaction detail, so a
 * tile on a card whose read failed cannot open an empty overlay: the read runs first and a failure
 * expands the transaction, whose inline region shows the honest read state.
 */
async function openPreviewFromCard(transaction: ManagerTransactionSummary, evidenceId: string): Promise<void> {
	const detail = await ensureTransactionDetail(transaction.id)
	const index = detail?.evidence.findIndex((item) => item.id === evidenceId) ?? -1
	if (detail && index >= 0) {
		previewTransactionId.value = transaction.id
		previewIndex.value = index
		activeOverlay.value = 'preview'
		return
	}
	expandedTransactionId.value = transaction.id
}

function navigatePreview(index: number): void {
	previewIndex.value = index
}

const previewDocuments = computed<Evidence[]>(() => transactionEvidence(previewTransactionId.value))
const previewEvidence = computed<Evidence | null>(() => previewDocuments.value[previewIndex.value] ?? null)

/** The manager read for the open sheet, or `null` while it is loading/failed. */
const openSheetReview = computed<ManagerSheetReview | null>(() =>
	openSheetItem.value !== null ? sheetReview(openSheetItem.value) : null)

/**
 * Sheet-level approved monetary totals per workflow stage (UXC-7-01), from the server's
 * whole-sheet `stageApprovedTotals` on the manager read (UXC-7F-05, owner decision 2026-11-20) —
 * never per-transaction decision history, which stays in each transaction's inline expansion, and
 * never a client-side sum over this manager's scoped transaction subset presented as a whole-sheet
 * figure.
 *
 * With the read missing (failed or still loading) or the field absent/malformed (old payload),
 * every stage renders honestly unavailable. A decoded zero total is a real answer, rendered as a
 * known zero.
 */
const approvalsStages = computed<ApprovalsStageList>(() =>
	openSheetReview.value !== null
		? authoritativeStageApprovals(openSheetReview.value.sheet.stageApprovedTotals) ?? unprovenApprovalsStages()
		: unprovenApprovalsStages()
)

/**
 * The Cost Center stage adapter for the shared shell (UXC-6-01): authoritative identity from the
 * manager sheet read — title, purchaser and submission instant are honest-unavailable until that
 * read answers — plus the sheet's per-currency amounts and the manager's progress aggregate.
 */
const costCenterAdapter = computed<SheetDetailsModalAdapter>(() => {
	const sheet = openSheetItem.value
	const review = openSheetReview.value
	return {
		stage: 'cost-center',
		identity: {
			reference: sheet?.sheetId ?? null,
			eyebrow: t('pettycash', 'reviews.quickReview.eyebrow'),
			title: review?.sheet.title?.trim() || null,
			owner: review?.sheet.ownerDisplayName?.trim() || null,
			submitted: review?.sheet.submittedAt ? formatSheetDate(review.sheet.submittedAt) : null,
			status: sheet ? sheetStatusLabel(sheet) : null,
			currency: review?.sheet.currency ?? null,
			totals: sheet?.totals ?? [],
			progress: review && review.progress.total > 0
				? { decided: review.progress.decidedByManager, total: review.progress.total }
				: null,
		},
		metrics: { transactionCount: sheet?.transactionCount ?? null },
		readOnly: false,
		submitting: submittingTransactionId.value !== null,
	}
})

/**
 * Per-stage previous decisions for the card's indicators, derived only from authoritative data:
 * the transaction's decisions (detail read), falling back to this manager's own decision for the
 * Cost Center stage and the summary's `latestDecision` for its stage. A stage with no known
 * decision stays `null` — the indicator then reads "No decision yet".
 */
function stageDecisionsFor(transaction: ManagerTransactionSummary): TransactionReviewCardStageDecisions {
	const decisions = transactionDetail(transaction.id)?.decisions ?? []
	const latestForStage = (stage: Decision['stage']): Decision | null => {
		const matches = decisions.filter((decision) => decision.stage === stage)
		return matches.length > 0 ? matches[matches.length - 1]! : null
	}
	const fallbackForStage = (stage: Decision['stage']): Decision | null => {
		if (stage === 'COST_CENTER' && transaction.managerDecision) return transaction.managerDecision
		return transaction.latestDecision?.stage === stage ? transaction.latestDecision : null
	}
	return {
		costCenter: latestForStage('COST_CENTER') ?? fallbackForStage('COST_CENTER'),
		senior: latestForStage('SENIOR') ?? fallbackForStage('SENIOR'),
		finance: latestForStage('FINANCE') ?? fallbackForStage('FINANCE'),
	}
}

/**
 * Dangling-expansion guard (UXC-V2-09 state lifecycle): if the expanded transaction leaves the
 * server-authoritative set (refreshed sheet read, reconciliation), the expansion collapses instead
 * of rendering a stale region against data the server no longer presents.
 */
watch(
	() => openSheetItem.value !== null ? panelTransactions(openSheetItem.value).map((transaction) => transaction.id) : [],
	(ids) => {
		if (expandedTransactionId.value !== null && !ids.includes(expandedTransactionId.value)) {
			expandedTransactionId.value = null
		}
	}
)

// Opening a sheet resets the overlay stack and the expansion (preserved single-overlay behaviour
// from the inline panel) so a stale expansion or confirmation cannot survive into the next sheet's
// context.
watch(
	() => [openSheetItem.value !== null, openSheetItem.value?.sheetId ?? ''] as const,
	([open]) => {
		if (open) {
			activeOverlay.value = 'none'
			expandedTransactionId.value = null
			previewTransactionId.value = ''
			previewIndex.value = 0
			submittingTransactionId.value = null
		}
	}
)

/** Card → confirmation. The detail read supplies the version and capability grant. */
function openConfirm(transaction: ManagerTransactionSummary, kind: DecisionValue): void {
	openConfirmForTransaction(transaction.id, kind)
}

async function openConfirmForTransaction(transactionId: string, kind: DecisionValue): Promise<void> {
	// One submission at a time for this transaction: a second confirmation cannot open while the
	// first is in flight (FR-085 first-write-wins is enforced server-side, but the duplicate
	// request should never leave the browser).
	if (submittingTransactionId.value !== null) return

	const detail = await ensureTransactionDetail(transactionId)
	if (detail === null) {
		// Without the detail there is neither a version to be optimistic against nor a capability
		// grant to honour — the inline expansion says what happened instead of a modal that cannot
		// submit.
		expandedTransactionId.value = transactionId
		return
	}

	const found = findManagerTransaction(transactionId)
	const summary = found?.transaction ?? null
	const description = detail.activeRevision?.description?.trim() ?? ''
	confirmTransaction.value = {
		id: transactionId,
		title: description !== '' ? description : t('pettycash', 'reviews.quickReview.card.noDescription'),
		sheetReference: summary?.sheetId ?? detail.sheetId,
		amount: detail.activeRevision?.amount ?? null,
		expenseDate: detail.activeRevision?.expenseDate ?? null,
	}
	confirmDecision.value = kind
	confirmVersion.value = detail.version
	confirmAllowedActions.value = detail.allowedActions
	submittingTransactionId.value = transactionId
	activeOverlay.value = 'confirm'
}

/**
 * Decision recorded: close the confirmation, collapse the expansion (the deterministic post-decision
 * presentation), refresh the sheet read (progress counts and the
 * card's decided state come from the server, not from a client-side patch), drop the now-stale per-
 * transaction detail, and re-read it in the background so the card keeps its evidence row and the
 * expansion its history. The toast words come from the approved mockup's completion state.
 */
async function onDecided(result: DecisionResult): Promise<void> {
	const transactionId = confirmTransaction.value?.id ?? result.transaction.id
	const sheetId = findManagerTransaction(transactionId)?.sheet.sheetId ?? result.transaction.sheetId

	activeOverlay.value = 'none'
	submittingTransactionId.value = null
	expandedTransactionId.value = null
	reviewsStore.clearManagerTransactionDetail(transactionId)

	void (async () => {
		try {
			await reviewsStore.fetchManagerSheetReview(sheetId, true)
		} catch {
			// The refreshed sheet read is preferred but not required for the card to show its
			// decision; the store exposes a failure and the queue keeps its previous state.
		}
		void reviewsStore.fetchManagerTransactionDetail(transactionId, true)
	})()

	try {
		const { showSuccess } = await import('@nextcloud/dialogs')
		showSuccess(t('pettycash', 'reviews.quickReview.decision.toastText'))
	} catch {
		// The decision is recorded and the panel refreshed; a toast is presentation, not evidence.
	}
}

onMounted(() => {
	void fetchSheets()
	// Cost-center options must not depend on visiting /admin/configuration.
	// configurationStore.costCenters is populated there, but the Cost Center queue must load
	// its assigned centers independently. listCostCenters() is #[NoAdminRequired] and
	// caller-scoped; filterableCenters further narrows to authStore.costCenterCodes.
	if (!configurationStore.costCenters.length && !costCentersLoaded.value) {
		costCentersLoaded.value = true
		void configurationStore.loadCostCenters().catch(() => {
			// Error is stored in configurationStore.error; view renders without chips.
		})
	}
})

const QUEUE_OWN_COLUMN: QueueColumn = {
	key: 'stage',
	label: t('pettycash', 'reviews.stage'),
}

const queueColumns = computed(() => queueColumnsAround(QUEUE_OWN_COLUMN, 4))

const queueCaption = computed(() => t('pettycash', 'reviews.costCenterQueue'))

function queueCell(item: TransactionSummary, column: QueueColumn): VNode | string | number {
	if (column.key === QUEUE_OWN_COLUMN.key) {
		return h('span', { 'data-testid': 'queue-stage' }, t('pettycash', `reviews.stage.${item.stage.toLowerCase()}`))
	}

	return sharedQueueCell(item, column, openTransaction)
}
</script>

<style scoped>
.queue-view {
	display: flex;
	flex-direction: column;
	gap: 16px;
	inline-size: 100%;
	min-inline-size: 0;
}

.breadcrumbs__list {
	display: flex;
	flex-wrap: wrap;
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
	gap: 8px;
	flex-wrap: wrap;
	margin-block-end: 6px;
}

.queue-header__eyebrow {
	margin: 0 0 4px;
	font-size: var(--font-size-floor);
	font-weight: 700;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	color: var(--color-primary-element);
}

.queue-header__title {
	margin: 0;
	font-size: var(--font-size-title);
	font-weight: 700;
	line-height: 1.2;
}

.queue-header__aside {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.role-badge {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 6px 12px;
	border: 1px solid color-mix(in srgb, var(--color-success) 30%, transparent);
	border-radius: 999px;
	background-color: color-mix(in srgb, var(--color-success) 10%, transparent);
	color: var(--color-success-text, var(--color-success));
	font-weight: 600;
	white-space: nowrap;
}

.role-badge__dot {
	inline-size: 8px;
	block-size: 8px;
	border-radius: 50%;
	background-color: currentcolor;
}

.scope {
	display: flex;
	align-items: center;
	justify-content: flex-end;
	gap: 8px;
	flex-wrap: wrap;
	margin-inline-start: auto;
	min-inline-size: 0;
}

.scope__label {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	white-space: nowrap;
}

.scope__list {
	display: flex;
	gap: 6px;
	flex-wrap: wrap;
	margin: 0;
	padding: 0;
	list-style: none;
}

.scope__item {
	padding: 2px 8px;
	border: 1px solid var(--color-border);
	border-radius: 999px;
	background-color: var(--color-main-background);
	font-family: monospace;
	font-size: var(--font-size-floor);
	direction: ltr;
	unicode-bidi: isolate;
}

.summary {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: baseline;
	padding: 0;
}

.stat {
	display: flex;
	flex-direction: row;
	gap: 6px;
	align-items: center;
	min-inline-size: 0;
	padding: 3px 8px;
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
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	font-weight: 600;
	line-height: 1.35;
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
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	line-height: 1.4;
}

.stat__hint--accent {
	display: flex;
	align-items: center;
	gap: 6px;
}

.stat__hint--accent::before {
	content: '';
	flex: none;
	inline-size: 6px;
	block-size: 6px;
	border-radius: 50%;
	background-color: var(--color-success);
}

.sheet-panel {
	min-inline-size: 0;
}

.sheet-card {
	margin-block-start: 4px;
}

.sheet-card__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 8px;
	flex-wrap: wrap;
	margin-block-end: 6px;
}

.sheet-card__header--actions {
	justify-content: flex-end;
}

.sheet-card__title {
	margin: 0;
	font-size: var(--font-size-section);
	font-weight: 700;
}

.sheet-card__description {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.sheet-card__count {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	white-space: nowrap;
}

.sheet-card__count::before {
	content: '';
	inline-size: 8px;
	block-size: 8px;
	border-radius: 50%;
	background-color: var(--color-success);
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

@media (width <= 640px) {
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
}

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

:deep(.sheet-row__title-button) {
	max-width: 100%;
	min-width: 0;
	font-size: var(--font-size-floor);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

:deep(.sheet-row__meta-line) {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
	min-inline-size: 0;
}

:deep(.sheet-row__reference) {
	font-family: monospace;
	font-size: var(--font-size-floor);
	font-weight: 500;
	direction: ltr;
	unicode-bidi: isolate;
	text-align: start;
	overflow-wrap: anywhere;
}

:deep(.sheet-row__meta) {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	overflow-wrap: anywhere;
}

:deep(.sheet-row__meta::before) {
	content: '·';
	margin-inline-end: 6px;
	color: var(--color-text-maxcontrast);
}

.sheet-row__metrics {
	display: contents;
}

.metric {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-inline-size: 0;
}

.metric--numeric {
	align-items: flex-end;
	text-align: end;
}

.metric__label {
	display: none;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.metric__value {
	font-weight: 600;
	overflow-wrap: anywhere;
}

.metric__value--money {
	font-family: 'Noto Sans Mono', monospace;
	font-variant-numeric: tabular-nums;
}

.metric__value--muted {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	font-weight: 400;
}

.sheet-row__returned {
	font-size: var(--font-size-floor);
	font-weight: 400;
	color: var(--color-text-maxcontrast);
	opacity: 0.7;
	margin-block-start: 2px;
}

.sheet-row__owner {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
	overflow: hidden;
	font-size: var(--font-size-floor);
}

.sheet-row__owner-avatar {
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

.sheet-row__owner-name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.cc-metric {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

.cc-metric__currency {
	font-weight: 600;
}

.cc-metric__amount {
	font-family: 'Noto Sans Mono', monospace;
	font-variant-numeric: tabular-nums;
}

.cc-metric__progress {
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

.cc-transactions {
	display: flex;
	flex-direction: column;
	gap: 10px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.cc-review-state {
	display: grid;
	grid-template-columns: auto minmax(0, 1fr);
	align-items: center;
	gap: 4px 10px;
	padding: 12px 14px;
	border: 1px dashed var(--color-border);
	border-radius: 10px;
	color: var(--color-text-maxcontrast);
}

.cc-review-state--unavailable {
	border-color: color-mix(in srgb, var(--color-warning) 35%, var(--color-border));
	background-color: color-mix(in srgb, var(--color-warning) 6%, var(--color-main-background));
}

.cc-review-state p {
	margin: 0;
}

.cc-review-note {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.cc-aggregate {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 12px 16px;
	margin: 0;
}

.cc-aggregate__item dt {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	font-weight: 600;
}

.cc-aggregate__item dd {
	margin: 2px 0 0;
	min-inline-size: 0;
	overflow-wrap: anywhere;
	font-weight: 500;
}

.cc-aggregate__money {
	display: block;
	font-family: 'Noto Sans Mono', monospace;
	font-variant-numeric: tabular-nums;
}

.cc-aggregate__reference {
	display: block;
	font-family: monospace;
	font-size: var(--font-size-floor);
	direction: ltr;
	unicode-bidi: isolate;
}

.cc-aggregate__unavailable {
	color: var(--color-text-maxcontrast);
}

.sheet-card__footnote {
	margin: 12px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.queue-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 10px;
	padding: 48px 16px;
	text-align: center;
}

.queue-state--unavailable {
	border: 1px solid color-mix(in srgb, var(--color-warning) 35%, var(--color-border));
	border-radius: 14px;
	background-color: color-mix(in srgb, var(--color-warning) 6%, var(--color-main-background));
}

.queue-state--empty {
	padding: 32px 16px;
}

.queue-state h3 {
	margin: 0;
	font-size: var(--font-size-emphasis);
}

.queue-state__message {
	margin: 0;
	max-inline-size: 68ch;
	color: var(--color-text-maxcontrast);
}

.queue-state__icon--error {
	color: var(--color-error-text);
}

.queue-state__icon--warning {
	color: var(--color-warning-text, var(--color-warning));
}

.queue-pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-block-start: 16px;
}

@media (width <= 1100px) {
	.summary {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	.toolbar {
		grid-template-columns: minmax(0, 1fr) auto;
	}

	.tabs {
		order: 3;
		grid-column: 1 / -1;
		inline-size: max-content;
		max-inline-size: 100%;
		flex-wrap: wrap;
	}

	.sheet-list__head,
	.sheet-row__summary {
		gap: 10px;
	}
}

@media (width <= 900px) {
	.sheet-list__head {
		display: none;
	}

	.sheet-row__summary {
		grid-template-columns: minmax(0, 1fr) 24px;
		grid-template-areas:
			'identity chevron'
			'metrics metrics';
		gap: 12px;
		align-items: start;
	}

	.sheet-row__identity {
		grid-area: identity;
	}

	.sheet-row__chevron {
		grid-area: chevron;
		padding-block-start: 4px;
	}

	.sheet-row__metrics {
		display: grid;
		grid-column: 1 / -1;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 10px 16px;
	}

	.metric {
		flex-direction: column;
		align-items: flex-start;
		justify-content: flex-start;
		gap: 2px;
	}

	.metric__label {
		display: block;
	}

	.metric--purchaser {
		grid-column: 1 / -1;
		padding-block-end: 2px;
	}

	.metric--purchaser .metric__label {
		display: none;
	}

	.metric--numeric {
		align-items: flex-start;
		text-align: start;
	}
}

@media (width <= 640px) {
	.queue-view {
		gap: 16px;
	}

	.summary {
		gap: 10px;
	}

	.stat {
		padding: 14px;
	}

	.stat__value {
		font-size: var(--font-size-title);
	}

	.sheet-card {
		padding: 14px;
	}

	.toolbar {
		grid-template-columns: minmax(0, 1fr);
	}

	.tabs {
		grid-column: 1;
		inline-size: 100%;
	}

	.tab {
		/* T036: the approved 390px floor — fan the tabs wide AND keep each tap area ≥44px tall. */
		flex: 1 1 auto;
		justify-content: center;
		min-block-size: 44px;
	}

	/* T036: keep the queue search a comfortable tap target on the 390px floor. */
	.search__input {
		min-block-size: 44px;
	}

	.sort {
		flex-direction: column;
		align-items: stretch;
		gap: 4px;
	}

	.sort__select {
		inline-size: 100%;
		min-inline-size: 0;
	}

	.sheet-row__summary {
		padding: 12px 4px;
	}

	.sheet-row__owner-avatar {
		inline-size: 26px;
		block-size: 26px;
	}

	.scope__item {
		font-size: var(--font-size-floor);
	}

	.cc-transactions {
		gap: 8px;
	}
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

/* Table column sizing (UXC-V2-FU-15 / UXC-V2-FU-15-VERIFY): deterministic column widths eliminate
   the initial-load horizontal shift. Root cause: async manager sheet reads (titles, purchaser
   names, submitted dates) change cell content after the first table render; table-layout: auto
   recalculates column widths on every content change, producing visible column movement.
   table-layout: fixed freezes column geometry from the first paint; content wraps or clips
   inside its column instead of resizing it. */
:deep(.responsive-table__desktop table) {
	width: 100%;
	table-layout: fixed;
}

:deep(.responsive-table__desktop th[data-testid="costcenter-queue-sort-reference"]),
:deep(.responsive-table__desktop td[data-testid="costcenter-queue-cell-reference"]) {
	min-width: 180px;
}

:deep(.responsive-table__desktop th[data-testid="costcenter-queue-sort-purchaser"]),
:deep(.responsive-table__desktop td[data-testid="costcenter-queue-cell-purchaser"]) {
	min-width: 140px;
}

:deep(.responsive-table__desktop th[data-testid="costcenter-queue-sort-transactions"]),
:deep(.responsive-table__desktop td[data-testid="costcenter-queue-cell-transactions"]) {
	min-width: 100px;
}

:deep(.responsive-table__desktop th[data-testid="costcenter-queue-sort-amount"]),
:deep(.responsive-table__desktop td[data-testid="costcenter-queue-cell-amount"]) {
	min-width: 130px;
}

:deep(.responsive-table__desktop th[data-testid="costcenter-queue-sort-status"]),
:deep(.responsive-table__desktop td[data-testid="costcenter-queue-cell-status"]) {
	min-width: 100px;
}

:deep(.responsive-table__desktop th[data-testid="costcenter-queue-sort-submitted"]),
:deep(.responsive-table__desktop td[data-testid="costcenter-queue-cell-submitted"]) {
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

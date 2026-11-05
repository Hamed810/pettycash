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
				<p class="queue-header__subtitle">
					{{ viewMode === 'sheets' ? t('pettycash', 'reviews.sheetQueue.subtitle') : t('pettycash', 'reviews.sheetQueue.transactionViewHint') }}
				</p>
			</div>
			<div class="queue-header__aside">
				<span class="role-badge" data-testid="role-badge">
					<span class="role-badge__dot" aria-hidden="true" />
					{{ t('pettycash', 'reviews.sheetQueue.roleBadge') }}
				</span>
				<NcButton v-if="viewMode === 'sheets'" data-testid="show-transactions-btn" @click="showTransactions">
					{{ t('pettycash', 'reviews.sheetQueue.showTransactions') }}
				</NcButton>
				<NcButton :disabled="loading" data-testid="refresh-btn" @click="refresh">
					<template #icon>
						<NcIconSvgWrapper :path="mdiRefresh" />
					</template>
					{{ t('pettycash', 'common.refresh') }}
				</NcButton>
			</div>
		</header>

		<section class="responsibility" data-testid="responsibility-banner">
			<span class="responsibility__icon" aria-hidden="true">
				<NcIconSvgWrapper :path="mdiShieldCheckOutline" :size="20" />
			</span>
			<div class="responsibility__body">
				<h3 class="responsibility__title">{{ t('pettycash', 'reviews.sheetQueue.banner.title') }}</h3>
				<p class="responsibility__text">{{ t('pettycash', 'reviews.sheetQueue.banner.body') }}</p>
			</div>
			<div v-if="managedCostCenters.length > 0" class="scope" data-testid="cost-center-scope">
				<span class="scope__label">{{ t('pettycash', 'reviews.sheetQueue.scopeLabel') }}</span>
				<ul class="scope__list">
					<li v-for="code in managedCostCenters" :key="code" class="scope__item">{{ code }}</li>
				</ul>
			</div>
		</section>

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
				v-else-if="sheetItems.length === 0 && searchQuery.trim() === ''"
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

				<div class="sheet-card">
					<div class="sheet-card__header">
						<div>
							<h3 class="sheet-card__title">{{ t('pettycash', 'reviews.sheetQueue.yourSheets') }}</h3>
							<p class="sheet-card__description">{{ t('pettycash', 'reviews.sheetQueue.description') }}</p>
						</div>
						<span class="sheet-card__count" data-testid="sheet-count">
							{{ t('pettycash', 'reviews.sheetQueue.queueCount', { count: formatNumber(sheetItems.length) }) }}
						</span>
					</div>

					<div class="toolbar">
						<label class="search">
							<span class="search__icon" aria-hidden="true">
								<NcIconSvgWrapper :path="mdiMagnify" :size="18" />
							</span>
							<input
								v-model="searchQuery"
								class="search__input"
								type="search"
								:placeholder="t('pettycash', 'reviews.sheetQueue.searchPlaceholder')"
								:aria-label="t('pettycash', 'reviews.sheetQueue.searchLabel')"
								data-testid="sheet-search"
							>
						</label>

						<div class="tabs" role="tablist" :aria-label="t('pettycash', 'reviews.sheetQueue.tabsLabel')">
							<button
								v-for="tab in tabs"
								:key="tab.key"
								type="button"
								role="tab"
								class="tab"
								:class="{ 'tab--active': activeTab === tab.key }"
								:aria-selected="activeTab === tab.key"
								:data-testid="`tab-${tab.key}`"
								@click="activeTab = tab.key"
							>
								{{ tab.label }}
								<span v-if="tab.count !== null" class="tab__count">{{ formatNumber(tab.count) }}</span>
							</button>
						</div>

						<label class="sort">
							<span class="sort__label">{{ t('pettycash', 'reviews.sheetQueue.sortLabel') }}</span>
							<NcSelect
								:model-value="sort"
								:options="sortOptions"
								:reduce="selectOptionValue"
								:get-option-key="selectOptionKey"
								:clearable="false"
								:dir="direction"
								:input-label="t('pettycash', 'reviews.sheetQueue.sortLabel')"
								label-outside
								class="sort__select"
								data-testid="sheet-sort"
								@update:model-value="onSortChange"
							/>
						</label>
					</div>

					<p class="availability-note" data-testid="manager-scope-note">
						{{ t('pettycash', 'reviews.sheetQueue.scopeNote') }}
					</p>

					<div v-if="sheetItems.length === 0" class="queue-state queue-state--empty" data-testid="no-search-results">
						<p>{{ t('pettycash', 'reviews.sheetQueue.noResults') }}</p>
						<NcButton @click="searchQuery = ''">{{ t('pettycash', 'reviews.sheetQueue.clearSearch') }}</NcButton>
					</div>

					<template v-else>
						<div class="sheet-list__head" aria-hidden="true">
							<span>{{ t('pettycash', 'reviews.sheetQueue.sheet') }}</span>
							<span>{{ t('pettycash', 'reviews.sheetQueue.purchaser') }}</span>
							<span class="sheet-list__head--numeric">{{ t('pettycash', 'reviews.sheetQueue.yourTransactions') }}</span>
							<span class="sheet-list__head--numeric">{{ t('pettycash', 'reviews.sheetQueue.relevantAmount') }}</span>
							<span>{{ t('pettycash', 'reviews.sheetQueue.status') }}</span>
							<span class="sheet-list__head--numeric">{{ t('pettycash', 'reviews.sheetQueue.submitted') }}</span>
							<span />
						</div>

						<ul class="sheet-list" data-testid="sheet-list">
							<li
								v-for="sheet in sheetItems"
								:key="sheet.sheetId || 'unresolved'"
								class="sheet-row"
								:data-testid="`sheet-row-${sheet.sheetId || 'unresolved'}`"
							>
								<button
									type="button"
									class="sheet-row__summary"
									:aria-expanded="expandedSheetId === sheet.sheetId"
									:aria-controls="detailId(sheet)"
									:aria-label="expandedSheetId === sheet.sheetId
										? t('pettycash', 'reviews.sheetQueue.collapse', { reference: sheetReference(sheet) })
										: t('pettycash', 'reviews.sheetQueue.expand', { reference: sheetReference(sheet) })"
									@click="toggleSheet(sheet.sheetId)"
								>
									<span class="sheet-row__identity">
										<span class="sheet-row__icon" aria-hidden="true">
											<NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="20" />
										</span>
										<span class="sheet-row__text">
											<span
												class="sheet-row__title"
												:class="{ 'sheet-row__title--unavailable': !sheetTitleAvailable(sheet) }"
												data-testid="sheet-title"
												:title="sheetTitleAvailable(sheet) ? sheetTitle(sheet) : t('pettycash', 'reviews.sheetQueue.unavailableField')"
											>
												{{ sheetTitle(sheet) }}
											</span>
											<span class="sheet-row__meta-line">
												<span class="sheet-row__reference" data-testid="sheet-reference" :title="sheet.sheetId || undefined">
													{{ shortenedReference(sheet) }}
												</span>
												<span class="sheet-row__meta">{{ costCenterLabel(sheet) }}</span>
											</span>
										</span>
									</span>

									<span class="sheet-row__metrics">
										<span class="metric metric--purchaser" data-testid="sheet-purchaser">
											<span class="metric__label">{{ t('pettycash', 'reviews.sheetQueue.purchaser') }}</span>
											<span class="purchaser">
												<span v-if="purchaserInitials(sheet) !== ''" class="purchaser__avatar purchaser__avatar--named" aria-hidden="true">
													{{ purchaserInitials(sheet) }}
												</span>
												<span v-else class="purchaser__avatar" aria-hidden="true">
													<NcIconSvgWrapper :path="mdiAccountOutline" :size="16" />
												</span>
												<span
													class="purchaser__name"
													:class="{ 'purchaser__name--unavailable': !purchaserName(sheet) }"
													:title="purchaserName(sheet) || t('pettycash', 'reviews.sheetQueue.unavailableField')"
													data-testid="sheet-purchaser-name"
												>
													{{ purchaserName(sheet) || t('pettycash', 'reviews.sheetQueue.purchaserUnavailable') }}
												</span>
											</span>
										</span>
										<span class="metric metric--numeric" data-testid="sheet-transaction-count">
											<span class="metric__label">{{ t('pettycash', 'reviews.sheetQueue.yourTransactions') }}</span>
											<span class="metric__value">{{ formatNumber(sheet.transactionCount) }}</span>
										</span>
										<span class="metric metric--numeric" data-testid="sheet-amount">
											<span class="metric__label">{{ t('pettycash', 'reviews.sheetQueue.relevantAmount') }}</span>
											<span v-for="total in sheet.totals" :key="total.currency" class="metric__value metric__value--money">
												{{ formatMoney(total) }}
											</span>
										</span>
										<span class="metric" data-testid="sheet-status">
											<span class="metric__label">{{ t('pettycash', 'reviews.sheetQueue.status') }}</span>
											<StatusBadge status="COST_CENTER" :label="sheetStatusLabel(sheet)" />
										</span>
										<span class="metric metric--numeric metric--submitted" data-testid="sheet-submitted">
											<span class="metric__label">{{ t('pettycash', 'reviews.sheetQueue.submitted') }}</span>
											<span
												class="metric__value"
												:class="{ 'metric__value--muted': !sheetSubmittedAt(sheet) }"
												:title="sheetSubmittedAt(sheet) ? undefined : t('pettycash', 'reviews.sheetQueue.unavailableField')"
											>
												{{ sheetSubmittedLabel(sheet) }}
											</span>
										</span>
									</span>

									<span class="sheet-row__chevron" aria-hidden="true">
										<NcIconSvgWrapper :path="expandedSheetId === sheet.sheetId ? mdiChevronUp : mdiChevronDown" :size="20" />
									</span>
								</button>

								<div
									v-if="expandedSheetId === sheet.sheetId"
									:id="detailId(sheet)"
									class="sheet-row__detail quick-review"
									:data-testid="`sheet-detail-${sheet.sheetId || 'unresolved'}`"
								>
									<header class="quick-review__head">
										<div class="quick-review__heading">
											<p class="quick-review__eyebrow">{{ t('pettycash', 'reviews.quickReview.eyebrow') }}</p>
											<h3 class="quick-review__title">
												<span
													:class="{ 'quick-review__title--unavailable': !sheetTitleAvailable(sheet) }"
													data-testid="quick-review-sheet-title"
												>
													{{ sheetTitle(sheet) }}
												</span>
												<bdi class="quick-review__reference" data-testid="quick-review-sheet-reference">
													{{ shortenedReference(sheet) }}
												</bdi>
											</h3>
											<p class="quick-review__meta">
												<span data-testid="quick-review-purchaser">{{ purchasedByLabel(sheet) }}</span>
												<span class="quick-review__scope" data-testid="quick-review-scope">
													{{ t('pettycash', 'reviews.quickReview.scope', { scope: costCenterLabel(sheet) }) }}
												</span>
												<span data-testid="quick-review-submitted">{{ sheetSubmittedLabel(sheet) }}</span>
											</p>
										</div>
										<NcButton
											variant="tertiary"
											data-testid="collapse-sheet-btn"
											@click="collapseSheet(sheet)"
										>
											{{ t('pettycash', 'reviews.quickReview.collapse') }}
										</NcButton>
									</header>

									<p
										v-if="sheetReview(sheet)"
										class="quick-review__progress"
										data-testid="quick-review-progress"
									>
										{{ t('pettycash', 'reviews.quickReview.progress', {
											decided: formatNumber(sheetReview(sheet)?.progress.decidedByManager ?? 0),
											total: formatNumber(sheetReview(sheet)?.progress.total ?? 0),
										}) }}
										<span v-if="(sheetReview(sheet)?.progress.returnedForCorrection ?? 0) > 0" class="quick-review__progress-extra">
											· {{ t('pettycash', 'reviews.quickReview.returnedCount', {
												count: formatNumber(sheetReview(sheet)?.progress.returnedForCorrection ?? 0),
											}) }}
										</span>
									</p>

									<div v-if="!sheetReview(sheet) && isDetailLoading(sheet)" class="quick-review__state" data-testid="quick-review-loading">
										<NcLoadingIcon :size="28" />
										<p>{{ t('pettycash', 'reviews.quickReview.loading') }}</p>
									</div>

									<div v-else-if="!sheetReview(sheet)" class="quick-review__state quick-review__state--unavailable" data-testid="quick-review-details-unavailable">
										<NcIconSvgWrapper :path="mdiAlertCircle" :size="28" class="queue-state__icon--warning" />
										<p>{{ t('pettycash', 'reviews.quickReview.detailsUnavailable') }}</p>
										<p class="quick-review__state-note">{{ t('pettycash', 'reviews.quickReview.fallbackNote') }}</p>
									</div>

									<ul class="quick-review__cards" data-testid="quick-review-cards">
										<li
											v-for="transaction in panelTransactions(sheet)"
											:key="transaction.id"
											class="quick-review__card-item"
											:data-testid="`sheet-transaction-${transaction.id}`"
										>
											<QuickReviewCard
												:transaction="transaction"
												:manager-state="transaction.managerState"
												:manager-decision="transaction.managerDecision"
												:evidence="transactionEvidence(transaction.id)"
												:allowed-actions="transactionDetail(transaction.id)?.allowedActions ?? null"
												:busy="submittingTransactionId === transaction.id"
												@open-review="openTransaction"
												@open-details="openDetails"
												@open-preview="(evidenceId) => openPreviewFromCard(transaction, evidenceId)"
												@decide="(kind) => openConfirm(transaction, kind)"
											/>
										</li>
									</ul>

									<p v-if="panelTransactions(sheet).length === 0" class="quick-review__state-note" data-testid="quick-review-no-transactions">
										{{ t('pettycash', 'reviews.quickReview.noTransactions') }}
									</p>

									<div class="quick-review__footer" data-testid="quick-review-footer">
										<NcIconSvgWrapper :path="mdiShieldCheckOutline" :size="18" aria-hidden="true" />
										<p>
											<strong>{{ t('pettycash', 'reviews.quickReview.footerTitle') }}</strong>
											{{ t('pettycash', 'reviews.quickReview.footerBody') }}
										</p>
									</div>
								</div>
							</li>
						</ul>
					</template>

					<p class="sheet-card__footnote" data-testid="relevant-amount-note">
						{{ t('pettycash', 'reviews.sheetQueue.relevantAmountNote') }}
					</p>
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
			T028: the Batch 4 overlay stack. Exactly one overlay is open at a time (single-overlay rule),
			so the three layers below are mutually exclusive on `activeOverlay`. Only the details drawer
			and the confirmation modal live here permanently; the evidence preview overlay is the same
			EvidencePreview instance the card tiles and the drawer both drive.
		-->
		<EvidencePreview
			:open="activeOverlay === 'preview'"
			:evidence="previewEvidence"
			:documents="previewDocuments"
			:index="previewIndex"
			@close="closeOverlay"
			@navigate="navigatePreview"
		/>

		<TransactionDetailsDrawer
			:open="activeOverlay === 'details'"
			:transaction="detailsTransaction"
			:manager-state="detailsManagerState"
			:manager-decision="detailsManagerDecision"
			:sheet="detailsSheet"
			:read-state="detailsReadState"
			@close="closeOverlay"
			@decide="decideFromDrawer"
			@preview="openPreviewFromDrawer"
		/>

		<QuickDecisionModal
			:open="activeOverlay === 'confirm'"
			:decision="confirmDecision"
			:transaction="confirmTransaction"
			:expected-version="confirmVersion"
			:allowed-actions="confirmAllowedActions"
			@close="closeOverlay"
			@decided="onDecided"
		/>
	</div>
</template>

<script setup lang="ts">
import { direction } from '@/utils/direction'
import { computed, h, nextTick, onMounted, ref, type VNode } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { t } from '@nextcloud/l10n'
import {
	mdiAccountOutline,
	mdiAlertCircle,
	mdiChevronDown,
	mdiChevronUp,
	mdiClipboardCheckOutline,
	mdiFileDocumentOutline,
	mdiMagnify,
	mdiRefresh,
	mdiShieldCheckOutline,
} from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import QuickDecisionModal from '@/components/review/QuickDecisionModal.vue'
import QuickReviewCard from '@/components/review/QuickReviewCard.vue'
import ResponsiveTable from '@/components/shared/ResponsiveTable.vue'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import EvidencePreview from '@/components/evidence/EvidencePreview.vue'
import TransactionDetailsDrawer from '@/components/review/TransactionDetailsDrawer.vue'
import {
	queueColumns as queueColumnsAround,
	queueCardRowTestId,
	queueRowKey,
	queueRowTestId,
	sharedQueueCell,
	type QueueColumn,
} from '@/components/review/queueColumns'
import { useAuthStore } from '@/stores/auth'
import { useReviewsStore } from '@/stores/reviews'
import { formatSheetDate } from '@/utils/date'
import { formatMoney } from '@/utils/money'
import { formatNumber } from '@/utils/number'
import { selectOptionKey, selectOptionValue } from '@/utils/selectOption'
import {
	SHEET_QUEUE_PAGE_SIZE,
	buildSheetQueue,
	checkSheetQueueCompleteness,
	searchSheetQueue,
	sortSheetQueue,
	type SheetQueueItem,
	type SheetQueueSort,
} from '@/composables/useSheetQueue'
import type { AllowedActions, DecisionResult, DecisionValue, Evidence, ManagerSheetReview, ManagerSheetSummary, ManagerTransactionState, ManagerTransactionReadState, ManagerTransactionSummary, Money, TransactionDetail, TransactionSummary } from '@/types/api'

type SheetTab = 'all' | 'awaiting' | 'inProgress'
type DetailState = 'pending' | 'loaded' | 'failed'

const router = useRouter()
const reviewsStore = useReviewsStore()
const authStore = useAuthStore()

const viewMode = ref<'sheets' | 'transactions'>('sheets')
const searchQuery = ref('')
const sort = ref<SheetQueueSort>('newest')
const expandedSheetId = ref<string | null>(null)
const activeTab = ref<SheetTab>('all')
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

const managedCostCenters = computed(() => authStore.managedCostCenterCodes)

const completeness = computed(() => checkSheetQueueCompleteness(transactionQueue.value, SHEET_QUEUE_PAGE_SIZE))

const allSheets = computed(() => buildSheetQueue(transactionQueue.value.items))
const awaitingSheetCount = computed(() => allSheets.value.length)
const awaitingTransactionCount = computed(() => allSheets.value.reduce((sum, sheet) => sum + sheet.transactionCount, 0))

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

/**
 * A sheet is "in progress" only when this manager has already recorded at least one decision in
 * it. It is not sheet-wide Cost Center completion and never implies Senior eligibility.
 */
function isSheetInProgress(sheet: SheetQueueItem): boolean {
	const progress = sheetReview(sheet)?.progress
	if (!progress) return false
	return progress.decidedByManager + progress.returnedForCorrection > 0
}

const detailsComplete = computed(() => allSheets.value.every((sheet) =>
	sheet.sheetId !== '' && detailState.value[sheet.sheetId] === 'loaded'
))

/**
 * Hidden until every manager read has answered: a partial count would present a known lower bound
 * as the queue's whole "In progress" set. Once complete, every value is traceable to a real sheet.
 */
const inProgressSheetCount = computed<number | null>(() =>
	detailsComplete.value ? allSheets.value.filter(isSheetInProgress).length : null
)

const tabs = computed<Array<{ key: SheetTab; label: string; count: number | null }>>(() => [
	{ key: 'all', label: t('pettycash', 'reviews.sheetQueue.tabAll'), count: allSheets.value.length },
	{ key: 'awaiting', label: t('pettycash', 'reviews.sheetQueue.tabAwaiting'), count: allSheets.value.length },
	{ key: 'inProgress', label: t('pettycash', 'reviews.sheetQueue.tabInProgress'), count: inProgressSheetCount.value },
])

const searchedSheets = computed(() => sortSheetQueue(
	searchSheetQueue(allSheets.value, searchQuery.value, (sheet) => {
		const review = sheetReview(sheet)
		return [review?.sheet.title, review?.sheet.ownerDisplayName]
	}),
	sort.value
))

const sheetItems = computed(() => activeTab.value === 'inProgress'
	? searchedSheets.value.filter(isSheetInProgress)
	: searchedSheets.value
)

const currentPage = computed(() => transactionQueue.value.page)
const pageCount = computed(() => Math.max(1, Math.ceil(transactionQueue.value.total / Math.max(1, transactionQueue.value.pageSize))))
const hasTransactions = computed(() => transactionQueue.value.items.length > 0)

const sortOptions = computed(() => [
	{ value: 'newest', label: t('pettycash', 'reviews.sheetQueue.sort.newest') },
	{ value: 'oldest', label: t('pettycash', 'reviews.sheetQueue.sort.oldest') },
	{ value: 'mostTransactions', label: t('pettycash', 'reviews.sheetQueue.sort.mostTransactions') },
	{ value: 'reference', label: t('pettycash', 'reviews.sheetQueue.sort.reference') },
] as Array<{ value: SheetQueueSort; label: string }>)

function onSortChange(value: unknown): void {
	const next = typeof value === 'string' ? value : ''
	if (sortOptions.value.some((option) => option.value === next)) {
		sort.value = next as SheetQueueSort
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
		expandedSheetId.value = null
		closeOverlay()
		reviewsStore.clearManagerSheetReviews()
		detailState.value = {}
		await prefetchManagerDetails(allSheets.value.map((sheet) => sheet.sheetId))
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

function shouldReduceMotion(): boolean {
	return typeof window.matchMedia === 'function'
		&& window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

/**
 * T036 / UX_SPEC §0.4: on mobile the expanded panel scrolls into view, so opening a row that sits
 * below the fold lands the reviewer on the panel rather than leaving the disclosure hidden at the
 * top of the viewport. Instant when the reviewer prefers reduced motion. The scroll is
 * opportunistic — it must never block the expansion, and jsdom records no layout so it is a
 * browser-time behaviour, not a unit-detectable one.
 */
async function toggleSheet(sheetId: string): Promise<void> {
	if (expandedSheetId.value === sheetId) {
		expandedSheetId.value = null
		return
	}

	expandedSheetId.value = sheetId
	if (sheetId !== '') {
		void loadManagerDetail(sheetId)
		const sheet = allSheets.value.find((item) => item.sheetId === sheetId)
		if (sheet) {
			// T028: the expanded panel's cards read their evidence tiles and capability grants from
			// the per-transaction detail reads, so they start as soon as the panel opens.
			void prefetchTransactionDetails(panelTransactions(sheet).map((transaction) => transaction.id))
		}
		await nextTick()
		if (typeof window.matchMedia === 'function' && window.matchMedia('(width <= 640px)').matches) {
			const panel = document.querySelector<HTMLElement>(`[data-testid="sheet-detail-${sheetId || 'unresolved'}"]`)
			panel?.scrollIntoView({ behavior: shouldReduceMotion() ? 'auto' : 'smooth', block: 'start' })
		}
	}
}

async function collapseSheet(sheet: SheetQueueItem): Promise<void> {
	expandedSheetId.value = null
	await nextTick()
	const row = document.querySelector(`[data-testid="sheet-row-${sheet.sheetId || 'unresolved'}"] .sheet-row__summary`)
	if (row instanceof HTMLElement) row.focus()
}

function detailId(sheet: SheetQueueItem): string {
	return `sheet-detail-${sheet.sheetId || 'unresolved'}`
}

function isDetailLoading(sheet: SheetQueueItem): boolean {
	return sheet.sheetId !== '' && detailState.value[sheet.sheetId] === 'pending'
}

function sheetReference(sheet: SheetQueueItem): string {
	return sheet.sheetId || t('pettycash', 'reviews.sheetQueue.unresolved')
}

/**
 * The visible reference is a short prefix, because the API publishes a uuid and no sheet title.
 * The full identifier stays on the row's `title` and in its accessible label, so the short form is
 * a readable reference rather than a replacement for the real id.
 */
function shortenedReference(sheet: SheetQueueItem): string {
	if (sheet.sheetId === '') return t('pettycash', 'reviews.sheetQueue.unresolved')
	return sheet.sheetId.length > 12 ? `${sheet.sheetId.slice(0, 8)}…` : sheet.sheetId
}

function costCenterLabel(sheet: SheetQueueItem): string {
	if (sheet.costCenters.length === 0) return t('pettycash', 'reviews.sheetQueue.noCostCenter')
	return sheet.costCenters.map((center) => center.code || center.name).filter(Boolean).join(' · ')
}

function sheetTitle(sheet: SheetQueueItem): string {
	const title = sheetReview(sheet)?.sheet.title?.trim() ?? ''
	return title !== '' ? title : t('pettycash', 'reviews.sheetQueue.sheetTitleUnavailable')
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

function purchasedByLabel(sheet: SheetQueueItem): string {
	const name = purchaserName(sheet)
	return t('pettycash', 'reviews.quickReview.purchasedBy', {
		purchaser: name !== '' ? name : t('pettycash', 'reviews.sheetQueue.purchaserUnavailable'),
	})
}

function sheetStatusLabel(sheet: SheetQueueItem): string {
	return isSheetInProgress(sheet)
		? t('pettycash', 'reviews.sheetQueue.statusInProgress')
		: t('pettycash', 'reviews.sheetQueue.needsAttention')
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
 * Exactly one overlay is ever open: the evidence preview, the details drawer, or the decision
 * confirmation. A single `activeOverlay` value is the whole rule — two `v-if`s keyed off separate
 * booleans is how two overlays end up stacked, and the approved design keeps one overlay at a time
 * with each transition replacing the previous layer.
 */
type OverlayState = 'none' | 'preview' | 'details' | 'confirm'

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

const detailsTransactionId = ref('')

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

function openDetails(transactionId: string): void {
	detailsTransactionId.value = transactionId
	activeOverlay.value = 'details'
	void ensureTransactionDetail(transactionId)
}

/**
 * Opens the preview from a card tile. The evidence set lives in the per-transaction detail, so a
 * tile on a card whose read failed cannot open an empty overlay: the read runs first and a failure
 * routes to the drawer, whose read state says what actually happened.
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
	openDetails(transaction.id)
}

/** The drawer hands over an evidence item it rendered; the index comes from the same detail read. */
function openPreviewFromDrawer(evidence: Evidence): void {
	const index = transactionEvidence(detailsTransactionId.value).findIndex((item) => item.id === evidence.id)
	if (index < 0) return
	previewTransactionId.value = detailsTransactionId.value
	previewIndex.value = index
	activeOverlay.value = 'preview'
}

function navigatePreview(index: number): void {
	previewIndex.value = index
}

const previewDocuments = computed<Evidence[]>(() => transactionEvidence(previewTransactionId.value))
const previewEvidence = computed<Evidence | null>(() => previewDocuments.value[previewIndex.value] ?? null)

/** The details drawer renders from the guarded detail read; its read state is the store's. */
const detailsTransaction = computed<TransactionDetail | null>(() =>
	activeOverlay.value === 'details' ? transactionDetail(detailsTransactionId.value) : null)
const detailsReadState = computed<ManagerTransactionReadState>(() =>
	managerTransactionReads.value[detailsTransactionId.value] ?? 'loading')
const detailsManagerTransaction = computed<ManagerTransactionSummary | null>(() =>
	activeOverlay.value === 'details' ? findManagerTransaction(detailsTransactionId.value)?.transaction ?? null : null)
const detailsManagerState = computed<ManagerTransactionState>(() =>
	detailsManagerTransaction.value?.managerState ?? 'AWAITING_MANAGER_DECISION')
const detailsManagerDecision = computed(() => detailsManagerTransaction.value?.managerDecision ?? null)
const detailsSheet = computed<ManagerSheetSummary | null>(() => {
	if (activeOverlay.value !== 'details') return null
	const sheetId = detailsManagerTransaction.value?.sheetId
	return sheetId !== undefined && sheetId !== '' ? managerSheetReviews.value[sheetId]?.sheet ?? null : null
})

/** Drawer → confirmation. The drawer closes first; the single-overlay rule keeps only the modal. */
function decideFromDrawer(kind: DecisionValue): void {
	const transaction = detailsTransaction.value
	if (transaction === null) return
	openConfirmForTransaction(transaction.id, kind)
}

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
		// grant to honour — the drawer says what happened instead of a modal that cannot submit.
		openDetails(transactionId)
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
 * Decision recorded: close the modal, refresh the sheet read (progress counts and the card's
 * decided state come from the server, not from a client-side patch), drop the now-stale per-
 * transaction detail, and re-read it in the background so the card keeps its evidence row and the
 * drawer its history. The toast words come from the approved mockup's completion state.
 */
async function onDecided(result: DecisionResult): Promise<void> {
	const transactionId = confirmTransaction.value?.id ?? result.transaction.id
	const sheetId = findManagerTransaction(transactionId)?.sheet.sheetId ?? result.transaction.sheetId

	activeOverlay.value = 'none'
	submittingTransactionId.value = null
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
	gap: 20px;
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
	gap: 16px;
	flex-wrap: wrap;
}

.queue-header__eyebrow {
	margin: 0 0 6px;
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

.queue-header__subtitle {
	margin: 6px 0 0;
	max-inline-size: 62ch;
	color: var(--color-text-maxcontrast);
}

.queue-header__aside {
	display: flex;
	align-items: center;
	gap: 10px;
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

.responsibility {
	display: flex;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	padding: 12px 16px;
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
}

.summary {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 14px;
}

.stat {
	display: flex;
	flex-direction: column;
	gap: 8px;
	min-inline-size: 0;
	padding: 14px 16px;
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
	inline-size: 28px;
	block-size: 28px;
	border-radius: 8px;
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
	font-size: 26px;
	font-weight: 700;
	line-height: 1.15;
	overflow-wrap: anywhere;
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
	padding: 18px 20px;
	border: 1px solid var(--color-border);
	border-radius: 14px;
	background-color: var(--color-main-background);
}

.sheet-card__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	margin-block-end: 14px;
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

.toolbar {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto auto;
	align-items: center;
	gap: 12px;
}

.search {
	display: flex;
	align-items: center;
	gap: 8px;
	min-inline-size: 0;
	padding: 0 12px;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background-color: var(--color-main-background);
}

.search:focus-within {
	border-color: var(--color-primary-element);
}

.search__icon {
	display: inline-flex;
	color: var(--color-text-maxcontrast);
}

.search__input {
	inline-size: 100%;
	min-inline-size: 0;
	padding: 10px 0;
	border: 0;
	background: transparent;
	color: inherit;
	font-family: inherit;
	font-size: var(--font-size-floor);
}

.search__input:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
	border-radius: var(--border-radius, 4px);
}

.tabs {
	display: flex;
	gap: 4px;
	padding: 4px;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background-color: var(--color-background-hover);
}

.tab {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 6px 12px;
	border: 0;
	border-radius: 8px;
	background: transparent;
	color: var(--color-main-text);
	font-family: inherit;
	font-size: var(--font-size-floor);
	cursor: pointer;
	white-space: nowrap;
}

.tab--active {
	background-color: var(--color-main-background);
	font-weight: 600;
	box-shadow: 0 1px 2px color-mix(in srgb, black 12%, transparent);
}

.tab__count {
	padding: 0 6px;
	border-radius: 999px;
	background-color: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
	color: var(--color-primary-element);
	font-weight: 600;
}

.sort {
	display: flex;
	align-items: center;
	gap: 8px;
	white-space: nowrap;
}

.sort__label {
	color: var(--color-text-maxcontrast);
}

.sort__select {
	inline-size: 240px;
	min-inline-size: 0;
}

.availability-note {
	margin: 8px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.sheet-list__head,
.sheet-row__summary {
	display: grid;
	grid-template-columns: minmax(0, 2fr) minmax(0, 1.2fr) 0.85fr 1fr 1.05fr 0.85fr 24px;
	align-items: center;
	gap: 12px;
}

.sheet-list__head {
	padding: 8px;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	font-weight: 600;
	line-height: 1.3;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	word-break: normal;
	overflow-wrap: normal;
	hyphens: none;
}

.sheet-list__head--numeric {
	text-align: end;
}

.sheet-list {
	margin: 0;
	padding: 0;
	list-style: none;
	border-block-start: 1px solid var(--color-border);
}

.sheet-row + .sheet-row {
	border-block-start: 1px solid var(--color-border);
}

.sheet-row__summary {
	inline-size: 100%;
	padding: 12px 8px;
	border: 0;
	background: transparent;
	color: inherit;
	font-family: inherit;
	font-size: var(--font-size-floor);
	text-align: start;
	cursor: pointer;
}

.sheet-row__summary:hover {
	background-color: var(--color-background-hover);
}

.sheet-row__identity {
	display: flex;
	align-items: center;
	gap: 12px;
	min-inline-size: 0;
}

.sheet-row__icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: none;
	inline-size: 40px;
	block-size: 40px;
	border-radius: 10px;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.sheet-row__text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-inline-size: 0;
}

.sheet-row__title {
	font-weight: 600;
	line-height: 1.3;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.sheet-row__meta-line {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
	min-inline-size: 0;
}

.sheet-row__reference {
	font-family: monospace;
	font-size: var(--font-size-floor);
	font-weight: 500;
	direction: ltr;
	unicode-bidi: isolate;
	text-align: start;
	overflow-wrap: anywhere;
}

.sheet-row__meta {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	overflow-wrap: anywhere;
}

.sheet-row__meta::before {
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
	font-variant-numeric: tabular-nums;
}

.metric__value--muted {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	font-weight: 400;
}

.purchaser {
	display: flex;
	align-items: center;
	gap: 8px;
	min-inline-size: 0;
}

.purchaser__avatar {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: none;
	inline-size: 28px;
	block-size: 28px;
	border: 1px dashed var(--color-border);
	border-radius: 50%;
	background-color: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
}

.purchaser__name {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	overflow-wrap: anywhere;
}

.sheet-row__chevron {
	display: inline-flex;
	justify-content: flex-end;
	color: var(--color-text-maxcontrast);
}

.sheet-row__detail {
	padding: 0;
	border-block-start: 1px dashed var(--color-border);
	background-color: var(--color-background-hover);
}

.quick-review {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 18px;
}

.quick-review__head {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
}

.quick-review__heading {
	min-inline-size: 0;
}

.quick-review__eyebrow {
	margin: 0 0 4px;
	color: var(--color-primary-element);
	font-size: var(--font-size-floor);
	font-weight: 700;
	letter-spacing: 0.08em;
	text-transform: uppercase;
}

.quick-review__title {
	display: flex;
	align-items: baseline;
	gap: 8px;
	flex-wrap: wrap;
	margin: 0;
	font-size: var(--font-size-section);
	font-weight: 700;
	line-height: 1.3;
}

.quick-review__title--unavailable {
	color: var(--color-text-maxcontrast);
}

.quick-review__reference {
	color: var(--color-text-maxcontrast);
	font-family: monospace;
	font-size: var(--font-size-floor);
	font-weight: 500;
	direction: ltr;
	unicode-bidi: isolate;
}

.quick-review__meta {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
	margin: 6px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.quick-review__meta > span + span::before {
	content: '·';
	margin-inline-end: 6px;
}

.quick-review__progress {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.quick-review__progress-extra {
	color: var(--pettycash-review);
	font-weight: 600;
}

.quick-review__state {
	display: grid;
	grid-template-columns: auto minmax(0, 1fr);
	align-items: center;
	gap: 4px 10px;
	padding: 12px 14px;
	border: 1px dashed var(--color-border);
	border-radius: 10px;
	color: var(--color-text-maxcontrast);
}

.quick-review__state--unavailable {
	border-color: color-mix(in srgb, var(--color-warning) 35%, var(--color-border));
	background-color: color-mix(in srgb, var(--color-warning) 6%, var(--color-main-background));
}

.quick-review__state p {
	margin: 0;
}

.quick-review__state-note {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.quick-review__state .quick-review__state-note {
	grid-column: 1 / -1;
}

.quick-review__cards {
	display: flex;
	flex-direction: column;
	gap: 10px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.quick-review__card-item {
	min-inline-size: 0;
}

.quick-review__footer {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 10px 12px;
	border: 1px solid color-mix(in srgb, var(--color-primary-element) 18%, var(--color-border));
	border-radius: 10px;
	background-color: color-mix(in srgb, var(--color-primary-element) 4%, var(--color-main-background));
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	line-height: 1.45;
}

.quick-review__footer p {
	margin: 0;
}

.quick-review__footer strong {
	color: var(--color-main-text);
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
		flex: 1 1 auto;
		justify-content: center;
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

	.responsibility {
		padding: 12px;
	}

	.responsibility__icon {
		inline-size: 30px;
		block-size: 30px;
	}

	.responsibility__body {
		flex-basis: calc(100% - 42px);
	}

	.scope {
		inline-size: 100%;
		justify-content: flex-start;
		margin-inline-start: 0;
	}

	.sheet-row__summary {
		padding: 12px 4px;
	}

	.sheet-row__icon {
		inline-size: 36px;
		block-size: 36px;
	}

	.purchaser__avatar {
		inline-size: 26px;
		block-size: 26px;
	}

	.scope__item {
		font-size: var(--font-size-floor);
	}

	.quick-review {
		gap: 12px;
		padding: 12px;
	}

	.quick-review__head {
		flex-direction: column;
		align-items: stretch;
	}

	.quick-review__title {
		font-size: var(--font-size-emphasis);
	}

	.quick-review__footer {
		padding: 10px;
	}
}
</style>

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
						<strong class="stat__value">{{ formatNumber(sheetCount) }}</strong>
						<span class="stat__hint stat__hint--accent">
							{{ t('pettycash', 'reviews.sheetQueue.stats.sheetsHint', { count: formatNumber(transactionCount) }) }}
						</span>
					</article>

					<article class="stat" data-testid="stat-transactions">
						<div class="stat__top">
							<span class="stat__label">{{ t('pettycash', 'reviews.sheetQueue.stats.transactions') }}</span>
							<span class="stat__icon stat__icon--review" aria-hidden="true">
								<NcIconSvgWrapper :path="mdiClipboardCheckOutline" :size="20" />
							</span>
						</div>
						<strong class="stat__value">{{ formatNumber(transactionCount) }}</strong>
						<span class="stat__hint">{{ t('pettycash', 'reviews.sheetQueue.stats.transactionsHint') }}</span>
					</article>

					<article class="stat" data-testid="stat-amount">
						<div class="stat__top">
							<span class="stat__label">{{ t('pettycash', 'reviews.sheetQueue.stats.amount') }}</span>
							<span class="stat__icon stat__icon--warning" aria-hidden="true">
								<NcIconSvgWrapper :path="mdiWalletOutline" :size="20" />
							</span>
						</div>
						<strong
							v-for="total in pendingTotals"
							:key="`${total.currency}-${total.minorUnit}`"
							class="stat__value stat__value--amount"
							data-testid="pending-total"
						>
							{{ formatMoney(total) }}
						</strong>
						<span class="stat__hint">{{ t('pettycash', 'reviews.sheetQueue.stats.amountHint') }}</span>
					</article>

					<article class="stat stat--disabled" aria-disabled="true" data-testid="stat-reviewed">
						<div class="stat__top">
							<span class="stat__label">{{ t('pettycash', 'reviews.sheetQueue.stats.reviewed') }}</span>
							<span class="stat__icon stat__icon--muted" aria-hidden="true">
								<NcIconSvgWrapper :path="mdiLockOutline" :size="20" />
							</span>
						</div>
						<strong class="stat__value stat__value--muted">{{ t('pettycash', 'reviews.sheetQueue.stats.reviewedUnavailable') }}</strong>
						<span class="stat__hint">{{ t('pettycash', 'reviews.sheetQueue.stats.reviewedReason') }}</span>
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
								type="button"
								role="tab"
								class="tab tab--active"
								aria-selected="true"
								data-testid="tab-awaiting"
							>
								{{ t('pettycash', 'reviews.sheetQueue.tabAwaiting') }}
								<span class="tab__count">{{ formatNumber(sheetCount) }}</span>
							</button>
							<button
								type="button"
								role="tab"
								class="tab tab--disabled"
								aria-selected="false"
								aria-disabled="true"
								disabled
								:title="t('pettycash', 'reviews.sheetQueue.reviewedDisabled')"
								data-testid="tab-reviewed"
							>
								{{ t('pettycash', 'reviews.sheetQueue.tabReviewed') }}
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

					<p class="availability-note" data-testid="reviewed-unavailable">
						{{ t('pettycash', 'reviews.sheetQueue.reviewedDisabled') }}
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
												data-testid="sheet-title"
												:title="t('pettycash', 'reviews.sheetQueue.unavailableField')"
											>
												{{ t('pettycash', 'reviews.sheetQueue.sheetTitleUnavailable') }}
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
												<span class="purchaser__avatar" aria-hidden="true">
													<NcIconSvgWrapper :path="mdiAccountOutline" :size="16" />
												</span>
												<span class="purchaser__name" :title="t('pettycash', 'reviews.sheetQueue.unavailableField')">
													{{ t('pettycash', 'reviews.sheetQueue.purchaserUnavailable') }}
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
											<StatusBadge status="COST_CENTER" :label="t('pettycash', 'reviews.sheetQueue.needsAttention')" />
										</span>
										<span class="metric metric--numeric metric--submitted" data-testid="sheet-submitted">
											<span class="metric__label">{{ t('pettycash', 'reviews.sheetQueue.submitted') }}</span>
											<span class="metric__value metric__value--muted" :title="t('pettycash', 'reviews.sheetQueue.unavailableField')">
												{{ t('pettycash', 'reviews.sheetQueue.submittedUnavailable') }}
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
									class="sheet-row__detail"
									:data-testid="`sheet-detail-${sheet.sheetId || 'unresolved'}`"
								>
									<p class="sheet-row__detail-note">{{ t('pettycash', 'reviews.sheetQueue.detailNote') }}</p>
									<ul class="transaction-list">
										<li
											v-for="transaction in sheet.transactions"
											:key="transaction.id"
											class="transaction-row"
											:data-testid="`sheet-transaction-${transaction.id}`"
										>
											<span class="transaction-row__text">
												<strong class="transaction-row__description">{{ transaction.activeRevision.description }}</strong>
												<span class="transaction-row__meta">
													{{ formatSheetDate(transaction.activeRevision.expenseDate) }}
													·
													{{ transaction.activeRevision.costCenter.name }}
												</span>
											</span>
											<span class="transaction-row__amount">{{ formatMoney(transaction.activeRevision.amount) }}</span>
											<NcButton
												variant="tertiary"
												:aria-label="t('pettycash', 'reviews.sheetQueue.reviewTransactionNamed', { description: transaction.activeRevision.description })"
												:data-testid="`review-transaction-${transaction.id}`"
												@click="openTransaction(transaction.id)"
											>
												{{ t('pettycash', 'reviews.sheetQueue.reviewTransaction') }}
											</NcButton>
										</li>
									</ul>
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
	</div>
</template>

<script setup lang="ts">
import { direction } from '@/utils/direction'
import { computed, h, onMounted, ref, type VNode } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { t } from '@nextcloud/l10n'
import {
	mdiAccountOutline,
	mdiAlertCircle,
	mdiChevronDown,
	mdiChevronUp,
	mdiClipboardCheckOutline,
	mdiFileDocumentOutline,
	mdiLockOutline,
	mdiMagnify,
	mdiRefresh,
	mdiShieldCheckOutline,
	mdiWalletOutline,
} from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ResponsiveTable from '@/components/shared/ResponsiveTable.vue'
import StatusBadge from '@/components/shared/StatusBadge.vue'
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
	totalPendingByCurrency,
	type SheetQueueItem,
	type SheetQueueSort,
} from '@/composables/useSheetQueue'
import type { TransactionSummary } from '@/types/api'

const router = useRouter()
const reviewsStore = useReviewsStore()
const authStore = useAuthStore()

const viewMode = ref<'sheets' | 'transactions'>('sheets')
const searchQuery = ref('')
const sort = ref<SheetQueueSort>('newest')
const expandedSheetId = ref<string | null>(null)

const transactionQueue = computed(() => reviewsStore.costCenterQueue)
const loading = computed(() => reviewsStore.loading)
const error = computed(() => reviewsStore.error)

const managedCostCenters = computed(() => authStore.managedCostCenterCodes)

const completeness = computed(() => checkSheetQueueCompleteness(transactionQueue.value, SHEET_QUEUE_PAGE_SIZE))

const allSheets = computed(() => buildSheetQueue(transactionQueue.value.items))
const sheetCount = computed(() => allSheets.value.length)
const transactionCount = computed(() => allSheets.value.reduce((sum, sheet) => sum + sheet.transactionCount, 0))
const pendingTotals = computed(() => totalPendingByCurrency(allSheets.value))
const sheetItems = computed(() => sortSheetQueue(searchSheetQueue(allSheets.value, searchQuery.value), sort.value))

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

async function fetchSheets(): Promise<void> {
	try {
		await reviewsStore.fetchCostCenterQueue({
			stage: 'cost-center',
			page: 1,
			pageSize: SHEET_QUEUE_PAGE_SIZE,
		})
		expandedSheetId.value = null
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

function toggleSheet(sheetId: string): void {
	expandedSheetId.value = expandedSheetId.value === sheetId ? null : sheetId
}

function detailId(sheet: SheetQueueItem): string {
	return `sheet-detail-${sheet.sheetId || 'unresolved'}`
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

function openTransaction(transactionId: string): void {
	router.push({ name: 'review-detail', params: { transactionId, stage: 'cost-center' } })
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
	grid-template-columns: repeat(4, minmax(0, 1fr));
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

.stat--disabled {
	border-style: dashed;
	background-color: var(--color-background-hover);
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

.stat__icon--warning {
	background-color: color-mix(in srgb, var(--color-warning) 16%, transparent);
	color: var(--color-warning-text, var(--color-warning));
}

.stat__icon--muted {
	background-color: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.stat__value {
	font-size: 26px;
	font-weight: 700;
	line-height: 1.15;
	overflow-wrap: anywhere;
}

.stat__value--amount {
	font-size: var(--font-size-section);
}

.stat__value--muted {
	font-size: var(--font-size-emphasis);
	color: var(--color-text-maxcontrast);
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
	font: inherit;
}

.search__input:focus {
	outline: none;
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
	font: inherit;
	cursor: pointer;
	white-space: nowrap;
}

.tab--active {
	background-color: var(--color-main-background);
	font-weight: 600;
	box-shadow: 0 1px 2px color-mix(in srgb, black 12%, transparent);
}

.tab--disabled {
	color: var(--color-text-maxcontrast);
	cursor: not-allowed;
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
	font: inherit;
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
	padding: 4px 8px 18px;
	border-block-start: 1px dashed var(--color-border);
	background-color: var(--color-background-hover);
}

.sheet-row__detail-note {
	margin: 12px 0;
	color: var(--color-text-maxcontrast);
}

.transaction-list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.transaction-row {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto auto;
	align-items: center;
	gap: 16px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background-color: var(--color-main-background);
}

.transaction-row + .transaction-row {
	margin-block-start: 8px;
}

.transaction-row__text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-inline-size: 0;
}

.transaction-row__description {
	overflow-wrap: anywhere;
}

.transaction-row__meta {
	color: var(--color-text-maxcontrast);
}

.transaction-row__amount {
	font-weight: 600;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
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

	.transaction-row {
		grid-template-columns: minmax(0, 1fr) auto;
	}

	.transaction-row :deep(.button-vue) {
		grid-column: 1 / -1;
		justify-self: start;
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

	.stat__value--amount {
		/* A long minor-unit amount must stay whole on a 390px card: the summary
		   grid is 2×2 here, so the value cannot inherit the desktop headline size. */
		font-size: var(--font-size-floor);
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
}
</style>

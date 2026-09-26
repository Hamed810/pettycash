<template>
	<div class="queue-view" dir="rtl" data-testid="cost-center-queue">
		<nav class="breadcrumbs" :aria-label="t('pettycash', 'common.breadcrumbs')">
			<ol class="breadcrumbs__list">
				<li class="breadcrumbs__item">
					<RouterLink :to="{ name: 'dashboard' }">{{ t('pettycash', 'navigation.dashboard') }}</RouterLink>
				</li>
				<li class="breadcrumbs__item" aria-current="page">{{ t('pettycash', 'reviews.costCenterQueue') }}</li>
			</ol>
		</nav>

		<div class="queue-view__panel">
			<div class="queue-view__header">
				<h2 class="queue-view__title">{{ t('pettycash', 'reviews.costCenterQueue') }}</h2>
				<NcButton :disabled="loading" data-testid="refresh-btn" @click="fetchQueue(currentPage)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiRefresh" />
					</template>
					{{ t('pettycash', 'common.refresh') }}
				</NcButton>
			</div>

			<div v-if="loading" class="queue-view__state" data-testid="loading">
				<NcLoadingIcon :size="44" />
				<p>{{ t('pettycash', 'common.loading') }}</p>
			</div>

			<div v-else-if="error" class="queue-view__state" data-testid="error">
				<NcIconSvgWrapper :path="mdiAlertCircle" :size="44" class="queue-view__state-icon--error" />
				<p>{{ error }}</p>
				<NcButton data-testid="retry-btn" @click="fetchQueue(currentPage)">
					{{ t('pettycash', 'common.retry') }}
				</NcButton>
			</div>

			<NcEmptyContent
				v-else-if="!hasItems"
				:name="t('pettycash', 'reviews.costCenterQueue')"
				:description="t('pettycash', 'reviews.noTransactionsInQueue')"
				data-testid="empty-queue" />

			<div v-else class="queue-view__content">
				<table class="queue-table" data-testid="queue-table">
					<thead>
						<tr>
							<th scope="col">{{ t('pettycash', 'reviews.transactionId') }}</th>
							<th scope="col">{{ t('pettycash', 'reviews.sheetId') }}</th>
							<th scope="col">{{ t('pettycash', 'reviews.costCenter') }}</th>
							<th scope="col">{{ t('pettycash', 'reviews.amount') }}</th>
							<th scope="col">{{ t('pettycash', 'reviews.stage') }}</th>
							<th scope="col">{{ t('pettycash', 'reviews.status') }}</th>
							<th scope="col">{{ t('pettycash', 'common.actions') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="item in queue.items" :key="item.id" :data-testid="`queue-row-${item.id}`">
							<td>{{ item.id }}</td>
							<td>{{ item.sheetId }}</td>
							<td>{{ item.activeRevision.costCenter.name }}</td>
							<td class="money" data-testid="amount">{{ formatMoney(item.activeRevision.amount) }}</td>
							<td>{{ t('pettycash', `reviews.stage.${item.stage.toLowerCase()}`) }}</td>
							<td>
								<StatusBadge :status="item.status" />
							</td>
							<td>
								<NcButton
									variant="tertiary"
									data-testid="view-details-btn"
									@click="openTransactionDetail(item.id)">
									{{ t('pettycash', 'common.view') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>

				<nav v-if="pageCount > 1" class="queue-pagination" data-testid="queue-pagination">
					<NcButton
						:disabled="currentPage <= 1"
						data-testid="prev-page-btn"
						@click="fetchQueue(currentPage - 1)">
						{{ t('pettycash', 'common.previous') }}
					</NcButton>
					<span data-testid="page-indicator">{{ currentPage }} / {{ pageCount }}</span>
					<NcButton
						:disabled="currentPage >= pageCount"
						data-testid="next-page-btn"
						@click="fetchQueue(currentPage + 1)">
						{{ t('pettycash', 'common.next') }}
					</NcButton>
				</nav>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { t } from '@nextcloud/l10n'
import { mdiAlertCircle, mdiRefresh } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import { useReviewsStore } from '@/stores/reviews'

const router = useRouter()
const reviewsStore = useReviewsStore()

const queue = computed(() => reviewsStore.costCenterQueue)
const loading = computed(() => reviewsStore.loading)
const error = computed(() => reviewsStore.error)
const hasItems = computed(() => queue.value.items.length > 0)
const currentPage = computed(() => queue.value.page)
const pageCount = computed(() => Math.max(1, Math.ceil(queue.value.total / queue.value.pageSize)))

function formatMoney(money: { amountMinor: number; currency: string; minorUnit: number }): string {
	const divisor = 10 ** money.minorUnit
	const formatted = (money.amountMinor / divisor).toLocaleString('fa-IR', {
		minimumFractionDigits: money.minorUnit,
		maximumFractionDigits: money.minorUnit,
	})
	return `${formatted} ${money.currency}`
}

async function fetchQueue(page = 1): Promise<void> {
	await reviewsStore.fetchCostCenterQueue({
		stage: 'cost-center',
		page,
		pageSize: queue.value.pageSize,
	})
}

function openTransactionDetail(transactionId: string): void {
	router.push({ name: 'review-detail', params: { transactionId, stage: 'cost-center' } })
}

onMounted(() => {
	fetchQueue()
})
</script>

<style scoped>
.queue-view {
	direction: rtl;
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

.queue-view__panel {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
}

.queue-view__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-block-end: 16px;
}

.queue-view__title {
	font-size: 1.25rem;
	font-weight: 600;
	margin: 0;
}

.queue-view__state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 32px 0;
	text-align: center;
}

.queue-view__state-icon--error {
	color: var(--color-error-text);
}

.queue-table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--font-size-floor);
}

.queue-table th,
.queue-table td {
	padding: 8px;
	text-align: start;
	border-block-end: 1px solid var(--color-border);
}

.money {
	font-family: monospace;
}

.queue-pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	margin-block-start: 16px;
}

@media (width <= 390px) {
	.queue-table {
		display: block;
		overflow-x: auto;
		white-space: nowrap;
		font-size: var(--font-size-floor);
	}

	.queue-view__header {
		flex-direction: column;
		align-items: stretch;
	}
}
</style>

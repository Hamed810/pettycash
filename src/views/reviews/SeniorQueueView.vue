<template>
	<div class="queue-view" dir="rtl" data-testid="senior-queue">
		<nav class="breadcrumbs" :aria-label="t('pettycash', 'common.breadcrumbs')">
			<ol class="breadcrumbs__list">
				<li class="breadcrumbs__item">
					<RouterLink :to="{ name: 'dashboard' }">{{ t('pettycash', 'navigation.dashboard') }}</RouterLink>
				</li>
				<li class="breadcrumbs__item" aria-current="page">{{ t('pettycash', 'reviews.seniorQueue') }}</li>
			</ol>
		</nav>

		<div class="queue-view__panel">
			<div class="queue-view__header">
				<h2 class="queue-view__title">{{ t('pettycash', 'reviews.seniorQueue') }}</h2>
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
				:name="t('pettycash', 'reviews.seniorQueue')"
				:description="t('pettycash', 'reviews.noTransactionsInQueue')"
				data-testid="empty-queue"
			/>

			<div v-else class="queue-view__content">
				<ResponsiveTable
					:columns="queueColumns"
					:rows="queue.items"
					:cell="queueCell"
					:row-key="queueRowKey"
					:caption="queueCaption"
					test-id="queue-table"
					test-id-prefix="senior-queue"
					:row-test-id="queueRowTestId"
					:card-row-test-id="queueCardRowTestId"
					:loading="loading"
				/>

				<nav v-if="pageCount > 1" class="queue-pagination" data-testid="queue-pagination">
					<NcButton
						:disabled="currentPage <= 1"
						data-testid="prev-page-btn"
						@click="fetchQueue(currentPage - 1)"
					>
						{{ t('pettycash', 'common.previous') }}
					</NcButton>
					<span data-testid="page-indicator">{{ currentPage }} / {{ pageCount }}</span>
					<NcButton
						:disabled="currentPage >= pageCount"
						data-testid="next-page-btn"
						@click="fetchQueue(currentPage + 1)"
					>
						{{ t('pettycash', 'common.next') }}
					</NcButton>
				</nav>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, h, onMounted, type VNode } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { t } from '@nextcloud/l10n'
import { mdiAlertCircle, mdiRefresh } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ResponsiveTable from '@/components/shared/ResponsiveTable.vue'
import {
	queueColumns as queueColumnsAround,
	queueCardRowTestId,
	queueRowKey,
	queueRowTestId,
	sharedQueueCell,
	type QueueColumn
} from '@/components/review/queueColumns'
import type { TransactionSummary } from '@/types/api'
import { useReviewsStore } from '@/stores/reviews'
import type { Decision } from '@/types/api'

const router = useRouter()
const reviewsStore = useReviewsStore()

const queue = computed(() => reviewsStore.seniorQueue)
const loading = computed(() => reviewsStore.loading)
const error = computed(() => reviewsStore.error)
const hasItems = computed(() => queue.value.items.length > 0)
const currentPage = computed(() => queue.value.page)
const pageCount = computed(() => Math.max(1, Math.ceil(queue.value.total / queue.value.pageSize)))

function formatDecision(decision: Decision | null | undefined): string {
	if (!decision) {
		return '—'
	}
	return t('pettycash', `reviews.decision.${decision.decision.toLowerCase()}`)
}

async function fetchQueue(page = 1): Promise<void> {
	await reviewsStore.fetchSeniorQueue({
		stage: 'senior',
		page,
		pageSize: queue.value.pageSize,
	})
}

function openTransactionDetail(transactionId: string): void {
	router.push({ name: 'review-detail', params: { transactionId, stage: 'senior' } })
}

onMounted(() => {
	fetchQueue()
})
const QUEUE_OWN_COLUMN: QueueColumn = {
	key: 'advisoryDecision',
	label: t('pettycash', 'reviews.advisoryDecision')
}

const queueColumns = computed(() => queueColumnsAround(QUEUE_OWN_COLUMN, 4))

const queueCaption = computed(() => t('pettycash', 'reviews.seniorQueue'))

function queueCell(item: TransactionSummary, column: QueueColumn): VNode | string | number {
	if (column.key === QUEUE_OWN_COLUMN.key) {
		return h('span', { 'data-testid': 'advisory-decision' }, formatDecision(item.latestDecision))
	}

	return sharedQueueCell(item, column, openTransactionDetail)
}

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

/*
 * T389: the `.queue-table` rule that stood here answered a narrow viewport with
 * `display: block; overflow-x: auto; white-space: nowrap` -- which is a scrollbar, not a reflow, and
 * `nowrap` makes it worse by refusing to wrap even if there were room. The table markup is now
 * `ResponsiveTable`, whose narrow-viewport answer is one card per row. The header rule is kept: it is a
 * genuine layout adjustment and has nothing to do with clipping.
 */
@media (width <= 390px) {
	.queue-view__header {
		flex-direction: column;
		align-items: stretch;
	}
}
</style>

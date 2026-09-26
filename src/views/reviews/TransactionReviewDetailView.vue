<template>
	<div class="transaction-review-detail-view" dir="rtl" data-testid="transaction-detail">
		<nav class="breadcrumbs" :aria-label="t('pettycash', 'common.breadcrumbs')">
			<ol class="breadcrumbs__list">
				<li class="breadcrumbs__item">
					<RouterLink :to="{ name: 'dashboard' }">{{ t('pettycash', 'navigation.dashboard') }}</RouterLink>
				</li>
				<li class="breadcrumbs__item">
					<RouterLink :to="{ name: `review-queue-${stage.toLowerCase()}` }">
						{{ t('pettycash', `reviews.${stage.toLowerCase()}Queue`) }}
					</RouterLink>
				</li>
				<li class="breadcrumbs__item" aria-current="page">
					{{ t('pettycash', 'reviews.transactionDetail') }}
				</li>
			</ol>
		</nav>

		<div class="detail__panel">
			<div class="detail__header">
				<h2 class="detail__title">{{ t('pettycash', 'reviews.transactionDetail') }}</h2>
				<NcButton variant="tertiary" data-testid="back-btn" @click="goBack">
					<template #icon>
						<NcIconSvgWrapper :path="mdiArrowRight" />
					</template>
					{{ t('pettycash', 'common.back') }}
				</NcButton>
			</div>

			<div v-if="loading" class="detail__state" data-testid="loading">
				<NcLoadingIcon :size="44" />
				<p>{{ t('pettycash', 'common.loading') }}</p>
			</div>

			<div v-else-if="error" class="detail__state" data-testid="error">
				<NcIconSvgWrapper :path="mdiAlertCircle" :size="44" class="detail__state-icon--error" />
				<p>{{ error }}</p>
				<NcButton data-testid="retry-btn" @click="fetchTransaction">
					{{ t('pettycash', 'common.retry') }}
				</NcButton>
			</div>

			<div v-else-if="transaction" class="detail__content">
				<TransactionHeader :transaction="transaction" :stage="stage" />

				<hr class="detail__divider">

				<RevisionInfo
					:revision="transaction.activeRevision"
					:has-evidence="transaction.evidenceCount > 0" />

				<hr class="detail__divider">

				<EvidenceList :evidence="transaction.evidence" />

				<hr class="detail__divider">

				<DecisionForm
					:stage="stage"
					:transaction-id="transaction.id"
					:allowed-actions="transaction.allowedActions"
					:expected-version="transaction.version"
					@submit-decision="onDecisionRecorded"
					@cancel="goBack" />

				<hr class="detail__divider">

				<PriorDecisionHistory
					:decisions="transaction.decisions"
					:current-stage="stage" />
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { t } from '@nextcloud/l10n'
import { mdiAlertCircle, mdiArrowRight } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import DecisionForm from '@/components/review/DecisionForm.vue'
import EvidenceList from '@/components/review/EvidenceList.vue'
import PriorDecisionHistory from '@/components/review/PriorDecisionHistory.vue'
import RevisionInfo from '@/components/review/RevisionInfo.vue'
import TransactionHeader from '@/components/review/TransactionHeader.vue'
import { useReviewsStore } from '@/stores/reviews'

const route = useRoute()
const router = useRouter()
const reviewsStore = useReviewsStore()

const transactionId = computed(() => route.params.transactionId as string)
const stage = computed(() => (route.params.stage as string) || 'cost-center')
const transaction = computed(() => reviewsStore.selectedTransaction)
const loading = computed(() => reviewsStore.loading)
const error = computed(() => reviewsStore.error)

async function fetchTransaction(): Promise<void> {
	await reviewsStore.fetchTransactionForReview(transactionId.value)
}

function goBack(): void {
	router.push({ name: `review-queue-${stage.value.toLowerCase()}` })
}

async function onDecisionRecorded(): Promise<void> {
	await fetchTransaction()
}

watch([transactionId, stage], () => {
	fetchTransaction()
}, { immediate: true })
</script>

<style scoped>
.transaction-review-detail-view {
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

.detail__panel {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
}

.detail__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-block-end: 16px;
}

.detail__title {
	font-size: 1.25rem;
	font-weight: 600;
	margin: 0;
}

.detail__state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 32px 0;
	text-align: center;
}

.detail__state-icon--error {
	color: var(--color-error-text);
}

.detail__content {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.detail__divider {
	border: 0;
	border-block-start: 1px solid var(--color-border);
	margin: 0;
}

@media (width <= 390px) {
	.detail__content {
		gap: 12px;
	}

	.detail__header {
		flex-direction: column;
		align-items: stretch;
	}
}
</style>

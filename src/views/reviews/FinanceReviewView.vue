<template>
	<div class="finance-review" dir="rtl" data-testid="finance-review">
		<nav class="breadcrumbs" :aria-label="t('pettycash', 'common.breadcrumbs')">
			<ol class="breadcrumbs__list">
				<li class="breadcrumbs__item">
					<RouterLink :to="{ name: 'dashboard' }">{{ t('pettycash', 'navigation.dashboard') }}</RouterLink>
				</li>
				<li class="breadcrumbs__item">
					<RouterLink :to="{ name: 'review-queue-finance' }">
						{{ t('pettycash', 'reviews.financeQueue') }}
					</RouterLink>
				</li>
				<li class="breadcrumbs__item" aria-current="page">{{ t('pettycash', 'finance.title') }}</li>
			</ol>
		</nav>

		<div class="finance-review__panel">
			<div class="finance-review__header">
				<h2 class="finance-review__title">{{ t('pettycash', 'finance.title') }}</h2>
				<SettlementLabel
					v-if="transaction"
					:status="transaction.status"
					:final-outcome="transaction.finalOutcome" />
			</div>

			<!--
				FR-075: a communication failure shows a clear error and waits for a person. There is no
				timer, no retry counter and no automatic re-request anywhere in this view — a finance
				decision is a write against money, and silently re-sending it is how a double charge
				happens. The button is the only way the request is made again.
			-->
			<div v-if="loading" class="finance-review__state" data-testid="loading">
				<NcLoadingIcon :size="44" />
				<p>{{ t('pettycash', 'common.loading') }}</p>
			</div>

			<div v-else-if="error" class="finance-review__state" data-testid="error">
				<NcIconSvgWrapper :path="mdiAlertCircle" :size="44" class="finance-review__state-icon--error" />
				<p class="finance-review__error-message" data-testid="error-message">{{ error }}</p>
				<p class="finance-review__error-hint" data-testid="manual-retry-hint">
					{{ t('pettycash', 'finance.manualRetryRequired') }}
				</p>
				<NcButton data-testid="retry-btn" @click="loadTransaction">
					{{ t('pettycash', 'common.retry') }}
				</NcButton>
			</div>

			<NcEmptyContent
				v-else-if="!transaction"
				:name="t('pettycash', 'finance.title')"
				:description="t('pettycash', 'finance.transactionNotFound')"
				data-testid="transaction-missing" />

			<div v-else class="finance-review__content">
				<TransactionHeader :transaction="transaction" :stage="'FINANCE'" />

				<hr class="finance-review__divider">

				<!--
					The management decision finance is deciding on top of. FR-036 makes it the final
					management decision and FR-034's advisory rule has already applied one stage earlier,
					so what finance reads here is a settled management position, not a suggestion.
				-->
				<section class="finance-review__section" data-testid="management-decision">
					<h3 class="finance-review__section-title">{{ t('pettycash', 'finance.managementDecision') }}</h3>
					<PriorDecisionHistory
						:decisions="managementDecisions"
						current-stage="FINANCE" />
					<p class="finance-review__note" data-testid="management-note">
						{{ t('pettycash', 'finance.managementDecisionNote') }}
					</p>
				</section>

				<hr class="finance-review__divider">

				<RevisionInfo
					:revision="transaction.activeRevision"
					:has-evidence="transaction.evidenceCount > 0" />

				<hr class="finance-review__divider">

				<EvidenceList :evidence="transaction.evidence" />

				<hr class="finance-review__divider">

				<!--
					FR-039: finance must not approve a senior-rejected transaction, but may reject it or
					return it. The capability set from the server is what decides which buttons exist —
					`DecisionForm` renders from it rather than from a rule repeated here, so the client
					never becomes a second, divergent copy of the policy.
				-->
				<section class="finance-review__section" data-testid="finance-decision">
					<h3 class="finance-review__section-title">{{ t('pettycash', 'finance.decision') }}</h3>
					<p v-if="approveForbiddenReason" class="finance-review__note" data-testid="approve-forbidden-reason">
						{{ approveForbiddenReason }}
					</p>
					<DecisionForm
						:stage="'FINANCE'"
						:transaction-id="transaction.id"
						:allowed-actions="transaction.allowedActions"
						:expected-version="transaction.version"
						@submit-decision="onDecisionRecorded"
						@cancel="goBack" />
				</section>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { t } from '@nextcloud/l10n'
import { mdiAlertCircle } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import DecisionForm from '@/components/review/DecisionForm.vue'
import EvidenceList from '@/components/review/EvidenceList.vue'
import PriorDecisionHistory from '@/components/review/PriorDecisionHistory.vue'
import RevisionInfo from '@/components/review/RevisionInfo.vue'
import SettlementLabel from '@/components/review/SettlementLabel.vue'
import TransactionHeader from '@/components/review/TransactionHeader.vue'
import { useReviewsStore } from '@/stores/reviews'

const route = useRoute()
const router = useRouter()
const reviewsStore = useReviewsStore()

const transactionId = computed(() => String(route.params.transactionId ?? ''))
const transaction = computed(() => reviewsStore.selectedTransaction)
const loading = computed(() => reviewsStore.loading)
const error = computed(() => reviewsStore.error)

/**
 * The decisions finance inherits, newest last.
 *
 * The senior decision is the one that matters here (FR-036) and the cost-centre one is advisory
 * (FR-034), so both are shown in full and the label above them says which is which — filtering the
 * cost-centre decision out would hide the very thing FR-034 requires to stay visible.
 */
const managementDecisions = computed(() => transaction.value?.decisions ?? [])

/**
 * Why finance cannot approve, when it cannot.
 *
 * FR-039's rule is enforced server-side by `FinanceDecisionPolicy` and arrives as a capability, so
 * this is not a client-side restatement of the policy — it is the reason to *show*, so that an
 * officer who finds approve unavailable is told why rather than left to guess whether the button is
 * broken. It reads the same signal the buttons are built from, so the two cannot disagree.
 */
const approveForbiddenReason = computed(() => {
	if (!transaction.value) return ''
	if (transaction.value.allowedActions?.canApprove) return ''
	if (transaction.value.status !== 'SENIOR_REJECTED') return ''
	return t('pettycash', 'finance.cannotApproveSeniorRejected')
})

async function loadTransaction(): Promise<void> {
	if (!transactionId.value) return
	// The store rethrows, so a failed load is caught here rather than surfacing as an unhandled
	// rejection: FR-075 requires the failure to be *shown* with a manual retry, and an unhandled
	// rejection would leave the view blank instead.
	try {
		await reviewsStore.fetchTransactionForReview(transactionId.value)
	} catch {
		// The store has already recorded the error in `error`, which the template renders.
	}
}

function goBack(): void {
	router.push({ name: 'review-queue-finance' })
}

async function onDecisionRecorded(): Promise<void> {
	// Re-read rather than patch locally: a finance decision writes a movement, a balance, a final
	// state and an audit row, and the settlement label on this page depends on all of them.
	await loadTransaction()
}

watch(
	[transactionId],
	() => {
		loadTransaction()
	},
	{ immediate: true }
)
</script>

<style scoped>
.finance-review {
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
	flex-wrap: wrap;
}

.breadcrumbs__item + .breadcrumbs__item::before {
	content: '/';
	margin-inline-end: 8px;
	color: var(--color-text-maxcontrast);
}

.finance-review__panel {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
}

.finance-review__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-block-end: 16px;
}

.finance-review__title {
	font-size: var(--font-size-section);
	font-weight: 600;
	margin: 0;
}

.finance-review__state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 32px 0;
	text-align: center;
}

.finance-review__state-icon--error {
	color: var(--color-error-text);
}

.finance-review__error-message {
	font-weight: 600;
}

.finance-review__error-hint {
	color: var(--color-text-maxcontrast);
}

.finance-review__content {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.finance-review__section {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.finance-review__section-title {
	font-size: var(--font-size-emphasis);
	font-weight: 600;
	margin: 0;
}

.finance-review__note {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.finance-review__divider {
	border: 0;
	border-block-start: 1px solid var(--color-border);
	margin: 0;
}

@media (width <= 390px) {
	.finance-review__content {
		gap: 12px;
	}

	.finance-review__header {
		flex-direction: column;
		align-items: stretch;
	}
}
</style>

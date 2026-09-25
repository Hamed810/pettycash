<template>
	<div class="transaction-header" data-testid="transaction-header">
		<div class="header-row">
			<div class="header-item">
				<span class="header-label">{{ t('pettycash', 'reviews.transactionId') }}</span>
				<span class="header-value">{{ transaction.id }}</span>
			</div>
			<div class="header-item">
				<span class="header-label">{{ t('pettycash', 'reviews.stage') }}</span>
				<span class="header-value">
					<StatusBadge :status="transaction.status" />
				</span>
			</div>
		</div>
		<div class="header-row">
			<div class="header-item">
				<span class="header-label">{{ t('pettycash', 'reviews.owner') }}</span>
				<span class="header-value">{{ transaction.activeRevision.createdBy }}</span>
			</div>
			<div class="header-item">
				<span class="header-label">{{ t('pettycash', 'reviews.sheetId') }}</span>
				<span class="header-value">{{ transaction.sheetId }}</span>
			</div>
		</div>
		<div v-if="transaction.finalOutcome" class="header-row">
			<div class="header-item">
				<span class="header-label">{{ t('pettycash', 'reviews.finalOutcome') }}</span>
				<span class="header-value">
					<NcChip
						:variant="transaction.finalOutcome === 'APPROVED' ? 'success' : 'error'"
						:text="t('pettycash', `reviews.outcome.${transaction.finalOutcome.toLowerCase()}`)" />
				</span>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcChip from '@nextcloud/vue/components/NcChip'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import type { TransactionSummary } from '@/types/api'

interface Props {
	transaction: TransactionSummary
	stage: string
}

defineProps<Props>()
</script>

<style scoped>
.transaction-header {
	background-color: var(--color-background-dark);
	padding: 16px;
	border-radius: var(--border-radius-large, 8px);
}

.header-row {
	display: flex;
	gap: 24px;
	flex-wrap: wrap;
}

.header-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 150px;
}

.header-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
}

.header-value {
	font-size: 14px;
	font-weight: 500;
}

@media (width <= 390px) {
	.header-row {
		flex-direction: column;
		gap: 12px;
	}

	.header-item {
		min-width: 100%;
	}
}
</style>

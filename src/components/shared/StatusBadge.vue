<template>
	<NcChip
		:variant="statusVariant"
		class="status-badge"
		:data-testid="`status-badge-${status.toLowerCase()}`"
	>
		{{ label }}
	</NcChip>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import NcChip from '@nextcloud/vue/components/NcChip'

interface Props {
	status: string
}

const props = defineProps<Props>()

const statusVariant = computed(() => {
	switch (props.status) {
		case 'DRAFT':
		case 'AWAITING_COST_CENTER_REVIEW':
		case 'AWAITING_SENIOR_REVIEW':
		case 'AWAITING_FINANCE_REVIEW':
		case 'AWAITING_PURCHASER_CORRECTION':
			return 'primary' as const
		case 'COST_CENTER_APPROVED':
		case 'SENIOR_APPROVED':
		case 'COMPLETED_APPROVED':
			return 'success' as const
		case 'COST_CENTER_REJECTED':
		case 'SENIOR_REJECTED':
		case 'COMPLETED_REJECTED':
			return 'error' as const
		default:
			return 'secondary' as const
	}
})

const label = computed(() => {
	const statusMap: Record<string, string> = {
		DRAFT: t('pettycash', 'reviews.status.draft'),
		AWAITING_COST_CENTER_REVIEW: t('pettycash', 'reviews.status.awaitingCostCenterReview'),
		COST_CENTER_APPROVED: t('pettycash', 'reviews.status.costCenterApproved'),
		COST_CENTER_REJECTED: t('pettycash', 'reviews.status.costCenterRejected'),
		AWAITING_SENIOR_REVIEW: t('pettycash', 'reviews.status.awaitingSeniorReview'),
		SENIOR_APPROVED: t('pettycash', 'reviews.status.seniorApproved'),
		SENIOR_REJECTED: t('pettycash', 'reviews.status.seniorRejected'),
		AWAITING_FINANCE_REVIEW: t('pettycash', 'reviews.status.awaitingFinanceReview'),
		AWAITING_PURCHASER_CORRECTION: t('pettycash', 'reviews.status.awaitingPurchaserCorrection'),
		COMPLETED_APPROVED: t('pettycash', 'reviews.status.completedApproved'),
		COMPLETED_REJECTED: t('pettycash', 'reviews.status.completedRejected'),
	}
	return statusMap[props.status] || props.status
})
</script>

<style scoped>
.status-badge {
	font-size: 12px;
	font-weight: 500;
}

@media (width <= 390px) {
	.status-badge {
		font-size: 12px;
	}
}
</style>

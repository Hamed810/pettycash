<template>
	<NcChip
		:variant="statusVariant"
		class="status-badge"
		:data-testid="`status-badge-${status.toLowerCase()}`"
		no-close
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
	// UI-PUR-002: the sheet summary statuses are a different vocabulary from the per-review ones
	// above. They reached this component through the sheet list and previously fell through to
	// `secondary`, printing the raw enum (`COST_CENTER`) in a Persian interface. The pill's colour
	// distinguishes "still moving" from "returned for correction" from "finished", using the same
	// design-token variants as every other status rather than a bespoke colour per status.
	case 'OPEN':
	case 'COST_CENTER':
	case 'SENIOR':
	case 'FINANCE':
		return 'primary' as const
	case 'CORRECTION':
		return 'warning' as const
	case 'COMPLETED':
	case 'COMPLETED_WITH_REJECTIONS':
		return 'success' as const
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
		// Sheet summary statuses (UI-PUR-002). The catalogue already carried these labels for the
		// sheet status control; the list simply never asked for them.
		OPEN: t('pettycash', 'sheets.status.open'),
		COST_CENTER: t('pettycash', 'sheets.status.cost_center'),
		SENIOR: t('pettycash', 'sheets.status.senior'),
		FINANCE: t('pettycash', 'sheets.status.finance'),
		CORRECTION: t('pettycash', 'sheets.status.correction'),
		COMPLETED: t('pettycash', 'sheets.status.completed'),
		COMPLETED_WITH_REJECTIONS: t('pettycash', 'sheets.status.completed_with_rejections'),
	}
	return statusMap[props.status] || props.status
})
</script>

<style scoped>
.status-badge {
	font-size: var(--font-size-floor);
	font-weight: 500;
}

@media (width <= 390px) {
	.status-badge {
		font-size: var(--font-size-floor);
	}
}
</style>

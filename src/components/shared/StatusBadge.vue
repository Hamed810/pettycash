<template>
	<NcChip
		:variant="statusVariant"
		:class="isReviewStatus ? 'status-badge status-badge--review' : 'status-badge'"
		:data-testid="`status-badge-${status.toLowerCase()}`"
		:title="internalLabel"
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
	/**
	 * Compact display override. The workspace table prints the reviewing stage alone so the chip
	 * stays single-line; the shared surfaces keep the full vocabulary by omitting the prop.
	 */
	label?: string
}

const props = defineProps<Props>()

/**
 * The approved pill colours, per state (visual-first owner directive, 2026-10-21).
 *
 * The approved sheets list gives the sheet statuses their palette: OPEN green
 * (`references/desktop/01`), under-cost-center-review amber, under-senior/finance review the
 * review purple, completed gray. The approved workspace gives a transaction that was returned to
 * the purchaser that same purple (`references/desktop/03`, "RETURNED"), while its awaiting-review
 * chips stay blue. Previously everything mid-flight was one blue chip; the decisive brief is that
 * missing backend capability may colour nothing out of existence, and the palette is purely
 * presentational -- no status is driven, reordered or derived by these classes.
 *
 * The review purple is not a Nextcloud variant, so it rides a class (`status-badge--review`) whose
 * colour comes from the `--pettycash-review` token declared on `.pettycash-shell` rather than a
 * hardcoded value here.
 */
const REVIEW_STATUSES = new Set(['SENIOR', 'FINANCE', 'CORRECTION', 'AWAITING_PURCHASER_CORRECTION'])

const statusVariant = computed(() => {
	switch (props.status) {
	case 'DRAFT':
	case 'AWAITING_COST_CENTER_REVIEW':
	case 'AWAITING_SENIOR_REVIEW':
	case 'AWAITING_FINANCE_REVIEW':
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
	// `secondary`, printing the raw enum (`COST_CENTER`) in a Persian interface.
	// The approved list (reference 01) colours them: OPEN green, cost-center amber, the two
	// remaining review stages the review purple (variant rides the class below), and the completed
	// pair gray.
	case 'OPEN':
		return 'success' as const
	case 'COST_CENTER':
		return 'warning' as const
	// REVIEW_STATUSES keep a valid variant (`secondary`) and carry their hue via the class.
	case 'SENIOR':
	case 'FINANCE':
	case 'CORRECTION':
	case 'AWAITING_PURCHASER_CORRECTION':
		return 'secondary' as const
	case 'COMPLETED':
	case 'COMPLETED_WITH_REJECTIONS':
		return 'secondary' as const
	default:
		return 'secondary' as const
	}
})

/** True for the statuses the approved design hues with the review purple. */
const isReviewStatus = computed(() => REVIEW_STATUSES.has(props.status))

const internalLabel = computed(() => {
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

const label = computed(() => props.label ?? internalLabel.value)
</script>

<style scoped>
.status-badge {
	font-size: var(--font-size-floor);
	font-weight: 600;
	border: 1px solid transparent;
}

/*
 * The approved surfaces use a quiet status pill: a tinted background, a readable foreground and no
 * filled primary/blue block. The colour still comes from the host theme, so light and dark themes
 * remain legible and no brand colour is hardcoded here.
 */
.status-badge.nc-chip--primary {
	background-color: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
	border-color: color-mix(in srgb, var(--color-primary-element) 22%, transparent);
	color: var(--color-primary-element);
}

.status-badge.nc-chip--success {
	background-color: color-mix(in srgb, var(--color-success) 14%, transparent);
	border-color: color-mix(in srgb, var(--color-success) 24%, transparent);
	color: var(--color-success-text, var(--color-success));
}

.status-badge.nc-chip--warning {
	background-color: color-mix(in srgb, var(--color-warning) 16%, transparent);
	border-color: color-mix(in srgb, var(--color-warning) 28%, transparent);
	color: var(--color-warning-text, var(--color-warning));
}

.status-badge.nc-chip--error {
	background-color: color-mix(in srgb, var(--color-error) 14%, transparent);
	border-color: color-mix(in srgb, var(--color-error) 24%, transparent);
	color: var(--color-error-text, var(--color-error));
}

.status-badge.nc-chip--secondary {
	background-color: var(--color-background-hover);
	border-color: var(--color-border);
	color: var(--color-main-text);
}

/*
 * The approved "in review"/returned purple. No Nextcloud variant carries it, so the hue rides the
 * `--pettycash-review` token declared once on `.pettycash-shell` and re-keyed for the dark theme;
 * the background and border mix the hue over transparency so the pill stays quiet on both themes.
 * The extra class raises the specificity above the plain-secondary rule, which is what pulls a
 * review chip away from Gray.
 */
.status-badge.nc-chip--secondary.status-badge--review {
	background-color: color-mix(in srgb, var(--pettycash-review) 12%, transparent);
	border-color: color-mix(in srgb, var(--pettycash-review) 22%, transparent);
	color: var(--pettycash-review);
}

@media (width <= 390px) {
	.status-badge {
		font-size: var(--font-size-floor);
	}
}
</style>

<template>
	<!--
		`span` with an explicit `role="status"`: the label is a state a screen reader must be able to
		read on its own, and NcChip's own semantics are not a contract this app should depend on for
		it. The state is always in the text, so it survives a colour-blind reader, a monochrome print
		and a high-contrast theme (WCAG 1.4.1).
	-->
	<span
		class="settlement-label"
		:class="`settlement-label--${tone}`"
		role="status"
		:data-testid="`settlement-label-${state}`"
		:data-settled="settled ? 'true' : 'false'">
		<NcIconSvgWrapper :path="icon" :size="20" class="settlement-label__icon" />
		<span class="settlement-label__text" data-testid="settlement-label-text">{{ label }}</span>
	</span>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import { mdiAlertCircleOutline, mdiCheckCircleOutline, mdiClockOutline, mdiCloseCircleOutline } from '@mdi/js'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'

interface Props {
	/**
	 * The transaction's workflow status.
	 *
	 * `SENIOR_APPROVED` and `SENIOR_REJECTED` are the states that make this component necessary: they
	 * are the senior manager's *final management decision* (FR-036), and the money has not moved.
	 */
	status: string
	/** `APPROVED` or `REJECTED` once finance has decided, `null` before that. */
	finalOutcome?: string | null
}

const props = defineProps<Props>()

/**
 * Whether the money has actually moved.
 *
 * Only a finance approval settles a transaction. FR-040 makes the consumption movement the
 * settlement, and FR-036 makes the senior decision final *as a management decision* and explicitly
 * not something finance may treat as settled. So this is derived from `finalOutcome` alone, and a
 * senior approval is `false` by construction rather than by omission — the alternative, inferring
 * it from a non-empty `finalOutcome` of any kind, is exactly the conflation `contracts/exports.md`
 * forbids ("do not describe pre-finance approval as settled").
 */
const settled = computed(() => props.finalOutcome === 'APPROVED')

/** The three states the label distinguishes, named for what they mean rather than for a status. */
type SettlementState = 'settled' | 'rejected' | 'management_decided' | 'awaiting_finance'

const state = computed<SettlementState>(() => {
	if (props.finalOutcome === 'APPROVED') return 'settled'
	if (props.finalOutcome === 'REJECTED') return 'rejected'
	if (props.status === 'SENIOR_APPROVED' || props.status === 'SENIOR_REJECTED') {
		return 'management_decided'
	}
	return 'awaiting_finance'
})

const label = computed(() => {
	switch (state.value) {
		case 'settled':
			return t('pettycash', 'finance.settled')
		case 'rejected':
			return t('pettycash', 'finance.rejectedNotSettled')
		case 'management_decided':
			// Names *which* decision was reached, because "approved" is exactly the word that gets
			// misread as money having moved.
			return props.status === 'SENIOR_REJECTED'
				? t('pettycash', 'finance.seniorRejectedNotSettled')
				: t('pettycash', 'finance.seniorApprovedNotSettled')
		default:
			return t('pettycash', 'finance.awaitingFinance')
	}
})

/**
 * Tone is decorative. The text carries the state; these are Nextcloud's own semantic variables so
 * the label follows the host theme rather than hardcoding a palette, and every tone here is paired
 * with a distinct icon and distinct wording.
 */
const tone = computed(() => {
	switch (state.value) {
		case 'settled':
			return 'settled'
		case 'rejected':
			return 'rejected'
		case 'management_decided':
			return 'management'
		default:
			return 'pending'
	}
})

const icon = computed(() => {
	switch (state.value) {
		case 'settled':
			return mdiCheckCircleOutline
		case 'rejected':
			return mdiCloseCircleOutline
		case 'management_decided':
			return mdiClockOutline
		default:
			return mdiAlertCircleOutline
	}
})
</script>

<style scoped>
.settlement-label {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	padding: 4px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	font-size: var(--font-size-floor);
	font-weight: 500;

	/* The label is read in a table cell and must wrap rather than widen the row: a single line that
	   cannot break is one of the two ways a table forces horizontal scroll on a 390px viewport. */
	overflow-wrap: anywhere;
}

.settlement-label--settled {
	color: var(--color-success-text);
	border-color: var(--color-success);
}

.settlement-label--rejected {
	color: var(--color-error-text);
	border-color: var(--color-error);
}

.settlement-label--management {
	color: var(--color-warning-text);
	border-color: var(--color-warning);
}

.settlement-label--pending {
	color: var(--color-text-maxcontrast);
}

.settlement-label__icon {
	flex: 0 0 auto;
}

@media (width <= 390px) {
	.settlement-label {
		font-size: var(--font-size-floor);
	}
}
</style>

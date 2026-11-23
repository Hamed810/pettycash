<template>
	<div class="review-decide-actions" data-testid="review-decide-actions">
		<span v-if="label !== null" class="review-decide-actions__label" :data-testid="`${testIdPrefix}-label`">
			{{ label }}
		</span>
		<div class="review-decide-actions__group">
			<NcButton
				v-for="kind in kinds"
				:key="kind"
				:variant="variantFor(kind)"
				:disabled="busy || !canDecide(kind)"
				:data-testid="`${testIdPrefix}-${kind.toLowerCase()}`"
				@click="emit('decide', kind)"
			>
				{{ t('pettycash', `reviews.decision.${kind.toLowerCase()}`) }}
			</NcButton>
		</div>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { decisionAllowed } from '@/types/api'
import type { AllowedActions, DecisionValue } from '@/types/api'

/**
 * The one reviewer decide-bar implementation (UXC-V2-07, owner-approved v2 baseline), rendered in
 * both card contexts that offer current-stage decisions: the collapsed card's always-visible bar
 * and the expanded transaction's proximity row. The approved hierarchy is fixed here so the two
 * contexts stay visually consistent: **Approve is the only filled/primary action**; Return uses the
 * warning semantic in a quiet outline; Reject uses the error semantic in a quiet outline (see the
 * scoped styles). Display order, which kinds are offered and the accessible labels stay the
 * caller's decision; whether a button is enabled rests only on the server's own capability grant
 * (`allowedActions`) plus the caller's busy state — this component adds no rule of its own.
 */
interface Props {
	/** The decision kinds this surface offers, in display order. */
	kinds: DecisionValue[]
	/** The server's capability set; a button is enabled only on an explicit `true`. */
	allowedActions?: AllowedActions | null
	/** True while a decision for this transaction is in flight, so a second cannot start. */
	busy?: boolean
	/** Optional human label rendered above the group (the expanded row's stage label). */
	label?: string | null
	/** Prefix for the per-kind `data-testid` (`<prefix>-<kind>`), preserving each context's contract. */
	testIdPrefix?: string
}

const props = withDefaults(defineProps<Props>(), {
	allowedActions: null,
	busy: false,
	label: null,
	testIdPrefix: 'review-decide',
})

const emit = defineEmits<{
	decide: [kind: DecisionValue]
}>()

function canDecide(kind: DecisionValue): boolean {
	return decisionAllowed(props.allowedActions, kind)
}

/** The approved v2 mapping: Approve filled/primary, Return warning, Reject restrained destructive. */
function variantFor(kind: DecisionValue): 'primary' | 'warning' | 'error' {
	switch (kind) {
	case 'APPROVE':
		return 'primary'
	case 'REJECT':
		return 'error'
	default:
		return 'warning'
	}
}
</script>

<style scoped>
.review-decide-actions {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	min-inline-size: 0;
}

.review-decide-actions__group {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.review-decide-actions__label {
	flex-basis: 100%;
	color: var(--color-text-maxcontrast);
	font-weight: 700;
}

/*
 * Quiet outline treatments (UXC-V2-07): Approve keeps the filled primary variant untouched; Return
 * and Reject keep their semantic variant classes (`button-vue--warning` / `button-vue--error`, so
 * meaning survives in the DOM) but drop the filled background for the approved restrained outline —
 * Approve remains the only filled action in the group. Scoped to this component's own buttons.
 */
.review-decide-actions__group :deep(.button-vue--warning),
.review-decide-actions__group :deep(.button-vue--error) {
	background-color: transparent;
	color: var(--color-warning-text);
}

.review-decide-actions__group :deep(.button-vue--warning) {
	border-color: color-mix(in srgb, var(--color-warning) 45%, transparent);
	color: var(--color-warning-text);
}

.review-decide-actions__group :deep(.button-vue--error) {
	border-color: color-mix(in srgb, var(--color-error) 45%, transparent);
	color: var(--color-error-text);
}

.review-decide-actions__group :deep(.button-vue--warning:hover:not(:disabled)),
.review-decide-actions__group :deep(.button-vue--error:hover:not(:disabled)) {
	background-color: var(--color-background-hover);
}

.review-decide-actions__group :deep(.button-vue--warning:active),
.review-decide-actions__group :deep(.button-vue--error:active) {
	background-color: color-mix(in srgb, var(--color-warning) 12%, transparent);
}

.review-decide-actions__group :deep(.button-vue--error:active) {
	background-color: color-mix(in srgb, var(--color-error) 12%, transparent);
}

@media (width <= 640px) {
	.review-decide-actions {
		align-items: stretch;
		flex-direction: column;
	}

	.review-decide-actions__group {
		flex-direction: column;
		align-items: stretch;
	}

	.review-decide-actions__group :deep(.button-vue) {
		inline-size: 100%;

		/* The approved 390px floor — reviewer decisions are primary finger targets. */
		min-block-size: 44px;
		justify-content: center;
	}
}
</style>

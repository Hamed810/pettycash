<template>
	<div class="review-stage-markers" :dir="direction" data-testid="review-stage-markers">
		<span
			v-for="marker in markers"
			:key="marker.key"
			class="review-stage-markers__stage"
			:class="`review-stage-markers__stage--${marker.stateClass}`"
			role="img"
			:aria-label="marker.label"
			:data-testid="`${testIdPrefix}-${marker.key}`"
		>
			<!--
				Stage identity: a restrained icon plus the stage label as plain text — visible without
				hover at every width (UXC-V2-02 / v2 baseline). It is presentational duplication of the
				accessible name below, hence aria-hidden.
			-->
			<span class="review-stage-markers__identity" aria-hidden="true">
				<NcIconSvgWrapper :path="marker.icon" :size="14" />
				<span class="review-stage-markers__identity-label">{{ marker.stageLabel }}</span>
			</span>
			<!--
				State marker: deliberately separate from the identity — a small semantic pill whose
				glyph shape distinguishes the state, so meaning never rests on colour alone. Approved ✓
				(refined green), Rejected ✕ (restrained red), Returned ↩ (amber), No decision yet •
				(neutral). Read-only: no button, no checkbox, no action, not focusable — there is no
				tooltip to reach and no decision to make here.
			-->
			<span class="review-stage-markers__marker" aria-hidden="true">{{ marker.stateGlyph }}</span>
			<!--
				The full `Stage — State` words stay in the element's text content for assistive
				technology and text readers; the accessible name above is composed from the same keys.
			-->
			<span class="review-stage-markers__sr">{{ marker.label }}</span>
		</span>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import { mdiAccountTieOutline, mdiCashMultiple, mdiOfficeBuildingOutline } from '@mdi/js'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { direction } from '@/utils/direction'
import type { Decision } from '@/types/api'

/**
 * The three canonical review stages in fixed semantic workflow order.
 */
export type ReviewStageKey = 'costCenter' | 'senior' | 'finance'

/**
 * One stage's previous decision as the caller supplies it. `null` (or an absent key) means the
 * stage has no recorded decision for the transaction's current revision — the marker then reads
 * "No decision yet"; the row never guesses a decision it was not given.
 */
export type ReviewStageDecisions = Partial<Record<ReviewStageKey, Decision | null>>

interface Props {
	/** Per-stage previous decisions, in the component's canonical order. */
	decisions?: ReviewStageDecisions | null
	/**
	 * Prefix for the per-stage `data-testid` (`<prefix>-<stageKey>`). Callers that already own a
	 * selector contract pass their own prefix; the default names the component itself.
	 */
	testIdPrefix?: string
}

const props = withDefaults(defineProps<Props>(), {
	decisions: null,
	testIdPrefix: 'review-stage-marker',
})

type StateClass = 'approve' | 'reject' | 'return' | 'none'

interface StageMarker {
	key: ReviewStageKey
	/** The composed `Stage — State` label: accessible name and text content, identical by construction. */
	label: string
	stageLabel: string
	icon: string
	stateGlyph: string
	stateClass: StateClass
}

/**
 * The glyph, state wording and semantic class of one stage's decision. The undecided state is a
 * status like the decided ones — the neutral marker, not an absence.
 */
function presentationFor(decision: Decision | null): { stateGlyph: string; stateLabelKey: string; stateClass: StateClass } {
	switch (decision?.decision) {
	case 'APPROVE':
		return { stateGlyph: '✓', stateLabelKey: 'reviews.outcome.approved', stateClass: 'approve' }
	case 'REJECT':
		return { stateGlyph: '✕', stateLabelKey: 'reviews.outcome.rejected', stateClass: 'reject' }
	case 'RETURN':
		return { stateGlyph: '↩', stateLabelKey: 'reviews.quickReview.card.returned', stateClass: 'return' }
	default:
		return { stateGlyph: '•', stateLabelKey: 'reviews.decisionStage.noDecision', stateClass: 'none' }
	}
}

/**
 * The three canonical stage markers in fixed workflow order. A stage the caller left empty renders
 * its "No decision yet" marker — an undecided stage is a fact about the workflow, not a reason to
 * hide the row. Every label is composed from the existing stage and state catalogue keys; the
 * catalogue, not this component, owns the words.
 */
const markers = computed<StageMarker[]>(() => {
	const stages: Array<{ key: ReviewStageKey; stageLabelKey: string; icon: string }> = [
		{ key: 'costCenter', stageLabelKey: 'reviews.decisionStage.costCenter', icon: mdiOfficeBuildingOutline },
		{ key: 'senior', stageLabelKey: 'reviews.decisionStage.senior', icon: mdiAccountTieOutline },
		{ key: 'finance', stageLabelKey: 'reviews.decisionStage.finance', icon: mdiCashMultiple },
	]
	return stages.map(({ key, stageLabelKey, icon }) => {
		const decision = props.decisions?.[key] ?? null
		const { stateGlyph, stateLabelKey, stateClass } = presentationFor(decision)
		const stageLabel = t('pettycash', stageLabelKey)
		const label = `${stageLabel} — ${t('pettycash', stateLabelKey)}`
		return { key, label, stageLabel, icon, stateGlyph, stateClass }
	})
})
</script>

<style scoped>
/*
 * Compact stage identity + state marker row (UXC-V2-02, owner-approved v2 baseline): one visually
 * light line suitable for scanning many transaction cards — no panels, no track, no ledger. The
 * stage identity is plain text beside a small icon; the state marker is a separate small semantic
 * pill. The row wraps when it must instead of ever scrolling horizontally.
 */
.review-stage-markers {
	display: flex;
	align-items: center;
	gap: 4px 16px;
	flex-wrap: wrap;
	min-inline-size: 0;
}

.review-stage-markers__stage {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	min-inline-size: 0;
}

.review-stage-markers__identity {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	color: var(--color-text-maxcontrast);
}

.review-stage-markers__identity :deep(.nc-icon-svg) {
	color: var(--color-text-maxcontrast);
}

.review-stage-markers__identity-label {
	font-size: var(--font-size-floor);
	font-weight: 600;
	line-height: 1.3;
	white-space: nowrap;
}

.review-stage-markers__marker {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	inline-size: 22px;
	block-size: 22px;
	flex: none;
	border: 1px solid transparent;
	border-radius: 999px;
	font-size: var(--font-size-floor);
	font-weight: 700;
	line-height: 1;
}

.review-stage-markers__stage--approve .review-stage-markers__marker {
	border-color: color-mix(in srgb, var(--color-success) 60%, transparent);
	background-color: color-mix(in srgb, var(--color-success) 20%, transparent);
	color: var(--color-success);
}

.review-stage-markers__stage--reject .review-stage-markers__marker {
	border-color: color-mix(in srgb, var(--color-error) 60%, transparent);
	background-color: color-mix(in srgb, var(--color-error) 20%, transparent);
	color: var(--color-error);
}

/* The Returned presentation is the warning semantic (amber), not the purple review accent. */
.review-stage-markers__stage--return .review-stage-markers__marker {
	border-color: color-mix(in srgb, var(--color-warning) 60%, transparent);
	background-color: color-mix(in srgb, var(--color-warning) 20%, transparent);
	color: var(--color-warning);
}

.review-stage-markers__stage--none .review-stage-markers__marker {
	border-color: color-mix(in srgb, var(--color-text-maxcontrast) 45%, transparent);
	background-color: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
}

/* Forced-colors mode: state meaning must survive a forced palette. The border carries the hue so
   the marker remains distinguishable even when background colours are forced away. */
@media (forced-colors: active) {
	.review-stage-markers__stage--approve .review-stage-markers__marker {
		border: 1px solid CanvasText;
		background-color: Canvas;
		color: CanvasText;
	}

	.review-stage-markers__stage--reject .review-stage-markers__marker,
	.review-stage-markers__stage--return .review-stage-markers__marker {
		border: 1px solid CanvasText;
		background-color: Canvas;
		color: CanvasText;
	}

	.review-stage-markers__stage--none .review-stage-markers__marker {
		border: 1px solid CanvasText;
		background-color: Canvas;
		color: CanvasText;
	}
}

/* The full `Stage — State` words: present for assistive technology and text readers, never printed. */
.review-stage-markers__sr {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
	border: 0;
}
</style>

<template>
	<div
		class="prior-decision-history"
		:class="{ 'prior-decision-history--compact': isCompact }"
		data-testid="prior-decision-history"
	>
		<h3 class="prior-decision-history__title">{{ headingText }}</h3>

		<!--
			Empty presentation. The default stays the established empty content; a workflow stage group
			supplies `emptyText` (added at UXC-4-04 for the former Transaction Detail Drawer, now fed
			by the inline expansion's stage groups) so a stage with no recorded decision renders its
			explicit "No decision yet" state instead of a generic empty block.
		-->
		<p v-if="decisions.length === 0 && emptyText !== null" class="prior-decision-history__empty" data-testid="prior-decision-history-empty">
			{{ emptyText }}
		</p>
		<NcEmptyContent
			v-else-if="decisions.length === 0"
			:description="t('pettycash', 'reviews.noPriorDecisions')"
			:name="t('pettycash', 'reviews.priorDecisions')"
		/>

		<ul v-else class="decision-history" data-testid="decision-history-list">
			<li
				v-for="decision in decisions"
				:key="decision.id"
				class="decision-item"
				:class="{ 'decision-item--compact': isCompact }"
				:data-testid="`decision-item-${decision.id}`"
			>
				<!--
					The established (pre-v2) presentation: stage chip, decision pill, actor line,
					explanation, time — each its own row. The compact variant (UXC-V2-08, owner-approved v2
					baseline) leads with the decision, puts actor and time on one secondary line, and keeps
					the explanation only when present — three concise stage rows, not an audit log. The
					stage chip is dropped there because the group heading already names the stage.
				-->
				<template v-if="isCompact">
					<div class="decision-header">
						<span
							class="decision-type"
							:class="`decision-${decision.decision.toLowerCase()}`"
							data-testid="decision-type"
						>
							{{ t('pettycash', `reviews.decision.${decision.decision.toLowerCase()}`) }}
						</span>
						<NcChip
							v-if="isAdvisory(decision)"
							variant="warning"
							class="advisory-badge"
							data-testid="advisory-badge"
							:text="t('pettycash', 'reviews.advisory')"
						/>
					</div>
					<div class="decision-actor decision-actor--compact">
						<strong>{{ decision.actor.displayName }}</strong>
						<span class="actor-role">({{ t('pettycash', `roles.${decision.actor.role.toLowerCase()}`) }})</span>
						<span class="decision-actor-sep" aria-hidden="true">&#183;</span>
						<span class="decision-time">{{ formatDateTime(decision.decidedAt) }}</span>
					</div>
					<div v-if="decision.explanation" class="decision-explanation">
						{{ decision.explanation }}
					</div>
				</template>
				<template v-else>
					<div class="decision-header">
						<span class="decision-stage" data-testid="decision-stage">
							{{ t('pettycash', `reviews.stage.${decision.stage.toLowerCase()}`) }}
						</span>
						<NcChip
							v-if="isAdvisory(decision)"
							variant="warning"
							class="advisory-badge"
							data-testid="advisory-badge"
							:text="t('pettycash', 'reviews.advisory')"
						/>
						<span
							class="decision-type"
							:class="`decision-${decision.decision.toLowerCase()}`"
							data-testid="decision-type"
						>
							{{ t('pettycash', `reviews.decision.${decision.decision.toLowerCase()}`) }}
						</span>
					</div>
					<div class="decision-actor">
						<strong>{{ decision.actor.displayName }}</strong>
						<span class="actor-role">({{ t('pettycash', `roles.${decision.actor.role.toLowerCase()}`) }})</span>
					</div>
					<div v-if="decision.explanation" class="decision-explanation">
						{{ decision.explanation }}
					</div>
					<div class="decision-time">
						{{ formatDateTime(decision.decidedAt) }}
					</div>
				</template>
			</li>
		</ul>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { formatSheetDateTime } from '@/utils/date'

type WorkflowDecision = 'APPROVE' | 'REJECT' | 'RETURN'

interface Props {
	decisions: Array<{
		id: string
		stage: 'COST_CENTER' | 'SENIOR' | 'FINANCE'
		decision: WorkflowDecision
		explanation?: string
		actor: {
			userId: string
			displayName: string
			role: string
		}
		decidedAt: string
	}>
	currentStage?: string
	/**
	 * Additive (UXC-4-04): overrides the section heading so a per-stage group can title itself.
	 * `null`/absent keeps the established "Prior decisions" heading, so every existing consumer
	 * renders exactly as before.
	 */
	heading?: string | null
	/**
	 * Additive (UXC-4-04): replaces the generic empty content with one explicit line (the stage's
	 * "No decision yet"). `null`/absent keeps the established `NcEmptyContent` empty state.
	 */
	emptyText?: string | null
	/**
		 * Additive (UXC-V2-08): `compact` renders the v2 presentation — decision-lead rows, actor and
		 * time on one secondary line, explanation only when present, no per-item stage chip (the group
		 * heading names the stage), lighter separators. The default keeps the established (pre-v2)
		 * presentation byte-for-byte, so every existing consumer renders exactly as before.
		 */
	variant?: 'default' | 'compact'
}

const props = withDefaults(defineProps<Props>(), {
	currentStage: undefined,
	heading: null,
	emptyText: null,
	variant: 'default',
})

const isCompact = computed(() => props.variant === 'compact')

const headingText = computed(() => props.heading ?? t('pettycash', 'reviews.priorDecisions'))

/**
 * T053: whether a decision renders as advisory.
 *
 * The router delivers stages lowercase and hyphenated (`senior`, `cost-center`)
 * while decisions carry uppercase enum stages (`SENIOR`, `COST_CENTER`), and both
 * spellings reach this prop depending on the caller — so both sides are
 * normalized before comparing. Comparing raw values silently never matched
 * through real routing, hiding every advisory badge in production.
 */
function normalizeStage(stage: string | null | undefined): string {
	return (stage ?? '').replace(/-/g, '_').toUpperCase()
}

function isAdvisory(decision: Props['decisions'][number]): boolean {
	return normalizeStage(decision.stage) === 'COST_CENTER' && normalizeStage(props.currentStage) === 'SENIOR'
}

/** UI-PUR-001: field set only; the shared helper owns locale, calendar and absence. */
const SHORT_DATE_TIME: Intl.DateTimeFormatOptions = {
	year: 'numeric',
	month: 'short',
	day: 'numeric',
	hour: '2-digit',
	minute: '2-digit',
}

function formatDateTime(dateStr: string): string {
	return formatSheetDateTime(dateStr, { options: SHORT_DATE_TIME })
}
</script>

<style scoped>
.prior-decision-history__title {
	margin-block-end: 16px;
}

.prior-decision-history__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.decision-history {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.decision-item {
	padding: 12px 0;
	border-block-end: 1px solid var(--color-border);
}

.decision-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-block-end: 4px;
	flex-wrap: wrap;
}

.decision-stage {
	font-size: var(--font-size-floor);
	font-weight: 500;
	color: var(--color-primary-element);
	text-transform: uppercase;
}

.advisory-badge {
	font-size: var(--font-size-floor);
}

.decision-type {
	font-size: var(--font-size-floor);
	font-weight: 600;
	padding: 2px 8px;
	border-radius: 12px;
	text-transform: capitalize;
}

.decision-approve {
	background: var(--color-success-light);
	color: var(--color-success-text);
}

.decision-reject {
	background: var(--color-error-light);
	color: var(--color-error-text);
}

.decision-return {
	background: var(--color-warning-light);
	color: var(--color-warning-text);
}

.decision-actor {
	font-size: var(--font-size-floor);
}

.actor-role {
	margin-inline-start: 8px;
	font-weight: 400;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.decision-explanation {
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.decision-time {
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

/*
 * Compact variant (UXC-V2-08, owner-approved v2 baseline): three concise stage rows instead of an
 * audit log — spacing provides the separation, the decision pill leads, actor and time share one
 * quiet line, and the explanation stays the only multi-line element. No per-item rules, no heavy
 * borders.
 */
.prior-decision-history--compact .prior-decision-history__title {
	margin-block-end: 8px;
	font-size: var(--font-size-emphasis);
}

.prior-decision-history--compact .decision-history {
	gap: 10px;
}

.prior-decision-history--compact .decision-item {
	padding: 0;
	border-block-end: 0;
}

.prior-decision-history--compact .decision-header {
	margin-block-end: 0;
	gap: 6px;
}

.decision-actor--compact {
	display: inline-flex;
	align-items: baseline;
	gap: 4px;
	flex-wrap: wrap;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.decision-actor--compact strong {
	color: var(--color-main-text);
	font-weight: 600;
}

.decision-actor-sep {
	color: var(--color-text-maxcontrast);
}

.decision-actor--compact .decision-time {
	font-variant-numeric: tabular-nums;
}

.prior-decision-history--compact .decision-explanation {
	margin-block-start: 2px;
}

@media (width <= 390px) {
	.decision-header {
		flex-direction: column;
		align-items: flex-start;
		gap: 4px;
	}
}
</style>

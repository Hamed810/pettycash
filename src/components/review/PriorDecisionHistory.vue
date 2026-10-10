<template>
	<div class="prior-decision-history" data-testid="prior-decision-history">
		<h3 class="prior-decision-history__title">{{ t('pettycash', 'reviews.priorDecisions') }}</h3>

		<NcEmptyContent
			v-if="decisions.length === 0"
			:description="t('pettycash', 'reviews.noPriorDecisions')"
			:name="t('pettycash', 'reviews.priorDecisions')" />

		<ul v-else class="decision-history" data-testid="decision-history-list">
			<li
				v-for="decision in decisions"
				:key="decision.id"
				class="decision-item"
				:data-testid="`decision-item-${decision.id}`">
				<div class="decision-header">
					<span class="decision-stage" data-testid="decision-stage">
						{{ t('pettycash', `reviews.stage.${decision.stage.toLowerCase()}`) }}
					</span>
					<NcChip
						v-if="isAdvisory(decision)"
						variant="warning"
						class="advisory-badge"
						data-testid="advisory-badge"
						:text="t('pettycash', 'reviews.advisory')" />
					<span
						class="decision-type"
						:class="`decision-${decision.decision.toLowerCase()}`"
						data-testid="decision-type">
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
			</li>
		</ul>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'

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
}

const props = defineProps<Props>()

function isAdvisory(decision: Props['decisions'][number]): boolean {
	return decision.stage === 'COST_CENTER' && props.currentStage === 'SENIOR'
}

function formatDateTime(dateStr: string): string {
	try {
		return new Date(dateStr).toLocaleString('fa-IR', {
			year: 'numeric',
			month: 'short',
			day: 'numeric',
			hour: '2-digit',
			minute: '2-digit',
		})
	} catch {
		return dateStr
	}
}
</script>

<style scoped>
.prior-decision-history__title {
	margin-block-end: 16px;
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

@media (width <= 390px) {
	.decision-header {
		flex-direction: column;
		align-items: flex-start;
		gap: 4px;
	}
}
</style>

<template>
	<div class="sheet-approvals" data-testid="sheet-approvals-summary">
		<!--
			UXC-7-01 / UXC-7F-05: the Approvals tab is the sheet-level aggregate surface — the
			server's whole-sheet approved totals per workflow stage. It never lists per-transaction
			decisions; that history lives only inside each transaction's inline expansion (UXC-7-02).
			The role views pass through their authoritative read's `stageApprovedTotals`; this
			component holds no business rules.
		-->
		<p class="sheet-approvals__scope" data-testid="sheet-approvals-scope-note">
			{{ t('pettycash', 'reviews.approvals.scopeNote') }}
		</p>
		<dl class="sheet-approvals__stages">
			<div
				v-for="stage in stages"
				:key="stage.key"
				class="sheet-approvals__stage"
				:data-testid="`sheet-approvals-stage-${stage.key}`"
			>
				<dt class="sheet-approvals__stage-label">
					{{ t('pettycash', STAGE_LABEL_KEYS[stage.key]) }}
				</dt>
				<dd class="sheet-approvals__stage-value">
					<template v-if="stage.state.known">
						<span
							v-for="total in stage.state.totals"
							:key="`${total.currency}:${total.minorUnit}`"
							class="sheet-approvals__money"
							:data-testid="`sheet-approvals-stage-${stage.key}-total`"
						>
							<bdi dir="ltr">{{ formatMoney(total) }}</bdi>
						</span>
						<span
							v-if="stage.state.totals.length === 0"
							class="sheet-approvals__none"
							:data-testid="`sheet-approvals-stage-${stage.key}-none`"
						>
							{{ t('pettycash', 'reviews.approvals.noneYet') }}
						</span>
					</template>
					<span
						v-else
						class="sheet-approvals__unavailable"
						:data-testid="`sheet-approvals-stage-${stage.key}-unavailable`"
					>
						{{ t('pettycash', 'reviews.approvals.unavailable') }}
					</span>
				</dd>
			</div>
		</dl>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import { formatMoney } from '@/utils/money'
import type { ApprovalsStageKey, StageApprovalAggregate } from '@/utils/stageApprovals'

/**
 * Shared Approvals-tab presentation for all three management roles (Cost Center, Senior Manager,
 * Finance Manager). Stage labels are the canonical decision-stage labels; the states come from
 * each role's authoritative `stageApprovedTotals` via `authoritativeStageApprovals`, or the honest
 * `unprovenApprovalsStages` fallback. Pure presentation: no stage decision logic, no role
 * references.
 */
interface StageProp {
	key: ApprovalsStageKey
	state: StageApprovalAggregate
}

defineProps<{
	stages: readonly StageProp[]
}>()

const STAGE_LABEL_KEYS: Record<ApprovalsStageKey, string> = {
	costCenter: 'reviews.decisionStage.costCenter',
	senior: 'reviews.decisionStage.senior',
	finance: 'reviews.decisionStage.finance',
}
</script>

<style scoped>
.sheet-approvals__scope {
	margin: 0 0 12px;
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

.sheet-approvals__stages {
	display: flex;
	flex-direction: column;
	gap: 12px;
	margin: 0;
}

.sheet-approvals__stage {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: 4px 16px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-medium, 6px);
}

.sheet-approvals__stage-label {
	font-weight: 600;
}

.sheet-approvals__stage-value {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 4px 12px;
	margin: 0;
	font-family: 'Noto Sans Mono', monospace;
	font-variant-numeric: tabular-nums;
}

.sheet-approvals__money,
.sheet-approvals__none,
.sheet-approvals__unavailable {
	font-family: inherit;
}

.sheet-approvals__none,
.sheet-approvals__unavailable {
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

@media (width <= 390px) {
	.sheet-approvals__stage {
		flex-direction: column;
		align-items: flex-start;
		padding: 10px;
	}
}
</style>

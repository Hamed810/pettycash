<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { useRoute, useRouter } from 'vue-router'
import { formatSheetDateTime } from '@/utils/date'
import { useFinanceStore } from '@/stores/finance'
import BalanceAmount from './BalanceAmount.vue'
import EvidencePreview from '@/components/evidence/EvidencePreview.vue'

const props = withDefaults(
	defineProps<{
		sheetId: string | null
	}>(),
	{}
)

const store = useFinanceStore()
const route = useRoute()
const router = useRouter()

const sheetId = computed(() => {
	const id = props.sheetId ?? route.params.sheetId
	return typeof id === 'string' ? id : null
})

const evidenceOpen = ref(false)
const activeEvidenceId = ref<string | null>(null)

const detail = computed(() => store.completedSheetDetail)

const activeEvidence = computed(() => {
	if (!detail.value || !activeEvidenceId.value) return null
	for (const tx of detail.value.transactions) {
		const ev = tx.evidence.find((e) => e.id === activeEvidenceId.value)
		if (ev) return ev
	}
	return null
})

const evidenceDocuments = computed(() =>
	detail.value?.transactions.flatMap((tx) => tx.evidence) ?? []
)

const activeEvidenceIndex = computed(() => {
	if (!detail.value || !activeEvidenceId.value) return -1
	let index = 0
	for (const tx of detail.value.transactions) {
		for (const ev of tx.evidence) {
			if (ev.id === activeEvidenceId.value) return index
			index++
		}
	}
	return -1
})

	const closeDetail = (): void => {
		store.closeCompletedSheet()
		void router.push({ name: 'finance-completed-sheets' })
	}

const toggleEvidence = (open: boolean, evidenceId?: string): void => {
	evidenceOpen.value = open
	if (evidenceId !== undefined) activeEvidenceId.value = evidenceId
	if (!open) activeEvidenceId.value = null
}

const navigateEvidence = (index: number): void => {
	if (!detail.value) return
	let current = 0
	for (const tx of detail.value.transactions) {
		for (const ev of tx.evidence) {
			if (current === index) {
				activeEvidenceId.value = ev.id
				return
			}
			current++
		}
	}
}

onMounted(() => {
	if (sheetId.value !== null) void store.openCompletedSheet(sheetId.value)
})

const decisionLabel = (value: string | null | undefined): string => {
	if (value === null || value === undefined) return t('pettycash', 'completedSheetDetail.decision.pending')
	switch (value) {
		case 'APPROVE':
			return t('pettycash', 'completedSheetDetail.decision.approved')
		case 'REJECT':
			return t('pettycash', 'completedSheetDetail.decision.rejected')
		case 'RETURN':
			return t('pettycash', 'completedSheetDetail.decision.returned')
		default:
			return t('pettycash', 'completedSheetDetail.decision.pending')
	}
}

const outcomeLabel = (value: string): string => {
	switch (value) {
		case 'APPROVED':
			return t('pettycash', 'completedSheetDetail.finalOutcome.approved')
		case 'REJECTED':
			return t('pettycash', 'completedSheetDetail.finalOutcome.rejected')
		default:
			return value
	}
}
</script>

<template>
	<section class="completed-sheet-detail" data-testid="completed-sheet-detail">
		<div class="completed-sheet-detail__header">
			<h2 v-if="detail" class="completed-sheet-detail__title">
				{{ t('pettycash', 'completedSheetDetail.title', { reference: detail.reference }) }}
			</h2>
			<button
				class="completed-sheet-detail__close"
				data-testid="completed-sheet-close"
				:aria-label="t('pettycash', 'completedSheetDetail.closeDetail')"
				@click="closeDetail">
				{{ t('pettycash', 'common.close') }}
			</button>
		</div>

		<div v-if="store.completedSheetLoading" class="completed-sheet-detail__state" data-testid="completed-sheet-detail-loading">
			{{ t('pettycash', 'common.loading') }}
		</div>

		<div
			v-else-if="store.completedSheetError"
			class="completed-sheet-detail__state completed-sheet-detail__state--error"
			data-testid="completed-sheet-detail-error"
			role="alert"
		>
			{{ store.completedSheetError }}
		</div>

		<div v-else-if="!detail" class="completed-sheet-detail__state" data-testid="completed-sheet-detail-empty">
			{{ t('pettycash', 'common.empty') }}
		</div>

		<div v-if="detail" class="completed-sheet-detail__meta">
			<div class="completed-sheet-detail__field">
				<span class="completed-sheet-detail__label">{{ t('pettycash', 'completedSheets.column.reference') }}</span>
				<span class="completed-sheet-detail__value">{{ detail.reference }}</span>
			</div>
			<div class="completed-sheet-detail__field">
				<span class="completed-sheet-detail__label">{{ t('pettycash', 'completedSheets.column.title') }}</span>
				<span class="completed-sheet-detail__value">{{ detail.title }}</span>
			</div>
			<div class="completed-sheet-detail__field">
				<span class="completed-sheet-detail__label">{{ t('pettycash', 'completedSheets.column.purchaser') }}</span>
				<span class="completed-sheet-detail__value">{{ detail.purchaser.displayName }}</span>
			</div>
			<div class="completed-sheet-detail__field">
				<span class="completed-sheet-detail__label">{{ t('pettycash', 'completedSheets.column.status') }}</span>
				<span class="completed-sheet-detail__value">{{ detail.status === 'COMPLETED_WITH_REJECTIONS' ? t('pettycash', 'completedSheets.completedWithRejections') : t('pettycash', 'financeAccounts.status.active') }}</span>
			</div>
			<div class="completed-sheet-detail__field">
				<span class="completed-sheet-detail__label">{{ t('pettycash', 'completedSheetDetail.createdAt') }}</span>
				<span class="completed-sheet-detail__value">{{ formatSheetDateTime(detail.createdAt) }}</span>
			</div>
			<div class="completed-sheet-detail__field">
				<span class="completed-sheet-detail__label">{{ t('pettycash', 'completedSheetDetail.completedAt') }}</span>
				<span class="completed-sheet-detail__value">{{ formatSheetDateTime(detail.completedAt) }}</span>
			</div>
			<div class="completed-sheet-detail__field">
				<span class="completed-sheet-detail__label">{{ t('pettycash', 'completedSheets.column.total') }}</span>
				<span class="completed-sheet-detail__value">{{ detail.totalTransactionCount }}</span>
			</div>
			<div class="completed-sheet-detail__field">
				<span class="completed-sheet-detail__label">{{ t('pettycash', 'completedSheets.column.approved') }}</span>
				<span class="completed-sheet-detail__value">{{ detail.approvedTransactionCount }}</span>
			</div>
			<div class="completed-sheet-detail__field">
				<span class="completed-sheet-detail__label">{{ t('pettycash', 'completedSheets.column.rejected') }}</span>
				<span class="completed-sheet-detail__value">{{ detail.rejectedTransactionCount }}</span>
			</div>
			<div class="completed-sheet-detail__field">
				<span class="completed-sheet-detail__label">{{ t('pettycash', 'completedSheets.column.postedAmount') }}</span>
				<span class="completed-sheet-detail__value">
					<BalanceAmount :balance="detail.postedAmount" :minor-unit="detail.postedAmount.minorUnit" />
				</span>
			</div>
			<div class="completed-sheet-detail__field">
				<span class="completed-sheet-detail__label">{{ t('pettycash', 'completedSheets.column.postingCount') }}</span>
				<span class="completed-sheet-detail__value">{{ detail.postingCount }}</span>
			</div>
		</div>

		<div v-if="detail && detail.transactions.length > 0" class="completed-sheet-detail__scroll" data-testid="completed-sheet-detail-scroll" style="overflow-x: auto;">
			<table class="completed-sheet-detail__table" data-testid="completed-sheet-detail-table">
				<colgroup>
					<col class="completed-sheet-detail__col--reference" />
					<col class="completed-sheet-detail__col--revision" />
					<col class="completed-sheet-detail__col--description" />
					<col class="completed-sheet-detail__col--expense-date" />
					<col class="completed-sheet-detail__col--amount" />
					<col class="completed-sheet-detail__col--outcome" />
					<col class="completed-sheet-detail__col--decision" />
					<col class="completed-sheet-detail__col--decision" />
					<col class="completed-sheet-detail__col--decision" />
					<col class="completed-sheet-detail__col--evidence" />
				</colgroup>
			<thead>
				<tr>
					<th>{{ t('pettycash', 'completedSheetDetail.column.reference') }}</th>
					<th>{{ t('pettycash', 'completedSheetDetail.column.revision') }}</th>
					<th>{{ t('pettycash', 'completedSheetDetail.column.description') }}</th>
					<th>{{ t('pettycash', 'completedSheetDetail.column.expenseDate') }}</th>
					<th>{{ t('pettycash', 'completedSheetDetail.column.amount') }}</th>
					<th>{{ t('pettycash', 'completedSheetDetail.column.finalOutcome') }}</th>
					<th>{{ t('pettycash', 'completedSheetDetail.column.costCenter') }}</th>
					<th>{{ t('pettycash', 'completedSheetDetail.column.seniorManager') }}</th>
					<th>{{ t('pettycash', 'completedSheetDetail.column.financeManager') }}</th>
					<th>{{ t('pettycash', 'completedSheetDetail.column.evidence') }}</th>
				</tr>
			</thead>
			<tbody>
				<template v-for="tx in detail.transactions" :key="tx.id">
					<tr
						class="completed-sheet-detail__row"
						:data-testid="`completed-tx-${tx.id}`"
					>
						<td class="completed-sheet-detail__cell--reference">{{ tx.reference }}</td>
						<td class="completed-sheet-detail__cell--revision">{{ tx.revisionNumber }}</td>
						<td class="completed-sheet-detail__cell--description">{{ tx.description }}</td>
						<td class="completed-sheet-detail__cell--expense-date">{{ formatSheetDateTime(tx.expenseDate) }}</td>
						<td class="completed-sheet-detail__cell--amount">
							<BalanceAmount :balance="tx.amount" :minor-unit="tx.amount.minorUnit" />
						</td>
						<td class="completed-sheet-detail__cell--outcome">{{ outcomeLabel(tx.finalOutcome) }}</td>
						<td class="completed-sheet-detail__cell--decision">
							{{ decisionLabel(tx.decisions.costCenter?.decision) }}
						</td>
						<td class="completed-sheet-detail__cell--decision">
							{{ decisionLabel(tx.decisions.senior?.decision) }}
						</td>
						<td class="completed-sheet-detail__cell--decision">
							{{ decisionLabel(tx.decisions.finance?.decision) }}
						</td>
						<td class="completed-sheet-detail__cell--evidence">
							<div v-if="tx.evidence.length === 0" data-testid="completed-tx-evidence-none">
								{{ t('pettycash', 'completedSheetDetail.evidence.none') }}
							</div>
							<div v-else>
								<button
									v-for="ev in tx.evidence"
									:key="ev.id"
									class="completed-sheet-detail__evidence-btn"
									data-testid="completed-tx-evidence-btn"
									:aria-label="t('pettycash', 'completedSheetDetail.openEvidence', { name: ev.name })"
									@click="toggleEvidence(true, ev.id)"
								>
									{{ ev.name }}
								</button>
							</div>
						</td>
					</tr>
				<tr v-if="tx.posting !== null" class="completed-sheet-detail__posting-row" :key="`posting-${tx.id}`">
					<td colSpan="6"></td>
					<td class="completed-sheet-detail__cell--posting-label">{{ t('pettycash', 'completedSheetDetail.postedAt') }}</td>
					<td class="completed-sheet-detail__cell--posting-value">{{ formatSheetDateTime(tx.posting.occurredAt) }}</td>
					<td class="completed-sheet-detail__cell--posting-amount">
						<BalanceAmount :balance="tx.posting.amount" :minor-unit="tx.posting.amount.minorUnit" />
					</td>
					<td class="completed-sheet-detail__cell--posting-balance">
						<BalanceAmount :balance="tx.posting.balanceAfter" :minor-unit="tx.posting.balanceAfter.minorUnit" />
					</td>
					<td></td>
					<td></td>
				</tr>
				<tr v-else-if="tx.finalOutcome === 'REJECTED'" class="completed-sheet-detail__posting-row" :key="`noposting-${tx.id}`">
					<td colSpan="6"></td>
					<td class="completed-sheet-detail__cell--posting-label">{{ t('pettycash', 'completedSheetDetail.postedAt') }}</td>
					<td class="completed-sheet-detail__cell--posting-value" colspan="4">
						{{ t('pettycash', 'common.empty') }}
					</td>
					<td></td>
					<td></td>
				</tr>
				</template>
			</tbody>
			</table>
		</div>

		<div v-if="detail && detail.transactions.length === 0" class="completed-sheet-detail__state" data-testid="completed-tx-empty">
			{{ t('pettycash', 'completedSheetDetail.noTransaction') }}
		</div>

		<EvidencePreview
			v-model:open="evidenceOpen"
			:evidence="activeEvidence"
			:documents="evidenceDocuments"
			:index="activeEvidenceIndex"
			@navigate="navigateEvidence"
			@close="toggleEvidence(false)"
		/>
	</section>
</template>

<style scoped>
.completed-sheet-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.completed-sheet-detail__title {
	font-size: var(--font-size-section);
	font-weight: 600;
	margin: 0;
}

.completed-sheet-detail__close {
	padding: 6px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.completed-sheet-detail__close:hover {
	background-color: var(--color-background-hover);
}

.completed-sheet-detail__state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 32px 0;
	text-align: center;
}

.completed-sheet-detail__state--error {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--nc-color-error-container, #fce4ec);
	color: var(--nc-color-on-error-container, #c62828);
}

.completed-sheet-detail__meta {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
	gap: 8px;
	margin-bottom: 16px;
	font-size: var(--font-size-floor);
}

.completed-sheet-detail__field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.completed-sheet-detail__label {
	font-weight: 500;
	color: var(--nc-color-on-surface-variant, #616161);
}

.completed-sheet-detail__value {
	font-weight: 400;
}

.completed-sheet-detail__table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--font-size-floor);
	table-layout: fixed;
}

.completed-sheet-detail__scroll {
	width: 100%;
}

.completed-sheet-detail__col--reference {
	min-width: 160px;
}

.completed-sheet-detail__col--revision {
	min-width: 70px;
}

.completed-sheet-detail__col--description {
	min-width: 200px;
}

.completed-sheet-detail__col--expense-date {
	min-width: 120px;
}

.completed-sheet-detail__col--amount {
	min-width: 100px;
}

.completed-sheet-detail__col--outcome {
	min-width: 90px;
}

.completed-sheet-detail__col--decision {
	min-width: 100px;
}

.completed-sheet-detail__col--evidence {
	min-width: 120px;
}

.completed-sheet-detail__table th,
.completed-sheet-detail__table td {
	padding: 6px;
	border: 1px solid var(--color-border);
	text-align: start;
	overflow-wrap: anywhere;
}

.completed-sheet-detail__table th {
	font-weight: 600;
	background-color: var(--color-main-background);
}

.completed-sheet-detail__cell--amount {
	text-align: end;
	font-family: monospace;
}

.completed-sheet-detail__cell--posting-amount {
	text-align: end;
	font-family: monospace;
}

.completed-sheet-detail__cell--posting-balance {
	text-align: end;
	font-family: monospace;
	font-weight: 600;
}

.completed-sheet-detail__cell--outcome {
	text-transform: uppercase;
	font-size: var(--font-size-floor);
	font-weight: 600;
}

.completed-sheet-detail__evidence-btn {
	background: none;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	padding: 4px 8px;
	font-size: var(--font-size-floor);
	cursor: pointer;
	color: var(--color-main-text);
}

.completed-sheet-detail__evidence-btn:hover {
	background-color: var(--color-background-hover);
}

.completed-sheet-detail__posting-row {
	font-size: var(--font-size-floor);
}

.completed-sheet-detail__cell--posting-label {
	font-weight: 500;
	color: var(--nc-color-on-surface-variant, #616161);
}

.completed-sheet-detail__cell--posting-value {
	text-align: end;
}

@media (width <= 390px) {
	.completed-sheet-detail__table {
		font-size: var(--font-size-floor);
	}

	.completed-sheet-detail__table th,
	.completed-sheet-detail__table td {
		padding: 6px;
	}
}
</style>

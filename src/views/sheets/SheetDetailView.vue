<script setup lang="ts">
import { onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSheetStore } from '@/stores/sheets'
import { useTransactionStore } from '@/stores/transactions'
import type { SheetStatus } from '@/types/api'
import { NcBreadcrumbs, NcNoteCard, NcButton, NcChip, NcIconSvgWrapper } from '@nextcloud/vue'
import { mdiPencil, mdiPlus, mdiSend, mdiEye, mdiLock } from '@mdi/js'
import { t } from '@nextcloud/l10n'

const route = useRoute()
const router = useRouter()
const sheetStore = useSheetStore()
const transactionStore = useTransactionStore()

const sheetId = computed(() => route.params.id as string)

const statusLabels: Record<SheetStatus, string> = {
	OPEN: 'pettycash.status.open',
	AWAITING_COST_CENTER_REVIEW: 'pettycash.status.awaitingCostCenter',
	AWAITING_SENIOR_REVIEW: 'pettycash.status.awaitingSenior',
	AWAITING_FINANCE_REVIEW: 'pettycash.status.awaitingFinance',
	COMPLETED: 'pettycash.status.completed',
	COMPLETED_WITH_REJECTIONS: 'pettycash.status.completedWithRejections'
}

const isSubmitted = computed(() => sheetStore.currentSheet?.status !== 'OPEN')

function formatMoney(amountMinor: number, currency: string) {
	return new Intl.NumberFormat('fa-IR', { style: 'currency', currency, minimumFractionDigits: 0 }).format(amountMinor / 100)
}

function goToEdit() { router.push({ name: 'sheet-edit', params: { id: sheetId.value } }) }
function goToCreateTransaction() { router.push({ name: 'transaction-create', params: { sheetId: sheetId.value } }) }
function goToTransaction(transactionId: string) { router.push({ name: 'transaction-detail', params: { id: transactionId } }) }

async function handleSubmit() {
	if (!sheetStore.currentSheet) return
	await sheetStore.submitCurrentSheet(sheetId.value, sheetStore.currentSheet.version)
	await transactionStore.loadTransactions(sheetId.value)
}

onMounted(() => {
	sheetStore.loadSheet(sheetId.value).then(sheet => {
		if (sheet) transactionStore.loadTransactions(sheetId.value)
	})
})

watch(sheetId, (newId) => {
	sheetStore.loadSheet(newId).then(sheet => {
		if (sheet) transactionStore.loadTransactions(newId)
	})
})

const transactionHeaders = [
	{ key: 'expenseDate', label: 'pettycash.transactions.expenseDate', sortable: true },
	{ key: 'amount', label: 'pettycash.transactions.amount', sortable: true },
	{ key: 'costCenter', label: 'pettycash.transactions.costCenter', sortable: true },
	{ key: 'expenseType', label: 'pettycash.transactions.expenseType', sortable: true },
	{ key: 'stage', label: 'pettycash.transactions.stage', sortable: true },
	{ key: 'status', label: 'pettycash.transactions.status', sortable: true },
	{ key: 'evidenceCount', label: 'pettycash.transactions.evidenceCount', sortable: false },
	{ key: 'actions', label: 'pettycash.transactions.actions', sortable: false }
]
</script>

<template>
	<div class="sheet-detail-view" dir="rtl" data-test="sheet-detail-view">
		<div class="mb-4">
			<NcBreadcrumbs
				:items="[
					{ text: t('pettycash', 'sheets.title'), href: '/sheets' },
					{ text: sheetStore.currentSheet?.title ?? '' }
				]"
			/>
		</div>

		<div class="header-row">
			<div>
				<NcChip


					data-test="sheet-status"
				>
					{{ t('pettycash', statusLabels[sheetStore.currentSheet?.status ?? 'OPEN']) }}
				</NcChip>
				<NcChip
					v-if="isSubmitted"
 variant="secondary"


					data-test="read-only-badge"
				>
					<NcIconSvgWrapper :path="mdiLock" :size="16" class="mr-2" />
					{{ t('pettycash', 'common.readOnly') }}
				</NcChip>
			</div>
		</div>

		<NcNoteCard data-test="sheet-header">
			<template #header>
				<div class="card-header-row">
					<h2 class="mb-0">{{ sheetStore.currentSheet?.title }}</h2>
					<div class="d-flex gap-2">
						<NcButton v-if="!isSubmitted" variant="secondary" @click="goToEdit" data-test="edit-sheet-btn">
							<NcIconSvgWrapper :path="mdiPencil" :size="16" class="mr-2" />
							{{ t('pettycash', 'common.edit') }}
						</NcButton>
						<NcButton v-if="!isSubmitted" variant="primary" @click="goToCreateTransaction" data-test="create-transaction-btn">
							<NcIconSvgWrapper :path="mdiPlus" :size="16" class="mr-2" />
							{{ t('pettycash', 'transactions.add') }}
						</NcButton>
						<NcButton v-if="sheetStore.canSubmit" variant="primary" @click="handleSubmit" data-test="submit-sheet-button">
							<NcIconSvgWrapper :path="mdiSend" :size="16" class="mr-2" />
							{{ t('pettycash', 'sheets.submit') }}
						</NcButton>
					</div>
				</div>
			</template>

			<div class="card-details">
				<div class="detail-item">
					<strong>{{ t('pettycash', 'sheets.currency') }}</strong>
					<div>{{ sheetStore.currentSheet?.currency }}</div>
				</div>
				<div class="detail-item">
					<strong>{{ t('pettycash', 'sheets.owner') }}</strong>
					<div>{{ sheetStore.currentSheet?.ownerDisplayName }}</div>
				</div>
			</div>
		</NcNoteCard>

		<NcNoteCard data-test="sheet-totals">
			<template #header>
				{{ t('pettycash', 'sheets.totals') }}
			</template>

			<div class="totals-grid">
				<div class="total-chip" data-test="current-total">
					<span class="total-label">{{ t('pettycash', 'sheets.currentTotal') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.current.amountMinor ?? 0, sheetStore.currentSheet?.currency ?? 'IRR') }}</span>
				</div>
				<div class="total-chip total-chip--info" data-test="submitted-total">
					<span class="total-label">{{ t('pettycash', 'sheets.submittedTotal') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.submitted.amountMinor ?? 0, sheetStore.currentSheet?.currency ?? 'IRR') }}</span>
				</div>
				<div class="total-chip total-chip--success" data-test="effective-approved-total">
					<span class="total-label">{{ t('pettycash', 'sheets.effectiveApproved') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.effectiveApproved.amountMinor ?? 0, sheetStore.currentSheet?.currency ?? 'IRR') }}</span>
				</div>
				<div class="total-chip total-chip--error" data-test="effective-rejected-total">
					<span class="total-label">{{ t('pettycash', 'sheets.effectiveRejected') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.effectiveRejected.amountMinor ?? 0, sheetStore.currentSheet?.currency ?? 'IRR') }}</span>
				</div>
				<div class="total-chip total-chip--warning" data-test="effective-pending-total">
					<span class="total-label">{{ t('pettycash', 'sheets.effectivePending') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.effectivePending.amountMinor ?? 0, sheetStore.currentSheet?.currency ?? 'IRR') }}</span>
				</div>
				<div class="total-chip total-chip--primary" data-test="cost-center-approved-total">
					<span class="total-label">{{ t('pettycash', 'sheets.costCenterApproved') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.costCenterApproved.amountMinor ?? 0, sheetStore.currentSheet?.currency ?? 'IRR') }}</span>
				</div>
				<div class="total-chip total-chip--secondary" data-test="senior-approved-total">
					<span class="total-label">{{ t('pettycash', 'sheets.seniorApproved') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.seniorApproved.amountMinor ?? 0, sheetStore.currentSheet?.currency ?? 'IRR') }}</span>
				</div>
				<div class="total-chip total-chip--orange" data-test="finance-approved-total">
					<span class="total-label">{{ t('pettycash', 'sheets.financeApproved') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.financeApproved.amountMinor ?? 0, sheetStore.currentSheet?.currency ?? 'IRR') }}</span>
				</div>
			</div>
		</NcNoteCard>

		<NcNoteCard data-test="transaction-list">
			<template #header>
				<div class="card-header-row">
					<span>{{ t('pettycash', 'transactions.list') }} ({{ transactionStore.pagination.total }})</span>
					<NcButton v-if="!isSubmitted" variant="primary" @click="goToCreateTransaction" data-test="create-transaction-btn">
						<NcIconSvgWrapper :path="mdiPlus" :size="16" class="mr-2" />
						{{ t('pettycash', 'transactions.add') }}
					</NcButton>
				</div>
			</template>

			<div class="table-container">
				<table class="nc-table" data-test="transaction-table">
					<thead>
						<tr>
							<th v-for="header in transactionHeaders" :key="header.key">{{ t('pettycash', header.label) }}</th>
						</tr>
					</thead>
					<tbody v-if="!transactionStore.loading">
						<tr v-for="item in transactionStore.transactions" :key="item.id">
							<td>{{ new Date(item.activeRevision.expenseDate).toLocaleDateString('fa-IR') }}</td>
							<td>{{ formatMoney(item.activeRevision.amount.amountMinor, item.activeRevision.amount.currency) }}</td>
							<td>{{ item.activeRevision.costCenter.name }}</td>
							<td>{{ item.activeRevision.expenseType.name }}</td>
							<td>
								<NcChip>
									{{ t('pettycash', 'stage.' + item.stage.toLowerCase()) }}
								</NcChip>
							</td>
							<td>
								<NcChip data-test="transaction-status-badge">
									{{ t('pettycash', 'status.' + item.status.toLowerCase().replace(/_/g, '-')) }}
								</NcChip>
							</td>
							<td data-test="evidence-count">{{ item.evidenceCount }}</td>
							<td>
								<NcButton variant="tertiary" @click="goToTransaction(item.id)" data-test="view-transaction">
									<NcIconSvgWrapper :path="mdiEye" :size="16" />
								</NcButton>
							</td>
						</tr>
					</tbody>
					<tbody v-else>
						<tr>
							<td :colspan="transactionHeaders.length" class="text-center">
								<div class="loading-row">Loading...</div>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</NcNoteCard>

		<NcNoteCard data-test="audit-tab">
			<template #header>
				{{ t('pettycash', 'audit.title') }}
			</template>

			<div class="table-container">
				<table class="nc-table" data-test="audit-table">
					<thead>
						<tr>
							<th>{{ t('pettycash', 'audit.eventType') }}</th>
							<th>{{ t('pettycash', 'audit.actor') }}</th>
							<th>{{ t('pettycash', 'audit.stage') }}</th>
							<th>{{ t('pettycash', 'audit.decision') }}</th>
							<th>{{ t('pettycash', 'audit.occurredAt') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-if="false">
							<td colspan="5" class="text-center py-8">{{ t('pettycash', 'audit.noEvents') }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</NcNoteCard>

		<div v-if="sheetStore.error" class="alert alert--error" role="alert">
			<span>{{ sheetStore.error }}</span>
			<button type="button" @click="sheetStore.clearError" aria-label="Dismiss">×</button>
		</div>
	</div>
</template>

<style scoped>
.sheet-detail-view {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.header-row {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	align-items: center;
}

.card-header-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.card-details {
	display: grid;
	grid-template-columns: 1fr;
	gap: 1rem;
}

@media (width >= 600px) {
	.card-details {
		grid-template-columns: repeat(3, 1fr);
	}
}

.detail-item {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.totals-grid {
	display: grid;
	grid-template-columns: 1fr;
	gap: 0.75rem;
}

@media (width >= 480px) {
	.totals-grid {
		grid-template-columns: repeat(2, 1fr);
	}
}

@media (width >= 768px) {
	.totals-grid {
		grid-template-columns: repeat(4, 1fr);
	}
}

@media (width >= 1024px) {
	.totals-grid {
		grid-template-columns: repeat(8, 1fr);
	}
}

.total-chip {
	display: flex;
	flex-direction: column;
	padding: 0.75rem;
	border-radius: 4px;
	background-color: var(--nc-color-surface-variant, #f5f5f5);
	gap: 0.25rem;
}

	.total-chip--info {
		background-color: var(--nc-color-info-container, #e3f2fd);
	}

	.total-chip--success {
		background-color: var(--nc-color-success-container, #e8f5e9);
	}

	.total-chip--error {
		background-color: var(--nc-color-error-container, #fce4ec);
	}

	.total-chip--warning {
		background-color: var(--nc-color-warning-container, #fff3e0);
	}

	.total-chip--primary {
		background-color: var(--nc-color-primary-container, #e3f2fd);
	}

	.total-chip--secondary {
		background-color: var(--nc-color-secondary-container, #f3e5f5);
	}

	.total-chip--orange {
		background-color: var(--nc-color-orange-container, #ffe0b2);
	}

.total-label {
	font-size: 0.75rem;
	color: var(--nc-color-on-surface-variant, #616161);
}

.total-value {
	font-size: 1rem;
	font-weight: 600;
	color: var(--nc-color-on-surface, #212121);
}

.table-container {
	overflow-x: auto;
}

.nc-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.875rem;
}

.nc-table th,
.nc-table td {
	padding: 0.75rem;
	text-align: right;
	border-bottom: 1px solid var(--nc-color-outline-variant, #e0e0e0);
}

.nc-table th {
	font-weight: 600;
	color: var(--nc-color-on-surface-variant, #616161);
	background-color: var(--nc-color-surface-container-lowest, #fff);
}

.nc-table tbody tr:hover {
	background-color: var(--nc-color-surface-container-low, #fafafa);
}

.loading-row {
	padding: 2rem;
	color: var(--nc-color-on-surface-variant, #616161);
}

.alert {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.75rem 1rem;
	border-radius: 4px;
}

.alert--error {
	background-color: var(--nc-color-error-container, #fce4ec);
	color: var(--nc-color-on-error-container, #c62828);
}

.alert button {
	background: none;
	border: none;
	font-size: 1.5rem;
	cursor: pointer;
	color: inherit;
	opacity: 0.7;
}

.alert button:hover {
	opacity: 1;
}

	.mr-2 {
		margin-right: 0.5rem;
	}

	.d-flex {
		display: flex;
	}

	.gap-2 {
		gap: 0.5rem;
	}

	.text-center {
		text-align: center;
	}

	.py-8 {
		padding-top: 2rem; padding-bottom: 2rem;
	}

	.w-100 {
		width: 100%;
	}
</style>
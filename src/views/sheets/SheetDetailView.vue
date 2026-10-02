<script setup lang="ts">
import { onMounted, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSheetStore } from '@/stores/sheets'
import { useTransactionStore } from '@/stores/transactions'
import type { SheetStatus } from '@/types/api'
import { NcBreadcrumbs, NcNoteCard, NcButton, NcChip, NcIconSvgWrapper } from '@nextcloud/vue'
import { mdiPencil, mdiPlus, mdiSend, mdiEye, mdiLock } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { formatMoney } from '@/utils/money'

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

function goToEdit() { router.push({ name: 'sheet-edit', params: { id: sheetId.value } }) }
function goToCreateTransaction() { router.push({ name: 'transaction-create', params: { sheetId: sheetId.value } }) }
function goToTransaction(transactionId: string) { router.push({ name: 'transaction-detail', params: { id: transactionId } }) }

async function handleSubmit() {
	if (!sheetStore.currentSheet) return
	await sheetStore.submitCurrentSheet(sheetId.value, sheetStore.currentSheet.version)
	await transactionStore.loadTransactions(sheetId.value)
}

/**
 * Loads the sheet, then its transactions.
 *
 * The stores record a failure in their own `error` ref, which this view renders in its alert. These
 * loads are fired from `onMounted` and from a `watch`, neither of which has a caller to react to a
 * rejection, so it is absorbed here rather than surfacing as an unhandled promise rejection in the
 * user's console.
 */
function loadSheetAndTransactions(id: string) {
	sheetStore.loadSheet(id).then(sheet => {
		if (sheet) return transactionStore.loadTransactions(id)
		return undefined
	}).catch(() => undefined)
}

onMounted(() => { loadSheetAndTransactions(sheetId.value) })

watch(sheetId, (newId) => { loadSheetAndTransactions(newId) })

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
			<!--
				The card's heading row lives in the default slot, not in a `#header` slot.

				`@nextcloud/vue` 9.12.0's `NcNoteCard` renders `heading` (a string prop) and `default`;
				**it has no `header` slot**. A `<template #header>` is therefore dropped without a
				warning, and everything inside it disappears from the rendered page while remaining
				present in the source — which is how this card came to have no Edit, no Add transaction
				and no Submit button at all, in the one view a purchaser has to pass through. Anything
				richer than a string therefore has to be a normal child of the card, and the app's
				existing `.card-header-row` gives it the same shape the dropped slot intended.
			-->
			<div class="card-header-row">
				<h2 class="mb-0" data-test="sheet-title">{{ sheetStore.currentSheet?.title }}</h2>
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

			<div class="card-details">
				<div class="detail-item">
					<strong>{{ t('pettycash', 'sheets.currency') }}</strong>
					<div data-test="sheet-currency">{{ sheetStore.currentSheet?.currency }}</div>
				</div>
				<div class="detail-item">
					<strong>{{ t('pettycash', 'sheets.owner') }}</strong>
					<div>{{ sheetStore.currentSheet?.ownerDisplayName }}</div>
				</div>
			</div>
		</NcNoteCard>

		<NcNoteCard :heading="t('pettycash', 'sheets.totals')" data-test="sheet-totals">
			<div class="totals-grid">
				<div class="total-chip" data-test="current-total">
					<span class="total-label">{{ t('pettycash', 'sheets.currentTotal') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.current) }}</span>
				</div>
				<div class="total-chip total-chip--info" data-test="submitted-total">
					<span class="total-label">{{ t('pettycash', 'sheets.submittedTotal') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.submitted) }}</span>
				</div>
				<!-- FR-062 asks for the stage-approved columns "alongside the latest effective outcome
				     column". The three latest-effective figures are that single column: each is summed by
				     each transaction's latest effective decision, so presenting them as one labelled
				     group is what distinguishes them from the three per-stage approved columns below. -->
				<div class="total-column" data-test="effective-outcome-total">
					<span class="total-column-label">{{ t('pettycash', 'sheets.latestEffectiveOutcome') }}</span>
					<div class="total-chip total-chip--success" data-test="effective-approved-total">
						<span class="total-label">{{ t('pettycash', 'sheets.effectiveApproved') }}</span>
						<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.effectiveApproved) }}</span>
					</div>
					<div class="total-chip total-chip--error" data-test="effective-rejected-total">
						<span class="total-label">{{ t('pettycash', 'sheets.effectiveRejected') }}</span>
						<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.effectiveRejected) }}</span>
					</div>
					<div class="total-chip total-chip--warning" data-test="effective-pending-total">
						<span class="total-label">{{ t('pettycash', 'sheets.effectivePending') }}</span>
						<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.effectivePending) }}</span>
					</div>
				</div>
				<div class="total-chip total-chip--primary" data-test="cost-center-approved-total">
					<span class="total-label">{{ t('pettycash', 'sheets.costCenterApproved') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.costCenterApproved) }}</span>
				</div>
				<div class="total-chip total-chip--secondary" data-test="senior-approved-total">
					<span class="total-label">{{ t('pettycash', 'sheets.seniorApproved') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.seniorApproved) }}</span>
				</div>
				<div class="total-chip total-chip--orange" data-test="finance-approved-total">
					<span class="total-label">{{ t('pettycash', 'sheets.financeApproved') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.financeApproved) }}</span>
				</div>
			</div>
		</NcNoteCard>

		<NcNoteCard :heading="`${t('pettycash', 'transactions.list')} (${transactionStore.pagination.total})`" data-test="transaction-list">
			<div class="table-container">
				<table class="nc-table" data-test="transaction-table">
					<thead>
						<tr>
							<th v-for="header in transactionHeaders" :key="header.key" scope="col">{{ t('pettycash', header.label) }}</th>
						</tr>
					</thead>
					<tbody v-if="!transactionStore.loading">
						<!-- Only this sheet's transactions: the store is loaded from the sheet in the
						     route, so a row here belongs to the sheet the purchaser opened. -->
						<tr v-for="item in transactionStore.transactions" :key="item.id" data-test="sheet-transaction-row">
							<td>{{ new Date(item.activeRevision.expenseDate).toLocaleDateString('fa-IR') }}</td>
							<td>{{ formatMoney(item.activeRevision.amount) }}</td>
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

		<NcNoteCard :heading="t('pettycash', 'audit.title')" data-test="audit-tab">
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

		<!-- A refused submission is reported here. `role="alert"` plus `aria-live` makes it announced
		     rather than only shown, so a purchaser who has scrolled past the totals still learns why
		     nothing happened. -->
		<div
			v-if="sheetStore.error"
			class="alert alert--error submission-errors"
			role="alert"
			aria-live="assertive"
			data-test="submission-errors"
		>
			<span data-test="submission-error-message">{{ sheetStore.error }}</span>
			<button type="button" @click="sheetStore.clearError" :aria-label="t('pettycash', 'common.dismiss')">×</button>
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

.total-column {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	padding: 0.5rem;
	border: 1px solid var(--nc-color-border, #ddd);
	border-radius: 4px;
}

.total-column-label {
	font-weight: 600;
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
	font-size: var(--font-size-floor);
	color: var(--nc-color-on-surface-variant, #616161);
}

.total-value {
	font-size: var(--font-size-floor);
	font-weight: 600;
	color: var(--nc-color-on-surface, #212121);
}

.table-container {
	overflow-x: auto;
}

.nc-table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--font-size-floor);
}

.nc-table th,
.nc-table td {
	padding: 0.75rem;
	text-align: start;
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
		margin-inline-end: 0.5rem;
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
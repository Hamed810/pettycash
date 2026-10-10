<script setup lang="ts">
import { onMounted, computed, h, type VNode } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSheetStore } from '@/stores/sheets'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import { mdiPlus, mdiEye } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { decisionKey, stageKey } from '@/l10n'
import { formatMoney } from '@/utils/money'
import { formatSheetDate as formatDate } from '@/utils/date'
import ResponsiveTable from '@/components/shared/ResponsiveTable.vue'
import type { TransactionSummary } from '@/types/api'

const route = useRoute()
const router = useRouter()
const sheetStore = useSheetStore()

const sheetId = computed(() => route.params.sheetId as string)

/**
 * The sheet's transactions, from `SheetDetail.transactions`.
 *
 * This view used to call `GET /sheets/{sheetId}/transactions`, which no route serves: the request
 * answered `405` with an empty body, so the table below rendered its header and no rows and the
 * sheet's transactions were unreachable from either screen that lists them. The contract carries the
 * same rows on the sheet payload, so this view loads the sheet and reads them from there — one
 * request, one source, and no path in the client that the server does not serve.
 */
const transactions = computed(() => sheetStore.currentSheet?.transactions ?? [])

function goToCreate() { router.push({ name: 'transaction-create', params: { sheetId: sheetId.value } }) }
function goToDetail(transactionId: string) { router.push({ name: 'transaction-detail', params: { id: transactionId } }) }

onMounted(() => {
	// The store records the failure in `error`, which this view renders in its alert. A mount-time
	// load has no caller to react to the rejection, so it is absorbed here rather than surfacing as
	// an unhandled promise rejection in the user's console.
	sheetStore.loadSheet(sheetId.value).catch(() => undefined)
})

/**
 * T389: this table's nine columns, as `ResponsiveTable` takes them.
 *
 * Declared once here rather than restated per cell so the `<th>` and the narrow-viewport card cannot
 * disagree about what a column is called — the defect the reflow exists to close.
 */
const transactionColumns = computed(() => [
	{ key: 'expenseDate', label: t('pettycash', 'transactions.expenseDate') },
	{ key: 'amount', label: t('pettycash', 'transactions.amount') },
	{ key: 'costCenter', label: t('pettycash', 'transactions.costCenter') },
	{ key: 'expenseType', label: t('pettycash', 'transactions.expenseType') },
	{ key: 'stage', label: t('pettycash', 'transactions.stage') },
	{ key: 'status', label: t('pettycash', 'transactions.status') },
	// The latest decision is what tells a purchaser why a transaction is where it is. The field arrives on
	// every row and was never rendered, so a sheet in review could only be understood by opening each
	// transaction.
	{ key: 'latestDecision', label: t('pettycash', 'transactions.latestDecision') },
	{ key: 'evidenceCount', label: t('pettycash', 'transactions.evidenceCount') },
	{ key: 'actions', label: t('pettycash', 'transactions.actions') }
])

function transactionRowKey(item: TransactionSummary, index: number): string {
	return item.id || `transaction-${index}`
}

/** The card's own row hook, off the `transaction-row` prefix so a prefix selector stays a row count. */
function transactionCardRowId(item: TransactionSummary): string {
	return `transaction-card-${item.id}`
}

/**
 * One transaction cell, rendered identically by the table and by the narrow-viewport card.
 *
 * The `latest-decision` branch keeps FR-052's actor snapshot: the name shown is the one the decision was
 * made under, not the account's current name.
 *
 * Every `data-test` the table carried is preserved verbatim, because seven suites across this repository
 * locate these cells by them and renaming one would be a silent loss of the only handle a test has.
 */
function transactionCell(item: TransactionSummary, column: { key: string }): VNode {
	switch (column.key) {
	case 'expenseDate':
		return h('span', { 'data-test': 'transaction-expense-date' }, formatDate(item.activeRevision.expenseDate))
	case 'amount':
		return h('span', { 'data-test': 'transaction-amount' }, formatMoney(item.activeRevision.amount))
	case 'costCenter':
		return h('span', null, item.activeRevision.costCenter.name)
	case 'expenseType':
		return h('span', null, item.activeRevision.expenseType.name)
	case 'stage':
		return h(NcChip, { 'data-test': 'transaction-stage' }, () => t('pettycash', stageKey(item.stage)))
	case 'status':
		return h(StatusBadge, { status: item.status })
	case 'latestDecision':
		// FR-052: the actor is a snapshot, so the name shown is the one the decision was made under.
		return item.latestDecision
			? h('div', { 'data-test': 'latest-decision' }, [
				h('span', { class: 'latest-decision__decision' }, t('pettycash', decisionKey(item.latestDecision.decision))),
				h('small', { class: 'latest-decision__actor' }, [
					`${item.latestDecision.actor.displayName} (`,
					t('pettycash', stageKey(item.latestDecision.stage)),
					')'
				])
			])
			: h('div', { 'data-test': 'latest-decision' }, [
				h('small', { class: 'latest-decision__actor' }, t('pettycash', 'transactions.noDecision'))
			])
	case 'evidenceCount':
		return h('span', { 'data-test': 'evidence-count' }, item.evidenceCount)
	case 'actions':
		return h(
			NcButton,
			{
				variant: 'tertiary',
				'aria-label': t('pettycash', 'transactions.viewDetails'),
				'data-test': 'view-transaction',
				onClick: () => goToDetail(item.id)
			},
			() => h(NcIconSvgWrapper, { path: mdiEye, size: 16 })
		)
	default:
		return h('span', { 'data-test': `transaction-unrendered-${column.key}` }, column.key)
	}
}
</script>

<template>
	<div class="transaction-list-view" dir="rtl" data-test="transaction-list-view">
		<div class="header-row mb-4">
			<NcBreadcrumbs
				:items="[
					{ text: t('pettycash', 'sheets.title'), href: '/sheets' },
					{ text: t('pettycash', 'transactions.list') }
				]"
			/>
			<NcButton
				variant="primary"
				:aria-label="t('pettycash', 'transactions.add')"
				data-test="create-transaction-btn"
				@click="goToCreate"
			>
				<NcIconSvgWrapper :path="mdiPlus" :size="16" class="mr-2" />
				{{ t('pettycash', 'transactions.add') }}
			</NcButton>
		</div>

		<NcNoteCard data-test="transaction-list">
			<!--
				FR-071 (T389): this table sat in `overflow-x: auto`, producing a horizontal scrollbar at
				390px rather than satisfying the requirement. `ResponsiveTable` renders these same rows as a
				`<table>` above 640px and one card per row below, from one column list and one cell renderer.
			-->
			<p v-if="sheetStore.loading" class="text-center py-8" data-test="transaction-loading">
				{{ t('pettycash', 'common.loading') }}
			</p>
			<ResponsiveTable
				v-else
				:columns="transactionColumns"
				:rows="transactions"
				:cell="transactionCell"
				:row-key="transactionRowKey"
				:caption="t('pettycash', 'transactions.list')"
				:loading="sheetStore.loading"
				test-id="transaction-table"
				test-id-prefix="transaction"
				row-test-id="transaction-row"
				:card-row-test-id="transactionCardRowId"
			/>
		</NcNoteCard>

		<div v-if="sheetStore.error" class="alert alert--error" role="alert">
			<span>{{ sheetStore.error }}</span>
			<button type="button" :aria-label="t('pettycash', 'common.dismiss')" @click="sheetStore.clearError">×</button>
		</div>
	</div>
</template>

<style scoped>
.transaction-list-view {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.header-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 0.5rem;
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

.latest-decision__decision {
	display: block;
}

.latest-decision__actor {
	display: block;
	color: var(--color-text-maxcontrast);
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

	.text-center {
		text-align: center;
	}

	.py-8 {
		padding-top: 2rem; padding-bottom: 2rem;
	}
</style>
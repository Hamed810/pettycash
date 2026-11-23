<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { t } from '@nextcloud/l10n'
import { useRouter } from 'vue-router'
import { formatSheetDateTime } from '@/utils/date'
import { useFinanceStore } from '@/stores/finance'
import BalanceAmount from './BalanceAmount.vue'

const store = useFinanceStore()
const router = useRouter()

const pageCount = computed(() => Math.max(1, Math.ceil(store.completedSheetsTotal / 25)))

onMounted(() => void store.loadCompletedSheets())

function loadPage(page: number): void {
	store.loadCompletedSheets(page)
}

function openSheet(sheetId: string): void {
	void router.push({ name: 'finance-completed-sheet-detail', params: { sheetId } })
}
</script>

<template>
	<section class="completed-sheet-table" data-testid="completed-sheet-table">
		<h2 class="completed-sheet-table__title">{{ t('pettycash', 'completedSheets.title') }}</h2>

		<div v-if="store.completedSheetsLoading" class="completed-sheet-table__state" data-testid="completed-sheets-loading">
			{{ t('pettycash', 'common.loading') }}
		</div>

		<div
			v-else-if="store.completedSheetsError"
			class="completed-sheet-table__state completed-sheet-table__state--error"
			data-testid="completed-sheets-error"
			role="alert"
		>
			{{ store.completedSheetsError }}
		</div>

		<div v-else-if="store.completedSheets.length === 0" class="completed-sheet-table__state" data-testid="completed-sheets-empty">
			{{ t('pettycash', 'completedSheets.empty') }}
		</div>

		<div v-else class="completed-sheet-table__scroll" data-testid="completed-sheets-scroll" style="overflow-x: auto;">
			<table class="completed-sheet-table__table" data-testid="completed-sheets-table">
				<colgroup>
					<col class="completed-sheet-table__col--reference" />
					<col class="completed-sheet-table__col--title" />
					<col class="completed-sheet-table__col--purchaser" />
					<col class="completed-sheet-table__col--status" />
					<col class="completed-sheet-table__col--count" />
					<col class="completed-sheet-table__col--count" />
					<col class="completed-sheet-table__col--count" />
					<col class="completed-sheet-table__col--amount" />
					<col class="completed-sheet-table__col--count" />
					<col class="completed-sheet-table__col--date" />
				</colgroup>
			<thead>
				<tr>
					<th>{{ t('pettycash', 'completedSheets.column.reference') }}</th>
					<th>{{ t('pettycash', 'completedSheets.column.title') }}</th>
					<th>{{ t('pettycash', 'completedSheets.column.purchaser') }}</th>
					<th>{{ t('pettycash', 'completedSheets.column.status') }}</th>
					<th>{{ t('pettycash', 'completedSheets.column.total') }}</th>
					<th>{{ t('pettycash', 'completedSheets.column.approved') }}</th>
					<th>{{ t('pettycash', 'completedSheets.column.rejected') }}</th>
					<th>{{ t('pettycash', 'completedSheets.column.postedAmount') }}</th>
					<th>{{ t('pettycash', 'completedSheets.column.postingCount') }}</th>
					<th>{{ t('pettycash', 'completedSheets.column.completedAt') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="sheet in store.completedSheets"
					:key="sheet.id"
					class="completed-sheet-table__row"
					:data-testid="`completed-sheet-${sheet.id}`"
					@click="openSheet(sheet.id)"
					@keydown.enter="openSheet(sheet.id)"
					role="button"
					tabindex="0"
					:aria-label="t('pettycash', 'common.view') + ' ' + sheet.reference"
				>
					<td class="completed-sheet-table__cell--reference">{{ sheet.reference }}</td>
					<td class="completed-sheet-table__cell--title">{{ sheet.title }}</td>
					<td class="completed-sheet-table__cell--purchaser">{{ sheet.purchaser.displayName }}</td>
					<td class="completed-sheet-table__cell--status" :data-status="sheet.status">
						{{ sheet.status === 'COMPLETED_WITH_REJECTIONS' ? t('pettycash', 'completedSheets.completedWithRejections') : t('pettycash', 'financeAccounts.status.active') }}
					</td>
					<td class="completed-sheet-table__cell--count">{{ sheet.totalTransactionCount }}</td>
					<td class="completed-sheet-table__cell--count">{{ sheet.approvedTransactionCount }}</td>
					<td class="completed-sheet-table__cell--count">{{ sheet.rejectedTransactionCount }}</td>
					<td class="completed-sheet-table__cell--amount">
						<BalanceAmount :balance="sheet.postedAmount" :minor-unit="sheet.postedAmount.minorUnit" />
					</td>
					<td class="completed-sheet-table__cell--count">{{ sheet.postingCount }}</td>
					<td class="completed-sheet-table__cell--date">{{ formatSheetDateTime(sheet.completedAt) }}</td>
				</tr>
			</tbody>
			</table>
		</div>

		<nav v-if="pageCount > 1" class="completed-sheet-table__pagination" data-testid="completed-sheets-pagination">
			<button
				:disabled="store.completedSheetsPage <= 1"
				data-testid="completed-sheets-prev"
				@click="loadPage(store.completedSheetsPage - 1)"
			>
				{{ t('pettycash', 'common.previous') }}
			</button>
			<span data-testid="completed-sheets-page-indicator">{{ store.completedSheetsPage }} / {{ pageCount }}</span>
			<button
				:disabled="store.completedSheetsPage >= pageCount"
				data-testid="completed-sheets-next"
				@click="loadPage(store.completedSheetsPage + 1)"
			>
				{{ t('pettycash', 'common.next') }}
			</button>
		</nav>
	</section>
</template>

<style scoped>
.completed-sheet-table__title {
	font-size: var(--font-size-section);
	font-weight: 600;
	margin: 0 0 16px;
}

.completed-sheet-table__state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 32px 0;
	text-align: center;
}

.completed-sheet-table__state--error {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--nc-color-error-container, #fce4ec);
	color: var(--nc-color-on-error-container, #c62828);
}

.completed-sheet-table__table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--font-size-floor);
	table-layout: fixed;
}

.completed-sheet-table__scroll {
	width: 100%;
}

.completed-sheet-table__col--reference {
	min-width: 220px;
}

.completed-sheet-table__col--title {
	min-width: 180px;
}

.completed-sheet-table__col--purchaser {
	min-width: 150px;
}

.completed-sheet-table__col--status {
	min-width: 120px;
}

.completed-sheet-table__col--count {
	min-width: 60px;
}

.completed-sheet-table__col--amount {
	min-width: 120px;
}

.completed-sheet-table__col--date {
	min-width: 140px;
}

.completed-sheet-table__table th,
.completed-sheet-table__table td {
	padding: 8px;
	border: 1px solid var(--color-border);
	text-align: start;
	overflow-wrap: anywhere;
}

.completed-sheet-table__table th {
	font-weight: 600;
	background-color: var(--color-main-background);
}

.completed-sheet-table__row {
	cursor: pointer;
}

.completed-sheet-table__row:hover {
	background-color: var(--color-background-hover);
}

.completed-sheet-table__cell--count {
	text-align: center;
}

.completed-sheet-table__cell--amount {
	text-align: end;
}

.completed-sheet-table__cell--date {
	text-align: end;
}

.completed-sheet-table__pagination {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 16px;
	font-size: var(--font-size-floor);
}

.completed-sheet-table__pagination button {
	padding: 6px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.completed-sheet-table__pagination button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

@media (width <= 390px) {
	.completed-sheet-table__table {
		font-size: var(--font-size-floor);
	}

	.completed-sheet-table__table th,
	.completed-sheet-table__table td {
		padding: 6px;
	}

	.completed-sheet-table__pagination {
		flex-wrap: wrap;
		gap: 8px;
	}
}
</style>

<script setup lang="ts">
import { computed, onMounted, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import { formatSheetDateTime } from '@/utils/date'
import { useFinanceStore } from '@/stores/finance'
import BalanceAmount from './BalanceAmount.vue'

const props = withDefaults(
	defineProps<{
		accountId: string | null
	}>(),
	{}
)

const store = useFinanceStore()

const pageCount = computed(() => Math.max(1, Math.ceil(store.ledgerTotal / store.ledgerPageSize)))

onMounted(() => {
	if (props.accountId !== null) void store.loadLedger(1)
})

watch(
	() => props.accountId,
	(newId) => {
		if (newId !== null) void store.loadLedger(1)
	}
)

function loadPage(page: number): void {
	store.loadLedger(page)
}
</script>

<template>
	<section class="account-ledger-table" data-testid="account-ledger-table">
		<h2 class="account-ledger-table__title">{{ t('pettycash', 'financeLedger.title') }}</h2>

		<div v-if="store.ledgerLoading" class="account-ledger-table__state" data-testid="ledger-loading">
			{{ t('pettycash', 'common.loading') }}
		</div>

		<div
			v-else-if="store.ledgerError"
			class="account-ledger-table__state account-ledger-table__state--error"
			data-testid="ledger-error"
			role="alert"
		>
			{{ store.ledgerError }}
		</div>

		<div v-else-if="store.ledgerRows.length === 0" class="account-ledger-table__state" data-testid="ledger-empty">
			{{ t('pettycash', 'financeLedger.empty') }}
		</div>

		<table v-else class="account-ledger-table__table" data-testid="ledger-table">
			<thead>
				<tr>
					<th>{{ t('pettycash', 'financeLedger.column.date') }}</th>
					<th>{{ t('pettycash', 'financeLedger.column.description') }}</th>
					<th>{{ t('pettycash', 'financeLedger.column.approved') }}</th>
					<th>{{ t('pettycash', 'financeLedger.column.recharge') }}</th>
					<th>{{ t('pettycash', 'financeLedger.column.expense') }}</th>
					<th>{{ t('pettycash', 'financeLedger.column.balance') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="movement in store.ledgerRows"
					:key="movement.id"
					class="account-ledger-table__row"
					:data-testid="`ledger-${movement.id}`"
				>
					<td class="account-ledger-table__cell--date" data-testid="ledger-date">
						{{ formatSheetDateTime(movement.occurredAt) }}
					</td>
					<td class="account-ledger-table__cell--description" data-testid="ledger-description">
						{{ movement.type === 'CONSUMPTION' && movement.sheet
							? t('pettycash', 'financeLedger.sheet', { title: movement.sheet.title, reference: movement.sheet.reference })
							: movement.description }}
					</td>
					<td class="account-ledger-table__cell--approved" data-testid="ledger-approved">
						{{ movement.type === 'CONSUMPTION' && movement.approved
							? t('pettycash', 'financeLedger.approvedPattern', {
									approvedCount: movement.approved.approvedCount,
									totalCount: movement.approved.totalCount,
								})
							: t('pettycash', 'financeLedger.notApplicable') }}
					</td>
					<td class="account-ledger-table__cell--amount" data-testid="ledger-recharge">
						<template v-if="movement.type === 'CHARGE' && movement.recharge">
							<BalanceAmount :balance="movement.recharge" :minor-unit="movement.recharge.minorUnit" />
						</template>
						<template v-else>—</template>
					</td>
					<td class="account-ledger-table__cell--amount" data-testid="ledger-expense">
						<template v-if="movement.type === 'CONSUMPTION' && movement.expense">
							<BalanceAmount :balance="movement.expense" :minor-unit="movement.expense.minorUnit" />
						</template>
						<template v-else>—</template>
					</td>
					<td class="account-ledger-table__cell--balance" data-testid="ledger-balance">
						<BalanceAmount :balance="movement.balanceAfter" :minor-unit="movement.balanceAfter.minorUnit" />
					</td>
				</tr>
			</tbody>
		</table>

		<nav v-if="pageCount > 1" class="account-ledger-table__pagination" data-testid="ledger-pagination">
			<button
				:disabled="store.ledgerPage <= 1"
				data-testid="ledger-prev"
				@click="loadPage(store.ledgerPage - 1)"
			>
				{{ t('pettycash', 'common.previous') }}
			</button>
			<span data-testid="ledger-page-indicator">{{ store.ledgerPage }} / {{ pageCount }}</span>
			<button
				:disabled="store.ledgerPage >= pageCount"
				data-testid="ledger-next"
				@click="loadPage(store.ledgerPage + 1)"
			>
				{{ t('pettycash', 'common.next') }}
			</button>
		</nav>
	</section>
</template>

<style scoped>
.account-ledger-table__title {
	font-size: var(--font-size-section);
	font-weight: 600;
	margin: 0 0 16px;
}

.account-ledger-table__state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 32px 0;
	text-align: center;
}

.account-ledger-table__state--error {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--nc-color-error-container, #fce4ec);
	color: var(--nc-color-on-error-container, #c62828);
}

.account-ledger-table__table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--font-size-floor);
	table-layout: fixed;
}

.account-ledger-table__table th,
.account-ledger-table__table td {
	padding: 8px;
	border: 1px solid var(--color-border);
	text-align: start;
	overflow-wrap: anywhere;
}

.account-ledger-table__table th {
	font-weight: 600;
	background-color: var(--color-main-background);
}

.account-ledger-table__cell--approved {
	text-align: center;
}

.account-ledger-table__cell--amount {
	text-align: end;
	font-family: monospace;
}

.account-ledger-table__cell--balance {
	text-align: end;
	font-family: monospace;
	font-weight: 600;
}

.account-ledger-table__pagination {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 16px;
	font-size: var(--font-size-floor);
}

.account-ledger-table__pagination button {
	padding: 6px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.account-ledger-table__pagination button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

@media (width <= 390px) {
	.account-ledger-table__table {
		font-size: var(--font-size-floor);
	}

	.account-ledger-table__table th,
	.account-ledger-table__table td {
		padding: 6px;
	}

	.account-ledger-table__pagination {
		flex-wrap: wrap;
		gap: 8px;
	}
}
</style>

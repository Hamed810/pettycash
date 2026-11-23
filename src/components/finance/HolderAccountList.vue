<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { useFinanceStore } from '@/stores/finance'
import BalanceAmount from './BalanceAmount.vue'
import RechargeDialog from './RechargeDialog.vue'
import type { HolderAccountSummary } from '@/types/api'

const store = useFinanceStore()

const PAGE_SIZE = 25
const pageCount = computed(() => Math.max(1, Math.ceil(store.holderAccountsTotal / PAGE_SIZE)))

const rechargeOpen = ref(false)
const rechargingAccount = ref<HolderAccountSummary | null>(null)

onMounted(() => void store.loadHolderAccounts())

function selectAccount(accountId: string | null): void {
	store.selectAccount(accountId)
}

function loadPage(page: number): void {
	store.loadHolderAccounts(page)
}

function beginRecharge(account: HolderAccountSummary): void {
	store.startRecharge(account, store.selectedHolderName)
	rechargingAccount.value = account
	rechargeOpen.value = true
}

async function onRechargeSuccess(): Promise<void> {
	await store.loadHolderAccounts()
	if (store.selectedAccountId !== null) {
		await store.loadLedger(1)
	}
}
</script>

<template>
	<section class="holder-account-list" data-testid="holder-account-list">
		<h2 class="holder-account-list__title">{{ t('pettycash', 'financeAccounts.title') }}</h2>

		<div v-if="store.holderAccountsLoading" class="holder-account-list__state" data-testid="accounts-loading">
			{{ t('pettycash', 'common.loading') }}
		</div>

		<div
			v-else-if="store.holderAccountsError"
			class="holder-account-list__state holder-account-list__state--error"
			data-testid="accounts-error"
			role="alert"
		>
			{{ store.holderAccountsError }}
		</div>

		<div v-else-if="store.holderAccounts.length === 0" class="holder-account-list__state" data-testid="accounts-empty">
			{{ t('pettycash', 'financeAccounts.empty') }}
		</div>

		<table v-else class="holder-account-list__table" data-testid="accounts-table">
			<thead>
				<tr>
					<th>{{ t('pettycash', 'financeAccounts.column.account') }}</th>
					<th>{{ t('pettycash', 'financeAccounts.column.currency') }}</th>
					<th>{{ t('pettycash', 'financeAccounts.column.balance') }}</th>
					<th>{{ t('pettycash', 'financeAccounts.column.status') }}</th>
					<th>{{ t('pettycash', 'financeAccounts.column.activity') }}</th>
					<th>{{ t('pettycash', 'financeAccounts.column.action') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="account in store.holderAccounts"
					:key="account.id"
					class="holder-account-list__row"
					:data-testid="`account-${account.id}`"
					role="button"
					tabindex="0"
					:aria-label="t('pettycash', 'common.view') + ' ' + account.id"
					@click="selectAccount(account.id)"
					@keydown.enter="selectAccount(account.id)"
				>
					<td class="holder-account-list__cell--account">{{ account.id }}</td>
					<td class="holder-account-list__cell--currency">{{ account.currency }}</td>
					<td class="holder-account-list__cell--balance">
						<BalanceAmount :balance="account.balance" :minor-unit="account.minorUnit" />
					</td>
					<td class="holder-account-list__cell--status" data-testid="account-status">
						{{ account.active ? t('pettycash', 'financeAccounts.status.active') : t('pettycash', 'financeAccounts.status.inactive') }}
					</td>
					<td class="holder-account-list__cell--activity">
						{{ account.movementCount > 0
							? t('pettycash', 'financeAccounts.movementCount', { count: account.movementCount })
							: t('pettycash', 'financeAccounts.noMovement') }}
					</td>
					<td class="holder-account-list__cell--action">
						<button
							v-if="account.active"
							data-testid="account-recharge"
							:disabled="store.rechargeStatus === 'submitting'"
							@click.stop="beginRecharge(account)"
						>
							{{ t('pettycash', 'financeAccounts.recharge') }}
						</button>
						<span v-else data-testid="account-recharge-disabled">
							{{ t('pettycash', 'financeAccounts.rechargeDisabled') }}
						</span>
					</td>
				</tr>
			</tbody>
		</table>

		<nav v-if="pageCount > 1" class="holder-account-list__pagination" data-testid="accounts-pagination">
			<button
				:disabled="store.holderAccountsPage <= 1"
				data-testid="accounts-prev"
				@click="loadPage(store.holderAccountsPage - 1)"
			>
				{{ t('pettycash', 'common.previous') }}
			</button>
			<span data-testid="accounts-page-indicator">{{ store.holderAccountsPage }} / {{ pageCount }}</span>
			<button
				:disabled="store.holderAccountsPage >= pageCount"
				data-testid="accounts-next"
				@click="loadPage(store.holderAccountsPage + 1)"
			>
				{{ t('pettycash', 'common.next') }}
			</button>
		</nav>

		<RechargeDialog
			v-if="rechargingAccount"
			:open="rechargeOpen"
			:account="rechargingAccount!"
			:holder-name="store.selectedHolderName"
			@update:open="(value: boolean) => {
				rechargeOpen = value
				if (!value) rechargingAccount = null
			}"
			@success="onRechargeSuccess"
		/>
	</section>
</template>

<style scoped>
.holder-account-list__title {
	font-size: var(--font-size-section);
	font-weight: 600;
	margin: 0 0 16px;
}

.holder-account-list__state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 32px 0;
	text-align: center;
}

.holder-account-list__state--error {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--nc-color-error-container, #fce4ec);
	color: var(--nc-color-on-error-container, #c62828);
}

.holder-account-list__table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--font-size-floor);
}

.holder-account-list__row {
	cursor: pointer;
}

.holder-account-list__row:hover {
	background-color: var(--color-background-hover);
}

.holder-account-list__cell--account,
.holder-account-list__cell--currency,
.holder-account-list__cell--balance {
	text-align: start;
}

.holder-account-list__cell--status,
.holder-account-list__cell--activity,
.holder-account-list__cell--action {
	text-align: center;
}

.holder-account-list__pagination {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 16px;
	font-size: var(--font-size-floor);
}

.holder-account-list__pagination button {
	padding: 6px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.holder-account-list__pagination button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

@media (width <= 390px) {
	.holder-account-list__table {
		font-size: var(--font-size-floor);
	}

	.holder-account-list__table th,
	.holder-account-list__table td {
		padding: 6px;
	}

	.holder-account-list__pagination {
		flex-wrap: wrap;
		gap: 8px;
	}
}
</style>

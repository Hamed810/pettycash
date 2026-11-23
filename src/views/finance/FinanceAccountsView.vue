<template>
	<div class="finance-accounts-view" :dir="direction" data-testid="finance-accounts-view">
		<nav class="breadcrumbs" :aria-label="t('pettycash', 'common.breadcrumbs')">
			<ol class="breadcrumbs__list">
				<li class="breadcrumbs__item">
					<RouterLink :to="{ name: 'dashboard' }">{{ t('pettycash', 'navigation.dashboard') }}</RouterLink>
				</li>
				<li class="breadcrumbs__item" aria-current="page">{{ t('pettycash', 'financeHolders.title') }}</li>
			</ol>
		</nav>

		<HolderDirectory />
		<HolderAccountList />
		<AccountLedgerTable :account-id="store.selectedAccountId" />
	</div>
</template>

<script setup lang="ts">
import { direction } from '@/utils/direction'
import { t } from '@nextcloud/l10n'
import { RouterLink } from 'vue-router'
import { useFinanceStore } from '@/stores/finance'
import HolderDirectory from '@/components/finance/HolderDirectory.vue'
import HolderAccountList from '@/components/finance/HolderAccountList.vue'
import AccountLedgerTable from '@/components/finance/AccountLedgerTable.vue'

const store = useFinanceStore()
</script>

<style scoped>
.finance-accounts-view {
	padding: 16px;
}

.breadcrumbs {
	margin-block-end: 16px;
}

.breadcrumbs__list {
	display: flex;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
	font-size: var(--font-size-floor);
	flex-wrap: wrap;
}

.breadcrumbs__item + .breadcrumbs__item::before {
	content: '/';
	margin-inline-end: 8px;
	color: var(--color-text-maxcontrast);
}

.HolderDirectory,
.HolderAccountList,
.AccountLedgerTable {
	margin-bottom: 24px;
}
</style>

<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { t } from '@nextcloud/l10n'
import { direction } from '@/utils/direction'
import { useFinanceStore } from '@/stores/finance'

const store = useFinanceStore()

/** Bound to the search input; synced with the store's search term. */
const searchTerm = computed({
	get: () => store.holdersSearch,
	set: (value: string) => void store.searchHolders(value),
})

/** Full pagination info for the directory. */
const pageCount = computed(() => Math.max(1, Math.ceil(store.holdersTotal / store.holdersPageSize)))

onMounted(() => void store.loadHolders())

/**
 * Select a holder: clears stale account/ledger state through the existing store behavior,
 * then loads this holder's accounts.
 */
function selectHolder(holder: { userId: string; displayName: string }): void {
	store.selectHolder(holder.userId, holder.displayName)
}

function loadPage(page: number): void {
	store.loadHolders(page)
}
</script>

<template>
	<section class="holder-directory" :dir="direction" data-testid="holder-directory">
		<h2 class="holder-directory__title">{{ t('pettycash', 'financeHolders.title') }}</h2>

		<div class="holder-directory__search">
			<label class="holder-directory__search-label" for="holder-search">
				{{ t('pettycash', 'financeHolders.searchLabel') }}
			</label>
			<input
				id="holder-search"
				v-model="searchTerm"
				class="holder-directory__search-input"
				data-testid="holder-search"
				:placeholder="t('pettycash', 'financeHolders.searchPlaceholder')"
			/>
		</div>

		<div v-if="store.holdersLoading" class="holder-directory__state" data-testid="holders-loading">
			{{ t('pettycash', 'common.loading') }}
		</div>

		<div
			v-else-if="store.holdersError"
			class="holder-directory__state holder-directory__state--error"
			data-testid="holders-error"
			role="alert"
		>
			{{ store.holdersError }}
		</div>

		<div v-else-if="store.holders.length === 0" class="holder-directory__state" data-testid="holders-empty">
			{{ searchTerm ? t('pettycash', 'financeHolders.emptySearch') : t('pettycash', 'financeHolders.empty') }}
		</div>

		<table v-else class="holder-directory__table" data-testid="holders-table">
			<thead>
				<tr>
					<th>{{ t('pettycash', 'financeHolders.column.holder') }}</th>
					<th>{{ t('pettycash', 'financeHolders.column.accounts') }}</th>
					<th>{{ t('pettycash', 'financeHolders.column.active') }}</th>
					<th>{{ t('pettycash', 'financeHolders.column.status') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="holder in store.holders"
					:key="holder.userId"
					class="holder-directory__row"
					:data-testid="`holder-${holder.userId}`"
					@click="selectHolder(holder)"
					@keydown.enter="selectHolder(holder)"
					role="button"
					tabindex="0"
					:aria-label="t('pettycash', 'common.view') + ' ' + holder.displayName"
				>
					<td class="holder-directory__cell--name">{{ holder.displayName }}</td>
					<td class="holder-directory__cell--count" data-testid="holder-accounts">{{ holder.accountCount }}</td>
					<td class="holder-directory__cell--active" data-testid="holder-active">{{ holder.activeAccountCount }}</td>
					<td class="holder-directory__cell--status" data-testid="holder-status">{{ holder.status }}</td>
				</tr>
			</tbody>
		</table>

		<nav v-if="pageCount > 1" class="holder-directory__pagination" data-testid="holders-pagination">
			<button
				:disabled="store.holdersPage <= 1"
				data-testid="holders-prev"
				@click="loadPage(store.holdersPage - 1)"
			>
				{{ t('pettycash', 'common.previous') }}
			</button>
			<span data-testid="holders-page-indicator">{{ store.holdersPage }} / {{ pageCount }}</span>
			<button
				:disabled="store.holdersPage >= pageCount"
				data-testid="holders-next"
				@click="loadPage(store.holdersPage + 1)"
			>
				{{ t('pettycash', 'common.next') }}
			</button>
		</nav>
	</section>
</template>

<style scoped>
.holder-directory__title {
	font-size: var(--font-size-section);
	font-weight: 600;
	margin: 0 0 16px;
}

.holder-directory__search {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 16px;
}

.holder-directory__search-label {
	font-weight: 500;
	font-size: var(--font-size-floor);
}

.holder-directory__search-input {
	font-size: var(--font-size-floor);
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.holder-directory__state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 32px 0;
	text-align: center;
}

.holder-directory__state--error {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--nc-color-error-container, #fce4ec);
	color: var(--nc-color-on-error-container, #c62828);
}

.holder-directory__table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--font-size-floor);
}

.holder-directory__row {
	cursor: pointer;
}

.holder-directory__row:hover {
	background-color: var(--color-background-hover);
}

.holder-directory__th,
.holder-directory__td {
	padding: 8px;
	border: 1px solid var(--color-border);
	text-align: start;
}

.holder-directory__cell--count,
.holder-directory__cell--active {
	text-align: center;
}

.holder-directory__pagination {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 16px;
	font-size: var(--font-size-floor);
}

.holder-directory__pagination button {
	padding: 6px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.holder-directory__pagination button:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

@media (width <= 390px) {
	.holder-directory__table {
		font-size: var(--font-size-floor);
	}

	.holder-directory__table th,
	.holder-directory__table td {
		padding: 6px;
	}

	.holder-directory__pagination {
		flex-wrap: wrap;
		gap: 8px;
	}
}
</style>

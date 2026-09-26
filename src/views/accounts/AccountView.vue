<template>
	<div class="account-view" dir="rtl" data-testid="account-view">
		<nav class="breadcrumbs" :aria-label="t('pettycash', 'common.breadcrumbs')">
			<ol class="breadcrumbs__list">
				<li class="breadcrumbs__item">
					<RouterLink :to="{ name: 'dashboard' }">{{ t('pettycash', 'navigation.dashboard') }}</RouterLink>
				</li>
				<li class="breadcrumbs__item" aria-current="page">{{ t('pettycash', 'accounts.title') }}</li>
			</ol>
		</nav>

		<div class="account-view__panel">
			<div class="account-view__header">
				<h2 class="account-view__title">{{ t('pettycash', 'accounts.title') }}</h2>
				<NcButton :disabled="loading" data-testid="refresh-btn" @click="load">
					<template #icon>
						<NcIconSvgWrapper :path="mdiRefresh" />
					</template>
					{{ t('pettycash', 'common.refresh') }}
				</NcButton>
			</div>

			<div v-if="loading" class="account-view__state" data-testid="loading">
				<NcLoadingIcon :size="44" />
				<p>{{ t('pettycash', 'common.loading') }}</p>
			</div>

			<div v-else-if="error" class="account-view__state" data-testid="error">
				<NcIconSvgWrapper :path="mdiAlertCircle" :size="44" class="account-view__state-icon--error" />
				<p class="account-view__error-message" data-testid="error-message">{{ error }}</p>
				<p data-testid="manual-retry-hint">{{ t('pettycash', 'accounts.manualRetryRequired') }}</p>
				<NcButton data-testid="retry-btn" @click="load">
					{{ t('pettycash', 'common.retry') }}
				</NcButton>
			</div>

			<NcEmptyContent
				v-else-if="!hasAccounts"
				:name="t('pettycash', 'accounts.title')"
				:description="t('pettycash', 'accounts.none')"
				data-testid="no-accounts" />

			<div v-else class="account-view__content">
				<!--
					One account per configured currency, so the picker appears only when there is a choice
					to make. A single account is shown directly: a one-option control is a step between a
					purchaser and their balance.
				-->
				<div v-if="accounts.length > 1" class="account-view__picker">
					<label class="account-view__picker-label" for="account-picker">
						{{ t('pettycash', 'accounts.selectAccount') }}
					</label>
					<select
						id="account-picker"
						v-model="selectedId"
						class="account-view__picker-select"
						data-testid="account-picker"
						@change="onAccountSelected">
						<option v-for="account in accounts" :key="account.id" :value="account.id">
							{{ account.balance.currency }} — {{ formatMoney(account.balance) }}
						</option>
					</select>
				</div>

				<section class="account-view__balance" data-testid="account-balance">
					<h3 class="account-view__section-title">{{ t('pettycash', 'accounts.balance') }}</h3>
					<p class="account-view__balance-value" data-testid="balance-value">
						{{ selectedAccount ? formatMoney(selectedAccount.balance) : '—' }}
					</p>
					<!--
						FR-027: a balance may be negative and must be permitted. A negative figure is
						therefore shown as an ordinary value and named in words, not flagged as an error —
						an account that has been overdrawn by approved consumption is a correct state, and
						FR-027 also forbids the balance from blocking submission, so a warning here would
						contradict a requirement the rest of the app obeys.
					-->
					<p
						v-if="isNegative"
						class="account-view__balance-note"
						data-testid="negative-balance-note">
						{{ t('pettycash', 'accounts.negativeBalancePermitted') }}
					</p>
				</section>

				<hr class="account-view__divider">

				<section class="account-view__ledger" data-testid="account-movements">
					<h3 class="account-view__section-title">{{ t('pettycash', 'accounts.movements') }}</h3>

					<div v-if="movementsLoading" class="account-view__state" data-testid="movements-loading">
						<NcLoadingIcon :size="44" />
						<p>{{ t('pettycash', 'common.loading') }}</p>
					</div>

					<div v-else-if="movementsError" class="account-view__state" data-testid="movements-error">
						<NcIconSvgWrapper :path="mdiAlertCircle" :size="44" class="account-view__state-icon--error" />
						<p class="account-view__error-message" data-testid="movements-error-message">
							{{ movementsError }}
						</p>
						<p data-testid="movements-manual-retry-hint">
							{{ t('pettycash', 'accounts.manualRetryRequired') }}
						</p>
						<NcButton data-testid="movements-retry-btn" @click="loadMovements(currentPage)">
							{{ t('pettycash', 'common.retry') }}
						</NcButton>
					</div>

					<NcEmptyContent
						v-else-if="!movements.length"
						:name="t('pettycash', 'accounts.movements')"
						:description="t('pettycash', 'accounts.noMovements')"
						data-testid="no-movements" />

					<ul v-else class="movement-list" data-testid="movement-list">
						<li
							v-for="movement in movements"
							:key="movement.id"
							class="movement-list__item"
							:data-testid="`movement-${movement.id}`"
							:data-movement-type="movement.type">
							<div class="movement-list__head">
								<span class="movement-list__type" data-testid="movement-type">
									{{ movement.type === 'CHARGE'
										? t('pettycash', 'accounts.movementCharge')
										: t('pettycash', 'accounts.movementConsumption') }}
								</span>
								<!--
									The sign is carried by the type *and* by the rendered figure, because
									`formatMoney` prints a magnitude: a charge of 100 and a consumption of 100
									are the same string without it, and the whole point of the ledger is the
									direction the money went.
								-->
								<span class="movement-list__amount" data-testid="movement-amount">
									{{ movement.type === 'CHARGE' ? '+' : '−' }}{{ formatMoney(movement.amount) }}
								</span>
							</div>
							<p class="movement-list__description" data-testid="movement-description">
								{{ movement.description }}
							</p>
							<p class="movement-list__meta">
								<span data-testid="movement-balance-after">
									{{ t('pettycash', 'accounts.balanceAfter') }}: {{ formatMoney(movement.balanceAfter) }}
								</span>
								<span v-if="movement.sourceRevisionId" data-testid="movement-source-revision">
									{{ t('pettycash', 'accounts.settledRevision') }}
								</span>
							</p>
						</li>
					</ul>

					<nav v-if="pageCount > 1" class="account-view__pagination" data-testid="movements-pagination">
						<NcButton
							:disabled="currentPage <= 1"
							data-testid="prev-page-btn"
							@click="loadMovements(currentPage - 1)">
							{{ t('pettycash', 'common.previous') }}
						</NcButton>
						<span data-testid="page-indicator">{{ currentPage }} / {{ pageCount }}</span>
						<NcButton
							:disabled="currentPage >= pageCount"
							data-testid="next-page-btn"
							@click="loadMovements(currentPage + 1)">
							{{ t('pettycash', 'common.next') }}
						</NcButton>
					</nav>
				</section>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { t } from '@nextcloud/l10n'
import { mdiAlertCircle, mdiRefresh } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { useAccountStore } from '@/stores/accounts'
import { formatMoney } from '@/utils/money'

const accountStore = useAccountStore()

const accounts = computed(() => accountStore.accounts)
const hasAccounts = computed(() => accountStore.hasAccounts)
const selectedAccount = computed(() => accountStore.selectedAccount)
const movements = computed(() => accountStore.movements)
const loading = computed(() => accountStore.loading)
const error = computed(() => accountStore.error)
const movementsLoading = computed(() => accountStore.movementsLoading)
const movementsError = computed(() => accountStore.movementsError)
const currentPage = computed(() => accountStore.movementPage)
const pageCount = computed(() =>
	Math.max(1, Math.ceil(accountStore.movementTotal / accountStore.movementPageSize))
)

/** Bound to the native `select`; `null` means "whichever the store picked". */
const selectedId = ref<string | null>(null)

const isNegative = computed(
	() => (selectedAccount.value?.balance.amountMinor ?? 0) < 0
)

function onAccountSelected(): void {
	if (selectedId.value) {
		accountStore.selectAccount(selectedId.value)
	}
	loadMovements(1)
}

async function loadMovements(page = 1): Promise<void> {
	await accountStore.loadMovements(page)
}

async function load(): Promise<void> {
	await accountStore.loadAccounts(true)
	await loadMovements(1)
}

onMounted(async () => {
	await accountStore.loadAccounts()
	// FR-020 provisions a default rial account on the first attempt, so "no account at all" is a state
	// the view must render rather than treat as an error — hence the empty state above rather than a
	// message telling the purchaser something went wrong.
	await loadMovements(1)
})

// The store falls back to the first account, so the picker has to follow it or it would show a
// selection the ledger is not showing.
watch(selectedAccount, (account) => {
	selectedId.value = account?.id ?? null
})
</script>

<style scoped>
.account-view {
	direction: rtl;
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

.account-view__panel {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
}

.account-view__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 12px;
	flex-wrap: wrap;
	margin-block-end: 16px;
}

.account-view__title {
	font-size: var(--font-size-section);
	font-weight: 600;
	margin: 0;
}

.account-view__state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 32px 0;
	text-align: center;
}

.account-view__state-icon--error {
	color: var(--color-error-text);
}

.account-view__error-message {
	font-weight: 600;
}

.account-view__content {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.account-view__picker {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.account-view__picker-label {
	font-weight: 500;
}

.account-view__picker-select {
	font-size: var(--font-size-floor);
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
}

.account-view__balance,
.account-view__ledger {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.account-view__section-title {
	font-size: var(--font-size-emphasis);
	font-weight: 600;
	margin: 0;
}

.account-view__balance-value {
	font-size: var(--font-size-section);
	font-weight: 600;
	margin: 0;

	/* A long figure breaks rather than widening the panel, which is what forces a horizontal
	   scrollbar on a 390px viewport. */
	overflow-wrap: anywhere;
}

.account-view__balance-note,
.movement-list__meta {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.movement-list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.movement-list__item {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.movement-list__head {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	gap: 8px;
	flex-wrap: wrap;
	font-size: var(--font-size-floor);
}

.movement-list__type {
	font-weight: 500;
}

.movement-list__amount {
	font-family: monospace;
	font-size: var(--font-size-emphasis);
	overflow-wrap: anywhere;
}

.movement-list__description,
.movement-list__meta {
	font-size: var(--font-size-floor);
	overflow-wrap: anywhere;
}

.movement-list__meta {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
}

.account-view__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 12px;
	margin-block-start: 16px;
}

.account-view__divider {
	border: 0;
	border-block-start: 1px solid var(--color-border);
	margin: 0;
}

@media (width <= 390px) {
	.account-view__content {
		gap: 12px;
	}

	.account-view__header {
		flex-direction: column;
		align-items: stretch;
	}

	.movement-list__head {
		flex-direction: column;
		align-items: stretch;
	}
}
</style>

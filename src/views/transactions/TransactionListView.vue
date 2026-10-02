<script setup lang="ts">
import { onMounted, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTransactionStore } from '@/stores/transactions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import { mdiPlus, mdiEye } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { formatMoney } from '@/utils/money'

const route = useRoute()
const router = useRouter()
const transactionStore = useTransactionStore()

const sheetId = computed(() => route.params.sheetId as string)

function goToCreate() { router.push({ name: 'transaction-create', params: { sheetId: sheetId.value } }) }
function goToDetail(transactionId: string) { router.push({ name: 'transaction-detail', params: { id: transactionId } }) }

onMounted(() => {
	// The store records the failure in `error`, which this view renders in its alert. A mount-time
	// load has no caller to react to the rejection, so it is absorbed here rather than surfacing as
	// an unhandled promise rejection in the user's console.
	transactionStore.loadTransactions(sheetId.value).catch(() => undefined)
})
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
				@click="goToCreate"
				data-test="create-transaction-btn"
			>
				<NcIconSvgWrapper :path="mdiPlus" :size="16" class="mr-2" />
				{{ t('pettycash', 'transactions.add') }}
			</NcButton>
		</div>

		<NcNoteCard data-test="transaction-list">
			<div class="table-container">
				<table class="nc-table" data-test="transaction-table">
					<thead>
						<tr>
							<th scope="col">{{ t('pettycash', 'transactions.expenseDate') }}</th>
							<th scope="col">{{ t('pettycash', 'transactions.amount') }}</th>
							<th scope="col">{{ t('pettycash', 'transactions.costCenter') }}</th>
							<th scope="col">{{ t('pettycash', 'transactions.expenseType') }}</th>
							<th scope="col">{{ t('pettycash', 'transactions.stage') }}</th>
							<th scope="col">{{ t('pettycash', 'transactions.status') }}</th>
							<!-- The latest decision is what tells a purchaser why a transaction is where
							     it is. The field arrives on every row and was never rendered, so a sheet
							     in review could only be understood by opening each transaction. -->
							<th scope="col">{{ t('pettycash', 'transactions.latestDecision') }}</th>
							<th scope="col">{{ t('pettycash', 'transactions.evidenceCount') }}</th>
							<th scope="col">{{ t('pettycash', 'transactions.actions') }}</th>
						</tr>
					</thead>
					<tbody v-if="!transactionStore.loading">
						<tr v-for="item in transactionStore.transactions" :key="item.id" data-test="transaction-row">
							<td>{{ new Date(item.activeRevision.expenseDate).toLocaleDateString('fa-IR') }}</td>
							<td>{{ formatMoney(item.activeRevision.amount) }}</td>
							<td>{{ item.activeRevision.costCenter.name }}</td>
							<td>{{ item.activeRevision.expenseType.name }}</td>
							<td data-test="transaction-stage">
								<NcChip>
									{{ t('pettycash', 'stage.' + item.stage.toLowerCase()) }}
								</NcChip>
							</td>
							<td>
								<StatusBadge :status="item.status" />
							</td>
							<td data-test="latest-decision">
								<template v-if="item.latestDecision">
									<span class="latest-decision__decision">
										{{ t('pettycash', 'decision.' + item.latestDecision.decision.toLowerCase()) }}
									</span>
									<!-- FR-052: the actor is a snapshot, so the name shown is the one the
									     decision was made under, not the account's current name. -->
									<small class="latest-decision__actor">
										{{ item.latestDecision.actor.displayName }}
										({{ t('pettycash', 'stage.' + item.latestDecision.stage.toLowerCase()) }})
									</small>
								</template>
								<small v-else class="latest-decision__actor">
									{{ t('pettycash', 'transactions.noDecision') }}
								</small>
							</td>
							<td data-test="evidence-count">{{ item.evidenceCount }}</td>
							<td>
								<NcButton
									variant="tertiary"
									:aria-label="t('pettycash', 'transactions.viewDetails')"
									@click="goToDetail(item.id)"
									data-test="view-transaction"
								>
									<NcIconSvgWrapper :path="mdiEye" :size="16" />
								</NcButton>
							</td>
						</tr>
					</tbody>
					<tbody v-else>
						<tr>
							<td :colspan="9" class="text-center py-8">Loading...</td>
						</tr>
					</tbody>
				</table>
			</div>
		</NcNoteCard>

		<div v-if="transactionStore.error" class="alert alert--error" role="alert">
			<span>{{ transactionStore.error }}</span>
			<button type="button" @click="transactionStore.clearError" aria-label="Dismiss">×</button>
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
<script setup lang="ts">
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useSheetStore } from '@/stores/sheets'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import { mdiPlus, mdiEye, mdiPencil } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { formatMoney } from '@/utils/money'

const router = useRouter()
const sheetStore = useSheetStore()

function goToCreate() { router.push({ name: 'sheet-create' }) }
function goToDetail(sheetId: string) { router.push({ name: 'sheet-detail', params: { id: sheetId } }) }
function goToEdit(sheetId: string) { router.push({ name: 'sheet-edit', params: { id: sheetId } }) }

onMounted(() => {
	// The store records the failure in `error`, which this view renders in its alert. A mount-time
	// load has no caller to react to the rejection, so it is absorbed here rather than surfacing as
	// an unhandled promise rejection in the user's console.
	sheetStore.loadSheets().catch(() => undefined)
})
</script>

<template>
	<div class="sheet-list-view" dir="rtl" data-test="sheet-list-view">
		<div class="header-row">
			<h1 class="mb-0">{{ t('pettycash', 'sheets.title') }}</h1>
			<NcButton
				variant="primary"
				:aria-label="t('pettycash', 'sheets.create')"
				@click="goToCreate"
				data-test="create-sheet-button"
			>
				<NcIconSvgWrapper :path="mdiPlus" :size="16" class="mr-2" />
				{{ t('pettycash', 'sheets.create') }}
			</NcButton>
		</div>

		<!--
			FR-018 lets a purchaser hold several open sheets at once and FR-061 asks for a dashboard of
			the caller's relevant open work. The open sheets are the actionable ones — a transaction can
			still be added to them and they can still be submitted — so they are listed as their own
			region instead of sitting in one undifferentiated table. The store already computed
			`openSheets`; the view simply never used it.
		-->
		<NcNoteCard :heading="t('pettycash', 'sheets.openSheets')" data-test="open-sheets">
			<table v-if="sheetStore.openSheets.length" class="nc-table">
				<thead>
					<tr>
						<th scope="col">{{ t('pettycash', 'sheets.title') }}</th>
						<th scope="col">{{ t('pettycash', 'sheets.currency') }}</th>
						<th scope="col">{{ t('pettycash', 'sheets.transactionCount') }}</th>
						<th scope="col">{{ t('pettycash', 'sheets.totals') }}</th>
						<th scope="col">{{ t('pettycash', 'sheets.createdAt') }}</th>
						<th scope="col">{{ t('pettycash', 'sheets.actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="item in sheetStore.openSheets" :key="item.id" data-test="sheet-row">
						<td>
							<NcButton variant="tertiary" @click="goToDetail(item.id)" data-test="sheet-list-item">
								{{ item.title }}
							</NcButton>
						</td>
						<td>{{ item.currency }}</td>
						<td>{{ item.transactionCount }}</td>
						<td>{{ formatMoney(item.totals.current) }}</td>
						<td>{{ new Date(item.createdAt).toLocaleDateString('fa-IR') }}</td>
						<td>
							<div class="actions-menu">
								<NcButton
									variant="tertiary"
									:aria-label="t('pettycash', 'sheets.viewNamed', { name: item.title })"
									@click="goToDetail(item.id)"
									data-test="view-sheet"
								>
									<NcIconSvgWrapper :path="mdiEye" :size="16" class="mr-2" />
									{{ t('pettycash', 'common.view') }}
								</NcButton>
								<NcButton
									variant="tertiary"
									:aria-label="t('pettycash', 'sheets.editNamed', { name: item.title })"
									@click="goToEdit(item.id)"
									data-test="edit-sheet"
								>
									<NcIconSvgWrapper :path="mdiPencil" :size="16" class="mr-2" />
									{{ t('pettycash', 'common.edit') }}
								</NcButton>
							</div>
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="empty-hint" data-test="no-open-sheets">
				{{ t('pettycash', 'sheets.noOpenSheets') }}
			</p>
		</NcNoteCard>

		<NcNoteCard data-test="sheet-list">
			<div class="table-container">
				<table class="nc-table">
					<thead>
						<tr>
							<th scope="col">{{ t('pettycash', 'sheets.title') }}</th>
							<th scope="col">{{ t('pettycash', 'sheets.currency') }}</th>
							<th scope="col">{{ t('pettycash', 'sheets.status') }}</th>
							<th scope="col">{{ t('pettycash', 'sheets.transactionCount') }}</th>
							<th scope="col">{{ t('pettycash', 'sheets.totals') }}</th>
							<th scope="col">{{ t('pettycash', 'sheets.createdAt') }}</th>
							<th scope="col">{{ t('pettycash', 'sheets.actions') }}</th>
						</tr>
					</thead>
					<tbody v-if="!sheetStore.loading">
						<tr v-for="item in sheetStore.sheets" :key="item.id" data-test="sheet-row">
							<td>
								<NcButton variant="tertiary" @click="goToDetail(item.id)" data-test="sheet-list-item">
									{{ item.title }}
								</NcButton>
							</td>
							<td>{{ item.currency }}</td>
							<td>
								<StatusBadge :status="item.status" />
							</td>
							<td>{{ item.transactionCount }}</td>
							<td>
								<div class="totals-cell">
									<span>{{ formatMoney(item.totals.current) }}</span>
									<small class="text-caption">{{ t('pettycash', 'sheets.currentTotal') }}</small>
								</div>
							</td>
							<td>{{ new Date(item.createdAt).toLocaleDateString('fa-IR') }}</td>
							<td>
								<div class="actions-menu">
									<NcButton
										variant="tertiary"
										:aria-label="t('pettycash', 'sheets.viewNamed', { name: item.title })"
										@click="goToDetail(item.id)"
										data-test="view-sheet"
									>
										<NcIconSvgWrapper :path="mdiEye" :size="16" class="mr-2" />
										{{ t('pettycash', 'common.view') }}
									</NcButton>
									<NcButton
										v-if="item.status === 'OPEN'"
										variant="tertiary"
										:aria-label="t('pettycash', 'sheets.editNamed', { name: item.title })"
										@click="goToEdit(item.id)"
										data-test="edit-sheet"
									>
										<NcIconSvgWrapper :path="mdiPencil" :size="16" class="mr-2" />
										{{ t('pettycash', 'common.edit') }}
									</NcButton>
								</div>
							</td>
						</tr>
					</tbody>
					<tbody v-else>
						<tr>
							<td :colspan="7" class="text-center py-8">Loading...</td>
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
.sheet-list-view {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.empty-hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
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

.totals-cell {
	display: flex;
	flex-direction: column;
	gap: 0.125rem;
}

.actions-menu {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
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
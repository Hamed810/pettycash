<script setup lang="ts">
/**
 * T088: the administrator's reference-data surface.
 *
 * Four sections — currencies, cost centers, expense types, finance assignments — sharing one
 * interaction shape: a list plus a form that creates, or edits the selected row. One shape rather
 * than four bespoke ones, because the server contract is one shape too (write shape plus
 * `expectedVersion` on update, refused as a conflict when stale).
 *
 * All edits go through the form rather than per-row quick toggles. Cost centers and expense types
 * require a `changeReason` the contract mandates, so a one-click active flip would have to invent a
 * reason — and an invented reason in an audited trail is worse than no shortcut. Currencies need no
 * reason, but a toggle there and a form everywhere else would be two patterns for one job.
 *
 * Every write flows through `useConfigurationStore`, which reloads the list afterwards: a stale
 * refusal leaves the displayed rows at the versions the server actually holds.
 */
import { onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { t } from '@nextcloud/l10n'
import { useConfigurationStore } from '@/stores/configuration'

const store = useConfigurationStore()

onMounted(async () => {
	await Promise.all([
		store.loadCurrencies().catch(() => undefined),
		store.loadCostCenters().catch(() => undefined),
		store.loadExpenseTypes().catch(() => undefined),
		store.loadFinanceAssignments().catch(() => undefined),
	])
})

// ===== currencies =====

const currencyForm = ref({ code: '', name: '', minorUnit: 0, active: true })
const editingCurrency = ref<string | null>(null)
const editingCurrencyVersion = ref(0)

function editCurrency(code: string) {
	const row = store.currencies.find((c) => c.code === code)
	if (!row) return
	editingCurrency.value = code
	editingCurrencyVersion.value = row.version
	currencyForm.value = { code: row.code, name: row.name, minorUnit: row.minorUnit, active: row.active }
}

function resetCurrencyForm() {
	editingCurrency.value = null
	currencyForm.value = { code: '', name: '', minorUnit: 0, active: true }
}

async function saveCurrency() {
	await store.saveCurrency({ ...currencyForm.value }, editingCurrency.value === null ? undefined : editingCurrencyVersion.value)
	resetCurrencyForm()
}

// ===== cost centers =====

const costCenterForm = ref({
	code: '',
	name: '',
	description: '',
	validFrom: '',
	validTo: '',
	active: true,
	managerUserId: '',
	seniorManagerUserId: '',
	changeReason: '',
})
const editingCostCenter = ref<string | null>(null)
const editingCostCenterVersion = ref(0)

function editCostCenter(id: string) {
	const row = store.costCenters.find((c) => c.id === id)
	if (!row) return
	editingCostCenter.value = id
	editingCostCenterVersion.value = row.version
	costCenterForm.value = {
		code: row.code,
		name: row.name,
		description: '',
		validFrom: row.validFrom,
		validTo: row.validTo ?? '',
		active: row.active,
		managerUserId: '',
		seniorManagerUserId: '',
		changeReason: '',
	}
}

function resetCostCenterForm() {
	editingCostCenter.value = null
	costCenterForm.value = {
		code: '',
		name: '',
		description: '',
		validFrom: '',
		validTo: '',
		active: true,
		managerUserId: '',
		seniorManagerUserId: '',
		changeReason: '',
	}
}

async function saveCostCenter() {
	const data = {
		...costCenterForm.value,
		description: costCenterForm.value.description || null,
		validTo: costCenterForm.value.validTo || null,
	}
	await store.saveCostCenter(editingCostCenter.value, data, editingCostCenterVersion.value)
	resetCostCenterForm()
}

// ===== expense types =====

const expenseTypeForm = ref({ title: '', description: '', evidenceRequired: false, active: true, changeReason: '' })
const editingExpenseType = ref<string | null>(null)
const editingExpenseTypeVersion = ref(0)

function editExpenseType(title: string) {
	const row = store.expenseTypes.find((t) => t.title === title)
	if (!row) return
	editingExpenseType.value = title
	editingExpenseTypeVersion.value = row.version
	expenseTypeForm.value = {
		title: row.title,
		description: '',
		evidenceRequired: row.evidenceRequired,
		active: row.active,
		changeReason: '',
	}
}

function resetExpenseTypeForm() {
	editingExpenseType.value = null
	expenseTypeForm.value = { title: '', description: '', evidenceRequired: false, active: true, changeReason: '' }
}

async function saveExpenseType() {
	await store.saveExpenseType(
		editingExpenseType.value,
		{ ...expenseTypeForm.value, description: expenseTypeForm.value.description || null },
		editingExpenseTypeVersion.value,
	)
	resetExpenseTypeForm()
}

// ===== finance assignments =====

const assignmentForm = ref({ userId: '', validFrom: '', validTo: '', active: true, changeReason: '' })

async function saveAssignment() {
	await store.assignFinance({
		...assignmentForm.value,
		validTo: assignmentForm.value.validTo || null,
	})
	assignmentForm.value = { userId: '', validFrom: '', validTo: '', active: true, changeReason: '' }
}
</script>

<template>
	<div class="configuration-view" dir="rtl" data-test="configuration-view">
		<h2 data-test="configuration-heading">{{ t('pettycash', 'administration.configuration') }}</h2>

		<div v-if="store.error" class="alert alert--error" role="alert" data-test="configuration-error">
			<span>{{ store.error }}</span>
			<button type="button" :aria-label="t('pettycash', 'common.dismiss')" @click="store.clearError">×</button>
		</div>

		<!-- Currencies -->
		<NcNoteCard :heading="t('pettycash', 'administration.currencies')" data-test="admin-currencies">
			<ul>
				<li v-for="currency in store.currencies" :key="currency.code" data-test="currency-row">
					<span data-test="currency-code">{{ currency.code }}</span>
					<span>{{ currency.name }}</span>
					<NcButton variant="tertiary" :aria-label="t('pettycash', 'common.edit')" data-test="currency-edit" @click="editCurrency(currency.code)">
						{{ t('pettycash', 'common.edit') }}
					</NcButton>
				</li>
			</ul>
			<form data-test="currency-form" @submit.prevent="saveCurrency">
				<NcTextField v-model="currencyForm.code" :disabled="editingCurrency !== null" :label="t('pettycash', 'administration.code')" data-test="currency-code-input" />
				<NcTextField v-model="currencyForm.name" :label="t('pettycash', 'administration.name')" data-test="currency-name-input" />
				<NcTextField v-model.number="currencyForm.minorUnit" type="number" :label="t('pettycash', 'administration.minorUnit')" data-test="currency-scale-input" />
				<label data-test="currency-active-label">
					<input v-model="currencyForm.active" type="checkbox" data-test="currency-active-input">
					{{ t('pettycash', 'administration.active') }}
				</label>
				<NcButton variant="primary" type="submit" data-test="currency-save">
					{{ editingCurrency === null ? t('pettycash', 'common.create') : t('pettycash', 'common.update') }}
				</NcButton>
			</form>
		</NcNoteCard>

		<!-- Cost centers -->
		<NcNoteCard :heading="t('pettycash', 'administration.costCenters')" data-test="admin-cost-centers">
			<ul>
				<li v-for="center in store.costCenters" :key="center.id" data-test="cost-center-row">
					<span data-test="cost-center-code">{{ center.code }}</span>
					<span>{{ center.name }}</span>
					<NcButton variant="tertiary" :aria-label="t('pettycash', 'common.edit')" data-test="cost-center-edit" @click="editCostCenter(center.id)">
						{{ t('pettycash', 'common.edit') }}
					</NcButton>
				</li>
			</ul>
			<form data-test="cost-center-form" @submit.prevent="saveCostCenter">
				<NcTextField v-model="costCenterForm.code" :label="t('pettycash', 'administration.code')" data-test="cost-center-code-input" />
				<NcTextField v-model="costCenterForm.name" :label="t('pettycash', 'administration.name')" data-test="cost-center-name-input" />
				<NcTextField v-model="costCenterForm.validFrom" :label="t('pettycash', 'administration.validFrom')" data-test="cost-center-valid-from-input" />
				<NcTextField v-model="costCenterForm.managerUserId" :label="t('pettycash', 'administration.manager')" data-test="cost-center-manager-input" />
				<NcTextField v-model="costCenterForm.seniorManagerUserId" :label="t('pettycash', 'administration.seniorManager')" data-test="cost-center-senior-input" />
				<NcTextField v-model="costCenterForm.changeReason" :label="t('pettycash', 'administration.changeReason')" data-test="cost-center-reason-input" />
				<label>
					<input v-model="costCenterForm.active" type="checkbox" data-test="cost-center-active-input">
					{{ t('pettycash', 'administration.active') }}
				</label>
				<NcButton variant="primary" type="submit" data-test="cost-center-save">
					{{ editingCostCenter === null ? t('pettycash', 'common.create') : t('pettycash', 'common.update') }}
				</NcButton>
			</form>
		</NcNoteCard>

		<!-- Expense types -->
		<NcNoteCard :heading="t('pettycash', 'administration.expenseTypes')" data-test="admin-expense-types">
			<ul>
				<li v-for="type in store.expenseTypes" :key="type.title" data-test="expense-type-row">
					<span data-test="expense-type-title">{{ type.title }}</span>
					<NcButton variant="tertiary" :aria-label="t('pettycash', 'common.edit')" data-test="expense-type-edit" @click="editExpenseType(type.title)">
						{{ t('pettycash', 'common.edit') }}
					</NcButton>
				</li>
			</ul>
			<form data-test="expense-type-form" @submit.prevent="saveExpenseType">
				<NcTextField v-model="expenseTypeForm.title" :disabled="editingExpenseType !== null" :label="t('pettycash', 'administration.title')" data-test="expense-type-title-input" />
				<label>
					<input v-model="expenseTypeForm.evidenceRequired" type="checkbox" data-test="expense-type-evidence-input">
					{{ t('pettycash', 'administration.evidenceRequired') }}
				</label>
				<label>
					<input v-model="expenseTypeForm.active" type="checkbox" data-test="expense-type-active-input">
					{{ t('pettycash', 'administration.active') }}
				</label>
				<NcTextField v-model="expenseTypeForm.changeReason" :label="t('pettycash', 'administration.changeReason')" data-test="expense-type-reason-input" />
				<NcButton variant="primary" type="submit" data-test="expense-type-save">
					{{ editingExpenseType === null ? t('pettycash', 'common.create') : t('pettycash', 'common.update') }}
				</NcButton>
			</form>
		</NcNoteCard>

		<!-- Finance assignments -->
		<NcNoteCard :heading="t('pettycash', 'administration.financeAssignments')" data-test="admin-finance-assignments">
			<ul>
				<li v-for="assignment in store.financeAssignments" :key="assignment.id" data-test="finance-assignment-row">
					<span data-test="finance-assignment-user">{{ assignment.userId }}</span>
				</li>
			</ul>
			<form data-test="finance-assignment-form" @submit.prevent="saveAssignment">
				<NcTextField v-model="assignmentForm.userId" :label="t('pettycash', 'administration.user')" data-test="finance-assignment-user-input" />
				<NcTextField v-model="assignmentForm.validFrom" :label="t('pettycash', 'administration.validFrom')" data-test="finance-assignment-valid-from-input" />
				<NcTextField v-model="assignmentForm.changeReason" :label="t('pettycash', 'administration.changeReason')" data-test="finance-assignment-reason-input" />
				<NcButton variant="primary" type="submit" data-test="finance-assignment-save">
					{{ t('pettycash', 'common.create') }}
				</NcButton>
			</form>
		</NcNoteCard>
	</div>
</template>

<style scoped>
.configuration-view {
	display: flex;
	flex-direction: column;
	gap: 16px;
}
</style>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import EvidenceUploader from '@/components/evidence/EvidenceUploader.vue'
import { useTransactionStore } from '@/stores/transactions'
import type { TransactionDraftWrite } from '@/types/api'

const route = useRoute()
const router = useRouter()
const transactionStore = useTransactionStore()

const sheetId = computed(() => route.params.sheetId as string)
const isEditing = computed(() => !!route.params.id)
const transactionId = computed(() => (route.params.id as string | undefined) ?? '')

const draft = ref<TransactionDraftWrite>({
	expenseDate: new Date().toISOString().split('T')[0] ?? '',
	amountMinor: 0,
	description: '',
	costCenterId: '',
	expenseTypeId: '',
})

const descriptionModel = computed<string>({
	get: () => draft.value.description ?? '',
	set: (value: string) => {
		draft.value.description = value
	},
})

const costCenters = ref<Array<{ id: string; name: string }>>([])
const expenseTypes = ref<Array<{ id: string; name: string }>>([])
const loadingOptions = ref(false)
const errors = ref<Record<string, string>>({})

const expenseDateModel = computed<Date | null>({
	get: () => (draft.value.expenseDate ? new Date(draft.value.expenseDate) : null),
	set: (value: Date | null) => {
		draft.value.expenseDate = value ? value.toISOString().split('T')[0] ?? '' : ''
	},
})

const isDraft = computed(() => !isEditing.value || transactionStore.currentTransaction?.status === 'DRAFT')
const submitButtonText = computed(() => isEditing.value
	? t('pettycash', 'common.update')
	: t('pettycash', 'common.create'))

function validateForm(): boolean {
	errors.value = {}
	if (!draft.value.expenseDate) errors.value.expenseDate = t('pettycash', 'validation.required')
	if (!draft.value.amountMinor || draft.value.amountMinor <= 0) {
		errors.value.amountMinor = t('pettycash', 'validation.positiveAmount')
	}
	if (!draft.value.description?.trim()) errors.value.description = t('pettycash', 'validation.required')
	if (!draft.value.costCenterId) errors.value.costCenterId = t('pettycash', 'validation.required')
	if (!draft.value.expenseTypeId) errors.value.expenseTypeId = t('pettycash', 'validation.required')
	return Object.keys(errors.value).length === 0
}

function validateDraft(): boolean {
	errors.value = {}
	if (!draft.value.expenseDate) errors.value.expenseDate = t('pettycash', 'validation.required')
	if (!draft.value.amountMinor || draft.value.amountMinor <= 0) {
		errors.value.amountMinor = t('pettycash', 'validation.positiveAmount')
	}
	return Object.keys(errors.value).length === 0
}

async function loadOptions(): Promise<void> {
	loadingOptions.value = true
	try {
		costCenters.value = [
			{ id: 'cc-1', name: 'Engineering' },
			{ id: 'cc-2', name: 'Marketing' },
		]
		expenseTypes.value = [
			{ id: 'et-1', name: 'Office Supplies' },
			{ id: 'et-2', name: 'Travel' },
		]
	} catch (e: unknown) {
		errors.value.options = (e as Error).message
	} finally {
		loadingOptions.value = false
	}
}

async function loadTransactionForEdit(): Promise<void> {
	if (!isEditing.value) return
	await transactionStore.loadTransaction(transactionId.value)
	if (transactionStore.currentTransaction) {
		const rev = transactionStore.currentTransaction.activeRevision
		draft.value.expenseDate = rev.expenseDate
		draft.value.amountMinor = rev.amount.amountMinor
		draft.value.description = rev.description ?? ''
		draft.value.costCenterId = rev.costCenter.id
		draft.value.expenseTypeId = rev.expenseType.id
	}
	await loadOptions()
}

async function persist(): Promise<void> {
	if (isEditing.value) {
		await transactionStore.updateExistingTransaction(
			transactionId.value,
			draft.value,
			transactionStore.currentTransaction?.version ?? 0,
		)
	} else {
		await transactionStore.createNewTransaction(sheetId.value, draft.value)
	}
	router.push({ name: 'transactions', params: { sheetId: sheetId.value } })
}

async function handleSaveDraft(): Promise<void> {
	if (!validateDraft()) return
	transactionStore.clearError()
	try {
		await persist()
	} catch {
		// Error surfaced through transactionStore.error
	}
}

async function handleSubmit(): Promise<void> {
	if (!validateForm()) return
	transactionStore.clearError()
	try {
		await persist()
	} catch {
		// Error surfaced through transactionStore.error
	}
}

onMounted(() => {
	if (isEditing.value) loadTransactionForEdit()
	else loadOptions()
})
</script>

<template>
	<div class="transaction-form-view" dir="rtl" data-test="transaction-form-view">
		<nav class="breadcrumbs" :aria-label="t('pettycash', 'common.breadcrumbs')">
			<ol class="breadcrumbs__list">
				<li class="breadcrumbs__item">
					<RouterLink :to="{ name: 'sheets' }">{{ t('pettycash', 'sheets.title') }}</RouterLink>
				</li>
				<li class="breadcrumbs__item">
					<RouterLink :to="{ name: 'transactions', params: { sheetId } }">
						{{ t('pettycash', 'transactions.list') }}
					</RouterLink>
				</li>
				<li class="breadcrumbs__item" aria-current="page">{{ submitButtonText }}</li>
			</ol>
		</nav>

		<NcNoteCard :heading="submitButtonText" data-test="transaction-form">
			<form class="transaction-form" @submit.prevent="handleSubmit" novalidate>
				<div class="form-row">
					<NcDateTimePickerNative
						v-model="expenseDateModel"
						:label="t('pettycash', 'transactions.expenseDate')"
						:error="!!errors.expenseDate"
						:helper-text="errors.expenseDate || ''"
						required
						data-test="expense-date-input" />
					<NcTextField
						v-model.number="draft.amountMinor"
						type="number"
						:label="t('pettycash', 'transactions.amount')"
						:error="!!errors.amountMinor"
						:helper-text="errors.amountMinor || ''"
						required
						data-test="amount-input" />
				</div>

				<div class="form-row">
					<NcTextArea
						v-model="descriptionModel"
						:label="t('pettycash', 'transactions.description')"
						:error="!!errors.description"
						:helper-text="errors.description || ''"
						:rows="3"
						required
						data-test="description-input" />
				</div>

				<div class="form-row">
					<NcSelect
						v-model="draft.costCenterId"
						:options="costCenters.map(c => ({ label: c.name, value: c.id }))"
						:input-label="t('pettycash', 'transactions.costCenter')"
						:error="!!errors.costCenterId"
						:helper-text="errors.costCenterId || ''"
						required
						data-test="cost-center-select" />
					<NcSelect
						v-model="draft.expenseTypeId"
						:options="expenseTypes.map(e => ({ label: e.name, value: e.id }))"
						:input-label="t('pettycash', 'transactions.expenseType')"
						:error="!!errors.expenseTypeId"
						:helper-text="errors.expenseTypeId || ''"
						required
						data-test="expense-type-select" />
				</div>

				<div class="form-row" v-if="!transactionId">
					<div data-test="evidence-upload-area">
						<EvidenceUploader :transaction-id="transactionId" :is-draft="isDraft" />
					</div>
				</div>

				<div class="form-actions">
					<NcButton variant="secondary" type="button" @click="router.push({ name: 'transactions', params: { sheetId } })">
						{{ t('pettycash', 'common.cancel') }}
					</NcButton>
					<NcButton
						variant="secondary"
						:disabled="transactionStore.loading"
						type="button"
						data-test="transaction-save-draft"
						@click="handleSaveDraft">
						{{ t('pettycash', 'transactions.saveDraft') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="transactionStore.loading"
						type="submit"
						data-test="submit-btn">
						{{ submitButtonText }}
					</NcButton>
				</div>
			</form>
		</NcNoteCard>

		<NcNoteCard v-if="transactionStore.error" type="error" role="alert" data-test="form-error">
			{{ transactionStore.error }}
		</NcNoteCard>
	</div>
</template>

<style scoped>
.transaction-form-view {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.breadcrumbs__list {
	display: flex;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
	font-size: 14px;
}

.breadcrumbs__item + .breadcrumbs__item::before {
	content: '/';
	margin-inline-end: 8px;
	color: var(--color-text-maxcontrast);
}

.transaction-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.form-row {
	display: grid;
	grid-template-columns: 1fr;
	gap: 16px;
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-block-start: 16px;
}

@media (width >= 600px) {
	.form-row {
		grid-template-columns: 1fr 1fr;
	}
}

@media (width <= 390px) {
	.form-actions {
		flex-direction: column-reverse;
	}
}
</style>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import EvidenceUploader from '@/components/evidence/EvidenceUploader.vue'
import { listActiveExpenseTypes, listCostCenters } from '@/services/configurationApi'
import { useSheetStore } from '@/stores/sheets'
import { useTransactionStore } from '@/stores/transactions'
import type { CostCenter, ExpenseType, TransactionDraftWrite } from '@/types/api'

const route = useRoute()
const router = useRouter()
const transactionStore = useTransactionStore()
const sheetStore = useSheetStore()

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

const costCenters = ref<CostCenter[]>([])
const expenseTypes = ref<ExpenseType[]>([])
const loadingOptions = ref(false)
const errors = ref<Record<string, string>>({})

const expenseDateModel = computed<Date | null>({
	get: () => (draft.value.expenseDate ? new Date(draft.value.expenseDate) : null),
	set: (value: Date | null) => {
		draft.value.expenseDate = value ? value.toISOString().split('T')[0] ?? '' : ''
	},
})

/**
 * FR-021: a transaction inherits its sheet's currency and defines none of its own, so the form
 * shows the sheet currency and offers no choice. The sheet is named by the route when a transaction is
 * being created and by the loaded transaction when one is being edited — the edit route
 * (`/transactions/:id/edit`) carries no `sheetId`, so reading the route alone would leave the field
 * blank exactly where an existing transaction is being corrected.
 */
const sheetId = computed(() =>
	(route.params.sheetId as string | undefined)
	?? transactionStore.currentTransaction?.sheetId
	?? ''
)

/**
 * FR-026: after submission the purchaser must not edit a transaction's business information. The one
 * exception the requirement names is correction of a *returned* transaction, which is the state the
 * server parks it in while it waits for the purchaser, so that state stays editable.
 */
const editable = computed(() => {
	if (!isEditing.value) return true
	const status = transactionStore.currentTransaction?.status
	return status === undefined || status === 'DRAFT' || status === 'AWAITING_PURCHASER_CORRECTION'
})

const isDraft = computed(() => editable.value)

const submitButtonText = computed(() => isEditing.value
	? t('pettycash', 'common.update')
	: t('pettycash', 'common.create'))

/**
 * Whether the selected expense type demands evidence.
 *
 * The rule belongs to the expense type, not to the form: `data-model.md` gives the column the meaning
 * "at least one active item required at submission when true". Reading it from anywhere else would
 * either demand evidence the configuration does not require, or let a submission through that the
 * configuration forbids.
 */
const selectedExpenseType = computed(() =>
	expenseTypes.value.find((type) => type.id === draft.value.expenseTypeId))

/** Only active evidence counts, per the same rule: a deactivated file does not satisfy the requirement. */
const activeEvidenceCount = computed(() =>
	(transactionStore.currentTransaction?.evidence ?? []).filter((item) => item.active).length)

/**
 * A positive whole number of minor units.
 *
 * `amountMinor` is the amount already scaled to the currency's minor unit, so a fraction of one is not
 * a representable amount of money — the server has nowhere to put the remainder. Rejecting it here
 * stops the form offering a value the API cannot store.
 */
function isPositiveIntegerMinor(amount: number | undefined): boolean {
	return typeof amount === 'number' && Number.isInteger(amount) && amount > 0
}

/** The submission rules of FR-023, as one function so the messages and the button state cannot disagree. */
function collectValidationErrors(): Record<string, string> {
	const found: Record<string, string> = {}
	if (!draft.value.expenseDate) found.expenseDate = t('pettycash', 'validation.required')
	if (!isPositiveIntegerMinor(draft.value.amountMinor)) {
		found.amountMinor = t('pettycash', 'validation.positiveAmount')
	}
	if (!draft.value.description?.trim()) found.description = t('pettycash', 'validation.required')
	if (!draft.value.costCenterId) found.costCenterId = t('pettycash', 'validation.required')
	if (!draft.value.expenseTypeId) found.expenseTypeId = t('pettycash', 'validation.required')
	if (selectedExpenseType.value?.evidenceRequired && activeEvidenceCount.value === 0) {
		found.evidence = t('pettycash', 'validation.evidenceRequired')
	}
	return found
}

/** The same rules, evaluated live for the submit button's disabled state. */
const validationErrors = computed(() => collectValidationErrors())

const isSubmittable = computed(() => editable.value && Object.keys(validationErrors.value).length === 0)

/**
 * FR-022: a draft needs only an expense date and a positive amount, so those two rules — and only
 * those two — decide whether the draft can be saved.
 */
function collectDraftErrors(): Record<string, string> {
	const found: Record<string, string> = {}
	if (!draft.value.expenseDate) found.expenseDate = t('pettycash', 'validation.required')
	if (!isPositiveIntegerMinor(draft.value.amountMinor)) {
		found.amountMinor = t('pettycash', 'validation.positiveAmount')
	}
	return found
}

const isDraftSavable = computed(() => Object.keys(collectDraftErrors()).length === 0)

async function loadOptions(): Promise<void> {
	loadingOptions.value = true
	try {
		// Real cost centers and expense types, from the endpoints `contracts/ocs-api.yaml` declares.
		// Invented identifiers cannot be used: a transaction is routed to the manager the server holds
		// for its cost center, so an invented one routes to nobody and is rejected on write.
		const [centers, types] = await Promise.all([listCostCenters(), listActiveExpenseTypes()])
		costCenters.value = centers.items
		expenseTypes.value = types.items
	} finally {
		loadingOptions.value = false
	}
}

async function loadSheet(): Promise<void> {
	if (!sheetId.value) return
	// The sheet supplies the inherited currency (FR-021). A failure is recorded in the store's own
	// `error` and rendered by the view's alert; it must not abort loading the rest of the form.
	await sheetStore.loadSheet(sheetId.value)
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
	await loadSheet()
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
	await router.push({ name: 'transactions', params: { sheetId: sheetId.value } })
}

async function handleSaveDraft(): Promise<void> {
	errors.value = collectDraftErrors()
	if (Object.keys(errors.value).length > 0) return
	transactionStore.clearError()
	try {
		await persist()
	} catch {
		// Error surfaced through transactionStore.error
	}
}

async function handleSubmit(): Promise<void> {
	errors.value = collectValidationErrors()
	if (Object.keys(errors.value).length > 0) return
	transactionStore.clearError()
	try {
		await persist()
	} catch {
		// Error surfaced through transactionStore.error
	}
}

/** The purchaser's own errors are stale as soon as they start correcting them. */
watch(draft, () => {
	errors.value = collectValidationErrors()
}, { deep: true })

onMounted(() => {
	// The stores record a failure in their own `error` ref, which this view renders in its alert.
	// A mount-time load has no caller to react to the rejection, so it is absorbed here rather than
	// surfacing as an unhandled promise rejection in the user's console.
	if (isEditing.value) loadTransactionForEdit().catch(() => undefined)
	else Promise.all([loadOptions(), loadSheet()]).catch(() => undefined)
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
				<!-- A summary as well as the per-field messages: after a refused submit the purchaser
				     needs to see the whole list of what is missing without hunting for each red field. -->
				<div
					v-if="Object.keys(errors).length > 0"
					class="alert alert--error validation-summary"
					role="alert"
					aria-live="assertive"
					data-test="validation-summary"
				>
					<strong data-test="validation-summary-title">{{ t('pettycash', 'validation.summary') }}</strong>
					<ul class="validation-summary-list">
						<li v-for="(message, field) in errors" :key="field" :data-test="`validation-summary-${field}`">
							{{ message }}
						</li>
					</ul>
				</div>

				<!-- FR-021: the currency is shown, not chosen. A read-only field rather than a disabled
				     select, because a disabled control offers an affordance that does not exist. -->
				<div class="form-row">
					<div class="form-col">
						<NcTextField
							:model-value="sheetStore.currentSheet?.currency ?? ''"
							:label="t('pettycash', 'transactions.currency')"
							:aria-label="t('pettycash', 'transactions.currency')"
							readonly
							data-test="transaction-currency"
						/>
					</div>
				</div>

				<div class="form-row">
					<!-- `NcDateTimePickerNative` names the control through its visible `label`, which it
					     associates with the input by `for`/`id`; it forwards `error`/`helper-text` to the
					     input field but drops an `aria-label` while the label is shown. -->
					<NcDateTimePickerNative
						v-model="expenseDateModel"
						:label="t('pettycash', 'transactions.expenseDate')"
						:error="!!errors.expenseDate"
						:helper-text="errors.expenseDate || ''"
						:disabled="!editable"
						required
						data-test="expense-date-input" />
					<!-- `step="1"`: the value is minor units, so a fraction of one is not a representable
					     amount and must not be offered by the control in the first place. -->
					<NcTextField
						v-model.number="draft.amountMinor"
						type="number"
						step="1"
						:label="t('pettycash', 'transactions.amount')"
						:aria-label="t('pettycash', 'transactions.amount')"
						:error="!!errors.amountMinor"
						:helper-text="errors.amountMinor || ''"
						:disabled="!editable"
						required
						data-test="amount-input" />
				</div>

				<div class="form-row">
					<NcTextArea
						v-model="descriptionModel"
						:label="t('pettycash', 'transactions.description')"
						:aria-label="t('pettycash', 'transactions.description')"
						:error="!!errors.description"
						:helper-text="errors.description || ''"
						:disabled="!editable"
						:rows="3"
						required
						data-test="description-input" />
				</div>

				<div class="form-row">
					<NcSelect
						v-model="draft.costCenterId"
						:options="costCenters.map(center => ({ label: center.name, value: center.id }))"
						:input-label="t('pettycash', 'transactions.costCenter')"
						:error="!!errors.costCenterId"
						:helper-text="errors.costCenterId || ''"
						:loading="loadingOptions"
						:disabled="!editable"
						required
						data-test="cost-center-select" />
					<NcSelect
						v-model="draft.expenseTypeId"
						:options="expenseTypes.map(type => ({ label: type.title, value: type.id }))"
						:input-label="t('pettycash', 'transactions.expenseType')"
						:error="!!errors.expenseTypeId"
						:helper-text="errors.expenseTypeId || ''"
						:loading="loadingOptions"
						:disabled="!editable"
						required
						data-test="expense-type-select" />
				</div>

				<div class="form-row">
					<div data-test="evidence-upload-area">
						<!-- The uploader is a draft affordance: FR-025 allows evidence to be added and
						     removed before submission, and FR-026 forbids it afterwards. -->
						<EvidenceUploader
							v-if="editable"
							:transaction-id="transactionId"
							:is-draft="isDraft" />
						<p v-else class="read-only-hint" data-test="evidence-read-only-hint">
							{{ t('pettycash', 'common.readOnly') }}
						</p>
					</div>
				</div>

				<div class="form-actions">
					<NcButton variant="secondary" type="button" @click="router.push({ name: 'transactions', params: { sheetId } })">
						{{ t('pettycash', 'common.cancel') }}
					</NcButton>
					<NcButton
						variant="secondary"
						:disabled="!editable || transactionStore.loading || !isDraftSavable"
						type="button"
						data-test="transaction-save-draft"
						@click="handleSaveDraft">
						{{ t('pettycash', 'transactions.saveDraft') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="transactionStore.loading || !isSubmittable"
						:loading="transactionStore.loading"
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

		<NcNoteCard v-if="sheetStore.error" type="error" role="alert" data-test="sheet-error">
			{{ sheetStore.error }}
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

	/* Typography is deliberately untouched here: FR-071's floor is owned by T120 across the app, and
	   correcting it one file at a time would leave that change incoherent. */
	font-size: var(--font-size-floor);
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

.form-col {
	display: flex;
	flex-direction: column;
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-block-start: 16px;
}

.validation-summary-list {
	margin: 0;
	padding-inline-start: 1.25rem;
}

.read-only-hint {
	margin: 0;
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

<script setup lang="ts">
import { direction } from '@/utils/direction'
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
import UnsavedChangesDialog from '@/components/shared/UnsavedChangesDialog.vue'
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard'
import { listActiveExpenseTypes, listCostCenters } from '@/services/configurationApi'
import { useSheetStore } from '@/stores/sheets'
import { useTransactionStore } from '@/stores/transactions'
import { ApiError, type CostCenter, type ExpenseType, type TransactionDraftWrite } from '@/types/api'
import { selectOptionKey, selectOptionValue } from '@/utils/selectOption'

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

/**
 * FR-047/US4: the form is editing a *returned* transaction awaiting purchaser correction.
 *
 * Distinct from a plain draft: a correction carries a submitted history behind it, and completing
 * it means *resubmitting* (POST `/resubmit`, which restarts review) rather than merely saving
 * (PATCH). The resubmit control below renders only in this state.
 */
const isCorrection = computed(() =>
	isEditing.value && transactionStore.currentTransaction?.status === 'AWAITING_PURCHASER_CORRECTION')

/**
 * FR-047's resubmission rule, as the button state: the full required dataset and evidence.
 *
 * Reuses `validationErrors` (FR-023's submission rules) rather than a second rule set, because
 * `contracts/workflow.md`'s Correction Contract clause 4 requires resubmission to validate the full
 * dataset — and two rules for "complete" would disagree about the same draft. The button and the
 * handler read the same computed, so the control cannot offer what the handler would refuse.
 */
const isResubmittable = computed(() =>
	isCorrection.value && Object.keys(validationErrors.value).length === 0)

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

/* ------------------------------------------------------------------ *
 * UI-PUR-012: unsaved-change guard and 409 refetch-without-merge
 * ------------------------------------------------------------------ */

const baseline = ref('')
const conflictMessage = ref('')
const deleteDialogOpen = ref(false)

function snapshotBaseline(): void { baseline.value = JSON.stringify(draft.value) }
const isDirty = computed(() => JSON.stringify(draft.value) !== baseline.value)

/** UI-PUR-011: only a draft can be deleted (FR-025). */
const canDeleteDraft = computed(() => isEditing.value
	&& transactionStore.currentTransaction?.status === 'DRAFT'
	&& !transactionStore.loading)

const deleteDescription = computed(() => transactionStore.currentTransaction?.activeRevision.description?.trim()
	|| transactionId.value)

/**
 * A conflict is not merged. The record is re-read and its stored values replace the form, so the
 * purchaser reviews what the server actually holds before deciding what to save next.
 */
async function reloadAfterConflict(): Promise<void> {
	if (!isEditing.value) return
	try {
		await transactionStore.loadTransaction(transactionId.value)
		const revision = transactionStore.currentTransaction?.activeRevision
		if (!revision) return
		draft.value = {
			expenseDate: revision.expenseDate,
			amountMinor: revision.amount.amountMinor,
			description: revision.description ?? '',
			costCenterId: revision.costCenter.id,
			expenseTypeId: revision.expenseType.id,
		}
		snapshotBaseline()
	} catch {
		// The store records the reload failure; the conflict message still explains what happened.
	}
}

const {
	open: guardOpen,
	saving: guardSaving,
	saveFailed: guardSaveFailed,
	ask: askLeave,
	stay: stayOnForm,
	saveAndLeave,
	discard: discardChanges,
} = useUnsavedChangesGuard({
	isDirty: () => isDirty.value,
	save: saveForGuard,
	fallback: () => ({ name: 'transactions', params: { sheetId: sheetId.value } }),
	isBlocked: () => deleteDialogOpen.value,
})

/**
 * UI-PUR-009: preselect the cost center of the most recently **saved** transaction.
 *
 * Ordered by the stored `activeRevision.createdAt`, never by array position or expense date, and
 * only when the center is still in the active option list. A tie is treated as ambiguous and left
 * blank rather than resolved by whichever row happened to arrive first.
 */
function preselectCostCenter(): void {
	if (isEditing.value || draft.value.costCenterId) return
	const transactions = [...(sheetStore.currentSheet?.transactions ?? [])]
	if (transactions.length === 0) return
	const sorted = transactions.sort((left, right) =>
		Date.parse(right.activeRevision.createdAt) - Date.parse(left.activeRevision.createdAt))
	const latest = sorted[0]
	const previous = sorted[1]
	if (!latest?.activeRevision.createdAt) return
	if (previous && previous.activeRevision.createdAt === latest.activeRevision.createdAt) return
	const costCenterId = latest.activeRevision.costCenter?.id
	if (!costCenterId) return
	if (!costCenters.value.some((center) => center.id === costCenterId)) return
	draft.value.costCenterId = costCenterId
}

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

/**
 * Stores the draft. Returns `false` when it was refused, so the save guard can stay on the form.
 *
 * It deliberately does **not** navigate: a normal save terminates on the sheet detail, while
 * "Save and exit" in the guard leaves for the destination the caller originally chose.
 */
async function persist(): Promise<boolean> {
	transactionStore.clearError()
	conflictMessage.value = ''
	try {
		if (isEditing.value) {
			// UI-PUR-013: an update must never send a placeholder because the record was not read.
			// Version `0` is the legitimate first version, so "missing" is `undefined`, not falsy.
			const version = transactionStore.currentTransaction?.version
			if (version === undefined) {
				await transactionStore.loadTransaction(transactionId.value)
				conflictMessage.value = t('pettycash', 'guard.conflict')
				return false
			}
			await transactionStore.updateExistingTransaction(transactionId.value, draft.value, version)
		} else {
			await transactionStore.createNewTransaction(sheetId.value, draft.value)
		}
		snapshotBaseline()
		return true
	} catch (error: unknown) {
		if (error instanceof ApiError && error.status === 409) {
			await reloadAfterConflict()
			conflictMessage.value = t('pettycash', 'guard.conflict')
		}
		return false
	}
}

/** The guard's Save action: a draft save, never a workflow submission. */
async function saveForGuard(): Promise<boolean> {
	errors.value = collectDraftErrors()
	if (Object.keys(errors.value).length > 0) return false
	return persist()
}

async function handleSaveDraft(): Promise<void> {
	errors.value = collectDraftErrors()
	if (Object.keys(errors.value).length > 0) return
	if (await persist()) {
		// FR-088: a save MUST return the purchaser to the sheet's detail view.
		await router.push({ name: 'sheet-detail', params: { id: sheetId.value } })
	}
}

async function handleSubmit(): Promise<void> {
	errors.value = collectValidationErrors()
	if (Object.keys(errors.value).length > 0) return
	if (await persist()) {
		await router.push({ name: 'sheet-detail', params: { id: sheetId.value } })
	}
}

/**
 * FR-047: resubmit the corrected transaction, restarting review at the cost-center manager.
 *
 * The version comes from the transaction as the store last saw it, not from when the draft was
 * created — creating a draft bumps the lock version, so a remembered value would be refused as a
 * conflict. The store sends the command id the resubmission is keyed on; a retry returns the first
 * outcome rather than activating the revision twice.
 */
async function handleResubmit(): Promise<void> {
	errors.value = collectValidationErrors()
	if (Object.keys(errors.value).length > 0) return
	transactionStore.clearError()
	conflictMessage.value = ''
	try {
		const version = transactionStore.currentTransaction?.version
		if (version === undefined) {
			await transactionStore.loadTransaction(transactionId.value)
			conflictMessage.value = t('pettycash', 'guard.conflict')
			return
		}
		await transactionStore.resubmitCorrectionDraft(transactionId.value, version)
		// FR-088, same as `handleSaveDraft()` above: a save terminates on the sheet detail,
		// never on the transactions list.
		await router.push({ name: 'sheet-detail', params: { id: sheetId.value } })
	} catch (error: unknown) {
		if (error instanceof ApiError && error.status === 409) {
			await reloadAfterConflict()
			conflictMessage.value = t('pettycash', 'guard.conflict')
		}
	}
}

/** UI-PUR-011: delete is offered for a draft only, and names the transaction in the confirmation. */
function askDeleteDraft(): void { deleteDialogOpen.value = true }

async function confirmDeleteDraft(): Promise<void> {
	const transaction = transactionStore.currentTransaction
	if (!transaction) return
	// Capture before the delete clears `currentTransaction`: `sheetId` falls back to it on the edit
	// route, so reading it afterwards would navigate to an empty id instead of the sheet.
	const targetSheetId = sheetId.value
	transactionStore.clearError()
	try {
		await transactionStore.deleteExistingTransaction(transaction.id, transaction.version)
		deleteDialogOpen.value = false
		await router.push({ name: 'sheet-detail', params: { id: targetSheetId } })
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
	if (isEditing.value) {
		loadTransactionForEdit().then(() => { snapshotBaseline() }).catch(() => undefined)
	} else {
		Promise.all([loadOptions(), loadSheet()]).then(() => {
			preselectCostCenter()
			snapshotBaseline()
		}).catch(() => undefined)
	}
})
</script>

<template>
	<div class="transaction-form-view" :dir="direction" data-test="transaction-form-view">
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
					:reduce="selectOptionValue"
					:get-option-key="selectOptionKey"
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
					:reduce="selectOptionValue"
					:get-option-key="selectOptionKey"
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
						<!-- UI-PUR-010: evidence needs a stored transaction. The create route offers a
						     catalogued hint instead of a control that could only fail; the uploader stays
						     on the edit route while the transaction is a draft or awaiting correction. -->
						<EvidenceUploader
							v-if="editable && isEditing"
							:transaction-id="transactionId"
							:is-draft="isDraft" />
						<p v-else-if="!editable" class="read-only-hint" data-test="evidence-read-only-hint">
							{{ t('pettycash', 'common.readOnly') }}
						</p>
						<p v-else class="read-only-hint" data-test="evidence-after-save-hint">
							{{ t('pettycash', 'transactions.evidenceAfterSave') }}
						</p>
					</div>
				</div>

				<div class="form-actions">
					<NcButton
						v-if="canDeleteDraft"
						variant="error"
						type="button"
						data-test="delete-transaction-btn"
						@click="askDeleteDraft">
						{{ t('pettycash', 'transactions.deleteDraft') }}
					</NcButton>
					<NcButton variant="secondary" type="button" data-test="transaction-cancel" @click="askLeave()">
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
					<!--
						FR-047/US4: resubmit a corrected transaction. Rendered only while the form edits a
						returned transaction — a plain draft has nothing to resubmit, and a submitted one
						must not be edited at all (FR-026). Disabled until the draft satisfies the full
						required dataset, so the control never offers a resubmission the server would
						refuse; the handler re-checks the same computed rather than trusting the button.
					-->
					<NcButton
						v-if="isCorrection"
						variant="primary"
						:disabled="transactionStore.loading || !isResubmittable"
						:loading="transactionStore.loading"
						type="button"
						data-test="resubmit-correction-btn"
						@click="handleResubmit">
						{{ t('pettycash', 'transactions.resubmit') }}
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

		<NcNoteCard v-if="conflictMessage" type="warning" role="alert" data-test="conflict-message">
			{{ conflictMessage }}
		</NcNoteCard>

		<!-- UI-PUR-011: DRAFT-only deletion, with a confirmation that names the transaction. -->
		<div v-if="deleteDialogOpen" class="confirm-backdrop" data-test="delete-transaction-dialog">
			<section
				class="confirm-panel"
				role="dialog"
				aria-modal="true"
				aria-labelledby="delete-transaction-title">
				<h2 id="delete-transaction-title" class="confirm-panel__title">
					{{ t('pettycash', 'transactions.deleteDraftTitle') }}
				</h2>
				<p>{{ t('pettycash', 'transactions.deleteDraftMessage', { description: deleteDescription }) }}</p>
				<div class="confirm-panel__actions">
					<NcButton
						variant="secondary"
						data-test="cancel-delete-transaction"
						@click="deleteDialogOpen = false">
						{{ t('pettycash', 'common.cancel') }}
					</NcButton>
					<NcButton
						variant="error"
						:loading="transactionStore.loading"
						data-test="confirm-delete-transaction"
						@click="confirmDeleteDraft">
						{{ t('pettycash', 'transactions.deleteDraftConfirm') }}
					</NcButton>
				</div>
			</section>
		</div>

		<UnsavedChangesDialog
			:open="guardOpen"
			:saving="guardSaving"
			:save-failed="guardSaveFailed"
			@stay="stayOnForm"
			@discard="discardChanges"
			@save="saveAndLeave" />
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
	border-radius: var(--border-radius-small);
}

.alert--error {
	background-color: var(--color-background-hover);
	color: var(--color-error);
}

.confirm-backdrop {
	position: fixed;
	inset: 0;
	z-index: 10040;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 1rem;
	background-color: var(--color-backdrop);
}

.confirm-panel {
	inline-size: min(100%, 30rem);
	padding: 1.5rem;
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: 0 8px 32px var(--color-box-shadow);
	font-size: var(--font-size-floor);
}

.confirm-panel__title {
	margin-block-start: 0;
}

.confirm-panel__actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: 0.5rem;
	margin-block-start: 1.5rem;
}

.confirm-panel :focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
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

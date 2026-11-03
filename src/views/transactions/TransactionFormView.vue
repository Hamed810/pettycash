<script setup lang="ts">
import { direction } from '@/utils/direction'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import EvidenceList from '@/components/evidence/EvidenceList.vue'
import EvidencePreview from '@/components/evidence/EvidencePreview.vue'
import EvidenceUploader from '@/components/evidence/EvidenceUploader.vue'
import ReceiptStager from '@/components/evidence/ReceiptStager.vue'
import UnsavedChangesDialog from '@/components/shared/UnsavedChangesDialog.vue'
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard'
import { useTransactionDeletion } from '@/composables/useTransactionDeletion'
import { listActiveExpenseTypes, listCostCenters } from '@/services/configurationApi'
import { uploadEvidence } from '@/services/evidenceApi'
import { useSheetStore } from '@/stores/sheets'
import { useTransactionStore } from '@/stores/transactions'
import {
	ApiError,
	type CostCenter,
	type Evidence,
	type ExpenseType,
	type TransactionDetail,
	type TransactionDraftWrite
} from '@/types/api'
import { parseEvidenceFieldErrors, type EvidenceFieldError } from '@/utils/evidenceFieldErrors'
import { selectOptionKey, selectOptionValue } from '@/utils/selectOption'
import { restoreScrollOffset } from '@/utils/sheetListSession'

/**
 * T075: the existing form can host the OPEN-sheet edit experience inside `TransactionSurface`.
 *
 * The embedded call supplies the identity explicitly because the sheet route's `:id` names the
 * sheet, not the transaction. The full-page `/transactions/:id/edit` route remains the canonical
 * deep-link and keeps using its own params. No second form is introduced: this is the same
 * validation, save, delete, evidence and guard code in both hosts.
 */
const props = withDefaults(defineProps<{
	embedded?: boolean
	editTransactionId?: string
	editSheetId?: string
}>(), {
	embedded: false,
	editTransactionId: '',
	editSheetId: ''
})

const emit = defineEmits<{
	saved: []
	deleted: []
	/** Embedded preview is owned by the host surface so it can honour the one-dialog rule. */
	preview: [evidence: Evidence]
}>()

const route = useRoute()
const router = useRouter()
const transactionStore = useTransactionStore()
const sheetStore = useSheetStore()

const isEditing = computed(() => !!props.editTransactionId || !!route.params.id)
const transactionId = computed(() => props.editTransactionId || ((route.params.id as string | undefined) ?? ''))

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

/**
 * Receipts selected before the transaction exists (FR-090).
 *
 * Nothing is uploaded from here. The queue is held until the create response supplies a non-empty id,
 * and only then does `uploadStagedReceipts()` address the existing evidence endpoint. The failure list
 * survives a failed upload so the purchaser can retry without creating a second transaction.
 */
const stagedReceipts = ref<File[]>([])
const stagedUploadFailures = ref<Array<{ file: File; message: string }>>([])
const createdTransactionId = ref('')
const lastPersistedTransaction = ref<TransactionDetail | null>(null)

/** The named transactions in a correction-resubmission receipt refusal (FR-091). */
const correctionEvidenceErrors = ref<EvidenceFieldError[]>([])
const correctionEvidenceSummary = ref<HTMLElement | null>(null)

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
	props.editSheetId
	|| (route.params.sheetId as string | undefined)
	|| transactionStore.currentTransaction?.sheetId
	|| ''
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

const formTitle = computed(() => isEditing.value
	? t('pettycash', 'transactions.editTitle')
	: t('pettycash', 'transactions.createTitle'))

/**
 * The correction's receipt rule, as the server published it.
 *
 * T059 requires correction resubmission to apply the same rule as sheet submission. The client must
 * not re-derive it from the selected expense type: `TransactionSummary.evidenceRequired` is resolved by
 * the server from the active expense type's **current** setting (001 T434), so it is already the value
 * the resubmit command will apply. A missing value is treated as false rather than guessed.
 */
const correctionEvidenceRequired = computed(() =>
	transactionStore.currentTransaction?.evidenceRequired ?? false)

/** Only active evidence counts, per the same rule: a deactivated file does not satisfy the requirement. */
const activeEvidenceCount = computed(() =>
	(transactionStore.currentTransaction?.evidence ?? []).filter((item) => item.active).length)

/**
 * T075: the edit experience shows the transaction's evidence and permits removal only while the
 * transaction itself is a DRAFT. A correction has its own revision lifecycle, so it keeps the
 * existing uploader behavior and does not gain an evidence-removal surface here.
 */
const canEditEvidence = computed(() =>
	isEditing.value && transactionStore.currentTransaction?.status === 'DRAFT')

/**
 * FR-005-047 / C-21: an ordinary working draft on a still-open sheet has no evidence lifecycle to
 * narrate. The sheet is read from the store the form already loads (`loadSheet` above); requiring
 * the id to match keeps a stale sheet from another route out of the decision.
 *
 * `canEditEvidence` (and therefore removal) is unchanged: this only decides whether lifecycle
 * badges and withdrawal metadata are rendered, and whether withdrawn rows are listed.
 */
const neverSubmittedEvidence = computed(() => isEditing.value
	&& transactionStore.currentTransaction?.status === 'DRAFT'
	&& sheetStore.currentSheet?.id === sheetId.value
	&& sheetStore.currentSheet?.status === 'OPEN')

/**
 * T075 (2026-10-26 refinement): the footer's single-save rule applies only to (A) normal CREATE and
 * (B) non-correction editing of an ordinary `DRAFT` on a still-`OPEN` sheet.
 *
 * (B) reuses the `neverSubmittedEvidence` discriminator on purpose: `DRAFT` alone also matches a
 * legacy draft left on a submitted sheet, and that footer keeps its existing two controls. A
 * correction is excluded because saving it and resubmitting it have different side effects.
 */
const singlePrimarySave = computed(() => !isEditing.value
	|| (!isCorrection.value && neverSubmittedEvidence.value))

const submitButtonText = computed(() => {
	if (singlePrimarySave.value) {
		return isEditing.value
			? t('pettycash', 'transactions.saveChanges')
			: t('pettycash', 'transactions.saveDraft')
	}
	return isEditing.value
		? t('pettycash', 'common.update')
		: t('pettycash', 'common.create')
})

/** C-21: the heading counts the ordinary list, not lifecycle rows the list no longer shows. */
const ordinaryEvidenceCount = computed(() => {
	const evidence = transactionStore.currentTransaction?.evidence ?? []
	return neverSubmittedEvidence.value
		? evidence.filter((item) => item.active).length
		: evidence.length
})

const selectedEvidence = ref<Evidence | null>(null)
const previewOpen = computed(() => selectedEvidence.value !== null)

function openEvidencePreview(evidence: Evidence): void {
	if (props.embedded) {
		emit('preview', evidence)
		return
	}
	selectedEvidence.value = evidence
}

function closeEvidencePreview(): void {
	selectedEvidence.value = null
}

/**
 * Re-read the transaction after an upload or removal so the list shows the server's current active
 * set. The draft fields are left untouched; only the evidence collection and version are refreshed.
 */
async function refreshTransactionEvidence(): Promise<void> {
	if (!isEditing.value) return
	await transactionStore.loadTransaction(transactionId.value).catch(() => undefined)
}

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
	// The receipt rule is a resubmission rule only. It is never applied while saving a draft or
	// creating the first version, because the purchaser can select receipts before that save and the
	// upload necessarily happens after it (FR-089/FR-090). For a correction, the server's published
	// value decides, not a second client-side lookup.
	if (isCorrection.value && correctionEvidenceRequired.value && activeEvidenceCount.value === 0) {
		found.evidence = t('pettycash', 'validation.evidenceRequired')
	}
	return found
}

/** The same rules, evaluated live for the resubmit button's disabled state. */
const validationErrors = computed(() => collectValidationErrors())

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
const deletion = useTransactionDeletion()

function snapshotBaseline(): void { baseline.value = JSON.stringify(draft.value) }
/**
 * A non-empty receipt queue is dirty even when every field already matches the saved draft. The files
 * are not on the server yet, so leaving would silently discard the purchaser's selection (T054).
 */
const isDirty = computed(() =>
	JSON.stringify(draft.value) !== baseline.value || stagedReceipts.value.length > 0)

/** UI-PUR-011 + T067: only an open sheet's draft may be deleted, as the server permits. */
const canDeleteDraft = computed(() => isEditing.value
	&& transactionStore.currentTransaction?.status === 'DRAFT'
	&& transactionStore.currentTransaction?.allowedActions.canDelete === true
	&& sheetStore.currentSheet?.status === 'OPEN'
	&& !transactionStore.mutationLoading
	&& !deletion.pending.value)

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
	// T075: the embedded form returns to the workspace that hosts it. The full-page form keeps its
	// existing transactions-list fallback.
	fallback: () => props.embedded
		? { name: 'sheet-detail', params: { id: sheetId.value } }
		: { name: 'transactions', params: { sheetId: sheetId.value } },
	isBlocked: () => deleteDialogOpen.value,
})

/** The host's close button and scrim use the same guard as the form's Cancel control. */
function requestClose(): void { askLeave() }

defineExpose({ requestClose })

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
	// T075: the embedding surface already read this sheet to decide the capability. The standalone
	// page keeps its own read.
	if (props.embedded && sheetStore.currentSheet?.id === sheetId.value) return
	// The sheet supplies the inherited currency (FR-021). A failure is recorded in the store's own
	// `error` and rendered by the view's alert; it must not abort loading the rest of the form.
	await sheetStore.loadSheet(sheetId.value)
}

async function loadTransactionForEdit(): Promise<void> {
	if (!isEditing.value) return
	// T075: the host surface reads the transaction before it mounts the editor. Do not issue the same
	// authoritative read twice in that one host; the full-page route always reads its own record.
	if (!props.embedded || transactionStore.currentTransaction?.id !== transactionId.value) {
		await transactionStore.loadTransaction(transactionId.value)
	}
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
	correctionEvidenceErrors.value = []
	try {
		let saved: TransactionDetail | null = null
		if (isEditing.value) {
			// UI-PUR-013: an update must never send a placeholder because the record was not read.
			// Version `0` is the legitimate first version, so "missing" is `undefined`, not falsy.
			const version = transactionStore.currentTransaction?.version
			if (version === undefined) {
				await transactionStore.loadTransaction(transactionId.value)
				conflictMessage.value = t('pettycash', 'guard.conflict')
				return false
			}
			saved = await transactionStore.updateExistingTransaction(transactionId.value, draft.value, version)
		} else {
			// A retry after a failed receipt upload must never create a second transaction. The first
			// create already succeeded; only its uploads are outstanding.
			if (createdTransactionId.value) return true
			saved = await transactionStore.createNewTransaction(sheetId.value, draft.value)
			createdTransactionId.value = saved.id
		}
		lastPersistedTransaction.value = saved
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

/**
 * Uploads the pre-save queue, one file at a time, after the transaction exists.
 *
 * A successful file leaves the queue immediately, so a retry sends only what still failed. An empty id
 * fails closed: the form does not issue `POST /transactions//evidence` and does not pretend a saved
 * transaction can receive a file it has no address for.
 */
async function uploadStagedReceipts(transactionAddress: string): Promise<boolean> {
	stagedUploadFailures.value = []
	if (stagedReceipts.value.length === 0) return true
	if (!transactionAddress) {
		const message = t('pettycash', 'transactions.receiptUploadNoId')
		stagedUploadFailures.value = stagedReceipts.value.map((file) => ({ file, message }))
		return false
	}

	for (const file of [...stagedReceipts.value]) {
		try {
			await uploadEvidence(transactionAddress, file)
			stagedReceipts.value = stagedReceipts.value.filter((candidate) => candidate !== file)
		} catch (error: unknown) {
			const message = error instanceof Error && error.message
				? error.message
				: t('pettycash', 'transactions.receiptUploadFailed')
			stagedUploadFailures.value.push({ file, message })
		}
	}
	return stagedUploadFailures.value.length === 0
}

/** Uploads the queue only for a newly created transaction; an edit uses the existing uploader. */
async function uploadAfterSave(): Promise<boolean> {
	if (isEditing.value) return true
	const address = createdTransactionId.value || lastPersistedTransaction.value?.id || ''
	return uploadStagedReceipts(address)
}

async function saveAndReturnToSheet(): Promise<void> {
	errors.value = collectDraftErrors()
	if (Object.keys(errors.value).length > 0) return
	if (!await persist()) return
	if (!await uploadAfterSave()) return
	// T075: the host surface refreshes the owning sheet and closes, leaving the workspace in place.
	// The full-page form keeps FR-088's sheet-detail navigation.
	if (props.embedded) {
		emit('saved')
		return
	}
	await router.push({ name: 'sheet-detail', params: { id: sheetId.value } })
}

/** The guard's Save action: a draft save, never a workflow submission. */
async function saveForGuard(): Promise<boolean> {
	// Once the create has succeeded, Save means "retry the outstanding uploads", not "create again".
	if (!isEditing.value && createdTransactionId.value) {
		return uploadStagedReceipts(createdTransactionId.value)
	}
	errors.value = collectDraftErrors()
	if (Object.keys(errors.value).length > 0) return false
	if (!await persist()) return false
	return uploadAfterSave()
}

async function handleSaveDraft(): Promise<void> {
	await saveAndReturnToSheet()
}

/**
 * The primary create/update action is a **save**, not a workflow submission.
 *
 * It therefore uses exactly the FR-022 draft rules. Reusing the submission rules here would make a
 * receipt-required expense type impossible to create: the receipt can only be uploaded after the first
 * save (FR-090), so a submission-only rule would disable the only control that creates the row.
 */
async function handleSubmit(): Promise<void> {
	await saveAndReturnToSheet()
}

async function retryStagedUploads(): Promise<void> {
	const address = createdTransactionId.value || lastPersistedTransaction.value?.id || ''
	if (await uploadStagedReceipts(address)) {
		await router.push({ name: 'sheet-detail', params: { id: sheetId.value } })
	}
}

async function continueToSheet(): Promise<void> {
	await router.push({ name: 'sheet-detail', params: { id: sheetId.value } })
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
	correctionEvidenceErrors.value = []
	try {
		const version = transactionStore.currentTransaction?.version
		if (version === undefined) {
			await transactionStore.loadTransaction(transactionId.value)
			conflictMessage.value = t('pettycash', 'guard.conflict')
			return
		}
		await transactionStore.resubmitCorrectionDraft(transactionId.value, version)
		// The server accepted the resubmission, so the form no longer holds unsaved
		// changes. Without this, a draft value the server has just stored (or a
		// control normalising as the form becomes read-only) can leave `isDirty`
		// true and make the route guard ask about changes that were already saved.
		snapshotBaseline()
		// FR-088, same as `handleSaveDraft()` above: a save terminates on the sheet detail,
		// never on the transactions list.
		await router.push({ name: 'sheet-detail', params: { id: sheetId.value } })
	} catch (error: unknown) {
		if (error instanceof ApiError && error.status === 409) {
			await reloadAfterConflict()
			conflictMessage.value = t('pettycash', 'guard.conflict')
			return
		}
		if (error instanceof ApiError && error.status === 422) {
			const evidenceErrors = parseEvidenceFieldErrors(error.body.fieldErrors)
			if (evidenceErrors.length > 0) {
				// The 422 is the authoritative refusal. Keep every named transaction in one summary and
				// move focus there, rather than leaving only `transactionStore.error`'s generic line.
				correctionEvidenceErrors.value = evidenceErrors
				await nextTick()
				correctionEvidenceSummary.value?.focus()
			}
		}
	}
}

function correctionTransactionLabel(evidenceError: EvidenceFieldError): string {
	const transaction = transactionStore.currentTransaction
	if (transaction?.id === evidenceError.transactionId) {
		return transaction.activeRevision.description?.trim() || transaction.id
	}
	return evidenceError.transactionId
}

/** UI-PUR-011: delete is offered for a draft only, and names the transaction in the confirmation. */
function askDeleteDraft(): void { deleteDialogOpen.value = true }

async function confirmDeleteDraft(): Promise<void> {
	const transaction = transactionStore.currentTransaction
	if (!transaction || deletion.pending.value) return
	// Capture before the delete clears `currentTransaction`: `sheetId` falls back to it on the edit
	// route, so reading it afterwards would navigate to an empty id instead of the sheet.
	const targetSheetId = sheetId.value
	transactionStore.clearError()
	// One command identity per (transaction, version) intent: a retry after an unknown outcome is the
	// same delete, and the server replays its original 204 instead of answering "not found" (T065).
	const result = await deletion.remove(transaction.id, transaction.version)
	if (result.outcome === 'deleted' || result.outcome === 'already-deleted') {
		deleteDialogOpen.value = false
		if (props.embedded) {
			// The row is gone; the host refreshes the sheet and closes the surface. The baseline is
			// reset first so a route/query change during that close cannot prompt about unsaved fields
			// for a transaction the server has already deleted.
			snapshotBaseline()
			emit('deleted')
			return
		}
		await router.push({ name: 'sheet-detail', params: { id: targetSheetId } })
		return
	}
	if (result.status === 409 || result.status === 422 || result.status === 404) {
		// A refused delete means this form is describing stale state. Re-read both resources before
		// offering the action again; the fresh capability is what decides whether it stays visible.
		await Promise.allSettled([
			transactionStore.loadTransaction(transaction.id),
			targetSheetId ? sheetStore.loadSheet(targetSheetId) : Promise.resolve(),
		])
	}
}

/**
 * The purchaser's own errors are stale as soon as they start correcting them. Only re-evaluate
 * while a summary is already on screen: the form pre-fills fields on mount, and validating that
 * programmatic change painted \"the amount must be positive\" before the purchaser had typed.
 */
watch(draft, () => {
	if (Object.keys(errors.value).length === 0) return
	// Saving and resubmitting have different rules. A create/save form must not repaint a
	// submission-only field (description, cost center, expense type, receipt) merely because the
	// purchaser changed the date.
	errors.value = isCorrection.value ? collectValidationErrors() : collectDraftErrors()
}, { deep: true })

onMounted(() => {
	// A full-page form reached from the sheet workspace inherits the app scroll container's offset;
	// the breadcrumb and title would otherwise open above the viewport. T075's embedded form sits in
	// the workspace and MUST leave the reader's scroll position alone.
	if (!props.embedded) restoreScrollOffset(0)
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
	<div
		class="transaction-form-view"
		:class="{ 'transaction-form-view--embedded': embedded }"
		:dir="direction"
		data-test="transaction-form-view"
	>
		<nav v-if="!embedded" class="breadcrumbs" :aria-label="t('pettycash', 'common.breadcrumbs')">
			<ol class="breadcrumbs__list">
				<li class="breadcrumbs__item">
					<RouterLink :to="{ name: 'sheets' }">{{ t('pettycash', 'sheets.title') }}</RouterLink>
				</li>
				<li class="breadcrumbs__item">
					<RouterLink :to="{ name: 'transactions', params: { sheetId } }">
						{{ t('pettycash', 'transactions.list') }}
					</RouterLink>
				</li>
				<li class="breadcrumbs__item" aria-current="page">{{ formTitle }}</li>
			</ol>
		</nav>

		<section class="transaction-form-card" data-test="transaction-form">
			<h2 v-if="!embedded" class="transaction-form-card__title">{{ formTitle }}</h2>
			<form class="transaction-form" novalidate @submit.prevent="handleSubmit">
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

				<!-- T058: a correction-resubmission 422 names the transaction whose receipt is
				     missing. The summary is focusable so the refusal is announced and the first
				     action is to read it, not to hunt through the form. -->
				<div
					v-if="correctionEvidenceErrors.length > 0"
					ref="correctionEvidenceSummary"
					class="alert alert--error correction-evidence-errors"
					role="alert"
					aria-live="assertive"
					tabindex="-1"
					data-test="correction-evidence-errors"
				>
					<strong>{{ t('pettycash', 'transactions.correctionEvidenceBlocked') }}</strong>
					<ul class="validation-summary-list">
						<li
							v-for="evidenceError in correctionEvidenceErrors"
							:key="evidenceError.transactionId"
							data-test="correction-evidence-error"
						>
							{{ correctionTransactionLabel(evidenceError) }} — {{ evidenceError.messages.join(' ') }}
						</li>
					</ul>
				</div>

				<div
					v-if="isCorrection"
					class="alert alert--info correction-notice"
					role="status"
					data-test="correction-notice"
				>
					{{ t('pettycash', 'transactions.correctionNotice') }}
				</div>

				<div class="form-row form-row--amount">
					<!-- FR-021: the currency is shown, not chosen. A read-only field rather than a disabled
					     select, because a disabled control offers an affordance that does not exist. -->
					<div class="form-col">
						<NcTextField
							:model-value="sheetStore.currentSheet?.currency ?? ''"
							:label="t('pettycash', 'transactions.currency')"
							:aria-label="t('pettycash', 'transactions.currency')"
							readonly
							data-test="transaction-currency"
						/>
					</div>
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
						data-test="expense-date-input"
					/>
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
						data-test="amount-input"
					/>
				</div>

				<div class="form-row form-row--full">
					<NcTextArea
						v-model="descriptionModel"
						:label="t('pettycash', 'transactions.description')"
						:aria-label="t('pettycash', 'transactions.description')"
						:error="!!errors.description"
						:helper-text="errors.description || ''"
						:disabled="!editable"
						:rows="3"
						required
						data-test="description-input"
					/>
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
						data-test="cost-center-select"
					/>
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
						data-test="expense-type-select"
					/>
				</div>

				<div class="form-row form-row--full">
					<div class="evidence-upload-area" data-test="evidence-upload-area">
						<!-- FR-090/T054: the create route selects receipts before the transaction exists and
						     uploads them after the create response. The existing uploader stays on the edit
						     route, where the transaction id already exists. -->
						<ReceiptStager
							v-if="editable && !isEditing"
							v-model="stagedReceipts"
							:disabled="transactionStore.mutationLoading || !!createdTransactionId"
						/>
						<EvidenceUploader
							v-else-if="editable && isEditing"
							:transaction-id="transactionId"
							:is-draft="isDraft"
							compact
							@uploaded="refreshTransactionEvidence"
						/>
						<p v-else class="read-only-hint" data-test="evidence-read-only-hint">
							{{ t('pettycash', 'common.readOnly') }}
						</p>
					</div>
				</div>

				<!-- T075: the edit experience keeps the transaction's existing evidence in view. The
				     list reuses the same safe preview/download/remove component as the read surface. -->
				<div v-if="isEditing" class="form-row form-row--full" data-test="edit-evidence-list">
					<h3 class="edit-evidence-list__title">
						{{ t('pettycash', 'evidence.list') }}
						({{ ordinaryEvidenceCount }})
					</h3>
					<EvidenceList
						:evidence="transactionStore.currentTransaction?.evidence ?? []"
						:is-draft="canEditEvidence"
						:never-submitted="neverSubmittedEvidence"
						@preview="openEvidencePreview"
						@changed="refreshTransactionEvidence"
					/>
				</div>

				<!-- T054: creation already succeeded when this renders. The transaction is saved; only
				     the file transfer failed. Retry addresses the existing id and never creates again. -->
				<div
					v-if="stagedUploadFailures.length > 0"
					class="alert alert--error staged-upload-failures"
					role="alert"
					data-test="staged-upload-failures"
				>
					<strong>{{ t('pettycash', 'transactions.receiptUploadFailedTitle') }}</strong>
					<p>{{ t('pettycash', 'transactions.receiptUploadSaved') }}</p>
					<ul class="validation-summary-list">
						<li
							v-for="failure in stagedUploadFailures"
							:key="`${failure.file.name}:${failure.file.size}`"
							data-test="staged-upload-failure"
						>
							{{ failure.file.name }} — {{ failure.message }}
						</li>
					</ul>
					<div class="form-actions">
						<NcButton
							variant="primary"
							type="button"
							:loading="transactionStore.mutationLoading"
							data-test="retry-staged-uploads"
							@click="retryStagedUploads"
						>
							{{ t('pettycash', 'common.retry') }}
						</NcButton>
						<NcButton
							variant="secondary"
							type="button"
							data-test="continue-to-sheet"
							@click="continueToSheet"
						>
							{{ t('pettycash', 'transactions.continueToSheet') }}
						</NcButton>
					</div>
				</div>

				<div class="form-actions">
					<NcButton
						v-if="canDeleteDraft"
						variant="error"
						type="button"
						data-test="delete-transaction-btn"
						@click="askDeleteDraft"
					>
						{{ t('pettycash', 'transactions.deleteDraft') }}
					</NcButton>
					<NcButton variant="secondary" type="button" data-test="transaction-cancel" @click="askLeave()">
						{{ t('pettycash', 'common.cancel') }}
					</NcButton>
					<NcButton
						v-if="!createdTransactionId && !singlePrimarySave"
						variant="secondary"
						:disabled="!editable || transactionStore.mutationLoading || !isDraftSavable"
						type="button"
						data-test="transaction-save-draft"
						@click="handleSaveDraft"
					>
						{{ t('pettycash', 'transactions.saveDraft') }}
					</NcButton>
					<NcButton
						v-if="!isCorrection && !createdTransactionId"
						variant="primary"
						:disabled="!editable || transactionStore.mutationLoading || !isDraftSavable"
						:loading="transactionStore.mutationLoading"
						type="submit"
						data-test="submit-btn"
					>
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
						:disabled="transactionStore.mutationLoading || !isResubmittable"
						:loading="transactionStore.mutationLoading"
						type="button"
						data-test="resubmit-correction-btn"
						@click="handleResubmit"
					>
						{{ t('pettycash', 'transactions.resubmit') }}
					</NcButton>
				</div>
			</form>
		</section>

		<NcNoteCard
			v-if="transactionStore.error && correctionEvidenceErrors.length === 0"
			type="error"
			role="alert"
			data-test="form-error"
		>
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
				aria-labelledby="delete-transaction-title"
			>
				<h2 id="delete-transaction-title" class="confirm-panel__title">
					{{ t('pettycash', 'transactions.deleteDraftTitle') }}
				</h2>
				<p>{{ t('pettycash', 'transactions.deleteDraftMessage', { description: deleteDescription }) }}</p>
				<div class="confirm-panel__actions">
					<NcButton
						variant="secondary"
						data-test="cancel-delete-transaction"
						@click="deleteDialogOpen = false"
					>
						{{ t('pettycash', 'common.cancel') }}
					</NcButton>
					<NcButton
						variant="error"
						:disabled="deletion.pending.value"
						:loading="deletion.pending.value"
						data-test="confirm-delete-transaction"
						@click="confirmDeleteDraft"
					>
						{{ t('pettycash', 'transactions.deleteDraftConfirm') }}
					</NcButton>
				</div>
			</section>
		</div>

		<!-- Embedded preview is rendered by TransactionSurface so the host can make itself inert
		     and preserve the one-dialog rule while the preview is open. -->
		<EvidencePreview
			v-if="!embedded"
			:evidence="selectedEvidence"
			:open="previewOpen"
			@close="closeEvidencePreview"
		/>

		<UnsavedChangesDialog
			:open="guardOpen"
			:saving="guardSaving"
			:save-failed="guardSaveFailed"
			@stay="stayOnForm"
			@discard="discardChanges"
			@save="saveAndLeave"
		/>
	</div>
</template>

<style scoped>
.transaction-form-view {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

/* T075: inside the transaction surface, the host already supplies the dialog frame and title. */
.transaction-form-view--embedded {
	gap: 0;
}

/* T077: the grid's `1fr` track has an automatic min-content minimum. A long selected filename is a
   single unbreakable line, so without this the upload host expanded to the filename's full width and
   pushed the compact controls off-screen at 390px. The uploader is the only compact host here. */
.evidence-upload-area {
	min-width: 0;
}

.edit-evidence-list__title {
	margin: 0 0 0.5rem;
	font-size: var(--font-size-emphasis);
}

.transaction-form-card {
	inline-size: min(100%, 68rem);
	margin-inline: auto;
	padding: 1.5rem clamp(1rem, 3vw, 2rem);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
}

.transaction-form-view--embedded .transaction-form-card {
	inline-size: 100%;
	padding: 0;
	border: 0;
	background-color: transparent;
}

.transaction-form-card__title {
	margin: 0 0 1.25rem;
	font-size: var(--font-size-title);
	font-weight: 700;
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

.alert--info {
	background-color: var(--color-background-hover);
	border-inline-start: 3px solid var(--color-primary-element);
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
	line-height: 1.5;
}

.correction-notice {
	display: block;
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
	background-color: var(--pettycash-scrim, color-mix(in srgb, black 55%, transparent));
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

	.form-row--full {
		grid-template-columns: 1fr;
	}

	.form-row--amount > .form-col {
		grid-column: 1 / -1;
	}
}

@media (width >= 901px) {
	.form-row--amount {
		grid-template-columns: minmax(8.5rem, 0.55fr) minmax(0, 1fr) minmax(0, 1fr);
	}

	.form-row--amount > .form-col {
		grid-column: auto;
	}
}

@media (width <= 390px) {
	.form-actions {
		flex-direction: column;
		align-items: stretch;
	}
}
</style>

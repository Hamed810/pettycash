<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch, useTemplateRef } from 'vue'
import { useRouter } from 'vue-router'
import { t } from '@nextcloud/l10n'
import { NcButton, NcChip, NcIconSvgWrapper } from '@nextcloud/vue'
import { mdiClose, mdiDelete, mdiLock, mdiPencil, mdiUndoVariant } from '@mdi/js'
import TransactionFormView from '@/views/transactions/TransactionFormView.vue'
import EvidenceList from '@/components/evidence/EvidenceList.vue'
import EvidencePreview from '@/components/evidence/EvidencePreview.vue'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import { useTransactionStore } from '@/stores/transactions'
import { useTransactionDeletion } from '@/composables/useTransactionDeletion'
import { direction } from '@/utils/direction'
import { formatSheetDate, formatSheetDateTime } from '@/utils/date'
import { formatMoney } from '@/utils/money'
import { stageKey } from '@/l10n'
import type { Evidence, SheetStatus, TransactionStatus } from '@/types/api'

/**
 * A transaction read/act surface opened from the sheet workspace (005 T066–T069).
 *
 * The workspace table only carries `TransactionSummary`, which has no capability projection. This
 * surface therefore reads `GET /transactions/{id}` and takes every action flag from that detail
 * (`allowedActions`) — never from the row underneath it. Opening the surface issues reads only.
 *
 * It is a **sibling** of the sheet workspace, not a child: at ≤640px the sheet workspace drops its
 * `role="dialog"` while this surface is open, so exactly one dialog exists. The evidence preview
 * repeats the rule one level deeper: while it is open, this surface drops its own dialog role and
 * becomes inert.
 */
const props = withDefaults(defineProps<{
	transactionId: string
	sheetId: string
	sheetStatus?: SheetStatus | null
	/** T075: the OPEN-sheet eye action enters the existing edit form directly. */
	startInEdit?: boolean
}>(), {
	sheetStatus: null,
	startInEdit: false
})

const emit = defineEmits<{
	close: []
	/** A delete/save completed (or the row was already gone); the owning sheet must be refreshed. */
	refresh: [outcome: 'deleted' | 'already-deleted' | 'saved']
}>()

const router = useRouter()
const transactionStore = useTransactionStore()
const deletion = useTransactionDeletion()

const TITLE_ID = 'transaction-surface-title'
const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

const dialogRef = useTemplateRef<HTMLElement>('dialogRef')
const editForm = useTemplateRef<{ requestClose: () => void }>('editForm')
const confirmingDelete = ref(false)
const deleteNotice = ref<string | null>(null)
const selectedEvidence = ref<Evidence | null>(null)
let previewOpener: HTMLElement | null = null

// T075: `startInEdit` is the OPEN-sheet eye path. The surface still reads the transaction and its
// capability set first; the editor is only rendered once `canEdit` is true, so a stale or
// unauthorized row falls back to the existing read-only inspection instead of offering an edit.
const editing = ref(props.startInEdit)
/**
 * The editor stays mounted while its own delete request completes. The store clears
 * `currentTransaction` on a successful DELETE, and without this latch the `canEdit` projection
 * would unmount the form before it could emit `deleted` back to the host.
 */
const editorMounted = ref(false)

watch(() => props.startInEdit, (value) => {
	if (value) editing.value = true
})
watch(() => props.transactionId, () => {
	editing.value = props.startInEdit
	editorMounted.value = false
})

const transaction = computed(() => transactionStore.currentTransaction?.id === props.transactionId
	? transactionStore.currentTransaction
	: null)
const revisions = computed(() => transaction.value ? transactionStore.revisions : [])

const loading = computed(() => transaction.value === null
	&& transactionStore.detailLoadingId === props.transactionId)
const notFound = computed(() => transaction.value === null
	&& !loading.value
	&& transactionStore.detailErrorStatus === 404)
const loadError = computed(() => transaction.value === null
	&& !loading.value
	&& transactionStore.detailErrorStatus !== 404
	&& transactionStore.detailError !== null)

const status = computed<TransactionStatus | undefined>(() => transaction.value?.status)
const isDraft = computed(() => status.value === 'DRAFT')
const isCorrection = computed(() => status.value === 'AWAITING_PURCHASER_CORRECTION')
const isSubmitted = computed(() => status.value !== undefined && status.value !== 'DRAFT')
const showReadOnly = computed(() => isSubmitted.value && !isCorrection.value)

/**
 * T067's action matrix. Both facts are read independently: the detail's capability set says the
 * purchaser *may* do it, and the owning sheet's state says whether that act is still available.
 * Edit/Delete additionally require the transaction itself to be a draft — `canEdit` alone must not
 * offer an edit on a submitted row.
 */
const canEdit = computed(() => transaction.value?.allowedActions.canEdit === true
	&& isDraft.value
	&& props.sheetStatus === 'OPEN')
const canDelete = computed(() => transaction.value?.allowedActions.canDelete === true
	&& isDraft.value
	&& props.sheetStatus === 'OPEN')
const canCorrect = computed(() => transaction.value?.allowedActions.canCorrect === true
	&& isCorrection.value)

/** The editable host is only valid for an authorized OPEN-sheet draft. */
const showsEditor = computed(() => editing.value && canEdit.value)

// Once the form has been legitimately mounted, keep it mounted through its own delete request so
// the successful `deleted` outcome can reach the host after the store clears the detail.
watch(showsEditor, (value) => {
	if (value) editorMounted.value = true
}, { immediate: true })

const previewOpen = computed(() => selectedEvidence.value !== null)

/**
 * T069: a never-submitted draft has working revisions, not official history. The list is hidden
 * rather than deleted; the rows remain in storage and become visible after submission.
 */
const isNeverSubmitted = computed(() => isDraft.value)

/**
 * C-21's evidence-presentation state: a never-submitted draft whose owning sheet is still OPEN.
 *
 * `isDraft` alone is deliberately not enough. A submitted legacy row can still read `DRAFT`, which
 * is why the server's own evidence-deletion boundary is the sheet (`EvidenceController` asks the
 * sheet, not the transaction). The `sheetStatus` prop is the workspace's persisted sheet state; a
 * null value leaves the historical presentation in place rather than guessing.
 */
const neverSubmittedEvidence = computed(() => isDraft.value && props.sheetStatus === 'OPEN')

/** C-21: the ordinary list shows current attachments, so its count must agree with it. */
const ordinaryEvidenceCount = computed(() => {
	const evidence = transaction.value?.evidence ?? []
	return neverSubmittedEvidence.value ? evidence.filter((item) => item.active).length : evidence.length
})

/**
 * A correction that has not been resubmitted is the transaction's *working* revision.
 *
 * `Revision.active` is projected from `transactions.active_revision_id`, so when a correction draft
 * exists it is the active revision and the submitted revision it replaces is no longer active. The
 * presence of an earlier revision is what distinguishes a working correction from a returned
 * transaction whose correction has not been started yet (a correction adds revision N+1).
 */
const workingCorrection = computed(() => {
	if (!isCorrection.value || !transaction.value) return null
	if (revisions.value.length < 2) return null
	const active = transaction.value.activeRevision
	return revisions.value.find((revision) => revision.id === active.id) ?? null
})
const officialRevisions = computed(() => {
	if (isNeverSubmitted.value) return []
	if (!workingCorrection.value) return revisions.value
	return revisions.value.filter((revision) => revision.id !== workingCorrection.value?.id)
})
const workingCorrectionLabel = computed(() => {
	const revision = workingCorrection.value
	return revision ? `#${revision.number}` : ''
})

async function loadData(): Promise<void> {
	const id = props.transactionId
	await transactionStore.loadTransaction(id).catch(() => undefined)
	if (transactionStore.currentTransaction?.id !== id) return
	if (transactionStore.detailErrorStatus === 404) return
	await transactionStore.loadRevisions(id).catch(() => undefined)
}

onMounted(loadData)
watch(() => props.transactionId, () => {
	confirmingDelete.value = false
	deleteNotice.value = null
	selectedEvidence.value = null
	void loadData()
})

/* ------------------------------------------------------------------ *
 * Dialog semantics
 * ------------------------------------------------------------------ */

function onKeydown(event: KeyboardEvent): void {
	if (event.key === 'Escape') {
		// The embedded form's global unsaved-changes guard owns Escape (it must be able to offer
		// Save/Discard/Stay). Handling Escape here too would close the surface under the guard.
		if (editorMounted.value) return
		event.preventDefault()
		if (confirmingDelete.value) {
			confirmingDelete.value = false
			return
		}
		close()
		return
	}
	if (event.key !== 'Tab' || !dialogRef.value) return
	const nodes = Array.from(dialogRef.value.querySelectorAll<HTMLElement>(FOCUSABLE))
		.filter((element) => {
			let node: HTMLElement | null = element
			while (node) {
				const style = window.getComputedStyle(node)
				if (style.display === 'none' || style.visibility === 'hidden') return false
				node = node.parentElement
			}
			return true
		})
	if (nodes.length === 0) return
	const first = nodes[0] as HTMLElement
	const last = nodes[nodes.length - 1] as HTMLElement
	if (event.shiftKey && document.activeElement === first) {
		event.preventDefault()
		last.focus()
	} else if (!event.shiftKey && document.activeElement === last) {
		event.preventDefault()
		first.focus()
	}
}

function close(): void {
	if (previewOpen.value) {
		closePreview()
		return
	}
	if (editorMounted.value) {
		// Header close and scrim must go through the form's unsaved-changes guard, not discard edits.
		if (editForm.value) editForm.value.requestClose()
		else emit('close')
		return
	}
	emit('close')
}

function goToEdit(): void {
	// T075: Edit from the read surface now enters the same embedded form; no full-page navigation.
	confirmingDelete.value = false
	deletion.reset()
	editing.value = true
}

function goToCorrect(): void {
	void router.push({ name: 'transaction-edit', params: { id: props.transactionId } })
}

function handleSaved(): void {
	emit('refresh', 'saved')
}

function handleDeleted(): void {
	emit('refresh', 'deleted')
}

/* ------------------------------------------------------------------ *
 * Deletion (T065)
 * ------------------------------------------------------------------ */

async function askDelete(): Promise<void> {
	confirmingDelete.value = true
	deleteNotice.value = null
	await nextTick()
	document.querySelector<HTMLElement>('[data-test="confirm-delete-transaction"]')?.focus()
}

function cancelDelete(): void {
	confirmingDelete.value = false
	deletion.reset()
}

async function confirmDelete(): Promise<void> {
	const detail = transaction.value
	if (!detail || deletion.pending.value) return
	const result = await deletion.remove(detail.id, detail.version)
	confirmingDelete.value = false
	if (result.outcome === 'deleted') {
		emit('refresh', 'deleted')
		return
	}
	if (result.outcome === 'already-deleted') {
		deleteNotice.value = t('pettycash', 'transactions.deleteAlreadyHandled')
		emit('refresh', 'already-deleted')
		return
	}
	// A refused delete is stale state, not a transport-only failure: read the transaction and the
	// owning sheet again before the purchaser can act on information the server has already moved on
	// from. The host refreshes the sheet; this surface refreshes its own detail.
	if (result.status === 409 || result.status === 422) {
		emit('refresh', 'already-deleted')
	}
	await loadData()
}

/* ------------------------------------------------------------------ *
 * Evidence preview (T068)
 * ------------------------------------------------------------------ */

function openPreview(evidence: Evidence): void {
	previewOpener = document.activeElement instanceof HTMLElement ? document.activeElement : null
	selectedEvidence.value = evidence
}

function closePreview(): void {
	selectedEvidence.value = null
	void nextTick(() => {
		if (previewOpener?.isConnected) previewOpener.focus()
		previewOpener = null
	})
}
</script>

<template>
	<div class="transaction-surface" :dir="direction" data-test="transaction-surface-layer">
		<div class="transaction-surface__scrim" data-test="transaction-surface-scrim" @click="close" />
		<section
			ref="dialogRef"
			class="transaction-surface__dialog"
			:role="previewOpen ? undefined : 'dialog'"
			:aria-modal="previewOpen ? undefined : 'true'"
			:aria-hidden="previewOpen ? 'true' : undefined"
			:inert="previewOpen ? true : undefined"
			:aria-labelledby="TITLE_ID"
			tabindex="-1"
			data-test="transaction-surface"
			@keydown="onKeydown"
		>
			<header class="transaction-surface__header">
				<div class="transaction-surface__identity">
					<h2 :id="TITLE_ID" class="transaction-surface__title" data-test="transaction-surface-title">
						{{ editorMounted ? t('pettycash', 'transactions.editTitle') : t('pettycash', 'transactions.surfaceTitle') }}
					</h2>
					<span class="transaction-surface__id">{{ transactionId }}</span>
				</div>
				<div class="transaction-surface__header-actions">
					<NcChip v-if="showReadOnly && !editorMounted" no-close data-test="transaction-surface-readonly">
						<NcIconSvgWrapper :path="mdiLock" :size="14" aria-hidden="true" />
						{{ t('pettycash', 'common.readOnly') }}
					</NcChip>
					<NcButton
						variant="tertiary"
						:aria-label="t('pettycash', 'common.close')"
						data-test="close-transaction-surface"
						@click="close"
					>
						<NcIconSvgWrapper :path="mdiClose" :size="20" />
					</NcButton>
				</div>
			</header>

			<p v-if="deleteNotice" class="transaction-surface__notice" role="status" data-test="delete-already-handled">
				{{ deleteNotice }}
			</p>

			<p v-if="loading" class="transaction-surface__state" data-test="transaction-surface-loading">
				{{ t('pettycash', 'common.loading') }}
			</p>

			<div v-else-if="notFound" class="transaction-surface__state" data-test="transaction-surface-not-found">
				<p>{{ t('pettycash', 'transactions.surfaceNotFound') }}</p>
				<NcButton variant="secondary" data-test="transaction-surface-back" @click="close">
					{{ t('pettycash', 'workspace.backToSheets') }}
				</NcButton>
			</div>

			<div v-else-if="loadError" class="transaction-surface__state" role="alert" data-test="transaction-surface-error">
				<p>{{ transactionStore.detailError }}</p>
				<NcButton variant="secondary" data-test="transaction-surface-retry" @click="loadData">
					{{ t('pettycash', 'common.retry') }}
				</NcButton>
			</div>

			<template v-else>
				<!-- T075: the OPEN-sheet eye click enters the existing form inside this dialog. The
				     read-only inspection below remains the fallback for every non-editable state. -->
				<TransactionFormView
					v-if="editorMounted"
					ref="editForm"
					embedded
					:edit-transaction-id="transactionId"
					:edit-sheet-id="sheetId"
					@preview="openPreview"
					@saved="handleSaved"
					@deleted="handleDeleted"
				/>

				<template v-else-if="transaction">
					<div v-if="confirmingDelete" class="transaction-surface__confirm" role="group" aria-labelledby="delete-transaction-confirm-title" data-test="delete-transaction-confirm">
						<h3 id="delete-transaction-confirm-title" class="transaction-surface__confirm-title">
							{{ t('pettycash', 'transactions.deleteDraftTitle') }}
						</h3>
						<p>{{ t('pettycash', 'transactions.deleteDraftMessage', { description: transaction.activeRevision.description ?? transaction.id }) }}</p>
						<div class="transaction-surface__confirm-actions">
							<NcButton
								variant="tertiary"
								:disabled="deletion.pending.value"
								data-test="cancel-delete-transaction"
								@click="cancelDelete"
							>
								{{ t('pettycash', 'common.cancel') }}
							</NcButton>
							<NcButton
								variant="error"
								:disabled="deletion.pending.value"
								:loading="deletion.pending.value"
								data-test="confirm-delete-transaction"
								@click="confirmDelete"
							>
								{{ t('pettycash', 'transactions.deleteDraftConfirm') }}
							</NcButton>
						</div>
					</div>

					<div class="transaction-surface__actions">
						<NcButton v-if="canEdit" variant="secondary" data-test="edit-transaction-btn" @click="goToEdit">
							<NcIconSvgWrapper :path="mdiPencil" :size="16" class="mr-2" />
							{{ t('pettycash', 'common.edit') }}
						</NcButton>
						<NcButton v-if="canDelete" variant="tertiary" data-test="delete-transaction-btn" @click="askDelete">
							<NcIconSvgWrapper :path="mdiDelete" :size="16" class="mr-2" />
							{{ t('pettycash', 'transactions.deleteDraft') }}
						</NcButton>
						<NcButton v-if="canCorrect" variant="secondary" data-test="create-correction-btn" @click="goToCorrect">
							<NcIconSvgWrapper :path="mdiUndoVariant" :size="16" class="mr-2" />
							{{ t('pettycash', 'transactions.createCorrection') }}
						</NcButton>
					</div>

					<section class="transaction-surface__card" data-test="transaction-surface-active-revision">
						<div class="transaction-surface__status-row">
							<StatusBadge :status="transaction.status" data-test="transaction-surface-status" />
							<NcChip no-close data-test="transaction-surface-stage">
								{{ t('pettycash', stageKey(transaction.stage)) }}
							</NcChip>
							<span class="transaction-surface__version" data-test="transaction-surface-version">
								{{ t('pettycash', 'transactions.version') }} {{ transaction.version }}
							</span>
						</div>
						<dl class="transaction-surface__fields">
							<div>
								<dt>{{ t('pettycash', 'transactions.expenseDate') }}</dt>
								<dd>{{ formatSheetDate(transaction.activeRevision.expenseDate) }}</dd>
							</div>
							<div>
								<dt>{{ t('pettycash', 'transactions.amount') }}</dt>
								<dd>{{ formatMoney(transaction.activeRevision.amount) }}</dd>
							</div>
							<div>
								<dt>{{ t('pettycash', 'transactions.costCenter') }}</dt>
								<dd>{{ transaction.activeRevision.costCenter.name }}</dd>
							</div>
							<div>
								<dt>{{ t('pettycash', 'transactions.expenseType') }}</dt>
								<dd>{{ transaction.activeRevision.expenseType.name }}</dd>
							</div>
							<div>
								<dt>{{ t('pettycash', 'transactions.description') }}</dt>
								<dd>{{ transaction.activeRevision.description }}</dd>
							</div>
						</dl>
					</section>

					<section class="transaction-surface__card" data-test="transaction-surface-revisions">
						<h3 class="transaction-surface__section-title">{{ t('pettycash', 'transactions.revisionHistory') }}</h3>
						<div v-if="isNeverSubmitted" data-test="transaction-surface-working-draft">
							<strong>{{ t('pettycash', 'transactions.workingDraft') }}</strong>
							<p>{{ t('pettycash', 'transactions.workingDraftHint') }}</p>
						</div>
						<template v-else>
							<div v-if="workingCorrection" class="transaction-surface__working-correction" data-test="transaction-surface-working-correction">
								<strong>{{ t('pettycash', 'transactions.workingCorrection') }} — {{ workingCorrectionLabel }}</strong>
								<p>{{ t('pettycash', 'transactions.workingCorrectionHint') }}</p>
							</div>
							<ul class="transaction-surface__revisions">
								<li v-for="revision in officialRevisions" :key="revision.id" data-test="transaction-surface-revision">
									<span>{{ t('pettycash', 'transactions.revision') }} {{ revision.number }}</span>
									<span v-if="revision.active" class="transaction-surface__revision-current">{{ t('pettycash', 'common.active') }}</span>
									<time>{{ formatSheetDateTime(revision.createdAt) }}</time>
								</li>
							</ul>
						</template>
					</section>

					<section class="transaction-surface__card" data-test="transaction-surface-decisions">
						<h3 class="transaction-surface__section-title">{{ t('pettycash', 'transactions.decisions') }}</h3>
						<div v-if="transaction.decisions.length > 0" class="transaction-surface__decisions">
							<div v-for="decision in transaction.decisions" :key="decision.id" class="transaction-surface__decision" data-test="transaction-surface-decision">
								<span>{{ t('pettycash', stageKey(decision.stage)) }}</span>
								<time>{{ formatSheetDateTime(decision.decidedAt) }}</time>
								<span v-if="decision.explanation">{{ decision.explanation }}</span>
							</div>
						</div>
						<p v-else class="transaction-surface__muted" data-test="transaction-surface-no-decisions">
							{{ t('pettycash', 'transactions.noDecisions') }}
						</p>
					</section>

					<section class="transaction-surface__card" data-test="transaction-surface-evidence">
						<h3 class="transaction-surface__section-title">
							{{ t('pettycash', 'evidence.list') }} ({{ ordinaryEvidenceCount }})
						</h3>
						<EvidenceList
							:evidence="transaction.evidence"
							:is-draft="isDraft"
							:never-submitted="neverSubmittedEvidence"
							@preview="openPreview"
							@changed="loadData"
						/>
					</section>
				</template>
			</template>
		</section>

		<EvidencePreview
			:evidence="selectedEvidence"
			:open="previewOpen"
			@close="closePreview"
		/>
	</div>
</template>

<style scoped>
.transaction-surface {
	position: fixed;
	inset-block: var(--header-height, 50px) 0;
	inset-inline: 0;
	z-index: 1600;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 1rem;
	font-size: var(--font-size-floor);
}

.transaction-surface__scrim {
	position: absolute;
	inset: 0;
	background-color: var(--pettycash-scrim, color-mix(in srgb, black 55%, transparent));
}

.transaction-surface__dialog {
	position: relative;
	inline-size: min(100%, 62rem);
	max-block-size: 92vh;
	overflow: clip auto;
	padding: 1.5rem;
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: 0 8px 40px var(--color-box-shadow);
}

.transaction-surface__header {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: 1rem;
	margin-block-end: 1rem;
}

.transaction-surface__identity {
	display: flex;
	flex-direction: column;
	gap: 0.2rem;
	min-inline-size: 0;
}

.transaction-surface__title {
	margin: 0;
	font-size: var(--font-size-section);
}

.transaction-surface__id,
.transaction-surface__version,
.transaction-surface__muted {
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.transaction-surface__header-actions,
.transaction-surface__actions,
.transaction-surface__status-row,
.transaction-surface__confirm-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem;
}

.transaction-surface__actions {
	margin-block-end: 1rem;
}

.transaction-surface__card {
	padding: 1rem;
	margin-block-start: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.transaction-surface__section-title {
	margin: 0 0 0.75rem;
	font-size: var(--font-size-emphasis);
}

.transaction-surface__fields {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
	gap: 0.75rem;
	margin: 0.75rem 0 0;
}

.transaction-surface__fields dt {
	font-weight: 700;
}

.transaction-surface__fields dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.transaction-surface__state,
.transaction-surface__notice {
	margin-block: 1rem;
}

.transaction-surface__notice {
	padding: 0.75rem;
	border-radius: var(--border-radius);
	background-color: var(--color-background-dark);
}

.transaction-surface__confirm {
	padding: 0.75rem;
	margin-block-end: 1rem;
	border: 1px solid var(--color-error);
	border-radius: var(--border-radius-large);
}

.transaction-surface__confirm-title {
	margin: 0 0 0.5rem;
}

.transaction-surface__revisions {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 0.4rem;
}

.transaction-surface__revisions li {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	align-items: baseline;
}

.transaction-surface__revision-current {
	color: var(--color-primary-element);
	font-weight: 700;
}

.transaction-surface__working-correction {
	padding: 0.75rem;
	margin-block-end: 0.75rem;
	border-radius: var(--border-radius);
	background-color: var(--color-background-dark);
}

.transaction-surface__decisions {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.transaction-surface__decision {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
}

@media (width <= 640px) {
	.transaction-surface {
		padding: 0;
	}

	.transaction-surface__dialog {
		inline-size: 100%;
		block-size: 100%;
		max-block-size: 100%;
		border-radius: 0;
	}
}
</style>

<script setup lang="ts">
import { direction } from '@/utils/direction'
import { onMounted, computed, watch, ref, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTransactionStore } from '@/stores/transactions'
import { useSheetStore } from '@/stores/sheets'
import type { Evidence, TransactionStatus } from '@/types/api'
import { NcButton, NcChip, NcIconSvgWrapper, NcBreadcrumbs, NcSelect, NcDialog } from '@nextcloud/vue'
import { mdiPencil, mdiUndoVariant, mdiPlus, mdiLock } from '@mdi/js'
import EvidenceUploader from '@/components/evidence/EvidenceUploader.vue'
import EvidenceList from '@/components/evidence/EvidenceList.vue'
import EvidencePreview from '@/components/evidence/EvidencePreview.vue'
import { t } from '@nextcloud/l10n'
import { decisionKey, stageKey } from '@/l10n'
import { formatMoney } from '@/utils/money'
import { formatSheetDate, formatSheetDateTime } from '@/utils/date'
import { selectOptionKey, selectOptionValue } from '@/utils/selectOption'
import { restoreScrollOffset } from '@/utils/sheetListSession'

const route = useRoute()
const router = useRouter()
const transactionStore = useTransactionStore()
const sheetStore = useSheetStore()

const transactionId = computed(() => route.params.id as string)

const statusLabels: Record<TransactionStatus, string> = {
	DRAFT: 'status.draft',
	AWAITING_COST_CENTER_REVIEW: 'status.awaitingCostCenter',
	COST_CENTER_APPROVED: 'status.costCenterApproved',
	COST_CENTER_REJECTED: 'status.costCenterRejected',
	AWAITING_SENIOR_REVIEW: 'status.awaitingSenior',
	SENIOR_APPROVED: 'status.seniorApproved',
	SENIOR_REJECTED: 'status.seniorRejected',
	AWAITING_FINANCE_REVIEW: 'status.awaitingFinance',
	AWAITING_PURCHASER_CORRECTION: 'status.awaitingCorrection',
	COMPLETED_APPROVED: 'status.completedApproved',
	COMPLETED_REJECTED: 'status.completedRejected'
}

const status = computed(() => transactionStore.currentTransaction?.status)
const isDraft = computed(() => status.value === 'DRAFT')
const isSubmitted = computed(() => status.value !== undefined && status.value !== 'DRAFT')
const isReturned = computed(() => status.value === 'AWAITING_PURCHASER_CORRECTION')

/**
 * T067: every action comes from the detail payload's `allowedActions`, never from a local status
 * guess. The server computes `canEdit`/`canDelete` for the owner of a DRAFT and `canCorrect` for the
 * owner of a returned transaction; a missing or false capability must offer nothing.
 *
 * Correct is additionally tied to the returned status here because C-15 states both conditions. It
 * deliberately does **not** require the sheet to be OPEN: a returned transaction lives in a
 * CORRECTION sheet, and requiring OPEN would hide the one action that state exists to offer.
 */
const canEdit = computed(() => transactionStore.currentTransaction?.allowedActions?.canEdit === true)
const canCreateCorrection = computed(() =>
	transactionStore.currentTransaction?.allowedActions?.canCorrect === true && isReturned.value)

/**
 * Whether to state that the transaction is read-only.
 *
 * A *returned* transaction is submitted, so `isSubmitted` is true for it — but FR-026 carves out
 * exactly one edit after submission, the correction of a returned transaction, and the view offers
 * that correction through a button. Showing the blanket read-only badge next to it tells the
 * purchaser the opposite of what the button is about to let them do, so the badge is withheld for
 * this one state and the correction button carries the meaning on its own.
 */
const showReadOnly = computed(() => isSubmitted.value && !isReturned.value)

/**
 * T069: a correction that has not been resubmitted is a *working* revision, not official history.
 *
 * The API projects `Revision.active` from `transactions.active_revision_id`, so once a correction
 * draft exists it is the active revision and the submitted revision it replaces is no longer active.
 * There is no draft flag on the revision payload, so the presence of an earlier revision is what
 * distinguishes "working correction" from "returned, correction not started yet": a correction adds
 * revision N+1, and a returned transaction that still has only its submitted revision has no
 * working draft to label.
 */
const workingCorrection = computed(() => {
	const active = transactionStore.currentTransaction?.activeRevision
	if (!isReturned.value || !active || transactionStore.revisions.length < 2) return null
	return transactionStore.revisions.find((revision) => revision.id === active.id) ?? null
})

/**
 * The revisions that belong to the official record.
 *
 * A never-submitted DRAFT has no official revisions: its rows are working state (T069). For a
 * returned transaction the working correction is presented separately and excluded here. Every
 * other state shows the full immutable chain.
 */
const officialRevisions = computed(() => {
	if (isDraft.value) return []
	if (workingCorrection.value) {
		return transactionStore.revisions.filter((revision) => revision.id !== workingCorrection.value?.id)
	}
	return transactionStore.revisions
})

/**
 * The superseded official revisions, which FR-050 keeps part of the record and FR-053 makes
 * append-only.
 */
const priorRevisions = computed(() => officialRevisions.value.filter((rev) => !rev.active))

/**
 * The evidence rows.
 *
 * This view used to render its own list inline, with `downloadEvidence()` and `removeEvidence()` as
 * empty `// TODO: Implement …` bodies bound to the buttons — so every purchaser with a draft receipt
 * was shown a download and a delete control that did nothing, with no error and no state change. The
 * working implementation already existed at `src/components/evidence/EvidenceList.vue` and was
 * imported by **no view**; only `PurchaserFlow.spec.ts` imported it, which is how the tested surface
 * and the shipped surface came to be two different files (T360).
 *
 * **The component is the better of the two, and adopting it fixes three requirement clauses the inline
 * markup got wrong** — which is the argument for deleting the inline copy rather than wiring the two
 * handlers to the service:
 *
 * - **FR-060.** The inline buttons were both `v-if="ev.active && !isSubmitted"`, so a reviewer
 *   looking at a *submitted* transaction could not download its evidence at all. FR-060 requires an
 *   authorized viewer to be able to preview and download; the component offers download whenever the
 *   item is active.
 * - **FR-058.** Removal stays the purchaser's and stays pre-submission, which the component keeps as
 *   `v-if="ev.active && props.isDraft"`.
 * - **FR-059.** The component renders `deactivation-info` — when the file was withdrawn, or that the
 *   time is unknown. The inline row said only "inactive", which is not the same information.
 *
 * It also carries a confirm before removal, a `deleting` guard so a double press cannot issue two
 * DELETEs, an `role="alert"` error region, and `aria-label`s naming the file each button acts on.
 */
const evidenceItems = computed(() => transactionStore.currentTransaction?.evidence ?? [])

/**
 * FR-005-047 / C-21: the sheet status is the authoritative submission boundary, and this direct
 * detail route is also an ordinary purchaser surface. Reuse the sheet already loaded by the list or
 * form when it is the same sheet; a direct load fetches only that sheet (see `loadData`). A missing
 * sheet status keeps the historical presentation rather than guessing.
 */
const neverSubmittedEvidence = computed(() => isDraft.value
	&& sheetStore.currentSheet?.id === transactionStore.currentTransaction?.sheetId
	&& sheetStore.currentSheet?.status === 'OPEN')

/** C-21: the heading counts the ordinary list, not lifecycle rows the list no longer shows. */
const ordinaryEvidenceCount = computed(() => neverSubmittedEvidence.value
	? evidenceItems.value.filter((item) => item.active).length
	: evidenceItems.value.length)

function goToEdit() { router.push({ name: 'transaction-edit', params: { id: transactionId.value } }) }

/** US6 history (T098): the formal audit timeline lives on its own view. */
function goToAudit() { router.push({ name: 'transaction-audit', params: { transactionId: transactionId.value } }) }

/**
 * FR-026's one carve-out after submission: a returned transaction stays editable.
 *
 * This pushed a route named `transaction-correction-create`, which **no route in
 * `src/router/index.ts` declares** — `vue-router` throws `MATCHER_NOT_FOUND` synchronously from
 * `router.push`, so pressing the button aborted the handler and nothing was caught (T359). Every
 * test asserting this button asserted only that it *exists*; none pressed it, and
 * `PurchaserFlow.spec.ts` re-declared the application's ten routes in its own test router, so a
 * missing name could not have been caught even if one had pushed it.
 *
 * **`transaction-edit` is the correct destination rather than a new route.** `TransactionFormView`
 * already treats `AWAITING_PURCHASER_CORRECTION` as editable — the `editable` computed at `:71`
 * names that status explicitly, and this button only renders when it holds — and its `persist()`
 * calls `updateExistingTransaction`, whose `PATCH` the server accepts for exactly this state:
 * `TransactionService.php:245` admits the update when `$isCorrection`, which it derives from
 * `TransactionStatus::AwaitingPurchaserCorrection`. That PATCH creates a new revision, so FR-048 is
 * satisfied on this path too. Registering a second route would have produced two URLs for one
 * behaviour with nothing to tell them apart.
 */
function goToCreateCorrection() { router.push({ name: 'transaction-edit', params: { id: transactionId.value } }) }

const selectedRevisionId = ref('')
const showEvidenceUploader = ref(false)
const selectedEvidence = ref<Evidence | null>(null)
const previewOpen = computed(() => selectedEvidence.value !== null)
let previewOpener: HTMLElement | null = null

const revisionOptions = computed(() => {
	return officialRevisions.value.map((rev) => ({
		label: t('pettycash', 'transactions.revision') + ' ' + rev.number + (rev.active ? ' (' + t('pettycash', 'common.active') + ')' : ''),
		value: rev.id
	}))
})

/**
 * The revision on display.
 *
 * FR-050 makes the *active* revision the one that determines the current information, so it is what
 * the panel opens on. Defaulting to `revisions[0]` opened on the oldest revision instead, which showed
 * a corrected transaction's superseded amount as if it were the current one.
 *
 * Only official revisions are selectable (T069), so a working draft cannot be mistaken for one.
 */
const selectedRevision = computed(() => {
	return officialRevisions.value.find((r) => r.id === selectedRevisionId.value)
		|| officialRevisions.value.find((r) => r.active)
		|| officialRevisions.value[0]
})

const selectedRevisionIndex = computed(() => {
	return officialRevisions.value.findIndex((r) => r.id === selectedRevision.value?.id)
})

/** Open the hosted preview and remember the control focus returns to (T068). */
function openPreview(evidence: Evidence) {
	previewOpener = document.activeElement instanceof HTMLElement ? document.activeElement : null
	selectedEvidence.value = evidence
}

function closePreview() {
	selectedEvidence.value = null
	void nextTick(() => {
		if (previewOpener?.isConnected) previewOpener.focus()
		previewOpener = null
	})
}

async function loadData() {
	// Each store records a failure in its own `error` ref, which this view renders in its alert.
	// `loadData` is fired from `onMounted`, from a `watch`, and from the evidence-upload handler,
	// none of which has a caller to react to a rejection, so it is absorbed here rather than
	// surfacing as an unhandled promise rejection in the user's console.
	await transactionStore.loadTransaction(transactionId.value).catch(() => undefined)
	// C-21: the evidence-lifecycle rule needs the owning sheet's state, and this route can be
	// opened directly. Reuse the sheet a list/form already loaded; otherwise read that one sheet.
	const sheetId = transactionStore.currentTransaction?.sheetId
	if (sheetId && sheetStore.currentSheet?.id !== sheetId) {
		await sheetStore.loadSheet(sheetId).catch(() => undefined)
	}
	await transactionStore.loadRevisions(transactionId.value).catch(() => undefined)
	if (!selectedRevisionId.value || !officialRevisions.value.some((r) => r.id === selectedRevisionId.value)) {
		const active = officialRevisions.value.find((r) => r.active) ?? officialRevisions.value[0]
		selectedRevisionId.value = active?.id ?? ''
	}
	// The audit fetch that used to stand here was removed by T343, with the sheet detail's audit card.
	// It resolved a page and discarded it: `loadAudit()` returns the page and stores nothing, and this
	// view bound no part of it, so the request bought a network round trip and a rendered nothing — which
	// is the same "header, no data" failure the sheet card was. The audit endpoint exists and is
	// unaffected; Phase 50 owns binding it once the page's shape and pagination are settled.
}

async function handleEvidenceUploaded() {
	showEvidenceUploader.value = false
	await loadData()
}

onMounted(() => {
	restoreScrollOffset(0)
	loadData()
})
watch(transactionId, () => { loadData() })
</script>

<template>
	<div class="transaction-detail-view" :dir="direction" data-test="transaction-detail-view">
		<div class="mb-4">
			<NcBreadcrumbs
				:items="[
					{ text: t('pettycash', 'sheets.title'), href: '/sheets' },
					{ text: t('pettycash', 'transactions.list') },
					{ text: transactionStore.currentTransaction?.id ?? '' }
				]"
			/>
		</div>

		<div class="header-row">
			<NcChip no-close


				data-test="transaction-status-badge"
			>
				{{ t('pettycash', statusLabels[transactionStore.currentTransaction?.status ?? 'DRAFT']) }}
			</NcChip>
			<NcChip no-close
				v-if="showReadOnly"
				variant="secondary"


				data-test="read-only-indicator"
			>
				<NcIconSvgWrapper :path="mdiLock" :size="16" class="mr-2" />
				{{ t('pettycash', 'common.readOnly') }}
			</NcChip>
		</div>

		<section class="detail-card" data-test="transaction-header">
			<!--
				This row is a child of the card, not a `#header` slot: `@nextcloud/vue` 9.12.0's
				`NcNoteCard` has no `header` slot, so a `#header` template is dropped silently and takes
				Edit and Create correction with it. `SheetDetailView.vue` records the full reasoning.
			-->
			<div class="card-header-row">
				<h2 class="detail-card__title mb-0">{{ t('pettycash', 'transactions.detail') }}</h2>
				<div class="d-flex gap-2">
					<NcButton v-if="canEdit" variant="secondary" data-test="edit-transaction-btn" @click="goToEdit">
						<NcIconSvgWrapper :path="mdiPencil" :size="16" class="mr-2" />
						{{ t('pettycash', 'common.edit') }}
					</NcButton>
					<NcButton v-if="canCreateCorrection" variant="secondary" data-test="create-correction-btn" @click="goToCreateCorrection">
						<NcIconSvgWrapper :path="mdiUndoVariant" :size="16" class="mr-2" />
						{{ t('pettycash', 'transactions.createCorrection') }}
					</NcButton>
					<NcButton variant="tertiary" data-test="view-audit-btn" @click="goToAudit">
						{{ t('pettycash', 'transactions.viewHistory') }}
					</NcButton>
				</div>
			</div>

			<div v-if="transactionStore.currentTransaction" class="card-details">
				<div class="detail-item">
					<strong>{{ t('pettycash', 'transactions.expenseDate') }}</strong>
					<div>{{ formatSheetDate(transactionStore.currentTransaction.activeRevision.expenseDate) }}</div>
				</div>
				<div class="detail-item">
					<strong>{{ t('pettycash', 'transactions.amount') }}</strong>
					<div>{{ formatMoney(transactionStore.currentTransaction.activeRevision.amount) }}</div>
				</div>
				<div class="detail-item">
					<strong>{{ t('pettycash', 'transactions.costCenter') }}</strong>
					<div>{{ transactionStore.currentTransaction.activeRevision.costCenter.name }}</div>
				</div>
				<div class="detail-item">
					<strong>{{ t('pettycash', 'transactions.expenseType') }}</strong>
					<div>{{ transactionStore.currentTransaction.activeRevision.expenseType.name }}</div>
				</div>
				<div class="detail-item">
					<strong>{{ t('pettycash', 'transactions.description') }}</strong>
					<div>{{ transactionStore.currentTransaction.activeRevision.description }}</div>
				</div>
				<div class="detail-item">
					<strong>{{ t('pettycash', 'transactions.stage') }}</strong>
					<div>
						<NcChip no-close>
							{{ t('pettycash', stageKey(transactionStore.currentTransaction.stage)) }}
						</NcChip>
					</div>
				</div>
				<div class="detail-item">
					<strong>{{ t('pettycash', 'transactions.status') }}</strong>
					<div>
						<NcChip no-close>
							{{ t('pettycash', statusLabels[transactionStore.currentTransaction.status]) }}
						</NcChip>
					</div>
				</div>
				<div class="detail-item">
					<strong>{{ t('pettycash', 'transactions.revision') }}</strong>
					<div>#{{ transactionStore.currentTransaction.activeRevision.number }}</div>
				</div>
			</div>
		</section>

		<section class="detail-card" data-test="revision-history">
			<div class="card-header-row">
				<h3 class="detail-card__title detail-card__title--inline">{{ t('pettycash', 'transactions.revisionHistory') }}</h3>
				<NcSelect
					v-if="!isDraft"
					v-model="selectedRevisionId"
					:options="revisionOptions"
					:reduce="selectOptionValue"
					:get-option-key="selectOptionKey"
					:clearable="false"
					:aria-label-combobox="t('pettycash', 'transactions.selectRevision')"
					data-test="revision-selector"
					style="max-width: 300px"
				/>
			</div>

			<!--
				T069. A never-submitted DRAFT has working revisions, not history. The rows stay in
				storage; this read-side projection simply does not present them as an official chain.
			-->
			<div v-if="isDraft" class="working-draft" data-test="working-draft">
				<strong>{{ t('pettycash', 'transactions.workingDraft') }}</strong>
				<p>{{ t('pettycash', 'transactions.workingDraftHint') }}</p>
			</div>

			<template v-else>
				<!--
					An un-resubmitted correction revision is working state. It is named here, never
					numbered into the official chain and never shown as a prior official revision.
				-->
				<div v-if="workingCorrection" class="working-correction" data-test="working-correction">
					<strong>{{ t('pettycash', 'transactions.workingCorrection') }} — {{ t('pettycash', 'transactions.revision') }} {{ workingCorrection.number }}</strong>
					<p>{{ t('pettycash', 'transactions.workingCorrectionHint') }}</p>
				</div>

				<div class="revision-tabs" data-test="revision-tabs">
					<div class="tab-list" role="tablist">
						<button
							v-for="(rev, i) in officialRevisions"
							:key="rev.id"
							:class="['tab-button', { active: selectedRevisionIndex === i }]"
							role="tab"
							:aria-selected="selectedRevisionIndex === i"
							data-test="revision-tab"
							@click="selectedRevisionId = rev.id"
						>
							{{ t('pettycash', 'transactions.revision') }} {{ rev.number }}
						</button>
					</div>

					<div v-if="selectedRevision" class="tab-panel" role="tabpanel">
						<div class="card-details">
							<div class="detail-item">
								<strong>{{ t('pettycash', 'transactions.expenseDate') }}</strong>
								<div>{{ formatSheetDate(selectedRevision.expenseDate) }}</div>
							</div>
							<div class="detail-item">
								<strong>{{ t('pettycash', 'transactions.amount') }}</strong>
								<div>{{ formatMoney(selectedRevision.amount) }}</div>
							</div>
							<div class="detail-item">
								<strong>{{ t('pettycash', 'transactions.costCenter') }}</strong>
								<div>{{ selectedRevision.costCenter.name }}</div>
							</div>
							<div class="detail-item">
								<strong>{{ t('pettycash', 'transactions.expenseType') }}</strong>
								<div>{{ selectedRevision.expenseType.name }}</div>
							</div>
							<div class="detail-item">
								<strong>{{ t('pettycash', 'transactions.description') }}</strong>
								<div>{{ selectedRevision.description }}</div>
							</div>
							<div class="detail-item">
								<strong>{{ t('pettycash', 'transactions.createdAt') }}</strong>
								<div>{{ formatSheetDateTime(selectedRevision.createdAt) }}</div>
							</div>
							<div class="detail-item">
								<strong>{{ t('pettycash', 'transactions.origin') }}</strong>
								<div>{{ selectedRevision.number === 1 ? t('pettycash', 'transactions.original') : t('pettycash', 'transactions.revision') + ' ' + selectedRevision.number }}</div>
							</div>
						</div>
					</div>
				</div>
			</template>
		</section>

		<!-- FR-050 keeps every superseded revision part of the record and FR-053 makes that record
		     append-only, so this region is deliberately inert: no control in it can change a past
		     revision. The revision selector above lets a reader page through history; this states that
		     what they are reading is retained history rather than a previous state of an editable row. -->
		<section v-if="priorRevisions.length > 0" class="detail-card" data-test="prior-revisions">
			<h3 class="detail-card__title">{{ t('pettycash', 'transactions.priorRevisions') }}</h3>
			<p class="prior-revisions__note">
				{{ t('pettycash', 'transactions.priorRevisionsRetained') }}
			</p>
			<ul class="prior-revisions-list">
				<li v-for="rev in priorRevisions" :key="rev.id" class="prior-revision" data-test="prior-revision">
					<div class="prior-revision__header">
						<strong>{{ t('pettycash', 'transactions.revision') }} {{ rev.number }}</strong>
						<small>{{ formatSheetDateTime(rev.createdAt) }}</small>
					</div>
					<dl class="prior-revision__fields">
						<dt>{{ t('pettycash', 'transactions.expenseDate') }}</dt>
						<dd>{{ formatSheetDate(rev.expenseDate) }}</dd>
						<dt>{{ t('pettycash', 'transactions.amount') }}</dt>
						<dd>{{ formatMoney(rev.amount) }}</dd>
						<dt>{{ t('pettycash', 'transactions.costCenter') }}</dt>
						<dd>{{ rev.costCenter.name }}</dd>
						<dt>{{ t('pettycash', 'transactions.expenseType') }}</dt>
						<dd>{{ rev.expenseType.name }}</dd>
						<template v-if="rev.description">
							<dt>{{ t('pettycash', 'transactions.description') }}</dt>
							<dd>{{ rev.description }}</dd>
						</template>
					</dl>
				</li>
			</ul>
		</section>

		<section class="detail-card" data-test="decision-list">
			<h3 class="detail-card__title">{{ t('pettycash', 'transactions.decisions') }}</h3>
			<div v-if="transactionStore.currentTransaction?.decisions?.length" class="decision-list">
				<div v-for="decision in transactionStore.currentTransaction.decisions" :key="decision.id" class="decision-item" data-test="decision-item">
					<div class="decision-header">
						<NcChip no-close>
							{{ t('pettycash', decisionKey(decision.decision)) }}
						</NcChip>
						<span>{{ t('pettycash', stageKey(decision.stage)) }}</span>
						<small>{{ formatSheetDateTime(decision.decidedAt) }}</small>
					</div>
					<div class="decision-meta">
						<!-- FR-052: the actor is a snapshot, not a live user lookup. The display name and
						     role are the ones the decision was made under, and the identifier is what ties
						     it to a person at all — the view previously showed the name and the role and
						     dropped the identifier, so a decision could not be traced back. -->
						<span class="actor-snapshot" data-test="actor-snapshot">
							<span data-test="actor-display-name">{{ decision.actor.displayName }}</span>
							<span data-test="actor-role">({{ decision.actor.role }})</span>
							<small class="actor-snapshot__id" data-test="actor-user-id">{{ decision.actor.userId }}</small>
						</span>
						<span v-if="decision.explanation" data-test="decision-explanation">{{ decision.explanation }}</span>
					</div>
				</div>
			</div>
			<div v-else class="text-center py-4" data-test="no-decisions">{{ t('pettycash', 'transactions.noDecisions') }}</div>
		</section>

		<section class="detail-card" data-test="evidence-list">
			<div class="card-header-row">
				<h3 class="detail-card__title detail-card__title--inline">{{ t('pettycash', 'evidence.list') }} ({{ ordinaryEvidenceCount }})</h3>
				<NcButton v-if="!isSubmitted" variant="primary" data-test="upload-evidence-btn" @click="showEvidenceUploader = true">
					<NcIconSvgWrapper :path="mdiPlus" :size="16" class="mr-2" />
					{{ t('pettycash', 'evidence.upload') }}
				</NcButton>
			</div>

			<!--
				T360. `EvidenceList` rather than an inline copy. `isDraft` is the *inverse* of
				`isSubmitted` because the two answer opposite questions: the component uses it to decide
				whether **removal** is offered (FR-058, pre-submission only), while `isSubmitted` is also
				what gates the upload button above. Passing the raw `isSubmitted` here would have been
				inverted and would have offered removal on a submitted transaction.

				Download deliberately ignores `isDraft` — see the `evidenceItems` docblock on FR-060.
			-->
			<EvidenceList
				:evidence="evidenceItems"
				:is-draft="!isSubmitted"
				:never-submitted="neverSubmittedEvidence"
				@preview="openPreview"
				@changed="loadData"
			/>
		</section>

		<!-- T068: one hosted preview layer, opened from any row. Keeping it here, beside the list
		     rather than inside every row, is what makes "at most one preview dialog" structural. -->
		<EvidencePreview
			:evidence="selectedEvidence"
			:open="previewOpen"
			@close="closePreview" />

		<NcDialog
			name="evidence-upload"
			size="normal"
			:open="showEvidenceUploader"
			@update:open="showEvidenceUploader = $event"
		>
			<EvidenceUploader
				:transaction-id="transactionId"
				:is-draft="!isSubmitted"
				@uploaded="handleEvidenceUploaded"
			/>
		</NcDialog>

		<div v-if="transactionStore.error" class="alert alert--error" role="alert">
			<span>{{ transactionStore.error }}</span>
			<button type="button" :aria-label="t('pettycash', 'common.dismiss')" @click="transactionStore.clearError">×</button>
		</div>
	</div>
</template>

<style scoped>
.transaction-detail-view {
	display: flex;
	flex-direction: column;
	inline-size: 100%;
	max-inline-size: 72rem;
	margin-inline: auto;
	gap: 1rem;
}

.detail-card {
	padding: 1.25rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
}

.detail-card__title {
	margin: 0 0 1rem;
	font-size: var(--font-size-section);
	font-weight: 700;
}

.detail-card__title--inline {
	margin: 0;
}

.header-row {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	align-items: center;
}

/*
 * NcChip's text slot is block-level; the lock icon renders as a block of its own and pushes the
 * label onto a second line, outside the chip's 24px pill. Keep the manual icon inline with the
 * label and pin its box to the icon size.
 */
.header-row :deep(.nc-chip__text) {
	display: inline-flex;
	align-items: center;
	gap: 0;
	block-size: 1.5rem;
	line-height: 1.5rem;
}

.header-row :deep(.nc-chip__text .icon-vue) {
	display: inline-flex;
	flex: none;
	inline-size: 1rem;
	block-size: 1rem;
	min-inline-size: 1rem;
	min-block-size: 1rem;
	max-inline-size: 1rem;
	max-block-size: 1rem;
}

.header-row :deep(.nc-chip__text .icon-vue svg) {
	inline-size: 1rem;
	block-size: 1rem;
}

.card-header-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.card-details {
	display: grid;
	grid-template-columns: minmax(0, 1fr);
	gap: 0.75rem 1rem;
}

@media (width >= 600px) {
	.card-details {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}
}

.detail-item {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.revision-tabs {
	margin-bottom: 1rem;
}

.working-draft,
.working-correction {
	padding: 0.75rem;
	margin-block-start: 0.75rem;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.working-draft p,
.working-correction p {
	margin: 0.25rem 0 0;
	color: var(--color-text-maxcontrast);
}

.tab-list {
	display: flex;
	gap: 0.5rem;
	border-bottom: 1px solid var(--color-border);
	padding-bottom: 0.5rem;
}

.tab-button {
	padding: 0.5rem 1rem;
	background: none;
	border: none;
	border-radius: 4px 4px 0 0;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.tab-button.active {
	color: var(--color-primary-element);
	font-weight: 600;
	border-bottom: 2px solid var(--color-primary-element);
}

.tab-panel {
	padding: 1rem 0;
}

.decision-list {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.decision-item {
	padding: 0.75rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.decision-header {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 0.25rem;
	flex-wrap: wrap;
}

.decision-meta {
	display: flex;
	align-items: center;
	gap: 1rem;
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

.actor-snapshot {
	display: inline-flex;
	align-items: baseline;
	gap: 0.25rem;
}

.actor-snapshot__id {
	color: var(--color-text-maxcontrast);
}

.prior-revisions__note {
	margin: 0 0 0.75rem;
	color: var(--color-text-maxcontrast);
}

.prior-revisions-list {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.prior-revision {
	padding: 0.75rem;
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.prior-revision__header {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 0.5rem;
	margin-bottom: 0.25rem;
}

.prior-revision__fields {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 0.125rem 0.75rem;
	margin: 0;
}

.prior-revision__fields dt {
	color: var(--color-text-maxcontrast);
}

.prior-revision__fields dd {
	margin: 0;
}

/*
 * The `.evidence-*` rules that styled this view's own inline evidence rows were removed with the
 * markup (T360). They are **not** replaced: `EvidenceList.vue` carries its own scoped presentation,
 * and keeping a second copy here would be a second definition of how a row looks that nothing renders.
 * `.evidence-list` itself also went — it is the same class name the component's root element uses, and
 * `scoped` styles would not have reached it anyway.
 */

.alert {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.75rem 1rem;
	border-radius: 4px;
}

.alert--error {
	background-color: var(--color-background-hover);
	color: var(--color-error);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
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

	.d-flex {
		display: flex;
	}

	.gap-2 {
		gap: 0.5rem;
	}

	.text-center {
		text-align: center;
	}

	.py-4 {
		padding-top: 1rem; padding-bottom: 1rem;
	}

	.justify-end {
		justify-content: flex-end;
	}

	.align-center {
		align-items: center;
	}

	.justify-space-between {
		justify-content: space-between;
	}
</style>
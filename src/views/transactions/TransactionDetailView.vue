<script setup lang="ts">
import { onMounted, computed, watch, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTransactionStore } from '@/stores/transactions'
import type { TransactionStatus } from '@/types/api'
import { NcButton, NcChip, NcIconSvgWrapper, NcNoteCard, NcBreadcrumbs, NcSelect, NcDialog } from '@nextcloud/vue'
import { mdiPencil, mdiUndoVariant, mdiDownload, mdiDelete, mdiFileOutline, mdiPlus, mdiLock } from '@mdi/js'
import EvidenceUploader from '@/components/evidence/EvidenceUploader.vue'
import { t } from '@nextcloud/l10n'
import { formatMoney } from '@/utils/money'

const route = useRoute()
const router = useRouter()
const transactionStore = useTransactionStore()

const transactionId = computed(() => route.params.id as string)

const statusLabels: Record<TransactionStatus, string> = {
	DRAFT: 'pettycash.status.draft',
	AWAITING_COST_CENTER_REVIEW: 'pettycash.status.awaitingCostCenter',
	COST_CENTER_APPROVED: 'pettycash.status.costCenterApproved',
	COST_CENTER_REJECTED: 'pettycash.status.costCenterRejected',
	AWAITING_SENIOR_REVIEW: 'pettycash.status.awaitingSenior',
	SENIOR_APPROVED: 'pettycash.status.seniorApproved',
	SENIOR_REJECTED: 'pettycash.status.seniorRejected',
	AWAITING_FINANCE_REVIEW: 'pettycash.status.awaitingFinance',
	AWAITING_PURCHASER_CORRECTION: 'pettycash.status.awaitingCorrection',
	COMPLETED_APPROVED: 'pettycash.status.completedApproved',
	COMPLETED_REJECTED: 'pettycash.status.completedRejected'
}

const status = computed(() => transactionStore.currentTransaction?.status)
const isSubmitted = computed(() => status.value !== undefined && status.value !== 'DRAFT')
const isReturned = computed(() => status.value === 'AWAITING_PURCHASER_CORRECTION')
const canCreateCorrection = computed(() => isReturned.value)
const canEdit = computed(() => status.value === undefined || status.value === 'DRAFT')

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
 * The superseded revisions, which FR-050 keeps part of the record and FR-053 makes append-only.
 */
const priorRevisions = computed(() => transactionStore.revisions.filter((rev) => !rev.active))

function formatFileSize(bytes: number) {
	if (bytes < 1024) return bytes + ' B'
	if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
	return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

function goToEdit() { router.push({ name: 'transaction-edit', params: { id: transactionId.value } }) }
function goToCreateCorrection() { router.push({ name: 'transaction-correction-create', params: { id: transactionId.value } }) }

const selectedRevisionId = ref('')
const showEvidenceUploader = ref(false)

const revisionOptions = computed(() => {
	return transactionStore.revisions.map((rev: any) => ({
		title: t('pettycash', 'transactions.revision') + ' ' + rev.number + (rev.active ? ' (' + t('pettycash', 'common.active') + ')' : ''),
		value: rev.id
	}))
})

/**
 * The revision on display.
 *
 * FR-050 makes the *active* revision the one that determines the current information, so it is what
 * the panel opens on. Defaulting to `revisions[0]` opened on the oldest revision instead, which showed
 * a corrected transaction's superseded amount as if it were the current one.
 */
const selectedRevision = computed(() => {
	return transactionStore.revisions.find((r: any) => r.id === selectedRevisionId.value)
		|| transactionStore.revisions.find((r: any) => r.active)
		|| transactionStore.revisions[0]
})

const selectedRevisionIndex = computed(() => {
	return transactionStore.revisions.findIndex((r: any) => r.id === selectedRevision.value?.id)
})

async function loadData() {
	// Each store records a failure in its own `error` ref, which this view renders in its alert.
	// `loadData` is fired from `onMounted`, from a `watch`, and from the evidence-upload handler,
	// none of which has a caller to react to a rejection, so it is absorbed here rather than
	// surfacing as an unhandled promise rejection in the user's console.
	await transactionStore.loadTransaction(transactionId.value).catch(() => undefined)
	await transactionStore.loadRevisions(transactionId.value).catch(() => undefined)
	await transactionStore.loadAudit(transactionId.value).catch(() => undefined)
}

async function handleEvidenceUploaded() {
	showEvidenceUploader.value = false
	await loadData()
}

async function downloadEvidence(_evidenceId: string) {
	// TODO: Implement download
}

async function removeEvidence(_evidenceId: string) {
	// TODO: Implement remove
}

onMounted(() => { loadData() })
watch(transactionId, () => { loadData() })
</script>

<template>
	<div class="transaction-detail-view" dir="rtl" data-test="transaction-detail-view">
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
			<NcChip


				data-test="transaction-status-badge"
			>
				{{ t('pettycash', statusLabels[transactionStore.currentTransaction?.status ?? 'DRAFT']) }}
			</NcChip>
			<NcChip
				v-if="showReadOnly"
				variant="secondary"


				data-test="read-only-indicator"
			>
				<NcIconSvgWrapper :path="mdiLock" :size="16" class="mr-2" />
				{{ t('pettycash', 'common.readOnly') }}
			</NcChip>
		</div>

		<NcNoteCard data-test="transaction-header">
			<template #header>
				<div class="card-header-row">
					<h2 class="mb-0">{{ t('pettycash', 'transactions.detail') }}</h2>
					<div class="d-flex gap-2">
						<NcButton v-if="canEdit" variant="secondary" @click="goToEdit" data-test="edit-transaction-btn">
							<NcIconSvgWrapper :path="mdiPencil" :size="16" class="mr-2" />
							{{ t('pettycash', 'common.edit') }}
						</NcButton>
						<NcButton v-if="canCreateCorrection" variant="secondary" @click="goToCreateCorrection" data-test="create-correction-btn">
							<NcIconSvgWrapper :path="mdiUndoVariant" :size="16" class="mr-2" />
							{{ t('pettycash', 'transactions.createCorrection') }}
						</NcButton>
					</div>
				</div>
			</template>

			<div class="card-details" v-if="transactionStore.currentTransaction">
				<div class="detail-item">
					<strong>{{ t('pettycash', 'transactions.expenseDate') }}</strong>
					<div>{{ new Date(transactionStore.currentTransaction.activeRevision.expenseDate).toLocaleDateString('fa-IR') }}</div>
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
						<NcChip>
							{{ t('pettycash', 'stage.' + transactionStore.currentTransaction.stage.toLowerCase()) }}
						</NcChip>
					</div>
				</div>
				<div class="detail-item">
					<strong>{{ t('pettycash', 'transactions.status') }}</strong>
					<div>
						<NcChip>
							{{ t('pettycash', statusLabels[transactionStore.currentTransaction.status]) }}
						</NcChip>
					</div>
				</div>
				<div class="detail-item">
					<strong>{{ t('pettycash', 'transactions.revision') }}</strong>
					<div>#{{ transactionStore.currentTransaction.activeRevision.number }}</div>
				</div>
			</div>
		</NcNoteCard>

		<NcNoteCard data-test="revision-history">
			<template #header>
				<div class="card-header-row">
					<span>{{ t('pettycash', 'transactions.revisionHistory') }}</span>
					<NcSelect
						v-model="selectedRevisionId"
						:options="revisionOptions"
						:label="t('pettycash', 'transactions.selectRevision')"
						data-test="revision-selector"
						style="max-width: 300px"
					/>
				</div>
			</template>

			<div class="revision-tabs" data-test="revision-tabs">
				<div class="tab-list" role="tablist">
					<button
						v-for="(rev, i) in transactionStore.revisions"
						:key="rev.id"
						:class="['tab-button', { active: selectedRevisionIndex === i }]"
						role="tab"
						:aria-selected="selectedRevisionIndex === i"
						@click="selectedRevisionId = rev.id"
						data-test="revision-tab"
					>
						{{ t('pettycash', 'transactions.revision') }} {{ rev.number }}
					</button>
				</div>

				<div v-if="selectedRevision" class="tab-panel" role="tabpanel">
					<div class="card-details">
						<div class="detail-item">
							<strong>{{ t('pettycash', 'transactions.expenseDate') }}</strong>
							<div>{{ new Date(selectedRevision.expenseDate).toLocaleDateString('fa-IR') }}</div>
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
							<div>{{ new Date(selectedRevision.createdAt).toLocaleString('fa-IR') }}</div>
						</div>
						<div class="detail-item">
							<strong>{{ t('pettycash', 'transactions.origin') }}</strong>
							<div>{{ selectedRevision.number === 1 ? t('pettycash', 'transactions.original') : t('pettycash', 'transactions.revision') + ' ' + selectedRevision.number }}</div>
						</div>
					</div>
				</div>
			</div>
		</NcNoteCard>

		<!-- FR-050 keeps every superseded revision part of the record and FR-053 makes that record
		     append-only, so this region is deliberately inert: no control in it can change a past
		     revision. The revision selector above lets a reader page through history; this states that
		     what they are reading is retained history rather than a previous state of an editable row. -->
		<NcNoteCard v-if="priorRevisions.length > 0" data-test="prior-revisions">
			<template #header>
				{{ t('pettycash', 'transactions.priorRevisions') }}
			</template>

			<p class="prior-revisions__note">
				{{ t('pettycash', 'transactions.priorRevisionsRetained') }}
			</p>
			<ul class="prior-revisions-list">
				<li v-for="rev in priorRevisions" :key="rev.id" class="prior-revision" data-test="prior-revision">
					<div class="prior-revision__header">
						<strong>{{ t('pettycash', 'transactions.revision') }} {{ rev.number }}</strong>
						<small>{{ new Date(rev.createdAt).toLocaleString('fa-IR') }}</small>
					</div>
					<dl class="prior-revision__fields">
						<dt>{{ t('pettycash', 'transactions.expenseDate') }}</dt>
						<dd>{{ new Date(rev.expenseDate).toLocaleDateString('fa-IR') }}</dd>
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
		</NcNoteCard>

		<NcNoteCard data-test="decision-list">
			<template #header>
				{{ t('pettycash', 'transactions.decisions') }}
			</template>

			<div v-if="transactionStore.currentTransaction?.decisions?.length" class="decision-list">
				<div v-for="decision in transactionStore.currentTransaction.decisions" :key="decision.id" class="decision-item" data-test="decision-item">
					<div class="decision-header">
						<NcChip>
							{{ t('pettycash', 'decision.' + decision.decision.toLowerCase()) }}
						</NcChip>
						<span>{{ t('pettycash', 'stage.' + decision.stage.toLowerCase()) }}</span>
						<small>{{ new Date(decision.decidedAt).toLocaleString('fa-IR') }}</small>
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
		</NcNoteCard>

		<NcNoteCard data-test="evidence-list">
			<template #header>
				<div class="card-header-row">
					<span>{{ t('pettycash', 'evidence.list') }} ({{ transactionStore.currentTransaction?.evidence?.length ?? 0 }})</span>
					<NcButton v-if="!isSubmitted" variant="primary" @click="showEvidenceUploader = true" data-test="upload-evidence-btn">
						<NcIconSvgWrapper :path="mdiPlus" :size="16" class="mr-2" />
						{{ t('pettycash', 'evidence.upload') }}
					</NcButton>
				</div>
			</template>

			<div v-if="transactionStore.currentTransaction?.evidence?.length" class="evidence-list">
				<div v-for="ev in transactionStore.currentTransaction.evidence" :key="ev.id" class="evidence-item" data-test="evidence-list-item">
					<div class="evidence-info">
						<NcIconSvgWrapper :path="mdiFileOutline" :size="24" class="evidence-icon" />
						<div class="evidence-details">
							<span class="evidence-name">{{ ev.name }}</span>
							<small class="evidence-meta">{{ ev.mediaType }} · {{ formatFileSize(ev.sizeBytes) }} · {{ new Date(ev.uploadedAt).toLocaleString('fa-IR') }}</small>
						</div>
					</div>
					<div class="evidence-actions">
						<NcChip data-test="evidence-active-badge">
							{{ ev.active ? t('pettycash', 'evidence.active') : t('pettycash', 'evidence.inactive') }}
						</NcChip>
						<NcButton v-if="ev.active && !isSubmitted" variant="tertiary" @click="downloadEvidence(ev.id)" data-test="download-evidence">
							<NcIconSvgWrapper :path="mdiDownload" :size="16" />
						</NcButton>
						<NcButton v-if="ev.active && !isSubmitted" variant="tertiary" @click="removeEvidence(ev.id)" data-test="remove-evidence">
							<NcIconSvgWrapper :path="mdiDelete" :size="16" />
						</NcButton>
						<NcChip v-if="!ev.active" variant="error" data-test="inactive-badge">
							{{ t('pettycash', 'evidence.inactive') }}
						</NcChip>
					</div>
				</div>
			</div>
			<div v-else class="text-center py-4">{{ t('pettycash', 'evidence.none') }}</div>
		</NcNoteCard>

		<NcDialog
			name="evidence-upload"
			size="normal"
			:open="showEvidenceUploader"
			@update:open="showEvidenceUploader = $event">
			<EvidenceUploader
				:transaction-id="transactionId"
				:is-draft="!isSubmitted"
				@uploaded="handleEvidenceUploaded"
			/>
		</NcDialog>

		<div v-if="transactionStore.error" class="alert alert--error" role="alert">
			<span>{{ transactionStore.error }}</span>
			<button type="button" @click="transactionStore.clearError" aria-label="Dismiss">×</button>
		</div>
	</div>
</template>

<style scoped>
.transaction-detail-view {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.header-row {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	align-items: center;
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
	grid-template-columns: 1fr;
	gap: 1rem;
}

@media (width >= 600px) {
	.card-details {
		grid-template-columns: repeat(2, 1fr);
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

.tab-list {
	display: flex;
	gap: 0.5rem;
	border-bottom: 1px solid var(--nc-color-outline-variant, #e0e0e0);
	padding-bottom: 0.5rem;
}

.tab-button {
	padding: 0.5rem 1rem;
	background: none;
	border: none;
	border-radius: 4px 4px 0 0;
	cursor: pointer;
	color: var(--nc-color-on-surface-variant, #616161);
	font-size: var(--font-size-floor);
}

.tab-button.active {
	color: var(--nc-color-primary, #1976d2);
	font-weight: 600;
	border-bottom: 2px solid var(--nc-color-primary, #1976d2);
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
	background-color: var(--nc-color-surface-container-low, #fafafa);
	border-radius: 4px;
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
	color: var(--nc-color-on-surface-variant, #616161);
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
	background-color: var(--nc-color-surface-container-low, #fafafa);
	border-radius: 4px;
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

.evidence-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.evidence-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.75rem;
	background-color: var(--nc-color-surface-container-low, #fafafa);
	border-radius: 4px;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.evidence-info {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	flex: 1;
}

.evidence-icon {
	color: var(--nc-color-on-surface-variant, #616161);
}

.evidence-details {
	display: flex;
	flex-direction: column;
	gap: 0.125rem;
}

.evidence-name {
	font-weight: 500;
}

.evidence-meta {
	font-size: var(--font-size-floor);
	color: var(--nc-color-on-surface-variant, #616161);
}

.evidence-actions {
	display: flex;
	align-items: center;
	gap: 0.5rem;
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
		margin-right: 0.5rem;
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
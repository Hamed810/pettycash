<script setup lang="ts">
import { direction } from '@/utils/direction'
import { computed, h, nextTick, onMounted, onUnmounted, ref, watch, type VNode } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSheetStore } from '@/stores/sheets'
import type { AuditEvent, SheetStatus, TransactionSummary } from '@/types/api'
import { NcButton, NcChip, NcIconSvgWrapper, NcNoteCard } from '@nextcloud/vue'
import { mdiClose, mdiDelete, mdiEye, mdiLock, mdiPencil, mdiPlus, mdiSend } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { decisionKey, stageKey } from '@/l10n'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import { formatMoney } from '@/utils/money'
import ResponsiveTable from '@/components/shared/ResponsiveTable.vue'
import DeleteSheetDialog from '@/components/shared/DeleteSheetDialog.vue'
import { useSheetHistory } from '@/composables/useSheetHistory'
import { rememberTab, rememberedTab, WORKSPACE_TABS, type WorkspaceTab } from '@/views/sheets/workspaceTabs'
import { formatSheetDate as formatDate, formatSheetDateRange as formatDateRange } from '@/utils/date'

const route = useRoute()
const router = useRouter()
const sheetStore = useSheetStore()

const sheetId = computed(() => route.params.id as string)
const sheet = computed(() => sheetStore.currentSheet)

// The domain's `SheetStatus` values (FR-037).
const statusLabels: Record<SheetStatus, string> = {
	OPEN: 'status.open',
	COST_CENTER: 'status.costCenter',
	CORRECTION: 'status.correction',
	SENIOR: 'status.senior',
	FINANCE: 'status.finance',
	COMPLETED: 'status.completed',
	COMPLETED_WITH_REJECTIONS: 'status.completedWithRejections'
}

const isSubmitted = computed(() => sheet.value?.status !== 'OPEN')
const isOpen = computed(() => sheet.value?.status === 'OPEN')
const sheetTransactions = computed(() => sheet.value?.transactions ?? [])

/**
 * The badge's message key, or `null` when the payload carries a status this client does not know.
 *
 * The union is exhaustive, so this is only reachable on a version skew or a malformed payload. It
 * still must not take the workspace down: `t()` receives a real key or is not called at all.
 */
const sheetStatusKey = computed<string | null>(() =>
	statusLabels[sheet.value?.status ?? 'OPEN'] ?? null)

/**
 * The two published statuses that can never occur (D-07). A label is deliberately not invented for
 * them: no such state exists, and rendering a badge would present it as reachable.
 */
const UNREACHABLE_STATUSES = new Set(['AWAITING_SENIOR_REVIEW', 'AWAITING_FINANCE_REVIEW'])

const isLoading = computed(() => sheetStore.loading || (!!sheet.value && sheet.value.id !== sheetId.value))
const notFound = computed(() => !sheet.value && !sheetStore.loading && !!sheetStore.error)

const TITLE_ID = 'sheet-workspace-title'
const dialogRef = ref<HTMLElement | null>(null)

function goToEdit() { void router.push({ name: 'sheet-edit', params: { id: sheetId.value } }) }
function goToCreateTransaction() { void router.push({ name: 'transaction-create', params: { sheetId: sheetId.value } }) }
function goToTransaction(transactionId: string) { void router.push({ name: 'transaction-detail', params: { id: transactionId } }) }

async function handleSubmit() {
	if (!sheet.value) return
	await sheetStore.submitCurrentSheet(sheetId.value, sheet.value.version)
}

/* ------------------------------------------------------------------ *
 * Workspace tabs
 * ------------------------------------------------------------------ */

const tabs = WORKSPACE_TABS

const tabLabels: Record<WorkspaceTab, string> = {
	transactions: 'workspace.tabTransactions',
	approvals: 'workspace.tabApprovals',
	overview: 'workspace.tabOverview',
	history: 'workspace.tabHistory'
}

/**
 * The selected tab survives a drill-down into a transaction and back within the SPA. Returning from
 * a transaction should restore the workspace the purchaser left, not reset it to its first tab.
 */
const activeTab = ref<WorkspaceTab>(rememberedTab(sheetId.value))

function selectTab(tab: WorkspaceTab): void {
	activeTab.value = tab
	rememberTab(sheetId.value, tab)
}

function tabButtonId(tab: WorkspaceTab): string { return `sheet-workspace-tab-${tab}` }
function tabPanelId(tab: WorkspaceTab): string { return `sheet-workspace-panel-${tab}` }

function onTabKeydown(event: KeyboardEvent, index: number): void {
	let next = index
	if (event.key === 'ArrowRight') next = (index + 1) % tabs.length
	else if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length
	else if (event.key === 'Home') next = 0
	else if (event.key === 'End') next = tabs.length - 1
	else return
	event.preventDefault()
	const target = tabs[next]
	if (target) {
		selectTab(target)
		void nextTick(() => document.getElementById(tabButtonId(target))?.focus())
	}
}

/* ------------------------------------------------------------------ *
 * Dialog semantics: focus entry, containment and Escape
 * ------------------------------------------------------------------ */

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

function isRendered(element: HTMLElement): boolean {
	let node: HTMLElement | null = element
	while (node) {
		const style = window.getComputedStyle(node)
		if (style.display === 'none' || style.visibility === 'hidden') return false
		node = node.parentElement
	}
	return true
}

function onDialogKeydown(event: KeyboardEvent): void {
	if (event.key === 'Escape') {
		if (deleteDialogOpen.value) return
		event.preventDefault()
		requestClose()
		return
	}
	if (event.key !== 'Tab' || !dialogRef.value) return
	const nodes = Array.from(dialogRef.value.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(isRendered)
	if (nodes.length === 0) return
	const first = nodes[0]
	const last = nodes[nodes.length - 1]
	if (event.shiftKey && document.activeElement === first) {
		event.preventDefault()
		last?.focus()
	} else if (!event.shiftKey && document.activeElement === last) {
		event.preventDefault()
		first?.focus()
	}
}

function requestClose(): void {
	if (deleteDialogOpen.value) {
		deleteDialogOpen.value = false
		return
	}
	void router.push({ name: 'sheets' }).catch(() => undefined)
}

/* ------------------------------------------------------------------ *
 * FR-086 deletion
 * ------------------------------------------------------------------ */

const deleteDialogOpen = ref(false)
function askDelete() { deleteDialogOpen.value = true }

async function confirmDeleteFromDetail() {
	if (!sheet.value) return
	await sheetStore.deleteOwnSheet(sheetId.value, sheet.value.version)
	deleteDialogOpen.value = false
	void router.push({ name: 'sheets' })
}

/* ------------------------------------------------------------------ *
 * Loading
 * ------------------------------------------------------------------ */

function loadSheet(id: string) {
	if (!id) return
	sheetStore.loadSheet(id).catch(() => undefined)
}

onMounted(async () => {
	loadSheet(sheetId.value)
	await nextTick()
	dialogRef.value?.focus()
})

onUnmounted(() => { sheetStore.clearCurrentSheet() })

watch(sheetId, (id) => {
	activeTab.value = rememberedTab(id)
	loadSheet(id)
})

/* ------------------------------------------------------------------ *
 * Transaction table (T036)
 * ------------------------------------------------------------------ */

const transactionHeaders = [
	{ key: 'expenseDate', label: 'transactions.expenseDate' },
	{ key: 'description', label: 'transactions.description' },
	{ key: 'amount', label: 'transactions.amount' },
	{ key: 'costCenter', label: 'transactions.costCenter' },
	{ key: 'expenseType', label: 'transactions.expenseType' },
	{ key: 'stage', label: 'transactions.stage' },
	{ key: 'status', label: 'transactions.status' },
	{ key: 'evidenceCount', label: 'transactions.evidenceCount' },
	{ key: 'actions', label: 'transactions.actions' }
]

const transactionColumns = computed(() =>
	transactionHeaders.map((header) => ({ key: header.key, label: t('pettycash', header.label) })))

function transactionRowKey(item: TransactionSummary, index: number): string {
	return item.id || `sheet-transaction-${index}`
}

function sheetTransactionCardId(item: TransactionSummary): string {
	return `sheet-transaction-card-${item.id}`
}

function transactionCell(item: TransactionSummary, column: { key: string }): VNode {
	switch (column.key) {
	case 'expenseDate':
		return h('span', { 'data-test': 'transaction-expense-date' }, formatDate(item.activeRevision.expenseDate))
	case 'description':
		return h('span', { 'data-test': 'transaction-description' }, item.activeRevision.description ?? '')
	case 'amount':
		return h('span', { 'data-test': 'transaction-amount' }, formatMoney(item.activeRevision.amount))
	case 'costCenter':
		return h('span', null, item.activeRevision.costCenter.name)
	case 'expenseType':
		return h('span', null, item.activeRevision.expenseType.name)
	case 'stage':
		// A stage is a label, not a removable selection: NcChip's close affordance is off.
		return h(NcChip, { noClose: true }, () => t('pettycash', stageKey(item.stage)))
	case 'status':
		// D-07: these two published values cannot occur; no label is invented for them.
		if (UNREACHABLE_STATUSES.has(item.status)) {
			return h('span', { 'data-test': 'transaction-status-badge' }, '—')
		}
		// The shared badge owns the transaction-status vocabulary *and* its no-close chip, so the
		// workspace cannot drift from the list or print a message key the catalogue does not have.
		return h(StatusBadge, {
			status: item.status,
			'data-test': 'transaction-status-badge'
		})
	case 'evidenceCount':
		return h('span', { 'data-test': 'evidence-count' }, item.evidenceCount)
	case 'actions':
		// Only the read action is offered: `TransactionSummary` carries no capability projection,
		// so edit cannot be claimed from the workspace payload without inventing a permission.
		return h(
			NcButton,
			{ variant: 'tertiary', 'data-test': 'view-transaction', 'aria-label': t('pettycash', 'common.view'), onClick: () => goToTransaction(item.id) },
			() => h(NcIconSvgWrapper, { path: mdiEye, size: 16 })
		)
	default:
		return h('span', { 'data-test': `transaction-unrendered-${column.key}` }, column.key)
	}
}

/* ------------------------------------------------------------------ *
 * FR-005-014 readiness (server tier only)
 * ------------------------------------------------------------------ */

function transactionLabel(item: TransactionSummary, index: number): string {
	const description = item.activeRevision.description?.trim()
	if (description) return description
	const date = formatDate(item.activeRevision.expenseDate)
	return date && date !== '—' ? date : String(index + 1)
}

/**
 * Only the four server-enforced conditions are named. Evidence is deliberately absent: the client
 * has no evidence-requirement rule for the sheet, so its state is reported as unknown rather than
 * guessed from the transaction payload.
 */
const readinessBlockers = computed<string[]>(() => {
	if (isSubmitted.value) return []
	if (sheetTransactions.value.length === 0) return [t('pettycash', 'readiness.noTransactions')]
	const blockers: string[] = []
	sheetTransactions.value.forEach((item, index) => {
		const revision = item.activeRevision
		const missing: string[] = []
		if (!revision.description?.trim()) missing.push(t('pettycash', 'readiness.missingDescription'))
		if (!revision.costCenter?.id && !revision.costCenter?.name) missing.push(t('pettycash', 'readiness.missingCostCenter'))
		if (!revision.expenseType?.id && !revision.expenseType?.name) missing.push(t('pettycash', 'readiness.missingExpenseType'))
		if (missing.length > 0) {
			blockers.push(t('pettycash', 'readiness.transactionLabel', {
				label: `${transactionLabel(item, index)} — ${missing.join('، ')}`
			}))
		}
	})
	return blockers
})

/* ------------------------------------------------------------------ *
 * Approvals: server-published figures only (T040 remains blocked)
 * ------------------------------------------------------------------ */

const stageFigures = computed(() => [
	{ stage: 'COST_CENTER', value: sheet.value?.totals.costCenterApproved },
	{ stage: 'SENIOR', value: sheet.value?.totals.seniorApproved },
	{ stage: 'FINANCE', value: sheet.value?.totals.financeApproved }
])

/* ------------------------------------------------------------------ *
 * History (T037)
 * ------------------------------------------------------------------ */

const history = useSheetHistory()

const historyTransactions = computed(() => sheetTransactions.value.map((item, index) => ({
	id: item.id,
	label: transactionLabel(item, index)
})))

watch(
	[activeTab, historyTransactions],
	() => {
		if (activeTab.value === 'history') history.ensureLoaded(historyTransactions.value)
	},
	{ immediate: true }
)

function eventLabel(eventType: string): string {
	switch (eventType) {
	case 'SUBMITTED': return t('pettycash', 'history.event.SUBMITTED')
	case 'ROUTED': return t('pettycash', 'history.event.ROUTED')
	case 'APPROVED': return t('pettycash', 'history.event.APPROVED')
	case 'REJECTED': return t('pettycash', 'history.event.REJECTED')
	case 'RETURNED': return t('pettycash', 'history.event.RETURNED')
	case 'CORRECTED': return t('pettycash', 'history.event.CORRECTED')
	case 'REVISION_CREATED': return t('pettycash', 'history.event.REVISION_CREATED')
	case 'TRANSACTION_CHANGED': return t('pettycash', 'history.event.TRANSACTION_CHANGED')
	case 'EVIDENCE_ADDED': return t('pettycash', 'history.event.EVIDENCE_ADDED')
	case 'EVIDENCE_DEACTIVATED': return t('pettycash', 'history.event.EVIDENCE_DEACTIVATED')
	case 'SUPERSEDED_ATTEMPT': return t('pettycash', 'history.event.SUPERSEDED_ATTEMPT')
	default: return eventType
	}
}

/** The decision value lives inside `after`; the top-level `decision` is the literal `DECISION`. */
function decisionFrom(event: AuditEvent): string | null {
	const after = event.after
	if (!after || typeof after !== 'object' || !('decision' in after)) return null
	const value = (after as { decision?: unknown }).decision
	if (typeof value !== 'string' || value.length === 0) return null
	if (value === 'APPROVE' || value === 'REJECT' || value === 'RETURN') {
		return t('pettycash', decisionKey(value))
	}
	return value
}
</script>

<template>
	<div class="sheet-workspace" :dir="direction" data-test="sheet-detail-view">
		<div class="sheet-workspace__scrim" data-test="sheet-workspace-scrim" @click="requestClose" />
		<section
			ref="dialogRef"
			class="sheet-workspace__dialog"
			role="dialog"
			aria-modal="true"
			:aria-labelledby="TITLE_ID"
			tabindex="-1"
			data-test="sheet-workspace"
			@keydown="onDialogKeydown">
			<header class="workspace-header" data-test="sheet-header">
				<div class="workspace-header__identity">
					<span class="workspace-header__eyebrow">{{ t('pettycash', 'sheets.sheet') }}</span>
					<h2 :id="TITLE_ID" class="workspace-header__title" data-test="sheet-title">
						{{ sheet?.title ?? '' }}
					</h2>
					<div class="workspace-header__badges">
						<span class="workspace-id" data-test="sheet-id">{{ sheet?.id ?? '' }}</span>
						<NcChip v-if="sheetStatusKey" no-close data-test="sheet-status">
							{{ t('pettycash', sheetStatusKey) }}
						</NcChip>
						<NcChip v-if="isSubmitted" variant="secondary" no-close data-test="read-only-badge">
							<NcIconSvgWrapper :path="mdiLock" :size="16" class="workspace-badge-icon" />
							{{ t('pettycash', 'common.readOnly') }}
						</NcChip>
					</div>
				</div>

				<div class="workspace-header__actions">
					<NcButton v-if="!isSubmitted" variant="secondary" data-test="edit-sheet-btn" @click="goToEdit">
						<NcIconSvgWrapper :path="mdiPencil" :size="16" class="workspace-badge-icon" />
						{{ t('pettycash', 'common.edit') }}
					</NcButton>
					<NcButton v-if="!isSubmitted" variant="primary" data-test="create-transaction-btn" @click="goToCreateTransaction">
						<NcIconSvgWrapper :path="mdiPlus" :size="16" class="workspace-badge-icon" />
						{{ t('pettycash', 'transactions.add') }}
					</NcButton>
					<NcButton
						v-if="isOpen"
						variant="error"
						data-test="delete-sheet-btn"
						@click="askDelete">
						<NcIconSvgWrapper :path="mdiDelete" :size="16" class="workspace-badge-icon" />
						{{ t('pettycash', 'common.delete') }}
					</NcButton>
					<NcButton
						variant="tertiary"
						:aria-label="t('pettycash', 'common.close')"
						data-test="close-sheet-workspace"
						@click="requestClose">
						<NcIconSvgWrapper :path="mdiClose" :size="20" />
					</NcButton>
				</div>
			</header>

			<div class="workspace-summary">
				<div class="workspace-figure" data-test="workspace-recorded-total">
					<span class="workspace-figure__label">{{ t('pettycash', 'workspace.recordedAmount') }}</span>
					<strong class="workspace-figure__value">{{ formatMoney(sheet?.totals.current) }}</strong>
				</div>
				<div class="workspace-figure workspace-figure--success" data-test="workspace-approved-total">
					<span class="workspace-figure__label">{{ t('pettycash', 'workspace.latestApproved') }}</span>
					<strong class="workspace-figure__value">{{ formatMoney(sheet?.totals.effectiveApproved) }}</strong>
				</div>
				<dl class="workspace-facts">
					<div class="workspace-fact">
						<dt>{{ t('pettycash', 'sheets.currency') }}</dt>
						<dd data-test="sheet-currency">{{ sheet?.currency }}</dd>
					</div>
					<div class="workspace-fact">
						<dt>{{ t('pettycash', 'sheets.owner') }}</dt>
						<dd data-testid="sheet-owner">{{ sheet?.ownerDisplayName }}</dd>
					</div>
					<div class="workspace-fact">
						<dt>{{ t('pettycash', 'workspace.firstSubmitted') }}</dt>
						<dd>{{ sheet?.submittedAt ? formatDate(sheet.submittedAt) : t('pettycash', 'workspace.notSubmitted') }}</dd>
					</div>
					<div class="workspace-fact">
						<dt>{{ t('pettycash', 'sheets.transactionCount') }}</dt>
						<dd>{{ sheetTransactions.length }}</dd>
					</div>
				</dl>
			</div>

			<p v-if="isLoading" class="workspace-state" data-test="sheet-loading">
				{{ t('pettycash', 'workspace.loading') }}
			</p>

			<div v-if="notFound" class="workspace-not-found" data-test="sheet-not-found">
				<h3>{{ t('pettycash', 'workspace.notFoundTitle') }}</h3>
				<p>{{ t('pettycash', 'workspace.notFoundHint') }}</p>
				<NcButton variant="primary" data-test="sheet-not-found-back" @click="requestClose">
					{{ t('pettycash', 'workspace.backToSheets') }}
				</NcButton>
			</div>

			<!-- A store-level submission error is independent of payload presence: it must be
			     announced whether or not the detail has finished loading. -->
			<div
				v-if="sheetStore.error"
				class="alert alert--error submission-errors"
				role="alert"
				aria-live="assertive"
				data-test="submission-errors">
				<span data-test="submission-error-message">{{ sheetStore.error }}</span>
				<button type="button" :aria-label="t('pettycash', 'common.dismiss')" @click="sheetStore.clearError">×</button>
			</div>

			<template v-if="!notFound">
				<div class="workspace-tabs" role="tablist" :aria-label="t('pettycash', 'workspace.tabs')">
					<button
						v-for="(tab, index) in tabs"
						:id="tabButtonId(tab)"
						:key="tab"
						type="button"
						role="tab"
						class="workspace-tab"
						:class="{ 'workspace-tab--active': activeTab === tab }"
						:aria-selected="activeTab === tab"
						:aria-controls="tabPanelId(tab)"
						:tabindex="activeTab === tab ? 0 : -1"
						:data-test="`${tab}-tab`"
						@click="selectTab(tab)"
						@keydown="onTabKeydown($event, index)">
						{{ t('pettycash', tabLabels[tab]) }}
						<span v-if="tab === 'transactions'" class="workspace-tab__count">({{ sheetTransactions.length }})</span>
					</button>
				</div>

				<section
					v-show="activeTab === 'transactions'"
					:id="tabPanelId('transactions')"
					role="tabpanel"
					:aria-labelledby="tabButtonId('transactions')"
					tabindex="0"
					data-test="transactions-panel">
					<p v-if="isOpen" class="workspace-hint" data-test="workspace-open-hint">
						{{ t('pettycash', 'workspace.openHint') }}
					</p>

					<section v-if="isOpen" class="readiness" data-test="readiness" aria-labelledby="readiness-title">
						<h3 id="readiness-title" class="readiness__title">{{ t('pettycash', 'readiness.title') }}</h3>
						<div v-if="readinessBlockers.length > 0">
							<p class="readiness__lead">{{ t('pettycash', 'readiness.blocked') }}</p>
							<ul class="readiness__list">
								<li
									v-for="blocker in readinessBlockers"
									:key="blocker"
									class="readiness__blocker"
									data-test="readiness-blocker">
									{{ blocker }}
								</li>
							</ul>
						</div>
						<p v-else class="readiness__ready" data-test="readiness-ready">
							{{ t('pettycash', 'readiness.ready') }}
						</p>
						<p class="readiness__note" data-test="readiness-evidence-unknown">
							{{ t('pettycash', 'readiness.evidenceUnknown') }}
						</p>
						<p class="readiness__note">{{ t('pettycash', 'readiness.submitHint') }}</p>
					</section>

					<div data-test="transaction-list">
						<h3 class="workspace-section-title">
							{{ t('pettycash', 'transactions.list') }} ({{ sheetTransactions.length }})
						</h3>
						<p v-if="sheetStore.loading" class="workspace-state" data-test="transaction-loading">
							{{ t('pettycash', 'common.loading') }}
						</p>
						<ResponsiveTable
							class="workspace-table"
							:columns="transactionColumns"
							:rows="sheetTransactions"
							:cell="transactionCell"
							:row-key="transactionRowKey"
							:caption="t('pettycash', 'sheets.transactions')"
							:loading="sheetStore.loading"
							test-id="transaction-table"
							test-id-prefix="sheet-transaction"
							row-test-id="sheet-transaction-row"
							:card-row-test-id="sheetTransactionCardId"
						/>
					</div>
				</section>

				<section
					v-show="activeTab === 'approvals'"
					:id="tabPanelId('approvals')"
					role="tabpanel"
					:aria-labelledby="tabButtonId('approvals')"
					tabindex="0"
					data-test="approvals-panel">
					<h3 class="workspace-section-title">{{ t('pettycash', 'workspace.tabApprovals') }}</h3>
					<p class="workspace-hint">{{ t('pettycash', 'workspace.approvalsIntro') }}</p>
					<dl class="stage-grid">
						<div v-for="figure in stageFigures" :key="figure.stage" class="stage-card" data-test="approval-stage">
							<dt class="stage-card__label">{{ t('pettycash', stageKey(figure.stage)) }}</dt>
							<dd class="stage-card__value">{{ formatMoney(figure.value) }}</dd>
						</div>
					</dl>
					<p class="workspace-hint" data-test="approvals-counts-unavailable">
						{{ t('pettycash', 'workspace.approvalsCountsUnavailable') }}
					</p>
				</section>

				<section
					v-show="activeTab === 'overview'"
					:id="tabPanelId('overview')"
					role="tabpanel"
					:aria-labelledby="tabButtonId('overview')"
					tabindex="0"
					data-test="overview-panel">
					<div class="workspace-metadata" data-test="sheet-key-dates">
						<div class="workspace-fact">
							<dt>{{ t('pettycash', 'sheets.createdAt') }}</dt>
							<dd data-test="sheet-created-at">{{ formatDate(sheet?.createdAt) }}</dd>
						</div>
						<div class="workspace-fact">
							<dt>{{ t('pettycash', 'sheets.submittedAt') }}</dt>
							<dd data-test="sheet-submitted-at">{{ formatDate(sheet?.submittedAt) }}</dd>
						</div>
						<div class="workspace-fact">
							<dt>{{ t('pettycash', 'sheets.completedAt') }}</dt>
							<dd data-test="sheet-completed-at">{{ formatDate(sheet?.completedAt) }}</dd>
						</div>
						<div class="workspace-fact">
							<dt>{{ t('pettycash', 'sheets.activeRevision') }}</dt>
							<dd data-test="sheet-active-revision">{{ sheet?.activeRevisionNumber ?? 0 }}</dd>
						</div>
						<div class="workspace-fact">
							<dt>{{ t('pettycash', 'sheets.expenseRange') }}</dt>
							<dd data-test="sheet-expense-range">{{ formatDateRange(sheet ?? {}) }}</dd>
						</div>
					</div>

					<NcNoteCard type="info" :heading="t('pettycash', 'sheets.totals')" data-test="sheet-totals">
						<div class="totals-grid">
							<div class="total-chip" data-test="current-total">
								<span class="total-label">{{ t('pettycash', 'sheets.currentTotal') }}</span>
								<span class="total-value">{{ formatMoney(sheet?.totals.current) }}</span>
							</div>
							<div class="total-chip" data-test="submitted-total">
								<span class="total-label">{{ t('pettycash', 'sheets.submittedTotal') }}</span>
								<span class="total-value">{{ formatMoney(sheet?.totals.submitted) }}</span>
							</div>
							<div class="total-column" data-test="effective-outcome-total">
								<span class="total-column-label">{{ t('pettycash', 'sheets.latestEffectiveOutcome') }}</span>
								<div class="total-chip total-chip--success" data-test="effective-approved-total">
									<span class="total-label">{{ t('pettycash', 'sheets.effectiveApproved') }}</span>
									<span class="total-value">{{ formatMoney(sheet?.totals.effectiveApproved) }}</span>
								</div>
								<div class="total-chip total-chip--error" data-test="effective-rejected-total">
									<span class="total-label">{{ t('pettycash', 'sheets.effectiveRejected') }}</span>
									<span class="total-value">{{ formatMoney(sheet?.totals.effectiveRejected) }}</span>
								</div>
								<div class="total-chip total-chip--warning" data-test="effective-pending-total">
									<span class="total-label">{{ t('pettycash', 'sheets.effectivePending') }}</span>
									<span class="total-value">{{ formatMoney(sheet?.totals.effectivePending) }}</span>
								</div>
							</div>
							<div class="total-chip total-chip--primary" data-test="cost-center-approved-total">
								<span class="total-label">{{ t('pettycash', 'sheets.costCenterApproved') }}</span>
								<span class="total-value">{{ formatMoney(sheet?.totals.costCenterApproved) }}</span>
							</div>
							<div class="total-chip total-chip--secondary" data-test="senior-approved-total">
								<span class="total-label">{{ t('pettycash', 'sheets.seniorApproved') }}</span>
								<span class="total-value">{{ formatMoney(sheet?.totals.seniorApproved) }}</span>
							</div>
							<div class="total-chip" data-test="finance-approved-total">
								<span class="total-label">{{ t('pettycash', 'sheets.financeApproved') }}</span>
								<span class="total-value">{{ formatMoney(sheet?.totals.financeApproved) }}</span>
							</div>
						</div>
					</NcNoteCard>
				</section>

				<section
					v-show="activeTab === 'history'"
					:id="tabPanelId('history')"
					role="tabpanel"
					:aria-labelledby="tabButtonId('history')"
					tabindex="0"
					data-test="history-panel">
					<h3 class="workspace-section-title">{{ t('pettycash', 'workspace.tabHistory') }}</h3>
					<p class="workspace-hint" data-test="history-intro">{{ t('pettycash', 'workspace.historyIntro') }}</p>
					<p class="workspace-hint workspace-hint--disclaimer" data-test="history-disclaimer">
						{{ t('pettycash', 'workspace.historyDisclaimer') }}
					</p>

					<p v-if="history.loading.value && history.loaded.value === 0" class="workspace-state" data-test="history-loading">
						{{ t('pettycash', 'common.loading') }}
					</p>

					<div v-for="failure in history.errors.value" :key="failure.transactionId" class="alert alert--error" role="alert" data-test="history-error">
						{{ t('pettycash', 'workspace.historyError') }} — {{ failure.transactionId }}
					</div>

					<p v-if="history.hasLoaded.value && !history.loading.value && history.merged.value.length === 0 && history.errors.value.length === 0"
						class="workspace-hint"
						data-test="history-empty">
						{{ t('pettycash', 'workspace.historyEmpty') }}
					</p>

					<p v-if="history.merged.value.length > 1" class="workspace-hint" data-test="history-order">
						{{ t('pettycash', 'workspace.historyOrder') }}
					</p>

					<ol v-if="history.merged.value.length > 0" class="history-list">
						<li v-for="event in history.merged.value" :key="event.id" class="history-event" data-test="history-event">
							<div class="history-event__head">
								<strong class="history-event__type">{{ eventLabel(event.eventType) }}</strong>
								<time class="history-event__time">{{ formatDate(event.occurredAt) }}</time>
							</div>
							<div class="history-event__meta">
								<span class="history-event__transaction" data-test="history-transaction">{{ event.transactionLabel }}</span>
								<span>{{ t('pettycash', stageKey(event.stage)) }}</span>
								<span>{{ event.actor.displayName }}</span>
							</div>
							<dl v-if="decisionFrom(event) !== null" class="history-event__decision">
								<dt>{{ t('pettycash', 'workspace.decision') }}</dt>
								<dd data-test="history-event-decision">{{ decisionFrom(event) }}</dd>
							</dl>
						</li>
					</ol>

					<div v-if="history.truncated.value.length > 0" class="history-more">
						<p class="workspace-hint">
							{{ t('pettycash', 'workspace.historyLoaded', {
								loaded: history.loaded.value,
								total: history.available.value
							}) }}
						</p>
						<NcButton
							v-for="feed in history.truncated.value"
							:key="feed.transactionId"
							variant="secondary"
							data-test="history-load-more"
							@click="history.loadMore(feed.transactionId)">
							{{ t('pettycash', 'workspace.historyLoadMore') }} — {{ feed.label }}
						</NcButton>
					</div>
				</section>

				<footer class="workspace-footer">
					<NcButton variant="secondary" data-test="close-sheet-workspace-footer" @click="requestClose">
						{{ t('pettycash', 'common.close') }}
					</NcButton>
					<NcButton
						v-if="sheetStore.canSubmit"
						variant="primary"
						:loading="sheetStore.loading"
						data-test="submit-sheet-button"
						@click="handleSubmit">
						<NcIconSvgWrapper :path="mdiSend" :size="16" class="workspace-badge-icon" />
						{{ t('pettycash', 'sheets.submit') }}
					</NcButton>
				</footer>
			</template>

			<DeleteSheetDialog
				:sheet-id="sheet?.id ?? ''"
				:sheet-title="sheet?.title ?? ''"
				:open="deleteDialogOpen"
				:busy="sheetStore.loading"
				@cancel="deleteDialogOpen = false"
				@confirm="confirmDeleteFromDetail" />
		</section>
	</div>
</template>

<style scoped>
.sheet-workspace {
	position: fixed;
	inset: 0;
	z-index: 1500;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 1rem;
	font-size: var(--font-size-floor);
}

.sheet-workspace__scrim {
	position: absolute;
	inset: 0;
	background-color: var(--color-backdrop);
}

.sheet-workspace__dialog {
	position: relative;
	inline-size: min(100%, 72rem);
	max-block-size: 92vh;
	overflow: clip auto;
	padding: 1.5rem;
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: 0 8px 40px var(--color-box-shadow);
}

.sheet-workspace__dialog:focus-visible,
.sheet-workspace__dialog :focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.workspace-header {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	justify-content: space-between;
	gap: 1rem;
}

.workspace-header__identity {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	min-inline-size: 0;
}

.workspace-header__eyebrow {
	color: var(--color-text-maxcontrast);
}

.workspace-header__title {
	margin: 0;
	font-size: var(--font-size-title);
}

.workspace-header__badges,
.workspace-header__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem;
}

.workspace-id {
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.workspace-badge-icon {
	margin-inline-end: 0.25rem;
}

.workspace-summary {
	display: grid;
	grid-template-columns: 1fr;
	gap: 1rem;
	margin-block: 1.25rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);
}

.workspace-figure {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	padding: 0.75rem;
	border-radius: var(--border-radius);
	background-color: var(--color-main-background);
}

.workspace-figure__label {
	color: var(--color-text-maxcontrast);
}

.workspace-figure__value {
	font-size: var(--font-size-title);
}

.workspace-figure--success .workspace-figure__value {
	color: var(--color-success-text, var(--color-success));
}

.workspace-facts {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 0.75rem;
	margin: 0;

	/* The two money figures occupy the first row; the facts span both tracks below them instead of
	   occupying one half and leaving the other half of the summary empty. */
	grid-column: 1 / -1;
}

.workspace-fact {
	display: flex;
	flex-direction: column;
	gap: 0.125rem;
	min-inline-size: 0;
}

.workspace-fact dt {
	color: var(--color-text-maxcontrast);
}

.workspace-fact dd {
	margin: 0;
	overflow-wrap: anywhere;
}

.workspace-state {
	color: var(--color-text-maxcontrast);
}

.workspace-not-found {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 0.75rem;
	padding-block: 2rem;
}

.workspace-tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem;
	margin-block-end: 1rem;
	border-block-end: 1px solid var(--color-border);

	/* The tabs are the workspace's primary navigation. On a short screen the summary above them
	   can fill the viewport, so keep them reachable once the dialog starts scrolling. */
	position: sticky;
	inset-block-start: 0;
	z-index: 2;
	background-color: var(--color-main-background);
}

.workspace-tab {
	min-block-size: 2.75rem;
	padding: 0.5rem 0.75rem;
	border: 0;
	border-block-end: 3px solid transparent;
	background: none;
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
	cursor: pointer;
}

.workspace-tab--active {
	border-block-end-color: var(--color-primary-element);
	font-weight: bold;
}

.workspace-tab__count {
	margin-inline-start: 0.25rem;
	color: var(--color-text-maxcontrast);
}

.workspace-hint {
	color: var(--color-text-maxcontrast);
}

.workspace-hint--disclaimer {
	border-inline-start: 3px solid var(--color-warning);
	padding-inline-start: 0.75rem;
}

.workspace-section-title {
	margin-block: 1rem 0.5rem;
	font-size: var(--font-size-section);
}

/*
 * The workspace table must fit the dialog. Its columns include long user-entered names, so the
 * table's min-content width would otherwise exceed the dialog and the RTL layout would clip the
 * first column against the left edge (the dialog itself must not scroll horizontally). Letting
 * every cell break a long token and letting the status/stage chips wrap keeps the table inside
 * the viewport; the narrow layout is the shared card presentation.
 */
.workspace-table :deep(table) {
	inline-size: 100%;

	/* Eight columns with long user-entered names: an auto layout gives every spare pixel to the
	   names and squeezes the stage/status chips into vertical one-character columns. Fixed layout
	   plus explicit share keeps every column readable at the dialog's desktop widths. */
	table-layout: fixed;
}

.workspace-table :deep(th:nth-child(1)) {
	inline-size: 8%;
}

.workspace-table :deep(th:nth-child(2)) {
	inline-size: 15%;
}

.workspace-table :deep(th:nth-child(3)) {
	inline-size: 10%;
}

.workspace-table :deep(th:nth-child(4)) {
	inline-size: 15%;
}

.workspace-table :deep(th:nth-child(5)) {
	inline-size: 14%;
}

.workspace-table :deep(th:nth-child(6)) {
	inline-size: 10%;
}

.workspace-table :deep(th:nth-child(7)) {
	inline-size: 13%;
}

.workspace-table :deep(th:nth-child(8)) {
	inline-size: 7%;
}

.workspace-table :deep(th:nth-child(9)) {
	inline-size: 8%;
}

.workspace-table :deep(th),
.workspace-table :deep(td) {
	overflow-wrap: anywhere;
}

.workspace-table :deep(td) {
	vertical-align: top;
}

.workspace-table :deep(.nc-chip) {
	block-size: auto;
	max-inline-size: 100%;
	white-space: normal;
}

.workspace-table :deep(.nc-chip__text) {
	white-space: normal;
	overflow-wrap: anywhere;
}

/* `NcNoteCard`'s slot div is a shrink-to-fit flex item; let the totals card fill its width. */
:deep(.notecard > div) {
	min-inline-size: 0;
	flex: 1 1 auto;
}

.readiness {
	margin-block: 1rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-inline-start: 4px solid var(--color-primary-element);
	border-radius: var(--border-radius);
}

.readiness__title {
	margin-block-start: 0;
	font-size: var(--font-size-section);
}

.readiness__list {
	margin: 0;
	padding-inline-start: 1.25rem;
}

.readiness__blocker {
	margin-block: 0.25rem;
}

.readiness__ready {
	color: var(--color-success-text, var(--color-success));
}

.readiness__note {
	margin-block: 0.5rem 0;
	color: var(--color-text-maxcontrast);
}

.stage-grid {
	display: grid;
	grid-template-columns: 1fr;
	gap: 1rem;
	margin-block: 1rem;
}

.stage-card {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.stage-card__label {
	color: var(--color-text-maxcontrast);
}

.stage-card__value {
	margin: 0;
	font-size: var(--font-size-emphasis);
}

.workspace-metadata {
	display: grid;
	grid-template-columns: 1fr;
	gap: 0.75rem;
	margin-block: 1rem;
}

.totals-grid {
	display: grid;
	grid-template-columns: 1fr;
	gap: 0.75rem;
}

.total-column {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.total-column-label {
	color: var(--color-text-maxcontrast);
}

.total-chip {
	display: flex;
	flex-direction: column;
	gap: 0.125rem;
	padding: 0.75rem;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.total-chip--success { border-inline-start: 3px solid var(--color-success); }
.total-chip--error { border-inline-start: 3px solid var(--color-error); }
.total-chip--warning { border-inline-start: 3px solid var(--color-warning); }
.total-chip--primary { border-inline-start: 3px solid var(--color-primary-element); }
.total-chip--secondary { border-inline-start: 3px solid var(--color-primary-element-light); }

.total-label {
	color: var(--color-text-maxcontrast);
}

.total-value {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.history-list {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.history-event {
	padding: 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.history-event__head {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: 0.5rem;
}

.history-event__time,
.history-event__meta {
	color: var(--color-text-maxcontrast);
}

.history-event__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 0.75rem;
	margin-block-start: 0.25rem;
}

.history-event__decision {
	display: flex;
	gap: 0.5rem;
	margin: 0.5rem 0 0;
}

.history-event__decision dd {
	margin: 0;
	font-weight: bold;
}

.history-more {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 0.5rem;
	margin-block-start: 1rem;
}

.workspace-footer {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: 0.5rem;
	margin-block-start: 1.5rem;
	padding-block-start: 1rem;
	border-block-start: 1px solid var(--color-border);
}

.alert {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.75rem 1rem;
	border-radius: var(--border-radius);
}

.alert--error {
	background-color: var(--color-background-hover);
	color: var(--color-error-text);
}

@media (width >= 600px) {
	.workspace-summary {
		grid-template-columns: 1fr 1fr;
	}

	.stage-grid,
	.workspace-metadata {
		grid-template-columns: repeat(3, 1fr);
	}

	.totals-grid {
		grid-template-columns: repeat(2, 1fr);
	}
}

@media (width >= 900px) {
	.totals-grid {
		grid-template-columns: repeat(4, 1fr);
	}
}

@media (width <= 640px) {
	.sheet-workspace {
		padding: 0;
	}

	.sheet-workspace__dialog {
		inline-size: 100%;
		max-block-size: 100vh;
		border-radius: 0;
	}

	.workspace-header__actions {
		inline-size: 100%;
	}

	.workspace-summary {
		padding: 0.75rem;
	}

	.workspace-facts {
		grid-template-columns: 1fr 1fr;
	}

	.workspace-footer {
		position: sticky;
		inset-block-end: 0;
		background-color: var(--color-main-background);
	}
}

@media (prefers-reduced-motion: reduce) {
	.sheet-workspace__dialog,
	.workspace-tab {
		transition: none;
		animation: none;
	}
}
</style>

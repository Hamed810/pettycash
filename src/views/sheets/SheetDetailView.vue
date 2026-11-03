<script setup lang="ts">
import { direction } from '@/utils/direction'
import { computed, h, nextTick, onMounted, onUnmounted, ref, watch, type VNode } from 'vue'
import { useRoute, useRouter, type LocationQueryRaw } from 'vue-router'
import { useSheetStore } from '@/stores/sheets'
import { ApiError, type AuditEvent, type SheetStatus, type TransactionSummary } from '@/types/api'
import { parseEvidenceFieldErrors, type EvidenceFieldError } from '@/utils/evidenceFieldErrors'
import { NcButton, NcIconSvgWrapper, NcNoteCard } from '@nextcloud/vue'
import {
	mdiCheckCircle,
	mdiCheckCircleOutline,
	mdiClose,
	mdiCloseCircleOutline,
	mdiDelete,
	mdiEye,
	mdiFileDocumentOutline,
	mdiFolderOutline,
	mdiHistory,
	mdiInformationOutline,
	mdiLock,
	mdiPencil,
	mdiPlus,
	mdiSend,
	mdiTagOutline,
	mdiWalletOutline,
} from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { decisionKey, stageKey } from '@/l10n'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import { formatMoney } from '@/utils/money'
import ResponsiveTable from '@/components/shared/ResponsiveTable.vue'
import DeleteSheetDialog from '@/components/shared/DeleteSheetDialog.vue'
import { useSheetHistory } from '@/composables/useSheetHistory'
import TransactionSurface from '@/components/transactions/TransactionSurface.vue'
import { rememberTab, rememberedTab, WORKSPACE_TABS, type WorkspaceTab } from '@/views/sheets/workspaceTabs'
import { formatSheetDate as formatDate, formatSheetDateRange as formatDateRange } from '@/utils/date'

const route = useRoute()
const router = useRouter()
const sheetStore = useSheetStore()

const sheetId = computed(() => route.params.id as string)
const sheet = computed(() => sheetStore.currentSheet)

/**
 * T066: the transaction surface is addressed by a query parameter on the sheet route.
 *
 * Using `?transaction=<id>` rather than a second route keeps the sheet workspace mounted underneath
 * (so closing the surface returns to the tab and scroll position the purchaser left) while the
 * combination remains refreshable and deep-linkable. The standalone `/transactions/:id` route stays
 * in service for links that do not originate from a sheet.
 */
const surfaceTransactionId = computed(() => {
	const value = route.query.transaction
	return typeof value === 'string' && value !== '' ? value : null
})
const surfaceOpen = computed(() => surfaceTransactionId.value !== null)
/**
 * T075: the OPEN-sheet eye action asks the surface to enter the form directly. The surface still
 * decides from the detail's capability set, so a stale flag or an unauthorized row falls back to the
 * existing read-only inspection rather than exposing an edit the server would refuse.
 */
const surfaceEdit = computed(() => route.query.edit === '1')
const lastSurfaceTransactionId = ref<string | null>(null)

/**
 * T080: a workspace-originated edit keeps this workspace mounted behind the shared edit modal.
 *
 * The workspace is the context the purchaser returns to, so it stays visible. While the edit route is
 * active it is a background layer: inert, without a dialog role or `aria-modal`, and the edit dialog is
 * the only `[role="dialog"]`. A list-row or direct edit never mounts it at all.
 */
const backgrounded = computed(() => route.name === 'sheet-edit')
const nonModal = computed(() => surfaceOpen.value || backgrounded.value)

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
 * The approved workspace cards colour the approved figure by its own state: a sheet whose latest
 * outcome has a non-zero approved amount gets the primary blue, and without one the figure stays the
 * neutral text colour. The rejected figure is the same rule the reference cards follow.
 */
const approvedTotalAmount = computed(() =>
	sheet.value?.totals.effectiveApproved?.amountMinor ?? 0)
const rejectedTotalAmount = computed(() =>
	sheet.value?.totals.effectiveRejected?.amountMinor ?? 0)

/**
 * The ⓘ affordance beside the status pill explains the status in one line, exactly as the
 * approved design's popover does. The explanation follows the same open/submitted split the
 * readiness block already uses, and both literal keys are catalogued.
 */
const statusInfoText = computed(() =>
	isSubmitted.value ? t('pettycash', 'workspace.submittedHint') : t('pettycash', 'workspace.openHint'))

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

/**
 * The workspace's table chip prints the reviewing stage alone — the full "awaiting … review"
 * vocabulary cannot fit a single line inside the reference's compact column, and the owner asked
 * the stage to remain identifiable. The chips below name the stage; the shared badge keeps its own
 * full label on the chip as the hover title, and the list and header keep the full labels.
 */
const workspaceStatusLabels: Record<string, string> = {
	AWAITING_COST_CENTER_REVIEW: 'workspace.status.awaitingCostCenterReview',
	AWAITING_SENIOR_REVIEW: 'workspace.status.awaitingSeniorReview',
	AWAITING_FINANCE_REVIEW: 'workspace.status.awaitingFinanceReview',
	AWAITING_PURCHASER_CORRECTION: 'workspace.status.awaitingPurchaserCorrection',
}

/**
 * The full-page loader is a *detail* concern (C-18): only when no sheet for the current id has been
 * loaded and a detail read is in flight. A list refetch, transaction fetch or mutation cannot reach it.
 */
const isLoading = computed(() => !sheet.value && sheetStore.detailLoadingId === sheetId.value)
const notFound = computed(() => (!sheet.value || sheet.value.id !== sheetId.value)
	&& !isLoading.value
	&& sheetStore.detailErrorStatus === 404)

const TITLE_ID = 'sheet-workspace-title'
const dialogRef = ref<HTMLElement | null>(null)

/** T058: the named receipt refusals from the server's 422, kept separate from the short message. */
const submissionEvidenceErrors = ref<EvidenceFieldError[]>([])
const submissionEvidenceSummary = ref<HTMLElement | null>(null)

/**
 * T080: the edit modal returns to this workspace because the origin travels in the URL. A direct
 * `/sheets/:id/edit` visit carries no marker and returns to the list instead.
 */
function goToEdit() {
	void router.push({ name: 'sheet-edit', params: { id: sheetId.value }, query: { from: 'workspace' } })
}
function goToCreateTransaction() { void router.push({ name: 'transaction-create', params: { sheetId: sheetId.value } }) }

/**
 * Open one row's transaction surface without leaving the sheet route.
 *
 * `push` rather than `replace` so the browser Back button closes the surface, which is what a
 * reader expects from a layer and what keeps the URL an honest description of the screen.
 */
function openTransaction(transactionId: string): void {
	const query: LocationQueryRaw = { ...route.query, transaction: transactionId }
	// T075: only an OPEN sheet's eye click requests the editable experience. Non-OPEN sheets keep
	// the exact inspection entry point they had; the surface remains the capability authority.
	if (isOpen.value) query.edit = '1'
	else delete query.edit
	void router.push({
		name: 'sheet-detail',
		params: { id: sheetId.value },
		query,
	}).catch(() => undefined)
}

function closeTransaction(): void {
	const query: LocationQueryRaw = { ...route.query }
	delete query.transaction
	delete query.edit
	void router.push({ name: 'sheet-detail', params: { id: sheetId.value }, query }).catch(() => undefined)
}

/**
 * T066's focus return: put focus back on the exact row action that opened the surface.
 *
 * The table renders both a desktop row and a narrow card, so the first matching element may be the
 * hidden layout. Pick the rendered one; if the row no longer exists (a successful delete) fall back
 * to the workspace itself rather than dropping focus on `<body>`.
 */
function focusTransactionRow(transactionId: string): void {
	const candidates = Array.from(document.querySelectorAll<HTMLElement>('[data-test="view-transaction"]'))
		.filter((element) => element.dataset.transactionId === transactionId)
	const target = candidates.find(isRendered)
	if (target) {
		target.focus()
		return
	}
	dialogRef.value?.focus()
}

watch(surfaceTransactionId, async (id, previous) => {
	if (id) {
		lastSurfaceTransactionId.value = id
		return
	}
	if (!previous) return
	const target = lastSurfaceTransactionId.value ?? previous
	await nextTick()
	focusTransactionRow(target)
})

/** The surface asks for the owning sheet after a delete or save; a 404 there means the row is gone. */
async function handleSurfaceRefresh(outcome: 'deleted' | 'already-deleted' | 'saved'): Promise<void> {
	await sheetStore.loadSheet(sheetId.value).catch(() => undefined)
	// T075: a successful save returns to the same workspace, exactly like a completed delete. The
	// already-deleted branch keeps T067's notice on screen instead of closing it.
	if (outcome === 'deleted' || outcome === 'saved') closeTransaction()
}

async function handleSubmit() {
	if (!sheet.value) return
	submissionEvidenceErrors.value = []
	try {
		await sheetStore.submitCurrentSheet(sheetId.value, sheet.value.version)
	} catch (error: unknown) {
		if (!(error instanceof ApiError) || error.status !== 422) return
		const evidenceErrors = parseEvidenceFieldErrors(error.body.fieldErrors)
		if (evidenceErrors.length === 0) return
		// The server's refusal is authoritative. Keep every named transaction in one summary and move
		// focus to it, rather than rendering a generic failure and leaving the purchaser to search.
		submissionEvidenceErrors.value = evidenceErrors
		await nextTick()
		submissionEvidenceSummary.value?.focus()
	}
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

const tabIcons: Record<WorkspaceTab, string> = {
	transactions: mdiFileDocumentOutline,
	approvals: mdiCheckCircleOutline,
	overview: mdiInformationOutline,
	history: mdiHistory
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
	// The transaction surface is a sibling dialog and owns its own keys. A bubbled event must not
	// close the sheet underneath it even if the inert attribute is unsupported by a test renderer.
	if (surfaceOpen.value) return
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
	// T080: the workspace is behind the edit modal; only the edit dialog may close itself.
	if (backgrounded.value) return
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
	if (!backgrounded.value) dialogRef.value?.focus()
})

onUnmounted(() => { sheetStore.clearCurrentSheet() })

// T080: closing the edit modal returns this workspace to the foreground; it takes focus back from
// the unmounted dialog. The component stayed mounted, so `onMounted` cannot do it.
watch(backgrounded, async (inBackground) => {
	if (inBackground) return
	await nextTick()
	dialogRef.value?.focus()
})

watch(sheetId, (id) => {
	activeTab.value = rememberedTab(id)
	loadSheet(id)
})

/* ------------------------------------------------------------------ *
 * Transaction table (T036)
 * ------------------------------------------------------------------ */

/**
 * The approved workspace table. Stage and evidence are transaction facts, but they are not decisions
 * the purchaser takes from this list; nine columns forced every chip to wrap and pushed the status
 * column under the actions. The transaction's own detail surface still carries both facts.
 */
const transactionHeaders = [
	{ key: 'index', label: 'transactions.index' },
	{ key: 'expenseDate', label: 'transactions.expenseDate' },
	{ key: 'description', label: 'transactions.description' },
	{ key: 'costCenter', label: 'transactions.costCenter' },
	{ key: 'expenseType', label: 'transactions.expenseType' },
	{ key: 'amount', label: 'transactions.amount' },
	{ key: 'status', label: 'transactions.status' },
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

function transactionCell(item: TransactionSummary, column: { key: string }, index: number): VNode {
	switch (column.key) {
	case 'index':
		return h('span', { class: 'row-index' }, String(index + 1))
	case 'expenseDate':
		return h('span', { 'data-test': 'transaction-expense-date' }, formatDate(item.activeRevision.expenseDate))
	case 'description':
		// The full value stays in the DOM and in the hover title, so the column can stay compact.
		return h('span', {
			class: 'cell-ellipsis',
			'data-test': 'transaction-description',
			title: item.activeRevision.description ?? '',
		}, item.activeRevision.description ?? '')
	case 'costCenter':
		return h('span', { class: 'entity-cell' }, [
			h(NcIconSvgWrapper, { path: mdiFolderOutline, size: 14, 'aria-hidden': true }),
			// The name is also the hover title: the reference's narrow column may ellipsize it, but
			// the complete value must remain reachable without leaving the row.
			h('span', { class: 'entity-cell__text', title: item.activeRevision.costCenter.name }, item.activeRevision.costCenter.name),
		])
	case 'expenseType':
		return h('span', { class: 'type-pill' }, [
			h(NcIconSvgWrapper, { path: mdiTagOutline, size: 12, 'aria-hidden': true }),
			h('span', { class: 'entity-cell__text', title: item.activeRevision.expenseType.name }, item.activeRevision.expenseType.name),
		])
	case 'amount':
		return h('span', { class: 'amount amount--strong', 'data-test': 'transaction-amount' }, formatMoney(item.activeRevision.amount))
	case 'status': {
		// D-07: these two published values cannot occur; no label is invented for them.
		if (UNREACHABLE_STATUSES.has(item.status)) {
			return h('span', { 'data-test': 'transaction-status-badge' }, '—')
		}
		// The shared badge owns the transaction-status vocabulary, its variant colours and its
		// no-close chip, so the workspace cannot drift from the list. The table asks for the
		// compact stage label so the chip holds one line; the badge hangs the full label on the
		// chip as its hover title.
		const compactKey = workspaceStatusLabels[item.status]
		return h(StatusBadge, {
			status: item.status,
			label: compactKey ? t('pettycash', compactKey) : undefined,
			'data-test': 'transaction-status-badge'
		})
	}
	case 'actions':
		// Only the read action is offered: `TransactionSummary` carries no capability projection,
		// so edit cannot be claimed from the workspace payload without inventing a permission.
		return h(
			NcButton,
			{
				variant: 'tertiary',
				'data-test': 'view-transaction',
				'data-transaction-id': item.id,
				'aria-label': t('pettycash', 'common.view'),
				onClick: () => openTransaction(item.id),
			},
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

/** Resolve a 422's transaction id to the same label the readiness list uses. */
function submissionEvidenceLabel(evidenceError: EvidenceFieldError): string {
	const index = sheetTransactions.value.findIndex((item) => item.id === evidenceError.transactionId)
	const item = index >= 0 ? sheetTransactions.value[index] : undefined
	return item ? transactionLabel(item, index) : evidenceError.transactionId
}

/**
 * T057: the readiness surface now names the receipt condition the server enforces.
 *
 * `evidenceRequired` is published by the server from the active expense type's current setting, and
 * `evidenceCount` is the active count. The client does not re-derive either. The blocker is skipped
 * entirely when the server says no receipt is required, so a receipt-exempt transaction is never
 * asked for one. The Submit control stays reachable in every case; this is guidance, and the 422 is
 * the authoritative backstop (FR-091).
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
		if (item.evidenceRequired === true && item.evidenceCount === 0) {
			missing.push(t('pettycash', 'readiness.missingReceipt'))
		}
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

/** T069: a sheet whose every transaction is still a draft has no official history to show. */
const allTransactionsAreDrafts = computed(() =>
	sheetTransactions.value.length > 0
	&& sheetTransactions.value.every((item) => item.status === 'DRAFT'))

const historyTransactions = computed(() => sheetTransactions.value.map((item, index) => ({
	id: item.id,
	label: transactionLabel(item, index),
	status: item.status
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
			:role="nonModal ? undefined : 'dialog'"
			:aria-modal="nonModal ? undefined : 'true'"
			:aria-hidden="nonModal ? 'true' : undefined"
			:inert="nonModal ? true : undefined"
			:aria-labelledby="TITLE_ID"
			tabindex="-1"
			data-test="sheet-workspace"
			@keydown="onDialogKeydown"
		>
			<header class="workspace-header" data-test="sheet-header">
				<div class="workspace-header__identity">
					<div class="workspace-title-row">
						<h2 :id="TITLE_ID" class="workspace-header__title" data-test="sheet-title">
							{{ sheet?.title ?? '' }}
						</h2>
						<NcButton
							v-if="!isSubmitted"
							variant="tertiary"
							class="workspace-icon-button"
							:aria-label="t('pettycash', 'sheets.edit')"
							data-test="edit-sheet-btn"
							@click="goToEdit"
						>
							<NcIconSvgWrapper :path="mdiPencil" :size="18" />
						</NcButton>
						<StatusBadge
							v-if="sheetStatusKey"
							:status="sheet?.status ?? 'OPEN'"
							:data-status="sheet?.status ?? 'OPEN'"
							class="workspace-status"
							data-test="sheet-status"
						/>
						<span
							v-if="sheetStatusKey"
							class="workspace-status-info"
							aria-hidden="true"
							:title="statusInfoText"
						>
							<NcIconSvgWrapper :path="mdiInformationOutline" :size="16" aria-hidden="true" />
						</span>
						<span v-if="isSubmitted" class="workspace-readonly" data-test="read-only-badge">
							<NcIconSvgWrapper :path="mdiLock" :size="13" aria-hidden="true" />
							<span>{{ t('pettycash', 'common.readOnly') }}</span>
						</span>
					</div>
					<span class="workspace-id" data-test="sheet-id">
						<NcIconSvgWrapper :path="mdiWalletOutline" :size="14" class="workspace-id__icon" aria-hidden="true" />
						<span>{{ sheet?.id ?? '' }}</span>
					</span>
				</div>

				<div class="workspace-header__actions">
					<NcButton
						v-if="isOpen"
						variant="tertiary"
						class="workspace-icon-button"
						:aria-label="t('pettycash', 'common.delete')"
						data-test="delete-sheet-btn"
						@click="askDelete"
					>
						<NcIconSvgWrapper :path="mdiDelete" :size="18" />
					</NcButton>
					<NcButton
						variant="tertiary"
						:aria-label="t('pettycash', 'common.close')"
						data-test="close-sheet-workspace"
						@click="requestClose"
					>
						<NcIconSvgWrapper :path="mdiClose" :size="22" />
					</NcButton>
				</div>
			</header>

			<div class="workspace-summary">
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
					<div class="workspace-fact workspace-fact--count">
						<dt>{{ t('pettycash', 'sheets.transactionCount') }}</dt>
						<dd>{{ sheetTransactions.length }}</dd>
					</div>
				</dl>

				<div class="workspace-cards">
					<div class="workspace-figure workspace-figure--recorded" data-test="workspace-recorded-total">
						<span class="workspace-figure__icon" aria-hidden="true">
							<NcIconSvgWrapper :path="mdiWalletOutline" :size="20" />
						</span>
						<span class="workspace-figure__body">
							<span class="workspace-figure__label">{{ t('pettycash', 'workspace.recordedAmount') }}</span>
							<strong class="workspace-figure__value workspace-figure__value--success">{{ formatMoney(sheet?.totals.current) }}</strong>
							<span class="workspace-figure__caption">{{ t('pettycash', 'workspace.recordedCaption') }}</span>
						</span>
					</div>
					<div
						class="workspace-figure"
						:class="{ 'workspace-figure--approved': approvedTotalAmount > 0 }"
						data-test="workspace-approved-total"
					>
						<span class="workspace-figure__icon" aria-hidden="true">
							<NcIconSvgWrapper :path="mdiCheckCircle" :size="20" />
						</span>
						<span class="workspace-figure__body">
							<span class="workspace-figure__label">{{ t('pettycash', 'workspace.latestApproved') }}</span>
							<strong
								class="workspace-figure__value"
								:class="approvedTotalAmount > 0 ? 'workspace-figure__value--primary' : undefined"
							>
								{{ formatMoney(sheet?.totals.effectiveApproved) }}
							</strong>
							<span class="workspace-figure__caption">{{ t('pettycash', 'workspace.approvedCaption') }}</span>
						</span>
					</div>
					<div
						v-if="rejectedTotalAmount > 0"
						class="workspace-figure workspace-figure--rejected"
						data-test="workspace-rejected-total"
					>
						<span class="workspace-figure__icon" aria-hidden="true">
							<NcIconSvgWrapper :path="mdiCloseCircleOutline" :size="20" />
						</span>
						<span class="workspace-figure__body">
							<span class="workspace-figure__label">{{ t('pettycash', 'workspace.rejectedAmount') }}</span>
							<strong class="workspace-figure__value workspace-figure__value--error">{{ formatMoney(sheet?.totals.effectiveRejected) }}</strong>
							<span class="workspace-figure__caption">{{ t('pettycash', 'workspace.rejectedCaption') }}</span>
						</span>
					</div>
				</div>
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
			<!-- T058: the 422's named transactions are one announced summary. When they exist, the
			     generic message is not repeated beside them: that would present the same refusal twice
			     and leave the actionable names in a second, quieter block. -->
			<div
				v-if="submissionEvidenceErrors.length > 0"
				ref="submissionEvidenceSummary"
				class="alert alert--error submission-evidence-errors"
				role="alert"
				aria-live="assertive"
				tabindex="-1"
				data-test="submission-evidence-errors"
			>
				<strong>{{ t('pettycash', 'readiness.submissionEvidenceBlocked') }}</strong>
				<ul class="readiness__list">
					<li
						v-for="evidenceError in submissionEvidenceErrors"
						:key="evidenceError.transactionId"
						data-test="submission-evidence-error"
					>
						{{ submissionEvidenceLabel(evidenceError) }} — {{ evidenceError.messages.join(' ') }}
					</li>
				</ul>
				<button
					type="button"
					:aria-label="t('pettycash', 'common.dismiss')"
					@click="submissionEvidenceErrors = []; sheetStore.clearError()"
				>
					×
				</button>
			</div>

			<div
				v-else-if="sheetStore.error"
				class="alert alert--error submission-errors"
				role="alert"
				aria-live="assertive"
				data-test="submission-errors"
			>
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
						@keydown="onTabKeydown($event, index)"
					>
						<NcIconSvgWrapper :path="tabIcons[tab]" :size="18" aria-hidden="true" />
						<span>{{ t('pettycash', tabLabels[tab]) }}</span>
						<span v-if="tab === 'transactions'" class="workspace-tab__count">({{ sheetTransactions.length }})</span>
					</button>
				</div>

				<section
					v-show="activeTab === 'transactions'"
					:id="tabPanelId('transactions')"
					role="tabpanel"
					:aria-labelledby="tabButtonId('transactions')"
					tabindex="0"
					data-test="transactions-panel"
				>
					<div class="transactions-bar">
						<NcButton
							v-if="!isSubmitted"
							variant="tertiary"
							class="add-transaction-btn"
							:aria-label="t('pettycash', 'transactions.add')"
							data-test="create-transaction-btn"
							@click="goToCreateTransaction"
						>
							<NcIconSvgWrapper :path="mdiPlus" :size="16" aria-hidden="true" />
							{{ t('pettycash', 'transactions.add') }}
						</NcButton>
					</div>
					<div data-test="transaction-list">
						<p v-if="sheetStore.detailLoadingId === sheetId && sheetTransactions.length === 0" class="workspace-state" data-test="transaction-loading">
							{{ t('pettycash', 'common.loading') }}
						</p>
						<ResponsiveTable
							class="workspace-table"
							:columns="transactionColumns"
							:rows="sheetTransactions"
							:cell="transactionCell"
							:row-key="transactionRowKey"
							:caption="t('pettycash', 'sheets.transactions')"
							:loading="sheetStore.detailLoadingId === sheetId"
							test-id="transaction-table"
							test-id-prefix="sheet-transaction"
							row-test-id="sheet-transaction-row"
							:card-row-test-id="sheetTransactionCardId"
						/>
					</div>

					<section v-if="isOpen" class="readiness" data-test="readiness" aria-labelledby="readiness-title">
						<span class="readiness__icon" aria-hidden="true">
							<NcIconSvgWrapper :path="mdiInformationOutline" :size="20" />
						</span>
						<div class="readiness__body">
							<p class="readiness__open" data-test="workspace-open-hint">
								{{ t('pettycash', 'workspace.openHint') }}
							</p>
							<h3 id="readiness-title" class="readiness__title">{{ t('pettycash', 'readiness.title') }}</h3>
							<div v-if="readinessBlockers.length > 0">
								<p class="readiness__lead">{{ t('pettycash', 'readiness.blocked') }}</p>
								<ul class="readiness__list">
									<li
										v-for="blocker in readinessBlockers"
										:key="blocker"
										class="readiness__blocker"
										data-test="readiness-blocker"
									>
										{{ blocker }}
									</li>
								</ul>
							</div>
							<p v-else class="readiness__ready" data-test="readiness-ready">
								{{ t('pettycash', 'readiness.ready') }}
							</p>
							<p class="readiness__note">{{ t('pettycash', 'readiness.submitHint') }}</p>
						</div>
					</section>
				</section>

				<section
					v-show="activeTab === 'approvals'"
					:id="tabPanelId('approvals')"
					role="tabpanel"
					:aria-labelledby="tabButtonId('approvals')"
					tabindex="0"
					data-test="approvals-panel"
				>
					<p class="workspace-hint">{{ t('pettycash', 'workspace.approvalsIntro') }}</p>
					<dl class="stage-grid">
						<div v-for="figure in stageFigures" :key="figure.stage" class="stage-card" data-test="approval-stage">
							<dt class="stage-card__label">{{ t('pettycash', stageKey(figure.stage)) }}</dt>
							<dt class="stage-card__caption">{{ t('pettycash', 'workspace.approvedSoFar') }}</dt>
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
					data-test="overview-panel"
				>
					<div class="overview-grid">
						<section class="overview-card" data-test="sheet-key-dates" aria-labelledby="overview-info-title">
							<h3 id="overview-info-title" class="overview-card__title">{{ t('pettycash', 'workspace.sheetInformation') }}</h3>
							<dl class="workspace-metadata">
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
							</dl>
						</section>

						<NcNoteCard class="overview-notchecard" type="info" :heading="t('pettycash', 'sheets.totals')" data-test="sheet-totals">
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
					</div>
				</section>

				<section
					v-show="activeTab === 'history'"
					:id="tabPanelId('history')"
					role="tabpanel"
					:aria-labelledby="tabButtonId('history')"
					tabindex="0"
					data-test="history-panel"
				>
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

					<p
						v-if="allTransactionsAreDrafts && !history.loading.value"
						class="workspace-hint"
						data-test="history-working-draft"
					>
						{{ t('pettycash', 'workspace.historyDraftOnly') }}
					</p>

					<p
						v-if="!allTransactionsAreDrafts && (history.hasLoaded.value || history.tracked.value === 0) && !history.loading.value && history.merged.value.length === 0 && history.errors.value.length === 0"
						class="workspace-hint"
						data-test="history-empty"
					>
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
							@click="history.loadMore(feed.transactionId)"
						>
							{{ t('pettycash', 'workspace.historyLoadMore') }} — {{ feed.label }}
						</NcButton>
					</div>
				</section>

				<footer class="workspace-footer">
					<span
						v-if="isSubmitted && sheet?.submittedAt"
						class="workspace-footer__caption"
						data-test="workspace-already-submitted"
					>
						{{ t('pettycash', 'workspace.alreadySubmitted', { date: formatDate(sheet.submittedAt) }) }}
					</span>
					<div class="workspace-footer__actions">
						<NcButton variant="secondary" data-test="close-sheet-workspace-footer" @click="requestClose">
							{{ t('pettycash', 'common.close') }}
						</NcButton>
						<NcButton
							v-if="sheetStore.canSubmit"
							variant="primary"
							:loading="sheetStore.mutationLoading"
							data-test="submit-sheet-button"
							@click="handleSubmit"
						>
							<NcIconSvgWrapper :path="mdiSend" :size="16" aria-hidden="true" />
							{{ t('pettycash', 'sheets.submit') }}
						</NcButton>
					</div>
				</footer>
			</template>

			<DeleteSheetDialog
				:sheet-id="sheet?.id ?? ''"
				:sheet-title="sheet?.title ?? ''"
				:open="deleteDialogOpen"
				:busy="sheetStore.mutationLoading"
				@cancel="deleteDialogOpen = false"
				@confirm="confirmDeleteFromDetail"
			/>
		</section>

		<!--
			T066: a direct `GET /transactions/{id}` surface. It is a **sibling** of the workspace
			dialog, not a child: while it is open the workspace section is inert, and an inert ancestor
			would make the surface inert too. That was the first implementation; this browser evidence
			found every control on the surface unclickable because the surface sat inside the inert
			section. The page still exposes exactly one dialog.
		-->
		<TransactionSurface
			v-if="surfaceOpen"
			:transaction-id="surfaceTransactionId ?? ''"
			:sheet-id="sheetId"
			:sheet-status="sheet?.status ?? null"
			:start-in-edit="surfaceEdit"
			@close="closeTransaction"
			@refresh="handleSurfaceRefresh"
		/>
	</div>
</template>

<style scoped>
/*
 * The approved workspace is one centered surface over the list. As in the list view, this screen
 * keys its primary actions to its own green: the brand tokens are defined once here and re-keyed
 * onto the Nextcloud tokens that every shared component reads.
 */
.sheet-workspace {
	position: fixed;
	inset-block: var(--header-height, 50px) 0;
	inset-inline: 0;
	z-index: 1500;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 1rem;
	font-size: var(--font-size-floor);

	--pettycash-brand: #008c67;
	--pettycash-brand-hover: #007b5a;
	--color-primary-element: var(--pettycash-brand);
	--color-primary-element-hover: var(--pettycash-brand-hover);
	--color-primary-element-text: #fff;
}

.sheet-workspace__scrim {
	position: absolute;
	inset: 0;
	background-color: var(--pettycash-scrim, color-mix(in srgb, black 55%, transparent));
}

/*
 * Centered dialog, ~80% of the viewport at desktop widths, with internal scroll. The
 * proportion matches the approved reference: generous side margins, rounded corners.
 */
.sheet-workspace__dialog {
	position: relative;
	inline-size: min(100%, 72rem);
	max-block-size: 92vh;
	overflow: clip auto;
	padding: 1.75rem 2rem;
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: 0 8px 40px var(--color-box-shadow);
}

/* The dialog itself is focused programmatically when it opens. The shared `[tabindex='-1']`
   exclusion owns the absence of a ring around the whole sheet; controls inside keep theirs. */
.sheet-workspace__dialog :focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

/*
 * Header: title with the edit pencil beside it, the status pill and ⓘ on the same line, the
 * sheet id below, and the action pair pushed to the inline end.
 */
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
	gap: 0.3rem;
	min-inline-size: 0;
}

.workspace-title-row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.55rem;
}

.workspace-header__title {
	margin: 0;
	font-size: 1.75rem;
	font-weight: 700;
	line-height: 1.2;
}

.workspace-header__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.4rem;
}

.workspace-icon-button {
	min-inline-size: 2.5rem;
	padding-inline: 0.4rem;
}

/*
 * The header status chip is the shared StatusBadge, drawn as the approved dot pill: leading dot,
 * uppercase, one line. It must stay single-line at the header, where any wrapping reads broken.
 * The approved references colour the pill by movement: an open sheet is the workspace green
 * (reference 02) while an in-review pill keeps Nextcloud's primary blue (reference 03) — the
 * status vocabulary's `primary` variant is re-keyed by state, not hardcoded.
 */
.workspace-title-row :deep(.workspace-status) {
	border-radius: 999px;
	letter-spacing: 0.02em;
	text-transform: uppercase;
	font-weight: 600;
	white-space: nowrap;
	max-inline-size: 100%;
}

.workspace-title-row :deep(.workspace-status)::before {
	content: '';
	flex: 0 0 auto;
	inline-size: 7px;
	block-size: 7px;
	border-radius: 50%;
	background-color: currentcolor;
}

.workspace-title-row :deep(.workspace-status.nc-chip--primary) {
	background-color: color-mix(in srgb, var(--color-primary) 12%, transparent);
	border-color: color-mix(in srgb, var(--color-primary) 22%, transparent);
	color: var(--color-primary);
}

.workspace-title-row :deep(.workspace-status.nc-chip--primary[data-status='OPEN']) {
	background-color: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
	border-color: color-mix(in srgb, var(--color-primary-element) 22%, transparent);
	color: var(--color-primary-element);
}

.workspace-status-info {
	display: inline-flex;
	align-items: center;
	color: var(--color-text-maxcontrast);
}

/*
 * The read-only pill is a plain span rather than a chip, so the lock icon and its label share one
 * baseline: the previous chip stacked them vertically because the chip wrapped its slot.
 */
.workspace-readonly {
	display: inline-flex;
	align-items: center;
	gap: 0.3rem;
	padding: 0.2rem 0.7rem;
	border: 1px solid color-mix(in srgb, var(--color-text-maxcontrast) 25%, transparent);
	border-radius: 999px;
	background-color: color-mix(in srgb, var(--color-text-maxcontrast) 8%, var(--color-main-background));
	color: var(--color-text-maxcontrast);
	font-weight: 600;
	letter-spacing: 0.02em;
	text-transform: uppercase;
	white-space: nowrap;
}

.workspace-id {
	display: inline-flex;
	align-items: center;
	gap: 0.35rem;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.workspace-id :deep(svg),
.workspace-id__icon {
	flex: 0 0 auto;
	opacity: 0.85;
}

/*
 * Summary strip: the facts (Currency, Owner, First submitted, Transactions) sit in one row with
 * hairline dividers, matching the approved reference; the financial cards sit below them.
 */
.workspace-summary {
	display: grid;
	grid-template-columns: 1fr;
	gap: 1rem;
	margin-block: 0.25rem 1.25rem;
	padding: 0;
	border: 0;
	background-color: transparent;
}

.workspace-facts {
	display: grid;
	grid-template-columns: 1fr;
	gap: 0.6rem 1rem;
	margin: 0;
}

.workspace-fact {
	display: flex;
	flex-direction: column;
	gap: 0.15rem;
	min-inline-size: 0;
}

/* Nextcloud's own stylesheet pins `dt` to a 130px end-aligned box; the strip needs the labels to
   read start-aligned above their values. */
.workspace-fact dt {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	text-align: start;
	inline-size: auto;
	max-inline-size: none;
}

.workspace-fact dd {
	margin: 0;
	font-weight: 600;
	overflow-wrap: anywhere;
}

/*
 * Transactions count is the strip's headline figure, exactly like the reference's large number.
 */
.workspace-fact--count dd {
	font-size: 1.5rem;
	font-weight: 700;
	line-height: 1.1;
	font-variant-numeric: tabular-nums;
}

/*
 * The financial figures are tinted cards, not plain boxes: the recorded card is success-tinted,
 * the approved card follows the reference's blue accent, the rejected card appears with an error
 * tint only when a non-zero amount exists to report.
 */
.workspace-cards {
	display: grid;
	grid-template-columns: 1fr;
	gap: 0.9rem;
}

.workspace-figure {
	display: flex;
	align-items: flex-start;
	gap: 0.75rem;
	padding: 1rem 1.1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-background-hover);
}

.workspace-figure__icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: 0 0 auto;
	inline-size: 2.25rem;
	block-size: 2.25rem;
	border-radius: var(--border-radius);
	background-color: color-mix(in srgb, var(--color-text-maxcontrast) 10%, var(--color-main-background));
	color: var(--color-text-maxcontrast);
}

.workspace-figure__body {
	display: flex;
	flex-direction: column;
	gap: 0.1rem;
	min-inline-size: 0;
}

.workspace-figure__label {
	color: var(--color-text-maxcontrast);
}

.workspace-figure__value {
	font-size: 1.5rem;
	line-height: 1.25;
	font-variant-numeric: tabular-nums;
}

.workspace-figure__caption {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.workspace-figure--recorded {
	border-color: color-mix(in srgb, var(--color-success) 30%, transparent);
	background-color: color-mix(in srgb, var(--color-success) 9%, var(--color-main-background));
}

.workspace-figure--recorded .workspace-figure__icon {
	background-color: color-mix(in srgb, var(--color-success) 16%, var(--color-main-background));
	color: var(--color-success-text, var(--color-success));
}

.workspace-figure__value--success {
	color: var(--color-success-text, var(--color-success));
}

/* The approved figure keeps Nextcloud's static primary blue -- the reference colours it differently
   from the workspace's green brand, and `--color-primary` still resolves to blue under the re-key. */
.workspace-figure--approved {
	border-color: color-mix(in srgb, var(--color-primary) 30%, transparent);
	background-color: color-mix(in srgb, var(--color-primary) 8%, var(--color-main-background));
}

.workspace-figure--approved .workspace-figure__icon {
	background-color: color-mix(in srgb, var(--color-primary) 14%, var(--color-main-background));
	color: var(--color-primary);
}

.workspace-figure__value--primary {
	color: var(--color-primary);
}

.workspace-figure--rejected {
	border-color: color-mix(in srgb, var(--color-error) 30%, transparent);
	background-color: color-mix(in srgb, var(--color-error) 9%, var(--color-main-background));
}

.workspace-figure--rejected .workspace-figure__icon {
	background-color: color-mix(in srgb, var(--color-error) 16%, var(--color-main-background));
	color: var(--color-error-text, var(--color-error));
}

.workspace-figure__value--error {
	color: var(--color-error-text, var(--color-error));
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

/*
 * Tabs. The selected tab takes the workspace green with a thicker underline; the count shares the
 * tab's colour. The row stays sticky so the tabs remain reachable while the dialog scrolls.
 */
.workspace-tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem;
	margin-block-end: 1rem;
	border-block-end: 1px solid var(--color-border);
	position: sticky;
	inset-block-start: 0;
	z-index: 2;
	background-color: var(--color-main-background);
}

.workspace-tab {
	display: inline-flex;
	align-items: center;
	gap: 0.45rem;
	min-block-size: 2.75rem;
	padding: 0.5rem 0.9rem;
	border: 0;
	border-block-end: 3px solid transparent;
	background: none;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	cursor: pointer;
	white-space: nowrap;
}

.workspace-tab:hover {
	color: var(--color-main-text);
	background-color: var(--color-background-hover);
}

.workspace-tab--active {
	border-block-end-color: var(--color-primary-element);
	color: var(--color-primary-element);
	font-weight: 600;
}

.workspace-tab--active :deep(svg) {
	color: var(--color-primary-element);
}

/* The selected tab is already identified by its green underline; a hover fill on it reads like a
   second selected state, which is exactly what made the captured tabs look inconsistent. The
   chosen tab keeps the underline and takes no fill. */
.workspace-tab--active:hover {
	background-color: transparent;
}

/* Keyboard focus keeps an explicit, consistent indicator. Nextcloud's core stylesheet fills every
   plain button's focus with a tint (button:not(.button-vue, …):focus), which is what made the
   captured tabs colour a mouse click like a second selected state: the workspace paints no fill on
   either focus path, shows the outline only for keyboard focus, and keeps underline-only selected. */
.workspace-tab:focus-visible {
	background-color: transparent;
	outline: 2px solid var(--color-primary-element);
	outline-offset: -2px;
	border-radius: var(--border-radius);
}

.workspace-tab:focus:not(:focus-visible) {
	background-color: transparent;
	outline: none;
}

.workspace-tab__count {
	margin-inline-start: 0.15rem;
	color: inherit;
	opacity: 0.8;
}

/*
 * The panel action bar: the "Add transaction" affordance lives on the table it belongs to, in the
 * reference's spot at the inline end. The green is the workspace brand.
 */
.transactions-bar {
	display: flex;
	justify-content: flex-end;
	margin-block-end: 0.35rem;
}

.add-transaction-btn {
	color: var(--color-primary-element);
	font-weight: 600;
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
 * Workspace table. Compact single-line rows: fixed layout, bounded columns, elliptical display
 * cells, one-line status pill, action cell without vertical padding. The narrow card layout is
 * deliberately exempted from the single-line rules so long values reflow at 390px.
 */
.workspace-table :deep(table) {
	inline-size: 100%;
	table-layout: fixed;
}

.workspace-table :deep(thead th:nth-child(1)) {
	inline-size: 5%;
}

.workspace-table :deep(thead th:nth-child(2)) {
	inline-size: 11%;
}

.workspace-table :deep(thead th:nth-child(3)) {
	inline-size: 18%;
}

.workspace-table :deep(thead th:nth-child(4)) {
	inline-size: 14%;
}

.workspace-table :deep(thead th:nth-child(5)) {
	inline-size: 12%;
}

.workspace-table :deep(thead th:nth-child(6)) {
	inline-size: 12%;
}

.workspace-table :deep(thead th:nth-child(7)) {
	inline-size: 19%;
}

.workspace-table :deep(thead th:nth-child(8)) {
	inline-size: 9%;
}

.workspace-table :deep(thead) {
	background-color: var(--color-background-dark);
}

.workspace-table :deep(thead th) {
	padding: 0.55rem 0.6rem;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
	white-space: nowrap;
}

.workspace-table :deep(td) {
	padding: 0.55rem 0.6rem;
	border-block-start: 1px solid var(--color-border);
	vertical-align: middle;
}

.workspace-table :deep(tbody tr:hover) {
	background-color: var(--color-background-hover);
}

.workspace-table :deep(.responsive-table__desktop .cell-ellipsis) {
	display: inline-block;
	max-inline-size: 100%;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.workspace-table :deep(.entity-cell) {
	display: flex;
	align-items: center;
	gap: 0.3rem;
	min-inline-size: 0;
}

.workspace-table :deep(.entity-cell svg) {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	opacity: 0.8;
}

/* The desktop table keeps every fact on one line; the narrow card wraps. The ellipsis needs the
   flex item to be allowed to shrink below its content width, or a long name still spills. */
.workspace-table :deep(.responsive-table__desktop .entity-cell__text) {
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	min-inline-size: 0;
}

/*
 * The row-index number is muted; the render-function nodes carry ResponsiveTable's scope id rather
 * than this view's, so every cell-internal rule must anchor on this view's scoped wrapper.
 */
.workspace-table :deep(.row-index) {
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.workspace-table :deep(.type-pill) {
	display: inline-flex;
	align-items: center;
	gap: 0.3rem;
	padding: 0.2rem 0.6rem;
	border-radius: 999px;
	background-color: color-mix(in srgb, var(--color-text-maxcontrast) 10%, var(--color-main-background));
	color: var(--color-main-text);
	max-inline-size: 100%;
	min-inline-size: 0;
	overflow: hidden;
}

.workspace-table :deep(.type-pill svg) {
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
	opacity: 0.8;
}

.workspace-table :deep(.responsive-table__desktop .type-pill) {
	white-space: nowrap;
}

/*
 * Money in the workspace table is the outcome colour, bold and tabular, as the reference prints it.
 * The render-function nodes carry ResponsiveTable's scope id, so the rule anchors on the wrapper.
 */
.workspace-table :deep(.amount) {
	color: var(--color-success-text, var(--color-success));
	font-weight: 700;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

/*
 * The status pill in the table is the same dot pill as the list and the header. The wrapping that
 * produced three-line ovals came from the shared chip; single-line it here, ellipsising the label
 * on the desktop layout only.
 */
.workspace-table :deep(.status-badge) {
	border-radius: 999px;
	font-weight: 600;
	max-inline-size: 100%;
}

.workspace-table :deep(.status-badge)::before {
	content: '';
	flex: 0 0 auto;
	inline-size: 7px;
	block-size: 7px;
	border-radius: 50%;
	background-color: currentcolor;
}

/* The table keeps the shared chip's variant colours as the approved list prints them: `primary`
   statuses stay Nextcloud blue, outcomes keep their own success/error tints. */
.workspace-table :deep(.status-badge.nc-chip--primary) {
	background-color: color-mix(in srgb, var(--color-primary) 12%, transparent);
	border-color: color-mix(in srgb, var(--color-primary) 22%, transparent);
	color: var(--color-primary);
}

.workspace-table :deep(.responsive-table__desktop .status-badge) {
	white-space: nowrap;
}

.workspace-table :deep(.responsive-table__desktop .status-badge .nc-chip__text) {
	display: inline-block;
	max-inline-size: 100%;
	min-inline-size: 0;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	vertical-align: middle;
}

/* `NcNoteCard`'s slot div is a shrink-to-fit flex item; let the totals card fill its width. */
:deep(.notecard > div) {
	min-inline-size: 0;
	flex: 1 1 auto;
}

/*
 * The readiness block draws as the approved panel's info banner: tinted, rounded, with the leading
 * icon. The open-sheet hint is the banner's first line, so the previous duplicated paragraph and
 * the "unnecessary whitespace" complaint share one fix.
 */
.readiness {
	display: flex;
	align-items: flex-start;
	gap: 0.75rem;
	margin-block: 0.75rem;
	padding: 0.9rem 1.1rem;
	border: 1px solid color-mix(in srgb, var(--color-info, var(--color-primary-element)) 30%, transparent);
	border-radius: var(--border-radius-small);
	background-color: color-mix(in srgb, var(--color-info, var(--color-primary-element)) 7%, var(--color-main-background));
}

.readiness__icon {
	display: inline-flex;
	flex: 0 0 auto;
	color: var(--color-info, var(--color-primary-element));
}

.readiness__body {
	display: flex;
	flex-direction: column;
	gap: 0.3rem;
	min-inline-size: 0;
}

.readiness__open {
	margin: 0;
	font-weight: 700;
}

.readiness__title {
	margin: 0;
	font-size: var(--font-size-floor);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.readiness__lead {
	margin: 0;
	font-weight: 600;
}

.readiness__list {
	margin: 0;
	padding-inline-start: 1.25rem;
}

.readiness__blocker {
	margin-block: 0.2rem;
}

.readiness__ready {
	margin: 0;
	color: var(--color-success-text, var(--color-success));
}

.readiness__note {
	margin-block: 0.25rem 0;
	color: var(--color-text-maxcontrast);
}

/*
 * Approvals tab: three stage tiles, each naming its stage and reporting the figure the server
 * publishes for it. Per-stage counts stay impossible without the server aggregation.
 */
.stage-grid {
	display: grid;
	grid-template-columns: 1fr;
	gap: 0.9rem;
	margin-block: 0.75rem;
}

.stage-card {
	display: flex;
	flex-direction: column;
	gap: 0.15rem;
	padding: 1rem 1.1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
}

/* Nextcloud's own stylesheet pins every `dt` into a 130px end-aligned box (the same core rule the
   strip labels needed) — the stage tiles need their label and caption start-aligned above the
   figure, and no inherited `dd` margins opening the vertical rhythm back up. */
.stage-card dt,
.stage-card dd {
	margin: 0;
	text-align: start;
	inline-size: auto;
	max-inline-size: none;
}

.stage-card__label {
	color: var(--color-main-text);
	font-weight: 600;
}

.stage-card__caption {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.stage-card__value {
	margin: 0;
	font-size: var(--font-size-emphasis);
	font-weight: 700;
	color: var(--color-success-text, var(--color-success));
	font-variant-numeric: tabular-nums;
}

/*
 * Overview tab: the sheet's own facts read as the reference's information card (label / value
 * rows with dividers), and the totals fill the second card as tinted tiles.
 */

/* The history decision value stays bold on its own terms; placed before the overview rules so no
   descending-specificity ordering complaint is introduced by their shared `dd` element. */
.history-event__decision dd {
	margin: 0;
	font-weight: bold;
}

.overview-grid {
	display: grid;
	grid-template-columns: 1fr;
	gap: 0.9rem;
	margin-block: 0.25rem 0;
	align-items: start;
}

.overview-card {
	min-inline-size: 0;
	padding: 1rem 1.1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
}

.overview-card__title {
	margin: 0 0 0.35rem;
	font-size: 1rem;
	font-weight: 700;
}

.workspace-metadata {
	display: flex;
	flex-direction: column;
	margin: 0;
	gap: 0;
}

.workspace-metadata .workspace-fact {
	flex-direction: row;
	align-items: baseline;
	justify-content: space-between;
	gap: 1rem;
	padding-block: 0.5rem;
	border-block-start: 1px solid var(--color-border);
}

.workspace-metadata .workspace-fact:first-child {
	border-block-start: 0;
	padding-block-start: 0.35rem;
}

.workspace-metadata .workspace-fact dt {
	flex: 0 0 auto;
	text-align: start;
	inline-size: auto;
	max-inline-size: none;
}

.workspace-metadata .workspace-fact dd {
	margin: 0;
	min-inline-size: 0;
	text-align: end;
	font-weight: 600;
	overflow-wrap: anywhere;
}

.overview-notchecard {
	min-inline-size: 0;
}

/*
 * The reference prints the overview's second card as plain paper behind a hairline (reference 06,
 * "Sheet Information" / "Transactions by Cost Center"). The shared note-card keeps its info
 * semantics and its heading, but loses the tinted banner chrome and heavy padding. The note-card's
 * stylesheet paints the background through `--note-background` on the card's own root element —
 * the class the template passes lands on that same element — so the colour is re-keyed by
 * overriding that variable rather than by out-competing the property.
 */
.overview-grid :deep(.notecard) {
	--note-background: var(--color-main-background);

	padding: 1rem 1.1rem;
	overflow: hidden;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: none;
}

.totals-grid {
	display: grid;
	grid-template-columns: 1fr;
	gap: 0.75rem;
}

.total-column {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.total-column-label {
	color: var(--color-text-maxcontrast);
}

/*
 * The owner asked the totals area to stop reading dense: every figure is one label/value line with
 * breathing room, and the outcome stack gets the full card width on desktop so its labels never
 * wrap through narrow multi-line tiles. The tinted state hits and value emphasis are unchanged.
 */
.total-chip {
	display: flex;
	flex-direction: row;
	align-items: baseline;
	justify-content: space-between;
	column-gap: 0.8rem;
	padding: 0.55rem 0.85rem;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.total-chip--success { border-inline-start: 3px solid var(--color-success); }
.total-chip--error { border-inline-start: 3px solid var(--color-error); }
.total-chip--warning { border-inline-start: 3px solid var(--color-warning); }
.total-chip--primary { border-inline-start: 3px solid var(--color-info, var(--color-primary-element)); }
.total-chip--secondary { border-inline-start: 3px solid var(--color-primary-element-light, var(--color-primary-element)); }

.total-label {
	color: var(--color-text-maxcontrast);

	/* FR-071's floor is absolute: the label de-emphasises by colour, never by shrinking below 16px. */
	font-size: var(--font-size-floor);
	min-inline-size: 0;
}

.total-value {
	font-weight: 700;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.history-list {
	display: flex;
	flex-direction: column;
	gap: 0.6rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.history-event {
	padding: 0.7rem 0.9rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
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

.history-more {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 0.5rem;
	margin-block-start: 1rem;
}

/*
 * Footer strip: the printed date on the inline start, the action pair on the inline end, behind
 * a hairline that separates the content from the chrome.
 */
.workspace-footer {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 0.75rem;
	margin-block-start: 1.25rem;
	padding-block-start: 1.1rem;
	border-block-start: 1px solid var(--color-border);
}

.workspace-footer__caption {
	color: var(--color-text-maxcontrast);
}

.workspace-footer__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem;
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
	.workspace-cards {
		grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
	}

	.workspace-facts {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}

	.stage-grid,
	.totals-grid {
		grid-template-columns: repeat(2, 1fr);
	}
}

@media (width >= 900px) {
	.workspace-facts {
		grid-template-columns: repeat(4, minmax(0, 1fr));
	}

	/* Hairline dividers between the strip's facts, first one unbordered. */
	.workspace-fact {
		border-inline-start: 1px solid var(--color-border);
		padding-inline-start: 1rem;
	}

	.workspace-fact:first-child {
		border-inline-start: 0;
		padding-inline-start: 0;
	}

	.stage-grid {
		grid-template-columns: repeat(3, 1fr);
	}

	/* The totals figures read best when each one holds a single line: two half-width tiles on the
	   first row, the outcome stack across the full card, and the per-stage figures after it. */
	.totals-grid {
		grid-template-columns: repeat(2, 1fr);
	}

	.totals-grid .total-column,
	.totals-grid > .total-chip:last-child {
		grid-column: 1 / -1;
	}

	.overview-grid {
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}
}

@media (width <= 640px) {
	.sheet-workspace {
		padding: 0;
	}

	.sheet-workspace__dialog {
		inline-size: 100%;
		max-block-size: 100%;
		padding: 0.75rem 1rem 0;
		border-radius: 0;
	}

	.workspace-header {
		gap: 0.5rem;
	}

	.workspace-header__title {
		font-size: 1.35rem;
	}

	.workspace-id {
		display: none;
	}

	.workspace-title-row {
		flex-wrap: nowrap;
		gap: 0.35rem;
	}

	.workspace-header__actions {
		inline-size: 100%;
	}

	.workspace-summary {
		gap: 0.5rem;
		margin-block: 0.5rem 0.75rem;
	}

	.workspace-figure {
		padding: 0.65rem 0.75rem;
	}

	.workspace-figure__value {
		font-size: 1.25rem;
	}

	.workspace-facts {
		grid-template-columns: 1fr 1fr;
		gap: 0.35rem 0.75rem;
	}

	.workspace-tabs {
		display: grid;
		grid-template-columns: repeat(2, minmax(0, 1fr));
		gap: 0;
		margin-block-end: 0.75rem;
	}

	.workspace-tab {
		justify-content: flex-start;
		min-block-size: 2.2rem;
		padding: 0.3rem 0.35rem;
	}

	.workspace-tab :deep(svg) {
		display: none;
	}

	.workspace-footer {
		position: sticky;
		inset-block-end: 0;
		z-index: 3;
		margin-block-start: 0.75rem;
		padding-block: 0.5rem calc(0.5rem + env(safe-area-inset-bottom));
		background-color: var(--color-main-background);
		box-shadow: 0 -1px 0 var(--color-border);
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

<template>
	<Teleport to="body">
		<div v-if="open" class="senior-modal" data-testid="senior-sheet-modal">
			<div
				class="senior-modal__scrim"
				data-testid="senior-modal-scrim"
				@click="onScrim"
			/>

			<div
				ref="dialogEl"
				class="senior-modal__dialog"
				role="dialog"
				aria-modal="true"
				:aria-label="dialogLabel"
				tabindex="-1"
				data-testid="senior-modal-dialog"
			>
				<header class="senior-modal__header">
					<div class="senior-modal__heading">
						<p class="senior-modal__eyebrow">{{ t('pettycash', 'reviews.seniorSheet.modal.eyebrow') }}</p>
						<h3 class="senior-modal__title" data-testid="senior-modal-title">
							{{ sheetTitleLabel }}
						</h3>
						<p class="senior-modal__meta" data-testid="senior-modal-meta">
							<bdi dir="ltr" class="senior-modal__reference" data-testid="senior-modal-reference">
								{{ referenceLabel }}
							</bdi>
							<span class="senior-modal__dot" aria-hidden="true">·</span>
							<span data-testid="senior-modal-owner">{{ ownerLabel }}</span>
							<template v-if="submittedLabel !== null">
								<span class="senior-modal__dot" aria-hidden="true">·</span>
								<span data-testid="senior-modal-submitted">{{ submittedLabel.value }}</span>
							</template>
							<template v-if="progressLabel !== null">
								<span class="senior-modal__dot" aria-hidden="true">·</span>
								<span data-testid="senior-modal-progress">{{ progressLabel }}</span>
							</template>
						</p>
					</div>
					<div class="senior-modal__actions" data-testid="senior-modal-actions">
						<NcButton
							:disabled="exporting || visibleEntries.length === 0"
							data-testid="senior-export-btn"
							@click="exportXlsx"
						>
							{{ exporting
								? t('pettycash', 'reviews.seniorSheet.export.busy')
								: t('pettycash', 'reviews.seniorSheet.export.label') }}
						</NcButton>
						<NcButton
							variant="tertiary"
							class="senior-modal__close"
							:aria-label="t('pettycash', 'reviews.seniorSheet.modal.close')"
							:title="t('pettycash', 'reviews.seniorSheet.modal.close')"
							data-testid="senior-modal-close"
							@click="close"
						>
							<template #icon>
								<NcIconSvgWrapper :path="mdiClose" :size="20" />
							</template>
						</NcButton>
					</div>
				</header>

				<div class="senior-modal__body">
					<div class="senior-modal__section-head">
						<p class="senior-modal__section-title" data-testid="senior-modal-transactions-title">
							{{ t('pettycash', 'reviews.seniorSheet.modal.transactions') }}
						</p>
						<span
							v-if="entries.length > 0"
							class="senior-filter__count"
							data-testid="senior-filter-count"
						>
							{{ t('pettycash', 'reviews.seniorSheet.filter.count', { count: formatNumber(visibleEntries.length) }) }}
						</span>
					</div>

					<template v-if="entries.length > 0">
						<!--
							T020/T021/T022: local filter + sort over the proven-complete set — no request per
							change, no vendor control (owner D7). The export submits the filtered + sorted
							selection as the exact ordered transaction ids (FR-007-044).
						-->
						<div class="senior-modal__toolbar" data-testid="senior-modal-toolbar">
							<div class="senior-filter" data-testid="senior-filter-panel">
								<label class="senior-filter__field senior-filter__field--search" data-testid="senior-filter-search-field">
									<span class="senior-filter__label">{{ t('pettycash', 'reviews.seniorSheet.filter.searchLabel') }}</span>
									<input
										type="search"
										class="senior-filter__input"
										:value="filter.query ?? ''"
										:placeholder="t('pettycash', 'reviews.seniorSheet.filter.searchPlaceholder')"
										data-testid="senior-filter-search"
										@input="onSearchInput"
									>
								</label>

								<div class="senior-filter__field senior-filter__field--types" data-testid="senior-filter-expense-types">
									<span class="senior-filter__label">{{ t('pettycash', 'reviews.seniorSheet.filter.expenseTypes') }}</span>
									<div class="senior-filter__chips">
										<button
											v-for="facet in expenseTypeFacets"
											:key="facet.id"
											type="button"
											class="senior-filter__chip"
											:class="{ 'senior-filter__chip--active': isExpenseTypeSelected(facet.id) }"
											:aria-pressed="isExpenseTypeSelected(facet.id)"
											:data-testid="`senior-filter-expense-type-${facet.id}`"
											@click="toggleExpenseType(facet.id)"
										>
											{{ facet.name }} · {{ formatNumber(facet.count) }}
										</button>
									</div>
								</div>
							</div>

							<div class="senior-results" data-testid="senior-results-toolbar">
								<div class="senior-sort" data-testid="senior-sort-panel">
									<span class="senior-filter__label">{{ t('pettycash', 'reviews.seniorSheet.sort.controlLabel') }}</span>
									<div class="senior-sort__row">
										<button
											v-for="key in SORT_KEYS"
											:key="key"
											type="button"
											class="senior-sort__button"
											:class="{ 'senior-sort__button--active': sort.key === key }"
											:aria-pressed="sort.key === key"
											:data-testid="`senior-sort-${key}`"
											@click="onSortKey(key)"
										>
											<span>{{ sortLabel(key) }}</span>
											<template v-if="sort.key === key">
												<NcIconSvgWrapper
													class="senior-sort__direction-icon"
													:path="sortDirectionIcon"
													:size="16"
													aria-hidden="true"
												/>
												<span class="senior-sort__direction-text">{{ directionLabel }}</span>
											</template>
										</button>
									</div>
								</div>

								<div class="senior-results__actions">
									<NcButton
										variant="tertiary"
										size="small"
										data-testid="senior-filter-clear"
										@click="clearFilters"
									>
										{{ t('pettycash', 'reviews.seniorSheet.filter.clear') }}
									</NcButton>
								</div>
							</div>

							<div v-if="exportRefused" class="senior-export__refused" role="alert" data-testid="senior-export-refused">
								<h4 class="senior-export__refused-title">{{ t('pettycash', 'reviews.seniorSheet.export.refusedTitle') }}</h4>
								<p class="senior-export__refused-body">{{ t('pettycash', 'reviews.seniorSheet.export.refusedBody') }}</p>
								<p v-if="exportReason" class="senior-export__reason" data-testid="senior-export-reason">{{ exportReason }}</p>
							</div>
						</div>

						<!--
							T012: one QuickReviewCard per transaction over the filtered + sorted proven-complete
							set. The card carries quick decision, full detail and the evidence thumbnail strip
							(T016); identity/advisory lines belong to the modal, not the shared card.
						-->
						<ul v-if="visibleEntries.length > 0" class="senior-transactions" data-testid="senior-modal-transactions">
							<li
								v-for="entry in visibleEntries"
								:key="entry.transaction.id"
								class="senior-transaction"
								:data-testid="`senior-transaction-${entry.transaction.id}`"
							>
								<p class="senior-transaction__advisory" data-testid="senior-transaction-advisory">
									<span class="senior-transaction__advisory-label">{{ t('pettycash', 'reviews.advisoryDecision') }}:</span>
									<strong>{{ advisoryLabel(entry.transaction) }}</strong>
								</p>
								<QuickReviewCard
									:transaction="entry.transaction"
									:manager-state="UNUSED_MANAGER_STATE"
									:manager-decision="null"
									:senior-state="entry.seniorState"
									:senior-decision="entry.seniorDecision"
									:evidence="transactionEvidence(entry.transaction.id)"
									:allowed-actions="isCompleted ? null : transactionDetail(entry.transaction.id)?.allowedActions ?? null"
									:read-only="isCompleted"
									:busy="submittingTransactionId === entry.transaction.id"
									@open-review="openDetails(entry.transaction.id)"
									@open-details="openDetails(entry.transaction.id)"
									@open-preview="(evidenceId) => openPreviewFromCard(entry, evidenceId)"
									@decide="(kind) => openConfirmForTransaction(entry.transaction.id, kind)"
								/>
							</li>
						</ul>

						<NcEmptyContent
							v-else
							:name="t('pettycash', 'reviews.seniorSheet.filter.noResults')"
							data-testid="senior-modal-no-results"
						/>

						<div class="senior-modal__footer" data-testid="senior-modal-footer">
							<NcIconSvgWrapper :path="mdiShieldCheckOutline" :size="18" aria-hidden="true" />
							<p>
								<strong>{{ t('pettycash', 'reviews.quickReview.footerTitle') }}</strong>
								{{ t('pettycash', 'reviews.quickReview.footerBody') }}
							</p>
						</div>
					</template>

					<div v-else-if="reviewLoading" class="senior-modal__state" data-testid="senior-modal-loading">
						<NcLoadingIcon :size="44" />
						<p>{{ t('pettycash', 'reviews.seniorSheet.modal.loading') }}</p>
					</div>

					<div v-else-if="reviewUnavailable" class="senior-modal__state" data-testid="senior-modal-unavailable">
						<h4 class="senior-modal__state-title">{{ t('pettycash', 'reviews.seniorSheet.modal.unavailableTitle') }}</h4>
						<p class="senior-modal__state-body">{{ t('pettycash', 'reviews.seniorSheet.modal.unavailableBody') }}</p>
						<NcButton data-testid="senior-modal-retry" @click="retryReview">
							{{ t('pettycash', 'common.retry') }}
						</NcButton>
					</div>

					<NcEmptyContent
						v-else
						:name="t('pettycash', 'reviews.seniorSheet.modal.emptyTransactions')"
						data-testid="senior-modal-empty-transactions"
					/>
				</div>
			</div>

			<!--
				T013/T016: one layer at a time above the modal. The drawer, the evidence preview and the
				decision modal each stop Escape propagation, so a key press closes only the top layer and
				the single-overlay rule (006 FR-006-037) holds while the queue stays mounted behind.
			-->
			<EvidencePreview
				:open="activeOverlay === 'preview'"
				:evidence="previewEvidence"
				:documents="previewDocuments"
				:index="previewIndex"
				@close="closeOverlay"
				@navigate="navigatePreview"
			/>

			<TransactionDetailsDrawer
				:open="activeOverlay === 'details'"
				:transaction="detailsTransaction"
				:manager-state="UNUSED_MANAGER_STATE"
				:manager-decision="null"
				:senior-state="detailsSeniorState"
				:senior-decision="detailsSeniorDecision"
				:sheet="review?.sheet ?? itemSheet ?? null"
				:read-state="detailsReadState"
				:read-only="isCompleted"
				@close="closeOverlay"
				@decide="decideFromDrawer"
				@preview="openPreviewFromDrawer"
			/>

			<QuickDecisionModal
				:open="activeOverlay === 'confirm'"
				:decision="confirmDecision"
				:transaction="confirmSnapshot"
				:expected-version="confirmExpectedVersion"
				:allowed-actions="confirmAllowedActions"
				@close="closeOverlay"
				@decided="onDecided"
			/>
		</div>
	</Teleport>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import { mdiArrowDown, mdiArrowUp, mdiClose, mdiShieldCheckOutline } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import QuickReviewCard from '@/components/review/QuickReviewCard.vue'
import TransactionDetailsDrawer from '@/components/review/TransactionDetailsDrawer.vue'
import QuickDecisionModal from '@/components/review/QuickDecisionModal.vue'
import EvidencePreview from '@/components/evidence/EvidencePreview.vue'
import {
	deriveExpenseTypeFacets,
	filterSeniorTransactions,
	sortSeniorTransactions,
} from '@/composables/useSeniorQueue'
import { useReviewsStore } from '@/stores/reviews'
import { exportArtifactBlob } from '@/services/reportsApi'
import { formatSheetDateTime, ABSENT_MARKER } from '@/utils/date'
import { formatNumber } from '@/utils/number'
import type {
	Decision,
	DecisionResult,
	DecisionValue,
	Evidence,
	ManagerTransactionReadState,
	ManagerTransactionState,
	SeniorFilterState,
	SeniorManagerTransactionState,
	SeniorManagerSheetReview,
	SeniorSheetQueueItem,
	SeniorSort,
	SeniorSortKey,
	TransactionDetail,
	TransactionSummary,
} from '@/types/api'

const props = defineProps<{
	open: boolean
	item: SeniorSheetQueueItem | null
}>()

const emit = defineEmits<{
	close: []
}>()

const reviewsStore = useReviewsStore()
const dialogEl = ref<HTMLElement | null>(null)

/**
 * The card and drawer require this manager's own state, but the Senior modal always supplies an
 * additive `seniorState`/`seniorDecision`, which the shared components prefer (`seniorState ??
 * managerState`). This placeholder is therefore never read by the rendered decision presentation; it
 * only satisfies the required prop so the Cost Center contract stays untouched.
 */
const UNUSED_MANAGER_STATE: ManagerTransactionState = 'AWAITING_MANAGER_DECISION'

type Overlay = 'none' | 'preview' | 'details' | 'confirm'

const review = computed<SeniorManagerSheetReview | null>(() => {
	if (!props.item || props.item.sheetId === '') return null
	const candidate = reviewsStore.seniorSheetReviews[props.item.sheetId]
	// A loaded but malformed read is treated as absent rather than trusted field by field
	// (FR-007-011): identity falls to the list row/unavailable placeholders, rows to the
	// proven-complete set.
	if (!candidate || !candidate.sheet || !Array.isArray(candidate.transactions)) return null
	return candidate
})

/**
 * The list row already carries the full server sheet summary, so the modal can render genuine
 * identity before (or instead of) the per-sheet transaction read.
 */
const itemSheet = computed(() => props.item?.sheet ?? null)

const sheetReadState = computed<ManagerTransactionReadState | 'idle'>(() => {
	if (!props.item || props.item.sheetId === '') return 'idle'
	return reviewsStore.seniorSheetReadStates[props.item.sheetId] ?? 'idle'
})

const reviewTransactions = computed(() => review.value?.transactions ?? [])

const reviewByTransactionId = computed(() => {
	const map = new Map<string, TransactionSummary & { seniorState: SeniorManagerTransactionState; seniorDecision: Decision | null }>()
	for (const row of reviewTransactions.value) map.set(row.id, row)
	return map
})

/**
 * Post-decision re-render source (FR-007-021): the server's own `result.transaction` replaces the
 * stale queue row, so the card shows its decided state from the response that recorded it — never a
 * client-invented status. Keyed by transaction id so several decisions in one session each land once.
 */
const patchedTransactions = ref<Record<string, TransactionDetail>>({})

/**
 * A transaction's Senior presentation state.
 *
 * - the additive Senior read's own `seniorState`/`seniorDecision` fields, when the backend supplies
 *   them (`[BD←BE1]`), are authoritative;
 * - a freshly recorded decision's server-returned `result.transaction` supplies the decision (its
 *   `decisions[]`), and the transaction then no longer awaits the senior;
 * - a row from the proven-complete senior-pending set awaits by construction — that is the definition
 *   of the queue, not a fabricated claim (FR-007-011).
 */
interface ModalTransactionEntry {
	transaction: TransactionSummary
	seniorState: SeniorManagerTransactionState
	seniorDecision: Decision | null
}

const entries = computed<ModalTransactionEntry[]>(() => {
	const rows = reviewTransactions.value.length > 0
		? reviewTransactions.value
		: (props.item?.transactions ?? [])
	return rows.map((raw) => {
		const id = raw.id
		const patched = patchedTransactions.value[id]
		if (patched) {
			const seniorDecision = latestSeniorDecision(patched)
			return {
				transaction: patched,
				seniorState: seniorDecision ? 'DECIDED_BY_SENIOR' : 'AWAITING_SENIOR_DECISION',
				seniorDecision,
			}
		}
		const reviewRow = reviewByTransactionId.value.get(id)
		if (reviewRow) {
			return { transaction: reviewRow, seniorState: reviewRow.seniorState, seniorDecision: reviewRow.seniorDecision }
		}
		return { transaction: raw, seniorState: 'AWAITING_SENIOR_DECISION', seniorDecision: null }
	})
})

function latestSeniorDecision(transaction: { decisions?: readonly Decision[] }): Decision | null {
	if (!Array.isArray(transaction.decisions)) return null
	const senior = transaction.decisions.filter((item) => item.stage === 'SENIOR')
	return senior[senior.length - 1] ?? null
}

/**
 * A list-derived row has no transactions of its own, so an empty `entries` set means either the
 * per-sheet read is still in flight or it failed — never that the sheet is empty until the server
 * has actually answered with an empty list.
 */
const reviewLoading = computed(() =>
	entries.value.length === 0 && sheetReadState.value === 'loading'
)

const reviewUnavailable = computed(() =>
	entries.value.length === 0
	&& (sheetReadState.value === 'unavailable'
		|| sheetReadState.value === 'forbidden'
		|| sheetReadState.value === 'notFound')
)

/** D5 / FR-007-017: the server-supplied presentation status; never derived client-side. */
const isCompleted = computed(() =>
	(review.value?.sheet.reviewStatus
		?? itemSheet.value?.reviewStatus
		?? props.item?.reviewStatus) === 'REVIEW_COMPLETED'
)

/*
 * T020/T021 — local filter + sort over the proven-complete set, with no request per change.
 *
 * The pure model lives in `useSeniorQueue.ts` and is unit-tested there; this wires the approved
 * toolbar controls (transaction search + expense-type multi-select) to it and keeps ONE derived
 * order (`visibleEntries`) that feeds both the rendered cards and the export selection (T022).
 * Vendor is out of scope (owner D7): no vendor term, field or placeholder appears.
 */
const SORT_KEYS: SeniorSortKey[] = ['amount', 'expenseDate', 'expenseType']

const filter = ref<SeniorFilterState>({})
const sort = ref<SeniorSort>({ key: 'expenseDate', direction: 'asc' })

const expenseTypeFacets = computed(() =>
	deriveExpenseTypeFacets(entries.value.map((entry) => entry.transaction), filter.value)
)

/**
 * The one derived order: filter, then sort, then re-attach each transaction's presentation entry.
 * Both the rendered cards and the export `ids` read from this, so what the user exports is exactly
 * what the modal shows (FR-007-044).
 */
const visibleEntries = computed<ModalTransactionEntry[]>(() => {
	const byId = new Map(entries.value.map((entry) => [entry.transaction.id, entry]))
	const filtered = filterSeniorTransactions(entries.value.map((entry) => entry.transaction), filter.value)
	const sorted = sortSeniorTransactions(filtered, sort.value)
	return sorted
		.map((transaction) => byId.get(transaction.id))
		.filter((entry): entry is ModalTransactionEntry => entry !== undefined)
})

function onSearchInput(event: Event): void {
	filter.value = { ...filter.value, query: (event.target as HTMLInputElement).value }
}

function isExpenseTypeSelected(id: string): boolean {
	return (filter.value.expenseTypeIds ?? []).includes(id)
}

function toggleExpenseType(id: string): void {
	const current = filter.value.expenseTypeIds ?? []
	const next = current.includes(id) ? current.filter((value) => value !== id) : [...current, id]
	filter.value = { ...filter.value, expenseTypeIds: next.length > 0 ? next : undefined }
}

function clearFilters(): void {
	filter.value = {}
}

const sortLabel = (key: SeniorSortKey): string => t('pettycash', `reviews.seniorSheet.sort.${key}`)
const directionLabel = computed(() => sort.value.direction === 'asc'
	? t('pettycash', 'reviews.seniorSheet.sort.directionAsc')
	: t('pettycash', 'reviews.seniorSheet.sort.directionDesc'))
const sortDirectionIcon = computed(() => sort.value.direction === 'asc' ? mdiArrowUp : mdiArrowDown)

/**
 * One active criterion. Activating the active criterion again toggles its direction; switching to a
 * different criterion always starts ascending (owner-approved toolbar behaviour).
 */
function onSortKey(key: SeniorSortKey): void {
	if (sort.value.key === key) {
		sort.value = { key, direction: sort.value.direction === 'asc' ? 'desc' : 'asc' }
		return
	}
	sort.value = { key, direction: 'asc' }
}

/*
 * T022 — the guarded XLSX export of the filtered + sorted selection.
 */
const exporting = computed(() => reviewsStore.seniorExport.status === 'exporting')
const exportRefused = computed(() => reviewsStore.seniorExport.status === 'refused')
const exportReason = computed(() => {
	const reason = reviewsStore.seniorExport.status === 'refused' ? reviewsStore.seniorExport.reason : null
	// The generic failure key carries no capability name; the fixed refusal copy below does.
	return reason !== null && reason !== '' && reason !== 'pettycash.error.export' ? reason : null
})

async function exportXlsx(): Promise<void> {
	if (exporting.value || visibleEntries.value.length === 0) return
	// The exact ordered selection the user sees; never an owner-scoped fallback.
	const ids = visibleEntries.value.map((entry) => entry.transaction.id)
	const result = await reviewsStore.exportSeniorSelection(ids)
	if (!result) return // refusal is settled and rendered by the store (FR-007-044); nothing is exported.
	// [BD←BE2]: the server returned a real Senior-scoped artifact — present exactly those bytes.
	const blob = exportArtifactBlob(result)
	const url = URL.createObjectURL(blob)
	const link = document.createElement('a')
	link.href = url
	link.download = `pettycash-senior-sheet-${props.item?.sheetId || 'sheet'}.xlsx`
	link.click()
	URL.revokeObjectURL(url)
}

const referenceLabel = computed(() => {
	const id = props.item?.sheetId ?? ''
	return id !== '' ? id : t('pettycash', 'reviews.seniorSheet.unresolvedReference')
})

const sheetTitleLabel = computed(() => {
	const title = review.value?.sheet?.title?.trim()
		|| itemSheet.value?.title?.trim()
		|| props.item?.title?.trim()
		|| ''
	return title !== '' ? title : t('pettycash', 'reviews.seniorSheet.modal.titleUnavailable')
})

const ownerLabel = computed(() => {
	const owner = review.value?.sheet?.ownerDisplayName?.trim()
		|| itemSheet.value?.ownerDisplayName?.trim()
		|| props.item?.purchaser?.trim()
		|| ''
	return owner !== '' ? owner : t('pettycash', 'reviews.seniorSheet.modal.ownerUnavailable')
})

const submittedLabel = computed<{ value: string; available: boolean } | null>(() => {
	const at = review.value?.sheet?.submittedAt
		?? itemSheet.value?.submittedAt
		?? props.item?.submittedAt
		?? null
	if (at === null || at === '') return null
	return { value: formatSheetDateTime(at), available: true }
})

/**
 * The per-sheet read's own progress counters, shown as the same compact line the Cost Center
 * panel uses. It is server-supplied; no client-side completion is derived from the card states.
 */
const progressLabel = computed<string | null>(() => {
	const progress = review.value?.progress
	if (!progress) return null
	return t('pettycash', 'reviews.seniorSheet.modal.progress', {
		decided: formatNumber(progress.decidedBySenior),
		total: formatNumber(progress.total),
	})
})

const dialogLabel = computed(() =>
	t('pettycash', 'reviews.seniorSheet.modal.title', { reference: referenceLabel.value })
)

/**
 * FR-007-004 / FR-034: the Cost Center decision is advisory at transaction level only — read from the
 * current revision's own Cost Center decision, never from `latestDecision` (which is simply the last
 * decision and may belong to a later stage — a completed sheet's `latestDecision` is the Senior or
 * Finance decision). When there is no Cost Center decision yet no value is fabricated: a Senior or
 * Finance decision is never labelled as if it were the Cost Center advisory.
 */
function latestCostCenterDecision(transaction: TransactionSummary): Decision | null {
	const sources: ReadonlyArray<readonly Decision[] | undefined> = [
		// A freshly recorded decision's server-returned `result.transaction` (TransactionDetail)
		// carries the full decision history, including the unchanged Cost Center decision.
		(transaction as TransactionSummary & { decisions?: readonly Decision[] }).decisions,
		transactionDetail(transaction.id)?.decisions,
	]
	for (const decisions of sources) {
		if (!Array.isArray(decisions)) continue
		if (decisions.length === 0) continue
		const cc = decisions.filter((item) => item.stage === 'COST_CENTER')
		return cc[cc.length - 1] ?? null
	}
	// Before the guarded detail read settles, the summary's own `latestDecision` is used only when it
	// is actually the Cost Center decision — a later-stage value is never substituted.
	const latest = transaction.latestDecision
	return latest?.stage === 'COST_CENTER' ? latest : null
}

function advisoryLabel(transaction: TransactionSummary): string {
	const cc = latestCostCenterDecision(transaction)
	if (!cc) return ABSENT_MARKER
	if (cc.decision === 'APPROVE') return t('pettycash', 'reviews.decision.approve')
	if (cc.decision === 'REJECT') return t('pettycash', 'reviews.decision.reject')
	return t('pettycash', 'reviews.decision.return')
}

/*
 * Per-transaction detail: the guarded cache (`managerTransactionDetails`) and read-state map carry
 * the transaction's `allowedActions` capability grant, its `evidence[]` set and its `version` — the
 * three inputs the card, thumbnails and confirmation modal need (T014, T016).
 */
function transactionDetail(transactionId: string): TransactionDetail | null {
	return reviewsStore.managerTransactionDetails[transactionId] ?? null
}

function transactionEvidence(transactionId: string): Evidence[] {
	return transactionDetail(transactionId)?.evidence ?? []
}

async function ensureTransactionDetail(transactionId: string): Promise<TransactionDetail | null> {
	return reviewsStore.fetchManagerTransactionDetail(transactionId)
}

/**
 * Bounded prefetch of the sheet's per-transaction details (evidence + capability grants), mirroring
 * the Cost Center queue's 4-worker pattern so a wide sheet cannot flood the OCS endpoint. Guarded
 * store reads: a failed transaction never blocks its neighbours and never drops the sheet.
 */
async function prefetchTransactionDetails(): Promise<void> {
	const ids = [...new Set(entries.value.map((entry) => entry.transaction.id).filter((id) => id !== ''))]
	let nextIndex = 0
	const workers = Array.from({ length: Math.min(4, ids.length) }, async () => {
		while (nextIndex < ids.length) {
			const id = ids[nextIndex]
			if (id === undefined) return
			nextIndex += 1
			await reviewsStore.fetchManagerTransactionDetail(id)
		}
	})
	await Promise.all(workers)
}

const activeOverlay = ref<Overlay>('none')
const confirmDecision = ref<DecisionValue | null>(null)
const confirmTransactionId = ref('')
const detailsTransactionId = ref('')
const previewTransactionId = ref('')
const previewIndex = ref(0)
const submittingTransactionId = ref<string | null>(null)

const detailsTransaction = computed<TransactionDetail | null>(() =>
	activeOverlay.value === 'details' ? transactionDetail(detailsTransactionId.value) : null)

const detailsReadState = computed<ManagerTransactionReadState>(() =>
	reviewsStore.managerTransactionReads[detailsTransactionId.value] ?? 'loading')

const detailsEntry = computed<ModalTransactionEntry | null>(() =>
	activeOverlay.value === 'details' ? entries.value.find((entry) => entry.transaction.id === detailsTransactionId.value) ?? null : null)

const detailsSeniorState = computed<SeniorManagerTransactionState>(() =>
	detailsEntry.value?.seniorState ?? 'AWAITING_SENIOR_DECISION')

const detailsSeniorDecision = computed<Decision | null>(() =>
	detailsEntry.value?.seniorDecision ?? null)

const previewDocuments = computed<Evidence[]>(() => transactionEvidence(previewTransactionId.value))
const previewEvidence = computed<Evidence | null>(() => previewDocuments.value[previewIndex.value] ?? null)

const confirmSnapshot = computed(() => {
	const detail = transactionDetail(confirmTransactionId.value)
	if (!detail) return null
	return {
		id: detail.id,
		title: (detail.activeRevision?.description?.trim() || t('pettycash', 'reviews.quickReview.card.noDescription')),
		sheetReference: props.item?.sheetId || detail.sheetId,
		amount: detail.activeRevision?.amount ?? null,
		expenseDate: detail.activeRevision?.expenseDate ?? null,
	}
})

const confirmExpectedVersion = computed<number | undefined>(() =>
	transactionDetail(confirmTransactionId.value)?.version)

const confirmAllowedActions = computed(() =>
	transactionDetail(confirmTransactionId.value)?.allowedActions)

function openDetails(transactionId: string): void {
	detailsTransactionId.value = transactionId
	activeOverlay.value = 'details'
	void ensureTransactionDetail(transactionId)
}

function closeOverlay(): void {
	activeOverlay.value = 'none'
	submittingTransactionId.value = null
}

/**
 * T016: a thumbnail opens the preview at exactly that document; a tile whose detail read failed cannot
 * open an empty overlay, so it routes to the drawer whose read state says what actually happened.
 */
async function openPreviewFromCard(entry: ModalTransactionEntry, evidenceId: string): Promise<void> {
	const detail = await ensureTransactionDetail(entry.transaction.id)
	const index = detail?.evidence.findIndex((item) => item.id === evidenceId) ?? -1
	if (detail && index >= 0) {
		previewTransactionId.value = entry.transaction.id
		previewIndex.value = index
		activeOverlay.value = 'preview'
		return
	}
	openDetails(entry.transaction.id)
}

function openPreviewFromDrawer(evidence: Evidence): void {
	const index = transactionEvidence(detailsTransactionId.value).findIndex((item) => item.id === evidence.id)
	if (index < 0) return
	previewTransactionId.value = detailsTransactionId.value
	previewIndex.value = index
	activeOverlay.value = 'preview'
}

function navigatePreview(index: number): void {
	previewIndex.value = index
}

function decideFromDrawer(kind: DecisionValue): void {
	if (isCompleted.value || detailsTransactionId.value === '') return
	void openConfirmForTransaction(detailsTransactionId.value, kind)
}

/**
 * T012/T014: the confirmation posts exactly one decision through the shared modal (which owns the
 * FR-085 idempotency header and FR-033 guard). Without the guarded detail read there is neither a
 * version to be optimistic against nor a capability grant to honour, so the drawer — whose read state
 * says what happened — opens instead of a modal that cannot submit.
 */
async function openConfirmForTransaction(transactionId: string, kind: DecisionValue): Promise<void> {
	if (isCompleted.value || submittingTransactionId.value !== null) return
	const detail = await ensureTransactionDetail(transactionId)
	if (detail === null) {
		openDetails(transactionId)
		return
	}
	confirmDecision.value = kind
	confirmTransactionId.value = transactionId
	submittingTransactionId.value = transactionId
	activeOverlay.value = 'confirm'
}

/**
 * Decision recorded (FR-007-021): close the layer, re-render the card from the server's
 * `result.transaction`, drop the now-stale per-transaction detail, and refetch the sheet read and the
 * queue so counts and states come from the server, never from a client-side patch. The toast words
 * come from the approved flow's completion state.
 */
async function onDecided(result: DecisionResult): Promise<void> {
	const transactionId = result.transaction.id
	const sheetId = props.item?.sheetId ?? result.transaction.sheetId

	activeOverlay.value = 'none'
	submittingTransactionId.value = null
	patchedTransactions.value = { ...patchedTransactions.value, [transactionId]: result.transaction }
	reviewsStore.clearManagerTransactionDetail(transactionId)

	void (async () => {
		try {
			await reviewsStore.fetchSeniorSheetReview(sheetId, true)
		} catch {
			// The refreshed read is preferred but not required; the card keeps its decided state.
		}
		try {
			await reviewsStore.fetchSeniorQueue()
		} catch {
			// The queue's own error state carries the failure; the store keeps its previous set.
		}
		void reviewsStore.fetchManagerTransactionDetail(transactionId, true)
	})()

	try {
		const { showSuccess } = await import('@nextcloud/dialogs')
		showSuccess(t('pettycash', 'reviews.quickReview.decision.toastText'))
	} catch {
		// The decision is recorded and the layer refreshed; a toast is presentation, not evidence.
	}
}

/**
 * The per-sheet read supplies the proven-complete transaction rows and their Senior presentation
 * state; the list row alone carries no transactions. The detail prefetch runs only after that read
 * settles, because the transaction ids to prefetch are exactly the ones it returns.
 */
async function loadReview(force = false): Promise<void> {
	if (!props.item || props.item.sheetId === '') return
	try {
		await reviewsStore.fetchSeniorSheetReview(props.item.sheetId, force)
	} catch {
		// The store classifies the failure into `seniorSheetReadStates`; the modal renders it.
	}
	await prefetchTransactionDetails()
}

function retryReview(): void {
	void loadReview(true)
}

function close(): void {
	emit('close')
}

/** The scrim closes the modal — except while a decision is being submitted (FR-007-002). */
function onScrim(): void {
	if (reviewsStore.seniorExport.status === 'exporting') return
	close()
}

function onKeydown(event: KeyboardEvent): void {
	if (props.open && event.key === 'Escape' && reviewsStore.seniorExport.status !== 'exporting') {
		// The drawer, preview and confirmation layers stop their own Escape; reaching this listener
		// means no sub-overlay is open, so the modal itself is the top layer.
		close()
	}
}

watch(
	() => props.open,
	(next) => {
		if (next) {
			activeOverlay.value = 'none'
			confirmTransactionId.value = ''
			detailsTransactionId.value = ''
			confirmDecision.value = null
			submittingTransactionId.value = null
			// A freshly opened sheet starts with a clean filter and the default order (transaction
			// date ascending): one sheet's search or criterion must not silently carry into the next.
			filter.value = {}
			sort.value = { key: 'expenseDate', direction: 'asc' }
			reviewsStore.resetSeniorExport()
			void loadReview()
			void nextTick(() => dialogEl.value?.focus())
			window.addEventListener('keydown', onKeydown)
		} else {
			window.removeEventListener('keydown', onKeydown)
		}
	},
	{ immediate: false }
)

onBeforeUnmount(() => {
	window.removeEventListener('keydown', onKeydown)
})
</script>

<style scoped>
.senior-modal {
	position: fixed;
	inset: 0;
	z-index: var(--z-index-dialog, 1000);
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 16px;
}

.senior-modal__scrim {
	position: absolute;
	inset: 0;
	background-color: rgb(0 0 0 / 50%);
}

.senior-modal__dialog {
	position: relative;
	width: min(1180px, calc(100vw - 140px));
	max-height: calc(100vh - 76px);
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	box-shadow: var(--shadow-modal, 0 8px 24px rgb(0 0 0 / 20%));
	outline: none;
}

.senior-modal__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	padding: 16px;
	border-block-end: 1px solid var(--color-border);
}

/* The heading is the flexible column; the header actions (export + close) must never be squeezed by
   a long sheet title or a wide meta line (that squeezed an icon-only NcButton into a clipped sliver). */
.senior-modal__heading {
	flex: 1 1 auto;
	min-width: 0;
}

.senior-modal__actions {
	display: flex;
	flex: 0 0 auto;
	align-items: center;
	gap: 8px;
}

.senior-modal__close {
	flex: 0 0 auto;
}

.senior-modal__eyebrow {
	margin: 0 0 4px;
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.senior-modal__title {
	margin: 0;
	font-size: 1.15rem;
	font-weight: 600;
}

.senior-modal__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
	margin: 6px 0 0;
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

.senior-modal__dot {
	opacity: 0.6;
}

.senior-modal__body {
	padding: 16px;
}

.senior-modal__section-head {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: 12px;
	margin-block-end: 8px;
}

.senior-modal__section-title {
	margin: 0;
	font-size: var(--font-size-floor);
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.senior-modal__state {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
	padding: 16px 0;
	color: var(--color-text-maxcontrast);
}

.senior-modal__state-title {
	margin: 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.senior-modal__state-body {
	margin: 0;
	font-size: var(--font-size-floor);
}

/* The same per-transaction decision note the Cost Center panel renders, kept compact so it closes
   the transaction section without competing with the cards. */
.senior-modal__footer {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	margin-block-start: 14px;
	padding: 9px 12px;
	border: 1px solid color-mix(in srgb, var(--color-primary-element) 18%, var(--color-border));
	border-radius: 10px;
	background-color: color-mix(in srgb, var(--color-primary-element) 4%, var(--color-main-background));
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	line-height: 1.45;
}

.senior-modal__footer p {
	margin: 0;
}

.senior-modal__footer strong {
	color: var(--color-main-text);
}

.senior-transactions {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.senior-transaction {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.senior-transaction__advisory {
	display: flex;
	align-items: baseline;
	gap: 6px;
	margin: 0;
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

.senior-transaction__advisory-label {
	text-transform: uppercase;
	letter-spacing: 0.04em;
	font-weight: 600;
}

.senior-transaction__advisory strong {
	font-weight: 600;
}

/* T020/T021/T022 — modal filter, sort and export toolbar. */
.senior-modal__toolbar {
	display: flex;
	flex-direction: column;
	gap: 14px;
	margin-block-end: 16px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-background-hover);
}

.senior-filter {
	display: flex;
	flex-direction: column;
	gap: 10px;
}

.senior-filter__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.senior-filter__label {
	font-size: var(--font-size-floor);
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	color: var(--color-text-maxcontrast);
}

.senior-filter__input {
	width: 100%;
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
}

.senior-filter__input:disabled {
	opacity: 0.5;
	cursor: not-allowed;
}

.senior-filter__chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.senior-filter__chip {
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
	cursor: pointer;
}

.senior-filter__chip--active {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element);
	color: var(--color-primary-text);
}

.senior-results {
	display: flex;
	flex-direction: column;
	gap: 14px;
}

.senior-filter__count {
	padding: 2px 8px;
	border-radius: 999px;
	background-color: var(--color-background-hover);
	font-size: var(--font-size-floor);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.senior-sort {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.senior-sort__row {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.senior-sort__button {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
	cursor: pointer;
}

.senior-sort__direction-icon {
	flex: 0 0 auto;
}

/* The arrow is decorative; the direction is announced as text by assistive technology. */
.senior-sort__direction-text {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
	border: 0;
}

.senior-sort__button--active {
	border-color: var(--color-primary-element);
	background-color: var(--color-primary-element);
	color: var(--color-primary-text);
}

.senior-filter__chip:focus-visible,
.senior-sort__button:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.senior-export__refused {
	width: 100%;
	padding: 10px 12px;
	border: 1px solid var(--color-error-border, var(--color-border));
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-error-background, var(--color-background-hover));
}

.senior-export__refused-title {
	margin: 0 0 4px;
	font-size: var(--font-size-floor);
	font-weight: 600;
	color: var(--color-error-text);
}

.senior-export__refused-body,
.senior-export__reason {
	margin: 0;
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

.senior-export__reason {
	margin-block-start: 4px;
}

/*
 * Desktop: the toolbar reads as two compact rows — filter fields, then results — so the
 * proven-complete cards start near the top of the dialog instead of below a tall wall of empty
 * filter space. The cards are full-width, matching the Cost Center review panel; the advisory is
 * folded into a compact header on the same bordered surface as the shared card.
 */
@media (width >= 992px) {
	.senior-modal__header {
		padding: 20px 24px 16px;
	}

	.senior-modal__title {
		font-size: 1.3rem;
		line-height: 1.25;
	}

	.senior-modal__eyebrow {
		color: var(--color-primary-element);
		font-weight: 700;
		letter-spacing: 0.08em;
	}

	.senior-modal__body {
		padding: 20px 24px 24px;
	}

	.senior-modal__section-title {
		margin: 0;
	}

	.senior-modal__toolbar {
		gap: 6px;
		padding: 10px 14px;
	}

	.senior-filter {
		flex-flow: row wrap;
		align-items: flex-end;
		gap: 6px 16px;
	}

	.senior-filter__field {
		gap: 3px;
	}

	.senior-filter__label {
		line-height: 1.2;
	}

	.senior-filter__field--search {
		flex: 1 1 300px;
		min-width: 260px;
	}

	.senior-filter__field--types {
		flex: 2 1 420px;
		min-width: 320px;
	}

	.senior-filter__chip,
	.senior-sort__button {
		min-block-size: 30px;
		padding: 5px 10px;
		border-radius: 999px;
	}

	.senior-results {
		flex-flow: row wrap;
		align-items: center;
		justify-content: space-between;
		gap: 10px;
		padding-block-start: 6px;
		border-block-start: 1px solid var(--color-border);
	}

	.senior-results__actions {
		display: flex;
		flex: 0 0 auto;
		align-items: center;
		gap: 10px;
		margin-inline-start: auto;
	}

	.senior-sort {
		flex-flow: column;
		align-items: flex-start;
		gap: 6px;
	}

	.senior-sort__row {
		flex-wrap: nowrap;
	}

	.senior-transactions {
		gap: 14px;
	}

	.senior-transaction {
		gap: 0;
		min-width: 0;
		border: 1px solid var(--color-border);
		border-radius: 12px;
		overflow: hidden;
		background-color: var(--color-main-background);
	}

	.senior-transaction__advisory {
		padding: 8px 16px;
		border-block-end: 1px solid var(--color-border);
		background-color: var(--color-background-hover);
	}

	.senior-transaction :deep(.quick-review-card) {
		border: 0;
		border-radius: 0;
	}
}

/* 390px: the header and the modal body keep everything readable without horizontal scroll (FR-007-042). */
@media (width <= 390px) {
	.senior-modal {
		padding: 8px;
	}

	.senior-modal__body {
		padding: 12px;
	}
}

/* Mobile touch targets (T030, same ≥44px floor as the Cost Center baseline): the custom filter
   chips and sort buttons are hand-rolled (padding alone is ~28px), so give them a proper tap area
   on narrow screens. The modal-close NcButton stays at NcButton's default size on desktop, where it
   is a pointer target; at 390px it is a finger target at ~34px, so it gets the same 44px floor as
   the rest of the senior surface. It is scoped from the modal root (`:deep`) so the rule outranks
   NcButton's own `--button-size` min-height regardless of stylesheet order. */
@media (width <= 640px) {
	.senior-filter__chip,
	.senior-sort__button {
		min-block-size: 44px;
	}

	.senior-filter__input {
		min-block-size: 44px;
	}

	.senior-modal :deep(.senior-modal__close) {
		min-block-size: 44px;
	}
}
</style>

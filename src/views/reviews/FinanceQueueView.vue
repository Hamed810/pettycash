<template>
	<div class="queue-view" :dir="direction" data-testid="finance-queue">
		<nav class="breadcrumbs" :aria-label="t('pettycash', 'common.breadcrumbs')">
			<ol class="breadcrumbs__list">
				<li class="breadcrumbs__item">
					<RouterLink :to="{ name: 'dashboard' }">{{ t('pettycash', 'navigation.dashboard') }}</RouterLink>
				</li>
				<li class="breadcrumbs__item" aria-current="page">{{ t('pettycash', 'reviews.financeQueue') }}</li>
			</ol>
		</nav>

		<header class="queue-header">
			<div class="queue-header__intro">
				<p class="queue-header__eyebrow">{{ t('pettycash', 'reviews.financeSheet.eyebrow') }}</p>
				<h2 class="queue-header__title">{{ t('pettycash', 'reviews.financeQueue') }}</h2>
			</div>
			<div class="queue-header__aside">
				<NcButton :disabled="loading" data-testid="refresh-btn" @click="refresh">
					<template #icon>
						<NcIconSvgWrapper :path="mdiRefresh" />
					</template>
					{{ t('pettycash', 'common.refresh') }}
				</NcButton>
			</div>
		</header>

		<div v-if="loading" class="queue-state" data-testid="loading">
			<NcLoadingIcon :size="44" />
			<p>{{ t('pettycash', 'common.loading') }}</p>
		</div>

		<div v-else-if="error" class="queue-state" data-testid="error">
			<NcIconSvgWrapper :path="mdiAlertCircle" :size="44" class="queue-state__icon--error" />
			<p>{{ error }}</p>
			<NcButton data-testid="retry-btn" @click="refresh">
				{{ t('pettycash', 'common.retry') }}
			</NcButton>
		</div>

		<!--
			Grouping completeness (FR-009-007/FR-009-008): a partial finance set is never presented
			as complete Cost Sheet rows. An honest unavailable state with retry renders instead.
		-->
		<div v-else-if="!completeness.complete" class="queue-state queue-state--unavailable" data-testid="grouping-unavailable">
			<NcIconSvgWrapper :path="mdiAlertCircle" :size="44" class="queue-state__icon--warning" />
			<h3>{{ t('pettycash', 'reviews.financeSheet.unavailable.title') }}</h3>
			<p class="queue-state__message">{{ t('pettycash', 'reviews.financeSheet.unavailable.body') }}</p>
			<NcButton data-testid="retry-btn" @click="refresh">
				{{ t('pettycash', 'reviews.financeSheet.unavailable.action') }}
			</NcButton>
		</div>

		<template v-else>
			<div v-if="sheetItems.length === 0" data-testid="empty-queue">
				<NcEmptyContent
					:name="t('pettycash', 'reviews.financeSheet.empty.title')"
					:description="t('pettycash', 'reviews.financeSheet.empty.body')"
				/>
			</div>

			<template v-else>
				<section class="summary" :aria-label="t('pettycash', 'reviews.financeSheet.stats.label')" data-testid="finance-stats">
					<article class="stat" data-testid="stat-sheets">
						<div class="stat__top">
							<span class="stat__label">
								<span class="stat__icon stat__icon--primary" aria-hidden="true">
									<NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="16" />
								</span>
								{{ t('pettycash', 'reviews.financeSheet.stats.sheetsAwaiting.label') }}
							</span>
						</div>
						<span class="stat__value">{{ formatNumber(awaitingSheetCount) }}</span>
						<span class="stat__hint">{{ t('pettycash', 'reviews.financeSheet.stats.sheetsAwaiting.hint', { count: formatNumber(awaitingTransactionCount) }) }}</span>
					</article>
					<article class="stat" data-testid="stat-transactions">
						<div class="stat__top">
							<span class="stat__label">
								<span class="stat__icon stat__icon--review" aria-hidden="true">
									<NcIconSvgWrapper :path="mdiClipboardCheckOutline" :size="16" />
								</span>
								{{ t('pettycash', 'reviews.financeSheet.stats.transactionsPending.label') }}
							</span>
						</div>
						<span class="stat__value">{{ formatNumber(awaitingTransactionCount) }}</span>
						<span class="stat__hint">{{ t('pettycash', 'reviews.financeSheet.stats.transactionsPending.hint', { count: formatNumber(awaitingSheetCount) }) }}</span>
					</article>
				</section>

				<div class="senior-queue-toolbar" data-testid="senior-queue-toolbar">
					<div class="senior-queue-search" data-testid="senior-queue-search-field">
						<label class="sheet-search__label" data-testid="finance-queue-search-label">
							{{ t('pettycash', 'reviews.financeSheet.searchLabel') }}
						</label>
						<input
							type="search"
							class="senior-queue-search__input"
							:value="sheetSearchQuery"
							:placeholder="t('pettycash', 'reviews.financeSheet.searchPlaceholder')"
							:aria-label="t('pettycash', 'reviews.financeSheet.searchLabel')"
							data-testid="finance-queue-search-field"
							@input="onSheetSearchInput"
						>
						<NcButton
							v-if="sheetSearchQuery !== ''"
							variant="tertiary"
							size="small"
							data-testid="finance-queue-search-clear"
							@click="clearSheetSearch"
						>
							{{ t('pettycash', 'common.clear') }}
						</NcButton>
					</div>

					<div
						class="senior-queue-tabs"
						role="group"
						:aria-label="t('pettycash', 'reviews.sheetQueue.tabsLabel')"
						data-testid="senior-queue-tabs"
					>
						<button
							v-for="tab in QUEUE_TABS"
							:key="tab.value"
							type="button"
							class="senior-queue-tabs__tab"
							:class="{ 'senior-queue-tabs__tab--active': sheetStatusTab === tab.value }"
							:data-testid="`finance-queue-tab-${tab.value}`"
							@click="setQueueTab(tab.value)"
						>
							{{ t('pettycash', tab.labelKey) }}
						</button>
					</div>
				</div>

				<div v-if="visibleSheets.length === 0" class="sheet-queue-no-results" data-testid="finance-queue-no-results">
					{{ t('pettycash', 'reviews.sheetQueue.noResults') }}
				</div>

				<div v-else class="sheet-panel" data-testid="finance-sheet-queue">
				<ResponsiveTable
					:columns="sheetColumns()"
					:rows="visibleSheets"
					:cell="sheetCell"
					:row-key="sheetRowKey"
					:caption="queueCaption"
					:sort="sheetSort"
					:on-sort="setSheetSort"
					test-id="finance-sheet-table"
					test-id-prefix="finance-queue"
					:row-test-id="sheetRowTestId"
					:card-row-test-id="sheetCardRowTestId"
				/>
				</div>
			</template>
		</template>

		<!--
			The shared Sheet Details Modal (UXC-5-01) over the still-mounted queue: Finance identity,
			tabs and overlays arrive through the adapter and slots, and the shell owns the canonical
			structure, focus lifecycle and single-overlay rules. The queue is never navigated away from.
		-->
		<SheetDetailsModal
			:open="openSheetItem !== null"
			:adapter="financeAdapter"
			@close="closeSheetModal"
		>
			<!--
				Option A: the flat finance queue carries no sheet title, purchaser or submission date.
				The shell renders the honest unavailable title from `identity.title = null`; this slot
				keeps the reference and the explicit owner-unavailable state the Finance surface has
				always shown — nothing is fabricated.
			-->
			<template #identity-meta>
				<CompactIdentifier :value="openSheetItem?.sheetId ?? ''" class="finance-identity__reference" data-testid="sheet-details-modal-reference" />
				<span class="finance-identity__dot" aria-hidden="true">&#183;</span>
				<span data-testid="sheet-details-modal-owner">{{ t('pettycash', 'reviews.financeSheet.modal.ownerUnavailable') }}</span>
			</template>

			<template #tab-transactions>
				<div v-if="reviewLoading" class="finance-review-state" data-testid="sheet-details-modal-loading">
					<NcLoadingIcon :size="44" />
					<p>{{ t('pettycash', 'reviews.financeSheet.modal.loading') }}</p>
				</div>

				<!-- A refusal, an absence and a failure stay distinct; the retry re-runs the bounded prefetch. -->
				<div v-else-if="reviewUnavailable" class="finance-review-state" data-testid="sheet-details-modal-unavailable">
					<h4>{{ t('pettycash', 'reviews.financeSheet.modal.unavailableTitle') }}</h4>
					<p>{{ t('pettycash', 'reviews.financeSheet.modal.unavailableBody') }}</p>
					<NcButton data-testid="sheet-details-modal-retry" @click="retryReview">
						{{ t('pettycash', 'common.retry') }}
					</NcButton>
				</div>

				<NcEmptyContent
					v-else-if="entries.length === 0"
					:name="t('pettycash', 'reviews.financeSheet.modal.emptyTransactions')"
					data-testid="sheet-details-modal-no-transactions"
				/>

				<template v-else>
					<p class="finance-review-count" data-testid="sheet-details-modal-transaction-count">
						{{ t('pettycash', 'reviews.seniorSheet.filter.count', { count: formatNumber(entries.length) }) }}
					</p>
					<ul class="finance-transactions" data-testid="transaction-review-cards">
						<li
							v-for="entry in entries"
							:key="entry.id"
							:data-testid="`sheet-transaction-${entry.id}`"
						>
							<!--
								The shared compact review card (UXC-5-02, v2 interaction per UXC-V2-11): summary
								fields, transaction-scoped evidence summary, stage-separated previous-decision
								indicators, always-visible decide bar, and the title that toggles this card's
								Inline Expanded Transaction in place — no second overlay, no route change. Decide
								actions come only from the server's `allowedActions`; a transaction Finance
								already decided keeps no decide bar. The expansion data comes from the same
								guarded per-transaction detail read.
							-->
							<TransactionReviewCard
								:transaction="entry"
								:evidence="transactionEvidence(entry.id)"
								:stage-decisions="stageDecisionsFor(entry)"
								:allowed-actions="allowedActionsFor(entry.id)"
								:busy="submittingTransactionId === entry.id"
								:show-actions="!financeCardDecided(entry)"
								expandable
								:expanded="expandedTransactionId === entry.id"
								:detail="expandedDetail"
								:detail-read-state="expandedReadState"
								:detail-sheet="null"
								:detail-stage="inlineStage"
								@toggle-expand="toggleExpansion"
								@open-preview="(evidenceId) => openPreviewFromCard(entry, evidenceId)"
								@decide="(kind) => openConfirmForTransaction(entry.id, kind)"
							/>
						</li>
					</ul>
				</template>
			</template>

			<!--
				Approvals (UXC-7-01): the sheet-level aggregate surface — the server's whole-sheet
				approved totals per workflow stage, consumed authoritatively from the queue-provided
				aggregate (UXC-7F-05). Per-transaction decision history stays in each transaction's
				Previous Decisions section inside the inline expansion only.
			-->
			<template #tab-approvals>
				<SheetApprovalsSummary :stages="approvalsStages" />
			</template>

			<template #tab-overview>
				<dl class="finance-overview" data-testid="finance-tab-overview">
					<div class="finance-overview__item">
						<dt>{{ t('pettycash', 'reviews.financeSheet.reference') }}</dt>
						<dd><CompactIdentifier :value="openSheetItem?.sheetId ?? ''" /></dd>
					</div>
					<div class="finance-overview__item">
						<dt>{{ t('pettycash', 'reviews.financeSheet.pendingCount') }}</dt>
						<dd data-testid="finance-overview-transaction-count">{{ formatNumber(openSheetItem?.transactionCount ?? 0) }}</dd>
					</div>
					<div class="finance-overview__item">
						<dt>{{ t('pettycash', 'reviews.amount') }}</dt>
						<dd data-testid="finance-overview-totals">
							<span
								v-for="total in openSheetItem?.totals ?? []"
								:key="`${total.currency}-${total.minorUnit}`"
								class="finance-overview__money"
							>
								<bdi>{{ formatMoney(total) }}</bdi>
							</span>
						</dd>
					</div>
					<div class="finance-overview__item">
						<dt>{{ t('pettycash', 'reviews.financeSheet.latestActivity') }}</dt>
						<dd>
							{{ openSheetItem?.latestActivityAt
								? formatSheetDateTime(openSheetItem.latestActivityAt)
								: t('pettycash', 'reviews.quickReview.card.metaUnavailable') }}
						</dd>
					</div>
				</dl>
			</template>

			<!-- No sheet-level event history exists under the Option A projection — honestly empty (UXC-7-03). -->
			<template #tab-history>
				<div class="sheet-details-modal__empty" data-testid="finance-tab-history-empty">
					{{ t('pettycash', 'reviews.sheetHistory.unavailable') }}
				</div>
			</template>

			<!--
				Evidence Preview and Decision Confirmation remain the only overlays above the sheet modal
				(UXC-V2-11): the transaction detail lives in the card's inline expansion, not in a second
				full-height layer. The queue and sheet context stay mounted behind it.
			-->
			<template #overlays>
				<EvidencePreview
					:open="activeOverlay === 'preview'"
					:evidence="previewEvidence"
					:documents="previewDocuments"
					:index="previewIndex"
					@close="closeOverlay"
					@navigate="navigatePreview"
				/>

				<DecisionConfirmation
					:open="activeOverlay === 'confirm'"
					:decision="confirmDecision"
					:transaction="confirmSnapshot"
					:expected-version="confirmExpectedVersion"
					:allowed-actions="confirmAllowedActions"
					@close="closeOverlay"
					@decided="onDecided"
				/>
			</template>
		</SheetDetailsModal>
	</div>
</template>

<script setup lang="ts">
import { computed, h, nextTick, onMounted, ref, watch, type VNode } from 'vue'
import { RouterLink } from 'vue-router'
import { t } from '@nextcloud/l10n'
import { mdiAlertCircle, mdiClipboardCheckOutline, mdiFileDocumentOutline, mdiRefresh } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import ResponsiveTable from '@/components/shared/ResponsiveTable.vue'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import SheetDetailsModal, { type SheetDetailsModalAdapter } from '@/components/review/SheetDetailsModal.vue'
import CompactIdentifier from '@/components/review/CompactIdentifier.vue'
import SheetApprovalsSummary from '@/components/review/SheetApprovalsSummary.vue'
import TransactionReviewCard, { type TransactionReviewCardStageDecisions } from '@/components/review/TransactionReviewCard.vue'
import type { TransactionInlineDetailStageConfig } from '@/components/review/TransactionInlineDetail.vue'
import DecisionConfirmation, { type DecisionConfirmationTransaction } from '@/components/review/DecisionConfirmation.vue'
import EvidencePreview from '@/components/evidence/EvidencePreview.vue'
import { direction } from '@/utils/direction'
import {
	authoritativeStageApprovals,
	unprovenApprovalsStages,
	type ApprovalsStageList,
} from '@/utils/stageApprovals'
import { formatMoney } from '@/utils/money'
import { formatNumber } from '@/utils/number'
import { formatSheetDateTime } from '@/utils/date'
import { checkFinanceQueueCompleteness, buildFinanceSheetQueue, dedupeFinanceTransactions } from '@/composables/useFinanceQueue'
import type { FinanceSheetQueueItem } from '@/composables/useFinanceQueue'
import { useReviewsStore } from '@/stores/reviews'
import type {
	Decision,
	DecisionResult,
	DecisionValue,
	Evidence,
	ManagerTransactionReadState,
	TransactionDetail,
	TransactionSummary,
} from '@/types/api'

const reviewsStore = useReviewsStore()

const financeQueue = computed(() => reviewsStore.financeQueue)
const loading = computed(() => reviewsStore.loading)
const error = computed(() => reviewsStore.error)

/** Grouping completeness over the proven-complete finance-eligible set (FR-009-007/FR-009-008). */
const completeness = computed(() => checkFinanceQueueCompleteness(financeQueue.value))

/** De-duplicated, grouped sheet rows — nothing is rendered unless completeness is proven. */
const sheetItems = computed<FinanceSheetQueueItem[]>(() =>
	buildFinanceSheetQueue(dedupeFinanceTransactions(financeQueue.value.items))
)

/** Distinct sheets containing at least one transaction awaiting Finance review. */
const awaitingSheetCount = computed(() =>
	sheetItems.value.filter((sheet) =>
		sheet.transactions.some((tx) => tx.status === 'AWAITING_FINANCE_REVIEW')
	).length
)

/** Current active transactions awaiting Finance review. */
const awaitingTransactionCount = computed(() =>
	sheetItems.value.reduce((sum, sheet) =>
		sum + sheet.transactions.filter((tx) => tx.status === 'AWAITING_FINANCE_REVIEW').length, 0)
)

const sheetSearchQuery = ref('')

const QUEUE_TABS: ReadonlyArray<{ value: 'all' | 'pending' | 'completed'; labelKey: string }> = [
	{ value: 'all', labelKey: 'reviews.seniorSheet.tabAll' },
	{ value: 'pending', labelKey: 'reviews.seniorSheet.tabAwaiting' },
	{ value: 'completed', labelKey: 'reviews.seniorSheet.tabCompleted' },
]

const sheetStatusTab = ref<'all' | 'pending' | 'completed'>('all')

const sheetSortKey = ref<'reference' | 'purchaser' | 'transactions' | 'amount' | 'status' | 'submitted' | null>(null)
const sheetSortDirection = ref<'asc' | 'desc'>('asc')

const sheetSort = computed(() =>
	sheetSortKey.value === null ? null : { key: sheetSortKey.value, direction: sheetSortDirection.value }
)

function setSheetSort(key: string): void {
	if (sheetSortKey.value === key) {
		sheetSortDirection.value = sheetSortDirection.value === 'asc' ? 'desc' : 'asc'
		return
	}
	sheetSortKey.value = key as typeof sheetSortKey.value
	sheetSortDirection.value = 'asc'
}

function setQueueTab(value: 'all' | 'pending' | 'completed'): void {
	sheetStatusTab.value = value
}

const visibleSheets = computed(() => {
	const query = sheetSearchQuery.value.trim().toLocaleLowerCase()
	let searched = sheetItems.value
	if (query !== '') {
		searched = searched.filter((sheet) =>
			sheet.sheetId.toLocaleLowerCase().includes(query)
			|| sheet.sheetTitle.toLocaleLowerCase().includes(query)
			|| sheet.sheetOwnerDisplayName.toLocaleLowerCase().includes(query)
			|| sheet.costCenters.some((center) =>
				center.code.toLocaleLowerCase().includes(query)
				|| center.name.toLocaleLowerCase().includes(query)
			)
		)
	}
	const statusOf = (sheet: FinanceSheetQueueItem): 'PENDING_REVIEW' | 'REVIEW_COMPLETED' =>
		sheet.transactions.some((tx) => tx.status === 'AWAITING_FINANCE_REVIEW')
			? 'PENDING_REVIEW'
			: 'REVIEW_COMPLETED'
	let filtered = sheetItems.value
	if (sheetStatusTab.value === 'completed') {
		filtered = searched.filter((s) => statusOf(s) === 'REVIEW_COMPLETED')
	} else if (sheetStatusTab.value === 'pending') {
		filtered = searched.filter((s) => statusOf(s) !== 'REVIEW_COMPLETED')
	} else {
		filtered = searched
	}
	const key = sheetSortKey.value
	if (key === null) return filtered
	return sortFinanceSheetsByColumn(filtered, { key, direction: sheetSortDirection.value }, sheetSortAccess)
})

function sheetAmountScalar(sheet: FinanceSheetQueueItem): { currency: string; amountMinor: number } | null {
	const min = sheet.totals.reduce<{ currency: string; amountMinor: number } | null>((acc, total) => {
		if (acc === null || total.currency < acc.currency) return { currency: total.currency, amountMinor: total.amountMinor }
		return acc
	}, null)
	return min
}

function sortFinanceSheetsByColumn(
	items: readonly FinanceSheetQueueItem[],
	sort: { key: string; direction: 'asc' | 'desc' },
	access: (sheet: FinanceSheetQueueItem) => { title: string | null; purchaser: string | null; amount: { currency: string; amountMinor: number } | null; status: 'PENDING_REVIEW' | 'REVIEW_COMPLETED'; submitted: string | null }
): FinanceSheetQueueItem[] {
	const copy = [...items]
	const direction = sort.direction === 'asc' ? 1 : -1
	copy.sort((a, b) => {
		const aa = access(a)
		const ba = access(b)
		let primary = 0
		switch (sort.key) {
			case 'reference': {
				const aValue = aa.title?.trim() !== '' ? aa.title!.trim() : a.sheetId
				const bValue = ba.title?.trim() !== '' ? ba.title!.trim() : b.sheetId
				if (aValue === '' && bValue !== '') { primary = 1; break }
				if (aValue !== '' && bValue === '') { primary = -1; break }
				primary = aValue.localeCompare(bValue)
				break
			}
			case 'purchaser': {
				const aName = aa.purchaser?.trim() ?? ''
				const bName = ba.purchaser?.trim() ?? ''
				if (aName === '' && bName !== '') { primary = 1; break }
				if (aName !== '' && bName === '') { primary = -1; break }
				primary = aName.localeCompare(bName)
				break
			}
			case 'transactions': {
				primary = a.transactionCount - b.transactionCount
				break
			}
			case 'amount': {
				const aAmt = aa.amount
				const bAmt = ba.amount
				if (aAmt === null && bAmt !== null) { primary = 1; break }
				if (aAmt !== null && bAmt === null) { primary = -1; break }
				if (aAmt !== null && bAmt !== null) {
					if (aAmt.amountMinor !== bAmt.amountMinor) primary = aAmt.amountMinor - bAmt.amountMinor
					else if (aAmt.currency !== bAmt.currency) primary = aAmt.currency.localeCompare(bAmt.currency)
				}
				break
			}
			case 'status': {
				primary = aa.status.localeCompare(ba.status)
				break
			}
			case 'submitted': {
				const aTime = aa.submitted === null ? null : Date.parse(aa.submitted)
				const bTime = ba.submitted === null ? null : Date.parse(ba.submitted)
				if (aTime === null && bTime === null) primary = 0
				else if (aTime === null) primary = 1
				else if (bTime === null) primary = -1
				else primary = aTime - bTime
				break
			}
		}
		return primary * direction
	})
	return copy
}

function sheetSortAccess(sheet: FinanceSheetQueueItem) {
	return {
		title: sheet.sheetTitle ?? null,
		purchaser: sheet.sheetOwnerDisplayName ?? null,
		amount: sheetAmountScalar(sheet),
		status: sheet.transactions.some((tx) => tx.status === 'AWAITING_FINANCE_REVIEW')
			? 'PENDING_REVIEW'
			: 'REVIEW_COMPLETED',
		submitted: sheet.sheetSubmittedAt ?? null,
	} as { title: string | null; purchaser: string | null; amount: { currency: string; amountMinor: number } | null; status: 'PENDING_REVIEW' | 'REVIEW_COMPLETED'; submitted: string | null }
}

function onSheetSearchInput(event: Event): void {
	sheetSearchQuery.value = (event.target as HTMLInputElement).value
}

function clearSheetSearch(): void {
	sheetSearchQuery.value = ''
}

interface SheetColumn {
	key: string
	label: string
	sortable?: boolean
}

function sheetColumns(): SheetColumn[] {
	return [
		{ key: 'reference', label: t('pettycash', 'reviews.financeSheet.reference'), sortable: true },
		{ key: 'purchaser', label: t('pettycash', 'reviews.sheetQueue.purchaser'), sortable: true },
		{ key: 'pendingCount', label: t('pettycash', 'reviews.sheetQueue.transactions'), sortable: true },
		{ key: 'amount', label: t('pettycash', 'reviews.amount'), sortable: true },
		{ key: 'status', label: t('pettycash', 'reviews.status'), sortable: true },
		{ key: 'submitted', label: t('pettycash', 'reviews.sheetQueue.submitted'), sortable: true },
	]
}

const queueCaption = t('pettycash', 'reviews.financeQueue')

function sheetCell(sheet: FinanceSheetQueueItem, column: SheetColumn): VNode | string | number {
	switch (column.key) {
	case 'reference':
		return h(
			'span',
			{ class: 'sheet-row__identity' },
			[
				h('span', { class: 'sheet-row__icon', 'aria-hidden': 'true' }, [
					h(NcIconSvgWrapper, { path: mdiFileDocumentOutline, size: 20 }),
				]),
				h('span', { class: 'sheet-row__text' }, [
					h(
						NcButton,
						{
							class: 'sheet-row__title-button',
							variant: 'tertiary',
							'data-testid': 'sheet-title',
							title: sheet.sheetTitle ?? sheet.sheetId,
							'aria-label': sheet.sheetTitle ?? sheet.sheetId,
							onClick: () => openSheet(sheet),
						},
						() => sheet.sheetTitle || sheet.sheetId
					),
					h(CompactIdentifier, {
						value: sheet.sheetId,
						'data-testid': 'sheet-reference',
						class: 'sheet-row__reference',
					}),
				]),
			]
		)
	case 'pendingCount':
		return h('span', { class: 'sheet-row__count', 'data-testid': 'sheet-pending-count' }, [
			formatNumber(sheet.transactionCount),
		])
	case 'amount':
		return h('span', { class: 'sheet-row__amount', 'data-testid': 'sheet-amount' }, [
			...sheet.totals.map((total) =>
				h('span', { key: `${total.currency}-${total.minorUnit}`, class: 'sheet-row__money' }, formatMoney(total))
			),
		])
	case 'purchaser':
		return h('span', { class: 'sheet-row__purchaser', 'data-testid': 'sheet-purchaser' }, [
			sheet.sheetOwnerDisplayName || t('pettycash', 'reviews.sheetQueue.purchaserUnavailable'),
		])
	case 'submitted':
		return h('span', { class: 'sheet-row__submitted', 'data-testid': 'sheet-submitted' }, [
			sheet.sheetSubmittedAt !== null && sheet.sheetSubmittedAt !== ''
				? formatSheetDateTime(sheet.sheetSubmittedAt)
				: t('pettycash', 'reviews.sheetQueue.submittedUnavailable'),
		])
	case 'status':
		return h('span', { class: 'sheet-row__status', 'data-testid': 'sheet-status' }, [
			h('span', { 'data-testid': 'sheet-status-badge' }, [
				h(StatusBadge, {
					status: 'COST_CENTER',
					variant: sheet.transactions.some((tx) => tx.status === 'AWAITING_FINANCE_REVIEW')
						? 'warning'
						: 'success',
					label: sheet.transactions.some((tx) => tx.status === 'AWAITING_FINANCE_REVIEW')
						? t('pettycash', 'reviews.sheetQueue.awaiting')
						: t('pettycash', 'reviews.seniorSheet.reviewCompleted'),
				}),
			]),
			spanReturnedIndicator(sheet),
		])
	default:
		return h('span', { 'data-testid': `sheet-unrendered-${column.key}` }, column.key)
	}
}

function spanReturnedIndicator(sheet: FinanceSheetQueueItem): VNode | null {
	let count = 0
	for (const tx of sheet.transactions) {
		if (tx.status !== 'AWAITING_FINANCE_REVIEW') continue
		const detail = transactionDetail(tx.id)
		if (!detail) continue
		if (detail.decisions?.some((d) => d.stage === 'FINANCE' && d.decision === 'RETURN')) count += 1
	}
	if (count === 0) return null
	return h('span', { class: 'sheet-row__returned' }, count === 1
		? t('pettycash', 'reviews.sheetQueue.returnedTransactionOne')
		: t('pettycash', 'reviews.sheetQueue.returnedTransactionMany', { count: formatNumber(count) }))
}

/** Derived only from the proven-complete set; never fabricated. */

function sheetRowTestId(sheet: FinanceSheetQueueItem): string {
	return `sheet-row-${sheet.sheetId || 'unresolved'}`
}

function sheetCardRowTestId(sheet: FinanceSheetQueueItem): string {
	return `sheet-card-row-${sheet.sheetId || 'unresolved'}`
}

function sheetRowKey(sheet: FinanceSheetQueueItem, index: number): string {
	return sheet.sheetId || `sheet-row-${index}`
}

/** No route navigation: the single overlay opens over the still-mounted queue. */
function openSheet(sheet: FinanceSheetQueueItem): void {
	openSheetItem.value = sheet
}

/** Close restores focus to the originating sheet row. */
async function closeSheetModal(): Promise<void> {
	const origin = openSheetItem.value?.sheetId ?? null
	openSheetItem.value = null
	if (origin === null) return
	await nextTick()
	const candidates = document.querySelectorAll<HTMLElement>(
		`[data-testid="sheet-row-${origin}"] [data-testid="sheet-title"], [data-testid="sheet-card-row-${origin}"] [data-testid="sheet-title"]`
	)
	const trigger = Array.from(candidates).find((el) => {
		const rect = el.getBoundingClientRect()
		return rect.width > 0 && rect.height > 0
	})
	if (trigger) {
		trigger.focus()
		return
	}
	const row = document.querySelector<HTMLElement>(`[data-testid="sheet-row-${origin}"]`)
	const rowTrigger = row?.querySelector<HTMLElement>('[data-testid="sheet-title"]')
	if (rowTrigger) {
		rowTrigger.focus()
		return
	}
	row?.focus()
}

let openSheetItem = ref<FinanceSheetQueueItem | null>(null)

// ---------------------------------------------------------------------------
// Shared review overlays (EvidencePreview, DecisionConfirmation) + the inline
// expansion (UXC-V2-11). The overlays stay one layer at a time above the Sheet
// Details Modal; the transaction detail lives in the card's inline expansion and
// the queue stays mounted behind everything.
// ---------------------------------------------------------------------------

type Overlay = 'none' | 'preview' | 'confirm'

const activeOverlay = ref<Overlay>('none')
/** The one Inline Expanded Transaction (UXC-V2-11); `null` collapses every card. */
const expandedTransactionId = ref<string | null>(null)
const previewTransactionId = ref('')
const previewIndex = ref(0)
const confirmDecision = ref<DecisionValue | null>(null)
const confirmTransactionId = ref('')
const submittingTransactionId = ref<string | null>(null)
/**
 * The server's authoritative post-decision transaction (UXC-5-05): the card re-renders from it
 * immediately, before the refreshed reads arrive.
 */
const patchedTransactions = ref<Record<string, TransactionDetail>>({})
/** Transactions Finance decided in this session; their cards keep no decide bar. */
const decidedTransactionIds = ref<Set<string>>(new Set())

/** The sheet's finance-eligible transactions with the authoritative decision patches applied. */
const entries = computed<TransactionSummary[]>(() =>
	(openSheetItem.value?.transactions ?? []).map((tx) => patchedTransactions.value[tx.id] ?? tx))

/** Per-transaction detail reads (evidence, decisions, capabilities), from the bounded prefetch. */
function transactionDetail(transactionId: string): TransactionDetail | null {
	return reviewsStore.managerTransactionDetails[transactionId] ?? null
}

function transactionEvidence(transactionId: string): Evidence[] {
	return transactionDetail(transactionId)?.evidence ?? []
}

/** The server-granted decision capabilities for one transaction (from its detail read). */
function allowedActionsFor(transactionId: string) {
	return transactionDetail(transactionId)?.allowedActions ?? {}
}

/**
 * Per-stage previous decisions for the card's indicators, derived only from authoritative data:
 * the transaction's decisions (detail read or the patched post-decision result), falling back to
 * the summary's single `latestDecision` for its own stage. A stage with no known decision stays
 * `null` — the indicator then reads "No decision yet" once the read lands.
 */
function stageDecisionsFor(entry: TransactionSummary): TransactionReviewCardStageDecisions {
	const decisions = transactionDetail(entry.id)?.decisions
		?? (entry as TransactionDetail).decisions
		?? []
	const latest = entry.latestDecision ?? null
	const fallbackForStage = (stage: Decision['stage']): Decision | null =>
		latest?.stage === stage ? latest : null
	const latestForStage = (stage: Decision['stage']): Decision | null => {
		const matches = decisions.filter((decision) => decision.stage === stage)
		return matches.length > 0 ? matches[matches.length - 1]! : null
	}
	if (decisions.length > 0) {
		return {
			costCenter: latestForStage('COST_CENTER') ?? fallbackForStage('COST_CENTER'),
			senior: latestForStage('SENIOR') ?? fallbackForStage('SENIOR'),
			finance: latestForStage('FINANCE') ?? fallbackForStage('FINANCE'),
		}
	}
	return {
		costCenter: fallbackForStage('COST_CENTER'),
		senior: fallbackForStage('SENIOR'),
		finance: fallbackForStage('FINANCE'),
	}
}

/** Whether Finance has already decided this transaction (read-only thereafter). */
function financeCardDecided(entry: TransactionSummary): boolean {
	if (decidedTransactionIds.value.has(entry.id)) return true
	if (entry.latestDecision?.stage === 'FINANCE') return true
	return transactionDetail(entry.id)?.decisions?.some((decision) => decision.stage === 'FINANCE') ?? false
}

/**
 * Sheet-level approved monetary totals per workflow stage (UXC-7-01), from the queue-provided
 * whole-sheet `stageApprovedTotals` carried by this sheet's finance queue items (UXC-7F-05, owner
 * decision 2026-11-20) — never per-transaction decision history, which stays in each transaction's
 * inline expansion, and never a client-side sum over the finance-visible transaction subset.
 *
 * `openSheetItem.stageApprovedTotals` is `null` — and every stage renders honestly unavailable —
 * when no item carried a well-formed aggregate or the sheet's items disagreed; the grouping in
 * `buildFinanceSheetQueue` owns that judgment. A decoded zero total is a real answer, rendered as
 * a known zero.
 */
const approvalsStages = computed<ApprovalsStageList>(() =>
	openSheetItem.value !== null
		? authoritativeStageApprovals(openSheetItem.value.stageApprovedTotals) ?? unprovenApprovalsStages()
		: unprovenApprovalsStages()
)

/** The Finance stage adapter for the shared shell: authoritative identity, totals and progress. */
const financeAdapter = computed<SheetDetailsModalAdapter>(() => {
	const item = openSheetItem.value
	const total = entries.value.length
	const decided = entries.value.filter((tx) => tx.latestDecision?.stage === 'FINANCE').length
	return {
		stage: 'finance',
		identity: {
			reference: item?.sheetId ?? null,
			eyebrow: t('pettycash', 'reviews.financeSheet.modal.eyebrow'),
			title: item?.sheetTitle || null,
			owner: item?.sheetOwnerDisplayName || null,
			submitted: item?.sheetSubmittedAt ?? null,
			status: null,
			currency: null,
			totals: item?.totals ?? [],
			progress: total > 0 ? { decided, total } : null,
		},
		metrics: { transactionCount: item?.transactionCount ?? null },
		readOnly: false,
		submitting: submittingTransactionId.value !== null,
	}
})

/** Zero or one expanded transaction; toggling the same card collapses it. */
function toggleExpansion(transactionId: string): void {
	const next = expandedTransactionId.value === transactionId ? null : transactionId
	expandedTransactionId.value = next
	if (next !== null) void ensureTransactionDetail(next)
}

const expandedDetail = computed<TransactionDetail | null>(() =>
	expandedTransactionId.value !== null ? transactionDetail(expandedTransactionId.value) : null)

const expandedReadState = computed<ManagerTransactionReadState>(() =>
	expandedTransactionId.value !== null
		? reviewsStore.managerTransactionReads[expandedTransactionId.value] ?? 'loading'
		: 'loading')

/**
 * The current stage's action surface for the expanded proximity row (UXC-5-03): offered while the
 * transaction still awaits a Finance decision; withheld once decided. Which kinds the server
 * actually grants is gated inside the shared decide row from `allowedActions` — this config adds no
 * rule of its own (the senior-rejected Approve restriction stays server-authoritative).
 */
const inlineStage = computed<TransactionInlineDetailStageConfig | null>(() => {
	const detail = expandedDetail.value
	if (detail === null) return null
	if (financeCardDecided(detail)) {
		return { actionsVisible: false, actions: [] }
	}
	return { actionsVisible: true, actions: ['APPROVE', 'REJECT', 'RETURN'] }
})

/**
 * Dangling-expansion guard (UXC-V2-11 state lifecycle): if the expanded transaction leaves the
 * sheet's finance-eligible set (refreshed queue, reconciliation, sheet repoint), the expansion
 * collapses instead of rendering a stale region against data the server no longer presents.
 */
watch(
	() => entries.value.map((entry) => entry.id),
	(ids) => {
		if (expandedTransactionId.value !== null && !ids.includes(expandedTransactionId.value)) {
			expandedTransactionId.value = null
		}
	}
)

const previewDocuments = computed<Evidence[]>(() => transactionEvidence(previewTransactionId.value))
const previewEvidence = computed<Evidence | null>(() => previewDocuments.value[previewIndex.value] ?? null)

const confirmSnapshot = computed<DecisionConfirmationTransaction | null>(() => {
	const detail = transactionDetail(confirmTransactionId.value)
	if (!detail) return null
	return {
		id: detail.id,
		title: detail.activeRevision?.description?.trim() || t('pettycash', 'reviews.quickReview.card.noDescription'),
		sheetReference: openSheetItem.value?.sheetId || detail.sheetId,
		amount: detail.activeRevision?.amount ?? null,
		expenseDate: detail.activeRevision?.expenseDate ?? null,
	}
})

const confirmExpectedVersion = computed<number | undefined>(() =>
	transactionDetail(confirmTransactionId.value)?.version)

const confirmAllowedActions = computed(() =>
	transactionDetail(confirmTransactionId.value)?.allowedActions)

const reviewLoading = computed(() =>
	entries.value.length > 0
	&& entries.value.every((tx) => !transactionDetail(tx.id))
	&& expandedReadState.value === 'loading')

const reviewUnavailable = computed(() =>
	entries.value.length > 0
	&& entries.value.every((tx) => !transactionDetail(tx.id))
	&& expandedReadState.value === 'unavailable')

/** Bounded prefetch of the sheet's per-transaction details (evidence + decisions + capabilities). */
async function prefetchTransactionDetails(transactionIds?: readonly string[]): Promise<void> {
	const ids = [...new Set((transactionIds ?? entries.value.map((entry) => entry.id)).filter((id): id is string => id !== ''))]
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

async function loadReview(): Promise<void> {
	const item = openSheetItem.value
	if (!item || item.sheetId === '') return
	try {
		await prefetchTransactionDetails()
	} catch {
		// The store classifies failures into managerTransactionReads; the transactions tab renders them.
	}
}

function retryReview(): void {
	void loadReview()
}

async function ensureTransactionDetail(transactionId: string): Promise<TransactionDetail | null> {
	const existing = transactionDetail(transactionId)
	if (existing) return existing
	try {
		return await reviewsStore.fetchManagerTransactionDetail(transactionId)
	} catch {
		return null
	}
}

function closeOverlay(): void {
	activeOverlay.value = 'none'
	submittingTransactionId.value = null
}

/**
 * A thumbnail opens the preview at exactly that document; a tile whose detail read failed cannot
 * open an empty overlay, so it expands the transaction, whose inline region shows the honest read
 * state.
 */
async function openPreviewFromCard(entry: TransactionSummary, evidenceId: string): Promise<void> {
	const detail = await ensureTransactionDetail(entry.id)
	const index = detail?.evidence.findIndex((item) => item.id === evidenceId) ?? -1
	if (detail && index >= 0) {
		previewTransactionId.value = entry.id
		previewIndex.value = index
		activeOverlay.value = 'preview'
		return
	}
	expandedTransactionId.value = entry.id
}

function navigatePreview(index: number): void {
	previewIndex.value = index
}

/**
 * Open the shared confirmation for exactly one transaction. Without the guarded detail read there
 * is neither a version to be optimistic against nor a capability grant to honour, so the inline
 * expansion — whose read state says what happened — opens instead of a confirmation that cannot
 * submit.
 */
async function openConfirmForTransaction(transactionId: string, kind: DecisionValue): Promise<void> {
	if (submittingTransactionId.value !== null) return
	const entry = entries.value.find((candidate) => candidate.id === transactionId)
	if (entry && financeCardDecided(entry)) return
	const detail = await ensureTransactionDetail(transactionId)
	if (detail === null) {
		expandedTransactionId.value = transactionId
		return
	}
	confirmDecision.value = kind
	confirmTransactionId.value = transactionId
	submittingTransactionId.value = transactionId
	activeOverlay.value = 'confirm'
}

/**
 * Decision recorded (UXC-5-05): close the layer, re-render the card from the server's
 * `result.transaction`, drop the now-stale per-transaction detail, refetch the queue so rows
 * reflect authoritative post-decision state, and repoint the open sheet — closing it when the
 * sheet no longer has any finance-pending transaction. Never a client-side invention of final
 * financial state.
 */
async function onDecided(result: DecisionResult): Promise<void> {
	const transactionId = result.transaction.id

	activeOverlay.value = 'none'
	submittingTransactionId.value = null
	expandedTransactionId.value = null
	patchedTransactions.value = { ...patchedTransactions.value, [transactionId]: result.transaction }
	decidedTransactionIds.value = new Set([...decidedTransactionIds.value, transactionId])
	reviewsStore.clearManagerTransactionDetail(transactionId)

	void (async () => {
		try {
			await reviewsStore.fetchFinanceQueue()
			const origin = openSheetItem.value?.sheetId
			if (origin !== undefined && origin !== null && origin !== '') {
				const nextSheet = sheetItems.value.find((sheet) => sheet.sheetId === origin)
				openSheetItem.value = nextSheet ?? null
			}
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

// Opening a sheet resets the overlay stack, the expansion, and runs the bounded prefetch
// (preserved Finance behavior from the shared-shell migration).
watch(
	() => [openSheetItem.value !== null, openSheetItem.value?.sheetId ?? ''] as const,
	([open]) => {
		if (open) {
			activeOverlay.value = 'none'
			expandedTransactionId.value = null
			previewTransactionId.value = ''
			previewIndex.value = 0
			confirmTransactionId.value = ''
			confirmDecision.value = null
			submittingTransactionId.value = null
			void loadReview()
		}
	}
)

async function refresh(): Promise<void> {
	try {
		await reviewsStore.fetchFinanceQueue()
		openSheetItem.value = null
		void prefetchTransactionDetails(sheetItems.value.flatMap((sheet) => sheet.transactions.map((t) => t.id)))
	} catch {
		// The store exposes the failure; the template renders it.
	}
}

onMounted(() => {
	void refresh()
})
</script>

<style scoped>
.queue-view {
	/* Direction comes from the `:dir="direction"` binding only. A hardcoded `direction` here would
	   force RTL even in English LTR — the layout bug the shared utility prevents. */
}

.breadcrumbs {
	margin-block-end: 16px;
}

.breadcrumbs__list {
	display: flex;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
	font-size: var(--font-size-floor);
}

.breadcrumbs__item + .breadcrumbs__item::before {
	content: '/';
	margin-inline-end: 8px;
	color: var(--color-text-maxcontrast);
}

.queue-header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	flex-wrap: wrap;
	margin-block-end: 16px;
}

.queue-header__eyebrow {
	margin: 0 0 4px;
	font-size: var(--font-size-floor);
	font-weight: 700;
	letter-spacing: 0.06em;
	text-transform: uppercase;
	color: var(--color-primary-element);
}

.queue-header__title {
	font-size: var(--font-size-title);
	font-weight: 700;
	margin: 0;
	line-height: 1.2;
}

.queue-header__subtitle {
	margin: 6px 0 0;
	max-inline-size: 62ch;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.queue-header__aside {
	display: flex;
	align-items: center;
	gap: 12px;
}

.queue-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 32px 0;
	text-align: center;
}

.queue-state__icon--error {
	color: var(--color-error-text);
}

.queue-state__icon--warning {
	color: var(--color-warning-text);
}

.queue-state h3 {
	margin: 8px 0 0;
	font-size: 1rem;
	font-weight: 600;
}

.queue-state__message {
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
}

.sheet-panel {
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
}

.sheet-search {
	display: flex;
	align-items: center;
	gap: 10px;
	flex-wrap: wrap;
	margin-block-end: 12px;
}

.sheet-search__label {
	font-size: var(--font-size-floor);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.sheet-search__input {
	flex: 1;
	min-width: 0;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
}

.sheet-queue-no-results {
	margin: 0;
	padding: 24px 0;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.senior-queue-toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	margin-block-end: 12px;
}

.senior-queue-search {
	display: flex;
	align-items: center;
	gap: 6px;
	min-width: min(280px, 100%);
}

.senior-queue-search__input {
	flex: 1;
	min-width: 0;
	padding: 6px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
}

.senior-queue-tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.senior-queue-tabs__tab {
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
	cursor: pointer;
}

.senior-queue-tabs__tab--active {
	border-color: var(--color-primary);
	background-color: var(--color-primary-light, var(--color-primary));
	color: var(--color-primary-text);
}

.senior-queue-no-results {
	margin: 0;
	padding: 24px 0;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

@media (width <= 640px) {
	.senior-queue-tabs {
		inline-size: 100%;
	}

	.senior-queue-tabs__tab {
		flex: 1 1 auto;
		justify-content: center;
		min-block-size: 44px;
	}

	.senior-queue-search {
		min-width: 100%;
	}

	.senior-queue-search__input {
		min-block-size: 44px;
	}
}

/* Finance review content inside the shared Sheet Details Modal slots. */
.finance-identity__reference {
	font-family: 'Noto Sans Mono', monospace;
	unicode-bidi: isolate;
	overflow-wrap: anywhere;
}

.finance-identity__dot {
	opacity: 0.6;
}

.finance-review-state {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
	padding: 16px 0;
	color: var(--color-text-maxcontrast);
}

.finance-review-state h4 {
	margin: 0;
	font-size: 1rem;
	font-weight: 600;
	color: var(--color-main-text);
}

.finance-review-state p {
	margin: 0;
	font-size: var(--font-size-floor);
}

.finance-review-count {
	margin: 0 0 8px;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	font-weight: 600;
}

.finance-transactions {
	margin: 0;
	padding: 0;
	list-style: none;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.finance-overview {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 12px 16px;
	margin: 0;
}

.finance-overview__item dt {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	font-weight: 600;
}

.finance-overview__item dd {
	margin: 2px 0 0;
	min-inline-size: 0;
	white-space: nowrap;
	overflow-wrap: normal;
	font-weight: 500;
}

.finance-overview__money {
	display: block;
	font-family: 'Noto Sans Mono', monospace;
	font-variant-numeric: tabular-nums;
}

@media (width <= 640px) {
	.queue-header {
		flex-direction: column;
		align-items: flex-start;
	}

	.queue-header__aside {
		width: 100%;
	}

	.sheet-search {
		flex-direction: column;
		align-items: stretch;
	}

	.sheet-search__input {
		min-block-size: 44px;
	}
}

/* The row cells are built by `sheetCell()` and rendered inside `ResponsiveTable` (child scope), so
   scoped selectors need `:deep()`. */
:deep(.sheet-row__identity) {
	display: flex;
	align-items: center;
	gap: 10px;
	min-width: 0;
}

:deep(.sheet-row__icon) {
	display: inline-flex;
	color: var(--color-text-maxcontrast);
	flex: none;
}

:deep(.sheet-row__text) {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

:deep(.sheet-row__title-button) {
	max-width: 100%;
	min-width: 0;
	font-size: var(--font-size-floor);
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

:deep(.sheet-row__reference) {
	direction: ltr;
	unicode-bidi: isolate;
	text-align: start;
	font-family: monospace;
	font-size: var(--font-size-floor);
	font-weight: 500;
	overflow-wrap: anywhere;
	color: var(--color-text-maxcontrast);
}

:deep(.sheet-row__count),
:deep(.sheet-row__activity),
:deep(.sheet-row__status) {
	display: block;
	text-align: end;
}

:deep(.sheet-row__returned) {
	font-size: var(--font-size-floor);
	font-weight: 400;
	color: var(--color-text-maxcontrast);
	opacity: 0.7;
	margin-block-start: 2px;
	display: block;
	text-align: end;
}

:deep(.sheet-row__amount) {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 2px;
}

:deep(.sheet-row__money) {
	font-family: 'Noto Sans Mono', monospace;
	font-variant-numeric: tabular-nums;
}

/* FR-005-066: the submitted date cell stays on one line. */
:deep(.responsive-table__desktop .sheet-row__submitted) {
	white-space: nowrap;
}

:deep(.sheet-row__activity) {
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}
</style>

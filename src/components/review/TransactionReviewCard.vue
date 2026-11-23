<template>
	<article
		class="transaction-review-card"
		:class="{ 'transaction-review-card--expanded': expanded }"
		:dir="direction"
		:data-testid="`transaction-review-card-${transaction.id}`"
		@keydown="onCardKeydown"
	>
		<div class="transaction-review-card__main">
			<span class="transaction-review-card__icon" aria-hidden="true">
				<NcIconSvgWrapper :path="mdiReceiptTextOutline" :size="20" />
			</span>
			<div class="transaction-review-card__body">
				<!--
					The title is the card's one explicit interactive control into the transaction's detail:
					a real button, so keyboard activation is native, and it never turns the whole card into
					one ambiguous hit target. When the caller participates in inline expansion (UXC-V2-04) it
					is the disclosure toggle — `aria-expanded` names the state and `aria-controls` points at
					the expanded region while it exists; without that wiring it keeps the established
					`openDetails` contract so existing consumers behave exactly as before.
				-->
				<button
					ref="titleToggle"
					type="button"
					class="transaction-review-card__title"
					data-testid="transaction-card-title"
					:aria-expanded="expandable ? (expanded ? 'true' : 'false') : undefined"
					:aria-controls="expandable && expanded ? expandedRegionId : undefined"
					@click="onTitleActivate"
				>
					{{ description }}
				</button>
				<!--
					First-view transaction date (UXC-V2-03, owner-approved v2 baseline): always visible
					under the title, from the transaction's own authoritative expense-date field. A
					missing date renders the shared absence marker — never a guessed value.
				-->
				<p class="transaction-review-card__date" data-testid="transaction-card-date">
					<span class="transaction-review-card__date-label">{{ t('pettycash', 'reviews.expenseDate') }}</span>
					<span class="transaction-review-card__date-value">{{ formattedExpenseDate }}</span>
				</p>
				<p class="transaction-review-card__meta">
					<span class="transaction-review-card__meta-item">
						<span class="transaction-review-card__meta-label">{{ t('pettycash', 'reviews.quickReview.card.transactionId') }}</span>
						<CompactIdentifier :value="transaction.id" data-testid="transaction-card-id" />
					</span>
					<span class="transaction-review-card__meta-item">
						<span class="transaction-review-card__meta-label">{{ t('pettycash', 'reviews.quickReview.card.costCenter') }}</span>
						<span data-testid="transaction-card-cost-center">{{ costCenter }}</span>
					</span>
					<span class="transaction-review-card__meta-item" data-testid="transaction-card-expense-type">
						<span class="transaction-review-card__meta-label">{{ t('pettycash', 'reviews.expenseType') }}</span>
						<span>{{ expenseType }}</span>
					</span>
				</p>
			</div>
		</div>

		<div class="transaction-review-card__side">
			<strong class="transaction-review-card__amount" data-testid="transaction-card-amount">
				<bdi>{{ formattedAmount }}</bdi>
			</strong>
		<span v-if="!suppressStatusBadge" class="transaction-review-card__status" data-testid="transaction-card-status">
			<StatusBadge :status="transaction.status" />
		</span>
		</div>

		<!--
				Canonical review progress (UXC-V2-02, owner-approved v2 baseline): the compact stage
				identity + state marker row in fixed workflow order — Cost Center → Senior Manager →
				Finance Manager. Informational and read-only; stage labels are visible without hover, and
				state meaning never rests on colour alone. Per-stage decision detail lives in the inline
				expansion (UXC-V2-05/08) once the caller wires it.
			-->
		<div class="transaction-review-card__stages" data-testid="transaction-card-decision-indicators" :aria-label="t('pettycash', 'reviews.priorDecisions')">
			<ReviewStageMarkers :decisions="stageDecisions" test-id-prefix="transaction-card-decision" />
		</div>

		<!--
				Compact summary evidence (UXC-V2-06, owner-approved v2 baseline): up to two tiles plus a
				`+N more` tile, drawn only from the evidence set the caller returned for this transaction.
				An active inline-safe image renders its real fetched thumbnail through the shared tile; every
				other state keeps the honest type-art tile. Full preview interaction lives in the
				EvidencePreview layer; a tile only requests it.
			-->
		<div v-if="attachments.length > 0" class="transaction-review-card__docs" data-testid="transaction-card-attachments">
			<span class="transaction-review-card__docs-label">
				<NcIconSvgWrapper :path="mdiPaperclip" :size="16" aria-hidden="true" />
				{{ t('pettycash', 'reviews.quickReview.card.attachments') }}
				<span class="transaction-review-card__docs-count" data-testid="transaction-card-evidence-count">{{ formatNumber(attachments.length) }}</span>
			</span>
			<div class="transaction-review-card__docs-row">
				<EvidenceTile
					v-for="item in visibleAttachments"
					:key="item.id"
					:item="item"
					class="transaction-review-card__thumb"
					:test-id="`transaction-card-thumb-${item.id}`"
					@open="emit('openPreview', item.id)"
				/>
				<button
					v-if="hiddenAttachmentCount > 0"
					type="button"
					class="transaction-review-card__thumb transaction-review-card__thumb--more"
					:aria-label="t('pettycash', 'reviews.quickReview.card.moreNamed', { count: formatNumber(hiddenAttachmentCount) })"
					:data-testid="`transaction-card-thumb-more-${transaction.id}`"
					@click="openFirstAttachment"
				>
					<span aria-hidden="true">+{{ formatNumber(hiddenAttachmentCount) }}</span>
					<small aria-hidden="true">{{ t('pettycash', 'reviews.quickReview.card.more') }}</small>
				</button>
			</div>
		</div>

		<!--
				The current stage's decide bar (UXC-V2-07): every server-permitted action stays visible here
				without expansion — Approve the only filled/primary control, Return a quiet warning outline,
				Reject a quiet restrained destructive outline. Visibility is an upstream decision
				(`showActions`/`readOnly`); the shared action implementation honours the server's capability
				grant, and `busy` blocks a second submission while one is in flight.
			-->
		<div v-if="showActionBar" class="transaction-review-card__actions">
			<ReviewDecideActions
				v-if="stageActions.length > 0"
				:kinds="stageActions"
				:allowed-actions="allowedActions"
				:busy="busy"
				test-id-prefix="transaction-card-decide"
				@decide="(kind) => emit('decide', kind)"
			/>
		</div>

		<!--
				The Inline Expanded Transaction (UXC-V2-04/05, owner-approved v2 baseline): the drawer's
				functional content — details, evidence, previous decisions, current-stage actions — rendered
				in place as an extension of this card, never as a second modal or a route change. Exactly
				one transaction expands at a time because the caller owns that single state; the card only
				renders what it is told. Focus moves into the region on expand and returns to the toggle on
				collapse (see the watcher); Escape pressed inside the expanded card collapses it before it
				can reach the Sheet Details Modal's own close (see `onCardKeydown`).
			-->
		<div
			v-if="expanded"
			:id="expandedRegionId"
			ref="expandedRegion"
			class="transaction-review-card__expanded"
			role="region"
			:aria-label="t('pettycash', 'reviews.quickReview.drawer.eyebrow')"
			tabindex="-1"
			data-testid="transaction-expanded-detail"
		>
			<TransactionInlineDetail
				:transaction="detail"
				:read-state="detailReadState"
				:sheet="detailSheet"
				:stage="detailStage"
				:busy="busy"
				@decide="(kind) => emit('decide', kind)"
				@preview="(item) => emit('openPreview', item.id)"
			/>
		</div>
	</article>
</template>

<script setup lang="ts">
import { computed, nextTick, useTemplateRef, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import {
	mdiPaperclip,
	mdiReceiptTextOutline,
} from '@mdi/js'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import ReviewStageMarkers, {
	type ReviewStageDecisions,
	type ReviewStageKey,
} from '@/components/review/ReviewStageMarkers.vue'
import ReviewDecideActions from '@/components/review/ReviewDecideActions.vue'
import EvidenceTile from '@/components/review/EvidenceTile.vue'
import CompactIdentifier from '@/components/review/CompactIdentifier.vue'
import TransactionInlineDetail, {
	type TransactionInlineDetailReadState,
	type TransactionInlineDetailSheet,
	type TransactionInlineDetailStageConfig,
} from '@/components/review/TransactionInlineDetail.vue'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import { direction } from '@/utils/direction'
import { formatSheetDate } from '@/utils/date'
import { formatNumber } from '@/utils/number'
import { formatMoney } from '@/utils/money'
import type {
	AllowedActions,
	DecisionValue,
	Evidence,
	TransactionDetail,
	TransactionSummary,
} from '@/types/api'

/**
 * The card's review-progress stage types are the shared marker component's — one definition, one
 * workflow order, one vocabulary across the collapsed card and (later) the inline expansion. The
 * historical card-level names are kept as aliases so existing consumers keep compiling.
 */
export type TransactionReviewCardStageKey = ReviewStageKey

export type TransactionReviewCardStageDecisions = ReviewStageDecisions

interface Props {
	transaction: TransactionSummary
	/**
	 * The transaction's returned evidence set (metadata only). Absent or empty renders no
	 * attachments row — never a fabricated tile.
	 */
	evidence?: Evidence[]
	/** Per-stage previous decisions for the indicator row, in the card's canonical order. */
	stageDecisions?: TransactionReviewCardStageDecisions | null
	/**
	 * The server's capability set for this transaction. A decision button is enabled only on an
	 * explicit `true`; without the grant (or without the detail read that carries it) it stays
	 * disabled.
	 */
	allowedActions?: AllowedActions | null
	/** True while a decision for this transaction is being submitted, so a second cannot start. */
	busy?: boolean
	/**
	 * Upstream sheet-level read-only (a completed sheet, a finished stage). Suppresses the decide
	 * bar; the card never derives read-only from a status itself.
	 */
	readOnly?: boolean
	/**
	 * Whether the current stage offers decide actions at all. Upstream decides — the card renders
	 * the bar for an active stage and omits it where the stage config says the surface has no
	 * decision actions.
	 */
	showActions?: boolean
	/**
		 * Whether the title participates in inline expansion (UXC-V2-04). When `true`, the title is the
		 * disclosure toggle: it emits `toggleExpand`, carries `aria-expanded`/`aria-controls`, and the
		 * card renders the expanded region when `expanded`. When `false` (the default), the title keeps
		 * the established `openDetails` contract so every existing consumer behaves as before.
		 */
	expandable?: boolean
	/**
	 * This card's disclosure state, owned by the caller. The caller owns the one-expanded-at-a-time
	 * rule; the card renders what it is told and never expands on its own.
	 */
	expanded?: boolean
	/**
	 * The guarded per-transaction detail read behind the expanded region. `null` renders the read
	 * state's honest answer (loading / refusal / absence / failure) inside the expansion.
	 */
	detail?: TransactionDetail | null
	/** The detail read's end state, in the shared inline surface's vocabulary. */
	detailReadState?: TransactionInlineDetailReadState
	/** The sheet header facts for the expanded details; `null` renders their unavailable states. */
	detailSheet?: TransactionInlineDetailSheet | null
	/** The current stage's action surface for the expanded proximity row; `null` renders none. */
	detailStage?: TransactionInlineDetailStageConfig | null
	/**
	 * Suppress the transaction-card status badge. The Senior workspace sets this so compound
	 * lifecycle + decision badges (e.g. `Completed approved`) are not shown — per-stage decisions
	 * are already communicated by the stage identity + state marker row. Cost Center and Finance
	 * omit the prop (default `false`) and keep their existing status presentation.
	 */
	suppressStatusBadge?: boolean
}

const props = withDefaults(defineProps<Props>(), {
	evidence: () => [],
	stageDecisions: null,
	allowedActions: null,
	busy: false,
	readOnly: false,
	showActions: true,
	expandable: false,
	expanded: false,
	detail: null,
	detailReadState: 'loading',
	detailSheet: null,
	detailStage: null,
	suppressStatusBadge: false,
})

const emit = defineEmits<{
	openReview: [transactionId: string]
	openDetails: [transactionId: string]
	/** Disclosure intent (UXC-V2-04): the caller toggles its one expanded transaction. */
	toggleExpand: [transactionId: string]
	openPreview: [evidenceId: string]
	decide: [kind: DecisionValue]
}>()

const MAX_CARD_THUMBNAILS = 2

/** The decide bar's canonical order; each button is gated by the server's own grant. */
const stageActions: DecisionValue[] = ['REJECT', 'RETURN', 'APPROVE']

const showActionBar = computed(() => props.showActions && !props.readOnly)

const titleToggleRef = useTemplateRef<HTMLElement>('titleToggle')
const expandedRegionRef = useTemplateRef<HTMLElement>('expandedRegion')

/** The expanded region's document-wide id; `aria-controls` points here while the region exists. */
const expandedRegionId = computed(() => `transaction-expanded-${props.transaction.id}`)

function onTitleActivate(): void {
	if (props.expandable) {
		emit('toggleExpand', props.transaction.id)
		return
	}
	emit('openDetails', props.transaction.id)
}

/**
 * Deterministic Escape layering (UXC-V2-04): while this card is expanded, an Escape pressed
 * anywhere inside the card collapses the expansion first and never reaches the Sheet Details
 * Modal's own close. The lightweight overlays (Evidence Preview, Decision Confirmation) keep their
 * own Escape handling above the modal; Escape pressed outside the expanded card still closes the
 * modal. When no expansion is open the event bubbles untouched, exactly as before.
 */
function onCardKeydown(event: KeyboardEvent): void {
	if (props.expanded && event.key === 'Escape') {
		event.preventDefault()
		event.stopPropagation()
		emit('toggleExpand', props.transaction.id)
	}
}

/**
 * The nearest ancestor that actually scrolls this region. The card is shared by every role, so it
 * must not name the Sheet modal's private class; the scroll container is discovered from layout.
 */
function nearestScrollableAncestor(element: HTMLElement): HTMLElement | null {
	let ancestor = element.parentElement
	while (ancestor !== null) {
		const style = window.getComputedStyle(ancestor)
		if (/(auto|scroll)/.test(style.overflowY) && ancestor.scrollHeight > ancestor.clientHeight) {
			return ancestor
		}
		ancestor = ancestor.parentElement
	}
	return null
}

/**
 * Focus the expanded region without the browser's default `nearest` alignment. A tall expansion
 * inside the fixed-height Sheet modal would otherwise be scrolled to its bottom, so the reader
 * would land mid-detail (measured at 390px: the region's top sat 375px above the viewport). When
 * the region does not start in view, align its start with the scroll container's start. Desktop,
 * where the region already starts in view, is left untouched.
 */
function focusExpandedRegion(): void {
	const region = expandedRegionRef.value
	if (region === null) return
	region.focus({ preventScroll: true })
	const container = nearestScrollableAncestor(region)
	if (container === null) return
	const containerRect = container.getBoundingClientRect()
	const regionRect = region.getBoundingClientRect()
	if (regionRect.top < containerRect.top || regionRect.top >= containerRect.bottom) {
		container.scrollTop += regionRect.top - containerRect.top
	}
}

/**
 * Disclosure focus lifecycle (UXC-V2-04): expanding moves focus into the expanded region so
 * keyboard use follows the disclosure; collapsing returns focus to the originating toggle — but
 * only when focus is still inside this card's region. A sibling expansion opening in the same
 * update must not have its region focus stolen back by this card's collapse.
 */
watch(() => props.expanded, (expanded, previous) => {
	if (previous === expanded) return
	if (expanded) {
		void nextTick(focusExpandedRegion)
		return
	}
	const region = expandedRegionRef.value
	const active = document.activeElement
	if (region !== null && active instanceof HTMLElement && region.contains(active)) {
		void nextTick(() => titleToggleRef.value?.focus())
	}
})

/** Only ever the transaction's own returned evidence; the list order is the server's. */
const attachments = computed(() => props.evidence ?? [])
const visibleAttachments = computed(() => attachments.value.slice(0, MAX_CARD_THUMBNAILS))
const hiddenAttachmentCount = computed(() => Math.max(0, attachments.value.length - MAX_CARD_THUMBNAILS))

function openFirstAttachment(): void {
	// The `+N more` tile opens the preview at the first attachment, where the user navigates forward.
	const first = attachments.value[0]
	if (first) emit('openPreview', first.id)
}

const description = computed(() => {
	const value = props.transaction.activeRevision?.description?.trim()
	return value && value !== ''
		? value
		: t('pettycash', 'reviews.quickReview.card.noDescription')
})

const costCenter = computed(() => {
	const center = props.transaction.activeRevision?.costCenter
	return center?.code || center?.name || t('pettycash', 'reviews.quickReview.card.unavailable')
})

const expenseType = computed(() => {
	const type = props.transaction.activeRevision?.expenseType
	return type?.name || t('pettycash', 'reviews.quickReview.card.unavailable')
})

/**
 * The transaction's own expense date, the mandatory first-view date (UXC-V2-03). The shared date
 * renderer answers a missing value with the honest absence marker, never an epoch.
 */
const formattedExpenseDate = computed(() =>
	formatSheetDate(props.transaction.activeRevision?.expenseDate ?? null)
)

const formattedAmount = computed(() => formatMoney(props.transaction.activeRevision?.amount ?? null))
</script>

<style scoped>
.transaction-review-card {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto;
	gap: 10px 18px;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	background-color: var(--color-main-background);
}

.transaction-review-card__main {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	min-inline-size: 0;
}

.transaction-review-card__icon {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: none;
	inline-size: 36px;
	block-size: 36px;
	border-radius: 9px;
	background-color: var(--color-background-hover);
	color: var(--color-primary-element);
}

.transaction-review-card__body {
	min-inline-size: 0;
}

.transaction-review-card__title {
	display: block;
	inline-size: 100%;
	padding: 0;
	border: 0;
	background: transparent;
	color: var(--color-main-text);
	font-family: inherit;
	font-size: var(--font-size-emphasis);
	font-weight: 700;
	line-height: 1.35;
	text-align: start;
	overflow-wrap: anywhere;
	cursor: pointer;
	border-radius: 6px;
}

.transaction-review-card__title:hover,
.transaction-review-card__title:focus-visible {
	color: var(--color-primary-element);
}

.transaction-review-card__title:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

/* The mandatory first-view transaction date (UXC-V2-03): quiet line directly under the title. */
.transaction-review-card__date {
	display: flex;
	align-items: baseline;
	gap: 6px;
	margin: 4px 0 0;
	font-size: var(--font-size-floor);
	line-height: 1.3;
}

.transaction-review-card__date-label {
	flex: none;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.transaction-review-card__date-value {
	color: var(--color-main-text);
	font-variant-numeric: tabular-nums;
	overflow-wrap: anywhere;
}

.transaction-review-card__meta {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin: 2px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.transaction-review-card__meta-item {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	min-inline-size: 0;
}

.transaction-review-card__meta-item + .transaction-review-card__meta-item::before {
	content: '·';
	margin-inline-end: 3px;
}

.transaction-review-card__meta-label {
	font-weight: 600;
	flex: none;
	white-space: nowrap;
}

.transaction-review-card__id {
	font-family: monospace;
	unicode-bidi: isolate;
	overflow-wrap: anywhere;
}

.transaction-review-card__side {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 6px;
	min-inline-size: 0;
	text-align: end;
}

.transaction-review-card__amount {
	font-size: var(--font-size-section);
	font-weight: 700;
	line-height: 1.15;
	font-variant-numeric: tabular-nums;
	overflow-wrap: anywhere;
}

.transaction-review-card__status {
	display: inline-flex;
	max-inline-size: 100%;
}

/*
	 * The attachments row (UXC-V2-06): each tile is the shared `EvidenceTile` — a real fetched image
	 * thumbnail when the item is an active inline-safe image, the honest type-art tile in every other
	 * state. This card owns only the tile boxes; the component owns appearance and lifecycle.
	 */
	.transaction-review-card__docs {
		grid-column: 1 / -1;
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

.transaction-review-card__docs-label {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	font-weight: 600;
}

.transaction-review-card__docs-count {
	padding: 0 7px;
	border-radius: 999px;
	background-color: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
	color: var(--color-primary-element);
	font-weight: 600;
}

.transaction-review-card__docs-row {
	display: flex;
	align-items: stretch;
	gap: 8px;
	flex-wrap: wrap;
}

/* The card owns the tile box; the shared EvidenceTile owns appearance, hover and lifecycle. */
.transaction-review-card__thumb {
	flex: none;
	inline-size: 74px;
	block-size: 54px;
}

.transaction-review-card__thumb--more {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 2px;
	flex: none;
	inline-size: 74px;
	block-size: 54px;
	padding: 4px;
	border: 1px dashed var(--color-border);
	border-radius: 8px;
	background-color: transparent;
	color: var(--color-text-maxcontrast);
	font-family: inherit;
	cursor: pointer;
	transition: border-color 0.15s ease, background-color 0.15s ease;
}

.transaction-review-card__thumb--more:hover,
.transaction-review-card__thumb--more:focus-visible {
	border-color: var(--color-primary-element);
	background-color: color-mix(in srgb, var(--color-primary-element) 8%, var(--color-main-background));
}

.transaction-review-card__thumb--more:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.transaction-review-card__thumb--more small {
	font-size: var(--font-size-floor);
	line-height: 1;
}

/*
 * The review-progress row (UXC-V2-02): the shared compact stage identity + state marker component
 * renders inside this full-width slot; the wrapper owns only the grid placement.
 */
.transaction-review-card__stages {
	grid-column: 1 / -1;
	min-inline-size: 0;
}

.transaction-review-card__actions {
	grid-column: 1 / -1;
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	justify-content: flex-end;
}

/*
 * The expanded state (UXC-V2-04/05, owner-approved v2 baseline): a subtle accent edge on the
 * reading side and a soft surface tint — the expansion reads as a continuation of this card, not a
 * new panel; there is no nested border of its own.
 */
.transaction-review-card--expanded {
	border-inline-start: 3px solid var(--color-primary-element);
	background-color: color-mix(in srgb, var(--color-primary-element) 4%, var(--color-main-background));
}

.transaction-review-card__expanded {
	grid-column: 1 / -1;
	min-inline-size: 0;
}

@media (width <= 640px) {
	.transaction-review-card {
		grid-template-columns: minmax(0, 1fr);
		gap: 10px;
		padding: 12px;
	}

	.transaction-review-card__meta {
		flex-direction: column;
		align-items: flex-start;
		gap: 2px;
	}

	.transaction-review-card__meta-item + .transaction-review-card__meta-item::before {
		content: none;
	}

	.transaction-review-card__meta-item {
		flex-direction: column;
		align-items: flex-start;
		gap: 0;
	}

	.transaction-review-card__side {
		align-items: flex-start;
		text-align: start;
	}

	.transaction-review-card__amount {
		font-size: var(--font-size-emphasis);
	}

	.transaction-review-card__actions {
		justify-content: stretch;
	}
}

@media (prefers-reduced-motion: reduce) {
	.transaction-review-card,
	.transaction-review-card * {
		transition: none !important;
		animation: none !important;
	}
}
</style>

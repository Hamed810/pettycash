<template>
	<article
		class="quick-review-card"
		:class="cardClasses"
		:data-testid="`quick-review-card-${transaction.id}`"
	>
		<div class="quick-review-card__main">
			<span class="quick-review-card__icon" aria-hidden="true">
				<NcIconSvgWrapper :path="mdiReceiptTextOutline" :size="20" />
			</span>
			<div class="quick-review-card__body">
				<h4 class="quick-review-card__title" data-testid="quick-review-description">
					{{ description }}
				</h4>
				<p class="quick-review-card__meta">
					<span class="quick-review-card__meta-item">
						<span class="quick-review-card__meta-label">{{ t('pettycash', 'reviews.quickReview.card.transactionId') }}</span>
						<bdi class="quick-review-card__id" data-testid="quick-review-id">{{ transaction.id }}</bdi>
					</span>
					<span class="quick-review-card__meta-item">
						<span class="quick-review-card__meta-label">{{ t('pettycash', 'reviews.quickReview.card.costCenter') }}</span>
						<span data-testid="quick-review-cost-center">{{ costCenter }}</span>
					</span>
				</p>
				<p class="quick-review-card__unavailable" data-testid="quick-review-meta-unavailable">
					{{ t('pettycash', 'reviews.quickReview.card.metaUnavailable') }}
				</p>
			</div>
		</div>

		<div class="quick-review-card__side">
			<strong class="quick-review-card__amount" data-testid="quick-review-amount">
				<bdi>{{ formattedAmount }}</bdi>
			</strong>
			<span class="quick-review-card__status" data-testid="quick-review-status">
				<StatusBadge :status="transaction.status" :label="stateLabel" />
			</span>
		</div>

		<!--
			T033: up to two evidence tiles plus a `+N more` tile, from the transaction's returned
			evidence set only. Each tile is an affordance into the preview overlay — never an inline
			renderer of untrusted content — and opens it at exactly that document (FR-006-040).
		-->
		<div v-if="attachments.length > 0" class="quick-review-card__docs" data-testid="quick-review-attachments">
			<span class="quick-review-card__docs-label">
				<NcIconSvgWrapper :path="mdiPaperclip" :size="16" aria-hidden="true" />
				{{ t('pettycash', 'reviews.quickReview.card.attachments') }}
				<span class="quick-review-card__docs-count">{{ formatNumber(attachments.length) }}</span>
			</span>
			<div class="quick-review-card__docs-row">
				<button
					v-for="item in visibleAttachments"
					:key="item.id"
					type="button"
					class="quick-review-card__thumb"
					:aria-label="t('pettycash', 'reviews.quickReview.card.opensPreviewNamed', { name: item.name })"
					:title="item.name"
					:data-testid="`quick-review-thumb-${item.id}`"
					@click="emit('openPreview', item.id)"
				>
					<span class="quick-review-card__thumb-art" aria-hidden="true">
						<NcIconSvgWrapper :path="evidenceIcon(item)" :size="22" />
					</span>
					<span class="quick-review-card__thumb-label" aria-hidden="true">{{ extensionOf(item) }}</span>
				</button>
				<button
					v-if="hiddenAttachmentCount > 0"
					type="button"
					class="quick-review-card__thumb quick-review-card__thumb--more"
					:aria-label="t('pettycash', 'reviews.quickReview.card.moreNamed', { count: formatNumber(hiddenAttachmentCount) })"
					:data-testid="`quick-review-thumb-more-${transaction.id}`"
					@click="openFirstAttachment"
				>
					<span aria-hidden="true">+{{ formatNumber(hiddenAttachmentCount) }}</span>
					<small aria-hidden="true">{{ t('pettycash', 'reviews.quickReview.card.more') }}</small>
				</button>
			</div>
		</div>

		<div
			v-if="isDecided"
			class="quick-review-card__note"
			:class="noteClass"
			data-testid="quick-review-decision-note"
		>
			<NcIconSvgWrapper :path="noteIcon" :size="18" aria-hidden="true" />
			<div class="quick-review-card__note-body">
				<p class="quick-review-card__note-title">
					<strong>{{ stateLabel }}</strong>
					<span class="quick-review-card__note-meta" data-testid="quick-review-decision-meta">
						{{ decisionMeta }}
					</span>
				</p>
				<p v-if="explanation !== ''" class="quick-review-card__note-text" data-testid="quick-review-decision-explanation">
					{{ explanation }}
				</p>
				<p v-else-if="isReturned" class="quick-review-card__note-text" data-testid="quick-review-decision-explanation">
					{{ t('pettycash', 'reviews.quickReview.card.noExplanation') }}
				</p>
			</div>
		</div>

		<!--
			T027/T032: the undecided card's action bar — the card is one of the two entry points into
			the shared confirmation workflow. The decision buttons are transaction-scoped and enabled
			only on an explicit server-granted capability (T026); `busy` blocks a second submission
			while one is in flight. A decided card keeps its note block and no decision actions.
		-->
		<div v-if="!isDecided" class="quick-review-card__actions">
			<NcButton
				variant="error"
				:disabled="busy || !canDecide('REJECT')"
				data-testid="quick-review-decide-reject"
				@click="emit('decide', 'REJECT')"
			>
				{{ t('pettycash', 'reviews.decision.reject') }}
			</NcButton>
			<NcButton
				variant="secondary"
				:disabled="busy || !canDecide('RETURN')"
				data-testid="quick-review-decide-return"
				@click="emit('decide', 'RETURN')"
			>
				{{ t('pettycash', 'reviews.decision.return') }}
			</NcButton>
			<span class="quick-review-card__actions-spacer" aria-hidden="true" />
			<NcButton
				variant="tertiary"
				:aria-label="t('pettycash', 'reviews.quickReview.card.reviewNamed', { description })"
				:data-testid="`review-transaction-${transaction.id}`"
				@click="emit('openReview', transaction.id)"
			>
				{{ t('pettycash', 'reviews.quickReview.card.review') }}
			</NcButton>
			<NcButton
				variant="tertiary"
				data-testid="quick-review-full-details"
				@click="emit('openDetails', transaction.id)"
			>
				{{ t('pettycash', 'reviews.quickReview.card.fullDetails') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy || !canDecide('APPROVE')"
				data-testid="quick-review-decide-approve"
				@click="emit('decide', 'APPROVE')"
			>
				{{ t('pettycash', 'reviews.decision.approve') }}
			</NcButton>
		</div>
	</article>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import {
	mdiAlertCircleOutline,
	mdiCheckCircleOutline,
	mdiFile,
	mdiFileDocumentOutline,
	mdiFilePdfBox,
	mdiImage,
	mdiPaperclip,
	mdiReceiptTextOutline,
	mdiRestore,
} from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import { decisionAllowed } from '@/types/api'
import { formatNumber } from '@/utils/number'
import { formatSheetDateTime } from '@/utils/date'
import { formatMoney } from '@/utils/money'
import type {
	AllowedActions,
	Decision,
	DecisionValue,
	Evidence,
	ManagerTransactionState,
	TransactionSummary,
} from '@/types/api'

interface Props {
	transaction: TransactionSummary
	/** This manager's own state. Never inferred from the global transaction status. */
	managerState: ManagerTransactionState
	/** Present only when the server returned a manager decision for the current revision. */
	managerDecision: Decision | null
	/**
	 * T033: the transaction's returned evidence set (metadata only), from the guarded per-transaction
	 * detail read. Absent or empty renders no attachments row — never a fabricated tile.
	 */
	evidence?: Evidence[]
	/**
	 * T026: the server's capability set for this transaction. A decision button is enabled only on
	 * an explicit `true`; without the detail read (or without the grant) it stays disabled.
	 */
	allowedActions?: AllowedActions | null
	/** True while a decision for this panel is being submitted, so a second cannot start. */
	busy?: boolean
}

const props = withDefaults(defineProps<Props>(), {
	evidence: () => [],
	allowedActions: null,
	busy: false,
})

const emit = defineEmits<{
	openReview: [transactionId: string]
	openDetails: [transactionId: string]
	openPreview: [evidenceId: string]
	decide: [kind: DecisionValue]
}>()

const MAX_CARD_THUMBNAILS = 2

const isReturned = computed(() => props.managerState === 'RETURNED_FOR_CORRECTION')
const isDecided = computed(() => props.managerState !== 'AWAITING_MANAGER_DECISION')

/** Only ever the transaction's own returned evidence; the list order is the server's. */
const attachments = computed(() => props.evidence ?? [])
const visibleAttachments = computed(() => attachments.value.slice(0, MAX_CARD_THUMBNAILS))
const hiddenAttachmentCount = computed(() => Math.max(0, attachments.value.length - MAX_CARD_THUMBNAILS))

function openFirstAttachment(): void {
	// The `+N more` tile opens the preview at the first attachment, where the user navigates forward.
	const first = attachments.value[0]
	if (first) emit('openPreview', first.id)
}

function canDecide(kind: DecisionValue): boolean {
	return decisionAllowed(props.allowedActions, kind)
}

function evidenceIcon(item: Evidence): string {
	const mediaType = (item.mediaType ?? '').split(';')[0]?.trim().toLowerCase() ?? ''
	if (mediaType.startsWith('image/')) return mdiImage
	if (mediaType === 'application/pdf') return mdiFilePdfBox
	if (mediaType.startsWith('application/')) return mdiFileDocumentOutline
	return mdiFile
}

function extensionOf(item: Evidence): string {
	const name = item.name ?? ''
	const dot = name.lastIndexOf('.')
	return dot >= 0 && dot < name.length - 1 ? name.slice(dot + 1).toLocaleUpperCase() : '—'
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

const formattedAmount = computed(() => formatMoney(props.transaction.activeRevision?.amount))

const stateLabel = computed(() => {
	if (isReturned.value) {
		return t('pettycash', 'reviews.quickReview.card.returned')
	}
	if (!isDecided.value) {
		return t('pettycash', 'reviews.quickReview.card.awaiting')
	}
	switch (props.managerDecision?.decision) {
	case 'APPROVE':
		return t('pettycash', 'reviews.quickReview.card.approvedByYou')
	case 'REJECT':
		return t('pettycash', 'reviews.quickReview.card.rejectedByYou')
	default:
		return t('pettycash', 'reviews.quickReview.card.decidedByYou')
	}
})

const cardClasses = computed(() => ({
	'quick-review-card--decided': isDecided.value,
	'quick-review-card--returned': isReturned.value,
	'quick-review-card--rejected': isDecided.value && !isReturned.value && props.managerDecision?.decision === 'REJECT',
}))

const noteClass = computed(() => ({
	'quick-review-card__note--returned': isReturned.value,
	'quick-review-card__note--rejected': isDecided.value && !isReturned.value && props.managerDecision?.decision === 'REJECT',
}))

const noteIcon = computed(() => {
	if (isReturned.value) return mdiRestore
	return props.managerDecision?.decision === 'REJECT' ? mdiAlertCircleOutline : mdiCheckCircleOutline
})

/**
 * The decision metadata is rendered only from the server's decision. An absent decision on a
 * decided card is an unavailable fact, not an invitation to print a guessed date or actor.
 */
const decisionMeta = computed(() => {
	if (!props.managerDecision) {
		return t('pettycash', 'reviews.quickReview.card.decisionUnavailable')
	}
	return t('pettycash', 'reviews.quickReview.card.decidedMeta', {
		date: formatSheetDateTime(props.managerDecision.decidedAt),
		actor: props.managerDecision.actor.displayName,
	})
})

const explanation = computed(() => props.managerDecision?.explanation?.trim() ?? '')
</script>

<style scoped>
.quick-review-card {
	position: relative;
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto;
	gap: 10px 18px;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	background-color: var(--color-main-background);
}

.quick-review-card::before {
	content: '';
	position: absolute;
	inset-block: 12px;
	inset-inline-start: 0;
	inline-size: 3px;
	border-radius: 0 3px 3px 0;
	background-color: var(--color-warning);
}

.quick-review-card--decided::before {
	background-color: var(--color-success);
}

.quick-review-card--returned::before {
	background-color: var(--pettycash-review);
}

.quick-review-card--rejected::before {
	background-color: var(--color-error);
}

.quick-review-card__main {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	min-inline-size: 0;
}

.quick-review-card__icon {
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

.quick-review-card__body {
	min-inline-size: 0;
}

.quick-review-card__title {
	margin: 0;
	font-size: var(--font-size-emphasis);
	font-weight: 700;
	line-height: 1.35;
	overflow-wrap: anywhere;
}

.quick-review-card__meta {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.quick-review-card__meta-item {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	min-inline-size: 0;
}

.quick-review-card__meta-item + .quick-review-card__meta-item::before {
	content: '·';
	margin-inline-end: 3px;
}

.quick-review-card__meta-label {
	font-weight: 600;
	flex: none;
	white-space: nowrap;
}

.quick-review-card__id {
	font-family: monospace;
	unicode-bidi: isolate;
	overflow-wrap: anywhere;
}

.quick-review-card__unavailable {
	margin: 5px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	line-height: 1.4;
}

.quick-review-card__side {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 6px;
	min-inline-size: 0;
	text-align: end;
}

.quick-review-card__amount {
	font-size: var(--font-size-section);
	font-weight: 700;
	line-height: 1.15;
	font-variant-numeric: tabular-nums;
	overflow-wrap: anywhere;
}

.quick-review-card__status {
	display: inline-flex;
	max-inline-size: 100%;
}

.quick-review-card__note {
	grid-column: 1 / -1;
	display: flex;
	align-items: flex-start;
	gap: 8px;
	padding: 9px 11px;
	border: 1px solid color-mix(in srgb, var(--color-success) 20%, var(--color-border));
	border-radius: 8px;
	background-color: color-mix(in srgb, var(--color-success) 8%, var(--color-main-background));
	color: var(--color-main-text);
}

.quick-review-card__note--returned {
	border-color: color-mix(in srgb, var(--pettycash-review) 24%, var(--color-border));
	background-color: color-mix(in srgb, var(--pettycash-review) 9%, var(--color-main-background));
}

.quick-review-card__note--rejected {
	border-color: color-mix(in srgb, var(--color-error) 24%, var(--color-border));
	background-color: color-mix(in srgb, var(--color-error) 8%, var(--color-main-background));
}

.quick-review-card__note-body {
	min-inline-size: 0;
}

.quick-review-card__note-title {
	margin: 0;
	font-size: var(--font-size-floor);
	line-height: 1.4;
}

.quick-review-card__note-meta {
	color: var(--color-text-maxcontrast);
	font-weight: 400;
}

.quick-review-card__note-text {
	margin: 3px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	line-height: 1.45;
	overflow-wrap: anywhere;
}

/*
 * T033: the attachments row. The tiles are type-art (icon + extension), not fetched image blobs —
 * there is no backend thumbnail endpoint, and downloading full evidence files just to decorate a
 * card row would be wasteful and a wider attack surface than metadata warrants. Sizes follow the
 * approved mockup's doc-thumb proportions (74×54), with the dashed `+N more` tile beside them.
 */
.quick-review-card__docs {
	grid-column: 1 / -1;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.quick-review-card__docs-label {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	font-weight: 600;
}

.quick-review-card__docs-count {
	padding: 0 7px;
	border-radius: 999px;
	background-color: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
	color: var(--color-primary-element);
	font-weight: 600;
}

.quick-review-card__docs-row {
	display: flex;
	align-items: stretch;
	gap: 8px;
	flex-wrap: wrap;
}

.quick-review-card__thumb {
	position: relative;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 2px;
	inline-size: 74px;
	block-size: 54px;
	padding: 4px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background-color: var(--color-background-hover);
	color: var(--color-primary-element);
	cursor: pointer;
	transition: border-color 0.15s ease, background-color 0.15s ease;
}

.quick-review-card__thumb:hover,
.quick-review-card__thumb:focus-visible {
	border-color: var(--color-primary-element);
	background-color: color-mix(in srgb, var(--color-primary-element) 8%, var(--color-main-background));
}

.quick-review-card__thumb-art {
	display: inline-flex;
	align-items: center;
	justify-content: center;
}

.quick-review-card__thumb-label {
	max-inline-size: 100%;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	font-weight: 600;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.quick-review-card__thumb--more {
	border-style: dashed;
	color: var(--color-text-maxcontrast);
	background-color: transparent;
}

.quick-review-card__thumb--more small {
	font-size: var(--font-size-floor);
	line-height: 1;
}

.quick-review-card__actions {
	grid-column: 1 / -1;
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
	justify-content: flex-end;
}

.quick-review-card__actions-spacer {
	flex: 1 1 auto;
	min-inline-size: 12px;
}

@media (width <= 640px) {
	.quick-review-card {
		grid-template-columns: minmax(0, 1fr);
		gap: 10px;
		padding: 12px;
	}

	.quick-review-card__meta {
		flex-direction: column;
		align-items: flex-start;
		gap: 2px;
	}

	.quick-review-card__meta-item + .quick-review-card__meta-item::before {
		content: none;
	}

	.quick-review-card__meta-item {
		flex-direction: column;
		align-items: flex-start;
		gap: 0;
	}

	.quick-review-card__side {
		align-items: flex-start;
		text-align: start;
	}

	.quick-review-card__amount {
		font-size: var(--font-size-emphasis);
	}

	.quick-review-card__actions {
		justify-content: stretch;
	}

	.quick-review-card__actions :deep(.button-vue) {
		inline-size: 100%;
		justify-content: center;
	}
}
</style>

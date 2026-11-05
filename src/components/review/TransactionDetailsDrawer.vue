<template>
	<div
		v-if="open"
		class="details-drawer-layer"
		:dir="direction"
		data-testid="details-drawer-layer"
	>
		<div
			class="details-drawer-layer__scrim"
			data-testid="details-drawer-scrim"
			@click="close"
		/>
		<aside
			ref="rootRef"
			class="details-drawer"
			role="dialog"
			aria-modal="true"
			:aria-labelledby="TITLE_ID"
			tabindex="-1"
			data-testid="details-drawer"
			@keydown="onKeydown"
		>
			<header class="details-drawer__header">
				<div class="details-drawer__head-row">
					<div class="details-drawer__titles">
						<p class="details-drawer__eyebrow">{{ t('pettycash', 'reviews.quickReview.drawer.eyebrow') }}</p>
						<h2 :id="TITLE_ID" class="details-drawer__title" data-testid="details-drawer-title">
							{{ title }}
						</h2>
						<p class="details-drawer__meta">
							<bdi dir="ltr" class="details-drawer__meta-id" data-testid="details-drawer-transaction-id">{{ transaction?.id }}</bdi>
							<span data-testid="details-drawer-sheet-title" :class="{ 'details-drawer__meta--unavailable': !sheetTitleAvailable }">
								{{ sheet?.title?.trim() || t('pettycash', 'reviews.sheetQueue.sheetTitleUnavailable') }}
							</span>
							<bdi dir="ltr" data-testid="details-drawer-sheet-reference">{{ transaction?.sheetId }}</bdi>
						</p>
					</div>
					<NcButton
						variant="tertiary"
						:aria-label="t('pettycash', 'common.close')"
						data-testid="details-drawer-close"
						@click="close"
					>
						<NcIconSvgWrapper :path="mdiClose" :size="18" />
					</NcButton>
				</div>

				<nav class="details-drawer__tabs" role="tablist" :aria-label="t('pettycash', 'reviews.quickReview.drawer.tabsLabel')">
					<button
						type="button"
						role="tab"
						class="details-drawer__tab"
						:class="{ 'details-drawer__tab--active': activeTab === 'description' }"
						:aria-selected="activeTab === 'description'"
						data-testid="drawer-tab-description"
						@click="activeTab = 'description'"
						@keydown.arrow-right.prevent="focusTab(1)"
						@keydown.arrow-left.prevent="focusTab(-1)"
					>
						{{ t('pettycash', 'reviews.quickReview.drawer.tabDescription') }}
					</button>
					<button
						type="button"
						role="tab"
						class="details-drawer__tab"
						:class="{ 'details-drawer__tab--active': activeTab === 'history' }"
						:aria-selected="activeTab === 'history'"
						data-testid="drawer-tab-history"
						@click="activeTab = 'history'"
						@keydown.arrow-right.prevent="focusTab(1)"
						@keydown.arrow-left.prevent="focusTab(-1)"
					>
						{{ t('pettycash', 'reviews.quickReview.drawer.tabHistory') }}
					</button>
				</nav>
			</header>

			<div class="details-drawer__body">
				<div v-if="readState === 'loading'" class="details-drawer__state" data-testid="details-drawer-loading">
					<NcLoadingIcon :size="32" />
					<p>{{ t('pettycash', 'reviews.quickReview.loading') }}</p>
				</div>

				<!-- A refusal, an absence and a failure are different answers; each says which it is. -->
				<div v-else-if="readState === 'forbidden'" class="details-drawer__state details-drawer__state--unavailable" data-testid="details-drawer-forbidden">
					<NcIconSvgWrapper :path="mdiShieldLockOutline" :size="28" aria-hidden="true" />
					<p>{{ t('pettycash', 'reviews.quickReview.drawer.forbidden') }}</p>
				</div>

				<div v-else-if="readState === 'notFound'" class="details-drawer__state details-drawer__state--unavailable" data-testid="details-drawer-not-found">
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" :size="28" aria-hidden="true" />
					<p>{{ t('pettycash', 'reviews.quickReview.drawer.notFound') }}</p>
				</div>

				<div v-else-if="transaction === null" class="details-drawer__state details-drawer__state--unavailable" data-testid="details-drawer-unavailable">
					<NcIconSvgWrapper :path="mdiAlertCircleOutline" :size="28" aria-hidden="true" />
					<p>{{ t('pettycash', 'reviews.quickReview.drawer.loadFailed') }}</p>
				</div>

				<template v-else>
					<div v-show="activeTab === 'description'" data-testid="drawer-panel-description">
						<div class="details-drawer__hero">
							<div>
								<div class="details-drawer__amount" data-testid="details-drawer-amount">
									<bdi>{{ amountLabel }}</bdi>
								</div>
								<p class="details-drawer__caption">
									{{ t('pettycash', 'reviews.amount') }} ·
									<span data-testid="details-drawer-expense-date">{{ expenseDateLabel }}</span>
								</p>
							</div>
							<div class="details-drawer__hero-side">
								<span data-testid="details-drawer-manager-state">
									<StatusBadge :status="transaction.status" :label="managerStateLabel" />
								</span>
								<span class="details-drawer__caption" data-testid="details-drawer-cost-center-scope">
									{{ costCenterLabel }}
								</span>
							</div>
						</div>

						<dl class="details-drawer__grid" data-testid="details-drawer-grid">
							<div class="details-drawer__item">
								<dt>{{ t('pettycash', 'reviews.quickReview.drawer.sheet') }}</dt>
								<dd data-testid="drawer-field-sheet">
									<span v-if="sheetTitleAvailable" class="details-drawer__value">{{ sheet?.title }}</span>
									<span v-else class="details-drawer__value--unavailable">{{ t('pettycash', 'reviews.sheetQueue.sheetTitleUnavailable') }}</span>
									<bdi class="details-drawer__reference">{{ transaction.sheetId }}</bdi>
								</dd>
							</div>
							<div class="details-drawer__item">
								<dt>{{ t('pettycash', 'reviews.quickReview.drawer.purchasedBy') }}</dt>
								<dd data-testid="drawer-field-purchaser">
									<span v-if="purchaserName !== ''" class="details-drawer__value">
										{{ purchaserName }} <span class="details-drawer__role">({{ t('pettycash', 'roles.purchaser') }})</span>
									</span>
									<span v-else class="details-drawer__value--unavailable">{{ t('pettycash', 'reviews.sheetQueue.purchaserUnavailable') }}</span>
								</dd>
							</div>
							<div class="details-drawer__item">
								<dt>{{ t('pettycash', 'reviews.quickReview.drawer.merchant') }}</dt>
								<dd class="details-drawer__value--unavailable" data-testid="drawer-field-merchant">
									{{ t('pettycash', 'reviews.sheetQueue.unavailableField') }}
								</dd>
							</div>
							<div class="details-drawer__item">
								<dt>{{ t('pettycash', 'reviews.quickReview.drawer.paidOn') }}</dt>
								<dd class="details-drawer__value--unavailable" data-testid="drawer-field-paid-on">
									{{ t('pettycash', 'reviews.sheetQueue.unavailableField') }}
								</dd>
							</div>
							<div class="details-drawer__item">
								<dt>{{ t('pettycash', 'reviews.quickReview.drawer.method') }}</dt>
								<dd class="details-drawer__value--unavailable" data-testid="drawer-field-method">
									{{ t('pettycash', 'reviews.sheetQueue.unavailableField') }}
								</dd>
							</div>
							<div class="details-drawer__item">
								<dt>{{ t('pettycash', 'reviews.quickReview.drawer.category') }}</dt>
								<dd class="details-drawer__value--unavailable" data-testid="drawer-field-category">
									{{ t('pettycash', 'reviews.sheetQueue.unavailableField') }}
								</dd>
							</div>
							<div class="details-drawer__item">
								<dt>{{ t('pettycash', 'reviews.costCenter') }}</dt>
								<dd class="details-drawer__value" data-testid="drawer-field-cost-center">{{ costCenterLabel }}</dd>
							</div>
							<div class="details-drawer__item">
								<dt>{{ t('pettycash', 'reviews.sheetQueue.status') }}</dt>
								<dd class="details-drawer__value" data-testid="drawer-field-status">
									<StatusBadge :status="transaction.status" />
								</dd>
							</div>
						</dl>

						<section class="details-drawer__section">
							<h3 class="details-drawer__section-title">{{ t('pettycash', 'reviews.quickReview.drawer.tabDescription') }}</h3>
							<p class="details-drawer__description" data-testid="drawer-description">
								{{ description }}
							</p>
						</section>

						<section class="details-drawer__section">
							<h3 class="details-drawer__section-title">
								{{ t('pettycash', 'reviews.quickReview.drawer.attachments') }}
								<span v-if="attachments.length > 0" class="details-drawer__attachment-count">{{ formatNumber(attachments.length) }}</span>
							</h3>
							<p v-if="attachments.length === 0" class="details-drawer__caption" data-testid="drawer-no-attachments">
								{{ t('pettycash', 'reviews.quickReview.drawer.noAttachments') }}
							</p>
							<ul v-else class="details-drawer__attachments" data-testid="drawer-attachments">
								<li v-for="item in attachments" :key="item.id">
									<button
										type="button"
										class="details-drawer__attachment"
										:aria-label="t('pettycash', 'reviews.quickReview.card.opensPreviewNamed', { name: item.name })"
										:data-testid="`drawer-attachment-${item.id}`"
										@click="emit('preview', item)"
									>
										<span class="details-drawer__attachment-art" aria-hidden="true">
											<NcIconSvgWrapper :path="evidenceIcon(item)" :size="20" />
										</span>
										<span class="details-drawer__attachment-text">
											<span class="details-drawer__attachment-name">{{ item.name }}</span>
											<span dir="ltr" class="details-drawer__attachment-meta">
												{{ extensionOf(item) }} · {{ formatFileSize(item.sizeBytes) }}
											</span>
										</span>
									</button>
								</li>
							</ul>
						</section>

						<p class="details-drawer__note">{{ t('pettycash', 'reviews.quickReview.drawer.decideNote') }}</p>
					</div>

					<div v-show="activeTab === 'history'" data-testid="drawer-panel-history">
						<PriorDecisionHistory :decisions="transaction.decisions" />
					</div>
				</template>
			</div>

			<footer v-if="transaction !== null && readState === 'loaded'" class="details-drawer__footer" data-testid="details-drawer-footer">
				<template v-if="isAwaiting">
					<span class="details-drawer__decide-label">{{ t('pettycash', 'reviews.quickReview.drawer.decideLabel') }}</span>
					<div class="details-drawer__footer-group">
						<NcButton
							variant="error"
							:disabled="!canReject"
							data-testid="drawer-decide-reject"
							@click="emit('decide', 'REJECT')"
						>
							<template #icon>
								<NcIconSvgWrapper :path="mdiClose" :size="16" />
							</template>
							{{ t('pettycash', 'reviews.decision.reject') }}
						</NcButton>
						<NcButton
							variant="secondary"
							:disabled="!canReturn"
							data-testid="drawer-decide-return"
							@click="emit('decide', 'RETURN')"
						>
							<template #icon>
								<NcIconSvgWrapper :path="mdiUndoVariant" :size="16" />
							</template>
							{{ t('pettycash', 'reviews.decision.return') }}
						</NcButton>
						<NcButton
							variant="primary"
							:disabled="!canApprove"
							data-testid="drawer-decide-approve"
							@click="emit('decide', 'APPROVE')"
						>
							<template #icon>
								<NcIconSvgWrapper :path="mdiCheck" :size="16" />
							</template>
							{{ t('pettycash', 'reviews.decision.approve') }}
						</NcButton>
					</div>
				</template>
				<NcButton
					variant="secondary"
					data-testid="drawer-close-btn"
					@click="close"
				>
					{{ t('pettycash', 'common.close') }}
				</NcButton>
			</footer>
		</aside>
	</div>
</template>

<script setup lang="ts">
import { direction } from '@/utils/direction'
import { computed, nextTick, onBeforeUnmount, ref, useTemplateRef, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import {
	mdiAlertCircleOutline,
	mdiCheck,
	mdiClose,
	mdiFile,
	mdiFileDocumentOutline,
	mdiFilePdfBox,
	mdiImage,
	mdiShieldLockOutline,
	mdiUndoVariant,
} from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import PriorDecisionHistory from '@/components/review/PriorDecisionHistory.vue'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import { decisionAllowed } from '@/types/api'
import { formatSheetDate } from '@/utils/date'
import { formatNumber } from '@/utils/number'
import { formatMoney } from '@/utils/money'
import type {
	Decision,
	Evidence,
	ManagerSheetSummary,
	ManagerTransactionReadState,
	ManagerTransactionState,
	TransactionDetail,
} from '@/types/api'

/**
 * T030/T031/T032 — the read-only transaction details drawer.
 *
 * Fields are read-only; the footer's decision controls are the drawer's only writes, and they emit
 * to the shared Batch 4 confirmation workflow rather than posting anything themselves. Every field
 * renders either a value the server returned or its explicit unavailable state — merchant, paid-on,
 * payment method and category are not in the required-capability scope and stay unavailable here.
 */

interface Props {
	open: boolean
	transaction: TransactionDetail | null
	/** This manager's own state for the transaction; never inferred from the global status. */
	managerState: ManagerTransactionState
	managerDecision: Decision | null
	/** The manager-scoped sheet header (B001); `null` renders its fields unavailable. */
	sheet: ManagerSheetSummary | null
	readState: ManagerTransactionReadState
}

const props = defineProps<Props>()

const emit = defineEmits<{
	close: []
	decide: [kind: 'APPROVE' | 'REJECT' | 'RETURN']
	preview: [evidence: Evidence]
}>()

const TITLE_ID = 'details-drawer-title'
const FOCUSABLE = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'

const rootRef = useTemplateRef<HTMLElement>('rootRef')
const activeTab = ref<'description' | 'history'>('description')
let previouslyFocused: HTMLElement | null = null

const isAwaiting = computed(() => props.managerState === 'AWAITING_MANAGER_DECISION')

/**
 * T026: a footer decision is offered only when the server explicitly granted it. Absence (the key
 * is missing) and refusal (explicit `false`) both keep the control disabled.
 */
const canApprove = computed(() => decisionAllowed(props.transaction?.allowedActions, 'APPROVE'))
const canReject = computed(() => decisionAllowed(props.transaction?.allowedActions, 'REJECT'))
const canReturn = computed(() => decisionAllowed(props.transaction?.allowedActions, 'RETURN'))

const title = computed(() => {
	const value = props.transaction?.activeRevision?.description?.trim() ?? ''
	return value !== '' ? value : t('pettycash', 'reviews.quickReview.card.noDescription')
})

const description = computed(() => {
	const value = props.transaction?.activeRevision?.description?.trim() ?? ''
	return value !== '' ? value : t('pettycash', 'common.notProvided')
})

const amountLabel = computed(() => formatMoney(props.transaction?.activeRevision?.amount ?? null))

const expenseDateLabel = computed(() => formatSheetDate(props.transaction?.activeRevision?.expenseDate ?? null))

const sheetTitleAvailable = computed(() => (props.sheet?.title?.trim() ?? '') !== '')

const purchaserName = computed(() => props.sheet?.ownerDisplayName?.trim() ?? '')

const costCenterLabel = computed(() => {
	const center = props.transaction?.activeRevision?.costCenter
	return center?.code || center?.name || t('pettycash', 'reviews.sheetQueue.noCostCenter')
})

const managerStateLabel = computed(() => {
	if (props.managerState === 'RETURNED_FOR_CORRECTION') {
		return t('pettycash', 'reviews.quickReview.card.returned')
	}
	if (!isAwaiting.value) {
		switch (props.managerDecision?.decision) {
		case 'APPROVE':
			return t('pettycash', 'reviews.quickReview.card.approvedByYou')
		case 'REJECT':
			return t('pettycash', 'reviews.quickReview.card.rejectedByYou')
		default:
			return t('pettycash', 'reviews.quickReview.card.decidedByYou')
		}
	}
	return t('pettycash', 'reviews.quickReview.card.awaiting')
})

/** The transaction's returned evidence set — the only set a thumbnail or row may ever render. */
const attachments = computed(() => props.transaction?.evidence ?? [])

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

function formatFileSize(bytes: number): string {
	if (!Number.isFinite(bytes) || bytes < 0) return '—'
	if (bytes < 1024) return `${formatNumber(bytes)} B`
	if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
	return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function focusTab(direction: 1 | -1): void {
	const tabs = [rootRef.value?.querySelector<HTMLElement>('[data-testid="drawer-tab-description"]'),
		rootRef.value?.querySelector<HTMLElement>('[data-testid="drawer-tab-history"]')]
	const current = tabs.findIndex((tab) => tab === document.activeElement)
	if (current < 0) return
	const next = tabs[(current + direction + tabs.length) % tabs.length]
	if (next instanceof HTMLElement) {
		next.focus()
		activeTab.value = next.dataset.testid === 'drawer-tab-history' ? 'history' : 'description'
	}
}

function close(): void {
	emit('close')
}

function onKeydown(event: KeyboardEvent): void {
	if (event.key === 'Escape') {
		event.preventDefault()
		event.stopPropagation()
		close()
		return
	}
	if (event.key !== 'Tab' || !rootRef.value) return
	const nodes = Array.from(rootRef.value.querySelectorAll<HTMLElement>(FOCUSABLE))
	if (nodes.length === 0) return
	const first = nodes[0] as HTMLElement
	const last = nodes[nodes.length - 1] as HTMLElement
	if (event.shiftKey && (document.activeElement === first || document.activeElement === rootRef.value)) {
		event.preventDefault()
		last.focus()
	} else if (!event.shiftKey && document.activeElement === last) {
		event.preventDefault()
		first.focus()
	}
}

watch(() => props.open, (open) => {
	if (open) {
		activeTab.value = 'description'
		// Focus returns to the control that opened the drawer (the card's Full details button), so a
		// keyboard user is not dropped at the top of the document when it closes.
		previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null
		void nextTick(() => rootRef.value?.focus())
	} else if (previouslyFocused !== null) {
		previouslyFocused.focus()
		previouslyFocused = null
	}
})

onBeforeUnmount(() => {
	if (previouslyFocused !== null && document.contains(previouslyFocused)) {
		previouslyFocused.focus()
	}
	previouslyFocused = null
})
</script>

<style scoped>

/* The global `#header` owns the top strip: `#app-content` is a lower stacking context, so no
   z-index inside it can paint above the header (the app's existing overlay convention,
   e.g. `.transaction-surface`, starts below it the same way). */
.details-drawer-layer {
	position: fixed;
	inset-block: var(--header-height, 50px) 0;
	inset-inline: 0;
	z-index: 1750;
	display: flex;
	align-items: stretch;
	justify-content: flex-end;
	font-size: var(--font-size-floor);
}

/* The inherited `dir` does the mirroring the approved design asks for: flex-end anchors the drawer
   to the reading edge — right in LTR, left in RTL — and the shadow is flipped to match. */
.details-drawer-layer__scrim {
	position: absolute;
	inset: 0;
	background-color: var(--pettycash-scrim, color-mix(in srgb, black 55%, transparent));
}

.details-drawer {
	position: relative;
	display: flex;
	flex-direction: column;
	inline-size: min(48.75rem, 100%);
	max-block-size: 100vh;
	block-size: 100%;
	border-radius: 0;
	background-color: var(--color-main-background);
	box-shadow: -24px 0 80px color-mix(in srgb, black 30%, transparent);
}

[dir='rtl'] .details-drawer {
	box-shadow: 24px 0 80px color-mix(in srgb, black 30%, transparent);
}

.details-drawer__header {
	padding: 16px 20px 0;
	border-block-end: 1px solid var(--color-border);
}

.details-drawer__head-row {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
}

.details-drawer__titles {
	min-inline-size: 0;
}

.details-drawer__eyebrow {
	margin: 0 0 4px;
	color: var(--color-primary-element);
	font-weight: 700;
	letter-spacing: 0.08em;
	text-transform: uppercase;
}

.details-drawer__title {
	margin: 0;
	font-size: var(--font-size-section);
	font-weight: 700;
	line-height: 1.25;
	overflow-wrap: anywhere;
}

.details-drawer__meta {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.details-drawer__meta-id,
.details-drawer__reference {
	font-family: monospace;
	unicode-bidi: isolate;
	overflow-wrap: anywhere;
}

.details-drawer__meta--unavailable {
	color: var(--color-text-maxcontrast);
}

.details-drawer__tabs {
	display: flex;
	align-items: center;
	gap: 4px;
	margin: 12px 0 -1px;
	border-block-end: 1px solid var(--color-border);
}

.details-drawer__tab {
	position: relative;
	padding: 10px 12px;
	border: 0;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-family: inherit;
	font-size: var(--font-size-floor);
	font-weight: 600;
	cursor: pointer;
	min-block-size: 44px;
}

.details-drawer__tab:hover {
	color: var(--color-main-text);
}

.details-drawer__tab--active {
	color: var(--color-primary-element);
}

.details-drawer__tab--active::after {
	content: '';
	position: absolute;
	inset-inline: 8px;
	inset-block-end: -1px;
	block-size: 3px;
	border-radius: 3px 3px 0 0;
	background-color: var(--color-primary-element);
}

.details-drawer__body {
	flex: 1 1 auto;
	min-block-size: 0;
	overflow-y: auto;
	padding: 16px 20px;
}

.details-drawer__state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 40px 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.details-drawer__state--unavailable {
	border: 1px dashed color-mix(in srgb, var(--color-warning) 35%, var(--color-border));
	border-radius: 10px;
}

.details-drawer__state p {
	margin: 0;
}

.details-drawer__hero {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
	padding: 12px 14px;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background-color: var(--color-background-hover);
}

.details-drawer__amount {
	font-size: var(--font-size-title);
	font-weight: 700;
	line-height: 1.15;
	font-variant-numeric: tabular-nums;
	overflow-wrap: anywhere;
}

.details-drawer__caption {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.details-drawer__hero-side {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 6px;
	text-align: end;
}

.details-drawer__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 12px 16px;
	margin: 14px 0 0;
}

.details-drawer__item dt {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	font-weight: 600;
}

.details-drawer__item dd {
	margin: 2px 0 0;
	min-inline-size: 0;
	overflow-wrap: anywhere;
}

.details-drawer__value {
	font-weight: 500;
}

.details-drawer__value--unavailable {
	color: var(--color-text-maxcontrast);
}

.details-drawer__role {
	color: var(--color-text-maxcontrast);
	font-weight: 400;
}

.details-drawer__section {
	margin-block-start: 16px;
}

.details-drawer__section-title {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0 0 8px;
	font-size: var(--font-size-emphasis);
	font-weight: 700;
}

.details-drawer__attachment-count {
	padding: 0 8px;
	border-radius: 999px;
	background-color: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
	color: var(--color-primary-element);
	font-size: var(--font-size-floor);
	font-weight: 600;
}

.details-drawer__description {
	margin: 0;
	max-inline-size: 72ch;
	line-height: 1.5;
	overflow-wrap: anywhere;
	white-space: pre-wrap;
}

.details-drawer__attachments {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.details-drawer__attachment {
	display: flex;
	align-items: center;
	gap: 10px;
	inline-size: 100%;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background-color: var(--color-main-background);
	color: inherit;
	font-family: inherit;
	font-size: var(--font-size-floor);
	text-align: start;
	cursor: pointer;
	min-block-size: 44px;
}

.details-drawer__attachment:hover {
	background-color: var(--color-background-hover);
}

.details-drawer__attachment-art {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: none;
	inline-size: 36px;
	block-size: 36px;
	border-radius: 8px;
	background-color: var(--color-background-hover);
	color: var(--color-primary-element);
}

.details-drawer__attachment-text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-inline-size: 0;
}

.details-drawer__attachment-name {
	font-weight: 600;
	overflow-wrap: anywhere;
}

.details-drawer__attachment-meta {
	color: var(--color-text-maxcontrast);
	unicode-bidi: isolate;
	text-align: start;
}

.details-drawer__note {
	margin: 16px 0 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.45;
}

.details-drawer__footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	flex-wrap: wrap;
	padding: 12px 20px;
	border-block-start: 1px solid var(--color-border);
	background-color: var(--color-background-hover);
}

.details-drawer__decide-label {
	flex-basis: 100%;
	color: var(--color-text-maxcontrast);
	font-weight: 700;
}

.details-drawer__footer-group {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

@media (width <= 640px) {
	.details-drawer-layer {
		padding: 0;
	}

	/* The approved mobile form: the drawer becomes one full-screen sheet, still the only overlay. */
	.details-drawer {
		inline-size: 100%;
		max-block-size: 100%;
		box-shadow: none;
	}

	.details-drawer__header {
		padding: 12px 14px 0;
	}

	.details-drawer__body {
		padding: 12px 14px;
	}

	.details-drawer__footer {
		flex-direction: column;
		align-items: stretch;
		padding: 12px 14px;
	}

	.details-drawer__footer-group {
		flex-direction: column;
		align-items: stretch;
	}

	.details-drawer__footer :deep(.button-vue) {
		inline-size: 100%;
		justify-content: center;
	}

	.details-drawer__hero-side {
		align-items: flex-start;
		text-align: start;
	}
}

@media (prefers-reduced-motion: reduce) {
	.details-drawer,
	.details-drawer * {
		transition: none !important;
		animation: none !important;
	}
}
</style>

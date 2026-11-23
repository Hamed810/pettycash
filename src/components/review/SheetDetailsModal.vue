<template>
	<Teleport to="body">
		<div
			v-if="open"
			class="sheet-details-modal"
			:dir="direction"
			data-testid="sheet-details-modal"
		>
			<div
				class="sheet-details-modal__scrim"
				data-testid="sheet-details-modal-scrim"
				@click="onScrim"
			/>

			<div
				ref="dialogEl"
				class="sheet-details-modal__dialog"
				role="dialog"
				aria-modal="true"
				:aria-labelledby="sheetDetailsModalTitleId"
				tabindex="-1"
				data-testid="sheet-details-modal-dialog"
				@keydown="onKeydown"
			>
				<header class="sheet-details-modal__header">
					<div class="sheet-details-modal__heading">
						<slot name="eyebrow">
							<p
								v-if="adapter.identity?.eyebrow"
								class="sheet-details-modal__eyebrow"
							>
								{{ adapter.identity.eyebrow }}
							</p>
						</slot>
						<h3
							id="sheetDetailsModalTitleId"
							class="sheet-details-modal__title"
							data-testid="sheet-details-modal-title"
						>
							{{
								adapter.identity?.title ??
									t('pettycash', 'reviews.sheetQueue.sheetTitleUnavailable')
							}}
						</h3>
						<p class="sheet-details-modal__meta" data-testid="sheet-details-modal-meta">
							<slot name="identity-meta">
								<template v-if="adapter.identity?.reference">
									<CompactIdentifier :value="adapter.identity.reference ?? ''" class="sheet-details-modal__reference" data-testid="sheet-details-modal-reference" />
									<span class="sheet-details-modal__dot" aria-hidden="true">&#183;</span>
								</template>
								<template v-if="adapter.identity?.owner">
									<span class="sheet-details-modal__owner" data-testid="sheet-details-modal-owner">
										{{ adapter.identity.owner }}
									</span>
									<span class="sheet-details-modal__dot" aria-hidden="true">&#183;</span>
								</template>
								<template v-if="adapter.identity?.submitted">
									<span class="sheet-details-modal__submitted" data-testid="sheet-details-modal-submitted">
										{{ adapter.identity.submitted }}
									</span>
									<span class="sheet-details-modal__dot" aria-hidden="true">&#183;</span>
								</template>
								<template v-if="adapter.identity?.status">
									<span class="sheet-details-modal__status" data-testid="sheet-details-modal-status">
										{{ adapter.identity.status }}
									</span>
								</template>
							</slot>
						</p>
					</div>
					<div class="sheet-details-modal__actions" data-testid="sheet-details-modal-actions">
						<slot name="header-actions" />
						<button
							type="button"
							class="sheet-details-modal__close"
							:aria-label="t('pettycash', 'common.close')"
							:title="t('pettycash', 'common.close')"
							data-testid="sheet-details-modal-close"
							@click="close"
						>
							<NcIconSvgWrapper :path="mdiClose" :size="20" />
						</button>
					</div>
				</header>

				<div class="sheet-details-modal__body" data-testid="sheet-details-modal-body">
					<div
						v-if="hasMetrics"
						class="sheet-details-modal__metrics"
						data-testid="sheet-details-modal-metrics"
					>
						<slot name="summary-metrics">
							<div
								v-for="(total, idx) in adapter.identity?.totals ?? []"
								:key="idx"
								class="sheet-details-modal__metric"
								data-testid="sheet-details-modal-metric"
							>
								<bdi dir="ltr" class="sheet-details-modal__metric-currency">
									{{ total.currency }}
								</bdi>
								<span class="sheet-details-modal__metric-amount">
									{{ formatMoney(total) }}
								</span>
							</div>
							<template v-if="adapter.identity?.progress">
								<div class="sheet-details-modal__progress" data-testid="sheet-details-modal-progress">
									{{
										t('pettycash', 'reviews.seniorSheet.modal.progress', {
											decided: formatNumber(adapter.identity.progress.decided),
											total: formatNumber(adapter.identity.progress.total),
										})
									}}
								</div>
							</template>
						</slot>
					</div>

					<nav
						class="sheet-details-modal__tabs"
						role="tablist"
						:aria-label="t('pettycash', 'workspace.tabs')"
						data-testid="sheet-details-modal-tabs"
					>
						<button
							v-for="tab in canonicalTabs"
							:key="tab.key"
							type="button"
							role="tab"
							class="sheet-details-modal__tab"
							:class="{ 'sheet-details-modal__tab--active': activeTab === tab.key }"
							:aria-selected="activeTab === tab.key"
							:data-testid="`sheet-details-modal-tab-${tab.key}`"
							@click="activateTab(tab.key)"
							@keydown.arrow-right.prevent="focusNextTab(1)"
							@keydown.arrow-left.prevent="focusNextTab(-1)"
						>
							{{ t('pettycash', tab.labelKey) }}
						</button>
					</nav>

					<div class="sheet-details-modal__tab-panels" data-testid="sheet-details-modal-tab-panels">
						<div
							v-if="activeTab === 'transactions'"
							class="sheet-details-modal__tab-panel"
							data-testid="sheet-details-modal-panel-transactions"
						>
							<slot name="tab-transactions">
								<div class="sheet-details-modal__empty">
									{{ t('pettycash', 'reviews.noTransactionsInQueue') }}
								</div>
							</slot>
						</div>
						<div
							v-else-if="activeTab === 'approvals'"
							class="sheet-details-modal__tab-panel"
							data-testid="sheet-details-modal-panel-approvals"
						>
							<slot name="tab-approvals">
								<div class="sheet-details-modal__empty">
									{{ t('pettycash', 'reviews.noTransactionsInQueue') }}
								</div>
							</slot>
						</div>
						<div
							v-else-if="activeTab === 'overview'"
							class="sheet-details-modal__tab-panel"
							data-testid="sheet-details-modal-panel-overview"
						>
							<slot name="tab-overview">
								<div class="sheet-details-modal__empty">
									{{ t('pettycash', 'reviews.noTransactionsInQueue') }}
								</div>
							</slot>
						</div>
						<div
							v-else
							class="sheet-details-modal__tab-panel"
							data-testid="sheet-details-modal-panel-history"
						>
							<slot name="tab-history">
								<div class="sheet-details-modal__empty">
									{{ t('pettycash', 'reviews.noTransactionsInQueue') }}
								</div>
							</slot>
						</div>
					</div>
				</div>

				<slot name="overlays" />
			</div>
		</div>
	</Teleport>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import { mdiClose } from '@mdi/js'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import CompactIdentifier from '@/components/review/CompactIdentifier.vue'
import { direction } from '@/utils/direction'
import { formatMoney } from '@/utils/money'
import { formatNumber } from '@/utils/number'

export interface SheetMoney {
	currency: string
	amountMinor: number
	minorUnit: number
}

export interface SheetProgress {
	decided: number
	total: number
}

export interface SheetIdentity {
	reference: string | null
	eyebrow?: string
	title: string | null
	owner: string | null
	submitted: string | null
	status: string | null
	currency: string | null
	totals: SheetMoney[]
	progress?: SheetProgress | null
}

export interface SheetMetrics {
	transactionCount: number | null
}

export interface SheetDetailsModalAdapter {
	stage: string
	identity: SheetIdentity
	metrics: SheetMetrics
	readOnly: boolean
	submitting: boolean
}

interface Props {
	open: boolean
	adapter?: SheetDetailsModalAdapter
}

const props = withDefaults(defineProps<Props>(), {
	adapter: () => ({
		stage: 'sheet',
		identity: {
			reference: null,
			title: null,
			owner: null,
			submitted: null,
			status: null,
			currency: null,
			totals: [],
		},
		metrics: { transactionCount: null },
		readOnly: false,
		submitting: false,
	}),
})

const emit = defineEmits<{
	close: []
	export: []
}>()

const dialogEl = ref<HTMLElement | null>(null)
const activeTab = ref<'transactions' | 'approvals' | 'overview' | 'history'>('transactions')

const FOCUSABLE =
	'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'

const sheetDetailsModalTitleId = 'sheet-details-modal-title'

const hasMetrics = computed(() =>
	(props.adapter.identity.totals?.length ?? 0) > 0 || props.adapter.identity.progress !== null
)

const canonicalTabs = [
	{ key: 'transactions' as const, labelKey: 'workspace.tabTransactions' },
	{ key: 'approvals' as const, labelKey: 'workspace.tabApprovals' },
	{ key: 'overview' as const, labelKey: 'workspace.tabOverview' },
	{ key: 'history' as const, labelKey: 'workspace.tabHistory' },
]

function activateTab(key: (typeof canonicalTabs)[number]['key']): void {
	activeTab.value = key
}

function focusNextTab(direction: 1 | -1): void {
	if (!dialogEl.value) return
	const tabs = Array.from(
		dialogEl.value.querySelectorAll<HTMLElement>('[role="tab"]')
	)
	const current = tabs.findIndex((tab) => tab === document.activeElement)
	if (current < 0) return
	const next = tabs[(current + direction + tabs.length) % tabs.length]
	if (next) {
		next.focus()
	}
}

function close(): void {
	emit('close')
}

function onScrim(): void {
	if (props.adapter.submitting) return
	close()
}

function onKeydown(event: KeyboardEvent): void {
	if (event.key === 'Escape') {
		if (props.adapter.submitting) return
		event.preventDefault()
		event.stopPropagation()
		close()
		return
	}
	if (event.key !== 'Tab' || !dialogEl.value) return
	const nodes = Array.from(
		dialogEl.value.querySelectorAll<HTMLElement>(FOCUSABLE)
	)
	if (nodes.length === 0) return
	const first = nodes[0] as HTMLElement
	const last = nodes[nodes.length - 1] as HTMLElement
	if (event.shiftKey && document.activeElement === first!) {
		event.preventDefault()
		last!.focus()
	} else if (!event.shiftKey && document.activeElement === last!) {
		event.preventDefault()
		first!.focus()
	}
}

watch(
	() => props.open,
	(open) => {
		if (open) {
			activeTab.value = 'transactions'
			void nextTick(() => {
				dialogEl.value?.focus()
			})
		}
	},
	{ immediate: true }
)

onBeforeUnmount(() => {
	// No global listeners are attached by the shell; focus restoration is the
	// mounting view's contract.
})
</script>

<style scoped>
.sheet-details-modal {
	position: fixed;
	inset: 0;
	z-index: var(--z-index-dialog, 1000);
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 16px;
}

.sheet-details-modal__scrim {
	position: absolute;
	inset: 0;
	background-color: rgb(0 0 0 / 50%);
}

/*
 * Stable geometry (UXC-V2-01, owner-approved v2 baseline): the dialog is a fixed-height frame —
 * width and usable height are viewport-stable constants, never content-driven — so switching tabs
 * cannot resize or visually jump the modal, and a sparse tab keeps intentional whitespace instead
 * of collapsing the frame. The dialog itself never scrolls (`overflow: hidden`); the only scroll
 * container is the tab-panels region below, so the header, integrated summary line and tab bar
 * stay fixed in position while a tab's content scrolls inside.
 */
.sheet-details-modal__dialog {
	position: relative;
	width: min(1180px, calc(100vw - 140px));
	height: min(860px, calc(100vh - 76px));
	overflow: hidden;
	display: flex;
	flex-direction: column;
	background-color: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	box-shadow: var(--shadow-modal, 0 8px 24px rgb(0 0 0 / 20%));
	outline: none;
}

.sheet-details-modal__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	padding: 16px;
	border-block-end: 1px solid var(--color-border);
}

.sheet-details-modal__heading {
	flex: 1 1 auto;
	min-inline-size: 0;
}

.sheet-details-modal__actions {
	display: flex;
	flex: 0 0 auto;
	align-items: center;
	gap: 8px;
}

.sheet-details-modal__close {
	flex: 0 0 auto;
}

.sheet-details-modal__eyebrow {
	margin: 0 0 4px;
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.04em;
}

.sheet-details-modal__title {
	margin: 0;
	font-size: 1.15rem;
	font-weight: 600;
	line-height: 1.25;
	overflow-wrap: anywhere;
}

.sheet-details-modal__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 4px;
	margin: 6px 0 0;
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

.sheet-details-modal__dot {
	opacity: 0.6;
}

/*
 * The body is a non-scrolling flex column: summary metrics and the tab bar keep their natural
 * height (they must never grow or scroll away), and the tab-panels region takes the remaining
 * space and owns the one scroll container.
 */
.sheet-details-modal__body {
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	min-block-size: 0;
	overflow: hidden;
	padding: 16px;
}

.sheet-details-modal__metrics {
	display: flex;
	flex: 0 0 auto;
	flex-wrap: wrap;
	gap: 12px;
	margin-block-end: 16px;
}

.sheet-details-modal__metric {
	display: flex;
	flex-direction: column;
	gap: 2px;
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

.sheet-details-modal__metric-currency {
	font-weight: 600;
}

.sheet-details-modal__metric-amount {
	font-family: 'Noto Sans Mono', monospace;
	font-variant-numeric: tabular-nums;
}

.sheet-details-modal__progress {
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

.sheet-details-modal__tabs {
	display: flex;
	flex: 0 0 auto;
	flex-wrap: wrap;
	gap: 4px;
	margin: 12px 0 -1px;
	border-block-end: 1px solid var(--color-border);
}

.sheet-details-modal__tab {
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

.sheet-details-modal__tab:hover {
	color: var(--color-main-text);
}

.sheet-details-modal__tab--active {
	color: var(--color-primary-element);
}

.sheet-details-modal__tab--active::after {
	content: '';
	position: absolute;
	inset-inline: 8px;
	inset-block-end: -1px;
	block-size: 3px;
	border-radius: 3px 3px 0 0;
	background-color: var(--color-primary-element);
}

/*
 * The single scroll container (UXC-V2-01): the panels region takes the frame's remaining height
 * and scrolls; the active panel is plain content, so a sparse panel leaves intentional whitespace
 * inside the fixed frame instead of shrinking the modal.
 */
.sheet-details-modal__tab-panels {
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	min-block-size: 0;
	overflow-y: auto;
	padding: 16px 0;
}

.sheet-details-modal__tab-panel {
	flex: none;
}

.sheet-details-modal__empty {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 40px 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

/* Desktop refinements */
@media (width >= 992px) {
	.sheet-details-modal__header {
		padding: 20px 24px 16px;
	}

	.sheet-details-modal__title {
		font-size: 1.3rem;
	}

	.sheet-details-modal__eyebrow {
		color: var(--color-primary-element);
		font-weight: 700;
		letter-spacing: 0.08em;
	}

	.sheet-details-modal__body {
		padding: 20px 24px 24px;
	}
}

/* 390px floor — no horizontal scroll, ≥44px touch targets */
@media (width <= 390px) {
	.sheet-details-modal {
		padding: 8px;
	}

	.sheet-details-modal__dialog {
		width: calc(100vw - 32px);
		height: calc(100vh - 32px);
	}

	.sheet-details-modal__header {
		padding: 12px 16px;
	}

	.sheet-details-modal__body {
		padding: 12px 16px 16px;
	}

	.sheet-details-modal__tab {
		min-block-size: 44px;
	}

	.sheet-details-modal__close {
		min-block-size: 44px;
		min-inline-size: 44px;
	}
}

@media (width <= 640px) {
	.sheet-details-modal__header {
		flex-direction: column;
		align-items: stretch;
		gap: 8px;
	}

	.sheet-details-modal__actions {
		width: 100%;
		justify-content: flex-end;
	}

	.sheet-details-modal__close {
		min-block-size: 44px;
		min-inline-size: 44px;
	}
}

@media (prefers-reduced-motion: reduce) {
	.sheet-details-modal__dialog,
	.sheet-details-modal__dialog * {
		transition: none !important;
		animation: none !important;
	}
}
</style>

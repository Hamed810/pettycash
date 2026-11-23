<template>
	<div class="transaction-inline-detail" data-testid="transaction-inline-detail">
		<div v-if="readState === 'loading'" class="transaction-inline-detail__state" data-testid="inline-loading">
			<NcLoadingIcon :size="32" />
			<p>{{ t('pettycash', 'reviews.quickReview.loading') }}</p>
		</div>

		<!-- A refusal, an absence and a failure are different answers; each says which it is. -->
		<div v-else-if="readState === 'forbidden'" class="transaction-inline-detail__state transaction-inline-detail__state--unavailable" data-testid="inline-forbidden">
			<NcIconSvgWrapper :path="mdiShieldLockOutline" :size="28" aria-hidden="true" />
			<p>{{ t('pettycash', 'reviews.quickReview.drawer.forbidden') }}</p>
		</div>

		<div v-else-if="readState === 'notFound'" class="transaction-inline-detail__state transaction-inline-detail__state--unavailable" data-testid="inline-not-found">
			<NcIconSvgWrapper :path="mdiAlertCircleOutline" :size="28" aria-hidden="true" />
			<p>{{ t('pettycash', 'reviews.quickReview.drawer.notFound') }}</p>
		</div>

		<div v-else-if="transaction === null" class="transaction-inline-detail__state transaction-inline-detail__state--unavailable" data-testid="inline-unavailable">
			<NcIconSvgWrapper :path="mdiAlertCircleOutline" :size="28" aria-hidden="true" />
			<p>{{ t('pettycash', 'reviews.quickReview.drawer.loadFailed') }}</p>
		</div>

		<template v-else>
			<!--
				The approved v2 desktop arrangement (UXC-V2-05): a light two-column split — transaction
				details and evidence on the reading side, previous decisions beside them — with the
				current-stage actions as a full-width proximity row underneath. The DOM order is the
				390px flow verbatim: details → evidence → history → actions, so narrow viewports need no
				second template. No nested bordered panels: the region inherits the expanded card's
				surface.
			-->
			<div class="transaction-inline-detail__columns">
				<div class="transaction-inline-detail__primary">
					<div data-testid="inline-section-details">
					<dl class="transaction-inline-detail__grid" data-testid="inline-details-grid">
						<div class="transaction-inline-detail__item">
							<dt>{{ t('pettycash', 'reviews.quickReview.drawer.sheet') }}</dt>
							<dd data-testid="inline-field-sheet">
								<span v-if="sheetTitleAvailable" class="transaction-inline-detail__value">{{ sheet?.title }}</span>
								<span v-else class="transaction-inline-detail__value--unavailable">{{ t('pettycash', 'reviews.sheetQueue.sheetTitleUnavailable') }}</span>
								<CompactIdentifier :value="transaction.sheetId" class="transaction-inline-detail__reference" />
							</dd>
						</div>
						<div class="transaction-inline-detail__item">
							<dt>{{ t('pettycash', 'reviews.quickReview.drawer.purchasedBy') }}</dt>
							<dd data-testid="inline-field-purchaser">
								<span v-if="purchaserName !== ''" class="transaction-inline-detail__value">
									{{ purchaserName }} <span class="transaction-inline-detail__role">({{ t('pettycash', 'roles.purchaser') }})</span>
								</span>
								<span v-else class="transaction-inline-detail__value--unavailable">{{ t('pettycash', 'reviews.sheetQueue.purchaserUnavailable') }}</span>
							</dd>
						</div>
						<div class="transaction-inline-detail__item">
							<dt>{{ t('pettycash', 'reviews.sheetQueue.submitted') }}</dt>
							<dd data-testid="inline-field-submitted">
								<span v-if="submittedLabel !== null" class="transaction-inline-detail__value">{{ submittedLabel }}</span>
								<span v-else class="transaction-inline-detail__value--unavailable">{{ t('pettycash', 'reviews.sheetQueue.submittedUnavailable') }}</span>
							</dd>
						</div>
						<div class="transaction-inline-detail__item">
							<dt>{{ t('pettycash', 'reviews.quickReview.drawer.merchant') }}</dt>
							<dd class="transaction-inline-detail__value--unavailable" data-testid="inline-field-merchant">
								{{ t('pettycash', 'reviews.sheetQueue.unavailableField') }}
							</dd>
						</div>
						<div class="transaction-inline-detail__item">
							<dt>{{ t('pettycash', 'reviews.quickReview.drawer.paidOn') }}</dt>
							<dd class="transaction-inline-detail__value--unavailable" data-testid="inline-field-paid-on">
								{{ t('pettycash', 'reviews.sheetQueue.unavailableField') }}
							</dd>
						</div>
						<div class="transaction-inline-detail__item">
							<dt>{{ t('pettycash', 'reviews.quickReview.drawer.method') }}</dt>
							<dd class="transaction-inline-detail__value--unavailable" data-testid="inline-field-method">
								{{ t('pettycash', 'reviews.sheetQueue.unavailableField') }}
							</dd>
						</div>
					</dl>

						<section class="transaction-inline-detail__section">
							<h3 class="transaction-inline-detail__section-title">{{ t('pettycash', 'reviews.quickReview.drawer.tabDescription') }}</h3>
							<p class="transaction-inline-detail__description" data-testid="inline-description">
								{{ description }}
							</p>
						</section>
					</div>

					<!--
						Evidence is summary presentation plus entry into the established preview overlay:
						the expanded region renders the transaction's own returned attachments with their
						metadata and emits `preview`; it never renders file content inline and never offers a
						mutation control. The separate EvidencePreview layer owns image/PDF/text rendering,
						Previous/Next + the `1/N` counter, and the download fallback.
					-->
					<section class="transaction-inline-detail__section" data-testid="inline-section-evidence">
						<h3 class="transaction-inline-detail__section-title">
							{{ t('pettycash', 'reviews.quickReview.drawer.attachments') }}
							<span v-if="attachments.length > 0" class="transaction-inline-detail__attachment-count" data-testid="inline-evidence-count">{{ formatNumber(attachments.length) }}</span>
						</h3>
						<p v-if="attachments.length === 0" class="transaction-inline-detail__caption" data-testid="inline-no-attachments">
							{{ t('pettycash', 'reviews.quickReview.drawer.noAttachments') }}
						</p>
						<ul v-else class="transaction-inline-detail__attachments" data-testid="inline-attachments">
							<li v-for="item in attachments" :key="item.id">
								<button
									type="button"
									class="transaction-inline-detail__attachment"
									:aria-label="t('pettycash', 'reviews.quickReview.card.opensPreviewNamed', { name: item.name })"
									:data-testid="`inline-attachment-${item.id}`"
									@click="emit('preview', item)"
								>
									<span class="transaction-inline-detail__attachment-art">
										<EvidenceTile :item="item" :interactive="false" :icon-size="20" />
									</span>
									<span class="transaction-inline-detail__attachment-text">
										<span class="transaction-inline-detail__attachment-name">{{ item.name }}</span>
										<span dir="ltr" class="transaction-inline-detail__attachment-meta">
											{{ extensionOf(item) }} · {{ formatFileSize(item.sizeBytes) }}
										</span>
									</span>
								</button>
							</li>
						</ul>
					</section>
				</div>

				<div class="transaction-inline-detail__secondary">
					<!--
						Previous Decisions (UXC-V2-08): the three workflow stages render separately from
						`TransactionDetail.decisions[]` in the compact v2 presentation — stage + decision
						lead, actor/time secondary, explanation only when present, and an explicit "No
						decision yet" — never collapsed into one generic approval status and never merged
						with the sheet-level Approvals aggregate.
					-->
					<section class="transaction-inline-detail__section" data-testid="inline-section-decisions">
						<h3 class="transaction-inline-detail__section-title">{{ t('pettycash', 'reviews.priorDecisions') }}</h3>
						<div
							v-for="group in stageDecisionGroups"
							:key="group.stage"
							class="transaction-inline-detail__decision-group"
							:data-testid="`inline-decisions-${group.key}`"
						>
							<PriorDecisionHistory
								variant="compact"
								:decisions="group.decisions"
								:heading="t('pettycash', group.stageLabelKey)"
								:empty-text="t('pettycash', 'reviews.decisionStage.noDecision')"
							/>
						</div>
					</section>
				</div>
			</div>

			<div class="transaction-inline-detail__actions" data-testid="inline-section-actions">
				<ReviewDecideActions
					v-if="stageActions.length > 0"
					:kinds="stageActions"
					:allowed-actions="transaction.allowedActions"
					:busy="busy"
					:label="stage?.actionsLabel ?? t('pettycash', 'reviews.quickReview.drawer.decideLabel')"
					test-id-prefix="inline-decide"
					@decide="(kind) => emit('decide', kind)"
				/>
				<p v-if="stageActions.length > 0" class="transaction-inline-detail__note" data-testid="inline-decide-note">
					{{ t('pettycash', 'reviews.transactionDrawer.decideNote') }}
				</p>
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import {
	mdiAlertCircleOutline,
	mdiShieldLockOutline,
} from '@mdi/js'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import PriorDecisionHistory from '@/components/review/PriorDecisionHistory.vue'
import ReviewDecideActions from '@/components/review/ReviewDecideActions.vue'
import EvidenceTile from '@/components/review/EvidenceTile.vue'
import CompactIdentifier from '@/components/review/CompactIdentifier.vue'
import { formatSheetDateTime } from '@/utils/date'
import { formatNumber } from '@/utils/number'
import type {
	Decision,
	DecisionValue,
	Evidence,
	TransactionDetail,
} from '@/types/api'

/**
 * The Inline Expanded Transaction's detail surface (UXC-V2-05, owner-approved v2 baseline).
 *
 * This is the former `TransactionDetailDrawer.vue` content (the drawer was the source of record
 * until its deletion at UXC-V2-13), rendered as an extension of the transaction card instead of an
 * overlay: the same read-state honesty, the same authoritative detail fields with their unavailable
 * states, the same transaction-bound evidence rows, the same stage-separated previous decisions,
 * and the same adapter-gated current-stage action surface. Only the chrome is gone: no teleport, no
 * dialog semantics, no focus trap, no close control — the region lives inside the card's
 * disclosure, and Escape/focus handling belongs to the card (one Escape collapses the expansion,
 * the modal stays).
 *
 * Deliberate parity notes (from the drawer migration):
 * - The read-state and stage-config vocabularies were re-declared here rather than imported from the
 *   drawer, which had to stay byte-identical until its removal (UXC-V2-13). They are the same five
 *   end states every review stage's read already reports.
 * - Small presentation helpers mirror the drawer's (and the card's) local copies rather than
 *   extracting a shared module through a drawer edit; there is no business logic here to duplicate —
 *   every value is either what the server returned or its explicit unavailable state.
 */

/**
 * The guarded per-transaction detail read's end states — the drawer's own vocabulary, re-declared
 * (see the parity note above) so the shared inline surface carries no dependency on the drawer file.
 */
export type TransactionInlineDetailReadState = 'loading' | 'loaded' | 'forbidden' | 'notFound' | 'unavailable'

/**
 * The current stage's action surface as the calling stage configures it — the drawer's stage
 * config, re-declared for the same reason. `actionsVisible` and the offered `actions` are upstream
 * decisions; the surface adds only the server's own capability grant (`transaction.allowedActions`)
 * on top, which is data, not a rule.
 */
export interface TransactionInlineDetailStageConfig {
	/** Whether the current-stage action surface renders at all. Upstream decides. */
	actionsVisible: boolean
	/** Human label above the action row; the surface renders its default when omitted. */
	actionsLabel?: string
	/** The decision kinds this stage offers, in display order. Each is gated by the server grant. */
	actions: DecisionValue[]
}

/** The sheet header facts the region may present; both review stages' sheet shapes satisfy it. */
export interface TransactionInlineDetailSheet {
	title?: string | null
	ownerDisplayName?: string | null
	submittedAt?: string | null
}

interface Props {
	transaction: TransactionDetail | null
	readState: TransactionInlineDetailReadState
	/** The sheet header under review; `null` renders the sheet facts' unavailable states. */
	sheet?: TransactionInlineDetailSheet | null
	/** Current-stage action configuration; `null` or `actionsVisible: false` renders no decide row. */
	stage?: TransactionInlineDetailStageConfig | null
	/** True while a decision for this transaction is being submitted, so a second cannot start. */
	busy?: boolean
}

const props = withDefaults(defineProps<Props>(), {
	sheet: null,
	stage: null,
	busy: false,
})

const emit = defineEmits<{
	decide: [kind: DecisionValue]
	preview: [evidence: Evidence]
}>()

/** The stage's offered kinds, in display order; empty when the surface has no decide row. */
const stageActions = computed<DecisionValue[]>(() => {
	if (!props.stage?.actionsVisible) return []
	return props.stage.actions
})

const description = computed(() => {
	const value = props.transaction?.activeRevision?.description?.trim() ?? ''
	return value !== '' ? value : t('pettycash', 'common.notProvided')
})

const sheetTitleAvailable = computed(() => (props.sheet?.title?.trim() ?? '') !== '')

const purchaserName = computed(() => props.sheet?.ownerDisplayName?.trim() ?? '')

/**
 * The sheet's genuine first-submission instant, rendered only when the sheet header carried one;
 * `null` is the unavailable state, never a guessed date.
 */
const submittedLabel = computed(() => {
	const value = props.sheet?.submittedAt
	return typeof value === 'string' && value !== '' ? formatSheetDateTime(value) : null
})

/** The transaction's returned evidence set — the only set a row here may ever render. */
const attachments = computed(() => props.transaction?.evidence ?? [])

interface StageDecisionGroup {
	key: 'cost-center' | 'senior' | 'finance'
	stage: Decision['stage']
	stageLabelKey: string
	decisions: Decision[]
}

/** The three workflow stages in canonical order, each with the decisions its stage recorded. */
const stageDecisionGroups = computed<StageDecisionGroup[]>(() => {
	const decisions = props.transaction?.decisions ?? []
	return [
		{
			key: 'cost-center' as const,
			stage: 'COST_CENTER' as const,
			stageLabelKey: 'reviews.decisionStage.costCenter',
			decisions: decisions.filter((decision) => decision.stage === 'COST_CENTER'),
		},
		{
			key: 'senior' as const,
			stage: 'SENIOR' as const,
			stageLabelKey: 'reviews.decisionStage.senior',
			decisions: decisions.filter((decision) => decision.stage === 'SENIOR'),
		},
		{
			key: 'finance' as const,
			stage: 'FINANCE' as const,
			stageLabelKey: 'reviews.decisionStage.finance',
			decisions: decisions.filter((decision) => decision.stage === 'FINANCE'),
		},
	]
})

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
</script>

<style scoped>
.transaction-inline-detail {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding-block-start: 14px;
	min-inline-size: 0;
	font-size: var(--font-size-floor);
}

.transaction-inline-detail__state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 8px;
	padding: 32px 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.transaction-inline-detail__state--unavailable {
	border: 1px dashed color-mix(in srgb, var(--color-warning) 35%, var(--color-border));
	border-radius: 10px;
}

.transaction-inline-detail__state p {
	margin: 0;
}

/*
 * The approved v2 desktop arrangement: two light columns (details + evidence | decisions) that
 * collapse into the mandatory 390px vertical flow — details → evidence → history → actions — with
 * no second template, because the DOM order already is that flow.
 */
.transaction-inline-detail__columns {
	display: flex;
	flex-direction: column;
	gap: 16px;
	min-inline-size: 0;
}

.transaction-inline-detail__primary,
.transaction-inline-detail__secondary {
	display: flex;
	flex-direction: column;
	gap: 16px;
	min-inline-size: 0;
}

.transaction-inline-detail__hero {
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

.transaction-inline-detail__amount {
	font-size: var(--font-size-section);
	font-weight: 700;
	line-height: 1.15;
	font-variant-numeric: tabular-nums;
	overflow-wrap: anywhere;
}

.transaction-inline-detail__caption {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.transaction-inline-detail__hero-side {
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 6px;
	text-align: end;
}

.transaction-inline-detail__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: 12px 16px;
	margin: 14px 0 0;
}

.transaction-inline-detail__item dt {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	font-weight: 600;
}

.transaction-inline-detail__item dd {
	margin: 2px 0 0;
	min-inline-size: 0;
	overflow-wrap: anywhere;
}

.transaction-inline-detail__value {
	font-weight: 500;
}

.transaction-inline-detail__value--unavailable {
	color: var(--color-text-maxcontrast);
}

.transaction-inline-detail__role {
	color: var(--color-text-maxcontrast);
	font-weight: 400;
}

.transaction-inline-detail__reference {
	font-family: monospace;
	unicode-bidi: isolate;
	overflow-wrap: anywhere;
}

.transaction-inline-detail__section {
	margin-block-start: 4px;
	min-inline-size: 0;
}

.transaction-inline-detail__section-title {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 0 0 8px;
	font-size: var(--font-size-emphasis);
	font-weight: 700;
}

.transaction-inline-detail__attachment-count {
	padding: 0 8px;
	border-radius: 999px;
	background-color: color-mix(in srgb, var(--color-primary-element) 12%, transparent);
	color: var(--color-primary-element);
	font-size: var(--font-size-floor);
	font-weight: 600;
}

.transaction-inline-detail__description {
	margin: 0;
	max-inline-size: 72ch;
	line-height: 1.5;
	overflow-wrap: anywhere;
	white-space: pre-wrap;
}

.transaction-inline-detail__attachments {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.transaction-inline-detail__attachment {
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

.transaction-inline-detail__attachment:hover {
	background-color: var(--color-background-hover);
}

/* The row's art slot: the tile fills it — real thumbnail when one exists, type art when not. */
.transaction-inline-detail__attachment-art {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: none;
	inline-size: 36px;
	block-size: 36px;
	border-radius: 8px;
	overflow: hidden;
}

.transaction-inline-detail__attachment-text {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-inline-size: 0;
}

.transaction-inline-detail__attachment-name {
	font-weight: 600;
	overflow-wrap: anywhere;
}

.transaction-inline-detail__attachment-meta {
	color: var(--color-text-maxcontrast);
	unicode-bidi: isolate;
	text-align: start;
}

.transaction-inline-detail__decision-group + .transaction-inline-detail__decision-group {
	margin-block-start: 12px;
	padding-block-start: 12px;
	border-block-start: 1px solid var(--color-border);
}

.transaction-inline-detail__actions {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding-block-start: 4px;
}

.transaction-inline-detail__note {
	margin: 0;
	color: var(--color-text-maxcontrast);
	line-height: 1.45;
}

/* Desktop refinements: the approved two-column split. */
@media (width >= 641px) {
	.transaction-inline-detail__columns {
		display: grid;
		grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
		gap: 16px 24px;
		align-items: start;
	}
}

@media (width <= 640px) {
	.transaction-inline-detail__hero-side {
		align-items: flex-start;
		text-align: start;
	}

	.transaction-inline-detail__actions :deep(.review-decide-actions__group) {
		flex-direction: column;
		align-items: stretch;
	}
}

@media (prefers-reduced-motion: reduce) {
	.transaction-inline-detail,
	.transaction-inline-detail * {
		transition: none !important;
		animation: none !important;
	}
}
</style>

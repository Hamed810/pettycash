<template>
	<div v-if="open && decision !== null && transaction !== null" class="decision-modal-layer" :dir="direction" data-testid="decision-modal-layer">
		<div
			class="decision-modal-layer__scrim"
			data-testid="decision-modal-scrim"
			@click="onScrimClick"
		/>
		<section
			ref="rootRef"
			class="decision-modal"
			:class="{ 'decision-modal--return': isReturn }"
			role="dialog"
			aria-modal="true"
			:aria-labelledby="TITLE_ID"
			tabindex="-1"
			data-testid="decision-modal"
			@keydown="onKeydown"
		>
			<header class="decision-modal__header">
				<div class="decision-modal__titles">
					<p class="decision-modal__eyebrow">
						{{ t('pettycash', 'reviews.quickReview.eyebrow') }} · <bdi>{{ transaction.sheetReference }}</bdi>
					</p>
					<h2 :id="TITLE_ID" class="decision-modal__title" data-testid="decision-modal-title">
						{{ copy.title }}
					</h2>
					<p class="decision-modal__meta">
						<bdi dir="ltr" class="decision-modal__meta-id" data-testid="decision-modal-transaction-id">{{ transaction.id }}</bdi>
						<span data-testid="decision-modal-transaction-title">{{ transaction.title }}</span>
					</p>
				</div>
				<NcButton
					variant="tertiary"
					:disabled="submitting"
					:aria-label="t('pettycash', 'common.cancel')"
					data-testid="decision-modal-close"
					@click="cancel"
				>
					<NcIconSvgWrapper :path="mdiClose" :size="18" />
				</NcButton>
			</header>

			<div class="decision-modal__body">
				<div class="decision-modal__summary">
					<div class="decision-modal__summary-text">
						<p class="decision-modal__summary-title">{{ transaction.title }}</p>
						<p class="decision-modal__summary-meta">
							<bdi>{{ transaction.sheetReference }}</bdi>
							<span class="decision-modal__summary-sep" aria-hidden="true">·</span>
							<span>{{ expenseDateLabel }}</span>
						</p>
					</div>
					<strong class="decision-modal__summary-amount" data-testid="decision-modal-amount">
						<bdi>{{ amountLabel }}</bdi>
					</strong>
				</div>

				<p
					class="decision-modal__warning"
					:class="copy.warningClass"
					data-testid="decision-modal-warning"
				>
					<NcIconSvgWrapper :path="mdiAlertOutline" :size="18" aria-hidden="true" />
					<span>{{ copy.warning }}</span>
				</p>

				<div class="decision-modal__field">
					<label class="decision-modal__label" for="quick-decision-note">{{ copy.noteLabel }}</label>
					<span class="decision-modal__hint">{{ copy.noteHint }}</span>
					<textarea
						id="quick-decision-note"
						v-model="explanation"
						class="decision-modal__textarea"
						:maxlength="MAX_EXPLANATION_LENGTH"
						:placeholder="copy.notePlaceholder"
						:disabled="submitting"
						:aria-invalid="showGuardError ? 'true' : undefined"
						:aria-describedby="showGuardError ? GUARD_ID : undefined"
						rows="3"
						data-testid="decision-note-input"
					/>
					<p
						v-if="showGuardError"
						:id="GUARD_ID"
						class="decision-modal__error"
						role="alert"
						data-testid="decision-guard-error"
					>
						<NcIconSvgWrapper :path="mdiAlertOutline" :size="16" aria-hidden="true" />
						<span><strong>{{ t('pettycash', 'reviews.quickReview.decision.errorLabel') }}</strong> {{ copy.guardError }}</span>
					</p>
					<p
						v-else-if="submitError !== null"
						class="decision-modal__error"
						role="alert"
						data-testid="decision-submit-error"
					>
						<NcIconSvgWrapper :path="mdiAlertOutline" :size="16" aria-hidden="true" />
						<span>{{ submitError }}</span>
					</p>
				</div>
			</div>

			<footer class="decision-modal__footer">
				<span class="decision-modal__footer-note">{{ t('pettycash', 'reviews.quickReview.decision.footerNote') }}</span>
				<div class="decision-modal__footer-group">
					<NcButton
						variant="secondary"
						:disabled="submitting"
						data-testid="decision-cancel-btn"
						@click="cancel"
					>
						{{ t('pettycash', 'common.cancel') }}
					</NcButton>
					<NcButton
						variant="primary"
						:class="copy.confirmClass"
						:disabled="!actionGranted || submitting"
						data-testid="decision-confirm-btn"
						@click="confirm"
					>
						<NcLoadingIcon v-if="submitting" :size="18" />
						{{ submitting ? t('pettycash', 'reviews.quickReview.decision.submitting') : copy.confirmLabel }}
					</NcButton>
				</div>
			</footer>
		</section>
	</div>
</template>

<script setup lang="ts">
import { direction } from '@/utils/direction'
import { computed, nextTick, onBeforeUnmount, ref, useTemplateRef, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import { mdiAlertOutline, mdiClose } from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { useReviewsStore } from '@/stores/reviews'
import { commandId as newCommandId } from '@/services/http'
import { decisionAllowed } from '@/types/api'
import { formatSheetDate } from '@/utils/date'
import { formatMoney } from '@/utils/money'
import type { AllowedActions, DecisionResult, DecisionValue, Money } from '@/types/api'

/**
 * T027 — the shared single-transaction decision confirmation.
 *
 * Both entry points (the quick-review card and the details drawer) render this one modal, so the
 * copy, the FR-033 explanation rule, the submitting state and the completion behaviour cannot drift
 * between them. It posts exactly one transaction decision through `reviewsStore.recordDecision`
 * and owns no sheet-level mutation — that unit does not exist.
 */

interface Props {
	open: boolean
	decision: DecisionValue | null
	/** The transaction the decision applies to, with the display fields the modal summarizes. */
	transaction: {
		id: string
		title: string
		sheetReference: string
		amount: Money | null
		expenseDate: string | null
	} | null
	/** The version the decision is optimistic against, from the guarded detail read (T026). */
	expectedVersion?: number
	/** Only an explicit server-granted `true` enables the confirm control (T026). */
	allowedActions?: AllowedActions
}

const props = defineProps<Props>()

const emit = defineEmits<{
	close: []
	/** Recorded successfully; the parent closes the modal, refreshes the sheet and shows a toast. */
	decided: [result: DecisionResult, decision: DecisionValue, explanation: string]
}>()

const TITLE_ID = 'quick-decision-modal-title'
const GUARD_ID = 'quick-decision-guard-error'
const FOCUSABLE = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
const MAX_EXPLANATION_LENGTH = 500

const rootRef = useTemplateRef<HTMLElement>('rootRef')
const reviewsStore = useReviewsStore()

// T036: focus returns to whatever opened the modal (a quick-review card button or a drawer control)
// when it closes, so a keyboard user is never dropped at the top of the document.
let previouslyFocused: HTMLElement | null = null

const explanation = ref('')
const submitting = ref(false)
const guardTripped = ref(false)
const submitError = ref<string | null>(null)

const isReject = computed(() => props.decision === 'REJECT')
const isReturn = computed(() => props.decision === 'RETURN')
const requiresExplanation = computed(() => isReject.value || isReturn.value)

const actionGranted = computed(() => decisionAllowed(props.allowedActions, props.decision ?? 'APPROVE'))

const amountLabel = computed(() => formatMoney(props.transaction?.amount ?? null))

const expenseDateLabel = computed(() => formatSheetDate(props.transaction?.expenseDate ?? null))

/**
 * Per-kind copy and styling, written out rather than derived from the decision value with a string
 * transform. The three confirmations differ in tone as well as words: the return confirmation keeps
 * its distinct purple treatment (final design decision #3), and the reject confirmation is the one
 * whose guard fires when the explanation is missing.
 */
const copy = computed(() => {
	if (isReject.value) {
		return {
			title: t('pettycash', 'reviews.quickReview.decision.rejectTitle'),
			warning: t('pettycash', 'reviews.quickReview.decision.rejectWarning'),
			warningClass: 'decision-modal__warning--danger',
			noteLabel: t('pettycash', 'reviews.quickReview.decision.rejectNoteLabel'),
			noteHint: t('pettycash', 'reviews.quickReview.decision.rejectNoteHint'),
			notePlaceholder: t('pettycash', 'reviews.quickReview.decision.rejectPlaceholder'),
			guardError: t('pettycash', 'reviews.quickReview.decision.rejectGuard'),
			confirmLabel: t('pettycash', 'reviews.quickReview.decision.rejectConfirm'),
			confirmClass: 'decision-modal__confirm--danger',
		}
	}
	if (isReturn.value) {
		return {
			title: t('pettycash', 'reviews.quickReview.decision.returnTitle'),
			warning: t('pettycash', 'reviews.quickReview.decision.returnWarning'),
			warningClass: 'decision-modal__warning--return',
			noteLabel: t('pettycash', 'reviews.quickReview.decision.returnNoteLabel'),
			noteHint: t('pettycash', 'reviews.quickReview.decision.returnNoteHint'),
			notePlaceholder: t('pettycash', 'reviews.quickReview.decision.returnPlaceholder'),
			guardError: t('pettycash', 'reviews.quickReview.decision.returnGuard'),
			confirmLabel: t('pettycash', 'reviews.quickReview.decision.returnConfirm'),
			confirmClass: 'decision-modal__confirm--return',
		}
	}
	return {
		title: t('pettycash', 'reviews.quickReview.decision.approveTitle'),
		warning: t('pettycash', 'reviews.quickReview.decision.approveWarning'),
		warningClass: '',
		noteLabel: t('pettycash', 'reviews.quickReview.decision.approveNoteLabel'),
		noteHint: t('pettycash', 'reviews.quickReview.decision.approveNoteHint'),
		notePlaceholder: '',
		guardError: '',
		confirmLabel: t('pettycash', 'reviews.quickReview.decision.approveConfirm'),
		confirmClass: '',
	}
})

const showGuardError = computed(() => guardTripped.value && requiresExplanation.value)

// A fresh opening (or a different transaction/decision) starts from the untripped state; the note
// itself is kept when only the guard tripped, so a rejected submit does not erase what was typed.
// `immediate` means a modal mounted already-open (not just toggled open) still traps focus.
watch(() => [props.open, props.transaction?.id, props.decision] as const, ([open]) => {
	guardTripped.value = false
	submitError.value = null
	if (open) {
		explanation.value = ''
		previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null
		void nextTick(() => rootRef.value?.focus())
	} else if (previouslyFocused !== null) {
		// T036: restore focus to the control that opened the modal.
		previouslyFocused.focus()
		previouslyFocused = null
	}
}, { immediate: true })

function cancel(): void {
	if (submitting.value) return
	emit('close')
}

onBeforeUnmount(() => {
	if (previouslyFocused !== null && document.contains(previouslyFocused)) {
		previouslyFocused.focus()
	}
	previouslyFocused = null
})

function onScrimClick(): void {
	if (submitting.value) return
	// FR-085 first-write-wins: once a submission is in flight, the scrim no longer closes the modal.
	emit('close')
}

function onKeydown(event: KeyboardEvent): void {
	if (event.key === 'Escape') {
		event.preventDefault()
		event.stopPropagation()
		if (!submitting.value) emit('close')
		return
	}
	if (event.key !== 'Tab' || !rootRef.value) return
	const nodes = Array.from(rootRef.value.querySelectorAll<HTMLElement>(FOCUSABLE))
		.filter((node) => !(node as HTMLButtonElement | HTMLTextAreaElement).disabled)
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

function confirm(): void {
	if (submitting.value || !actionGranted.value || props.decision === null || props.transaction === null) return

	const note = explanation.value.trim()
	if (requiresExplanation.value && note === '') {
		// FR-033: the decision is not posted; the modal re-renders in its error state with the
		// textarea flagged, exactly as the approved design's reject-without-note guard behaves.
		guardTripped.value = true
		return
	}

	void submit(note)
}

async function submit(note: string): Promise<void> {
	if (props.decision === null || props.transaction === null) return

	const kind = props.decision
	submitting.value = true
	submitError.value = null
	// One command identity per submission attempt: the first write wins (FR-085) and a replayed
	// request with the same id can never record a second decision.
	const id = newCommandId()
	try {
		const result = await reviewsStore.recordDecision(props.transaction.id, {
			decision: kind,
			explanation: note,
			expectedVersion: props.expectedVersion ?? 0,
		}, { commandId: id })
		emit('decided', result, kind, note)
	} catch (e: unknown) {
		// The modal stays open with the typed explanation intact; the loaded panel behind it is
		// untouched (T028). A version conflict and an authorization refusal get their own words,
		// because "retry" is right for one and wrong for the other. The store also publishes the
		// failure globally for the full-page review path; this modal is the presenter here, so the
		// queue must not additionally collapse into its error state for the same failed request.
		reviewsStore.clearError()
		submitError.value = describeDecisionError(e)
	} finally {
		submitting.value = false
	}
}

function describeDecisionError(e: unknown): string {
	const status = (e as { status?: number }).status
	const code = (e as { body?: { code?: string } }).body?.code
	if (code === 'TRANSACTION_VERSION_CONFLICT' || status === 409) {
		return t('pettycash', 'reviews.quickReview.decision.versionConflict')
	}
	if (code === 'FORBIDDEN' || status === 403) {
		return t('pettycash', 'reviews.quickReview.decision.forbidden')
	}
	if (code === 'IDEMPOTENCY_CONFLICT') {
		return t('pettycash', 'reviews.quickReview.decision.idempotencyConflict')
	}
	const message = e instanceof Error ? e.message : ''
	return message !== '' ? message : t('pettycash', 'reviews.quickReview.decision.failedGeneric')
}
</script>

<style scoped>

/* Below the global `#header` (see `.transaction-surface`): the header owns the top strip. */
.decision-modal-layer {
	position: fixed;
	inset-block: var(--header-height, 50px) 0;
	inset-inline: 0;
	z-index: 1800;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 16px;
	font-size: var(--font-size-floor);
}

.decision-modal-layer__scrim {
	position: absolute;
	inset: 0;
	background-color: var(--pettycash-scrim, color-mix(in srgb, black 55%, transparent));
}

.decision-modal {
	position: relative;
	display: flex;
	flex-direction: column;
	inline-size: min(35rem, 100%);
	max-block-size: 100%;
	overflow: auto;
	border-radius: 14px;
	background-color: var(--color-main-background);
	box-shadow: 0 12px 40px color-mix(in srgb, black 30%, transparent);
}

.decision-modal__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	padding: 16px 18px 12px;
	border-block-end: 1px solid var(--color-border);
}

.decision-modal__titles {
	min-inline-size: 0;
}

.decision-modal__eyebrow {
	margin: 0 0 4px;
	color: var(--color-primary-element);
	font-weight: 700;
	letter-spacing: 0.08em;
	text-transform: uppercase;
}

.decision-modal__title {
	margin: 0;
	font-size: var(--font-size-section);
	font-weight: 700;
	line-height: 1.25;
}

.decision-modal__meta {
	display: flex;
	align-items: center;
	gap: 6px;
	flex-wrap: wrap;
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.decision-modal__meta-id {
	font-family: monospace;
	unicode-bidi: isolate;
	overflow-wrap: anywhere;
}

.decision-modal__body {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 14px 18px;
	min-inline-size: 0;
}

.decision-modal__summary {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: 10px;
	background-color: var(--color-background-hover);
}

.decision-modal__summary-text {
	min-inline-size: 0;
}

.decision-modal__summary-title {
	margin: 0;
	font-weight: 600;
	line-height: 1.35;
	overflow-wrap: anywhere;
}

.decision-modal__summary-meta {
	display: flex;
	align-items: center;
	gap: 4px;
	margin: 2px 0 0;
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.decision-modal__summary-amount {
	flex: none;
	font-size: var(--font-size-emphasis);
	font-variant-numeric: tabular-nums;
	overflow-wrap: anywhere;
}

.decision-modal__warning {
	display: flex;
	align-items: flex-start;
	gap: 8px;
	margin: 0;
	padding: 9px 11px;
	border: 1px solid color-mix(in srgb, var(--color-warning) 30%, var(--color-border));
	border-radius: 8px;
	background-color: color-mix(in srgb, var(--color-warning) 8%, var(--color-main-background));
	line-height: 1.45;
}

.decision-modal__warning--danger {
	border-color: color-mix(in srgb, var(--color-error) 30%, var(--color-border));
	background-color: color-mix(in srgb, var(--color-error) 8%, var(--color-main-background));
}

.decision-modal__warning--return {
	border-color: color-mix(in srgb, var(--pettycash-review) 30%, var(--color-border));
	background-color: color-mix(in srgb, var(--pettycash-review) 9%, var(--color-main-background));
}

.decision-modal__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.decision-modal__label {
	font-weight: 600;
}

.decision-modal__hint {
	color: var(--color-text-maxcontrast);
}

.decision-modal__textarea {
	inline-size: 100%;
	padding: 8px 10px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background-color: var(--color-main-background);
	color: inherit;
	font-family: inherit;
	font-size: var(--font-size-floor);
	resize: vertical;
}

.decision-modal__textarea:focus {
	border-color: var(--color-primary-element);
}

.decision-modal__textarea[aria-invalid='true'] {
	border-color: var(--color-error);
}

.decision-modal__error {
	display: flex;
	align-items: flex-start;
	gap: 6px;
	margin: 0;
	color: var(--color-error-text);
	line-height: 1.4;
}

.decision-modal__footer {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 10px;
	flex-wrap: wrap;
	padding: 12px 18px;
	border-block-start: 1px solid var(--color-border);
}

.decision-modal__footer-note {
	color: var(--color-text-maxcontrast);
}

.decision-modal__footer-group {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-inline-start: auto;
	flex-wrap: wrap;
}

/* The return confirmation keeps its distinct purple (final design decision #3); the reject
   confirmation uses the danger palette. Applied over the primary variant so the disabled and
   focus states keep the library's own treatments. */
.decision-modal__footer-group :deep(.decision-modal__confirm--return.button-vue) {
	background-color: var(--pettycash-review);
	border-color: var(--pettycash-review);
	color: #fff;
}

.decision-modal__footer-group :deep(.decision-modal__confirm--return.button-vue:hover:not(:disabled)) {
	background-color: color-mix(in srgb, var(--pettycash-review) 85%, black);
}

.decision-modal__footer-group :deep(.decision-modal__confirm--danger.button-vue) {
	background-color: var(--color-error);
	border-color: var(--color-error);
	color: #fff;
}

@media (width <= 640px) {
	.decision-modal-layer {
		padding: 0;
	}

	.decision-modal {
		inline-size: 100%;
		max-block-size: 100%;
		block-size: 100%;
		border-radius: 0;
	}

	.decision-modal__footer {
		flex-direction: column;
		align-items: stretch;
	}

	.decision-modal__footer-group {
		margin-inline-start: 0;
	}

	.decision-modal__footer-group :deep(.button-vue) {
		inline-size: 100%;
		justify-content: center;
	}
}

@media (prefers-reduced-motion: reduce) {
	.decision-modal,
	.decision-modal * {
		transition: none !important;
		animation: none !important;
	}
}
</style>

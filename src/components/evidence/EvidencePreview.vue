<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useAttrs, useTemplateRef, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiChevronLeft, mdiChevronRight, mdiClose, mdiDownload } from '@mdi/js'
import { getEvidenceContent } from '@/services/evidenceApi'
import { isInlineSafeMediaType, normalizedMediaType } from '@/utils/evidencePreview'
import { formatNumber } from '@/utils/number'
import type { Evidence } from '@/types/api'

const props = defineProps<{
	evidence: Pick<Evidence, 'id' | 'name' | 'mediaType' | 'active'> | null
	open: boolean
	/**
	 * T034: the transaction's returned evidence set, for Previous/Next navigation.
	 *
	 * Optional, so the purchaser surfaces that pass a single document render exactly as before.
	 * The overlay never walks the list on its own: the parent owns the index, passes the active
	 * document through `evidence`, and receives `navigate` events for it.
	 */
	documents?: ReadonlyArray<Pick<Evidence, 'id' | 'name' | 'mediaType' | 'active'>>
	/** The active document's position in `documents`; rendered as `1 / N` when a set is given. */
	index?: number
}>()

const emit = defineEmits<{
	close: []
	navigate: [index: number]
}>()

const TITLE_ID = 'evidence-preview-title'
const FOCUSABLE = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'

const rootRef = useTemplateRef<HTMLElement>('rootRef')
/** `dir` is not a prop (it is inherited from the layer that hosts the preview), so read it once. */
const attrs = useAttrs()
const inheritedDir = computed(() => typeof attrs.dir === 'string' ? attrs.dir : undefined)
const objectUrl = ref<string | null>(null)
const textContent = ref<string | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
let requestToken = 0

const mediaType = computed(() => normalizedMediaType(props.evidence?.mediaType))
const inlineSafe = computed(() => isInlineSafeMediaType(mediaType.value))

/**
 * T034: navigation is offered only over a provided set with a valid position. The counter is `1 / N`
 * in the human sense — rendered through `formatNumber` so the digits follow the active locale.
 */
const hasNavigation = computed(() => (props.documents?.length ?? 0) > 0 && typeof props.index === 'number')
const documentCount = computed(() => props.documents?.length ?? 0)
const activeIndex = computed(() => (typeof props.index === 'number' ? props.index : -1))
const canGoPrevious = computed(() => hasNavigation.value && activeIndex.value > 0)
const canGoNext = computed(() => hasNavigation.value && activeIndex.value < documentCount.value - 1)

function navigateBy(step: -1 | 1): void {
	if (!hasNavigation.value) return
	const target = activeIndex.value + step
	// The buttons already disable at the ends; this guard keeps a stale event from emitting -1 or N.
	if (target < 0 || target >= documentCount.value) return
	emit('navigate', target)
}
const kind = computed<'' | 'image' | 'pdf' | 'text'>(() => {
	if (!inlineSafe.value) return ''
	if (mediaType.value === 'application/pdf') return 'pdf'
	if (mediaType.value === 'text/plain') return 'text'
	return 'image'
})

/**
 * Inactive evidence keeps its metadata but never renders content (C-16.8). The existing download
 * policy offers download only for active rows, so an inactive preview is informational only.
 */
const active = computed(() => props.evidence?.active !== false)

function releaseObjectUrl(): void {
	if (objectUrl.value !== null) {
		URL.revokeObjectURL(objectUrl.value)
		objectUrl.value = null
	}
}

function resetContent(): void {
	releaseObjectUrl()
	textContent.value = null
	error.value = null
	loading.value = false
}

async function load(): Promise<void> {
	const token = ++requestToken
	resetContent()
	if (!props.open || !props.evidence || !active.value || !inlineSafe.value) return

	loading.value = true
	try {
		const blob = await getEvidenceContent(props.evidence.id, 'inline')
		if (token !== requestToken) return
		if (kind.value === 'text') {
			// Rendered through interpolation, never `v-html`: a stored plain-text receipt is data, not
			// markup, even when its bytes contain angle brackets.
			textContent.value = await blob.text()
			if (token !== requestToken) return
		} else {
			objectUrl.value = URL.createObjectURL(blob)
		}
	} catch (e: unknown) {
		if (token !== requestToken) return
		error.value = (e as Error).message || t('pettycash', 'evidence.previewFailed')
	} finally {
		if (token === requestToken) loading.value = false
	}
}

async function download(): Promise<void> {
	if (!props.evidence || !active.value) return
	try {
		const blob = await getEvidenceContent(props.evidence.id, 'attachment')
		const url = URL.createObjectURL(blob)
		const a = document.createElement('a')
		a.href = url
		a.download = props.evidence.name
		document.body.appendChild(a)
		a.click()
		document.body.removeChild(a)
		URL.revokeObjectURL(url)
	} catch (e: unknown) {
		error.value = (e as Error).message || t('pettycash', 'evidence.previewFailed')
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

watch(() => [props.open, props.evidence?.id, props.evidence?.mediaType] as const, () => {
	void load()
}, { immediate: true })

/**
 * T036: focus handling lives apart from content loading. Opening captures the trigger control (a
 * card tile, a drawer attachment row, a form thumbnail) so it can be restored on close; navigating
 * between documents while open leaves focus alone.
 */
let previouslyFocused: HTMLElement | null = null
function restoreFocus(): void {
	if (previouslyFocused !== null && document.contains(previouslyFocused)) {
		previouslyFocused.focus()
	}
	previouslyFocused = null
}
watch(() => props.open, (open) => {
	if (open) {
		previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null
		void nextTick(() => rootRef.value?.focus())
	} else {
		restoreFocus()
	}
}, { immediate: true })

onBeforeUnmount(() => {
	// Late responses are ignored, and the blob is released even when the layer unmounts mid-flight.
	requestToken += 1
	releaseObjectUrl()
	restoreFocus()
})
</script>

<template>
	<div v-if="open && evidence" class="evidence-preview-layer" :dir="inheritedDir" data-test="evidence-preview-layer">
		<div class="evidence-preview-layer__scrim" data-test="evidence-preview-scrim" @click="close" />
		<section
			ref="rootRef"
			class="evidence-preview-layer__dialog"
			role="dialog"
			aria-modal="true"
			:aria-labelledby="TITLE_ID"
			tabindex="-1"
			:data-media-type="mediaType"
			data-test="evidence-preview"
			@keydown="onKeydown">
			<header class="evidence-preview-layer__header">
				<h2 :id="TITLE_ID" class="evidence-preview-layer__title" data-test="evidence-preview-name">
					{{ evidence.name }}
				</h2>
				<NcButton
					variant="tertiary"
					:aria-label="t('pettycash', 'common.close')"
					data-test="close-evidence-preview"
					@click="close">
					<NcIconSvgWrapper :path="mdiClose" :size="18" />
				</NcButton>
			</header>

			<p v-if="!active" class="evidence-preview-layer__note" data-test="preview-inactive-note">
				{{ t('pettycash', 'evidence.inactiveNote') }}
			</p>

			<p v-else-if="!inlineSafe" class="evidence-preview-layer__note" data-test="preview-download-only">
				{{ t('pettycash', 'evidence.previewUnavailable') }}
			</p>

			<template v-else>
				<p v-if="loading" class="evidence-preview-layer__state" data-test="preview-loading">
					{{ t('pettycash', 'common.loading') }}
				</p>

				<div v-else-if="error" class="evidence-preview-layer__error" role="alert" data-test="preview-error">
					<p>{{ t('pettycash', 'evidence.previewFailed') }} — {{ error }}</p>
					<NcButton variant="secondary" data-test="retry-evidence-preview" @click="load">
						{{ t('pettycash', 'common.retry') }}
					</NcButton>
				</div>

				<template v-else>
					<img
						v-if="kind === 'image' && objectUrl"
						:src="objectUrl"
						:alt="evidence.name"
						class="evidence-preview-layer__image"
						data-test="evidence-preview-image" />
					<iframe
						v-else-if="kind === 'pdf' && objectUrl"
						:src="objectUrl"
						:title="evidence.name"
						class="evidence-preview-layer__pdf"
						data-test="evidence-preview-pdf" />
					<pre
						v-else-if="kind === 'text' && textContent !== null"
						class="evidence-preview-layer__text"
						data-test="evidence-preview-text">{{ textContent }}</pre>
				</template>
			</template>

			<footer class="evidence-preview-layer__footer">
				<nav
					v-if="hasNavigation"
					class="evidence-preview-layer__nav"
					:aria-label="t('pettycash', 'evidence.previewNavigationLabel')"
					data-test="preview-navigation"
				>
					<NcButton
						variant="secondary"
						:disabled="!canGoPrevious"
						:aria-label="t('pettycash', 'evidence.previewPrevious')"
						data-test="preview-nav-prev"
						@click="navigateBy(-1)">
						<NcIconSvgWrapper :path="mdiChevronLeft" :size="16" />
						{{ t('pettycash', 'common.previous') }}
					</NcButton>
					<span class="evidence-preview-layer__counter" data-test="preview-counter">
						{{ t('pettycash', 'evidence.previewCounter', { index: formatNumber(activeIndex + 1), total: formatNumber(documentCount) }) }}
					</span>
					<NcButton
						variant="secondary"
						:disabled="!canGoNext"
						:aria-label="t('pettycash', 'evidence.previewNext')"
						data-test="preview-nav-next"
						@click="navigateBy(1)">
						{{ t('pettycash', 'common.next') }}
						<NcIconSvgWrapper :path="mdiChevronRight" :size="16" />
					</NcButton>
				</nav>
				<div class="evidence-preview-layer__footer-group">
					<NcButton
						v-if="active"
						variant="secondary"
						:aria-label="t('pettycash', 'evidence.downloadNamed', { name: evidence.name })"
						data-test="preview-download-btn"
						@click="download">
						<NcIconSvgWrapper :path="mdiDownload" :size="16" class="mr-2" />
						{{ t('pettycash', 'evidence.download') }}
					</NcButton>
					<NcButton variant="tertiary" data-test="close-evidence-preview-footer" @click="close">
						{{ t('pettycash', 'common.close') }}
					</NcButton>
				</div>
			</footer>
		</section>
	</div>
</template>

<style scoped>

/* Below the global `#header` (see `.transaction-surface`): the header owns the top strip. */
.evidence-preview-layer {
	position: fixed;
	inset-block: var(--header-height, 50px) 0;
	inset-inline: 0;
	z-index: 1700;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 1rem;
	font-size: var(--font-size-floor);
}

.evidence-preview-layer__scrim {
	position: absolute;
	inset: 0;
	background-color: var(--pettycash-scrim, color-mix(in srgb, black 55%, transparent));
}

.evidence-preview-layer__dialog {
	position: relative;
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	inline-size: min(56rem, 100%);
	max-block-size: 100%;
	overflow: auto;
	padding: 1rem;
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	box-shadow: 0 12px 40px color-mix(in srgb, black 30%, transparent);
}

.evidence-preview-layer__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 1rem;
}

.evidence-preview-layer__title {
	margin: 0;
	font-size: var(--font-size-section);
	overflow-wrap: anywhere;
}

.evidence-preview-layer__image {
	display: block;
	max-inline-size: 100%;
	max-block-size: 65vh;
	margin-inline: auto;
}

.evidence-preview-layer__pdf {
	inline-size: 100%;
	block-size: 65vh;
	border: 0;
}

.evidence-preview-layer__text {
	margin: 0;
	padding: 0.75rem;
	max-block-size: 65vh;
	overflow: auto;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	background-color: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.evidence-preview-layer__state,
.evidence-preview-layer__note {
	margin: 0;
}

.evidence-preview-layer__error {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.75rem;
	color: var(--color-error-text);
}

.evidence-preview-layer__footer {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem;
	justify-content: space-between;
}

.evidence-preview-layer__footer-group {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	margin-inline-start: auto;
}

.evidence-preview-layer__nav {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	flex-wrap: wrap;
}

.evidence-preview-layer__counter {
	padding: 0 0.25rem;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

@media (width <= 640px) {
	.evidence-preview-layer {
		padding: 0;
	}

	.evidence-preview-layer__dialog {
		inline-size: 100%;
		block-size: 100%;
		max-block-size: 100%;
		border-radius: 0;
	}
}
</style>

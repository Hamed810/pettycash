<template>
	<div class="evidence-card" :data-testid="`evidence-card-${evidence.id}`">
		<!--
			T361. The `src` is an object URL built from a fetched `Blob`, not an OCS URL: a browser
			navigation to the OCS route cannot set `OCS-APIRequest`, and the request was answered
			`412 CSRF check failed` for every user. `v-if` on a *loaded* URL rather than on `isImage`
			alone, so the card shows its icon until the bytes arrive instead of an empty `<img>` frame —
			and `preview-loading`/`evidence-error` below make both states say what they are.
		-->
		<img
			v-if="isImage && previewObjectUrl"
			class="evidence-preview"
			:src="previewObjectUrl"
			:alt="evidence.name"
			data-testid="evidence-preview">

		<div v-else-if="previewLoading" class="evidence-icon" data-testid="preview-loading">
			<NcIconSvgWrapper :path="fileIcon" :size="48" />
		</div>

		<div v-else class="evidence-icon" data-testid="evidence-icon">
			<NcIconSvgWrapper :path="fileIcon" :size="48" />
		</div>

		<div class="evidence-card__header">
			<div class="file-name" data-testid="file-name">{{ evidence.name }}</div>
			<NcChip
				v-if="!evidence.active"
				variant="error"
				class="deactivated-badge"
				:icon-path="mdiCancel"
				:text="t('pettycash', 'reviews.deactivated')"
				data-testid="deactivated-badge" />
		</div>

		<div class="file-meta">
			<div class="meta-item">{{ formatFileSize(evidence.sizeBytes) }}</div>
			<div class="meta-item">{{ evidence.mediaType }}</div>
			<div class="meta-item">{{ formatDate(evidence.uploadedAt) }}</div>
		</div>

		<div class="evidence-card__actions">
			<NcButton
				v-if="evidence.active"
				variant="tertiary"
				data-testid="evidence-preview-btn"
				@click="preview">
				<template #icon>
					<NcIconSvgWrapper :path="mdiEye" />
				</template>
				{{ t('pettycash', 'common.preview') }}
			</NcButton>
			<NcButton
				variant="tertiary"
				data-testid="evidence-download-btn"
				@click="download">
				<template #icon>
					<NcIconSvgWrapper :path="mdiDownload" />
				</template>
				{{ t('pettycash', 'common.download') }}
			</NcButton>
		</div>

		<!--
			Announced, not merely painted. A preview that failed looks identical to a receipt that is
			blank unless something says which it is, and FR-060 makes preview a capability a reviewer
			relies on to approve an expense.
		-->
		<p
			v-if="previewError"
			class="evidence-error"
			role="alert"
			data-testid="evidence-error">
			{{ previewError }}
		</p>
	</div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import { getEvidenceContent } from '@/services/evidenceApi'
import {
	mdiCancel,
	mdiDownload,
	mdiEye,
	mdiFile,
	mdiFileDocument,
	mdiFilePdfBox,
	mdiImage,
} from '@mdi/js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import type { Evidence } from '@/types/api'

interface Props {
	/** The shared `Evidence` type, not a copy of it — see the note on `EvidenceList.vue`. */
	evidence: Evidence
}

const props = defineProps<Props>()

const isImage = computed(() => props.evidence.mediaType.startsWith('image/'))

const fileIcon = computed(() => {
	if (props.evidence.mediaType.startsWith('image/')) return mdiImage
	if (props.evidence.mediaType.startsWith('application/pdf')) return mdiFilePdfBox
	if (props.evidence.mediaType.startsWith('application/')) return mdiFileDocument
	return mdiFile
})

/**
 * The evidence bytes, fetched as a `Blob` and handed to the browser as an object URL.
 *
 * **This used to be a navigation, and a navigation cannot carry the header this route requires.**
 * `preview()` did `window.open(generateOcsUrl(…))` and `download()` assigned
 * `window.location.href`, so `GET /evidence/{id}/content` went out with no `OCS-APIRequest` header.
 * `EvidenceController::content()` carries `#[NoAdminRequired]` and **no** `#[NoCSRFRequired]`, so
 * `SecurityMiddleware` rejected **every** such request with `412 CSRF check failed` — for every user, at
 * every review stage (T361). FR-060's preview was unavailable precisely where US2/AC1 and US3/AC1 ask
 * for it.
 *
 * **`getEvidenceContent()` already sends `OCS-APIRequest: true`** (`src/services/evidenceApi.ts:49`),
 * which is why the purchaser's `EvidenceList.vue` works and this card did not.
 *
 * **Request-neutral, which is why the inline `<img>` can stay eager.** The browser was already issuing
 * one request per image for `<img :src="previewUrl">` — it just received a 412. Fetching the blob and
 * setting an object URL is the same single request, now one that succeeds.
 */
const previewObjectUrl = ref<string | null>(null)
const previewError = ref<string | null>(null)
const previewLoading = ref(false)

/** Object URLs are a leak until revoked, and this card is inside a `v-for`. */
function releaseObjectUrl(): void {
	if (previewObjectUrl.value !== null) {
		URL.revokeObjectURL(previewObjectUrl.value)
		previewObjectUrl.value = null
	}
}

onBeforeUnmount(releaseObjectUrl)

async function fetchAsObjectUrl(disposition: 'inline' | 'attachment'): Promise<string> {
	const blob = await getEvidenceContent(props.evidence.id, disposition)
	return URL.createObjectURL(blob)
}

/**
 * Load the inline preview for an image.
 *
 * Only `image/*` is fetched automatically. PDF and plain text are inline-safe per FR-060 but the
 * browser cannot render either from a blob URL in an `<img>`, so they go through the explicit preview
 * button, which opens a tab.
 */
async function loadInlinePreview(): Promise<void> {
	if (!isImage.value || previewObjectUrl.value !== null || previewLoading.value) return
	previewLoading.value = true
	previewError.value = null
	try {
		previewObjectUrl.value = await fetchAsObjectUrl('inline')
	} catch (e: unknown) {
		// A broken preview must say so. An `<img>` whose `src` failed renders as an empty box, which
		// reads as "this receipt is blank" rather than "we could not load it".
		previewError.value = e instanceof Error ? e.message : t('pettycash', 'evidence.previewFailed')
	} finally {
		previewLoading.value = false
	}
}

onMounted(() => {
	loadInlinePreview().catch(() => undefined)
})

/**
 * Open the evidence in a new tab.
 *
 * **The tab is opened synchronously, before the fetch.** Awaiting first loses the user-gesture context
 * and popup blockers then refuse `window.open` — which would turn a working preview into a silent
 * no-op, the exact failure mode this task exists to remove. So the window exists first and its
 * location is filled in when the bytes arrive; if the fetch fails the empty tab is closed rather than
 * left blank.
 */
async function preview(): Promise<void> {
	if (isImage.value && previewObjectUrl.value !== null) {
		window.open(previewObjectUrl.value, '_blank', 'noopener')
		return
	}

	const opened = window.open('', '_blank')
	previewError.value = null
	try {
		const url = await fetchAsObjectUrl('inline')
		if (opened !== null) {
			opened.location.href = url
		} else {
			window.open(url, '_blank', 'noopener')
		}
	} catch (e: unknown) {
		if (opened !== null) opened.close()
		previewError.value = e instanceof Error ? e.message : t('pettycash', 'evidence.previewFailed')
	}
}

/** Save the file under its own name, via an object URL rather than a navigation. */
async function download(): Promise<void> {
	previewError.value = null
	try {
		const url = await fetchAsObjectUrl('attachment')
		const anchor = document.createElement('a')
		anchor.href = url
		anchor.download = props.evidence.name
		document.body.appendChild(anchor)
		anchor.click()
		document.body.removeChild(anchor)
		URL.revokeObjectURL(url)
	} catch (e: unknown) {
		previewError.value = e instanceof Error ? e.message : t('pettycash', 'evidence.downloadFailed')
	}
}

function formatFileSize(bytes: number): string {
	if (bytes < 1024) return `${bytes} B`
	if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
	return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function formatDate(dateStr: string): string {
	try {
		return new Date(dateStr).toLocaleDateString('fa-IR', {
			year: 'numeric',
			month: 'short',
			day: 'numeric',
		})
	} catch {
		return dateStr
	}
}
</script>

<style scoped>
.evidence-card {
	min-height: 280px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--color-main-background);
}

.evidence-preview {
	aspect-ratio: 1;
	object-fit: contain;
	background: var(--color-background-dark);
	border-radius: var(--border-radius, 8px);
}

.evidence-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	aspect-ratio: 1;
	background: var(--color-background-dark);
	border-radius: var(--border-radius, 8px);
	color: var(--color-text-maxcontrast);
}

.evidence-card__header {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.file-name {
	font-size: var(--font-size-floor);
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.deactivated-badge {
	font-size: var(--font-size-floor);
}

.file-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
}

.meta-item {
	display: flex;
	align-items: center;
	gap: 4px;
}

.evidence-card__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-block-start: auto;
}

.evidence-error {
	margin: 0;
	color: var(--color-error, #c9326b);
	font-size: var(--font-size-floor);
}

@media (width <= 390px) {
	.evidence-card__actions {
		flex-direction: column;
	}
}
</style>

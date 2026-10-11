<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { getEvidenceContent } from '@/services/evidenceApi'
import type { Evidence } from '@/types/api'

/**
 * Inline evidence preview under FR-060 (T098).
 *
 * The **stored** media type decides, never the viewer's choice: images, PDF
 * and plain text render inline; anything else — an uploader-supplied
 * `text/html` included — is offered as a download and never rendered in the
 * Nextcloud origin, where it would execute as script in the viewer's session.
 * The server enforces the same rule on the bytes; this component enforces it
 * on the markup, so neither layer alone carries the guarantee.
 */
const props = defineProps<{
	evidence: Pick<Evidence, 'id' | 'name' | 'mediaType' | 'active'>
}>()

const objectUrl = ref<string | null>(null)
const error = ref<string | null>(null)

const kind = computed((): 'image' | 'pdf' | 'text' | 'download' => {
	const mediaType = props.evidence.mediaType
	if (mediaType.startsWith('image/')) return 'image'
	if (mediaType === 'application/pdf') return 'pdf'
	if (mediaType === 'text/plain') return 'text'
	return 'download'
})

const inline = computed(() => kind.value !== 'download' && props.evidence.active !== false)

onMounted(async () => {
	if (!inline.value) return
	try {
		const blob = await getEvidenceContent(props.evidence.id, 'inline')
		objectUrl.value = URL.createObjectURL(blob)
	} catch (e: unknown) {
		const err = e as Error
		error.value = err.message
	}
})

onBeforeUnmount(() => {
	if (objectUrl.value !== null) {
		URL.revokeObjectURL(objectUrl.value)
	}
})

async function download() {
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
		const err = e as Error
		error.value = err.message
	}
}
</script>

<template>
	<div class="evidence-preview" dir="rtl" data-test="evidence-preview">
		<img
			v-if="kind === 'image' && objectUrl"
			:src="objectUrl"
			:alt="evidence.name"
			class="evidence-preview__image"
			data-test="evidence-preview-image"
		/>
		<iframe
			v-else-if="kind === 'pdf' && objectUrl"
			:src="objectUrl"
			:title="evidence.name"
			class="evidence-preview__pdf"
			data-test="evidence-preview-pdf"
		/>
		<iframe
			v-else-if="kind === 'text' && objectUrl"
			:src="objectUrl"
			:title="evidence.name"
			class="evidence-preview__text"
			data-test="evidence-preview-text"
		/>
		<NcButton
			v-else-if="evidence.active !== false"
			variant="secondary"
			:aria-label="t('pettycash', 'evidence.downloadNamed', { name: evidence.name })"
			data-test="download-evidence-btn"
			@click="download"
		>
			{{ t('pettycash', 'evidence.download') }}
		</NcButton>
		<p v-if="evidence.active === false" class="evidence-preview__inactive" data-test="preview-inactive-note">
			{{ t('pettycash', 'evidence.inactiveNote') }}
		</p>
		<p v-if="error" class="evidence-preview__error" role="alert" data-test="preview-error">{{ error }}</p>
	</div>
</template>

<style scoped>
.evidence-preview {
	font-size: var(--font-size-floor);
}

.evidence-preview__image {
	max-width: 100%;
}

.evidence-preview__pdf,
.evidence-preview__text {
	width: 100%;
	min-height: 320px;
	border: 0;
}

.evidence-preview__error {
	margin: 4px 0 0;
}

.evidence-preview__inactive {
	margin: 4px 0 0;
}
</style>

<script setup lang="ts">
import { ref, useTemplateRef } from 'vue'
import { uploadEvidence } from '@/services/evidenceApi'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import { mdiCloudUpload, mdiFileCheck, mdiClose, mdiLock } from '@mdi/js'
import { t } from '@nextcloud/l10n'

interface Props {
	transactionId: string
	isDraft: boolean
}

const props = withDefaults(defineProps<Props>(), {
	isDraft: true
})

const emit = defineEmits<{
	uploaded: []
}>()

const file = ref<File | null>(null)
const uploading = ref(false)
const progress = ref(0)
const error = ref<string | null>(null)
const MAX_FILE_SIZE = 100 * 1024 * 1024 // 100 MB

function formatFileSize(bytes: number) {
	if (bytes < 1024) return bytes + ' B'
	if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
	return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

const fileInput = useTemplateRef<HTMLInputElement>('fileInput')

function openFilePicker() {
	fileInput.value?.click()
}

function onFileSelected(event: Event) {
	// FR-025 allows evidence to be added before submission and FR-026 forbids it afterwards, so the
	// input is not even rendered for a submitted transaction. The guard is repeated here because a
	// file can also arrive by assignment rather than by a click.
	if (!props.isDraft) return
	const input = event.target as HTMLInputElement
	if (input.files && input.files.length > 0) {
		const selectedFile = input.files[0]
		if (!selectedFile) {
			return
		}
		// The limit is inclusive: a file of exactly the maximum is allowed.
		if (selectedFile.size > MAX_FILE_SIZE) {
			// The limit is stated, not just the refusal. A message that only says "too large" leaves
			// the purchaser with nothing to act on, and the `{max}` placeholder this call passed was
			// never present in the message key, so the number was never rendered.
			error.value = t('pettycash', 'evidence.fileSizeExceeded: {max}', { max: `${MAX_FILE_SIZE / (1024 * 1024)} MB` })
			file.value = null
			return
		}
		file.value = selectedFile
		error.value = null
	}
}

async function handleUpload() {
	if (!file.value || !props.isDraft) return
	uploading.value = true
	progress.value = 0
	error.value = null
	try {
		// Simulate progress (real implementation would use axios onUploadProgress)
		const progressInterval = setInterval(() => {
			progress.value = Math.min(progress.value + 10, 90)
		}, 100)

		await uploadEvidence(props.transactionId, file.value, { commandId: crypto.randomUUID() })

		clearInterval(progressInterval)
		progress.value = 100
		emit('uploaded')
		file.value = null
	} catch (e: unknown) {
		const err = e as Error
		error.value = err.message
	} finally {
		uploading.value = false
		progress.value = 0
	}
}

function removeFile() {
	file.value = null
	error.value = null
}
</script>

<template>
	<div class="evidence-uploader" dir="rtl" data-test="evidence-uploader">
		<!-- FR-025 permits adding evidence before submission; FR-026 forbids it afterwards, with the
		     single exception of a correction of a returned transaction, which the server models as a
		     new revision rather than as an edit of this one. The prop previously existed and was never
		     read, so a submitted transaction could still be handed evidence. -->
		<div v-if="!isDraft" class="evidence-locked" data-test="evidence-locked">
			<NcIconSvgWrapper :path="mdiLock" :size="20" />
			<span>{{ t('pettycash', 'evidence.lockedAfterSubmission') }}</span>
		</div>

		<template v-else>
			<div class="drop-zone" :class="{ 'has-file': file }" data-test="drop-zone">
				<input type="file" ref="fileInput" @change="onFileSelected" accept="*" hidden data-test="evidence-upload-input" />
				<div v-if="!file" class="drop-zone__empty">
					<NcIconSvgWrapper :path="mdiCloudUpload" :size="48" class="drop-zone__icon" />
					<span class="drop-zone__text">{{ t('pettycash', 'evidence.dragDrop') }}</span>
					<small class="drop-zone__hint">{{ t('pettycash', 'evidence.clickToSelect') }}</small>
					<NcButton variant="secondary" @click="openFilePicker" class="drop-zone__btn" data-test="select-file-btn">
						{{ t('pettycash', 'evidence.selectFile') }}
					</NcButton>
					<small class="drop-zone__limit">{{ t('pettycash', 'evidence.maxSize: {max}', { max: `${MAX_FILE_SIZE / (1024 * 1024)} MB` }) }}</small>
				</div>
				<div v-else class="drop-zone__file">
					<div class="drop-zone__file-info">
						<NcIconSvgWrapper :path="mdiFileCheck" :size="24" class="drop-zone__file-icon" />
						<div class="drop-zone__file-details">
							<div class="drop-zone__file-name">{{ file.name }}</div>
							<div class="drop-zone__file-size">{{ formatFileSize(file.size) }}</div>
						</div>
						<NcChip data-test="file-size-badge">
							{{ t('pettycash', 'evidence.sizeOk') }}
						</NcChip>
					</div>
					<NcProgressBar v-if="uploading" :value="progress" :indeterminate="!progress" color="primary" data-test="upload-progress" />
					<div class="drop-zone__actions">
						<NcButton variant="tertiary" :aria-label="t('pettycash', 'evidence.removeSelected')" @click="removeFile" data-test="remove-file-btn">
							<NcIconSvgWrapper :path="mdiClose" :size="16" />
							{{ t('pettycash', 'common.remove') }}
						</NcButton>
						<NcButton :disabled="uploading" :loading="uploading" variant="primary" @click="handleUpload" data-test="upload-btn">
							{{ uploading ? t('pettycash', 'evidence.uploading') : t('pettycash', 'evidence.upload') }}
						</NcButton>
					</div>
				</div>
			</div>
		</template>

		<NcNoteCard v-if="error" type="error" role="alert" data-test="file-size-error">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<style scoped>
.evidence-uploader {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.drop-zone {
	border: 2px dashed var(--nc-color-outline-variant, #e0e0e0);
	border-radius: 8px;
	padding: 1.5rem;
	transition: border-color 0.2s, background-color 0.2s;
}

.drop-zone:hover {
	border-color: var(--nc-color-primary, #1976d2);
	background-color: var(--nc-color-primary-container, #e3f2fd);
}

.drop-zone.has-file {
	border-style: solid;
	border-color: var(--nc-color-outline, #757575);
}

.drop-zone__empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	text-align: center;
	gap: 1rem;
}

.drop-zone__icon {
	color: var(--nc-color-on-surface-variant, #616161);
}

.drop-zone__text {
	font-size: var(--font-size-floor);
	color: var(--nc-color-on-surface, #212121);
}

.drop-zone__hint {
	color: var(--nc-color-on-surface-variant, #616161);
}

.drop-zone__btn {
	margin-top: 0.5rem;
}

.drop-zone__limit {
	color: var(--nc-color-on-surface-variant, #616161);
}

.evidence-locked {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 1rem;
	border: 1px dashed var(--nc-color-outline-variant, #e0e0e0);
	border-radius: 8px;
	color: var(--nc-color-on-surface-variant, #616161);
}

.drop-zone__file {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 1rem;
	padding: 0.5rem;
}

.drop-zone__file-info {
	display: flex;
	align-items: center;
	gap: 1rem;
	width: 100%;
	max-width: 500px;
}

.drop-zone__file-icon {
	flex-shrink: 0;
}

.drop-zone__file-details {
	flex: 1;
	min-width: 0;
}

.drop-zone__file-name {
	font-weight: 500;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.drop-zone__file-size {
	font-size: var(--font-size-floor);
	color: var(--nc-color-on-surface-variant, #616161);
}

.drop-zone__actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.5rem;
	width: 100%;
	max-width: 500px;
}
</style>
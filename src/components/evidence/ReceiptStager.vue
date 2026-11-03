<script setup lang="ts">
import { computed, ref, useTemplateRef } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { mdiCloudUpload, mdiClose, mdiFileDocumentOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'

/**
 * FR-090: receipts are selected **before** the transaction exists and uploaded once the server returns
 * its id. The queue therefore belongs to the form, not to the evidence uploader: nothing here talks to
 * the API. Keeping the component free of upload logic is what makes "no request before creation"
 * verifiable at the form level, where the create call lives.
 */
const props = withDefaults(defineProps<{
	modelValue: File[]
	disabled?: boolean
}>(), {
	disabled: false
})

const emit = defineEmits<{
	'update:modelValue': [files: File[]]
}>()

const MAX_FILE_SIZE = 100 * 1024 * 1024 // 100 MB, the same limit as the edit-route uploader.
const fileInput = useTemplateRef<HTMLInputElement>('fileInput')
const error = ref<string | null>(null)

const files = computed(() => props.modelValue)

function fileKey(file: File): string {
	return `${file.name}:${file.size}:${file.lastModified}`
}

function formatFileSize(bytes: number): string {
	if (bytes < 1024) return `${bytes} B`
	if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
	return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function openFilePicker(): void {
	if (props.disabled) return
	fileInput.value?.click()
}

function onFileSelected(event: Event): void {
	if (props.disabled) return
	const input = event.target as HTMLInputElement
	const selected = Array.from(input.files ?? [])
	if (selected.length === 0) return

	// A repeated selection of the same file is a no-op rather than a second queued upload. The identity
	// triple is what a browser exposes; using only the name would drop two legitimately different files
	// that happen to share one.
	const existing = new Set(files.value.map(fileKey))
	const unique = selected.filter((file) => !existing.has(fileKey(file)))
	const tooLarge = unique.filter((file) => file.size > MAX_FILE_SIZE)
	const accepted = unique.filter((file) => file.size <= MAX_FILE_SIZE)

	if (tooLarge.length > 0) {
		error.value = t('pettycash', 'evidence.fileSizeExceeded: {max}', {
			max: `${MAX_FILE_SIZE / (1024 * 1024)} MB`
		})
	} else {
		error.value = null
	}

	if (accepted.length > 0) {
		emit('update:modelValue', [...files.value, ...accepted])
	}

	// Allow the same file to be chosen again after removal: a file input does not fire `change` when
	// the value it already carries is selected a second time.
	input.value = ''
}

function removeFile(file: File): void {
	emit('update:modelValue', files.value.filter((candidate) => candidate !== file))
	error.value = null
}
</script>

<template>
	<div class="receipt-stager" data-test="receipt-stager">
		<div class="receipt-stager__head">
			<span class="receipt-stager__icon" aria-hidden="true">
				<NcIconSvgWrapper :path="mdiCloudUpload" :size="24" />
			</span>
			<div>
				<p class="receipt-stager__title">{{ t('pettycash', 'transactions.receiptsBeforeSave') }}</p>
				<p class="receipt-stager__hint">{{ t('pettycash', 'transactions.receiptsBeforeSaveHint') }}</p>
			</div>
		</div>

		<input
			ref="fileInput"
			type="file"
			multiple
			accept="*"
			hidden
			:disabled="disabled"
			data-test="staged-receipt-input"
			@change="onFileSelected" />
		<NcButton
			variant="secondary"
			type="button"
			:disabled="disabled"
			data-test="staged-receipt-select"
			@click="openFilePicker">
			{{ t('pettycash', 'transactions.selectReceipts') }}
		</NcButton>

		<NcNoteCard v-if="error" type="error" role="alert" data-test="staged-receipt-error">
			{{ error }}
		</NcNoteCard>

		<p v-if="files.length === 0" class="receipt-stager__empty" data-test="staged-receipts-empty">
			{{ t('pettycash', 'transactions.noReceiptsSelected') }}
		</p>
		<ul v-else class="receipt-stager__list" data-test="staged-receipts-list">
			<li
				v-for="file in files"
				:key="fileKey(file)"
				class="receipt-stager__item"
				data-test="staged-receipt-item">
				<NcIconSvgWrapper :path="mdiFileDocumentOutline" :size="20" aria-hidden="true" />
				<span class="receipt-stager__name">{{ file.name }}</span>
				<span class="receipt-stager__size">{{ formatFileSize(file.size) }}</span>
				<NcButton
					variant="tertiary"
					type="button"
					:disabled="disabled"
					:aria-label="t('pettycash', 'evidence.removeNamed', { name: file.name })"
					data-test="staged-receipt-remove"
					@click="removeFile(file)">
					<NcIconSvgWrapper :path="mdiClose" :size="16" aria-hidden="true" />
					{{ t('pettycash', 'common.remove') }}
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<style scoped>
.receipt-stager {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding: 1rem;
	border: 2px dashed var(--color-border);
	border-radius: var(--border-radius-large);
}

.receipt-stager__head {
	display: flex;
	align-items: flex-start;
	gap: 0.75rem;
}

.receipt-stager__icon {
	color: var(--color-primary-element);
}

.receipt-stager__title {
	margin: 0;
	font-weight: 600;
}

.receipt-stager__hint,
.receipt-stager__empty {
	margin: 0.25rem 0 0;
	color: var(--color-text-maxcontrast);
}

.receipt-stager__list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.receipt-stager__item {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	padding: 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.receipt-stager__name {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.receipt-stager__size {
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}
</style>

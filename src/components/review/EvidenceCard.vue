<template>
	<div class="evidence-card" :data-testid="`evidence-card-${evidence.id}`">
		<img
			v-if="isImage"
			class="evidence-preview"
			:src="previewUrl"
			:alt="evidence.name"
			data-testid="evidence-preview">

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
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import { generateOcsUrl } from '@nextcloud/router'
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

interface Props {
	evidence: {
		id: string
		transactionId: string
		revisionId: string
		name: string
		mediaType: string
		sizeBytes: number
		active: boolean
		uploadedBy: string
		uploadedAt: string
		deactivatedAt?: string
	}
}

const props = defineProps<Props>()

const isImage = computed(() => props.evidence.mediaType.startsWith('image/'))

const fileIcon = computed(() => {
	if (props.evidence.mediaType.startsWith('image/')) return mdiImage
	if (props.evidence.mediaType.startsWith('application/pdf')) return mdiFilePdfBox
	if (props.evidence.mediaType.startsWith('application/')) return mdiFileDocument
	return mdiFile
})

const previewUrl = computed(() => generateOcsUrl('apps/pettycash/api/v1/evidence/{id}/content', { id: props.evidence.id }))

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

function preview(): void {
	window.open(previewUrl.value, '_blank')
}

function download(): void {
	window.location.href = `${previewUrl.value}?disposition=attachment`
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
	font-size: 13px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.deactivated-badge {
	font-size: 12px;
}

.file-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	font-size: 12px;
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

@media (width <= 390px) {
	.evidence-card__actions {
		flex-direction: column;
	}
}
</style>

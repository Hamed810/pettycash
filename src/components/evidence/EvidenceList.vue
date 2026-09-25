<script setup lang="ts">
import { ref } from 'vue'
import { deleteEvidence, getEvidenceContent } from '@/services/evidenceApi'
import type { Evidence } from '@/types/api'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { mdiImage, mdiFilePdfBox, mdiFileDocument, mdiFile, mdiDownload, mdiDelete, mdiFileOutline } from '@mdi/js'
import { t } from '@nextcloud/l10n'

interface Props {
	evidence: Evidence[]
	isDraft: boolean
}

const props = withDefaults(defineProps<Props>(), {
	isDraft: true
})

const deleting = ref<string | null>(null)
const error = ref<string | null>(null)

function formatFileSize(bytes: number) {
	if (bytes < 1024) return bytes + ' B'
	if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
	return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

function getMediaTypeIcon(mediaType: string) {
	if (mediaType.startsWith('image/')) return mdiImage
	if (mediaType.startsWith('application/pdf')) return mdiFilePdfBox
	if (mediaType.startsWith('text/')) return mdiFileDocument
	return mdiFile
}

async function handleDownload(ev: Evidence) {
	try {
		const blob = await getEvidenceContent(ev.id)
		const url = URL.createObjectURL(blob)
		const a = document.createElement('a')
		a.href = url
		a.download = ev.name
		document.body.appendChild(a)
		a.click()
		document.body.removeChild(a)
		URL.revokeObjectURL(url)
	} catch (e: unknown) {
		const err = e as Error
		error.value = err.message
	}
}

async function handleDelete(ev: Evidence) {
	if (!confirm(t('pettycash', 'evidence.confirmDelete'))) return
	deleting.value = ev.id
	error.value = null
	try {
		await deleteEvidence(ev.id)
	} catch (e: unknown) {
		const err = e as Error
		error.value = err.message
	} finally {
		deleting.value = null
	}
}
</script>

<template>
	<div class="evidence-list" dir="rtl" data-test="evidence-list">
		<div v-if="props.evidence.length">
			<NcListItem v-for="ev in props.evidence" :key="ev.id" class="evidence-item" data-test="evidence-list-item">
				<template #avatar>
					<NcIconSvgWrapper :path="getMediaTypeIcon(ev.mediaType)" :size="24" :color="ev.active ? 'primary' : 'error'" />
				</template>
				<template #title>
					<div class="evidence-title">
						<span class="evidence-name">{{ ev.name }}</span>
						<div class="evidence-meta">
							<span>{{ ev.mediaType }}</span>
							<span>·</span>
							<span>{{ formatFileSize(ev.sizeBytes) }}</span>
							<span>·</span>
							<span>{{ new Date(ev.uploadedAt).toLocaleString('fa-IR') }}</span>
						</div>
					</div>
				</template>
				<template #subtitle>
					<div class="evidence-actions">
						<NcChip data-test="evidence-active-badge">
							{{ ev.active ? t('pettycash', 'evidence.active') : t('pettycash', 'evidence.inactive') }}
						</NcChip>
						<NcButton v-if="ev.active && props.isDraft" variant="tertiary" @click="handleDownload(ev)" data-test="download-evidence-btn">
							<NcIconSvgWrapper :path="mdiDownload" :size="16" />
						</NcButton>
						<NcButton v-if="ev.active && props.isDraft" variant="tertiary" @click="handleDelete(ev)" :disabled="deleting === ev.id" :loading="deleting === ev.id" data-test="delete-evidence-btn">
							<NcIconSvgWrapper :path="mdiDelete" :size="16" />
						</NcButton>
						<NcChip v-if="!ev.active" variant="error" data-test="inactive-badge">
							{{ t('pettycash', 'evidence.inactive') }}
						</NcChip>
						<div v-if="!ev.active" class="evidence-deactivation-info">
							<span v-if="ev.deactivatedAt">{{ t('pettycash', 'evidence.deactivatedAt') }} {{ new Date(ev.deactivatedAt).toLocaleString('fa-IR') }}</span>
						</div>
					</div>
				</template>
			</NcListItem>
		</div>
		<div v-else class="evidence-empty">
			<NcIconSvgWrapper :path="mdiFileOutline" :size="48" class="evidence-empty-icon" />
			<p>{{ t('pettycash', 'evidence.none') }}</p>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<style scoped>
.evidence-list {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.evidence-item {
	--nc-list-item-gap: 1rem;
}

.evidence-title {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.evidence-name {
	font-weight: 500;
}

.evidence-meta {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	font-size: 0.75rem;
	color: var(--nc-color-on-surface-variant, #616161);
	flex-wrap: wrap;
}

.evidence-actions {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	flex-wrap: wrap;
}

.evidence-deactivation-info {
	display: flex;
	flex-direction: column;
	gap: 0.125rem;
	font-size: 0.75rem;
	color: var(--nc-color-on-surface-variant, #616161);
}

.evidence-empty {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 2rem;
	color: var(--nc-color-on-surface-variant, #616161);
	text-align: center;
}

.evidence-empty-icon {
	margin-bottom: 0.5rem;
	opacity: 0.5;
}
</style>
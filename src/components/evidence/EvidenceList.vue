<script setup lang="ts">
import { computed, ref } from 'vue'
import { deleteEvidence, getEvidenceContent } from '@/services/evidenceApi'
import type { Evidence } from '@/types/api'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcChip from '@nextcloud/vue/components/NcChip'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { mdiImage, mdiFilePdfBox, mdiFileDocument, mdiFile, mdiDownload, mdiDelete, mdiFileOutline, mdiEye } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { formatSheetDateTime } from '@/utils/date'
import { isInlineSafeMediaType } from '@/utils/evidencePreview'

interface Props {
	evidence: Evidence[]
	isDraft: boolean
	/**
	 * FR-005-047 / C-21: the transaction has never been submitted *and* its sheet is still OPEN.
	 *
	 * In that state the ordinary attachment list is the purchaser's current working set, not a
	 * lifecycle record: withdrawn files are gone, and every remaining row is simply attached. The
	 * inactive-history presentation (badge and deactivation time) belongs to submitted and
	 * correction transactions, where 001 FR-059/FR-060 keep that history visible.
	 *
	 * The host passes the conjunction because neither fact alone is enough: a submitted legacy row
	 * can still read `DRAFT`, and an `OPEN` sheet can hold rows in other states. This is
	 * presentation only; it never changes stored evidence or server behavior.
	 */
	neverSubmitted?: boolean
}

const props = withDefaults(defineProps<Props>(), {
	isDraft: true,
	neverSubmitted: false
})

/** C-21: the ordinary working list contains only current attachments. */
const visibleEvidence = computed(() => props.neverSubmitted
	? props.evidence.filter((item) => item.active)
	: props.evidence)

const emit = defineEmits<{
	preview: [evidence: Evidence]
	/** T075: a successful removal changed the server's evidence set; the host must refresh it. */
	changed: []
}>()

const deleting = ref<string | null>(null)
const error = ref<string | null>(null)

function formatFileSize(bytes: number) {
	if (bytes < 1024) return bytes + ' B'
	if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
	return (bytes / (1024 * 1024)).toFixed(1) + ' MB'
}

function formatTimestamp(value: string): string {
	return formatSheetDateTime(value)
}

function getMediaTypeIcon(mediaType: string) {
	if (mediaType.startsWith('image/')) return mdiImage
	if (mediaType.startsWith('application/pdf')) return mdiFilePdfBox
	if (mediaType.startsWith('text/')) return mdiFileDocument
	return mdiFile
}

async function handleDownload(ev: Evidence) {
	try {
		// The stored type decides how the server serves this. `attachment` is what keeps a
		// non-inline-safe file (SVG, HTML, unknown) from ever being navigated to in this origin.
		const blob = await getEvidenceContent(ev.id, 'attachment')
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
		emit('changed')
	} catch (e: unknown) {
		const err = e as Error
		error.value = err.message
	} finally {
		deleting.value = null
	}
}
</script>

<template>
	<div class="evidence-list" data-test="evidence-list">
		<!--
			A plain list rather than `NcListItem`.

			`NcListItem` was previously used with `avatar` / `title` / `subtitle` slots, none of which
			exist in `@nextcloud/vue` 9.x — its slots are `icon`, `name`, `subname`, `details`,
			`indicator` and `actions` — so every evidence row rendered as an empty shell and a purchaser
			looking at a transaction's receipt saw nothing at all. Its remaining shape is wrong for this
			too: it always renders its row as an anchor, which for a non-navigable file is a focusable
			link to nowhere, and it puts its actions behind a hover-revealed three-dot menu, so the
			removal affordance FR-058 requires is hidden until the pointer happens to be over the row.
		-->
		<ul v-if="visibleEvidence.length" class="evidence-items">
			<li v-for="ev in visibleEvidence" :key="ev.id" class="evidence-item" data-test="evidence-list-item">
				<NcIconSvgWrapper
					:path="getMediaTypeIcon(ev.mediaType)"
					:size="24"
					:color="ev.active ? 'primary' : 'error'"
					class="evidence-item__icon"
				/>

				<div class="evidence-item__body">
					<span class="evidence-item__name" data-test="evidence-name">{{ ev.name }}</span>
					<div class="evidence-item__meta">
						<span data-test="evidence-type">{{ ev.mediaType }}</span>
						<span aria-hidden="true">·</span>
						<span data-test="evidence-size">{{ formatFileSize(ev.sizeBytes) }}</span>
						<span aria-hidden="true">·</span>
						<span data-test="evidence-upload-time">{{ formatTimestamp(ev.uploadedAt) }}</span>
					</div>
					<!-- FR-059: a deactivated file is retained, and its history stays viewable, so the
					     time it was withdrawn is part of what the row says. Without a timestamp the
					     row would say only that it is inactive, which is not the same information.
					     C-21 keeps this for submitted/correction history; a never-submitted OPEN-sheet
					     draft does not render lifecycle metadata at all. -->
					<div v-if="!props.neverSubmitted && !ev.active" class="evidence-item__deactivation" data-test="deactivation-info">
						<span v-if="ev.deactivatedAt">
							{{ t('pettycash', 'evidence.deactivatedAt') }} {{ formatTimestamp(ev.deactivatedAt) }}
						</span>
						<span v-else>{{ t('pettycash', 'evidence.deactivatedAtUnknown') }}</span>
					</div>
				</div>

				<div class="evidence-item__actions">
					<NcChip v-if="!props.neverSubmitted" :variant="ev.active ? 'success' : 'error'" data-test="evidence-active-badge">
						{{ ev.active ? t('pettycash', 'evidence.active') : t('pettycash', 'evidence.inactive') }}
					</NcChip>
					<!-- FR-005-042: preview is offered only for the server's inline-safe set. An SVG or
					     HTML file keeps the download control and gains no preview button at all, so the
					     UI cannot promise an inline rendering the server refuses to serve. -->
					<NcButton
						v-if="ev.active && isInlineSafeMediaType(ev.mediaType)"
						variant="tertiary"
						:aria-label="t('pettycash', 'evidence.previewNamed', { name: ev.name })"
						data-test="preview-evidence-btn"
						@click="emit('preview', ev)"
					>
						<NcIconSvgWrapper :path="mdiEye" :size="16" />
					</NcButton>
					<!-- FR-060: an authorized viewer must be able to download evidence and see its state,
					     so download is offered whenever the item is active — including to a reviewer
					     looking at a submitted transaction, which is the case download used to refuse. -->
					<NcButton
						v-if="ev.active"
						variant="tertiary"
						:aria-label="t('pettycash', 'evidence.downloadNamed', { name: ev.name })"
						data-test="download-evidence-btn"
						@click="handleDownload(ev)"
					>
						<NcIconSvgWrapper :path="mdiDownload" :size="16" />
					</NcButton>
					<!-- FR-058: removal belongs to the purchaser and only before submission. -->
					<NcButton
						v-if="ev.active && props.isDraft"
						variant="tertiary"
						:aria-label="t('pettycash', 'evidence.removeNamed', { name: ev.name })"
						:disabled="deleting === ev.id"
						:loading="deleting === ev.id"
						data-test="delete-evidence-btn"
						@click="handleDelete(ev)"
					>
						<NcIconSvgWrapper :path="mdiDelete" :size="16" />
					</NcButton>
				</div>
			</li>
		</ul>

		<div v-else class="evidence-empty" data-test="evidence-empty">
			<NcIconSvgWrapper :path="mdiFileOutline" :size="48" class="evidence-empty-icon" />
			<p>{{ t('pettycash', 'evidence.none') }}</p>
		</div>

		<NcNoteCard v-if="error" type="error" role="alert" data-test="evidence-error">
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

.evidence-items {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

.evidence-item {
	display: flex;
	align-items: center;
	gap: 1rem;
	padding: 0.75rem;
	background-color: var(--nc-color-surface-container-low, #fafafa);
	border-radius: 4px;
}

.evidence-item__icon {
	flex-shrink: 0;
}

.evidence-item__body {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	flex: 1;
	min-width: 0;
}

.evidence-item__name {
	font-weight: 500;
	overflow-wrap: anywhere;
}

.evidence-item__meta {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	font-size: var(--font-size-floor);
	color: var(--nc-color-on-surface-variant, #616161);
	flex-wrap: wrap;
}

.evidence-item__deactivation {
	display: flex;
	flex-direction: column;
	gap: 0.125rem;
	font-size: var(--font-size-floor);
	color: var(--nc-color-on-surface-variant, #616161);
}

.evidence-item__actions {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	flex-shrink: 0;
	flex-wrap: wrap;
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

/*
 * At a narrow viewport the row cannot stay on one line.
 *
 * `.evidence-item__actions` keeps its max-content width (three 68px buttons plus gaps = 219px)
 * because `flex-shrink: 0` stops it yielding, and the row is a nowrap flex line. The body then
 * absorbs the whole deficit and `overflow-wrap: anywhere` breaks the filename one character per
 * line: at 390px the body measured 21px in the detail view and 39.5px in the editing form.
 *
 * Grid gives the actions their own full-width row instead. `minmax(0, 1fr)` keeps the body the
 * shrinking track it already was, `align-items: start` aligns the icon with the first name line,
 * and `justify-content: flex-end` is direction-aware, so RTL and LTR place the actions on the same
 * physical side. DOM order (icon, body, actions) is unchanged, so no template or data-test hook
 * moves. Desktop keeps the single-line flex row.
 */
@media (width <= 640px) {
	.evidence-item {
		display: grid;
		grid-template-columns: auto minmax(0, 1fr);
		align-items: start;
	}

	.evidence-item__actions {
		grid-column: 1 / -1;
		justify-content: flex-end;
	}
}
</style>

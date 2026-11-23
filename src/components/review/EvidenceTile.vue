<template>
	<!--
		The tile element doubles as the interactive control (collapsed-card summary tile) and as the
		art-only presentation inside a larger row button (inline evidence rows), so a button never
		nests inside a button. In both modes the accessible name lives on the surrounding control —
		the image itself stays decorative (`alt=""`), announced once through the button's label.
	-->
	<button
		v-if="interactive"
		type="button"
		class="evidence-tile"
		:class="{ 'evidence-tile--thumb': thumbUrl !== null }"
		:aria-label="t('pettycash', 'reviews.quickReview.card.opensPreviewNamed', { name: item.name })"
		:title="item.name"
		:data-thumb-state="thumbUrl !== null ? 'thumb' : 'fallback'"
		:data-testid="testId"
		@click="emit('open')"
	>
		<img
			v-if="thumbUrl !== null"
			:src="thumbUrl"
			alt=""
			class="evidence-tile__image"
			data-testid="evidence-tile-image"
		>
		<span v-else class="evidence-tile__art" aria-hidden="true">
			<NcIconSvgWrapper :path="fileIcon" :size="iconSize" />
		</span>
		<span v-if="interactive" class="evidence-tile__label" aria-hidden="true">{{ extensionOf(item) }}</span>
	</button>

	<span
		v-else
		class="evidence-tile evidence-tile--static"
		:class="{ 'evidence-tile--thumb': thumbUrl !== null }"
		:data-thumb-state="thumbUrl !== null ? 'thumb' : 'fallback'"
		:data-testid="testId"
	>
		<img
			v-if="thumbUrl !== null"
			:src="thumbUrl"
			alt=""
			class="evidence-tile__image"
			data-testid="evidence-tile-image"
		>
		<span v-else class="evidence-tile__art" aria-hidden="true">
			<NcIconSvgWrapper :path="fileIcon" :size="iconSize" />
		</span>
	</span>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { t } from '@nextcloud/l10n'
import {
	mdiFile,
	mdiFileDocumentOutline,
	mdiFilePdfBox,
	mdiImage,
} from '@mdi/js'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { getEvidenceContent } from '@/services/evidenceApi'
import { isInlineSafeMediaType, normalizedMediaType } from '@/utils/evidencePreview'
import type { Evidence } from '@/types/api'

/**
 * One compact evidence tile (UXC-V2-06, owner-approved v2 baseline): a real image thumbnail when an
 * actual preview source exists, the honest file/type art when it does not.
 *
 * "Actual preview source" is the established evidence-content endpoint — the same source
 * `EvidencePreview.vue` and `EvidenceCard.vue` already load through. There is no backend thumbnail
 * endpoint, so a thumbnail means fetching the file's bytes for an **active** item whose normalized
 * media type is an inline-safe raster image; a fetched blob becomes an object URL that is revoked
 * when the tile unmounts (the same lifecycle `EvidenceCard` applies). Every other state keeps the
 * type-art tile: inactive evidence never renders content (C-16.8), PDFs and unknown files have no
 * raster preview, and a failed fetch must not fake one — the fallback says "this is a file of this
 * type", never "this receipt is blank".
 *
 * Thumbnails are fetched per mount and never cached beyond it: bounded (at most the caller's
 * visible tiles), leak-free, and always in step with the evidence's active state.
 */
interface Props {
	/** The transaction's own returned evidence item; the tile never renders another row's data. */
	item: Evidence
	/**
	 * `true` renders the tile as its own preview-opening button (the collapsed-card summary);
	 * `false` renders art only, for embedding inside a larger row control.
	 */
	interactive?: boolean
	/** Art-icon size; thumbnails scale to the tile box regardless. */
	iconSize?: number
	/**
	 * The tile's `data-testid`. Defaults to the tile's own name; a caller that owns a selector
	 * contract (the card's `transaction-card-thumb-*`) passes its own.
	 */
	testId?: string
}

const props = withDefaults(defineProps<Props>(), {
	interactive: true,
	iconSize: 22,
	testId: '',
})

const emit = defineEmits<{
	open: []
}>()

const testId = computed(() => props.testId !== '' ? props.testId : `evidence-tile-${props.item.id}`)

/** Only raster images render as thumbnails; PDF/text stay type-art tiles. */
const IMAGE_MEDIA_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp'] as const

const thumbUrl = ref<string | null>(null)

const isThumbnailCandidate = computed(() => {
	const mediaType = normalizedMediaType(props.item.mediaType)
	return (
		props.item.active !== false
		&& isInlineSafeMediaType(mediaType)
		&& (IMAGE_MEDIA_TYPES as readonly string[]).includes(mediaType)
	)
})

const fileIcon = computed(() => {
	const mediaType = normalizedMediaType(props.item.mediaType)
	if (mediaType.startsWith('image/')) return mdiImage
	if (mediaType === 'application/pdf') return mdiFilePdfBox
	if (mediaType.startsWith('application/')) return mdiFileDocumentOutline
	return mdiFile
})

function extensionOf(item: Evidence): string {
	const name = item.name ?? ''
	const dot = name.lastIndexOf('.')
	return dot >= 0 && dot < name.length - 1 ? name.slice(dot + 1).toLocaleUpperCase() : '—'
}

async function loadThumbnail(): Promise<void> {
	if (!isThumbnailCandidate.value) return
	try {
		const blob = await getEvidenceContent(props.item.id, 'inline')
		thumbUrl.value = URL.createObjectURL(blob)
	} catch {
		// The honest fallback is the type-art tile — the same presentation as "no preview source".
		thumbUrl.value = null
	}
}

onMounted(() => {
	void loadThumbnail()
})

/** Object URLs are a leak until revoked, and this tile renders inside `v-for` lists. */
onBeforeUnmount(() => {
	if (thumbUrl.value !== null) {
		URL.revokeObjectURL(thumbUrl.value)
		thumbUrl.value = null
	}
})
</script>

<style scoped>
.evidence-tile {
	position: relative;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 2px;
	padding: 4px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background-color: var(--color-background-hover);
	color: var(--color-primary-element);
	font-family: inherit;
	transition: border-color 0.15s ease, background-color 0.15s ease;
}

button.evidence-tile {
	cursor: pointer;
}

/* The caller owns the tile's box size; the static variant fills its row's art slot. */
.evidence-tile--static {
	inline-size: 100%;
	block-size: 100%;
}

button.evidence-tile:hover,
button.evidence-tile:focus-visible {
	border-color: var(--color-primary-element);
	background-color: color-mix(in srgb, var(--color-primary-element) 8%, var(--color-main-background));
}

button.evidence-tile:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

/*
 * The fetched thumbnail: the whole receipt stays visible (`contain` never crops a financial
 * document), letterboxed on the same quiet surface the type art uses.
 */
.evidence-tile__image {
	position: absolute;
	inset: 0;
	inline-size: 100%;
	block-size: 100%;
	object-fit: contain;
	border-radius: 7px;
	background-color: var(--color-background-hover);
}

.evidence-tile__art {
	display: inline-flex;
	align-items: center;
	justify-content: center;
}

.evidence-tile__label {
	max-inline-size: 100%;
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
	font-weight: 600;
	line-height: 1.2;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

@media (prefers-reduced-motion: reduce) {
	.evidence-tile {
		transition: none;
	}
}
</style>

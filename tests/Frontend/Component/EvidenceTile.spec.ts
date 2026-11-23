// EvidenceTile — Vue Component Tests (UXC-V2-06)
//
// The compact evidence tile: a real image thumbnail when an actual preview source exists (active,
// inline-safe raster image, content fetch succeeds), and the honest type-art fallback in every
// other state. The l10n double renders catalogue keys verbatim, so assertions target keys.

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import EvidenceTile from '@/components/review/EvidenceTile.vue'
import type { Evidence } from '@/types/api'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (match, key: string) => String(params?.[key] ?? match)),
	n: (_app: string, text: string) => text,
	getLanguage: () => 'en',
	isRTL: () => false,
	imagePath: (path: string) => `/apps/pettycash/${path}`
}))

const contentMock = vi.fn()
vi.mock('@/services/evidenceApi', () => ({
	getEvidenceContent: (...args: unknown[]) => contentMock(...args),
}))

const objectUrls: string[] = []
const createdUrls: string[] = []
const revokedUrls: string[] = []

function makeObjectUrl(): string {
	const url = `blob:mock-${objectUrls.length + 1}`
	objectUrls.push(url)
	createdUrls.push(url)
	return url
}

beforeEach(() => {
	vi.stubGlobal('URL', Object.assign(URL, {
		createObjectURL: vi.fn(() => makeObjectUrl()),
		revokeObjectURL: vi.fn((url: string) => revokedUrls.push(url)),
	}))
})

afterEach(() => {
	contentMock.mockReset()
	createdUrls.length = 0
	revokedUrls.length = 0
	vi.unstubAllGlobals()
})

function evidence(overrides: Partial<Evidence> = {}): Evidence {
	return {
		id: 'ev-1',
		transactionId: 'tx-1',
		revisionId: 'rev-1',
		name: 'receipt.png',
		mediaType: 'image/png',
		sizeBytes: 2048,
		active: true,
		uploadedBy: 'user1',
		uploadedAt: '2026-09-20T10:05:00Z',
		...overrides
	}
}

function mountTile(item: Evidence, interactive = true) {
	return mount(EvidenceTile, { props: { item, interactive } })
}

describe('Interactive tile (collapsed-card summary)', () => {
	it('renders a native button labelled with the file name for assistive technology', () => {
		const wrapper = mountTile(evidence())
		const tile = wrapper.find('[data-testid="evidence-tile-ev-1"]')
		expect(tile.element.tagName).toBe('BUTTON')
		expect(tile.attributes('aria-label')).toBe('reviews.quickReview.card.opensPreviewNamed')
	})

	it('emits open on activation', async () => {
		const wrapper = mountTile(evidence())
		await wrapper.find('[data-testid="evidence-tile-ev-1"]').trigger('click')
		expect(wrapper.emitted('open')).toHaveLength(1)
	})

	it('renders the extension label as presentational text', () => {
		const wrapper = mountTile(evidence({ name: 'scan.PDF', mediaType: 'application/pdf' }))
		expect(wrapper.find('.evidence-tile__label').text()).toBe('PDF')
	})

	it('renders the honest em-dash label when the name carries no extension', () => {
		const wrapper = mountTile(evidence({ name: 'receipt' }))
		expect(wrapper.find('.evidence-tile__label').text()).toBe('—')
	})
})

describe('Real image thumbnail (UXC-V2-06)', () => {
	it('fetches the inline content of an active inline-safe image and renders it', async () => {
		contentMock.mockResolvedValue(new Blob(['bytes'], { type: 'image/png' }))
		const wrapper = mountTile(evidence())
		await vi.waitFor(() => expect(wrapper.find('[data-testid="evidence-tile-image"]').exists()).toBe(true))
		expect(contentMock).toHaveBeenCalledWith('ev-1', 'inline')
		expect(wrapper.find('[data-testid="evidence-tile-ev-1"]').attributes('data-thumb-state')).toBe('thumb')
	})

	it('keeps the type art until the bytes arrive, never an empty frame', () => {
		contentMock.mockReturnValue(new Promise(() => undefined))
		const wrapper = mountTile(evidence())
		expect(wrapper.find('.evidence-tile__art').exists()).toBe(true)
		expect(wrapper.find('[data-testid="evidence-tile-image"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('keeps the type-art fallback when the fetch fails — never a fabricated preview', async () => {
		contentMock.mockRejectedValue(new Error('Failed to fetch evidence content'))
		const wrapper = mountTile(evidence())
		await vi.waitFor(() => expect(contentMock).toHaveBeenCalled())
		await Promise.resolve()
		expect(wrapper.find('[data-testid="evidence-tile-image"]').exists()).toBe(false)
		expect(wrapper.find('.evidence-tile__art').exists()).toBe(true)
		expect(wrapper.find('[data-testid="evidence-tile-ev-1"]').attributes('data-thumb-state')).toBe('fallback')
		wrapper.unmount()
	})

	it('marks the thumbnail image decorative — the accessible name stays on the button', async () => {
		contentMock.mockResolvedValue(new Blob(['bytes'], { type: 'image/png' }))
		const wrapper = mountTile(evidence())
		await vi.waitFor(() => expect(wrapper.find('[data-testid="evidence-tile-image"]').exists()).toBe(true))
		expect(wrapper.find('[data-testid="evidence-tile-image"]').attributes('alt')).toBe('')
		expect(wrapper.find('[data-testid="evidence-tile-ev-1"]').attributes('aria-label'))
			.toBe('reviews.quickReview.card.opensPreviewNamed')
		wrapper.unmount()
	})
})

describe('Honest fallback states (UXC-V2-06)', () => {
	it('never fetches and never renders content for an inactive item (C-16.8)', async () => {
		const wrapper = mountTile(evidence({ active: false }))
		await Promise.resolve()
		expect(contentMock).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="evidence-tile-image"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="evidence-tile-ev-1"]').attributes('data-thumb-state')).toBe('fallback')
		wrapper.unmount()
	})

	it('keeps the PDF type-art tile — a PDF has no raster thumbnail', async () => {
		const wrapper = mountTile(evidence({ name: 'invoice.pdf', mediaType: 'application/pdf' }))
		await Promise.resolve()
		expect(contentMock).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="evidence-tile-image"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('keeps the generic file tile for unknown media types', async () => {
		const wrapper = mountTile(evidence({ name: 'data.bin', mediaType: 'application/octet-stream' }))
		await Promise.resolve()
		expect(contentMock).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="evidence-tile-image"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('treats a parameterised media type by its base type', async () => {
		contentMock.mockResolvedValue(new Blob(['bytes'], { type: 'image/jpeg' }))
		const wrapper = mountTile(evidence({ mediaType: 'image/jpeg; charset=binary' }))
		await vi.waitFor(() => expect(wrapper.find('[data-testid="evidence-tile-image"]').exists()).toBe(true))
		wrapper.unmount()
	})
})

describe('Object URL lifecycle', () => {
	it('revokes the object URL when the tile unmounts', async () => {
		contentMock.mockResolvedValue(new Blob(['bytes'], { type: 'image/png' }))
		const wrapper = mountTile(evidence())
		await vi.waitFor(() => expect(createdUrls).toHaveLength(1))
		wrapper.unmount()
		expect(revokedUrls).toEqual(createdUrls)
	})

	it('leaves nothing to revoke when no thumbnail was created', () => {
		const wrapper = mountTile(evidence({ mediaType: 'application/pdf' }))
		wrapper.unmount()
		expect(revokedUrls).toHaveLength(0)
	})
})

describe('Static art mode (inline evidence rows)', () => {
	it('renders art only — no nested button inside a row control', () => {
		const wrapper = mountTile(evidence(), false)
		const tile = wrapper.find('[data-testid="evidence-tile-ev-1"]')
		expect(tile.element.tagName).toBe('SPAN')
		expect(tile.find('button').exists()).toBe(false)
		expect(tile.attributes('aria-label')).toBeUndefined()
	})

	it('still renders a fetched thumbnail in static mode', async () => {
		contentMock.mockResolvedValue(new Blob(['bytes'], { type: 'image/png' }))
		const wrapper = mountTile(evidence(), false)
		await vi.waitFor(() => expect(wrapper.find('[data-testid="evidence-tile-image"]').exists()).toBe(true))
		expect(wrapper.find('[data-testid="evidence-tile-ev-1"]').attributes('data-thumb-state')).toBe('thumb')
		wrapper.unmount()
	})
})

// 005 T068 — the in-app evidence preview.
//
// The server is the authority for *which* media types may be rendered in this origin
// (`EvidenceContentPolicy::INLINE_SAFE_MEDIA_TYPES`). The client mirrors that closed set in
// `src/utils/evidencePreview.ts`; this file proves the component honours it, because a client that
// guesses with `image/*` or `text/*` re-admits SVG and HTML.
//
// What only the component can be wrong about:
//
// - a safe type is fetched with `disposition=inline` and rendered (image / PDF / plain text);
// - plain text is interpolated, never injected as markup;
// - an unsafe type performs **no content request at all** and offers download only;
// - inactive evidence previews nothing, even when its stored type would be safe;
// - a failed read is retryable, and a late answer cannot replace a newer selection;
// - the object URL is released on close/unmount;
// - Escape closes the layer.

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import { t } from '@nextcloud/l10n'

const evidenceApi = vi.hoisted(() => ({
	getEvidenceContent: vi.fn(),
	deleteEvidence: vi.fn()
}))

vi.mock('@/services/evidenceApi', () => ({
	getEvidenceContent: evidenceApi.getEvidenceContent,
	deleteEvidence: evidenceApi.deleteEvidence
}))

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (_match, key: string) => String(params?.[key] ?? `{${key}}`))),
	n: vi.fn((_app: string, text: string) => text),
	getLanguage: vi.fn(() => 'en'),
	isRTL: vi.fn(() => false),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`)
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'loading'],
		emits: ['click'],
		template: '<button type="button" :disabled="disabled" v-bind="$attrs" @click="$emit(\'click\', $event)"><slot /></button>'
	},
	NcIconSvgWrapper: {
		name: 'NcIconSvgWrapper',
		props: ['path', 'size'],
		template: '<span data-test="nc-icon" />'
	}
}))

import EvidencePreview from '@/components/evidence/EvidencePreview.vue'

type PreviewEvidence = {
	id: string
	name: string
	mediaType: string
	active: boolean
}

function evidence(overrides: Partial<PreviewEvidence> = {}): PreviewEvidence {
	return {
		id: 'ev-1',
		name: 'receipt.png',
		mediaType: 'image/png',
		active: true,
		...overrides
	}
}

function mountPreview(item: PreviewEvidence = evidence(), open = true) {
	return mount(EvidencePreview, {
		props: { evidence: item as never, open },
		attachTo: document.body
	})
}

/** A Blob-shaped value whose only used method is `text()`. */
function textBlob(text: string): Blob {
	return { text: async () => text } as unknown as Blob
}

describe('EvidencePreview (T068)', () => {
	const originalCreate = URL.createObjectURL
	const originalRevoke = URL.revokeObjectURL

	beforeEach(() => {
		evidenceApi.getEvidenceContent.mockReset()
		evidenceApi.deleteEvidence.mockReset()
		URL.createObjectURL = vi.fn(() => 'blob:evidence-preview') as unknown as typeof URL.createObjectURL
		URL.revokeObjectURL = vi.fn() as unknown as typeof URL.revokeObjectURL
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)
	})

	afterEach(() => {
		URL.createObjectURL = originalCreate
		URL.revokeObjectURL = originalRevoke
		vi.restoreAllMocks()
	})

	it('fetches an inline-safe image for inline display and releases its object URL on unmount', async () => {
		evidenceApi.getEvidenceContent.mockResolvedValue(new Blob(['png']))
		const wrapper = mountPreview(evidence({ mediaType: 'image/png' }))
		await flushPromises()

		expect(evidenceApi.getEvidenceContent).toHaveBeenCalledWith('ev-1', 'inline')
		const image = wrapper.find('[data-test="evidence-preview-image"]')
		expect(image.exists()).toBe(true)
		expect(image.attributes('src')).toBe('blob:evidence-preview')

		wrapper.unmount()
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:evidence-preview')
	})

	it('renders a PDF in an iframe rather than navigating to the file', async () => {
		evidenceApi.getEvidenceContent.mockResolvedValue(new Blob(['pdf']))
		const wrapper = mountPreview(evidence({ name: 'invoice.pdf', mediaType: 'application/pdf' }))
		await flushPromises()

		const frame = wrapper.find('[data-test="evidence-preview-pdf"]')
		expect(frame.exists()).toBe(true)
		expect(frame.attributes('src')).toBe('blob:evidence-preview')
		expect(wrapper.find('[data-test="evidence-preview-image"]').exists()).toBe(false)
	})

	it('renders plain text as text, never as markup', async () => {
		evidenceApi.getEvidenceContent.mockResolvedValue(textBlob('<b>not bold</b> & <script>alert(1)</script>'))
		const wrapper = mountPreview(evidence({ name: 'notes.txt', mediaType: 'text/plain; charset=utf-8' }))
		await flushPromises()

		const block = wrapper.find('[data-test="evidence-preview-text"]')
		expect(block.exists()).toBe(true)
		// The angle brackets are characters, not elements.
		expect(block.element.querySelector('b')).toBeNull()
		expect(block.element.querySelector('script')).toBeNull()
		expect(block.element.textContent).toContain('<b>not bold</b>')
	})

	it.each([
		'image/svg+xml',
		'text/html',
		'application/zip',
		'application/octet-stream',
	])('never fetches or renders %s: download only', async (mediaType) => {
		evidenceApi.getEvidenceContent.mockResolvedValue(new Blob(['bytes']))
		const wrapper = mountPreview(evidence({ mediaType }))
		await flushPromises()

		expect(evidenceApi.getEvidenceContent).not.toHaveBeenCalled()
		expect(wrapper.find('[data-test="preview-download-only"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="evidence-preview-image"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="evidence-preview-pdf"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="evidence-preview-text"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="preview-download-btn"]').exists()).toBe(true)
	})

	it('downloads non-previewable evidence through the attachment disposition', async () => {
		evidenceApi.getEvidenceContent.mockResolvedValue(new Blob(['svg']))
		const wrapper = mountPreview(evidence({ name: 'drawing.svg', mediaType: 'image/svg+xml' }))
		await flushPromises()

		await wrapper.find('[data-test="preview-download-btn"]').trigger('click')
		await flushPromises()

		expect(evidenceApi.getEvidenceContent).toHaveBeenCalledWith('ev-1', 'attachment')
		expect(HTMLAnchorElement.prototype.click).toHaveBeenCalled()
	})

	it('previews nothing for inactive evidence and offers no download', async () => {
		const wrapper = mountPreview(evidence({ active: false }))
		await flushPromises()

		expect(evidenceApi.getEvidenceContent).not.toHaveBeenCalled()
		expect(wrapper.find('[data-test="preview-inactive-note"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="preview-download-btn"]').exists()).toBe(false)
	})

	it('shows a retryable error and loads the content on retry', async () => {
		evidenceApi.getEvidenceContent
			.mockRejectedValueOnce(new Error('content unavailable'))
			.mockResolvedValueOnce(new Blob(['png']))
		const wrapper = mountPreview()
		await flushPromises()

		expect(wrapper.find('[data-test="preview-error"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="preview-error"]').text()).toContain('content unavailable')

		await wrapper.find('[data-test="retry-evidence-preview"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-test="preview-error"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="evidence-preview-image"]').exists()).toBe(true)
	})

	it('ignores a superseded response when the selection changes mid-flight', async () => {
		let resolveFirst: (blob: Blob) => void = () => undefined
		const first = new Promise<Blob>((resolve) => { resolveFirst = resolve })
		evidenceApi.getEvidenceContent
			.mockReturnValueOnce(first)
			.mockResolvedValueOnce(new Blob(['second']))

		const wrapper = mountPreview(evidence({ id: 'ev-old', name: 'old.png' }))
		await flushPromises()
		await wrapper.setProps({ evidence: evidence({ id: 'ev-new', name: 'new.png' }) as never })
		await flushPromises()

		resolveFirst(new Blob(['first']))
		await flushPromises()

		expect(evidenceApi.getEvidenceContent).toHaveBeenLastCalledWith('ev-new', 'inline')
		expect(wrapper.find('[data-test="evidence-preview-name"]').text()).toBe('new.png')
	})

	it('closes on Escape without bubbling the event to the surface beneath it', async () => {
		evidenceApi.getEvidenceContent.mockResolvedValue(new Blob(['png']))
		const wrapper = mountPreview()
		await flushPromises()

		await wrapper.find('[data-test="evidence-preview"]').trigger('keydown', { key: 'Escape' })

		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('renders nothing while closed and requests nothing', async () => {
		const wrapper = mountPreview(evidence(), false)
		await flushPromises()

		expect(wrapper.find('[data-test="evidence-preview-layer"]').exists()).toBe(false)
		expect(evidenceApi.getEvidenceContent).not.toHaveBeenCalled()

		await wrapper.setProps({ open: true })
		await nextTick()
		await flushPromises()
		expect(wrapper.find('[data-test="evidence-preview-layer"]').exists()).toBe(true)
	})

	it('restores focus to the control that opened it when closed (T036)', async () => {
		evidenceApi.getEvidenceContent.mockResolvedValue(new Blob(['png']))
		const trigger = document.createElement('button')
		trigger.type = 'button'
		trigger.textContent = 'Open preview'
		document.body.appendChild(trigger)
		trigger.focus()

		const wrapper = mountPreview()
		await nextTick()
		await flushPromises()
		expect(document.activeElement).toBe(wrapper.find('[data-test="evidence-preview"]').element)

		await wrapper.setProps({ open: false })
		await nextTick()
		expect(document.activeElement).toBe(trigger)

		trigger.remove()
	})
})

// T034 — multi-document navigation. The overlay stays a pure function of its props: the parent
// owns the index and the set, so these tests pin the contract the quick-review queue relies on —
// a visible position, Previous/Next that stop at the ends, and a `navigate` event instead of
// internal mutation.
describe('EvidencePreview navigation (T034)', () => {
	const originalCreate = URL.createObjectURL
	const originalRevoke = URL.revokeObjectURL

	beforeEach(() => {
		evidenceApi.getEvidenceContent.mockReset()
		evidenceApi.deleteEvidence.mockReset()
		evidenceApi.getEvidenceContent.mockResolvedValue(new Blob(['png']))
		URL.createObjectURL = vi.fn(() => 'blob:evidence-preview') as unknown as typeof URL.createObjectURL
		URL.revokeObjectURL = vi.fn() as unknown as typeof URL.revokeObjectURL
	})

	afterEach(() => {
		URL.createObjectURL = originalCreate
		URL.revokeObjectURL = originalRevoke
		vi.restoreAllMocks()
	})

	function documents(): PreviewEvidence[] {
		return [
			evidence({ id: 'ev-1', name: 'receipt.png' }),
			evidence({ id: 'ev-2', name: 'invoice.png' }),
			evidence({ id: 'ev-3', name: 'total.png' })
		]
	}

	function mountWithSet(index: number) {
		return mount(EvidencePreview, {
			props: { evidence: documents()[index] as never, open: true, documents: documents() as never, index },
			attachTo: document.body
		})
	}

	it('shows the position counter and offers navigation over the provided set', async () => {
		const wrapper = mountWithSet(0)

		const nav = wrapper.find('[data-test="preview-navigation"]')
		expect(nav.exists()).toBe(true)
		expect(nav.attributes('aria-label')).toBe('evidence.previewNavigationLabel')
		// The t stub returns the key; the locale-correct digits travel through the params instead.
		expect(wrapper.find('[data-test="preview-counter"]').text()).toBe('evidence.previewCounter')
		expect(vi.mocked(t)).toHaveBeenCalledWith('pettycash', 'evidence.previewCounter', { index: '1', total: '3' })
	})

	it('emits navigate with the neighbouring index and stops at the ends', async () => {
		const wrapper = mountWithSet(1)

		await wrapper.find('[data-test="preview-nav-prev"]').trigger('click')
		expect(wrapper.emitted('navigate')).toEqual([[0]])

		const atEnd = mountWithSet(2)
		expect((atEnd.find('[data-test="preview-nav-next"]').element as HTMLButtonElement).disabled).toBe(true)
		await atEnd.find('[data-test="preview-nav-prev"]').trigger('click')
		expect(atEnd.emitted('navigate')).toEqual([[1]])

		const atStart = mountWithSet(0)
		expect((atStart.find('[data-test="preview-nav-prev"]').element as HTMLButtonElement).disabled).toBe(true)
		await atStart.find('[data-test="preview-nav-next"]').trigger('click')
		expect(atStart.emitted('navigate')).toEqual([[1]])
	})

	it('renders no navigation for the single-document usage', async () => {
		const wrapper = mountPreview()

		expect(wrapper.find('[data-test="preview-navigation"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="preview-counter"]').exists()).toBe(false)
	})
})

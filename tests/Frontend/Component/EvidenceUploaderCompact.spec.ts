import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import EvidenceUploader from '@/components/evidence/EvidenceUploader.vue'
import * as evidenceApi from '@/services/evidenceApi'

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (_match, key: string) => String(params?.[key] ?? `{${key}}`))),
	n: vi.fn((_app: string, text: string) => text),
	getLanguage: vi.fn(() => 'fa'),
	isRTL: vi.fn(() => true),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`)
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'loading'],
		emits: ['click'],
		template: '<button type="button" :disabled="disabled" :aria-busy="loading ? \'true\' : undefined" @click="$emit(\'click\')"><slot /></button>'
	},
	NcChip: {
		name: 'NcChip',
		template: '<span><slot /></span>'
	},
	NcIconSvgWrapper: {
		name: 'NcIconSvgWrapper',
		props: ['path', 'size', 'color'],
		template: '<span data-test="nc-icon" />'
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		template: '<div><slot /></div>'
	},
	NcProgressBar: {
		name: 'NcProgressBar',
		props: ['value', 'indeterminate', 'color'],
		template: '<progress :value="value" :indeterminate="indeterminate" />'
	}
}))

const LONG_FILE_NAME = 'receipt-for-the-quarterly-office-supplies-reimbursement-2026-10-25-with-a-very-long-descriptive-name-and-no-early-truncation.pdf'

function mountUploader(props: Record<string, unknown> = {}) {
	return mount(EvidenceUploader, {
		props: {
			transactionId: 'tx-1',
			isDraft: true,
			...props
		}
	})
}

function fileOfSize(bytes: number, name = 'receipt.pdf'): File {
	const file = new File(['x'], name, { type: 'application/pdf' })
	Object.defineProperty(file, 'size', { value: bytes })
	return file
}

async function selectFile(wrapper: VueWrapper, file: File) {
	const input = wrapper.get('[data-test="evidence-upload-input"]')
	Object.defineProperty(input.element, 'files', { value: [file], configurable: true })
	await input.trigger('change')
	await wrapper.vm.$nextTick()
}

describe('EvidenceUploader compact presentation', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('defaults to the unchanged non-compact presentation used by the detail view', async () => {
		const wrapper = mountUploader()

		expect(wrapper.classes()).not.toContain('evidence-uploader--compact')
		await selectFile(wrapper, fileOfSize(1572864, 'receipt.pdf'))
		expect(wrapper.find('[data-test="file-name-toggle"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="file-full-name"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="file-size-badge"]').exists()).toBe(true)
	})

	it('renders the selected filename, size, and remove/upload controls in compact mode', async () => {
		const wrapper = mountUploader({ compact: true })

		expect(wrapper.classes()).toContain('evidence-uploader--compact')
		await selectFile(wrapper, fileOfSize(1572864, LONG_FILE_NAME))

		const toggle = wrapper.get('[data-test="file-name-toggle"]')
		expect(toggle.text()).toBe(LONG_FILE_NAME)
		expect(toggle.attributes('title')).toBe(LONG_FILE_NAME)
		expect(toggle.attributes('aria-expanded')).toBe('false')
		expect(wrapper.get('[data-test="drop-zone"]').text()).toContain('1.5 MB')
		expect(wrapper.find('[data-test="remove-file-btn"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="upload-btn"]').exists()).toBe(true)
	})

	it('makes a truncated long filename inspectable by keyboard and touch', async () => {
		const wrapper = mountUploader({ compact: true })
		await selectFile(wrapper, fileOfSize(1024, LONG_FILE_NAME))

		const toggle = wrapper.get('[data-test="file-name-toggle"]')
		await toggle.trigger('click')

		const fullName = wrapper.get('[data-test="file-full-name"]')
		expect(fullName.text()).toBe(LONG_FILE_NAME)
		expect(fullName.attributes('tabindex')).toBe('0')
		expect(fullName.attributes('aria-label')).toBe(LONG_FILE_NAME)
		expect(toggle.attributes('aria-expanded')).toBe('true')

		await toggle.trigger('click')
		expect(wrapper.find('[data-test="file-full-name"]').exists()).toBe(false)
	})

	it('removes the selected file and clears the full-name inspection state', async () => {
		const wrapper = mountUploader({ compact: true })
		await selectFile(wrapper, fileOfSize(1024, LONG_FILE_NAME))
		await wrapper.get('[data-test="file-name-toggle"]').trigger('click')

		await wrapper.get('[data-test="remove-file-btn"]').trigger('click')

		expect(wrapper.find('[data-test="file-name-toggle"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="file-full-name"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="select-file-btn"]').exists()).toBe(true)
	})

	it('keeps the inclusive 100 MB limit and its stated refusal', async () => {
		const wrapper = mountUploader({ compact: true })

		await selectFile(wrapper, fileOfSize(101 * 1024 * 1024))
		const refusal = wrapper.get('[data-test="file-size-error"]')
		expect(refusal.text()).toContain('100 MB')
		expect(refusal.attributes('role')).toBe('alert')
		expect(wrapper.find('[data-test="upload-btn"]').exists()).toBe(false)

		await selectFile(wrapper, fileOfSize(100 * 1024 * 1024))
		expect(wrapper.find('[data-test="file-size-error"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="upload-btn"]').exists()).toBe(true)
	})

	it('preserves the existing upload path and successful-upload handling', async () => {
		const uploadSpy = vi.spyOn(evidenceApi, 'uploadEvidence')
			.mockResolvedValue({ id: 'ev-1' } as never)
		const wrapper = mountUploader({ compact: true })
		const file = fileOfSize(1024, 'receipt.pdf')
		await selectFile(wrapper, file)

		await wrapper.get('[data-test="upload-btn"]').trigger('click')
		await flushPromises()

		expect(uploadSpy).toHaveBeenCalledTimes(1)
		expect(uploadSpy).toHaveBeenCalledWith('tx-1', file)
		expect(wrapper.emitted('uploaded')).toHaveLength(1)
		expect(wrapper.find('[data-test="upload-btn"]').exists()).toBe(false)
	})

	it('preserves progress and upload failure handling', async () => {
		let resolveUpload!: (value: unknown) => void
		const pendingUpload = new Promise((resolve) => {
			resolveUpload = resolve
		})
		vi.spyOn(evidenceApi, 'uploadEvidence').mockReturnValue(pendingUpload as never)
		const wrapper = mountUploader({ compact: true })
		await selectFile(wrapper, fileOfSize(1024, 'receipt.pdf'))

		await wrapper.get('[data-test="upload-btn"]').trigger('click')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-test="upload-progress"]').exists()).toBe(true)

		resolveUpload({ id: 'ev-1' })
		await flushPromises()

		vi.spyOn(evidenceApi, 'uploadEvidence').mockRejectedValue(new Error('Upload failed'))
		await selectFile(wrapper, fileOfSize(1024, 'second.pdf'))
		await wrapper.get('[data-test="upload-btn"]').trigger('click')
		await flushPromises()
		expect(wrapper.get('[data-test="file-size-error"]').text()).toContain('Upload failed')
		expect(wrapper.find('[data-test="upload-btn"]').exists()).toBe(true)
	})

	it('activates compact mode only from the transaction form, never the detail view', () => {
		const formSource = readFileSync(
			resolve(process.cwd(), 'src/views/transactions/TransactionFormView.vue'),
			'utf8'
		)
		const detailSource = readFileSync(
			resolve(process.cwd(), 'src/views/transactions/TransactionDetailView.vue'),
			'utf8'
		)
		const formUploader = formSource.match(/<EvidenceUploader[\s\S]*?\/>/)?.[0] ?? ''
		const detailUploader = detailSource.match(/<EvidenceUploader[\s\S]*?\/>/)?.[0] ?? ''

		expect(formUploader).not.toBe('')
		expect(formUploader).toContain('compact')
		expect(detailUploader).not.toBe('')
		expect(detailUploader).not.toContain('compact')
	})
})

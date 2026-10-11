import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory } from 'vue-router'
import { routes } from '@/router'

const ocsRequest = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({
	default: {
		request: (...args: unknown[]) => ocsRequest(...args),
		isAxiosError: (error: unknown) =>
			Boolean((error as { isAxiosError?: boolean } | null)?.isAxiosError)
	}
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => `/ocs/v2.php${path}`,
	imagePath: (path: string) => `/apps/pettycash/${path}`,
	appName: 'pettycash',
	appVersion: '1.0.0'
}))

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
		props: ['variant', 'disabled', 'loading', 'type'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>'
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['heading', 'type'],
		template: '<div><slot /></div>'
	},
	NcChip: {
		name: 'NcChip',
		template: '<span><slot /></span>'
	},
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'open'],
		emits: ['update:open'],
		template: '<div v-if="open"><slot /></div>'
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />'
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		template: '<select><slot /></select>'
	}
}))

import { listTransactionAudit, listTransactionRevisions } from '@/services/auditApi'
import { useAuditStore } from '@/stores/audit'
import AuditTimeline from '@/components/audit/AuditTimeline.vue'
import RevisionHistory from '@/components/audit/RevisionHistory.vue'
import EvidencePreview from '@/components/evidence/EvidencePreview.vue'
import EvidenceLinkManagement from '@/components/evidence/EvidenceLinkManagement.vue'
import AuditView from '@/views/audit/AuditView.vue'
import type { AuditEvent } from '@/types/api'

function ocsEnvelope<T>(data: T) {
	return { status: 200, data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data } } }
}

function auditEventFixture(overrides: Partial<AuditEvent> = {}): AuditEvent {
	return {
		id: 'evt-1',
		eventType: 'SUBMITTED',
		stage: 'COST_CENTER',
		decision: null,
		explanation: 'Submitted for review',
		actor: { userId: 'employee1', displayName: 'Employee One', role: 'PURCHASER' },
		before: null,
		after: null,
		occurredAt: '2026-09-20T10:00:00+00:00',
		...overrides
	}
}

function mountView(component: object, path: string, props?: Record<string, unknown>) {
	const router = createRouter({ history: createWebHashHistory(), routes })
	const wrapper = mount(component as never, {
		props: props as never,
		global: { plugins: [router, createPinia()] }
	})
	return { wrapper, router }
}

describe('AuditEvidence (US6)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		ocsRequest.mockReset()
		vi.unstubAllGlobals()
	})

	describe('auditApi service (T097)', () => {
		it('reads a transaction audit page with typed events', async () => {
			const page = { items: [auditEventFixture()], page: 1, pageSize: 25, total: 1 }
			ocsRequest.mockResolvedValueOnce(ocsEnvelope(page))

			const result = await listTransactionAudit('tx-1')

			expect(result.total).toBe(1)
			expect(result.items[0]?.actor.displayName).toBe('Employee One')
			expect(ocsRequest).toHaveBeenCalledOnce()
			expect(String((ocsRequest.mock.calls[0]?.[0] as { url?: string } | undefined)?.url ?? '')).toContain('/transactions/tx-1/audit')
		})

		it('reads revision history as typed revisions', async () => {
			ocsRequest.mockResolvedValueOnce(ocsEnvelope([{ id: 'rev-1', number: 1 }]))

			const result = await listTransactionRevisions('tx-1')

			expect(result).toHaveLength(1)
			expect(String((ocsRequest.mock.calls[0]?.[0] as { url?: string } | undefined)?.url ?? '')).toContain('/transactions/tx-1/revisions')
		})
	})

	describe('audit store (T097)', () => {
		it('loads audit items and page metadata, and records failures', async () => {
			const store = useAuditStore()
			const page = { items: [auditEventFixture()], page: 1, pageSize: 25, total: 1 }
			ocsRequest.mockResolvedValueOnce(ocsEnvelope(page))

			await store.loadAudit('tx-1')

			expect(store.items).toHaveLength(1)
			expect(store.pagination.total).toBe(1)
			expect(store.error).toBeNull()
		})

		it('surfaces server failures through error rather than throwing past the view', async () => {
			const store = useAuditStore()
			ocsRequest.mockRejectedValueOnce(new Error('boom'))

			await expect(store.loadAudit('tx-1')).rejects.toThrow()
			expect(store.error).not.toBeNull()
		})
	})

	describe('AuditTimeline component (T098)', () => {
		it('renders one row per event with actor, stage and explanation', () => {
			const { wrapper } = mountView(AuditTimeline, '/audit/tx-1', {
				events: [auditEventFixture(), auditEventFixture({ id: 'evt-2', eventType: 'APPROVE', actor: { userId: 'mgr', displayName: 'Manager', role: 'COST_CENTER_MANAGER' } })]
			})

			const rows = wrapper.findAll('[data-test="audit-event"]')
			expect(rows).toHaveLength(2)
			expect(rows[0]?.text()).toContain('Employee One')
			expect(rows[1]?.text()).toContain('Manager')
		})

		it('renders an empty state instead of an empty table', () => {
			const { wrapper } = mountView(AuditTimeline, '/audit/tx-1', { events: [] })

			expect(wrapper.find('[data-test="audit-empty"]').exists()).toBe(true)
			expect(wrapper.findAll('[data-test="audit-event"]')).toHaveLength(0)
		})

		it('shows before/after values where the event carries them', () => {
			const { wrapper } = mountView(AuditTimeline, '/audit/tx-1', {
				events: [auditEventFixture({ before: { stage: 'DRAFT' }, after: { stage: 'COST_CENTER' } })]
			})

			expect(wrapper.find('[data-test="audit-diff"]').exists()).toBe(true)
		})
	})

	describe('RevisionHistory component (T098)', () => {
		it('renders one tab per revision with its number', () => {
			const { wrapper } = mountView(RevisionHistory, '/audit/tx-1', {
				revisions: [
					{ id: 'rev-1', number: 1, expenseDate: '2026-09-20' },
					{ id: 'rev-2', number: 2, expenseDate: '2026-09-21' }
				]
			})

			const tabs = wrapper.findAll('[data-test="revision-tab"]')
			expect(tabs).toHaveLength(2)
		})

		it('renders an empty state when there are no revisions', () => {
			const { wrapper } = mountView(RevisionHistory, '/audit/tx-1', { revisions: [] })

			expect(wrapper.find('[data-test="revision-empty"]').exists()).toBe(true)
		})
	})

	describe('EvidencePreview component (T098, FR-060)', () => {
		const originalCreate = URL.createObjectURL
		const originalRevoke = URL.revokeObjectURL

		beforeEach(() => {
			URL.createObjectURL = vi.fn(() => 'blob:evidence') as unknown as typeof URL.createObjectURL
			URL.revokeObjectURL = vi.fn() as unknown as typeof URL.revokeObjectURL
			vi.stubGlobal('fetch', vi.fn(async () => new Response(new Blob(['bytes']), { status: 200 })))
		})

		afterEach(() => {
			URL.createObjectURL = originalCreate
			URL.revokeObjectURL = originalRevoke
		})

		it('renders images inline rather than as a download', async () => {
			const { wrapper } = mountView(EvidencePreview, '/audit/tx-1', {
				evidence: { id: 'ev-1', name: 'scan.png', mediaType: 'image/png', sizeBytes: 100, active: true }
			})
			await flushPromises()

			expect(wrapper.find('[data-test="evidence-preview-image"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="download-evidence-btn"]').exists()).toBe(false)
		})

		it('renders PDFs inline', async () => {
			const { wrapper } = mountView(EvidencePreview, '/audit/tx-1', {
				evidence: { id: 'ev-1', name: 'bill.pdf', mediaType: 'application/pdf', sizeBytes: 100, active: true }
			})
			await flushPromises()

			expect(wrapper.find('[data-test="evidence-preview-pdf"]').exists()).toBe(true)
		})

		it('never renders an unsafe type inline: download fallback only', async () => {
			const { wrapper } = mountView(EvidencePreview, '/audit/tx-1', {
				evidence: { id: 'ev-1', name: 'run.html', mediaType: 'text/html', sizeBytes: 100, active: true }
			})
			await flushPromises()

			expect(wrapper.find('[data-test="evidence-preview-image"]').exists()).toBe(false)
			expect(wrapper.find('[data-test="evidence-preview-pdf"]').exists()).toBe(false)
			expect(wrapper.find('[data-test="download-evidence-btn"]').exists()).toBe(true)
		})

		it('notes inactive state instead of previewing withdrawn files', async () => {
			const { wrapper } = mountView(EvidencePreview, '/audit/tx-1', {
				evidence: { id: 'ev-1', name: 'old.png', mediaType: 'image/png', sizeBytes: 100, active: false }
			})
			await flushPromises()

			expect(wrapper.find('[data-test="evidence-preview-image"]').exists()).toBe(false)
			expect(wrapper.find('[data-test="download-evidence-btn"]').exists()).toBe(false)
			expect(wrapper.find('[data-test="preview-inactive-note"]').exists()).toBe(true)
		})
	})

	describe('EvidenceLinkManagement component (T098, FR-031)', () => {
		it('creates a link and discloses the URL once', async () => {
			ocsRequest.mockResolvedValueOnce(ocsEnvelope({ id: 'link-1', url: 'https://nc.example/s/TOKEN' }))
			const { wrapper } = mountView(EvidenceLinkManagement, '/audit/tx-1', {
				evidenceId: 'ev-1',
				isAdministrator: true
			})

			await wrapper.find('[data-test="create-link-btn"]').trigger('click')
			await flushPromises()

			expect(wrapper.find('[data-test="public-link-url"]').exists()).toBe(true)
			expect(wrapper.find('[data-test="public-link-url"]').text()).toContain('TOKEN')
		})

		it('deactivates a link', async () => {
			ocsRequest.mockResolvedValueOnce(ocsEnvelope({ id: 'link-1', url: 'https://nc.example/s/TOKEN' }))
			ocsRequest.mockResolvedValueOnce(ocsEnvelope({ id: 'link-1' }))
			const { wrapper } = mountView(EvidenceLinkManagement, '/audit/tx-1', {
				evidenceId: 'ev-1',
				isAdministrator: true
			})

			await wrapper.find('[data-test="create-link-btn"]').trigger('click')
			await flushPromises()
			await wrapper.find('[data-test="deactivate-link-btn"]').trigger('click')
			await flushPromises()

			expect(wrapper.find('[data-test="link-inactive"]').exists()).toBe(true)
		})

		it('is hidden from non-administrators: link administration is admin-only', () => {
			const { wrapper } = mountView(EvidenceLinkManagement, '/audit/tx-1', {
				evidenceId: 'ev-1',
				isAdministrator: false
			})

			expect(wrapper.find('[data-test="link-management"]').exists()).toBe(false)
		})
	})

	describe('AuditView (T098)', () => {
		it('shows the audit timeline for a transaction', async () => {
			const page = { items: [auditEventFixture()], page: 1, pageSize: 25, total: 1 }
			// The view loads its own session first (roles gate the link
			// management), then audit, revisions, and the transaction detail.
			ocsRequest.mockResolvedValueOnce(ocsEnvelope({ userId: 'admin', displayName: 'Admin', roles: ['ADMINISTRATOR'] }))
			ocsRequest.mockResolvedValueOnce(ocsEnvelope(page))
			ocsRequest.mockResolvedValueOnce(ocsEnvelope([]))
			ocsRequest.mockResolvedValueOnce(ocsEnvelope({ evidence: [] }))
			const { wrapper } = mountView(AuditView, '/audit/tx-1')

			await flushPromises()

			expect(wrapper.find('[data-test="audit-view"]').exists()).toBe(true)
			expect(wrapper.findAll('[data-test="audit-event"]')).toHaveLength(1)
		})
	})
})

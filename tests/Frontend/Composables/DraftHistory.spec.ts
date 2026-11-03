// 005 T069 — the draft boundary in the workspace history tab.
//
// `GET /transactions/{id}/audit` is the only history feed the API publishes, and it contains both
// official events and the legacy events written while a transaction was still a draft. The UI must
// present official history only:
//
// - a never-submitted DRAFT has no official history, so its feed is not requested at all;
// - legacy `REVISION_CREATED` / `TRANSACTION_CHANGED` events recorded at stage `DRAFT` are hidden
//   for submitted transactions as well;
// - a real review event is never hidden, whatever its stage;
// - `tracked` counts only transactions that can contribute official history.
//
// Nothing here deletes or rewrites anything: this is the read-side projection only. The audit rows
// remain in storage, which is why the assertions are about what is *rendered* and what is *fetched*.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'
import type { AuditEvent } from '@/types/api'

const auditApi = vi.hoisted(() => ({
	listTransactionAudit: vi.fn(),
	listTransactionRevisions: vi.fn()
}))

vi.mock('@/services/auditApi', () => ({
	listTransactionAudit: auditApi.listTransactionAudit,
	listTransactionRevisions: auditApi.listTransactionRevisions
}))

import { isLegacyDraftEvent, useSheetHistory } from '@/composables/useSheetHistory'

function auditEvent(overrides: Partial<AuditEvent> = {}): AuditEvent {
	return {
		id: 'evt-1',
		eventType: 'SUBMITTED',
		stage: 'COST_CENTER',
		decision: null,
		explanation: 'Submitted for review',
		actor: { userId: 'employee1', displayName: 'Employee One', role: 'PURCHASER' },
		occurredAt: '2026-09-20T10:00:00+00:00',
		...overrides
	}
}

function page(items: AuditEvent[], total = items.length) {
	return { items, page: 1, pageSize: 20, total }
}

describe('useSheetHistory — the draft boundary (T069)', () => {
	beforeEach(() => {
		auditApi.listTransactionAudit.mockReset()
		auditApi.listTransactionRevisions.mockReset()
	})

	it('never requests the audit feed of a never-submitted draft', async () => {
		auditApi.listTransactionAudit.mockResolvedValue(page([auditEvent()]))
		const history = useSheetHistory()

		history.ensureLoaded([
			{ id: 'tx-draft', label: 'Draft taxi', status: 'DRAFT' },
			{ id: 'tx-submitted', label: 'Submitted taxi', status: 'AWAITING_COST_CENTER_REVIEW' }
		])
		await flushPromises()

		expect(auditApi.listTransactionAudit).toHaveBeenCalledOnce()
		expect(auditApi.listTransactionAudit).toHaveBeenCalledWith('tx-submitted', 1, 20)
		expect(history.tracked.value).toBe(1)
	})

	it('hides legacy draft events but keeps real events at any stage', async () => {
		auditApi.listTransactionAudit.mockResolvedValue(page([
			auditEvent({ id: 'draft-revision', eventType: 'REVISION_CREATED', stage: 'DRAFT' }),
			auditEvent({ id: 'draft-change', eventType: 'TRANSACTION_CHANGED', stage: 'DRAFT' }),
			auditEvent({ id: 'draft-submitted', eventType: 'SUBMITTED', stage: 'DRAFT' }),
			auditEvent({ id: 'official-revision', eventType: 'REVISION_CREATED', stage: 'COST_CENTER' }),
			auditEvent({ id: 'official-submit', eventType: 'SUBMITTED', stage: 'COST_CENTER' })
		]))
		const history = useSheetHistory()

		history.ensureLoaded([{ id: 'tx-1', label: 'Taxi', status: 'AWAITING_COST_CENTER_REVIEW' }])
		await flushPromises()

		expect(history.merged.value.map((event) => event.id)).toEqual([
			'draft-submitted',
			'official-revision',
			'official-submit'
		])
	})

	it('classifies only the two legacy draft event types as draft working state', () => {
		expect(isLegacyDraftEvent({ eventType: 'REVISION_CREATED', stage: 'DRAFT' })).toBe(true)
		expect(isLegacyDraftEvent({ eventType: 'TRANSACTION_CHANGED', stage: 'DRAFT' })).toBe(true)
		expect(isLegacyDraftEvent({ eventType: 'REVISION_CREATED', stage: 'COST_CENTER' })).toBe(false)
		expect(isLegacyDraftEvent({ eventType: 'SUBMITTED', stage: 'DRAFT' })).toBe(false)
		expect(isLegacyDraftEvent({ eventType: 'DECISION_RECORDED', stage: 'DRAFT' })).toBe(false)
	})

	it('merges pages newest first and de-duplicates an event that appears on both', async () => {
		const older = auditEvent({ id: 'a', occurredAt: '2026-09-20T09:00:00+00:00' })
		const newer = auditEvent({ id: 'b', occurredAt: '2026-09-21T09:00:00+00:00' })
		const latest = auditEvent({ id: 'c', occurredAt: '2026-09-22T09:00:00+00:00' })
		auditApi.listTransactionAudit
			.mockResolvedValueOnce(page([older, newer], 4))
			.mockResolvedValueOnce(page([newer, latest], 4))

		const history = useSheetHistory()
		history.ensureLoaded([{ id: 'tx-1', label: 'Taxi', status: 'AWAITING_COST_CENTER_REVIEW' }])
		await flushPromises()
		history.loadMore('tx-1')
		await flushPromises()

		expect(history.merged.value.map((event) => event.id)).toEqual(['c', 'b', 'a'])
		expect(history.loaded.value).toBe(3)
		expect(history.available.value).toBe(4)
		expect(history.truncated.value).toEqual([{ transactionId: 'tx-1', label: 'Taxi', loaded: 3, total: 4 }])
	})

	it('records a feed failure against its transaction and keeps other feeds usable', async () => {
		auditApi.listTransactionAudit
			.mockRejectedValueOnce(new Error('history unavailable'))
			.mockResolvedValueOnce(page([auditEvent({ id: 'ok', occurredAt: '2026-09-20T10:00:00+00:00' })]))

		const history = useSheetHistory()
		history.ensureLoaded([
			{ id: 'tx-broken', label: 'Broken', status: 'AWAITING_COST_CENTER_REVIEW' },
			{ id: 'tx-ok', label: 'Fine', status: 'AWAITING_COST_CENTER_REVIEW' }
		])
		await flushPromises()

		expect(history.errors.value).toEqual([{ transactionId: 'tx-broken', message: 'history unavailable' }])
		expect(history.merged.value.map((event) => event.id)).toEqual(['ok'])
		expect(history.hasLoaded.value).toBe(true)
		expect(history.tracked.value).toBe(2)
	})
})

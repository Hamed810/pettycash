// Shared Transaction Review Card — Vue Component Tests (UXC-3-01 to UXC-3-04)
//
// Tests the canonical compact card: summary fields, evidence summary, the three canonical
// previous-decision indicators, title interaction, decide-bar capability gating, RTL/LTR, and
// narrow-width structure. The card is stage-agnostic; assertions use neutral fixtures only.
// The l10n double renders catalogue keys verbatim, so assertions target keys.

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { readFileSync } from 'node:fs'
import TransactionReviewCard from '@/components/review/TransactionReviewCard.vue'
import { formatSheetDate } from '@/utils/date'
import { formatMoney } from '@/utils/money'
import type {
	AllowedActions,
	Decision,
	Evidence,
	TransactionDetail,
	TransactionSummary,
} from '@/types/api'
import type { TransactionInlineDetailStageConfig } from '@/components/review/TransactionInlineDetail.vue'

// The language is a hoisted mutable so the Persian-RTL test can switch it; the direction util's
// computed is cached per module instance, so that test re-imports the card after `vi.resetModules`.
const state = vi.hoisted(() => ({ language: 'en' }))

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (match, key: string) => String(params?.[key] ?? match)),
	n: (_app: string, text: string) => text,
	getLanguage: () => state.language,
	isRTL: (language?: string) => (language ?? state.language).startsWith('fa'),
	imagePath: (path: string) => `/apps/pettycash/${path}`
}))

// The card's evidence tiles resolve real thumbnails through the evidence-content endpoint; this
// spec keeps that endpoint at the failure boundary so every tile renders its honest type-art
// fallback unless a thumbnail test arranges otherwise.
const contentMock = vi.hoisted(() => vi.fn())
vi.mock('@/services/evidenceApi', () => ({
	getEvidenceContent: contentMock,
}))

beforeEach(() => {
	contentMock.mockReset()
	contentMock.mockRejectedValue(new Error('no content unless a test provides it'))
})

function transaction(overrides: Record<string, unknown> = {}): TransactionSummary {
	return {
		id: 'tx-1',
		sheetId: 'sheet-1',
		stage: 'COST_CENTER',
		status: 'AWAITING_COST_CENTER_REVIEW',
		finalOutcome: null,
		evidenceRequired: false,
		evidenceCount: 1,
		version: 0,
		activeRevision: {
			id: 'rev-1',
			number: 1,
			expenseDate: '2026-09-20',
			amount: { amountMinor: 123456, currency: 'USD', minorUnit: 2 },
			description: 'Taxi to client site',
			costCenter: { id: 'cc-1', code: 'CC-42', name: 'Operations' },
			expenseType: { id: 'et-1', name: 'Travel' },
			createdBy: 'user1',
			createdAt: '2026-09-20T10:00:00Z',
			active: true
		},
		...overrides
	} as TransactionSummary
}

function decision(stage: Decision['stage'], kind: Decision['decision'], overrides: Partial<Decision> = {}): Decision {
	return {
		id: `decision-${stage.toLowerCase()}`,
		stage,
		decision: kind,
		explanation: 'Receipt matches the amount.',
		actor: { userId: 'manager1', displayName: 'Manager One', role: 'COST_CENTER_MANAGER' },
		decidedAt: '2026-09-21T10:30:00Z',
		...overrides
	}
}

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

type MountOpts = {
	transaction?: TransactionSummary
	stageDecisions?: Record<string, Decision | null> | null
	allowedActions?: AllowedActions | null
	busy?: boolean
	readOnly?: boolean
	showActions?: boolean
	evidence?: Evidence[]
	expandable?: boolean
	expanded?: boolean
	detail?: TransactionDetail | null
	detailReadState?: 'loading' | 'loaded' | 'forbidden' | 'notFound' | 'unavailable'
	detailStage?: TransactionInlineDetailStageConfig | null
}

function mountCard(opts: MountOpts = {}, attachTo = false) {
	return mount(TransactionReviewCard, {
		props: {
			transaction: opts.transaction ?? transaction(),
			stageDecisions: opts.stageDecisions ?? null,
			allowedActions: opts.allowedActions ?? null,
			busy: opts.busy ?? false,
			readOnly: opts.readOnly ?? false,
			showActions: opts.showActions ?? true,
			evidence: opts.evidence ?? [],
			expandable: opts.expandable ?? false,
			expanded: opts.expanded ?? false,
			detail: opts.detail !== undefined ? opts.detail : null,
			detailReadState: opts.detailReadState ?? 'loading',
			detailStage: opts.detailStage ?? null,
		},
		attachTo: attachTo ? document.body : undefined,
	})
}

/** A full detail read for the expanded region, mirroring the drawer spec's fixture. */
function detail(overrides: Record<string, unknown> = {}): TransactionDetail {
	return {
		id: 'tx-1',
		sheetId: 'sheet-1',
		stage: 'COST_CENTER',
		status: 'AWAITING_COST_CENTER_REVIEW',
		finalOutcome: null,
		evidenceRequired: false,
		evidenceCount: 1,
		version: 3,
		activeRevision: {
			id: 'rev-1',
			number: 1,
			expenseDate: '2026-09-20',
			amount: { amountMinor: 123456, currency: 'USD', minorUnit: 2 },
			description: 'Taxi to client site',
			costCenter: { id: 'cc-1', code: 'CC-42', name: 'Operations' },
			expenseType: { id: 'et-1', name: 'Travel' },
			createdBy: 'user1',
			createdAt: '2026-09-20T10:00:00Z',
			active: true
		},
		allowedActions: { canApprove: true, canReject: true },
		decisions: [decision('COST_CENTER', 'APPROVE')],
		evidence: [evidence()],
		...overrides
	} as unknown as TransactionDetail
}

const EXPAND_STAGE: TransactionInlineDetailStageConfig = {
	actionsVisible: true,
	actions: ['REJECT', 'RETURN', 'APPROVE'],
}

afterEach(() => {
	document.body.innerHTML = ''
	state.language = 'en'
})

describe('Card shell and transaction binding (UXC-3-01)', () => {
	it('binds the card to exactly its own transaction', () => {
		const wrapper = mountCard()
		expect(wrapper.find('[data-testid="transaction-review-card-tx-1"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="transaction-card-id"]').text()).toBe('tx-1')
		expect(wrapper.find('[data-testid="transaction-card-title"]').text()).toBe('Taxi to client site')
	})

	it('renders the no-description placeholder instead of fabricating a title', () => {
		const wrapper = mountCard({
			transaction: transaction({
				activeRevision: { description: '   ' }
			})
		})
		expect(wrapper.find('[data-testid="transaction-card-title"]').text()).toBe('reviews.quickReview.card.noDescription')
	})

	it('emits openDetails with the transaction id when the title is activated', async () => {
		const wrapper = mountCard()
		await wrapper.find('[data-testid="transaction-card-title"]').trigger('click')
		expect(wrapper.emitted('openDetails')).toEqual([['tx-1']])
	})

	it('emits openPreview with the evidence id from a thumbnail', async () => {
		const wrapper = mountCard({ evidence: [evidence()] })
		await wrapper.find('[data-testid="transaction-card-thumb-ev-1"]').trigger('click')
		expect(wrapper.emitted('openPreview')).toEqual([['ev-1']])
	})
})

describe('Summary fields (UXC-3-02)', () => {
	it('renders the payload-correct amount and currency', () => {
		const wrapper = mountCard()
		expect(wrapper.find('[data-testid="transaction-card-amount"]').text()).toBe(formatMoney({
			amountMinor: 123456,
			currency: 'USD',
			minorUnit: 2
		}))
		expect(wrapper.find('[data-testid="transaction-card-amount"] bdi').exists()).toBe(true)
	})

	it('renders an empty amount rather than a guessed figure when the revision carries none', () => {
		const wrapper = mountCard({
			transaction: transaction({
				activeRevision: { amount: null }
			})
		})
		expect(wrapper.find('[data-testid="transaction-card-amount"]').text()).toBe('')
	})

	it('shows the cost centre code where the revision carries one', () => {
		const wrapper = mountCard()
		expect(wrapper.find('[data-testid="transaction-card-cost-center"]').text()).toBe('CC-42')
	})

	it('shows the explicit unavailable state rather than a guessed cost centre', () => {
		const wrapper = mountCard({
			transaction: transaction({
				activeRevision: { costCenter: null }
			})
		})
		expect(wrapper.find('[data-testid="transaction-card-cost-center"]').text()).toBe('reviews.quickReview.card.unavailable')
	})

	it('renders the transaction status through the shared badge', () => {
		const wrapper = mountCard()
		expect(wrapper.find('[data-testid="status-badge-awaiting_cost_center_review"]').exists()).toBe(true)
	})

	it('binds the evidence count and thumbnails to the transaction\'s own returned evidence', () => {
		const wrapper = mountCard({
			evidence: [evidence(), evidence({ id: 'ev-2', name: 'invoice.pdf', mediaType: 'application/pdf' })]
		})
		expect(wrapper.find('[data-testid="transaction-card-evidence-count"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="transaction-card-thumb-ev-1"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="transaction-card-thumb-ev-2"]').exists()).toBe(true)
	})

	it('renders no attachments row when the transaction has no evidence', () => {
		const wrapper = mountCard({ evidence: [] })
		expect(wrapper.find('[data-testid="transaction-card-attachments"]').exists()).toBe(false)
	})
})

describe('Review progress — stage identity + state marker (UXC-V2-02, v2 baseline)', () => {
	it('renders exactly three stage markers in canonical workflow order', () => {
		const wrapper = mountCard()
		const stages = wrapper.findAll('[data-testid^="transaction-card-decision-"]:not([data-testid="transaction-card-decision-indicators"])')
		const keys = stages.map((s) => s.attributes('data-testid'))
		expect(keys).toEqual([
			'transaction-card-decision-costCenter',
			'transaction-card-decision-senior',
			'transaction-card-decision-finance',
		])
	})

	it('exposes each stage identity as visible text — stage understanding never depends on hover', () => {
		const wrapper = mountCard({
			stageDecisions: { costCenter: decision('COST_CENTER', 'APPROVE') }
		})
		const identity = wrapper.find('[data-testid="transaction-card-decision-costCenter"] .review-stage-markers__identity')
		expect(identity.exists()).toBe(true)
		expect(identity.find('.review-stage-markers__identity-label').text()).toBe('reviews.decisionStage.costCenter')
	})

	it('marks an approved decision with the ✓ state marker', () => {
		const wrapper = mountCard({
			stageDecisions: { costCenter: decision('COST_CENTER', 'APPROVE') }
		})
		const marker = wrapper.find('[data-testid="transaction-card-decision-costCenter"]')
		expect(marker.classes()).toContain('review-stage-markers__stage--approve')
		expect(marker.find('.review-stage-markers__marker').text()).toBe('✓')
	})

	it('marks a rejected decision with the ✕ state marker', () => {
		const wrapper = mountCard({
			stageDecisions: { senior: decision('SENIOR', 'REJECT') }
		})
		const marker = wrapper.find('[data-testid="transaction-card-decision-senior"]')
		expect(marker.classes()).toContain('review-stage-markers__stage--reject')
		expect(marker.find('.review-stage-markers__marker').text()).toBe('✕')
	})

	it('marks a returned decision with the ↩ state marker', () => {
		const wrapper = mountCard({
			stageDecisions: { finance: decision('FINANCE', 'RETURN') }
		})
		const marker = wrapper.find('[data-testid="transaction-card-decision-finance"]')
		expect(marker.classes()).toContain('review-stage-markers__stage--return')
		expect(marker.find('.review-stage-markers__marker').text()).toBe('↩')
	})

	it('keeps every stage without a recorded decision visible as the neutral • marker', () => {
		const wrapper = mountCard({
			stageDecisions: { costCenter: decision('COST_CENTER', 'APPROVE') }
		})
		for (const key of ['senior', 'finance']) {
			const marker = wrapper.find(`[data-testid="transaction-card-decision-${key}"]`)
			expect(marker.exists()).toBe(true)
			expect(marker.classes()).toContain('review-stage-markers__stage--none')
			expect(marker.find('.review-stage-markers__marker').text()).toBe('•')
		}
	})

	it('renders no decision yet for all stages when none were supplied', () => {
		const wrapper = mountCard({ stageDecisions: null })
		const markers = wrapper.findAll('.review-stage-markers__marker')
		expect(markers).toHaveLength(3)
		for (const marker of markers) expect(marker.text()).toBe('•')
	})

	it('no longer renders the old persistent stage/status text strip or the v3 ledger', () => {
		const wrapper = mountCard({
			stageDecisions: { costCenter: decision('COST_CENTER', 'APPROVE') }
		})
		expect(wrapper.find('.transaction-review-card__stage-name').exists()).toBe(false)
		expect(wrapper.find('.transaction-review-card__stage-state').exists()).toBe(false)
		// The superseded v3 chip/tooltip implementation is gone from the card.
		expect(wrapper.find('.transaction-review-card__stage-chip').exists()).toBe(false)
		expect(wrapper.find('.transaction-review-card__stage-tip').exists()).toBe(false)
		// The state word is not lost — it stays in the marker's visually-hidden text content.
		expect(wrapper.find('[data-testid="transaction-card-decision-costCenter"]').text())
			.toContain('reviews.outcome.approved')
	})

	it('exposes the full Stage — State label as the accessible name on every marker', () => {
		const wrapper = mountCard({
			stageDecisions: {
				costCenter: decision('COST_CENTER', 'APPROVE'),
				senior: decision('SENIOR', 'REJECT'),
				finance: decision('FINANCE', 'RETURN'),
			}
		})
		const expected = [
			['costCenter', 'reviews.decisionStage.costCenter', 'reviews.outcome.approved'],
			['senior', 'reviews.decisionStage.senior', 'reviews.outcome.rejected'],
			['finance', 'reviews.decisionStage.finance', 'reviews.quickReview.card.returned'],
		] as const
		for (const [key, stageKey, stateKey] of expected) {
			const marker = wrapper.find(`[data-testid="transaction-card-decision-${key}"]`)
			expect(marker.attributes('aria-label')).toBe(`${stageKey} — ${stateKey}`)
		}
	})

	it('names the undecided stage too — never silent, never hidden', () => {
		const wrapper = mountCard({ stageDecisions: null })
		const marker = wrapper.find('[data-testid="transaction-card-decision-finance"]')
		expect(marker.attributes('aria-label'))
			.toBe('reviews.decisionStage.finance — reviews.decisionStage.noDecision')
	})

	it('repeats the label as visually-hidden text so the words stay in the content', () => {
		const wrapper = mountCard({
			stageDecisions: { finance: decision('FINANCE', 'RETURN') }
		})
		const marker = wrapper.find('[data-testid="transaction-card-decision-finance"]')
		expect(marker.find('.review-stage-markers__sr').text())
			.toBe('reviews.decisionStage.finance — reviews.quickReview.card.returned')
	})

	it('presents markers as read-only status — not buttons, not checkboxes, not focusable', () => {
		const wrapper = mountCard({ stageDecisions: { costCenter: decision('COST_CENTER', 'APPROVE') } })
		const row = wrapper.find('[data-testid="transaction-card-decision-indicators"]')
		expect(row.element.tagName).toBe('DIV')
		for (const marker of row.findAll('[data-testid^="transaction-card-decision-"]')) {
			expect(marker.find('button').exists()).toBe(false)
			expect(marker.find('input').exists()).toBe(false)
			// v2 has no tooltip to reach, so the marker is not keyboard-focusable at all — the row
			// never injects stop points into the tab order, and meaning never depends on hover.
			expect(marker.attributes('tabindex')).toBeUndefined()
			expect(marker.attributes('role')).toBe('img')
		}
	})

	it('distinguishes all decided states by glyph shape, not colour alone', () => {
		const wrapper = mountCard({
			stageDecisions: {
				costCenter: decision('COST_CENTER', 'APPROVE'),
				senior: decision('SENIOR', 'REJECT'),
				finance: decision('FINANCE', 'RETURN'),
			}
		})
		const glyphs = wrapper.findAll('.review-stage-markers__marker').map((g) => g.text())
		expect(glyphs).toEqual(['✓', '✕', '↩'])
	})

	it('orders the marker row before the evidence summary (v2 hierarchy: progress, then evidence)', () => {
		const wrapper = mountCard({
			evidence: [evidence()],
			stageDecisions: { costCenter: decision('COST_CENTER', 'APPROVE') },
		})
		const progress = wrapper.find('[data-testid="transaction-card-decision-indicators"]')
		const docs = wrapper.find('[data-testid="transaction-card-attachments"]')
		expect(progress.exists()).toBe(true)
		expect(docs.exists()).toBe(true)
		expect(progress.element.compareDocumentPosition(docs.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
	})
})

describe('Non-interactive semantics (UXC-V2-02)', () => {
	it('keeps Enter and Space inert — no decide, open or preview action can be emitted', async () => {
		const wrapper = mountCard({
			stageDecisions: { costCenter: decision('COST_CENTER', 'APPROVE') },
			allowedActions: { canApprove: true },
		})
		for (const key of ['costCenter', 'senior', 'finance']) {
			const marker = wrapper.find(`[data-testid="transaction-card-decision-${key}"]`)
			await marker.trigger('keydown', { key: 'Enter' })
			await marker.trigger('keydown', { key: ' ' })
		}
		expect(wrapper.emitted('decide')).toBeUndefined()
		expect(wrapper.emitted('openDetails')).toBeUndefined()
		expect(wrapper.emitted('openPreview')).toBeUndefined()
		expect(wrapper.emitted('openReview')).toBeUndefined()
	})

	it('styles the Returned marker with the warning semantic, not the purple review accent', () => {
		const source = readFileSync('src/components/review/ReviewStageMarkers.vue', 'utf8')
		const returnRule = source.match(/__stage--return \.review-stage-markers__marker\s*\{[^}]+\}/s)?.[0] ?? ''
		expect(returnRule).toContain('var(--color-warning)')
		expect(returnRule).not.toContain('--pettycash-review')
		// The glyph itself is unchanged.
		const wrapper = mountCard({ stageDecisions: { finance: decision('FINANCE', 'RETURN') } })
		expect(wrapper.find('[data-testid="transaction-card-decision-finance"] .review-stage-markers__marker').text())
			.toBe('↩')
	})
})

describe('First-view transaction date (UXC-V2-03)', () => {
	it('renders the transaction date from the authoritative expense-date source without opening details', () => {
		const wrapper = mountCard()
		const date = wrapper.find('[data-testid="transaction-card-date"]')
		expect(date.exists()).toBe(true)
		// The value comes from the transaction's own `activeRevision.expenseDate`, through the shared
		// date renderer — never a revision-creation or submission timestamp.
		expect(date.text()).toContain(formatSheetDate('2026-09-20'))
	})

	it('sits directly under the title in the card body (v2 hierarchy)', () => {
		const wrapper = mountCard()
		const body = wrapper.find('.transaction-review-card__body')
		expect(body.find('[data-testid="transaction-card-title"]').exists()).toBe(true)
		expect(body.find('[data-testid="transaction-card-date"]').exists()).toBe(true)
	})

	it('renders the honest absence marker when the revision carries no expense date', () => {
		const wrapper = mountCard({
			transaction: transaction({ activeRevision: { expenseDate: null } }),
		})
		expect(wrapper.find('[data-testid="transaction-card-date"]').text()).toContain('—')
	})

	it('renders the expense type in the quiet metadata row', () => {
		const wrapper = mountCard()
		expect(wrapper.find('[data-testid="transaction-card-expense-type"]').text()).toContain('Travel')
	})

	it('shows the explicit unavailable state rather than a guessed expense type', () => {
		const wrapper = mountCard({
			transaction: transaction({ activeRevision: { expenseType: { id: '', name: '' } } }),
		})
		expect(wrapper.find('[data-testid="transaction-card-expense-type"]').text())
			.toContain('reviews.quickReview.card.unavailable')
	})
})

describe('Decide-bar capability gating (UXC-3-01)', () => {
	it('emits decide for an explicitly granted kind', async () => {
		const wrapper = mountCard({ allowedActions: { canApprove: true } })
		await wrapper.find('[data-testid="transaction-card-decide-approve"]').trigger('click')
		expect(wrapper.emitted('decide')).toEqual([['APPROVE']])
	})

	it('keeps a kind without an explicit grant disabled', () => {
		const wrapper = mountCard({ allowedActions: { canApprove: true } })
		expect(wrapper.find('[data-testid="transaction-card-decide-reject"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="transaction-card-decide-return"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="transaction-card-decide-approve"]').attributes('disabled')).toBeUndefined()
	})

	it('keeps every kind disabled without a capability set at all', () => {
		const wrapper = mountCard({ allowedActions: null })
		for (const kind of ['reject', 'return', 'approve']) {
			expect(wrapper.find(`[data-testid="transaction-card-decide-${kind}"]`).attributes('disabled')).toBeDefined()
		}
	})

	it('disables the bar while busy so a second submission cannot start', () => {
		const wrapper = mountCard({ allowedActions: { canApprove: true, canReject: true }, busy: true })
		expect(wrapper.find('[data-testid="transaction-card-decide-approve"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="transaction-card-decide-reject"]').attributes('disabled')).toBeDefined()
	})

	it('hides the decide bar for a read-only sheet (upstream decision)', () => {
		const wrapper = mountCard({ allowedActions: { canApprove: true }, readOnly: true })
		expect(wrapper.find('[data-testid="transaction-card-decide-approve"]').exists()).toBe(false)
	})

	it('hides the decide bar when the stage config withholds actions', () => {
		const wrapper = mountCard({ allowedActions: { canApprove: true }, showActions: false })
		expect(wrapper.find('[data-testid="transaction-card-decide-approve"]').exists()).toBe(false)
	})
})

describe('Accessibility, RTL and narrow width (UXC-3-03/04)', () => {
	it('makes the title a native button so keyboard activation is native', async () => {
		const wrapper = mountCard({}, true)
		const title = wrapper.find('[data-testid="transaction-card-title"]')
		expect(title.element.tagName).toBe('BUTTON')
		;(title.element as HTMLElement).focus()
		expect(document.activeElement).toBe(title.element)
		// The activation contract: the native control's click behaviour emits the drawer entry.
		await title.trigger('click')
		expect(wrapper.emitted('openDetails')).toEqual([['tx-1']])
		wrapper.unmount()
	})

	it('labels each evidence thumbnail for assistive technology', () => {
		const wrapper = mountCard({ evidence: [evidence()] })
		const thumb = wrapper.find('[data-testid="transaction-card-thumb-ev-1"]')
		expect(thumb.attributes('aria-label')).toBe('reviews.quickReview.card.opensPreviewNamed')
	})

	it('binds dir from the shared direction utility', () => {
		const wrapper = mountCard()
		expect(wrapper.find('[data-testid="transaction-review-card-tx-1"]').attributes('dir')).toBe('ltr')
	})

	it('renders identifiers direction-safe inside bdi', () => {
		const wrapper = mountCard()
		const id = wrapper.find('[data-testid="transaction-card-id"] bdi')
		expect(id.element.tagName).toBe('BDI')
		expect(id.attributes('dir')).toBe('ltr')
	})

	it('keeps the card structure intact at the 390px floor', async () => {
		const wrapper = mountCard({
			evidence: [evidence()],
			stageDecisions: { costCenter: decision('COST_CENTER', 'APPROVE') },
		})
		await nextTick()
		expect(wrapper.find('[data-testid="transaction-card-title"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="transaction-card-amount"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="transaction-card-date"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="transaction-card-decision-indicators"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="transaction-card-thumb-ev-1"]').exists()).toBe(true)
		// The v2 marker row: three stage markers, and the old chip/strip markup is gone.
		expect(wrapper.findAll('.review-stage-markers__marker')).toHaveLength(3)
		expect(wrapper.find('.transaction-review-card__stage-chip').exists()).toBe(false)
		expect(wrapper.find('.transaction-review-card__stage-name').exists()).toBe(false)
		// The marker is the compact ~22px pill the v2 baseline pins; jsdom has no layout, so the size
		// is checked at source level like the other structural CSS assertions in this repository.
		const source = readFileSync('src/components/review/ReviewStageMarkers.vue', 'utf8')
		expect(source).toMatch(/__marker\s*\{[^}]*inline-size:\s*22px/s)
		expect(source).not.toMatch(/__marker\s*\{[^}]*inline-size:\s*3\d\dpx/s)
	})

	it('keeps the three stage markers intact and named under Persian RTL', async () => {
		// The direction computed is cached per module instance, so a genuine RTL mount needs fresh
		// module copies; the l10n double stays registered across `vi.resetModules()`.
		state.language = 'fa'
		vi.resetModules()
		const { default: RtlCard } = await import('@/components/review/TransactionReviewCard.vue')
		const wrapper = mount(RtlCard, {
			props: {
				transaction: transaction(),
				stageDecisions: {
					costCenter: decision('COST_CENTER', 'APPROVE'),
					senior: decision('SENIOR', 'REJECT'),
					finance: decision('FINANCE', 'RETURN'),
				},
			},
		})
		expect(wrapper.find('[data-testid="transaction-review-card-tx-1"]').attributes('dir')).toBe('rtl')
		const keys = wrapper.findAll('[data-testid^="transaction-card-decision-"]:not([data-testid="transaction-card-decision-indicators"])')
			.map((s) => s.attributes('data-testid'))
		expect(keys).toEqual([
			'transaction-card-decision-costCenter',
			'transaction-card-decision-senior',
			'transaction-card-decision-finance',
		])
		const glyphs = wrapper.findAll('.review-stage-markers__marker').map((g) => g.text())
		expect(glyphs).toEqual(['✓', '✕', '↩'])
		// The accessible name travels with the stage regardless of direction.
		expect(wrapper.find('[data-testid="transaction-card-decision-senior"]').attributes('aria-label'))
			.toBe('reviews.decisionStage.senior — reviews.outcome.rejected')
		// The visible stage identity survives RTL as plain text — no hover, no direction-locked words.
		expect(wrapper.find('[data-testid="transaction-card-decision-senior"] .review-stage-markers__identity-label').text())
			.toBe('reviews.decisionStage.senior')
		wrapper.unmount()
	})
})

describe('Stage neutrality (UXC-3-01 exclusions)', () => {
	it('contains no stage-specific business logic in the shared card', () => {
		const source = readFileSync('src/components/review/TransactionReviewCard.vue', 'utf8')
		expect(source).not.toMatch(/SeniorSheetModal|FinanceSheetModal|CostCenterQueue|SeniorQueueView|allowedActions\s*===|SENIOR_REJECTED/)
	})
})

describe('Inline expansion disclosure (UXC-V2-04)', () => {
	it('keeps the title a plain drawer-entry control when the caller wires no expansion', () => {
		const wrapper = mountCard()
		const title = wrapper.find('[data-testid="transaction-card-title"]')
		expect(title.attributes('aria-expanded')).toBeUndefined()
		expect(title.attributes('aria-controls')).toBeUndefined()
		expect(wrapper.find('[data-testid="transaction-expanded-detail"]').exists()).toBe(false)
	})

	it('exposes aria-expanded="false" on the toggle while collapsed', () => {
		const wrapper = mountCard({ expandable: true })
		const title = wrapper.find('[data-testid="transaction-card-title"]')
		expect(title.attributes('aria-expanded')).toBe('false')
		expect(title.attributes('aria-controls')).toBeUndefined()
	})

	it('toggles to aria-expanded="true" with aria-controls and renders the region in place', async () => {
		const wrapper = mountCard({ expandable: true, expanded: true, detail: detail(), detailReadState: 'loaded' })
		await nextTick()
		const title = wrapper.find('[data-testid="transaction-card-title"]')
		expect(title.attributes('aria-expanded')).toBe('true')
		expect(title.attributes('aria-controls')).toBe('transaction-expanded-tx-1')
		const region = wrapper.find('[data-testid="transaction-expanded-detail"]')
		expect(region.exists()).toBe(true)
		// The region is the card's own extension — inside the card article, no dialog of its own.
		expect(region.attributes('role')).toBe('region')
		expect(region.element.closest('article')).not.toBeNull()
		wrapper.unmount()
	})

	it('emits toggleExpand from title activation instead of openDetails when expandable', async () => {
		const wrapper = mountCard({ expandable: true })
		await wrapper.find('[data-testid="transaction-card-title"]').trigger('click')
		expect(wrapper.emitted('toggleExpand')).toEqual([['tx-1']])
		expect(wrapper.emitted('openDetails')).toBeUndefined()
	})

	it('moves focus into the expanded region on expand', async () => {
		const wrapper = mountCard({ expandable: true, detail: detail(), detailReadState: 'loaded' }, true)
		await wrapper.setProps({ expanded: true })
		await nextTick()
		await nextTick()
		const region = wrapper.find('[data-testid="transaction-expanded-detail"]')
		expect(document.activeElement).toBe(region.element)
		wrapper.unmount()
	})

	it('aligns a tall expansion with the start of its scroll container instead of its bottom', async () => {
		const host = document.createElement('div')
		host.style.overflowY = 'auto'
		Object.defineProperty(host, 'clientHeight', { configurable: true, value: 400 })
		Object.defineProperty(host, 'scrollHeight', { configurable: true, value: 1200 })
		document.body.appendChild(host)

		const containerRect = { top: 100, bottom: 500, height: 400, left: 0, right: 300, width: 300, x: 0, y: 100, toJSON: () => ({}) } as DOMRect
		const regionRect = { top: 600, bottom: 1600, height: 1000, left: 0, right: 300, width: 300, x: 0, y: 600, toJSON: () => ({}) } as DOMRect
		vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
			if (this === host) return containerRect
			if (this.classList.contains('transaction-review-card__expanded')) return regionRect
			return { top: 0, bottom: 0, height: 0, left: 0, right: 0, width: 0, x: 0, y: 0, toJSON: () => ({}) } as DOMRect
		})

		const wrapper = mount(TransactionReviewCard, {
			attachTo: host,
			props: {
				transaction: transaction(),
				expandable: true,
				expanded: false,
				detail: detail(),
				detailReadState: 'loaded',
			},
		})
		await wrapper.setProps({ expanded: true })
		await nextTick()
		await nextTick()

		// The expansion begins 500px below the container's top (600 - 100), not at its bottom.
		expect(host.scrollTop).toBe(500)
		wrapper.unmount()
		host.remove()
	})

	it('returns focus to the toggle on collapse', async () => {
		const wrapper = mountCard({ expandable: true, detail: detail(), detailReadState: 'loaded' }, true)
		await wrapper.setProps({ expanded: true })
		await nextTick()
		await nextTick()
		// Precondition: the expansion holds focus (the expand lifecycle ran).
		expect(document.activeElement).toBe(wrapper.find('[data-testid="transaction-expanded-detail"]').element)
		const title = wrapper.find('[data-testid="transaction-card-title"]')
		await wrapper.setProps({ expanded: false })
		await nextTick()
		await nextTick()
		expect(wrapper.find('[data-testid="transaction-expanded-detail"]').exists()).toBe(false)
		expect(document.activeElement).toBe(title.element)
		wrapper.unmount()
	})

	it('does not steal focus on collapse when focus already left the region', async () => {
		const wrapper = mountCard({ expandable: true, expanded: true, detail: detail(), detailReadState: 'loaded' }, true)
		await nextTick()
		await nextTick()
		// The sibling-card case: focus sits elsewhere (e.g. the other card's toggle) when this
		// region collapses — this card must not yank the caret back to its own toggle.
		const elsewhere = document.createElement('button')
		document.body.appendChild(elsewhere)
		elsewhere.focus()
		await wrapper.setProps({ expanded: false })
		await nextTick()
		await nextTick()
		expect(document.activeElement).toBe(elsewhere)
		elsewhere.remove()
		wrapper.unmount()
	})

	it('collapses on Escape inside the expanded card and keeps the event from the sheet modal', async () => {
		const wrapper = mountCard({ expandable: true, expanded: true, detail: detail(), detailReadState: 'loaded' }, true)
		await nextTick()
		const parentSpy = vi.fn()
		document.body.addEventListener('keydown', parentSpy)
		const region = wrapper.find('[data-testid="transaction-expanded-detail"]')
		region.element.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		await nextTick()
		expect(wrapper.emitted('toggleExpand')).toEqual([['tx-1']])
		// One Escape closes exactly one layer: the sheet modal's Escape handler never sees it.
		expect(parentSpy).not.toHaveBeenCalled()
		document.body.removeEventListener('keydown', parentSpy)
		wrapper.unmount()
	})

	it('lets Escape bubble when no expansion is open — the sheet modal closes as before', async () => {
		const wrapper = mountCard({}, true)
		const parentSpy = vi.fn()
		document.body.addEventListener('keydown', parentSpy)
		wrapper.find('[data-testid="transaction-review-card-tx-1"]').element
			.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		await nextTick()
		expect(wrapper.emitted('toggleExpand')).toBeUndefined()
		expect(parentSpy).toHaveBeenCalledTimes(1)
		document.body.removeEventListener('keydown', parentSpy)
		wrapper.unmount()
	})

	it('renders the detail read states inside the expansion instead of fabricating content', async () => {
		const loading = mountCard({ expandable: true, expanded: true, detail: null, detailReadState: 'loading' })
		expect(loading.find('[data-testid="inline-loading"]').exists()).toBe(true)
		loading.unmount()

		const forbidden = mountCard({ expandable: true, expanded: true, detail: null, detailReadState: 'forbidden' })
		expect(forbidden.find('[data-testid="inline-forbidden"]').exists()).toBe(true)
		forbidden.unmount()

		const notFound = mountCard({ expandable: true, expanded: true, detail: null, detailReadState: 'notFound' })
		expect(notFound.find('[data-testid="inline-not-found"]').exists()).toBe(true)
		notFound.unmount()

		const unavailable = mountCard({ expandable: true, expanded: true, detail: null, detailReadState: 'unavailable' })
		expect(unavailable.find('[data-testid="inline-unavailable"]').exists()).toBe(true)
		unavailable.unmount()
	})

	it('carries the complementary drawer detail fields inside the expansion (card-owned fields are not repeated)', async () => {
		const wrapper = mountCard({ expandable: true, expanded: true, detail: detail(), detailReadState: 'loaded' })
		expect(wrapper.find('[data-testid="inline-field-sheet"]').text()).toContain('sheet-1')
		expect(wrapper.find('[data-testid="inline-field-purchaser"]').text()).toBe('reviews.sheetQueue.purchaserUnavailable')
		expect(wrapper.find('[data-testid="inline-field-submitted"]').text()).toBe('reviews.sheetQueue.submittedUnavailable')
		expect(wrapper.find('[data-testid="inline-description"]').text()).toBe('Taxi to client site')
		wrapper.unmount()
	})

	it('surfaces the expanded decide row through the card\'s decide contract', async () => {
		const wrapper = mountCard({
			expandable: true,
			expanded: true,
			detail: detail(),
			detailReadState: 'loaded',
			detailStage: EXPAND_STAGE,
		})
		await wrapper.find('[data-testid="inline-decide-approve"]').trigger('click')
		expect(wrapper.emitted('decide')).toEqual([['APPROVE']])
		wrapper.unmount()
	})

	it('surfaces an expanded attachment row through the card\'s openPreview contract, transaction-bound', async () => {
		const item = evidence()
		const wrapper = mountCard({
			expandable: true,
			expanded: true,
			detail: detail({ evidence: [item] }),
			detailReadState: 'loaded',
		})
		await wrapper.find('[data-testid="inline-attachment-ev-1"]').trigger('click')
		expect(wrapper.emitted('openPreview')).toEqual([['ev-1']])
		wrapper.unmount()
	})

	it('keeps the region out of the DOM entirely when not expanded', () => {
		const wrapper = mountCard({ expandable: true })
		expect(wrapper.find('[data-testid="transaction-expanded-detail"]').exists()).toBe(false)
	})
})

describe('Evidence summary thumbnails (UXC-V2-06)', () => {
	it('renders a real image thumbnail from the fetched content for an active inline-safe image', async () => {
		const createObjectURL = vi.fn(() => 'blob:thumb-1')
		vi.stubGlobal('URL', Object.assign(URL, { createObjectURL, revokeObjectURL: vi.fn() }))
		contentMock.mockResolvedValue(new Blob(['bytes'], { type: 'image/png' }))
		const wrapper = mountCard({ evidence: [evidence()] })
		await vi.waitFor(() => expect(wrapper.find('[data-testid="transaction-card-thumb-ev-1"] [data-testid="evidence-tile-image"]').exists()).toBe(true))
		expect(contentMock).toHaveBeenCalledWith('ev-1', 'inline')
		expect(wrapper.find('[data-testid="transaction-card-thumb-ev-1"]').attributes('data-thumb-state')).toBe('thumb')
		vi.unstubAllGlobals()
		wrapper.unmount()
	})

	it('keeps the honest type-art tile when the thumbnail fetch fails', async () => {
		const wrapper = mountCard({ evidence: [evidence()] })
		await vi.waitFor(() => expect(contentMock).toHaveBeenCalled())
		await Promise.resolve()
		expect(wrapper.find('[data-testid="transaction-card-thumb-ev-1"] [data-testid="evidence-tile-image"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="transaction-card-thumb-ev-1"]').attributes('data-thumb-state')).toBe('fallback')
		wrapper.unmount()
	})

	it('never fetches content for an inactive evidence item', async () => {
		const wrapper = mountCard({ evidence: [evidence({ active: false })] })
		await Promise.resolve()
		expect(contentMock).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="transaction-card-thumb-ev-1"] [data-testid="evidence-tile-image"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('keeps the PDF tile as type art with no content fetch', async () => {
		const wrapper = mountCard({ evidence: [evidence({ id: 'ev-2', name: 'invoice.pdf', mediaType: 'application/pdf' })] })
		await Promise.resolve()
		expect(contentMock).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="transaction-card-thumb-ev-2"]').attributes('data-thumb-state')).toBe('fallback')
		wrapper.unmount()
	})

	it('shows at most two thumbnails plus the +N tile for additional evidence', () => {
		const wrapper = mountCard({
			evidence: [
				evidence(),
				evidence({ id: 'ev-2', name: 'invoice.pdf', mediaType: 'application/pdf' }),
				evidence({ id: 'ev-3', name: 'third.png' }),
			]
		})
		expect(wrapper.find('[data-testid="transaction-card-thumb-ev-1"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="transaction-card-thumb-ev-2"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="transaction-card-thumb-ev-3"]').exists()).toBe(false)
		expect(wrapper.find(`[data-testid="transaction-card-thumb-more-${'tx-1'}"]`).exists()).toBe(true)
		wrapper.unmount()
	})
})

describe('Reviewer action hierarchy (UXC-V2-07)', () => {
	it('keeps Approve the only filled/primary action in the decide bar', () => {
		const wrapper = mountCard({ allowedActions: { canApprove: true, canReject: true, canReturn: true } })
		const approve = wrapper.find('[data-testid="transaction-card-decide-approve"]')
		const reject = wrapper.find('[data-testid="transaction-card-decide-reject"]')
		const back = wrapper.find('[data-testid="transaction-card-decide-return"]')
		expect(approve.classes()).toContain('button-vue--primary')
		expect(reject.classes()).toContain('button-vue--error')
		expect(back.classes()).toContain('button-vue--warning')
		// The quiet outline treatment is applied to the non-primary actions only.
		const source = readFileSync('src/components/review/ReviewDecideActions.vue', 'utf8')
		expect(source).toMatch(/__group :deep\(\.button-vue--warning\),[\s\S]*?__group :deep\(\.button-vue--error\)/)
		expect(source).toMatch(/background-color:\s*transparent/)
	})

	it('keeps all three granted actions visible on the collapsed card without expansion', () => {
		const wrapper = mountCard({ allowedActions: { canApprove: true, canReject: true, canReturn: true } })
		for (const kind of ['approve', 'reject', 'return']) {
			const button = wrapper.find(`[data-testid="transaction-card-decide-${kind}"]`)
			expect(button.exists()).toBe(true)
			expect(button.attributes('disabled')).toBeUndefined()
		}
		expect(wrapper.find('[data-testid="transaction-expanded-detail"]').exists()).toBe(false)
	})

	it('emits decide from the collapsed bar into the same confirmation flow as the expansion', async () => {
		const wrapper = mountCard({ allowedActions: { canApprove: true } })
		await wrapper.find('[data-testid="transaction-card-decide-approve"]').trigger('click')
		expect(wrapper.emitted('decide')).toEqual([['APPROVE']])
	})
})

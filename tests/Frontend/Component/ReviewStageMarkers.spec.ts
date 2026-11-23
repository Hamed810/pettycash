// Shared ReviewStageMarkers — compact stage identity + state marker row (UXC-V2-02).
//
// The owner-approved v2 baseline replaces both the icon-only tooltip chips (UXC-FU-01) and the
// superseded v3 Review Ledger with one compact read-only presentation: per workflow stage a
// restrained identity (icon + label, visible without hover) and a separate small semantic state
// marker. The l10n double renders catalogue keys verbatim, so assertions target keys.

import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import ReviewStageMarkers from '@/components/review/ReviewStageMarkers.vue'
import type { Decision } from '@/types/api'

// The language is a hoisted mutable so the Persian-RTL test can switch it; the direction util's
// computed is cached per module instance, so that test re-imports the component after
// `vi.resetModules` — the same pattern the card spec uses.
const state = vi.hoisted(() => ({ language: 'en' }))

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (match, key: string) => String(params?.[key] ?? match)),
	n: (_app: string, text: string) => text,
	getLanguage: () => state.language,
	isRTL: (language?: string) => (language ?? state.language).startsWith('fa'),
	imagePath: (path: string) => `/apps/pettycash/${path}`,
}))

function decision(stage: Decision['stage'], kind: Decision['decision'], overrides: Partial<Decision> = {}): Decision {
	return {
		id: `decision-${stage.toLowerCase()}`,
		stage,
		decision: kind,
		explanation: 'Receipt matches the amount.',
		actor: { userId: 'manager1', displayName: 'Manager One', role: 'COST_CENTER_MANAGER' },
		decidedAt: '2026-09-21T10:30:00Z',
		...overrides,
	}
}

function mountMarkers(props: Record<string, unknown> = {}) {
	return mount(ReviewStageMarkers, { props })
}

afterEach(() => {
	state.language = 'en'
})

describe('Stage identity + state marker row (UXC-V2-02)', () => {
	it('renders exactly the three canonical stages in fixed semantic workflow order', () => {
		const wrapper = mountMarkers()
		const keys = wrapper.findAll('[data-testid^="review-stage-marker-"]').map((s) => s.attributes('data-testid'))
		expect(keys).toEqual([
			'review-stage-marker-costCenter',
			'review-stage-marker-senior',
			'review-stage-marker-finance',
		])
	})

	it('exposes stage identity as visible text beside a restrained icon — no hover required', () => {
		const wrapper = mountMarkers()
		const identity = wrapper.find('[data-testid="review-stage-marker-costCenter"] .review-stage-markers__identity')
		expect(identity.exists()).toBe(true)
		// The label is plain text in the DOM (the double prints catalogue keys), not a tooltip.
		expect(identity.find('.review-stage-markers__identity-label').text()).toBe('reviews.decisionStage.costCenter')
		expect(identity.find('svg').exists()).toBe(true)
	})

	it('marks Approved with the ✓ glyph and the refined green semantic class', () => {
		const wrapper = mountMarkers({ decisions: { costCenter: decision('COST_CENTER', 'APPROVE') } })
		const marker = wrapper.find('[data-testid="review-stage-marker-costCenter"]')
		expect(marker.classes()).toContain('review-stage-markers__stage--approve')
		expect(marker.find('.review-stage-markers__marker').text()).toBe('✓')
	})

	it('marks Rejected with the ✕ glyph and the restrained red semantic class', () => {
		const wrapper = mountMarkers({ decisions: { senior: decision('SENIOR', 'REJECT') } })
		const marker = wrapper.find('[data-testid="review-stage-marker-senior"]')
		expect(marker.classes()).toContain('review-stage-markers__stage--reject')
		expect(marker.find('.review-stage-markers__marker').text()).toBe('✕')
	})

	it('marks Returned with the ↩ glyph and the amber warning semantic class', () => {
		const wrapper = mountMarkers({ decisions: { finance: decision('FINANCE', 'RETURN') } })
		const marker = wrapper.find('[data-testid="review-stage-marker-finance"]')
		expect(marker.classes()).toContain('review-stage-markers__stage--return')
		expect(marker.find('.review-stage-markers__marker').text()).toBe('↩')
	})

	it('renders No decision yet as the neutral • marker — an undecided stage is never hidden', () => {
		const wrapper = mountMarkers({ decisions: { costCenter: decision('COST_CENTER', 'APPROVE') } })
		for (const key of ['senior', 'finance']) {
			const marker = wrapper.find(`[data-testid="review-stage-marker-${key}"]`)
			expect(marker.exists()).toBe(true)
			expect(marker.classes()).toContain('review-stage-markers__stage--none')
			expect(marker.find('.review-stage-markers__marker').text()).toBe('•')
		}
	})

	it('renders every stage as undecided when no decisions were supplied', () => {
		const wrapper = mountMarkers()
		const markers = wrapper.findAll('.review-stage-markers__marker')
		expect(markers).toHaveLength(3)
		for (const marker of markers) expect(marker.text()).toBe('•')
	})

	it('composes the full Stage — State label as the accessible name on every marker', () => {
		const wrapper = mountMarkers({
			decisions: {
				costCenter: decision('COST_CENTER', 'APPROVE'),
				senior: decision('SENIOR', 'REJECT'),
				finance: decision('FINANCE', 'RETURN'),
			},
		})
		const expected = [
			['costCenter', 'reviews.decisionStage.costCenter', 'reviews.outcome.approved'],
			['senior', 'reviews.decisionStage.senior', 'reviews.outcome.rejected'],
			['finance', 'reviews.decisionStage.finance', 'reviews.quickReview.card.returned'],
		] as const
		for (const [key, stageKey, stateKey] of expected) {
			expect(wrapper.find(`[data-testid="review-stage-marker-${key}"]`).attributes('aria-label'))
				.toBe(`${stageKey} — ${stateKey}`)
		}
	})

	it('names the undecided stage too — never silent, never hidden', () => {
		const wrapper = mountMarkers()
		expect(wrapper.find('[data-testid="review-stage-marker-finance"]').attributes('aria-label'))
			.toBe('reviews.decisionStage.finance — reviews.decisionStage.noDecision')
	})

	it('repeats the label as visually-hidden text so the words stay in the content', () => {
		const wrapper = mountMarkers({ decisions: { finance: decision('FINANCE', 'RETURN') } })
		expect(wrapper.find('[data-testid="review-stage-marker-finance"] .review-stage-markers__sr').text())
			.toBe('reviews.decisionStage.finance — reviews.quickReview.card.returned')
	})

	it('is informational and read-only: no buttons, no inputs, no action semantics, not focusable', () => {
		const wrapper = mountMarkers({ decisions: { costCenter: decision('COST_CENTER', 'APPROVE') } })
		const row = wrapper.find('[data-testid="review-stage-markers"]')
		expect(row.element.tagName).toBe('DIV')
		for (const marker of row.findAll('[data-testid^="review-stage-marker-"]')) {
			expect(marker.find('button').exists()).toBe(false)
			expect(marker.find('input').exists()).toBe(false)
			expect(marker.find('a').exists()).toBe(false)
			// v2 has no tooltip to reach, so the marker is not keyboard-focusable at all — meaning
			// never depends on hover, and the row never injects stop points into tab order.
			expect(marker.attributes('tabindex')).toBeUndefined()
			expect(marker.attributes('role')).toBe('img')
		}
	})

	it('distinguishes all decided states by glyph shape, not colour alone', () => {
		const wrapper = mountMarkers({
			decisions: {
				costCenter: decision('COST_CENTER', 'APPROVE'),
				senior: decision('SENIOR', 'REJECT'),
				finance: decision('FINANCE', 'RETURN'),
			},
		})
		const glyphs = wrapper.findAll('.review-stage-markers__marker').map((g) => g.text())
		expect(glyphs).toEqual(['✓', '✕', '↩'])
	})

	it('honours a caller-supplied testid prefix so existing selector contracts keep working', () => {
		const wrapper = mountMarkers({ testIdPrefix: 'transaction-card-decision' })
		expect(wrapper.find('[data-testid="transaction-card-decision-costCenter"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="transaction-card-decision-senior"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="transaction-card-decision-finance"]').exists()).toBe(true)
	})

	it('binds dir from the shared direction utility (LTR in this environment)', () => {
		const wrapper = mountMarkers()
		expect(wrapper.find('[data-testid="review-stage-markers"]').attributes('dir')).toBe('ltr')
	})
})

describe('Marker presentation constraints (source-level, jsdom has no layout)', () => {
	it('keeps the identity label on the enforced typography floor', () => {
		const source = readFileSync('src/components/review/ReviewStageMarkers.vue', 'utf8')
		const labelRule = source.match(/__identity-label\s*\{[^}]+\}/s)?.[0] ?? ''
		expect(labelRule).toContain('var(--font-size-floor)')
	})

	it('stays a compact wrapping row — no large equal-height stage panels, no ledger', () => {
		const source = readFileSync('src/components/review/ReviewStageMarkers.vue', 'utf8')
		const rowBlock = source.match(/\.review-stage-markers\s*\{[^}]+\}/s)?.[0] ?? ''
		expect(rowBlock).toContain('flex-wrap: wrap')
		const markerRule = source.match(/__marker\s*\{[^}]+\}/s)?.[0] ?? ''
		expect(markerRule).toContain('inline-size: 22px')
		expect(source).not.toMatch(/grid-template/)
	})

	it('styles the Returned marker with the warning semantic, not the purple review accent', () => {
		const source = readFileSync('src/components/review/ReviewStageMarkers.vue', 'utf8')
		const returnRule = source.match(/__stage--return \.review-stage-markers__marker\s*\{[^}]+\}/s)?.[0] ?? ''
		expect(returnRule).toContain('var(--color-warning)')
		expect(returnRule).not.toContain('--pettycash-review')
	})
})

describe('Persian RTL', () => {
	it('keeps the three stages, their order, glyphs and accessible names under RTL', async () => {
		state.language = 'fa'
		vi.resetModules()
		const { default: RtlMarkers } = await import('@/components/review/ReviewStageMarkers.vue')
		const wrapper = mount(RtlMarkers, {
			props: {
				decisions: {
					costCenter: decision('COST_CENTER', 'APPROVE'),
					senior: decision('SENIOR', 'REJECT'),
					finance: decision('FINANCE', 'RETURN'),
				},
			},
		})
		expect(wrapper.find('[data-testid="review-stage-markers"]').attributes('dir')).toBe('rtl')
		const keys = wrapper.findAll('[data-testid^="review-stage-marker-"]').map((s) => s.attributes('data-testid'))
		expect(keys).toEqual([
			'review-stage-marker-costCenter',
			'review-stage-marker-senior',
			'review-stage-marker-finance',
		])
		const glyphs = wrapper.findAll('.review-stage-markers__marker').map((g) => g.text())
		expect(glyphs).toEqual(['✓', '✕', '↩'])
		expect(wrapper.find('[data-testid="review-stage-marker-senior"]').attributes('aria-label'))
			.toBe('reviews.decisionStage.senior — reviews.outcome.rejected')
		// The visible identity label survives RTL as plain text — no hover, no direction-locked words.
		expect(wrapper.find('[data-testid="review-stage-marker-senior"] .review-stage-markers__identity-label').text())
			.toBe('reviews.decisionStage.senior')
		wrapper.unmount()
	})
})

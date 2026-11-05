// T036/T037 — mobile, RTL and accessibility conformance for the Batch 4 quick-review surfaces.
//
// jsdom has no layout engine and no font metrics: it cannot measure an overflow, a hit target or a
// contrast ratio. What a source scan *can* falsify is the declarations that make the requirement
// achievable, in the same sense `ManagementReview.spec.ts` already pins the queue's 390px
// breakpoint. This file pins the half of each requirement the components declare in their own
// scoped styles:
//
// | Requirement | Checked here |
// |---|---|---|
// | full-screen mobile overlays that cannot shave a hit target | each overlay declares a `width <= 640px` (or `max-width: 600px`) rule covering 390px |
// | `≥44px` touch targets | drawer tabs/attachments declare `min-block-size: 44px`; card thumbnails declare ≥44px on both axes; card/modal buttons stretch full width on mobile so they never fall below the design system's 44px default |
// | reduced motion | the animated surfaces (drawer, modal) declare `prefers-reduced-motion: reduce` suppression |
// | RTL mirroring | the drawer mirrors in RTL via `[dir='rtl']` rather than physical `left/right` |
//
// The runtime behaviours (focus return, single overlay, scroll-to-panel, honesty) live in the
// component/workflow specs and the browser captures; this file intentionally does not re-assert them.

import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

function source(relative: string): string {
	return readFileSync(join(process.cwd(), relative), 'utf8')
}

/** A declared breakpoint (range syntax or legacy max-width) that covers a 390px viewport. */
function hasMobileBreakpoint(css: string): boolean {
	const widths = [
		...[...css.matchAll(/width\s*<=\s*(\d+)px/g)].map((m) => Number(m[1])),
		...[...css.matchAll(/max-width:\s*(\d+)px/g)].map((m) => Number(m[1]))
	]
	return widths.length > 0 && Math.max(...widths) >= 390
}

describe('Quick-review accessibility conformance (T036)', () => {
	const drawer = source('src/components/review/TransactionDetailsDrawer.vue')
	const card = source('src/components/review/QuickReviewCard.vue')
	const modal = source('src/components/review/QuickDecisionModal.vue')
	const preview = source('src/components/evidence/EvidencePreview.vue')

	it('makes every Batch 4 overlay full-screen within a mobile breakpoint that covers 390px', () => {
		expect(hasMobileBreakpoint(drawer), 'drawer must declare a mobile full-screen breakpoint').toBe(true)
		expect(hasMobileBreakpoint(modal), 'decision modal must declare a mobile full-screen breakpoint').toBe(true)
		expect(hasMobileBreakpoint(preview), 'evidence preview must declare a mobile full-screen breakpoint').toBe(true)
		expect(hasMobileBreakpoint(card), 'card action row must declare a mobile breakpoint').toBe(true)
	})

	it('keeps the interactive drawer controls on ≥44px touch targets', () => {
		const minSizes = [...drawer.matchAll(/min-block-size:\s*(\d+)px/g)].map((m) => Number(m[1]))
		expect(minSizes, 'drawer tabs and attachment rows must declare a 44px minimum').toContain(44)
	})

	it('keeps the card thumbnails on ≥44px touch targets on both axes', () => {
		const thumb = card.match(/quick-review-card__thumb\s*\{([^}]+)\}/s)
		expect(thumb, 'thumbnail rule must exist').not.toBeNull()
		const sizes = [...(thumb?.[1] ?? '').matchAll(/(?:inline-size|block-size):\s*(\d+)px/g)].map((m) => Number(m[1]))
		expect(sizes).toContain(74)
		expect(sizes).toContain(54)
	})

	it('stretches card and modal buttons full width on mobile so they cannot shrink below the 44px default', () => {
		expect(card).toMatch(/quick-review-card__actions\s*:deep\(\.button-vue\)\s*\{\s*inline-size:\s*100%;/)
		expect(modal).toMatch(/decision-modal__footer-group\s*:deep\(\.button-vue\)\s*\{\s*inline-size:\s*100%;/)
	})

	it('suppresses animation under reduced motion on the animated surfaces', () => {
		expect(drawer).toContain('@media (prefers-reduced-motion: reduce)')
		expect(modal).toContain('@media (prefers-reduced-motion: reduce)')
	})

	it('mirrors the drawer in RTL with logical layout, not physical left/right', () => {
		// The RTL mirror is `[dir='rtl']` over flex/logical properties — never a physical
		// `left`/`right` override that would fight the direction.
		expect(drawer).toMatch(/\[dir='rtl'\]/)
		expect(drawer).not.toMatch(/\[dir='rtl'\]\s*\{[^}]*\bleft:/)
		expect(drawer).not.toMatch(/\[dir='rtl'\]\s*\{[^}]*\bright:/)
	})
})

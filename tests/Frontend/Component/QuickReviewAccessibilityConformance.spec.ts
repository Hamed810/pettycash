// T036/T037 — mobile, RTL and accessibility conformance for the Batch 4 quick-review surfaces.
//
// jsdom has no layout engine and no font metrics: it cannot measure an overflow, a hit target or a
// contrast ratio. What a source scan *can* falsify is the declarations that make the requirement
// achievable, in the same sense `ManagementReview.spec.ts` already pins the queue's 390px
// breakpoint. This file pins the half of each requirement the components declare in their own
// scoped styles:
//
// | Requirement | Checked here |
// |---|---|
// | mobile answers that cannot shave a hit target | each surface declares a `width <= 640px` (or `max-width: 600px`) rule covering 390px |
// | `≥44px` touch targets | the interactive controls draw this from their own declared floor — inline attachment rows `min-block-size: 44px`; card/modal/decide/preview button rows and the queue tabs/search declare `min-block-size: 44px` inside their mobile block; card thumbnails declare ≥44px on both axes |
// | reduced motion | the animated surfaces (card, inline detail, decision modal) declare `prefers-reduced-motion: reduce` suppression |
// | RTL mirroring | the inline expansion mirrors in RTL through logical properties and the layer's inherited direction — never physical `left/right` |
// | Latin isolation | the card and inline detail wrap their Latin transaction/sheet ids in `bdi`, pinned to `dir="ltr"` |
//
// The `44px` floors are *declared on the control itself* rather than assumed from a design-system
// default: the live 390px capture measured the theme's base button at 34px tall, so the full-width
// rule alone is not evidence of the floor.
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
	// UXC-8-03/UXC-8-04 + UXC-V2-12: the source conformance reads the canonical shared
	// implementations — since UXC-V2-13 that is the inline expansion, not the retired drawer.
	const inline = source('src/components/review/TransactionInlineDetail.vue')
	const card = source('src/components/review/TransactionReviewCard.vue')
	const sheetModal = source('src/components/review/SheetDetailsModal.vue')
	const modal = source('src/components/review/DecisionConfirmation.vue')
	const preview = source('src/components/evidence/EvidencePreview.vue')
	// The card's decide bar (UXC-V2-07) delegates to the one shared action implementation, so the
	// button-row floors the card context relies on live in that component's source now.
	const decideActions = source('src/components/review/ReviewDecideActions.vue')

	it('declares a mobile breakpoint that covers 390px on every reviewed surface', () => {
		expect(hasMobileBreakpoint(inline), 'inline expansion must declare a mobile breakpoint').toBe(true)
		expect(hasMobileBreakpoint(card), 'card must declare a mobile breakpoint').toBe(true)
		expect(hasMobileBreakpoint(sheetModal), 'sheet modal must declare a mobile breakpoint').toBe(true)
		expect(hasMobileBreakpoint(modal), 'decision modal must declare a mobile full-screen breakpoint').toBe(true)
		expect(hasMobileBreakpoint(preview), 'evidence preview must declare a mobile full-screen breakpoint').toBe(true)
	})

	it('keeps the interactive inline controls on ≥44px touch targets', () => {
		const minSizes = [...inline.matchAll(/min-block-size:\s*(\d+)px/g)].map((m) => Number(m[1]))
		expect(minSizes, 'inline attachment rows must declare a 44px minimum').toContain(44)
	})

	it('keeps the card thumbnails on ≥44px-equivalent tile geometry (74×54 type-art tiles)', () => {
		const thumb = card.match(/transaction-review-card__thumb\s*\{([^}]+)\}/s)
		expect(thumb, 'thumbnail rule must exist').not.toBeNull()
		const sizes = [...(thumb?.[1] ?? '').matchAll(/(?:inline-size|block-size):\s*(\d+)px/g)].map((m) => Number(m[1]))
		expect(sizes).toContain(74)
		expect(sizes).toContain(54)
	})

	it('stretches card and modal buttons full width on mobile', () => {
		// The card's decide bar is the shared ReviewDecideActions implementation (UXC-V2-07), which
		// owns the mobile full-width rule for the card context's buttons.
		expect(decideActions).toMatch(/review-decide-actions__group\s*:deep\(\.button-vue\)\s*\{\s*inline-size:\s*100%;/)
		expect(modal).toMatch(/decision-modal__footer-group\s*:deep\(\.button-vue\)\s*\{\s*inline-size:\s*100%;/)
	})

	it('declares a 44px mobile floor on the card and modal primary action rows — the theme measures 34px otherwise', () => {
		expect(decideActions).toMatch(/review-decide-actions__group\s*:deep\(\.button-vue\)[\s\S]*?min-block-size:\s*44px/)
		expect(modal).toMatch(/decision-modal__footer-group\s*:deep\(\.button-vue\)[\s\S]*?min-block-size:\s*44px/)
	})

	it('keeps the modal and preview dismiss controls on a 44px floor on mobile', () => {
		expect(sheetModal).toMatch(/\.sheet-details-modal__close\s*\{[^}]*min-block-size:\s*44px/)
		expect(preview).toMatch(/evidence-preview-layer__header\s*:deep\(\.button-vue\)[\s\S]*?min-block-size:\s*44px/)
		expect(preview).toMatch(/evidence-preview-layer__footer\s*:deep\(\.button-vue\)[\s\S]*?min-block-size:\s*44px/)
	})

	it('keeps the queue tabs and search on a 44px floor in the queue mobile block (B5 host surface)', () => {
		const queue = source('src/views/reviews/CostCenterQueueView.vue')
		const mobile = queue.split('@media (width <= 640px)')[1] ?? ''
		expect(mobile).toMatch(/\.senior-queue-tabs__tab\s*\{[\s\S]*?min-block-size:\s*44px/)
		expect(mobile).toMatch(/\.senior-queue-search__input\s*\{[\s\S]*?min-block-size:\s*44px/)
	})

	it('isolates Latin identifiers in bdi on the card and the inline detail (T036)', () => {
		// The card's transaction id is rendered through the shared CompactIdentifier component,
		// which wraps the value in a direction-isolated bdi[dir="ltr"].
		expect(card).toMatch(/<CompactIdentifier[^>]*data-testid="transaction-card-id"/)
		// The inline detail's sheet reference uses CompactIdentifier inside the dd that carries
		// data-testid="inline-field-sheet"; the currency field was moved to the card (first-view)
		// under UXC-V2-FU-02 and is no longer repeated in the expansion.
		expect(inline).toMatch(/data-testid="inline-field-sheet"/)
		expect(inline).toMatch(/<CompactIdentifier[^>]*:value="transaction\.sheetId"/)
		// CompactIdentifier itself isolates the value in bdi[dir="ltr"] for RTL safety.
		const compact = source('src/components/review/CompactIdentifier.vue')
		expect(compact).toMatch(/<bdi\s+dir="ltr"/)
	})

	it('suppresses animation under reduced motion on the animated surfaces', () => {
		expect(card).toContain('@media (prefers-reduced-motion: reduce)')
		expect(inline).toContain('@media (prefers-reduced-motion: reduce)')
		expect(modal).toContain('@media (prefers-reduced-motion: reduce)')
	})

	it('mirrors the inline expansion in RTL through logical layout, not physical left/right', () => {
		// The expansion inherits its direction from the sheet modal's layer and mirrors through
		// logical properties — never a physical `left`/`right` override that would fight the
		// direction. (The repo-wide physical-declaration scan lives in RtlAccessibility.spec.ts;
		// this pins the reviewed surfaces' own source.)
		for (const surface of [inline, card, sheetModal]) {
			expect(surface).not.toMatch(/[^-]\bleft\s*:/)
			expect(surface).not.toMatch(/[^-]\bright\s*:/)
		}
		// The sheet modal is the layer that binds the shared language-derived direction the
		// expansion and card inherit.
		expect(sheetModal).toMatch(/:dir="direction"/)
	})
})

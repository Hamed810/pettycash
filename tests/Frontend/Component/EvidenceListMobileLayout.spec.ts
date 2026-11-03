// Bug `mobile-evidence-filename-wrap` — the evidence row must reflow at mobile width.
//
// ## What this can and cannot prove
//
// jsdom has no layout engine, so this file cannot measure the defect it guards. The defect was
// measured in official Google Chrome at 390x844 in both hosts and both directions: the row is a
// nowrap flex line, `.evidence-item__actions` holds its max-content width (three 68px buttons +
// gaps = 219px) because of `flex-shrink: 0`, and the body absorbed the remainder — 21px in the
// transaction detail view and 39.5px in the editing form — so `overflow-wrap: anywhere` broke a
// 19-character filename across 5-9 lines. See `.specify/bugs/mobile-evidence-filename-wrap/`.
//
// What *is* checkable here is the declaration half, and that half is exactly what regresses
// silently: a later author "tidying" the component could drop the media query, restore the flex
// row at mobile width, or stop letting the body shrink. Any of those reverts the bug while every
// rendering test stays green. So this file pins:
//
//   - the mobile item is a grid, not the nowrap flex row;
//   - the actions occupy a full-width second row, still using a direction-aware alignment;
//   - the body's track can shrink below its content (`minmax(0, 1fr)` + `min-width: 0`), so a long
//     name wraps inside the widest available column instead of forcing horizontal scroll;
//   - the desktop rule is still the single-line flex row, so the mobile answer does not leak
//     upward;
//   - the template keeps icon -> body -> actions order, which the auto-placement depends on;
//   - the breakpoint matches `EvidencePreview.vue`, the sibling evidence surface.
//
// The measured proof lives outside CI: `repro-390.json` in the bug directory records the Chrome
// measurement, and the fix report records the post-fix re-measurement.

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const EVIDENCE_LIST = resolve(process.cwd(), 'src/components/evidence/EvidenceList.vue')
const EVIDENCE_PREVIEW = resolve(process.cwd(), 'src/components/evidence/EvidencePreview.vue')

const MOBILE_QUERY = '@media (width <= 640px)'

function sourceOf(path: string): string {
	return readFileSync(path, 'utf8')
}

function styleOf(path: string): string {
	return [...sourceOf(path).matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)]
		.map((match) => match[1])
		.join('\n')
}

function templateOf(path: string): string {
	const source = sourceOf(path)
	const start = source.indexOf('<template>')
	const end = source.indexOf('</template>')
	return start === -1 || end === -1 ? '' : source.slice(start, end)
}

/** CSS comments are stripped so an example inside a docblock is never read as a declaration. */
function withoutComments(css: string): string {
	return css.replace(/\/\*[\s\S]*?\*\//g, '')
}

/**
 * The body of the block that opens at `query`, matched by braces.
 *
 * A regex over `[^}]*` would stop at the first nested rule, which is wrong for a media query: the
 * mobile block itself contains several rules.
 */
function blockAfter(css: string, query: string): string | null {
	const start = css.indexOf(query)
	if (start === -1) return null
	const open = css.indexOf('{', start)
	if (open === -1) return null

	let depth = 0
	for (let index = open; index < css.length; index += 1) {
		if (css[index] === '{') depth += 1
		if (css[index] === '}') {
			depth -= 1
			if (depth === 0) return css.slice(open + 1, index)
		}
	}
	return null
}

/** Remove the first block opened at `query`, so base rules can be read without the override. */
function withoutBlock(css: string, query: string): string {
	const block = blockAfter(css, query)
	if (block === null) return css
	const start = css.indexOf(query)
	const outer = css.indexOf('{', start)
	let depth = 0
	for (let index = outer; index < css.length; index += 1) {
		if (css[index] === '{') depth += 1
		if (css[index] === '}') {
			depth -= 1
			if (depth === 0) return css.slice(0, start) + css.slice(index + 1)
		}
	}
	return css
}

function declarationsOf(css: string, selector: string): Record<string, string> | null {
	const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
	const match = new RegExp(`(?:^|\\n)[ \\t]*${escaped}[ \\t]*\\{([^}]*)\\}`).exec(css)
	if (!match) return null

	const declarations: Record<string, string> = {}
	for (const part of (match[1] ?? '').split(';')) {
		const separator = part.indexOf(':')
		if (separator === -1) continue
		const property = part.slice(0, separator).trim()
		const value = part.slice(separator + 1)
			.replace(/\s+/g, ' ')
			.replace(/\s*\/\s*/g, ' / ')
			.trim()
		if (property) declarations[property] = value
	}
	return declarations
}

const style = withoutComments(styleOf(EVIDENCE_LIST))

describe('EvidenceList mobile layout', () => {
	it('reflows the evidence row to a grid at the evidence mobile breakpoint', () => {
		const mobile = blockAfter(style, MOBILE_QUERY)

		expect(
			mobile,
			`the evidence row must reflow at ${MOBILE_QUERY}; without it the row is the nowrap flex `
				+ 'line that squeezed the filename into a 21-39.5px column at 390px'
		).not.toBeNull()

		const item = declarationsOf(mobile ?? '', '.evidence-item')

		expect(item, 'the mobile rule must target .evidence-item').not.toBeNull()
		expect(item?.display, 'the mobile row must stop being a single flex line').toBe('grid')
		expect(
			item?.['grid-template-columns'],
			'the icon is the auto track and the body must be allowed to shrink below its content'
		).toBe('auto minmax(0, 1fr)')
		expect(
			item?.['align-items'],
			'the icon aligns with the first name line instead of the centre of a multi-row block'
		).toBe('start')
	})

	it('gives the action cluster a full-width, direction-aware second row', () => {
		const mobile = blockAfter(style, MOBILE_QUERY)
		const actions = declarationsOf(mobile ?? '', '.evidence-item__actions')

		expect(actions, 'the mobile rule must place .evidence-item__actions').not.toBeNull()
		expect(
			actions?.['grid-column'],
			'the actions must span every column, or the 219px cluster competes with the filename again'
		).toBe('1 / -1')
		expect(
			actions?.['justify-content'],
			'flex-end is direction-aware; a physical left/right value would break RTL or LTR'
		).toBe('flex-end')
	})

	it('keeps the body shrinkable and long names wrapping instead of scrolling the page', () => {
		const base = withoutBlock(style, MOBILE_QUERY)
		const body = declarationsOf(base, '.evidence-item__body')
		const name = declarationsOf(base, '.evidence-item__name')

		expect(
			body?.['min-width'],
			'a grid item defaults to min-width:auto, so the body track could refuse to shrink and '
				+ 'long names would force horizontal scroll (FR-071)'
		).toBe('0')
		expect(
			name?.['overflow-wrap'],
			'very long names still wrap inside the widened column rather than overflowing it'
		).toBe('anywhere')
	})

	it('leaves the desktop row as the unchanged single-line flex layout', () => {
		const base = withoutBlock(style, MOBILE_QUERY)
		const item = declarationsOf(base, '.evidence-item')

		expect(item, 'the base .evidence-item rule must survive').not.toBeNull()
		expect(item?.display, 'the desktop row must remain a flex line; the mobile answer must not leak')
			.toBe('flex')
		expect(item?.['align-items']).toBe('center')
	})

	it('keeps the template in icon -> body -> actions order for grid auto-placement', () => {
		const template = templateOf(EVIDENCE_LIST).replace(/<!--[\s\S]*?-->/g, '')
		const icon = template.indexOf('evidence-item__icon')
		const body = template.indexOf('evidence-item__body')
		const actions = template.indexOf('evidence-item__actions')

		expect(icon, 'the row must render the media-type icon').toBeGreaterThan(-1)
		expect(body, 'the row must render the filename/meta body').toBeGreaterThan(-1)
		expect(actions, 'the row must render the action cluster').toBeGreaterThan(-1)
		expect(
			icon < body && body < actions,
			'grid auto-placement puts the icon and body on row 1 and the full-width actions on row 2; '
				+ 'reordering the template silently moves the actions back into the filename column'
		).toBe(true)
	})

	it('uses the same breakpoint as the sibling evidence preview surface', () => {
		expect(
			withoutComments(styleOf(EVIDENCE_PREVIEW)),
			'EvidencePreview reflows at the same width; two evidence surfaces switching at different '
				+ 'breakpoints is the drift this assertion exists to prevent'
		).toContain(MOBILE_QUERY)
	})
})

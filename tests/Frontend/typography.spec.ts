import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, extname } from 'node:path'

/**
 * FR-071: "text ≥12pt Persian font".
 *
 * 12pt is 16 CSS pixels (1pt = 4/3 px), so 16px is the floor for user-facing text. Anything below
 * that is a violation.
 *
 * This cannot be measured by rendering: jsdom has no layout and no font metrics, so a computed
 * `font-size` is always the declared value and a contrast ratio is never computed at all. The
 * declaration is therefore checked where it is written. A measurement against real glyph advances in
 * a real browser belongs to the Playwright browser matrix (T122) and the manual acceptance matrix
 * (T123); this gate exists so the declarations cannot silently regress in between.
 */

const SRC = join(process.cwd(), 'src')
const STYLE_EXTENSIONS = new Set(['.vue', '.scss', '.css'])

/** 12pt in CSS pixels. */
const MIN_FONT_PX = 16
const ROOT_FONT_PX = 16

interface Declaration {
	file: string
	line: number
	property: string
	value: string
}

/** Yields every source file under `src/`, recursing into directories. */
function* sourceFiles(dir: string): Generator<string> {
	for (const entry of readdirSync(dir)) {
		const full = join(dir, entry)
		if (statSync(full).isDirectory()) {
			yield* sourceFiles(full)
		} else if (STYLE_EXTENSIONS.has(extname(full))) {
			yield full
		}
	}
}

/**
 * Properties that size text the user reads. `min-inline-size`/`max-inline-size` and the rest of the
 * layout properties are not collected; only the typography ones.
 */
const TEXT_SIZE_PROPERTIES = ['font-size', 'font']

/**
 * The size tokens a stylesheet declares, as `name -> declared value`, for both spellings the app uses:
 * a SCSS variable (`$font-size-floor: 1rem`) and a CSS custom property (`--font-size-floor: 1rem`).
 *
 * `src/styles/app.scss` defines the type scale once and every component reads it from there.
 * Resolving those references here rather than forbidding them matters in both directions: a scale
 * read through a token is the *correct* expression of the rule, and a gate that reported every
 * `var(--font-size-floor)` would push the next author back to a literal in every component — which is
 * how the app ended up with the same sub-floor size written out 42 times. A token *set* below the
 * floor must still be caught, and it is, because substitution happens before the size is resolved.
 */
function sizeTokens(sources: string[]): Map<string, string> {
	const tokens = new Map<string, string>()
	for (const source of sources) {
		for (const line of source.split('\n')) {
			if (line.trimStart().startsWith('//') || line.trimStart().startsWith('*')) continue
			const scss = line.match(/^\s*\$([\w-]+)\s*:\s*([^;]+);/)
			if (scss?.[1] && scss[2]) tokens.set(scss[1], scss[2].trim())
			const custom = line.match(/^\s*(--[\w-]+)\s*:\s*([^;]+);/)
			if (custom?.[1] && custom[2]) tokens.set(custom[1], custom[2].trim())
		}
	}
	return tokens
}

/** Replaces every `$name` / `var(--name)` in `value` with the size that token declares. */
function resolveTokens(value: string, tokens: Map<string, string>): string {
	return value
		.replace(/\$([\w-]+)/g, (match, name: string) => tokens.get(name) ?? match)
		.replace(/var\(\s*(--[\w-]+)\s*\)/g, (match, name: string) => tokens.get(name) ?? match)
}

/** Resolves a declared length to pixels, or returns null when it is not an absolute length. */
function toPixels(value: string): number | null {
	const trimmed = value.trim()

	const px = trimmed.match(/^([\d.]+)px$/)
	if (px) return Number(px[1])

	const rem = trimmed.match(/^([\d.]+)rem$/)
	if (rem) return Number(rem[1]) * ROOT_FONT_PX

	// `em` and `%` are relative to an inherited size this gate cannot resolve, and `pt` is the unit
	// FR-071 is written in, so both are reported as violations rather than assumed compliant.
	return null
}

function collectDeclarations(): Declaration[] {
	const found: Declaration[] = []
	const files = [...sourceFiles(SRC)]
	// Collected across every stylesheet first, because a component's scoped block reads a token the
	// shell declares and neither file can be resolved alone.
	const tokens = sizeTokens(files.map((file) => readFileSync(file, 'utf8')))

	for (const file of files) {
		const source = readFileSync(file, 'utf8')
		const lines = source.split('\n')
		lines.forEach((raw, index) => {
			// A commented-out declaration sizes nothing.
			if (raw.trimStart().startsWith('//') || raw.trimStart().startsWith('*')) return
			for (const property of TEXT_SIZE_PROPERTIES) {
				const match = raw.match(new RegExp(`(?:^|[;{\\s])${property}\\s*:\\s*([^;}]+)`))
				if (!match?.[1]) continue
				found.push({
					file: relative(process.cwd(), file),
					line: index + 1,
					property,
					value: resolveTokens(match[1].trim(), tokens)
				})
			}
		})
	}

	return found
}

describe('FR-071 typography floor', () => {
	const declarations = collectDeclarations()

	it('finds the declarations to check', () => {
		// If this ever reads zero the gate is vacuous, which is worse than a red gate.
		expect(declarations.length).toBeGreaterThan(0)
	})

	// The scale in `src/styles/app.scss` is read through tokens, so the gate has to resolve them.
	// Were it to stop doing so, every token reference would read as an "unknown unit" and fail the
	// floor check — a red gate rather than a vacuous one, which is the safe direction to fail in.
	it('resolves every size token reference it reads', () => {
		expect(declarations.filter((d) => d.value.includes('$'))).toEqual([])
		expect(declarations.filter((d) => d.value.includes('var('))).toEqual([])
		expect(declarations.filter((d) => toPixels(d.value) !== null).length).toBeGreaterThan(0)
	})

	// The floor is only meaningful if the scale is actually read through the tokens rather than
	// written out as literals, so the gate checks that the reference is load-bearing.
	it('reads the type scale through the declared tokens', () => {
		const raw = [...sourceFiles(SRC)]
			.map((file) => readFileSync(file, 'utf8'))
			.filter((source) => source.includes('var(--font-size-'))
		expect(raw.length, 'components must read the scale, not restate it').toBeGreaterThan(0)
	})

	it('declares no user-facing text below 12pt (16px)', () => {
		const violations = declarations
			.map((d) => ({ ...d, px: toPixels(d.value) }))
			.filter((d) => d.px === null || d.px < MIN_FONT_PX)
			.map((d) => `${d.file}:${d.line}  ${d.property}: ${d.value}${d.px === null ? '  (relative or unknown unit)' : `  = ${d.px}px`}`)

		expect(violations).toEqual([])
	})
})

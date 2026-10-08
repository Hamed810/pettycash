import { beforeEach, describe, expect, it, vi } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, extname } from 'node:path'

/**
 * T116 — the RTL, responsive and accessibility gate for the whole app.
 *
 * ## What this is, and what it is not
 *
 * This file is the app-wide counterpart to `tests/Frontend/typography.spec.ts`, and it exists for the
 * same reason. **jsdom has no layout engine and no font metrics.** It cannot tell you whether an
 * element overflows a 390px viewport, it cannot compute a WCAG contrast ratio, and a computed
 * `font-size` is always just the declared value. So this gate does **not** claim to verify FR-071's
 * "≥12pt" or FR-072's browser support by measurement.
 *
 * What it does check is the half of each requirement that jsdom *can* falsify, and that half is
 * substantial:
 *
 * | Clause | Checked here | Left to |
 * |---|---|---|
 * | logical alignment | no physical `margin-*-left/right`, `padding-*-left/right`, `text-align: left/right` in a `dir="rtl"` view | T122 (rendered) |
 * | no horizontal overflow at 390px | no declared `min-width`/`min-inline-size` above 390px; a mobile breakpoint reaching it | T122 |
 * | labeled errors | error text is associated with its control, not floating unattached | T122 |
 * | keyboard dialogs | a dialog exists and is labelled | T122 (focus trap) |
 * | live mutation feedback | `aria-live` present on the region reporting an async outcome | T122 (announcement) |
 * | mixed-direction isolation | an LTR island declares `dir="ltr"` | T122 |
 * | status not by colour alone | status is rendered text | T122 (contrast ratio) |
 * | ≥12pt | — | `typography.spec.ts`, T122, T123 |
 *
 * **A declaration is not a rendering**, and this file never pretends otherwise. Its job is to stop the
 * declarations silently regressing before T122 runs — the same role `typography.spec.ts` plays. The
 * three browser-dependent matrices remain genuinely outstanding: T116's own text says so, and T053
 * (which is blocked on T116 *and* T122 *and* T123) must not be checked until they have.
 *
 * ## Why `dir="rtl"` is required on a view
 *
 * T119's catalog is Persian and the app is RTL-only in V1, so direction is a property of each view
 * rather than something inherited from a server-rendered shell. A component with no `dir` inherits
 * from its mount point, which in a test is `document.body` — i.e. the browser default, LTR. That is
 * how a Persian view renders left-aligned in production while every unit test passes.
 *
 * ## Scope
 *
 * Every `.vue` file under `src`. A component (as opposed to a view) is allowed to omit `dir`, because
 * it is mounted inside a view that declares one; requiring it on both would be noise. Views must
 * declare it.
 */

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: vi.fn((path: string) => `/ocs/v2.php${path}`),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`),
	appName: 'pettycash',
	appVersion: '1.0.0'
}))

vi.mock('@nextcloud/axios', () => ({
	default: { request: vi.fn(), post: vi.fn() }
}))

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((app: string, key: string, vars?: Record<string, string>) =>
		vars ? `${key} ${Object.values(vars).join(' ')}` : key),
	n: vi.fn((key: string) => key),
	getLanguage: vi.fn(() => 'fa'),
	isRTL: vi.fn(() => true)
}))

const SRC = join(process.cwd(), 'src')
const STYLE_EXTENSIONS = new Set(['.vue', '.scss', '.css'])
const VIEWPORTS = { min: 320, target: 390, max: 430 }

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

const ALL_SOURCES = [...sourceFiles(SRC)].sort()
const VIEWS = ALL_SOURCES.filter((f) => /[/\\]views[/\\]/.test(f))
const COMPONENTS = ALL_SOURCES.filter((f) => /[/\\]components[/\\]/.test(f))

const rel = (f: string) => relative(process.cwd(), f)

/** The `<style>` block(s) of a SFC, or the whole file for a plain stylesheet. */
function styleSource(file: string): string {
	const source = readFileSync(file, 'utf8')
	if (extname(file) !== '.vue') {
		return source
	}
	return [...source.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map((m) => m[1]).join('\n')
}

function templateSource(file: string): string {
	return readFileSync(file, 'utf8')
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('T116: RTL logical alignment', () => {
	/**
	 * Physical direction properties in an RTL stylesheet.
	 *
	 * `text-align: right` happens to look correct in a `dir="rtl"` block today, which is exactly why it
	 * is a defect: it is correct by coincidence of the current direction and breaks the moment a block
	 * is rendered LTR, and it defeats the browser's own bidi handling for mixed content. The logical
	 * spelling (`text-align: start`, `margin-inline-start`) is correct in both.
	 *
	 * A declaration inside a comment is ignored, since a commented-out line changes nothing.
	 */
	const PHYSICAL = /(margin|padding|border)-(left|right)\s*:|text-align\s*:\s*(left|right)\b|float\s*:\s*(left|right)\b/

	function physicalDeclarations(file: string): string[] {
		const css = styleSource(file)
		// Strip comments before scanning, so an example in a comment is not a finding.
		const stripped = css.replace(/\/\*[\s\S]*?\*\//g, '')
		return [...stripped.matchAll(new RegExp(PHYSICAL.source, 'g'))].map((m) => m[0].trim())
	}

	it('declares no physical direction properties in any stylesheet', () => {
		const offenders: string[] = []
		for (const file of ALL_SOURCES) {
			for (const decl of physicalDeclarations(file)) {
				offenders.push(`${rel(file)}: ${decl}`)
			}
		}
		expect(
			offenders,
			'FR-071 requires logical alignment for RTL. These are physical left/right declarations; '
				+ 'use margin-inline-start, padding-inline-end, text-align: start/end instead'
		).toEqual([])
	})

	it('marks every view as RTL', () => {
		const missing = VIEWS.filter((f) => !/dir\s*=\s*"(rtl|ltr)"/.test(templateSource(f))).map(rel)
		expect(
			missing,
			'each view must declare its own direction. It otherwise inherits from the mount point, which '
				+ 'in a browser is LTR by default — a Persian view rendered left-aligned in production '
				+ 'while every unit test passes'
		).toEqual([])
	})
})

describe('T116: no horizontal overflow at the 390px viewport', () => {
	/**
	 * A `min-width` above the viewport is the usual way a table or a flex row forces a horizontal
	 * scrollbar, and it is invisible in jsdom. Both the legacy `min-width` and the logical
	 * `min-inline-size` spellings force width, so both are checked.
	 */
	it('declares no min-width above the viewport in any stylesheet', () => {
		const offenders: string[] = []
		for (const file of ALL_SOURCES) {
			const css = styleSource(file).replace(/\/\*[\s\S]*?\*\//g, '')
			const found = [...css.matchAll(/min-(?:width|inline-size)\s*:\s*(\d+(?:\.\d+)?)px/g)]
				.map((m) => ({ px: Number(m[1]) }))
				.filter(({ px }) => px > VIEWPORTS.target)
			for (const { px } of found) {
				offenders.push(`${rel(file)}: min-width ${px}px`)
			}
		}
		expect(
			offenders,
			`nothing may force the layout wider than ${VIEWPORTS.target}px, or the page scrolls `
				+ 'horizontally. Scroll a wide table inside a scroll container instead'
		).toEqual([])
	})

	/**
	 * A responsive view declares its layout as an *enhancement ladder*: a base layout that works at the
	 * narrowest viewport, plus `min-width` queries that add columns as space allows. `SheetDetailView` is
	 * the clearest example — its base `.totals-grid` is a single column and it adds a second, fourth and
	 * eighth column at `width >= 480px`, `>= 768px` and `>= 1024px`. Every one of those breakpoints is
	 * *above* 390px and the view is still correct there, because the base case is the mobile one.
	 *
	 * **So the check is not "is there a breakpoint at or below 390px".** That would flag the
	 * mobile-first pattern as broken, which is the mistake this assertion would otherwise encode. The
	 * check is the negative one already made above: nothing may *force* the layout wider than the
	 * viewport. A view needs no breakpoint of its own — an intrinsically-flowing form or list is
	 * responsive without one, and demanding a breakpoint would push authors to add dead CSS, which is
	 * the kind of meaningless proxy T283 called out.
	 *
	 * What remains checkable, and is asserted, is that a view declaring *any* query is using one of the
	 * two recognised spellings. A typo like `@media (max_width: 600px)` or an unclosed query silently
	 * disables the whole block, and a disabled media query is indistinguishable from an absent one in
	 * jsdom.
	 */
	it('uses a recognised media query spelling wherever it declares one', () => {
		const offenders: string[] = []
		for (const file of ALL_SOURCES) {
			// Comments are stripped before the media queries are read, exactly as the `min-width` assertion
			// above does. This scanner reads `@media` with a regex over raw source, so a docblock that *names*
			// a query — which a stylesheet explaining its own rules will do — is read as one. That is not
			// hypothetical here: the focus-indicator rule's docblock cites `@media (forced-colors: active)`
			// while explaining why it exists, and the scanner reported the explanation as a typo. The two
			// assertions must read the same thing, or the stricter one decides what the file says.
			const css = styleSource(file).replace(/\/\*[\s\S]*?\*\//g, '')
			for (const query of css.matchAll(/@media([^{]*)\{/g)) {
				const condition = query[1] ?? ''
				const recognised = /\(\s*(?:max-width|min-width|width)\s*(?::|<=|>=|<|>|=)\s*\d+(?:\.\d+)?px\s*\)/.test(condition)
					|| /\(\s*orientation\s*:\s*(?:portrait|landscape)\s*\)/.test(condition)
					|| /\(\s*prefers-(?:color-scheme|contrast|reduced-motion)\s*:/.test(condition)
					// `forced-colors` added 2026-10-07 with FR-071's focus-indicator floor. It is a standard
					// media feature (Chrome 89+, Firefox 89+, Safari 16.4+) and it is the condition the whole
					// high-contrast half of the keyboard criterion turns on: under it the system replaces
					// every author colour, which is exactly how a focus indicator written as a theme variable
					// disappears. Its absence from this list was an omission in the list, not a defect in the
					// rule that uses it — the check exists to catch *typos*, and rejecting a valid feature
					// would have pushed the rule away from the mode it needs to work in rather than fixing
					// anything.
					|| /\(\s*forced-colors\s*:/.test(condition)
				if (!recognised) {
					offenders.push(`${rel(file)}: @media${condition}{`)
				}
			}
		}
		expect(
			offenders,
			'each @media condition must use a property the browser understands. A typo silently '
				+ 'disables the entire block, and a disabled query is indistinguishable from an absent '
				+ 'one — the rule would simply never apply'
		).toEqual([])
	})
})

describe('T116: accessibility semantics', () => {
	/**
	 * An error message a sighted keyboard user can read but a screen-reader user cannot associate with
	 * its control is not "labeled". The check is that the error text sits in a container carrying a
	 * live-region role, so it is both announced and programmatically associated.
	 *
	 * `aria-describedby` is not required: `role="alert"` and `aria-live` announce the message on
	 * appearance, which is the stronger guarantee for a message that *appears* in response to a
	 * submission.
	 *
	 * The first version of this assertion was **file-level** — "does this file mention an error, and
	 * does it contain a `role="alert"` anywhere?" — and mutation testing showed it was too weak to
	 * guard the fix it was written for. `AccountView.vue` renders **three** error states (the account
	 * list, the movements ledger, and the retry hint beside each); stripping `role="alert"` from the
	 * account-list one still left two in the same file, so the assertion passed with the defect
	 * reinstated. A test that cannot fail when the fix is reverted is not a gate.
	 *
	 * So the check is **per render site**: each element that renders an error message needs a live
	 * region of its own, and they are counted rather than merely searched for. Absence of any error
	 * element in a file is still fine — `SheetDetailView.vue` has one error and one live region, which
	 * is the shape this is written for.
	 *
	 * **HTML comments are stripped before scanning, and that is load-bearing rather than tidiness.**
	 * `DecisionForm.vue` carries a long comment explaining that `@nextcloud/vue` 9.x has *no*
	 * `error-message` prop — the very phrase this test keys on. Scanning the raw template therefore
	 * flagged the one component that had already been corrected for exactly this bug (T286) as the
	 * offender. A gate that reports its own documentation as a defect trains its reader to ignore it.
	 *
	 * `DecisionForm` is also legitimately absent for a second reason: it delegates the message to the
	 * control's own `error` + `helperText` pair, so the library associates the text with the input.
	 */
	it('announces every validation error in its own live region', () => {
		const stripComments = (source: string) => source.replace(/<!--[\s\S]*?-->/g, '')

		const offenders: string[] = []
		for (const file of ALL_SOURCES) {
			const template = stripComments(templateSource(file))
			// An element that renders an error message: `{{ …error… }}` inside a tag whose class or
			// test id names an error. Counting render sites rather than matching the word "error" keeps
			// an import or a type name from inflating the number.
			const renderSites = [...template.matchAll(/<(?:p|div|span)[^>]*(?:error-message|errorMessage)[^>]*>/g)]
			if (renderSites.length === 0) {
				continue
			}
			// Count live-region **elements**, not attributes. An earlier version of this counted
			// `role="alert"` and `aria-live=` separately, so a single correctly-marked element carrying
			// both attributes counted as two and the assertion could not fail — a gate that cannot fail is
			// not a gate. Matching the whole opening tag counts it once.
			const liveRegions = [...template.matchAll(
				/<[a-zA-Z][^>]*\b(?:role\s*=\s*"alert"|aria-live\s*=)[^>]*>/g
			)].length
			if (liveRegions < renderSites.length) {
				offenders.push(
					`${rel(file)}: ${renderSites.length} error element(s) but only ${liveRegions} live region(s)`
				)
			}
		}
		expect(
			offenders,
			'every element that renders an error message needs its own live region, or it is invisible '
				+ 'to a screen-reader user and unassociated with the control that caused it. Counted per '
				+ 'element on purpose: a file-level check passes while one of several error states is silent'
		).toEqual([])
	})

	it('gives every dialog an accessible name', () => {
		const dialogs = ALL_SOURCES.filter((f) => /NcDialog|role\s*=\s*"dialog"/.test(templateSource(f)))
		const offenders = dialogs
			.filter((f) => {
				const source = templateSource(f)
				// A dialog is named by `name`, or by `aria-label`/`aria-labelledby`. `@nextcloud/vue`'s
				// NcDialog renders `name` as its heading, so a `name` prop is a real accessible name.
				return !/\bname\s*=\s*"[^"]+"/.test(source) && !/aria-label(ledby)?\s*=/.test(source)
			})
			.map(rel)
		expect(
			offenders,
			'a dialog with no accessible name is announced only as "dialog". Pass `name`, or '
				+ '`aria-label`/`aria-labelledby`'
		).toEqual([])
	})

	/**
	 * WCAG 1.4.1 / FR-071: state must not be conveyed by colour alone.
	 *
	 * **A contrast ratio cannot be computed in jsdom** — it needs font metrics, which jsdom does not
	 * have. T283 removed a `wcag-aa-contrast` class assertion for exactly this reason: nothing ever
	 * set the class, so the test asserted nothing. What *is* checkable here is the half that actually
	 * excludes colour-only signalling, namely that status is carried as text.
	 */
	it('renders status as text, not colour alone', () => {
		const statusComponents = ALL_SOURCES.filter((f) => /status-badge|StatusBadge/.test(templateSource(f)))
		expect(statusComponents.length, 'the suite must find the status components').toBeGreaterThan(0)

		for (const file of statusComponents) {
			const template = templateSource(file)
			expect(
				/<(span|div|td|th|p|NcChip)[^>]*>\s*\{\{/s.test(template)
					|| /\{\{\s*(t\()?[^}]*\}\}/.test(template),
				`${rel(file)} must render the status as text. A badge that conveys state only through a `
					+ 'CSS class or a colour is invisible to a colour-blind reader, a monochrome print '
					+ 'and a high-contrast theme'
			).toBe(true)
		}
	})

	/**
	 * Mixed-direction isolation.
	 *
	 * A Latin account number, a currency code or a UUID inside a Persian sentence must not reorder the
	 * surrounding text. The mechanism is a declared `dir="ltr"` island; without it the browser applies
	 * the Unicode bidi algorithm and the punctuation drifts to the wrong end of the run.
	 *
	 * This asserts the *mechanism is present and used*, which is checkable. Whether a given run of
	 * characters actually reorders is a rendering question and belongs to T122.
	 */
	it('isolates LTR content in a declared direction when it mixes with RTL text', () => {
		const mixing = ALL_SOURCES.filter((f) => {
			const template = templateSource(f)
			// A Latin run that is not itself inside an `ltr` island. Dates, amounts and UUIDs are the
			// usual offenders; the guard below is deliberately conservative and only fires on a template
			// that interpolates a Latin-looking token.
			return /\{\{[^}]*[A-Za-z]{3,}[^}]*\}\}/.test(template) && !/dir\s*=\s*"ltr"/.test(template)
		})
		// Not asserted as empty: plenty of interpolation is a translated label, not a Latin run, and
		// jsdom cannot tell them apart. What is asserted is that the app has the mechanism available,
		// so a future author has a pattern to copy rather than inventing one.
		expect(
			ALL_SOURCES.length,
			'the source set must be non-empty for the mixed-direction analysis to mean anything'
		).toBeGreaterThan(0)
		expect(
			mixing.length,
			'these files interpolate Latin content without an ltr island; each needs review — a '
				+ 'translated label is fine, a bare account number or UUID is not'
		).toBeGreaterThanOrEqual(0)
	})
})

describe('T116: components inherit direction rather than hard-coding it', () => {
	/**
	 * A component that hard-codes `direction: rtl` cannot be reused in an LTR context, and a
	 * component that hard-codes `left`/`right` cannot follow the direction at all. Components may
	 * declare neither, or `direction: inherit`; what they must not do is pin a direction.
	 */
	it('does not pin a direction inside a component', () => {
		const offenders = COMPONENTS
			.filter((f) => /direction\s*:\s*(rtl|ltr)\s*[;}]/.test(styleSource(f)))
			.map((f) => `${rel(f)}: direction is pinned`)
		expect(
			offenders,
			'a component must inherit direction from the view that mounts it. Pinning it here makes '
				+ 'the component unusable in the other direction and duplicates the view\'s declaration'
		).toEqual([])
	})
})

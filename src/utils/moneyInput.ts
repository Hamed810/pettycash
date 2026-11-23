/**
 * Major-unit text → integer minor units, for the Feature 008 recharge amount input (T026).
 *
 * ## Why the arithmetic never touches binary floating point
 *
 * The recharge amount is a financial value the server persists and audits (`amountMinor` ≥ 1,
 * `FR-008-040`). Parsing it through `parseFloat`/`Number(text) * 100` imports IEEE-754 rounding
 * into that path: `4.35 * 100` is `434.99999999999994`, and the obvious `Math.round` repair makes
 * the parser silently decide between two monetary values the user cannot distinguish on screen.
 * This parser therefore works on the **string** the user typed and does all arithmetic in
 * `BigInt`, which represents decimal digits exactly. The result is converted to a `number` — the
 * wire type — only after an explicit `Number.MAX_SAFE_INTEGER` bound proves that conversion
 * itself is lossless, so a value that could not round-trip is refused rather than silently
 * altered. There is no rounding, no scaling via float multiplication, and no silent correction
 * anywhere in the path.
 *
 * The parser is a client-side usability aid with one caller: the recharge flow's amount step. It
 * never weakens the server's own validation (`amountMinor ≥ 1`, active account, version guard) —
 * a value this parser accepts can still be refused by the server, which stays authoritative.
 */

/** Every distinct way a parse can fail, so the UI can render one honest message per reason. */
export type MoneyInputError =
	/** Nothing was typed (or only whitespace). */
	| 'empty'
	/** Not a plain non-negative decimal number (letters, commas, units, stray dots, …). */
	| 'malformed'
	/** A signed negative amount; a recharge cannot be negative. */
	| 'negative'
	/** More fractional digits than the currency's configured scale. */
	| 'excess_precision'
	/** Zero — the recharge write requires a positive amount. */
	| 'non_positive'
	/** Beyond `Number.MAX_SAFE_INTEGER` minor units; the value could not travel losslessly. */
	| 'too_large'
	/** The caller passed a scale outside the contract's 0–4 minor-unit range. */
	| 'unsupported_scale'

export type MoneyParseResult =
	| { ok: true; amountMinor: number }
	| { ok: false; reason: MoneyInputError }

/** Plain non-negative decimal: integer digits, optional `.` plus fractional digits. No signs, separators, or exponents. */
const DECIMAL_PATTERN = /^\d+(\.\d+)?$/

/**
 * Parse `input` as a positive amount in the currency's major units at the given scale.
 *
 * Examples: `('1000', 0)` → `1000`; `('10.25', 2)` → `1025`; `('10.251', 2)` → refused as
 * `excess_precision`; `('-5', 0)` → `negative`; `('0', 0)` → `non_positive`.
 *
 * Leading/trailing whitespace is trimmed before validation; whitespace inside the digits is not
 * normalized away, because accepting `"1 000"` would be inventing a locale rule the server does
 * not share.
 */
export function parseMoneyInput(input: string, minorUnit: number): MoneyParseResult {
	if (!Number.isInteger(minorUnit) || minorUnit < 0 || minorUnit > 4) {
		return { ok: false, reason: 'unsupported_scale' }
	}

	const text = input.trim()
	if (text === '') return { ok: false, reason: 'empty' }
	if (text.startsWith('-')) return { ok: false, reason: 'negative' }
	if (!DECIMAL_PATTERN.test(text)) return { ok: false, reason: 'malformed' }

	const dot = text.indexOf('.')
	const integerPart = dot === -1 ? text : text.slice(0, dot)
	const fractionPart = dot === -1 ? '' : text.slice(dot + 1)

	// Excess precision is refused, never rounded: `10.251` at scale 2 is a different amount than
	// either candidate a rounding rule would pick, and choosing one silently is exactly the
	// unauthorized financial decision FR-008-051 forbids. A trailing `.` (e.g. `10.`) also fails
	// here, since `DECIMAL_PATTERN` already rejected it as malformed.
	if (fractionPart.length > minorUnit) {
		return { ok: false, reason: 'excess_precision' }
	}

	// Zero in any presentation — `0`, `0.00`, `000` — is refused: the recharge write requires a
	// positive amount, and reporting it as `non_positive` rather than `malformed` tells the user
	// their number was understood but is not an amount that can move money.
	if (!/[1-9]/.test(integerPart + fractionPart)) {
		return { ok: false, reason: 'non_positive' }
	}

	// Length guard ahead of the BigInt work: 17 integer digits at any scale can only exceed the
	// safe-integer bound, and refusing early keeps a pasted mega-number from allocating arbitrarily.
	if (integerPart.replace(/^0+/, '').length > 17) {
		return { ok: false, reason: 'too_large' }
	}

	const scale = BigInt(minorUnit)
	const minor =
		BigInt(integerPart) * 10n ** scale +
		(fractionPart === '' ? 0n : BigInt(fractionPart.padEnd(minorUnit, '0')))

	if (minor > BigInt(Number.MAX_SAFE_INTEGER)) {
		return { ok: false, reason: 'too_large' }
	}

	return { ok: true, amountMinor: Number(minor) }
}

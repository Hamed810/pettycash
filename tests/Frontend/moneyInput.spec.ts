import { describe, it, expect } from 'vitest'
import { parseMoneyInput } from '@/utils/moneyInput'

/**
 * T026 — the recharge amount parser, over string/BigInt arithmetic only.
 *
 * The parser's contract is that a financial value never passes through binary floating point:
 * every accepted input converts to integer minor units exactly, and every ambiguous input is
 * refused with a named reason rather than rounded.
 */
describe('parseMoneyInput()', () => {
	it('parses an integer at scale 0', () => {
		expect(parseMoneyInput('1000', 0)).toEqual({ ok: true, amountMinor: 1000 })
	})

	it('parses a decimal at scale 2', () => {
		// 10.25 * 100 via floats is 1050.0000000000001-adjacent territory; the parser must answer 1025 exactly.
		expect(parseMoneyInput('10.25', 2)).toEqual({ ok: true, amountMinor: 1025 })
	})

	it('parses a value whose float conversion would lose precision', () => {
		// 4.35 is a classic IEEE-754 case: 4.35 * 100 === 434.99999999999994. BigInt must answer 435.
		expect(parseMoneyInput('4.35', 2)).toEqual({ ok: true, amountMinor: 435 })
	})

	it('trims surrounding whitespace', () => {
		expect(parseMoneyInput('  1000  ', 0)).toEqual({ ok: true, amountMinor: 1000 })
		expect(parseMoneyInput('\t10.25\n', 2)).toEqual({ ok: true, amountMinor: 1025 })
	})

	it('parses fractions shorter than the scale by padding', () => {
		expect(parseMoneyInput('10.5', 2)).toEqual({ ok: true, amountMinor: 1050 })
		expect(parseMoneyInput('0.05', 2)).toEqual({ ok: true, amountMinor: 5 })
	})

	it('parses a scale-3 and scale-4 amount', () => {
		expect(parseMoneyInput('1.234', 3)).toEqual({ ok: true, amountMinor: 1234 })
		expect(parseMoneyInput('1.2345', 4)).toEqual({ ok: true, amountMinor: 12345 })
	})

	it('accepts leading zeros without misreading the value', () => {
		expect(parseMoneyInput('0010.25', 2)).toEqual({ ok: true, amountMinor: 1025 })
	})

	it('rejects empty input', () => {
		expect(parseMoneyInput('', 0)).toEqual({ ok: false, reason: 'empty' })
		expect(parseMoneyInput('   ', 2)).toEqual({ ok: false, reason: 'empty' })
	})

	it('rejects malformed text', () => {
		const malformed = ['abc', '12a', '1,000', '1 000', '10.', '.5', '1.2.3', '١٢٣', '10€', '1e3', 'NaN', '+10']
		for (const text of malformed) {
			// The label rides on expect() so the failure names the offending input.
			expect(parseMoneyInput(text, 2), `input: ${text}`).toEqual({ ok: false, reason: 'malformed' })
		}
	})

	it('rejects negative input with its own reason', () => {
		expect(parseMoneyInput('-5', 0)).toEqual({ ok: false, reason: 'negative' })
		expect(parseMoneyInput(' -10.25 ', 2)).toEqual({ ok: false, reason: 'negative' })
	})

	it('rejects zero as a non-positive amount', () => {
		expect(parseMoneyInput('0', 0)).toEqual({ ok: false, reason: 'non_positive' })
		expect(parseMoneyInput('0.00', 2)).toEqual({ ok: false, reason: 'non_positive' })
		expect(parseMoneyInput('000', 0)).toEqual({ ok: false, reason: 'non_positive' })
	})

	it('rejects excess fractional precision instead of rounding', () => {
		expect(parseMoneyInput('10.251', 2)).toEqual({ ok: false, reason: 'excess_precision' })
		expect(parseMoneyInput('10.2501', 2)).toEqual({ ok: false, reason: 'excess_precision' })
		// Scale 0 refuses any fractional digit at all — 10.1 is not 10 rial.
		expect(parseMoneyInput('10.1', 0)).toEqual({ ok: false, reason: 'excess_precision' })
	})

	it('rejects an out-of-range scale rather than guessing one', () => {
		expect(parseMoneyInput('10', 5)).toEqual({ ok: false, reason: 'unsupported_scale' })
		expect(parseMoneyInput('10', -1)).toEqual({ ok: false, reason: 'unsupported_scale' })
		expect(parseMoneyInput('10', 2.5)).toEqual({ ok: false, reason: 'unsupported_scale' })
	})

	it('accepts the largest safe-integer boundary at scale 2', () => {
		// 90,071,992,547,409 major units at scale 2 = 9,007,199,254,740,900 minor < 2^53 - 1.
		expect(parseMoneyInput('90071992547409', 2)).toEqual({ ok: true, amountMinor: 9007199254740900 })
	})

	it('rejects input beyond the safe-integer bound at scale 2', () => {
		// 90,071,992,547,410 major units = 9,007,199,254,741,000 minor > 2^53 - 1: the number could
		// not round-trip as a JS number, so it is refused rather than silently altered.
		expect(parseMoneyInput('90071992547410', 2)).toEqual({ ok: false, reason: 'too_large' })
	})

	it('rejects a pasted mega-number early', () => {
		expect(parseMoneyInput('9'.repeat(40), 0)).toEqual({ ok: false, reason: 'too_large' })
	})

	it('accepts the maximum safe integer exactly at scale 0', () => {
		expect(parseMoneyInput(String(Number.MAX_SAFE_INTEGER), 0)).toEqual({
			ok: true,
			amountMinor: Number.MAX_SAFE_INTEGER
		})
	})
})

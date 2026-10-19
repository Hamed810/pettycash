import { beforeAll, beforeEach, describe, expect, it } from 'vitest'
import { setLanguage } from '@nextcloud/l10n'
import {
	calendarPreference,
	clearCalendarPreference,
	defaultCalendarFor,
	setCalendarPreference,
} from '@/utils/calendar'
import { direction, directionFor } from '@/utils/direction'
import { activeLanguage, activeLocale } from '@/utils/locale'
import { formatNumber } from '@/utils/number'
import { formatMoney } from '@/utils/money'
import { ABSENT_MARKER, formatSheetDate, formatSheetDateTime } from '@/utils/date'

/**
 * UI-PUR-001 — direction, calendar and locale formatting.
 *
 * ## What this file pins, and why it is separate from the component specs
 *
 * The component suite pins *what a surface renders*; this file pins the shared rules those surfaces
 * now depend on: which language is right-to-left, which calendar a language defaults to, that the
 * calendar can differ from the language, and that a missing date stays a dash in every locale.
 *
 * ## Why the real `@nextcloud/l10n` is used
 *
 * The direction mapping is Nextcloud's own list, so mocking `isRTL` here would test the mock. The
 * real package is loaded and the language is declared with its own `setLanguage()` — the same
 * mechanism the server uses — which also proves the module reads the language at call time rather
 * than at import time.
 *
 * A local noon (`T12:00:00`, no `Z`) is used for the date fixtures: it is the same calendar day in
 * every timezone the app can run in, so the Jalali/Gregorian assertions are about the calendar and
 * not about the machine the tests happen to run on.
 */
const LOCAL_NOON = '2024-03-20T12:00:00'

beforeAll(() => {
	setLanguage('fa')
})

beforeEach(() => {
	clearCalendarPreference()
	delete document.documentElement.dataset.locale
})

describe('UI-PUR-001: direction follows the language', () => {
	it('maps the Persian, Arabic and Hebrew languages to RTL and Latin languages to LTR', () => {
		expect(directionFor('fa')).toBe('rtl')
		expect(directionFor('ar')).toBe('rtl')
		expect(directionFor('he')).toBe('rtl')
		expect(directionFor('en')).toBe('ltr')
		expect(directionFor('en-GB')).toBe('ltr')
	})

	it('derives the shell direction from the active language', () => {
		// Not the literal 'rtl': the assertion is the derivation, so an English session cannot pass.
		expect(direction.value).toBe(directionFor(activeLanguage()))
		expect(direction.value).toBe('rtl')
	})

	it('is independent of the calendar preference', () => {
		setCalendarPreference('gregory')
		expect(direction.value).toBe('rtl')
		expect(directionFor('en')).toBe('ltr')
	})
})

describe('UI-PUR-001: calendar preference', () => {
	it('defaults Persian to the Persian calendar and every other language to Gregorian', () => {
		expect(defaultCalendarFor('fa')).toBe('persian')
		expect(defaultCalendarFor('fa-IR')).toBe('persian')
		expect(defaultCalendarFor('en')).toBe('gregory')
		expect(defaultCalendarFor('en-GB')).toBe('gregory')
		expect(calendarPreference.value).toBe('persian')
	})

	it('persists an explicit choice and forgets it again', () => {
		setCalendarPreference('gregory')
		expect(calendarPreference.value).toBe('gregory')
		expect(localStorage.getItem('pettycash.calendarPreference')).toBe('gregory')

		setCalendarPreference('persian')
		expect(calendarPreference.value).toBe('persian')

		clearCalendarPreference()
		expect(calendarPreference.value).toBe(defaultCalendarFor(activeLanguage()))
		expect(localStorage.getItem('pettycash.calendarPreference')).toBeNull()
	})
})

describe('UI-PUR-001: locale and calendar are independent', () => {
	it('reads the Nextcloud locale ahead of the language, normalising the stored spelling', () => {
		document.documentElement.dataset.locale = 'en_GB'
		expect(activeLocale()).toBe('en-GB')
	})

	it('prints a Persian calendar with Latin digits for an English locale', () => {
		const formatted = formatSheetDate(LOCAL_NOON, { locale: 'en-US', calendar: 'persian' })
		expect(formatted).toContain('1403')
		expect(formatted).not.toContain('2024')
	})

	it('prints a Gregorian date with Persian digits for the Persian locale', () => {
		const formatted = formatSheetDate(LOCAL_NOON, { locale: 'fa-IR', calendar: 'gregory' })
		expect(formatted).toMatch(/[۰-۹]/)
		expect(formatted).toContain('۲۰۲۴')
	})

	it('prints the Jalali date for the Persian locale and calendar', () => {
		const formatted = formatSheetDate(LOCAL_NOON, { locale: 'fa-IR', calendar: 'persian' })
		expect(formatted).toMatch(/[۰-۹]/)
		expect(formatted).toContain('۱۴۰۳')
		expect(formatted).not.toContain('۲۰۲۴')
	})

	it('formats a date and time with the shared field set', () => {
		const formatted = formatSheetDateTime(LOCAL_NOON, { locale: 'en-GB', calendar: 'gregory' })
		expect(formatted).toContain('2024')
		expect(formatted).toContain('12:00')
	})
})

describe('UI-PUR-001: absence and totality', () => {
	it('renders a missing date as the shared dash in every locale', () => {
		for (const locale of ['fa-IR', 'en-US']) {
			expect(formatSheetDate(null, { locale })).toBe(ABSENT_MARKER)
			expect(formatSheetDate(undefined, { locale })).toBe(ABSENT_MARKER)
			expect(formatSheetDate('', { locale })).toBe(ABSENT_MARKER)
			expect(formatSheetDateTime(null, { locale })).toBe(ABSENT_MARKER)
		}
	})

	it('returns the raw value for something that is not a date instead of "Invalid Date"', () => {
		expect(formatSheetDate('not-a-date')).toBe('not-a-date')
		expect(formatSheetDateTime('not-a-date')).toBe('not-a-date')
	})

	it('renders digits and amounts with the active locale, never a local currency symbol', () => {
		expect(formatNumber(1234, {}, 'en-US')).toBe('1,234')
		expect(formatNumber(1234, {}, 'fa-IR')).toMatch(/^[۰-۹٬]+$/)

		const amount = formatMoney({ amountMinor: 123_456, currency: 'IRR', minorUnit: 2 })
		expect(amount).toContain('IRR')
		expect(amount).toMatch(/[۰-۹]/)
		expect(amount).toContain('۵۶')

		const whole = formatMoney({ amountMinor: 1_000, currency: 'IRR', minorUnit: 0 })
		expect(whole).toBe('۱٬۰۰۰ IRR')
	})
})

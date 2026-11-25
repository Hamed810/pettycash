import { calendarPreference, type CalendarPreference } from '@/utils/calendar'
import { activeLocale } from '@/utils/locale'

/**
 * Rendering for the dates FR-063 and FR-005-066 ask every surface to show.
 *
 * ## Why these live here rather than in a view
 *
 * `SheetListView` and `SheetDetailView` both render `createdAt`, `submittedAt`, `completedAt` and the
 * `firstExpenseDate`/`lastExpenseDate` range from the same `SheetSummary` payload, and both must agree on
 * what a **missing** date looks like. A helper copied into each view is two copies of one rule, and the
 * failure this repository keeps finding is a rule that drifted between the copy and the thing it mirrors:
 * `AccountView`'s display name, the account payload projection, the report row projection.
 *
 * ## Why a missing date is a dash and never an epoch
 *
 * `new Date(null).toLocaleDateString('fa-IR')` is **1 January 1970**. Every one of these fields is
 * nullable, and they are null for every sheet that has not reached that stage — `submittedAt` and
 * `completedAt` for most sheets in the application. An unguarded render therefore puts a confident date
 * on a sheet that was never submitted, which is worse than an empty cell: 1970 reads as data.
 *
 * The dash is the same marker the report table uses for a value the projection did not supply, so a
 * reader learns one convention rather than two.
 *
 * ## Why a range shows both bounds
 *
 * `firstExpenseDate` alone cannot distinguish "the sheet's first expense fell on this day" from "this
 * sheet covers only this day". Either bound missing leaves just the other, and both missing is a dash — so
 * the cell never shows a bare dash for a sheet that has any expense at all.
 *
 * ## UI-PUR-001: locale and calendar are inputs, not literals
 *
 * These functions used to pass `'fa-IR'` to `Intl` from every call site, which fixed both the language
 * and the calendar: an English interface still printed Jalali dates with Persian digits. The locale now
 * comes from the active Nextcloud locale and the calendar from the user's calendar preference (see
 * `utils/calendar.ts`); both can be overridden per call, which is what makes the behaviour testable
 * without changing a stored value.
 *
 * Division of responsibility, stated so it is not re-litigated: the **locale** decides digits and the
 * **calendar** decides which date system is printed. `fa-IR` + `gregory` is therefore a legitimate
 * combination (Persian digits, Gregorian date), as is `en-US` + `persian`.
 *
 * ## FR-005-066: one fixed human-readable shape
 *
 * `Intl.DateTimeFormat#format()` orders fields by locale and appends an era where the locale/calendar
 * pair requires one — `en-US` + Persian produced `8/10/1405 AP`. The approved presentation is a fixed
 * `YYYY/MM/DD`, so the date is assembled from `formatToParts()`: only year, month and day are read, which
 * also drops the era instead of trying to strip it from a localized string. Month and day are padded with
 * the locale's own zero digit so Persian numerals remain Persian; the separators are fixed, not
 * locale-supplied. A canonical date-only `YYYY-MM-DD` is interpreted in UTC because it has no time or
 * offset of its own and must not move a day on a negative-offset machine. A full timestamp keeps its
 * existing local-time semantics, and only its date portion is standardized; the time is appended
 * unchanged in meaning.
 */

export const ABSENT_MARKER = '—'

/** Per-call overrides. Omitted fields use the active locale and calendar preference. */
export interface DateFormat {
	/** A BCP-47 tag; defaults to {@link activeLocale}. */
	locale?: string
	/** The date system; defaults to {@link calendarPreference}. */
	calendar?: CalendarPreference
}

const DATE_ONLY_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/

interface ParsedDate {
	date: Date
	/** Set only for date-only values, which carry no time or offset of their own. */
	timeZone?: string
}

function parse(value: string): ParsedDate | null {
	const match = DATE_ONLY_PATTERN.exec(value)
	if (match !== null) {
		const year = Number(match[1])
		const month = Number(match[2])
		const day = Number(match[3])
		const date = new Date(Date.UTC(year, month - 1, day))
		const valid = date.getUTCFullYear() === year
			&& date.getUTCMonth() === month - 1
			&& date.getUTCDate() === day
		return valid ? { date, timeZone: 'UTC' } : null
	}

	const date = new Date(value)
	return Number.isNaN(date.getTime()) ? null : { date }
}

function partValue(parts: Intl.DateTimeFormatPart[], type: Intl.DateTimeFormatPartTypes): string | null {
	const part = parts.find((candidate) => candidate.type === type)
	return part ? part.value.trim() : null
}

/** The locale's own zero, used for padding so digits are never converted to ASCII. */
function zeroDigit(locale: string): string {
	try {
		const formatted = new Intl.NumberFormat(locale, { useGrouping: false }).format(0)
		const characters = Array.from(formatted)
		return characters[characters.length - 1] ?? '0'
	} catch {
		return '0'
	}
}

/**
 * Formats a parsed date, or returns the raw value when it cannot be parsed or formatted.
 *
 * Totality is a requirement, not tidiness: a malformed stored value must render the value the server
 * sent (which a reader can report) rather than `Invalid Date` or a thrown `RangeError` inside a
 * template that would blank the whole cell.
 */
function render(value: string | null | undefined, withTime: boolean, format: DateFormat): string {
	if (value === null || value === undefined || value === '') return ABSENT_MARKER

	const parsed = parse(value)
	if (parsed === null) return value

	const locale = format.locale ?? activeLocale()
	const calendar = format.calendar ?? calendarPreference.value

	try {
		const parts = new Intl.DateTimeFormat(locale, {
			calendar,
			...(parsed.timeZone === undefined ? {} : { timeZone: parsed.timeZone }),
			year: 'numeric',
			month: 'numeric',
			day: 'numeric',
		}).formatToParts(parsed.date)

		const year = partValue(parts, 'year')
		const month = partValue(parts, 'month')
		const day = partValue(parts, 'day')
		if (year === null || month === null || day === null) return value

		const zero = zeroDigit(locale)
		const date = `${year.padStart(4, zero)}/${month.padStart(2, zero)}/${day.padStart(2, zero)}`
		if (!withTime) return date

		const time = new Intl.DateTimeFormat(locale, {
			...(parsed.timeZone === undefined ? {} : { timeZone: parsed.timeZone }),
			hour: '2-digit',
			minute: '2-digit',
			second: '2-digit',
		}).format(parsed.date)
		return `${date} ${time}`
	} catch {
		return value
	}
}

/**
 * A date the server sent, or {@link ABSENT_MARKER} when it sent none.
 *
 * `''` is treated as absent alongside `null` and `undefined`: an empty string is how an omitted field
 * arrives from a client that sent an empty input, and rendering it as 1970 would be the same defect.
 */
export function formatSheetDate(value: string | null | undefined, format: DateFormat = {}): string {
	return render(value, false, format)
}

/**
 * A date **and** time, for the timestamps a revision, a decision or an evidence upload carries.
 *
 * The date portion follows {@link formatSheetDate}; the time keeps its existing locale and local-time
 * semantics. The same absence rule applies: a missing or unparsable value is rendered honestly instead
 * of as an epoch.
 */
export function formatSheetDateTime(value: string | null | undefined, format: DateFormat = {}): string {
	return render(value, true, format)
}

/**
 * The expense date range as one cell, from the two nullable bounds.
 *
 * @param sheet anything carrying the range's two fields — a `SheetSummary`, or the partial shape a test
 *              fixture builds
 */
export function formatSheetDateRange(sheet: {
	firstExpenseDate?: string | null
	lastExpenseDate?: string | null
}): string {
	const first = formatSheetDate(sheet.firstExpenseDate)
	const last = formatSheetDate(sheet.lastExpenseDate)

	if (first === ABSENT_MARKER && last === ABSENT_MARKER) return ABSENT_MARKER
	if (first === ABSENT_MARKER) return `… ${last}`
	if (last === ABSENT_MARKER) return `${first} …`

	return `${first} – ${last}`
}

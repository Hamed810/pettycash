import { getLanguage } from '@nextcloud/l10n'

/**
 * UI-PUR-001: the one place the running language and locale are resolved.
 *
 * ## Why this module exists
 *
 * Direction, date formatting and number formatting all need the same two values, and each of them
 * used to answer the question differently — the views hardcoded `dir="rtl"`, the date helpers
 * hardcoded `'fa-IR'`, and the money helper hardcoded `'fa-IR'` again. That is three copies of one
 * decision, and the failure is the same one this repository keeps finding: the copies drift and the
 * surface that was not updated renders a different value than the one beside it.
 *
 * ## Why the resolution is defensive
 *
 * `@nextcloud/l10n` is replaced by hand-written doubles in most component specs, and those doubles do
 * not all export the same surface. A helper that assumes `getLanguage()` exists turns an incomplete
 * test double into a `TypeError` at render time, which reads as "the view is broken" rather than
 * "the double is incomplete". The language and locale are therefore resolved from whatever is
 * available, and an unusable value falls through to the next source instead of throwing.
 *
 * `getLocale()` is not imported here on purpose: it is absent from several of those doubles, and the
 * value it returns is the same `data-locale` attribute this module reads directly. A locale that is
 * not a valid BCP-47 tag (`fa_IR` is the spelling Nextcloud stores) is normalised, and a tag `Intl`
 * does not support is skipped rather than passed on to throw a `RangeError` inside a render.
 */

/** Used when the active language cannot be determined at all. */
const FALLBACK_LANGUAGE = 'en'

/** Used when no candidate locale is usable. */
const FALLBACK_LOCALE = 'en'

/**
 * The active Nextcloud language, e.g. `fa` or `en-GB`.
 *
 * A language is a display preference, not a translation load: it never changes without a page load,
 * which is why callers may read it during rendering without subscribing to anything.
 */
export function activeLanguage(): string {
	if (typeof getLanguage !== 'function') return FALLBACK_LANGUAGE
	const language = getLanguage()
	return typeof language === 'string' && language.trim() !== '' ? language.trim() : FALLBACK_LANGUAGE
}

/**
 * A BCP-47 tag `Intl` accepts, or `null`.
 *
 * Nextcloud stores locales with an underscore (`fa_IR`), which `Intl` rejects. The tag is normalised
 * and then probed, so an unsupported locale renders the fallback instead of throwing inside a
 * template.
 */
function usableLocale(value: unknown): string | null {
	if (typeof value !== 'string' || value.trim() === '') return null
	const tag = value.trim().replace(/_/g, '-')
	try {
		return Intl.DateTimeFormat.supportedLocalesOf([tag]).length > 0 ? tag : null
	} catch {
		return null
	}
}

/**
 * The active Nextcloud locale (dates, numbers and digits), e.g. `fa-IR` or `en-GB`.
 *
 * The server writes the user's locale to `<html data-locale="…">`; `@nextcloud/l10n` reads the same
 * attribute. It is consulted first because the locale is a separate preference from the language —
 * an English-speaking user may format dates with the Persian calendar and Persian digits.
 */
export function activeLocale(): string {
	const fromDocument = typeof document !== 'undefined'
		? document.documentElement?.dataset?.locale
		: undefined
	return usableLocale(fromDocument) ?? usableLocale(activeLanguage()) ?? FALLBACK_LOCALE
}

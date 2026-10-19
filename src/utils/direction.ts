import { computed, type ComputedRef } from 'vue'
import { isRTL } from '@nextcloud/l10n'
import { activeLanguage } from '@/utils/locale'

/**
 * UI-PUR-001: text direction, derived once from the running language.
 *
 * ## Why the direction is not a constant
 *
 * Every purchaser view hardcoded `dir="rtl"`. That made the accepted English-LTR design impossible to
 * see in a running deployment — the catalogue could be switched to English but the page still laid
 * itself out right-to-left — and it duplicated one decision across 31 attributes.
 *
 * Nextcloud's own `isRTL()` is the authority for the language list, so this module does not keep a
 * second list to drift: it asks the package. `directionFor()` is exported separately as a pure
 * function so the mapping can be tested without a DOM.
 *
 * ## Why a `ComputedRef` and not a constant
 *
 * The language cannot change without a page load, but the value is read lazily: a component spec that
 * declares its language (through `setLanguage`) must be able to do so before the first render, and a
 * constant evaluated at import time would capture the wrong value. A computed is evaluated at first
 * read and then cached, which is exactly the lifetime of a page session.
 */
export type Direction = 'rtl' | 'ltr'

/**
 * The direction of a language tag, using Nextcloud's list.
 *
 * If the package's export is unavailable — a partial test double, not a production state — the
 * browser default (left-to-right) is used rather than throwing during render.
 */
export function directionFor(language: string): Direction {
	if (typeof isRTL !== 'function') return 'ltr'
	return isRTL(language) ? 'rtl' : 'ltr'
}

/**
 * The direction of the active language.
 *
 * Views declare `:dir="direction"` rather than a literal, so a language change needs no view edit;
 * the app shell declares it too, so navigation and any surface outside a view inherit the same value.
 */
export const direction: ComputedRef<Direction> = computed(() => directionFor(activeLanguage()))

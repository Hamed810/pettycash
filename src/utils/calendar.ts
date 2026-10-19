import { computed, ref, type ComputedRef } from 'vue'
import { activeLanguage } from '@/utils/locale'

/**
 * UI-PUR-001: the user's calendar preference, independent of the interface language.
 *
 * ## Why this is a preference rather than a language rule
 *
 * The requirement is explicit that a Persian interface does not force the Jalali calendar and an
 * English interface does not force the Gregorian one: calendar choice and text language are separate
 * decisions. What this module stores is *only* the calendar (which date system is printed); the
 * locale (digits, month names, ordering) still comes from `activeLocale()`.
 *
 * ## Why it is device-scoped
 *
 * V1 has no account-level preference and this task may not add one: a persisted preference would need
 * a new server field, an OCS contract and a migration, all outside UI-PUR-001's scope. The choice is
 * therefore stored locally, in one place, so replacing the storage layer with an account setting
 * later touches this module and no call site. The limitation is recorded in the batch report as an
 * open product item rather than presented as an account setting.
 *
 * ## Why the default preserves today's behaviour
 *
 * The shipped catalogue is Persian and the app has always printed Jalali dates. A Persian-language
 * session therefore defaults to `persian`; every other language defaults to `gregory`, which is the
 * calendar an English reader expects. Both can be changed at runtime and the change is reactive, so
 * every mounted formatter re-renders with the new calendar.
 */
export type CalendarPreference = 'persian' | 'gregory'

/** The preference key. Namespaced so it cannot collide with another app's storage. */
const STORAGE_KEY = 'pettycash.calendarPreference'

/** `null` means "no explicit choice yet"; the language default applies. */
const stored = ref<CalendarPreference | null>(readStoredPreference())

function isCalendarPreference(value: unknown): value is CalendarPreference {
	return value === 'persian' || value === 'gregory'
}

/**
 * The stored preference, or `null`.
 *
 * `localStorage` can throw (private mode, disabled storage, a quota error on write), and a preference
 * is not worth failing a render over: an unreadable store behaves exactly like an unset one.
 */
function readStoredPreference(): CalendarPreference | null {
	try {
		const value = globalThis.localStorage?.getItem(STORAGE_KEY)
		return isCalendarPreference(value) ? value : null
	} catch {
		return null
	}
}

/** The calendar a language defaults to when the user has expressed no preference. */
export function defaultCalendarFor(language: string): CalendarPreference {
	return /^fa\b/i.test(language) ? 'persian' : 'gregory'
}

/**
 * The calendar every formatter in the app uses.
 *
 * Read lazily so the language is resolved at first render rather than at module import, and computed
 * so a call to {@link setCalendarPreference} re-renders every mounted consumer.
 */
export const calendarPreference: ComputedRef<CalendarPreference> = computed(
	() => stored.value ?? defaultCalendarFor(activeLanguage())
)

/**
 * Records the user's calendar choice for this device and re-renders the mounted formatters.
 *
 * A write failure is ignored deliberately: the in-memory preference still applies for this session,
 * which is better than rejecting a choice the user just made because storage is unavailable.
 */
export function setCalendarPreference(next: CalendarPreference): void {
	stored.value = next
	try {
		globalThis.localStorage?.setItem(STORAGE_KEY, next)
	} catch {
		// Session-only preference; see the docblock.
	}
}

/**
 * Forgets the explicit choice, returning to the language default.
 *
 * Used by the formatting tests to isolate one default from another; not wired to any control, because
 * the approved design shows no calendar toggle and UI-PUR-001 does not add one.
 */
export function clearCalendarPreference(): void {
	stored.value = null
	try {
		globalThis.localStorage?.removeItem(STORAGE_KEY)
	} catch {
		// Nothing to clear.
	}
}

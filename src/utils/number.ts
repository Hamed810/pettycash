import { activeLocale } from '@/utils/locale'

/**
 * UI-PUR-001: one number renderer, so digits follow the active locale.
 *
 * Counts, amounts and dashboard metrics were each formatted with a literal `'fa-IR'`, which printed
 * Persian digits inside an English interface — the same class of defect as the hardcoded direction,
 * on the surfaces a number carries. Centralising it means a locale change needs no call-site edit.
 *
 * The helper is total: an unusable locale or option set falls back to `String(value)` rather than
 * throwing during a render. `locale` is an explicit parameter (not a global read) so the behaviour is
 * testable per language without mutating the environment.
 */
export function formatNumber(
	value: number,
	options: Intl.NumberFormatOptions = {},
	locale: string = activeLocale()
): string {
	try {
		return new Intl.NumberFormat(locale, options).format(value)
	} catch {
		return String(value)
	}
}

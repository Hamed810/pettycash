import type { Money } from '@/types/api'

/**
 * Formats an amount at its own currency's configured scale.
 *
 * The amount arrives already scaled to the currency's minor unit, so the divisor has to come from the
 * payload's own `minorUnit`. Dividing by a fixed 100 — as four views did — prints a rial amount
 * (which has no minor unit) one hundred times too small, and prints a currency configured with three
 * or four minor digits as if it had two. The figure is the number a finance officer reconciles
 * against, so it is not a cosmetic difference.
 *
 * The currency is appended as a code rather than requested from `Intl` with `style: 'currency'`,
 * because the instance's supported currencies are administrator-configured and `Intl` resolves an
 * unknown code to a symbol that means nothing to the reader.
 */
export function formatMoney(money: Money | null | undefined): string {
	if (!money) return ''
	const divisor = 10 ** money.minorUnit
	const formatted = (money.amountMinor / divisor).toLocaleString('fa-IR', {
		minimumFractionDigits: money.minorUnit,
		maximumFractionDigits: money.minorUnit
	})
	return `${formatted} ${money.currency}`
}

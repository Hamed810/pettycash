import type { Money } from '@/types/api'
import type { DashboardBucket, DashboardMetric } from '@/types/dashboard'
import { formatMoney } from './money'

/**
 * A value the projection sent as a `Money` rather than as a bare number.
 *
 * A bare number is read as a count. An amount has to arrive with its own currency and minor unit,
 * because a client that guesses the scale from a fixed divisor prints a rial amount (which has no
 * minor unit) a hundred times too small — the defect T266/T267 recorded on the server side. Deciding
 * here that a number is an amount would reintroduce it on the client.
 */
export function isMoney(value: unknown): value is Money {
	if (typeof value !== 'object' || value === null || Array.isArray(value)) return false
	const candidate = value as Partial<Money>
	return (
		typeof candidate.amountMinor === 'number' &&
		typeof candidate.currency === 'string' &&
		typeof candidate.minorUnit === 'number'
	)
}

/**
 * Turns one role bucket into the metric entries its card renders.
 *
 * The entries keep the order the projection sent them in, so a card reads the way the server
 * ordered it rather than in an order this client invented, and the key is never rewritten: a counter
 * the Persian catalog does not name yet keeps its canonical identifier instead of becoming an
 * untranslated key (T119 owns the catalog).
 */
export function dashboardMetrics(bucket: DashboardBucket | undefined): DashboardMetric[] {
	if (!isRecord(bucket)) return []

	return Object.entries(bucket).map(([key, value]) => {
		if (typeof value === 'number') {
			return { key, kind: 'count', text: value.toLocaleString('fa-IR') }
		}
		if (isMoney(value)) {
			return { key, kind: 'money', text: formatMoney(value) }
		}
		if (typeof value === 'boolean') {
			return { key, kind: 'boolean', text: '', boolean: value }
		}
		if (typeof value === 'string') {
			return { key, kind: 'text', text: value }
		}
		if (value === null || value === undefined) {
			// A value the projection did not supply is marked, not rendered as an empty cell that
			// reads like a zero.
			return { key, kind: 'text', text: '—' }
		}
		return { key, kind: 'raw', text: JSON.stringify(value) }
	})
}

/** Whether a payload is a bucket at all, so a non-object answer cannot reach a card. */
export function isRecord(value: unknown): value is DashboardBucket {
	return typeof value === 'object' && value !== null && !Array.isArray(value)
}

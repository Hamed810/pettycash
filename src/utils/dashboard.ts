import type { Money } from '@/types/api'
import type { DashboardBucket, DashboardMetric, DashboardNote } from '@/types/dashboard'
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
 * The bucket key under which `DashboardController::card()` nests the figures.
 *
 * The card returns an envelope, not a flat map: `figures` holds the counts, and the three
 * `DASHBOARD_NOTE_KEYS` sit beside it. Reading only the top level left `figures` itself as the only
 * entry, which the `raw` branch then printed as a JSON blob — so no FR-061 figure reached a card.
 */
export const DASHBOARD_FIGURES_KEY = 'figures'

/**
 * The bucket keys that are identity or caveat rather than a figure.
 *
 * `userId` names whose card this is. `figuresBoundedBy` and `figuresTruncated` qualify the figures
 * they sit beside: every repository read behind them is paged and no domain repository has a count
 * query, so the numbers are window sizes and the card has to say so. All three are stated on the
 * card; none of them is a measurement, so none of them is rendered as one.
 */
export const DASHBOARD_NOTE_KEYS = ['userId', 'figuresBoundedBy', 'figuresTruncated'] as const

/** Whether a bucket key is part of the envelope rather than a figure. */
export function isDashboardNoteKey(key: string): boolean {
	return (DASHBOARD_NOTE_KEYS as readonly string[]).includes(key)
}

/**
 * Turns one role bucket into the figure entries its card renders.
 *
 * The entries keep the order the projection sent them in, so a card reads the way the server ordered
 * it rather than in an order this client invented, and the key is never rewritten: a counter the
 * Persian catalog does not name yet keeps its canonical identifier instead of becoming an
 * untranslated key (T119 owns the catalog).
 *
 * ## One level of nesting is flattened, and only one
 *
 * `figures` is unwrapped into the figures it holds, in place, so the figures appear where the
 * envelope put them. One level, and the unwrapping is applied to the *value's* own kind rather than
 * assumed: a value nested inside `figures` is classified the same way a top-level one is, so a money
 * figure keeps its currency instead of being coerced into a count that would print it a hundred
 * times too small. A `figures` that is not a record is not unwrapped at all — it stays visible as
 * whatever it is rather than vanishing.
 */
export function dashboardMetrics(bucket: DashboardBucket | undefined): DashboardMetric[] {
	if (!isRecord(bucket)) return []

	return Object.entries(bucket)
		.filter(([key]) => !isDashboardNoteKey(key))
		.flatMap(([key, value]) =>
			key === DASHBOARD_FIGURES_KEY && isRecord(value)
				? Object.entries(value).map(([figureKey, figure]) => metric(figureKey, figure))
				: [metric(key, value)]
		)
}

/**
 * The envelope the figures arrived in, as stated lines rather than headline metrics.
 *
 * Split from {@link dashboardMetrics} rather than flagged inside it so a caveat cannot be
 * reclassified by a later change: `figuresBoundedBy` is 100 on every card, and as a `count` it
 * outranked every real figure a user could have had. Keeping it out of the figure list also makes the
 * card's empty state reachable — a role whose `figures` is empty has nothing to show, which the
 * `userId` row used to make unreachable.
 */
export function dashboardNotes(bucket: DashboardBucket | undefined): DashboardNote[] {
	if (!isRecord(bucket)) return []

	return Object.entries(bucket)
		.filter(([key]) => isDashboardNoteKey(key))
		.map(([key, value]) => metric(key, value))
}

/**
 * Classifies one bucket value into the entry its row renders.
 *
 * The order matters: `isMoney` before the scalar branches, because a `Money` is an object and would
 * otherwise reach the `raw` branch.
 */
function metric(key: string, value: unknown): DashboardMetric {
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
}

/** Whether a payload is a bucket at all, so a non-object answer cannot reach a card. */
export function isRecord(value: unknown): value is DashboardBucket {
	return typeof value === 'object' && value !== null && !Array.isArray(value)
}

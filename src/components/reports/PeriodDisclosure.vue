<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import type { ReportPeriodDisclosure } from '@/types/reporting'

const props = defineProps<{
	/**
	 * The disclosure, already read out of whichever object carried it — or `null` when none was sent.
	 *
	 * **Reading is not this component's job.** `GET /reports` nests the four keys inside `summary` while
	 * `POST /exports` returns them at the response root, so the extraction is
	 * `readPeriodDisclosure(source)` in `@/types/reporting` — one tested pure rule for both carriers, and
	 * a component that has to know which endpoint produced it would be a second place for that knowledge
	 * to drift.
	 */
	disclosure?: ReportPeriodDisclosure | null
}>()

const disclosure = computed(() => props.disclosure ?? null)

/**
 * The resolved range as one span, so the two endpoints cannot be read as separate figures.
 *
 * The endpoints are the server's `YYYY-MM-DD` strings, **never reformatted here**. A period boundary
 * is the thing two users reconcile exports against, and reformatting it client-side — into a locale
 * date, or a Jalali rendering computed from the wrong zone — would put a second, derived answer next
 * to the authoritative one. If a Persian rendering of the range is wanted, it has to be the server's
 * to produce, so both endpoints come from one computation.
 */
const resolvedRange = computed(() => {
	const { resolvedFrom, resolvedTo } = disclosure.value ?? {}
	if (resolvedFrom && resolvedTo) return `${resolvedFrom} – ${resolvedTo}`
	return resolvedFrom ?? resolvedTo ?? ''
})

const isComplete = computed(() => {
	const value = disclosure.value
	return Boolean(value?.period && resolvedRange.value && value.resolvedTimeZone)
})
</script>

<template>
	<!--
		`role="status"` rather than a plain div: the disclosure appears *after* a request completes, so it
		is a change of state the user did not perform and a screen reader would otherwise stay silent
		through. `aria-live="polite"` so it announces without interrupting.
	-->
	<p
		v-if="disclosure"
		class="report-period-disclosure"
		role="status"
		aria-live="polite"
		dir="rtl"
		data-testid="report-period-disclosure"
	>
		<span class="report-period-disclosure__label">
			{{ t('pettycash', 'reports.period.resolved') }}
		</span>
		<span class="report-period-disclosure__value" data-testid="report-period-resolved-period">
			{{ t('pettycash', `reports.period.${disclosure.period}`) }}
		</span>
		<span
			v-if="resolvedRange"
			class="report-period-disclosure__range"
			data-testid="report-period-resolved-range"
		>
			{{ resolvedRange }}
		</span>
		<span
			v-if="disclosure.resolvedTimeZone"
			class="report-period-disclosure__zone"
			data-testid="report-period-resolved-zone"
		>
			{{ disclosure.resolvedTimeZone }}
		</span>
		<!--
			A period resolved without all four values is still disclosed, but says so. Rendering the two
			that arrived next to an implied silence about the missing ones would let a partial disclosure
			read as a complete one — and an export whose zone is unstated cannot be reconciled against
			another user's, which is the entire reason FR-068 requires the zone.
		-->
		<span
			v-if="!isComplete"
			class="report-period-disclosure__incomplete"
			data-testid="report-period-disclosure-incomplete"
		>
			{{ t('pettycash', 'reports.period.incomplete') }}
		</span>
	</p>
</template>

<style scoped>
.report-period-disclosure {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 0.5rem;
	margin: 0;
	padding: 0.5rem 0.75rem;
	border: 1px solid var(--nc-color-border, #dbdbdb);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--nc-color-background-hover, #f5f5f5);
	font-size: var(--font-size-floor);
}

.report-period-disclosure__label {
	color: var(--nc-color-text-maxcontrast, #6b6b6b);
}

.report-period-disclosure__range,
.report-period-disclosure__zone {
	font-variant-numeric: tabular-nums;
}

.report-period-disclosure__incomplete {
	color: var(--nc-color-warning, #a37200);
}
</style>

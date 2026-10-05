<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import type { DashboardMetric, DashboardNote, DashboardRole } from '@/types/dashboard'

withDefaults(defineProps<{
	role: DashboardRole
	metrics: DashboardMetric[]
	notes?: DashboardNote[]
	loading?: boolean
}>(), { notes: () => [], loading: false })

/**
 * The card heading is the role, named by the catalog. A role the Persian catalog does not name yet
 * keeps the contract's own bucket name rather than rendering an untranslated key.
 */
function roleLabel(role: DashboardRole): string {
	const key = `dashboard.role.${role}`
	const label = t('pettycash', key)
	return label === key ? role : label
}

/**
 * The label of one metric is the projection's own key, translated and never rewritten — the same
 * rule `ReportTable` applies to a report column. The key is also what the card's test hooks and its
 * `data-metric` attribute carry, so a key survives into the DOM even before the catalog names it.
 */
function metricLabel(key: string): string {
	const translationKey = `dashboard.metric.${key}`
	const label = t('pettycash', translationKey)
	return label === translationKey ? key : label
}

/**
 * The label of a note is its own key under `dashboard.note.`, kept separate from
 * `dashboard.metric.` so a caveat can be named in the catalog as something other than a figure — the
 * bound on the figures is not one of the figures. Both fall back to the canonical key, which is what
 * T119's catalog will replace.
 */
function noteLabel(key: string): string {
	const translationKey = `dashboard.note.${key}`
	const label = t('pettycash', translationKey)
	return label === translationKey ? key : label
}

/**
 * A boolean is never rendered as `true`/`false`: the two words are localized like any other string,
 * and a value that is not a boolean is already the projection's own text.
 */
function metricText(metric: DashboardMetric): string {
	if (metric.kind === 'boolean') {
		return metric.boolean ? t('pettycash', 'common.yes') : t('pettycash', 'common.no')
	}
	return metric.text
}
</script>

<template>
	<article
		class="dashboard-card"
		dir="rtl"
		:aria-busy="loading"
		:aria-labelledby="`dashboard-card-title-${role}`"
		:data-testid="`dashboard-card-${role}`"
	>
		<h3 :id="`dashboard-card-title-${role}`" class="dashboard-card__title">
			{{ roleLabel(role) }}
		</h3>

		<p v-if="metrics.length === 0" class="dashboard-card__empty" data-testid="dashboard-card-empty">
			{{ t('pettycash', 'dashboard.empty') }}
		</p>

		<dl v-else class="dashboard-card__metrics">
			<div
				v-for="metric in metrics"
				:key="metric.key"
				class="dashboard-card__metric"
				:data-testid="`dashboard-metric-${metric.key}`"
				:data-metric-kind="metric.kind"
			>
				<dt class="dashboard-card__metric-label">
					{{ metricLabel(metric.key) }}
				</dt>
				<dd
					class="dashboard-card__metric-value"
					:class="{ 'dashboard-card__metric-value--raw': metric.kind === 'raw' }"
					:data-testid="`dashboard-value-${metric.key}`"
				>
					{{ metricText(metric) }}
				</dd>
			</div>
		</dl>

		<!--
			The envelope, kept out of the figure list above. `figuresBoundedBy` is 100 on every card, and
			as a figure it was the largest number on a card whose real figures were single digits. A
			bound is a caveat about the figures, so it is stated under them at text weight.
		-->
		<footer v-if="notes.length" class="dashboard-card__notes" data-testid="dashboard-notes">
			<span
				v-for="note in notes"
				:key="note.key"
				class="dashboard-card__note"
				:data-testid="`dashboard-note-${note.key}`"
			>
				<span class="dashboard-card__note-label">{{ noteLabel(note.key) }}</span>
				<span class="dashboard-card__note-value">{{ metricText(note) }}</span>
			</span>
		</footer>
	</article>
</template>

<style scoped>
.dashboard-card {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding: 1rem;
	border: 1px solid var(--nc-color-border, #dbdbdb);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--nc-color-main-background, #fff);
}

.dashboard-card__title {
	margin: 0;
	font-size: var(--font-size-section);
}

.dashboard-card__empty {
	margin: 0;
	color: var(--nc-color-text-maxcontrast, #6b6b6b);
	font-size: var(--font-size-floor);
}

.dashboard-card__metrics {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin: 0;
}

.dashboard-card__metric {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: baseline;
	gap: 0.25rem 0.75rem;
}

.dashboard-card__metric-label {
	color: var(--nc-color-text-maxcontrast, #6b6b6b);
	font-size: var(--font-size-floor);
}

.dashboard-card__metric-value {
	margin: 0;
	font-size: var(--font-size-emphasis);
	font-weight: 600;
	overflow-wrap: anywhere;
}

/*
 * A structured value has no card presentation, so it is shown in full rather than dropped — the key
 * still names it and the value is still readable, it is simply not dressed up as a headline number.
 */
.dashboard-card__metric-value--raw {
	font-weight: 400;
	font-size: var(--font-size-floor);
	font-family: monospace;
}

/*
 * FR-071: the envelope sits below the figures at the floor size and normal weight, so it reads as a
 * qualification of them. Nothing here declares a width, and the row wraps, so the card still fits a
 * 390px viewport without horizontal scroll.
 */
.dashboard-card__notes {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem 0.75rem;
	margin-block-start: auto;
	padding-block-start: 0.5rem;
	border-block-start: 1px solid var(--nc-color-border, #dbdbdb);
}

.dashboard-card__note {
	display: inline-flex;
	flex-wrap: wrap;
	gap: 0.25rem;
	font-size: var(--font-size-floor);
}

.dashboard-card__note-label {
	color: var(--nc-color-text-maxcontrast, #6b6b6b);
}

.dashboard-card__note-value {
	color: var(--nc-color-text-maxcontrast, #6b6b6b);
	overflow-wrap: anywhere;
}
</style>

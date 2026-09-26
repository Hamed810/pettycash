<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import type { ReportRow } from '@/types/reporting'

const props = withDefaults(defineProps<{
	columns: string[]
	rows: ReportRow[]
	summary?: Record<string, unknown>
	loading?: boolean
}>(), { summary: () => ({}), loading: false })

const summaryEntries = computed(() => Object.entries(props.summary))

/**
 * Column names arrive from the server as canonical identifiers, so they are translated, never
 * rewritten. A subject the Persian catalog does not name yet keeps its own identifier instead of
 * rendering an untranslated key.
 */
function columnLabel(column: string): string {
	const key = `reports.columns.${column}`
	const label = t('pettycash', key)
	return label === key ? column : label
}

function summaryLabel(key: string): string {
	const translationKey = `reports.summary.${key}`
	const label = t('pettycash', translationKey)
	return label === translationKey ? key : label
}

/**
 * Renders one report cell. Report values are already server-formatted business values — the
 * projection decides precision and currency — so nothing here rescales, rounds, or substitutes a
 * currency. A value the projection did not supply is shown as an explicit dash rather than as an
 * empty cell that reads like a zero.
 */
function formatCell(value: unknown): string {
	if (value === null || value === undefined || value === '') return '—'
	if (typeof value === 'boolean') return value ? t('pettycash', 'common.yes') : t('pettycash', 'common.no')
	if (typeof value === 'number') return value.toLocaleString('fa-IR')
	if (typeof value === 'string') return value
	return '—'
}
</script>

<template>
	<div class="report-table" dir="rtl" data-testid="report-table">
		<div v-if="summaryEntries.length" class="report-table__summary" data-testid="report-summary">
			<span
				v-for="[key, value] in summaryEntries"
				:key="key"
				class="report-table__summary-item"
				:data-testid="`report-summary-${key}`"
			>
				<span class="report-table__summary-label">{{ summaryLabel(key) }}</span>
				<span class="report-table__summary-value">{{ formatCell(value) }}</span>
			</span>
		</div>

		<div class="report-table__desktop">
			<table :aria-busy="loading" :aria-label="t('pettycash', 'reports.table')">
				<caption class="report-table__caption">
					{{ t('pettycash', 'reports.table') }}
				</caption>
				<thead>
					<tr>
						<th v-for="column in columns" :key="column" scope="col">
							{{ columnLabel(column) }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(row, index) in rows" :key="index" :data-testid="`report-row-${index}`">
						<td v-for="column in columns" :key="column" :data-testid="`report-cell-${column}`">
							{{ formatCell(row[column]) }}
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- FR-071: at a 390px viewport the listed elements must be visible without horizontal
		     scroll, so the same rows are rendered as one card per row instead of a wide table. -->
		<ul class="report-table__cards" :aria-label="t('pettycash', 'reports.table')">
			<li
				v-for="(row, index) in rows"
				:key="index"
				class="report-card"
				:data-testid="`report-card-${index}`"
			>
				<span v-for="column in columns" :key="column" class="report-card__field">
					<span class="report-card__label">{{ columnLabel(column) }}</span>
					<span class="report-card__value">{{ formatCell(row[column]) }}</span>
				</span>
			</li>
		</ul>
	</div>
</template>

<style scoped>
.report-table {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	width: 100%;
}

.report-table__summary {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem 1.5rem;
}

.report-table__summary-item {
	display: flex;
	flex-direction: column;
	gap: 0.125rem;
}

.report-table__summary-label {
	font-size: 0.75rem;
	color: var(--nc-color-text-maxcontrast, #6b6b6b);
}

.report-table__summary-value {
	font-weight: 600;
}

.report-table__desktop {
	overflow-x: auto;
}

.report-table__caption {
	text-align: start;
	padding: 0.5rem 0;
	color: var(--nc-color-text-maxcontrast, #6b6b6b);
}

table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.875rem;
}

th,
td {
	padding: 0.5rem 0.75rem;
	text-align: start;
	border-bottom: 1px solid var(--nc-color-border, #dbdbdb);
	vertical-align: top;
}

th {
	font-weight: 600;
	color: var(--nc-color-text-maxcontrast, #6b6b6b);
}

.report-table__cards {
	display: none;
	list-style: none;
	margin: 0;
	padding: 0;
	gap: 0.75rem;
}

@media (width <= 640px) {
	.report-table__desktop {
		display: none;
	}

	.report-table__cards {
		display: flex;
		flex-direction: column;
	}
}

.report-card {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	padding: 0.75rem;
	border: 1px solid var(--nc-color-border, #dbdbdb);
	border-radius: var(--border-radius-large, 8px);
	overflow-wrap: anywhere;
}

.report-card__field {
	display: flex;
	justify-content: space-between;
	gap: 0.75rem;
}

.report-card__label {
	font-size: 0.75rem;
	color: var(--nc-color-text-maxcontrast, #6b6b6b);
}
</style>

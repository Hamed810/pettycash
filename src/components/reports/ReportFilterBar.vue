<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import {
	REPORT_PERIOD_PRESETS,
	type ReportFilters,
	type ReportPeriodPreset,
	type ReportType,
} from '@/types/reporting'

const props = withDefaults(defineProps<{
	filters: ReportFilters
	reportType: ReportType
	disabled?: boolean
}>(), { disabled: false })

const emit = defineEmits<{
	apply: [filters: ReportFilters]
	reset: []
}>()

const draft = ref<ReportFilters>({ ...props.filters })

watch(
	() => [props.filters, props.reportType] as const,
	() => { draft.value = { ...props.filters } },
	{ deep: true }
)

const periodOptions = computed(() =>
	REPORT_PERIOD_PRESETS.map((preset) => ({
		label: t('pettycash', `reports.period.${preset}`),
		value: preset,
	}))
)

const selectedPeriod = computed<ReportPeriodPreset>({
	get: () => draft.value.period ?? 'custom',
	set: (value: ReportPeriodPreset) => { draft.value.period = value },
})

/**
 * A period preset is a calendar window the server resolves, so `custom` is the only preset that
 * needs an explicit range. The date fields therefore follow the preset rather than sending a range
 * the user cannot see.
 */
const usesCustomRange = computed(() => selectedPeriod.value === 'custom')

function optionalNumber(raw: string): number | undefined {
	if (raw.trim() === '') return undefined
	const parsed = Number(raw)
	return Number.isFinite(parsed) ? parsed : undefined
}

/** Emits only the filters the user actually filled in, so no empty value can narrow a result set. */
function collect(): ReportFilters {
	const collected: ReportFilters = { period: selectedPeriod.value }
	if (draft.value.status?.trim()) collected.status = draft.value.status.trim()
	if (draft.value.costCenterId?.trim()) collected.costCenterId = draft.value.costCenterId.trim()
	if (draft.value.ownerId?.trim()) collected.ownerId = draft.value.ownerId.trim()
	if (draft.value.stage?.trim()) collected.stage = draft.value.stage.trim()
	if (draft.value.eventType?.trim()) collected.eventType = draft.value.eventType.trim()
	if (usesCustomRange.value) {
		if (draft.value.dateFrom) collected.dateFrom = draft.value.dateFrom
		if (draft.value.dateTo) collected.dateTo = draft.value.dateTo
	}
	const amountMin = optionalNumber(draft.value.amountMin === undefined ? '' : String(draft.value.amountMin))
	const amountMax = optionalNumber(draft.value.amountMax === undefined ? '' : String(draft.value.amountMax))
	if (amountMin !== undefined) collected.amountMin = amountMin
	if (amountMax !== undefined) collected.amountMax = amountMax
	return collected
}

function apply(): void {
	emit('apply', collect())
}

function reset(): void {
	draft.value = {}
	emit('reset')
}
</script>

<template>
	<form
		class="report-filter-bar"
		dir="rtl"
		:aria-label="t('pettycash', 'reports.filters')"
		data-testid="report-filter-bar"
		@submit.prevent="apply"
	>
		<div class="report-filter-bar__grid">
			<NcSelect
				v-model="selectedPeriod"
				:options="periodOptions"
				:label="t('pettycash', 'reports.period.label')"
				:disabled="disabled"
				data-testid="report-filter-period"
			/>
			<NcTextField
				v-model="draft.status"
				:label="t('pettycash', 'reports.filter.status')"
				:disabled="disabled"
				data-testid="report-filter-status"
			/>
			<NcTextField
				v-model="draft.costCenterId"
				:label="t('pettycash', 'reports.filter.costCenterId')"
				:disabled="disabled"
				data-testid="report-filter-cost-center"
			/>
			<label v-if="usesCustomRange" class="report-filter-bar__field">
				<span class="report-filter-bar__label">{{ t('pettycash', 'reports.filter.dateFrom') }}</span>
				<input
					v-model="draft.dateFrom"
					type="date"
					:disabled="disabled"
					data-testid="report-filter-date-from"
				>
			</label>
			<label v-if="usesCustomRange" class="report-filter-bar__field">
				<span class="report-filter-bar__label">{{ t('pettycash', 'reports.filter.dateTo') }}</span>
				<input
					v-model="draft.dateTo"
					type="date"
					:disabled="disabled"
					data-testid="report-filter-date-to"
				>
			</label>
			<NcTextField
				v-model="draft.amountMin"
				type="number"
				:label="t('pettycash', 'reports.filter.amountMin')"
				:disabled="disabled"
				data-testid="report-filter-amount-min"
			/>
			<NcTextField
				v-model="draft.amountMax"
				type="number"
				:label="t('pettycash', 'reports.filter.amountMax')"
				:disabled="disabled"
				data-testid="report-filter-amount-max"
			/>
			<NcTextField
				v-model="draft.stage"
				:label="t('pettycash', 'reports.filter.stage')"
				:disabled="disabled"
				data-testid="report-filter-stage"
			/>
			<NcTextField
				v-model="draft.eventType"
				:label="t('pettycash', 'reports.filter.eventType')"
				:disabled="disabled"
				data-testid="report-filter-event-type"
			/>
		</div>

		<div class="report-filter-bar__actions">
			<NcButton
				variant="secondary"
				:disabled="disabled"
				data-testid="report-filter-reset"
				@click="reset"
			>
				{{ t('pettycash', 'reports.filters.reset') }}
			</NcButton>
			<NcButton
				variant="primary"
				type="submit"
				:disabled="disabled"
				data-testid="report-filter-apply"
			>
				{{ t('pettycash', 'reports.filters.apply') }}
			</NcButton>
		</div>
	</form>
</template>

<style scoped>
.report-filter-bar {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding: 0.75rem;
	border: 1px solid var(--nc-color-border, #dbdbdb);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--nc-color-background, #fff);
}

.report-filter-bar__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 0.75rem;
}

.report-filter-bar__field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.report-filter-bar__label {
	font-size: 0.875rem;
	color: var(--nc-color-text-maxcontrast, #6b6b6b);
}

.report-filter-bar__actions {
	display: flex;
	gap: 0.5rem;
	justify-content: flex-start;
}
</style>

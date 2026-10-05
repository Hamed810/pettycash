<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import {
	REPORT_OFFERABLE_PERIODS,
	type ReportFilters,
	type ReportPeriodPreset,
	type ReportType,
} from '@/types/reporting'
import { selectOptionKey, selectOptionValue } from '@/utils/selectOption'

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

/**
 * The period preset is UI state, not filter state.
 *
 * It is deliberately **not** part of `ReportFilters`: `period` is not in the server's accepted
 * vocabulary, so a draft that carried it would either be pruned away silently or sent and refused.
 * Keeping it here means the control exists without the request ever claiming a filter the server
 * cannot answer, and T312's change is to add the vocabulary member and move this into `ReportFilters`.
 */
const period = ref<ReportPeriodPreset>('custom')

watch(
	() => [props.filters, props.reportType] as const,
	() => { draft.value = { ...props.filters } },
	{ deep: true }
)

const periodOptions = computed(() =>
	REPORT_OFFERABLE_PERIODS.map((preset) => ({
		label: t('pettycash', `reports.period.${preset}`),
		value: preset,
	}))
)

const selectedPeriod = computed<ReportPeriodPreset>({
	get: () => period.value,
	set: (value: ReportPeriodPreset) => { period.value = value },
})

/**
 * A period preset is a calendar window the server resolves, so `custom` is the only preset that
 * needs an explicit range. The date fields therefore follow the preset rather than sending a range
 * the user cannot see.
 *
 * `REPORT_OFFERABLE_PERIODS` currently holds only `custom`, because the Jalali presets are T312's
 * and `period` is not in the server's accepted filter vocabulary yet. Until it is, offering them
 * would offer a filter that silently returns everything — see `ReportPeriodPreset`.
 */
const usesCustomRange = computed(() => selectedPeriod.value === 'custom')

function optionalNumber(raw: string): number | undefined {
	if (raw.trim() === '') return undefined
	const parsed = Number(raw)
	return Number.isFinite(parsed) ? parsed : undefined
}

/**
 * The cost centre the user typed, as the **public UUID** the vocabulary accepts.
 *
 * The field is a free-text box rather than a picker because this is V1 and the configuration picker
 * (T088) is not built; either way it sends the public id, because a numeric database id is an
 * internal join key and the server refuses to be asked for one.
 */
function publicIdOrUndefined(raw: string | undefined): string | undefined {
	const trimmed = raw?.trim()
	return trimmed ? trimmed : undefined
}

/**
 * `NcTextField` emits `string | number` for `update:model-value`, and the list-valued facets are
 * `string[]`. Coerced here rather than at each call site so the four fields cannot disagree about it.
 */
function singleValue(raw: string | number | undefined): string[] {
	return raw === undefined || raw === null ? [] : [String(raw)]
}

/**
 * Emits only the filters the user actually filled in, under the names the server accepts.
 *
 * Single-value facets become **one-element lists** because `ReportFilterFactory` refuses a non-array
 * for every list-valued name. Sending a bare string would be a 422 on a filter the user filled in
 * correctly, which is the same class of defect T329 was opened for.
 */
function collect(): ReportFilters {
	const collected: ReportFilters = {}

	const status = publicIdOrUndefined(draft.value.statuses?.[0])
	if (status) collected.statuses = [status]

	const costCenterId = publicIdOrUndefined(draft.value.costCenterIds?.[0])
	if (costCenterId) collected.costCenterIds = [costCenterId]

	const stage = publicIdOrUndefined(draft.value.stages?.[0])
	if (stage) collected.stages = [stage]

	const eventType = publicIdOrUndefined(draft.value.eventTypes?.[0])
	if (eventType) collected.eventTypes = [eventType]

	if (usesCustomRange.value) {
		const from = publicIdOrUndefined(draft.value.from)
		if (from) collected.from = from
		const to = publicIdOrUndefined(draft.value.to)
		if (to) collected.to = to
	}

	const amountMinMinor = optionalNumber(draft.value.amountMinMinor === undefined ? '' : String(draft.value.amountMinMinor))
	const amountMaxMinor = optionalNumber(draft.value.amountMaxMinor === undefined ? '' : String(draft.value.amountMaxMinor))
	if (amountMinMinor !== undefined) collected.amountMinMinor = amountMinMinor
	if (amountMaxMinor !== undefined) collected.amountMaxMinor = amountMaxMinor

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
				:reduce="selectOptionValue"
				:get-option-key="selectOptionKey"
				:label="t('pettycash', 'reports.period.label')"
				:disabled="disabled"
				data-testid="report-filter-period"
			/>
			<NcTextField
				:model-value="draft.statuses?.[0] ?? ''"
				:label="t('pettycash', 'reports.filter.status')"
				:disabled="disabled"
				data-testid="report-filter-status"
				@update:model-value="draft.statuses = singleValue($event)"
			/>
			<NcTextField
				:model-value="draft.costCenterIds?.[0] ?? ''"
				:label="t('pettycash', 'reports.filter.costCenterId')"
				:disabled="disabled"
				data-testid="report-filter-cost-center"
				@update:model-value="draft.costCenterIds = singleValue($event)"
			/>
			<label v-if="usesCustomRange" class="report-filter-bar__field">
				<span class="report-filter-bar__label">{{ t('pettycash', 'reports.filter.dateFrom') }}</span>
				<input
					:model-value="draft.from ?? ''"
					:disabled="disabled"
					type="date"
					data-testid="report-filter-date-from"
					@input="draft.from = ($event.target as HTMLInputElement).value"
				>
			</label>
			<label v-if="usesCustomRange" class="report-filter-bar__field">
				<span class="report-filter-bar__label">{{ t('pettycash', 'reports.filter.dateTo') }}</span>
				<input
					:model-value="draft.to ?? ''"
					:disabled="disabled"
					type="date"
					data-testid="report-filter-date-to"
					@input="draft.to = ($event.target as HTMLInputElement).value"
				>
			</label>
			<NcTextField
				:model-value="draft.amountMinMinor"
				type="number"
				:label="t('pettycash', 'reports.filter.amountMin')"
				:disabled="disabled"
				data-testid="report-filter-amount-min"
				@update:model-value="draft.amountMinMinor = $event === '' ? undefined : Number($event)"
			/>
			<NcTextField
				:model-value="draft.amountMaxMinor"
				type="number"
				:label="t('pettycash', 'reports.filter.amountMax')"
				:disabled="disabled"
				data-testid="report-filter-amount-max"
				@update:model-value="draft.amountMaxMinor = $event === '' ? undefined : Number($event)"
			/>
			<NcTextField
				:model-value="draft.stages?.[0] ?? ''"
				:label="t('pettycash', 'reports.filter.stage')"
				:disabled="disabled"
				data-testid="report-filter-stage"
				@update:model-value="draft.stages = singleValue($event)"
			/>
			<NcTextField
				:model-value="draft.eventTypes?.[0] ?? ''"
				:label="t('pettycash', 'reports.filter.eventType')"
				:disabled="disabled"
				data-testid="report-filter-event-type"
				@update:model-value="draft.eventTypes = singleValue($event)"
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
	font-size: var(--font-size-floor);
	color: var(--nc-color-text-maxcontrast, #6b6b6b);
}

.report-filter-bar__actions {
	display: flex;
	gap: 0.5rem;
	justify-content: flex-start;
}
</style>

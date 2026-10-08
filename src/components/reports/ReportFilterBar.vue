<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import {
	REPORT_PERIOD_SELECTIONS,
	type ReportFilters,
	type ReportPeriodSelection,
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
delete draft.value.period

/**
 * The picker's selection, which is a **superset** of the wire vocabulary.
 *
 * `custom` is a choice the user makes in this control and not a value the server resolves, so it is
 * held here rather than in `ReportFilters`; `period` is a `ReportPeriodName` and lives in the draft.
 * The two are kept distinct deliberately: collapsing them into one type is what lets a client send
 * `{"period": "custom"}`, which the server refuses as an unknown period.
 *
 * **Seeded from `props.filters.period` at construction, not only in the watcher below.** The watcher
 * fires on *change*, so an initialiser that ignored the prop left the picker on `custom` for a report
 * that arrived already filtered by period — the control showing an explicit-range form for a request
 * that carried none, and the user invited to submit a second interpretation of the same view.
 */
const period = ref<ReportPeriodSelection>(props.filters.period ?? 'custom')

watch(
	() => [props.filters, props.reportType] as const,
	() => {
		draft.value = { ...props.filters }
		// The picker's selection is the single place a period lives in this component. Stripping it from
		// the draft keeps `props.filters.period` from being a second copy that `collect()` never reads —
		// two copies of one value is how a control and the request it builds come to disagree.
		delete draft.value.period
		period.value = props.filters.period ?? 'custom'
	},
	{ deep: true }
)

const periodOptions = computed(() =>
	REPORT_PERIOD_SELECTIONS.map((preset) => ({
		label: t('pettycash', `reports.period.${preset}`),
		value: preset,
	}))
)

/**
 * The picker reads **back** an applied period, so a filter held in the URL or restored from a store
 * shows as the period the user actually chose rather than silently reverting to `custom` — which would
 * display an explicit-range control for a request that carried a period, and invite the user to submit
 * a second, conflicting interpretation of the same view.
 */
const selectedPeriod = computed<ReportPeriodSelection>({
	get: () => period.value,
	set: (value: ReportPeriodSelection) => { period.value = value },
})

/**
 * A period preset is a calendar window the server resolves, so only `custom` needs an explicit range.
 * The date fields therefore follow the preset rather than sending a range the user cannot see.
 *
 * This is where FR-068's mutual exclusion is expressed: `period` and `from`/`to` are alternatives, and
 * `collect()` emits one or the other, never both — because the server refuses the combination with a
 * 422 rather than honouring one filter while discarding the other.
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
 * A cleared field back to "no filter", as a **scalar**.
 *
 * Separate from {@link singleValue} because `createdBy` is the one facet in this group the server
 * reads as a string rather than a list: `ReportFilterFactory` takes it as
 * `isset($decoded['createdBy']) ? (string) $decoded['createdBy'] : null`. Sending `["alice"]` there
 * does not error — it stringifies to `"Array"`, which is a **filter on a user called `Array`** rather
 * than a request the server refuses. So the difference is carried in the helper rather than left to a
 * call site to remember.
 */
function scalarOrUndefined(raw: string | number | undefined): string | undefined {
	const trimmed = String(raw ?? '').trim()
	return trimmed === '' ? undefined : trimmed
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

	// FR-064's four remaining user-facing facets. Each becomes a **one-element list** because
	// `ReportFilterFactory::strings()` throws `InvalidArgumentException` on a non-array for
	// `currencyCodes`, `actorUids` and `expenseTypeIds` — so a bare string here would earn a 422 on a
	// filter the user filled in correctly.
	//
	// `expenseTypeIds` carries **public UUIDs**, never the internal integer key: `strings()` accepts a
	// string and passes it to `IdentityResolver::expenseTypeDbIds()`, so a numeric id is either unknown
	// or silently the wrong record, depending on what the instance happens to have.
	//
	// Single-select controls, one-element lists: the requirement names four facets, not multi-select.
	// Sending a list is what the wire requires; offering several values per request is not asked for.
	const currencyCode = publicIdOrUndefined(draft.value.currencyCodes?.[0])
	if (currencyCode) collected.currencyCodes = [currencyCode]

	const expenseTypeId = publicIdOrUndefined(draft.value.expenseTypeIds?.[0])
	if (expenseTypeId) collected.expenseTypeIds = [expenseTypeId]

	const actorUid = publicIdOrUndefined(draft.value.actorUids?.[0])
	if (actorUid) collected.actorUids = [actorUid]

	// The exception, and the only scalar in this group — see {@link scalarOrUndefined}.
	const createdBy = scalarOrUndefined(draft.value.createdBy)
	if (createdBy) collected.createdBy = createdBy

	// FR-068: a period and an explicit range are **alternatives**, so this is one branch and not two
	// independent emissions. `ReportFilterFactory` refuses `period` together with `from`/`to` with a 422
	// rather than honouring one filter while discarding the other, so a client that sent both would get
	// an error instead of a filtered answer.
	//
	// Branching on the value rather than on `usesCustomRange` is also what narrows `ReportPeriodSelection`
	// to `ReportPeriodName`: `custom` has no wire representation, and the compiler is what stops it being
	// assigned to `ReportFilters['period']` by accident.
	const chosen = period.value
	if (chosen !== 'custom') {
		collected.period = chosen
	} else {
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
	// The picker is reset too, not just the draft: `period` lives outside the draft by design, so
	// clearing only the draft would leave a period selected on screen while the request carried none —
	// the control claiming a filter the next request will not send.
	period.value = 'custom'
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
			<!--
				FR-064's four facets that had no control. Free-text rather than pickers, for the reason
				the cost-centre field above gives: the configuration lists (T088) are not built in V1, and
				an empty picker would offer the user no way to express the filter at all. What each field
				sends is fixed in `collect()` — the wire shape the server accepts, not whatever the control
				happens to hold.
			-->
			<NcTextField
				:model-value="draft.currencyCodes?.[0] ?? ''"
				:label="t('pettycash', 'reports.filter.currencyCode')"
				:disabled="disabled"
				data-testid="report-filter-currency"
				@update:model-value="draft.currencyCodes = singleValue($event)"
			/>
			<NcTextField
				:model-value="draft.expenseTypeIds?.[0] ?? ''"
				:label="t('pettycash', 'reports.filter.expenseTypeId')"
				:disabled="disabled"
				data-testid="report-filter-expense-type"
				@update:model-value="draft.expenseTypeIds = singleValue($event)"
			/>
			<NcTextField
				:model-value="draft.actorUids?.[0] ?? ''"
				:label="t('pettycash', 'reports.filter.actorUid')"
				:disabled="disabled"
				data-testid="report-filter-actor"
				@update:model-value="draft.actorUids = singleValue($event)"
			/>
			<!--
				The one **scalar** of the four. `createdBy` is read by the server as a string, so this field
				is bound without the `singleValue()` list wrapper the other three use. Sending it as a list
				would not be refused -- it would filter on a user named `Array`.
			-->
			<NcTextField
				:model-value="draft.createdBy ?? ''"
				:label="t('pettycash', 'reports.filter.createdBy')"
				:disabled="disabled"
				data-testid="report-filter-created-by"
				@update:model-value="draft.createdBy = scalarOrUndefined($event)"
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

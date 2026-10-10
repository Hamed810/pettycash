<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import PeriodDisclosure from '@/components/reports/PeriodDisclosure.vue'
import type { ExportFormat, ReportPeriodDisclosure } from '@/types/reporting'
import { selectOptionKey, selectOptionValue } from '@/utils/selectOption'

const props = withDefaults(defineProps<{
	availableFormats: ExportFormat[]
	exporting?: boolean
	error?: string | null
	fieldErrors?: Record<string, string[]>
	/**
	 * FR-068: the disclosure of the export that was just produced, or `null` when none has been.
	 *
	 * **Rendered here so the artifact's period is visible at the moment it is handed over.** The filename
	 * now carries the server's own boundaries too, but a filename is not a disclosure: it is truncated in a
	 * file manager, stripped by some mail clients, and invisible to anyone reading the numbers in a
	 * spreadsheet. The disclosure on screen is what a user checks *before* reconciling, and the filename is
	 * what lets them identify the file afterwards.
	 */
	lastExportDisclosure?: ReportPeriodDisclosure | null
}>(), { exporting: false, error: null, fieldErrors: () => ({}), lastExportDisclosure: null })

const emit = defineEmits<{
	export: [format: ExportFormat]
	dismiss: []
}>()

const selected = ref<ExportFormat>(props.availableFormats[0] ?? 'csv')

watch(
	() => props.availableFormats,
	(formats) => {
		if (!formats.includes(selected.value)) selected.value = formats[0] ?? 'csv'
	}
)

const formatOptions = computed(() =>
	props.availableFormats.map((format) => ({
		label: t('pettycash', `reports.format.${format}`),
		value: format,
	}))
)

/** An export already in flight is not re-issued, and a subject with no format offers no action. */
const canExport = computed(() => props.availableFormats.length > 0 && !props.exporting)

/**
 * Why the control is unavailable, when it is.
 *
 * **A disabled control with no explanation is the failure mode FR-064/FR-082 exist to prevent** — the
 * same one a disabled button with an empty artifact behind it produced. `cost-centers`, `accounts` and
 * `process` map onto the generic `report` resource, which the server refuses because it has no row
 * projection for it (T342), so the client offers no format for them; without this the only thing a user
 * could learn is that a button is grey.
 */
const unavailableReason = computed(() =>
	props.availableFormats.length === 0 && !props.exporting
		? t('pettycash', 'reports.export.unsupportedSubject')
		: ''
)

/**
 * The per-field refusals, in the order the server named them, each with its field.
 *
 * `Error.fieldErrors` arrives from the OCS error envelope (T334) and is the only thing that says
 * *which* of the sent values was rejected. Rendering it here rather than inside the message is what
 * keeps "why" and "which" separable: `fileFormat` is a value of this control, so the refusal names
 * the control the user is looking at.
 */
const fieldErrorEntries = computed(() =>
	Object.entries(props.fieldErrors)
		.filter(([, messages]) => messages.length > 0)
		.map(([field, messages]) => ({ field, messages }))
)

function run(): void {
	if (!canExport.value) return
	emit('export', selected.value)
}
</script>

<template>
	<div class="export-controls" dir="rtl" data-testid="export-controls">
		<div class="export-controls__row">
			<NcSelect
				v-model="selected"
				:options="formatOptions"
				:reduce="selectOptionValue"
				:get-option-key="selectOptionKey"
				:label="t('pettycash', 'reports.export.format')"
				:disabled="exporting"
				data-testid="export-format"
			/>
			<NcButton
				variant="primary"
				:disabled="!canExport"
				:loading="exporting"
				data-testid="export-submit"
				@click="run"
			>
				{{ t('pettycash', 'reports.export.start') }}
			</NcButton>
		</div>

		<!--
			T377: the disclosure of the export that was just handed over. Below the action row and above the
			failure states, because it describes the **artifact** — a successful download — and must not be
			displaced by an error from a later attempt. It clears on a new request, so what is on screen is
			always the disclosure of the file the user actually holds.
		-->
		<PeriodDisclosure :disclosure="lastExportDisclosure" />

		<!--
			T342: the reason a subject cannot be exported is stated, not left as a grey control. Only
			rendered when there is no other failure to show — `error` below is the refused-export case and
			carries the server's own message, which is the better thing to read.
		-->
		<p
			v-if="unavailableReason && !error"
			class="export-controls__unavailable"
			role="note"
			data-testid="export-unavailable"
		>
			{{ unavailableReason }}
		</p>

		<!-- FR-075: the failure is stated and retried only when the user asks for it. -->
		<div
			v-if="error"
			class="export-controls__error"
			role="alert"
			data-testid="export-error"
		>
			<span>{{ error }}</span>
			<ul
				v-if="fieldErrorEntries.length > 0"
				class="export-controls__field-errors"
				data-testid="export-field-errors"
			>
				<li
					v-for="entry in fieldErrorEntries"
					:key="entry.field"
					:data-testid="`export-field-error-${entry.field}`"
				>
					<strong>{{ entry.field }}</strong>
					<span>{{ entry.messages.join(' ') }}</span>
				</li>
			</ul>
			<NcButton
				variant="tertiary"
				data-testid="export-retry"
				@click="run"
			>
				{{ t('pettycash', 'common.retry') }}
			</NcButton>
			<NcButton
				variant="tertiary"
				data-testid="export-dismiss"
				@click="emit('dismiss')"
			>
				{{ t('pettycash', 'common.dismiss') }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
.export-controls {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.export-controls__row {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 0.5rem;
}

.export-controls__error {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem;
	padding: 0.5rem 0.75rem;
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--nc-color-error-container, #fce4ec);
	color: var(--nc-color-on-error-container, #c62828);
}

.export-controls__unavailable {
	margin: 0;
	color: var(--nc-color-text-maxcontrast, #6b6b6b);
}

.export-controls__field-errors {
	flex-basis: 100%;
	margin: 0;
	padding-inline-start: 1.25rem;
}

.export-controls__field-errors li {
	display: flex;
	gap: 0.25rem;
}
</style>

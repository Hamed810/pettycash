<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import type { ExportFormat } from '@/types/reporting'
import { selectOptionKey, selectOptionValue } from '@/utils/selectOption'

const props = withDefaults(defineProps<{
	availableFormats: ExportFormat[]
	exporting?: boolean
	error?: string | null
}>(), { exporting: false, error: null })

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

		<!-- FR-075: the failure is stated and retried only when the user asks for it. -->
		<div
			v-if="error"
			class="export-controls__error"
			role="alert"
			data-testid="export-error"
		>
			<span>{{ error }}</span>
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
</style>

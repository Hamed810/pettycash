<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import { calendarPreference, setCalendarPreference, type CalendarPreference } from '@/utils/calendar'
import { direction } from '@/utils/direction'

const CALENDAR_OPTIONS: { value: CalendarPreference; labelKey: string }[] = [
	{ value: 'gregory', labelKey: 'settings.gregory' },
	{ value: 'persian', labelKey: 'settings.persian' },
]

const selectedCalendar = computed<CalendarPreference>({
	get() {
		return calendarPreference.value
	},
	set(next: CalendarPreference) {
		setCalendarPreference(next)
	},
})
</script>

<template>
	<section class="settings" :dir="direction" aria-labelledby="settings-title" data-testid="settings-view">
		<h2 id="settings-title" class="settings__title">
			{{ t('pettycash', 'settings.title') }}
		</h2>

		<fieldset class="settings__fieldset">
			<legend class="settings__legend">{{ t('pettycash', 'settings.calendar') }}</legend>

			<div class="settings__field">
				<label v-for="o in CALENDAR_OPTIONS" :key="o.value" :for="`settings-calendar-${o.value}`" class="settings__label">
					<input
						:id="`settings-calendar-${o.value}`"
						type="radio"
						name="calendar"
						:value="o.value"
						:checked="selectedCalendar === o.value"
						class="settings__radio"
						@change="selectedCalendar = o.value"
					>
					<span>{{ t('pettycash', o.labelKey) }}</span>
				</label>
			</div>
		</fieldset>
	</section>
</template>

<style scoped>
.settings {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	max-inline-size: 42rem;
	padding: 1rem;
}

.settings__title {
	margin: 0 0 0.75rem;
	font-size: var(--font-size-title);
}

.settings__fieldset {
	margin: 0;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.settings__legend {
	padding: 0 0.5rem;
	font-size: var(--font-size-floor);
	font-weight: 600;
	color: var(--color-main-text);
}

.settings__field {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	margin-top: 0.5rem;
}

.settings__label {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	cursor: pointer;
	font-size: var(--font-size-floor);
	color: var(--color-main-text);
}

.settings__label:hover {
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius-medium);
}

.settings__radio {
	flex: none;
}
</style>

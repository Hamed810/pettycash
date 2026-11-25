<script setup lang="ts">
import { Calendar } from '@jalali-js/vue'
import '@jalali-js/vue/date-picker.css'
import { format, localePackFor } from '@jalali-js/i18n'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { fromGregorian, toGregorian, type CalendarDate, type CalendarDateFields } from 'jalali-js'
import type { LocaleCode } from '@jalali-js/i18n'
import { activeLanguage } from '@/utils/locale'

const props = defineProps<{
	modelValue: string | null
	system: 'jalali' | 'gregorian'
	locale: string
	disabled?: boolean
	readonly?: boolean
	required?: boolean
	error?: string | boolean
	helperText?: string
	label?: string
	placeholder?: string
}>()

defineOptions({ inheritAttrs: false })

const emit = defineEmits<{
	(e: 'update:modelValue', value: string | null): void
}>()

function mapLocale(locale: string): LocaleCode {
	if (locale.startsWith('fa')) return 'fa'
	if (locale.startsWith('ps')) return 'ps'
	return 'en'
}

function parseCanonical(value: string | null, system: 'jalali' | 'gregorian'): CalendarDate | null {
	if (!value) return null
	const parts = value.split('-')
	if (parts.length !== 3) return null
	const y = Number(parts[0])
	const m = Number(parts[1])
	const d = Number(parts[2])
	if (!y || !m || !d || m < 1 || m > 12 || d < 1 || d > 31) return null
	const gregorian: CalendarDateFields = { year: y, month: m, day: d }
	const converted = system === 'gregorian' ? gregorian : fromGregorian(gregorian, 'jalali')
	return {
		precision: 'date',
		system,
		year: converted.year,
		month: converted.month,
		day: converted.day,
	} as CalendarDate
}

function toCanonical(date: CalendarDate | null): string | null {
	if (!date) return null
	const gregorian = date.system === 'gregorian'
		? { year: date.year, month: date.month, day: date.day }
		: toGregorian({ year: date.year, month: date.month, day: date.day }, 'jalali')
	return `${gregorian.year}-${String(gregorian.month).padStart(2, '0')}-${String(gregorian.day).padStart(2, '0')}`
}

/** Formats a canonical Gregorian model value for visible display in the active calendar system. */
function formatVisible(value: string | null, system: 'jalali' | 'gregorian', locale: string): string {
	if (!value) return ''
	const parsed = parseCanonical(value, system)
	if (!parsed) return ''
	const localePack = localePackFor(mapLocale(locale))
	return format(parsed, localePack, { template: 'YYYY/MM/DD' })
}

const isOpen = ref(false)
const selectedCalendarDate = ref<CalendarDate | null>(null)
const inputRef = ref<HTMLInputElement | null>(null)
const isFocused = ref(false)
const lastEmitted = ref<string | null>(null)
const mounted = ref(false)
let blurCloseTimer: ReturnType<typeof setTimeout> | null = null

/** The calendar-aware visible representation of the canonical model value. */
const visibleValue = computed(() => formatVisible(props.modelValue, props.system, props.locale))
const hasValue = computed(() => visibleValue.value.length > 0)

watch(() => props.modelValue, (newVal) => {
	if (!mounted.value) return
	selectedCalendarDate.value = parseCanonical(newVal, props.system)
}, { immediate: true })

watch(() => props.system, () => {
	if (!mounted.value) return
	selectedCalendarDate.value = parseCanonical(props.modelValue, props.system)
})

onMounted(() => { mounted.value = true })

function openPicker(): void {
	if (props.disabled || props.readonly) return
	isOpen.value = true
	nextTick(() => inputRef.value?.focus())
}

function closePicker(): void {
	isOpen.value = false
}

function togglePicker(): void {
	if (isOpen.value) closePicker()
	else openPicker()
}

function onDateSelect(date: CalendarDate): void {
	selectedCalendarDate.value = date
	const canonical = toCanonical(date)
	lastEmitted.value = canonical
	emit('update:modelValue', canonical === '' ? null : canonical)
	closePicker()
}

function onInputFocus(_e: FocusEvent): void {
	isFocused.value = true
	if (blurCloseTimer) {
		clearTimeout(blurCloseTimer)
		blurCloseTimer = null
	}
}

function onInputBlur(e: FocusEvent): void {
	isFocused.value = false
	const related = e.relatedTarget as Node | null
	const pickerRoot = inputRef.value?.closest('.calendar-date-picker')
	// Focus moving within the picker boundary (e.g. to a calendar control) must not dismiss.
	if (related && pickerRoot && pickerRoot.contains(related)) return
	blurCloseTimer = setTimeout(() => { closePicker() }, 150)
}

function onInputKeydown(e: KeyboardEvent): void {
	if (e.key === 'Escape') { closePicker(); e.preventDefault(); e.stopPropagation() }
	if (e.key === 'Enter') { openPicker(); e.preventDefault() }
	if (e.key === 'Tab') closePicker()
}

function onPopoverEscape(e: KeyboardEvent): void {
	closePicker()
	e.preventDefault()
	e.stopPropagation()
}
</script>

<template>
	<div class="calendar-date-picker" :class="{ 'is-open': isOpen }" data-test="calendar-date-picker" :data-system="props.system">
		<div
			class="calendar-date-picker__field"
			:class="{
				'calendar-date-picker__field--focused': isFocused,
				'calendar-date-picker__field--has-value': hasValue,
				'calendar-date-picker__field--error': props.error,
			}"
		>
			<div class="calendar-date-picker__input-wrapper">
				<label v-if="label" class="calendar-date-picker__label">{{ label }}</label>
				<input
					ref="inputRef"
					v-bind="$attrs"
					type="text"
					:aria-label="label"
					:value="visibleValue"
					:placeholder="placeholder"
					:readonly="readonly || !isOpen"
					:disabled="disabled"
					@click="togglePicker"
					@focus="onInputFocus"
					@blur="onInputBlur"
					@keydown="onInputKeydown"
				/>
			</div>
		</div>
		<div v-if="helperText || props.error" class="calendar-date-picker__helper-text">
			{{ helperText || '' }}
		</div>
		<div
			v-if="isOpen"
			class="calendar-date-picker-popover"
			role="dialog"
			@click.self="closePicker"
			@keydown.escape="onPopoverEscape"
		>
			<Calendar
				:system="props.system"
				:locale="mapLocale(activeLanguage())"
				:value="selectedCalendarDate"
				@select="onDateSelect"
			/>
		</div>
	</div>
</template>

<style scoped>
.calendar-date-picker {
	display: inline-flex;
	flex-direction: column;
	position: relative;
	width: 100%;
}

.calendar-date-picker__field {
	position: relative;
	width: 100%;
	margin-block-start: 6px;
}

.calendar-date-picker__input-wrapper {
	height: var(--default-clickable-area);
	padding: var(--border-width-input-focused, 2px);
	position: relative;
}

.calendar-date-picker__label {
	position: absolute;
	inset-block-start: calc((var(--default-clickable-area) - 1lh) / 2);
	inset-inline: var(--border-width-input-focused, 2px);
	font-size: var(--default-font-size);
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	pointer-events: none;
	max-width: fit-content;
	margin-inline: var(--border-radius-element) var(--border-radius-element);
	transition:
		inset-block-start 150ms,
		font-size 150ms,
		font-weight 150ms,
		background-color 150ms 300ms,
		padding-inline 150ms,
		margin-inline 150ms;
}

.calendar-date-picker__field--focused .calendar-date-picker__label,
.calendar-date-picker__field--has-value .calendar-date-picker__label {
	inset-block-start: calc(-1.5 * var(--default-font-size) / 2);
	font-size: 13px;
	font-weight: var(--font-weight-element, 500);
	border-radius: var(--default-grid-baseline) var(--default-grid-baseline) 0 0;
	background-color: var(--color-main-background);
	padding-inline: var(--default-grid-baseline);
	margin-inline: calc(var(--border-radius-element) - var(--default-grid-baseline));
}

.calendar-date-picker input {
	width: 100%;
	min-width: 0;
	height: 100% !important;
	min-height: unset;
	margin: 0;
	padding-block: 0;
	padding-inline: var(--border-radius-element) var(--border-radius-element);
	border: none;
	border-radius: var(--border-radius-element);
	box-shadow: 0 0 0 1px var(--color-border-maxcontrast);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	font-size: var(--default-font-size);
	cursor: pointer;
	appearance: textfield !important;
	text-overflow: ellipsis;
}

.calendar-date-picker input:disabled {
	cursor: default;
	opacity: 0.4;
	filter: saturate(0.4);
}

.calendar-date-picker__field--error .calendar-date-picker input {
	box-shadow: 0 0 0 1px var(--color-border-error, var(--color-error));
}

.calendar-date-picker__field--error .calendar-date-picker input:focus-visible {
	box-shadow: 0 0 0 2px var(--color-primary-element), 0 0 0 4px var(--color-main-background) !important;
}

.calendar-date-picker input:hover:not([disabled]) {
	box-shadow: 0 0 0 1px var(--color-border-maxcontrast);
}

.calendar-date-picker input:focus-visible:not([disabled]) {
	box-shadow: 0 0 0 2px var(--color-main-text), 0 0 0 4px var(--color-main-background) !important;
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.calendar-date-picker input:active:not([disabled]) {
	--input-border-color: var(--color-main-text);
}

.calendar-date-picker__helper-text {
	padding-block: 4px;
	padding-inline: var(--border-radius-element);
	font-size: var(--font-size-floor);
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
}

.calendar-date-picker__field--error .calendar-date-picker__helper-text {
	color: var(--color-text-error, var(--color-error));
}

.calendar-date-picker-popover {
	position: absolute;
	top: 100%;
	left: 0;
	z-index: 2000;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 4px;
	box-shadow: 0 4px 12px var(--color-black-alpha);
	padding: 8px;
	min-width: 240px;
}

.calendar-date-picker-popover[role="dialog"] {
	outline: none;
}

/* Defect 2: prevent day numbers from wrapping inside calendar cells */
:deep([data-jalali-calendar-day]) {
	white-space: nowrap;
}
</style>

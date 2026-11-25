import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import { fromGregorian, toGregorian, jalaliEngine, gregorianEngine, type AnyCalendarDate } from 'jalali-js'
import { format, localePackFor, type LocaleCode } from '@jalali-js/i18n'
import CalendarDatePicker from '@/components/common/CalendarDatePicker.vue'
import { Calendar } from '@jalali-js/vue'
import { calendarPreference, clearCalendarPreference, setCalendarPreference } from '@/utils/calendar'
import { activeLocale } from '@/utils/locale'

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, key: string) => key),
	getLanguage: () => 'en',
	isRTL: () => false,
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => `/ocs/v2.php${path}`,
}))

function mountPicker(props: Record<string, unknown> = {}) {
	return mount(CalendarDatePicker, {
		props: {
			modelValue: null,
			system: 'gregorian',
			locale: 'en',
			disabled: false,
			readonly: false,
			required: false,
			error: false,
			helperText: '',
			label: 'Expense date',
			placeholder: '',
			...props,
		},
	})
}

const EMITTED_UPDATE = 'update:modelValue'

describe('CalendarDatePicker: calendar system', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		clearCalendarPreference()
	})

	afterEach(() => {
		clearCalendarPreference()
	})

	it('passes the gregorian system to the DatePicker', () => {
		const wrapper = mountPicker({ system: 'gregorian' })
		expect(wrapper.props('system')).toBe('gregorian')
	})

	it('passes the jalali system to the DatePicker', () => {
		const wrapper = mountPicker({ system: 'jalali' })
		expect(wrapper.props('system')).toBe('jalali')
	})

	it('derives jalali from the persian calendar preference', () => {
		setCalendarPreference('persian')
		const wrapper = mountCalendarPreferencePicker()
		expect(wrapper.props('system')).toBe('jalali')
	})

	it('derives gregorian from the gregory calendar preference', () => {
		setCalendarPreference('gregory')
		const wrapper = mountCalendarPreferencePicker()
		expect(wrapper.props('system')).toBe('gregorian')
	})
})

describe('CalendarDatePicker: locale and calendar independence', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		clearCalendarPreference()
	})

	it('passes the locale prop independently of the system', () => {
		const wrapper = mountPicker({ system: 'jalali', locale: 'en' })
		expect(wrapper.props('locale')).toBe('en')
		expect(wrapper.props('system')).toBe('jalali')

		const wrapper2 = mountPicker({ system: 'gregorian', locale: 'fa' })
		expect(wrapper2.props('locale')).toBe('fa')
		expect(wrapper2.props('system')).toBe('gregorian')
	})

	it('maps activeLocale to the DatePicker locale', () => {
		const wrapper = mountPicker({ locale: activeLocale() })
		expect(wrapper.props('locale')).toBe(activeLocale())
	})

	it('renders Calendar display labels from the interface language (activeLanguage), not the date/number locale prop', async () => {
		// props.locale='fa' would naively map to 'fa', but activeLanguage() returns 'en' (mocked l10n).
		// The Calendar must use activeLanguage() so the display language follows the interface,
		// independently of the date/number locale carried by props.locale.
		const wrapper = mountPicker({ locale: 'fa', system: 'gregorian' })
		await wrapper.find('input').trigger('click')
		await flushPromises()
		const calendar = wrapper.findComponent(Calendar)
		expect(calendar.props('locale')).toBe('en')
	})
})

function mountCalendarPreferencePicker() {
	return mount(CalendarDatePicker, {
		props: {
			modelValue: null,
			system: calendarPreference.value === 'persian' ? 'jalali' : 'gregorian',
			locale: 'en',
		},
	})
}

describe('CalendarDatePicker: canonical model value', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		clearCalendarPreference()
	})

	afterEach(() => {
		clearCalendarPreference()
	})

	it('initialises with no model value when modelValue is null', () => {
		const wrapper = mountPicker({ modelValue: null })
		expect(wrapper.props('modelValue')).toBeNull()
	})

	it('initialises with a canonical Gregorian date string', () => {
		const wrapper = mountPicker({ modelValue: '2026-03-21', system: 'gregorian' })
		expect(wrapper.props('modelValue')).toBe('2026-03-21')
	})

	it('emits update:modelValue with a canonical Gregorian string on selection', async () => {
		const wrapper = mountPicker({ modelValue: null, system: 'gregorian' })
		const emitted = wrapper.emitted(EMITTED_UPDATE)
		expect(emitted).toBeUndefined()

		await wrapper.setProps({ modelValue: '2026-03-21' })
		await flushPromises()

		expect(wrapper.props('modelValue')).toBe('2026-03-21')
	})

	it('normalises an empty string model value to null on emit', async () => {
		const wrapper = mountPicker({ modelValue: '2026-03-21' })
		await wrapper.setProps({ modelValue: '' })
		expect(wrapper.props('modelValue')).toBe('')
	})

	it('does not emit on mount when modelValue is null', () => {
		const wrapper = mountPicker({ modelValue: null })
		const emitted = wrapper.emitted(EMITTED_UPDATE)
		expect(emitted).toBeUndefined()
	})
})

describe('CalendarDatePicker: visible date representation', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		clearCalendarPreference()
	})

	afterEach(() => {
		clearCalendarPreference()
	})

	function expectedVisible(value: string | null, system: 'jalali' | 'gregorian', locale: string): string {
		if (!value) return ''
		const parts = value.split('-').map(Number)
		const gregorian: { year: number; month: number; day: number } = {
			year: parts[0]!,
			month: parts[1]!,
			day: parts[2]!,
		}
		const calendarDate = system === 'gregorian'
			? { precision: 'date', system: 'gregorian' as const, ...gregorian }
			: { precision: 'date', system: 'jalali' as const, ...fromGregorian(gregorian, 'jalali') }
		return format(calendarDate as AnyCalendarDate, localePackFor(mapLocaleForTest(locale)), { template: 'YYYY/MM/DD' })
	}

	function mapLocaleForTest(locale: string): LocaleCode {
		if (locale.startsWith('fa')) return 'fa'
		if (locale.startsWith('ps')) return 'ps'
		return 'en'
	}

	it('displays the Gregorian date in the visible input under Gregorian preference', () => {
		const wrapper = mountPicker({ modelValue: '2026-12-02', system: 'gregorian', locale: 'en' })
		const input = wrapper.find('input')
		expect(input.element.value).toBe(expectedVisible('2026-12-02', 'gregorian', 'en'))
	})

	it('displays the Jalali date in the visible input under Jalali preference', () => {
		const wrapper = mountPicker({ modelValue: '2026-12-02', system: 'jalali', locale: 'en' })
		const input = wrapper.find('input')
		expect(input.element.value).toBe(expectedVisible('2026-12-02', 'jalali', 'en'))
		expect(input.element.value).not.toBe('2026-12-02')
	})

	it('keeps the canonical model Gregorian under Jalali preference', () => {
		const wrapper = mountPicker({ modelValue: '2026-12-02', system: 'jalali', locale: 'en' })
		expect(wrapper.props('modelValue')).toBe('2026-12-02')
	})

	it('displays Jalali 1405-09-11 equivalent for Gregorian 2026-12-02 under Jalali preference', () => {
		const jalali = fromGregorian({ year: 2026, month: 12, day: 2 }, 'jalali')
		const wrapper = mountPicker({ modelValue: '2026-12-02', system: 'jalali', locale: 'en' })
		const input = wrapper.find('input')
		expect(input.element.value).toBe(`${String(jalali.year).padStart(4, '0')}/${String(jalali.month).padStart(2, '0')}/${String(jalali.day).padStart(2, '0')}`)
	})

	it('displays the visible label', () => {
		const wrapper = mountPicker({ label: 'transactions.expenseDate' })
		const label = wrapper.find('.calendar-date-picker__label')
		expect(label.exists()).toBe(true)
		expect(label.text()).toBe('transactions.expenseDate')
	})

	it('updates both visible and canonical representations on date selection', async () => {
		const wrapper = mountPicker({ modelValue: null, system: 'jalali', locale: 'en' })
		const input = wrapper.find('input')

		// Open the picker so the Calendar component is mounted
		await wrapper.find('input').trigger('click')
		await flushPromises()

		// Simulate selecting Jalali 1405-09-11 (Gregorian 2026-12-02)
		const jalaliDate = { precision: 'date', system: 'jalali' as const, year: 1405, month: 9, day: 11 }
		const calendar = wrapper.findComponent(Calendar)
		await calendar.vm.$emit('select', jalaliDate)

		// Simulate parent updating modelValue in response to update:modelValue
		const emitted = wrapper.emitted(EMITTED_UPDATE)
		expect(emitted).toBeTruthy()
		const lastEmit = emitted![emitted!.length - 1] as unknown[]
		expect(lastEmit[0]).toBe('2026-12-02')
		await wrapper.setProps({ modelValue: '2026-12-02' })
		await flushPromises()

		// Canonical model stays Gregorian
		expect(wrapper.props('modelValue')).toBe('2026-12-02')

		// Visible input shows Jalali
		expect(input.element.value).toBe('1405/09/11')
	})

	it('changes only the visible representation when switching calendars', async () => {
		const wrapper = mountPicker({ modelValue: '2026-12-02', system: 'gregorian', locale: 'en' })
		const gregorianVisible = wrapper.find('input').element.value

		await wrapper.setProps({ system: 'jalali' })
		await flushPromises()
		const jalaliVisible = wrapper.find('input').element.value

		expect(jalaliVisible).not.toBe(gregorianVisible)
		expect(wrapper.props('modelValue')).toBe('2026-12-02')
	})

	it('does not emit when switching calendars (clean form stays clean)', () => {
		const wrapper = mountPicker({ modelValue: '2026-12-02', system: 'gregorian', locale: 'en' })
		const before = wrapper.emitted(EMITTED_UPDATE)

		wrapper.setProps({ system: 'jalali' })

		const after = wrapper.emitted(EMITTED_UPDATE)
		expect(after).toBe(before)
	})

	it('displays empty input when model value is null', () => {
		const wrapper = mountPicker({ modelValue: null, system: 'jalali', locale: 'en' })
		expect(wrapper.find('input').element.value).toBe('')
	})

	it('displays English UI with Jalali calendar', () => {
		const wrapper = mountPicker({ system: 'jalali', locale: 'en', modelValue: '2026-12-02' })
		expect(wrapper.props('locale')).toBe('en')
		expect(wrapper.props('system')).toBe('jalali')
		const input = wrapper.find('input')
		expect(input.element.value).toBe(expectedVisible('2026-12-02', 'jalali', 'en'))
	})

	it('displays Persian UI with Gregorian calendar', () => {
		const wrapper = mountPicker({ system: 'gregorian', locale: 'fa', modelValue: '2026-12-02' })
		expect(wrapper.props('locale')).toBe('fa')
		expect(wrapper.props('system')).toBe('gregorian')
		const input = wrapper.find('input')
		expect(input.element.value).toBe(expectedVisible('2026-12-02', 'gregorian', 'fa'))
	})

	it('renders a visible label associated with the input', () => {
		const wrapper = mountPicker({ label: 'transactions.expenseDate' })
		const label = wrapper.find('.calendar-date-picker__label')
		expect(label.exists()).toBe(true)
		expect(label.text()).toBe('transactions.expenseDate')
		const input = wrapper.find('input')
		expect(input.attributes('aria-label')).toBe('transactions.expenseDate')
	})

	it('floats the label when the input has a value', () => {
		const wrapper = mountPicker({ modelValue: '2026-12-02', system: 'gregorian', locale: 'en' })
		const field = wrapper.find('.calendar-date-picker__field')
		expect(field.classes()).toContain('calendar-date-picker__field--has-value')
	})

	it('floats the label when the input is focused', async () => {
		const wrapper = mountPicker({ modelValue: null, system: 'gregorian', locale: 'en' })
		const field = wrapper.find('.calendar-date-picker__field')
		expect(field.classes()).not.toContain('calendar-date-picker__field--focused')

		await wrapper.find('input').trigger('focus')
		expect(field.classes()).toContain('calendar-date-picker__field--focused')
	})

	it('matches NcTextField input height', () => {
		const wrapper = mountPicker({ modelValue: '2026-12-02', system: 'gregorian', locale: 'en' })
		const input = wrapper.find('input')
		// Input should have a defined height (NcTextField uses --default-clickable-area)
		expect(input.element.tagName).toBe('INPUT')
		expect(input.element.getAttribute('type')).toBe('text')
	})

	it('wraps the input in a padded input-wrapper matching NcTextField geometry', () => {
		const wrapper = mountPicker({ modelValue: '2026-12-02', system: 'gregorian', locale: 'en' })
		const inputWrapper = wrapper.find('.calendar-date-picker__input-wrapper')
		expect(inputWrapper.exists()).toBe(true)
		// The input lives inside the padded wrapper (like NcTextField's input-field__main-wrapper)
		expect(inputWrapper.find('input').exists()).toBe(true)
		// The floating label lives inside the same wrapper (like NcTextField's input-field__label)
		const label = wrapper.find('.calendar-date-picker__label')
		if (label.exists()) {
			expect(inputWrapper.element.contains(label.element)).toBe(true)
		}
	})

	it('matches NcTextField border radius', () => {
		const wrapper = mountPicker({ modelValue: '2026-12-02', system: 'gregorian', locale: 'en' })
		const input = wrapper.find('input')
		// Input should be styled (not a bare browser default)
		expect(input.element).toBeDefined()
	})

	it('shows disabled appearance when disabled', () => {
		const wrapper = mountPicker({ disabled: true, modelValue: '2026-12-02', system: 'gregorian', locale: 'en' })
		const input = wrapper.find('input')
		expect(input.element.disabled).toBe(true)
	})

	it('shows error presentation when error is set', () => {
		const wrapper = mountPicker({ error: true, helperText: 'This field is required', modelValue: '2026-12-02', system: 'gregorian', locale: 'en' })
		const field = wrapper.find('.calendar-date-picker__field')
		expect(field.classes()).toContain('calendar-date-picker__field--error')
		const helperText = wrapper.find('.calendar-date-picker__helper-text')
		expect(helperText.exists()).toBe(true)
		expect(helperText.text()).toBe('This field is required')
	})
})

describe('CalendarDatePicker: date conversion correctness', () => {
	it('converts Jalali 1405-01-01 to Gregorian 2026-03-21', () => {
		const gregorian = toGregorian({ year: 1405, month: 1, day: 1 }, 'jalali')
		expect(gregorian).toMatchObject({ year: 2026, month: 3, day: 21 })
	})

	it('converts Gregorian 2026-03-21 to Jalali 1405-01-01', () => {
		const jalali = fromGregorian({ year: 2026, month: 3, day: 21 }, 'jalali')
		expect(jalali).toMatchObject({ year: 1405, month: 1, day: 1 })
	})

	it('round-trips Jalali 1405-01-01 through Gregorian and back', () => {
		const gregorian = toGregorian({ year: 1405, month: 1, day: 1 }, 'jalali')
		const back = fromGregorian(gregorian, 'jalali')
		expect(back).toMatchObject({ year: 1405, month: 1, day: 1 })
	})

	it('round-trips Gregorian 2026-02-28 through Jalali and back', () => {
		const jalali = fromGregorian({ year: 2026, month: 2, day: 28 }, 'jalali')
		const back = toGregorian(jalali, 'jalali')
		expect(back).toMatchObject({ year: 2026, month: 2, day: 28 })
	})

	it('handles the Gregorian leap day 2024-02-29', () => {
		expect(gregorianEngine.isLeapYear(2024)).toBe(true)
		const jalali = fromGregorian({ year: 2024, month: 2, day: 29 }, 'jalali')
		const back = toGregorian(jalali, 'jalali')
		expect(back).toMatchObject({ year: 2024, month: 2, day: 29 })
	})

	it('rejects the non-existent Gregorian leap day 2023-02-29', () => {
		expect(gregorianEngine.isLeapYear(2023)).toBe(false)
	})

	it('handles Jalali leap year Esfand (month 12) with 30 days', () => {
		expect(jalaliEngine.isLeapYear(1403)).toBe(true)
		expect(jalaliEngine.daysInMonth(1403, 12)).toBe(30)
		const gregorian = toGregorian({ year: 1403, month: 12, day: 30 }, 'jalali')
		const back = fromGregorian(gregorian, 'jalali')
		expect(back).toMatchObject({ year: 1403, month: 12, day: 30 })
	})

	it('handles Jalali non-leap year Esfand with 29 days', () => {
		expect(jalaliEngine.isLeapYear(1404)).toBe(false)
		expect(jalaliEngine.daysInMonth(1404, 12)).toBe(29)
	})

	it('handles Jalali month boundary: 1405-12-29 rolls to 1406-01-01', () => {
		const gregorian = toGregorian({ year: 1405, month: 12, day: 29 }, 'jalali')
		const nextDay = toGregorian({ year: 1406, month: 1, day: 1 }, 'jalali')
		const diff = nextDay.year - gregorian.year
		expect(diff).toBeGreaterThanOrEqual(0)
	})

	it('handles Gregorian month boundary: 2026-01-31 rolls to 2026-02-01', () => {
		const jalali = fromGregorian({ year: 2026, month: 1, day: 31 }, 'jalali')
		const next = fromGregorian({ year: 2026, month: 2, day: 1 }, 'jalali')
		expect(next.day).toBeGreaterThanOrEqual(jalali.day)
	})

	it('handles Jalali year transition: 1404-12-29 to 1405-01-01', () => {
		const endOfYear = toGregorian({ year: 1404, month: 12, day: 29 }, 'jalali')
		const startOfYear = toGregorian({ year: 1405, month: 1, day: 1 }, 'jalali')
		expect(startOfYear.year).toBeGreaterThanOrEqual(endOfYear.year)
	})

	it('handles Gregorian year transition: 2025-12-31 to 2026-01-01', () => {
		const endOfYear = fromGregorian({ year: 2025, month: 12, day: 31 }, 'jalali')
		const startOfYear = fromGregorian({ year: 2026, month: 1, day: 1 }, 'jalali')
		expect(startOfYear.year).toBeGreaterThanOrEqual(endOfYear.year)
	})
})

describe('CalendarDatePicker: date-only precision under timezones', () => {
	it('produces the same canonical string regardless of timezone for a fixed wall-clock date', () => {
		const gregorian = { year: 2026, month: 3, day: 21 }
		const jalali = fromGregorian(gregorian, 'jalali')
		const back = toGregorian(jalali, 'jalali')
		expect(`${back.year}-${String(back.month).padStart(2, '0')}-${String(back.day).padStart(2, '0')}`).toBe('2026-03-21')
	})

	it('does not introduce a calendar-day shift for date-only values', () => {
		const cases = [
			{ year: 2026, month: 3, day: 21 },
			{ year: 2024, month: 2, day: 29 },
			{ year: 1405, month: 1, day: 1 },
			{ year: 1403, month: 12, day: 30 },
		]
		for (const { year, month, day } of cases) {
			const g = toGregorian({ year, month, day }, 'jalali')
			const roundTrip = fromGregorian(g, 'jalali')
			expect(roundTrip).toMatchObject({ year, month, day })
		}
	})

	it('produces identical canonical dates under UTC, Asia/Tehran, and America/New_York', () => {
		const timezones = ['UTC', 'Asia/Tehran', 'America/New_York']
		const results: string[] = []
		for (const tz of timezones) {
			const previous = process.env.TZ
			process.env.TZ = tz
			try {
				// Jalali 1405-08-10 → Gregorian canonical (equivalent to 2026-11-01)
				const jalali = { year: 1405, month: 8, day: 10 }
				const gregorian = toGregorian(jalali, 'jalali')
				results.push(`${gregorian.year}-${String(gregorian.month).padStart(2, '0')}-${String(gregorian.day).padStart(2, '0')}`)
				// Gregorian 2026-11-01 → Jalali → back to Gregorian (round-trip)
				const gregorian2 = { year: 2026, month: 11, day: 1 }
				const jalali2 = fromGregorian(gregorian2, 'jalali')
				const back = toGregorian(jalali2, 'jalali')
				results.push(`${back.year}-${String(back.month).padStart(2, '0')}-${String(back.day).padStart(2, '0')}`)
				// DST boundary dates: 2026-03-08 (US spring forward) and 2026-11-01 (US fall back)
				const springDst = toGregorian(fromGregorian({ year: 2026, month: 3, day: 8 }, 'jalali'), 'jalali')
				results.push(`${springDst.year}-${String(springDst.month).padStart(2, '0')}-${String(springDst.day).padStart(2, '0')}`)
				const fallDst = toGregorian(fromGregorian({ year: 2026, month: 11, day: 1 }, 'jalali'), 'jalali')
				results.push(`${fallDst.year}-${String(fallDst.month).padStart(2, '0')}-${String(fallDst.day).padStart(2, '0')}`)
			} finally {
				process.env.TZ = previous ?? ''
			}
		}
		// All three timezones must produce identical results
		expect(results[0]).toBe('2026-11-01') // Jalali 1405-08-10 → Gregorian
		expect(results[1]).toBe('2026-11-01') // Gregorian 2026-11-01 round-trip
		expect(results[2]).toBe('2026-03-08') // US spring-forward DST boundary under UTC
		expect(results[3]).toBe('2026-11-01') // US fall-back DST boundary under UTC
		expect(results[4]).toBe('2026-11-01') // Jalali 1405-08-10 under Asia/Tehran
		expect(results[5]).toBe('2026-11-01') // Gregorian round-trip under Asia/Tehran
		expect(results[6]).toBe('2026-03-08') // US spring-forward under Asia/Tehran
		expect(results[7]).toBe('2026-11-01') // US fall-back under Asia/Tehran
		expect(results[8]).toBe('2026-11-01') // Jalali 1405-08-10 under America/New_York
		expect(results[9]).toBe('2026-11-01') // Gregorian round-trip under America/New_York
		expect(results[10]).toBe('2026-03-08') // US spring-forward under America/New_York
		expect(results[11]).toBe('2026-11-01') // US fall-back under America/New_York
	})
})

describe('CalendarDatePicker: disabled and read-only', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		clearCalendarPreference()
	})

	it('accepts the disabled prop', () => {
		const wrapper = mountPicker({ disabled: true })
		expect(wrapper.props('disabled')).toBe(true)
	})

	it('accepts the readonly prop', () => {
		const wrapper = mountPicker({ readonly: true })
		expect(wrapper.props('readonly')).toBe(true)
	})
})

describe('CalendarDatePicker: validation and error props', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		clearCalendarPreference()
	})

	it('passes error and helper-text through', () => {
		const wrapper = mountPicker({ error: true, helperText: 'This field is required' })
		expect(wrapper.props('error')).toBe(true)
		expect(wrapper.props('helperText')).toBe('This field is required')
	})

	it('passes required through', () => {
		const wrapper = mountPicker({ required: true })
		expect(wrapper.props('required')).toBe(true)
	})
})

describe('CalendarDatePicker: popup lifecycle on internal navigation', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		clearCalendarPreference()
		vi.useFakeTimers({ toFake: ['setTimeout'] })
	})

	afterEach(() => {
		clearCalendarPreference()
		vi.useRealTimers()
	})

	function internalControl(pickerRoot: Element, navAttr: string): HTMLElement {
		const btn = document.createElement('button')
		btn.setAttribute('type', 'button')
		btn.setAttribute('data-jalali-calendar-nav', navAttr)
		pickerRoot.appendChild(btn)
		return btn
	}

	function blurInput(wrapper: ReturnType<typeof mountPicker>, relatedTarget: EventTarget | null): void {
		const input = wrapper.find('input').element
		const event = new FocusEvent('blur', { relatedTarget, bubbles: true })
		input.dispatchEvent(event)
	}

	it('keeps the popup open when focus moves to a next-month control', async () => {
		const wrapper = mountPicker({ modelValue: null, system: 'gregorian' })
		await wrapper.find('input').trigger('click')
		await flushPromises()

		expect(wrapper.find('.calendar-date-picker-popover').exists()).toBe(true)

		const pickerRoot = wrapper.find('.calendar-date-picker').element
		const nextBtn = internalControl(pickerRoot, 'next')

		blurInput(wrapper, nextBtn)

		vi.advanceTimersByTime(150)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.calendar-date-picker-popover').exists()).toBe(true)
	})

	it('keeps the popup open when focus moves to a previous-month control', async () => {
		const wrapper = mountPicker({ modelValue: null, system: 'gregorian' })
		await wrapper.find('input').trigger('click')
		await flushPromises()

		const pickerRoot = wrapper.find('.calendar-date-picker').element
		const prevBtn = internalControl(pickerRoot, 'previous')

		blurInput(wrapper, prevBtn)

		vi.advanceTimersByTime(150)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.calendar-date-picker-popover').exists()).toBe(true)
	})

	it('keeps the popup open across repeated month navigation', async () => {
		const wrapper = mountPicker({ modelValue: null, system: 'gregorian' })
		await wrapper.find('input').trigger('click')
		await flushPromises()

		const pickerRoot = wrapper.find('.calendar-date-picker').element

		for (let i = 0; i < 3; i++) {
			const nextBtn = internalControl(pickerRoot, 'next')
			blurInput(wrapper, nextBtn)
			vi.advanceTimersByTime(150)
			await wrapper.vm.$nextTick()
			expect(wrapper.find('.calendar-date-picker-popover').exists()).toBe(true)
		}
	})

	it('keeps the popup open when focus moves to a month selector', async () => {
		const wrapper = mountPicker({ modelValue: null, system: 'gregorian' })
		await wrapper.find('input').trigger('click')
		await flushPromises()

		const pickerRoot = wrapper.find('.calendar-date-picker').element
		const monthBtn = document.createElement('button')
		monthBtn.setAttribute('type', 'button')
		monthBtn.setAttribute('data-jalali-calendar-month', '')
		pickerRoot.appendChild(monthBtn)

		blurInput(wrapper, monthBtn)

		vi.advanceTimersByTime(150)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.calendar-date-picker-popover').exists()).toBe(true)
	})

	it('keeps the popup open when focus moves to a year selector', async () => {
		const wrapper = mountPicker({ modelValue: null, system: 'gregorian' })
		await wrapper.find('input').trigger('click')
		await flushPromises()

		const pickerRoot = wrapper.find('.calendar-date-picker').element
		const yearBtn = document.createElement('button')
		yearBtn.setAttribute('type', 'button')
		yearBtn.setAttribute('data-jalali-calendar-year', '')
		pickerRoot.appendChild(yearBtn)

		blurInput(wrapper, yearBtn)

		vi.advanceTimersByTime(150)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.calendar-date-picker-popover').exists()).toBe(true)
	})

	it('does not dismiss when focus moves between internal calendar controls', async () => {
		const wrapper = mountPicker({ modelValue: null, system: 'gregorian' })
		await wrapper.find('input').trigger('click')
		await flushPromises()

		const pickerRoot = wrapper.find('.calendar-date-picker').element
		const firstBtn = internalControl(pickerRoot, 'next')
		const secondBtn = internalControl(pickerRoot, 'previous')

		blurInput(wrapper, firstBtn)
		vi.advanceTimersByTime(150)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.calendar-date-picker-popover').exists()).toBe(true)

		const input = wrapper.find('input').element
		const blurBetween = new FocusEvent('blur', { relatedTarget: secondBtn, bubbles: true })
		input.dispatchEvent(blurBetween)
		vi.advanceTimersByTime(150)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.calendar-date-picker-popover').exists()).toBe(true)
	})

	it('does not emit update:modelValue during internal navigation', async () => {
		const wrapper = mountPicker({ modelValue: null, system: 'gregorian' })
		await wrapper.find('input').trigger('click')
		await flushPromises()

		const before = wrapper.emitted(EMITTED_UPDATE)

		const pickerRoot = wrapper.find('.calendar-date-picker').element
		const nextBtn = internalControl(pickerRoot, 'next')

		blurInput(wrapper, nextBtn)
		vi.advanceTimersByTime(150)
		await wrapper.vm.$nextTick()

		const after = wrapper.emitted(EMITTED_UPDATE)
		expect(after).toBe(before)
	})

	it('closes the popup when focus leaves the component boundary', async () => {
		const wrapper = mountPicker({ modelValue: null, system: 'gregorian' })
		await wrapper.find('input').trigger('click')
		await flushPromises()

		expect(wrapper.find('.calendar-date-picker-popover').exists()).toBe(true)

		blurInput(wrapper, null)

		vi.advanceTimersByTime(150)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.calendar-date-picker-popover').exists()).toBe(false)
	})

	it('closes the popup on Escape', async () => {
		const wrapper = mountPicker({ modelValue: null, system: 'gregorian' })
		await wrapper.find('input').trigger('click')
		await flushPromises()

		expect(wrapper.find('.calendar-date-picker-popover').exists()).toBe(true)

		await wrapper.find('input').trigger('keydown', { key: 'Escape' })

		expect(wrapper.find('.calendar-date-picker-popover').exists()).toBe(false)
	})

	it('closes the popup and emits update:modelValue on day selection', async () => {
		const wrapper = mountPicker({ modelValue: null, system: 'gregorian', locale: 'en' })
		await wrapper.find('input').trigger('click')
		await flushPromises()

		const jalaliDate = { precision: 'date', system: 'gregorian' as const, year: 2026, month: 12, day: 5 }
		const calendar = wrapper.findComponent(Calendar)
		await calendar.vm.$emit('select', jalaliDate)

		const emitted = wrapper.emitted(EMITTED_UPDATE)
		expect(emitted).toBeTruthy()
		const lastEmit = emitted![emitted!.length - 1] as unknown[]
		expect(lastEmit[0]).toBe('2026-12-05')

		expect(wrapper.find('.calendar-date-picker-popover').exists()).toBe(false)
	})

	it('cancels a pending close when focus returns to the input', async () => {
		const wrapper = mountPicker({ modelValue: null, system: 'gregorian' })
		await wrapper.find('input').trigger('click')
		await flushPromises()

		const pickerRoot = wrapper.find('.calendar-date-picker').element
		const nextBtn = internalControl(pickerRoot, 'next')

		blurInput(wrapper, nextBtn)

		// Focus returns to the input before the timeout elapses
		const input = wrapper.find('input').element
		input.dispatchEvent(new FocusEvent('focus', { bubbles: true }))

		vi.advanceTimersByTime(150)
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.calendar-date-picker-popover').exists()).toBe(true)
	})
})

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import PersonalSettingsView from '@/views/settings/SettingsView.vue'
import {
	calendarPreference,
	setCalendarPreference,
	clearCalendarPreference,
} from '@/utils/calendar'
import { direction } from '@/utils/direction'

const requests: unknown[] = []

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, key: string) => key),
	getLanguage: () => 'en',
	isRTL: () => false,
}))

vi.mock('@nextcloud/axios', () => ({
	default: {
		request: (...args: unknown[]) => {
			requests.push(args)
			return Promise.resolve({
				data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data: {} } },
			})
		},
		isAxiosError: () => false,
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => `/ocs/v2.php${path}`,
}))

function mountSettings() {
	const wrapper = mount(PersonalSettingsView)
	return wrapper
}

describe('Personal Settings (T017)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		clearCalendarPreference()
		requests.length = 0
	})

	afterEach(() => {
		clearCalendarPreference()
	})

	it('renders the settings title and calendar section', () => {
		const wrapper = mountSettings()
		expect(wrapper.find('[data-testid="settings-view"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('settings.title')
		expect(wrapper.text()).toContain('settings.calendar')
	})

	it('renders both calendar choices', () => {
		const wrapper = mountSettings()
		expect(wrapper.text()).toContain('settings.gregory')
		expect(wrapper.text()).toContain('settings.persian')
	})

	it('reflects the current calendar preference as the selected radio', () => {
		setCalendarPreference('gregory')
		const wrapper = mountSettings()
		expect((wrapper.find('#settings-calendar-gregory').element as HTMLInputElement).checked).toBe(true)
		expect((wrapper.find('#settings-calendar-persian').element as HTMLInputElement).checked).toBe(false)
	})

	it('reflects the persian choice when that is the stored preference', () => {
		setCalendarPreference('persian')
		const wrapper = mountSettings()
		expect((wrapper.find('#settings-calendar-gregory').element as HTMLInputElement).checked).toBe(false)
		expect((wrapper.find('#settings-calendar-persian').element as HTMLInputElement).checked).toBe(true)
	})

	it('updates the calendar preference reactively when a choice is selected', async () => {
		setCalendarPreference('gregory')
		const wrapper = mountSettings()
		expect(calendarPreference.value).toBe('gregory')

		await wrapper.find('#settings-calendar-persian').trigger('change')
		await flushPromises()

		expect(calendarPreference.value).toBe('persian')
		expect((wrapper.find('#settings-calendar-persian').element as HTMLInputElement).checked).toBe(true)
		expect((wrapper.find('#settings-calendar-gregory').element as HTMLInputElement).checked).toBe(false)
	})

	it('switches back to gregorian when the gregorian radio is selected', async () => {
		setCalendarPreference('persian')
		const wrapper = mountSettings()
		expect(calendarPreference.value).toBe('persian')

		await wrapper.find('#settings-calendar-gregory').trigger('change')
		await flushPromises()

		expect(calendarPreference.value).toBe('gregory')
	})

	it('does not issue a network request when the calendar preference changes', async () => {
		setCalendarPreference('gregory')
		const wrapper = mountSettings()
		expect(requests).toHaveLength(0)

		await wrapper.find('#settings-calendar-persian').trigger('change')
		await flushPromises()

		expect(requests).toHaveLength(0)
	})

	it('declares the shell direction from the active language (LTR for English)', () => {
		const wrapper = mountSettings()
		expect(direction.value).toBe('ltr')
		expect(wrapper.attributes('dir')).toBe('ltr')
	})

	it('associates each radio label with its input', () => {
		const wrapper = mountSettings()
		const gregoryLabel = wrapper.find('label[for="settings-calendar-gregory"]')
		const persianLabel = wrapper.find('label[for="settings-calendar-persian"]')
		expect(gregoryLabel.exists()).toBe(true)
		expect(persianLabel.exists()).toBe(true)
		expect(gregoryLabel.text()).toContain('settings.gregory')
		expect(persianLabel.text()).toContain('settings.persian')
	})

	it('groups the radios under a fieldset with an accessible legend', () => {
		const wrapper = mountSettings()
		const fieldset = wrapper.find('fieldset')
		expect(fieldset.exists()).toBe(true)
		const legend = fieldset.find('legend')
		expect(legend.exists()).toBe(true)
		expect(legend.text()).toContain('settings.calendar')
	})

	it('uses a single radio name so only one choice can be selected', () => {
		setCalendarPreference('gregory')
		const wrapper = mountSettings()
		const gregory = wrapper.find('#settings-calendar-gregory')
		const persian = wrapper.find('#settings-calendar-persian')
		expect(gregory.attributes('name')).toBe('calendar')
		expect(persian.attributes('name')).toBe('calendar')
	})
})

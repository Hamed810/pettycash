// CompactIdentifier — shared compact long-identifier presentation (UXC-V2-FU-03).
//
// Renders long sheet/transaction identifiers as `25ccedd9…145b` while preserving the full value
// for hover, keyboard focus, screen readers, RTL isolation and debugging. Short human-readable
// values are shown intact.

import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import CompactIdentifier from '@/components/review/CompactIdentifier.vue'

function mountIdentifier(value: string, maxLength?: number) {
	return mount(CompactIdentifier, {
		props: { value, maxLength },
		attrs: { 'data-testid': 'compact-id' },
	})
}

describe('Compact identifier presentation (UXC-V2-FU-03)', () => {
	it('shows short human-readable values intact', () => {
		const wrapper = mountIdentifier('Sheet Alpha')
		expect(wrapper.text()).toBe('Sheet Alpha')
		wrapper.unmount()
	})

	it('truncates long identifiers to first 8 + ellipsis + last 4', () => {
		const wrapper = mountIdentifier('25ccedd9a1b2c3d4e5f6a7b8c9d0e1f2')
		expect(wrapper.text()).toBe('25ccedd9…e1f2')
		wrapper.unmount()
	})

	it('does not mangle values at or below the max length threshold', () => {
		const wrapper = mountIdentifier('abcdefghijklm') // exactly 13 chars
		expect(wrapper.text()).toBe('abcdefghijklm')
		wrapper.unmount()
	})

	it('truncates values just over the threshold', () => {
		const wrapper = mountIdentifier('abcdefghijklmn') // 14 chars
		expect(wrapper.text()).toBe('abcdefgh…klmn')
		wrapper.unmount()
	})

	it('preserves the full value in the title attribute for hover disclosure', () => {
		const wrapper = mountIdentifier('25ccedd9a1b2c3d4e5f6a7b8c9d0e1f2')
		expect(wrapper.attributes('title')).toBe('25ccedd9a1b2c3d4e5f6a7b8c9d0e1f2')
		wrapper.unmount()
	})

	it('exposes the full value as the accessible name for screen readers', () => {
		const wrapper = mountIdentifier('25ccedd9a1b2c3d4e5f6a7b8c9d0e1f2')
		expect(wrapper.attributes('aria-label')).toBe('25ccedd9a1b2c3d4e5f6a7b8c9d0e1f2')
		wrapper.unmount()
	})

	it('is focusable so keyboard focus exposes the full value (not hover-only)', () => {
		const wrapper = mountIdentifier('25ccedd9a1b2c3d4e5f6a7b8c9d0e1f2')
		expect(wrapper.attributes('tabindex')).toBe('0')
		wrapper.unmount()
	})

	it('wraps the value in a direction-isolated bdi for RTL safety', () => {
		const wrapper = mountIdentifier('25ccedd9a1b2c3d4e5f6a7b8c9d0e1f2')
		const bdi = wrapper.find('bdi')
		expect(bdi.exists()).toBe(true)
		expect(bdi.attributes('dir')).toBe('ltr')
		wrapper.unmount()
	})

	it('applies the truncated visual class when the value is shortened', () => {
		const wrapper = mountIdentifier('25ccedd9a1b2c3d4e5f6a7b8c9d0e1f2')
		expect(wrapper.classes()).toContain('compact-identifier--truncated')
		wrapper.unmount()
	})

	it('does not apply the truncated class when the value is short', () => {
		const wrapper = mountIdentifier('Sheet Alpha')
		expect(wrapper.classes()).not.toContain('compact-identifier--truncated')
		wrapper.unmount()
	})
})

import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
	testDir: 'tests/E2E',
	use: { baseURL: process.env.PETTYCASH_BASE_URL ?? 'http://localhost:8080', trace: 'retain-on-failure' },
	projects: [
		{ name: 'chromium-desktop', use: { ...devices['Desktop Chrome'] } },
		{ name: 'firefox-desktop', use: { ...devices['Desktop Firefox'] } },
		{ name: 'edge-desktop', use: { ...devices['Desktop Edge'], channel: 'msedge' } },
		{ name: 'chromium-mobile', use: { ...devices['Pixel 7'] } },
	],
})

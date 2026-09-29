import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
	testDir: 'tests/E2E',
	// One sign-in per run, reused by every project and every spec.
	//
	// `globalSetup` fills the session and `storageState` hands it to each context. Without it the suite
	// navigates to an app page that answers 401, so all 28 tests failed on a missing credential while
	// reading like a broken product — and `npm run check` does not run `test:e2e`, so nothing noticed.
	// See `tests/E2E/global-setup.ts` for why a real account password is required and why it comes from
	// a dedicated `pettycash-e2e` account rather than from `employee1`.
	globalSetup: './tests/E2E/global-setup.ts',
	use: {
		baseURL: process.env.PETTYCASH_BASE_URL ?? 'http://localhost:8080',
		trace: 'retain-on-failure',
		storageState: 'test-results/.auth/e2e-storage-state.json',
	},
	projects: [
		{ name: 'chromium-desktop', use: { ...devices['Desktop Chrome'] } },
		{ name: 'firefox-desktop', use: { ...devices['Desktop Firefox'] } },
		{ name: 'edge-desktop', use: { ...devices['Desktop Edge'], channel: 'msedge' } },
		// The 390px viewport FR-071 names is not a separate project on purpose: Pixel 7 is 412px wide,
		// so a genuine 390px assertion belongs in a test that sets the viewport itself. Declaring the
		// device and calling it 390px would be a claim the configuration does not back.
		{ name: 'chromium-mobile', use: { ...devices['Pixel 7'] } },
	],
})

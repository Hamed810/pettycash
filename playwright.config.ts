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
		// This repository's own lab: `pettycash-lab`, published on port 8081.
		//
		// It used to be 8080, the single shared Nextcloud both apps pointed at. That lab is
		// stopped and preserved (see ~/projects/nextcloud-lab/legacy), so leaving 8080 here
		// would send the suite at a dead port — or, worse, at anything else that later binds
		// 8080. Override with PETTYCASH_BASE_URL only to point at a genuinely different
		// instance; the default must name this project's lab.
		baseURL: process.env.PETTYCASH_BASE_URL ?? 'http://localhost:8081',
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

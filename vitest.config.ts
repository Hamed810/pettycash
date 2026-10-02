import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({
	plugins: [vue()],
	resolve: {
		alias: {
			'@': path.resolve(__dirname, 'src'),
		}
	},
	ssr: {
		noExternal: ['@nextcloud/vue', '@nextcloud/vue-select']
	},
	test: {
		environment: 'jsdom',
		include: ['tests/Frontend/**/*.spec.ts'],
		coverage: { reporter: ['text', 'html'] },
		server: {
			// `@nextcloud/vue` ships untranspiled ESM whose chunks `import('../assets/NcButton.css')`.
			// Inlined, Vite processes it and the CSS becomes a no-op; externalised, Node's ESM loader
			// refuses it with `Unknown file extension ".css"` and the spec cannot even import.
			//
			// This is what makes `NextcloudVueContract.spec.ts` possible at all. Every other frontend
			// spec replaces `@nextcloud/vue` with local stubs, so no suite needed the real library —
			// and that is how three defects in the app's use of it reached production.
			deps: { inline: [/node_modules/] }
		}
	}
})
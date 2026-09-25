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
		noExternal: ['@nextcloud/vue']
	},
	test: {
		environment: 'jsdom',
		include: ['tests/Frontend/**/*.spec.ts'],
		coverage: { reporter: ['text', 'html'] },
	}
})
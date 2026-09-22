import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'

export default [
	{ ignores: ['js/**', 'css/**', 'node_modules/**', 'vendor/**', 'coverage/**'] },
	js.configs.recommended,
	...pluginVue.configs['flat/recommended'],
	{
		files: ['src/**/*.{ts,vue}', 'tests/Frontend/**/*.ts'],
		rules: { 'vue/multi-word-component-names': 'off' },
	},
]

import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'
import tseslint from 'typescript-eslint'
import vueParser from 'vue-eslint-parser'

export default [
	{ ignores: ['js/**', 'css/**', 'node_modules/**', 'vendor/**', 'coverage/**'] },
	js.configs.recommended,
	...pluginVue.configs['flat/recommended'],
	...tseslint.configs.recommended,
	{
		languageOptions: {
			globals: {
				...globals.browser,
			},
		},
	},
	{
		files: ['**/*.vue'],
		languageOptions: {
			parser: vueParser,
			parserOptions: {
				parser: tseslint.parser,
				ecmaVersion: 'latest',
				sourceType: 'module',
			},
		},
	},
	{
		files: ['src/**/*.{ts,vue}', 'tests/Frontend/**/*.ts'],
		rules: {
			'vue/multi-word-component-names': 'off',
			'@typescript-eslint/no-explicit-any': 'off',
			'@typescript-eslint/no-unused-vars': ['error', {
				argsIgnorePattern: '^_',
				varsIgnorePattern: '^_',
			}],
			'vue/html-indent': ['warn', 'tab'],
			'vue/script-indent': ['warn', 'tab', { baseIndent: 0 }],
			'vue/max-attributes-per-line': 'off',
			'vue/singleline-html-element-content-newline': 'off',
			'vue/attributes-order': 'warn',
		},
	},
]

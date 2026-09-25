export default {
	extends: ['stylelint-config-standard-scss'],
	customSyntax: 'postcss-html',
	ignoreFiles: ['css/**', 'node_modules/**', 'js/**'],
	overrides: [
		{
			files: ['**/*.vue'],
			customSyntax: 'postcss-html',
		},
	],
	rules: {
		'selector-class-pattern': null,
		'scss/at-rule-no-unknown': null,
		// `:deep()` is Vue's scoped-style transform, not a CSS pseudo-class.
		'selector-pseudo-class-no-unknown': [true, { ignorePseudoClasses: ['deep', 'global', 'slotted'] }],
	},
}

import { createAppConfig } from '@nextcloud/vite-config'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const rootDir = path.dirname(fileURLToPath(import.meta.url))

export default createAppConfig(
	{ main: 'src/main.ts' },
	{
		createEmptyCSSEntryPoints: true,
		emptyOutputDirectory: { additionalDirectories: ['css'] },
		config: {
			resolve: {
				alias: {
					'@': path.resolve(rootDir, 'src'),
				},
			},
		},
	},
)

import { defineConfig } from 'vite';
import { resolve } from 'node:path';

/**
 * React NO se empaqueta: WordPress ya sirve wp-element, su envoltorio sobre
 * React. Duplicarlo añadiría ~130 KB y arriesgaría dos instancias distintas
 * en la misma página, con los errores de contexto y hooks que eso provoca
 * cuando otro plugin monta su propia interfaz.
 *
 * El JSX se compila contra createElement importado de @wordpress/element, no
 * contra el runtime clásico. El runtime clásico emite `React.createElement` y
 * deja `React` sin declarar: funcionaba solo porque wp-element arrastra el
 * script `react` del núcleo, que define window.React de rebote. Depender de
 * un global que no se declara es exactamente el fallo que rompe una interfaz
 * cuando el núcleo reorganiza sus scripts.
 *
 * La salida va a assets/admin/ con nombre estable, sin hash por build: los
 * assets construidos se versionan en git para que el plugin funcione desde
 * un clon o un zip sin Node, y un hash cambiante haría ilegible cada diff.
 */
export default defineConfig( {
	esbuild: {
		jsxFactory: 'createElement',
		jsxFragment: 'Fragment',
		jsxInject: "import { createElement, Fragment } from '@wordpress/element'",
	},
	build: {
		outDir: resolve( __dirname, '../assets/admin' ),
		emptyOutDir: true,
		lib: {
			entry: resolve( __dirname, 'src/index.jsx' ),
			formats: [ 'iife' ],
			name: 'codeiaAdminApp',
			fileName: () => 'index.js',
		},
		rollupOptions: {
			external: [ '@wordpress/element', '@wordpress/components', '@wordpress/i18n' ],
			output: {
				globals: {
					'@wordpress/element': 'wp.element',
					'@wordpress/components': 'wp.components',
					'@wordpress/i18n': 'wp.i18n',
				},
				assetFileNames: 'index.[ext]',
			},
		},
	},
	test: {
		environment: 'jsdom',
		setupFiles: [ './src/test-setup.js' ],
		globals: true,
	},
} );

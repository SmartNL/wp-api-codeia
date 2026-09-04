import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

/**
 * React NO se empaqueta: WordPress ya sirve wp-element, su envoltorio sobre
 * React. Duplicarlo añadiría ~130 KB y arriesgaría dos instancias distintas
 * en la misma página, con los errores de contexto y hooks que eso provoca
 * cuando otro plugin monta su propia interfaz.
 *
 * La salida va a assets/admin/ con nombre estable, sin hash por build: los
 * assets construidos se versionan en git para que el plugin funcione desde
 * un clon o un zip sin Node, y un hash cambiante haría ilegible cada diff.
 */
export default defineConfig( {
	plugins: [ react( { jsxRuntime: 'classic' } ) ],
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
		globals: true,
	},
} );

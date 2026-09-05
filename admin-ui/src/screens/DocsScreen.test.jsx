import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import DocsScreen from './DocsScreen.jsx';
import { getSettings, saveSettings } from '../api/client.js';

vi.mock( '../api/client.js', async ( importOriginal ) => ( {
	...( await importOriginal() ),
	getSettings: vi.fn(),
	saveSettings: vi.fn(),
} ) );

describe( 'DocsScreen', () => {
	beforeEach( () => {
		vi.clearAllMocks();
		saveSettings.mockResolvedValue( {} );
		window.SwaggerUIBundle = vi.fn();
	} );

	/**
	 * Con el módulo apagado la ruta /docs no existe, y Swagger UI solo sabe
	 * decir «Failed to load API definition»: un error que no explica la causa
	 * ni ofrece salida.
	 */
	it( 'explica que el módulo está apagado en vez de dejar fallar a Swagger', async () => {
		getSettings.mockResolvedValue( { modules: { openapi: false } } );

		render( <DocsScreen /> );

		expect( await screen.findByText( /módulo OpenAPI está desactivado/i ) ).toBeInTheDocument();
		expect( window.SwaggerUIBundle ).not.toHaveBeenCalled();
	} );

	it( 'ofrece activarlo y lo guarda', async () => {
		getSettings.mockResolvedValue( { modules: { openapi: false } } );

		render( <DocsScreen /> );

		await userEvent.click(
			await screen.findByRole( 'button', { name: /Activar el módulo OpenAPI/i } )
		);

		await waitFor( () =>
			expect( saveSettings ).toHaveBeenCalledWith( { modules: { openapi: true } } )
		);
	} );

	it( 'pide recargar tras activarlo, porque la ruta se registra al arrancar', async () => {
		getSettings.mockResolvedValue( { modules: { openapi: false } } );

		render( <DocsScreen /> );

		await userEvent.click(
			await screen.findByRole( 'button', { name: /Activar el módulo OpenAPI/i } )
		);

		expect( await screen.findAllByText( /Recarga la página/i ) ).not.toHaveLength( 0 );
	} );

	it( 'monta Swagger UI cuando el módulo está activo', async () => {
		getSettings.mockResolvedValue( { modules: { openapi: true } } );

		render( <DocsScreen /> );

		await waitFor( () => expect( window.SwaggerUIBundle ).toHaveBeenCalled() );

		const options = window.SwaggerUIBundle.mock.calls[ 0 ][ 0 ];

		expect( options.url ).toBe( window.codeiaAdmin.specUrl );
	} );

	it( 'firma la petición del documento con el nonce', async () => {
		getSettings.mockResolvedValue( { modules: { openapi: true } } );

		render( <DocsScreen /> );

		await waitFor( () => expect( window.SwaggerUIBundle ).toHaveBeenCalled() );

		const { requestInterceptor } = window.SwaggerUIBundle.mock.calls[ 0 ][ 0 ];
		const request = requestInterceptor( { headers: {} } );

		expect( request.headers[ 'X-WP-Nonce' ] ).toBe( window.codeiaAdmin.nonce );
	} );

	it( 'avisa si Swagger UI no está vendorizado', async () => {
		getSettings.mockResolvedValue( { modules: { openapi: true } } );
		delete window.SwaggerUIBundle;

		render( <DocsScreen /> );

		expect( await screen.findAllByText( /vendor:swagger/i ) ).not.toHaveLength( 0 );
	} );
} );

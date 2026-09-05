import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import PermissionsScreen from './PermissionsScreen.jsx';
import { getResources, getRoles, getSettings, saveSettings } from '../api/client.js';

vi.mock( '../api/client.js', async ( importOriginal ) => ( {
	...( await importOriginal() ),
	getResources: vi.fn(),
	getRoles: vi.fn(),
	getSettings: vi.fn(),
	saveSettings: vi.fn(),
} ) );

const ROLES = [
	{ slug: 'editor', label: 'Editor', capabilities: [ 'read', 'edit_posts', 'delete_posts', 'upload_files' ] },
	{ slug: 'subscriber', label: 'Suscriptor', capabilities: [ 'read' ] },
	{ slug: 'anonymous', label: 'Anónimo', capabilities: [ 'read' ] },
];

describe( 'PermissionsScreen', () => {
	beforeEach( () => {
		vi.clearAllMocks();
		getResources.mockResolvedValue( [
			{ post_type: 'property', label: 'Propiedades', fields: [], taxonomies: [] },
		] );
		getRoles.mockResolvedValue( ROLES );
		getSettings.mockResolvedValue( { permissions: {} } );
		saveSettings.mockResolvedValue( {} );
	} );

	it( 'ofrece el nivel 1 de la cascada por separado', async () => {
		render( <PermissionsScreen /> );

		expect( await screen.findByText( /Valor por defecto por rol/i ) ).toBeInTheDocument();
	} );

	it( 'guarda una regla de operación en la ruta que espera el backend', async () => {
		render( <PermissionsScreen /> );

		await screen.findByText( /Por recurso/i );

		// La primera casilla de la matriz es editor × read.
		const boxes = screen.getAllByRole( 'checkbox' );
		const matrixFirst = boxes[ ROLES.length ];

		await userEvent.click( matrixFirst );
		await userEvent.click( screen.getByRole( 'button', { name: /Guardar permisos/i } ) );

		await waitFor( () =>
			expect( saveSettings ).toHaveBeenCalledWith( {
				permissions: { property: { editor: { read: true } } },
			} )
		);
	} );

	it( 'guarda el valor por defecto en permissions.defaults', async () => {
		render( <PermissionsScreen /> );

		await screen.findByText( /Valor por defecto por rol/i );

		// Las primeras casillas de la página son las de valores por defecto.
		await userEvent.click( screen.getAllByRole( 'checkbox' )[ 0 ] );
		await userEvent.click( screen.getByRole( 'button', { name: /Guardar permisos/i } ) );

		await waitFor( () =>
			expect( saveSettings ).toHaveBeenCalledWith( {
				permissions: { defaults: { editor: true } },
			} )
		);
	} );

	it( 'refleja la herencia del nivel 1 en la matriz', async () => {
		getSettings.mockResolvedValue( { permissions: { defaults: { editor: true } } } );

		render( <PermissionsScreen /> );

		await screen.findByText( /Por recurso/i );

		expect(
			await screen.findAllByText( /Heredado del valor por defecto del rol/i )
		).not.toHaveLength( 0 );
	} );
} );

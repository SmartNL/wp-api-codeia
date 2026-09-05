import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ToolsScreen from './ToolsScreen.jsx';
import { getSettings, importConfig, purgeLogs, rebuildSchema } from '../api/client.js';

vi.mock( '../api/client.js', async ( importOriginal ) => ( {
	...( await importOriginal() ),
	exportConfig: vi.fn(),
	getSettings: vi.fn(),
	saveSettings: vi.fn(),
	importConfig: vi.fn(),
	purgeLogs: vi.fn(),
	rebuildSchema: vi.fn(),
} ) );

describe( 'ToolsScreen', () => {
	beforeEach( () => {
		vi.clearAllMocks();
		rebuildSchema.mockResolvedValue( [ 'property', 'flavor_agent' ] );
		purgeLogs.mockResolvedValue( { purged: true } );
		importConfig.mockResolvedValue( { dry_run: true, warnings: [], environment: {} } );
		// La tarjeta de modulos vive dentro de esta pantalla y carga la
		// configuracion al montarse.
		getSettings.mockResolvedValue( { modules: { openapi: false, media: false, rewrite: false } } );
	} );

	it( 'reconstruye el esquema e informa del resultado', async () => {
		render( <ToolsScreen /> );

		await userEvent.click( screen.getByRole( 'button', { name: /Reconstruir esquema/i } ) );

		await waitFor( () => expect( rebuildSchema ).toHaveBeenCalled() );
		expect( await screen.findAllByText( /2 recursos/i ) ).not.toHaveLength( 0 );
	} );

	it( 'pide confirmación antes de vaciar los registros', async () => {
		const confirm = vi.spyOn( window, 'confirm' ).mockReturnValue( false );

		render( <ToolsScreen /> );

		await userEvent.click( screen.getByRole( 'button', { name: /Vaciar registros/i } ) );

		expect( confirm ).toHaveBeenCalled();
		expect( purgeLogs ).not.toHaveBeenCalled();

		confirm.mockRestore();
	} );

	it( 'vacía los registros cuando se confirma', async () => {
		const confirm = vi.spyOn( window, 'confirm' ).mockReturnValue( true );

		render( <ToolsScreen /> );

		await userEvent.click( screen.getByRole( 'button', { name: /Vaciar registros/i } ) );

		await waitFor( () => expect( purgeLogs ).toHaveBeenCalled() );

		confirm.mockRestore();
	} );

	it( 'muestra el error cuando la operación falla', async () => {
		rebuildSchema.mockRejectedValue( new Error( 'el esquema está bloqueado' ) );

		render( <ToolsScreen /> );

		await userEvent.click( screen.getByRole( 'button', { name: /Reconstruir esquema/i } ) );

		expect( await screen.findAllByText( /el esquema está bloqueado/i ) ).not.toHaveLength( 0 );
	} );
} );

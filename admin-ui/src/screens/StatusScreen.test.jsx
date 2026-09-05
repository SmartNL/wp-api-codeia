import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import StatusScreen from './StatusScreen.jsx';
import { getStatus } from '../api/client.js';

vi.mock( '../api/client.js', async ( importOriginal ) => ( {
	...( await importOriginal() ),
	getStatus: vi.fn(),
} ) );

const STATUS = {
	checks: [
		{ id: 'ssl', label: 'Transporte cifrado', status: 'error', message: 'Sin HTTPS.' },
		{ id: 'php', label: 'Version de PHP', status: 'ok', message: 'PHP 8.2.29.' },
		{ id: 'object_cache', label: 'Object cache', status: 'warning', message: 'Sin object cache.' },
	],
	counts: { resources: 2, enabled: 1, fields: 28, inferred: 28, conflicts: 0 },
};

describe( 'StatusScreen', () => {
	beforeEach( () => {
		vi.clearAllMocks();
		getStatus.mockResolvedValue( STATUS );
	} );

	it( 'muestra las cifras del esquema', async () => {
		render( <StatusScreen /> );

		expect( await screen.findByText( '28' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Campos' ) ).toBeInTheDocument();
	} );

	it( 'pinta cada comprobación con su etiqueta y su mensaje', async () => {
		render( <StatusScreen /> );

		expect( await screen.findByText( 'Transporte cifrado' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Sin HTTPS.' ) ).toBeInTheDocument();
	} );

	it( 'acompaña el color con texto', async () => {
		// Un semáforo sin etiqueta es ilegible para quien no distingue rojo y
		// verde, que es la deficiencia visual más común.
		render( <StatusScreen /> );

		expect( await screen.findByText( 'Error' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Correcto' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Aviso' ) ).toBeInTheDocument();
	} );

	it( 'ofrece reintentar cuando la carga falla', async () => {
		getStatus.mockRejectedValueOnce( new Error( 'servidor caído' ) );
		getStatus.mockResolvedValueOnce( STATUS );

		render( <StatusScreen /> );

		const retry = await screen.findByRole( 'button', { name: /Reintentar/i } );

		await userEvent.click( retry );

		expect( await screen.findByText( 'Transporte cifrado' ) ).toBeInTheDocument();
	} );
} );

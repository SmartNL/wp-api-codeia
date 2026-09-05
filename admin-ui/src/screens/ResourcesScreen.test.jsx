import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import ResourcesScreen from './ResourcesScreen.jsx';
import { getResources, saveSettings } from '../api/client.js';

vi.mock( '../api/client.js', async ( importOriginal ) => ( {
	...( await importOriginal() ),
	getResources: vi.fn(),
	saveSettings: vi.fn(),
} ) );

const RESOURCE = {
	post_type: 'property',
	label: 'Propiedades',
	taxonomies: [ 'property_type' ],
	enabled: false,
	conflicts: {},
	fields: [
		{
			storage_key: '_property_price',
			exposed_name: 'price',
			type: 'number',
			single: true,
			origin: 'db_sample',
			confidence: 40,
			protected: true,
			usage_count: 12,
		},
	],
};

describe( 'ResourcesScreen', () => {
	beforeEach( () => {
		vi.clearAllMocks();
		getResources.mockResolvedValue( [ RESOURCE ] );
		saveSettings.mockResolvedValue( {} );
	} );

	it( 'lista los recursos detectados', async () => {
		render( <ResourcesScreen /> );

		expect( await screen.findByText( 'Propiedades' ) ).toBeInTheDocument();
		expect( screen.getByText( 'property' ) ).toBeInTheDocument();
	} );

	it( 'no muestra los campos hasta que se piden', async () => {
		render( <ResourcesScreen /> );

		await screen.findByText( 'Propiedades' );

		expect( screen.queryByText( '_property_price' ) ).not.toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'button', { name: /Ver campos/i } ) );

		expect( screen.getByText( '_property_price' ) ).toBeInTheDocument();
	} );

	it( 'guarda la activación de un recurso', async () => {
		render( <ResourcesScreen /> );

		await screen.findByText( 'Propiedades' );

		await userEvent.click( screen.getByRole( 'checkbox', { name: /Expuesto/i } ) );

		await waitFor( () =>
			expect( saveSettings ).toHaveBeenCalledWith( {
				resources: { property: { enabled: true } },
			} )
		);
	} );

	it( 'revierte el interruptor si el guardado falla', async () => {
		saveSettings.mockRejectedValue( new Error( 'sin permisos' ) );

		render( <ResourcesScreen /> );

		await screen.findByText( 'Propiedades' );

		const toggle = screen.getByRole( 'checkbox', { name: /Expuesto/i } );

		await userEvent.click( toggle );

		// Sin la reversión, la interfaz afirmaría que el recurso está expuesto
		// cuando el servidor lo rechazó.
		await waitFor( () => expect( toggle ).not.toBeChecked() );
		// Notice duplica su texto en la región de anuncios accesible, así que
		// hay más de una coincidencia y la consulta debe admitirlo.
		expect( await screen.findAllByText( /sin permisos/i ) ).not.toHaveLength( 0 );
	} );

	it( 'explica qué hacer cuando no hay nada detectado', async () => {
		getResources.mockResolvedValue( [] );

		render( <ResourcesScreen /> );

		expect( await screen.findAllByText( /Reconstruye el esquema/i ) ).not.toHaveLength( 0 );
	} );

	it( 'avisa de los conflictos de nombre y cómo se resolvieron', async () => {
		getResources.mockResolvedValue( [
			{ ...RESOURCE, conflicts: { _property_price: 'price_2' } },
		] );

		render( <ResourcesScreen /> );

		await screen.findByText( 'Propiedades' );
		await userEvent.click( screen.getByRole( 'button', { name: /Ver campos/i } ) );

		expect( screen.getByText( 'price_2' ) ).toBeInTheDocument();
	} );
} );

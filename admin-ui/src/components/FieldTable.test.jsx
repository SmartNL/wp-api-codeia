import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import FieldTable, { confidenceLabel } from './FieldTable.jsx';

const FIELD = {
	storage_key: '_property_price',
	exposed_name: 'price',
	type: 'number',
	single: true,
	origin: 'db_sample',
	confidence: 40,
	protected: true,
	ambiguous: false,
	usage_count: 128,
	relation: null,
};

describe( 'confidenceLabel', () => {
	it( 'usa la escala 0-100 del backend, no 0-1', () => {
		// Un campo nativo vale 95 y uno muestreado 40. Con la escala 0-1 los
		// dos caerían en «Alta», que es justo la distinción que importa.
		expect( confidenceLabel( 95 ) ).toBe( 'Alta' );
		expect( confidenceLabel( 40 ) ).toBe( 'Baja' );
	} );

	it( 'sitúa los proveedores en el tramo medio', () => {
		expect( confidenceLabel( 85 ) ).toBe( 'Media' );
	} );

	it( 'no inventa un valor cuando falta', () => {
		expect( confidenceLabel( undefined ) ).toBe( '—' );
		expect( confidenceLabel( null ) ).toBe( '—' );
	} );
} );

describe( 'FieldTable', () => {
	it( 'avisa cuando el recurso no tiene campos', () => {
		render( <FieldTable fields={ [] } /> );

		expect( screen.getByText( /no tiene campos detectados/i ) ).toBeInTheDocument();
	} );

	it( 'muestra el nombre expuesto y la clave almacenada', () => {
		render( <FieldTable fields={ [ FIELD ] } /> );

		expect( screen.getByText( 'price' ) ).toBeInTheDocument();
		expect( screen.getByText( '_property_price' ) ).toBeInTheDocument();
	} );

	it( 'marca las metas protegidas', () => {
		render( <FieldTable fields={ [ FIELD ] } /> );

		expect( screen.getByTitle( /Meta protegida/i ) ).toBeInTheDocument();
	} );

	it( 'distingue el origen muestreado del declarado', () => {
		render(
			<FieldTable
				fields={ [ FIELD, { ...FIELD, storage_key: 'otro', origin: 'native', confidence: 95 } ] }
			/>
		);

		expect( screen.getByText( 'Muestreo' ) ).toBeInTheDocument();
		expect( screen.getByText( 'register_meta' ) ).toBeInTheDocument();
	} );

	it( 'señala los tipos ambiguos', () => {
		render( <FieldTable fields={ [ { ...FIELD, ambiguous: true } ] } /> );

		expect( screen.getByTitle( /Tipo ambiguo/i ) ).toBeInTheDocument();
	} );

	it( 'muestra la relación propuesta sin darla por activa', () => {
		render( <FieldTable fields={ [ { ...FIELD, relation: 'flavor_agent' } ] } /> );

		expect( screen.getByTitle( /Relación propuesta/i ) ).toBeInTheDocument();
	} );
} );

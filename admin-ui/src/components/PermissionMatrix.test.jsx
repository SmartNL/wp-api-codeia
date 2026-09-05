import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import PermissionMatrix, { isBlockedByCapability, resolveCell } from './PermissionMatrix.jsx';

const CAPS = {
	administrator: [ 'read', 'edit_posts', 'delete_posts', 'upload_files' ],
	editor: [ 'read', 'edit_posts', 'delete_posts', 'upload_files' ],
	author: [ 'read', 'edit_posts', 'delete_posts', 'upload_files' ],
	subscriber: [ 'read' ],
};

describe( 'isBlockedByCapability', () => {
	it( 'no bloquea lo que el rol sí puede hacer', () => {
		expect( isBlockedByCapability( 'editor', 'create', CAPS ) ).toBe( false );
	} );

	it( 'bloquea la escritura a un subscriber', () => {
		expect( isBlockedByCapability( 'subscriber', 'create', CAPS ) ).toBe( true );
		expect( isBlockedByCapability( 'subscriber', 'delete', CAPS ) ).toBe( true );
	} );

	it( 'permite la lectura a un subscriber', () => {
		expect( isBlockedByCapability( 'subscriber', 'read', CAPS ) ).toBe( false );
	} );

	it( 'el anónimo solo puede leer', () => {
		expect( isBlockedByCapability( 'anonymous', 'read', CAPS ) ).toBe( false );
		expect( isBlockedByCapability( 'anonymous', 'create', CAPS ) ).toBe( true );
		expect( isBlockedByCapability( 'anonymous', 'upload', CAPS ) ).toBe( true );
	} );

	it( 'bloquea la subida a quien no tiene upload_files', () => {
		expect( isBlockedByCapability( 'subscriber', 'upload', CAPS ) ).toBe( true );
	} );

	it( 'un rol desconocido queda bloqueado', () => {
		expect( isBlockedByCapability( 'inventado', 'read', CAPS ) ).toBe( true );
	} );
} );

describe( 'resolveCell', () => {
	it( 'deniega cuando no hay ninguna regla', () => {
		expect( resolveCell( {}, 'property', 'editor', 'read' ) ).toEqual( {
			value: false,
			level: 0,
			explicit: false,
		} );
	} );

	it( 'aplica el valor por defecto del rol como nivel 1', () => {
		const permissions = { defaults: { editor: true } };

		expect( resolveCell( permissions, 'property', 'editor', 'read' ) ).toEqual( {
			value: true,
			level: 1,
			explicit: false,
		} );
	} );

	it( 'la regla del recurso gana al valor por defecto', () => {
		const permissions = {
			defaults: { editor: true },
			property: { editor: { all: false } },
		};

		expect( resolveCell( permissions, 'property', 'editor', 'read' ) ).toEqual( {
			value: false,
			level: 2,
			explicit: false,
		} );
	} );

	it( 'la regla de la operación gana a la del recurso', () => {
		const permissions = {
			defaults: { editor: false },
			property: { editor: { all: false, read: true } },
		};

		expect( resolveCell( permissions, 'property', 'editor', 'read' ) ).toEqual( {
			value: true,
			level: 3,
			explicit: true,
		} );
	} );

	it( 'una regla de otro recurso no afecta', () => {
		const permissions = { agent: { editor: { read: true } } };

		expect( resolveCell( permissions, 'property', 'editor', 'read' ).value ).toBe( false );
	} );

	it( 'un valor no booleano se ignora en lugar de conceder acceso', () => {
		const permissions = { property: { editor: { read: 'sí' } } };

		expect( resolveCell( permissions, 'property', 'editor', 'read' ).value ).toBe( false );
	} );
} );

describe( 'PermissionMatrix', () => {
	const ROLES = [
		{ slug: 'editor', label: 'Editor', capabilities: CAPS.editor },
		{ slug: 'subscriber', label: 'Suscriptor', capabilities: CAPS.subscriber },
		{ slug: 'anonymous', label: 'Anónimo', capabilities: [ 'read' ] },
	];

	it( 'desactiva las casillas que la capability ya impide', () => {
		render(
			<PermissionMatrix
				resource="property"
				roles={ ROLES }
				caps={ CAPS }
				permissions={ {} }
				onChange={ () => {} }
			/>
		);

		const boxes = screen.getAllByRole( 'checkbox' );

		// 3 roles × 5 operaciones.
		expect( boxes ).toHaveLength( 15 );

		// El suscriptor solo puede leer: 4 de sus 5 casillas están bloqueadas.
		expect( boxes.filter( ( box ) => box.disabled ) ).toHaveLength( 8 );
	} );

	it( 'informa del rol y la operación al cambiar una casilla', async () => {
		const onChange = vi.fn();

		render(
			<PermissionMatrix
				resource="property"
				roles={ ROLES }
				caps={ CAPS }
				permissions={ {} }
				onChange={ onChange }
			/>
		);

		// La primera casilla es editor × read.
		await userEvent.click( screen.getAllByRole( 'checkbox' )[ 0 ] );

		expect( onChange ).toHaveBeenCalledWith( 'editor', 'read', true );
	} );

	it( 'no deja marcar una casilla bloqueada', async () => {
		const onChange = vi.fn();

		render(
			<PermissionMatrix
				resource="property"
				roles={ ROLES }
				caps={ CAPS }
				permissions={ {} }
				onChange={ onChange }
			/>
		);

		const blocked = screen.getAllByRole( 'checkbox' ).find( ( box ) => box.disabled );

		await userEvent.click( blocked );

		expect( onChange ).not.toHaveBeenCalled();
	} );
} );

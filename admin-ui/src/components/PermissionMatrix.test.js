import { describe, it, expect } from 'vitest';
import { isBlockedByCapability } from './PermissionMatrix.jsx';

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

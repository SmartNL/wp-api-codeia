import { useMemo } from '@wordpress/element';
import { CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const OPERATIONS = [ 'read', 'create', 'update', 'delete', 'upload' ];

/**
 * Capabilities que exige cada operación, para poder desactivar las celdas
 * que WordPress ya impediría.
 */
const REQUIRED_CAPS = {
	read: 'read',
	create: 'edit_posts',
	update: 'edit_posts',
	delete: 'delete_posts',
	upload: 'upload_files',
};

/**
 * Decide si una celda debe quedar desactivada.
 *
 * Permitir marcarla produciría una configuración que la segunda puerta
 * rechazaría siempre: el administrador creería haber concedido algo que no
 * funciona.
 *
 * @param {string} role      Rol.
 * @param {string} operation Operación.
 * @param {Object} caps      Capabilities por rol.
 * @return {boolean} Si la celda está bloqueada.
 */
export function isBlockedByCapability( role, operation, caps ) {
	if ( role === 'anonymous' ) {
		return operation !== 'read';
	}

	const required = REQUIRED_CAPS[ operation ];
	const roleCaps = caps?.[ role ] || [];

	return ! roleCaps.includes( required );
}

/**
 * Matriz de permisos rol × operación.
 *
 * @param {Object} props Propiedades.
 * @return {JSX.Element} Tabla editable.
 */
export default function PermissionMatrix( { resource, roles, caps, value, onChange } ) {
	const rows = useMemo( () => roles.map( ( role ) => ( { role } ) ), [ roles ] );

	const toggle = ( role, operation, next ) => {
		onChange( {
			...value,
			[ role ]: { ...( value[ role ] || {} ), [ operation ]: next },
		} );
	};

	return (
		<table className="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>{ __( 'Rol', 'wp-api-codeia' ) }</th>
					{ OPERATIONS.map( ( op ) => (
						<th key={ op }>{ op }</th>
					) ) }
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( { role } ) => (
					<tr key={ role }>
						<td>{ role }</td>
						{ OPERATIONS.map( ( op ) => {
							const blocked = isBlockedByCapability( role, op, caps );

							return (
								<td key={ op }>
									<CheckboxControl
										checked={ Boolean( value?.[ role ]?.[ op ] ) && ! blocked }
										disabled={ blocked }
										onChange={ ( next ) => toggle( role, op, next ) }
										label=""
										help={
											blocked
												? __( 'El rol no tiene la capability necesaria.', 'wp-api-codeia' )
												: undefined
										}
										__nextHasNoMarginBottom
									/>
								</td>
							);
						} ) }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

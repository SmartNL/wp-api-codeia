import { CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export const OPERATIONS = [ 'read', 'create', 'update', 'delete', 'upload' ];

/**
 * Capability que exige cada operación.
 *
 * La matriz solo puede restringir: es la primera de las dos puertas. Marcar
 * una casilla cuyo rol no tiene la capability produciría una configuración
 * que la segunda puerta rechazaría siempre.
 */
export const REQUIRED_CAPS = {
	read: 'read',
	create: 'edit_posts',
	update: 'edit_posts',
	delete: 'delete_posts',
	upload: 'upload_files',
};

/**
 * Decide si una celda debe quedar desactivada.
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
 * Valor efectivo de una celda y el nivel que lo decidió.
 *
 * Replica la cascada de PermissionMatrix::resolve() del backend: gana la
 * regla más específica que exista. Mostrar solo el valor guardado en el nivel
 * 3 engañaría al administrador cuando lo que manda es el nivel 2.
 *
 * @param {Object} permissions Rama permissions de la configuración.
 * @param {string} resource    Recurso.
 * @param {string} role        Rol.
 * @param {string} operation   Operación.
 * @return {{value: boolean, level: number, explicit: boolean}} Resolución.
 */
export function resolveCell( permissions, resource, role, operation ) {
	let value = false;
	let level = 0;

	const byDefault = permissions?.defaults?.[ role ];
	if ( typeof byDefault === 'boolean' ) {
		value = byDefault;
		level = 1;
	}

	const byResource = permissions?.[ resource ]?.[ role ]?.all;
	if ( typeof byResource === 'boolean' ) {
		value = byResource;
		level = 2;
	}

	const byOperation = permissions?.[ resource ]?.[ role ]?.[ operation ];
	if ( typeof byOperation === 'boolean' ) {
		value = byOperation;
		level = 3;
	}

	return { value, level, explicit: level === 3 };
}

/**
 * Texto que explica de dónde sale el valor de una celda.
 *
 * @param {number} level Nivel que decidió.
 * @return {string} Explicación.
 */
export function levelHelp( level ) {
	switch ( level ) {
		case 3:
			return __( 'Regla propia de esta casilla.', 'wp-api-codeia' );
		case 2:
			return __( 'Heredado de la regla del recurso.', 'wp-api-codeia' );
		case 1:
			return __( 'Heredado del valor por defecto del rol.', 'wp-api-codeia' );
		default:
			return __( 'Sin regla: denegado por defecto.', 'wp-api-codeia' );
	}
}

/**
 * Matriz de permisos rol × operación para un recurso.
 *
 * @param {Object} props Propiedades.
 * @return {JSX.Element} Tabla editable.
 */
export default function PermissionMatrix( { resource, roles, caps, permissions, onChange } ) {
	const toggle = ( role, operation, next ) => {
		onChange( role, operation, next );
	};

	return (
		<table className="wp-list-table widefat striped">
			<thead>
				<tr>
					<th style={ { width: 180 } }>{ __( 'Rol', 'wp-api-codeia' ) }</th>
					{ OPERATIONS.map( ( op ) => (
						<th key={ op }>{ op }</th>
					) ) }
				</tr>
			</thead>
			<tbody>
				{ roles.map( ( role ) => (
					<tr key={ role.slug }>
						<td>
							<strong>{ role.label }</strong>
							<div style={ { color: '#646970', fontSize: 11 } }>
								<code>{ role.slug }</code>
							</div>
						</td>
						{ OPERATIONS.map( ( op ) => {
							const blocked = isBlockedByCapability( role.slug, op, caps );
							const { value, level } = resolveCell( permissions, resource, role.slug, op );

							return (
								<td key={ op }>
									<CheckboxControl
										checked={ value && ! blocked }
										disabled={ blocked }
										onChange={ ( next ) => toggle( role.slug, op, next ) }
										label=""
										help={
											blocked
												? __( 'El rol no tiene la capability necesaria.', 'wp-api-codeia' )
												: levelHelp( level )
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

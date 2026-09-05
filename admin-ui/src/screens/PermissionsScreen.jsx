import { useMemo, useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getResources, getRoles, getSettings, saveSettings } from '../api/client.js';
import { useResource } from '../hooks/useResource.js';
import { Loadable, ErrorNotice } from '../components/Feedback.jsx';
import PermissionMatrix from '../components/PermissionMatrix.jsx';

/**
 * Carga en paralelo lo que la pantalla necesita.
 *
 * @return {Promise<Object>} Recursos, roles y configuración.
 */
const loadAll = async () => {
	const [ resources, roles, settings ] = await Promise.all( [
		getResources(),
		getRoles(),
		getSettings(),
	] );

	return { resources, roles, settings };
};

/**
 * Pantalla de permisos: matriz de cuatro ejes.
 *
 * @return {JSX.Element} Pantalla.
 */
export default function PermissionsScreen() {
	const { data, loading, error, reload } = useResource( loadAll );
	const [ draft, setDraft ] = useState( null );
	const [ resource, setResource ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState( null );
	const [ saved, setSaved ] = useState( false );

	const resources = data?.resources || [];
	const roles = data?.roles || [];

	// El borrador arranca de la configuración cargada y solo entonces se puede
	// editar: sin esto, un cambio antes de que responda el servidor se perdería.
	const permissions = draft ?? data?.settings?.permissions ?? {};

	const caps = useMemo( () => {
		const map = {};
		roles.forEach( ( role ) => {
			map[ role.slug ] = role.capabilities || [];
		} );
		return map;
	}, [ roles ] );

	const current = resource || resources[ 0 ]?.post_type || '';

	const change = ( role, operation, next ) => {
		setSaved( false );
		setDraft( {
			...permissions,
			[ current ]: {
				...( permissions[ current ] || {} ),
				[ role ]: {
					...( permissions[ current ]?.[ role ] || {} ),
					[ operation ]: next,
				},
			},
		} );
	};

	const changeDefault = ( role, next ) => {
		setSaved( false );
		setDraft( {
			...permissions,
			defaults: { ...( permissions.defaults || {} ), [ role ]: next },
		} );
	};

	const save = async () => {
		setSaving( true );
		setSaveError( null );

		try {
			await saveSettings( { permissions } );
			setSaved( true );
		} catch ( err ) {
			setSaveError( err );
		} finally {
			setSaving( false );
		}
	};

	return (
		<>
			<h1>{ __( 'Permisos', 'wp-api-codeia' ) }</h1>
			<p className="description">
				{ __(
					'Esta matriz es la primera de las dos puertas: solo puede restringir. Una casilla marcada no concede nada que la capability del rol no permita ya.',
					'wp-api-codeia'
				) }
			</p>

			<ErrorNotice error={ saveError } />
			{ saved && (
				<Notice status="success" onRemove={ () => setSaved( false ) }>
					{ __( 'Permisos guardados.', 'wp-api-codeia' ) }
				</Notice>
			) }

			<Loadable
				loading={ loading }
				error={ error }
				onRetry={ reload }
				isEmpty={ ! loading && ! error && resources.length === 0 }
				empty={ __( 'No hay recursos detectados a los que aplicar permisos.', 'wp-api-codeia' ) }
			>
				<Card style={ { marginBottom: 16 } }>
					<CardHeader>
						<h2 style={ { margin: 0 } }>{ __( 'Valor por defecto por rol', 'wp-api-codeia' ) }</h2>
					</CardHeader>
					<CardBody>
						<p className="description">
							{ __(
								'Nivel 1 de la cascada. Se aplica a cualquier recurso que no tenga una regla más específica.',
								'wp-api-codeia'
							) }
						</p>
						<table className="wp-list-table widefat striped">
							<tbody>
								{ roles.map( ( role ) => (
									<tr key={ role.slug }>
										<td style={ { width: 220 } }>
											<strong>{ role.label }</strong>
										</td>
										<td>
											<label>
												<input
													type="checkbox"
													checked={ Boolean( permissions.defaults?.[ role.slug ] ) }
													onChange={ ( e ) => changeDefault( role.slug, e.target.checked ) }
												/>{ ' ' }
												{ __( 'Permitido salvo regla más específica', 'wp-api-codeia' ) }
											</label>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</CardBody>
				</Card>

				<Card>
					<CardHeader>
						<h2 style={ { margin: 0, flex: 1 } }>{ __( 'Por recurso', 'wp-api-codeia' ) }</h2>
						<SelectControl
							value={ current }
							onChange={ setResource }
							options={ resources.map( ( r ) => ( {
								label: `${ r.label } (${ r.post_type })`,
								value: r.post_type,
							} ) ) }
							__nextHasNoMarginBottom
						/>
					</CardHeader>
					<CardBody>
						<PermissionMatrix
							resource={ current }
							roles={ roles }
							caps={ caps }
							permissions={ permissions }
							onChange={ change }
						/>
					</CardBody>
				</Card>

				<p style={ { marginTop: 16 } }>
					<Button variant="primary" onClick={ save } isBusy={ saving } disabled={ saving }>
						{ __( 'Guardar permisos', 'wp-api-codeia' ) }
					</Button>
				</p>
			</Loadable>
		</>
	);
}

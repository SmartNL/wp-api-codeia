import { useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, Notice, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getSettings, saveSettings } from '../api/client.js';
import { useResource } from '../hooks/useResource.js';
import { Loadable, ErrorNotice } from '../components/Feedback.jsx';

/**
 * Los tres módulos activables, con el motivo de que estén apagados de origen.
 *
 * Ninguno se activa solo: cada uno amplía la superficie expuesta y esa es una
 * decisión del administrador, no un valor por defecto cómodo.
 */
const MODULES = [
	{
		id: 'openapi',
		label: __( 'OpenAPI y documentación', 'wp-api-codeia' ),
		help: __(
			'Publica el documento en /docs. Describe la superficie de la API a cualquiera que llegue a la ruta.',
			'wp-api-codeia'
		),
	},
	{
		id: 'media',
		label: __( 'Subida de medios', 'wp-api-codeia' ),
		help: __(
			'Habilita /media. La validación es por contenido, nunca por extensión, y SVG queda fuera de la lista blanca.',
			'wp-api-codeia'
		),
	},
	{
		id: 'rewrite',
		label: __( 'Alias de rutas en raíz', 'wp-api-codeia' ),
		help: __(
			'Sirve la API también fuera de /wp-json. Exige enlaces permanentes activos y regenera las reglas al guardar.',
			'wp-api-codeia'
		),
	},
];

/**
 * Tarjeta de activación de módulos.
 *
 * @return {JSX.Element} Tarjeta.
 */
export default function ModulesCard() {
	const { data, loading, error, reload } = useResource( getSettings );
	const [ draft, setDraft ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState( null );
	const [ saved, setSaved ] = useState( false );

	const modules = draft ?? data?.modules ?? {};

	const toggle = ( id, next ) => {
		setSaved( false );
		setDraft( { ...modules, [ id ]: next } );
	};

	const save = async () => {
		setSaving( true );
		setSaveError( null );

		try {
			await saveSettings( { modules } );
			setSaved( true );
		} catch ( err ) {
			setSaveError( err );
		} finally {
			setSaving( false );
		}
	};

	return (
		<Card style={ { marginBottom: 16 } }>
			<CardHeader>
				<h2 style={ { margin: 0 } }>{ __( 'Módulos', 'wp-api-codeia' ) }</h2>
			</CardHeader>
			<CardBody>
				<ErrorNotice error={ saveError } />
				{ saved && (
					<Notice status="success" onRemove={ () => setSaved( false ) }>
						{ __(
							'Módulos guardados. Recarga la página: las rutas se registran al arrancar la petición.',
							'wp-api-codeia'
						) }
					</Notice>
				) }

				<Loadable loading={ loading } error={ error } onRetry={ reload }>
					{ MODULES.map( ( module ) => (
						<div
							key={ module.id }
							style={ { padding: '12px 0', borderBottom: '1px solid #f0f0f1' } }
						>
							<ToggleControl
								checked={ Boolean( modules[ module.id ] ) }
								onChange={ ( next ) => toggle( module.id, next ) }
								label={ module.label }
								help={ module.help }
								__nextHasNoMarginBottom
							/>
						</div>
					) ) }

					<p style={ { marginTop: 16 } }>
						<Button variant="primary" onClick={ save } isBusy={ saving } disabled={ saving }>
							{ __( 'Guardar módulos', 'wp-api-codeia' ) }
						</Button>
					</p>
				</Loadable>
			</CardBody>
		</Card>
	);
}

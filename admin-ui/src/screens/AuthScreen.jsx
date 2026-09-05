import { useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, Notice, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getSettings, saveSettings } from '../api/client.js';
import { useResource } from '../hooks/useResource.js';
import { Loadable, ErrorNotice } from '../components/Feedback.jsx';

/**
 * Proveedores de autenticación, con el criterio de cuándo usar cada uno.
 *
 * El texto no es decorativo: la diferencia entre una credencial de sesión
 * corta y una de larga vida es la decisión de seguridad más importante de
 * esta pantalla, y quien la toma no siempre conoce las implicaciones.
 */
const PROVIDERS = [
	{
		id: 'app_password',
		label: __( 'Application Passwords', 'wp-api-codeia' ),
		help: __(
			'Respaldado por el núcleo de WordPress y sin coste de mantenimiento. La opción recomendada para integraciones servidor a servidor.',
			'wp-api-codeia'
		),
	},
	{
		id: 'jwt',
		label: __( 'JWT', 'wp-api-codeia' ),
		help: __(
			'Sesiones cortas de 15 minutos con refresco rotatorio. Indicado para clientes que mantienen sesión de usuario.',
			'wp-api-codeia'
		),
	},
	{
		id: 'api_key',
		label: __( 'API Key', 'wp-api-codeia' ),
		help: __(
			'Credencial de larga vida con ámbito propio, independiente del rol. Actívala solo si necesitas ese ámbito distinto.',
			'wp-api-codeia'
		),
	},
	{
		id: 'user_token',
		label: __( 'Token de usuario', 'wp-api-codeia' ),
		help: __(
			'Token opaco ligado a un usuario. Existe para instalaciones donde las Application Passwords están desactivadas.',
			'wp-api-codeia'
		),
	},
];

/**
 * Pantalla de autenticación.
 *
 * @return {JSX.Element} Pantalla.
 */
export default function AuthScreen() {
	const { data, loading, error, reload } = useResource( getSettings );
	const [ draft, setDraft ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState( null );
	const [ saved, setSaved ] = useState( false );

	const providers = draft ?? data?.auth?.providers ?? {};

	const isOn = ( id ) => {
		const value = providers[ id ];
		return typeof value === 'object' && value !== null ? Boolean( value.enabled ) : Boolean( value );
	};

	const toggle = ( id, next ) => {
		setSaved( false );
		setDraft( { ...providers, [ id ]: next } );
	};

	const save = async () => {
		setSaving( true );
		setSaveError( null );

		try {
			await saveSettings( { auth: { providers } } );
			setSaved( true );
		} catch ( err ) {
			setSaveError( err );
		} finally {
			setSaving( false );
		}
	};

	const noneEnabled = PROVIDERS.every( ( p ) => ! isOn( p.id ) );

	return (
		<>
			<h1>{ __( 'Autenticación', 'wp-api-codeia' ) }</h1>
			<p className="description">
				{ __(
					'Un proveedor desactivado no se monta: su credencial deja de aceptarse, no solo de documentarse.',
					'wp-api-codeia'
				) }
			</p>

			<ErrorNotice error={ saveError } />
			{ saved && (
				<Notice status="success" onRemove={ () => setSaved( false ) }>
					{ __( 'Configuración guardada.', 'wp-api-codeia' ) }
				</Notice>
			) }

			<Loadable loading={ loading } error={ error } onRetry={ reload }>
				{ noneEnabled && (
					<Notice status="warning" isDismissible={ false }>
						{ __(
							'Sin ningún proveedor activo la API solo responderá a peticiones anónimas, y únicamente a lo que la matriz permita al rol anónimo.',
							'wp-api-codeia'
						) }
					</Notice>
				) }

				<Card>
					<CardHeader>
						<h2 style={ { margin: 0 } }>{ __( 'Proveedores', 'wp-api-codeia' ) }</h2>
					</CardHeader>
					<CardBody>
						{ PROVIDERS.map( ( provider ) => (
							<div
								key={ provider.id }
								style={ { padding: '12px 0', borderBottom: '1px solid #f0f0f1' } }
							>
								<ToggleControl
									checked={ isOn( provider.id ) }
									onChange={ ( next ) => toggle( provider.id, next ) }
									label={ provider.label }
									help={ provider.help }
									__nextHasNoMarginBottom
								/>
							</div>
						) ) }
					</CardBody>
				</Card>

				<p style={ { marginTop: 16 } }>
					<Button variant="primary" onClick={ save } isBusy={ saving } disabled={ saving }>
						{ __( 'Guardar', 'wp-api-codeia' ) }
					</Button>
				</p>
			</Loadable>
		</>
	);
}

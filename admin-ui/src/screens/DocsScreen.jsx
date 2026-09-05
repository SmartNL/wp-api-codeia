import { useEffect, useRef, useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getSettings, saveSettings } from '../api/client.js';
import { useResource } from '../hooks/useResource.js';
import { Loadable, ErrorNotice } from '../components/Feedback.jsx';

/**
 * Pantalla de documentación: Swagger UI sobre el documento OpenAPI del sitio.
 *
 * El documento se pide con las credenciales del administrador, así que refleja
 * lo que ese rol puede ver. Un lector anónimo obtendría un documento más
 * pequeño: los campos restringidos ni siquiera se mencionan.
 *
 * @return {JSX.Element} Pantalla.
 */
export default function DocsScreen() {
	const container = useRef( null );
	const [ error, setError ] = useState( null );
	const [ enabling, setEnabling ] = useState( false );
	const [ justEnabled, setJustEnabled ] = useState( false );

	const { data, loading, error: loadError, reload } = useResource( getSettings );

	const boot = window.codeiaAdmin || {};
	const enabled = Boolean( data?.modules?.openapi );

	useEffect( () => {
		// Sin el módulo activo la ruta /docs no está registrada y Swagger UI
		// solo sabría decir «Failed to load API definition», que no explica
		// nada ni ofrece salida.
		if ( ! enabled || ! container.current ) {
			return;
		}

		if ( typeof window.SwaggerUIBundle !== 'function' ) {
			setError(
				new Error(
					__(
						'Swagger UI no está disponible. Ejecuta «npm run vendor:swagger» en admin-ui para copiarlo a assets/vendor/.',
						'wp-api-codeia'
					)
				)
			);
			return;
		}

		if ( ! boot.specUrl ) {
			setError( new Error( __( 'No se conoce la URL del documento OpenAPI.', 'wp-api-codeia' ) ) );
			return;
		}

		try {
			window.SwaggerUIBundle( {
				domNode: container.current,
				url: boot.specUrl,
				// El documento se sirve por cookie desde el panel, así que la
				// petición necesita el nonce igual que el resto de la interfaz.
				requestInterceptor: ( request ) => {
					request.headers[ 'X-WP-Nonce' ] = boot.nonce;
					return request;
				},
				docExpansion: 'list',
				defaultModelsExpandDepth: 0,
				tryItOutEnabled: true,
			} );
		} catch ( err ) {
			setError( err );
		}
	}, [ enabled, boot.specUrl, boot.nonce ] );

	const enable = async () => {
		setEnabling( true );
		setError( null );

		try {
			await saveSettings( { modules: { openapi: true } } );

			// La ruta se registra en rest_api_init, así que no existe hasta la
			// siguiente petición: recargar es parte de activar, no un extra.
			setJustEnabled( true );
		} catch ( err ) {
			setError( err );
		} finally {
			setEnabling( false );
		}
	};

	return (
		<>
			<h1>{ __( 'Documentación', 'wp-api-codeia' ) }</h1>
			<p className="description">
				{ __(
					'Documento OpenAPI 3.1 generado a partir del esquema detectado. Lo que ves aquí es el ámbito de tu propio rol.',
					'wp-api-codeia'
				) }
				{ enabled && boot.specUrl && (
					<>
						{ ' ' }
						<a href={ boot.specUrl } target="_blank" rel="noreferrer">
							{ __( 'Ver el JSON', 'wp-api-codeia' ) }
						</a>
					</>
				) }
			</p>

			<ErrorNotice error={ error } />

			<Loadable loading={ loading } error={ loadError } onRetry={ reload }>
				{ justEnabled && (
					<Notice status="success" isDismissible={ false }>
						{ __(
							'Módulo activado. Recarga la página para que la ruta del documento quede registrada.',
							'wp-api-codeia'
						) }{ ' ' }
						<Button variant="primary" onClick={ () => window.location.reload() }>
							{ __( 'Recargar', 'wp-api-codeia' ) }
						</Button>
					</Notice>
				) }

				{ ! enabled && ! justEnabled && (
					<Notice status="warning" isDismissible={ false }>
						<p>
							{ __(
								'El módulo OpenAPI está desactivado, así que la ruta del documento no está registrada.',
								'wp-api-codeia'
							) }
						</p>
						<p>
							{ __(
								'Está apagado por defecto a propósito: publicar la documentación describe la superficie de la API a cualquiera que llegue a la ruta.',
								'wp-api-codeia'
							) }
						</p>
						<Button
							variant="primary"
							onClick={ enable }
							isBusy={ enabling }
							disabled={ enabling }
						>
							{ __( 'Activar el módulo OpenAPI', 'wp-api-codeia' ) }
						</Button>
					</Notice>
				) }

				{ enabled && (
					<div
						ref={ container }
						className="codeia-swagger"
						style={ { background: '#fff', marginTop: 16 } }
					/>
				) }
			</Loadable>
		</>
	);
}

import { useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { exportConfig, importConfig, purgeLogs, rebuildSchema } from '../api/client.js';
import { ErrorNotice } from '../components/Feedback.jsx';
import ModulesCard from '../components/ModulesCard.jsx';

/**
 * Descarga un objeto como fichero JSON.
 *
 * @param {Object} data     Contenido.
 * @param {string} filename Nombre del fichero.
 * @return {void}
 */
function download( data, filename ) {
	const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
	const url = URL.createObjectURL( blob );
	const link = document.createElement( 'a' );

	link.href = url;
	link.download = filename;
	document.body.appendChild( link );
	link.click();
	document.body.removeChild( link );
	URL.revokeObjectURL( url );
}

/**
 * Pantalla de herramientas.
 *
 * @return {JSX.Element} Pantalla.
 */
export default function ToolsScreen() {
	const [ busy, setBusy ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ message, setMessage ] = useState( null );
	const [ preview, setPreview ] = useState( null );
	const [ pending, setPending ] = useState( null );

	const run = async ( id, fn ) => {
		setBusy( id );
		setError( null );
		setMessage( null );

		try {
			return await fn();
		} catch ( err ) {
			setError( err );
			return null;
		} finally {
			setBusy( null );
		}
	};

	const doRebuild = () =>
		run( 'rebuild', async () => {
			const result = await rebuildSchema();
			const total = Array.isArray( result )
				? result.length
				: Object.keys( result || {} ).length;

			setMessage(
				sprintf(
					/* translators: %d: número de recursos reconstruidos. */
					__( 'Esquema reconstruido: %d recursos.', 'wp-api-codeia' ),
					total
				)
			);
		} );

	const doExport = () =>
		run( 'export', async () => {
			const doc = await exportConfig();
			download( doc, 'codeia-config-' + new Date().toISOString().slice( 0, 10 ) + '.json' );
			setMessage( __( 'Configuración exportada. Los secretos no se incluyen.', 'wp-api-codeia' ) );
		} );

	const doPurge = () => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( '¿Vaciar la tabla de registros?', 'wp-api-codeia' ) ) ) {
			return undefined;
		}

		return run( 'purge', async () => {
			await purgeLogs();
			setMessage( __( 'Registros vaciados.', 'wp-api-codeia' ) );
		} );
	};

	const pickFile = ( event ) => {
		const file = event.target.files && event.target.files[ 0 ];

		if ( ! file ) {
			return;
		}

		const reader = new FileReader();

		reader.onload = async () => {
			setError( null );
			setMessage( null );
			setPreview( null );

			let parsed;

			try {
				parsed = JSON.parse( String( reader.result ) );
			} catch {
				setError( new Error( __( 'El fichero no es JSON válido.', 'wp-api-codeia' ) ) );
				return;
			}

			// Siempre se simula primero: una importación sustituye la
			// configuración entera y no hay deshacer en la interfaz.
			const result = await run( 'import', () => importConfig( parsed, true ) );

			if ( result ) {
				setPreview( result );
				setPending( parsed );
			}
		};

		reader.readAsText( file );
	};

	const applyImport = () =>
		run( 'apply', async () => {
			await importConfig( pending, false );
			setPreview( null );
			setPending( null );
			setMessage(
				__( 'Configuración importada. Se ha guardado una copia de la anterior.', 'wp-api-codeia' )
			);
		} );

	return (
		<>
			<h1>{ __( 'Herramientas', 'wp-api-codeia' ) }</h1>

			<ErrorNotice error={ error } />
			{ message && (
				<Notice status="success" onRemove={ () => setMessage( null ) }>
					{ message }
				</Notice>
			) }

			<ModulesCard />

			<Card style={ { marginBottom: 16 } }>
				<CardHeader>
					<h2 style={ { margin: 0 } }>{ __( 'Esquema', 'wp-api-codeia' ) }</h2>
				</CardHeader>
				<CardBody>
					<p className="description">
						{ __(
							'Vuelve a detectar tipos y campos. Necesario tras instalar un plugin que registre contenido o tras añadir campos nuevos.',
							'wp-api-codeia'
						) }
					</p>
					<Button
						variant="secondary"
						onClick={ doRebuild }
						isBusy={ busy === 'rebuild' }
						disabled={ busy !== null }
					>
						{ __( 'Reconstruir esquema', 'wp-api-codeia' ) }
					</Button>
				</CardBody>
			</Card>
			<Card style={ { marginBottom: 16 } }>
				<CardHeader>
					<h2 style={ { margin: 0 } }>{ __( 'Configuración', 'wp-api-codeia' ) }</h2>
				</CardHeader>
				<CardBody>
					<p className="description">
						{ __(
							'La exportación no incluye secretos: la clave de firma no sale del servidor, así que el fichero es seguro de compartir entre entornos.',
							'wp-api-codeia'
						) }
					</p>
					<p>
						<Button
							variant="secondary"
							onClick={ doExport }
							isBusy={ busy === 'export' }
							disabled={ busy !== null }
						>
							{ __( 'Exportar', 'wp-api-codeia' ) }
						</Button>
					</p>
					<p>
						<label htmlFor="codeia-import">
							<strong>{ __( 'Importar', 'wp-api-codeia' ) }</strong>
						</label>
						<br />
						<input
							id="codeia-import"
							type="file"
							accept="application/json"
							onChange={ pickFile }
						/>
					</p>

					{ preview && (
						<Notice status="warning" isDismissible={ false }>
							<p>
								<strong>{ __( 'Revisa antes de aplicar', 'wp-api-codeia' ) }</strong>
							</p>
							{ preview.warnings && preview.warnings.length > 0 && (
								<ul style={ { listStyle: 'disc', marginLeft: 16 } }>
									{ preview.warnings.map( ( w ) => (
										<li key={ w }>{ w }</li>
									) ) }
								</ul>
							) }
							{ preview.environment &&
								preview.environment.missing_post_types &&
								preview.environment.missing_post_types.length > 0 && (
									<p>
										{ __( 'Tipos de contenido que no existen aquí:', 'wp-api-codeia' ) }{ ' ' }
										<code>{ preview.environment.missing_post_types.join( ', ' ) }</code>
									</p>
								) }
							{ preview.environment &&
								preview.environment.missing_roles &&
								preview.environment.missing_roles.length > 0 && (
									<p>
										{ __( 'Roles que no existen aquí:', 'wp-api-codeia' ) }{ ' ' }
										<code>{ preview.environment.missing_roles.join( ', ' ) }</code>
									</p>
								) }
							<p>
								<Button
									variant="primary"
									onClick={ applyImport }
									isBusy={ busy === 'apply' }
									disabled={ busy !== null }
								>
									{ __( 'Aplicar importación', 'wp-api-codeia' ) }
								</Button>{ ' ' }
								<Button
									variant="tertiary"
									onClick={ () => {
										setPreview( null );
										setPending( null );
									} }
								>
									{ __( 'Cancelar', 'wp-api-codeia' ) }
								</Button>
							</p>
						</Notice>
					) }
				</CardBody>
			</Card>

			<Card>
				<CardHeader>
					<h2 style={ { margin: 0 } }>{ __( 'Registros', 'wp-api-codeia' ) }</h2>
				</CardHeader>
				<CardBody>
					<p className="description">
						{ __( 'Vacía la tabla de eventos. No se puede deshacer.', 'wp-api-codeia' ) }
					</p>
					<Button
						variant="secondary"
						isDestructive
						onClick={ doPurge }
						isBusy={ busy === 'purge' }
						disabled={ busy !== null }
					>
						{ __( 'Vaciar registros', 'wp-api-codeia' ) }
					</Button>
				</CardBody>
			</Card>
		</>
	);
}

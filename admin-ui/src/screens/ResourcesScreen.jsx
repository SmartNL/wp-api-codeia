import { useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, Notice, ToggleControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { getResources, saveSettings } from '../api/client.js';
import { useResource } from '../hooks/useResource.js';
import { Loadable, ErrorNotice } from '../components/Feedback.jsx';
import FieldTable from '../components/FieldTable.jsx';

/**
 * Catálogo de recursos detectados.
 *
 * Un recurso detectado no se expone solo: activarlo es una decisión explícita
 * del administrador. La detección propone; esta pantalla dispone.
 *
 * @return {JSX.Element} Pantalla.
 */
export default function ResourcesScreen() {
	const { data, loading, error, reload, setData } = useResource( getResources );
	const [ openType, setOpenType ] = useState( null );
	const [ saving, setSaving ] = useState( null );
	const [ saveError, setSaveError ] = useState( null );

	const resources = data || [];

	const toggle = async ( postType, enabled ) => {
		setSaving( postType );
		setSaveError( null );

		// Optimista: la lista se actualiza antes de la respuesta y se revierte
		// si el guardado falla. Con decenas de recursos, esperar al servidor
		// en cada clic hace la pantalla inusable.
		setData( ( prev ) =>
			prev.map( ( r ) => ( r.post_type === postType ? { ...r, enabled } : r ) )
		);

		try {
			await saveSettings( { resources: { [ postType ]: { enabled } } } );
		} catch ( err ) {
			setSaveError( err );
			setData( ( prev ) =>
				prev.map( ( r ) => ( r.post_type === postType ? { ...r, enabled: ! enabled } : r ) )
			);
		} finally {
			setSaving( null );
		}
	};

	return (
		<>
			<h1>{ __( 'Recursos', 'wp-api-codeia' ) }</h1>
			<p className="description">
				{ __(
					'Tipos de contenido detectados y los campos encontrados en cada uno. Activar un recurso publica sus endpoints.',
					'wp-api-codeia'
				) }
			</p>

			<ErrorNotice error={ saveError } />

			<Loadable
				loading={ loading }
				error={ error }
				onRetry={ reload }
				isEmpty={ ! loading && ! error && resources.length === 0 }
				empty={ __(
					'No se ha detectado ningún tipo de contenido. Reconstruye el esquema desde Herramientas.',
					'wp-api-codeia'
				) }
			>
				{ resources.map( ( resource ) => {
					const isOpen = openType === resource.post_type;
					const conflicts = resource.conflicts || {};
					const conflictCount = Object.keys( conflicts ).length;

					return (
						<Card key={ resource.post_type } style={ { marginBottom: 16 } }>
							<CardHeader>
								<div style={ { flex: 1 } }>
									<strong>{ resource.label }</strong>{ ' ' }
									<code>{ resource.post_type }</code>
									<div style={ { color: '#646970', fontSize: 12, marginTop: 2 } }>
										{ sprintf(
											/* translators: %1$d: número de campos. %2$d: número de taxonomías. */
											__( '%1$d campos · %2$d taxonomías', 'wp-api-codeia' ),
											resource.fields?.length || 0,
											resource.taxonomies?.length || 0
										) }
										{ conflictCount > 0 && (
											<span style={ { color: '#dba617', marginLeft: 8 } }>
												{ sprintf(
													/* translators: %d: número de conflictos de nombre. */
													__( '· %d conflictos de nombre', 'wp-api-codeia' ),
													conflictCount
												) }
											</span>
										) }
									</div>
								</div>
								<div style={ { display: 'flex', alignItems: 'center', gap: 12 } }>
									<ToggleControl
										checked={ Boolean( resource.enabled ) }
										disabled={ saving === resource.post_type }
										onChange={ ( next ) => toggle( resource.post_type, next ) }
										label={ __( 'Expuesto', 'wp-api-codeia' ) }
										__nextHasNoMarginBottom
									/>
									<Button
										variant="secondary"
										onClick={ () => setOpenType( isOpen ? null : resource.post_type ) }
										aria-expanded={ isOpen }
									>
										{ isOpen
											? __( 'Ocultar campos', 'wp-api-codeia' )
											: __( 'Ver campos', 'wp-api-codeia' ) }
									</Button>
								</div>
							</CardHeader>

							{ isOpen && (
								<CardBody>
									{ conflictCount > 0 && (
										<Notice status="warning" isDismissible={ false }>
											{ __(
												'Dos claves distintas producen el mismo nombre expuesto. Se ha desambiguado automáticamente:',
												'wp-api-codeia'
											) }
											<ul style={ { margin: '8px 0 0 16px', listStyle: 'disc' } }>
												{ Object.entries( conflicts ).map( ( [ key, value ] ) => (
													<li key={ key }>
														<code>{ key }</code> → <code>{ String( value ) }</code>
													</li>
												) ) }
											</ul>
										</Notice>
									) }
									<FieldTable fields={ resource.fields } />
								</CardBody>
							) }
						</Card>
					);
				} ) }
			</Loadable>
		</>
	);
}

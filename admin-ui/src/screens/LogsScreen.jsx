import { useState } from '@wordpress/element';
import { Button, Card, CardBody, CardHeader, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getLogs } from '../api/client.js';
import { useResource } from '../hooks/useResource.js';
import { Loadable } from '../components/Feedback.jsx';

const LEVEL_COLORS = {
	debug: '#646970',
	info: '#2271b1',
	warning: '#dba617',
	error: '#d63638',
};

/**
 * Formatea la marca de tiempo UTC del registro a la zona del navegador.
 *
 * La columna se guarda en UTC. Mostrarla sin convertir hace que los eventos
 * parezcan desfasados respecto al reloj de quien mira la pantalla, que es la
 * causa habitual de creer que el registro no funciona.
 *
 * @param {string} value Marca en formato MySQL UTC.
 * @return {string} Fecha local legible.
 */
export function formatDate( value ) {
	if ( ! value ) {
		return '—';
	}

	const parsed = new Date( `${ String( value ).replace( ' ', 'T' ) }Z` );

	if ( Number.isNaN( parsed.getTime() ) ) {
		return String( value );
	}

	return parsed.toLocaleString();
}

/**
 * Visor de registros.
 *
 * @return {JSX.Element} Pantalla.
 */
export default function LogsScreen() {
	const [ level, setLevel ] = useState( '' );
	const [ open, setOpen ] = useState( null );

	const { data, loading, error, reload } = useResource(
		() => getLogs( level ? `?level=${ level }&per_page=100` : '?per_page=100' ),
		[ level ]
	);

	const rows = data || [];

	return (
		<>
			<h1>{ __( 'Registros', 'wp-api-codeia' ) }</h1>
			<p className="description">
				{ __(
					'Los tokens y contraseñas no se guardan en claro: aparecen como [oculto].',
					'wp-api-codeia'
				) }
			</p>

			<Card>
				<CardHeader>
					<SelectControl
						label={ __( 'Nivel', 'wp-api-codeia' ) }
						value={ level }
						onChange={ setLevel }
						options={ [
							{ label: __( 'Todos', 'wp-api-codeia' ), value: '' },
							{ label: 'debug', value: 'debug' },
							{ label: 'info', value: 'info' },
							{ label: 'warning', value: 'warning' },
							{ label: 'error', value: 'error' },
						] }
						__nextHasNoMarginBottom
					/>
					<Button variant="secondary" onClick={ reload }>
						{ __( 'Actualizar', 'wp-api-codeia' ) }
					</Button>
				</CardHeader>
				<CardBody>
					<Loadable
						loading={ loading }
						error={ error }
						onRetry={ reload }
						isEmpty={ ! loading && ! error && rows.length === 0 }
						empty={ __( 'No hay eventos registrados.', 'wp-api-codeia' ) }
					>
						<table className="wp-list-table widefat striped">
							<thead>
								<tr>
									<th style={ { width: 170 } }>{ __( 'Fecha', 'wp-api-codeia' ) }</th>
									<th style={ { width: 80 } }>{ __( 'Nivel', 'wp-api-codeia' ) }</th>
									<th style={ { width: 110 } }>{ __( 'Canal', 'wp-api-codeia' ) }</th>
									<th>{ __( 'Mensaje', 'wp-api-codeia' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ rows.map( ( row ) => (
									<tr key={ row.id }>
										<td>{ formatDate( row.created_at ) }</td>
										<td style={ { color: LEVEL_COLORS[ row.level ] || '#646970' } }>
											{ row.level }
										</td>
										<td>{ row.channel }</td>
										<td>
											{ row.message }
											{ row.context && row.context !== '[]' && (
												<>
													{ ' ' }
													<button
														type="button"
														className="button-link"
														onClick={ () => setOpen( open === row.id ? null : row.id ) }
													>
														{ open === row.id
															? __( 'Ocultar contexto', 'wp-api-codeia' )
															: __( 'Ver contexto', 'wp-api-codeia' ) }
													</button>
													{ open === row.id && (
														<pre
															style={ {
																whiteSpace: 'pre-wrap',
																background: '#f6f7f7',
																padding: 8,
																marginTop: 6,
															} }
														>
															{ row.context }
														</pre>
													) }
												</>
											) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</Loadable>
				</CardBody>
			</Card>
		</>
	);
}

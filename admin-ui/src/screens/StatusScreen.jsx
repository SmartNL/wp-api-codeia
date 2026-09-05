import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getStatus } from '../api/client.js';
import { useResource } from '../hooks/useResource.js';
import { Loadable } from '../components/Feedback.jsx';

/**
 * Color del semáforo por severidad.
 */
const TONES = {
	ok: { color: '#00a32a', label: __( 'Correcto', 'wp-api-codeia' ) },
	warning: { color: '#dba617', label: __( 'Aviso', 'wp-api-codeia' ) },
	error: { color: '#d63638', label: __( 'Error', 'wp-api-codeia' ) },
};

/**
 * Punto de color con su texto asociado.
 *
 * El color nunca va solo: un semáforo sin etiqueta es ilegible para quien no
 * distingue rojo y verde, que es la deficiencia visual más común.
 *
 * @param {Object} props Propiedades.
 * @return {JSX.Element} Indicador.
 */
export function StatusDot( { status } ) {
	const tone = TONES[ status ] || TONES.warning;

	return (
		<span style={ { whiteSpace: 'nowrap' } }>
			<span
				aria-hidden="true"
				style={ {
					display: 'inline-block',
					width: 10,
					height: 10,
					borderRadius: '50%',
					background: tone.color,
					marginRight: 6,
				} }
			/>
			{ tone.label }
		</span>
	);
}

/**
 * Pantalla de estado: comprobaciones del entorno y cifras del esquema.
 *
 * @return {JSX.Element} Pantalla.
 */
export default function StatusScreen() {
	const { data, loading, error, reload } = useResource( getStatus );

	const checks = data?.checks || [];
	const counts = data?.counts || {};

	return (
		<>
			<h1>{ __( 'Estado', 'wp-api-codeia' ) }</h1>

			<Loadable loading={ loading } error={ error } onRetry={ reload }>
				<div style={ { display: 'flex', gap: 16, flexWrap: 'wrap', marginBottom: 24 } }>
					{ [
						[ __( 'Recursos', 'wp-api-codeia' ), counts.resources ],
						[ __( 'Campos', 'wp-api-codeia' ), counts.fields ],
						[ __( 'Rutas', 'wp-api-codeia' ), counts.routes ],
					].map( ( [ label, value ] ) => (
						<Card key={ label } style={ { minWidth: 160 } }>
							<CardBody>
								<p style={ { margin: 0, fontSize: 28, fontWeight: 600 } }>
									{ value ?? '—' }
								</p>
								<p style={ { margin: 0, color: '#646970' } }>{ label }</p>
							</CardBody>
						</Card>
					) ) }
				</div>

				<Card>
					<CardHeader>
						<h2 style={ { margin: 0 } }>{ __( 'Comprobaciones', 'wp-api-codeia' ) }</h2>
					</CardHeader>
					<CardBody>
						<table className="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th style={ { width: 120 } }>{ __( 'Estado', 'wp-api-codeia' ) }</th>
									<th style={ { width: 220 } }>{ __( 'Comprobación', 'wp-api-codeia' ) }</th>
									<th>{ __( 'Detalle', 'wp-api-codeia' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ checks.map( ( check ) => (
									<tr key={ check.id }>
										<td>
											<StatusDot status={ check.status } />
										</td>
										<td>{ check.label }</td>
										<td>{ check.message }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</CardBody>
				</Card>
			</Loadable>
		</>
	);
}

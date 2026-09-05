import { Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ApiError } from '../api/client.js';

/**
 * Traduce un error de la API a un mensaje accionable.
 *
 * Los nonces caducan a las 12-24 h: una pestaña abierta toda la noche fallará
 * al guardar, y conviene decir que hay que recargar en lugar de mostrar un
 * error genérico que no sugiere ninguna acción.
 *
 * @param {Object} props Propiedades.
 * @return {JSX.Element|null} Aviso.
 */
export function ErrorNotice( { error, onRetry } ) {
	if ( ! error ) {
		return null;
	}

	const expired = error instanceof ApiError && error.isExpiredNonce();

	return (
		<Notice status="error" isDismissible={ false }>
			{ expired
				? __( 'La sesión ha caducado. Recarga la página para continuar.', 'wp-api-codeia' )
				: error.message }
			{ ! expired && onRetry && (
				<>
					{ ' ' }
					<button type="button" className="button-link" onClick={ onRetry }>
						{ __( 'Reintentar', 'wp-api-codeia' ) }
					</button>
				</>
			) }
		</Notice>
	);
}

/**
 * Envoltorio que resuelve carga, error y contenido.
 *
 * @param {Object} props Propiedades.
 * @return {JSX.Element} Contenido o estado intermedio.
 */
export function Loadable( { loading, error, onRetry, children, empty, isEmpty } ) {
	if ( loading ) {
		return (
			<p>
				<Spinner /> { __( 'Cargando…', 'wp-api-codeia' ) }
			</p>
		);
	}

	if ( error ) {
		return <ErrorNotice error={ error } onRetry={ onRetry } />;
	}

	if ( isEmpty ) {
		return (
			<Notice status="info" isDismissible={ false }>
				{ empty || __( 'No hay nada que mostrar todavía.', 'wp-api-codeia' ) }
			</Notice>
		);
	}

	return children;
}

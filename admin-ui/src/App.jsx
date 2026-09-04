import { useEffect, useState } from '@wordpress/element';
import { Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { getResources, getStatus, ApiError } from './api/client.js';

/**
 * Raíz del dashboard.
 *
 * @param {Object} props Propiedades.
 * @return {JSX.Element} Interfaz.
 */
export default function App( { page } ) {
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ data, setData ] = useState( null );

	useEffect( () => {
		const loader = page === 'codeia-api' ? getStatus : getResources;

		loader()
			.then( setData )
			.catch( ( err ) => setError( err ) )
			.finally( () => setLoading( false ) );
	}, [ page ] );

	if ( loading ) {
		return <Spinner />;
	}

	if ( error ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ error instanceof ApiError && error.isExpiredNonce()
					? __( 'La sesión ha caducado. Recarga la página.', 'wp-api-codeia' )
					: error.message }
			</Notice>
		);
	}

	return (
		<div className="codeia-admin">
			<h1>{ __( 'WP API Codeia', 'wp-api-codeia' ) }</h1>
			<pre>{ JSON.stringify( data, null, 2 ) }</pre>
		</div>
	);
}

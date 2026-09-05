import { useCallback, useEffect, useState } from '@wordpress/element';

/**
 * Carga un recurso de la API interna con su estado de carga y error.
 *
 * Centraliza el patrón para que ninguna pantalla repita el manejo de errores
 * ni olvide el caso del nonce caducado.
 *
 * @param {Function} loader Función que devuelve una promesa.
 * @param {Array}    deps   Dependencias que fuerzan una recarga.
 * @return {Object} Estado y función de recarga.
 */
export function useResource( loader, deps = [] ) {
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const reload = useCallback( () => {
		setLoading( true );
		setError( null );

		return loader()
			.then( ( result ) => {
				setData( result );
				return result;
			} )
			.catch( ( err ) => {
				setError( err );
				return null;
			} )
			.finally( () => setLoading( false ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, deps );

	useEffect( () => {
		reload();
	}, [ reload ] );

	return { data, loading, error, reload, setData };
}

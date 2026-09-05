/**
 * Cliente de la API interna del dashboard.
 *
 * Es la única vía de datos: no hay admin-ajax ni estado preinyectado más allá
 * de window.codeiaAdmin. La interfaz carga igual que lo haría un cliente
 * externo, lo que permite ejercitarla con curl durante el desarrollo.
 */

const boot = () => window.codeiaAdmin || { root: '', nonce: '' };

/**
 * Lanza una petición contra la API interna.
 *
 * @param {string} path   Ruta relativa.
 * @param {Object} params Opciones de fetch.
 * @return {Promise<*>} Respuesta decodificada.
 */
export async function request( path, params = {} ) {
	const { root, nonce } = boot();

	const response = await fetch( root + path, {
		...params,
		headers: {
			'Content-Type': 'application/json',
			// Las rutas admin/ se consumen por cookie, así que sí requieren
			// nonce, a diferencia de las públicas autenticadas por token.
			'X-WP-Nonce': nonce,
			...( params.headers || {} ),
		},
		credentials: 'same-origin',
	} );

	const body = await response.json().catch( () => null );

	if ( ! response.ok ) {
		throw new ApiError( body, response.status );
	}

	return body;
}

/**
 * Error de la API con el código de WordPress preservado.
 */
export class ApiError extends Error {
	constructor( body, status ) {
		super( body?.message || 'Error de la API' );
		this.name = 'ApiError';
		this.code = body?.code || 'unknown';
		this.status = status;
	}

	/**
	 * Los nonces caducan a las 12-24 h. Una pestaña abierta toda la noche
	 * fallará al guardar, y conviene decir que hay que recargar en lugar de
	 * mostrar un error genérico.
	 *
	 * @return {boolean} Si el fallo se debe a un nonce caducado.
	 */
	isExpiredNonce() {
		return this.code === 'rest_cookie_invalid_nonce';
	}
}

export const getResources = () => request( 'resources' );
export const getStatus = () => request( 'status' );
export const getLogs = ( query = '' ) => request( 'logs' + query );
export const rebuildSchema = () => request( 'schema/rebuild', { method: 'POST' } );
export const saveSettings = ( payload ) =>
	request( 'settings', { method: 'PATCH', body: JSON.stringify( payload ) } );
export const getSettings = () => request( 'settings' );
export const getRoles = () => request( 'roles' );
export const exportConfig = () => request( 'export' );
export const purgeLogs = () => request( 'logs', { method: 'DELETE' } );
export const importConfig = ( document, dryRun = true ) =>
	request( `import?dry_run=${ dryRun ? '1' : '0' }`, {
		method: 'POST',
		body: JSON.stringify( document ),
	} );

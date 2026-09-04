<?php
/**
 * Contrato de los proveedores de autenticacion.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Auth;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Resuelve una identidad a partir de una peticion.
 *
 * La separacion entre handles() y authenticate() es la clave del diseno:
 * permite distinguir "no traes credenciales mias" —pasar al siguiente
 * proveedor— de "traes credenciales mias y son invalidas" —401 inmediato,
 * sin seguir probando—.
 *
 * Sin esa separacion, un token caducado caeria al siguiente proveedor y
 * acabaria resolviendose como peticion anonima con un 403 confuso, en lugar
 * del 401 que le dice al cliente que debe renovar.
 */
interface Authenticator {

	/**
	 * Identificador corto del proveedor.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Orden en la cadena. Menor se evalua antes.
	 *
	 * @return int
	 */
	public function priority(): int;

	/**
	 * Indica si la peticion trae credenciales de este tipo.
	 *
	 * Debe ser barato: solo inspeccionar cabeceras, sin tocar la base de
	 * datos ni criptografia.
	 *
	 * @param RequestCredentials $credentials Credenciales de la peticion.
	 * @return bool
	 */
	public function handles( RequestCredentials $credentials ): bool;

	/**
	 * Resuelve la identidad.
	 *
	 * @param RequestCredentials $credentials Credenciales de la peticion.
	 * @return int|WP_Error ID de usuario mayor que cero, o error si la
	 *                      credencial es invalida.
	 */
	public function authenticate( RequestCredentials $credentials );
}

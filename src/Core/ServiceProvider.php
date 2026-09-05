<?php
/**
 * Contrato de los proveedores de servicios.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Core;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Container;

/**
 * Un proveedor declara servicios y los engancha a WordPress.
 *
 * La separacion entre register() y boot() es deliberada: garantiza que TODAS
 * las factorias esten declaradas antes de que ningun modulo intente resolver
 * una dependencia. Sin ella, el orden de carga de los proveedores se vuelve
 * significativo y fragil.
 */
interface ServiceProvider {

	/**
	 * Declara factorias en el contenedor.
	 *
	 * No debe ejecutar logica de negocio, consultar la base de datos ni
	 * registrar hooks: solo describir como se construye cada servicio.
	 *
	 * @param Container $container Contenedor del plugin.
	 * @return void
	 */
	public function register( Container $container ): void;

	/**
	 * Engancha el proveedor a WordPress.
	 *
	 * Aqui si se llama a add_action() y add_filter(). En este punto todos los
	 * proveedores han pasado ya por register().
	 *
	 * @param Container $container Contenedor del plugin.
	 * @return void
	 */
	public function boot( Container $container ): void;
}

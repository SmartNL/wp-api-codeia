<?php
/**
 * Plugin Name:       WP API Codeia
 * Plugin URI:        https://sn4p.dev/wp-api-codeia
 * Description:       Convierte WordPress en una API personalizada configurable desde un dashboard propio.
 * Version:           0.5.0
 * Requires at least: 7.1
 * Requires PHP:      8.0
 * Author:            sn4p.dev
 * Author URI:        https://sn4p.dev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-api-codeia
 * Domain Path:       /languages
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'CODEIA_VERSION', '0.5.0' );
define( 'CODEIA_MIN_PHP', '8.0' );
define( 'CODEIA_MIN_WP', '7.1' );
define( 'CODEIA_PLUGIN_FILE', __FILE__ );
define( 'CODEIA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CODEIA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Comprueba que el entorno cumple los minimos antes de cargar nada.
 *
 * Se ejecuta antes del autoloader a proposito: si el entorno no cumple, ni
 * siquiera queremos incluir clases que podrian usar sintaxis no soportada.
 *
 * @return string Cadena vacia si el entorno es valido, o el motivo del fallo.
 */
function codeia_check_environment(): string {
	if ( version_compare( PHP_VERSION, CODEIA_MIN_PHP, '<' ) ) {
		return sprintf(
			/* translators: 1: version de PHP requerida, 2: version instalada. */
			__( 'WP API Codeia requiere PHP %1$s o superior. Esta instalacion usa PHP %2$s.', 'wp-api-codeia' ),
			CODEIA_MIN_PHP,
			PHP_VERSION
		);
	}

	if ( version_compare( get_bloginfo( 'version' ), CODEIA_MIN_WP, '<' ) ) {
		return sprintf(
			/* translators: 1: version de WordPress requerida, 2: version instalada. */
			__( 'WP API Codeia requiere WordPress %1$s o superior. Esta instalacion usa la %2$s.', 'wp-api-codeia' ),
			CODEIA_MIN_WP,
			get_bloginfo( 'version' )
		);
	}

	return '';
}

/**
 * Muestra el aviso de entorno incompatible en el administrador.
 *
 * @param string $message Motivo del fallo.
 * @return void
 */
function codeia_render_environment_notice( string $message ): void {
	add_action(
		'admin_notices',
		static function () use ( $message ): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $message )
			);
		}
	);
}

/**
 * Localiza el autoloader de Composer.
 *
 * @return string Ruta al autoloader, o cadena vacia si no existe.
 */
function codeia_autoloader_path(): string {
	$path = CODEIA_PLUGIN_DIR . 'vendor/autoload.php';

	return file_exists( $path ) ? $path : '';
}

/**
 * Arranca el plugin.
 *
 * @return void
 */
function codeia_bootstrap(): void {
	$error = codeia_check_environment();

	if ( '' !== $error ) {
		codeia_render_environment_notice( $error );
		return;
	}

	$autoloader = codeia_autoloader_path();

	if ( '' === $autoloader ) {
		codeia_render_environment_notice(
			__( 'WP API Codeia no encuentra sus dependencias. Ejecuta "composer install" en el directorio del plugin.', 'wp-api-codeia' )
		);
		return;
	}

	require_once $autoloader;

	// Se registran aqui y no antes: si el entorno no cumple o falta el
	// autoloader, activar el plugin provocaria un fatal en lugar de un aviso.
	register_activation_hook( CODEIA_PLUGIN_FILE, array( WpApi\Codeia\Core\Activator::class, 'activate' ) );
	register_deactivation_hook( CODEIA_PLUGIN_FILE, array( WpApi\Codeia\Core\Activator::class, 'deactivate' ) );

	$codeia_plugin = new WpApi\Codeia\Plugin( new WpApi\Codeia\Container() );

	// Modulos del plugin. Declarar un proveedor no construye nada: la
	// resolucion en el contenedor es perezosa.
	$codeia_plugin->add_provider( new WpApi\Codeia\Schema\SchemaServiceProvider() );
	$codeia_plugin->add_provider( new WpApi\Codeia\Auth\AuthServiceProvider() );
	$codeia_plugin->add_provider( new WpApi\Codeia\Permissions\PermissionsServiceProvider() );

	$codeia_plugin->boot();
}

codeia_bootstrap();

<?php
/**
 * Configuracion de la suite de tests de WordPress.
 *
 * Los tests de integracion corren contra la instalacion de Local, usando su
 * MySQL pero una base de datos APARTE.
 *
 * ⚠ La suite de tests de WordPress ejecuta DROP en todas las tablas de la base
 * de datos que se le indique en cada arranque. DB_NAME debe apuntar SIEMPRE a
 * una base de datos exclusiva de tests. Apuntarla a "local" destruiria el
 * sitio.
 *
 * Los valores por defecto son los de Local (root/root en 127.0.0.1) y se
 * pueden sobrescribir por variables de entorno para otra maquina.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

/**
 * Devuelve una variable de entorno o su valor por defecto.
 *
 * @param string $name     Nombre de la variable.
 * @param string $fallback Valor por defecto.
 * @return string
 */
function codeia_test_env( string $name, string $fallback ): string {
	$value = getenv( $name );

	return ( false === $value || '' === $value ) ? $fallback : $value;
}

// Instalacion de WordPress contra la que se ejecutan los tests.
// El plugin vive en app/public/wp-content/plugins/wp-api-codeia.
define( 'ABSPATH', dirname( __DIR__, 3 ) . '/' );

// Base de datos EXCLUSIVA de tests. Nunca "local".
define( 'DB_NAME', codeia_test_env( 'CODEIA_TEST_DB_NAME', 'local_tests' ) );
define( 'DB_USER', codeia_test_env( 'CODEIA_TEST_DB_USER', 'root' ) );
define( 'DB_PASSWORD', codeia_test_env( 'CODEIA_TEST_DB_PASSWORD', 'root' ) );
define( 'DB_HOST', codeia_test_env( 'CODEIA_TEST_DB_HOST', '127.0.0.1:10011' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

// Prefijo propio: aisla aun mas por si alguien apunta mal la base de datos.
$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', codeia_test_env( 'CODEIA_TEST_DOMAIN', 'session21.local' ) );
define( 'WP_TESTS_EMAIL', 'admin@session21.local' );
define( 'WP_TESTS_TITLE', 'Session21 Tests' );

define( 'WP_PHP_BINARY', codeia_test_env( 'CODEIA_TEST_PHP_BINARY', 'php' ) );

define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );

// El plugin exige HTTPS para emitir credenciales; en tests no lo hay.
define( 'CODEIA_ALLOW_INSECURE_AUTH', true );

// Sales de prueba. No son secretos: la base de datos se borra en cada arranque.
define( 'AUTH_KEY', 'codeia-tests-auth-key' );
define( 'SECURE_AUTH_KEY', 'codeia-tests-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'codeia-tests-logged-in-key' );
define( 'NONCE_KEY', 'codeia-tests-nonce-key' );
define( 'AUTH_SALT', 'codeia-tests-auth-salt' );
define( 'SECURE_AUTH_SALT', 'codeia-tests-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'codeia-tests-logged-in-salt' );
define( 'NONCE_SALT', 'codeia-tests-nonce-salt' );

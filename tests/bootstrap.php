<?php
/**
 * Bootstrap de la suite de tests.
 *
 * Elige entre el arranque unitario (WordPress mockeado con Brain Monkey) y el
 * de integracion (WordPress real de Local), segun la suite en ejecucion.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

$codeia_root = dirname( __DIR__ );

require_once $codeia_root . '/vendor/autoload.php';

/**
 * Determina si se esta ejecutando la suite de integracion.
 *
 * @return bool
 */
function codeia_is_integration_run(): bool {
	$argv = $_SERVER['argv'] ?? array();

	foreach ( (array) $argv as $index => $arg ) {
		if ( 'integration' === $arg ) {
			return true;
		}

		if ( str_starts_with( (string) $arg, '--testsuite' ) ) {
			$value = str_contains( (string) $arg, '=' )
				? explode( '=', (string) $arg, 2 )[1]
				: ( $argv[ $index + 1 ] ?? '' );

			if ( 'integration' === $value ) {
				return true;
			}
		}
	}

	return false;
}

if ( ! codeia_is_integration_run() ) {
	// Suite unitaria: constantes minimas para que las guardas ABSPATH pasen.
	// No se carga WordPress; Brain Monkey mockea sus funciones por test.
	defined( 'ABSPATH' ) || define( 'ABSPATH', $codeia_root . '/tests/stubs/' );
	defined( 'CODEIA_VERSION' ) || define( 'CODEIA_VERSION', '0.2.0' );
	defined( 'CODEIA_PLUGIN_DIR' ) || define( 'CODEIA_PLUGIN_DIR', $codeia_root . '/' );
	defined( 'CODEIA_PLUGIN_URL' ) || define( 'CODEIA_PLUGIN_URL', 'https://session21.local/wp-content/plugins/wp-api-codeia/' );
	defined( 'CODEIA_PLUGIN_FILE' ) || define( 'CODEIA_PLUGIN_FILE', $codeia_root . '/wp-api-codeia.php' );

	// Constantes de tiempo del nucleo que usan CacheManager y Logger.
	// WP_Error no lo aporta Brain Monkey. La suite de integracion usa la clase
	// real de WordPress; aqui hace falta un equivalente reducido.
	require_once $codeia_root . '/tests/stubs/wp-error.php';

	defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
	defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
	defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

	return;
}

/*
 * Suite de integracion: arranca el WordPress real de Local.
 *
 * wp-phpunit trae el framework de tests del nucleo como dependencia de
 * Composer, con la version fijada a la de WordPress instalada (7.1). La
 * configuracion —incluida la base de datos EXCLUSIVA de tests— vive en
 * wp-tests-config.php.
 */
$codeia_wp_phpunit = $codeia_root . '/vendor/wp-phpunit/wp-phpunit';

if ( ! file_exists( $codeia_wp_phpunit . '/includes/functions.php' ) ) {
	fwrite( STDERR, 'Falta wp-phpunit. Ejecuta: composer install' . PHP_EOL );
	exit( 1 );
}

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $codeia_root . '/wp-tests-config.php' );
}

require_once $codeia_wp_phpunit . '/includes/functions.php';

// El plugin se carga como mu-plugin para que este activo sin depender del
// estado de activacion del sitio de Local.
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $codeia_root ): void {
		require $codeia_root . '/wp-api-codeia.php';
	}
);

require $codeia_wp_phpunit . '/includes/bootstrap.php';

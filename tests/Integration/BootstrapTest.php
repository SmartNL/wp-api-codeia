<?php
/**
 * Tests de integracion del arranque.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Integration;

use WP_UnitTestCase;
use WpApi\Codeia\Container;
use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Core\Logger;
use WpApi\Codeia\Plugin;

/**
 * @covers \WpApi\Codeia\Plugin
 */
final class BootstrapTest extends WP_UnitTestCase {

	public function test_el_plugin_esta_cargado(): void {
		$this->assertTrue( defined( 'CODEIA_VERSION' ) );
		$this->assertTrue( class_exists( Plugin::class ) );
	}

	public function test_la_version_de_la_constante_coincide_con_la_cabecera(): void {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( CODEIA_PLUGIN_FILE, false, false );

		$this->assertSame(
			CODEIA_VERSION,
			$data['Version'],
			'La version de la cabecera y la de CODEIA_VERSION deben ir sincronizadas.'
		);
	}

	public function test_boot_engancha_init_con_prioridad_20(): void {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertSame(
			20,
			has_action( 'init', array( $plugin, 'boot_providers' ) ),
			'Los CPT de terceros se registran en init a prioridad 10; hay que ir despues.'
		);
	}

	public function test_boot_engancha_plugins_loaded_en_dos_fases(): void {
		$plugin = new Plugin( new Container() );
		$plugin->boot();

		$this->assertSame( 5, has_action( 'plugins_loaded', array( $plugin, 'boot_core' ) ) );
		$this->assertSame( 10, has_action( 'plugins_loaded', array( $plugin, 'register_providers' ) ) );
	}

	public function test_boot_es_idempotente(): void {
		$plugin = new Plugin( new Container() );

		$plugin->boot();
		$plugin->boot();

		$this->assertSame( 20, has_action( 'init', array( $plugin, 'boot_providers' ) ) );
	}

	public function test_boot_core_registra_los_servicios_de_infraestructura(): void {
		$container = new Container();
		$plugin    = new Plugin( $container );

		$plugin->boot_core();

		foreach ( array( Config::class, CacheManager::class, EventDispatcher::class, Logger::class ) as $service ) {
			$this->assertTrue( $container->has( $service ), "Falta el servicio {$service}." );
		}
	}

	public function test_los_servicios_se_construyen_de_forma_perezosa(): void {
		$container = new Container();
		$plugin    = new Plugin( $container );

		$plugin->boot_core();

		$reflection = new \ReflectionClass( $container );
		$instances  = $reflection->getProperty( 'instances' );
		$instances->setAccessible( true );

		$this->assertSame(
			array(),
			$instances->getValue( $container ),
			'Registrar no debe construir: una carga de front-end no paga el grafo.'
		);
	}

	public function test_el_logger_toma_su_nivel_de_la_configuracion(): void {
		$container = new Container();
		$plugin    = new Plugin( $container );

		$plugin->boot_core();
		$container->instance( Config::class, new Config( array_replace_recursive( Config::defaults(), array( 'logging' => array( 'level' => Logger::ERROR ) ) ) ) );

		$logger = $container->get( Logger::class );

		$this->assertTrue( $logger->should_log( Logger::ERROR ) );
		$this->assertFalse( $logger->should_log( Logger::WARNING ) );
	}

	public function test_una_peticion_de_front_end_no_registra_rutas_rest(): void {
		$this->assertFalse(
			has_action( 'rest_api_init', 'codeia_bootstrap' ),
			'El registro de rutas es competencia del sprint 5, no del arranque.'
		);
	}
}

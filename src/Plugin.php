<?php
/**
 * Orquestador del arranque del plugin.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Activator;
use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Core\Logger;
use WpApi\Codeia\Core\ServiceProvider;

/**
 * Punto de entrada del grafo de servicios.
 *
 * Se instancia una sola vez desde wp-api-codeia.php, pero NO es un singleton
 * clasico: no expone get_instance() ni publica los subsistemas como
 * propiedades globales. Quien necesita un servicio lo pide al contenedor, de
 * modo que las dependencias son explicitas y sustituibles en tests.
 */
final class Plugin {

	/**
	 * Contenedor de servicios.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Proveedores registrados.
	 *
	 * @var ServiceProvider[]
	 */
	private array $providers = array();

	/**
	 * Indica si boot() ya se ejecuto.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Construye el plugin.
	 *
	 * @param Container $container Contenedor de servicios.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Devuelve el contenedor.
	 *
	 * @return Container
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Engancha el arranque a WordPress.
	 *
	 * Las prioridades no son arbitrarias, estan justificadas en
	 * docs/arquitectura.md:
	 *
	 * - plugins_loaded 5:  el contenedor y la configuracion existen ya cuando
	 *                      cualquier otro plugin del sitio se ha cargado, asi
	 *                      que se puede detectar si ACF o JetEngine estan.
	 * - plugins_loaded 10: register() de todos los proveedores, despues de
	 *                      que la configuracion diga cuales estan activos.
	 * - init 20:           boot(). Los CPT de terceros se registran en init
	 *                      con prioridad 10; enganchar antes daria un catalogo
	 *                      vacio de forma intermitente.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'plugins_loaded', array( $this, 'boot_core' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'register_providers' ), 10 );
		add_action( 'init', array( $this, 'boot_providers' ), 20 );
		add_action( 'init', array( $this, 'load_textdomain' ), 5 );
		add_action( Activator::CRON_PURGE_LOGS, array( $this, 'purge_logs' ) );
	}

	/**
	 * Declara los servicios de infraestructura.
	 *
	 * Todas las factorias son perezosas: nada se construye hasta que alguien
	 * lo pide, de modo que una carga del front-end que no toca la API no paga
	 * el coste de instanciar el grafo.
	 *
	 * @return void
	 */
	public function boot_core(): void {
		$this->container->set(
			Config::class,
			static fn (): Config => new Config()
		);

		$this->container->set(
			CacheManager::class,
			static fn (): CacheManager => new CacheManager()
		);

		$this->container->set(
			EventDispatcher::class,
			static fn (): EventDispatcher => new EventDispatcher()
		);

		$this->container->set(
			Logger::class,
			static function ( Container $container ): Logger {
				$config = $container->get( Config::class );

				return new Logger( (string) $config->get( 'logging.level', Logger::INFO ) );
			}
		);
	}

	/**
	 * Anade un proveedor a la lista.
	 *
	 * @param ServiceProvider $provider Proveedor.
	 * @return void
	 */
	public function add_provider( ServiceProvider $provider ): void {
		$this->providers[] = $provider;
	}

	/**
	 * Ejecuta register() en todos los proveedores.
	 *
	 * @return void
	 */
	public function register_providers(): void {
		$providers = $this->container->get( EventDispatcher::class )
			->filter( 'plugin/providers', $this->providers );

		if ( is_array( $providers ) ) {
			$this->providers = array_values(
				array_filter(
					$providers,
					static fn ( $provider ): bool => $provider instanceof ServiceProvider
				)
			);
		}

		foreach ( $this->providers as $provider ) {
			$provider->register( $this->container );
		}
	}

	/**
	 * Ejecuta boot() en todos los proveedores.
	 *
	 * @return void
	 */
	public function boot_providers(): void {
		foreach ( $this->providers as $provider ) {
			$provider->boot( $this->container );
		}

		$this->container->get( EventDispatcher::class )->emit( 'plugin/booted', $this->container );
	}

	/**
	 * Carga las traducciones.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'wp-api-codeia',
			false,
			dirname( plugin_basename( CODEIA_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Purga los logs antiguos. Enganchado al cron diario.
	 *
	 * @return void
	 */
	public function purge_logs(): void {
		$days = (int) $this->container->get( Config::class )->get( 'logging.retention_days', 30 );

		Logger::purge_older_than( max( 1, $days ) );
	}
}

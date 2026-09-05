<?php
/**
 * Proveedor de servicios del dashboard.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Admin;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Container;
use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\Logger;
use WpApi\Codeia\Core\ServiceProvider;
use WpApi\Codeia\Schema\SchemaRegistry;

/**
 * Declara y arranca el dashboard administrativo.
 */
final class AdminServiceProvider implements ServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $container Contenedor.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->set(
			Sanitizer::class,
			static function ( Container $c ): Sanitizer {
				return new Sanitizer( $c->get( Config::class ), $c->get( Logger::class ) );
			}
		);

		$container->set(
			StatusChecker::class,
			static function ( Container $c ): StatusChecker {
				return new StatusChecker(
					$c->get( CacheManager::class ),
					$c->get( Config::class ),
					$c->get( SchemaRegistry::class )
				);
			}
		);

		$container->set(
			ConfigExporter::class,
			static function ( Container $c ): ConfigExporter {
				return new ConfigExporter( $c->get( Config::class ) );
			}
		);

		$container->set(
			Menu::class,
			static function (): Menu {
				return new Menu();
			}
		);

		$container->set(
			AssetLoader::class,
			static function ( Container $c ): AssetLoader {
				return new AssetLoader( $c->get( Config::class ) );
			}
		);

		$container->set(
			InternalRestController::class,
			static function ( Container $c ): InternalRestController {
				return new InternalRestController(
					$c->get( SchemaRegistry::class ),
					$c->get( Config::class ),
					$c->get( Sanitizer::class ),
					$c->get( StatusChecker::class ),
					$c->get( ConfigExporter::class ),
					$c->get( Logger::class )
				);
			}
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $container Contenedor.
	 * @return void
	 */
	public function boot( Container $container ): void {
		// Las rutas internas se registran siempre: el cliente React las
		// necesita, y su permission_callback ya exige manage_options.
		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$container->get( InternalRestController::class )->register_routes();
			}
		);

		if ( ! is_admin() ) {
			return;
		}

		$container->get( Menu::class )->register_hooks();
		$container->get( AssetLoader::class )->register_hooks();

		add_action(
			'admin_init',
			static function () use ( $container ): void {
				register_setting(
					'codeia_settings_group',
					Config::OPTION,
					array(
						'type'              => 'object',
						'sanitize_callback' => array( $container->get( Sanitizer::class ), 'sanitize' ),
						'show_in_rest'      => false,
						'default'           => Config::defaults(),
					)
				);
			}
		);
	}
}

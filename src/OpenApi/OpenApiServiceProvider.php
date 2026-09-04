<?php
/**
 * Proveedor de servicios de OpenAPI y rendimiento.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\OpenApi;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Api\DocsController;
use WpApi\Codeia\Api\RouteRegistrar;
use WpApi\Codeia\Container;
use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Cache\StampedeLock;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EtagManager;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Core\ServiceProvider;
use WpApi\Codeia\Permissions\FieldVisibility;
use WpApi\Codeia\Schema\SchemaCache;
use WpApi\Codeia\Schema\SchemaRegistry;

/**
 * Declara la generacion de OpenAPI, el cerrojo de cache y los ETag.
 */
final class OpenApiServiceProvider implements ServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $container Contenedor.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->set(
			StampedeLock::class,
			static function ( Container $c ): StampedeLock {
				return new StampedeLock( $c->get( CacheManager::class ) );
			}
		);

		$container->set(
			EtagManager::class,
			static function (): EtagManager {
				return new EtagManager();
			}
		);

		$container->set(
			SchemaMapper::class,
			static function (): SchemaMapper {
				return new SchemaMapper();
			}
		);

		$container->set(
			SecuritySchemeBuilder::class,
			static function ( Container $c ): SecuritySchemeBuilder {
				return new SecuritySchemeBuilder( $c->get( Config::class ) );
			}
		);

		$container->set(
			SpecCache::class,
			static function ( Container $c ): SpecCache {
				return new SpecCache(
					$c->get( CacheManager::class ),
					$c->get( StampedeLock::class ),
					$c->get( SchemaCache::class ),
					$c->get( FieldVisibility::class )
				);
			}
		);

		$container->set(
			SpecGenerator::class,
			static function ( Container $c ): SpecGenerator {
				return new SpecGenerator(
					$c->get( SchemaRegistry::class ),
					$c->get( RouteRegistrar::class ),
					$c->get( SchemaMapper::class ),
					$c->get( SecuritySchemeBuilder::class ),
					$c->get( FieldVisibility::class ),
					$c->get( Config::class ),
					$c->get( EventDispatcher::class )
				);
			}
		);

		$container->set(
			DocsController::class,
			static function ( Container $c ): DocsController {
				return new DocsController(
					$c->get( SpecGenerator::class ),
					$c->get( SpecCache::class ),
					$c->get( SchemaRegistry::class ),
					$c->get( Config::class )
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
		$config = $container->get( Config::class );

		if ( $config->get( 'modules.openapi', false ) ) {
			add_action(
				'rest_api_init',
				static function () use ( $container ): void {
					$container->get( DocsController::class )->register_routes();
				}
			);
		}

		// ETag en las respuestas de lectura del namespace propio.
		add_filter(
			'rest_post_dispatch',
			static function ( $response, $server, $request ) use ( $container ) {
				if ( ! $response instanceof \WP_REST_Response || 'GET' !== $request->get_method() ) {
					return $response;
				}

				$namespace = (string) $container->get( Config::class )->get( 'namespace', 'codeia' );

				if ( ! str_starts_with( ltrim( $request->get_route(), '/' ), $namespace . '/' ) ) {
					return $response;
				}

				return $container->get( EtagManager::class )->apply( $response, $request );
			},
			10,
			3
		);
	}
}

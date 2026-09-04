<?php
/**
 * Proveedor de servicios de la API REST.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Api;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Auth\SecretManager;
use WpApi\Codeia\Container;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Core\ServiceProvider;
use WpApi\Codeia\Modules\RewriteModule;
use WpApi\Codeia\Permissions\CollectionRestrictor;
use WpApi\Codeia\Permissions\FieldVisibility;
use WpApi\Codeia\Permissions\PermissionResolver;
use WpApi\Codeia\Rewrite\CollisionDetector;
use WpApi\Codeia\Schema\SchemaRegistry;

/**
 * Declara y arranca los endpoints dinamicos y el alias de rutas.
 */
final class ApiServiceProvider implements ServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $container Contenedor.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->set(
			QueryBuilder::class,
			static function ( Container $c ): QueryBuilder {
				return new QueryBuilder(
					(int) $c->get( Config::class )->get( 'limits.meta_clauses_max', 4 )
				);
			}
		);

		$container->set(
			CursorPaginator::class,
			static function ( Container $c ): CursorPaginator {
				$paginator = new CursorPaginator( $c->get( SecretManager::class )->secret() );
				$paginator->register_hooks();

				return $paginator;
			}
		);

		$container->set(
			FieldProjector::class,
			static function ( Container $c ): FieldProjector {
				return new FieldProjector( $c->get( FieldVisibility::class ) );
			}
		);

		$container->set(
			ControllerFactory::class,
			static function ( Container $c ): ControllerFactory {
				return new ControllerFactory(
					$c->get( PermissionResolver::class ),
					$c->get( FieldVisibility::class ),
					$c->get( CollectionRestrictor::class ),
					$c->get( FieldProjector::class ),
					$c->get( QueryBuilder::class ),
					$c->get( CursorPaginator::class ),
					$c->get( Config::class )
				);
			}
		);

		$container->set(
			RouteRegistrar::class,
			static function ( Container $c ): RouteRegistrar {
				return new RouteRegistrar(
					$c->get( SchemaRegistry::class ),
					$c->get( ControllerFactory::class ),
					$c->get( Config::class ),
					$c->get( EventDispatcher::class )
				);
			}
		);

		$container->set(
			CollisionDetector::class,
			static function (): CollisionDetector {
				return new CollisionDetector();
			}
		);

		$container->set(
			RewriteModule::class,
			static function ( Container $c ): RewriteModule {
				return new RewriteModule(
					$c->get( Config::class ),
					$c->get( CollisionDetector::class )
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
		// Unico momento valido para register_rest_route(). Al ir aqui, una
		// carga del front-end no paga el coste de construir definiciones.
		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$container->get( RouteRegistrar::class )->register();
			}
		);

		if ( $container->get( Config::class )->get( 'modules.rewrite', false ) ) {
			$container->get( RewriteModule::class )->register_hooks();
		}
	}
}

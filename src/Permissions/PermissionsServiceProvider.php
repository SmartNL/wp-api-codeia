<?php
/**
 * Proveedor de servicios del modulo de permisos.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Permissions;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Container;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Core\ServiceProvider;

/**
 * Declara el resolutor de permisos y sus colaboradores.
 *
 * El modulo es obligatorio: sin el, nada decide quien accede a que.
 */
final class PermissionsServiceProvider implements ServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $container Contenedor.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->set(
			PermissionMatrix::class,
			static function ( Container $c ): PermissionMatrix {
				return new PermissionMatrix( $c->get( Config::class ) );
			}
		);

		$container->set(
			CapabilityMapper::class,
			static function (): CapabilityMapper {
				return new CapabilityMapper();
			}
		);

		$container->set(
			PermissionResolver::class,
			static function ( Container $c ): PermissionResolver {
				return new PermissionResolver(
					$c->get( PermissionMatrix::class ),
					$c->get( CapabilityMapper::class ),
					$c->get( EventDispatcher::class )
				);
			}
		);

		$container->set(
			FieldVisibility::class,
			static function ( Container $c ): FieldVisibility {
				return new FieldVisibility(
					$c->get( PermissionResolver::class ),
					$c->get( EventDispatcher::class )
				);
			}
		);

		$container->set(
			CollectionRestrictor::class,
			static function ( Container $c ): CollectionRestrictor {
				return new CollectionRestrictor(
					$c->get( CapabilityMapper::class ),
					$c->get( PermissionResolver::class ),
					$c->get( EventDispatcher::class )
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
		// Las decisiones se cachean por peticion. Al cambiar el usuario
		// actual la cache deja de valer: se vacia para no arrastrar la
		// visibilidad de otra identidad.
		add_action(
			'set_current_user',
			static function () use ( $container ): void {
				$container->get( PermissionResolver::class )->flush();
			}
		);
	}
}

<?php
/**
 * Proveedor de servicios de seguridad y medios.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Security;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Api\MediaController;
use WpApi\Codeia\Container;
use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\ServiceProvider;
use WpApi\Codeia\Media\Deduplicator;
use WpApi\Codeia\Media\ExifCleaner;
use WpApi\Codeia\Media\MimeValidator;
use WpApi\Codeia\Media\QuotaManager;
use WpApi\Codeia\Permissions\PermissionResolver;

/**
 * Declara el rate limiting, CORS y el modulo de medios.
 */
final class SecurityServiceProvider implements ServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $container Contenedor.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->set(
			SlidingWindow::class,
			static function ( Container $c ): SlidingWindow {
				return new SlidingWindow( $c->get( CacheManager::class ) );
			}
		);

		$container->set(
			IpResolver::class,
			static function ( Container $c ): IpResolver {
				$trusted = $c->get( Config::class )->get( 'security.trusted_proxies', array() );

				return new IpResolver( is_array( $trusted ) ? $trusted : array() );
			}
		);

		$container->set(
			RateLimiter::class,
			static function ( Container $c ): RateLimiter {
				return new RateLimiter(
					$c->get( SlidingWindow::class ),
					$c->get( IpResolver::class ),
					$c->get( CacheManager::class )
				);
			}
		);

		$container->set(
			CorsHandler::class,
			static function ( Container $c ): CorsHandler {
				return new CorsHandler( $c->get( Config::class ) );
			}
		);

		$container->set(
			MimeValidator::class,
			static function (): MimeValidator {
				return new MimeValidator();
			}
		);

		$container->set(
			QuotaManager::class,
			static function ( Container $c ): QuotaManager {
				$config = $c->get( Config::class );

				return new QuotaManager(
					$c->get( SlidingWindow::class ),
					(int) $config->get( 'media.quota_files', QuotaManager::DEFAULT_FILES ),
					(int) $config->get( 'media.quota_bytes', QuotaManager::DEFAULT_BYTES )
				);
			}
		);

		$container->set(
			Deduplicator::class,
			static function (): Deduplicator {
				return new Deduplicator();
			}
		);

		$container->set(
			ExifCleaner::class,
			static function ( Container $c ): ExifCleaner {
				return new ExifCleaner(
					(string) $c->get( Config::class )->get( 'media.exif_mode', ExifCleaner::MODE_STRIP_GPS )
				);
			}
		);

		$container->set(
			MediaController::class,
			static function ( Container $c ): MediaController {
				return new MediaController(
					$c->get( PermissionResolver::class ),
					$c->get( MimeValidator::class ),
					$c->get( QuotaManager::class ),
					$c->get( Deduplicator::class ),
					$c->get( ExifCleaner::class ),
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

		$container->get( CorsHandler::class )->register_hooks();

		add_filter(
			'rest_pre_dispatch',
			static function ( $result, $server, $request ) use ( $container ) {
				$namespace = (string) $container->get( Config::class )->get( 'namespace', 'codeia' );

				if ( ! str_starts_with( ltrim( $request->get_route(), '/' ), $namespace . '/' ) ) {
					return $result;
				}

				$limiter = $container->get( RateLimiter::class );
				$state   = $limiter->check( get_current_user_id(), $_SERVER );

				if ( ! $state['allowed'] ) {
					return $limiter->too_many_requests( $state );
				}

				return $result;
			},
			10,
			3
		);

		// El modulo de medios esta desactivado por defecto: la subida es la
		// superficie de ataque mas peligrosa de una API.
		if ( $config->get( 'modules.media', false ) ) {
			add_action(
				'rest_api_init',
				static function () use ( $container ): void {
					$container->get( MediaController::class )->register_routes();
				}
			);
		}
	}
}

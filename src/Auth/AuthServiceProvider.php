<?php
/**
 * Proveedor de servicios del modulo de autenticacion.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Auth;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Api\AuthController;
use WpApi\Codeia\Auth\Authenticators\ApiKeyAuthenticator;
use WpApi\Codeia\Auth\Authenticators\AppPasswordAuthenticator;
use WpApi\Codeia\Auth\Authenticators\JwtAuthenticator;
use WpApi\Codeia\Auth\Authenticators\UserTokenAuthenticator;
use WpApi\Codeia\Auth\Jwt\JwtCodec;
use WpApi\Codeia\Container;
use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Core\Logger;
use WpApi\Codeia\Core\ServiceProvider;

/**
 * Declara y arranca la autenticacion.
 */
final class AuthServiceProvider implements ServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $container Contenedor.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->set(
			SecretManager::class,
			static function (): SecretManager {
				return new SecretManager();
			}
		);

		$container->set(
			JwtCodec::class,
			static function ( Container $c ): JwtCodec {
				$secrets = $c->get( SecretManager::class );

				return new JwtCodec( $secrets->secret(), $secrets->issuer() );
			}
		);

		$container->set(
			TokenVersion::class,
			static function (): TokenVersion {
				return new TokenVersion();
			}
		);

		$container->set(
			RevocationList::class,
			static function ( Container $c ): RevocationList {
				return new RevocationList( $c->get( CacheManager::class ) );
			}
		);

		$container->set(
			TokenRepository::class,
			static function (): TokenRepository {
				return new TokenRepository();
			}
		);

		$container->set(
			RefreshTokenService::class,
			static function ( Container $c ): RefreshTokenService {
				return new RefreshTokenService( $c->get( Logger::class ) );
			}
		);

		$container->set(
			AuthenticatorChain::class,
			static function ( Container $c ): AuthenticatorChain {
				$chain = new AuthenticatorChain( $c->get( EventDispatcher::class ) );

				$chain->add(
					new JwtAuthenticator(
						$c->get( JwtCodec::class ),
						$c->get( TokenVersion::class ),
						$c->get( RevocationList::class )
					)
				);
				$chain->add( new UserTokenAuthenticator( $c->get( TokenRepository::class ) ) );
				$chain->add( new ApiKeyAuthenticator( $c->get( TokenRepository::class ) ) );
				$chain->add( new AppPasswordAuthenticator() );

				return $chain;
			}
		);

		$container->set(
			AuthMiddleware::class,
			static function ( Container $c ): AuthMiddleware {
				return new AuthMiddleware( $c->get( AuthenticatorChain::class ) );
			}
		);

		$container->set(
			AuthController::class,
			static function ( Container $c ): AuthController {
				return new AuthController(
					$c->get( JwtCodec::class ),
					$c->get( RefreshTokenService::class ),
					$c->get( TokenVersion::class ),
					$c->get( Config::class ),
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
		$container->get( AuthMiddleware::class )->register_hooks();
		$container->get( TokenVersion::class )->register_hooks();

		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$container->get( AuthController::class )->register_routes();
			}
		);
	}
}

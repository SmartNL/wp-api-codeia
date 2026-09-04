<?php
/**
 * Proveedor de servicios del modulo de esquema.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Schema;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Container;
use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Core\ServiceProvider;
use WpApi\Codeia\Schema\Providers\AcfProvider;
use WpApi\Codeia\Schema\Providers\DbSampleProvider;
use WpApi\Codeia\Schema\Providers\JetEngineProvider;
use WpApi\Codeia\Schema\Providers\ManualProvider;
use WpApi\Codeia\Schema\Providers\MetaBoxProvider;
use WpApi\Codeia\Schema\Providers\NativeProvider;

/**
 * Declara y arranca el registro de esquema.
 *
 * Todo se registra como factoria perezosa: una carga de front-end que no toca
 * la API no construye ningun proveedor ni consulta la base de datos.
 */
final class SchemaServiceProvider implements ServiceProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @param Container $container Contenedor.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->set(
			ExclusionList::class,
			static function ( Container $c ): ExclusionList {
				$custom = $c->get( Config::class )->get( 'schema.excluded_keys', array() );

				return new ExclusionList( is_array( $custom ) ? $custom : array() );
			}
		);

		$container->set(
			TypeInferrer::class,
			static function (): TypeInferrer {
				return new TypeInferrer();
			}
		);

		$container->set(
			FieldNormalizer::class,
			static function (): FieldNormalizer {
				return new FieldNormalizer();
			}
		);

		$container->set(
			ConflictResolver::class,
			static function (): ConflictResolver {
				return new ConflictResolver();
			}
		);

		$container->set(
			SchemaCache::class,
			static function ( Container $c ): SchemaCache {
				return new SchemaCache(
					$c->get( CacheManager::class ),
					$c->get( Config::class )
				);
			}
		);

		$container->set(
			SchemaRegistry::class,
			static function ( Container $c ): SchemaRegistry {
				$registry = new SchemaRegistry(
					$c->get( SchemaCache::class ),
					$c->get( FieldNormalizer::class ),
					$c->get( ConflictResolver::class ),
					$c->get( EventDispatcher::class )
				);

				// El orden importa poco porque la resolucion es por confianza,
				// pero se registran de menor a mayor autoridad para que el
				// catalogo del dashboard se lea de forma natural.
				$registry->add_provider(
					new DbSampleProvider(
						$c->get( ExclusionList::class ),
						$c->get( TypeInferrer::class )
					)
				);
				$registry->add_provider( new AcfProvider() );
				$registry->add_provider( new MetaBoxProvider() );
				$registry->add_provider( new JetEngineProvider() );
				$registry->add_provider( new NativeProvider() );
				$registry->add_provider( new ManualProvider( $c->get( Config::class ) ) );

				return $registry;
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
		// La invalidacion es implicita por el hash de entorno incrustado en la
		// clave de cache, asi que no hace falta borrar nada al activar o
		// desactivar plugins: la clave cambia y las entradas viejas expiran.
		// Solo se limpia el nivel en memoria, que si es por peticion.
		add_action(
			'activated_plugin',
			static function () use ( $container ): void {
				$container->get( SchemaCache::class )->reset_env_hash();
			}
		);

		add_action(
			'deactivated_plugin',
			static function () use ( $container ): void {
				$container->get( SchemaCache::class )->reset_env_hash();
			}
		);
	}
}

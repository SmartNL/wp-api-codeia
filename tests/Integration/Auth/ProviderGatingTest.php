<?php
/**
 * La configuracion decide que proveedores de autenticacion se montan.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Integration\Auth;

use WP_UnitTestCase;
use WpApi\Codeia\Auth\AuthServiceProvider;
use WpApi\Codeia\Auth\AuthenticatorChain;
use WpApi\Codeia\Container;
use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Core\Logger;

/**
 * @covers \WpApi\Codeia\Auth\AuthServiceProvider
 */
final class ProviderGatingTest extends WP_UnitTestCase {

	/**
	 * Construye la cadena con la configuracion indicada.
	 *
	 * @param array<string, bool> $providers Proveedores y su estado.
	 * @return string[] Identificadores montados.
	 */
	private function montados( array $providers ): array {
		$config = new Config( Config::defaults() );
		$config->set( 'auth.providers', $providers );

		$container = new Container();

		// Los servicios base que registra Plugin::boot_core(); el proveedor de
		// autenticacion los da por presentes.
		$container->set( Config::class, static fn (): Config => $config );
		$container->set( CacheManager::class, static fn (): CacheManager => new CacheManager() );
		$container->set( EventDispatcher::class, static fn (): EventDispatcher => new EventDispatcher() );
		$container->set( Logger::class, static fn (): Logger => new Logger( Logger::ERROR ) );

		$provider = new AuthServiceProvider();
		$provider->register( $container );

		$ids = array();

		foreach ( $container->get( AuthenticatorChain::class )->authenticators() as $authenticator ) {
			$ids[] = $authenticator->id();
		}

		return $ids;
	}

	/**
	 * Antes, los cuatro proveedores se montaban siempre y auth.providers solo
	 * afectaba al documento OpenAPI: desactivar api_key lo ocultaba de la
	 * documentacion pero la credencial se seguia aceptando, que es lo
	 * contrario de lo que espera quien lo desactiva.
	 */
	public function test_un_proveedor_desactivado_no_se_monta(): void {
		$ids = $this->montados(
			array(
				'jwt'          => true,
				'app_password' => false,
				'api_key'      => false,
				'user_token'   => false,
			)
		);

		$this->assertSame( array( 'jwt' ), $ids );
	}

	public function test_se_montan_todos_los_activos(): void {
		$ids = $this->montados(
			array(
				'jwt'          => true,
				'app_password' => true,
				'api_key'      => true,
				'user_token'   => true,
			)
		);

		sort( $ids );

		$this->assertSame( array( 'api_key', 'app_password', 'jwt', 'user_token' ), $ids );
	}

	public function test_sin_ninguno_activo_la_cadena_queda_vacia(): void {
		$this->assertSame( array(), $this->montados( array() ) );
	}

	/**
	 * Criterio de docs/01-autenticacion.md: Application Passwords por coste
	 * cero y respaldo del nucleo, JWT para sesiones cortas con refresh. Las
	 * credenciales de larga vida no se activan solas.
	 */
	public function test_los_valores_por_defecto_siguen_el_criterio_documentado(): void {
		$defaults = Config::defaults()['auth']['providers'];

		$this->assertTrue( $defaults['jwt'] );
		$this->assertTrue( $defaults['app_password'] );
		$this->assertFalse( $defaults['api_key'] );
		$this->assertFalse( $defaults['user_token'] );
	}

	public function test_la_forma_con_enabled_tambien_se_entiende(): void {
		// El generador de OpenAPI acepta tanto un booleano como un array con
		// 'enabled'. La cadena tiene que leer las dos formas o una
		// configuracion importada se interpretaria distinto en cada sitio.
		$ids = $this->montados(
			array(
				'jwt'          => array( 'enabled' => true ),
				'app_password' => array( 'enabled' => false ),
			)
		);

		$this->assertSame( array( 'jwt' ), $ids );
	}
}

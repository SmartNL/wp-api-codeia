<?php
/**
 * Tests de la cadena de autenticacion.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit\Auth;

use WP_Error;
use WpApi\Codeia\Auth\Authenticator;
use WpApi\Codeia\Auth\AuthenticatorChain;
use WpApi\Codeia\Auth\RequestCredentials;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Tests\Unit\TestCase;

/**
 * @covers \WpApi\Codeia\Auth\AuthenticatorChain
 */
final class AuthenticatorChainTest extends TestCase {

	/**
	 * Proveedor de prueba configurable.
	 *
	 * @param string $id       Identificador.
	 * @param int    $priority Prioridad.
	 * @param bool   $handles  Si reclama la peticion.
	 * @param mixed  $result   Resultado de authenticate().
	 * @return Authenticator
	 */
	private function fake( string $id, int $priority, bool $handles, $result ): Authenticator {
		return new class( $id, $priority, $handles, $result ) implements Authenticator {

			public array $calls = array();

			public function __construct(
				private string $identifier,
				private int $order,
				private bool $claims,
				private mixed $outcome
			) {}

			public function id(): string {
				return $this->identifier;
			}

			public function priority(): int {
				return $this->order;
			}

			public function handles( RequestCredentials $credentials ): bool {
				return $this->claims;
			}

			public function authenticate( RequestCredentials $credentials ) {
				return $this->outcome;
			}
		};
	}

	private function chain(): AuthenticatorChain {
		return new AuthenticatorChain( new EventDispatcher() );
	}

	public function test_sin_credenciales_la_identidad_es_anonima(): void {
		$chain = $this->chain();
		$chain->add( $this->fake( 'jwt', 10, true, 7 ) );

		$this->assertSame( 0, $chain->resolve( new RequestCredentials() ) );
	}

	public function test_resuelve_con_el_proveedor_que_reclama(): void {
		$chain = $this->chain();
		$chain->add( $this->fake( 'jwt', 10, false, 1 ) );
		$chain->add( $this->fake( 'api_key', 30, true, 7 ) );

		$this->assertSame( 7, $chain->resolve( new RequestCredentials( 'Bearer x' ) ) );
		$this->assertSame( 'api_key', $chain->resolved_by() );
	}

	public function test_respeta_el_orden_de_prioridad(): void {
		$chain = $this->chain();
		$chain->add( $this->fake( 'tardio', 40, true, 40 ) );
		$chain->add( $this->fake( 'temprano', 10, true, 10 ) );

		$this->assertSame( 10, $chain->resolve( new RequestCredentials( 'Bearer x' ) ) );
		$this->assertSame( 'temprano', $chain->resolved_by() );
	}

	/**
	 * La distincion clave del diseno: una credencial reclamada e invalida
	 * corta la cadena en vez de caer al siguiente proveedor.
	 */
	public function test_una_credencial_reclamada_e_invalida_corta_la_cadena(): void {
		$error = new WP_Error( 'codeia_auth_invalid', 'no vale' );

		$chain = $this->chain();
		$chain->add( $this->fake( 'jwt', 10, true, $error ) );
		$chain->add( $this->fake( 'api_key', 30, true, 99 ) );

		$result = $chain->resolve( new RequestCredentials( 'Bearer x' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( '', $chain->resolved_by() );
	}

	public function test_un_proveedor_que_no_reclama_deja_pasar_al_siguiente(): void {
		$chain = $this->chain();
		$chain->add( $this->fake( 'jwt', 10, false, new WP_Error( 'x', 'y' ) ) );
		$chain->add( $this->fake( 'api_key', 30, true, 5 ) );

		$this->assertSame( 5, $chain->resolve( new RequestCredentials( 'Bearer x' ) ) );
	}

	public function test_si_nadie_reclama_la_identidad_es_anonima(): void {
		$chain = $this->chain();
		$chain->add( $this->fake( 'jwt', 10, false, 1 ) );

		$this->assertSame( 0, $chain->resolve( new RequestCredentials( 'Bearer x' ) ) );
	}

	public function test_un_id_de_usuario_cero_no_cuenta_como_resuelto(): void {
		$chain = $this->chain();
		$chain->add( $this->fake( 'jwt', 10, true, 0 ) );
		$chain->add( $this->fake( 'api_key', 30, true, 8 ) );

		$this->assertSame( 8, $chain->resolve( new RequestCredentials( 'Bearer x' ) ) );
	}

	public function test_los_proveedores_se_devuelven_ordenados(): void {
		$chain = $this->chain();
		$chain->add( $this->fake( 'c', 30, true, 1 ) );
		$chain->add( $this->fake( 'a', 10, true, 1 ) );
		$chain->add( $this->fake( 'b', 20, true, 1 ) );

		$ids = array_map(
			static function ( Authenticator $a ): string {
				return $a->id();
			},
			$chain->authenticators()
		);

		$this->assertSame( array( 'a', 'b', 'c' ), $ids );
	}
}

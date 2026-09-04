<?php
/**
 * Tests de la lectura de credenciales.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit\Auth;

use WpApi\Codeia\Auth\RequestCredentials;
use WpApi\Codeia\Tests\Unit\TestCase;

/**
 * @covers \WpApi\Codeia\Auth\RequestCredentials
 */
final class RequestCredentialsTest extends TestCase {

	public function test_extrae_el_bearer(): void {
		$c = new RequestCredentials( 'Bearer abc.def.ghi' );

		$this->assertSame( 'abc.def.ghi', $c->bearer() );
	}

	public function test_el_esquema_es_insensible_a_mayusculas(): void {
		$c = new RequestCredentials( 'bearer abc' );

		$this->assertSame( 'abc', $c->bearer() );
	}

	public function test_distingue_un_jwt_de_un_token_opaco(): void {
		$jwt   = new RequestCredentials( 'Bearer aaa.bbb.ccc' );
		$opaco = new RequestCredentials( 'Bearer TokenOpacoSinPuntos' );

		$this->assertTrue( $jwt->bearer_is_jwt() );
		$this->assertFalse( $opaco->bearer_is_jwt() );
	}

	public function test_un_bearer_con_dos_puntos_no_es_jwt(): void {
		$c = new RequestCredentials( 'Bearer aaa.bbb' );

		$this->assertFalse( $c->bearer_is_jwt() );
	}

	public function test_extrae_el_basic(): void {
		$c = new RequestCredentials( 'Basic dXNlcjpwYXNz' );

		$this->assertSame( 'dXNlcjpwYXNz', $c->basic() );
	}

	public function test_basic_y_bearer_no_se_confunden(): void {
		$bearer = new RequestCredentials( 'Bearer abc' );
		$basic  = new RequestCredentials( 'Basic abc' );

		$this->assertSame( '', $bearer->basic() );
		$this->assertSame( '', $basic->bearer() );
	}

	public function test_detecta_la_ausencia_de_credenciales(): void {
		$this->assertTrue( ( new RequestCredentials() )->is_empty() );
		$this->assertFalse( ( new RequestCredentials( 'Bearer x' ) )->is_empty() );
		$this->assertFalse( ( new RequestCredentials( '', 'ck_x' ) )->is_empty() );
	}

	public function test_lee_la_cabecera_authorization_del_entorno(): void {
		$c = RequestCredentials::from_server( array( 'HTTP_AUTHORIZATION' => 'Bearer xyz' ) );

		$this->assertSame( 'xyz', $c->bearer() );
	}

	/**
	 * En algunos servidores Authorization no llega a PHP y hay que
	 * recuperarla de la variable de redireccion.
	 */
	public function test_recupera_authorization_de_la_variable_de_redireccion(): void {
		$c = RequestCredentials::from_server( array( 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer xyz' ) );

		$this->assertSame( 'xyz', $c->bearer() );
	}

	public function test_la_cabecera_directa_tiene_prioridad(): void {
		$c = RequestCredentials::from_server(
			array(
				'HTTP_AUTHORIZATION'          => 'Bearer directo',
				'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer redirigido',
			)
		);

		$this->assertSame( 'directo', $c->bearer() );
	}

	public function test_lee_la_api_key(): void {
		$c = RequestCredentials::from_server( array( 'HTTP_X_CODEIA_KEY' => 'ck_abc' ) );

		$this->assertSame( 'ck_abc', $c->api_key() );
	}

	public function test_un_entorno_sin_cabeceras_da_credenciales_vacias(): void {
		$this->assertTrue( RequestCredentials::from_server( array() )->is_empty() );
	}
}

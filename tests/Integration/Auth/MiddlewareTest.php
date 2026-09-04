<?php
/**
 * Integracion del middleware de autenticacion con WordPress.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Integration\Auth;

use WP_UnitTestCase;
use WpApi\Codeia\Auth\AuthMiddleware;
use WpApi\Codeia\Auth\AuthenticatorChain;
use WpApi\Codeia\Auth\Authenticators\JwtAuthenticator;
use WpApi\Codeia\Auth\Jwt\JwtCodec;
use WpApi\Codeia\Auth\RevocationList;
use WpApi\Codeia\Auth\TokenVersion;
use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\EventDispatcher;

/**
 * @covers \WpApi\Codeia\Auth\AuthMiddleware
 * @covers \WpApi\Codeia\Auth\Authenticators\JwtAuthenticator
 */
final class MiddlewareTest extends WP_UnitTestCase {

	private const SECRETO = 'secreto-de-integracion-para-hs256-en-tests';

	private AuthMiddleware $middleware;
	private JwtCodec $codec;
	private TokenVersion $versions;
	private RevocationList $revoked;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->codec    = new JwtCodec( self::SECRETO, home_url() );
		$this->versions = new TokenVersion();
		$this->revoked  = new RevocationList( new CacheManager() );
		$this->user_id  = self::factory()->user->create( array( 'role' => 'editor' ) );

		$chain = new AuthenticatorChain( new EventDispatcher() );
		$chain->add( new JwtAuthenticator( $this->codec, $this->versions, $this->revoked ) );

		$this->middleware = new AuthMiddleware( $chain );
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		parent::tear_down();
	}

	/**
	 * Emite un token valido para el usuario de prueba.
	 *
	 * @param array<string, mixed> $overrides Claims a sobrescribir.
	 * @return string
	 */
	private function token( array $overrides = array() ): string {
		$now = time();

		return $this->codec->encode(
			array_merge(
				array(
					'iss' => home_url(),
					'sub' => $this->user_id,
					'iat' => $now,
					'exp' => $now + 900,
					'jti' => 'jti-de-prueba',
					'tv'  => $this->versions->current( $this->user_id ),
				),
				$overrides
			)
		);
	}

	public function test_la_prioridad_es_la_documentada(): void {
		$this->assertSame(
			15,
			AuthMiddleware::PRIORITY,
			'Por encima de la cookie (10) y por debajo de Application Passwords (20).'
		);
	}

	public function test_resuelve_el_usuario_desde_un_token_valido(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token();

		$this->assertSame( $this->user_id, $this->middleware->determine_user( false ) );
		$this->assertTrue( $this->middleware->authenticated_by_token() );
	}

	public function test_respeta_un_usuario_ya_resuelto_por_cookie(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token();

		$this->assertSame( 99, $this->middleware->determine_user( 99 ) );
		$this->assertFalse( $this->middleware->authenticated_by_token() );
	}

	public function test_sin_credenciales_no_altera_el_usuario(): void {
		$this->assertFalse( $this->middleware->determine_user( false ) );
		$this->assertFalse( $this->middleware->authenticated_by_token() );
	}

	/**
	 * La proteccion CSRF de las peticiones por cookie depende de esto: solo
	 * se devuelve true cuando un proveedor resolvio una identidad.
	 */
	public function test_solo_confirma_al_rest_server_tras_autenticar(): void {
		$this->assertNull(
			$this->middleware->report_errors( null ),
			'Sin autenticar debe dejar pasar el estado previo, no devolver true.'
		);

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token();
		$this->middleware->determine_user( false );

		$this->assertTrue( $this->middleware->report_errors( null ) );
	}

	public function test_una_credencial_invalida_se_reporta_como_error(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer aaa.bbb.ccc';

		$this->middleware->determine_user( false );
		$error = $this->middleware->report_errors( null );

		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'codeia_auth_invalid', $error->get_error_code() );
		$this->assertSame( 401, $error->get_error_data()['status'] );
	}

	public function test_un_token_caducado_no_autentica(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token( array( 'exp' => time() - 3600 ) );

		$this->middleware->determine_user( false );

		$this->assertInstanceOf( \WP_Error::class, $this->middleware->report_errors( null ) );
	}

	public function test_cambiar_la_version_de_token_invalida_los_emitidos(): void {
		$token = $this->token();

		$this->versions->bump( $this->user_id );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$this->middleware->determine_user( false );

		$this->assertInstanceOf(
			\WP_Error::class,
			$this->middleware->report_errors( null ),
			'Subir tv debe dejar fuera a los tokens emitidos antes.'
		);
	}

	public function test_un_token_revocado_individualmente_no_autentica(): void {
		$token = $this->token();
		$this->revoked->revoke( 'jti-de-prueba', 900 );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
		$this->middleware->determine_user( false );

		$this->assertInstanceOf( \WP_Error::class, $this->middleware->report_errors( null ) );
	}

	public function test_un_token_de_otra_instalacion_no_autentica(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token( array( 'iss' => 'https://otro.test' ) );

		$this->middleware->determine_user( false );

		$this->assertInstanceOf( \WP_Error::class, $this->middleware->report_errors( null ) );
	}

	public function test_no_hay_recursion_al_resolver(): void {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token();

		// Si el middleware llamase a current_user_can() dentro del filtro,
		// reentraria y agotaria la pila.
		$this->assertSame( $this->user_id, $this->middleware->determine_user( false ) );
		$this->assertSame( $this->user_id, $this->middleware->determine_user( false ) );
	}
}

<?php
/**
 * Endpoints de emision y renovacion de credenciales.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Integration\Auth;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use WpApi\Codeia\Auth\Jwt\JwtCodec;
use WpApi\Codeia\Auth\SecretManager;

/**
 * @covers \WpApi\Codeia\Api\AuthController
 */
final class TokenEndpointTest extends WP_UnitTestCase {

	private const CLAVE = 'contrasena-de-prueba-123';

	private JwtCodec $codec;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();

		$this->user_id = self::factory()->user->create(
			array(
				'user_login' => 'integrador',
				'user_pass'  => self::CLAVE,
				'role'       => 'editor',
			)
		);

		/*
		 * Se deja que el plugin registre sus propias rutas. Registrar aqui un
		 * segundo AuthController sobre la misma ruta no la sustituye: WordPress
		 * anade un handler mas y despacha al primero, de modo que el test
		 * acabaria ejercitando el controlador del plugin con un secreto
		 * distinto del suyo, y las firmas no cuadrarian.
		 *
		 * Por eso el codec de verificacion se construye con el MISMO secreto
		 * que usa el plugin en produccion.
		 */
		do_action( 'rest_api_init', $wp_rest_server );

		$secrets     = new SecretManager();
		$this->codec = new JwtCodec( $secrets->secret(), $secrets->issuer() );
	}

	/**
	 * Lanza una peticion a un endpoint del plugin.
	 *
	 * @param string               $route  Ruta sin namespace.
	 * @param array<string, mixed> $params Parametros.
	 * @return \WP_REST_Response
	 */
	private function post( string $route, array $params ) {
		$request = new WP_REST_Request( 'POST', '/codeia/v1' . $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Emite un par de tokens con las credenciales correctas.
	 *
	 * @return array<string, mixed>
	 */
	private function issue(): array {
		return $this->post(
			'/auth/token',
			array(
				'username' => 'integrador',
				'password' => self::CLAVE,
			)
		)->get_data();
	}

	public function test_las_rutas_quedan_registradas(): void {
		$rutas = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/codeia/v1/auth/token', $rutas );
		$this->assertArrayHasKey( '/codeia/v1/auth/refresh', $rutas );
	}

	/**
	 * Los endpoints de auth son publicos por necesidad, pero eso no los deja
	 * sin permission_callback: el suyo exige transporte cifrado.
	 */
	public function test_ninguna_ruta_usa_return_true_como_permiso(): void {
		$rutas = rest_get_server()->get_routes();

		foreach ( array( '/codeia/v1/auth/token', '/codeia/v1/auth/refresh' ) as $ruta ) {
			foreach ( $rutas[ $ruta ] as $handler ) {
				$this->assertNotSame(
					'__return_true',
					$handler['permission_callback'],
					"La ruta {$ruta} no debe usar __return_true."
				);
			}
		}
	}

	public function test_emite_un_par_de_tokens_con_credenciales_correctas(): void {
		$respuesta = $this->post(
			'/auth/token',
			array(
				'username' => 'integrador',
				'password' => self::CLAVE,
			)
		);
		$datos     = $respuesta->get_data();

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertSame( 'Bearer', $datos['token_type'] );
		$this->assertSame( 900, $datos['expires_in'] );
		$this->assertNotEmpty( $datos['access_token'] );
		$this->assertNotEmpty( $datos['refresh_token'] );
	}

	public function test_el_access_token_es_un_jwt_verificable(): void {
		$claims = $this->codec->decode( (string) $this->issue()['access_token'] );

		$this->assertIsArray( $claims );
		$this->assertSame( $this->user_id, $claims['sub'] );
		$this->assertSame( home_url(), $claims['iss'] );
		$this->assertArrayHasKey( 'jti', $claims );
		$this->assertArrayHasKey( 'tv', $claims );
	}

	public function test_el_refresh_no_es_un_jwt(): void {
		$this->assertStringNotContainsString(
			'.',
			(string) $this->issue()['refresh_token'],
			'El alfabeto opaco excluye el punto para distinguirlo del JWT.'
		);
	}

	public function test_rechaza_una_contrasena_incorrecta(): void {
		$respuesta = $this->post(
			'/auth/token',
			array(
				'username' => 'integrador',
				'password' => 'mal',
			)
		);

		$this->assertSame( 401, $respuesta->get_status() );
		$this->assertSame( 'codeia_auth_failed', $respuesta->get_data()['code'] );
	}

	/**
	 * El mensaje debe ser identico para usuario inexistente y contrasena
	 * incorrecta, o el endpoint se vuelve un oraculo de enumeracion.
	 */
	public function test_el_mensaje_es_identico_para_usuario_inexistente(): void {
		$mal_pass = $this->post(
			'/auth/token',
			array(
				'username' => 'integrador',
				'password' => 'mal',
			)
		);
		$no_user  = $this->post(
			'/auth/token',
			array(
				'username' => 'noexiste',
				'password' => 'mal',
			)
		);

		$this->assertSame( $mal_pass->get_status(), $no_user->get_status() );
		$this->assertSame( $mal_pass->get_data()['code'], $no_user->get_data()['code'] );
		$this->assertSame( $mal_pass->get_data()['message'], $no_user->get_data()['message'] );
	}

	public function test_renueva_con_un_refresh_valido(): void {
		$inicial  = $this->issue();
		$renovado = $this->post( '/auth/refresh', array( 'refresh_token' => $inicial['refresh_token'] ) );

		$this->assertSame( 200, $renovado->get_status() );
		$this->assertNotSame( $inicial['refresh_token'], $renovado->get_data()['refresh_token'] );
	}

	public function test_reutilizar_el_refresh_revoca_la_familia(): void {
		$inicial = $this->issue();
		$segundo = $this->post( '/auth/refresh', array( 'refresh_token' => $inicial['refresh_token'] ) )->get_data();

		$reuso = $this->post( '/auth/refresh', array( 'refresh_token' => $inicial['refresh_token'] ) );
		$this->assertSame( 401, $reuso->get_status() );

		$legitimo = $this->post( '/auth/refresh', array( 'refresh_token' => $segundo['refresh_token'] ) );
		$this->assertSame( 401, $legitimo->get_status(), 'La familia entera cae tras detectar el reuso.' );
	}

	public function test_un_refresh_desconocido_devuelve_401(): void {
		$respuesta = $this->post( '/auth/refresh', array( 'refresh_token' => 'inventado' ) );

		$this->assertSame( 401, $respuesta->get_status() );
		$this->assertSame( 'codeia_auth_invalid', $respuesta->get_data()['code'] );
	}

	public function test_faltan_parametros_obligatorios(): void {
		$this->assertSame( 400, $this->post( '/auth/token', array( 'username' => 'x' ) )->get_status() );
		$this->assertSame( 400, $this->post( '/auth/refresh', array() )->get_status() );
	}
}

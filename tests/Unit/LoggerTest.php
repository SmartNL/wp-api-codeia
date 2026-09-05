<?php
/**
 * Tests del logger.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit;

use WpApi\Codeia\Core\Logger;

/**
 * @covers \WpApi\Codeia\Core\Logger
 */
final class LoggerTest extends TestCase {

	public function test_respeta_el_nivel_minimo_configurado(): void {
		$logger = new Logger( Logger::WARNING );

		$this->assertTrue( $logger->should_log( Logger::ERROR ) );
		$this->assertTrue( $logger->should_log( Logger::WARNING ) );
		$this->assertFalse( $logger->should_log( Logger::INFO ) );
		$this->assertFalse( $logger->should_log( Logger::DEBUG ) );
	}

	public function test_el_nivel_debug_admite_todo(): void {
		$logger = new Logger( Logger::DEBUG );

		foreach ( array( Logger::DEBUG, Logger::INFO, Logger::WARNING, Logger::ERROR ) as $level ) {
			$this->assertTrue( $logger->should_log( $level ) );
		}
	}

	public function test_un_nivel_desconocido_no_se_registra(): void {
		$logger = new Logger( Logger::DEBUG );

		$this->assertFalse( $logger->should_log( 'inventado' ) );
	}

	public function test_un_nivel_minimo_invalido_cae_a_info(): void {
		$logger = new Logger( 'no_existe' );

		$this->assertTrue( $logger->should_log( Logger::INFO ) );
		$this->assertFalse( $logger->should_log( Logger::DEBUG ) );
	}

	/**
	 * @dataProvider claves_sensibles
	 */
	public function test_oculta_las_credenciales_del_contexto( string $key ): void {
		$redacted = Logger::redact( array( $key => 'valor-secreto-real' ) );

		$this->assertSame( '[oculto]', $redacted[ $key ] );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function claves_sensibles(): array {
		return array(
			'password'      => array( 'password' ),
			'token'         => array( 'token' ),
			'access_token'  => array( 'access_token' ),
			'refresh_token' => array( 'refresh_token' ),
			'api_key'       => array( 'api_key' ),
			'secret'        => array( 'secret' ),
			'authorization' => array( 'authorization' ),
			'mayusculas'    => array( 'API_KEY' ),
			'compuesta'     => array( 'user_password_hash' ),
		);
	}

	public function test_conserva_los_valores_no_sensibles(): void {
		$redacted = Logger::redact(
			array(
				'user_id'  => 42,
				'resource' => 'property',
			)
		);

		$this->assertSame( 42, $redacted['user_id'] );
		$this->assertSame( 'property', $redacted['resource'] );
	}

	public function test_oculta_credenciales_anidadas(): void {
		$redacted = Logger::redact(
			array(
				'request' => array(
					'headers' => array(
						'authorization' => 'Bearer abc.def.ghi',
						'accept'        => 'application/json',
					),
				),
			)
		);

		$this->assertSame( '[oculto]', $redacted['request']['headers']['authorization'] );
		$this->assertSame( 'application/json', $redacted['request']['headers']['accept'] );
	}

	public function test_la_tabla_usa_el_prefijo_del_sitio(): void {
		global $wpdb;

		$wpdb         = new \stdClass();
		$wpdb->prefix = 'wp_7_';

		$this->assertSame( 'wp_7_codeia_logs', Logger::table_name() );
	}
}

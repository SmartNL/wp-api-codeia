<?php
/**
 * Caso base de los tests unitarios.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Arranca y apaga Brain Monkey alrededor de cada test.
 */
abstract class TestCase extends PhpUnitTestCase {

	/**
	 * Prepara el mockeo de WordPress.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->stub_pure_wp_functions();
	}

	/**
	 * Replica las funciones puras de WordPress que usa el codigo bajo prueba.
	 *
	 * Solo se replican funciones sin estado y sin acceso a base de datos, con
	 * el mismo comportamiento que el nucleo. Sustituirlas por un returnArg()
	 * generico dejaria pasar tests que en produccion fallarian: sanitize_key()
	 * existe justamente para descartar caracteres.
	 *
	 * @return void
	 */
	protected function stub_pure_wp_functions(): void {
		Functions\when( 'sanitize_key' )->alias(
			static fn ( string $key ): string => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''
		);

		Functions\when( 'absint' )->alias(
			static fn ( $value ): int => abs( (int) $value )
		);

		Functions\when( 'wp_json_encode' )->alias(
			static fn ( $data, int $flags = 0 ): string|false => json_encode( $data, $flags )
		);

		// is_serialized y maybe_unserialize son funciones de WordPress, no de
		// PHP. Sin ellas, pasar 'is_serialized' como callable falla con
		// TypeError antes siquiera de ejecutarse. Se replica su comportamiento
		// real: un stub que devolviera siempre false ocultaria justo los casos
		// que TypeInferrer tiene que distinguir.
		Functions\when( 'is_serialized' )->alias(
			static function ( $data ): bool {

				if ( ! is_string( $data ) ) {
					return false;
				}

				$data = trim( $data );

				if ( 'N;' === $data ) {
					return true;
				}

				if ( strlen( $data ) < 4 || ':' !== $data[1] ) {
					return false;
				}

				$token = $data[0];

				if ( in_array( $token, array( 'a', 'O', 's' ), true ) ) {
					return 1 === preg_match( "/^{$token}:[0-9]+:/s", $data );
				}

				if ( in_array( $token, array( 'b', 'i', 'd' ), true ) ) {
					return 1 === preg_match( "/^{$token}:[0-9.E+-]+;/", $data );
				}

				return false;
			}
		);

		Functions\when( 'maybe_unserialize' )->alias(
			static function ( $data ) {

				if ( is_string( $data ) && is_serialized( $data ) ) {
					return unserialize( trim( $data ) );
				}

				return $data;
			}
		);

		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ): bool {

				return $thing instanceof \WP_Error;
			}
		);

		Functions\when( '__' )->alias(
			static function ( string $text ): string {

				return $text;
			}
		);

		Functions\when( 'is_protected_meta' )->alias(
			static function ( string $key ): bool {

				return str_starts_with( $key, '_' );
			}
		);
	}
	/**
	 * Limpia el mockeo.
	 */
	protected function tearDown(): void {
		Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}
}

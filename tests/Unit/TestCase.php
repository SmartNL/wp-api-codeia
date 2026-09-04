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

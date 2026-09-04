<?php
/**
 * Tests de las utilidades.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit\Utils;

use WpApi\Codeia\Tests\Unit\TestCase;
use WpApi\Codeia\Utils\Arr;
use WpApi\Codeia\Utils\Hash;
use WpApi\Codeia\Utils\Str;

/**
 * @covers \WpApi\Codeia\Utils\Arr
 * @covers \WpApi\Codeia\Utils\Str
 * @covers \WpApi\Codeia\Utils\Hash
 */
final class UtilsTest extends TestCase {

	public function test_arr_get_con_notacion_de_punto(): void {
		$datos = array( 'limits' => array( 'per_page_max' => 100 ) );

		$this->assertSame( 100, Arr::get( $datos, 'limits.per_page_max' ) );
		$this->assertNull( Arr::get( $datos, 'no.existe' ) );
		$this->assertSame( 'x', Arr::get( $datos, 'no.existe', 'x' ) );
	}

	public function test_arr_set_crea_ramas_intermedias(): void {
		$datos = Arr::set( array(), 'a.b.c', 42 );

		$this->assertSame( 42, Arr::get( $datos, 'a.b.c' ) );
	}

	public function test_arr_only_y_except(): void {
		$datos = array(
			'a' => 1,
			'b' => 2,
			'c' => 3,
		);

		$this->assertSame(
			array(
				'a' => 1,
				'c' => 3,
			),
			Arr::only( $datos, array( 'a', 'c' ) )
		);
		$this->assertSame( array( 'b' => 2 ), Arr::except( $datos, array( 'a', 'c' ) ) );
	}

	public function test_arr_is_list(): void {
		$this->assertTrue( Arr::is_list( array() ) );
		$this->assertTrue( Arr::is_list( array( 'a', 'b' ) ) );
		$this->assertFalse( Arr::is_list( array( 'k' => 'v' ) ) );
		$this->assertFalse(
			Arr::is_list(
				array(
					1 => 'a',
					0 => 'b',
				)
			)
		);
	}

	/**
	 * @dataProvider casos_snake
	 */
	public function test_str_snake( string $entrada, string $esperado ): void {
		$this->assertSame( $esperado, Str::snake( $entrada ) );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function casos_snake(): array {
		return array(
			'camel'    => array( 'propertyPrice', 'property_price' ),
			'pascal'   => array( 'PropertyPrice', 'property_price' ),
			'guiones'  => array( 'property-price', 'property_price' ),
			'espacios' => array( 'Property Price', 'property_price' ),
			'ya_snake' => array( 'property_price', 'property_price' ),
			'simbolos' => array( 'property!!price', 'property_price' ),
		);
	}

	public function test_str_without_prefix(): void {
		$this->assertSame( 'price', Str::without_prefix( 'property_price', 'property_' ) );
		$this->assertSame( 'property_price', Str::without_prefix( 'property_price', 'otro_' ) );
		$this->assertSame( 'property_price', Str::without_prefix( 'property_price', '' ) );
	}

	public function test_str_truncate_respeta_palabras(): void {
		$this->assertSame( 'Atico en', Str::truncate( 'Atico en Malasana', 12 ) );
		$this->assertSame( 'corto', Str::truncate( 'corto', 50 ) );
	}

	public function test_hash_short_es_determinista(): void {
		$this->assertSame( Hash::short( 'valor' ), Hash::short( 'valor' ) );
		$this->assertNotSame( Hash::short( 'a' ), Hash::short( 'b' ) );
		$this->assertSame( 8, strlen( Hash::short( 'valor' ) ) );
	}

	public function test_hash_of_set_ignora_el_orden(): void {
		$this->assertSame(
			Hash::of_set( array( 'a', 'b', 'c' ) ),
			Hash::of_set( array( 'c', 'a', 'b' ) )
		);
		$this->assertNotSame(
			Hash::of_set( array( 'a', 'b' ) ),
			Hash::of_set( array( 'a', 'b', 'c' ) )
		);
	}

	public function test_hash_short_impone_una_longitud_minima(): void {
		$this->assertSame( 4, strlen( Hash::short( 'x', 1 ) ) );
	}
}

<?php
/**
 * Tests del paginador por cursor.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit\Api;

use WpApi\Codeia\Api\CursorPaginator;
use WpApi\Codeia\Tests\Unit\TestCase;

/**
 * @covers \WpApi\Codeia\Api\CursorPaginator
 */
final class CursorPaginatorTest extends TestCase {

	private CursorPaginator $paginator;

	protected function setUp(): void {
		parent::setUp();
		$this->paginator = new CursorPaginator( 'secreto-de-cursores-para-tests' );
	}

	public function test_codifica_y_decodifica(): void {
		$cursor = $this->paginator->encode( '2026-03-12 10:00:00', 42 );
		$back   = $this->paginator->decode( $cursor );

		$this->assertIsArray( $back );
		$this->assertSame( '2026-03-12 10:00:00', $back['date'] );
		$this->assertSame( 42, $back['id'] );
	}

	public function test_el_cursor_tiene_dos_segmentos(): void {
		$cursor = $this->paginator->encode( '2026-03-12 10:00:00', 42 );

		$this->assertCount( 2, explode( '.', $cursor ) );
	}

	public function test_rechaza_un_cursor_manipulado(): void {
		$cursor = $this->paginator->encode( '2026-03-12 10:00:00', 42 );
		$partes = explode( '.', $cursor );

		$alterado = rtrim( strtr( base64_encode( '{"d":"2020-01-01 00:00:00","i":1}' ), '+/', '-_' ), '=' );

		$this->assertNull(
			$this->paginator->decode( $alterado . '.' . $partes[1] ),
			'La firma no cuadra con el payload alterado.'
		);
	}

	public function test_rechaza_un_cursor_de_otro_secreto(): void {
		$otro   = new CursorPaginator( 'secreto-distinto' );
		$cursor = $otro->encode( '2026-03-12 10:00:00', 42 );

		$this->assertNull( $this->paginator->decode( $cursor ) );
	}

	/**
	 * @dataProvider cursores_mal_formados
	 */
	public function test_rechaza_cursores_mal_formados( string $cursor ): void {
		$this->assertNull( $this->paginator->decode( $cursor ) );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function cursores_mal_formados(): array {
		return array(
			'vacio'       => array( '' ),
			'un_segmento' => array( 'abc' ),
			'tres'        => array( 'a.b.c' ),
			'basura'      => array( '!!!.???' ),
		);
	}

	/**
	 * Sin cursor no hace falta el conteo total, que es lo caro:
	 * SQL_CALC_FOUND_ROWS recorre el conjunto completo ignorando el LIMIT.
	 */
	public function test_apply_desactiva_el_conteo_de_filas(): void {
		$args = $this->paginator->apply(
			array(),
			array(
				'date' => '2026-01-01 00:00:00',
				'id'   => 5,
			)
		);

		$this->assertTrue( $args['no_found_rows'] );
	}

	public function test_apply_conserva_el_sentido_de_orden(): void {
		$asc = $this->paginator->apply(
			array(),
			array(
				'date' => 'x',
				'id'   => 1,
			),
			'asc'
		);
		$des = $this->paginator->apply(
			array(),
			array(
				'date' => 'x',
				'id'   => 1,
			),
			'desc'
		);

		$this->assertSame( 'ASC', $asc['codeia_cursor']['order'] );
		$this->assertSame( 'DESC', $des['codeia_cursor']['order'] );
	}

	public function test_el_cursor_no_lleva_relleno_base64(): void {
		$this->assertStringNotContainsString(
			'=',
			$this->paginator->encode( '2026-03-12 10:00:00', 42 )
		);
	}
}

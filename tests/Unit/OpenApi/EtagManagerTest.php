<?php
/**
 * Tests de los ETag.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit\OpenApi;

use WpApi\Codeia\Core\EtagManager;
use WpApi\Codeia\Tests\Unit\TestCase;

/**
 * @covers \WpApi\Codeia\Core\EtagManager
 */
final class EtagManagerTest extends TestCase {

	private EtagManager $etags;

	protected function setUp(): void {
		parent::setUp();
		$this->etags = new EtagManager();
	}

	public function test_el_etag_es_estable_para_los_mismos_datos(): void {
		$datos = array(
			'id'    => 1,
			'price' => 495000,
		);

		$this->assertSame( $this->etags->compute( $datos ), $this->etags->compute( $datos ) );
	}

	public function test_el_etag_cambia_con_los_datos(): void {
		$this->assertNotSame(
			$this->etags->compute( array( 'id' => 1 ) ),
			$this->etags->compute( array( 'id' => 2 ) )
		);
	}

	public function test_el_etag_va_entre_comillas(): void {
		$etag = $this->etags->compute( array( 'a' => 1 ) );

		$this->assertStringStartsWith( '"', $etag );
		$this->assertStringEndsWith( '"', $etag );
	}

	public function test_reconoce_un_etag_identico(): void {
		$etag = $this->etags->compute( array( 'a' => 1 ) );

		$this->assertTrue( $this->etags->matches( $etag, $etag ) );
	}

	public function test_reconoce_la_forma_debil(): void {
		$etag = $this->etags->compute( array( 'a' => 1 ) );

		$this->assertTrue(
			$this->etags->matches( 'W/' . $etag, $etag ),
			'Algunos proxies anaden el prefijo debil.'
		);
	}

	public function test_reconoce_el_comodin(): void {
		$this->assertTrue( $this->etags->matches( '*', '"cualquiera"' ) );
	}

	public function test_reconoce_uno_dentro_de_una_lista(): void {
		$etag = $this->etags->compute( array( 'a' => 1 ) );

		$this->assertTrue( $this->etags->matches( '"otro", ' . $etag, $etag ) );
	}

	public function test_rechaza_un_etag_distinto(): void {
		$this->assertFalse( $this->etags->matches( '"viejo"', '"nuevo"' ) );
	}

	public function test_una_cadena_vacia_no_coincide(): void {
		$this->assertFalse( $this->etags->matches( '', '"actual"' ) );
	}
}

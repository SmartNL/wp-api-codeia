<?php
/**
 * Tests del normalizador de nombres.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit\Schema;

use WpApi\Codeia\Schema\FieldNormalizer;
use WpApi\Codeia\Tests\Unit\TestCase;

/**
 * @covers \WpApi\Codeia\Schema\FieldNormalizer
 */
final class FieldNormalizerTest extends TestCase {

	private FieldNormalizer $normalizer;

	protected function setUp(): void {
		parent::setUp();
		$this->normalizer = new FieldNormalizer();
	}

	/**
	 * @dataProvider claves_de_property
	 */
	public function test_normaliza_las_claves_reales( string $key, string $esperado ): void {
		$this->assertSame( $esperado, $this->normalizer->normalize( $key, 'property' ) );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function claves_de_property(): array {
		return array(
			'precio'       => array( '_property_price', 'price' ),
			'precio_ant'   => array( '_property_price_before', 'price_before' ),
			'moneda'       => array( '_property_currency', 'currency' ),
			'construida'   => array( '_property_built_area', 'built_area' ),
			'habitaciones' => array( '_property_rooms', 'rooms' ),
			'agente'       => array( '_property_agent_id', 'agent_id' ),
			'galeria'      => array( '_property_gallery', 'gallery' ),
			'latitud'      => array( '_property_latitude', 'latitude' ),
		);
	}

	public function test_sin_post_type_solo_retira_el_guion_bajo(): void {
		$this->assertSame( 'property_price', $this->normalizer->normalize( '_property_price' ) );
	}

	public function test_no_retira_el_prefijo_si_dejaria_el_nombre_vacio(): void {
		$this->assertSame( 'property', $this->normalizer->normalize( '_property', 'property' ) );
	}

	public function test_sanea_caracteres_no_validos(): void {
		$this->assertSame( 'campo_raro', $this->normalizer->normalize( '_campo-raro!' ) );
	}

	public function test_un_campo_que_choca_con_uno_nativo_conserva_la_clave(): void {
		$mapa = $this->normalizer->normalize_all( array( '_property_title' ), 'property' );

		$this->assertSame(
			'property_title',
			$mapa['_property_title'],
			'title es un campo nativo: el meta no puede quedarse con ese nombre.'
		);
		$this->assertArrayHasKey( '_property_title', $this->normalizer->collisions() );
	}

	public function test_dos_claves_que_normalizan_igual_no_se_pisan(): void {
		$mapa = $this->normalizer->normalize_all(
			array( '_property_price', 'property_price' ),
			'property'
		);

		$this->assertNotSame( $mapa['_property_price'], $mapa['property_price'] );
		$this->assertCount( 1, $this->normalizer->collisions() );
	}

	public function test_las_claves_sin_conflicto_no_generan_colision(): void {
		$this->normalizer->normalize_all( array( '_property_price', '_property_rooms' ), 'property' );

		$this->assertSame( array(), $this->normalizer->collisions() );
	}

	public function test_reconoce_los_nombres_reservados(): void {
		$this->assertTrue( FieldNormalizer::is_reserved( 'id' ) );
		$this->assertTrue( FieldNormalizer::is_reserved( 'title' ) );
		$this->assertTrue( FieldNormalizer::is_reserved( 'featured_media' ) );
		$this->assertFalse( FieldNormalizer::is_reserved( 'price' ) );
	}
}

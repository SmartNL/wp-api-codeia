<?php
/**
 * Tests de la lista de exclusion.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit\Schema;

use WpApi\Codeia\Schema\ExclusionList;
use WpApi\Codeia\Tests\Unit\TestCase;

/**
 * @covers \WpApi\Codeia\Schema\ExclusionList
 */
final class ExclusionListTest extends TestCase {

	/**
	 * La regla central del modulo: no filtrar por prefijo de guion bajo.
	 *
	 * En flavor-real-estate los 28 campos utiles empiezan por "_". Un filtro
	 * por prefijo los descartaria todos y el plugin no detectaria nada.
	 *
	 * @dataProvider campos_reales_de_property
	 */
	public function test_no_descarta_los_campos_reales_con_guion_bajo( string $key ): void {
		$lista = new ExclusionList();

		$this->assertFalse(
			$lista->excludes( $key ),
			"La clave {$key} es un campo real y no debe descartarse."
		);
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function campos_reales_de_property(): array {
		return array(
			'precio'       => array( '_property_price' ),
			'habitaciones' => array( '_property_rooms' ),
			'agente'       => array( '_property_agent_id' ),
			'superficie'   => array( '_property_built_area' ),
			'galeria'      => array( '_property_gallery' ),
			'catastro'     => array( '_property_cadastral_ref' ),
			'postal'       => array( '_property_postal_code' ),
			'latitud'      => array( '_property_latitude' ),
		);
	}

	/**
	 * @dataProvider claves_internas
	 */
	public function test_descarta_las_claves_internas_del_nucleo( string $key ): void {
		$lista = new ExclusionList();

		$this->assertTrue( $lista->excludes( $key ), "La clave {$key} deberia descartarse." );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function claves_internas(): array {
		return array(
			'edit_lock'  => array( '_edit_lock' ),
			'edit_last'  => array( '_edit_last' ),
			'thumbnail'  => array( '_thumbnail_id' ),
			'plantilla'  => array( '_wp_page_template' ),
			'slug_viejo' => array( '_wp_old_slug' ),
			'adjunto'    => array( '_wp_attached_file' ),
			'metadatos'  => array( '_wp_attachment_metadata' ),
			'papelera'   => array( '_wp_trash_meta_status' ),
			'oembed'     => array( '_oembed_a1b2c3' ),
		);
	}

	public function test_descarta_las_claves_configuradas_por_el_administrador(): void {
		$lista = new ExclusionList( array( '_ruido_de_seo' ) );

		$this->assertTrue( $lista->excludes( '_ruido_de_seo' ) );
		$this->assertFalse( $lista->excludes( '_otra_clave' ) );
	}

	public function test_reconoce_el_puntero_de_acf(): void {
		$lista = new ExclusionList();
		$todas = array(
			'precio'  => 10,
			'_precio' => 10,
		);

		$this->assertTrue(
			$lista->excludes( '_precio', $todas, 'field_6a1f2c' ),
			'ACF guarda _precio con una referencia field_*; es puntero, no campo.'
		);
		$this->assertFalse( $lista->excludes( 'precio', $todas, '495000' ) );
	}

	public function test_no_confunde_un_campo_real_con_un_puntero_de_acf(): void {
		$lista = new ExclusionList();
		$todas = array(
			'property_price'  => 10,
			'_property_price' => 10,
		);

		$this->assertFalse(
			$lista->excludes( '_property_price', $todas, '495000' ),
			'El valor no es una referencia field_*, asi que es un campo real.'
		);
	}

	public function test_sin_valor_de_muestra_no_descarta_como_puntero(): void {
		$lista = new ExclusionList();
		$todas = array(
			'precio'  => 10,
			'_precio' => 10,
		);

		$this->assertFalse(
			$lista->excludes( '_precio', $todas, null ),
			'Sin poder confirmar, es mejor no descartar.'
		);
	}

	public function test_registra_el_motivo_del_descarte(): void {
		$lista = new ExclusionList();
		$lista->excludes( '_edit_lock' );

		$motivos = $lista->reasons();

		$this->assertArrayHasKey( '_edit_lock', $motivos );
		$this->assertStringContainsString( 'nucleo', $motivos['_edit_lock'] );
	}

	public function test_colapsa_las_claves_indexadas_de_repetidor(): void {
		$this->assertSame( 'galeria_imagen', ExclusionList::collapse_repeater( 'galeria_0_imagen' ) );
		$this->assertSame( 'galeria_imagen', ExclusionList::collapse_repeater( 'galeria_12_imagen' ) );
		$this->assertSame( '_property_price', ExclusionList::collapse_repeater( '_property_price' ) );
	}

	public function test_detecta_si_una_clave_es_de_repetidor(): void {
		$this->assertTrue( ExclusionList::is_repeater_item( 'galeria_0_imagen' ) );
		$this->assertFalse( ExclusionList::is_repeater_item( '_property_price' ) );
	}
}

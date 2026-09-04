<?php
/**
 * Tests del gestor de configuracion.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit;

use WpApi\Codeia\Core\Config;

/**
 * @covers \WpApi\Codeia\Core\Config
 */
final class ConfigTest extends TestCase {

	public function test_los_defaults_no_exponen_ningun_recurso(): void {
		$defaults = Config::defaults();

		$this->assertSame( array(), $defaults['resources'], 'Denegacion por defecto: sin recursos expuestos.' );
		$this->assertSame( array(), $defaults['permissions'] );
	}

	public function test_los_modulos_opcionales_arrancan_apagados(): void {
		$modules = Config::defaults()['modules'];

		$this->assertFalse( $modules['media'] );
		$this->assertFalse( $modules['openapi'] );
		$this->assertFalse( $modules['rewrite'] );
	}

	public function test_get_lee_con_notacion_de_punto(): void {
		$config = new Config( Config::defaults() );

		$this->assertSame( 100, $config->get( 'limits.per_page_max' ) );
		$this->assertSame( 'codeia', $config->get( 'namespace' ) );
	}

	public function test_get_devuelve_el_valor_por_defecto_si_la_ruta_no_existe(): void {
		$config = new Config( Config::defaults() );

		$this->assertNull( $config->get( 'no.existe' ) );
		$this->assertSame( 'fallback', $config->get( 'no.existe', 'fallback' ) );
	}

	public function test_set_crea_las_ramas_intermedias(): void {
		$config = new Config( Config::defaults() );

		$config->set( 'resources.property.enabled', true );

		$this->assertTrue( $config->get( 'resources.property.enabled' ) );
	}

	public function test_sanitize_descarta_las_claves_desconocidas(): void {
		$config = new Config( Config::defaults() );

		$clean = $config->sanitize(
			array(
				'namespace'    => 'mimarca',
				'clave_basura' => 'deberia_desaparecer',
			)
		);

		$this->assertArrayNotHasKey( 'clave_basura', $clean );
		$this->assertSame( 'mimarca', $clean['namespace'] );
	}

	public function test_sanitize_conserva_lo_que_no_viene_en_el_fragmento(): void {
		$config = new Config( array_replace( Config::defaults(), array( 'api_version' => 'v3' ) ) );

		$clean = $config->sanitize( array( 'namespace' => 'otro' ) );

		$this->assertSame( 'v3', $clean['api_version'], 'Un guardado parcial no debe perder el resto.' );
	}

	public function test_sanitize_aplica_el_tope_duro_de_per_page(): void {
		$config = new Config( Config::defaults() );

		$clean = $config->sanitize( array( 'limits' => array( 'per_page_max' => 100000 ) ) );

		$this->assertSame( 100, $clean['limits']['per_page_max'] );
	}

	public function test_sanitize_rechaza_una_version_de_api_mal_formada(): void {
		$config = new Config( Config::defaults() );

		$clean = $config->sanitize( array( 'api_version' => '../../etc' ) );

		$this->assertSame( 'v1', $clean['api_version'] );
	}

	public function test_sanitize_fuerza_la_version_de_esquema_actual(): void {
		$config = new Config( Config::defaults() );

		$clean = $config->sanitize( array( 'version' => 99 ) );

		$this->assertSame( Config::SCHEMA_VERSION, $clean['version'] );
	}

	public function test_sanitize_normaliza_los_modulos_a_booleanos(): void {
		$config = new Config( Config::defaults() );

		$clean = $config->sanitize(
			array(
				'modules' => array(
					'media'   => '1',
					'openapi' => 0,
				),
			)
		);

		$this->assertTrue( $clean['modules']['media'] );
		$this->assertFalse( $clean['modules']['openapi'] );
		$this->assertFalse( $clean['modules']['rewrite'], 'Un modulo ausente conserva su defecto.' );
	}

	public function test_migrate_es_idempotente(): void {
		$config = new Config( Config::defaults() );

		$una_vez   = $config->migrate( Config::defaults() );
		$dos_veces = $config->migrate( $una_vez );

		$this->assertSame( $una_vez, $dos_veces );
	}

	public function test_migrate_completa_las_ramas_ausentes(): void {
		$config = new Config( Config::defaults() );

		$migrado = $config->migrate(
			array(
				'version'   => 0,
				'namespace' => 'antiguo',
			)
		);

		$this->assertSame( 'antiguo', $migrado['namespace'], 'Migrar no debe pisar lo configurado.' );
		$this->assertSame( Config::SCHEMA_VERSION, $migrado['version'] );
		$this->assertArrayHasKey( 'limits', $migrado );
	}
}

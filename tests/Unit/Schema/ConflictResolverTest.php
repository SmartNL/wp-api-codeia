<?php
/**
 * Tests de la resolucion de conflictos.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit\Schema;

use WpApi\Codeia\Schema\ConflictResolver;
use WpApi\Codeia\Schema\FieldDefinition;
use WpApi\Codeia\Tests\Unit\TestCase;

/**
 * @covers \WpApi\Codeia\Schema\ConflictResolver
 */
final class ConflictResolverTest extends TestCase {

	private ConflictResolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		$this->resolver = new ConflictResolver();
	}

	private function field( string $origin, string $type, array $extra = array() ): FieldDefinition {
		return new FieldDefinition( '_property_price', 'price', $type, $origin, $extra );
	}

	public function test_gana_el_de_mayor_confianza(): void {
		$ganador = $this->resolver->resolve(
			array(
				$this->field( FieldDefinition::ORIGIN_DB_SAMPLE, FieldDefinition::TYPE_STRING ),
				$this->field( FieldDefinition::ORIGIN_NATIVE, FieldDefinition::TYPE_NUMBER ),
			)
		);

		$this->assertSame( FieldDefinition::TYPE_NUMBER, $ganador->type );
		$this->assertSame( FieldDefinition::ORIGIN_NATIVE, $ganador->origin );
	}

	public function test_lo_manual_gana_a_todo(): void {
		$ganador = $this->resolver->resolve(
			array(
				$this->field( FieldDefinition::ORIGIN_NATIVE, FieldDefinition::TYPE_STRING ),
				$this->field( FieldDefinition::ORIGIN_ACF, FieldDefinition::TYPE_INTEGER ),
				$this->field( FieldDefinition::ORIGIN_MANUAL, FieldDefinition::TYPE_NUMBER ),
			)
		);

		$this->assertSame( FieldDefinition::ORIGIN_MANUAL, $ganador->origin );
		$this->assertSame( 100, $ganador->confidence );
	}

	public function test_los_de_menor_confianza_rellenan_huecos(): void {
		$ganador = $this->resolver->resolve(
			array(
				$this->field( FieldDefinition::ORIGIN_NATIVE, FieldDefinition::TYPE_NUMBER ),
				$this->field(
					FieldDefinition::ORIGIN_DB_SAMPLE,
					FieldDefinition::TYPE_NUMBER,
					array(
						'usage_count' => 47,
						'label'       => 'Precio',
					)
				),
			)
		);

		$this->assertSame( FieldDefinition::TYPE_NUMBER, $ganador->type );
		$this->assertSame( 47, $ganador->usage_count, 'db_sample aporta el conteo de usos.' );
		$this->assertSame( 'Precio', $ganador->label );
	}

	public function test_el_relleno_no_pisa_lo_que_ya_tiene_el_ganador(): void {
		$ganador = $this->resolver->resolve(
			array(
				$this->field( FieldDefinition::ORIGIN_NATIVE, FieldDefinition::TYPE_NUMBER, array( 'label' => 'Oficial' ) ),
				$this->field( FieldDefinition::ORIGIN_DB_SAMPLE, FieldDefinition::TYPE_NUMBER, array( 'label' => 'Inferida' ) ),
			)
		);

		$this->assertSame( 'Oficial', $ganador->label );
	}

	/**
	 * Ante un empate con tipos distintos el criterio es conservador: string,
	 * marcado ambiguo, y el campo queda pendiente de decision humana.
	 */
	public function test_empate_con_tipos_distintos_cae_a_cadena_y_marca_conflicto(): void {
		$ganador = $this->resolver->resolve(
			array(
				$this->field( FieldDefinition::ORIGIN_ACF, FieldDefinition::TYPE_INTEGER ),
				$this->field( FieldDefinition::ORIGIN_METABOX, FieldDefinition::TYPE_NUMBER ),
			)
		);

		$this->assertSame( FieldDefinition::TYPE_STRING, $ganador->type );
		$this->assertTrue( $ganador->ambiguous );
		$this->assertTrue( $this->resolver->has_conflict( '_property_price' ) );
	}

	public function test_empate_con_el_mismo_tipo_no_es_conflicto(): void {
		$ganador = $this->resolver->resolve(
			array(
				$this->field( FieldDefinition::ORIGIN_ACF, FieldDefinition::TYPE_NUMBER ),
				$this->field( FieldDefinition::ORIGIN_METABOX, FieldDefinition::TYPE_NUMBER ),
			)
		);

		$this->assertSame( FieldDefinition::TYPE_NUMBER, $ganador->type );
		$this->assertFalse( $this->resolver->has_conflict( '_property_price' ) );
	}

	public function test_un_solo_candidato_pasa_sin_tocar(): void {
		$original = $this->field( FieldDefinition::ORIGIN_DB_SAMPLE, FieldDefinition::TYPE_INTEGER );

		$this->assertSame( $original, $this->resolver->resolve( array( $original ) ) );
	}

	public function test_resolve_all_agrupa_por_clave_de_almacenamiento(): void {
		$resueltos = $this->resolver->resolve_all(
			array(
				new FieldDefinition( '_a', 'a', FieldDefinition::TYPE_STRING, FieldDefinition::ORIGIN_DB_SAMPLE ),
				new FieldDefinition( '_a', 'a', FieldDefinition::TYPE_INTEGER, FieldDefinition::ORIGIN_NATIVE ),
				new FieldDefinition( '_b', 'b', FieldDefinition::TYPE_STRING, FieldDefinition::ORIGIN_DB_SAMPLE ),
			)
		);

		$this->assertCount( 2, $resueltos );
		$this->assertSame( FieldDefinition::TYPE_INTEGER, $resueltos['_a']->type );
	}

	public function test_la_confianza_por_origen_es_la_documentada(): void {
		$this->assertSame( 100, FieldDefinition::CONFIDENCE[ FieldDefinition::ORIGIN_MANUAL ] );
		$this->assertSame( 95, FieldDefinition::CONFIDENCE[ FieldDefinition::ORIGIN_NATIVE ] );
		$this->assertSame( 85, FieldDefinition::CONFIDENCE[ FieldDefinition::ORIGIN_ACF ] );
		$this->assertSame( 40, FieldDefinition::CONFIDENCE[ FieldDefinition::ORIGIN_DB_SAMPLE ] );
	}

	public function test_db_sample_se_considera_inferido(): void {
		$campo = $this->field( FieldDefinition::ORIGIN_DB_SAMPLE, FieldDefinition::TYPE_INTEGER );

		$this->assertTrue( $campo->is_inferred() );
		$this->assertFalse( $this->field( FieldDefinition::ORIGIN_NATIVE, FieldDefinition::TYPE_INTEGER )->is_inferred() );
	}

	public function test_una_relacion_sin_confirmar_se_marca(): void {
		$campo = $this->field(
			FieldDefinition::ORIGIN_DB_SAMPLE,
			FieldDefinition::TYPE_INTEGER,
			array(
				'relation' => array(
					'post_type' => 'flavor_agent',
					'confirmed' => false,
				),
			)
		);

		$this->assertTrue( $campo->has_unconfirmed_relation() );
	}
}

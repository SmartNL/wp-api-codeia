<?php
/**
 * Tests de la conversion draft-04 a OpenAPI 3.1.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit\OpenApi;

use WpApi\Codeia\OpenApi\SchemaMapper;
use WpApi\Codeia\Schema\FieldDefinition;
use WpApi\Codeia\Tests\Unit\TestCase;

/**
 * @covers \WpApi\Codeia\OpenApi\SchemaMapper
 */
final class SchemaMapperTest extends TestCase {

	private SchemaMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new SchemaMapper();
	}

	private function field( string $type, array $extra = array() ): FieldDefinition {
		return new FieldDefinition(
			'_property_price',
			'price',
			$type,
			$extra['origin'] ?? FieldDefinition::ORIGIN_NATIVE,
			$extra
		);
	}

	/**
	 * @dataProvider tipos
	 */
	public function test_traduce_los_tipos( string $interno, string $esperado ): void {
		$property = $this->mapper->field_to_property( $this->field( $interno ) );

		$this->assertSame( $esperado, $property['type'] );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function tipos(): array {
		return array(
			'entero'   => array( FieldDefinition::TYPE_INTEGER, 'integer' ),
			'numero'   => array( FieldDefinition::TYPE_NUMBER, 'number' ),
			'booleano' => array( FieldDefinition::TYPE_BOOLEAN, 'boolean' ),
			'array'    => array( FieldDefinition::TYPE_ARRAY, 'array' ),
			'objeto'   => array( FieldDefinition::TYPE_OBJECT, 'object' ),
			'cadena'   => array( FieldDefinition::TYPE_STRING, 'string' ),
		);
	}

	public function test_un_array_declara_sus_items(): void {
		$property = $this->mapper->field_to_property( $this->field( FieldDefinition::TYPE_ARRAY ) );

		$this->assertArrayHasKey( 'items', $property );
	}

	public function test_conserva_el_formato_y_el_enum(): void {
		$property = $this->mapper->field_to_property(
			$this->field(
				FieldDefinition::TYPE_STRING,
				array(
					'format' => 'date-time',
					'enum'   => array( 'a', 'b' ),
				)
			)
		);

		$this->assertSame( 'date-time', $property['format'] );
		$this->assertSame( array( 'a', 'b' ), $property['enum'] );
	}

	/**
	 * Publicar un tipo inferido como declarado generaria clientes tipados
	 * sobre una conjetura.
	 */
	public function test_marca_los_campos_inferidos(): void {
		$property = $this->mapper->field_to_property(
			$this->field( FieldDefinition::TYPE_NUMBER, array( 'origin' => FieldDefinition::ORIGIN_DB_SAMPLE ) )
		);

		$this->assertSame( 40, $property['x-codeia-confidence'] );
		$this->assertSame( 'db_sample', $property['x-codeia-origin'] );
		$this->assertStringContainsString( 'inferido', $property['description'] );
	}

	public function test_no_marca_los_campos_declarados(): void {
		$property = $this->mapper->field_to_property( $this->field( FieldDefinition::TYPE_NUMBER ) );

		$this->assertArrayNotHasKey( 'x-codeia-confidence', $property );
	}

	public function test_incluye_la_relacion_propuesta(): void {
		$property = $this->mapper->field_to_property(
			$this->field(
				FieldDefinition::TYPE_INTEGER,
				array(
					'relation' => array(
						'post_type' => 'flavor_agent',
						'confirmed' => false,
					),
				)
			)
		);

		$this->assertSame( 'flavor_agent', $property['x-codeia-relation']['post_type'] );
	}

	public function test_retira_el_schema_de_draft_04(): void {
		$convertido = $this->mapper->to_openapi(
			array(
				'$schema' => 'http://json-schema.org/draft-04/schema#',
				'type'    => 'object',
			)
		);

		$this->assertArrayNotHasKey( '$schema', $convertido );
	}

	public function test_renombra_id_y_definitions(): void {
		$convertido = $this->mapper->to_openapi(
			array(
				'id'          => 'x',
				'definitions' => array( 'a' => array() ),
			)
		);

		$this->assertSame( 'x', $convertido['$id'] );
		$this->assertArrayHasKey( '$defs', $convertido );
		$this->assertArrayNotHasKey( 'definitions', $convertido );
	}

	/**
	 * En draft-04 exclusiveMinimum es un booleano que acompana a minimum; en
	 * 2020-12 es el propio valor. Traducirlo mal cambia la semantica.
	 */
	public function test_convierte_exclusive_minimum(): void {
		$convertido = $this->mapper->to_openapi(
			array(
				'minimum'          => 0,
				'exclusiveMinimum' => true,
			)
		);

		$this->assertSame( 0, $convertido['exclusiveMinimum'] );
		$this->assertArrayNotHasKey( 'minimum', $convertido );
	}

	public function test_un_exclusive_minimum_falso_se_descarta(): void {
		$convertido = $this->mapper->to_openapi(
			array(
				'minimum'          => 5,
				'exclusiveMinimum' => false,
			)
		);

		$this->assertSame( 5, $convertido['minimum'] );
		$this->assertArrayNotHasKey( 'exclusiveMinimum', $convertido );
	}

	public function test_convierte_exclusive_maximum(): void {
		$convertido = $this->mapper->to_openapi(
			array(
				'maximum'          => 100,
				'exclusiveMaximum' => true,
			)
		);

		$this->assertSame( 100, $convertido['exclusiveMaximum'] );
		$this->assertArrayNotHasKey( 'maximum', $convertido );
	}

	public function test_convierte_las_propiedades_anidadas(): void {
		$convertido = $this->mapper->to_openapi(
			array(
				'type'       => 'object',
				'properties' => array(
					'precio' => array(
						'minimum'          => 0,
						'exclusiveMinimum' => true,
					),
				),
			)
		);

		$this->assertSame( 0, $convertido['properties']['precio']['exclusiveMinimum'] );
	}
}

<?php
/**
 * Tests de la inferencia de tipos.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit\Schema;

use WpApi\Codeia\Schema\FieldDefinition;
use WpApi\Codeia\Schema\TypeInferrer;
use WpApi\Codeia\Tests\Unit\TestCase;

/**
 * @covers \WpApi\Codeia\Schema\TypeInferrer
 */
final class TypeInferrerTest extends TestCase {

	private TypeInferrer $inferrer;

	protected function setUp(): void {
		parent::setUp();
		$this->inferrer = new TypeInferrer();
	}

	public function test_enteros(): void {
		$r = $this->inferrer->infer( array( '3', '4', '12' ) );

		$this->assertSame( FieldDefinition::TYPE_INTEGER, $r['type'] );
		$this->assertFalse( $r['ambiguous'] );
	}

	public function test_decimales(): void {
		$r = $this->inferrer->infer( array( '495000.00', '320000.50' ) );

		$this->assertSame( FieldDefinition::TYPE_NUMBER, $r['type'] );
	}

	/**
	 * _property_postal_code guarda "28004" pero tambien "08001": convertirlo
	 * a entero perderia el cero inicial de forma irreversible.
	 */
	public function test_los_codigos_postales_son_cadenas(): void {
		$r = $this->inferrer->infer( array( '28004', '08001', '01234' ) );

		$this->assertSame(
			FieldDefinition::TYPE_STRING,
			$r['type'],
			'Numerico con ceros a la izquierda y longitud fija es cadena.'
		);
	}

	public function test_un_numerico_sin_ceros_iniciales_si_es_entero(): void {
		$r = $this->inferrer->infer( array( '28004', '18001', '41013' ) );

		$this->assertSame( FieldDefinition::TYPE_INTEGER, $r['type'] );
	}

	/**
	 * _property_floors con valores 0 y 1 es un contador de plantas, no un
	 * si/no. Se propone boolean pero marcado ambiguo.
	 */
	public function test_cero_y_uno_se_propone_booleano_pero_ambiguo(): void {
		$r = $this->inferrer->infer( array( '0', '1', '1', '0' ) );

		$this->assertSame( FieldDefinition::TYPE_BOOLEAN, $r['type'] );
		$this->assertTrue( $r['ambiguous'], 'Debe pedir confirmacion: puede ser un contador.' );
	}

	public function test_con_un_tercer_valor_ya_no_es_booleano(): void {
		$r = $this->inferrer->infer( array( '0', '1', '2' ) );

		$this->assertSame( FieldDefinition::TYPE_INTEGER, $r['type'] );
		$this->assertFalse( $r['ambiguous'] );
	}

	public function test_valores_serializados_como_array(): void {
		$r = $this->inferrer->infer(
			array(
				serialize( array( 12, 34, 56 ) ),
				serialize( array( 78, 90 ) ),
			)
		);

		$this->assertSame( FieldDefinition::TYPE_ARRAY, $r['type'] );
	}

	public function test_valores_serializados_asociativos_como_objeto(): void {
		$r = $this->inferrer->infer(
			array(
				serialize(
					array(
						'lat' => 40.4,
						'lng' => -3.7,
					)
				),
			)
		);

		$this->assertSame( FieldDefinition::TYPE_OBJECT, $r['type'] );
	}

	public function test_json_como_objeto(): void {
		$r = $this->inferrer->infer( array( '{"a":1}', '{"b":2}' ) );

		$this->assertSame( FieldDefinition::TYPE_OBJECT, $r['type'] );
	}

	public function test_fechas(): void {
		$r = $this->inferrer->infer( array( '2026-03-12 10:00:00', '2026-04-01 08:30:00' ) );

		$this->assertSame( FieldDefinition::TYPE_STRING, $r['type'] );
		$this->assertSame( 'date-time', $r['format'] );
	}

	public function test_correos(): void {
		$r = $this->inferrer->infer( array( 'a@ejemplo.com', 'b@ejemplo.com' ) );

		$this->assertSame( 'email', $r['format'] );
	}

	public function test_urls(): void {
		$r = $this->inferrer->infer( array( 'https://a.test/x', 'https://b.test/y' ) );

		$this->assertSame( 'uri', $r['format'] );
	}

	public function test_texto_libre(): void {
		$r = $this->inferrer->infer( array( 'Atico en Malasana', 'Piso en Chamberi' ) );

		$this->assertSame( FieldDefinition::TYPE_STRING, $r['type'] );
		$this->assertNull( $r['format'] );
	}

	public function test_propone_enum_con_pocos_valores_distintos(): void {
		$r = $this->inferrer->infer( array( 'venta', 'alquiler', 'venta', 'alquiler', 'venta' ) );

		$this->assertSame( array( 'alquiler', 'venta' ), $r['enum'] );
	}

	public function test_no_propone_enum_con_demasiada_variedad(): void {
		$r = $this->inferrer->infer( array( 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j' ) );

		$this->assertSame( array(), $r['enum'] );
	}

	public function test_ignora_los_valores_vacios(): void {
		$r = $this->inferrer->infer( array( '', null, '5', '7' ) );

		$this->assertSame( FieldDefinition::TYPE_INTEGER, $r['type'] );
	}

	public function test_sin_valores_cae_a_cadena(): void {
		$r = $this->inferrer->infer( array() );

		$this->assertSame( FieldDefinition::TYPE_STRING, $r['type'] );
	}

	public function test_limita_la_muestra_analizada(): void {
		$this->assertSame( 50, TypeInferrer::SAMPLE_SIZE );
	}
}

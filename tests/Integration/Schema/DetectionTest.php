<?php
/**
 * Deteccion de esquema contra datos reales.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Integration\Schema;

use WP_UnitTestCase;
use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Schema\ConflictResolver;
use WpApi\Codeia\Schema\ExclusionList;
use WpApi\Codeia\Schema\FieldDefinition;
use WpApi\Codeia\Schema\FieldNormalizer;
use WpApi\Codeia\Schema\Providers\DbSampleProvider;
use WpApi\Codeia\Schema\Providers\NativeProvider;
use WpApi\Codeia\Schema\SchemaCache;
use WpApi\Codeia\Schema\SchemaRegistry;
use WpApi\Codeia\Schema\TypeInferrer;

/**
 * Reproduce el caso que justifica la deteccion multinivel.
 *
 * Registra un post type con campos guardados por update_post_meta() SIN
 * register_meta(), igual que hace flavor-real-estate, y comprueba que el
 * registro nativo no los ve pero el muestreo de base de datos si.
 *
 * @covers \WpApi\Codeia\Schema\SchemaRegistry
 * @covers \WpApi\Codeia\Schema\Providers\DbSampleProvider
 */
final class DetectionTest extends WP_UnitTestCase {

	/**
	 * Campos que se siembran, con sus valores.
	 *
	 * Reproducen las claves y los tipos reales de flavor-real-estate.
	 */
	private const CAMPOS = array(
		'_property_price'         => array( '495000.00', '320000.50', '780000.00' ),
		'_property_rooms'         => array( '3', '4', '2' ),
		'_property_postal_code'   => array( '28004', '08001', '01234' ),
		'_property_gallery'       => null,
		'_property_cadastral_ref' => array( '1234567AB1234C0001XX', '7654321BA4321D0002YY', '1111111CC1111E0003ZZ' ),
		'_property_floors'        => array( '0', '1', '1' ),
	);

	private SchemaRegistry $registry;

	public function set_up(): void {
		parent::set_up();

		register_post_type(
			'property',
			array(
				'public'       => true,
				'label'        => 'Propiedades',
				'supports'     => array( 'title', 'editor', 'thumbnail' ),
				'show_in_rest' => true,
			)
		);

		register_post_type(
			'flavor_agent',
			array(
				'public' => true,
				'label'  => 'Agentes',
			)
		);
		register_taxonomy( 'property_type', 'property', array( 'public' => true ) );

		$this->seed();
		$this->registry = $this->make_registry();
	}

	public function tear_down(): void {
		unregister_post_type( 'property' );
		unregister_post_type( 'flavor_agent' );
		unregister_taxonomy( 'property_type' );
		parent::tear_down();
	}

	/**
	 * Crea agentes y propiedades con meta, sin register_meta().
	 *
	 * @return void
	 */
	private function seed(): void {
		$agentes = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$agentes[] = self::factory()->post->create( array( 'post_type' => 'flavor_agent' ) );
		}

		for ( $i = 0; $i < 3; $i++ ) {
			$post_id = self::factory()->post->create( array( 'post_type' => 'property' ) );

			foreach ( self::CAMPOS as $key => $valores ) {
				if ( null === $valores ) {
					update_post_meta( $post_id, $key, array( 10 + $i, 20 + $i ) );
					continue;
				}

				update_post_meta( $post_id, $key, $valores[ $i ] );
			}

			update_post_meta( $post_id, '_property_agent_id', $agentes[ $i ] );
			update_post_meta( $post_id, '_edit_lock', '1700000000:1' );
		}
	}

	/**
	 * Monta el registro con los dos niveles relevantes.
	 *
	 * @return SchemaRegistry
	 */
	private function make_registry(): SchemaRegistry {
		$config = new Config( Config::defaults() );
		$cache  = new SchemaCache( new CacheManager(), $config );

		$registry = new SchemaRegistry(
			$cache,
			new FieldNormalizer(),
			new ConflictResolver(),
			new EventDispatcher()
		);

		$registry->add_provider( new DbSampleProvider( new ExclusionList(), new TypeInferrer() ) );
		$registry->add_provider( new NativeProvider() );

		return $registry;
	}

	/**
	 * El punto de partida: la API de registro nativa no ve nada.
	 */
	public function test_el_registro_nativo_no_detecta_los_campos(): void {
		$registradas = get_registered_meta_keys( 'post', 'property' );

		foreach ( array_keys( self::CAMPOS ) as $key ) {
			$this->assertArrayNotHasKey(
				$key,
				$registradas,
				"get_registered_meta_keys() no puede conocer {$key}: se guardo sin register_meta()."
			);
		}
	}

	public function test_el_muestreo_de_base_de_datos_si_los_detecta(): void {
		$definicion = $this->registry->definition_for( 'property', true );

		$this->assertNotNull( $definicion );

		foreach ( array_keys( self::CAMPOS ) as $key ) {
			$this->assertArrayHasKey(
				$key,
				$definicion->fields,
				"El muestreo deberia encontrar {$key}."
			);
		}
	}

	public function test_los_campos_detectados_llevan_origen_y_confianza_baja(): void {
		$definicion = $this->registry->definition_for( 'property', true );
		$campo      = $definicion->field( '_property_price' );

		$this->assertSame( FieldDefinition::ORIGIN_DB_SAMPLE, $campo->origin );
		$this->assertSame( 40, $campo->confidence );
		$this->assertTrue( $campo->is_inferred() );
	}

	public function test_infiere_los_tipos_correctos(): void {
		$definicion = $this->registry->definition_for( 'property', true );

		$this->assertSame( FieldDefinition::TYPE_NUMBER, $definicion->field( '_property_price' )->type );
		$this->assertSame( FieldDefinition::TYPE_INTEGER, $definicion->field( '_property_rooms' )->type );
		$this->assertSame( FieldDefinition::TYPE_ARRAY, $definicion->field( '_property_gallery' )->type );
		$this->assertSame( FieldDefinition::TYPE_STRING, $definicion->field( '_property_cadastral_ref' )->type );
	}

	public function test_el_codigo_postal_se_queda_en_cadena(): void {
		$definicion = $this->registry->definition_for( 'property', true );

		$this->assertSame(
			FieldDefinition::TYPE_STRING,
			$definicion->field( '_property_postal_code' )->type,
			'Convertirlo a entero perderia el cero inicial de 08001.'
		);
	}

	public function test_el_campo_de_planta_queda_marcado_como_ambiguo(): void {
		$definicion = $this->registry->definition_for( 'property', true );
		$campo      = $definicion->field( '_property_floors' );

		$this->assertSame( FieldDefinition::TYPE_BOOLEAN, $campo->type );
		$this->assertTrue( $campo->ambiguous, 'Con valores 0/1 puede ser un contador, no un si/no.' );
	}

	public function test_propone_la_relacion_con_flavor_agent_sin_activarla(): void {
		$definicion = $this->registry->definition_for( 'property', true );
		$campo      = $definicion->field( '_property_agent_id' );

		$this->assertNotNull( $campo->relation );
		$this->assertSame( 'flavor_agent', $campo->relation['post_type'] );
		$this->assertFalse( $campo->relation['confirmed'], 'Una relacion inferida nunca se activa sola.' );
		$this->assertTrue( $campo->has_unconfirmed_relation() );
	}

	public function test_descarta_las_claves_internas_del_nucleo(): void {
		$definicion = $this->registry->definition_for( 'property', true );

		$this->assertArrayNotHasKey( '_edit_lock', $definicion->fields );
	}

	public function test_los_campos_protegidos_se_marcan(): void {
		$definicion = $this->registry->definition_for( 'property', true );

		$this->assertTrue(
			$definicion->field( '_property_price' )->protected,
			'Exponer meta protegida exige confirmacion explicita en el dashboard.'
		);
	}

	public function test_normaliza_los_nombres_publicos(): void {
		$definicion = $this->registry->definition_for( 'property', true );

		$this->assertSame( 'price', $definicion->field( '_property_price' )->exposed_name );
		$this->assertSame( 'rooms', $definicion->field( '_property_rooms' )->exposed_name );
		$this->assertSame( 'agent_id', $definicion->field( '_property_agent_id' )->exposed_name );
	}

	public function test_traduce_del_nombre_publico_a_la_clave_real(): void {
		$definicion = $this->registry->definition_for( 'property', true );

		$this->assertSame( '_property_price', $definicion->storage_key_for( 'price' ) );
		$this->assertNull(
			$definicion->storage_key_for( 'inventado' ),
			'Un identificador que no traduce debe rechazarse, no llegar a la consulta.'
		);
	}

	public function test_recoge_las_taxonomias_y_los_supports(): void {
		$definicion = $this->registry->definition_for( 'property', true );

		$this->assertContains( 'property_type', $definicion->taxonomies );
		$this->assertContains( 'thumbnail', $definicion->supports );
		$this->assertSame( 'Propiedades', $definicion->label );
	}

	public function test_la_definicion_se_cachea_y_se_reutiliza(): void {
		$primera = $this->registry->definition_for( 'property', true );
		$segunda = $this->registry->definition_for( 'property' );

		$this->assertSame( $primera->to_array(), $segunda->to_array() );
	}

	public function test_sobrevive_al_ciclo_de_serializacion(): void {
		$original     = $this->registry->definition_for( 'property', true );
		$reconstruida = \WpApi\Codeia\Schema\ResourceDefinition::from_array( $original->to_array() );

		$this->assertSame( $original->post_type, $reconstruida->post_type );
		$this->assertCount( count( $original->fields ), $reconstruida->fields );
		$this->assertSame(
			$original->field( '_property_price' )->type,
			$reconstruida->field( '_property_price' )->type
		);
	}

	public function test_lista_los_campos_inferidos_y_las_relaciones_pendientes(): void {
		$definicion = $this->registry->definition_for( 'property', true );

		$this->assertNotEmpty( $definicion->inferred_fields() );
		$this->assertCount( 1, $definicion->pending_relations() );
	}

	public function test_un_post_type_inexistente_devuelve_null(): void {
		$this->assertNull( $this->registry->definition_for( 'no_existe', true ) );
	}
}

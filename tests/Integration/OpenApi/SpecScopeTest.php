<?php
/**
 * El documento OpenAPI varia segun el ambito de permisos.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Integration\OpenApi;

use WP_UnitTestCase;
use WpApi\Codeia\Api\ControllerFactory;
use WpApi\Codeia\Api\CursorPaginator;
use WpApi\Codeia\Api\FieldProjector;
use WpApi\Codeia\Api\QueryBuilder;
use WpApi\Codeia\Api\RouteRegistrar;
use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\OpenApi\SchemaMapper;
use WpApi\Codeia\OpenApi\SecuritySchemeBuilder;
use WpApi\Codeia\OpenApi\SpecGenerator;
use WpApi\Codeia\Permissions\CapabilityMapper;
use WpApi\Codeia\Permissions\CollectionRestrictor;
use WpApi\Codeia\Permissions\FieldVisibility;
use WpApi\Codeia\Permissions\PermissionMatrix;
use WpApi\Codeia\Permissions\PermissionResolver;
use WpApi\Codeia\Schema\ConflictResolver;
use WpApi\Codeia\Schema\ExclusionList;
use WpApi\Codeia\Schema\FieldNormalizer;
use WpApi\Codeia\Schema\Providers\DbSampleProvider;
use WpApi\Codeia\Schema\SchemaCache;
use WpApi\Codeia\Schema\SchemaRegistry;
use WpApi\Codeia\Schema\TypeInferrer;

/**
 * @covers \WpApi\Codeia\OpenApi\SpecGenerator
 */
final class SpecScopeTest extends WP_UnitTestCase {

	private SpecGenerator $generator;
	private int $editor;
	private int $subscriber;

	public function set_up(): void {
		parent::set_up();

		register_post_type(
			'property',
			array(
				'public' => true,
				'label'  => 'Propiedades',
			)
		);

		$this->editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$post_id = self::factory()->post->create(
				array(
					'post_type'   => 'property',
					'post_status' => 'publish',
				)
			);

			update_post_meta( $post_id, '_property_price', '495000.00' );
			update_post_meta( $post_id, '_property_cadastral_ref', 'REF' . $i );
		}

		$this->generator = $this->build_generator();
	}

	public function tear_down(): void {
		unregister_post_type( 'property' );
		parent::tear_down();
	}

	/**
	 * Monta el generador con permisos que ocultan un campo al subscriber.
	 *
	 * @return SpecGenerator
	 */
	private function build_generator(): SpecGenerator {
		$config = new Config(
			array_replace(
				Config::defaults(),
				array(
					'auth'        => array(
						'providers' => array(
							'jwt'          => array( 'enabled' => true ),
							'app_password' => array( 'enabled' => true ),
						),
					),
					'resources'   => array(
						'property' => array(
							'enabled'    => true,
							'operations' => array( 'read', 'create' ),
						),
					),
					'permissions' => array(
						'property' => array(
							'editor'     => array( 'all' => true ),
							'subscriber' => array(
								'read'   => true,
								'fields' => array( 'cadastral_ref' => array( 'read' => false ) ),
							),
						),
					),
				)
			)
		);

		$schema = new SchemaRegistry(
			new SchemaCache( new CacheManager(), $config ),
			new FieldNormalizer(),
			new ConflictResolver(),
			new EventDispatcher()
		);
		$schema->add_provider( new DbSampleProvider( new ExclusionList(), new TypeInferrer() ) );
		$schema->definition_for( 'property', true );

		$resolver   = new PermissionResolver(
			new PermissionMatrix( $config ),
			new CapabilityMapper(),
			new EventDispatcher()
		);
		$visibility = new FieldVisibility( $resolver, new EventDispatcher() );

		$factory = new ControllerFactory(
			$resolver,
			$visibility,
			new CollectionRestrictor( new CapabilityMapper(), $resolver, new EventDispatcher() ),
			new FieldProjector( $visibility ),
			new QueryBuilder( 4 ),
			new CursorPaginator( 'secreto-spec' ),
			$config
		);

		return new SpecGenerator(
			$schema,
			new RouteRegistrar( $schema, $factory, $config, new EventDispatcher() ),
			new SchemaMapper(),
			new SecuritySchemeBuilder( $config ),
			$visibility,
			$config,
			new EventDispatcher()
		);
	}

	public function test_declara_openapi_3_1(): void {
		$spec = $this->generator->generate( $this->editor );

		$this->assertSame( '3.1.0', $spec['openapi'] );
	}

	public function test_incluye_las_rutas_del_recurso(): void {
		$spec = $this->generator->generate( $this->editor );

		$this->assertArrayHasKey( '/property', $spec['paths'] );
		$this->assertArrayHasKey( '/property/{id}', $spec['paths'] );
	}

	public function test_solo_documenta_las_operaciones_habilitadas(): void {
		$spec = $this->generator->generate( $this->editor );

		$this->assertArrayHasKey( 'get', $spec['paths']['/property'] );
		$this->assertArrayHasKey( 'post', $spec['paths']['/property'] );
		$this->assertArrayNotHasKey( 'delete', $spec['paths']['/property/{id}'] );
	}

	public function test_genera_tres_esquemas_por_recurso(): void {
		$spec = $this->generator->generate( $this->editor );

		$this->assertArrayHasKey( 'Property', $spec['components']['schemas'] );
		$this->assertArrayHasKey( 'PropertyCreate', $spec['components']['schemas'] );
		$this->assertArrayHasKey( 'PropertyUpdate', $spec['components']['schemas'] );
	}

	/**
	 * Un esquema compartido produciria clientes que envian id en el POST.
	 */
	public function test_el_esquema_de_escritura_no_lleva_campos_de_solo_lectura(): void {
		$spec = $this->generator->generate( $this->editor );

		$this->assertArrayHasKey( 'id', $spec['components']['schemas']['Property']['properties'] );
		$this->assertArrayNotHasKey( 'id', $spec['components']['schemas']['PropertyCreate']['properties'] );
	}

	/**
	 * El NOMBRE de un campo ya es informacion: un documento unico con todos
	 * los campos filtraria la existencia de cadastral_ref.
	 */
	public function test_el_documento_de_un_rol_restringido_no_menciona_el_campo_oculto(): void {
		$spec = $this->generator->generate( $this->subscriber );

		$this->assertArrayNotHasKey(
			'cadastral_ref',
			$spec['components']['schemas']['Property']['properties'],
			'El nombre del campo no debe aparecer para quien no puede leerlo.'
		);
	}

	public function test_el_documento_del_editor_si_lo_menciona(): void {
		$spec = $this->generator->generate( $this->editor );

		$this->assertArrayHasKey(
			'cadastral_ref',
			$spec['components']['schemas']['Property']['properties']
		);
	}

	public function test_marca_los_tipos_inferidos(): void {
		$spec  = $this->generator->generate( $this->editor );
		$price = $spec['components']['schemas']['Property']['properties']['price'];

		$this->assertSame( 40, $price['x-codeia-confidence'] );
		$this->assertSame( 'number', $price['type'] );
	}

	/**
	 * Documentar JWT cuando esta desactivado invita a integrar contra algo
	 * que devolvera 401.
	 */
	public function test_solo_declara_los_proveedores_de_auth_activos(): void {
		$spec = $this->generator->generate( $this->editor );

		$this->assertArrayHasKey( 'bearerAuth', $spec['components']['securitySchemes'] );
		$this->assertArrayHasKey( 'appPassword', $spec['components']['securitySchemes'] );
		$this->assertArrayNotHasKey( 'apiKey', $spec['components']['securitySchemes'] );
	}

	public function test_la_lectura_admite_acceso_anonimo_en_el_requisito(): void {
		$spec     = $this->generator->generate( $this->editor );
		$security = $spec['paths']['/property']['get']['security'];

		$this->assertContains( array(), $security, 'El objeto vacio significa tambien sin autenticar.' );
	}

	public function test_la_escritura_no_admite_anonimo(): void {
		$spec     = $this->generator->generate( $this->editor );
		$security = $spec['paths']['/property']['post']['security'];

		$this->assertNotContains( array(), $security );
	}

	public function test_las_respuestas_de_error_se_referencian(): void {
		$spec = $this->generator->generate( $this->editor );

		$this->assertArrayHasKey( 'NotFound', $spec['components']['responses'] );
		$this->assertSame(
			'#/components/responses/NotFound',
			$spec['paths']['/property/{id}']['get']['responses']['404']['$ref']
		);
	}

	public function test_todas_las_referencias_resuelven(): void {
		$spec = $this->generator->generate( $this->editor );
		$roto = array();

		array_walk_recursive(
			$spec,
			static function ( $value, $key ) use ( $spec, &$roto ): void {
				if ( '$ref' !== $key || ! is_string( $value ) ) {
					return;
				}

				$path   = explode( '/', ltrim( $value, '#/' ) );
				$cursor = $spec;

				foreach ( $path as $segment ) {
					if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
						$roto[] = $value;
						return;
					}

					$cursor = $cursor[ $segment ];
				}
			}
		);

		$this->assertSame( array(), $roto, 'Toda referencia debe apuntar a un componente existente.' );
	}

	public function test_no_hay_operation_id_duplicados(): void {
		$spec = $this->generator->generate( $this->editor );
		$ids  = array();

		foreach ( $spec['paths'] as $operations ) {
			foreach ( $operations as $operation ) {
				if ( isset( $operation['operationId'] ) ) {
					$ids[] = $operation['operationId'];
				}
			}
		}

		$this->assertSame( array_unique( $ids ), $ids );
	}

	public function test_declara_el_servidor_canonico(): void {
		$spec = $this->generator->generate( $this->editor );

		$this->assertStringContainsString( 'codeia/v1', $spec['servers'][0]['url'] );
		$this->assertCount( 1, $spec['servers'], 'Sin alias activo, solo la ruta canonica.' );
	}
}

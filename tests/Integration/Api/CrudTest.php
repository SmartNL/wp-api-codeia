<?php
/**
 * CRUD dinamico sobre un recurso real.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Integration\Api;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use WpApi\Codeia\Api\ControllerFactory;
use WpApi\Codeia\Api\CursorPaginator;
use WpApi\Codeia\Api\FieldProjector;
use WpApi\Codeia\Api\QueryBuilder;
use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
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
 * Ejercita el sprint 5 sobre las tres capas anteriores.
 *
 * @covers \WpApi\Codeia\Api\ResourceController
 */
final class CrudTest extends WP_UnitTestCase {

	private int $editor;
	private int $subscriber;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();

		register_post_type(
			'property',
			array(
				'public' => true,
				'label'  => 'Propiedades',
			)
		);

		$this->editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->seed();
		$this->register_routes();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		unregister_post_type( 'property' );
		parent::tear_down();
	}

	/**
	 * Crea propiedades con campos meta, sin register_meta().
	 *
	 * @return void
	 */
	private function seed(): void {
		$precios = array( '495000.00', '320000.00', '780000.00', '210000.00', '650000.00' );

		foreach ( $precios as $i => $precio ) {
			$post_id = self::factory()->post->create(
				array(
					'post_type'   => 'property',
					'post_status' => 'publish',
					'post_title'  => 'Propiedad ' . ( $i + 1 ),
				)
			);

			update_post_meta( $post_id, '_property_price', $precio );
			update_post_meta( $post_id, '_property_rooms', (string) ( 2 + $i ) );
			update_post_meta( $post_id, '_property_cadastral_ref', 'REF' . $i );
		}
	}

	/**
	 * Monta el registro de rutas con permisos reales.
	 *
	 * @return void
	 */
	private function register_routes(): void {
		$permissions = array(
			'property' => array(
				'editor'     => array( 'all' => true ),
				'subscriber' => array(
					'read'   => true,
					'fields' => array( 'cadastral_ref' => array( 'read' => false ) ),
				),
				'anonymous'  => array( 'read' => true ),
			),
		);

		$config = new Config(
			array_replace(
				Config::defaults(),
				array(
					'permissions' => $permissions,
					'resources'   => array(
						'property' => array(
							'enabled'    => true,
							'operations' => array( 'read', 'create', 'update', 'delete' ),
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

		$resolver = new PermissionResolver(
			new PermissionMatrix( $config ),
			new CapabilityMapper(),
			new EventDispatcher()
		);

		$visibility = new FieldVisibility( $resolver, new EventDispatcher() );

		$cursors = new CursorPaginator( 'secreto-crud-tests' );
		$cursors->register_hooks();

		$factory = new ControllerFactory(
			$resolver,
			$visibility,
			new CollectionRestrictor( new CapabilityMapper(), $resolver, new EventDispatcher() ),
			new FieldProjector( $visibility ),
			new QueryBuilder( 4 ),
			$cursors,
			$config
		);

		$definition = $schema->definition_for( 'property', true );

		/*
		 * Las rutas se registran DENTRO de rest_api_init. WordPress emite un
		 * aviso de uso incorrecto si se hace fuera, y con razon: registrar
		 * antes de ese hook deja rutas invisibles para el servidor que se
		 * construya despues.
		 */
		add_action(
			'rest_api_init',
			static function () use ( $factory, $definition ): void {
				$factory->make( $definition )->register_resource_routes(
					array( 'read', 'create', 'update', 'delete' )
				);
			}
		);

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Lanza una peticion contra la API.
	 *
	 * @param string               $method Metodo HTTP.
	 * @param string               $route  Ruta sin namespace.
	 * @param array<string, mixed> $params Parametros.
	 * @return \WP_REST_Response
	 */
	private function request( string $method, string $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/codeia/v1' . $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	public function test_las_rutas_se_registran(): void {
		$rutas = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/codeia/v1/property', $rutas );
		$this->assertArrayHasKey( '/codeia/v1/property/(?P<id>[\d]+)', $rutas );
		$this->assertArrayHasKey( '/codeia/v1/property/schema', $rutas );
	}

	/**
	 * Las rutas nacen con el PermissionResolver real del sprint 4.
	 */
	public function test_ninguna_ruta_usa_return_true(): void {
		foreach ( rest_get_server()->get_routes() as $ruta => $handlers ) {
			if ( ! str_starts_with( $ruta, '/codeia/v1/property' ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				$this->assertNotSame( '__return_true', $handler['permission_callback'], "Ruta {$ruta}." );
			}
		}
	}

	public function test_lista_elementos_con_total(): void {
		wp_set_current_user( $this->editor );

		$respuesta = $this->request( 'GET', '/property', array( 'per_page' => 10 ) );

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertCount( 5, $respuesta->get_data() );
		$this->assertSame( '5', $respuesta->get_headers()['X-WP-Total'] );
	}

	public function test_los_tipos_se_castean_segun_el_esquema(): void {
		wp_set_current_user( $this->editor );

		$items = $this->request( 'GET', '/property' )->get_data();

		$this->assertIsFloat( $items[0]['price'], 'Detectado como number.' );
		$this->assertIsInt( $items[0]['rooms'], 'Detectado como integer.' );
	}

	/**
	 * El campo vetado se OMITE, no se devuelve como null.
	 */
	public function test_un_subscriber_no_ve_el_campo_vetado(): void {
		wp_set_current_user( $this->subscriber );

		$items = $this->request( 'GET', '/property' )->get_data();

		$this->assertArrayNotHasKey( 'cadastral_ref', $items[0] );
		$this->assertArrayHasKey( 'price', $items[0] );
	}

	public function test_un_editor_si_ve_el_campo(): void {
		wp_set_current_user( $this->editor );

		$items = $this->request( 'GET', '/property' )->get_data();

		$this->assertArrayHasKey( 'cadastral_ref', $items[0] );
	}

	public function test_filtra_por_campo_numerico(): void {
		wp_set_current_user( $this->editor );

		$items = $this->request(
			'GET',
			'/property',
			array( 'filter' => array( 'price' => array( 'gte' => 500000 ) ) )
		)->get_data();

		$this->assertCount( 2, $items, 'Solo 650000 y 780000 superan 500000.' );
	}

	/**
	 * Sin type NUMERIC, MySQL compararia como cadena y "300000" < "89000"
	 * seria verdadero.
	 */
	public function test_la_comparacion_numerica_no_es_alfabetica(): void {
		wp_set_current_user( $this->editor );

		$items = $this->request(
			'GET',
			'/property',
			array( 'filter' => array( 'price' => array( 'lt' => 400000 ) ) )
		)->get_data();

		$precios = array_column( $items, 'price' );
		sort( $precios );

		$this->assertSame( array( 210000.0, 320000.0 ), $precios );
	}

	public function test_rechaza_un_campo_inexistente_en_el_filtro(): void {
		wp_set_current_user( $this->editor );

		$respuesta = $this->request(
			'GET',
			'/property',
			array( 'filter' => array( 'inventado' => 1 ) )
		);

		$this->assertSame( 400, $respuesta->get_status() );
		$this->assertSame( 'codeia_unknown_field', $respuesta->get_data()['code'] );
	}

	public function test_rechaza_un_orderby_no_valido(): void {
		wp_set_current_user( $this->editor );

		$respuesta = $this->request( 'GET', '/property', array( 'orderby' => 'inventado' ) );

		$this->assertSame( 400, $respuesta->get_status() );
	}

	public function test_ordena_por_campo_meta(): void {
		wp_set_current_user( $this->editor );

		$items = $this->request(
			'GET',
			'/property',
			array(
				'orderby' => 'price',
				'order'   => 'asc',
			)
		)->get_data();

		$this->assertSame( 210000.0, $items[0]['price'] );
	}

	public function test_fields_reduce_la_respuesta(): void {
		wp_set_current_user( $this->editor );

		$items = $this->request( 'GET', '/property', array( '_fields' => 'id,price' ) )->get_data();

		$this->assertSame( array( 'id', 'price' ), array_keys( $items[0] ) );
	}

	/**
	 * _fields solo puede pedir MENOS, jamas mas.
	 */
	public function test_fields_no_puede_ampliar_lo_que_los_permisos_denegaron(): void {
		wp_set_current_user( $this->subscriber );

		$items = $this->request(
			'GET',
			'/property',
			array( '_fields' => 'id,cadastral_ref' )
		)->get_data();

		$this->assertArrayNotHasKey( 'cadastral_ref', $items[0] );
	}

	public function test_obtiene_un_elemento(): void {
		wp_set_current_user( $this->editor );

		$items     = $this->request( 'GET', '/property' )->get_data();
		$respuesta = $this->request( 'GET', '/property/' . $items[0]['id'] );

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertSame( $items[0]['id'], $respuesta->get_data()['id'] );
	}

	public function test_un_elemento_inexistente_devuelve_404(): void {
		wp_set_current_user( $this->editor );

		$respuesta = $this->request( 'GET', '/property/999999' );

		$this->assertSame( 404, $respuesta->get_status() );
		$this->assertSame( 'codeia_not_found', $respuesta->get_data()['code'] );
	}

	public function test_crea_un_elemento(): void {
		wp_set_current_user( $this->editor );

		$request = new WP_REST_Request( 'POST', '/codeia/v1/property' );
		$request->set_body_params(
			array(
				'title' => 'Nueva',
				'price' => 123456,
			)
		);

		$respuesta = rest_get_server()->dispatch( $request );

		$this->assertSame( 201, $respuesta->get_status() );
		$this->assertSame( 'Nueva', $respuesta->get_data()['title'] );
		$this->assertSame( 123456.0, $respuesta->get_data()['price'] );
	}

	/**
	 * La escritura no se aplica parcialmente: se rechaza entera.
	 */
	public function test_un_campo_no_escribible_rechaza_la_peticion_entera(): void {
		wp_set_current_user( $this->subscriber );

		$request = new WP_REST_Request( 'POST', '/codeia/v1/property' );
		$request->set_body_params(
			array(
				'title' => 'X',
				'price' => 1,
			)
		);

		$respuesta = rest_get_server()->dispatch( $request );

		$this->assertSame( 403, $respuesta->get_status(), 'Un subscriber no puede crear.' );
	}

	/**
	 * Si el rol no puede ni VER el campo, se responde unknown_field y no
	 * field_forbidden: un 403 confirmaria que el campo existe.
	 */
	public function test_escribir_un_campo_invisible_da_400_no_403(): void {
		wp_set_current_user( $this->editor );

		$items = $this->request( 'GET', '/property' )->get_data();

		$request = new WP_REST_Request( 'PATCH', '/codeia/v1/property/' . $items[0]['id'] );
		$request->set_body_params( array( 'campo_inventado' => 'x' ) );

		$respuesta = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $respuesta->get_status() );
		$this->assertSame( 'codeia_unknown_field', $respuesta->get_data()['code'] );
	}

	public function test_actualiza_un_elemento(): void {
		wp_set_current_user( $this->editor );

		$items = $this->request( 'GET', '/property' )->get_data();

		$request = new WP_REST_Request( 'PATCH', '/codeia/v1/property/' . $items[0]['id'] );
		$request->set_body_params( array( 'price' => 999999 ) );

		$respuesta = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertSame( 999999.0, $respuesta->get_data()['price'] );
	}

	public function test_borra_un_elemento(): void {
		wp_set_current_user( $this->editor );

		$items     = $this->request( 'GET', '/property' )->get_data();
		$respuesta = $this->request( 'DELETE', '/property/' . $items[0]['id'] );

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertTrue( $respuesta->get_data()['deleted'] );
	}

	public function test_la_paginacion_por_cursor_devuelve_cabecera(): void {
		wp_set_current_user( $this->editor );

		$respuesta = $this->request( 'GET', '/property', array( 'per_page' => 2 ) );

		$this->assertArrayHasKey( 'X-Codeia-Cursor', $respuesta->get_headers() );
	}

	public function test_el_cursor_avanza_sin_repetir_elementos(): void {
		wp_set_current_user( $this->editor );

		$primera = $this->request( 'GET', '/property', array( 'per_page' => 2 ) );
		$cursor  = $primera->get_headers()['X-Codeia-Cursor'];

		$segunda = $this->request(
			'GET',
			'/property',
			array(
				'per_page' => 2,
				'after'    => $cursor,
			)
		);

		$this->assertSame( 200, $segunda->get_status() );

		$ids_primera = array_column( $primera->get_data(), 'id' );
		$ids_segunda = array_column( $segunda->get_data(), 'id' );

		$this->assertSame( array(), array_intersect( $ids_primera, $ids_segunda ) );
	}

	public function test_un_cursor_manipulado_devuelve_400(): void {
		wp_set_current_user( $this->editor );

		$respuesta = $this->request( 'GET', '/property', array( 'after' => 'falso.cursor' ) );

		$this->assertSame( 400, $respuesta->get_status() );
		$this->assertSame( 'codeia_invalid_cursor', $respuesta->get_data()['code'] );
	}

	public function test_el_esquema_publica_los_campos_detectados(): void {
		wp_set_current_user( $this->editor );

		$esquema = $this->request( 'GET', '/property/schema' )->get_data();

		$this->assertArrayHasKey( 'price', $esquema['properties'] );
		$this->assertSame( 'number', $esquema['properties']['price']['type'] );
	}

	/**
	 * Publicar un tipo inferido como si fuera declarado generaria clientes
	 * tipados sobre una conjetura.
	 */
	public function test_el_esquema_marca_los_tipos_inferidos(): void {
		wp_set_current_user( $this->editor );

		$esquema = $this->request( 'GET', '/property/schema' )->get_data();

		$this->assertSame( 40, $esquema['properties']['price']['x-codeia-confidence'] );
		$this->assertSame( 'db_sample', $esquema['properties']['price']['x-codeia-origin'] );
	}

	public function test_el_tope_de_per_page_se_respeta(): void {
		wp_set_current_user( $this->editor );

		$respuesta = $this->request( 'GET', '/property', array( 'per_page' => 100000 ) );

		$this->assertSame( 400, $respuesta->get_status(), 'El maximo lo impone el esquema de args.' );
	}
}

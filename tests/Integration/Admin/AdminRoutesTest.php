<?php
/**
 * Rutas internas del dashboard y saneado de configuracion.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Integration\Admin;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use WpApi\Codeia\Admin\ConfigExporter;
use WpApi\Codeia\Admin\InternalRestController;
use WpApi\Codeia\Admin\Menu;
use WpApi\Codeia\Admin\Sanitizer;
use WpApi\Codeia\Admin\StatusChecker;
use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Core\Logger;
use WpApi\Codeia\Schema\ConflictResolver;
use WpApi\Codeia\Schema\FieldNormalizer;
use WpApi\Codeia\Schema\SchemaCache;
use WpApi\Codeia\Schema\SchemaRegistry;

/**
 * @covers \WpApi\Codeia\Admin\InternalRestController
 * @covers \WpApi\Codeia\Admin\Sanitizer
 * @covers \WpApi\Codeia\Admin\ConfigExporter
 */
final class AdminRoutesTest extends WP_UnitTestCase {

	private int $admin;
	private int $editor;
	private Config $config;
	private Sanitizer $sanitizer;
	private ConfigExporter $exporter;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();

		register_post_type( 'property', array( 'public' => true ) );

		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->config    = new Config( Config::defaults() );
		$this->sanitizer = new Sanitizer( $this->config, new Logger( Logger::ERROR ) );
		$this->exporter  = new ConfigExporter( $this->config );

		$schema = new SchemaRegistry(
			new SchemaCache( new CacheManager(), $this->config ),
			new FieldNormalizer(),
			new ConflictResolver(),
			new EventDispatcher()
		);

		$controller = new InternalRestController(
			$schema,
			$this->config,
			$this->sanitizer,
			new StatusChecker( new CacheManager(), $this->config, $schema ),
			$this->exporter
		);

		add_action(
			'rest_api_init',
			static function () use ( $controller ): void {
				$controller->register_routes();
			}
		);

		do_action( 'rest_api_init', rest_get_server() );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		unregister_post_type( 'property' );
		parent::tear_down();
	}

	/**
	 * Lanza una peticion a una ruta interna.
	 *
	 * @param string $method Metodo.
	 * @param string $route  Ruta.
	 * @return \WP_REST_Response
	 */
	private function request( string $method, string $route ) {
		return rest_get_server()->dispatch( new WP_REST_Request( $method, '/codeia/v1' . $route ) );
	}

	public function test_las_rutas_internas_se_registran(): void {
		$rutas = rest_get_server()->get_routes();

		foreach ( array( 'resources', 'status', 'logs', 'export' ) as $ruta ) {
			$this->assertArrayHasKey( '/codeia/v1/admin/' . $ruta, $rutas );
		}
	}

	/**
	 * Es el fallo de seguridad mas repetido en plugins de WordPress.
	 */
	public function test_ninguna_ruta_interna_usa_return_true(): void {
		foreach ( rest_get_server()->get_routes() as $ruta => $handlers ) {
			if ( ! str_contains( $ruta, '/admin/' ) ) {
				continue;
			}

			foreach ( $handlers as $handler ) {
				$this->assertNotSame( '__return_true', $handler['permission_callback'], $ruta );
			}
		}
	}

	public function test_un_editor_no_accede_a_las_rutas_internas(): void {
		wp_set_current_user( $this->editor );

		$this->assertSame( 403, $this->request( 'GET', '/admin/status' )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/admin/resources' )->get_status() );
	}

	public function test_un_anonimo_tampoco(): void {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->request( 'GET', '/admin/status' )->get_status() );
	}

	public function test_un_administrador_si_accede(): void {
		wp_set_current_user( $this->admin );

		$this->assertSame( 200, $this->request( 'GET', '/admin/status' )->get_status() );
	}

	public function test_el_estado_devuelve_un_diagnostico(): void {
		wp_set_current_user( $this->admin );

		$filas = $this->request( 'GET', '/admin/status' )->get_data();

		$this->assertNotEmpty( $filas );
		$this->assertArrayHasKey( 'status', $filas[0] );
		$this->assertContains( $filas[0]['status'], array( 'ok', 'warning', 'error' ) );
	}

	/**
	 * Conservar claves desconocidas convierte la opcion en un vertedero y
	 * abre la puerta a inyectar datos que un isset() futuro interprete.
	 */
	public function test_el_saneador_descarta_claves_desconocidas(): void {
		$limpio = $this->sanitizer->sanitize(
			array(
				'namespace'    => 'mimarca',
				'clave_basura' => 'x',
			)
		);

		$this->assertArrayNotHasKey( 'clave_basura', $limpio );
		$this->assertSame( 'mimarca', $limpio['namespace'] );
	}

	public function test_el_saneador_descarta_post_types_inexistentes(): void {
		$limpio = $this->sanitizer->sanitize(
			array(
				'resources' => array(
					'property'  => array(
						'enabled'    => true,
						'operations' => array( 'read' ),
					),
					'no_existe' => array(
						'enabled'    => true,
						'operations' => array( 'read' ),
					),
				),
			)
		);

		$this->assertArrayHasKey( 'property', $limpio['resources'] );
		$this->assertArrayNotHasKey( 'no_existe', $limpio['resources'] );
	}

	public function test_el_saneador_descarta_operaciones_invalidas(): void {
		$limpio = $this->sanitizer->sanitize(
			array(
				'resources' => array(
					'property' => array(
						'enabled'    => true,
						'operations' => array( 'read', 'inventada', 'delete' ),
					),
				),
			)
		);

		$this->assertSame( array( 'read', 'delete' ), $limpio['resources']['property']['operations'] );
	}

	public function test_el_saneador_descarta_roles_inexistentes(): void {
		$limpio = $this->sanitizer->sanitize(
			array(
				'permissions' => array(
					'property' => array(
						'editor'    => array( 'read' => true ),
						'rol_falso' => array( 'read' => true ),
					),
				),
			)
		);

		$this->assertArrayHasKey( 'editor', $limpio['permissions']['property'] );
		$this->assertArrayNotHasKey( 'rol_falso', $limpio['permissions']['property'] );
	}

	public function test_el_saneador_acepta_el_rol_anonimo(): void {
		$limpio = $this->sanitizer->sanitize(
			array( 'permissions' => array( 'property' => array( 'anonymous' => array( 'read' => true ) ) ) )
		);

		$this->assertTrue( $limpio['permissions']['property']['anonymous']['read'] );
	}

	public function test_un_guardado_parcial_conserva_el_resto(): void {
		$config    = new Config( array_replace( Config::defaults(), array( 'api_version' => 'v3' ) ) );
		$sanitizer = new Sanitizer( $config, new Logger( Logger::ERROR ) );

		$limpio = $sanitizer->sanitize( array( 'namespace' => 'otro' ) );

		$this->assertSame( 'v3', $limpio['api_version'] );
	}

	/**
	 * Un fichero de configuracion acaba en un repositorio o en un ticket de
	 * soporte: los secretos se regeneran en destino.
	 */
	public function test_la_exportacion_no_incluye_secretos(): void {
		$config = new Config(
			array_replace_recursive(
				Config::defaults(),
				array( 'auth' => array( 'providers' => array( 'jwt' => array( 'secret' => 'NO-DEBE-SALIR' ) ) ) )
			)
		);

		$json = (string) wp_json_encode( ( new ConfigExporter( $config ) )->export() );

		$this->assertStringNotContainsString( 'NO-DEBE-SALIR', $json );
	}

	public function test_la_exportacion_declara_formato_y_version(): void {
		$documento = $this->exporter->export();

		$this->assertSame( ConfigExporter::FORMAT, $documento['format'] );
		$this->assertSame( ConfigExporter::FORMAT_VERSION, $documento['format_version'] );
		$this->assertStringStartsWith( 'sha256:', $documento['checksum'] );
	}

	public function test_valida_un_documento_correcto(): void {
		$resultado = $this->exporter->validate( $this->exporter->export() );

		$this->assertSame( array(), $resultado['errors'] );
		$this->assertSame( array(), $resultado['warnings'] );
	}

	public function test_rechaza_un_documento_de_otro_formato(): void {
		$resultado = $this->exporter->validate( array( 'format' => 'otra-cosa' ) );

		$this->assertNotEmpty( $resultado['errors'] );
	}

	public function test_avisa_de_un_checksum_que_no_cuadra(): void {
		$documento             = $this->exporter->export();
		$documento['checksum'] = 'sha256:falso';

		$resultado = $this->exporter->validate( $documento );

		$this->assertSame( array(), $resultado['errors'] );
		$this->assertNotEmpty( $resultado['warnings'] );
	}

	/**
	 * Sin esta comparacion, importar de otro entorno dejaria referencias a
	 * recursos inexistentes y una configuracion silenciosamente rota.
	 */
	public function test_compara_el_entorno_de_destino(): void {
		$documento = array(
			'config' => array(
				'resources'   => array(
					'property'  => array(),
					'no_existe' => array(),
				),
				'permissions' => array(
					'property' => array(
						'editor'    => array(),
						'rol_falso' => array(),
					),
				),
			),
		);

		$diff = $this->exporter->compare_environment( $documento );

		$this->assertSame( array( 'no_existe' ), $diff['missing_post_types'] );
		$this->assertSame( array( 'rol_falso' ), $diff['missing_roles'] );
	}

	public function test_todas_las_pantallas_exigen_manage_options(): void {
		$this->assertSame( 'manage_options', Menu::CAPABILITY );
		$this->assertCount( 7, Menu::pages() );
	}
}

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
use WpApi\Codeia\Core\Activator;
use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Core\Logger;
use WpApi\Codeia\Modules\RewriteModule;
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
			$this->exporter,
			new Logger( Logger::DEBUG )
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

		$datos = $this->request( 'GET', '/admin/status' )->get_data();

		$this->assertArrayHasKey( 'checks', $datos );
		$this->assertArrayHasKey( 'counts', $datos );
		$this->assertNotEmpty( $datos['checks'] );
		$this->assertArrayHasKey( 'status', $datos['checks'][0] );
		$this->assertContains( $datos['checks'][0]['status'], array( 'ok', 'warning', 'error' ) );
	}

	/**
	 * El color por si solo no informa: la interfaz necesita una etiqueta que
	 * acompane al semaforo para quien no distingue rojo y verde.
	 */
	public function test_cada_comprobacion_trae_etiqueta_legible(): void {
		wp_set_current_user( $this->admin );

		$checks = $this->request( 'GET', '/admin/status' )->get_data()['checks'];

		foreach ( $checks as $check ) {
			$this->assertArrayHasKey( 'label', $check );
			$this->assertNotSame( '', $check['label'] );
			$this->assertNotSame( $check['id'], $check['label'], 'La etiqueta debe ser legible, no el identificador.' );
		}
	}

	public function test_el_estado_cuenta_los_recursos_detectados(): void {
		wp_set_current_user( $this->admin );

		$counts = $this->request( 'GET', '/admin/status' )->get_data()['counts'];

		foreach ( array( 'resources', 'enabled', 'fields', 'inferred', 'conflicts' ) as $clave ) {
			$this->assertArrayHasKey( $clave, $counts );
			$this->assertIsInt( $counts[ $clave ] );
		}
	}

	public function test_los_roles_llegan_con_sus_capabilities(): void {
		wp_set_current_user( $this->admin );

		$roles = $this->request( 'GET', '/admin/roles' )->get_data();

		$slugs = array_column( $roles, 'slug' );

		$this->assertContains( 'administrator', $slugs );
		$this->assertContains( 'subscriber', $slugs );
		$this->assertContains( 'anonymous', $slugs, 'El anonimo no es un rol de WordPress pero si un eje de la matriz.' );

		$por_slug = array_column( $roles, 'capabilities', 'slug' );

		$this->assertContains( 'edit_posts', $por_slug['editor'] );
		$this->assertNotContains( 'edit_posts', $por_slug['subscriber'] );
	}

	/**
	 * La pantalla necesita leer la configuracion para pintar el estado de cada
	 * casilla, pero la clave de firma no debe salir del servidor ni siquiera
	 * hacia un administrador.
	 */
	public function test_la_configuracion_se_sirve_sin_secretos(): void {
		wp_set_current_user( $this->admin );

		$this->config->set( 'auth.providers.jwt.secret', 'clave-de-firma' );
		$this->config->save();

		$datos = $this->request( 'GET', '/admin/settings' )->get_data();

		$this->assertStringNotContainsString( 'clave-de-firma', (string) wp_json_encode( $datos ) );
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

	/**
	 * El nivel 1 de la matriz tiene forma distinta de los demas nodos: es un
	 * mapa rol => booleano, no rol => reglas. Sin un caso aparte en el
	 * saneador se descarta entero y el nivel queda inalcanzable desde el
	 * panel, con la interfaz mostrando un valor que nunca llego a guardarse.
	 */
	public function test_el_saneador_conserva_los_valores_por_defecto_de_la_matriz(): void {
		$limpio = $this->sanitizer->sanitize(
			array(
				'permissions' => array(
					'defaults' => array(
						'editor'     => true,
						'subscriber' => false,
					),
				),
			)
		);

		$this->assertArrayHasKey( 'defaults', $limpio['permissions'] );
		$this->assertTrue( $limpio['permissions']['defaults']['editor'] );
		$this->assertFalse( $limpio['permissions']['defaults']['subscriber'] );
	}

	public function test_el_saneador_descarta_roles_inexistentes_en_los_valores_por_defecto(): void {
		$limpio = $this->sanitizer->sanitize(
			array(
				'permissions' => array(
					'defaults' => array( 'rol_inventado' => true ),
				),
			)
		);

		$this->assertArrayNotHasKey( 'rol_inventado', $limpio['permissions']['defaults'] ?? array() );
	}

	public function test_el_saneador_solo_admite_los_proveedores_conocidos(): void {
		$limpio = $this->sanitizer->sanitize(
			array(
				'auth' => array(
					'providers' => array(
						'jwt'       => true,
						'inventado' => true,
					),
				),
			)
		);

		$this->assertTrue( $limpio['auth']['providers']['jwt'] );
		$this->assertArrayNotHasKey( 'inventado', $limpio['auth']['providers'] );
	}

	public function test_el_saneador_acota_la_retencion_de_registros(): void {
		$limpio = $this->sanitizer->sanitize(
			array( 'logging' => array( 'retention_days' => 99999 ) )
		);

		$this->assertSame( 365, $limpio['logging']['retention_days'] );

		$limpio = $this->sanitizer->sanitize(
			array( 'logging' => array( 'level' => 'inventado' ) )
		);

		$this->assertSame( 'info', $limpio['logging']['level'] );
	}

	public function test_la_importacion_es_simulacion_salvo_que_se_pida_aplicar(): void {
		wp_set_current_user( $this->admin );

		$documento = $this->exporter->export();

		$peticion = new WP_REST_Request( 'POST', '/codeia/v1/admin/import' );
		$peticion->set_header( 'content-type', 'application/json' );
		$peticion->set_body( (string) wp_json_encode( $documento ) );

		$datos = rest_get_server()->dispatch( $peticion )->get_data();

		$this->assertTrue( $datos['dry_run'] );
		$this->assertFalse( $datos['applied'], 'Una importacion sustituye la configuracion entera: no debe aplicarse sin pedirlo.' );
	}

	public function test_la_importacion_rechaza_un_documento_ajeno(): void {
		wp_set_current_user( $this->admin );

		$peticion = new WP_REST_Request( 'POST', '/codeia/v1/admin/import' );
		$peticion->set_header( 'content-type', 'application/json' );
		$peticion->set_body( (string) wp_json_encode( array( 'format' => 'otro-plugin' ) ) );

		$this->assertSame( 400, rest_get_server()->dispatch( $peticion )->get_status() );
	}

	/**
	 * La importacion sustituye la configuracion entera y no hay deshacer en la
	 * interfaz: sin copia previa, importar el fichero equivocado obliga a
	 * rehacer a mano toda la matriz de permisos.
	 *
	 * La comprobacion no fija un valor concreto a proposito. Quien atiende la
	 * ruta es el controlador que registra el plugin al arrancar, con su propia
	 * instancia de Config; comparar contra un valor que este test escriba en
	 * SU instancia probaria el objeto equivocado. Se compara contra lo que la
	 * propia API dice que habia antes.
	 */
	public function test_la_importacion_guarda_copia_previa_antes_de_escribir(): void {
		wp_set_current_user( $this->admin );

		delete_option( InternalRestController::BACKUP_OPTION );

		$antes = $this->request( 'GET', '/admin/settings' )->get_data();

		$documento                        = $this->exporter->export();
		$documento['config']['namespace'] = 'despues';

		$peticion = new WP_REST_Request( 'POST', '/codeia/v1/admin/import' );
		$peticion->set_header( 'content-type', 'application/json' );
		$peticion->set_body( (string) wp_json_encode( $documento ) );
		$peticion->set_query_params( array( 'dry_run' => '0' ) );

		$respuesta = rest_get_server()->dispatch( $peticion );

		$this->assertSame( 200, $respuesta->get_status() );
		$this->assertTrue( $respuesta->get_data()['applied'] );

		$copia = get_option( InternalRestController::BACKUP_OPTION );

		$this->assertIsArray( $copia, 'La importacion debe dejar copia de la configuracion anterior.' );
		$this->assertSame( $antes['namespace'], $copia['namespace'] );
		$this->assertNotSame( 'despues', $copia['namespace'], 'La copia debe ser previa a la escritura, no posterior.' );
		$this->assertSame( 'despues', ( new Config() )->get( 'namespace' ) );
	}
	public function test_la_purga_vacia_la_tabla_de_registros(): void {
		wp_set_current_user( $this->admin );

		$this->preparar_tabla_de_registros();

		$logger = new Logger( Logger::INFO );
		$logger->log( Logger::INFO, 'evento de prueba', array(), 'tests' );

		$this->assertSame( 200, $this->request( 'DELETE', '/admin/logs' )->get_status() );

		global $wpdb;

		$mensajes = $wpdb->get_col( 'SELECT message FROM ' . Logger::table_name() );

		$this->assertNotContains( 'evento de prueba', $mensajes );
		$this->assertSame(
			array( 'Registros purgados desde el panel.' ),
			$mensajes,
			'La purga deja constancia de si misma: vaciar sin rastro de quien lo hizo seria peor que no vaciar.'
		);
	}

	public function test_el_listado_de_registros_filtra_por_nivel(): void {
		wp_set_current_user( $this->admin );

		$this->preparar_tabla_de_registros();

		$logger = new Logger( Logger::DEBUG );
		$logger->log( Logger::ERROR, 'un error', array(), 'tests' );
		$logger->log( Logger::INFO, 'un aviso informativo', array(), 'tests' );

		$peticion = new WP_REST_Request( 'GET', '/codeia/v1/admin/logs' );
		$peticion->set_param( 'level', 'error' );

		$filas = rest_get_server()->dispatch( $peticion )->get_data();

		$this->assertNotEmpty( $filas );

		foreach ( $filas as $fila ) {
			$this->assertSame( 'error', $fila['level'] );
		}
	}

	public function test_el_listado_de_registros_rechaza_un_nivel_invalido(): void {
		wp_set_current_user( $this->admin );

		$peticion = new WP_REST_Request( 'GET', '/codeia/v1/admin/logs' );
		$peticion->set_param( 'level', 'inventado' );

		$this->assertSame( 400, rest_get_server()->dispatch( $peticion )->get_status() );
	}
	/**
	 * Deja la tabla de registros lista y vacia.
	 *
	 * Logger recuerda en una propiedad estatica que la tabla no estaba
	 * disponible para no reintentar una escritura condenada en cada llamada.
	 * Ese recuerdo sobrevive entre tests del mismo proceso, asi que sin
	 * reiniciarlo un test anterior que corriese sin tabla dejaria a este
	 * escribiendo en el vacio.
	 *
	 * @return void
	 */
	private function preparar_tabla_de_registros(): void {
		global $wpdb;

		Activator::create_tables();
		Logger::reset_availability();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia del plugin en la base de datos de tests.
		$wpdb->query( 'DELETE FROM ' . Logger::table_name() );
	}

	/**
	 * Cambiar el namespace o activar el alias mueve las rutas de sitio. Sin
	 * marcar la regeneracion, las reglas viejas siguen en su sitio y el alias
	 * responde 404 sin que nada en la interfaz explique por que.
	 *
	 * El flush no se ejecuta al guardar: se difiere a la siguiente carga de
	 * init, cuando todos los plugins han registrado ya sus reglas.
	 */
	public function test_activar_el_alias_marca_la_regeneracion_de_reglas(): void {
		wp_set_current_user( $this->admin );

		delete_option( RewriteModule::FLUSH_FLAG );

		$peticion = new WP_REST_Request( 'PATCH', '/codeia/v1/admin/settings' );
		$peticion->set_header( 'content-type', 'application/json' );
		$peticion->set_body( (string) wp_json_encode( array( 'modules' => array( 'rewrite' => true ) ) ) );

		$this->assertSame( 200, rest_get_server()->dispatch( $peticion )->get_status() );
		$this->assertNotFalse( get_option( RewriteModule::FLUSH_FLAG ) );
	}

	public function test_cambiar_el_namespace_marca_la_regeneracion_de_reglas(): void {
		wp_set_current_user( $this->admin );

		delete_option( RewriteModule::FLUSH_FLAG );

		$peticion = new WP_REST_Request( 'PATCH', '/codeia/v1/admin/settings' );
		$peticion->set_header( 'content-type', 'application/json' );
		$peticion->set_body( (string) wp_json_encode( array( 'namespace' => 'otromarca' ) ) );

		rest_get_server()->dispatch( $peticion );

		$this->assertNotFalse( get_option( RewriteModule::FLUSH_FLAG ) );
	}

	/**
	 * Regenerar las reglas es caro. Guardar algo que no afecta al enrutado no
	 * debe dispararlo.
	 */
	public function test_un_cambio_que_no_afecta_al_enrutado_no_marca_regeneracion(): void {
		wp_set_current_user( $this->admin );

		delete_option( RewriteModule::FLUSH_FLAG );

		$peticion = new WP_REST_Request( 'PATCH', '/codeia/v1/admin/settings' );
		$peticion->set_header( 'content-type', 'application/json' );
		$peticion->set_body( (string) wp_json_encode( array( 'logging' => array( 'retention_days' => 15 ) ) ) );

		rest_get_server()->dispatch( $peticion );

		$this->assertFalse( get_option( RewriteModule::FLUSH_FLAG ) );
	}

	/**
	 * El documento OpenAPI solo se publica si su modulo esta activo: describir
	 * la superficie de la API a quien llegue a la ruta es una decision del
	 * administrador, no un valor por defecto.
	 */
	public function test_la_ruta_de_documentacion_depende_de_su_modulo(): void {
		$config = new Config();

		$this->assertFalse(
			(bool) $config->get( 'modules.openapi', false ),
			'El modulo OpenAPI esta apagado de origen.'
		);

		$rutas = rest_get_server()->get_routes();

		$this->assertArrayNotHasKey( '/codeia/v1/docs', $rutas );
	}
}

<?php
/**
 * API interna del dashboard.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Admin;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\Logger;
use WpApi\Codeia\Modules\RewriteModule;
use WpApi\Codeia\Schema\SchemaRegistry;

/**
 * Rutas que consume el cliente React del dashboard.
 *
 * Es la UNICA API que usa la interfaz: no hay admin-ajax ni estado
 * preinyectado mas alla de la configuracion de arranque. Carga sus datos
 * igual que lo haria un cliente externo, lo que permite ejercitarla con curl
 * durante el desarrollo.
 *
 * Estas rutas NO aparecen en el documento OpenAPI: documentar la superficie
 * de administracion no aporta nada a quien integra y si a quien ataca.
 */
final class InternalRestController {

	/**
	 * Registro de esquema.
	 *
	 * @var SchemaRegistry
	 */
	/**
	 * Opcion donde se guarda la copia previa a una importacion.
	 */
	public const BACKUP_OPTION = 'codeia_config_backup';

	/**
	 * Registro de esquema.
	 *
	 * @var SchemaRegistry
	 */
	private SchemaRegistry $schema;

	/**
	 * Configuracion.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Saneador.
	 *
	 * @var Sanitizer
	 */
	private Sanitizer $sanitizer;

	/**
	 * Comprobador de estado.
	 *
	 * @var StatusChecker
	 */
	private StatusChecker $status;

	/**
	 * Exportador de configuracion.
	 *
	 * @var ConfigExporter
	 */
	private ConfigExporter $exporter;

	/**
	 * Registro de eventos.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Construye el controlador.
	 *
	 * @param SchemaRegistry $schema    Registro de esquema.
	 * @param Config         $config    Configuracion.
	 * @param Sanitizer      $sanitizer Saneador.
	 * @param StatusChecker  $status    Comprobador de estado.
	 * @param ConfigExporter $exporter  Exportador.
	 * @param Logger         $logger    Registro de eventos.
	 */
	public function __construct(
		SchemaRegistry $schema,
		Config $config,
		Sanitizer $sanitizer,
		StatusChecker $status,
		ConfigExporter $exporter,
		Logger $logger
	) {
		$this->schema    = $schema;
		$this->config    = $config;
		$this->sanitizer = $sanitizer;
		$this->status    = $status;
		$this->exporter  = $exporter;
		$this->logger    = $logger;
	}

	/**
	 * Namespace de las rutas internas.
	 *
	 * @return string
	 */
	private function namespace(): string {
		return sprintf(
			'%s/%s',
			(string) $this->config->get( 'namespace', 'codeia' ),
			(string) $this->config->get( 'api_version', 'v1' )
		);
	}

	/**
	 * Registra las rutas.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$namespace = $this->namespace();

		$routes = array(
			'/admin/resources' => array( 'GET', 'get_resources' ),
			'/admin/status'    => array( 'GET', 'get_status' ),
			'/admin/logs'      => array(
				'GET',
				'get_logs',
				array(
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 25,
						'minimum'           => 1,
						'maximum'           => 100,
						'validate_callback' => 'rest_validate_request_arg',
					),
					'level'    => array(
						'type'              => 'string',
						'enum'              => array( 'debug', 'info', 'warning', 'error' ),
						'validate_callback' => 'rest_validate_request_arg',
					),
					'channel'  => array(
						'type'              => 'string',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			),
			'/admin/export'    => array( 'GET', 'export_config' ),
			'/admin/settings'  => array( 'GET', 'get_settings' ),
			'/admin/roles'     => array( 'GET', 'get_roles' ),
		);

		foreach ( $routes as $route => $handler ) {
			register_rest_route(
				$namespace,
				$route,
				array(
					'methods'             => $handler[0],
					'callback'            => array( $this, $handler[1] ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $handler[2] ?? array(),
				)
			);
		}

		register_rest_route(
			$namespace,
			'/admin/logs',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'purge_logs' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/import',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'import_config' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/schema/rebuild',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rebuild_schema' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			$namespace,
			'/admin/settings',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Permiso de todas las rutas internas.
	 *
	 * NUNCA __return_true, ni siquiera provisionalmente durante el
	 * desarrollo: es el fallo de seguridad mas repetido en plugins de
	 * WordPress y el que mas se cuela en produccion, porque en pruebas
	 * funciona igual.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Catalogo de recursos detectados.
	 *
	 * @return WP_REST_Response
	 */
	public function get_resources(): WP_REST_Response {
		$resources = array();

		foreach ( $this->schema->detectable_post_types() as $post_type => $object ) {
			$definition = $this->schema->definition_for( (string) $post_type );

			if ( null === $definition ) {
				continue;
			}

			$fields = array();

			foreach ( $definition->fields as $field ) {
				$fields[] = $field->to_array();
			}

			$resources[] = array(
				'post_type'  => $definition->post_type,
				'label'      => $definition->label,
				'taxonomies' => $definition->taxonomies,
				'fields'     => $fields,
				'conflicts'  => $definition->conflicts,
				'enabled'    => (bool) $this->config->get( 'resources.' . $post_type . '.enabled', false ),
			);
		}

		return new WP_REST_Response( $resources, 200 );
	}

	/**
	 * Diagnostico del entorno.
	 *
	 * @return WP_REST_Response
	 */
	public function get_status(): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'checks' => $this->status->run(),
				'counts' => $this->status->counts(),
			),
			200
		);
	}

	/**
	 * Ultimos registros.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return WP_REST_Response
	 */
	public function get_logs( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;

		$table = Logger::table_name();
		$limit = min( 100, max( 1, (int) ( $request['per_page'] ?? 25 ) ) );

		/*
		 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		 *
		 * El nombre de tabla no admite marcador de posicion en prepare(): se
		 * compone desde $wpdb->prefix, nunca desde entrada externa. El unico
		 * valor variable, el limite, si va parametrizado.
		 */
		$where  = array( '1=1' );
		$params = array();

		$level = $request->get_param( 'level' );

		if ( is_string( $level ) && '' !== $level ) {
			$where[]  = 'level = %s';
			$params[] = $level;
		}

		$channel = $request->get_param( 'channel' );

		if ( is_string( $channel ) && '' !== $channel ) {
			$where[]  = 'channel = %s';
			$params[] = $channel;
		}

		$params[] = $limit;
		$clause   = implode( ' AND ', $where );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $clause solo contiene marcadores y literales de este metodo.
				"SELECT * FROM `{$table}` WHERE {$clause} ORDER BY id DESC LIMIT %d",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return new WP_REST_Response( is_array( $rows ) ? $rows : array(), 200 );
	}

	/**
	 * Reconstruye el esquema.
	 *
	 * @return WP_REST_Response
	 */
	public function rebuild_schema(): WP_REST_Response {
		$definitions = $this->schema->rebuild_all();

		return new WP_REST_Response(
			array(
				'rebuilt'   => count( $definitions ),
				'resources' => array_keys( $definitions ),
			),
			200
		);
	}

	/**
	 * Guarda un fragmento de configuracion.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}

		$antes = $this->config->all();
		$clean = $this->sanitizer->sanitize( is_array( $payload ) ? $payload : array() );

		update_option( Config::OPTION, $clean, false );

		$this->maybe_request_flush( $antes, $clean );

		return new WP_REST_Response( $clean, 200 );
	}

	/**
	 * Exporta la configuracion.
	 *
	 * @return WP_REST_Response
	 */
	public function export_config(): WP_REST_Response {
		return new WP_REST_Response( $this->exporter->export(), 200 );
	}

	/**
	 * Configuracion vigente, sin secretos.
	 *
	 * La interfaz necesita leerla para pintar el estado de cada casilla, pero
	 * la clave de firma no debe salir del servidor ni siquiera hacia un
	 * administrador: no hay ninguna pantalla que la necesite.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		$documento = $this->exporter->export();

		return new WP_REST_Response( $documento['config'], 200 );
	}

	/**
	 * Roles con sus capabilities relevantes.
	 *
	 * La matriz las usa para desactivar las celdas que WordPress ya impide.
	 * Permitir marcarlas produciria una configuracion que la segunda puerta
	 * rechazaria siempre, y el administrador creeria haber concedido algo que
	 * no funciona.
	 *
	 * @return WP_REST_Response
	 */
	public function get_roles(): WP_REST_Response {
		$relevantes = array( 'read', 'edit_posts', 'delete_posts', 'upload_files', 'manage_options' );
		$roles      = array();

		foreach ( wp_roles()->roles as $slug => $role ) {
			$caps = array();

			foreach ( $relevantes as $cap ) {
				if ( ! empty( $role['capabilities'][ $cap ] ) ) {
					$caps[] = $cap;
				}
			}

			$roles[] = array(
				'slug'         => (string) $slug,
				'label'        => (string) ( $role['name'] ?? $slug ),
				'capabilities' => $caps,
			);
		}

		// El anonimo no es un rol de WordPress, pero si un eje de la matriz.
		$roles[] = array(
			'slug'         => 'anonymous',
			'label'        => __( 'Anonimo', 'wp-api-codeia' ),
			'capabilities' => array( 'read' ),
		);

		return new WP_REST_Response( $roles, 200 );
	}

	/**
	 * Marca una regeneracion de reglas si la configuracion de rutas cambio.
	 *
	 * El flush no se ejecuta aqui: se difiere a la siguiente carga de init,
	 * cuando todos los plugins han registrado ya sus reglas. Hacerlo al
	 * guardar capturaria un estado parcial.
	 *
	 * Sin esta llamada, activar el alias desde el panel guardaba la opcion y
	 * dejaba las reglas viejas en su sitio: el alias respondia 404 y nada en
	 * la interfaz explicaba por que.
	 *
	 * @param array<string, mixed> $antes   Configuracion previa.
	 * @param array<string, mixed> $despues Configuracion guardada.
	 * @return void
	 */
	private function maybe_request_flush( array $antes, array $despues ): void {
		$relevantes = static function ( array $config ): array {
			return array(
				'namespace'   => $config['namespace'] ?? null,
				'api_version' => $config['api_version'] ?? null,
				'rewrite'     => ! empty( $config['modules']['rewrite'] ),
			);
		};

		if ( $relevantes( $antes ) === $relevantes( $despues ) ) {
			return;
		}

		RewriteModule::request_flush();

		$this->logger->info(
			'Cambio en la configuracion de rutas: regeneracion pendiente.',
			array( 'user_id' => get_current_user_id() ),
			'admin'
		);
	}

	/**
	 * Vacia la tabla de registros.
	 *
	 * @return WP_REST_Response
	 */
	public function purge_logs(): WP_REST_Response {
		global $wpdb;

		$table = Logger::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia del plugin, nombre compuesto desde $wpdb->prefix.
		$wpdb->query( "DELETE FROM `{$table}`" );

		$this->logger->info( 'Registros purgados desde el panel.', array( 'user_id' => get_current_user_id() ), 'admin' );

		return new WP_REST_Response( array( 'purged' => true ), 200 );
	}

	/**
	 * Importa un documento de configuracion.
	 *
	 * Por defecto es una simulacion: devuelve la validacion y la comparacion
	 * con el entorno sin escribir nada. Aplicar exige pedirlo explicitamente
	 * con dry_run en falso, porque una importacion sustituye la configuracion
	 * entera y no hay forma de deshacerla desde la interfaz.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_config( WP_REST_Request $request ) {
		$document = $request->get_json_params();
		$result   = $this->exporter->validate( $document );

		if ( array() !== $result['errors'] ) {
			return new WP_Error(
				'codeia_import_invalid',
				implode( ' ', $result['errors'] ),
				array( 'status' => 400 )
			);
		}

		$environment = $this->exporter->compare_environment( (array) $document );
		$dry_run     = (bool) $request->get_param( 'dry_run' );

		$response = array(
			'dry_run'     => $dry_run,
			'warnings'    => $result['warnings'],
			'environment' => $environment,
			'applied'     => false,
		);

		if ( $dry_run ) {
			return new WP_REST_Response( $response, 200 );
		}

		/*
		 * Copia previa antes de escribir. La importacion sustituye la
		 * configuracion entera: sin copia, importar el fichero equivocado
		 * obliga a reconstruir a mano toda la matriz de permisos.
		 */
		update_option( self::BACKUP_OPTION, $this->config->all(), false );

		$clean = $this->sanitizer->sanitize( (array) ( $document['config'] ?? array() ) );

		update_option( Config::OPTION, $clean, false );

		$this->logger->warning(
			'Configuracion importada.',
			array(
				'user_id'     => get_current_user_id(),
				'environment' => $environment,
			),
			'admin'
		);

		$response['applied'] = true;
		$response['config']  = $clean;

		return new WP_REST_Response( $response, 200 );
	}
}

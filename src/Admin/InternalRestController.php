<?php
/**
 * API interna del dashboard.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Admin;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\Logger;
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
	 * Construye el controlador.
	 *
	 * @param SchemaRegistry $schema    Registro de esquema.
	 * @param Config         $config    Configuracion.
	 * @param Sanitizer      $sanitizer Saneador.
	 * @param StatusChecker  $status    Comprobador de estado.
	 * @param ConfigExporter $exporter  Exportador.
	 */
	public function __construct(
		SchemaRegistry $schema,
		Config $config,
		Sanitizer $sanitizer,
		StatusChecker $status,
		ConfigExporter $exporter
	) {
		$this->schema    = $schema;
		$this->config    = $config;
		$this->sanitizer = $sanitizer;
		$this->status    = $status;
		$this->exporter  = $exporter;
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
			'/admin/logs'      => array( 'GET', 'get_logs' ),
			'/admin/export'    => array( 'GET', 'export_config' ),
		);

		foreach ( $routes as $route => $handler ) {
			register_rest_route(
				$namespace,
				$route,
				array(
					'methods'             => $handler[0],
					'callback'            => array( $this, $handler[1] ),
					'permission_callback' => array( $this, 'can_manage' ),
				)
			);
		}

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
		return new WP_REST_Response( $this->status->run(), 200 );
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
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", $limit ),
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

		$clean = $this->sanitizer->sanitize( is_array( $payload ) ? $payload : array() );

		update_option( Config::OPTION, $clean, false );

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
}

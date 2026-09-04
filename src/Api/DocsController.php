<?php
/**
 * Endpoint del documento OpenAPI.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Api;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\OpenApi\SpecCache;
use WpApi\Codeia\OpenApi\SpecGenerator;
use WpApi\Codeia\Schema\SchemaRegistry;

/**
 * Sirve el documento OpenAPI segun el modo de exposicion configurado.
 *
 * Por defecto es PRIVADO. Un documento OpenAPI es un mapa completo de la
 * superficie de ataque: rutas, parametros, tipos y metodos de autenticacion.
 * Publicarlo es legitimo para una API abierta, pero debe ser deliberado.
 */
final class DocsController {

	public const MODE_PRIVATE       = 'private';
	public const MODE_AUTHENTICATED = 'authenticated';
	public const MODE_PUBLIC        = 'public';

	/**
	 * Generador del documento.
	 *
	 * @var SpecGenerator
	 */
	private SpecGenerator $generator;

	/**
	 * Cache del documento.
	 *
	 * @var SpecCache
	 */
	private SpecCache $cache;

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
	 * Construye el controlador.
	 *
	 * @param SpecGenerator  $generator Generador.
	 * @param SpecCache      $cache     Cache.
	 * @param SchemaRegistry $schema    Registro de esquema.
	 * @param Config         $config    Configuracion.
	 */
	public function __construct(
		SpecGenerator $generator,
		SpecCache $cache,
		SchemaRegistry $schema,
		Config $config
	) {
		$this->generator = $generator;
		$this->cache     = $cache;
		$this->schema    = $schema;
		$this->config    = $config;
	}

	/**
	 * Registra la ruta.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			sprintf(
				'%s/%s',
				(string) $this->config->get( 'namespace', 'codeia' ),
				(string) $this->config->get( 'api_version', 'v1' )
			),
			'/docs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'serve' ),
				'permission_callback' => array( $this, 'can_read_docs' ),
				'args'                => array(
					'format' => array(
						'type' => 'string',
						'enum' => array( 'json' ),
					),
				),
			)
		);
	}

	/**
	 * Permiso de lectura del documento.
	 *
	 * @return bool|WP_Error
	 */
	public function can_read_docs() {
		$mode = (string) $this->config->get( 'openapi.mode', self::MODE_PRIVATE );

		if ( self::MODE_PUBLIC === $mode ) {
			return true;
		}

		if ( self::MODE_AUTHENTICATED === $mode ) {
			return get_current_user_id() > 0 ? true : ErrorFormatter::forbidden();
		}

		return current_user_can( 'manage_options' ) ? true : ErrorFormatter::forbidden();
	}

	/**
	 * Sirve el documento.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return WP_REST_Response
	 */
	public function serve( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$fields  = $this->candidate_fields();

		$spec = $this->cache->remember(
			$user_id,
			$fields['names'],
			$fields['resource'],
			function () use ( $user_id ): array {
				return $this->generator->generate( $user_id );
			}
		);

		$response = new WP_REST_Response( $spec, 200 );

		$mode = (string) $this->config->get( 'openapi.mode', self::MODE_PRIVATE );

		if ( self::MODE_PUBLIC !== $mode ) {
			// El contenido varia por ambito de permisos: no debe cachearse en
			// CDN ni en cache de pagina.
			$response->header( 'Cache-Control', 'private, no-store' );
		}

		return $response;
	}

	/**
	 * Campos de referencia para el hash de ambito.
	 *
	 * @return array<string, mixed>
	 */
	private function candidate_fields(): array {
		$resources = $this->config->get( 'resources', array() );

		if ( ! is_array( $resources ) || array() === $resources ) {
			return array(
				'names'    => array(),
				'resource' => '',
			);
		}

		$post_type  = (string) array_key_first( $resources );
		$definition = $this->schema->definition_for( $post_type );

		if ( null === $definition ) {
			return array(
				'names'    => array(),
				'resource' => $post_type,
			);
		}

		$names = array();

		foreach ( $definition->fields as $field ) {
			$names[] = $field->exposed_name;
		}

		return array(
			'names'    => $names,
			'resource' => $post_type,
		);
	}
}

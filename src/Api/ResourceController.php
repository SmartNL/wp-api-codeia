<?php
/**
 * Controlador CRUD derivado del esquema.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Api;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_Query;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WpApi\Codeia\Permissions\CollectionRestrictor;
use WpApi\Codeia\Permissions\FieldVisibility;
use WpApi\Codeia\Permissions\PermissionContext;
use WpApi\Codeia\Permissions\PermissionResolver;
use WpApi\Codeia\Schema\ResourceDefinition;

/**
 * Un solo controlador sirve a TODOS los recursos.
 *
 * Se parametriza con su ResourceDefinition en el constructor; no se genera
 * una clase por post type. Extiende WP_REST_Controller para reutilizar el
 * contrato de metodos del nucleo y su integracion con _fields.
 *
 * Las rutas nacen con el PermissionResolver real: no hay ningun
 * permission_callback provisional en ningun momento.
 */
final class ResourceController extends WP_REST_Controller {

	/**
	 * Definicion del recurso.
	 *
	 * @var ResourceDefinition
	 */
	private ResourceDefinition $definition;

	/**
	 * Resolutor de permisos.
	 *
	 * @var PermissionResolver
	 */
	private PermissionResolver $permissions;

	/**
	 * Visibilidad por campo.
	 *
	 * @var FieldVisibility
	 */
	private FieldVisibility $visibility;

	/**
	 * Restrictor de colecciones.
	 *
	 * @var CollectionRestrictor
	 */
	private CollectionRestrictor $restrictor;

	/**
	 * Proyector de campos.
	 *
	 * @var FieldProjector
	 */
	private FieldProjector $projector;

	/**
	 * Constructor de consultas.
	 *
	 * @var QueryBuilder
	 */
	private QueryBuilder $queries;

	/**
	 * Paginador por cursor.
	 *
	 * @var CursorPaginator
	 */
	private CursorPaginator $cursors;

	/**
	 * Maximo de elementos por pagina.
	 *
	 * @var int
	 */
	private int $per_page_max;

	/**
	 * Construye el controlador.
	 *
	 * @param ResourceDefinition   $definition   Definicion del recurso.
	 * @param PermissionResolver   $permissions  Resolutor.
	 * @param FieldVisibility      $visibility   Visibilidad.
	 * @param CollectionRestrictor $restrictor   Restrictor.
	 * @param FieldProjector       $projector    Proyector.
	 * @param QueryBuilder         $queries      Constructor de consultas.
	 * @param CursorPaginator      $cursors      Paginador.
	 * @param string               $namespace    Namespace REST.
	 * @param int                  $per_page_max Maximo por pagina.
	 */
	public function __construct(
		ResourceDefinition $definition,
		PermissionResolver $permissions,
		FieldVisibility $visibility,
		CollectionRestrictor $restrictor,
		FieldProjector $projector,
		QueryBuilder $queries,
		CursorPaginator $cursors,
		string $namespace,
		int $per_page_max = 100
	) {
		$this->definition   = $definition;
		$this->permissions  = $permissions;
		$this->visibility   = $visibility;
		$this->restrictor   = $restrictor;
		$this->projector    = $projector;
		$this->queries      = $queries;
		$this->cursors      = $cursors;
		$this->namespace    = $namespace;
		$this->rest_base    = $definition->post_type;
		$this->per_page_max = max( 1, $per_page_max );
	}

	/**
	 * Registra las rutas del recurso.
	 *
	 * Las operaciones NO habilitadas no se registran: responden 404, no 403.
	 * Es preferible, porque no revela que operaciones existen pero estan
	 * vetadas.
	 *
	 * @param string[] $operations Operaciones habilitadas.
	 * @return void
	 */
	public function register_resource_routes( array $operations ): void {
		$collection = array();

		if ( in_array( PermissionContext::OP_READ, $operations, true ) ) {
			$collection[] = array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => $this->get_collection_params(),
			);
		}

		if ( in_array( PermissionContext::OP_CREATE, $operations, true ) ) {
			$collection[] = array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
			);
		}

		if ( array() !== $collection ) {
			register_rest_route( $this->namespace, '/' . $this->rest_base, $collection );
		}

		$single = array();

		if ( in_array( PermissionContext::OP_READ, $operations, true ) ) {
			$single[] = array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
			);
		}

		if ( in_array( PermissionContext::OP_UPDATE, $operations, true ) ) {
			$single[] = array(
				'methods'             => 'PUT, PATCH',
				'callback'            => array( $this, 'update_item' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
			);
		}

		if ( in_array( PermissionContext::OP_DELETE, $operations, true ) ) {
			$single[] = array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_item' ),
				'permission_callback' => array( $this, 'delete_item_permissions_check' ),
			);
		}

		if ( array() !== $single ) {
			// El id se registra como digitos: aceptar slugs abriria una
			// segunda via de resolucion con reglas de permiso distintas.
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>[\d]+)',
				$single
			);
		}

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/schema',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_public_item_schema_route' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
			)
		);
	}

	/**
	 * Parametros admitidos en la coleccion.
	 *
	 * @return array<string, mixed>
	 */
	public function get_collection_params(): array {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'validate_callback' => 'rest_validate_request_arg',
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => $this->per_page_max,
				'sanitize_callback' => 'absint',
			),
			'orderby'  => array(
				'type'              => 'string',
				'default'           => 'date',
				'sanitize_callback' => 'sanitize_key',
			),
			'order'    => array(
				'type'    => 'string',
				'default' => 'desc',
				'enum'    => array( 'asc', 'desc' ),
			),
			'after'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'filter'   => array(
				'type' => 'object',
			),
			'_fields'  => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Permiso de lectura de la coleccion.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		return $this->check( PermissionContext::OP_READ );
	}

	/**
	 * Permiso de lectura de un elemento.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		return $this->check( PermissionContext::OP_READ, (int) $request['id'] );
	}

	/**
	 * Permiso de creacion.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return bool|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		return $this->check( PermissionContext::OP_CREATE );
	}

	/**
	 * Permiso de actualizacion.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return bool|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		return $this->check( PermissionContext::OP_UPDATE, (int) $request['id'] );
	}

	/**
	 * Permiso de borrado.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return bool|WP_Error
	 */
	public function delete_item_permissions_check( $request ) {
		return $this->check( PermissionContext::OP_DELETE, (int) $request['id'] );
	}

	/**
	 * Comprueba el permiso de una operacion.
	 *
	 * Cuando la operacion recae sobre un elemento que no existe o que el rol
	 * no puede ver, se responde 404 y no 403: un 403 confirmaria que existe.
	 *
	 * @param string $operation Operacion.
	 * @param int    $object_id ID del objeto, 0 si no aplica.
	 * @return bool|WP_Error
	 */
	private function check( string $operation, int $object_id = 0 ) {
		$user_id = get_current_user_id();

		if ( $object_id > 0 ) {
			$post = get_post( $object_id );

			if ( ! $post instanceof \WP_Post || $post->post_type !== $this->definition->post_type ) {
				return ErrorFormatter::not_found();
			}
		}

		$allowed = $this->permissions->can_operate(
			$user_id,
			$this->definition->post_type,
			$operation,
			$object_id
		);

		if ( $allowed ) {
			return true;
		}

		return $object_id > 0 ? ErrorFormatter::not_found() : ErrorFormatter::forbidden();
	}

	/**
	 * Lista elementos del recurso.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$user_id = get_current_user_id();

		$args = array(
			'post_type'      => $this->definition->post_type,
			'posts_per_page' => min( (int) $request['per_page'], $this->per_page_max ),
			'paged'          => (int) $request['page'],
		);

		$filters = $request->get_param( 'filter' );

		if ( is_array( $filters ) && array() !== $filters ) {
			$built = $this->queries->build( $this->definition, $filters );

			if ( is_wp_error( $built ) ) {
				return $built;
			}

			$args = array_merge( $args, $built );
		}

		$order = $this->queries->order(
			$this->definition,
			(string) $request['orderby'],
			(string) $request['order']
		);

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$args = $this->merge_order( $args, $order );

		$cursor_param = (string) ( $request['after'] ?? '' );

		if ( '' !== $cursor_param ) {
			$cursor = $this->cursors->decode( $cursor_param );

			if ( null === $cursor ) {
				return ErrorFormatter::invalid_cursor();
			}

			$args = $this->cursors->apply( $args, $cursor, (string) $request['order'] );
			unset( $args['paged'] );
		}

		// La restriccion se aplica EN la consulta, nunca filtrando despues.
		$args = $this->restrictor->apply( $args, $user_id, $this->definition->post_type );

		$query     = new WP_Query( $args );
		$requested = $this->requested_fields( $request );

		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->projector->project( $post, $this->definition, $user_id, $requested );
		}

		$response = new WP_REST_Response( $items, 200 );

		if ( empty( $args['no_found_rows'] ) ) {
			$response->header( 'X-WP-Total', (string) $query->found_posts );
			$response->header( 'X-WP-TotalPages', (string) $query->max_num_pages );
		}

		if ( array() !== $query->posts ) {
			$last = end( $query->posts );
			$response->header( 'X-Codeia-Cursor', $this->cursors->encode( $last->post_date_gmt, $last->ID ) );
		}

		return $response;
	}

	/**
	 * Devuelve un elemento.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$post = get_post( (int) $request['id'] );

		if ( ! $post instanceof \WP_Post ) {
			return ErrorFormatter::not_found();
		}

		return new WP_REST_Response(
			$this->projector->project(
				$post,
				$this->definition,
				get_current_user_id(),
				$this->requested_fields( $request )
			),
			200
		);
	}

	/**
	 * Crea un elemento.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$payload = $this->payload( $request );
		$error   = $this->reject_forbidden_writes( $payload );

		if ( null !== $error ) {
			return $error;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => $this->definition->post_type,
				'post_title'  => isset( $payload['title'] ) ? sanitize_text_field( (string) $payload['title'] ) : '',
				'post_status' => 'draft',
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$this->save_meta( (int) $post_id, $payload );

		$post = get_post( (int) $post_id );

		return new WP_REST_Response(
			$this->projector->project( $post, $this->definition, get_current_user_id() ),
			201
		);
	}

	/**
	 * Actualiza un elemento.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$post_id = (int) $request['id'];
		$payload = $this->payload( $request );
		$error   = $this->reject_forbidden_writes( $payload );

		if ( null !== $error ) {
			return $error;
		}

		if ( isset( $payload['title'] ) ) {
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => sanitize_text_field( (string) $payload['title'] ),
				)
			);
		}

		$this->save_meta( $post_id, $payload );

		return new WP_REST_Response(
			$this->projector->project(
				get_post( $post_id ),
				$this->definition,
				get_current_user_id()
			),
			200
		);
	}

	/**
	 * Borra un elemento.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$post_id = (int) $request['id'];
		$deleted = wp_trash_post( $post_id );

		if ( ! $deleted ) {
			return ErrorFormatter::not_found();
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $post_id,
			),
			200
		);
	}

	/**
	 * Sirve el esquema publico del recurso.
	 *
	 * @return WP_REST_Response
	 */
	public function get_public_item_schema_route(): WP_REST_Response {
		return new WP_REST_Response( $this->get_item_schema(), 200 );
	}

	/**
	 * Esquema JSON del recurso, fuente unica para OpenAPI y validacion.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		$properties = array(
			'id'     => array(
				'type'     => 'integer',
				'readonly' => true,
			),
			'title'  => array( 'type' => 'string' ),
			'status' => array(
				'type'     => 'string',
				'readonly' => true,
			),
			'date'   => array(
				'type'     => 'string',
				'format'   => 'date-time',
				'readonly' => true,
			),
			'link'   => array(
				'type'     => 'string',
				'format'   => 'uri',
				'readonly' => true,
			),
		);

		foreach ( $this->definition->fields as $field ) {
			$property = array( 'type' => $field->type );

			if ( null !== $field->format ) {
				$property['format'] = $field->format;
			}

			if ( array() !== $field->enum ) {
				$property['enum'] = $field->enum;
			}

			if ( $field->is_inferred() ) {
				// Publicar un tipo inferido como si fuera declarado es peor
				// que no documentarlo: se generarian clientes sobre una
				// conjetura.
				$property['x-codeia-confidence'] = $field->confidence;
				$property['x-codeia-origin']     = $field->origin;
			}

			$properties[ $field->exposed_name ] = $property;
		}

		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => $this->definition->post_type,
			'type'       => 'object',
			'properties' => $properties,
		);
	}

	/**
	 * Rechaza la peticion si toca campos no escribibles.
	 *
	 * La escritura NO se aplica parcialmente: o se acepta entera o se rechaza
	 * entera. Descartar en silencio un campo vetado devolveria 200 a un
	 * cliente que cree haber guardado el dato.
	 *
	 * @param array<string, mixed> $payload Campos entrantes.
	 * @return WP_Error|null
	 */
	private function reject_forbidden_writes( array $payload ): ?WP_Error {
		$user_id = get_current_user_id();

		foreach ( array_keys( $payload ) as $name ) {
			$name = (string) $name;

			if ( 'title' === $name ) {
				continue;
			}

			if ( null === $this->definition->storage_key_for( $name ) ) {
				return ErrorFormatter::unknown_field( $name );
			}

			/*
			 * Si el rol no puede LEER el campo, se responde unknown_field y no
			 * field_forbidden: un 403 confirmaria la existencia de un campo
			 * que ese rol no deberia saber siquiera que esta ahi.
			 */
			if ( ! $this->visibility->can_read( $user_id, $this->definition->post_type, $name ) ) {
				return ErrorFormatter::unknown_field( $name );
			}
		}

		$forbidden = $this->visibility->forbidden_writes(
			array_diff_key( $payload, array( 'title' => true ) ),
			$user_id,
			$this->definition->post_type
		);

		return array() === $forbidden ? null : ErrorFormatter::field_forbidden( $forbidden );
	}

	/**
	 * Guarda los campos meta de una escritura.
	 *
	 * @param int                  $post_id ID del post.
	 * @param array<string, mixed> $payload Campos.
	 * @return void
	 */
	private function save_meta( int $post_id, array $payload ): void {
		foreach ( $payload as $name => $value ) {
			$storage_key = $this->definition->storage_key_for( (string) $name );

			if ( null === $storage_key ) {
				continue;
			}

			update_post_meta( $post_id, $storage_key, $this->sanitize_value( $value ) );
		}
	}

	/**
	 * Sanea un valor entrante antes de persistirlo.
	 *
	 * @param mixed $value Valor.
	 * @return mixed
	 */
	private function sanitize_value( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'sanitize_value' ), $value );
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		return sanitize_text_field( (string) $value );
	}

	/**
	 * Extrae el cuerpo de la peticion.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return array<string, mixed>
	 */
	private function payload( WP_REST_Request $request ): array {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			$body = $request->get_body_params();
		}

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Campos solicitados con _fields.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return string[]
	 */
	private function requested_fields( WP_REST_Request $request ): array {
		$raw = (string) ( $request['_fields'] ?? '' );

		if ( '' === $raw ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	/**
	 * Fusiona los argumentos de ordenacion sin pisar los filtros meta.
	 *
	 * @param array<string, mixed> $args  Argumentos actuales.
	 * @param array<string, mixed> $order Argumentos de ordenacion.
	 * @return array<string, mixed>
	 */
	private function merge_order( array $args, array $order ): array {
		if ( isset( $order['meta_query'] ) && isset( $args['meta_query'] ) ) {
			$args['meta_query'] = array_merge( $args['meta_query'], $order['meta_query'] );
			unset( $order['meta_query'] );
		}

		return array_merge( $args, $order );
	}

	/**
	 * Definicion del recurso que sirve este controlador.
	 *
	 * @return ResourceDefinition
	 */
	public function definition(): ResourceDefinition {
		return $this->definition;
	}
}

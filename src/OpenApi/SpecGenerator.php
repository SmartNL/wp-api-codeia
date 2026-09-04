<?php
/**
 * Generacion del documento OpenAPI.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\OpenApi;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Api\RouteRegistrar;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Permissions\FieldVisibility;
use WpApi\Codeia\Schema\ResourceDefinition;
use WpApi\Codeia\Schema\SchemaRegistry;

/**
 * Deriva el documento de la configuracion vigente.
 *
 * Consume los mismos objetos que producen las rutas. Que ambos salgan del
 * mismo sitio es lo que impide la deriva entre documentacion e
 * implementacion: la alternativa habitual —anotaciones o ficheros YAML
 * mantenidos aparte— envejece en cuanto alguien cambia la configuracion.
 *
 * Se elige OpenAPI 3.1 porque su modelo de esquemas es JSON Schema estandar,
 * y los esquemas de WordPress ya son JSON Schema. En 3.0 habria que traducir
 * cada type nulo a nullable, un paso con perdida.
 */
final class SpecGenerator {

	/**
	 * Version de OpenAPI que se emite.
	 */
	public const OPENAPI_VERSION = '3.1.0';

	/**
	 * Registro de esquema.
	 *
	 * @var SchemaRegistry
	 */
	private SchemaRegistry $schema;

	/**
	 * Registrador de rutas.
	 *
	 * @var RouteRegistrar
	 */
	private RouteRegistrar $routes;

	/**
	 * Traductor de esquemas.
	 *
	 * @var SchemaMapper
	 */
	private SchemaMapper $mapper;

	/**
	 * Generador de esquemas de seguridad.
	 *
	 * @var SecuritySchemeBuilder
	 */
	private SecuritySchemeBuilder $security;

	/**
	 * Visibilidad por campo.
	 *
	 * @var FieldVisibility
	 */
	private FieldVisibility $visibility;

	/**
	 * Configuracion del plugin.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Bus de eventos.
	 *
	 * @var EventDispatcher
	 */
	private EventDispatcher $events;

	/**
	 * Construye el generador.
	 *
	 * @param SchemaRegistry        $schema     Registro de esquema.
	 * @param RouteRegistrar        $routes     Registrador de rutas.
	 * @param SchemaMapper          $mapper     Traductor de esquemas.
	 * @param SecuritySchemeBuilder $security   Esquemas de seguridad.
	 * @param FieldVisibility       $visibility Visibilidad por campo.
	 * @param Config                $config     Configuracion.
	 * @param EventDispatcher       $events     Bus de eventos.
	 */
	public function __construct(
		SchemaRegistry $schema,
		RouteRegistrar $routes,
		SchemaMapper $mapper,
		SecuritySchemeBuilder $security,
		FieldVisibility $visibility,
		Config $config,
		EventDispatcher $events
	) {
		$this->schema     = $schema;
		$this->routes     = $routes;
		$this->mapper     = $mapper;
		$this->security   = $security;
		$this->visibility = $visibility;
		$this->config     = $config;
		$this->events     = $events;
	}

	/**
	 * Genera el documento para un usuario concreto.
	 *
	 * El documento describe campos, y los campos tienen visibilidad por rol.
	 * Uno solo con todos los campos filtraria la existencia de cadastral_ref
	 * a un consumidor anonimo: el NOMBRE de un campo ya es informacion.
	 *
	 * @param int $user_id Usuario que lo pide.
	 * @return array<string, mixed>
	 */
	public function generate( int $user_id ): array {
		$spec = array(
			'openapi'    => self::OPENAPI_VERSION,
			'info'       => array(
				'title'   => get_bloginfo( 'name' ) . ' — API',
				'version' => (string) $this->config->get( 'version', 1 ),
			),
			'servers'    => $this->servers(),
			'components' => array(
				'securitySchemes' => $this->security->schemes(),
				'schemas'         => array(),
				'responses'       => $this->shared_responses(),
				'parameters'      => $this->shared_parameters(),
			),
			'paths'      => array(),
		);

		foreach ( $this->routes->enabled_resources() as $post_type => $operations ) {
			$definition = $this->schema->definition_for( (string) $post_type );

			if ( null === $definition ) {
				continue;
			}

			$this->add_resource( $spec, $definition, $operations, $user_id );
		}

		$filtered = $this->events->filter( 'openapi/spec', $spec );

		return is_array( $filtered ) ? $filtered : $spec;
	}

	/**
	 * Anade un recurso al documento.
	 *
	 * @param array<string, mixed> $spec       Documento, por referencia.
	 * @param ResourceDefinition   $definition Definicion.
	 * @param string[]             $operations Operaciones habilitadas.
	 * @param int                  $user_id    Usuario.
	 * @return void
	 */
	private function add_resource( array &$spec, ResourceDefinition $definition, array $operations, int $user_id ): void {
		$name = ucfirst( str_replace( array( '-', '_' ), '', $definition->post_type ) );
		$base = '/' . $definition->post_type;

		/*
		 * Tres esquemas por recurso, no uno. Uno compartido produce clientes
		 * generados que envian id en el POST o consideran obligatorio en
		 * escritura lo que solo aparece en lectura.
		 */
		$spec['components']['schemas'][ $name ]            = $this->read_schema( $definition, $user_id );
		$spec['components']['schemas'][ $name . 'Create' ] = $this->write_schema( $definition, $user_id );
		$spec['components']['schemas'][ $name . 'Update' ] = $this->write_schema( $definition, $user_id );

		$collection = array();

		if ( in_array( 'read', $operations, true ) ) {
			$collection['get'] = array(
				'operationId' => 'list' . $name,
				'summary'     => sprintf( 'Lista %s', $definition->label ),
				'parameters'  => $this->collection_parameters(),
				'security'    => $this->security->requirement( true ),
				'responses'   => array(
					'200' => array(
						'description' => 'Coleccion',
						'content'     => array(
							'application/json' => array(
								'schema' => array(
									'type'  => 'array',
									'items' => array( '$ref' => '#/components/schemas/' . $name ),
								),
							),
						),
					),
					'429' => array( '$ref' => '#/components/responses/RateLimited' ),
				),
			);
		}

		if ( in_array( 'create', $operations, true ) ) {
			$collection['post'] = array(
				'operationId' => 'create' . $name,
				'summary'     => sprintf( 'Crea %s', $definition->label ),
				'security'    => $this->security->requirement( false ),
				'requestBody' => array(
					'required' => true,
					'content'  => array(
						'application/json' => array(
							'schema' => array( '$ref' => '#/components/schemas/' . $name . 'Create' ),
						),
					),
				),
				'responses'   => array(
					'201' => array(
						'description' => 'Creado',
						'content'     => array(
							'application/json' => array(
								'schema' => array( '$ref' => '#/components/schemas/' . $name ),
							),
						),
					),
					'403' => array( '$ref' => '#/components/responses/Forbidden' ),
				),
			);
		}

		if ( array() !== $collection ) {
			$spec['paths'][ $base ] = $collection;
		}

		$single = array();

		if ( in_array( 'read', $operations, true ) ) {
			$single['get'] = array(
				'operationId' => 'get' . $name,
				'parameters'  => array( $this->id_parameter() ),
				'security'    => $this->security->requirement( true ),
				'responses'   => array(
					'200' => array(
						'description' => 'Elemento',
						'content'     => array(
							'application/json' => array(
								'schema' => array( '$ref' => '#/components/schemas/' . $name ),
							),
						),
					),
					'404' => array( '$ref' => '#/components/responses/NotFound' ),
				),
			);
		}

		if ( in_array( 'update', $operations, true ) ) {
			$single['patch'] = array(
				'operationId' => 'update' . $name,
				'parameters'  => array( $this->id_parameter() ),
				'security'    => $this->security->requirement( false ),
				'requestBody' => array(
					'required' => true,
					'content'  => array(
						'application/json' => array(
							'schema' => array( '$ref' => '#/components/schemas/' . $name . 'Update' ),
						),
					),
				),
				'responses'   => array(
					'200' => array( 'description' => 'Actualizado' ),
					'403' => array( '$ref' => '#/components/responses/Forbidden' ),
				),
			);
		}

		if ( in_array( 'delete', $operations, true ) ) {
			$single['delete'] = array(
				'operationId' => 'delete' . $name,
				'parameters'  => array( $this->id_parameter() ),
				'security'    => $this->security->requirement( false ),
				'responses'   => array(
					'200' => array( 'description' => 'Eliminado' ),
					'404' => array( '$ref' => '#/components/responses/NotFound' ),
				),
			);
		}

		if ( array() !== $single ) {
			$spec['paths'][ $base . '/{id}' ] = $single;
		}
	}

	/**
	 * Esquema de lectura, con los campos visibles para el usuario.
	 *
	 * @param ResourceDefinition $definition Definicion.
	 * @param int                $user_id    Usuario.
	 * @return array<string, mixed>
	 */
	private function read_schema( ResourceDefinition $definition, int $user_id ): array {
		$properties = array(
			'id'     => array(
				'type'     => 'integer',
				'readOnly' => true,
			),
			'title'  => array( 'type' => 'string' ),
			'status' => array(
				'type'     => 'string',
				'readOnly' => true,
			),
			'date'   => array(
				'type'     => 'string',
				'format'   => 'date-time',
				'readOnly' => true,
			),
			'link'   => array(
				'type'     => 'string',
				'format'   => 'uri',
				'readOnly' => true,
			),
		);

		foreach ( $definition->fields as $field ) {
			if ( ! $this->visibility->can_read( $user_id, $definition->post_type, $field->exposed_name ) ) {
				continue;
			}

			$properties[ $field->exposed_name ] = $this->mapper->field_to_property( $field );
		}

		return $this->mapper->to_openapi(
			array(
				'type'       => 'object',
				'properties' => $properties,
			)
		);
	}

	/**
	 * Esquema de escritura: sin campos de solo lectura.
	 *
	 * @param ResourceDefinition $definition Definicion.
	 * @param int                $user_id    Usuario.
	 * @return array<string, mixed>
	 */
	private function write_schema( ResourceDefinition $definition, int $user_id ): array {
		$properties = array( 'title' => array( 'type' => 'string' ) );

		foreach ( $definition->fields as $field ) {
			if ( ! $this->visibility->can_write( $user_id, $definition->post_type, $field->exposed_name ) ) {
				continue;
			}

			$properties[ $field->exposed_name ] = $this->mapper->field_to_property( $field );
		}

		return $this->mapper->to_openapi(
			array(
				'type'       => 'object',
				'properties' => $properties,
			)
		);
	}

	/**
	 * Servidores declarados.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function servers(): array {
		$namespace = (string) $this->config->get( 'namespace', 'codeia' );
		$version   = (string) $this->config->get( 'api_version', 'v1' );

		$servers = array(
			array( 'url' => rest_url( $namespace . '/' . $version ) ),
		);

		if ( $this->config->get( 'modules.rewrite', false ) ) {
			$servers[] = array( 'url' => home_url( '/' . $namespace . '/' . $version ) );
		}

		return $servers;
	}

	/**
	 * Respuestas de error compartidas.
	 *
	 * Se referencian en lugar de repetirse: con seis recursos, cinco
	 * operaciones y cinco errores, la diferencia son cientos de lineas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function shared_responses(): array {
		$error = array(
			'type'       => 'object',
			'properties' => array(
				'code'    => array( 'type' => 'string' ),
				'message' => array( 'type' => 'string' ),
				'data'    => array( 'type' => 'object' ),
			),
		);

		$make = static function ( string $description ) use ( $error ): array {
			return array(
				'description' => $description,
				'content'     => array( 'application/json' => array( 'schema' => $error ) ),
			);
		};

		return array(
			'Unauthorized' => $make( 'Credencial ausente o invalida' ),
			'Forbidden'    => $make( 'Sin permiso para la operacion' ),
			'NotFound'     => $make( 'El recurso no existe' ),
			'RateLimited'  => $make( 'Limite de peticiones superado' ),
		);
	}

	/**
	 * Parametros reutilizables.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function shared_parameters(): array {
		return array(
			'Page'    => $this->query_param( 'page', 'integer', 'Numero de pagina' ),
			'PerPage' => $this->query_param( 'per_page', 'integer', 'Elementos por pagina' ),
			'Fields'  => $this->query_param( '_fields', 'string', 'Campos a devolver, separados por coma' ),
		);
	}

	/**
	 * Parametros de una coleccion.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function collection_parameters(): array {
		return array(
			array( '$ref' => '#/components/parameters/Page' ),
			array( '$ref' => '#/components/parameters/PerPage' ),
			array( '$ref' => '#/components/parameters/Fields' ),
		);
	}

	/**
	 * Parametro de ruta id.
	 *
	 * @return array<string, mixed>
	 */
	private function id_parameter(): array {
		return array(
			'name'     => 'id',
			'in'       => 'path',
			'required' => true,
			'schema'   => array( 'type' => 'integer' ),
		);
	}

	/**
	 * Compone un parametro de consulta.
	 *
	 * @param string $name        Nombre.
	 * @param string $type        Tipo.
	 * @param string $description Descripcion.
	 * @return array<string, mixed>
	 */
	private function query_param( string $name, string $type, string $description ): array {
		return array(
			'name'        => $name,
			'in'          => 'query',
			'description' => $description,
			'schema'      => array( 'type' => $type ),
		);
	}
}

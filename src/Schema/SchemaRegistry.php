<?php
/**
 * Registro de esquema: orquesta los cuatro niveles de deteccion.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Schema;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Schema\Providers\FieldProvider;

/**
 * Construye, cachea y sirve las definiciones de recurso.
 *
 * No sabe que existe una API REST: se puede ejercitar desde WP-CLI o desde un
 * test sin levantar una peticion HTTP. Esa independencia es lo que permite
 * que endpoints, permisos por campo y OpenAPI sean proyecciones del mismo
 * modelo.
 */
final class SchemaRegistry {

	/**
	 * Proveedores registrados, en orden de consulta.
	 *
	 * @var FieldProvider[]
	 */
	private array $providers = array();

	/**
	 * Cache de esquema.
	 *
	 * @var SchemaCache
	 */
	private SchemaCache $cache;

	/**
	 * Normalizador de nombres.
	 *
	 * @var FieldNormalizer
	 */
	private FieldNormalizer $normalizer;

	/**
	 * Resolutor de conflictos.
	 *
	 * @var ConflictResolver
	 */
	private ConflictResolver $resolver;

	/**
	 * Bus de eventos.
	 *
	 * @var EventDispatcher
	 */
	private EventDispatcher $events;

	/**
	 * Construye el registro.
	 *
	 * @param SchemaCache      $cache      Cache.
	 * @param FieldNormalizer  $normalizer Normalizador.
	 * @param ConflictResolver $resolver   Resolutor.
	 * @param EventDispatcher  $events     Bus de eventos.
	 */
	public function __construct(
		SchemaCache $cache,
		FieldNormalizer $normalizer,
		ConflictResolver $resolver,
		EventDispatcher $events
	) {
		$this->cache      = $cache;
		$this->normalizer = $normalizer;
		$this->resolver   = $resolver;
		$this->events     = $events;
	}

	/**
	 * Registra un proveedor de campos.
	 *
	 * @param FieldProvider $provider Proveedor.
	 * @return void
	 */
	public function add_provider( FieldProvider $provider ): void {
		$this->providers[] = $provider;
	}

	/**
	 * Proveedores disponibles en esta instalacion.
	 *
	 * @return FieldProvider[]
	 */
	public function available_providers(): array {
		$providers = $this->events->filter( 'schema/providers', $this->providers );

		if ( ! is_array( $providers ) ) {
			$providers = $this->providers;
		}

		return array_values(
			array_filter(
				$providers,
				static function ( $provider ): bool {
					return $provider instanceof FieldProvider && $provider->is_available();
				}
			)
		);
	}

	/**
	 * Post types candidatos a exponerse.
	 *
	 * El filtro show_in_rest NO se aplica aqui: un CPT excluido de la REST API
	 * nativa puede exponerse por este plugin, que es un namespace distinto
	 * con configuracion propia.
	 *
	 * @return array<string, \WP_Post_Type>
	 */
	public function detectable_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'objects' );

		return is_array( $types ) ? $types : array();
	}

	/**
	 * Devuelve la definicion de un recurso, de cache o construyendola.
	 *
	 * @param string $post_type Post type.
	 * @param bool   $fresh     Fuerza la reconstruccion.
	 * @return ResourceDefinition|null
	 */
	public function definition_for( string $post_type, bool $fresh = false ): ?ResourceDefinition {
		if ( ! $fresh ) {
			$cached = $this->cache->get( $post_type );

			if ( null !== $cached ) {
				return $cached;
			}
		}

		$object = get_post_type_object( $post_type );

		if ( null === $object ) {
			return null;
		}

		$definition = $this->build( $post_type, $object );

		$this->cache->put( $definition );

		return $definition;
	}

	/**
	 * Construye la definicion recorriendo los cuatro niveles.
	 *
	 * @param string        $post_type Post type.
	 * @param \WP_Post_Type $type_object Objeto del post type.
	 * @return ResourceDefinition
	 */
	private function build( string $post_type, \WP_Post_Type $type_object ): ResourceDefinition {
		$this->events->emit( 'schema/before_rebuild', $post_type );

		$collected = array();

		foreach ( $this->available_providers() as $provider ) {
			foreach ( $provider->fields_for( $post_type ) as $field ) {
				$collected[] = $field;
			}
		}

		$resolved = $this->resolver->resolve_all( $collected );
		$resolved = $this->apply_names( $resolved, $post_type );

		$filtered = $this->events->filter( 'schema/resource_fields', $resolved, $post_type );

		if ( is_array( $filtered ) ) {
			$resolved = $filtered;
		}

		$definition = new ResourceDefinition(
			$post_type,
			array(
				'label'        => $type_object->labels->name ?? $post_type,
				'hierarchical' => (bool) $type_object->hierarchical,
				'supports'     => array_keys( (array) get_all_post_type_supports( $post_type ) ),
				'taxonomies'   => array_values( get_object_taxonomies( $post_type ) ),
			),
			$resolved,
			array(
				'types'      => $this->resolver->conflicts(),
				'names'      => $this->normalizer->collisions(),
				'exclusions' => $this->exclusion_reasons(),
			)
		);

		$this->events->emit( 'schema/rebuilt', $post_type, $definition );

		return $definition;
	}

	/**
	 * Asigna los nombres publicos resolviendo colisiones.
	 *
	 * @param FieldDefinition[] $fields    Campos indexados por storage_key.
	 * @param string            $post_type Post type.
	 * @return FieldDefinition[]
	 */
	private function apply_names( array $fields, string $post_type ): array {
		$map = $this->normalizer->normalize_all( array_keys( $fields ), $post_type );

		foreach ( $fields as $key => $field ) {
			if ( FieldDefinition::ORIGIN_MANUAL === $field->origin ) {
				// Lo manual gana tambien en el nombre: el administrador ya
				// decidio como quiere que se llame el campo.
				continue;
			}

			$field->exposed_name = $map[ $key ] ?? $field->exposed_name;
		}

		return $fields;
	}

	/**
	 * Motivos de exclusion acumulados por los proveedores.
	 *
	 * @return array<string, string>
	 */
	private function exclusion_reasons(): array {
		foreach ( $this->providers as $provider ) {
			if ( method_exists( $provider, 'exclusion_reasons' ) ) {
				return (array) $provider->exclusion_reasons();
			}
		}

		return array();
	}

	/**
	 * Reconstruye el esquema de todos los recursos detectables.
	 *
	 * Operacion cara: recorre el muestreo de base de datos. Solo se invoca
	 * desde la activacion, un rebuild manual o un cron.
	 *
	 * @return ResourceDefinition[]
	 */
	public function rebuild_all(): array {
		$this->cache->reset_env_hash();

		$definitions = array();

		foreach ( array_keys( $this->detectable_post_types() ) as $post_type ) {
			$definition = $this->definition_for( (string) $post_type, true );

			if ( null !== $definition ) {
				$definitions[ (string) $post_type ] = $definition;
			}
		}

		return $definitions;
	}
}

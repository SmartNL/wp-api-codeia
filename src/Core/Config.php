<?php
/**
 * Gestor de configuracion del plugin.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Lee, valida y persiste la configuracion.
 *
 * Toda la configuracion vive en una sola opcion con autoload desactivado: con
 * muchos recursos y campos llega a cientos de kilobytes, y cargarla en cada
 * peticion del sitio, incluidas las del front-end que nunca tocan la API,
 * seria un coste inaceptable.
 *
 * Los secretos (clave de firma JWT) NO viven aqui: van en opciones aparte
 * para que la configuracion se pueda exportar sin arrastrar credenciales.
 */
final class Config {

	/**
	 * Nombre de la opcion.
	 */
	public const OPTION = 'codeia_settings';

	/**
	 * Version del esquema de configuracion que entiende este codigo.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Configuracion en memoria.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Construye el gestor.
	 *
	 * @param array<string, mixed>|null $data Configuracion explicita. Si es
	 *                                        null se carga de la base de datos.
	 */
	public function __construct( ?array $data = null ) {
		$this->data = $data ?? $this->load();
	}

	/**
	 * Valores por defecto.
	 *
	 * Denegacion por defecto: sin recursos expuestos y con los modulos
	 * opcionales apagados.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'version'     => self::SCHEMA_VERSION,
			'namespace'   => 'codeia',
			'api_version' => 'v1',
			'modules'     => array(
				'media'   => false,
				'openapi' => false,
				'rewrite' => false,
			),
			'auth'        => array(
				'providers' => array(),
			),
			'resources'   => array(),
			'permissions' => array(),
			'limits'      => array(
				'per_page_max'      => 100,
				'meta_clauses_max'  => 4,
				'search_max_length' => 200,
			),
			'logging'     => array(
				'level'          => 'info',
				'retention_days' => 30,
			),
		);
	}

	/**
	 * Carga la configuracion desde la base de datos y aplica migraciones.
	 *
	 * @return array<string, mixed>
	 */
	private function load(): array {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) || array() === $stored ) {
			return self::defaults();
		}

		return $this->migrate( $stored );
	}

	/**
	 * Aplica las migraciones pendientes.
	 *
	 * Las migraciones son idempotentes y no destructivas: renombrar una clave
	 * copia el valor nuevo y deja el viejo una version mas, para que revertir
	 * el plugin no pierda la configuracion.
	 *
	 * @param array<string, mixed> $data Configuracion almacenada.
	 * @return array<string, mixed>
	 */
	public function migrate( array $data ): array {
		$from = isset( $data['version'] ) && is_int( $data['version'] ) ? $data['version'] : 0;

		if ( $from >= self::SCHEMA_VERSION ) {
			return $this->fill_defaults( $data );
		}

		// Sin migraciones todavia: la v1 es la primera. Cuando existan, se
		// aplican aqui en orden ascendente, cada una idempotente.
		$data['version'] = self::SCHEMA_VERSION;

		return $this->fill_defaults( $data );
	}

	/**
	 * Completa las ramas ausentes con sus valores por defecto.
	 *
	 * @param array<string, mixed> $data Configuracion parcial.
	 * @return array<string, mixed>
	 */
	private function fill_defaults( array $data ): array {
		return array_replace_recursive( self::defaults(), $data );
	}

	/**
	 * Lee un valor con notacion de punto.
	 *
	 * @param string $path     Ruta, por ejemplo "limits.per_page_max".
	 * @param mixed  $fallback Valor devuelto si la ruta no existe.
	 * @return mixed
	 */
	public function get( string $path, mixed $fallback = null ): mixed {
		$segments = explode( '.', $path );
		$cursor   = $this->data;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return $fallback;
			}

			$cursor = $cursor[ $segment ];
		}

		return $cursor;
	}

	/**
	 * Escribe un valor en memoria con notacion de punto.
	 *
	 * No persiste: hay que llamar a save() despues.
	 *
	 * @param string $path  Ruta.
	 * @param mixed  $value Valor.
	 * @return void
	 */
	public function set( string $path, mixed $value ): void {
		$segments = explode( '.', $path );
		$cursor   = &$this->data;

		foreach ( $segments as $segment ) {
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = array();
			}

			$cursor = &$cursor[ $segment ];
		}

		$cursor = $value;
	}

	/**
	 * Devuelve la configuracion completa.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		return $this->data;
	}

	/**
	 * Persiste la configuracion.
	 *
	 * @return bool
	 */
	public function save(): bool {
		return update_option( self::OPTION, $this->data, false );
	}

	/**
	 * Sanea un fragmento entrante y lo fusiona con la configuracion actual.
	 *
	 * Es la unica puerta de escritura. Descarta las claves desconocidas en
	 * lugar de conservarlas: mantenerlas convertiria la opcion en un vertedero
	 * y abriria la puerta a inyectar datos que un isset() futuro interprete.
	 *
	 * @param array<string, mixed> $input Fragmento entrante.
	 * @return array<string, mixed> Configuracion completa y saneada.
	 */
	public function sanitize( array $input ): array {
		$merged   = array_replace_recursive( $this->data, $input );
		$defaults = self::defaults();
		$clean    = array();

		// Solo sobreviven las claves de primer nivel que existen por defecto.
		foreach ( array_keys( $defaults ) as $key ) {
			$clean[ $key ] = $merged[ $key ] ?? $defaults[ $key ];
		}

		$clean['namespace']   = $this->sanitize_namespace( (string) $clean['namespace'] );
		$clean['api_version'] = $this->sanitize_api_version( (string) $clean['api_version'] );
		$clean['modules']     = $this->sanitize_bool_map( $clean['modules'], $defaults['modules'] );
		$clean['limits']      = $this->sanitize_limits( $clean['limits'] );
		$clean['version']     = self::SCHEMA_VERSION;

		return $clean;
	}

	/**
	 * Sanea el namespace REST.
	 *
	 * @param string $value Valor entrante.
	 * @return string
	 */
	private function sanitize_namespace( string $value ): string {
		$clean = sanitize_key( $value );

		return '' === $clean ? 'codeia' : $clean;
	}

	/**
	 * Sanea la version de la API.
	 *
	 * @param string $value Valor entrante.
	 * @return string
	 */
	private function sanitize_api_version( string $value ): string {
		return 1 === preg_match( '/^v\d+$/', $value ) ? $value : 'v1';
	}

	/**
	 * Sanea un mapa de banderas booleanas contra su plantilla.
	 *
	 * @param mixed               $value    Valor entrante.
	 * @param array<string, bool> $template Claves admitidas y su defecto.
	 * @return array<string, bool>
	 */
	private function sanitize_bool_map( mixed $value, array $template ): array {
		$clean = array();

		foreach ( $template as $key => $default ) {
			$clean[ $key ] = is_array( $value ) && isset( $value[ $key ] )
				? (bool) $value[ $key ]
				: $default;
		}

		return $clean;
	}

	/**
	 * Sanea los limites numericos, aplicando topes duros.
	 *
	 * Los topes no son configurables por encima de estos valores: per_page sin
	 * limite es una denegacion de servicio de una linea.
	 *
	 * @param mixed $value Valor entrante.
	 * @return array<string, int>
	 */
	private function sanitize_limits( mixed $value ): array {
		$defaults = self::defaults()['limits'];
		$caps     = array(
			'per_page_max'      => 100,
			'meta_clauses_max'  => 10,
			'search_max_length' => 500,
		);

		$clean = array();

		foreach ( $defaults as $key => $default ) {
			$candidate     = is_array( $value ) && isset( $value[ $key ] ) ? absint( $value[ $key ] ) : $default;
			$clean[ $key ] = max( 1, min( $candidate, $caps[ $key ] ) );
		}

		return $clean;
	}

	/**
	 * Hash corto del estado del entorno.
	 *
	 * Se incrusta en las claves de cache: cuando cambia, todas las entradas
	 * derivadas quedan huerfanas y expiran solas, sin necesitar un borrado
	 * por grupos que el object cache no garantiza.
	 *
	 * @return string
	 */
	public function env_hash(): string {
		$signature = array(
			array_keys( get_post_types( array(), 'names' ) ),
			array_keys( get_taxonomies( array(), 'names' ) ),
			get_option( 'active_plugins', array() ),
			CODEIA_VERSION,
			$this->get( 'version' ),
		);

		return substr( md5( (string) wp_json_encode( $signature ) ), 0, 8 );
	}
}

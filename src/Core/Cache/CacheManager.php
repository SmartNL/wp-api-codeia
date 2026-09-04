<?php
/**
 * Jerarquia de cache del plugin.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Core\Cache;

defined( 'ABSPATH' ) || exit;

/**
 * Coordina el nivel en memoria (L1) y el persistente (L2).
 *
 * Una lectura consulta L1 y, si falla, L2; un acierto en L2 se promueve a L1
 * para que la siguiente lectura de la misma peticion no vuelva a salir. Una
 * escritura va a los dos niveles.
 *
 * Los grupos y TTL de referencia estan en docs/10-rendimiento.md.
 */
final class CacheManager {

	public const GROUP_SCHEMA   = 'codeia_schema';
	public const GROUP_RESPONSE = 'codeia_response';
	public const GROUP_OPENAPI  = 'codeia_openapi';
	public const GROUP_STATUS   = 'codeia_status';

	/**
	 * TTL por grupo cuando hay object cache persistente, en segundos.
	 */
	private const TTL_PERSISTENT = array(
		self::GROUP_SCHEMA   => 12 * HOUR_IN_SECONDS,
		self::GROUP_RESPONSE => 5 * MINUTE_IN_SECONDS,
		self::GROUP_OPENAPI  => 12 * HOUR_IN_SECONDS,
		self::GROUP_STATUS   => 5 * MINUTE_IN_SECONDS,
	);

	/**
	 * TTL por grupo cuando solo hay transients.
	 *
	 * Se reducen a proposito: cada escritura es una fila en wp_options y las
	 * entradas caducadas no se recogen solas.
	 */
	private const TTL_TRANSIENT = array(
		self::GROUP_SCHEMA   => HOUR_IN_SECONDS,
		self::GROUP_RESPONSE => MINUTE_IN_SECONDS,
		self::GROUP_OPENAPI  => HOUR_IN_SECONDS,
		self::GROUP_STATUS   => 5 * MINUTE_IN_SECONDS,
	);

	/**
	 * Primer nivel, siempre presente.
	 *
	 * @var MemoryDriver
	 */
	private MemoryDriver $l1;

	/**
	 * Segundo nivel, persistente entre peticiones.
	 *
	 * @var CacheInterface
	 */
	private CacheInterface $l2;

	/**
	 * Construye la jerarquia.
	 *
	 * @param CacheInterface|null $l2 Driver persistente. Si es null se elige
	 *                                segun el entorno.
	 * @param MemoryDriver|null   $l1 Driver en memoria. Inyectable para tests.
	 */
	public function __construct( ?CacheInterface $l2 = null, ?MemoryDriver $l1 = null ) {
		$this->l1 = $l1 ?? new MemoryDriver();
		$this->l2 = $l2 ?? self::detect_persistent_driver();
	}

	/**
	 * Elige el driver persistente segun el entorno.
	 *
	 * @return CacheInterface
	 */
	public static function detect_persistent_driver(): CacheInterface {
		return wp_using_ext_object_cache()
			? new ObjectCacheDriver()
			: new TransientDriver();
	}

	/**
	 * Lee un valor recorriendo la jerarquia.
	 *
	 * @param string    $key   Clave.
	 * @param string    $group Grupo.
	 * @param bool|null $found Recibe si la clave existia en algun nivel.
	 * @return mixed
	 */
	public function get( string $key, string $group, ?bool &$found = null ): mixed {
		$value = $this->l1->get( $key, $group, $found );

		if ( $found ) {
			return $value;
		}

		$value = $this->l2->get( $key, $group, $found );

		if ( $found ) {
			// Promocion a L1: evita repetir la lectura de L2 en esta peticion.
			$this->l1->set( $key, $value, $group );
		}

		return $found ? $value : null;
	}

	/**
	 * Escribe en los dos niveles.
	 *
	 * @param string   $key   Clave.
	 * @param mixed    $value Valor.
	 * @param string   $group Grupo.
	 * @param int|null $ttl   TTL explicito, o null para usar el del grupo.
	 * @return bool
	 */
	public function set( string $key, mixed $value, string $group, ?int $ttl = null ): bool {
		$this->l1->set( $key, $value, $group );

		return $this->l2->set( $key, $value, $group, $ttl ?? $this->ttl_for( $group ) );
	}

	/**
	 * Borra en los dos niveles.
	 *
	 * @param string $key   Clave.
	 * @param string $group Grupo.
	 * @return bool
	 */
	public function delete( string $key, string $group ): bool {
		$this->l1->delete( $key, $group );

		return $this->l2->delete( $key, $group );
	}

	/**
	 * Devuelve el valor cacheado o lo calcula y lo guarda.
	 *
	 * @param string   $key      Clave.
	 * @param string   $group    Grupo.
	 * @param callable $producer Callback que produce el valor si falta.
	 * @param int|null $ttl      TTL explicito, o null para el del grupo.
	 * @return mixed
	 */
	public function remember( string $key, string $group, callable $producer, ?int $ttl = null ): mixed {
		$found = false;
		$value = $this->get( $key, $group, $found );

		if ( $found ) {
			return $value;
		}

		$value = $producer();

		$this->set( $key, $value, $group, $ttl );

		return $value;
	}

	/**
	 * Vacia el nivel en memoria.
	 *
	 * L2 no se vacia: la invalidacion se hace versionando la clave, porque
	 * wp_cache_flush_group() no esta soportado por todos los backends.
	 *
	 * @param string|null $group Grupo, o null para todos.
	 * @return void
	 */
	public function flush_memory( ?string $group = null ): void {
		$this->l1->flush( $group );
	}

	/**
	 * TTL configurado para un grupo.
	 *
	 * @param string $group Grupo.
	 * @return int Segundos.
	 */
	public function ttl_for( string $group ): int {
		$table = $this->l2 instanceof ObjectCacheDriver
			? self::TTL_PERSISTENT
			: self::TTL_TRANSIENT;

		return $table[ $group ] ?? MINUTE_IN_SECONDS;
	}

	/**
	 * Nombre del driver persistente activo, para el panel de estado.
	 *
	 * @return string
	 */
	public function driver_name(): string {
		return $this->l2->name();
	}

	/**
	 * Indica si el almacen persistente es un object cache externo.
	 *
	 * @return bool
	 */
	public function has_persistent_object_cache(): bool {
		return $this->l2 instanceof ObjectCacheDriver;
	}
}

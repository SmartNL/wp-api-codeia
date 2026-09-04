<?php
/**
 * Sistema de logging del plugin.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registro de eventos en tabla propia.
 *
 * Niveles y firma compatibles con PSR-3, sin heredar de su interfaz para no
 * arrastrar el paquete como dependencia de runtime.
 *
 * Se usa una tabla y no una opcion: wp_options con autoload cargaria los logs
 * en cada peticion, y sin autoload sigue siendo un blob serializado que hay
 * que leer y reescribir entero para anadir una linea.
 */
final class Logger {

	public const ERROR   = 'error';
	public const WARNING = 'warning';
	public const INFO    = 'info';
	public const DEBUG   = 'debug';

	/**
	 * Severidad de cada nivel. A mayor numero, mas grave.
	 */
	private const SEVERITY = array(
		self::DEBUG   => 10,
		self::INFO    => 20,
		self::WARNING => 30,
		self::ERROR   => 40,
	);

	/**
	 * Claves de contexto cuyo valor nunca se escribe en claro.
	 */
	private const SENSITIVE_KEYS = array(
		'password',
		'pass',
		'token',
		'access_token',
		'refresh_token',
		'api_key',
		'key',
		'secret',
		'authorization',
		'nonce',
		'hash',
	);

	/**
	 * Nivel minimo que se registra.
	 *
	 * @var string
	 */
	private string $min_level;

	/**
	 * Construye el logger.
	 *
	 * @param string $min_level Nivel minimo a registrar.
	 */
	public function __construct( string $min_level = self::INFO ) {
		$this->min_level = isset( self::SEVERITY[ $min_level ] ) ? $min_level : self::INFO;
	}

	/**
	 * Nombre completo de la tabla de logs.
	 *
	 * Usa el prefijo del sitio, no el de la red: en multisitio cada sitio
	 * tiene su propia tabla.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'codeia_logs';
	}

	/**
	 * Registra un error.
	 *
	 * @param string               $message Mensaje.
	 * @param array<string, mixed> $context Contexto.
	 * @param string               $channel Canal.
	 * @return void
	 */
	public function error( string $message, array $context = array(), string $channel = 'core' ): void {
		$this->log( self::ERROR, $message, $context, $channel );
	}

	/**
	 * Registra una degradacion con continuidad.
	 *
	 * @param string               $message Mensaje.
	 * @param array<string, mixed> $context Contexto.
	 * @param string               $channel Canal.
	 * @return void
	 */
	public function warning( string $message, array $context = array(), string $channel = 'core' ): void {
		$this->log( self::WARNING, $message, $context, $channel );
	}

	/**
	 * Registra un hecho relevante para auditoria.
	 *
	 * @param string               $message Mensaje.
	 * @param array<string, mixed> $context Contexto.
	 * @param string               $channel Canal.
	 * @return void
	 */
	public function info( string $message, array $context = array(), string $channel = 'core' ): void {
		$this->log( self::INFO, $message, $context, $channel );
	}

	/**
	 * Registra detalle de diagnostico.
	 *
	 * @param string               $message Mensaje.
	 * @param array<string, mixed> $context Contexto.
	 * @param string               $channel Canal.
	 * @return void
	 */
	public function debug( string $message, array $context = array(), string $channel = 'core' ): void {
		$this->log( self::DEBUG, $message, $context, $channel );
	}

	/**
	 * Escribe una entrada si el nivel lo permite.
	 *
	 * @param string               $level   Nivel.
	 * @param string               $message Mensaje.
	 * @param array<string, mixed> $context Contexto.
	 * @param string               $channel Canal.
	 * @return bool Si se escribio la entrada.
	 */
	public function log( string $level, string $message, array $context = array(), string $channel = 'core' ): bool {
		if ( ! $this->should_log( $level ) ) {
			return false;
		}

		global $wpdb;

		$written = $wpdb->insert(
			self::table_name(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'level'      => $level,
				'channel'    => $channel,
				'message'    => $message,
				'context'    => (string) wp_json_encode( self::redact( $context ) ),
				'user_id'    => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		return false !== $written;
	}

	/**
	 * Indica si un nivel supera el minimo configurado.
	 *
	 * @param string $level Nivel.
	 * @return bool
	 */
	public function should_log( string $level ): bool {
		if ( ! isset( self::SEVERITY[ $level ] ) ) {
			return false;
		}

		return self::SEVERITY[ $level ] >= self::SEVERITY[ $this->min_level ];
	}

	/**
	 * Sustituye los valores sensibles del contexto.
	 *
	 * Las credenciales no se registran nunca, ni siquiera en nivel debug. Un
	 * volcado de logs acaba en un ticket de soporte o en un correo.
	 *
	 * @param array<string, mixed> $context Contexto original.
	 * @return array<string, mixed> Contexto con los valores sensibles ocultos.
	 */
	public static function redact( array $context ): array {
		$clean = array();

		foreach ( $context as $key => $value ) {
			if ( is_array( $value ) ) {
				$clean[ $key ] = self::redact( $value );
				continue;
			}

			$clean[ $key ] = self::is_sensitive( (string) $key )
				? '[oculto]'
				: $value;
		}

		return $clean;
	}

	/**
	 * Indica si una clave de contexto es sensible.
	 *
	 * @param string $key Clave.
	 * @return bool
	 */
	private static function is_sensitive( string $key ): bool {
		$needle = strtolower( $key );

		foreach ( self::SENSITIVE_KEYS as $sensitive ) {
			if ( str_contains( $needle, $sensitive ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Borra las entradas mas antiguas que el numero de dias indicado.
	 *
	 * Sin purga, la tabla de logs es la forma mas comun de que un plugin como
	 * este degrade una instalacion a lo largo de meses.
	 *
	 * @param int $days Dias de retencion.
	 * @return int Filas borradas.
	 */
	public static function purge_older_than( int $days ): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table  = self::table_name();

		// El nombre de tabla no admite marcador de posicion; se compone desde
		// $wpdb->prefix, nunca desde entrada del usuario.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}
}

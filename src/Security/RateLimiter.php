<?php
/**
 * Limitacion de peticiones.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Security;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WpApi\Codeia\Core\Cache\CacheManager;

/**
 * Aplica limites por identidad y por IP.
 *
 * Se ejecuta DESPUES de autenticar, no antes, para que el contador se asocie
 * a la identidad real y no solo a la IP, que es facil de rotar. El coste es
 * que una credencial invalida consume ciclo de autenticacion; se compensa con
 * un limite mas agresivo para las peticiones anonimas.
 */
final class RateLimiter {

	/**
	 * Limites por defecto: peticiones por ventana de un minuto.
	 */
	public const ANON_LIMIT = 60;
	public const AUTH_LIMIT = 300;
	public const WINDOW     = MINUTE_IN_SECONDS;

	/**
	 * Limite del endpoint de emision de credenciales.
	 */
	public const AUTH_ISSUE_LIMIT  = 5;
	public const AUTH_ISSUE_WINDOW = 15 * MINUTE_IN_SECONDS;

	/**
	 * Ventana deslizante.
	 *
	 * @var SlidingWindow
	 */
	private SlidingWindow $window;

	/**
	 * Resolutor de IP.
	 *
	 * @var IpResolver
	 */
	private IpResolver $ips;

	/**
	 * Gestor de cache, para saber si degrada.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * Construye el limitador.
	 *
	 * @param SlidingWindow $window Ventana deslizante.
	 * @param IpResolver    $ips    Resolutor de IP.
	 * @param CacheManager  $cache  Gestor de cache.
	 */
	public function __construct( SlidingWindow $window, IpResolver $ips, CacheManager $cache ) {
		$this->window = $window;
		$this->ips    = $ips;
		$this->cache  = $cache;
	}

	/**
	 * Aplica el limite general de la API.
	 *
	 * @param int                  $user_id ID de usuario, 0 si anonimo.
	 * @param array<string, mixed> $server  Copia de $_SERVER.
	 * @return array<string, mixed>
	 */
	public function check( int $user_id, array $server ): array {
		if ( $user_id > 0 ) {
			return $this->window->hit( 'user:' . $user_id, self::AUTH_LIMIT, self::WINDOW );
		}

		return $this->window->hit( $this->ip_key( $server ), self::ANON_LIMIT, self::WINDOW );
	}

	/**
	 * Aplica el limite del endpoint de emision.
	 *
	 * Se limita por IP Y por usuario a la vez: solo por IP, un ataque
	 * distribuido contra una cuenta pasa; solo por usuario, se pueden sondear
	 * muchas cuentas desde una IP.
	 *
	 * @param string               $username Nombre de usuario intentado.
	 * @param array<string, mixed> $server   Copia de $_SERVER.
	 * @return array<string, mixed>
	 */
	public function check_issue( string $username, array $server ): array {
		$by_ip = $this->window->hit(
			'issue_ip:' . $this->ip_key( $server ),
			self::AUTH_ISSUE_LIMIT,
			self::AUTH_ISSUE_WINDOW
		);

		if ( ! $by_ip['allowed'] ) {
			return $by_ip;
		}

		return $this->window->hit(
			'issue_user:' . md5( strtolower( $username ) ),
			self::AUTH_ISSUE_LIMIT,
			self::AUTH_ISSUE_WINDOW
		);
	}

	/**
	 * Compone el error 429 con su cabecera Retry-After.
	 *
	 * @param array<string, mixed> $state Estado del limite.
	 * @return WP_Error
	 */
	public function too_many_requests( array $state ): WP_Error {
		return new WP_Error(
			'codeia_rate_limited',
			__( 'Has superado el limite de peticiones. Intentalo mas tarde.', 'wp-api-codeia' ),
			array(
				'status'      => 429,
				'retry_after' => $state['retry_after'] ?? 60,
			)
		);
	}

	/**
	 * Cabeceras estandar del limite.
	 *
	 * @param array<string, mixed> $state Estado.
	 * @return array<string, string>
	 */
	public function headers( array $state ): array {
		$headers = array(
			'X-RateLimit-Limit'     => (string) ( $state['limit'] ?? 0 ),
			'X-RateLimit-Remaining' => (string) ( $state['remaining'] ?? 0 ),
			'X-RateLimit-Reset'     => (string) ( $state['reset'] ?? 0 ),
		);

		if ( empty( $state['allowed'] ) ) {
			// Retry-After es lo que permite a un cliente correcto esperar en
			// lugar de reintentar en bucle.
			$headers['Retry-After'] = (string) ( $state['retry_after'] ?? 60 );
		}

		return $headers;
	}

	/**
	 * Indica si el limitador esta degradado.
	 *
	 * Sin object cache persistente los contadores caen a transients y, con
	 * varios servidores web, se cuentan por nodo: el limite efectivo se
	 * multiplica. NO se desactiva por ello — un limite impreciso protege mas
	 * que ninguno — pero el panel de estado debe avisarlo.
	 *
	 * @return bool
	 */
	public function is_degraded(): bool {
		return ! $this->cache->has_persistent_object_cache();
	}

	/**
	 * Clave de cache de la IP.
	 *
	 * @param array<string, mixed> $server Copia de $_SERVER.
	 * @return string
	 */
	private function ip_key( array $server ): string {
		$ip = $this->ips->resolve( $server );

		return 'ip:' . IpResolver::key( $ip, wp_salt( 'nonce' ) );
	}
}

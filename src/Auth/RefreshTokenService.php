<?php
/**
 * Rotacion de tokens de refresco.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Auth;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Logger;

/**
 * Emite, rota y revoca tokens de refresco.
 *
 * Cada refresco invalida el token usado y emite uno nuevo. Los tokens se
 * agrupan en FAMILIAS: cada rotacion conserva el identificador de familia del
 * anterior.
 *
 * Que un refresh ya consumido vuelva a presentarse solo tiene dos
 * explicaciones: una condicion de carrera del cliente, o que un atacante lo
 * haya robado. Como no se pueden distinguir, se asume lo peor y se revoca la
 * familia completa. Sin rotacion, un refresh robado da acceso indefinido y
 * silencioso; con ella, el robo se manifiesta en cuanto legitimo y atacante
 * usan el token.
 */
final class RefreshTokenService {

	/**
	 * Vigencia por defecto de un token de refresco.
	 */
	public const TTL = 14 * DAY_IN_SECONDS;

	/**
	 * Clave de user meta donde viven los tokens de un usuario.
	 */
	public const META_KEY = '_codeia_refresh_tokens';

	/**
	 * Registro de eventos.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Construye el servicio.
	 *
	 * @param Logger $logger Registro de eventos.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Emite un token de refresco nuevo.
	 *
	 * @param int         $user_id ID de usuario.
	 * @param string|null $family  Familia a la que pertenece, o null para una nueva.
	 * @return string Token en claro. Solo se devuelve aqui.
	 */
	public function issue( int $user_id, ?string $family = null ): string {
		$token  = OpaqueToken::generate();
		$family = $family ?? OpaqueToken::generate( 'fam_' );

		$tokens = $this->tokens_for( $user_id );

		$tokens[ OpaqueToken::hash( $token ) ] = array(
			'family'    => $family,
			'issued_at' => time(),
			'expires'   => time() + self::TTL,
			'used'      => false,
		);

		$this->save( $user_id, $this->prune( $tokens ) );

		return $token;
	}

	/**
	 * Consume un token de refresco y emite el siguiente de la familia.
	 *
	 * @param int    $user_id ID de usuario.
	 * @param string $token   Token presentado.
	 * @return string|null Token nuevo, o null si el presentado no es valido.
	 */
	public function rotate( int $user_id, string $token ): ?string {
		$tokens = $this->tokens_for( $user_id );
		$hash   = OpaqueToken::hash( $token );

		if ( ! isset( $tokens[ $hash ] ) ) {
			return null;
		}

		$entry = $tokens[ $hash ];

		if ( time() > (int) $entry['expires'] ) {
			unset( $tokens[ $hash ] );
			$this->save( $user_id, $tokens );

			return null;
		}

		if ( ! empty( $entry['used'] ) ) {
			$this->handle_reuse( $user_id, (string) $entry['family'] );

			return null;
		}

		$tokens[ $hash ]['used'] = true;
		$this->save( $user_id, $tokens );

		return $this->issue( $user_id, (string) $entry['family'] );
	}

	/**
	 * Revoca la familia completa tras detectar una reutilizacion.
	 *
	 * @param int    $user_id ID de usuario.
	 * @param string $family  Familia comprometida.
	 * @return void
	 */
	private function handle_reuse( int $user_id, string $family ): void {
		$this->revoke_family( $user_id, $family );

		$this->logger->warning(
			'Reutilizacion de token de refresco detectada; familia revocada.',
			array(
				'user_id' => $user_id,
				'family'  => $family,
			),
			'auth'
		);
	}

	/**
	 * Revoca todos los tokens de una familia.
	 *
	 * @param int    $user_id ID de usuario.
	 * @param string $family  Familia.
	 * @return int Numero de tokens revocados.
	 */
	public function revoke_family( int $user_id, string $family ): int {
		$tokens = $this->tokens_for( $user_id );
		$before = count( $tokens );

		$tokens = array_filter(
			$tokens,
			static function ( array $entry ) use ( $family ): bool {
				return ( $entry['family'] ?? '' ) !== $family;
			}
		);

		$this->save( $user_id, $tokens );

		return $before - count( $tokens );
	}

	/**
	 * Revoca todos los tokens de refresco de un usuario.
	 *
	 * @param int $user_id ID de usuario.
	 * @return void
	 */
	public function revoke_all( int $user_id ): void {
		delete_user_meta( $user_id, self::META_KEY );
	}

	/**
	 * Devuelve los tokens almacenados de un usuario.
	 *
	 * @param int $user_id ID de usuario.
	 * @return array<string, array<string, mixed>>
	 */
	public function tokens_for( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META_KEY, true );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Elimina los tokens caducados y los usados hace tiempo.
	 *
	 * Un token usado se conserva un tiempo prudencial: es lo que permite
	 * detectar la reutilizacion. Borrarlo al consumirlo haria que un robo
	 * pareciese un token desconocido y se perderia la senal.
	 *
	 * @param array<string, array<string, mixed>> $tokens Tokens.
	 * @return array<string, array<string, mixed>>
	 */
	private function prune( array $tokens ): array {
		$now = time();

		return array_filter(
			$tokens,
			static function ( array $entry ) use ( $now ): bool {
				return $now <= (int) ( $entry['expires'] ?? 0 );
			}
		);
	}

	/**
	 * Persiste los tokens de un usuario.
	 *
	 * @param int                                 $user_id ID de usuario.
	 * @param array<string, array<string, mixed>> $tokens  Tokens.
	 * @return void
	 */
	private function save( int $user_id, array $tokens ): void {
		if ( array() === $tokens ) {
			delete_user_meta( $user_id, self::META_KEY );
			return;
		}

		update_user_meta( $user_id, self::META_KEY, $tokens );
	}
}

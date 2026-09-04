<?php
/**
 * Almacen de credenciales opacas.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Guarda API Keys y tokens de usuario, siempre hasheados.
 *
 * La credencial en claro se devuelve UNA sola vez, al crearla. A partir de
 * ahi solo existe su hash: si el almacen se filtra, no hay nada reutilizable.
 */
final class TokenRepository {

	public const TYPE_API_KEY = 'api_key';
	public const TYPE_USER    = 'user_token';

	/**
	 * Clave de user meta donde viven las credenciales.
	 */
	public const META_KEY = '_codeia_credentials';

	/**
	 * Prefijo de las API Keys, para que se reconozcan a simple vista.
	 */
	private const API_KEY_PREFIX = 'ck_';

	/**
	 * Emite una credencial nueva.
	 *
	 * @param int    $user_id ID de usuario.
	 * @param string $type    Tipo de credencial.
	 * @param string $label   Etiqueta para identificarla en el dashboard.
	 * @param int    $expires Marca de tiempo de expiracion, 0 para nunca.
	 * @return string Credencial en claro. No vuelve a estar disponible.
	 */
	public function issue( int $user_id, string $type, string $label = '', int $expires = 0 ): string {
		$prefix = self::TYPE_API_KEY === $type ? self::API_KEY_PREFIX : '';
		$token  = OpaqueToken::generate( $prefix );

		$stored = $this->all_for( $user_id );

		$stored[ OpaqueToken::hash( $token ) ] = array(
			'type'      => $type,
			'label'     => $label,
			'created'   => time(),
			'expires'   => $expires,
			'last_used' => 0,
		);

		update_user_meta( $user_id, self::META_KEY, $stored );

		return $token;
	}

	/**
	 * Resuelve una credencial a su usuario.
	 *
	 * La busqueda es por hash completo sobre un indice, no recorriendo
	 * usuarios: con muchos usuarios, un recorrido seria inviable.
	 *
	 * @param string $token Credencial en claro.
	 * @param string $type  Tipo esperado.
	 * @return int ID de usuario, o 0 si no resuelve.
	 */
	public function resolve( string $token, string $type ): int {
		if ( ! OpaqueToken::is_well_formed( $token ) ) {
			return 0;
		}

		$hash    = OpaqueToken::hash( $token );
		$user_id = $this->lookup( $hash );

		if ( $user_id <= 0 ) {
			return 0;
		}

		$stored = $this->all_for( $user_id );

		if ( ! isset( $stored[ $hash ] ) ) {
			return 0;
		}

		$entry = $stored[ $hash ];

		if ( ( $entry['type'] ?? '' ) !== $type ) {
			return 0;
		}

		$expires = (int) ( $entry['expires'] ?? 0 );

		if ( $expires > 0 && time() > $expires ) {
			return 0;
		}

		$stored[ $hash ]['last_used'] = time();
		update_user_meta( $user_id, self::META_KEY, $stored );

		return $user_id;
	}

	/**
	 * Busca a que usuario pertenece un hash.
	 *
	 * Consulta directa sobre usermeta con el hash completo: la columna
	 * meta_value guarda un array serializado, asi que se busca por
	 * coincidencia del hash dentro de el. Es una consulta por indice de
	 * meta_key acotada, no un recorrido de usuarios.
	 *
	 * @param string $hash Hash de la credencial.
	 * @return int ID de usuario, o 0.
	 */
	private function lookup( string $hash ): int {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Busqueda por credencial: no hay API de WordPress para esto y cachearla filtraria la credencial.
		$user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta}
                 WHERE meta_key = %s AND meta_value LIKE %s
                 LIMIT 1",
				self::META_KEY,
				'%' . $wpdb->esc_like( $hash ) . '%'
			)
		);

		return null === $user_id ? 0 : (int) $user_id;
	}

	/**
	 * Devuelve las credenciales de un usuario.
	 *
	 * @param int $user_id ID de usuario.
	 * @return array<string, array<string, mixed>>
	 */
	public function all_for( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META_KEY, true );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Revoca una credencial concreta.
	 *
	 * @param int    $user_id ID de usuario.
	 * @param string $hash    Hash de la credencial.
	 * @return bool
	 */
	public function revoke( int $user_id, string $hash ): bool {
		$stored = $this->all_for( $user_id );

		if ( ! isset( $stored[ $hash ] ) ) {
			return false;
		}

		unset( $stored[ $hash ] );

		if ( array() === $stored ) {
			delete_user_meta( $user_id, self::META_KEY );

			return true;
		}

		return (bool) update_user_meta( $user_id, self::META_KEY, $stored );
	}

	/**
	 * Revoca todas las credenciales de un usuario.
	 *
	 * @param int $user_id ID de usuario.
	 * @return void
	 */
	public function revoke_all( int $user_id ): void {
		delete_user_meta( $user_id, self::META_KEY );
	}

	/**
	 * Metadatos publicos de las credenciales de un usuario.
	 *
	 * Nunca devuelve la credencial ni su hash completo: solo lo necesario
	 * para listarlas en el dashboard.
	 *
	 * @param int $user_id ID de usuario.
	 * @return array<int, array<string, mixed>>
	 */
	public function describe( int $user_id ): array {
		$list = array();

		foreach ( $this->all_for( $user_id ) as $hash => $entry ) {
			$list[] = array(
				'id'        => substr( (string) $hash, 0, 12 ),
				'type'      => $entry['type'] ?? '',
				'label'     => $entry['label'] ?? '',
				'created'   => (int) ( $entry['created'] ?? 0 ),
				'expires'   => (int) ( $entry['expires'] ?? 0 ),
				'last_used' => (int) ( $entry['last_used'] ?? 0 ),
			);
		}

		return $list;
	}
}

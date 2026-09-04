<?php
/**
 * Revocacion individual de tokens.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Auth;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Cache\CacheManager;

/**
 * Registra los identificadores de tokens revocados antes de tiempo.
 *
 * Solo almacena jti de tokens NO caducados: pasado su exp la entrada sobra,
 * porque el token ya no valdria de todos modos. Asi la lista queda acotada
 * por la vigencia del token —quince minutos— y no por el volumen historico.
 *
 * Esa es la razon practica de que el access token sea de vida corta: hace que
 * la revocacion individual sea barata.
 */
final class RevocationList {

	/**
	 * Grupo de cache donde viven las revocaciones.
	 */
	private const GROUP = 'codeia_revoked';

	/**
	 * Gestor de cache.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * Construye la lista.
	 *
	 * @param CacheManager $cache Gestor de cache.
	 */
	public function __construct( CacheManager $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Revoca un token concreto.
	 *
	 * @param string $jti      Identificador del token.
	 * @param int    $ttl      Segundos que faltan hasta su expiracion.
	 * @return bool
	 */
	public function revoke( string $jti, int $ttl ): bool {
		if ( '' === $jti || $ttl <= 0 ) {
			return false;
		}

		return $this->cache->set( $this->key( $jti ), 1, self::GROUP, $ttl );
	}

	/**
	 * Indica si un token esta revocado.
	 *
	 * @param string $jti Identificador del token.
	 * @return bool
	 */
	public function is_revoked( string $jti ): bool {
		if ( '' === $jti ) {
			return false;
		}

		$found = false;
		$this->cache->get( $this->key( $jti ), self::GROUP, $found );

		return $found;
	}

	/**
	 * Levanta la revocacion de un token.
	 *
	 * @param string $jti Identificador del token.
	 * @return bool
	 */
	public function restore( string $jti ): bool {
		return $this->cache->delete( $this->key( $jti ), self::GROUP );
	}

	/**
	 * Compone la clave de cache.
	 *
	 * Se hashea para no guardar el identificador en claro en un almacen que
	 * puede ser compartido.
	 *
	 * @param string $jti Identificador del token.
	 * @return string
	 */
	private function key( string $jti ): string {
		return hash( 'sha256', $jti );
	}
}

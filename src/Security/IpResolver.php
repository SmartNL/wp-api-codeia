<?php
/**
 * Resolucion de la IP del cliente.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Determina la IP de origen sin fiarse de cabeceras falsificables.
 *
 * REMOTE_ADDR es lo unico fiable. X-Forwarded-For la puede escribir
 * cualquiera, SALVO que el sitio este tras un proxy conocido: por eso solo
 * se lee cuando el administrador declara los rangos de confianza.
 *
 * Sin esa condicion, cualquiera esquivaria el limite de peticiones rotando
 * la cabecera.
 */
final class IpResolver {

	/**
	 * Rangos de proxy en los que se confia.
	 *
	 * @var string[]
	 */
	private array $trusted;

	/**
	 * Construye el resolutor.
	 *
	 * @param string[] $trusted Rangos CIDR o IPs de proxies de confianza.
	 */
	public function __construct( array $trusted = array() ) {
		$this->trusted = array_values( array_filter( array_map( 'strval', $trusted ) ) );
	}

	/**
	 * Resuelve la IP del cliente.
	 *
	 * @param array<string, mixed> $server Copia de $_SERVER.
	 * @return string
	 */
	public function resolve( array $server ): string {
		$remote = isset( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '';

		if ( '' === $remote ) {
			return '';
		}

		if ( array() === $this->trusted || ! $this->is_trusted( $remote ) ) {
			return $remote;
		}

		$forwarded = isset( $server['HTTP_X_FORWARDED_FOR'] ) ? (string) $server['HTTP_X_FORWARDED_FOR'] : '';

		if ( '' === $forwarded ) {
			return $remote;
		}

		// El primero de la lista es el cliente original.
		$first = trim( explode( ',', $forwarded )[0] );

		return filter_var( $first, FILTER_VALIDATE_IP ) ? $first : $remote;
	}

	/**
	 * Indica si una IP pertenece a un proxy declarado de confianza.
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	public function is_trusted( string $ip ): bool {
		foreach ( $this->trusted as $range ) {
			if ( $this->matches( $ip, $range ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compara una IP con una IP o rango CIDR.
	 *
	 * @param string $ip    IP a comprobar.
	 * @param string $range IP o CIDR.
	 * @return bool
	 */
	private function matches( string $ip, string $range ): bool {
		if ( ! str_contains( $range, '/' ) ) {
			return $ip === $range;
		}

		list( $subnet, $bits ) = explode( '/', $range, 2 );

		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );

		if ( false === $ip_long || false === $subnet_long ) {
			return false;
		}

		$mask = -1 << ( 32 - (int) $bits );

		return ( $ip_long & $mask ) === ( $subnet_long & $mask );
	}

	/**
	 * Clave de cache para una IP.
	 *
	 * La IP se guarda HASHEADA: es dato personal bajo el RGPD y no hay razon
	 * para conservarla en claro en un almacen compartido.
	 *
	 * @param string $ip   IP.
	 * @param string $salt Sal del sitio.
	 * @return string
	 */
	public static function key( string $ip, string $salt ): string {
		return substr( hash_hmac( 'sha256', $ip, $salt ), 0, 32 );
	}
}

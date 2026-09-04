<?php
/**
 * Cabeceras CORS para consumo headless.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Security;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Config;

/**
 * Sustituye el CORS permisivo del nucleo por uno configurable.
 *
 * WordPress envia Access-Control-Allow-Origin: * en REST. Es suficiente para
 * lecturas publicas, pero INCOMPATIBLE con credenciales: el navegador rechaza
 * Allow-Origin: * junto a Allow-Credentials: true.
 */
final class CorsHandler {

	public const MODE_PUBLIC = 'public';
	public const MODE_LIST   = 'list';
	public const MODE_SAME   = 'same_origin';

	/**
	 * Configuracion.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Construye el manejador.
	 *
	 * @param Config $config Configuracion.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Engancha el manejador.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_filter( 'rest_pre_serve_request', array( $this, 'send_headers' ), 10, 4 );
		add_filter( 'rest_allowed_cors_headers', array( $this, 'allowed_headers' ) );
	}

	/**
	 * Emite las cabeceras CORS.
	 *
	 * @param bool  $served  Si la peticion ya se sirvio.
	 * @param mixed $result  Resultado.
	 * @param mixed $request Peticion.
	 * @param mixed $server  Servidor REST.
	 * @return bool
	 */
	public function send_headers( $served, $result = null, $request = null, $server = null ) {
		$mode = (string) $this->config->get( 'cors.mode', self::MODE_PUBLIC );

		if ( self::MODE_PUBLIC === $mode ) {
			return $served;
		}

		$origin = get_http_origin();

		if ( ! is_string( $origin ) || '' === $origin ) {
			return $served;
		}

		if ( ! in_array( $origin, $this->allowed_origins( $mode ), true ) ) {
			return $served;
		}

		header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
		header( 'Access-Control-Allow-Credentials: true' );

		/*
		 * Vary: Origin NO es opcional. Sin el, cualquier cache intermedia
		 * —CDN, proxy, cache de pagina— puede servir a un origen la respuesta
		 * con la cabecera CORS de otro. Es una fuga entre origenes.
		 */
		header( 'Vary: Origin' );
		header( 'Access-Control-Max-Age: 86400' );

		return $served;
	}

	/**
	 * Origenes admitidos segun el modo.
	 *
	 * Se declaran completos, sin comodines de subdominio: un comodin concede
	 * acceso a cualquier subdominio, incluidos los que un atacante pudiera
	 * controlar por una toma de subdominio.
	 *
	 * @param string $mode Modo activo.
	 * @return string[]
	 */
	public function allowed_origins( string $mode ): array {
		if ( self::MODE_SAME === $mode ) {
			return array( untrailingslashit( home_url() ) );
		}

		$list = $this->config->get( 'cors.origins', array() );

		if ( ! is_array( $list ) ) {
			return array();
		}

		return array_values(
			array_filter(
				array_map( 'strval', $list ),
				static function ( string $origin ): bool {
					return '' !== $origin && ! str_contains( $origin, '*' );
				}
			)
		);
	}

	/**
	 * Declara las cabeceras propias en el preflight.
	 *
	 * Sin declarar X-Codeia-Key, el navegador bloquea la peticion antes de
	 * enviarla y el error aparece como fallo de red, sin nada en los logs del
	 * servidor. Es de los problemas mas dificiles de diagnosticar en
	 * integraciones headless.
	 *
	 * @param string[] $headers Cabeceras permitidas.
	 * @return string[]
	 */
	public function allowed_headers( array $headers ): array {
		return array_values(
			array_unique(
				array_merge(
					$headers,
					array( 'X-Codeia-Key', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-Codeia-Cursor' )
				)
			)
		);
	}
}

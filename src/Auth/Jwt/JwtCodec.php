<?php
/**
 * Codificacion y verificacion de JWT.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Auth\Jwt;

defined( 'ABSPATH' ) || exit;

/**
 * Emite y valida tokens JWS compactos con firma simetrica.
 *
 * Se usa HS256 porque emisor y verificador son el mismo sitio. RS256 solo
 * aportaria valor si un tercero tuviera que verificar sin poder emitir, lo
 * que hoy no ocurre.
 *
 * El orden de validacion es deliberado: primero lo barato (formato,
 * algoritmo), luego la firma, y solo despues los claims. Comprobar la firma
 * ANTES que los claims impide que un atacante use el mensaje de error de un
 * claim para sondear tokens que ni siquiera estan firmados.
 */
final class JwtCodec {

	/**
	 * Unico algoritmo admitido.
	 */
	public const ALGORITHM = 'HS256';

	/**
	 * Secreto de firma.
	 *
	 * @var string
	 */
	private string $secret;

	/**
	 * Emisor esperado.
	 *
	 * @var string
	 */
	private string $issuer;

	/**
	 * Construye el codec.
	 *
	 * @param string $secret Secreto de firma.
	 * @param string $issuer Emisor.
	 */
	public function __construct( string $secret, string $issuer ) {
		$this->secret = $secret;
		$this->issuer = $issuer;
	}

	/**
	 * Emite un token firmado.
	 *
	 * @param array<string, mixed> $claims Claims del payload.
	 * @return string
	 */
	public function encode( array $claims ): string {
		$header = array(
			'typ' => 'JWT',
			'alg' => self::ALGORITHM,
		);

		$segments = array(
			$this->base64url_encode( (string) wp_json_encode( $header ) ),
			$this->base64url_encode( (string) wp_json_encode( $claims ) ),
		);

		$signing_input = implode( '.', $segments );
		$segments[]    = $this->base64url_encode( $this->sign( $signing_input ) );

		return implode( '.', $segments );
	}

	/**
	 * Verifica un token y devuelve sus claims.
	 *
	 * @param string $token Token compacto.
	 * @return array<string, mixed>|null Claims, o null si el token no es valido.
	 */
	public function decode( string $token ): ?array {
		$segments = explode( '.', $token );

		if ( 3 !== count( $segments ) ) {
			return null;
		}

		list( $header_b64, $payload_b64, $signature_b64 ) = $segments;

		$header = $this->decode_json( $header_b64 );

		if ( null === $header ) {
			return null;
		}

		/*
		 * El algoritmo se compara contra el esperado, NUNCA se lee del token
		 * para decidir como verificar. Asi se cierran de golpe el ataque
		 * "alg: none" y la confusion HS/RS.
		 */
		if ( ! isset( $header['alg'] ) || self::ALGORITHM !== $header['alg'] ) {
			return null;
		}

		$expected = $this->sign( $header_b64 . '.' . $payload_b64 );
		$actual   = $this->base64url_decode( $signature_b64 );

		if ( ! hash_equals( $expected, $actual ) ) {
			return null;
		}

		return $this->decode_json( $payload_b64 );
	}

	/**
	 * Comprueba los claims temporales y el emisor.
	 *
	 * Se separa de decode() para poder distinguir en el llamador un token mal
	 * firmado de uno caducado, aunque hacia el cliente ambos den el mismo
	 * mensaje.
	 *
	 * @param array<string, mixed> $claims Claims ya verificados.
	 * @param int                  $now    Marca de tiempo actual.
	 * @param int                  $leeway Margen en segundos para desfase de reloj.
	 * @return bool
	 */
	public function claims_are_current( array $claims, int $now, int $leeway = 60 ): bool {
		if ( isset( $claims['iss'] ) && $claims['iss'] !== $this->issuer ) {
			return false;
		}

		if ( isset( $claims['exp'] ) && $now > ( (int) $claims['exp'] + $leeway ) ) {
			return false;
		}

		if ( isset( $claims['iat'] ) && ( (int) $claims['iat'] - $leeway ) > $now ) {
			return false;
		}

		return true;
	}

	/**
	 * Firma una cadena.
	 *
	 * @param string $input Entrada.
	 * @return string Firma binaria.
	 */
	private function sign( string $input ): string {
		return hash_hmac( 'sha256', $input, $this->secret, true );
	}

	/**
	 * Decodifica un segmento JSON en base64url.
	 *
	 * @param string $segment Segmento.
	 * @return array<string, mixed>|null
	 */
	private function decode_json( string $segment ): ?array {
		$decoded = json_decode( $this->base64url_decode( $segment ), true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Codifica en base64url sin relleno.
	 *
	 * @param string $data Datos.
	 * @return string
	 */
	private function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Decodifica base64url.
	 *
	 * @param string $data Datos.
	 * @return string
	 */
	private function base64url_decode( string $data ): string {
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true );

		return false === $decoded ? '' : $decoded;
	}
}

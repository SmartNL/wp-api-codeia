<?php
/**
 * Tests del codec JWT.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit\Auth;

use WpApi\Codeia\Auth\Jwt\JwtCodec;
use WpApi\Codeia\Tests\Unit\TestCase;

/**
 * @covers \WpApi\Codeia\Auth\Jwt\JwtCodec
 */
final class JwtCodecTest extends TestCase {

	private const SECRETO = 'secreto-de-pruebas-de-256-bits-para-hs256';
	private const EMISOR  = 'https://session21.local';

	private JwtCodec $codec;

	protected function setUp(): void {
		parent::setUp();
		$this->codec = new JwtCodec( self::SECRETO, self::EMISOR );
	}

	private function claims( array $extra = array() ): array {
		return array_merge(
			array(
				'iss' => self::EMISOR,
				'sub' => 42,
				'iat' => 1000,
				'exp' => 1900,
				'jti' => 'abc123',
				'tv'  => 1,
			),
			$extra
		);
	}

	public function test_un_token_emitido_se_verifica(): void {
		$token  = $this->codec->encode( $this->claims() );
		$claims = $this->codec->decode( $token );

		$this->assertIsArray( $claims );
		$this->assertSame( 42, $claims['sub'] );
		$this->assertSame( 'abc123', $claims['jti'] );
	}

	public function test_el_token_tiene_tres_segmentos(): void {
		$token = $this->codec->encode( $this->claims() );

		$this->assertCount( 3, explode( '.', $token ) );
	}

	public function test_no_lleva_relleno_base64(): void {
		$token = $this->codec->encode( $this->claims() );

		$this->assertStringNotContainsString( '=', $token );
	}

	/**
	 * Ataque "alg: none": el atacante quita la firma y declara que no hay
	 * algoritmo. Se cierra comparando alg contra el valor esperado en vez de
	 * leerlo del token para decidir como verificar.
	 */
	public function test_rechaza_el_ataque_alg_none(): void {
		$header  = $this->b64(
			(string) json_encode(
				array(
					'typ' => 'JWT',
					'alg' => 'none',
				)
			)
		);
		$payload = $this->b64( (string) json_encode( $this->claims() ) );

		$this->assertNull( $this->codec->decode( $header . '.' . $payload . '.' ) );
	}

	/**
	 * Confusion HS/RS: el atacante declara RS256 esperando que el verificador
	 * use la clave publica como secreto HMAC.
	 */
	public function test_rechaza_la_confusion_de_algoritmo(): void {
		$header  = $this->b64(
			(string) json_encode(
				array(
					'typ' => 'JWT',
					'alg' => 'RS256',
				)
			)
		);
		$payload = $this->b64( (string) json_encode( $this->claims() ) );
		$firma   = $this->b64( 'lo-que-sea' );

		$this->assertNull( $this->codec->decode( "{$header}.{$payload}.{$firma}" ) );
	}

	public function test_rechaza_una_firma_manipulada(): void {
		$token     = $this->codec->encode( $this->claims() );
		$segmentos = explode( '.', $token );
		$alterado  = $segmentos[0] . '.' . $segmentos[1] . '.' . $this->b64( 'firma-falsa' );

		$this->assertNull( $this->codec->decode( $alterado ) );
	}

	public function test_rechaza_un_payload_manipulado(): void {
		$token     = $this->codec->encode( $this->claims() );
		$segmentos = explode( '.', $token );
		$otro      = $this->b64( (string) json_encode( $this->claims( array( 'sub' => 1 ) ) ) );

		$this->assertNull( $this->codec->decode( $segmentos[0] . '.' . $otro . '.' . $segmentos[2] ) );
	}

	public function test_rechaza_un_token_firmado_con_otro_secreto(): void {
		$otro  = new JwtCodec( 'un-secreto-completamente-distinto', self::EMISOR );
		$token = $otro->encode( $this->claims() );

		$this->assertNull( $this->codec->decode( $token ) );
	}

	/**
	 * @dataProvider tokens_mal_formados
	 */
	public function test_rechaza_tokens_mal_formados( string $token ): void {
		$this->assertNull( $this->codec->decode( $token ) );
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function tokens_mal_formados(): array {
		return array(
			'vacio'         => array( '' ),
			'un_segmento'   => array( 'abc' ),
			'dos_segmentos' => array( 'abc.def' ),
			'cuatro'        => array( 'a.b.c.d' ),
			'basura'        => array( '...' ),
			'no_base64'     => array( '!!!.???.***' ),
		);
	}

	public function test_acepta_un_token_vigente(): void {
		$this->assertTrue( $this->codec->claims_are_current( $this->claims(), 1500 ) );
	}

	public function test_rechaza_un_token_caducado(): void {
		$this->assertFalse( $this->codec->claims_are_current( $this->claims(), 2500 ) );
	}

	public function test_tolera_un_desfase_de_reloj_pequeno(): void {
		$this->assertTrue(
			$this->codec->claims_are_current( $this->claims(), 1930 ),
			'exp 1900 con 60s de margen debe seguir aceptandose a los 1930.'
		);
	}

	public function test_rechaza_un_token_emitido_en_el_futuro(): void {
		$this->assertFalse(
			$this->codec->claims_are_current( $this->claims( array( 'iat' => 5000 ) ), 1500 )
		);
	}

	public function test_rechaza_un_emisor_distinto(): void {
		$this->assertFalse(
			$this->codec->claims_are_current( $this->claims( array( 'iss' => 'https://otro.test' ) ), 1500 ),
			'Un token de otra instalacion no debe aceptarse.'
		);
	}

	private function b64( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}

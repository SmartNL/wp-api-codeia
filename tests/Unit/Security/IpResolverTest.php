<?php
/**
 * Tests de la resolucion de IP.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit\Security;

use WpApi\Codeia\Security\IpResolver;
use WpApi\Codeia\Tests\Unit\TestCase;

/**
 * @covers \WpApi\Codeia\Security\IpResolver
 */
final class IpResolverTest extends TestCase {

	public function test_sin_proxies_declarados_usa_remote_addr(): void {
		$resolver = new IpResolver();

		$ip = $resolver->resolve(
			array(
				'REMOTE_ADDR'          => '203.0.113.9',
				'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
			)
		);

		$this->assertSame(
			'203.0.113.9',
			$ip,
			'X-Forwarded-For la escribe cualquiera: sin proxy declarado se ignora.'
		);
	}

	public function test_con_proxy_de_confianza_lee_la_cabecera(): void {
		$resolver = new IpResolver( array( '10.0.0.1' ) );

		$ip = $resolver->resolve(
			array(
				'REMOTE_ADDR'          => '10.0.0.1',
				'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
			)
		);

		$this->assertSame( '203.0.113.9', $ip );
	}

	public function test_una_ip_no_declarada_no_es_de_confianza(): void {
		$resolver = new IpResolver( array( '10.0.0.1' ) );

		$ip = $resolver->resolve(
			array(
				'REMOTE_ADDR'          => '198.51.100.7',
				'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
			)
		);

		$this->assertSame( '198.51.100.7', $ip );
	}

	public function test_acepta_rangos_cidr(): void {
		$resolver = new IpResolver( array( '10.0.0.0/8' ) );

		$this->assertTrue( $resolver->is_trusted( '10.5.4.3' ) );
		$this->assertFalse( $resolver->is_trusted( '11.5.4.3' ) );
	}

	public function test_toma_el_primero_de_la_cadena_forwarded(): void {
		$resolver = new IpResolver( array( '10.0.0.1' ) );

		$ip = $resolver->resolve(
			array(
				'REMOTE_ADDR'          => '10.0.0.1',
				'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 10.0.0.5, 10.0.0.1',
			)
		);

		$this->assertSame( '203.0.113.9', $ip, 'El primero es el cliente original.' );
	}

	public function test_una_cabecera_con_basura_cae_a_remote_addr(): void {
		$resolver = new IpResolver( array( '10.0.0.1' ) );

		$ip = $resolver->resolve(
			array(
				'REMOTE_ADDR'          => '10.0.0.1',
				'HTTP_X_FORWARDED_FOR' => 'no-es-una-ip',
			)
		);

		$this->assertSame( '10.0.0.1', $ip );
	}

	public function test_sin_remote_addr_devuelve_cadena_vacia(): void {
		$this->assertSame( '', ( new IpResolver() )->resolve( array() ) );
	}

	/**
	 * La IP es dato personal bajo el RGPD: no se guarda en claro.
	 */
	public function test_la_clave_de_cache_esta_hasheada(): void {
		$clave = IpResolver::key( '203.0.113.9', 'sal-del-sitio' );

		$this->assertStringNotContainsString( '203.0.113.9', $clave );
		$this->assertSame( 32, strlen( $clave ) );
	}

	public function test_la_clave_es_estable_para_la_misma_ip(): void {
		$this->assertSame(
			IpResolver::key( '203.0.113.9', 'sal' ),
			IpResolver::key( '203.0.113.9', 'sal' )
		);
		$this->assertNotSame(
			IpResolver::key( '203.0.113.9', 'sal' ),
			IpResolver::key( '203.0.113.10', 'sal' )
		);
	}
}

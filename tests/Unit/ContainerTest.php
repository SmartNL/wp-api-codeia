<?php
/**
 * Tests del contenedor DI.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit;

use WpApi\Codeia\Container;
use WpApi\Codeia\Core\Exceptions\ContainerException;
use WpApi\Codeia\Core\Exceptions\NotFoundException;

/**
 * @covers \WpApi\Codeia\Container
 */
final class ContainerTest extends TestCase {

	public function test_resuelve_un_servicio_registrado(): void {
		$container = new Container();
		$container->set( 'saludo', static fn (): string => 'hola' );

		$this->assertSame( 'hola', $container->get( 'saludo' ) );
	}

	public function test_la_factoria_no_se_ejecuta_hasta_que_se_pide(): void {
		$ejecutada = false;
		$container = new Container();

		$container->set(
			'perezoso',
			static function () use ( &$ejecutada ): string {
				$ejecutada = true;
				return 'listo';
			}
		);

		$this->assertFalse( $ejecutada, 'La factoria no debe ejecutarse al registrarse.' );

		$container->get( 'perezoso' );

		$this->assertTrue( $ejecutada );
	}

	public function test_cachea_la_instancia_entre_llamadas(): void {
		$container = new Container();
		$container->set( 'objeto', static fn (): object => new \stdClass() );

		$this->assertSame( $container->get( 'objeto' ), $container->get( 'objeto' ) );
	}

	public function test_la_factoria_recibe_el_contenedor(): void {
		$container = new Container();
		$container->set( 'base', static fn (): int => 21 );
		$container->set(
			'derivado',
			static fn ( Container $c ): int => $c->get( 'base' ) * 2
		);

		$this->assertSame( 42, $container->get( 'derivado' ) );
	}

	public function test_lanza_not_found_si_el_servicio_no_existe(): void {
		$container = new Container();

		$this->expectException( NotFoundException::class );
		$this->expectExceptionMessage( 'inexistente' );

		$container->get( 'inexistente' );
	}

	public function test_detecta_dependencias_circulares(): void {
		$container = new Container();
		$container->set( 'a', static fn ( Container $c ): mixed => $c->get( 'b' ) );
		$container->set( 'b', static fn ( Container $c ): mixed => $c->get( 'a' ) );

		$this->expectException( ContainerException::class );
		$this->expectExceptionMessage( 'Dependencia circular' );

		$container->get( 'a' );
	}

	public function test_la_cadena_de_resolucion_queda_limpia_tras_una_excepcion(): void {
		$container = new Container();
		$container->set(
			'explota',
			static function (): void {
				throw new \RuntimeException( 'fallo interno' );
			}
		);

		try {
			$container->get( 'explota' );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		// Si el finally del pop no funcionara, esta segunda llamada se
		// interpretaria como ciclo en vez de repetir el fallo original.
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'fallo interno' );

		$container->get( 'explota' );
	}

	public function test_rechaza_registrar_dos_veces_el_mismo_identificador(): void {
		$container = new Container();
		$container->set( 'unico', static fn (): int => 1 );

		$this->expectException( ContainerException::class );
		$this->expectExceptionMessage( 'ya estaba registrado' );

		$container->set( 'unico', static fn (): int => 2 );
	}

	public function test_instance_sobrescribe_para_permitir_dobles_en_tests(): void {
		$container = new Container();
		$container->set( 'servicio', static fn (): string => 'real' );

		$container->instance( 'servicio', 'doble' );

		$this->assertSame( 'doble', $container->get( 'servicio' ) );
	}

	public function test_has_distingue_registrado_de_ausente(): void {
		$container = new Container();
		$container->set( 'factoria', static fn (): int => 1 );
		$container->instance( 'valor', 2 );

		$this->assertTrue( $container->has( 'factoria' ) );
		$this->assertTrue( $container->has( 'valor' ) );
		$this->assertFalse( $container->has( 'nada' ) );
	}

	public function test_keys_devuelve_factorias_e_instancias_sin_duplicar(): void {
		$container = new Container();
		$container->set( 'a', static fn (): int => 1 );
		$container->instance( 'b', 2 );

		$keys = $container->keys();
		sort( $keys );

		$this->assertSame( array( 'a', 'b' ), $keys );
	}

	public function test_conserva_un_valor_nulo_registrado_como_instancia(): void {
		$container = new Container();
		$container->instance( 'nulo', null );

		$this->assertTrue( $container->has( 'nulo' ) );
		$this->assertNull( $container->get( 'nulo' ) );
	}
}

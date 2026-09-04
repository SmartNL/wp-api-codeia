<?php
/**
 * Tests de la ventana deslizante.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit\Security;

use WpApi\Codeia\Core\Cache\CacheInterface;
use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Security\SlidingWindow;
use WpApi\Codeia\Tests\Unit\TestCase;

/**
 * @covers \WpApi\Codeia\Security\SlidingWindow
 */
final class SlidingWindowTest extends TestCase {

	private SlidingWindow $window;

	protected function setUp(): void {
		parent::setUp();

		$driver = new class() implements CacheInterface {

			/** @var array<string, mixed> */
			private array $store = array();

			public function get( string $key, string $group, ?bool &$found = null ): mixed {
				$found = array_key_exists( $group . '|' . $key, $this->store );

				return $found ? $this->store[ $group . '|' . $key ] : null;
			}

			public function set( string $key, mixed $value, string $group, int $ttl = 0 ): bool {
				$this->store[ $group . '|' . $key ] = $value;

				return true;
			}

			public function delete( string $key, string $group ): bool {
				unset( $this->store[ $group . '|' . $key ] );

				return true;
			}

			public function is_persistent(): bool {
				return true;
			}

			public function name(): string {
				return 'fake';
			}
		};

		$this->window = new SlidingWindow( new CacheManager( $driver ) );
	}

	public function test_permite_hasta_el_limite(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$estado = $this->window->hit( 'k', 5, 60, 1000 );
			$this->assertTrue( $estado['allowed'], "Peticion {$i} deberia caber." );
		}

		$this->assertFalse( $this->window->hit( 'k', 5, 60, 1000 )['allowed'] );
	}

	public function test_los_restantes_decrecen(): void {
		$primera = $this->window->hit( 'k', 10, 60, 1000 );
		$segunda = $this->window->hit( 'k', 10, 60, 1000 );

		$this->assertSame( 9, $primera['remaining'] );
		$this->assertSame( 8, $segunda['remaining'] );
	}

	/**
	 * El defecto de la ventana fija: 120 al final de un minuto y 120 al
	 * principio del siguiente pasarian como 240 en dos segundos. La ventana
	 * deslizante lo impide.
	 */
	public function test_no_admite_el_doble_del_limite_en_el_borde(): void {
		// Ventana de 60s: t=1050 esta en el slot 17, t=1080 en el 18.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->window->hit( 'borde', 10, 60, 1050 );
		}

		// Justo al cruzar, la ventana anterior aun pesa casi entera.
		$estado = $this->window->hit( 'borde', 10, 60, 1082 );

		$this->assertFalse(
			$estado['allowed'],
			'Al cruzar el borde, el contador anterior sigue contando ponderado.'
		);
	}

	public function test_al_alejarse_del_borde_vuelve_a_admitir(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->window->hit( 'lejos', 10, 60, 1050 );
		}

		// Ya casi terminada la ventana siguiente, la anterior pesa poco.
		$this->assertTrue( $this->window->hit( 'lejos', 10, 60, 1138 )['allowed'] );
	}

	public function test_identidades_distintas_no_se_mezclan(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->window->hit( 'uno', 5, 60, 1000 );
		}

		$this->assertFalse( $this->window->hit( 'uno', 5, 60, 1000 )['allowed'] );
		$this->assertTrue( $this->window->hit( 'otro', 5, 60, 1000 )['allowed'] );
	}

	public function test_peek_no_consume(): void {
		$this->window->hit( 'p', 5, 60, 1000 );

		$primera = $this->window->peek( 'p', 5, 60, 1000 );
		$segunda = $this->window->peek( 'p', 5, 60, 1000 );

		$this->assertSame( $primera['remaining'], $segunda['remaining'] );
		$this->assertSame( 4, $primera['remaining'] );
	}

	public function test_calcula_el_momento_de_reinicio(): void {
		$estado = $this->window->hit( 'r', 5, 60, 1030 );

		$this->assertSame( 1080, $estado['reset'] );
		$this->assertSame( 50, $estado['retry_after'] );
	}

	public function test_retry_after_nunca_es_cero(): void {
		$estado = $this->window->hit( 'r', 5, 60, 1079 );

		$this->assertGreaterThan( 0, $estado['retry_after'] );
	}
}

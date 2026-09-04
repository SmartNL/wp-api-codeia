<?php
/**
 * Tests de la jerarquia de cache.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit;

use WpApi\Codeia\Core\Cache\CacheInterface;
use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Cache\MemoryDriver;

/**
 * @covers \WpApi\Codeia\Core\Cache\CacheManager
 * @covers \WpApi\Codeia\Core\Cache\MemoryDriver
 */
final class CacheManagerTest extends TestCase {

	/**
	 * Driver persistente falso que cuenta las lecturas.
	 *
	 * @return CacheInterface
	 */
	private function spy_driver(): CacheInterface {
		return new class() implements CacheInterface {

			/** @var array<string, mixed> */
			public array $store = array();

			public int $reads = 0;

			public int $writes = 0;

			public function get( string $key, string $group, ?bool &$found = null ): mixed {
				++$this->reads;
				$found = array_key_exists( $group . '|' . $key, $this->store );

				return $found ? $this->store[ $group . '|' . $key ] : null;
			}

			public function set( string $key, mixed $value, string $group, int $ttl = 0 ): bool {
				++$this->writes;
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
				return 'spy';
			}
		};
	}

	public function test_una_segunda_lectura_no_toca_el_nivel_persistente(): void {
		$spy   = $this->spy_driver();
		$cache = new CacheManager( $spy );

		$cache->set( 'clave', 'valor', CacheManager::GROUP_SCHEMA );

		// Vaciar L1 obliga a la primera lectura a bajar a L2.
		$cache->flush_memory();

		$cache->get( 'clave', CacheManager::GROUP_SCHEMA );
		$cache->get( 'clave', CacheManager::GROUP_SCHEMA );
		$cache->get( 'clave', CacheManager::GROUP_SCHEMA );

		$this->assertSame( 1, $spy->reads, 'Tras el primer acierto, L1 debe servir el resto.' );
	}

	public function test_escribe_en_los_dos_niveles(): void {
		$spy   = $this->spy_driver();
		$cache = new CacheManager( $spy );

		$cache->set( 'k', 'v', CacheManager::GROUP_RESPONSE );

		$this->assertSame( 1, $spy->writes );
		$this->assertSame( 'v', $cache->get( 'k', CacheManager::GROUP_RESPONSE ) );
	}

	public function test_distingue_un_valor_falso_almacenado_de_un_fallo(): void {
		$cache = new CacheManager( $this->spy_driver() );

		$cache->set( 'bandera', false, CacheManager::GROUP_SCHEMA );

		$found = null;
		$value = $cache->get( 'bandera', CacheManager::GROUP_SCHEMA, $found );

		$this->assertTrue( $found, 'Un false almacenado es un acierto, no un fallo.' );
		$this->assertFalse( $value );
	}

	public function test_indica_fallo_cuando_la_clave_no_existe(): void {
		$cache = new CacheManager( $this->spy_driver() );

		$found = null;
		$value = $cache->get( 'ausente', CacheManager::GROUP_SCHEMA, $found );

		$this->assertFalse( $found );
		$this->assertNull( $value );
	}

	public function test_remember_ejecuta_el_productor_una_sola_vez(): void {
		$cache = new CacheManager( $this->spy_driver() );
		$veces = 0;

		$producer = static function () use ( &$veces ): string {
			++$veces;
			return 'calculado';
		};

		$this->assertSame( 'calculado', $cache->remember( 'k', CacheManager::GROUP_SCHEMA, $producer ) );
		$this->assertSame( 'calculado', $cache->remember( 'k', CacheManager::GROUP_SCHEMA, $producer ) );
		$this->assertSame( 1, $veces );
	}

	public function test_delete_limpia_los_dos_niveles(): void {
		$spy   = $this->spy_driver();
		$cache = new CacheManager( $spy );

		$cache->set( 'k', 'v', CacheManager::GROUP_SCHEMA );
		$cache->delete( 'k', CacheManager::GROUP_SCHEMA );

		$found = null;
		$cache->get( 'k', CacheManager::GROUP_SCHEMA, $found );

		$this->assertFalse( $found );
		$this->assertSame( array(), $spy->store );
	}

	public function test_los_grupos_no_colisionan(): void {
		$cache = new CacheManager( $this->spy_driver() );

		$cache->set( 'misma', 'esquema', CacheManager::GROUP_SCHEMA );
		$cache->set( 'misma', 'respuesta', CacheManager::GROUP_RESPONSE );

		$this->assertSame( 'esquema', $cache->get( 'misma', CacheManager::GROUP_SCHEMA ) );
		$this->assertSame( 'respuesta', $cache->get( 'misma', CacheManager::GROUP_RESPONSE ) );
	}

	public function test_el_driver_en_memoria_no_es_persistente(): void {
		$memory = new MemoryDriver();

		$this->assertFalse( $memory->is_persistent() );
		$this->assertSame( 'memory', $memory->name() );
	}

	public function test_flush_memory_por_grupo_solo_afecta_a_ese_grupo(): void {
		$memory = new MemoryDriver();

		$memory->set( 'a', 1, CacheManager::GROUP_SCHEMA );
		$memory->set( 'b', 2, CacheManager::GROUP_RESPONSE );

		$memory->flush( CacheManager::GROUP_SCHEMA );

		$found_a = null;
		$found_b = null;
		$memory->get( 'a', CacheManager::GROUP_SCHEMA, $found_a );
		$memory->get( 'b', CacheManager::GROUP_RESPONSE, $found_b );

		$this->assertFalse( $found_a );
		$this->assertTrue( $found_b );
	}
}

<?php
/**
 * Tests del sistema de eventos.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use WpApi\Codeia\Core\EventDispatcher;

/**
 * @covers \WpApi\Codeia\Core\EventDispatcher
 */
final class EventDispatcherTest extends TestCase {

	public function test_prefija_los_nombres_de_evento(): void {
		$events = new EventDispatcher();

		$this->assertSame( 'codeia/schema/providers', $events->hook_name( 'schema/providers' ) );
	}

	public function test_emit_dispara_la_accion_con_el_prefijo(): void {
		$events = new EventDispatcher();

		$events->emit( 'plugin/booted', 'contexto' );

		$this->assertSame( 1, Actions\did( 'codeia/plugin/booted' ) );
	}

	public function test_filter_aplica_el_filtro_con_el_prefijo(): void {
		Filters\expectApplied( 'codeia/schema/resource_fields' )
			->once()
			->with( array( 'a' ), 'property' )
			->andReturn( array( 'a', 'b' ) );

		$events = new EventDispatcher();

		$this->assertSame(
			array( 'a', 'b' ),
			$events->filter( 'schema/resource_fields', array( 'a' ), 'property' )
		);
	}

	public function test_filter_devuelve_el_valor_original_sin_suscriptores(): void {
		$events = new EventDispatcher();

		$this->assertSame( 'sin tocar', $events->filter( 'evento/libre', 'sin tocar' ) );
	}

	public function test_el_prefijo_es_el_documentado(): void {
		$this->assertSame( 'codeia/', EventDispatcher::PREFIX );
	}
}

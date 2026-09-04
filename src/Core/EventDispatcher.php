<?php
/**
 * Sistema de eventos del plugin.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Fachada sobre los hooks de WordPress con el prefijo del plugin.
 *
 * No sustituye a do_action() ni apply_filters(): los envuelve. Centraliza el
 * prefijo "codeia/", documenta la firma de cada evento y permite instrumentar
 * sin tocar los puntos de emision. Cualquier desarrollador puede seguir
 * usando add_filter() directamente sobre el nombre completo del hook.
 */
final class EventDispatcher {

	/**
	 * Prefijo de todos los hooks del plugin.
	 */
	public const PREFIX = 'codeia/';

	/**
	 * Emite una accion.
	 *
	 * @param string $event Nombre del evento sin prefijo, por ejemplo "schema/before_rebuild".
	 * @param mixed  ...$args Argumentos que reciben los suscriptores.
	 * @return void
	 */
	public function emit( string $event, mixed ...$args ): void {
		do_action( self::PREFIX . $event, ...$args );
	}

	/**
	 * Aplica un filtro.
	 *
	 * @param string $event   Nombre del evento sin prefijo.
	 * @param mixed  $value   Valor a filtrar.
	 * @param mixed  ...$args Argumentos adicionales de contexto.
	 * @return mixed Valor filtrado.
	 */
	public function filter( string $event, mixed $value, mixed ...$args ): mixed {
		return apply_filters( self::PREFIX . $event, $value, ...$args );
	}

	/**
	 * Suscribe un callback a un evento.
	 *
	 * @param string   $event         Nombre del evento sin prefijo.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Prioridad.
	 * @param int      $accepted_args Numero de argumentos que acepta el callback.
	 * @return void
	 */
	public function on( string $event, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		add_filter( self::PREFIX . $event, $callback, $priority, $accepted_args );
	}

	/**
	 * Cancela una suscripcion.
	 *
	 * @param string   $event    Nombre del evento sin prefijo.
	 * @param callable $callback Callback exactamente igual al suscrito.
	 * @param int      $priority Prioridad con la que se suscribio.
	 * @return bool Si se elimino la suscripcion.
	 */
	public function off( string $event, callable $callback, int $priority = 10 ): bool {
		return remove_filter( self::PREFIX . $event, $callback, $priority );
	}

	/**
	 * Devuelve el nombre completo de un evento.
	 *
	 * Pensado para documentacion y mensajes de error, no para uso interno.
	 *
	 * @param string $event Nombre del evento sin prefijo.
	 * @return string
	 */
	public function hook_name( string $event ): string {
		return self::PREFIX . $event;
	}
}

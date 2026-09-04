<?php
/**
 * Cuotas de subida por usuario.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Media;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Security\SlidingWindow;

/**
 * Limita cuantos ficheros y cuantos bytes sube cada usuario por ventana.
 *
 * Sin cuota, la API se convierte en alojamiento gratuito de ficheros, con el
 * coste de ancho de banda y la responsabilidad legal que eso implica.
 */
final class QuotaManager {

	/**
	 * Ficheros por usuario y hora, por defecto.
	 */
	public const DEFAULT_FILES = 50;

	/**
	 * Bytes por usuario y hora, por defecto.
	 */
	public const DEFAULT_BYTES = 100 * 1024 * 1024;

	/**
	 * Ventana de la cuota, en segundos.
	 */
	public const WINDOW = HOUR_IN_SECONDS;

	/**
	 * Ventana deslizante.
	 *
	 * @var SlidingWindow
	 */
	private SlidingWindow $window;

	/**
	 * Maximo de ficheros por ventana.
	 *
	 * @var int
	 */
	private int $max_files;

	/**
	 * Maximo de bytes por ventana.
	 *
	 * @var int
	 */
	private int $max_bytes;

	/**
	 * Construye el gestor.
	 *
	 * @param SlidingWindow $window    Ventana deslizante.
	 * @param int           $max_files Ficheros por ventana.
	 * @param int           $max_bytes Bytes por ventana.
	 */
	public function __construct(
		SlidingWindow $window,
		int $max_files = self::DEFAULT_FILES,
		int $max_bytes = self::DEFAULT_BYTES
	) {
		$this->window    = $window;
		$this->max_files = max( 1, $max_files );
		$this->max_bytes = max( 1, $max_bytes );
	}

	/**
	 * Comprueba y consume la cuota de un usuario.
	 *
	 * @param int $user_id ID de usuario.
	 * @param int $bytes   Bytes del fichero.
	 * @return array<string, mixed>
	 */
	public function consume( int $user_id, int $bytes ): array {
		$files = $this->window->hit( 'files:' . $user_id, $this->max_files, self::WINDOW );

		if ( ! $files['allowed'] ) {
			return array(
				'allowed'     => false,
				'reason'      => 'files',
				'retry_after' => $files['retry_after'],
			);
		}

		$chunks   = max( 1, (int) ceil( $bytes / 1024 ) );
		$max_kb   = (int) ceil( $this->max_bytes / 1024 );
		$consumed = null;

		for ( $i = 0; $i < $chunks; $i++ ) {
			$consumed = $this->window->hit( 'bytes:' . $user_id, $max_kb, self::WINDOW );

			if ( ! $consumed['allowed'] ) {
				return array(
					'allowed'     => false,
					'reason'      => 'bytes',
					'retry_after' => $consumed['retry_after'],
				);
			}
		}

		return array(
			'allowed'   => true,
			'remaining' => $files['remaining'],
		);
	}

	/**
	 * Consulta la cuota sin consumirla.
	 *
	 * @param int $user_id ID de usuario.
	 * @return array<string, mixed>
	 */
	public function status( int $user_id ): array {
		return $this->window->peek( 'files:' . $user_id, $this->max_files, self::WINDOW );
	}

	/**
	 * Limite de ficheros por ventana.
	 *
	 * @return int
	 */
	public function max_files(): int {
		return $this->max_files;
	}
}

<?php
/**
 * Servicio no encontrado en el contenedor.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Core\Exceptions;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/**
 * Se lanza cuando se pide al contenedor un identificador que no tiene registrado.
 *
 * Equivalente a Psr\Container\NotFoundExceptionInterface. No se hereda del
 * paquete PSR para no arrastrar una dependencia de runtime que podria chocar
 * con la version que cargue otro plugin.
 */
final class NotFoundException extends InvalidArgumentException {

	/**
	 * Crea la excepcion a partir del identificador solicitado.
	 *
	 * @param string $id Identificador del servicio.
	 * @return self
	 */
	public static function for_id( string $id ): self {
		return new self(
			sprintf( 'El contenedor no tiene registrado el servicio "%s".', $id )
		);
	}
}

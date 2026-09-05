<?php
/**
 * Error al resolver un servicio del contenedor.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Core\Exceptions;

defined( 'ABSPATH' ) || exit;

use RuntimeException;

/**
 * Se lanza cuando el contenedor no puede construir un servicio registrado.
 *
 * Equivalente a Psr\Container\ContainerExceptionInterface, sin heredar del
 * paquete PSR. Ver el razonamiento en NotFoundException.
 */
final class ContainerException extends RuntimeException {

	/**
	 * Dependencia circular detectada durante la resolucion.
	 *
	 * @param string   $id    Servicio que cierra el ciclo.
	 * @param string[] $chain Cadena de resolucion en curso.
	 * @return self
	 */
	public static function circular_dependency( string $id, array $chain ): self {
		return new self(
			sprintf(
				'Dependencia circular al resolver "%s". Cadena: %s.',
				$id,
				implode( ' -> ', array_merge( $chain, array( $id ) ) )
			)
		);
	}

	/**
	 * El identificador ya estaba registrado.
	 *
	 * @param string $id Identificador duplicado.
	 * @return self
	 */
	public static function already_registered( string $id ): self {
		return new self(
			sprintf( 'El servicio "%s" ya estaba registrado en el contenedor.', $id )
		);
	}
}

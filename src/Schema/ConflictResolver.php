<?php
/**
 * Resolucion de conflictos entre niveles de deteccion.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Fusiona varias definiciones de la misma clave en una sola.
 *
 * Cuando varios niveles describen el mismo campo, gana el de mayor confianza
 * y los demas solo rellenan huecos. Ante un empate con tipos distintos el
 * criterio es deliberadamente conservador: se aplica el tipo mas permisivo y
 * el campo NO se expone hasta que alguien decida.
 *
 * Un campo expuesto como string cuando en realidad guarda texto funciona;
 * expuesto como integer produce errores de validacion intermitentes.
 */
final class ConflictResolver {

	/**
	 * Conflictos sin resolver de la ultima fusion.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $conflicts = array();

	/**
	 * Fusiona las definiciones de una misma clave.
	 *
	 * @param FieldDefinition[] $candidates Definiciones de la misma storage_key.
	 * @return FieldDefinition
	 */
	public function resolve( array $candidates ): FieldDefinition {
		if ( 1 === count( $candidates ) ) {
			return $candidates[0];
		}

		usort(
			$candidates,
			static function ( FieldDefinition $a, FieldDefinition $b ): int {
				return $b->confidence <=> $a->confidence;
			}
		);

		$winner = $candidates[0];
		$rest   = array_slice( $candidates, 1 );

		$this->detect_type_standoff( $winner, $rest );
		$this->fill_gaps( $winner, $rest );

		return $winner;
	}

	/**
	 * Marca el campo como no expuesto si hay empate de confianza con tipos
	 * distintos.
	 *
	 * @param FieldDefinition   $winner Definicion ganadora.
	 * @param FieldDefinition[] $rest   Resto de candidatas.
	 * @return void
	 */
	private function detect_type_standoff( FieldDefinition $winner, array $rest ): void {
		foreach ( $rest as $other ) {
			if ( $other->confidence !== $winner->confidence ) {
				continue;
			}

			if ( $other->type === $winner->type ) {
				continue;
			}

			$this->conflicts[ $winner->storage_key ] = array(
				'reason' => 'tipos en disputa con la misma confianza',
				'types'  => array(
					$winner->origin => $winner->type,
					$other->origin  => $other->type,
				),
			);

			$winner->type      = FieldDefinition::TYPE_STRING;
			$winner->format    = null;
			$winner->ambiguous = true;

			return;
		}
	}

	/**
	 * Rellena con los candidatos de menor confianza los datos que faltan.
	 *
	 * @param FieldDefinition   $winner Definicion ganadora, modificada en sitio.
	 * @param FieldDefinition[] $rest   Resto de candidatas.
	 * @return void
	 */
	private function fill_gaps( FieldDefinition $winner, array $rest ): void {
		foreach ( $rest as $other ) {
			if ( null === $winner->label && null !== $other->label ) {
				$winner->label = $other->label;
			}

			if ( array() === $winner->enum && array() !== $other->enum ) {
				$winner->enum = $other->enum;
			}

			if ( null === $winner->relation && null !== $other->relation ) {
				$winner->relation = $other->relation;
			}

			if ( null === $winner->usage_count && null !== $other->usage_count ) {
				$winner->usage_count = $other->usage_count;
			}

			if ( null === $winner->format && null !== $other->format && $other->type === $winner->type ) {
				$winner->format = $other->format;
			}
		}
	}

	/**
	 * Agrupa definiciones por clave de almacenamiento y resuelve cada grupo.
	 *
	 * @param FieldDefinition[] $definitions Definiciones de todos los niveles.
	 * @return FieldDefinition[] Indexadas por storage_key.
	 */
	public function resolve_all( array $definitions ): array {
		$this->conflicts = array();

		$grouped = array();

		foreach ( $definitions as $definition ) {
			$grouped[ $definition->storage_key ][] = $definition;
		}

		$resolved = array();

		foreach ( $grouped as $key => $candidates ) {
			$resolved[ $key ] = $this->resolve( $candidates );
		}

		return $resolved;
	}

	/**
	 * Conflictos sin resolver, para el panel de estado.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function conflicts(): array {
		return $this->conflicts;
	}

	/**
	 * Indica si una clave quedo en disputa.
	 *
	 * @param string $storage_key Clave.
	 * @return bool
	 */
	public function has_conflict( string $storage_key ): bool {
		return isset( $this->conflicts[ $storage_key ] );
	}
}

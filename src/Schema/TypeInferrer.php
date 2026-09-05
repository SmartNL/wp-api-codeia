<?php
/**
 * Inferencia de tipos a partir de valores reales.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Deduce el tipo de un campo analizando una muestra de sus valores.
 *
 * El muestreo de base de datos da nombres, no tipos. Lo que sale de aqui es
 * SIEMPRE una propuesta con confianza baja, revisable en el dashboard: el
 * tipo condiciona la comparacion en meta_query, y equivocarse produce
 * filtros que no filtran o valores mal convertidos en la respuesta.
 */
final class TypeInferrer {

	/**
	 * Numero maximo de valores distintos que se analizan por clave.
	 */
	public const SAMPLE_SIZE = 50;

	/**
	 * Deduce tipo, formato y ambiguedad de un conjunto de valores.
	 *
	 * @param array<int, mixed> $values Valores de muestra.
	 * @return array<string, mixed>
	 */
	public function infer( array $values ): array {
		$values = array_slice( array_values( $values ), 0, self::SAMPLE_SIZE );
		$values = array_values(
			array_filter(
				$values,
				static function ( $value ): bool {
					return null !== $value && '' !== $value;
				}
			)
		);

		if ( array() === $values ) {
			return $this->result( FieldDefinition::TYPE_STRING );
		}

		if ( $this->all_match( $values, 'is_serialized' ) ) {
			return $this->result( $this->serialized_type( $values ) );
		}

		if ( $this->all_json( $values ) ) {
			return $this->result( FieldDefinition::TYPE_OBJECT );
		}

		if ( $this->all_dates( $values ) ) {
			return $this->result( FieldDefinition::TYPE_STRING, 'date-time' );
		}

		if ( $this->all_emails( $values ) ) {
			return $this->result( FieldDefinition::TYPE_STRING, 'email' );
		}

		if ( $this->all_urls( $values ) ) {
			return $this->result( FieldDefinition::TYPE_STRING, 'uri' );
		}

		if ( $this->looks_padded( $values ) ) {
			return $this->result( FieldDefinition::TYPE_STRING );
		}

		if ( $this->is_binary_flag( $values ) ) {
			return $this->result( FieldDefinition::TYPE_BOOLEAN, null, true );
		}

		if ( $this->all_match( $values, 'is_numeric' ) ) {
			return $this->result( $this->numeric_type( $values ) );
		}

		return $this->result( FieldDefinition::TYPE_STRING, null, false, $this->small_enum( $values ) );
	}

	/**
	 * Compone el array de resultado.
	 *
	 * @param string             $type      Tipo JSON Schema.
	 * @param string|null        $format    Formato adicional.
	 * @param bool               $ambiguous Si requiere confirmacion humana.
	 * @param array<int, scalar> $allowed   Valores admitidos.
	 * @return array<string, mixed>
	 */
	private function result( string $type, ?string $format = null, bool $ambiguous = false, array $allowed = array() ): array {
		return array(
			'type'      => $type,
			'format'    => $format,
			'ambiguous' => $ambiguous,
			'enum'      => $allowed,
		);
	}

	/**
	 * Comprueba que todos los valores pasan un predicado.
	 *
	 * @param array<int, mixed> $values    Valores.
	 * @param callable          $predicate Predicado que recibe una cadena.
	 * @return bool
	 */
	private function all_match( array $values, callable $predicate ): bool {
		foreach ( $values as $value ) {
			if ( ! $predicate( (string) $value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Distingue entero de decimal.
	 *
	 * @param array<int, mixed> $values Valores numericos.
	 * @return string
	 */
	private function numeric_type( array $values ): string {
		foreach ( $values as $value ) {
			if ( str_contains( (string) $value, '.' ) ) {
				return FieldDefinition::TYPE_NUMBER;
			}
		}

		return FieldDefinition::TYPE_INTEGER;
	}

	/**
	 * Detecta numeros con ceros a la izquierda y longitud constante.
	 *
	 * Numerico no implica numero: los codigos postales y las referencias
	 * catastrales son cadenas. Convertirlos a entero pierde los ceros
	 * iniciales de forma irreversible.
	 *
	 * @param array<int, mixed> $values Valores.
	 * @return bool
	 */
	private function looks_padded( array $values ): bool {
		$lengths = array();
		$padded  = false;

		foreach ( $values as $value ) {
			$text = (string) $value;

			if ( ! ctype_digit( $text ) ) {
				return false;
			}

			$lengths[] = strlen( $text );

			if ( strlen( $text ) > 1 && str_starts_with( $text, '0' ) ) {
				$padded = true;
			}
		}

		return $padded && 1 === count( array_unique( $lengths ) );
	}

	/**
	 * Detecta un rango de valores limitado a 0 y 1.
	 *
	 * Parece booleano, pero _property_floors con valores 0 y 1 es un contador
	 * de plantas. Se propone boolean y se marca ambiguo para pedir
	 * confirmacion en el dashboard.
	 *
	 * @param array<int, mixed> $values Valores.
	 * @return bool
	 */
	private function is_binary_flag( array $values ): bool {
		$distinct = array_unique( array_map( 'strval', $values ) );
		sort( $distinct );

		return array( '0', '1' ) === $distinct;
	}

	/**
	 * Distingue una lista de un mapa dentro de valores serializados.
	 *
	 * @param array<int, mixed> $values Valores.
	 * @return string
	 */
	private function serialized_type( array $values ): string {
		foreach ( $values as $value ) {
			$decoded = maybe_unserialize( (string) $value );

			if ( ! is_array( $decoded ) ) {
				return FieldDefinition::TYPE_OBJECT;
			}

			if ( array() !== $decoded && array_keys( $decoded ) !== range( 0, count( $decoded ) - 1 ) ) {
				return FieldDefinition::TYPE_OBJECT;
			}
		}

		return FieldDefinition::TYPE_ARRAY;
	}

	/**
	 * Comprueba si todos los valores son JSON estructurado.
	 *
	 * @param array<int, mixed> $values Valores.
	 * @return bool
	 */
	private function all_json( array $values ): bool {
		foreach ( $values as $value ) {
			$text = trim( (string) $value );

			if ( ! str_starts_with( $text, '{' ) && ! str_starts_with( $text, '[' ) ) {
				return false;
			}

			json_decode( $text, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Comprueba si todos los valores son fechas reconocibles.
	 *
	 * @param array<int, mixed> $values Valores.
	 * @return bool
	 */
	private function all_dates( array $values ): bool {
		foreach ( $values as $value ) {
			$text = (string) $value;

			if ( is_numeric( $text ) ) {
				return false;
			}

			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}/', $text ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Comprueba si todos los valores son direcciones de correo.
	 *
	 * @param array<int, mixed> $values Valores.
	 * @return bool
	 */
	private function all_emails( array $values ): bool {
		foreach ( $values as $value ) {
			if ( ! filter_var( (string) $value, FILTER_VALIDATE_EMAIL ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Comprueba si todos los valores son URL absolutas.
	 *
	 * @param array<int, mixed> $values Valores.
	 * @return bool
	 */
	private function all_urls( array $values ): bool {
		foreach ( $values as $value ) {
			if ( ! filter_var( (string) $value, FILTER_VALIDATE_URL ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Propone un enum cuando el conjunto de valores distintos es pequeno.
	 *
	 * @param array<int, mixed> $values Valores.
	 * @return array<int, scalar>
	 */
	private function small_enum( array $values ): array {
		$distinct = array_values( array_unique( array_map( 'strval', $values ) ) );

		if ( count( $distinct ) > 8 || count( $values ) < 4 ) {
			return array();
		}

		sort( $distinct );

		return $distinct;
	}
}

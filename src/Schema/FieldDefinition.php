<?php
/**
 * Definicion normalizada de un campo.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Un campo de un recurso, ya normalizado, venga de donde venga.
 *
 * Todos los niveles de deteccion producen esta misma estructura. Los campos
 * origin y confidence son los que permiten resolver conflictos y explicar en
 * la interfaz por que un campo se detecto como se detecto: sin trazabilidad
 * de origen, un esquema equivocado es imposible de depurar.
 */
final class FieldDefinition {

	public const ORIGIN_MANUAL    = 'manual';
	public const ORIGIN_NATIVE    = 'native';
	public const ORIGIN_ACF       = 'acf';
	public const ORIGIN_METABOX   = 'metabox';
	public const ORIGIN_JETENGINE = 'jetengine';
	public const ORIGIN_DB_SAMPLE = 'db_sample';

	/**
	 * Confianza asociada a cada origen.
	 *
	 * Manual gana siempre porque lo dijo una persona. db_sample es el mas
	 * bajo: el campo existe seguro, pero su tipo es una conjetura.
	 */
	public const CONFIDENCE = array(
		self::ORIGIN_MANUAL    => 100,
		self::ORIGIN_NATIVE    => 95,
		self::ORIGIN_ACF       => 85,
		self::ORIGIN_METABOX   => 85,
		self::ORIGIN_JETENGINE => 85,
		self::ORIGIN_DB_SAMPLE => 40,
	);

	public const TYPE_STRING  = 'string';
	public const TYPE_INTEGER = 'integer';
	public const TYPE_NUMBER  = 'number';
	public const TYPE_BOOLEAN = 'boolean';
	public const TYPE_ARRAY   = 'array';
	public const TYPE_OBJECT  = 'object';

	/**
	 * Clave real en la base de datos.
	 *
	 * @var string
	 */
	public string $storage_key;

	/**
	 * Nombre publico del campo en la API.
	 *
	 * @var string
	 */
	public string $exposed_name;

	/**
	 * Tipo JSON Schema.
	 *
	 * @var string
	 */
	public string $type;

	/**
	 * Formato adicional (date-time, uri, email) o null.
	 *
	 * @var string|null
	 */
	public ?string $format;

	/**
	 * Si el campo guarda un solo valor.
	 *
	 * @var bool
	 */
	public bool $single;

	/**
	 * Nivel que produjo la definicion.
	 *
	 * @var string
	 */
	public string $origin;

	/**
	 * Confianza de 0 a 100.
	 *
	 * @var int
	 */
	public int $confidence;

	/**
	 * Etiqueta legible, si el origen la aporta.
	 *
	 * @var string|null
	 */
	public ?string $label;

	/**
	 * Valores admitidos, si los hay.
	 *
	 * @var array<int, scalar>
	 */
	public array $enum;

	/**
	 * Relacion propuesta: array{post_type: string, confirmed: bool} o null.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $relation;

	/**
	 * Si la clave es meta protegida segun WordPress.
	 *
	 * @var bool
	 */
	public bool $protected;

	/**
	 * Si el tipo inferido es ambiguo y requiere confirmacion.
	 *
	 * @var bool
	 */
	public bool $ambiguous;

	/**
	 * Numero de entradas que usan la clave, cuando el origen lo sabe.
	 *
	 * @var int|null
	 */
	public ?int $usage_count;

	/**
	 * Construye la definicion.
	 *
	 * @param string               $storage_key  Clave de almacenamiento.
	 * @param string               $exposed_name Nombre publico.
	 * @param string               $type         Tipo JSON Schema.
	 * @param string               $origin       Origen de la deteccion.
	 * @param array<string, mixed> $extra        Atributos opcionales.
	 */
	public function __construct(
		string $storage_key,
		string $exposed_name,
		string $type,
		string $origin,
		array $extra = array()
	) {
		$this->storage_key  = $storage_key;
		$this->exposed_name = $exposed_name;
		$this->type         = $type;
		$this->origin       = $origin;
		$this->confidence   = isset( $extra['confidence'] )
			? (int) $extra['confidence']
			: ( self::CONFIDENCE[ $origin ] ?? 0 );
		$this->format       = isset( $extra['format'] ) ? (string) $extra['format'] : null;
		$this->single       = (bool) ( $extra['single'] ?? true );
		$this->label        = isset( $extra['label'] ) ? (string) $extra['label'] : null;
		$this->enum         = isset( $extra['enum'] ) && is_array( $extra['enum'] ) ? $extra['enum'] : array();
		$this->relation     = isset( $extra['relation'] ) && is_array( $extra['relation'] ) ? $extra['relation'] : null;
		$this->protected    = (bool) ( $extra['protected'] ?? false );
		$this->ambiguous    = (bool) ( $extra['ambiguous'] ?? false );
		$this->usage_count  = isset( $extra['usage_count'] ) ? (int) $extra['usage_count'] : null;
	}

	/**
	 * Indica si la definicion procede de una conjetura.
	 *
	 * @return bool
	 */
	public function is_inferred(): bool {
		return $this->confidence < 50;
	}

	/**
	 * Indica si hay una relacion propuesta sin confirmar.
	 *
	 * Una relacion inferida mal genera expansiones y consultas erroneas, asi
	 * que nunca se activa sola.
	 *
	 * @return bool
	 */
	public function has_unconfirmed_relation(): bool {
		return null !== $this->relation && empty( $this->relation['confirmed'] );
	}

	/**
	 * Serializa la definicion para caché y para la interfaz.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'storage_key'  => $this->storage_key,
			'exposed_name' => $this->exposed_name,
			'type'         => $this->type,
			'format'       => $this->format,
			'single'       => $this->single,
			'origin'       => $this->origin,
			'confidence'   => $this->confidence,
			'label'        => $this->label,
			'enum'         => $this->enum,
			'relation'     => $this->relation,
			'protected'    => $this->protected,
			'ambiguous'    => $this->ambiguous,
			'usage_count'  => $this->usage_count,
		);
	}

	/**
	 * Reconstruye una definicion serializada.
	 *
	 * @param array<string, mixed> $data Datos de to_array().
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['storage_key'] ?? '' ),
			(string) ( $data['exposed_name'] ?? '' ),
			(string) ( $data['type'] ?? self::TYPE_STRING ),
			(string) ( $data['origin'] ?? self::ORIGIN_DB_SAMPLE ),
			$data
		);
	}
}

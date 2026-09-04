<?php
/**
 * Contexto de una decision de acceso.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Permissions;

defined( 'ABSPATH' ) || exit;

/**
 * Transporta todo lo que necesita saber quien decide sobre un acceso.
 *
 * Incluye la decision previa y el nivel de la cascada que la produjo: sin el
 * motivo, un filtro de terceros solo puede decidir a ciegas.
 */
final class PermissionContext {

	public const OP_READ   = 'read';
	public const OP_CREATE = 'create';
	public const OP_UPDATE = 'update';
	public const OP_DELETE = 'delete';
	public const OP_UPLOAD = 'upload';

	/**
	 * Operaciones reconocidas.
	 */
	public const OPERATIONS = array(
		self::OP_READ,
		self::OP_CREATE,
		self::OP_UPDATE,
		self::OP_DELETE,
		self::OP_UPLOAD,
	);

	/**
	 * ID del usuario, 0 si es anonimo.
	 *
	 * @var int
	 */
	public int $user_id;

	/**
	 * Rol efectivo con el que se evalua.
	 *
	 * @var string
	 */
	public string $role;

	/**
	 * Recurso, normalmente el post type.
	 *
	 * @var string
	 */
	public string $resource;

	/**
	 * Operacion solicitada.
	 *
	 * @var string
	 */
	public string $operation;

	/**
	 * Campo concreto, o null si la decision es de recurso.
	 *
	 * @var string|null
	 */
	public ?string $field;

	/**
	 * ID del objeto afectado, o null.
	 *
	 * @var int|null
	 */
	public ?int $object_id;

	/**
	 * Decision alcanzada antes de los filtros de extension.
	 *
	 * @var bool
	 */
	public bool $decision = false;

	/**
	 * Nivel de la cascada que produjo la decision.
	 *
	 * @var int
	 */
	public int $level = 0;

	/**
	 * Construye el contexto.
	 *
	 * @param int         $user_id   ID de usuario.
	 * @param string      $role      Rol efectivo.
	 * @param string      $resource_type Recurso.
	 * @param string      $operation Operacion.
	 * @param string|null $field     Campo, si aplica.
	 * @param int|null    $object_id Objeto, si aplica.
	 */
	public function __construct(
		int $user_id,
		string $role,
		string $resource_type,
		string $operation,
		?string $field = null,
		?int $object_id = null
	) {
		$this->user_id   = $user_id;
		$this->role      = $role;
		$this->resource  = $resource_type;
		$this->operation = $operation;
		$this->field     = $field;
		$this->object_id = $object_id;
	}

	/**
	 * Indica si la peticion es anonima.
	 *
	 * @return bool
	 */
	public function is_anonymous(): bool {
		return 0 === $this->user_id;
	}

	/**
	 * Indica si la decision es sobre un campo concreto.
	 *
	 * @return bool
	 */
	public function is_field_level(): bool {
		return null !== $this->field;
	}

	/**
	 * Comprueba que la operacion es una de las reconocidas.
	 *
	 * @param string $operation Operacion.
	 * @return bool
	 */
	public static function is_valid_operation( string $operation ): bool {
		return in_array( $operation, self::OPERATIONS, true );
	}
}

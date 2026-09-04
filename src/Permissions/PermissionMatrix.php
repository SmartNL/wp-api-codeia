<?php
/**
 * Matriz de permisos configurable.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Permissions;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Config;

/**
 * Resuelve la matriz rol x recurso x operacion x campo.
 *
 * Las reglas se declaran a distintos niveles de especificidad y gana LA MAS
 * ESPECIFICA, no la mas permisiva ni la mas restrictiva. Cada nivel
 * sobrescribe al anterior en lugar de combinarse: un editor con
 * property.read permitido y property.read.price denegado puede leer
 * propiedades sin ver el precio.
 *
 * Toda combinacion no declarada esta denegada. La consecuencia practica es
 * que actualizar el plugin nunca puede ampliar el acceso de nadie: una
 * operacion nueva no la tiene ningun rol hasta que un administrador la
 * concede.
 */
final class PermissionMatrix {

	public const LEVEL_NONE              = 0;
	public const LEVEL_ROLE              = 1;
	public const LEVEL_ROLE_RESOURCE     = 2;
	public const LEVEL_ROLE_RES_OP       = 3;
	public const LEVEL_ROLE_RES_OP_FIELD = 4;

	/**
	 * Configuracion del plugin.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Construye la matriz.
	 *
	 * @param Config $config Configuracion.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Resuelve la decision recorriendo la cascada.
	 *
	 * @param PermissionContext $context Contexto de la decision.
	 * @return array<string, mixed> Decision y nivel que la produjo.
	 */
	public function resolve( PermissionContext $context ): array {
		$decision = false;
		$level    = self::LEVEL_NONE;

		foreach ( $this->candidate_paths( $context ) as $candidate_level => $path ) {
			$rule = $this->config->get( $path );

			if ( null === $rule ) {
				continue;
			}

			$value = $this->normalize( $rule );

			if ( null === $value ) {
				continue;
			}

			$decision = $value;
			$level    = $candidate_level;
		}

		return array(
			'decision' => $decision,
			'level'    => $level,
		);
	}

	/**
	 * Rutas de configuracion a consultar, de menos a mas especifica.
	 *
	 * @param PermissionContext $context Contexto.
	 * @return array<int, string>
	 */
	private function candidate_paths( PermissionContext $context ): array {
		$role     = $context->role;
		$resource = $context->resource;
		$op       = $context->operation;

		$paths = array(
			self::LEVEL_ROLE          => sprintf( 'permissions.defaults.%s', $role ),
			self::LEVEL_ROLE_RESOURCE => sprintf( 'permissions.%s.%s.all', $resource, $role ),
			self::LEVEL_ROLE_RES_OP   => sprintf( 'permissions.%s.%s.%s', $resource, $role, $op ),
		);

		if ( $context->is_field_level() ) {
			$paths[ self::LEVEL_ROLE_RES_OP_FIELD ] = sprintf(
				'permissions.%s.%s.fields.%s.%s',
				$resource,
				$role,
				(string) $context->field,
				$op
			);
		}

		return $paths;
	}

	/**
	 * Normaliza el valor almacenado a booleano.
	 *
	 * Un valor que no sea booleano ni entero se ignora: una configuracion
	 * corrupta no debe conceder acceso por accidente.
	 *
	 * @param mixed $rule Valor almacenado.
	 * @return bool|null Null si el valor no es interpretable.
	 */
	private function normalize( $rule ): ?bool {
		if ( is_bool( $rule ) ) {
			return $rule;
		}

		if ( is_int( $rule ) ) {
			return 1 === $rule;
		}

		if ( is_string( $rule ) && in_array( $rule, array( '0', '1' ), true ) ) {
			return '1' === $rule;
		}

		return null;
	}

	/**
	 * Matriz por defecto al activar el plugin.
	 *
	 * Los recursos arrancan SIN exponer; esta matriz solo se aplica una vez
	 * el administrador expone uno.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function defaults(): array {
		return array(
			'administrator' => array(
				'read'   => true,
				'create' => true,
				'update' => true,
				'delete' => true,
				'upload' => true,
			),
			'editor'        => array(
				'read'   => true,
				'create' => true,
				'update' => true,
				'delete' => true,
				'upload' => true,
			),
			'author'        => array(
				'read'   => true,
				'create' => true,
				'update' => true,
				'delete' => true,
				'upload' => true,
			),
			'contributor'   => array(
				'read'   => true,
				'create' => true,
				'update' => true,
				'delete' => true,
				'upload' => false,
			),
			'subscriber'    => array(
				'read'   => true,
				'create' => false,
				'update' => false,
				'delete' => false,
				'upload' => false,
			),
			'anonymous'     => array(
				'read'   => true,
				'create' => false,
				'update' => false,
				'delete' => false,
				'upload' => false,
			),
		);
	}
}

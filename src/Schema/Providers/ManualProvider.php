<?php
/**
 * Nivel 4: declaraciones manuales del administrador.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Schema\Providers;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Schema\FieldDefinition;

/**
 * Lee los campos declarados a mano en el dashboard.
 *
 * Tiene la prioridad maxima: lo dijo una persona. Se conserva aunque un
 * rebuild deje de detectar el campo, porque esa discrepancia es justo la
 * senal que interesa cuando alguien renombra una clave en el codigo.
 */
final class ManualProvider implements FieldProvider {

	/**
	 * Configuracion del plugin.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Construye el proveedor.
	 *
	 * @param Config $config Configuracion.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return FieldDefinition::ORIGIN_MANUAL;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return int
	 */
	public function confidence(): int {
		return FieldDefinition::CONFIDENCE[ FieldDefinition::ORIGIN_MANUAL ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type.
	 * @return FieldDefinition[]
	 */
	public function fields_for( string $post_type ): array {
		$declared = $this->config->get( 'resources.' . $post_type . '.manual_fields', array() );

		if ( ! is_array( $declared ) ) {
			return array();
		}

		$fields = array();

		foreach ( $declared as $storage_key => $spec ) {
			if ( ! is_array( $spec ) ) {
				continue;
			}

			$key = (string) $storage_key;

			$fields[] = new FieldDefinition(
				$key,
				isset( $spec['exposed_name'] ) ? (string) $spec['exposed_name'] : $key,
				isset( $spec['type'] ) ? (string) $spec['type'] : FieldDefinition::TYPE_STRING,
				FieldDefinition::ORIGIN_MANUAL,
				$spec
			);
		}

		return $fields;
	}
}

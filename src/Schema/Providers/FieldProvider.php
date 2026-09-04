<?php
/**
 * Contrato de los proveedores de campos.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Schema\Providers;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Schema\FieldDefinition;

/**
 * Un proveedor sabe extraer definiciones de campo de una fuente concreta.
 *
 * El nucleo no conoce a ACF, JetEngine ni Meta Box: se integran por
 * adaptadores tras esta interfaz. Quitar uno no debe tocar el registro de
 * esquema.
 */
interface FieldProvider {

	/**
	 * Identificador corto del proveedor.
	 *
	 * Coincide con las constantes ORIGIN_* de FieldDefinition.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Indica si la fuente esta disponible en esta instalacion.
	 *
	 * Se comprueba con class_exists()/function_exists(), nunca con
	 * is_plugin_active(): esa funcion exige cargar wp-admin/includes/plugin.php
	 * y no esta disponible en contexto REST.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Confianza que merecen las definiciones de este proveedor.
	 *
	 * @return int De 0 a 100.
	 */
	public function confidence(): int;

	/**
	 * Extrae los campos de un post type.
	 *
	 * @param string $post_type Post type.
	 * @return FieldDefinition[]
	 */
	public function fields_for( string $post_type ): array;
}

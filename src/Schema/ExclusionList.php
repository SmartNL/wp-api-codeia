<?php
/**
 * Claves meta que nunca se exponen como campos.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Decide que claves de wp_postmeta son ruido y cuales son campos reales.
 *
 * IMPORTANTE: no se filtra por el prefijo "_". La recomendacion habitual de
 * "ignorar las claves que empiezan por guion bajo porque son privadas" es
 * exactamente lo que NO hay que hacer aqui: el guion bajo marca la meta como
 * protegida, que la oculta de la caja "Campos personalizados" del editor, y
 * los plugins serios lo usan precisamente para sus campos reales.
 *
 * En la instalacion de referencia, flavor-real-estate nombra asi sus 28
 * campos utiles (_property_price, _property_rooms, _property_agent_id...).
 * Un filtro por prefijo los descartaria todos.
 *
 * Por eso se usa una lista de exclusion explicita.
 */
final class ExclusionList {

	/**
	 * Claves internas del nucleo, coincidencia exacta.
	 */
	private const CORE_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_wp_page_template',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_desired_post_slug',
		'_thumbnail_id',
		'_pingme',
		'_encloseme',
		'_menu_item_type',
		'_menu_item_menu_item_parent',
		'_menu_item_object_id',
		'_menu_item_object',
		'_menu_item_target',
		'_menu_item_classes',
		'_menu_item_xfn',
		'_menu_item_url',
	);

	/**
	 * Prefijos de claves internas.
	 */
	private const CORE_PREFIXES = array(
		'_wp_attach',
		'_wp_trash_meta',
		'_oembed_',
		'_transient_',
		'_edit_',
	);

	/**
	 * Claves adicionales configuradas por el administrador.
	 *
	 * @var string[]
	 */
	private array $custom;

	/**
	 * Motivo del ultimo descarte, para el panel de estado.
	 *
	 * @var array<string, string>
	 */
	private array $reasons = array();

	/**
	 * Construye la lista.
	 *
	 * @param string[] $custom Claves extra a excluir.
	 */
	public function __construct( array $custom = array() ) {
		$this->custom = array_values( array_filter( array_map( 'strval', $custom ) ) );
	}

	/**
	 * Indica si una clave debe descartarse.
	 *
	 * @param string               $key         Clave meta.
	 * @param array<string, mixed> $all_keys    Todas las claves detectadas, para
	 *                                          reconocer punteros de ACF.
	 * @param string|null          $sample_value Un valor de muestra, si se tiene.
	 * @return bool
	 */
	public function excludes( string $key, array $all_keys = array(), ?string $sample_value = null ): bool {
		if ( in_array( $key, self::CORE_KEYS, true ) ) {
			$this->reasons[ $key ] = 'clave interna del nucleo';
			return true;
		}

		foreach ( self::CORE_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				$this->reasons[ $key ] = sprintf( 'prefijo interno "%s"', $prefix );
				return true;
			}
		}

		if ( in_array( $key, $this->custom, true ) ) {
			$this->reasons[ $key ] = 'excluida en la configuracion';
			return true;
		}

		if ( $this->is_acf_pointer( $key, $all_keys, $sample_value ) ) {
			$this->reasons[ $key ] = 'puntero de ACF al campo ' . ltrim( $key, '_' );
			return true;
		}

		return false;
	}

	/**
	 * Reconoce el puntero que ACF guarda junto a cada valor.
	 *
	 * ACF escribe dos entradas por campo: "precio" con el valor y "_precio"
	 * con una referencia del tipo "field_6a1f2c". Sin este reconocimiento, el
	 * muestreo de base de datos veria "_precio" como un campo mas.
	 *
	 * @param string               $key          Clave a evaluar.
	 * @param array<string, mixed> $all_keys     Todas las claves detectadas.
	 * @param string|null          $sample_value Valor de muestra.
	 * @return bool
	 */
	private function is_acf_pointer( string $key, array $all_keys, ?string $sample_value ): bool {
		if ( ! str_starts_with( $key, '_' ) ) {
			return false;
		}

		$sibling = substr( $key, 1 );

		if ( '' === $sibling || ! array_key_exists( $sibling, $all_keys ) ) {
			return false;
		}

		// Sin valor de muestra no se puede confirmar: mejor no descartar.
		if ( null === $sample_value ) {
			return false;
		}

		return 1 === preg_match( '/^field_[0-9a-f]+$/i', $sample_value );
	}

	/**
	 * Motivos de descarte acumulados.
	 *
	 * Se muestran en el panel de estado: "por que no aparece mi campo" sera
	 * la pregunta mas frecuente que genere este plugin.
	 *
	 * @return array<string, string>
	 */
	public function reasons(): array {
		return $this->reasons;
	}

	/**
	 * Colapsa las claves de repetidor de ACF en un solo campo.
	 *
	 * Los repetidores generan claves dinamicas (galeria_0_imagen,
	 * galeria_1_imagen). Listarlas una por indice llenaria el catalogo de
	 * ruido, asi que se reduce el patron nombre_<n>_sub a nombre_sub.
	 *
	 * @param string $key Clave a normalizar.
	 * @return string Clave colapsada, o la original si no es de repetidor.
	 */
	public static function collapse_repeater( string $key ): string {
		$collapsed = preg_replace( '/_\d+_/', '_', $key );

		return is_string( $collapsed ) ? $collapsed : $key;
	}

	/**
	 * Indica si la clave sigue el patron de un repetidor indexado.
	 *
	 * @param string $key Clave.
	 * @return bool
	 */
	public static function is_repeater_item( string $key ): bool {
		return 1 === preg_match( '/_\d+_/', $key );
	}
}

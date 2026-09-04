<?php
/**
 * Exportacion e importacion de configuracion.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Admin;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Config;

/**
 * Serializa la configuracion en un formato portable entre entornos.
 *
 * NUNCA incluye secretos. Un fichero de configuracion acaba en un
 * repositorio, en un correo o en un ticket de soporte; las claves se
 * regeneran en destino.
 */
final class ConfigExporter {

	/**
	 * Identificador del formato.
	 */
	public const FORMAT = 'codeia-config';

	/**
	 * Version del FORMATO, independiente de la del plugin.
	 *
	 * Describe la forma del fichero, no del producto: permite importar en una
	 * version distinta del plugin mientras el formato sea compatible.
	 */
	public const FORMAT_VERSION = 1;

	/**
	 * Claves que nunca se exportan.
	 */
	private const SECRETS = array( 'auth.providers.jwt.secret', 'secret', 'token' );

	/**
	 * Configuracion.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Construye el exportador.
	 *
	 * @param Config $config Configuracion.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Compone el documento exportable.
	 *
	 * @return array<string, mixed>
	 */
	public function export(): array {
		$payload = $this->strip_secrets( $this->config->all() );

		$document = array(
			'format'         => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'source'         => array(
				'site_url'       => home_url(),
				'plugin_version' => CODEIA_VERSION,
				'wp_version'     => get_bloginfo( 'version' ),
				'post_types'     => array_keys( (array) $this->config->get( 'resources', array() ) ),
			),
			'config'         => $payload,
		);

		$document['checksum'] = 'sha256:' . hash( 'sha256', (string) wp_json_encode( $payload ) );

		return $document;
	}

	/**
	 * Valida un documento entrante.
	 *
	 * @param mixed $document Documento.
	 * @return array<string, mixed> Resultado con errores y avisos.
	 */
	public function validate( $document ): array {
		$errors   = array();
		$warnings = array();

		if ( ! is_array( $document ) ) {
			return array(
				'errors'   => array( 'El fichero no es un documento valido.' ),
				'warnings' => array(),
			);
		}

		if ( ( $document['format'] ?? '' ) !== self::FORMAT ) {
			$errors[] = 'El fichero no es una exportacion de este plugin.';
		}

		if ( (int) ( $document['format_version'] ?? 0 ) > self::FORMAT_VERSION ) {
			$errors[] = 'El fichero usa un formato mas reciente que el que entiende esta version.';
		}

		if ( ! isset( $document['config'] ) || ! is_array( $document['config'] ) ) {
			$errors[] = 'El documento no contiene configuracion.';
		}

		if ( array() === $errors && isset( $document['checksum'] ) ) {
			$expected = 'sha256:' . hash( 'sha256', (string) wp_json_encode( $document['config'] ) );

			if ( $expected !== $document['checksum'] ) {
				// Detecta corrupcion, no manipulacion: quien puede editar el
				// fichero puede recalcular el checksum. Su funcion es evitar
				// importar un JSON truncado.
				$warnings[] = 'El checksum no coincide: el fichero puede estar corrupto.';
			}
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Compara el documento con el entorno de destino.
	 *
	 * Sin esta comparacion, importar la configuracion de produccion en un
	 * entorno donde falta un plugin dejaria referencias a recursos
	 * inexistentes y una configuracion silenciosamente rota.
	 *
	 * @param array<string, mixed> $document Documento validado.
	 * @return array<string, string[]>
	 */
	public function compare_environment( array $document ): array {
		$config    = is_array( $document['config'] ?? null ) ? $document['config'] : array();
		$resources = is_array( $config['resources'] ?? null ) ? $config['resources'] : array();

		$missing_types = array();

		foreach ( array_keys( $resources ) as $post_type ) {
			if ( null === get_post_type_object( (string) $post_type ) ) {
				$missing_types[] = (string) $post_type;
			}
		}

		$roles         = array_keys( wp_roles()->roles );
		$missing_roles = array();

		foreach ( (array) ( $config['permissions'] ?? array() ) as $by_role ) {
			if ( ! is_array( $by_role ) ) {
				continue;
			}

			foreach ( array_keys( $by_role ) as $role ) {
				if ( 'anonymous' !== $role && ! in_array( (string) $role, $roles, true ) ) {
					$missing_roles[] = (string) $role;
				}
			}
		}

		return array(
			'missing_post_types' => array_values( array_unique( $missing_types ) ),
			'missing_roles'      => array_values( array_unique( $missing_roles ) ),
		);
	}

	/**
	 * Elimina los secretos de un arbol de configuracion.
	 *
	 * @param array<string, mixed> $data Configuracion.
	 * @return array<string, mixed>
	 */
	private function strip_secrets( array $data ): array {
		$clean = array();

		foreach ( $data as $key => $value ) {
			if ( $this->is_secret( (string) $key ) ) {
				continue;
			}

			$clean[ $key ] = is_array( $value ) ? $this->strip_secrets( $value ) : $value;
		}

		return $clean;
	}

	/**
	 * Indica si una clave contiene un secreto.
	 *
	 * @param string $key Clave.
	 * @return bool
	 */
	private function is_secret( string $key ): bool {
		$needle = strtolower( $key );

		foreach ( self::SECRETS as $secret ) {
			if ( str_contains( $needle, $secret ) ) {
				return true;
			}
		}

		return false;
	}
}

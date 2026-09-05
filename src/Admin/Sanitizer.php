<?php
/**
 * Unica puerta de escritura de la configuracion.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Admin;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\Logger;

/**
 * Fusiona, valida y limpia cada guardado de configuracion.
 *
 * Con toda la configuracion en una opcion, cada guardado parcial recibe un
 * fragmento y debe fusionarlo con lo existente sin perder el resto.
 *
 * Las claves desconocidas se DESCARTAN, nunca se conservan: mantenerlas
 * convierte la opcion en un vertedero y abre la puerta a inyectar datos que
 * un isset() futuro interprete.
 */
final class Sanitizer {

	/**
	 * Configuracion actual.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Registro de eventos.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Construye el saneador.
	 *
	 * @param Config $config Configuracion.
	 * @param Logger $logger Registro.
	 */
	public function __construct( Config $config, Logger $logger ) {
		$this->config = $config;
		$this->logger = $logger;
	}

	/**
	 * Sanea un fragmento entrante.
	 *
	 * @param mixed $input Fragmento recibido del formulario.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			return $this->config->all();
		}

		$clean = $this->config->sanitize( $input );

		$clean['resources']   = $this->sanitize_resources( $clean['resources'] );
		$clean['permissions'] = $this->sanitize_permissions( $clean['permissions'] );
		$clean['auth']        = $this->sanitize_auth( $clean['auth'] ?? array() );
		$clean['logging']     = $this->sanitize_logging( $clean['logging'] ?? array() );

		$this->logger->info(
			'Configuracion actualizada.',
			array(
				'keys'    => array_keys( $input ),
				'user_id' => get_current_user_id(),
			),
			'admin'
		);

		return $clean;
	}

	/**
	 * Sanea la rama de autenticacion.
	 *
	 * Solo sobreviven los cuatro proveedores conocidos, y siempre como
	 * booleano. Una clave inventada aqui no haria nada, pero quedaria
	 * almacenada dando la impresion de que si.
	 *
	 * @param mixed $auth Rama entrante.
	 * @return array<string, mixed>
	 */
	public function sanitize_auth( $auth ): array {
		$known     = array( 'jwt', 'app_password', 'api_key', 'user_token' );
		$incoming  = is_array( $auth ) && is_array( $auth['providers'] ?? null ) ? $auth['providers'] : array();
		$providers = array();

		foreach ( $known as $id ) {
			$value = $incoming[ $id ] ?? false;

			if ( is_array( $value ) ) {
				$value = ! empty( $value['enabled'] );
			}

			$providers[ $id ] = (bool) $value;
		}

		return array( 'providers' => $providers );
	}

	/**
	 * Sanea la rama de registro.
	 *
	 * La retencion se acota por arriba: un valor enorme convierte la tabla de
	 * logs en el objeto mas grande de la base de datos sin que nadie lo note
	 * hasta que el disco se llena.
	 *
	 * @param mixed $logging Rama entrante.
	 * @return array<string, mixed>
	 */
	public function sanitize_logging( $logging ): array {
		$levels = array( 'debug', 'info', 'warning', 'error' );
		$level  = is_array( $logging ) ? sanitize_key( (string) ( $logging['level'] ?? 'info' ) ) : 'info';
		$days   = is_array( $logging ) ? absint( $logging['retention_days'] ?? 30 ) : 30;

		return array(
			'level'          => in_array( $level, $levels, true ) ? $level : 'info',
			'retention_days' => max( 1, min( 365, $days ) ),
		);
	}

	/**
	 * Sanea la rama de recursos.
	 *
	 * Solo sobreviven los post types que existen realmente: importar una
	 * configuracion de otro entorno no debe dejar referencias a recursos
	 * inexistentes.
	 *
	 * @param mixed $resources Rama entrante.
	 * @return array<string, mixed>
	 */
	public function sanitize_resources( $resources ): array {
		if ( ! is_array( $resources ) ) {
			return array();
		}

		$clean = array();

		foreach ( $resources as $post_type => $settings ) {
			$post_type = sanitize_key( (string) $post_type );

			if ( '' === $post_type || ! is_array( $settings ) ) {
				continue;
			}

			if ( null === get_post_type_object( $post_type ) ) {
				continue;
			}

			$clean[ $post_type ] = array(
				'enabled'    => ! empty( $settings['enabled'] ),
				'operations' => $this->sanitize_operations( $settings['operations'] ?? array() ),
				'fields'     => is_array( $settings['fields'] ?? null ) ? $settings['fields'] : array(),
			);
		}

		return $clean;
	}

	/**
	 * Sanea una lista de operaciones.
	 *
	 * @param mixed $operations Operaciones entrantes.
	 * @return string[]
	 */
	private function sanitize_operations( $operations ): array {
		if ( ! is_array( $operations ) ) {
			return array();
		}

		$valid = array( 'read', 'create', 'update', 'delete', 'upload' );

		return array_values(
			array_intersect( array_map( 'sanitize_key', array_map( 'strval', $operations ) ), $valid )
		);
	}

	/**
	 * Sanea la matriz de permisos.
	 *
	 * Solo sobreviven los roles que existen en la instalacion.
	 *
	 * @param mixed $permissions Rama entrante.
	 * @return array<string, mixed>
	 */
	public function sanitize_permissions( $permissions ): array {
		if ( ! is_array( $permissions ) ) {
			return array();
		}

		$roles = array_merge( array_keys( wp_roles()->roles ), array( 'anonymous' ) );
		$clean = array();

		foreach ( $permissions as $resource => $by_role ) {
			$resource = sanitize_key( (string) $resource );

			if ( '' === $resource || ! is_array( $by_role ) ) {
				continue;
			}

			/*
			 * El nivel 1 de la matriz no es un recurso: es el mapa
			 * rol => booleano que actua de valor por defecto. Su forma es
			 * distinta de la de los demas nodos y sin este caso aparte se
			 * descartaria entero, dejando el nivel 1 inalcanzable desde el
			 * panel.
			 */
			if ( 'defaults' === $resource ) {
				foreach ( $by_role as $role => $allowed ) {
					$role = sanitize_key( (string) $role );

					if ( in_array( $role, $roles, true ) ) {
						$clean['defaults'][ $role ] = (bool) $allowed;
					}
				}

				continue;
			}

			foreach ( $by_role as $role => $rules ) {
				$role = sanitize_key( (string) $role );

				if ( ! in_array( $role, $roles, true ) || ! is_array( $rules ) ) {
					continue;
				}

				$clean[ $resource ][ $role ] = $this->sanitize_rules( $rules );
			}
		}

		return $clean;
	}

	/**
	 * Sanea las reglas de un rol.
	 *
	 * @param array<string, mixed> $rules Reglas.
	 * @return array<string, mixed>
	 */
	private function sanitize_rules( array $rules ): array {
		$clean = array();

		foreach ( $rules as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( 'fields' === $key && is_array( $value ) ) {
				foreach ( $value as $field => $field_rules ) {
					if ( is_array( $field_rules ) ) {
						$clean['fields'][ sanitize_key( (string) $field ) ] = array_map(
							static function ( $flag ): bool {
								return (bool) $flag;
							},
							$field_rules
						);
					}
				}

				continue;
			}

			$clean[ $key ] = (bool) $value;
		}

		return $clean;
	}
}

<?php
/**
 * Esquemas de seguridad de OpenAPI.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\OpenApi;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Config;

/**
 * Declara solo los metodos de autenticacion ACTIVOS.
 *
 * Documentar JWT cuando esta desactivado invita a integrar contra algo que
 * devolvera 401.
 */
final class SecuritySchemeBuilder {

	/**
	 * Configuracion.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Construye el generador.
	 *
	 * @param Config $config Configuracion.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Compone la seccion securitySchemes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function schemes(): array {
		$providers = $this->config->get( 'auth.providers', array() );
		$providers = is_array( $providers ) ? $providers : array();
		$schemes   = array();

		if ( $this->is_enabled( $providers, 'jwt' ) ) {
			$schemes['bearerAuth'] = array(
				'type'         => 'http',
				'scheme'       => 'bearer',
				'bearerFormat' => 'JWT',
			);
		}

		if ( $this->is_enabled( $providers, 'app_password' ) ) {
			$schemes['appPassword'] = array(
				'type'   => 'http',
				'scheme' => 'basic',
			);
		}

		if ( $this->is_enabled( $providers, 'api_key' ) ) {
			$schemes['apiKey'] = array(
				'type' => 'apiKey',
				'in'   => 'header',
				'name' => 'X-Codeia-Key',
			);
		}

		if ( $this->is_enabled( $providers, 'user_token' ) ) {
			$schemes['userToken'] = array(
				'type'   => 'http',
				'scheme' => 'bearer',
			);
		}

		return $schemes;
	}

	/**
	 * Requisito de seguridad de una operacion.
	 *
	 * El objeto vacio significa "tambien sin autenticar", que es informacion
	 * relevante para quien integra.
	 *
	 * @param bool $allows_anonymous Si la operacion admite acceso anonimo.
	 * @return array<int, array<string, array<int, string>>>
	 */
	public function requirement( bool $allows_anonymous ): array {
		$names = array_keys( $this->schemes() );

		if ( array() === $names ) {
			return array();
		}

		$requirement = array();

		if ( $allows_anonymous ) {
			$requirement[] = array();
		}

		foreach ( $names as $name ) {
			$requirement[] = array( $name => array() );
		}

		return $requirement;
	}

	/**
	 * Indica si un proveedor esta habilitado.
	 *
	 * @param array<string, mixed> $providers Proveedores configurados.
	 * @param string               $id        Identificador.
	 * @return bool
	 */
	private function is_enabled( array $providers, string $id ): bool {
		if ( ! isset( $providers[ $id ] ) ) {
			return false;
		}

		$settings = $providers[ $id ];

		if ( is_bool( $settings ) ) {
			return $settings;
		}

		return is_array( $settings ) && ! empty( $settings['enabled'] );
	}
}

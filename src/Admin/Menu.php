<?php
/**
 * Menu del dashboard.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registra las pantallas del plugin.
 *
 * Todas exigen manage_options. No se contempla un rol intermedio de gestor de
 * API: quien configura que datos salen del sitio y con que permisos esta
 * tomando decisiones de administracion plenas.
 */
final class Menu {

	/**
	 * Slug del menu principal.
	 */
	public const SLUG = 'codeia-api';

	/**
	 * Capability exigida en todas las pantallas.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Pantallas registradas y su hook_suffix.
	 *
	 * @var string[]
	 */
	private array $screens = array();

	/**
	 * Definicion de las paginas.
	 *
	 * @return array<string, string>
	 */
	public static function pages(): array {
		return array(
			self::SLUG                  => 'Estado',
			self::SLUG . '-resources'   => 'Recursos',
			self::SLUG . '-permissions' => 'Permisos',
			self::SLUG . '-auth'        => 'Autenticacion',
			self::SLUG . '-docs'        => 'Documentacion',
			self::SLUG . '-logs'        => 'Registros',
			self::SLUG . '-tools'       => 'Herramientas',
		);
	}

	/**
	 * Engancha el menu.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register' ) );
	}

	/**
	 * Registra el menu y sus submenus.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->screens[] = (string) add_menu_page(
			__( 'WP API Codeia', 'wp-api-codeia' ),
			__( 'Codeia API', 'wp-api-codeia' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-rest-api',
			80
		);

		foreach ( self::pages() as $slug => $title ) {
			if ( self::SLUG === $slug ) {
				continue;
			}

			$this->screens[] = (string) add_submenu_page(
				self::SLUG,
				$title,
				$title,
				self::CAPABILITY,
				$slug,
				array( $this, 'render' )
			);
		}
	}

	/**
	 * Renderiza el contenedor donde monta React.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'No tienes permiso para acceder a esta pagina.', 'wp-api-codeia' ) );
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo se lee para elegir que pantalla monta el cliente.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : self::SLUG;

		printf(
			'<div class="wrap"><div id="codeia-admin-root" data-page="%s"></div></div>',
			esc_attr( $page )
		);
	}

	/**
	 * Identificadores de pantalla registrados.
	 *
	 * @return string[]
	 */
	public function screens(): array {
		return array_values( array_filter( $this->screens ) );
	}
}

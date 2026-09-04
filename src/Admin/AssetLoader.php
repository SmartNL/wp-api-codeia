<?php
/**
 * Encolado de los assets del dashboard.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Admin;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Config;

/**
 * Carga el bundle de React solo en las pantallas del plugin.
 *
 * React NO se empaqueta: WordPress ya sirve wp-element, su envoltorio sobre
 * React. Duplicarlo anadiria unos 130 KB y arriesgaria dos instancias
 * distintas en la misma pagina, con los errores de contexto y hooks que eso
 * provoca cuando otro plugin monta su propia interfaz.
 */
final class AssetLoader {

	/**
	 * Handle del script principal.
	 */
	public const HANDLE = 'codeia-admin';

	/**
	 * Configuracion.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Pantallas del plugin.
	 *
	 * @var string[]
	 */
	private array $screens;

	/**
	 * Construye el cargador.
	 *
	 * @param Config   $config  Configuracion.
	 * @param string[] $screens Identificadores de pantalla.
	 */
	public function __construct( Config $config, array $screens = array() ) {
		$this->config  = $config;
		$this->screens = $screens;
	}

	/**
	 * Registra el hook de encolado.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Encola los assets si la pantalla es del plugin.
	 *
	 * Se comprueba el screen_id en lugar de encolar en todo el admin:
	 * cargar el dashboard en cada pantalla del escritorio es lo que hace que
	 * un plugin tenga fama de pesado.
	 *
	 * @param string $hook_suffix Sufijo de la pantalla actual.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! $this->is_plugin_screen( $hook_suffix ) ) {
			return;
		}

		$script = CODEIA_PLUGIN_DIR . 'assets/admin/index.js';

		if ( ! file_exists( $script ) ) {
			// El bundle se versiona, pero si falta no se rompe el admin.
			return;
		}

		wp_enqueue_script(
			self::HANDLE,
			CODEIA_PLUGIN_URL . 'assets/admin/index.js',
			array( 'wp-element', 'wp-components', 'wp-i18n' ),
			CODEIA_VERSION,
			true
		);

		// El CSS solo existe si el bundle lo genero; encolarlo a ciegas
		// produciria un 404 en cada carga de la pantalla.
		if ( file_exists( CODEIA_PLUGIN_DIR . 'assets/admin/index.css' ) ) {
			wp_enqueue_style(
				self::HANDLE,
				CODEIA_PLUGIN_URL . 'assets/admin/index.css',
				array( 'wp-components' ),
				CODEIA_VERSION
			);
		}

		/*
		 * Posicion 'before': con React montandose al cargar, inyectar la
		 * configuracion despues dejaria la aplicacion sin root ni nonce en su
		 * primer render.
		 */
		wp_add_inline_script(
			self::HANDLE,
			sprintf( 'window.codeiaAdmin = %s;', (string) wp_json_encode( $this->boot_data() ) ),
			'before'
		);
	}

	/**
	 * Datos de arranque del cliente.
	 *
	 * @return array<string, mixed>
	 */
	public function boot_data(): array {
		return array(
			'root'      => esc_url_raw(
				rest_url(
					sprintf(
						'%s/%s/admin/',
						(string) $this->config->get( 'namespace', 'codeia' ),
						(string) $this->config->get( 'api_version', 'v1' )
					)
				)
			),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'version'   => CODEIA_VERSION,
			'namespace' => (string) $this->config->get( 'namespace', 'codeia' ),
		);
	}

	/**
	 * Indica si la pantalla pertenece al plugin.
	 *
	 * @param string $hook_suffix Sufijo de la pantalla.
	 * @return bool
	 */
	public function is_plugin_screen( string $hook_suffix ): bool {
		if ( array() !== $this->screens ) {
			return in_array( $hook_suffix, $this->screens, true );
		}

		return str_contains( $hook_suffix, 'codeia' );
	}
}

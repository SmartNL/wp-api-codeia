<?php
/**
 * Alias de rutas en la raiz del dominio.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Modules;

defined( 'ABSPATH' ) || exit;

use WP;
use WP_REST_Request;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Rewrite\CollisionDetector;

/**
 * Sirve la API tambien fuera de /wp-json/, via rewrite rules.
 *
 * Desactivado por defecto: la API funciona integramente sin el. Es una capa
 * de presentacion de URLs, no un requisito funcional.
 *
 * El alias reenvia al MISMO WP_REST_Server, no reimplementa el despacho:
 * cualquier logica de autenticacion, permisos o filtros se aplica igual.
 */
final class RewriteModule {

	/**
	 * Opcion que marca que hay que regenerar las reglas.
	 */
	public const FLUSH_FLAG = 'codeia_flush_needed';

	/**
	 * Configuracion.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Detector de colisiones.
	 *
	 * @var CollisionDetector
	 */
	private CollisionDetector $collisions;

	/**
	 * Construye el modulo.
	 *
	 * @param Config            $config     Configuracion.
	 * @param CollisionDetector $collisions Detector de colisiones.
	 */
	public function __construct( Config $config, CollisionDetector $collisions ) {
		$this->config     = $config;
		$this->collisions = $collisions;
	}

	/**
	 * Engancha el modulo.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'add_rules' ), 20 );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'parse_request', array( $this, 'forward' ) );

		// El flush va DIFERIDO y a prioridad tardia: en init corriente se
		// ejecutaria en cada peticion del sitio, regenerando cientos de
		// expresiones regulares y escribiendo en la base de datos.
		add_action( 'init', array( $this, 'maybe_flush' ), 99 );
	}

	/**
	 * Registra la regla del alias.
	 *
	 * @return void
	 */
	public function add_rules(): void {
		$prefix = $this->prefix();

		if ( '' === $prefix ) {
			return;
		}

		// 'top' es obligatorio: las reglas de paginas capturan casi
		// cualquier cosa, y registrada abajo esta nunca se alcanzaria.
		add_rewrite_rule(
			'^' . preg_quote( $prefix, '/' ) . '/(v\d+)/(.+?)/?$',
			'index.php?codeia_api=1&codeia_version=$matches[1]&codeia_route=$matches[2]',
			'top'
		);
	}

	/**
	 * Declara las variables de consulta.
	 *
	 * Sin declararlas, WordPress las descarta.
	 *
	 * @param string[] $vars Variables existentes.
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		return array_merge( $vars, array( 'codeia_api', 'codeia_version', 'codeia_route' ) );
	}

	/**
	 * Reenvia la peticion al REST server.
	 *
	 * @param WP $wp Entorno de la peticion.
	 * @return void
	 */
	public function forward( WP $wp ): void {
		if ( empty( $wp->query_vars['codeia_api'] ) ) {
			return;
		}

		/*
		 * REST_REQUEST se define ANTES de despachar. Muchos plugins y el
		 * propio nucleo la consultan para ajustar su comportamiento;
		 * definirla tarde produce diferencias sutiles entre la ruta canonica
		 * y el alias, que es justo lo que hay que evitar.
		 */
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}

		$route = sprintf(
			'/%s/%s/%s',
			(string) $this->config->get( 'namespace', 'codeia' ),
			(string) $wp->query_vars['codeia_version'],
			(string) $wp->query_vars['codeia_route']
		);

		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$request = new WP_REST_Request( $method, $route );

		$request->set_query_params( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$request->set_body( (string) file_get_contents( 'php://input' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$request->set_headers( $this->collect_headers() );

		$server   = rest_get_server();
		$response = $server->dispatch( $request );

		status_header( $response->get_status() );
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'X-Content-Type-Options: nosniff' );

		foreach ( $response->get_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}

		echo wp_json_encode( $server->response_to_data( $response, false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		// Sin exit, WordPress continuaria y anadiria la plantilla del tema
		// detras del JSON.
		exit;
	}

	/**
	 * Recoge las cabeceras de la peticion.
	 *
	 * En algunos servidores Authorization no llega a PHP como HTTP_*, asi que
	 * tambien se busca en la variable de redireccion. Sin ella, el alias no
	 * autenticaria y la canonica si: las dos rutas deben comportarse igual.
	 *
	 * @return array<string, string>
	 */
	private function collect_headers(): array {
		$headers = array();

		foreach ( $_SERVER as $key => $value ) {
			if ( ! is_string( $key ) || ! str_starts_with( $key, 'HTTP_' ) ) {
				continue;
			}

			$name             = str_replace( '_', '-', substr( $key, 5 ) );
			$headers[ $name ] = is_string( $value ) ? $value : '';
		}

		if ( ! isset( $headers['AUTHORIZATION'] ) && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$headers['AUTHORIZATION'] = sanitize_text_field(
				wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] )
			);
		}

		return $headers;
	}

	/**
	 * Regenera las reglas si hay una marca pendiente.
	 *
	 * Diferirlo garantiza que el flush ocurre DESPUES de que todos los
	 * plugins hayan registrado sus reglas. Hacerlo al guardar la
	 * configuracion capturaria un estado parcial.
	 *
	 * @return void
	 */
	public function maybe_flush(): void {
		if ( ! get_option( self::FLUSH_FLAG ) ) {
			return;
		}

		// false: no reescribe .htaccess, innecesario para reglas internas y
		// que exige permisos de escritura que muchos entornos no tienen.
		flush_rewrite_rules( false );
		delete_option( self::FLUSH_FLAG );
	}

	/**
	 * Marca que hay que regenerar las reglas.
	 *
	 * @return void
	 */
	public static function request_flush(): void {
		update_option( self::FLUSH_FLAG, true, false );
	}

	/**
	 * Prefijo del alias, o cadena vacia si esta desactivado o hay colision.
	 *
	 * @return string
	 */
	public function prefix(): string {
		if ( ! $this->config->get( 'modules.rewrite', false ) ) {
			return '';
		}

		$prefix = (string) $this->config->get( 'namespace', 'codeia' );

		return $this->collisions->is_available( $prefix ) ? $prefix : '';
	}

	/**
	 * Indica si el alias esta operativo.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return '' !== $this->prefix();
	}
}

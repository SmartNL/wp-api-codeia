<?php
/**
 * Endpoints de emision y renovacion de credenciales.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Api;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpApi\Codeia\Auth\Jwt\JwtCodec;
use WpApi\Codeia\Auth\OpaqueToken;
use WpApi\Codeia\Auth\RefreshTokenService;
use WpApi\Codeia\Auth\TokenVersion;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\Logger;

/**
 * Sirve /auth/token y /auth/refresh.
 */
final class AuthController {

	/**
	 * Vigencia por defecto del access token, en segundos.
	 */
	public const ACCESS_TTL = 900;

	/**
	 * Codec de tokens JWT.
	 *
	 * @var JwtCodec
	 */
	private JwtCodec $codec;

	/**
	 * Servicio de tokens de refresco.
	 *
	 * @var RefreshTokenService
	 */
	private RefreshTokenService $refresh;

	/**
	 * Versiones de token por usuario.
	 *
	 * @var TokenVersion
	 */
	private TokenVersion $versions;

	/**
	 * Configuracion del plugin.
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
	 * Construye el controlador.
	 *
	 * @param JwtCodec            $codec    Codec de tokens.
	 * @param RefreshTokenService $refresh  Servicio de refresco.
	 * @param TokenVersion        $versions Versiones de token.
	 * @param Config              $config   Configuracion.
	 * @param Logger              $logger   Registro de eventos.
	 */
	public function __construct(
		JwtCodec $codec,
		RefreshTokenService $refresh,
		TokenVersion $versions,
		Config $config,
		Logger $logger
	) {
		$this->codec    = $codec;
		$this->refresh  = $refresh;
		$this->versions = $versions;
		$this->config   = $config;
		$this->logger   = $logger;
	}

	/**
	 * Registra las rutas.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$namespace = sprintf(
			'%s/%s',
			(string) $this->config->get( 'namespace', 'codeia' ),
			(string) $this->config->get( 'api_version', 'v1' )
		);

		register_rest_route(
			$namespace,
			'/auth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'issue_token' ),
				'permission_callback' => array( $this, 'transport_is_secure' ),
				'args'                => array(
					'username' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_user',
					),
					'password' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			$namespace,
			'/auth/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'refresh_token' ),
				'permission_callback' => array( $this, 'transport_is_secure' ),
				'args'                => array(
					'refresh_token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Unica puerta de estos endpoints: exigir transporte cifrado.
	 *
	 * Son publicos por necesidad —no se puede pedir autenticacion para
	 * obtener autenticacion— pero eso no los deja sin permission_callback.
	 * Sobre HTTP cualquier credencial viaja en claro, asi que la emision se
	 * RECHAZA, no solo se advierte.
	 *
	 * La constante de escape existe para entornos locales como Local, donde
	 * no hay HTTPS; nunca debe llegar a produccion.
	 *
	 * @return bool|WP_Error
	 */
	public function transport_is_secure() {
		if ( is_ssl() ) {
			return true;
		}

		if ( defined( 'CODEIA_ALLOW_INSECURE_AUTH' ) && CODEIA_ALLOW_INSECURE_AUTH ) {
			return true;
		}

		return new WP_Error(
			'codeia_insecure_transport',
			__( 'La emision de credenciales requiere HTTPS.', 'wp-api-codeia' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Emite un par access + refresh a partir de usuario y contrasena.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return WP_REST_Response|WP_Error
	 */
	public function issue_token( WP_REST_Request $request ) {
		$username = (string) $request->get_param( 'username' );
		$password = (string) $request->get_param( 'password' );

		$user = wp_authenticate( $username, $password );

		if ( is_wp_error( $user ) || ! $user instanceof \WP_User ) {
			$this->logger->info(
				'Intento de autenticacion fallido.',
				array( 'username' => $username ),
				'auth'
			);

			/*
			 * El mensaje es IDENTICO para usuario inexistente y contrasena
			 * incorrecta. Distinguirlos convertiria el endpoint en un oraculo
			 * de enumeracion de usuarios.
			 */
			return new WP_Error(
				'codeia_auth_failed',
				__( 'Credenciales incorrectas.', 'wp-api-codeia' ),
				array( 'status' => 401 )
			);
		}

		return $this->token_response( $user->ID );
	}

	/**
	 * Renueva un par de tokens rotando el de refresco.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return WP_REST_Response|WP_Error
	 */
	public function refresh_token( WP_REST_Request $request ) {
		$presented = (string) $request->get_param( 'refresh_token' );
		$user_id   = $this->owner_of( $presented );

		if ( $user_id <= 0 ) {
			return $this->invalid_refresh();
		}

		$next = $this->refresh->rotate( $user_id, $presented );

		if ( null === $next ) {
			return $this->invalid_refresh();
		}

		return $this->token_response( $user_id, $next );
	}

	/**
	 * Compone la respuesta con el par de tokens.
	 *
	 * @param int         $user_id ID de usuario.
	 * @param string|null $refresh Token de refresco ya emitido, o null.
	 * @return WP_REST_Response
	 */
	private function token_response( int $user_id, ?string $refresh = null ): WP_REST_Response {
		$now = time();
		$ttl = self::ACCESS_TTL;

		$access = $this->codec->encode(
			array(
				'iss' => home_url(),
				'sub' => $user_id,
				'iat' => $now,
				'exp' => $now + $ttl,
				'jti' => OpaqueToken::generate(),
				'tv'  => $this->versions->current( $user_id ),
			)
		);

		return new WP_REST_Response(
			array(
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => $ttl,
				'refresh_token' => $refresh ?? $this->refresh->issue( $user_id ),
			),
			200
		);
	}

	/**
	 * Localiza al propietario de un token de refresco.
	 *
	 * @param string $token Token presentado.
	 * @return int ID de usuario, o 0.
	 */
	private function owner_of( string $token ): int {
		global $wpdb;

		if ( ! OpaqueToken::is_well_formed( $token ) ) {
			return 0;
		}

		$hash = OpaqueToken::hash( $token );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Busqueda por credencial; cachearla la filtraria.
		$user_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta}
                 WHERE meta_key = %s AND meta_value LIKE %s
                 LIMIT 1",
				RefreshTokenService::META_KEY,
				'%' . $wpdb->esc_like( $hash ) . '%'
			)
		);

		return null === $user_id ? 0 : (int) $user_id;
	}

	/**
	 * Error unico para cualquier fallo de refresco.
	 *
	 * @return WP_Error
	 */
	private function invalid_refresh(): WP_Error {
		return new WP_Error(
			'codeia_auth_invalid',
			__( 'La credencial no es valida.', 'wp-api-codeia' ),
			array( 'status' => 401 )
		);
	}
}

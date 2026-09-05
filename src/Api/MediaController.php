<?php
/**
 * Endpoint de subida de medios.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Api;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Media\Deduplicator;
use WpApi\Codeia\Media\ExifCleaner;
use WpApi\Codeia\Media\MimeValidator;
use WpApi\Codeia\Media\QuotaManager;
use WpApi\Codeia\Permissions\PermissionContext;
use WpApi\Codeia\Permissions\PermissionResolver;

/**
 * Endpoint propio que delega en las funciones del nucleo.
 *
 * No se subclasifica WP_REST_Attachments_Controller: su modelo de permisos
 * esta fijado a las capabilities de attachment y no admite la matriz de este
 * plugin. Pero tampoco se reimplementa el manejo de ficheros —
 * wp_handle_upload(), wp_insert_attachment() y wp_generate_attachment_metadata()
 * son codigo auditado del nucleo y se usan tal cual.
 *
 * Lo propio es la capa de politica: quien, cuanto, con que frecuencia y hacia
 * que post.
 */
final class MediaController {

	/**
	 * Resolutor de permisos.
	 *
	 * @var PermissionResolver
	 */
	private PermissionResolver $permissions;

	/**
	 * Validador de ficheros.
	 *
	 * @var MimeValidator
	 */
	private MimeValidator $mime;

	/**
	 * Gestor de cuotas.
	 *
	 * @var QuotaManager
	 */
	private QuotaManager $quotas;

	/**
	 * Deduplicador.
	 *
	 * @var Deduplicator
	 */
	private Deduplicator $dedup;

	/**
	 * Limpiador de EXIF.
	 *
	 * @var ExifCleaner
	 */
	private ExifCleaner $exif;

	/**
	 * Configuracion.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Construye el controlador.
	 *
	 * @param PermissionResolver $permissions Resolutor.
	 * @param MimeValidator      $mime        Validador.
	 * @param QuotaManager       $quotas      Cuotas.
	 * @param Deduplicator       $dedup       Deduplicador.
	 * @param ExifCleaner        $exif        Limpiador de EXIF.
	 * @param Config             $config      Configuracion.
	 */
	public function __construct(
		PermissionResolver $permissions,
		MimeValidator $mime,
		QuotaManager $quotas,
		Deduplicator $dedup,
		ExifCleaner $exif,
		Config $config
	) {
		$this->permissions = $permissions;
		$this->mime        = $mime;
		$this->quotas      = $quotas;
		$this->dedup       = $dedup;
		$this->exif        = $exif;
		$this->config      = $config;
	}

	/**
	 * Registra la ruta.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			sprintf(
				'%s/%s',
				(string) $this->config->get( 'namespace', 'codeia' ),
				(string) $this->config->get( 'api_version', 'v1' )
			),
			'/media',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload' ),
				'permission_callback' => array( $this, 'can_upload' ),
				'args'                => array(
					'post_id'      => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'set_featured' => array( 'type' => 'boolean' ),
					'alt_text'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Permiso de subida.
	 *
	 * La subida anonima es imposible y no es configurable: habilitarla
	 * convertiria el sitio en alojamiento gratuito de ficheros.
	 *
	 * @return bool|WP_Error
	 */
	public function can_upload() {
		$user_id = get_current_user_id();

		if ( 0 === $user_id ) {
			return ErrorFormatter::forbidden();
		}

		$allowed = $this->permissions->can_operate(
			$user_id,
			'attachment',
			PermissionContext::OP_UPLOAD
		);

		return $allowed ? true : ErrorFormatter::forbidden();
	}

	/**
	 * Procesa la subida.
	 *
	 * @param WP_REST_Request $request Peticion.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( WP_REST_Request $request ) {
		$files = $request->get_file_params();

		if ( ! isset( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new WP_Error(
				'codeia_no_file',
				__( 'No se ha recibido ningun fichero.', 'wp-api-codeia' ),
				array( 'status' => 400 )
			);
		}

		$file    = $files['file'];
		$user_id = get_current_user_id();

		if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error(
				'codeia_upload_error',
				__( 'La subida del fichero fallo.', 'wp-api-codeia' ),
				array( 'status' => 400 )
			);
		}

		$target = (int) ( $request['post_id'] ?? 0 );

		/*
		 * El destino se valida ANTES de escribir nada. Si se comprobase
		 * despues, un fallo de permiso dejaria el fichero ya en disco y
		 * habria que borrarlo: mejor no llegar a escribirlo.
		 */
		if ( $target > 0 ) {
			$error = $this->check_target( $target );

			if ( null !== $error ) {
				return $error;
			}
		}

		$size  = (int) ( $file['size'] ?? 0 );
		$quota = $this->quotas->consume( $user_id, $size );

		if ( ! $quota['allowed'] ) {
			return new WP_Error(
				'codeia_quota_exceeded',
				__( 'Has agotado tu cuota de subida.', 'wp-api-codeia' ),
				array(
					'status'      => 429,
					'retry_after' => $quota['retry_after'],
				)
			);
		}

		$valid = $this->mime->validate(
			(string) $file['tmp_name'],
			(string) $file['name'],
			$this->max_bytes_for( $user_id )
		);

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$hash     = $this->dedup->hash( (string) $file['tmp_name'] );
		$existing = $this->dedup->find( $hash );

		if ( $existing > 0 ) {
			return $this->respond( $existing, $target, true );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// test_form false: la validacion la hemos hecho nosotros y es mas
		// estricta que la del nucleo.
		$moved = wp_handle_upload( $file, array( 'test_form' => false ) );

		if ( ! is_array( $moved ) || isset( $moved['error'] ) ) {
			return new WP_Error(
				'codeia_upload_failed',
				__( 'No se pudo guardar el fichero.', 'wp-api-codeia' ),
				array( 'status' => 500 )
			);
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $moved['type'],
				'post_title'     => sanitize_file_name( (string) $file['name'] ),
				'post_status'    => 'inherit',
				'post_parent'    => $target,
			),
			$moved['file'],
			$target,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$attachment_id = (int) $attachment_id;
		$metadata      = wp_generate_attachment_metadata( $attachment_id, $moved['file'] );

		if ( is_array( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $this->exif->clean( $metadata ) );
		}

		$this->dedup->remember( $attachment_id, $hash );

		$alt = (string) ( $request['alt_text'] ?? '' );

		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}

		if ( $target > 0 && ! empty( $request['set_featured'] ) && post_type_supports( get_post_type( $target ), 'thumbnail' ) ) {
			set_post_thumbnail( $target, $attachment_id );
		}

		return $this->respond( $attachment_id, $target, false );
	}

	/**
	 * Valida el post destino de la asociacion.
	 *
	 * El permiso se comprueba sobre el POST DESTINO, no sobre el adjunto:
	 * adjuntar una imagen a una propiedad es una modificacion de esa
	 * propiedad, y quien no puede editarla no puede alterar su galeria. Es el
	 * punto que el controlador nativo no cubre — acepta el parametro post sin
	 * verificar edit_post sobre el.
	 *
	 * @param int $target ID del post destino.
	 * @return WP_Error|null
	 */
	private function check_target( int $target ): ?WP_Error {
		$post = get_post( $target );

		if ( ! $post instanceof \WP_Post ) {
			return ErrorFormatter::not_found();
		}

		if ( ! current_user_can( 'edit_post', $target ) ) {
			return new WP_Error(
				'codeia_target_forbidden',
				__( 'No tienes permiso para modificar el contenido de destino.', 'wp-api-codeia' ),
				array( 'status' => 403 )
			);
		}

		return null;
	}

	/**
	 * Tamano maximo admitido para un usuario.
	 *
	 * El limite efectivo es el MENOR entre el del rol y el del servidor:
	 * configurar 15 MB con upload_max_filesize = 2M produce fallos que
	 * parecen del plugin y son del servidor.
	 *
	 * @param int $user_id ID de usuario.
	 * @return int
	 */
	private function max_bytes_for( int $user_id ): int {
		$role      = $this->permissions->effective_role( $user_id );
		$by_role   = (int) $this->config->get( 'media.max_bytes.' . $role, 5 * MB_IN_BYTES );
		$by_server = (int) wp_max_upload_size();

		return max( 1, min( $by_role, $by_server ) );
	}

	/**
	 * Compone la respuesta de un adjunto.
	 *
	 * @param int  $attachment_id ID del adjunto.
	 * @param int  $target        Post destino.
	 * @param bool $deduplicated  Si se reutilizo uno existente.
	 * @return WP_REST_Response
	 */
	private function respond( int $attachment_id, int $target, bool $deduplicated ): WP_REST_Response {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		$data = array(
			'id'           => $attachment_id,
			'url'          => (string) wp_get_attachment_url( $attachment_id ),
			'mime_type'    => (string) get_post_mime_type( $attachment_id ),
			'width'        => is_array( $metadata ) ? (int) ( $metadata['width'] ?? 0 ) : 0,
			'height'       => is_array( $metadata ) ? (int) ( $metadata['height'] ?? 0 ) : 0,
			'post_id'      => $target,
			'deduplicated' => $deduplicated,
		);

		return new WP_REST_Response( $data, $deduplicated ? 200 : 201 );
	}
}

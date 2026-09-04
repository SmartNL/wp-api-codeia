<?php
/**
 * Panel de estado del entorno.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Admin;

defined( 'ABSPATH' ) || exit;

use WpApi\Codeia\Core\Cache\CacheManager;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\Logger;
use WpApi\Codeia\Schema\SchemaRegistry;

/**
 * Comprueba el entorno y devuelve un diagnostico con semaforo.
 *
 * Es la pantalla mas util en soporte. Responde de antemano a la pregunta mas
 * frecuente que generara este plugin: por que no aparece un campo.
 */
final class StatusChecker {

	public const OK      = 'ok';
	public const WARNING = 'warning';
	public const ERROR   = 'error';

	/**
	 * Gestor de cache.
	 *
	 * @var CacheManager
	 */
	private CacheManager $cache;

	/**
	 * Configuracion.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Registro de esquema.
	 *
	 * @var SchemaRegistry
	 */
	private SchemaRegistry $schema;

	/**
	 * Construye el comprobador.
	 *
	 * @param CacheManager   $cache  Gestor de cache.
	 * @param Config         $config Configuracion.
	 * @param SchemaRegistry $schema Registro de esquema.
	 */
	public function __construct( CacheManager $cache, Config $config, SchemaRegistry $schema ) {
		$this->cache  = $cache;
		$this->config = $config;
		$this->schema = $schema;
	}

	/**
	 * Ejecuta todas las comprobaciones.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function run(): array {
		return array(
			$this->check_object_cache(),
			$this->check_permalinks(),
			$this->check_ssl(),
			$this->check_php(),
			$this->check_logging(),
			$this->check_providers(),
			$this->check_upload_limits(),
		);
	}

	/**
	 * Object cache persistente.
	 *
	 * @return array<string, string>
	 */
	private function check_object_cache(): array {
		if ( $this->cache->has_persistent_object_cache() ) {
			return $this->row( 'object_cache', self::OK, 'Object cache persistente activo.' );
		}

		return $this->row(
			'object_cache',
			self::WARNING,
			'Sin object cache persistente: se usan transients. El limite de peticiones sera impreciso y con varios nodos se contara por servidor.'
		);
	}

	/**
	 * Enlaces permanentes, necesarios solo para el alias.
	 *
	 * @return array<string, string>
	 */
	private function check_permalinks(): array {
		$structure = get_option( 'permalink_structure' );

		if ( '' !== $structure ) {
			return $this->row( 'permalinks', self::OK, 'Enlaces permanentes activos.' );
		}

		if ( ! $this->config->get( 'modules.rewrite', false ) ) {
			return $this->row( 'permalinks', self::OK, 'Enlaces permanentes desactivados; el alias no esta en uso.' );
		}

		return $this->row(
			'permalinks',
			self::ERROR,
			'El alias de rutas exige enlaces permanentes activos.'
		);
	}

	/**
	 * Transporte cifrado.
	 *
	 * @return array<string, string>
	 */
	private function check_ssl(): array {
		if ( is_ssl() ) {
			return $this->row( 'ssl', self::OK, 'El sitio se sirve por HTTPS.' );
		}

		if ( defined( 'CODEIA_ALLOW_INSECURE_AUTH' ) && CODEIA_ALLOW_INSECURE_AUTH ) {
			return $this->row(
				'ssl',
				self::WARNING,
				'Sin HTTPS, con CODEIA_ALLOW_INSECURE_AUTH activa. Es aceptable en local; nunca debe llegar a produccion.'
			);
		}

		return $this->row(
			'ssl',
			self::ERROR,
			'Sin HTTPS no se emiten credenciales: viajarian en claro.'
		);
	}

	/**
	 * Version de PHP.
	 *
	 * @return array<string, string>
	 */
	private function check_php(): array {
		if ( version_compare( PHP_VERSION, '8.1', '>=' ) ) {
			return $this->row( 'php', self::OK, 'PHP ' . PHP_VERSION . '.' );
		}

		return $this->row( 'php', self::WARNING, 'PHP ' . PHP_VERSION . '. Se recomienda 8.1 o superior.' );
	}

	/**
	 * Disponibilidad del registro.
	 *
	 * @return array<string, string>
	 */
	private function check_logging(): array {
		if ( Logger::is_unavailable() ) {
			return $this->row(
				'logging',
				self::WARNING,
				'El registro no esta disponible: la tabla falta o no se puede escribir. No se estan guardando eventos.'
			);
		}

		return $this->row( 'logging', self::OK, 'Registro operativo.' );
	}

	/**
	 * Proveedores de campos detectados.
	 *
	 * @return array<string, string>
	 */
	private function check_providers(): array {
		$ids = array();

		foreach ( $this->schema->available_providers() as $provider ) {
			$ids[] = $provider->id();
		}

		if ( array() === $ids ) {
			return $this->row( 'providers', self::WARNING, 'Ningun proveedor de campos disponible.' );
		}

		return $this->row( 'providers', self::OK, 'Proveedores activos: ' . implode( ', ', $ids ) . '.' );
	}

	/**
	 * Coherencia de los limites de subida.
	 *
	 * @return array<string, string>
	 */
	private function check_upload_limits(): array {
		$server = (int) wp_max_upload_size();
		$role   = (int) $this->config->get( 'media.max_bytes.editor', 15 * MB_IN_BYTES );

		if ( $role > $server ) {
			return $this->row(
				'upload_limits',
				self::WARNING,
				sprintf(
					'El limite por rol (%1$s) supera el del servidor (%2$s). Manda el menor.',
					size_format( $role ),
					size_format( $server )
				)
			);
		}

		return $this->row( 'upload_limits', self::OK, 'Limite de subida: ' . size_format( $server ) . '.' );
	}

	/**
	 * Compone una fila del diagnostico.
	 *
	 * @param string $id      Identificador.
	 * @param string $status  Estado.
	 * @param string $message Mensaje.
	 * @return array<string, string>
	 */
	private function row( string $id, string $status, string $message ): array {
		return array(
			'id'      => $id,
			'status'  => $status,
			'message' => $message,
		);
	}
}

<?php
/**
 * Activacion y desactivacion del plugin.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Prepara y limpia el entorno del plugin.
 *
 * Los metodos son estaticos porque register_activation_hook() se resuelve
 * antes de que exista el contenedor: en ese punto no hay grafo de servicios
 * del que tirar.
 */
final class Activator {

	/**
	 * Opcion donde se guarda la version instalada.
	 */
	public const VERSION_OPTION = 'codeia_installed_version';

	/**
	 * Nombre del evento de cron que purga los logs.
	 */
	public const CRON_PURGE_LOGS = 'codeia_purge_logs';

	/**
	 * Se ejecuta al activar el plugin.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
		self::seed_config();
		self::schedule_events();

		update_option( self::VERSION_OPTION, CODEIA_VERSION, false );
	}

	/**
	 * Se ejecuta al desactivar el plugin.
	 *
	 * No borra datos: eso es competencia de uninstall.php. Desactivar y volver
	 * a activar no debe costarle al administrador su configuracion.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		self::unschedule_events();

		// El alias de rutas puede haber registrado reglas; al desactivar hay
		// que limpiarlas o quedarian apuntando a un plugin que ya no responde.
		flush_rewrite_rules( false );
	}

	/**
	 * Crea las tablas propias.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = Logger::table_name();
		$collate = $wpdb->get_charset_collate();

		/*
		 * dbDelta() no parsea SQL: lo compara linea a linea con una serie de
		 * expresiones regulares muy rigidas. Tres requisitos no negociables,
		 * documentados en el manual de WordPress:
		 *
		 *   1. El nombre de tabla va SIN backticks; dbDelta no los reconoce.
		 *   2. Dos espacios entre PRIMARY KEY y el parentesis.
		 *   3. Una definicion por linea.
		 *
		 * Incumplir cualquiera de ellos hace que la tabla no se cree, y sin
		 * error: dbDelta simplemente devuelve un array vacio.
		 */
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			level varchar(20) NOT NULL DEFAULT 'info',
			channel varchar(50) NOT NULL DEFAULT 'core',
			message text NOT NULL,
			context longtext NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_level (created_at, level),
			KEY channel (channel)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Escribe la configuracion por defecto si aun no existe.
	 *
	 * No sobrescribe: una reactivacion no debe descartar lo configurado.
	 *
	 * @return void
	 */
	public static function seed_config(): void {
		if ( false !== get_option( Config::OPTION, false ) ) {
			return;
		}

		add_option( Config::OPTION, Config::defaults(), '', false );
	}

	/**
	 * Programa los eventos de cron.
	 *
	 * @return void
	 */
	public static function schedule_events(): void {
		if ( ! wp_next_scheduled( self::CRON_PURGE_LOGS ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_PURGE_LOGS );
		}
	}

	/**
	 * Cancela los eventos de cron.
	 *
	 * @return void
	 */
	public static function unschedule_events(): void {
		$timestamp = wp_next_scheduled( self::CRON_PURGE_LOGS );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_PURGE_LOGS );
		}
	}
}

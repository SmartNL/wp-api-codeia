<?php
/**
 * Limpieza al desinstalar el plugin.
 *
 * A diferencia de la desactivacion, aqui si se borran los datos: el usuario
 * ha pedido explicitamente eliminar el plugin.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

// Solo WordPress puede invocar este archivo, y solo durante una desinstalacion.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Borra los datos del plugin en el sitio actual.
 *
 * @return void
 */
function codeia_uninstall_site(): void {
	global $wpdb;

	$options = array(
		'codeia_settings',
		'codeia_installed_version',
		'codeia_jwt_secret',
		'codeia_flush_needed',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Copias de seguridad de migraciones: codeia_settings_backup_{version}.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'codeia_settings_backup_' ) . '%'
		)
	);

	// Transients del plugin, por si el sitio no tiene object cache persistente.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_codeia_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_codeia_' ) . '%'
		)
	);

	// Meta propia de usuario: version de token para la revocacion masiva.
	delete_metadata( 'user', 0, '_codeia_token_version', '', true );

	$table = $wpdb->prefix . 'codeia_logs';

	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	wp_clear_scheduled_hook( 'codeia_purge_logs' );
}

if ( is_multisite() ) {
	// Cada sitio tiene su propia configuracion, sus propios secretos y su
	// propia tabla de logs, asi que hay que recorrerlos uno a uno.
	$codeia_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $codeia_sites as $codeia_site_id ) {
		switch_to_blog( (int) $codeia_site_id );
		codeia_uninstall_site();
		restore_current_blog();
	}

	delete_site_option( 'codeia_network_settings' );
} else {
	codeia_uninstall_site();
}

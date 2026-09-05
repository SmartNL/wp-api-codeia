<?php
/**
 * Tests de integracion de la activacion.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Integration;

use WP_UnitTestCase;
use WpApi\Codeia\Core\Activator;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\Logger;

/**
 * @covers \WpApi\Codeia\Core\Activator
 */
final class ActivationTest extends WP_UnitTestCase {

	/**
	 * La tabla se crea y tiene las columnas esperadas.
	 *
	 * NO se comprueba con SHOW TABLES: el framework de tests de WordPress
	 * reescribe CREATE TABLE como CREATE TEMPORARY TABLE para aislar cada
	 * test, y SHOW TABLES no lista tablas temporales. Una asercion con
	 * SHOW TABLES da falso negativo aqui, o falso positivo si el plugin quedo
	 * activado antes y dejo una tabla real.
	 */
	public function test_la_activacion_crea_la_tabla_de_logs(): void {
		global $wpdb;

		Activator::create_tables();

		$table   = Logger::table_name();
		$columns = $wpdb->get_col( "DESCRIBE {$table}", 0 );

		$this->assertSame( '', $wpdb->last_error );

		foreach ( array( 'id', 'created_at', 'level', 'channel', 'message', 'context', 'user_id' ) as $column ) {
			$this->assertContains( $column, $columns, "Falta la columna {$column}." );
		}
	}

	public function test_la_tabla_de_logs_admite_escritura_y_lectura(): void {
		global $wpdb;

		Activator::create_tables();

		$logger  = new Logger( Logger::INFO );
		$escrito = $logger->log( Logger::INFO, 'mensaje de prueba', array( 'token' => 'secreto' ), 'tests' );

		$this->assertTrue( $escrito );

		$row = $wpdb->get_row( 'SELECT level, channel, message, context FROM ' . Logger::table_name() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );

		$this->assertSame( 'info', $row['level'] );
		$this->assertSame( 'tests', $row['channel'] );
		$this->assertSame( 'mensaje de prueba', $row['message'] );
		$this->assertStringContainsString( '[oculto]', $row['context'], 'El token no debe guardarse en claro.' );
		$this->assertStringNotContainsString( 'secreto', $row['context'] );
	}

	public function test_la_activacion_escribe_la_configuracion_por_defecto(): void {
		delete_option( Config::OPTION );

		Activator::seed_config();

		$stored = get_option( Config::OPTION );

		$this->assertIsArray( $stored );
		$this->assertSame( Config::SCHEMA_VERSION, $stored['version'] );
		$this->assertSame( array(), $stored['resources'] );
	}

	public function test_la_configuracion_no_tiene_autoload(): void {
		global $wpdb;

		delete_option( Config::OPTION );
		Activator::seed_config();

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				Config::OPTION
			)
		);

		$this->assertNotSame( 'yes', $autoload, 'La configuracion llega a cientos de KB: no debe autocargarse.' );
	}

	public function test_reactivar_no_pisa_la_configuracion_existente(): void {
		delete_option( Config::OPTION );
		Activator::seed_config();

		$config = new Config();
		$config->set( 'namespace', 'personalizado' );
		$config->save();

		Activator::seed_config();

		$this->assertSame( 'personalizado', ( new Config() )->get( 'namespace' ) );
	}

	public function test_la_activacion_programa_la_purga_de_logs(): void {
		Activator::unschedule_events();
		Activator::schedule_events();

		$this->assertIsInt( wp_next_scheduled( Activator::CRON_PURGE_LOGS ) );
	}

	public function test_la_desactivacion_cancela_el_cron(): void {
		Activator::schedule_events();
		Activator::deactivate();

		$this->assertFalse( wp_next_scheduled( Activator::CRON_PURGE_LOGS ) );
	}

	public function test_la_desactivacion_no_borra_la_configuracion(): void {
		delete_option( Config::OPTION );
		Activator::seed_config();

		Activator::deactivate();

		$this->assertIsArray( get_option( Config::OPTION ) );
	}
}

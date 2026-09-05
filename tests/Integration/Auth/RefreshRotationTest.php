<?php
/**
 * Rotacion y deteccion de reutilizacion de tokens de refresco.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Integration\Auth;

use WP_UnitTestCase;
use WpApi\Codeia\Auth\RefreshTokenService;
use WpApi\Codeia\Core\Logger;

/**
 * @covers \WpApi\Codeia\Auth\RefreshTokenService
 */
final class RefreshRotationTest extends WP_UnitTestCase {

	private RefreshTokenService $service;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		$this->service = new RefreshTokenService( new Logger( Logger::ERROR ) );
		$this->user_id = self::factory()->user->create( array( 'role' => 'author' ) );
	}

	public function test_emite_un_token_de_refresco(): void {
		$token = $this->service->issue( $this->user_id );

		$this->assertNotSame( '', $token );
		$this->assertStringNotContainsString( '.', $token, 'Un token opaco no debe contener puntos.' );
	}

	public function test_el_token_se_guarda_hasheado(): void {
		$token  = $this->service->issue( $this->user_id );
		$stored = $this->service->tokens_for( $this->user_id );

		$this->assertCount( 1, $stored );
		$this->assertArrayNotHasKey( $token, $stored, 'La credencial en claro no debe almacenarse.' );
		$this->assertArrayHasKey( hash( 'sha256', $token ), $stored );
	}

	public function test_rotar_invalida_el_anterior_y_emite_uno_nuevo(): void {
		$primero = $this->service->issue( $this->user_id );
		$segundo = $this->service->rotate( $this->user_id, $primero );

		$this->assertNotNull( $segundo );
		$this->assertNotSame( $primero, $segundo );
	}

	public function test_la_familia_se_conserva_entre_rotaciones(): void {
		$primero = $this->service->issue( $this->user_id );
		$segundo = $this->service->rotate( $this->user_id, $primero );

		$stored   = $this->service->tokens_for( $this->user_id );
		$familias = array_unique( array_column( $stored, 'family' ) );

		$this->assertCount( 1, $familias, 'Toda la cadena pertenece a la misma familia.' );
		$this->assertNotNull( $segundo );
	}

	/**
	 * El comportamiento central del modulo: reutilizar un refresh ya
	 * consumido solo tiene dos explicaciones, carrera del cliente o robo.
	 * Como no se distinguen, se asume lo peor y cae la familia entera.
	 */
	public function test_reutilizar_un_token_consumido_revoca_la_familia(): void {
		$primero = $this->service->issue( $this->user_id );
		$segundo = $this->service->rotate( $this->user_id, $primero );

		$this->assertNotNull( $segundo );

		// El atacante presenta el token ya consumido.
		$tercero = $this->service->rotate( $this->user_id, $primero );

		$this->assertNull( $tercero, 'Un token ya usado no debe rotar.' );
		$this->assertNull(
			$this->service->rotate( $this->user_id, $segundo ),
			'La familia entera queda revocada, incluido el token legitimo.'
		);
	}

	public function test_un_token_desconocido_no_rota(): void {
		$this->service->issue( $this->user_id );

		$this->assertNull( $this->service->rotate( $this->user_id, 'inventado' ) );
	}

	public function test_un_token_caducado_no_rota(): void {
		$token  = $this->service->issue( $this->user_id );
		$stored = $this->service->tokens_for( $this->user_id );
		$hash   = array_key_first( $stored );

		$stored[ $hash ]['expires'] = time() - 10;
		update_user_meta( $this->user_id, RefreshTokenService::META_KEY, $stored );

		$this->assertNull( $this->service->rotate( $this->user_id, $token ) );
	}

	public function test_revocar_la_familia_deja_fuera_solo_a_esa(): void {
		$familia_a = $this->service->issue( $this->user_id );
		$familia_b = $this->service->issue( $this->user_id );

		$stored = $this->service->tokens_for( $this->user_id );
		$hash_a = hash( 'sha256', $familia_a );

		$this->service->revoke_family( $this->user_id, (string) $stored[ $hash_a ]['family'] );

		$this->assertNull( $this->service->rotate( $this->user_id, $familia_a ) );
		$this->assertNotNull( $this->service->rotate( $this->user_id, $familia_b ) );
	}

	public function test_revocar_todo_deja_al_usuario_sin_tokens(): void {
		$token = $this->service->issue( $this->user_id );

		$this->service->revoke_all( $this->user_id );

		$this->assertSame( array(), $this->service->tokens_for( $this->user_id ) );
		$this->assertNull( $this->service->rotate( $this->user_id, $token ) );
	}

	public function test_los_tokens_de_dos_usuarios_no_se_mezclan(): void {
		$otro  = self::factory()->user->create();
		$token = $this->service->issue( $this->user_id );

		$this->assertNull(
			$this->service->rotate( $otro, $token ),
			'Un token no debe valer para otro usuario.'
		);
	}
}

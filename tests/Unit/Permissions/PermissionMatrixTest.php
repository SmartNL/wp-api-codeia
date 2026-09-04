<?php
/**
 * Tests de la cascada de la matriz de permisos.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Unit\Permissions;

use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Permissions\PermissionContext;
use WpApi\Codeia\Permissions\PermissionMatrix;
use WpApi\Codeia\Tests\Unit\TestCase;

/**
 * @covers \WpApi\Codeia\Permissions\PermissionMatrix
 */
final class PermissionMatrixTest extends TestCase {

	/**
	 * Construye una matriz con la configuracion indicada.
	 *
	 * @param array<string, mixed> $permissions Rama permissions.
	 * @return PermissionMatrix
	 */
	private function matrix( array $permissions ): PermissionMatrix {
		$config = array_replace( Config::defaults(), array( 'permissions' => $permissions ) );

		return new PermissionMatrix( new Config( $config ) );
	}

	/**
	 * Contexto de prueba sobre el recurso property.
	 *
	 * @param string      $role  Rol.
	 * @param string      $op    Operacion.
	 * @param string|null $field Campo.
	 * @return PermissionContext
	 */
	private function context( string $role, string $op, ?string $field = null ): PermissionContext {
		return new PermissionContext( 5, $role, 'property', $op, $field );
	}

	public function test_sin_reglas_todo_esta_denegado(): void {
		$resultado = $this->matrix( array() )->resolve( $this->context( 'editor', 'read' ) );

		$this->assertFalse( $resultado['decision'], 'Denegacion por defecto.' );
		$this->assertSame( PermissionMatrix::LEVEL_NONE, $resultado['level'] );
	}

	public function test_nivel_1_regla_global_por_rol(): void {
		$resultado = $this->matrix(
			array( 'defaults' => array( 'editor' => true ) )
		)->resolve( $this->context( 'editor', 'read' ) );

		$this->assertTrue( $resultado['decision'] );
		$this->assertSame( PermissionMatrix::LEVEL_ROLE, $resultado['level'] );
	}

	public function test_nivel_2_sobrescribe_al_nivel_1(): void {
		$resultado = $this->matrix(
			array(
				'defaults' => array( 'editor' => true ),
				'property' => array( 'editor' => array( 'all' => false ) ),
			)
		)->resolve( $this->context( 'editor', 'read' ) );

		$this->assertFalse( $resultado['decision'], 'Gana la mas especifica, no la mas permisiva.' );
		$this->assertSame( PermissionMatrix::LEVEL_ROLE_RESOURCE, $resultado['level'] );
	}

	public function test_nivel_3_sobrescribe_al_nivel_2(): void {
		$resultado = $this->matrix(
			array(
				'property' => array(
					'editor' => array(
						'all'  => false,
						'read' => true,
					),
				),
			)
		)->resolve( $this->context( 'editor', 'read' ) );

		$this->assertTrue( $resultado['decision'] );
		$this->assertSame( PermissionMatrix::LEVEL_ROLE_RES_OP, $resultado['level'] );
	}

	/**
	 * El caso del documento: leer propiedades sin ver el precio.
	 */
	public function test_nivel_4_de_campo_sobrescribe_al_de_operacion(): void {
		$matriz = $this->matrix(
			array(
				'property' => array(
					'editor' => array(
						'read'   => true,
						'fields' => array( 'price' => array( 'read' => false ) ),
					),
				),
			)
		);

		$recurso = $matriz->resolve( $this->context( 'editor', 'read' ) );
		$campo   = $matriz->resolve( $this->context( 'editor', 'read', 'price' ) );

		$this->assertTrue( $recurso['decision'], 'Puede leer el recurso.' );
		$this->assertFalse( $campo['decision'], 'Pero no ese campo.' );
		$this->assertSame( PermissionMatrix::LEVEL_ROLE_RES_OP_FIELD, $campo['level'] );
	}

	public function test_un_campo_sin_regla_hereda_la_de_la_operacion(): void {
		$resultado = $this->matrix(
			array( 'property' => array( 'editor' => array( 'read' => true ) ) )
		)->resolve( $this->context( 'editor', 'read', 'rooms' ) );

		$this->assertTrue( $resultado['decision'] );
		$this->assertSame( PermissionMatrix::LEVEL_ROLE_RES_OP, $resultado['level'] );
	}

	public function test_los_roles_no_se_contaminan_entre_si(): void {
		$matriz = $this->matrix(
			array( 'property' => array( 'editor' => array( 'read' => true ) ) )
		);

		$this->assertTrue( $matriz->resolve( $this->context( 'editor', 'read' ) )['decision'] );
		$this->assertFalse( $matriz->resolve( $this->context( 'subscriber', 'read' ) )['decision'] );
	}

	public function test_las_operaciones_no_se_contaminan_entre_si(): void {
		$matriz = $this->matrix(
			array( 'property' => array( 'editor' => array( 'read' => true ) ) )
		);

		$this->assertTrue( $matriz->resolve( $this->context( 'editor', 'read' ) )['decision'] );
		$this->assertFalse( $matriz->resolve( $this->context( 'editor', 'delete' ) )['decision'] );
	}

	/**
	 * Una configuracion corrupta no debe conceder acceso por accidente.
	 *
	 * @dataProvider valores_no_interpretables
	 * @param mixed $valor Valor almacenado.
	 */
	public function test_un_valor_corrupto_no_concede_acceso( $valor ): void {
		$resultado = $this->matrix(
			array( 'property' => array( 'editor' => array( 'read' => $valor ) ) )
		)->resolve( $this->context( 'editor', 'read' ) );

		$this->assertFalse( $resultado['decision'] );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public static function valores_no_interpretables(): array {
		return array(
			'cadena'  => array( 'si' ),
			'array'   => array( array( 'x' ) ),
			'decimal' => array( 1.5 ),
		);
	}

	public function test_acepta_enteros_y_cadenas_binarias(): void {
		$uno  = $this->matrix( array( 'property' => array( 'editor' => array( 'read' => 1 ) ) ) );
		$cero = $this->matrix( array( 'property' => array( 'editor' => array( 'read' => 0 ) ) ) );
		$str  = $this->matrix( array( 'property' => array( 'editor' => array( 'read' => '1' ) ) ) );

		$this->assertTrue( $uno->resolve( $this->context( 'editor', 'read' ) )['decision'] );
		$this->assertFalse( $cero->resolve( $this->context( 'editor', 'read' ) )['decision'] );
		$this->assertTrue( $str->resolve( $this->context( 'editor', 'read' ) )['decision'] );
	}

	public function test_los_defaults_no_conceden_escritura_a_subscriber(): void {
		$defaults = PermissionMatrix::defaults();

		$this->assertTrue( $defaults['subscriber']['read'] );
		$this->assertFalse( $defaults['subscriber']['create'] );
		$this->assertFalse( $defaults['subscriber']['update'] );
		$this->assertFalse( $defaults['subscriber']['delete'] );
	}

	public function test_los_defaults_no_conceden_nada_de_escritura_al_anonimo(): void {
		$anonimo = PermissionMatrix::defaults()['anonymous'];

		$this->assertTrue( $anonimo['read'] );
		$this->assertFalse( $anonimo['create'] );
		$this->assertFalse( $anonimo['upload'] );
	}
}

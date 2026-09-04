<?php
/**
 * Visibilidad por campo: lectura silenciosa, escritura explicita.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Integration\Permissions;

use WP_UnitTestCase;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Permissions\CapabilityMapper;
use WpApi\Codeia\Permissions\FieldVisibility;
use WpApi\Codeia\Permissions\PermissionMatrix;
use WpApi\Codeia\Permissions\PermissionResolver;

/**
 * @covers \WpApi\Codeia\Permissions\FieldVisibility
 */
final class FieldVisibilityTest extends WP_UnitTestCase {

	private int $editor;
	private int $subscriber;

	public function set_up(): void {
		parent::set_up();

		register_post_type( 'property', array( 'public' => true ) );

		$this->editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tear_down(): void {
		unregister_post_type( 'property' );
		parent::tear_down();
	}

	/**
	 * Visibilidad con la matriz del documento: coordenadas ocultas al
	 * publico, referencia catastral solo para editores.
	 *
	 * @return FieldVisibility
	 */
	private function visibility(): FieldVisibility {
		$permissions = array(
			'property' => array(
				'editor'     => array(
					'all'    => true,
					'fields' => array(
						'cadastral_ref' => array(
							'read'   => true,
							'update' => true,
						),
						'latitude'      => array(
							'read'   => true,
							'update' => true,
						),
					),
				),
				'subscriber' => array(
					'read'   => true,
					'fields' => array(
						'cadastral_ref' => array( 'read' => false ),
						'latitude'      => array( 'read' => false ),
					),
				),
			),
		);

		$config = new Config( array_replace( Config::defaults(), array( 'permissions' => $permissions ) ) );

		$resolver = new PermissionResolver(
			new PermissionMatrix( $config ),
			new CapabilityMapper(),
			new EventDispatcher()
		);

		return new FieldVisibility( $resolver, new EventDispatcher() );
	}

	/**
	 * Elemento de ejemplo con campos reales de property.
	 *
	 * @return array<string, mixed>
	 */
	private function item(): array {
		return array(
			'title'         => 'Atico en Malasana',
			'price'         => 495000,
			'rooms'         => 3,
			'latitude'      => 40.4265,
			'cadastral_ref' => '1234567AB1234C0001XX',
		);
	}

	public function test_un_editor_ve_todos_los_campos(): void {
		$proyectado = $this->visibility()->project( $this->item(), $this->editor, 'property' );

		$this->assertCount( 5, $proyectado );
		$this->assertArrayHasKey( 'cadastral_ref', $proyectado );
	}

	/**
	 * Los campos vetados se OMITEN. Devolverlos como null confirmaria que
	 * existen y que hay algo que ocultar.
	 */
	public function test_los_campos_vetados_se_omiten_no_se_ponen_a_null(): void {
		$proyectado = $this->visibility()->project( $this->item(), $this->subscriber, 'property' );

		$this->assertArrayNotHasKey( 'cadastral_ref', $proyectado );
		$this->assertArrayNotHasKey( 'latitude', $proyectado );
		$this->assertArrayHasKey( 'price', $proyectado );
		$this->assertSame( 495000, $proyectado['price'] );
	}

	public function test_detecta_los_campos_no_escribibles(): void {
		$vetados = $this->visibility()->forbidden_writes(
			array(
				'price'         => 1,
				'cadastral_ref' => 'x',
			),
			$this->subscriber,
			'property'
		);

		$this->assertContains( 'cadastral_ref', $vetados );
	}

	public function test_un_editor_no_tiene_campos_vetados_en_escritura(): void {
		$vetados = $this->visibility()->forbidden_writes(
			array(
				'price'         => 1,
				'cadastral_ref' => 'x',
			),
			$this->editor,
			'property'
		);

		$this->assertSame( array(), $vetados );
	}

	public function test_el_hash_de_campos_legibles_distingue_roles(): void {
		$visibility = $this->visibility();
		$campos     = array_keys( $this->item() );

		$hash_editor = $visibility->fields_hash( $campos, $this->editor, 'property' );
		$hash_subs   = $visibility->fields_hash( $campos, $this->subscriber, 'property' );

		$this->assertNotSame(
			$hash_editor,
			$hash_subs,
			'Sin esta distincion, la respuesta cacheada de un rol se serviria a otro.'
		);
	}

	public function test_el_hash_es_estable_para_el_mismo_rol(): void {
		$visibility = $this->visibility();
		$campos     = array_keys( $this->item() );

		$this->assertSame(
			$visibility->fields_hash( $campos, $this->editor, 'property' ),
			$visibility->fields_hash( $campos, $this->editor, 'property' )
		);
	}

	public function test_el_hash_no_depende_del_orden_de_los_campos(): void {
		$visibility = $this->visibility();
		$campos     = array_keys( $this->item() );

		$this->assertSame(
			$visibility->fields_hash( $campos, $this->editor, 'property' ),
			$visibility->fields_hash( array_reverse( $campos ), $this->editor, 'property' )
		);
	}

	public function test_el_filtro_de_visibilidad_puede_ocultar_un_campo(): void {
		add_filter(
			'codeia/permissions/field_visible',
			static function ( $visible, $field ) {
				return 'price' === $field ? false : $visible;
			},
			10,
			2
		);

		$proyectado = $this->visibility()->project( $this->item(), $this->editor, 'property' );

		$this->assertArrayNotHasKey( 'price', $proyectado );

		remove_all_filters( 'codeia/permissions/field_visible' );
	}
}

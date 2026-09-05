<?php
/**
 * Las dos puertas de la decision de acceso.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Integration\Permissions;

use WP_UnitTestCase;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Permissions\CapabilityMapper;
use WpApi\Codeia\Permissions\PermissionContext;
use WpApi\Codeia\Permissions\PermissionMatrix;
use WpApi\Codeia\Permissions\PermissionResolver;

/**
 * @covers \WpApi\Codeia\Permissions\PermissionResolver
 * @covers \WpApi\Codeia\Permissions\CapabilityMapper
 */
final class TwoGatesTest extends WP_UnitTestCase {

	private int $editor;
	private int $author;
	private int $otro_author;
	private int $subscriber;

	public function set_up(): void {
		parent::set_up();

		register_post_type(
			'property',
			array(
				'public' => true,
				'label'  => 'Propiedades',
			)
		);

		$this->editor      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->author      = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->otro_author = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriber  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	public function tear_down(): void {
		unregister_post_type( 'property' );
		parent::tear_down();
	}

	/**
	 * Construye un resolutor con la matriz indicada.
	 *
	 * @param array<string, mixed> $permissions Rama permissions.
	 * @return PermissionResolver
	 */
	private function resolver( array $permissions ): PermissionResolver {
		$config = new Config( array_replace( Config::defaults(), array( 'permissions' => $permissions ) ) );

		return new PermissionResolver(
			new PermissionMatrix( $config ),
			new CapabilityMapper(),
			new EventDispatcher()
		);
	}

	/**
	 * Matriz que concede todo a todos los roles usados.
	 *
	 * @return array<string, mixed>
	 */
	private function todo_permitido(): array {
		$roles  = array( 'editor', 'author', 'subscriber', 'anonymous' );
		$reglas = array();

		foreach ( $roles as $rol ) {
			$reglas[ $rol ] = array( 'all' => true );
		}

		return array( 'property' => $reglas );
	}

	/**
	 * La matriz solo puede restringir: aunque conceda, las capabilities
	 * mandan. Si pudiera ampliar, la API seria una via para eludir el modelo
	 * de permisos del sitio.
	 */
	public function test_la_matriz_no_puede_ampliar_las_capabilities(): void {
		$resolver = $this->resolver( $this->todo_permitido() );

		$this->assertFalse(
			$resolver->can_operate( $this->subscriber, 'property', PermissionContext::OP_CREATE ),
			'Un subscriber no tiene edit_posts, por mucho que la matriz lo conceda.'
		);
	}

	public function test_la_matriz_si_puede_restringir(): void {
		$resolver = $this->resolver(
			array(
				'property' => array(
					'editor' => array(
						'all'    => true,
						'delete' => false,
					),
				),
			)
		);

		$this->assertTrue( $resolver->can_operate( $this->editor, 'property', PermissionContext::OP_CREATE ) );
		$this->assertFalse(
			$resolver->can_operate( $this->editor, 'property', PermissionContext::OP_DELETE ),
			'La capability existe, pero la matriz la deniega.'
		);
	}

	public function test_sin_regla_en_la_matriz_todo_esta_denegado(): void {
		$resolver = $this->resolver( array() );

		$this->assertFalse( $resolver->can_operate( $this->editor, 'property', PermissionContext::OP_READ ) );
	}

	/**
	 * El fallo de autorizacion mas comun en endpoints REST personalizados:
	 * comprobar edit_posts y olvidar edit_post con el ID del objeto.
	 */
	public function test_un_author_no_edita_contenido_ajeno(): void {
		$resolver = $this->resolver( $this->todo_permitido() );

		$propio = self::factory()->post->create(
			array(
				'post_type'   => 'property',
				'post_author' => $this->author,
			)
		);
		$ajeno  = self::factory()->post->create(
			array(
				'post_type'   => 'property',
				'post_author' => $this->otro_author,
			)
		);

		$this->assertTrue(
			$resolver->can_operate( $this->author, 'property', PermissionContext::OP_UPDATE, $propio ),
			'Puede editar lo suyo.'
		);
		$this->assertFalse(
			$resolver->can_operate( $this->author, 'property', PermissionContext::OP_UPDATE, $ajeno ),
			'No puede editar lo de otro autor.'
		);
	}

	public function test_un_editor_si_edita_contenido_ajeno(): void {
		$resolver = $this->resolver( $this->todo_permitido() );

		$ajeno = self::factory()->post->create(
			array(
				'post_type'   => 'property',
				'post_author' => $this->author,
			)
		);

		$this->assertTrue(
			$resolver->can_operate( $this->editor, 'property', PermissionContext::OP_UPDATE, $ajeno )
		);
	}

	public function test_la_capability_de_coleccion_no_basta_sin_la_de_objeto(): void {
		$mapper = new CapabilityMapper();

		$this->assertSame( 'edit_posts', $mapper->collection_cap( 'property', PermissionContext::OP_UPDATE ) );
		$this->assertSame( 'edit_post', $mapper->object_cap( PermissionContext::OP_UPDATE ) );
		$this->assertSame( 'delete_post', $mapper->object_cap( PermissionContext::OP_DELETE ) );
	}

	public function test_el_rol_efectivo_de_un_anonimo_es_anonymous(): void {
		$resolver = $this->resolver( array() );

		$this->assertSame( 'anonymous', $resolver->effective_role( 0 ) );
		$this->assertSame( 'editor', $resolver->effective_role( $this->editor ) );
	}

	public function test_un_anonimo_solo_puede_leer(): void {
		$resolver = $this->resolver( $this->todo_permitido() );

		$this->assertTrue( $resolver->can_operate( 0, 'property', PermissionContext::OP_READ ) );
		$this->assertFalse( $resolver->can_operate( 0, 'property', PermissionContext::OP_CREATE ) );
		$this->assertFalse( $resolver->can_operate( 0, 'property', PermissionContext::OP_UPLOAD ) );
	}

	public function test_una_operacion_desconocida_se_deniega(): void {
		$resolver = $this->resolver( $this->todo_permitido() );

		$this->assertFalse( $resolver->can_operate( $this->editor, 'property', 'inventada' ) );
	}

	/**
	 * El filtro tiene la ultima palabra por diseno, y recibe el contexto con
	 * la decision previa y el nivel que la produjo.
	 */
	public function test_el_filtro_de_extension_puede_revertir_la_decision(): void {
		$resolver = $this->resolver( $this->todo_permitido() );

		$visto = null;

		add_filter(
			'codeia/permissions/can',
			static function ( $allowed, $context ) use ( &$visto ) {
				$visto = $context;

				return false;
			},
			10,
			2
		);

		$this->assertFalse( $resolver->can_operate( $this->editor, 'property', PermissionContext::OP_READ ) );
		$this->assertInstanceOf( PermissionContext::class, $visto );
		$this->assertTrue( $visto->decision, 'El filtro ve la decision previa.' );
		$this->assertSame( PermissionMatrix::LEVEL_ROLE_RESOURCE, $visto->level );

		remove_all_filters( 'codeia/permissions/can' );
	}

	public function test_las_decisiones_de_recurso_se_cachean_por_peticion(): void {
		$resolver = $this->resolver( $this->todo_permitido() );
		$llamadas = 0;

		add_filter(
			'codeia/permissions/can',
			static function ( $allowed ) use ( &$llamadas ) {
				++$llamadas;

				return $allowed;
			}
		);

		$resolver->can_operate( $this->editor, 'property', PermissionContext::OP_READ );
		$resolver->can_operate( $this->editor, 'property', PermissionContext::OP_READ );
		$resolver->can_operate( $this->editor, 'property', PermissionContext::OP_READ );

		$this->assertSame( 1, $llamadas, 'El conjunto depende del rol, no del post concreto.' );

		remove_all_filters( 'codeia/permissions/can' );
	}

	public function test_las_decisiones_sobre_objeto_no_se_cachean(): void {
		$resolver = $this->resolver( $this->todo_permitido() );

		$propio = self::factory()->post->create(
			array(
				'post_type'   => 'property',
				'post_author' => $this->author,
			)
		);
		$ajeno  = self::factory()->post->create(
			array(
				'post_type'   => 'property',
				'post_author' => $this->otro_author,
			)
		);

		$this->assertTrue( $resolver->can_operate( $this->author, 'property', PermissionContext::OP_UPDATE, $propio ) );
		$this->assertFalse(
			$resolver->can_operate( $this->author, 'property', PermissionContext::OP_UPDATE, $ajeno ),
			'Cachear por rol no debe arrastrar la decision de otro objeto.'
		);
	}
}

<?php
/**
 * Restriccion de colecciones aplicada en la consulta.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Tests\Integration\Permissions;

use WP_Query;
use WP_UnitTestCase;
use WpApi\Codeia\Core\Config;
use WpApi\Codeia\Core\EventDispatcher;
use WpApi\Codeia\Permissions\CapabilityMapper;
use WpApi\Codeia\Permissions\CollectionRestrictor;
use WpApi\Codeia\Permissions\PermissionMatrix;
use WpApi\Codeia\Permissions\PermissionResolver;

/**
 * @covers \WpApi\Codeia\Permissions\CollectionRestrictor
 */
final class CollectionRestrictorTest extends WP_UnitTestCase {

	private CollectionRestrictor $restrictor;
	private int $editor;
	private int $author;
	private int $subscriber;

	public function set_up(): void {
		parent::set_up();

		register_post_type( 'property', array( 'public' => true ) );

		$this->editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->author     = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$config   = new Config( Config::defaults() );
		$resolver = new PermissionResolver(
			new PermissionMatrix( $config ),
			new CapabilityMapper(),
			new EventDispatcher()
		);

		$this->restrictor = new CollectionRestrictor(
			new CapabilityMapper(),
			$resolver,
			new EventDispatcher()
		);
	}

	public function tear_down(): void {
		unregister_post_type( 'property' );
		parent::tear_down();
	}

	public function test_un_anonimo_solo_ve_lo_publicado(): void {
		$args = $this->restrictor->apply( array(), 0, 'property' );

		$this->assertSame( 'publish', $args['post_status'] );
	}

	public function test_un_subscriber_solo_ve_lo_publicado(): void {
		$args = $this->restrictor->apply( array(), $this->subscriber, 'property' );

		$this->assertSame( 'publish', $args['post_status'] );
	}

	public function test_un_author_ve_lo_suyo_en_cualquier_estado(): void {
		$args = $this->restrictor->apply( array(), $this->author, 'property' );

		$this->assertContains( 'draft', (array) $args['post_status'] );
		$this->assertSame( $this->author, $args['author'] );
	}

	public function test_un_editor_no_tiene_restriccion_de_estado(): void {
		$args = $this->restrictor->apply( array(), $this->editor, 'property' );

		$this->assertArrayNotHasKey( 'post_status', $args );
		$this->assertArrayNotHasKey( 'author', $args );
	}

	/**
	 * La restriccion debe aplicarse EN la consulta. Filtrar despues romperia
	 * la paginacion: pedir 20 y descartar 7 devolveria 13, y X-WP-Total
	 * dejaria de cuadrar con lo devuelto.
	 */
	public function test_la_restriccion_se_aplica_en_la_consulta_y_el_total_cuadra(): void {
		self::factory()->post->create_many(
			5,
			array(
				'post_type'   => 'property',
				'post_status' => 'publish',
			)
		);
		self::factory()->post->create_many(
			3,
			array(
				'post_type'   => 'property',
				'post_status' => 'draft',
				'post_author' => $this->author,
			)
		);

		$args = $this->restrictor->apply(
			array(
				'post_type'      => 'property',
				'posts_per_page' => 20,
			),
			$this->subscriber,
			'property'
		);

		$query = new WP_Query( $args );

		$this->assertCount( 5, $query->posts, 'Solo lo publicado.' );
		$this->assertSame(
			5,
			(int) $query->found_posts,
			'El total lo calcula la consulta, no un filtrado posterior.'
		);
	}

	public function test_un_author_ve_sus_borradores_ademas_de_lo_publicado(): void {
		self::factory()->post->create_many(
			2,
			array(
				'post_type'   => 'property',
				'post_status' => 'publish',
				'post_author' => $this->author,
			)
		);
		self::factory()->post->create_many(
			3,
			array(
				'post_type'   => 'property',
				'post_status' => 'draft',
				'post_author' => $this->author,
			)
		);

		$args  = $this->restrictor->apply(
			array(
				'post_type'      => 'property',
				'posts_per_page' => 20,
			),
			$this->author,
			'property'
		);
		$query = new WP_Query( $args );

		$this->assertCount( 5, $query->posts );
	}

	public function test_un_post_type_inexistente_cae_a_publicado(): void {
		$args = $this->restrictor->apply( array(), $this->editor, 'no_existe' );

		$this->assertSame( 'publish', $args['post_status'] );
	}

	public function test_el_filtro_de_extension_puede_anadir_restricciones(): void {
		add_filter(
			'codeia/permissions/collection_args',
			static function ( $args ) {
				$args['meta_key'] = '_property_agent_id';

				return $args;
			}
		);

		$args = $this->restrictor->apply( array(), $this->editor, 'property' );

		$this->assertSame( '_property_agent_id', $args['meta_key'] );

		remove_all_filters( 'codeia/permissions/collection_args' );
	}
}

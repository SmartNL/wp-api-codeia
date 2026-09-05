<?php
/**
 * Deteccion de colisiones del alias en raiz.
 *
 * @package WpApi\Codeia
 */

declare( strict_types=1 );

namespace WpApi\Codeia\Rewrite;

defined( 'ABSPATH' ) || exit;

/**
 * Comprueba que el prefijo del alias no choca con nada existente.
 *
 * El alias ocupa un prefijo en la raiz del dominio. Si existe una pagina con
 * ese slug, ambas compiten y la regla registrada en 'top' gana, dejando la
 * pagina inaccesible.
 *
 * Ante una colision la activacion se BLOQUEA, no se avisa: activar de todos
 * modos dejaria una pagina rota o una API que no responde, y el diagnostico
 * posterior es dificil.
 */
final class CollisionDetector {

	/**
	 * Prefijos reservados por el nucleo.
	 */
	private const RESERVED = array(
		'wp-json',
		'wp-admin',
		'wp-content',
		'wp-includes',
		'feed',
		'rss',
		'rss2',
		'atom',
		'rdf',
		'page',
		'comments',
		'search',
		'author',
		'category',
		'tag',
		'embed',
		'trackback',
		'robots.txt',
		'sitemap.xml',
		'wp-sitemap.xml',
	);

	/**
	 * Busca colisiones para un prefijo.
	 *
	 * @param string $prefix Prefijo del alias.
	 * @return string[] Motivos de colision. Vacio si no hay ninguna.
	 */
	public function conflicts( string $prefix ): array {
		$prefix    = trim( $prefix, '/' );
		$conflicts = array();

		if ( '' === $prefix ) {
			return array( __( 'El prefijo no puede estar vacio.', 'wp-api-codeia' ) );
		}

		if ( in_array( strtolower( $prefix ), self::RESERVED, true ) ) {
			$conflicts[] = sprintf(
				/* translators: %s: prefijo. */
				__( '"%s" es un prefijo reservado por WordPress.', 'wp-api-codeia' ),
				$prefix
			);
		}

		$page = get_page_by_path( $prefix );

		if ( $page instanceof \WP_Post ) {
			$conflicts[] = sprintf(
				/* translators: 1: prefijo, 2: ID de la pagina. */
				__( 'Existe una pagina con el slug "%1$s" (ID %2$d).', 'wp-api-codeia' ),
				$prefix,
				$page->ID
			);
		}

		foreach ( get_post_types( array(), 'objects' ) as $post_type ) {
			if ( $this->rewrite_slug( $post_type->rewrite ) === $prefix ) {
				$conflicts[] = sprintf(
					/* translators: 1: prefijo, 2: post type. */
					__( 'El post type "%2$s" usa el slug "%1$s".', 'wp-api-codeia' ),
					$prefix,
					$post_type->name
				);
			}
		}

		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			if ( $this->rewrite_slug( $taxonomy->rewrite ) === $prefix ) {
				$conflicts[] = sprintf(
					/* translators: 1: prefijo, 2: taxonomia. */
					__( 'La taxonomia "%2$s" usa el slug "%1$s".', 'wp-api-codeia' ),
					$prefix,
					$taxonomy->name
				);
			}
		}

		return $conflicts;
	}

	/**
	 * Indica si el prefijo esta libre.
	 *
	 * @param string $prefix Prefijo.
	 * @return bool
	 */
	public function is_available( string $prefix ): bool {
		return array() === $this->conflicts( $prefix );
	}

	/**
	 * Propone un prefijo libre a partir de uno ocupado.
	 *
	 * @param string $prefix Prefijo deseado.
	 * @return string
	 */
	public function suggest( string $prefix ): string {
		$base = trim( $prefix, '/' );

		for ( $i = 2; $i <= 20; $i++ ) {
			$candidate = $base . '-' . $i;

			if ( $this->is_available( $candidate ) ) {
				return $candidate;
			}
		}

		return $base . '-api';
	}

	/**
	 * Extrae el slug de una definicion de rewrite.
	 *
	 * @param mixed $rewrite Definicion de rewrite.
	 * @return string
	 */
	private function rewrite_slug( $rewrite ): string {
		if ( is_array( $rewrite ) && isset( $rewrite['slug'] ) ) {
			return trim( (string) $rewrite['slug'], '/' );
		}

		return '';
	}
}

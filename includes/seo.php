<?php
/**
 * Search engine hints. On a single post:
 * - <link rel="predecessor-version|successor-version|latest-version"> (RFC 5829)
 *   connect versions in both directions.
 * - The newer post's structured data says it isBasedOn the earlier one. If an SEO
 *   plugin already outputs an Article, the property is added there; otherwise a
 *   small BlogPosting block is printed.
 *
 * Canonical URLs are left alone: each version is its own page.
 */

defined( 'ABSPATH' ) || exit;

/**
 * The post being viewed, if it's a supported single post and hints are on.
 */
function forkposter_seo_post(): ?WP_Post {
	if ( ! forkposter_setting( 'seo_hints' ) || ! is_singular() ) {
		return null;
	}

	$post = get_queried_object();
	return ( $post instanceof WP_Post && 'publish' === $post->post_status && forkposter_supports( $post ) ) ? $post : null;
}

/**
 * The schema.org description of the version a post is based on.
 */
function forkposter_based_on_schema( WP_Post $post ): ?array {
	$parent = forkposter_get_published_parent( $post->ID );
	if ( ! $parent ) {
		return null;
	}

	return forkposter_with_schema_version(
		array(
			'@type'         => 'BlogPosting',
			'url'           => get_permalink( $parent ),
			'headline'      => wp_strip_all_tags( $parent->post_title ),
			'datePublished' => get_post_time( DATE_W3C, true, $parent ),
		),
		$parent->ID
	);
}

/**
 * Add schema.org's "version" property when version numbers are on and the post has one.
 */
function forkposter_with_schema_version( array $node, int $post_id ): array {
	if ( forkposter_versions_enabled() && '' !== forkposter_get_version( $post_id ) ) {
		$node['version'] = forkposter_get_version( $post_id );
	}
	return $node;
}

add_action( 'wp_head', 'forkposter_version_links', 5 );
function forkposter_version_links() {
	$post = forkposter_seo_post();
	if ( ! $post ) {
		return;
	}

	$links  = array();
	// A branch is based on its original (see isBasedOn below) but isn't a version of it.
	$parent = forkposter_get_published_parent( $post->ID );
	if ( $parent && ! forkposter_is_branch( $post->ID ) ) {
		$links['predecessor-version'] = $parent;
	}

	$successor = forkposter_get_successor( $post->ID );
	if ( $successor ) {
		$links['successor-version'] = $successor;

		$latest = forkposter_get_latest( $post->ID );
		if ( $latest ) {
			$links['latest-version'] = $latest;
		}
	}

	foreach ( $links as $rel => $target ) {
		printf( '<link rel="%s" href="%s" />' . "\n", esc_attr( $rel ), esc_url( get_permalink( $target ) ) );
	}
}

/**
 * Whether an SEO plugin that prints its own Article structured data is active.
 */
function forkposter_seo_plugin_handles_schema(): bool {
	$handled = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' );

	/**
	 * Whether another plugin outputs Article structured data. Return true to stop
	 * Forkposter printing its own JSON-LD block.
	 *
	 * @param bool $handled
	 */
	return (bool) apply_filters( 'forkposter_schema_handled_elsewhere', $handled );
}

/**
 * Add isBasedOn to the Article-like entities in a list of schema.org nodes.
 */
function forkposter_add_based_on_to_nodes( array $nodes ): array {
	$post = forkposter_seo_post();
	$base = $post ? forkposter_based_on_schema( $post ) : null;
	if ( ! $base ) {
		return $nodes;
	}

	foreach ( $nodes as &$node ) {
		if ( ! is_array( $node ) || empty( $node['@type'] ) ) {
			continue;
		}
		$types = (array) $node['@type'];
		if ( array_intersect( $types, array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'ScholarlyArticle' ) ) ) {
			$node              = forkposter_with_schema_version( $node, $post->ID );
			$node['isBasedOn'] = $base;
		}
	}

	return $nodes;
}

// Yoast SEO: the Article piece.
add_filter( 'wpseo_schema_article', 'forkposter_yoast_article' );
function forkposter_yoast_article( $data ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}
	$nodes = forkposter_add_based_on_to_nodes( array( $data ) );
	return $nodes[0];
}

// Rank Math: entities keyed by name.
add_filter( 'rank_math/json_ld', 'forkposter_add_based_on_to_nodes', 99 );

// All in One SEO: a list of graph nodes.
add_filter( 'aioseo_schema_output', 'forkposter_add_based_on_to_nodes' );

add_action( 'wp_head', 'forkposter_print_schema', 20 );
function forkposter_print_schema() {
	$post = forkposter_seo_post();
	if ( ! $post || forkposter_seo_plugin_handles_schema() ) {
		return;
	}

	$base = forkposter_based_on_schema( $post );
	if ( ! $base ) {
		return;
	}

	$schema = forkposter_with_schema_version(
		array(
			'@context'      => 'https://schema.org',
			'@type'         => 'BlogPosting',
			'url'           => get_permalink( $post ),
			'headline'      => wp_strip_all_tags( $post->post_title ),
			'datePublished' => get_post_time( DATE_W3C, true, $post ),
			'dateModified'  => get_post_modified_time( DATE_W3C, true, $post ),
			'author'        => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', $post->post_author ),
				'url'   => get_author_posts_url( $post->post_author ),
			),
			'isBasedOn'     => $base,
		),
		$post->ID
	);

	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG ) . "</script>\n";
}

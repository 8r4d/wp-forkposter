<?php
/**
 * How forks are stored and looked up.
 *
 * A fork stores its source in FORKPOSTER_META_PARENT. When the fork is published,
 * the source gets FORKPOSTER_META_SUPERSEDED_BY pointing at it plus the
 * "superseded" term in FORKPOSTER_TAXONOMY. Everything shown on the site is
 * derived from those at render time, so nothing is ever written into post content.
 */

defined( 'ABSPATH' ) || exit;

const FORKPOSTER_META_PARENT        = '_forkposter_parent';
const FORKPOSTER_META_SUPERSEDED_BY = '_forkposter_superseded_by';
const FORKPOSTER_META_NOTE          = '_forkposter_note';
const FORKPOSTER_META_ADDED_TAG     = '_forkposter_added_tag';
const FORKPOSTER_META_VERSION       = '_forkposter_version';
const FORKPOSTER_TAXONOMY           = 'forkposter_state';
const FORKPOSTER_TERM_SUPERSEDED    = 'superseded';

/**
 * Post types that can be forked. Filter to add pages or custom post types.
 */
function forkposter_post_types(): array {
	return (array) apply_filters( 'forkposter_post_types', array( 'post' ) );
}

/**
 * @param WP_Post|int|null $post
 */
function forkposter_supports( $post ): bool {
	$post = get_post( $post );
	return $post instanceof WP_Post && in_array( $post->post_type, forkposter_post_types(), true );
}

add_action( 'init', 'forkposter_register_taxonomy' );
function forkposter_register_taxonomy() {
	// Not shown anywhere on the site. It exists so other code can query or exclude
	// earlier versions with a plain tax_query.
	register_taxonomy(
		FORKPOSTER_TAXONOMY,
		forkposter_post_types(),
		array(
			'labels'            => array( 'name' => __( 'Version state', 'forkposter' ) ),
			'public'            => false,
			'show_ui'           => false,
			'show_in_rest'      => true,
			'show_admin_column' => false,
			'rewrite'           => false,
			'query_var'         => false,
		)
	);
}

function forkposter_get_parent_id( int $post_id ): int {
	return (int) get_post_meta( $post_id, FORKPOSTER_META_PARENT, true );
}

/**
 * The post this one was forked from, if it is still published.
 */
function forkposter_get_published_parent( int $post_id ): ?WP_Post {
	$parent_id = forkposter_get_parent_id( $post_id );
	$parent    = $parent_id ? get_post( $parent_id ) : null;
	return ( $parent && 'publish' === $parent->post_status ) ? $parent : null;
}

/**
 * The published fork that directly replaced this post.
 */
function forkposter_get_successor( int $post_id ): ?WP_Post {
	$successor_id = (int) get_post_meta( $post_id, FORKPOSTER_META_SUPERSEDED_BY, true );
	$successor    = $successor_id ? get_post( $successor_id ) : null;
	return ( $successor && 'publish' === $successor->post_status ) ? $successor : null;
}

/**
 * The newest version in this post's chain (v1 -> v2 -> v3 returns v3 for v1 and v2).
 * Returns null when the post has not been superseded.
 */
function forkposter_get_latest( int $post_id ): ?WP_Post {
	$latest = null;
	$seen   = array( $post_id => true );

	while ( $next = forkposter_get_successor( $latest ? $latest->ID : $post_id ) ) {
		if ( isset( $seen[ $next->ID ] ) ) {
			break;
		}
		$seen[ $next->ID ] = true;
		$latest            = $next;
	}

	return $latest;
}

function forkposter_is_superseded( int $post_id ): bool {
	return null !== forkposter_get_successor( $post_id );
}

function forkposter_is_fork( int $post_id ): bool {
	return null !== forkposter_get_published_parent( $post_id );
}

/**
 * Published forks of a post, newest first.
 *
 * @return WP_Post[]
 */
function forkposter_find_published_forks( int $parent_id, int $exclude_id = 0, int $limit = -1 ): array {
	return get_posts(
		array(
			'post_type'      => forkposter_post_types(),
			'post_status'    => 'publish',
			'meta_key'       => FORKPOSTER_META_PARENT,
			'meta_value'     => $parent_id,
			'post__not_in'   => $exclude_id ? array( $exclude_id ) : array(),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'posts_per_page' => $limit,
		)
	);
}

/**
 * Every published version in this post's line, oldest first: its ancestors,
 * the post itself, then the chain of versions that replaced it.
 *
 * @return WP_Post[]
 */
function forkposter_get_lineage( int $post_id ): array {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array();
	}

	$seen      = array( $post->ID => true );
	$ancestors = array();
	$cursor    = $post->ID;
	while ( $parent = forkposter_get_published_parent( $cursor ) ) {
		if ( isset( $seen[ $parent->ID ] ) ) {
			break;
		}
		$seen[ $parent->ID ] = true;
		array_unshift( $ancestors, $parent );
		$cursor = $parent->ID;
	}

	$descendants = array();
	$cursor      = $post->ID;
	while ( $next = forkposter_get_successor( $cursor ) ) {
		if ( isset( $seen[ $next->ID ] ) ) {
			break;
		}
		$seen[ $next->ID ] = true;
		$descendants[]     = $next;
		$cursor            = $next->ID;
	}

	return array_merge( $ancestors, array( $post ), $descendants );
}

function forkposter_versions_enabled(): bool {
	return (bool) forkposter_setting( 'show_versions' );
}

/**
 * The version number stored on a post ("1", "2.0", ...), or ''.
 */
function forkposter_get_version( int $post_id ): string {
	return (string) get_post_meta( $post_id, FORKPOSTER_META_VERSION, true );
}

function forkposter_set_version( int $post_id, string $version ) {
	$version = substr( trim( sanitize_text_field( $version ) ), 0, 20 );
	if ( '' === $version ) {
		delete_post_meta( $post_id, FORKPOSTER_META_VERSION );
	} else {
		update_post_meta( $post_id, FORKPOSTER_META_VERSION, $version );
	}
}

/**
 * A post's version formatted for display ("v.2.0"), or '' when version numbers
 * are off or the post has none.
 */
function forkposter_version_label( int $post_id ): string {
	$version = forkposter_versions_enabled() ? forkposter_get_version( $post_id ) : '';
	if ( '' === $version ) {
		return '';
	}

	$format = (string) forkposter_setting( 'version_format' );
	return false === strpos( $format, '{version}' ) ? $version : str_replace( '{version}', $version, $format );
}

/**
 * A post's title with its version label appended: "Remote work (v.2)".
 */
function forkposter_title_with_version( WP_Post $post ): string {
	$title = $post->post_title ?: __( '(no title)', 'forkposter' );
	$label = forkposter_version_label( $post->ID );
	return '' === $label ? $title : sprintf( '%s (%s)', $title, $label );
}

/**
 * The number after $version: bumps the first number and zeroes the rest,
 * keeping the format ("1" -> "2", "2.0" -> "3.0", "1.4.2" -> "2.0.0").
 * Returns '' when $version has no number in it.
 */
function forkposter_next_version( string $version ): string {
	if ( ! preg_match( '/^(\D*)(\d+)(.*)$/s', $version, $m ) ) {
		return '';
	}
	return $m[1] . ( (int) $m[2] + 1 ) . preg_replace( '/\d+/', '0', $m[3] );
}

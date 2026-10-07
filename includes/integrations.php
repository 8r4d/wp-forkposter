<?php
/**
 * Compatibility with page cache plugins and Yoast Duplicate Post.
 */

defined( 'ABSPATH' ) || exit;

/*
 * Page caches.
 *
 * Cache plugins clear a post's cached page when that post is saved. When a fork
 * is published, the original only gets new meta and terms (its modified date is
 * deliberately untouched), so no cache plugin notices that its page now needs a
 * version notice. Clear it, and every earlier version, whose notices point at the
 * newest version, explicitly. The fork itself, the home page and the feed are
 * cleared by the cache plugin as part of publishing the fork.
 */

add_action( 'forkposter_superseded', 'forkposter_purge_family_pages', 10, 2 );
add_action( 'forkposter_restored', 'forkposter_purge_family_pages', 10, 2 );
function forkposter_purge_family_pages( int $parent_id, int $fork_id ) {
	$ids    = array();
	$cursor = $parent_id;
	while ( $cursor && ! isset( $ids[ $cursor ] ) && get_post( $cursor ) ) {
		$ids[ $cursor ] = true;
		$cursor         = forkposter_get_parent_id( $cursor );
	}

	foreach ( array_keys( $ids ) as $post_id ) {
		forkposter_purge_post_cache( $post_id );
	}
}

function forkposter_purge_post_cache( int $post_id ) {
	// Object cache, plus the clean_post_cache action that some hosts' page caches listen to.
	clean_post_cache( $post_id );

	if ( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $post_id ); // WP Rocket.
	}
	if ( function_exists( 'w3tc_flush_post' ) ) {
		w3tc_flush_post( $post_id ); // W3 Total Cache.
	}
	if ( function_exists( 'wp_cache_post_change' ) ) {
		wp_cache_post_change( $post_id ); // WP Super Cache.
	}
	if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		sg_cachepress_purge_cache( get_permalink( $post_id ) ); // SiteGround Optimizer.
	}
	do_action( 'litespeed_purge_post', $post_id ); // LiteSpeed Cache; a no-op without it.

	/**
	 * Fires when Forkposter needs a post's cached page cleared. Hook in here for
	 * cache plugins or hosts not handled above.
	 *
	 * @param int $post_id
	 */
	do_action( 'forkposter_purge_post', $post_id );
}

// New labels or notice wording appear on every version's page, so clear everything.
add_action( 'update_option_' . FORKPOSTER_OPTION, 'forkposter_purge_all_caches' );
function forkposter_purge_all_caches() {
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
	if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
		sg_cachepress_purge_everything();
	}
	do_action( 'litespeed_purge_all' );

	/**
	 * Fires when Forkposter's settings change and every cached page may be stale.
	 */
	do_action( 'forkposter_purge_all' );
}

/*
 * Yoast Duplicate Post.
 *
 * It copies a post's meta and terms to the copy by default. A copy of an earlier
 * version would then claim to be superseded, and a copy of a fork would become a
 * phantom extra version. Keep Forkposter's data out of copies in both directions
 * (its Rewrite & Republish feature copies the copy back over the original).
 */

add_filter( 'duplicate_post_excludelist_filter', 'forkposter_duplicate_post_exclude_meta' );
add_filter( 'duplicate_post_blacklist_filter', 'forkposter_duplicate_post_exclude_meta' ); // Before Duplicate Post 4.0.
function forkposter_duplicate_post_exclude_meta( $keys ) {
	return array_merge(
		(array) $keys,
		array( FORKPOSTER_META_PARENT, FORKPOSTER_META_SUPERSEDED_BY, FORKPOSTER_META_NOTE, FORKPOSTER_META_ADDED_TAG, FORKPOSTER_META_VERSION )
	);
}

add_filter( 'duplicate_post_taxonomies_excludelist_filter', 'forkposter_duplicate_post_exclude_taxonomy' );
add_filter( 'duplicate_post_taxonomies_blacklist_filter', 'forkposter_duplicate_post_exclude_taxonomy' );
function forkposter_duplicate_post_exclude_taxonomy( $taxonomies ) {
	return array_merge( (array) $taxonomies, array( FORKPOSTER_TAXONOMY ) );
}

// The optional "superseded" tag is an ordinary tag, so remove it from plain copies.
// Rewrite & Republish copies keep it, since their tags are copied back onto the original.
add_action( 'dp_duplicate_post', 'forkposter_duplicate_post_remove_tag', 20, 2 );
function forkposter_duplicate_post_remove_tag( $new_post_id, $post ) {
	$tag = $post instanceof WP_Post ? get_post_meta( $post->ID, FORKPOSTER_META_ADDED_TAG, true ) : '';
	if ( $tag && ! get_post_meta( $new_post_id, '_dp_is_rewrite_republish_copy', true ) ) {
		wp_remove_object_terms( (int) $new_post_id, $tag, 'post_tag' );
	}
}

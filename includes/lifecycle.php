<?php
/**
 * Keeps the "earlier version" state in sync with the fork's publish status.
 *
 * Only post meta and terms are written, never post fields, so the original's
 * modified date is untouched and it doesn't show up as recently updated.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'transition_post_status', 'forkposter_on_status_change', 10, 3 );
function forkposter_on_status_change( string $new_status, string $old_status, WP_Post $post ) {
	if ( ! forkposter_supports( $post ) ) {
		return;
	}

	$parent_id = forkposter_get_parent_id( $post->ID );
	if ( ! $parent_id ) {
		return;
	}

	if ( 'publish' === $new_status && 'publish' !== $old_status ) {
		forkposter_mark_superseded( $parent_id, $post->ID );
	} elseif ( 'publish' === $old_status && 'publish' !== $new_status ) {
		// Unpublished, scheduled back, or trashed.
		forkposter_release( $parent_id, $post->ID );
	}
}

// Permanent deletion can skip the trash, so it doesn't always go through a status change.
add_action( 'before_delete_post', 'forkposter_on_delete' );
function forkposter_on_delete( int $post_id ) {
	$post      = get_post( $post_id );
	$parent_id = forkposter_get_parent_id( $post_id );

	if ( $post && $parent_id && 'publish' === $post->post_status ) {
		forkposter_release( $parent_id, $post_id );
	}
}

function forkposter_mark_superseded( int $parent_id, int $fork_id ) {
	update_post_meta( $parent_id, FORKPOSTER_META_SUPERSEDED_BY, $fork_id );
	wp_set_object_terms( $parent_id, FORKPOSTER_TERM_SUPERSEDED, FORKPOSTER_TAXONOMY, false );

	$tag = trim( (string) forkposter_setting( 'extra_tag' ) );
	if ( '' !== $tag && is_object_in_taxonomy( get_post_type( $parent_id ), 'post_tag' ) && ! get_post_meta( $parent_id, FORKPOSTER_META_ADDED_TAG, true ) ) {
		// Remember the tag only if this plugin added it, so undo never strips a tag the author chose.
		if ( ! has_term( $tag, 'post_tag', $parent_id ) ) {
			wp_set_object_terms( $parent_id, $tag, 'post_tag', true );
			update_post_meta( $parent_id, FORKPOSTER_META_ADDED_TAG, $tag );
		}
	}

	/**
	 * Fires when a post becomes an earlier version.
	 *
	 * @param int $parent_id The post that now has a newer version.
	 * @param int $fork_id   The newly published version.
	 */
	do_action( 'forkposter_superseded', $parent_id, $fork_id );
}

/**
 * A published fork went away. Point the parent at another published fork if
 * there is one; otherwise restore it to a normal post.
 */
function forkposter_release( int $parent_id, int $fork_id ) {
	if ( (int) get_post_meta( $parent_id, FORKPOSTER_META_SUPERSEDED_BY, true ) !== $fork_id ) {
		return;
	}

	$others = forkposter_find_published_forks( $parent_id, $fork_id, 1 );
	if ( $others ) {
		forkposter_mark_superseded( $parent_id, $others[0]->ID );
		return;
	}

	delete_post_meta( $parent_id, FORKPOSTER_META_SUPERSEDED_BY );
	wp_remove_object_terms( $parent_id, FORKPOSTER_TERM_SUPERSEDED, FORKPOSTER_TAXONOMY );

	$added_tag = get_post_meta( $parent_id, FORKPOSTER_META_ADDED_TAG, true );
	if ( $added_tag ) {
		wp_remove_object_terms( $parent_id, $added_tag, 'post_tag' );
		delete_post_meta( $parent_id, FORKPOSTER_META_ADDED_TAG );
	}

	/**
	 * Fires when a post is no longer an earlier version.
	 *
	 * @param int $parent_id The restored post.
	 * @param int $fork_id   The version that was unpublished or deleted.
	 */
	do_action( 'forkposter_restored', $parent_id, $fork_id );
}

<?php
/**
 * Keeps an original's state in sync with its forks. Whenever a fork is
 * published, unpublished, deleted, or switches between update and branch,
 * forkposter_sync_parent() recalculates the original's links from all of its
 * published forks: the newest update replaces it, and every branch is listed.
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
	if ( $parent_id && ( 'publish' === $new_status ) !== ( 'publish' === $old_status ) ) {
		// Published, or unpublished, scheduled back, or trashed.
		forkposter_sync_parent( $parent_id, $post->ID );
	}
}

// Permanent deletion can skip the trash, so it doesn't always go through a status change.
add_action( 'before_delete_post', 'forkposter_on_delete' );
function forkposter_on_delete( int $post_id ) {
	$post      = get_post( $post_id );
	$parent_id = forkposter_get_parent_id( $post_id );

	if ( $post && $parent_id && 'publish' === $post->post_status ) {
		forkposter_sync_parent( $parent_id, $post_id, true );
	}
}

// Switching a published fork between update and branch.
add_action( 'added_post_meta', 'forkposter_on_kind_change', 10, 3 );
add_action( 'updated_post_meta', 'forkposter_on_kind_change', 10, 3 );
add_action( 'deleted_post_meta', 'forkposter_on_kind_change', 10, 3 );
function forkposter_on_kind_change( $meta_id, $post_id, $meta_key ) {
	if ( FORKPOSTER_META_KIND !== $meta_key || 'publish' !== get_post_status( $post_id ) ) {
		return;
	}

	$parent_id = forkposter_get_parent_id( (int) $post_id );
	if ( $parent_id ) {
		forkposter_sync_parent( $parent_id, (int) $post_id );
	}
}

/**
 * Recalculate an original's links from its published forks.
 *
 * @param int  $parent_id  The original.
 * @param int  $trigger_id The fork whose change prompted this.
 * @param bool $leaving    True when the trigger is about to be deleted and must be ignored.
 */
function forkposter_sync_parent( int $parent_id, int $trigger_id = 0, bool $leaving = false ) {
	$successor_id = 0;
	$branch_ids   = array();
	foreach ( forkposter_find_published_forks( $parent_id, $leaving ? $trigger_id : 0 ) as $fork ) {
		if ( FORKPOSTER_KIND_BRANCH === forkposter_get_kind( $fork->ID ) ) {
			$branch_ids[] = $fork->ID;
		} elseif ( ! $successor_id ) {
			$successor_id = $fork->ID; // Newest first, so this is the newest update.
		}
	}
	sort( $branch_ids );

	$old_successor_id = (int) get_post_meta( $parent_id, FORKPOSTER_META_SUPERSEDED_BY, true );
	$old_branch_ids   = array_map( 'intval', get_post_meta( $parent_id, FORKPOSTER_META_BRANCHED_INTO ) );
	sort( $old_branch_ids );

	if ( $successor_id === $old_successor_id && $branch_ids === $old_branch_ids ) {
		return;
	}

	if ( $successor_id ) {
		update_post_meta( $parent_id, FORKPOSTER_META_SUPERSEDED_BY, $successor_id );
	} else {
		delete_post_meta( $parent_id, FORKPOSTER_META_SUPERSEDED_BY );
	}

	delete_post_meta( $parent_id, FORKPOSTER_META_BRANCHED_INTO );
	foreach ( $branch_ids as $branch_id ) {
		add_post_meta( $parent_id, FORKPOSTER_META_BRANCHED_INTO, $branch_id );
	}

	if ( $successor_id || $branch_ids ) {
		forkposter_mark_moved_on( $parent_id );

		/**
		 * Fires when a post's newer versions change: it got its first or a new
		 * update or branch, or lost one but still has others.
		 *
		 * @param int $parent_id The post that has newer versions.
		 * @param int $fork_id   The fork whose change prompted this.
		 */
		do_action( 'forkposter_superseded', $parent_id, $trigger_id );
	} else {
		forkposter_unmark_moved_on( $parent_id );

		/**
		 * Fires when a post no longer has any published updates or branches.
		 *
		 * @param int $parent_id The restored post.
		 * @param int $fork_id   The fork that was unpublished, deleted or changed.
		 */
		do_action( 'forkposter_restored', $parent_id, $trigger_id );
	}
}

/**
 * Add the state term, and the optional tag that auto-share plugins can exclude.
 */
function forkposter_mark_moved_on( int $post_id ) {
	wp_set_object_terms( $post_id, FORKPOSTER_TERM_SUPERSEDED, FORKPOSTER_TAXONOMY, false );

	$tag = trim( (string) forkposter_setting( 'extra_tag' ) );
	if ( '' !== $tag && is_object_in_taxonomy( get_post_type( $post_id ), 'post_tag' ) && ! get_post_meta( $post_id, FORKPOSTER_META_ADDED_TAG, true ) ) {
		// Remember the tag only if this plugin added it, so undo never strips a tag the author chose.
		if ( ! has_term( $tag, 'post_tag', $post_id ) ) {
			wp_set_object_terms( $post_id, $tag, 'post_tag', true );
			update_post_meta( $post_id, FORKPOSTER_META_ADDED_TAG, $tag );
		}
	}
}

function forkposter_unmark_moved_on( int $post_id ) {
	wp_remove_object_terms( $post_id, FORKPOSTER_TERM_SUPERSEDED, FORKPOSTER_TAXONOMY );

	$added_tag = get_post_meta( $post_id, FORKPOSTER_META_ADDED_TAG, true );
	if ( $added_tag ) {
		wp_remove_object_terms( $post_id, $added_tag, 'post_tag' );
		delete_post_meta( $post_id, FORKPOSTER_META_ADDED_TAG );
	}
}

<?php
/**
 * The "Fork this post" action: copy a published post into a linked draft.
 */

defined( 'ABSPATH' ) || exit;

function forkposter_can_fork( $post ): bool {
	$post = get_post( $post );
	return forkposter_user_can_fork( $post ) && 'publish' === $post->post_status;
}

/**
 * Whether the current user may fork this post once it's published.
 */
function forkposter_user_can_fork( $post ): bool {
	$post = get_post( $post );
	if ( ! forkposter_supports( $post ) ) {
		return false;
	}

	$type = get_post_type_object( $post->post_type );
	return $type && current_user_can( $type->cap->edit_posts ) && current_user_can( 'edit_post', $post->ID );
}

function forkposter_fork_url( int $post_id ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'forkposter_fork',
				'post'   => $post_id,
			),
			admin_url( 'admin-post.php' )
		),
		'forkposter_fork_' . $post_id
	);
}

add_action( 'admin_post_forkposter_fork', 'forkposter_handle_fork_request' );
function forkposter_handle_fork_request() {
	$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
	check_admin_referer( 'forkposter_fork_' . $post_id );

	$post = get_post( $post_id );
	if ( ! forkposter_can_fork( $post ) ) {
		wp_die( esc_html__( 'You can’t fork this post. Only published posts you can edit can be forked.', 'forkposter' ), 403 );
	}

	$fork_id = forkposter_create_fork( $post );
	if ( is_wp_error( $fork_id ) ) {
		wp_die( esc_html( $fork_id->get_error_message() ) );
	}

	wp_safe_redirect( get_edit_post_link( $fork_id, 'raw' ) );
	exit;
}

/**
 * Create a draft copy of $post linked back to it. The source isn't changed
 * until the draft is published (see lifecycle.php).
 *
 * @return int|WP_Error The new draft's ID.
 */
function forkposter_create_fork( WP_Post $post ) {
	// wp_insert_post() unslashes its input, so slash it to keep backslashes in the content.
	$fork_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'      => $post->post_type,
				'post_status'    => 'draft',
				'post_title'     => $post->post_title,
				'post_content'   => $post->post_content,
				'post_excerpt'   => $post->post_excerpt,
				'post_author'    => get_current_user_id(),
				'post_parent'    => $post->post_parent,
				'menu_order'     => $post->menu_order,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
				'post_password'  => $post->post_password,
			)
		),
		true
	);

	if ( is_wp_error( $fork_id ) ) {
		return $fork_id;
	}

	$added_tag = get_post_meta( $post->ID, FORKPOSTER_META_ADDED_TAG, true );

	foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
		if ( FORKPOSTER_TAXONOMY === $taxonomy ) {
			continue;
		}

		$terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'all' ) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			continue;
		}

		$term_ids = array();
		foreach ( $terms as $term ) {
			// Don't carry the plugin's own "superseded" tag onto the new version.
			if ( 'post_tag' === $taxonomy && $added_tag && $term->name === $added_tag ) {
				continue;
			}
			$term_ids[] = (int) $term->term_id;
		}
		wp_set_object_terms( $fork_id, $term_ids, $taxonomy );
	}

	$thumbnail_id = get_post_thumbnail_id( $post );
	if ( $thumbnail_id ) {
		set_post_thumbnail( $fork_id, $thumbnail_id );
	}

	/**
	 * Extra meta keys to copy onto the fork (for example an SEO plugin's description).
	 *
	 * @param string[] $keys
	 * @param WP_Post  $post The post being forked.
	 */
	$meta_keys = (array) apply_filters( 'forkposter_copy_meta_keys', array(), $post );
	foreach ( $meta_keys as $key ) {
		foreach ( get_post_meta( $post->ID, $key ) as $value ) {
			add_post_meta( $fork_id, $key, wp_slash( $value ) );
		}
	}

	update_post_meta( $fork_id, FORKPOSTER_META_PARENT, $post->ID );

	if ( forkposter_versions_enabled() ) {
		forkposter_number_fork( $post->ID, $fork_id );
	}

	/**
	 * Fires after a fork draft is created.
	 *
	 * @param int $fork_id   The new draft.
	 * @param int $parent_id The post it was forked from.
	 */
	do_action( 'forkposter_forked', $fork_id, $post->ID );

	return $fork_id;
}

/**
 * Give a new fork the next version number after its parent. An unnumbered
 * parent becomes "1". A number already used by another fork of the same parent
 * (a branch) is skipped, so forking v.1 twice gives v.2 and v.3.
 */
function forkposter_number_fork( int $parent_id, int $fork_id ) {
	$parent_version = forkposter_get_version( $parent_id );
	if ( '' === $parent_version ) {
		$parent_version = '1';
		forkposter_set_version( $parent_id, $parent_version );
	}

	$siblings = get_posts(
		array(
			'post_type'      => forkposter_post_types(),
			'post_status'    => 'any',
			'meta_key'       => FORKPOSTER_META_PARENT,
			'meta_value'     => $parent_id,
			'post__not_in'   => array( $fork_id ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	$taken = array_map( 'forkposter_get_version', $siblings );

	$version = forkposter_next_version( $parent_version );
	for ( $i = 0; '' !== $version && in_array( $version, $taken, true ) && $i < 100; $i++ ) {
		$version = forkposter_next_version( $version );
	}

	if ( '' !== $version ) {
		forkposter_set_version( $fork_id, $version );
	}
}

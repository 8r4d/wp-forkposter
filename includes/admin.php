<?php
/**
 * Admin UI: row actions, the admin bar link, the editor sidebar box, and post states.
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'post_row_actions', 'forkposter_row_actions', 10, 2 );
add_filter( 'page_row_actions', 'forkposter_row_actions', 10, 2 );
function forkposter_row_actions( array $actions, WP_Post $post ): array {
	if ( forkposter_can_fork( $post ) ) {
		$actions['forkposter_fork'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( forkposter_fork_url( $post->ID ) ),
			/* translators: %s: post title */
			esc_attr( sprintf( __( 'Fork “%s” into a new version', 'forkposter' ), $post->post_title ) ),
			esc_html__( 'Fork', 'forkposter' )
		);
	}
	return $actions;
}

add_action( 'admin_bar_menu', 'forkposter_admin_bar_link', 90 );
function forkposter_admin_bar_link( WP_Admin_Bar $bar ) {
	if ( is_admin() || ! is_singular() ) {
		return;
	}

	$post = get_queried_object();
	if ( $post instanceof WP_Post && forkposter_can_fork( $post ) ) {
		$bar->add_node(
			array(
				'id'    => 'forkposter-fork',
				'title' => __( 'Fork this post', 'forkposter' ),
				'href'  => forkposter_fork_url( $post->ID ),
			)
		);
	}
}

add_filter( 'display_post_states', 'forkposter_post_states', 10, 2 );
function forkposter_post_states( array $states, WP_Post $post ): array {
	if ( ! forkposter_supports( $post ) ) {
		return $states;
	}

	$version = forkposter_version_label( $post->ID );
	$suffix  = '' === $version ? '' : ' · ' . $version;

	if ( forkposter_is_superseded( $post->ID ) ) {
		$states['forkposter_superseded'] = esc_html( forkposter_setting( 'old_label' ) . $suffix );
	} elseif ( forkposter_get_parent_id( $post->ID ) ) {
		$states['forkposter_fork'] = 'publish' === $post->post_status
			? esc_html( forkposter_setting( 'new_label' ) . $suffix )
			: esc_html( __( 'Fork', 'forkposter' ) . $suffix );
	}

	return $states;
}

add_action( 'add_meta_boxes', 'forkposter_add_meta_box' );
function forkposter_add_meta_box() {
	add_meta_box(
		'forkposter',
		__( 'Versions', 'forkposter' ),
		'forkposter_render_meta_box',
		forkposter_post_types(),
		'side',
		'default',
		// The block editor uses the Versions panel in editor.php instead.
		array( '__back_compat_meta_box' => true )
	);
}

/**
 * Admin link to a related post: its editor if you can edit it, otherwise the public page.
 */
function forkposter_admin_post_link( WP_Post $post ): string {
	$url = current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID ) : get_permalink( $post );
	$out = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( forkposter_title_with_version( $post ) ) );

	if ( 'publish' !== $post->post_status ) {
		$status = get_post_status_object( $post->post_status );
		$out   .= ' <em>(' . esc_html( $status ? $status->label : $post->post_status ) . ')</em>';
	}

	return $out;
}

function forkposter_render_meta_box( WP_Post $post ) {
	wp_nonce_field( 'forkposter_save_meta_box', 'forkposter_meta_box_nonce' );

	if ( forkposter_versions_enabled() ) {
		printf(
			'<p><label for="forkposter_version"><strong>%s</strong></label> <input type="text" id="forkposter_version" name="forkposter_version" value="%s" size="8" maxlength="20" placeholder="1"> <span class="description">%s</span></p>',
			esc_html__( 'Version', 'forkposter' ),
			esc_attr( forkposter_get_version( $post->ID ) ),
			esc_html( forkposter_version_label( $post->ID ) ? sprintf( /* translators: %s: formatted version */ __( 'Shown as “%s”', 'forkposter' ), forkposter_version_label( $post->ID ) ) : '' )
		);
	}

	$parent_id = forkposter_get_parent_id( $post->ID );
	$parent    = $parent_id ? get_post( $parent_id ) : null;
	$successor = forkposter_get_successor( $post->ID );
	$latest    = forkposter_get_latest( $post->ID );

	if ( $successor ) {
		printf(
			'<p><strong>%s.</strong> %s</p>',
			esc_html( forkposter_setting( 'old_label' ) ),
			/* translators: %s: linked post title */
			sprintf( esc_html__( 'Replaced by %s.', 'forkposter' ), forkposter_admin_post_link( $successor ) )
		);
		if ( $latest && $latest->ID !== $successor->ID ) {
			/* translators: %s: linked post title */
			printf( '<p>' . esc_html__( 'Newest version: %s', 'forkposter' ) . '</p>', forkposter_admin_post_link( $latest ) );
		}
	}

	if ( $parent ) {
		/* translators: %s: linked post title */
		printf( '<p>' . esc_html__( 'Forked from %s.', 'forkposter' ) . '</p>', forkposter_admin_post_link( $parent ) );
		printf( '<p><a href="%s">%s</a></p>', esc_url( forkposter_compare_url( $parent->ID, $post->ID ) ), esc_html__( 'Compare with previous version', 'forkposter' ) );

		if ( 'publish' !== $post->post_status ) {
			printf(
				'<p class="description">%s</p>',
				sprintf(
					/* translators: %s: the "earlier version" label */
					esc_html__( 'When you publish this, the original stays live, gets labeled “%s”, and links here.', 'forkposter' ),
					esc_html( forkposter_setting( 'old_label' ) )
				)
			);
		}

		printf(
			'<p><label for="forkposter_note"><strong>%s</strong></label><textarea id="forkposter_note" name="forkposter_note" class="widefat" rows="3">%s</textarea><span class="description">%s</span></p>',
			esc_html__( 'Why you revisited it', 'forkposter' ),
			esc_textarea( get_post_meta( $post->ID, FORKPOSTER_META_NOTE, true ) ),
			esc_html__( 'Optional. Shown with the version notice at the top of this post.', 'forkposter' )
		);
	} elseif ( $parent_id ) {
		echo '<p>' . esc_html__( 'The post this was forked from no longer exists.', 'forkposter' ) . '</p>';
	}

	if ( forkposter_can_fork( $post ) ) {
		printf(
			'<p><a class="button" href="%s">%s</a></p><p class="description">%s</p>',
			esc_url( forkposter_fork_url( $post->ID ) ),
			esc_html__( 'Fork this post', 'forkposter' ),
			esc_html__( 'Creates a new draft linked to this post. This post stays live and is labeled as an earlier version once the draft is published.', 'forkposter' )
		);
		if ( $successor ) {
			echo '<p class="description">' . esc_html__( 'This post already has a newer version. Forking it again starts a separate branch.', 'forkposter' ) . '</p>';
		}
	} elseif ( ! $parent_id && ! $successor && 'publish' !== $post->post_status ) {
		echo '<p class="description">' . esc_html__( 'Publish this post to be able to fork it later.', 'forkposter' ) . '</p>';
	}

	if ( $parent || $successor ) {
		printf( '<p><a href="%s">%s</a></p>', esc_url( forkposter_dashboard_url() ), esc_html__( 'All versions →', 'forkposter' ) );
	}
}

add_action( 'save_post', 'forkposter_save_meta_box', 10, 2 );
function forkposter_save_meta_box( int $post_id, WP_Post $post ) {
	if ( ! isset( $_POST['forkposter_meta_box_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['forkposter_meta_box_nonce'] ), 'forkposter_save_meta_box' ) ) {
		return;
	}
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	// The field is only shown while version numbers are on.
	if ( isset( $_POST['forkposter_version'] ) ) {
		forkposter_set_version( $post_id, wp_unslash( $_POST['forkposter_version'] ) );
	}

	// The note field is only shown on forks whose original still exists.
	if ( ! isset( $_POST['forkposter_note'] ) || ! forkposter_get_parent_id( $post_id ) ) {
		return;
	}

	$note = sanitize_textarea_field( wp_unslash( $_POST['forkposter_note'] ) );
	if ( '' === $note ) {
		delete_post_meta( $post_id, FORKPOSTER_META_NOTE );
	} else {
		update_post_meta( $post_id, FORKPOSTER_META_NOTE, $note );
	}
}

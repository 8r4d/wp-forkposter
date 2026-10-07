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
			esc_url( forkposter_fork_url( $post->ID, FORKPOSTER_KIND_UPDATE ) ),
			/* translators: %s: post title */
			esc_attr( sprintf( __( 'Fork “%s” into an updated version', 'forkposter' ), $post->post_title ) ),
			esc_html__( 'Fork', 'forkposter' )
		);
		$actions['forkposter_branch'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( forkposter_fork_url( $post->ID, FORKPOSTER_KIND_BRANCH ) ),
			/* translators: %s: post title */
			esc_attr( sprintf( __( 'Branch “%s” into a new direction', 'forkposter' ), $post->post_title ) ),
			esc_html__( 'Branch', 'forkposter' )
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
	if ( ! $post instanceof WP_Post || ! forkposter_can_fork( $post ) ) {
		return;
	}

	$bar->add_node(
		array(
			'id'    => 'forkposter-fork',
			'title' => __( 'Fork this post', 'forkposter' ),
			'href'  => forkposter_fork_url( $post->ID, FORKPOSTER_KIND_UPDATE ),
		)
	);
	$bar->add_node(
		array(
			'parent' => 'forkposter-fork',
			'id'     => 'forkposter-fork-update',
			'title'  => __( 'As an update', 'forkposter' ),
			'href'   => forkposter_fork_url( $post->ID, FORKPOSTER_KIND_UPDATE ),
		)
	);
	$bar->add_node(
		array(
			'parent' => 'forkposter-fork',
			'id'     => 'forkposter-fork-branch',
			'title'  => __( 'As a branch', 'forkposter' ),
			'href'   => forkposter_fork_url( $post->ID, FORKPOSTER_KIND_BRANCH ),
		)
	);
}

add_filter( 'display_post_states', 'forkposter_post_states', 10, 2 );
function forkposter_post_states( array $states, WP_Post $post ): array {
	if ( ! forkposter_supports( $post ) ) {
		return $states;
	}

	$version = forkposter_version_label( $post->ID );
	$suffix  = '' === $version ? '' : ' · ' . $version;
	$role    = forkposter_role( $post->ID );

	if ( $role ) {
		$states[ 'forkposter_' . $role['key'] ] = esc_html( $role['label'] . $suffix );
	} elseif ( forkposter_get_parent_id( $post->ID ) ) {
		// An unpublished fork.
		$states['forkposter_draft'] = esc_html(
			( forkposter_is_branch( $post->ID ) ? __( 'Branch', 'forkposter' ) : __( 'Fork', 'forkposter' ) ) . $suffix
		);
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

/**
 * What publishing a fork of each kind does, for the editor and the meta box.
 */
function forkposter_kind_descriptions(): array {
	return array(
		FORKPOSTER_KIND_UPDATE => sprintf(
			/* translators: %s: the "earlier version" label */
			__( 'Replaces the original. Once this is published, the original stays live, gets labeled “%s”, and points here.', 'forkposter' ),
			forkposter_setting( 'old_label' )
		),
		FORKPOSTER_KIND_BRANCH => __( 'Takes the piece in a new direction. Once this is published, the original stays live and lists this among its branches.', 'forkposter' ),
	);
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
	$branches  = forkposter_get_branches( $post->ID );

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

	if ( $branches ) {
		/* translators: %s: list of linked post titles */
		printf( '<p>' . esc_html__( 'Branched into %s.', 'forkposter' ) . '</p>', wp_sprintf_l( '%l', array_map( 'forkposter_admin_post_link', $branches ) ) );
	}

	if ( $parent ) {
		/* translators: %s: linked post title */
		printf( '<p>' . esc_html__( 'Forked from %s.', 'forkposter' ) . '</p>', forkposter_admin_post_link( $parent ) );
		printf( '<p><a href="%s">%s</a></p>', esc_url( forkposter_compare_url( $parent->ID, $post->ID ) ), esc_html__( 'Compare with previous version', 'forkposter' ) );

		$kind = forkposter_get_kind( $post->ID );
		echo '<fieldset><legend><strong>' . esc_html__( 'This fork is', 'forkposter' ) . '</strong></legend>';
		foreach ( array(
			FORKPOSTER_KIND_UPDATE => __( 'An update', 'forkposter' ),
			FORKPOSTER_KIND_BRANCH => __( 'A branch', 'forkposter' ),
		) as $value => $label ) {
			printf(
				'<label style="display:block"><input type="radio" name="forkposter_kind" value="%s" %s> %s</label>',
				esc_attr( $value ),
				checked( $kind, $value, false ),
				esc_html( $label )
			);
		}
		printf( '<p class="description">%s</p></fieldset>', esc_html( forkposter_kind_descriptions()[ $kind ] ) );

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
		$descriptions = forkposter_kind_descriptions();
		printf(
			'<p><a class="button" href="%s">%s</a> <a class="button" href="%s">%s</a></p><p class="description"><strong>%s</strong> %s</p><p class="description"><strong>%s</strong> %s</p>',
			esc_url( forkposter_fork_url( $post->ID, FORKPOSTER_KIND_UPDATE ) ),
			esc_html__( 'Fork as update', 'forkposter' ),
			esc_url( forkposter_fork_url( $post->ID, FORKPOSTER_KIND_BRANCH ) ),
			esc_html__( 'Fork as branch', 'forkposter' ),
			esc_html__( 'Update:', 'forkposter' ),
			esc_html( $descriptions[ FORKPOSTER_KIND_UPDATE ] ),
			esc_html__( 'Branch:', 'forkposter' ),
			esc_html( $descriptions[ FORKPOSTER_KIND_BRANCH ] )
		);
		if ( $successor ) {
			echo '<p class="description">' . esc_html__( 'This post already has an update. A new update would replace it as the newest version.', 'forkposter' ) . '</p>';
		}
	} elseif ( ! $parent_id && ! $successor && ! $branches && 'publish' !== $post->post_status ) {
		echo '<p class="description">' . esc_html__( 'Publish this post to be able to fork it later.', 'forkposter' ) . '</p>';
	}

	if ( $parent || $successor || $branches ) {
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

	// The kind and note fields are only shown on forks whose original still exists.
	if ( ! forkposter_get_parent_id( $post_id ) ) {
		return;
	}

	if ( isset( $_POST['forkposter_kind'] ) ) {
		update_post_meta( $post_id, FORKPOSTER_META_KIND, forkposter_sanitize_kind( sanitize_key( $_POST['forkposter_kind'] ) ) );
	}

	if ( ! isset( $_POST['forkposter_note'] ) ) {
		return;
	}

	$note = sanitize_textarea_field( wp_unslash( $_POST['forkposter_note'] ) );
	if ( '' === $note ) {
		delete_post_meta( $post_id, FORKPOSTER_META_NOTE );
	} else {
		update_post_meta( $post_id, FORKPOSTER_META_NOTE, $note );
	}
}

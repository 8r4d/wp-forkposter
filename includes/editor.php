<?php
/**
 * Block editor: the Versions panel in the post sidebar (assets/editor-panel.js),
 * and the post meta it edits. The classic editor keeps the meta box in admin.php.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'forkposter_register_meta' );
function forkposter_register_meta() {
	$fields = array(
		FORKPOSTER_META_VERSION => static function ( $value ) {
			return substr( trim( sanitize_text_field( $value ) ), 0, 20 );
		},
		FORKPOSTER_META_NOTE    => 'sanitize_textarea_field',
	);

	foreach ( forkposter_post_types() as $post_type ) {
		foreach ( $fields as $key => $sanitize ) {
			register_post_meta(
				$post_type,
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					// The block editor reads and saves these through the REST API.
					'show_in_rest'      => true,
					'sanitize_callback' => $sanitize,
					'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
					// Keep the number and note in the post's revision history.
					'revisions_enabled' => post_type_supports( $post_type, 'revisions' ),
				)
			);
		}
	}
}

add_action( 'enqueue_block_editor_assets', 'forkposter_enqueue_editor_panel' );
function forkposter_enqueue_editor_panel() {
	$post = get_post();
	if ( ! forkposter_supports( $post ) ) {
		return;
	}

	wp_enqueue_script(
		'forkposter-editor',
		FORKPOSTER_URL . 'assets/editor-panel.js',
		array( 'wp-components', 'wp-data', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ),
		FORKPOSTER_VERSION,
		true
	);
	wp_add_inline_script( 'forkposter-editor', 'window.forkposterEditor = ' . wp_json_encode( forkposter_editor_data( $post ) ) . ';', 'before' );
	wp_set_script_translations( 'forkposter-editor', 'forkposter' );

	wp_register_style( 'forkposter-editor', false, array(), FORKPOSTER_VERSION );
	wp_enqueue_style( 'forkposter-editor' );
	wp_add_inline_style(
		'forkposter-editor',
		'.forkposter-panel{display:flex;flex-direction:column;gap:12px}.forkposter-panel p{margin:0}.forkposter-panel__fork{display:flex;flex-direction:column;align-items:flex-start;gap:8px}'
	);
}

/**
 * How the panel shows a related post.
 */
function forkposter_editor_post_ref( WP_Post $post ): array {
	$status = '';
	if ( 'publish' !== $post->post_status ) {
		$object = get_post_status_object( $post->post_status );
		$status = $object ? $object->label : $post->post_status;
	}

	return array(
		'id'     => $post->ID,
		'title'  => forkposter_title_with_version( $post ),
		'url'    => current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID, 'raw' ) : get_permalink( $post ),
		'status' => $status,
	);
}

/**
 * Everything the panel needs that isn't part of the post being edited.
 */
function forkposter_editor_data( WP_Post $post ): array {
	$parent_id = forkposter_get_parent_id( $post->ID );
	$parent    = $parent_id ? get_post( $parent_id ) : null;
	if ( $parent && 'trash' === $parent->post_status ) {
		$parent = null;
	}
	$successor = forkposter_get_successor( $post->ID );
	$latest    = forkposter_get_latest( $post->ID );

	return array(
		'versionsEnabled' => forkposter_versions_enabled(),
		'versionFormat'   => (string) forkposter_setting( 'version_format' ),
		'oldLabel'        => (string) forkposter_setting( 'old_label' ),
		'parent'          => $parent ? forkposter_editor_post_ref( $parent ) : null,
		'parentMissing'   => $parent_id && ! $parent,
		'successor'       => $successor ? forkposter_editor_post_ref( $successor ) : null,
		'latest'          => $latest ? forkposter_editor_post_ref( $latest ) : null,
		'canFork'         => forkposter_user_can_fork( $post ),
		'forkUrl'         => forkposter_fork_url( $post->ID ),
		'compareUrl'      => $parent ? forkposter_compare_url( $parent->ID, $post->ID ) : '',
		'dashboardUrl'    => forkposter_dashboard_url(),
	);
}

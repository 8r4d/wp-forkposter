<?php
/**
 * Helper for tests/editor.mjs, mounted as /forkposter-editor-fixture.php.
 *   ?setup            creates an original (with the Version history block) and a fork draft
 *   ?inspect=<id>     reports a post's version, note, status, and its newest revision's copies
 */

require __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
header( 'Content-Type: application/json' );

if ( ! function_exists( 'forkposter_create_fork' ) ) {
	activate_plugin( 'forkposter/forkposter.php' );
	echo wp_json_encode( array( 'activated' => true ) );
	exit;
}

wp_set_current_user( 1 );

if ( isset( $_GET['inspect'] ) ) {
	$id        = absint( $_GET['inspect'] );
	$revisions = wp_get_post_revisions( $id );
	$revision  = reset( $revisions );

	echo wp_json_encode(
		array(
			'status'          => get_post_status( $id ),
			'version'         => get_post_meta( $id, '_forkposter_version', true ),
			'note'            => get_post_meta( $id, '_forkposter_note', true ),
			'revisionVersion' => $revision ? get_metadata( 'post', $revision->ID, '_forkposter_version', true ) : null,
			'revisionNote'    => $revision ? get_metadata( 'post', $revision->ID, '_forkposter_note', true ) : null,
		)
	);
	exit;
}

update_option( 'forkposter_settings', array( 'show_versions' => 1 ) );

$original = wp_insert_post(
	wp_slash(
		array(
			'post_title'   => 'Remote work',
			'post_content' => "<!-- wp:paragraph -->\n<p>Remote work is mostly a productivity question.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:forkposter/history /-->",
			'post_status'  => 'publish',
		)
	)
);
$fork = forkposter_create_fork( get_post( $original ) );
wp_update_post( array( 'ID' => $fork, 'post_title' => 'Remote work, revisited' ) );

echo wp_json_encode(
	array(
		'original'    => $original,
		'fork'        => $fork,
		'originalUrl' => get_permalink( $original ),
	)
);

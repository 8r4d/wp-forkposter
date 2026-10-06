<?php
/**
 * Runs when the plugin is deleted from Plugins > Installed Plugins.
 * Removes the links between versions; the posts themselves are left untouched.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Remove tags this plugin added to older posts.
$added = $wpdb->get_results(
	$wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_forkposter_added_tag' )
);
foreach ( $added as $row ) {
	wp_remove_object_terms( (int) $row->post_id, $row->meta_value, 'post_tag' );
}

foreach ( array( '_forkposter_parent', '_forkposter_superseded_by', '_forkposter_note', '_forkposter_added_tag' ) as $key ) {
	delete_post_meta_by_key( $key );
}

// The taxonomy isn't registered during uninstall, so register it to remove its terms.
register_taxonomy( 'forkposter_state', 'post', array( 'public' => false ) );
$terms = get_terms(
	array(
		'taxonomy'   => 'forkposter_state',
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);
if ( ! is_wp_error( $terms ) ) {
	foreach ( $terms as $term_id ) {
		wp_delete_term( $term_id, 'forkposter_state' );
	}
}

delete_option( 'forkposter_settings' );

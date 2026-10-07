<?php
/**
 * Runs when the plugin is deleted from Plugins > Installed Plugins.
 * Removes the links between versions; the posts themselves are left untouched.
 * On multisite, every site in the network is cleaned up.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

function forkposter_uninstall_site() {
	global $wpdb;

	// Remove tags this plugin added to older posts.
	$added = $wpdb->get_results(
		$wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_forkposter_added_tag' )
	);
	foreach ( $added as $row ) {
		wp_remove_object_terms( (int) $row->post_id, $row->meta_value, 'post_tag' );
	}

	foreach ( array( '_forkposter_parent', '_forkposter_superseded_by', '_forkposter_branched_into', '_forkposter_kind', '_forkposter_note', '_forkposter_added_tag', '_forkposter_version' ) as $key ) {
		delete_post_meta_by_key( $key );
	}

	// The taxonomy isn't registered during uninstall, so register it to remove its terms.
	if ( ! taxonomy_exists( 'forkposter_state' ) ) {
		register_taxonomy( 'forkposter_state', 'post', array( 'public' => false ) );
	}
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
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $forkposter_site_id ) {
		switch_to_blog( $forkposter_site_id );
		forkposter_uninstall_site();
		restore_current_blog();
	}
} else {
	forkposter_uninstall_site();
}

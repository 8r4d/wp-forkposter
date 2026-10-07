<?php
/**
 * RSS: older posts stay in the feed at their original date, labeled and
 * linked to the newer version.
 */

defined( 'ABSPATH' ) || exit;

function forkposter_feed_post(): ?WP_Post {
	$post = get_post();
	return ( forkposter_setting( 'feed_labels' ) && forkposter_supports( $post ) ) ? $post : null;
}

add_filter( 'the_title_rss', 'forkposter_feed_title' );
function forkposter_feed_title( $title ) {
	$post = forkposter_feed_post();
	$role = $post ? forkposter_role( $post->ID ) : null;

	// Only posts that have moved on get a prefix; newer versions read as normal items.
	if ( ! $role || ! in_array( $role['key'], array( 'superseded', 'branched' ), true ) ) {
		return $title;
	}

	return '[' . esc_html( forkposter_badge_text( $role['label'], $post->ID ) ) . '] ' . $title;
}

add_filter( 'the_content_feed', 'forkposter_feed_content' );
function forkposter_feed_content( $content ) {
	$post = forkposter_feed_post();
	return $post ? forkposter_notices_html( $post->ID, 'feed' ) . $content : $content;
}

// The <description> element is plain text, so use the notice text and spell out the URL.
add_filter( 'the_excerpt_rss', 'forkposter_feed_excerpt' );
function forkposter_feed_excerpt( $excerpt ) {
	$post = forkposter_feed_post();
	if ( ! $post ) {
		return $excerpt;
	}

	$latest  = forkposter_get_latest( $post->ID );
	$parent  = forkposter_get_published_parent( $post->ID );
	$targets = array_merge( $latest ? array( $latest ) : array(), forkposter_get_branches( $post->ID ) );
	if ( ! $targets && $parent ) {
		$targets = array( $parent );
	}
	if ( ! $targets ) {
		return $excerpt;
	}

	$notice = wp_strip_all_tags( forkposter_notices_html( $post->ID, 'list' ) );
	$urls   = implode( ' ', array_map( 'get_permalink', $targets ) );
	return esc_html( $notice . ' ' . $urls ) . ' — ' . $excerpt;
}

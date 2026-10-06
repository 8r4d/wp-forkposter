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
	if ( ! $post || ! forkposter_is_superseded( $post->ID ) ) {
		return $title;
	}

	return '[' . esc_html( forkposter_setting( 'old_label' ) ) . '] ' . $title;
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

	$target = forkposter_get_latest( $post->ID ) ?: forkposter_get_published_parent( $post->ID );
	if ( ! $target ) {
		return $excerpt;
	}

	$notice = wp_strip_all_tags( forkposter_notices_html( $post->ID, 'list' ) );
	return esc_html( $notice . ' ' . get_permalink( $target ) ) . ' — ' . $excerpt;
}

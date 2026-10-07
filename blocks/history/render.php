<?php
/**
 * Front-end and editor-preview output for the Version history block.
 *
 * @var array    $attributes
 * @var WP_Block $block
 */

defined( 'ABSPATH' ) || exit;

// Inside a Query Loop the context holds the item's ID; elsewhere use the current post.
$forkposter_post_id = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID();

echo forkposter_history_html( // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
	$forkposter_post_id,
	get_block_wrapper_attributes( array( 'class' => 'forkposter-history' ) )
);

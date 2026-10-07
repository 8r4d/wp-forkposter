<?php
/**
 * Blocks: Version history (blocks/history).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'forkposter_register_blocks' );
function forkposter_register_blocks() {
	register_block_type( FORKPOSTER_DIR . 'blocks/history' );
}

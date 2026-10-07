<?php
/**
 * Integration tests. tests/run.sh mounts this file into a throwaway WordPress
 * Playground site as /forkposter-tests.php and requests it; it prints one line
 * per check and ends with "ALL PASSED" or "N FAILED".
 *
 * Every section creates its own posts and sets its own settings, so sections
 * don't depend on each other. The uninstall section runs last because it
 * deletes all of the plugin's data.
 */

require __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/post.php';
header( 'Content-Type: text/plain; charset=utf-8' );

if ( ! function_exists( 'forkposter_create_fork' ) ) {
	$result = activate_plugin( 'forkposter/forkposter.php' );
	echo is_wp_error( $result ) ? 'Activation failed: ' . $result->get_error_message() : "Activated. Request again to run the tests.\n";
	exit;
}

wp_set_current_user( 1 );

$fp_failures = 0;
$fp_php_errors = array();

// Any PHP warning or notice raised from the plugin's own files is a failure.
set_error_handler(
	function ( $errno, $message, $file, $line ) use ( &$fp_php_errors ) {
		if ( false !== strpos( $file, '/plugins/forkposter/' ) ) {
			$fp_php_errors[] = basename( $file ) . ":$line $message";
		}
		return false;
	}
);

function check( string $label, $condition ) {
	global $fp_failures;
	echo ( $condition ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $condition ) {
		$fp_failures++;
	}
}

function section( string $name ) {
	echo "\n## $name\n";
}

function settings( array $settings ) {
	update_option( FORKPOSTER_OPTION, $settings );
}

function publish( int $post_id, array $fields = array() ) {
	wp_update_post( array_merge( array( 'ID' => $post_id, 'post_status' => 'publish' ), $fields ) );
}

/**
 * Point the main query at $query_args, as if WordPress were rendering that page.
 */
function simulate_query( array $query_args ) {
	$GLOBALS['wp_query']     = new WP_Query( $query_args );
	$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
}

// ---------------------------------------------------------------------------
section( 'Forking' );
settings( array( 'extra_tag' => 'superseded' ) );

$v1          = wp_insert_post( wp_slash( array( 'post_title' => 'Original take', 'post_content' => 'Line with a back\\slash.', 'post_status' => 'publish', 'post_date' => '2025-03-01 10:00:00', 'tags_input' => array( 'ideas' ) ) ) );
$v1_modified = get_post_field( 'post_modified', $v1 );

$v2 = forkposter_create_fork( get_post( $v1 ) );
check( 'fork is a draft', 'draft' === get_post_status( $v2 ) );
check( 'content copied, backslash intact', 'Line with a back\\slash.' === get_post_field( 'post_content', $v2 ) );
check( 'tags copied', has_term( 'ideas', 'post_tag', $v2 ) );
check( 'original untouched while fork is a draft', ! forkposter_is_superseded( $v1 ) );

sleep( 1 );
publish( $v2, array( 'post_title' => 'Better take' ) );
check( 'original superseded once fork is published', forkposter_is_superseded( $v1 ) );
check( 'original has the state term', has_term( FORKPOSTER_TERM_SUPERSEDED, FORKPOSTER_TAXONOMY, $v1 ) );
check( 'original has the optional tag', has_term( 'superseded', 'post_tag', $v1 ) );
check( 'original modified date unchanged', get_post_field( 'post_modified', $v1 ) === $v1_modified );
check( 'fork recognised as a fork', forkposter_is_fork( $v2 ) );

$v3 = forkposter_create_fork( get_post( $v2 ) );
check( 'optional tag not copied onto forks', ! has_term( 'superseded', 'post_tag', $v3 ) );
publish( $v3, array( 'post_title' => 'Best take' ) );
check( 'chain: newest version of v1 is v3', forkposter_get_latest( $v1 )->ID === $v3 );
check( 'chain: lineage is v1, v2, v3', wp_list_pluck( forkposter_get_lineage( $v2 ), 'ID' ) === array( $v1, $v2, $v3 ) );

// ---------------------------------------------------------------------------
section( 'Front end' );

simulate_query( array( 'p' => $v1 ) );
the_post();
$html = apply_filters( 'the_content', get_post_field( 'post_content', $v1 ) );
check( 'single notice on v1 links to v3', false !== strpos( $html, 'forkposter-notice--single' ) && false !== strpos( $html, get_permalink( $v3 ) ) );
check( 'block theme active (Playground default)', wp_is_block_theme() );
$title = render_block( array( 'blockName' => 'core/post-title', 'attrs' => array( 'level' => 1 ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) );
check( 'Post Title block gets the badge inside the heading', false !== strpos( $title, 'forkposter-badge--superseded' ) && preg_match( '#</span>\s*</h1>#', $title ) );
wp_reset_postdata();

simulate_query( array( 'post_type' => 'post', 'posts_per_page' => 20 ) );
check( 'earlier version still listed on the home page', in_array( $v1, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), true ) );
while ( have_posts() ) {
	the_post();
	if ( get_the_ID() === $v1 ) {
		check( 'list note on the excerpt', false !== strpos( apply_filters( 'the_excerpt', get_the_excerpt() ), 'forkposter-notice--list' ) );
		check( 'automatic excerpt has no notice text in it', false === strpos( get_the_excerpt(), 'revisited' ) );
	}
}
wp_reset_postdata();

simulate_query( array( 'post_type' => 'post' ) );
$GLOBALS['wp_query']->is_feed = true;
$GLOBALS['post']               = get_post( $v1 );
setup_postdata( $GLOBALS['post'] );
check( 'feed title prefixed', 0 === strpos( apply_filters( 'the_title_rss', 'Original take' ), '[Earlier version]' ) );
wp_reset_postdata();

// ---------------------------------------------------------------------------
section( 'Undo and branches' );

wp_trash_post( $v3 );
check( 'v2 restored after v3 is trashed', ! forkposter_is_superseded( $v2 ) );
check( 'v1 still points at v2', forkposter_get_latest( $v1 )->ID === $v2 );
wp_update_post( array( 'ID' => $v2, 'post_status' => 'draft' ) );
check( 'v1 restored after v2 is unpublished', ! forkposter_is_superseded( $v1 ) );
check( 'state term removed', ! has_term( FORKPOSTER_TERM_SUPERSEDED, FORKPOSTER_TAXONOMY, $v1 ) );
check( 'optional tag removed', ! has_term( 'superseded', 'post_tag', $v1 ) );
check( "author's own tag kept", has_term( 'ideas', 'post_tag', $v1 ) );

$a = forkposter_create_fork( get_post( $v1 ) );
publish( $a );
$b = forkposter_create_fork( get_post( $v1 ) );
publish( $b );
check( 'branch: original points at the newest fork', forkposter_get_successor( $v1 )->ID === $b );
wp_delete_post( $b, true );
check( 'branch: falls back to the other fork after delete', forkposter_get_successor( $v1 )->ID === $a );

// ---------------------------------------------------------------------------
section( 'Version numbers' );

check( 'next 1 -> 2', '2' === forkposter_next_version( '1' ) );
check( 'next 2.0 -> 3.0', '3.0' === forkposter_next_version( '2.0' ) );
check( 'next 1.4.2 -> 2.0.0', '2.0.0' === forkposter_next_version( '1.4.2' ) );
check( 'next v9 -> v10', 'v10' === forkposter_next_version( 'v9' ) );
check( 'next of a non-number is empty', '' === forkposter_next_version( 'draft' ) );

settings( array() );
$o1 = wp_insert_post( array( 'post_title' => 'Unnumbered', 'post_status' => 'publish' ) );
$o2 = forkposter_create_fork( get_post( $o1 ) );
publish( $o2 );
check( 'off: no numbers assigned', '' === forkposter_get_version( $o1 ) && '' === forkposter_get_version( $o2 ) );
check( 'off: badge has no number', false === strpos( forkposter_badge_html( $o1 ), '·' ) );

settings( array( 'show_versions' => 1 ) );
$n1 = wp_insert_post( array( 'post_title' => 'Remote work', 'post_status' => 'publish' ) );
$n2 = forkposter_create_fork( get_post( $n1 ) );
check( 'unnumbered parent becomes 1', '1' === forkposter_get_version( $n1 ) );
check( 'fork becomes 2', '2' === forkposter_get_version( $n2 ) );
$nb = forkposter_create_fork( get_post( $n1 ) );
check( 'second fork of the same parent becomes 3', '3' === forkposter_get_version( $nb ) );
publish( $n2, array( 'post_title' => 'Remote work, revisited' ) );
forkposter_set_version( $n2, '2.0' );
$n3 = forkposter_create_fork( get_post( $n2 ) );
check( 'fork of 2.0 becomes 3.0', '3.0' === forkposter_get_version( $n3 ) );

check( 'label uses the format', 'v.2.0' === forkposter_version_label( $n2 ) );
check( 'badge on original includes v.1', false !== strpos( forkposter_badge_html( $n1 ), 'Earlier version · v.1' ) );
check( 'badge on fork includes v.2.0', false !== strpos( forkposter_badge_html( $n2 ), 'Revised · v.2.0' ) );
check( "original's notice links to “… (v.2.0)”", false !== strpos( forkposter_notices_html( $n1, 'single' ), 'Remote work, revisited (v.2.0)' ) );
check( "fork's notice links to “… (v.1)”", false !== strpos( forkposter_notices_html( $n2, 'single' ), 'Remote work (v.1)' ) );
$states = forkposter_post_states( array(), get_post( $n1 ) );
check( 'post list state includes the number', 'Earlier version · v.1' === ( $states['forkposter_superseded'] ?? '' ) );

settings( array( 'show_versions' => 1, 'version_format' => 'Version {version}' ) );
check( 'custom format', 'Version 2.0' === forkposter_version_label( $n2 ) );
settings( array( 'show_versions' => 1 ) );

$GLOBALS['post'] = get_post( $n2 );
setup_postdata( $GLOBALS['post'] );
$history = do_shortcode( '[forkposter_history]' );
check( 'history shortcode lists numbered versions', false !== strpos( $history, 'Remote work (v.1)' ) && false !== strpos( $history, '(v.2.0)' ) );
$block = do_blocks( '<!-- wp:forkposter/history /-->' );
check( 'history block renders on a post with versions', false !== strpos( $block, 'wp-block-forkposter-history' ) );
wp_reset_postdata();
$solo = wp_insert_post( array( 'post_title' => 'Standalone', 'post_status' => 'publish' ) );
check( 'history renders nothing on a post without versions', '' === forkposter_history_html( $solo ) );

// ---------------------------------------------------------------------------
section( 'Classic editor meta box' );

ob_start();
forkposter_render_meta_box( get_post( $n2 ) );
$box = ob_get_clean();
check( 'version field shows the stored number', false !== strpos( $box, 'name="forkposter_version" value="2.0"' ) );
update_post_meta( $n2, FORKPOSTER_META_NOTE, 'keep me' );
$_POST = array(
	'forkposter_meta_box_nonce' => wp_create_nonce( 'forkposter_save_meta_box' ),
	'forkposter_version'        => ' 2.1 ',
);
forkposter_save_meta_box( $n2, get_post( $n2 ) );
check( 'saving trims the number', '2.1' === forkposter_get_version( $n2 ) );
check( 'saving without the note field keeps the note', 'keep me' === get_post_meta( $n2, FORKPOSTER_META_NOTE, true ) );
$_POST = array();
forkposter_set_version( $n2, '2.0' );

// ---------------------------------------------------------------------------
section( 'Block editor data' );

$registered = get_registered_meta_keys( 'post', 'post' );
check( 'version meta is exposed to the block editor', ! empty( $registered[ FORKPOSTER_META_VERSION ]['show_in_rest'] ) );
check( 'note meta is exposed to the block editor', ! empty( $registered[ FORKPOSTER_META_NOTE ]['show_in_rest'] ) );
check( 'version meta is kept in revisions', ! empty( $registered[ FORKPOSTER_META_VERSION ]['revisions_enabled'] ) );
$data = forkposter_editor_data( get_post( $n2 ) );
check( 'panel data names the parent', $data['parent'] && $data['parent']['id'] === $n1 );
check( 'panel data includes a compare link', false !== strpos( $data['compareUrl'], 'from=' . $n1 ) );

// ---------------------------------------------------------------------------
section( 'Search engine hints' );

$v2_url = get_permalink( $n2 );
simulate_query( array( 'p' => $n2 ) );
ob_start();
do_action( 'wp_head' );
$head = ob_get_clean();
check( 'fork links to its predecessor', false !== strpos( $head, 'rel="predecessor-version" href="' . get_permalink( $n1 ) . '"' ) );
check( 'fork outputs isBasedOn structured data', (bool) preg_match( '#"isBasedOn":\{[^}]*"url":"' . preg_quote( get_permalink( $n1 ), '#' ) . '"#', $head ) );
check( 'structured data includes the version', false !== strpos( $head, '"version":"2.0"' ) );
wp_reset_postdata();

// ---------------------------------------------------------------------------
section( 'Page cache clearing' );

$purged = array();
add_action(
	'forkposter_purge_post',
	function ( $post_id ) use ( &$purged ) {
		$purged[] = $post_id;
	}
);
$c1 = wp_insert_post( array( 'post_title' => 'Cache v1', 'post_status' => 'publish' ) );
$c2 = forkposter_create_fork( get_post( $c1 ) );
publish( $c2 );
check( 'publishing a fork clears the original', in_array( $c1, $purged, true ) );
$purged = array();
$c3     = forkposter_create_fork( get_post( $c2 ) );
publish( $c3 );
check( 'publishing v3 clears v2 and v1', in_array( $c2, $purged, true ) && in_array( $c1, $purged, true ) );
$purged = array();
wp_update_post( array( 'ID' => $c3, 'post_status' => 'draft' ) );
check( 'unpublishing a fork clears the earlier versions', in_array( $c2, $purged, true ) && in_array( $c1, $purged, true ) );
$before = did_action( 'forkposter_purge_all' );
settings( array( 'show_versions' => 1, 'old_label' => 'Older take' ) );
check( 'changing settings clears everything', did_action( 'forkposter_purge_all' ) > $before );
settings( array( 'show_versions' => 1 ) );

// ---------------------------------------------------------------------------
section( 'Duplicate post plugins' );

$excluded = apply_filters( 'duplicate_post_excludelist_filter', array() );
check( 'Duplicate Post skips the parent and superseded links', in_array( FORKPOSTER_META_PARENT, $excluded, true ) && in_array( FORKPOSTER_META_SUPERSEDED_BY, $excluded, true ) );
check( 'Duplicate Post skips the state taxonomy', in_array( FORKPOSTER_TAXONOMY, apply_filters( 'duplicate_post_taxonomies_excludelist_filter', array() ), true ) );

// A plugin that copies every meta field of an earlier version.
$copy = wp_insert_post( array( 'post_title' => 'Copy of Cache v1', 'post_status' => 'publish' ) );
update_post_meta( $copy, FORKPOSTER_META_SUPERSEDED_BY, $c2 );
check( 'a copied "replaced by" link is ignored', ! forkposter_is_superseded( $copy ) && null === forkposter_get_latest( $copy ) );
check( 'the real original is unaffected', forkposter_get_successor( $c1 )->ID === $c2 );

settings( array( 'extra_tag' => 'superseded' ) );
$t1 = wp_insert_post( array( 'post_title' => 'Tagged v1', 'post_status' => 'publish' ) );
$t2 = forkposter_create_fork( get_post( $t1 ) );
publish( $t2 );
$plain_copy = wp_insert_post( array( 'post_title' => 'Plain copy', 'post_status' => 'draft', 'tags_input' => array( 'superseded' ) ) );
do_action( 'dp_duplicate_post', $plain_copy, get_post( $t1 ), 'draft' );
check( 'plain copy loses the optional tag', ! has_term( 'superseded', 'post_tag', $plain_copy ) );
$rewrite_copy = wp_insert_post( array( 'post_title' => 'Rewrite copy', 'post_status' => 'draft', 'tags_input' => array( 'superseded' ) ) );
update_post_meta( $rewrite_copy, '_dp_is_rewrite_republish_copy', $t1 );
do_action( 'dp_duplicate_post', $rewrite_copy, get_post( $t1 ), 'draft' );
check( 'Rewrite & Republish copy keeps it', has_term( 'superseded', 'post_tag', $rewrite_copy ) );

// ---------------------------------------------------------------------------
section( 'Uninstall' );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'forkposter/forkposter.php' );
}
include WP_PLUGIN_DIR . '/forkposter/uninstall.php';

global $wpdb;
$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_forkposter\\_%'" );
check( 'all plugin meta removed', 0 === $left );
check( 'settings removed', false === get_option( FORKPOSTER_OPTION ) );
check( 'state terms removed', 0 === count( get_terms( array( 'taxonomy' => FORKPOSTER_TAXONOMY, 'hide_empty' => false ) ) ) );
check( 'optional tag removed from earlier versions', ! has_term( 'superseded', 'post_tag', $t1 ) );
check( 'posts themselves kept', 'publish' === get_post_status( $t1 ) && 'publish' === get_post_status( $t2 ) );

// ---------------------------------------------------------------------------
restore_error_handler();
section( 'PHP warnings from plugin code' );
check( 'none' . ( $fp_php_errors ? ': ' . implode( '; ', array_unique( $fp_php_errors ) ) : '' ), ! $fp_php_errors );

echo "\n" . ( $fp_failures ? "$fp_failures FAILED" : 'ALL PASSED' ) . "\n";

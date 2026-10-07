<?php
/**
 * Posts > Versions: every family of forked posts, plus a private
 * comparison view between any two versions in a family.
 */

defined( 'ABSPATH' ) || exit;

const FORKPOSTER_DASHBOARD_SLUG = 'forkposter-versions';

add_action( 'admin_menu', 'forkposter_add_dashboard_page' );
function forkposter_add_dashboard_page() {
	$hook = add_submenu_page(
		'edit.php',
		__( 'Versions', 'forkposter' ),
		__( 'Versions', 'forkposter' ),
		'edit_posts',
		FORKPOSTER_DASHBOARD_SLUG,
		'forkposter_render_dashboard'
	);

	add_action(
		'admin_print_styles-' . $hook,
		function () {
			// Core's diff table styles live in the revisions stylesheet.
			wp_enqueue_style( 'revisions' );
			wp_enqueue_style( 'forkposter-admin', FORKPOSTER_URL . 'assets/forkposter-admin.css', array(), FORKPOSTER_VERSION );
		}
	);
}

function forkposter_dashboard_url( array $args = array() ): string {
	return add_query_arg( array_merge( array( 'page' => FORKPOSTER_DASHBOARD_SLUG ), $args ), admin_url( 'edit.php' ) );
}

function forkposter_compare_url( int $from_id, int $to_id ): string {
	return forkposter_dashboard_url(
		array(
			'from' => $from_id,
			'to'   => $to_id,
		)
	);
}

/**
 * A post counts as part of a family only while it exists outside the trash.
 */
function forkposter_is_live_member( $post ): bool {
	return $post instanceof WP_Post && ! in_array( $post->post_status, array( 'trash', 'auto-draft' ), true );
}

/**
 * Group every forked post with its ancestors into families.
 *
 * @return array[] Each family: 'root' => WP_Post, 'children' => array( parent ID => int[] ),
 *                 'members' => int[], 'updated' => latest post_modified_gmt in the family.
 */
function forkposter_get_families(): array {
	global $wpdb;

	$types        = forkposter_post_types();
	$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

	// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built above.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT pm.post_id, pm.meta_value AS parent_id
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s
			AND p.post_status NOT IN ('trash', 'auto-draft')
			AND p.post_type IN ($placeholders)",
			array_merge( array( FORKPOSTER_META_PARENT ), $types )
		)
	);

	$parent_of = array();
	foreach ( $rows as $row ) {
		$parent_of[ (int) $row->post_id ] = (int) $row->parent_id;
	}

	_prime_post_caches( array_unique( array_merge( array_keys( $parent_of ), array_values( $parent_of ) ) ), false, false );

	// A fork whose parent is gone or trashed starts its own family.
	foreach ( $parent_of as $child_id => $parent_id ) {
		if ( ! forkposter_is_live_member( get_post( $parent_id ) ) ) {
			unset( $parent_of[ $child_id ] );
		}
	}

	$families = array();
	foreach ( $parent_of as $child_id => $parent_id ) {
		$root_id = $parent_id;
		$seen    = array( $child_id => true );
		while ( isset( $parent_of[ $root_id ] ) && ! isset( $seen[ $root_id ] ) ) {
			$seen[ $root_id ] = true;
			$root_id          = $parent_of[ $root_id ];
		}

		if ( ! isset( $families[ $root_id ] ) ) {
			$families[ $root_id ] = array(
				'root'     => get_post( $root_id ),
				'children' => array(),
				'members'  => array( $root_id ),
			);
		}
		$families[ $root_id ]['children'][ $parent_id ][] = $child_id;
		$families[ $root_id ]['members'][]                 = $child_id;
	}

	foreach ( $families as &$family ) {
		$family['members'] = array_values( array_unique( $family['members'] ) );
		$family['updated'] = max( array_map( fn( $id ) => get_post( $id )->post_modified_gmt, $family['members'] ) );

		foreach ( $family['children'] as &$kids ) {
			// post_date rather than post_date_gmt: drafts have an empty GMT date, which would sort them first.
			usort( $kids, fn( $a, $b ) => strcmp( get_post( $a )->post_date, get_post( $b )->post_date ) ?: $a <=> $b );
		}
	}
	unset( $family, $kids );

	// Only show families that include something the current user can edit.
	$families = array_filter(
		$families,
		function ( $family ) {
			foreach ( $family['members'] as $id ) {
				if ( current_user_can( 'edit_post', $id ) ) {
					return true;
				}
			}
			return false;
		}
	);

	uasort( $families, fn( $a, $b ) => strcmp( $b['updated'], $a['updated'] ) );

	return $families;
}

/**
 * The family containing a post, or null.
 */
function forkposter_get_family_of( int $post_id ): ?array {
	foreach ( forkposter_get_families() as $family ) {
		if ( in_array( $post_id, $family['members'], true ) ) {
			return $family;
		}
	}
	return null;
}

/**
 * Members of a family in tree order (each version followed by its forks), with depth.
 *
 * @return array[] Each item: array( 'post' => WP_Post, 'depth' => int ).
 */
function forkposter_flatten_family( array $family ): array {
	$out  = array();
	$walk = function ( int $id, int $depth ) use ( &$walk, &$out, $family ) {
		$out[] = array(
			'post'  => get_post( $id ),
			'depth' => $depth,
		);
		foreach ( $family['children'][ $id ] ?? array() as $child_id ) {
			$walk( $child_id, $depth + 1 );
		}
	};
	$walk( $family['root']->ID, 0 );

	return $out;
}

/**
 * Turn post content into readable prose for comparing: block markup removed,
 * one paragraph per line, so the diff highlights changed words within paragraphs.
 */
function forkposter_content_as_text( string $content ): string {
	$text = preg_replace( '/<!--\s*\/?wp:.*?-->/s', '', $content );
	$text = preg_replace( '#<li\b[^>]*>#i', '• ', $text );
	$text = preg_replace( '#<(?:br|hr)\b[^>]*>|</(?:p|h[1-6]|li|blockquote|pre|figcaption|div|figure|tr)>#i', "$0\n", $text );
	$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, get_bloginfo( 'charset' ) );

	$lines = array_filter( array_map( 'trim', preg_split( '/\R/u', $text ) ), 'strlen' );
	return implode( "\n", $lines );
}

function forkposter_word_count( WP_Post $post ): int {
	$text = trim( forkposter_content_as_text( $post->post_content ) );
	return '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) );
}

/**
 * Short status label for a version: Current, Earlier version, Branched, Draft, Scheduled, ...
 */
function forkposter_version_status( WP_Post $post ): array {
	if ( 'publish' === $post->post_status ) {
		if ( forkposter_is_superseded( $post->ID ) ) {
			return array( forkposter_setting( 'old_label' ), 'superseded' );
		}
		if ( forkposter_is_branched( $post->ID ) ) {
			return array( forkposter_setting( 'branched_label' ), 'branched' );
		}
		return array( __( 'Current', 'forkposter' ), 'current' );
	}

	$status = get_post_status_object( $post->post_status );
	return array( $status ? $status->label : $post->post_status, 'unpublished' );
}

function forkposter_render_dashboard() {
	$from = isset( $_GET['from'] ) ? absint( $_GET['from'] ) : 0;
	$to   = isset( $_GET['to'] ) ? absint( $_GET['to'] ) : 0;

	echo '<div class="wrap forkposter-dashboard">';

	if ( $from && $to ) {
		forkposter_render_compare( $from, $to );
	} else {
		forkposter_render_families();
	}

	echo '</div>';
}

function forkposter_render_families() {
	$families = forkposter_get_families();

	echo '<h1>' . esc_html__( 'Versions', 'forkposter' ) . '</h1>';
	echo '<p class="description">' . esc_html__( 'Every post you’ve forked, grouped with all its versions. Most recently changed first.', 'forkposter' ) . '</p>';

	if ( ! $families ) {
		echo '<div class="forkposter-empty"><p>' . esc_html__( 'No forked posts yet. Use “Fork” under any published post in Posts > All Posts to start a new version.', 'forkposter' ) . '</p></div>';
		return;
	}

	foreach ( $families as $family ) {
		$items  = forkposter_flatten_family( $family );
		$root   = $family['root'];
		$latest = forkposter_get_latest( $root->ID );

		echo '<section class="forkposter-family">';
		echo '<header class="forkposter-family__header">';
		printf( '<h2>%s</h2>', esc_html( $root->post_title ?: __( '(no title)', 'forkposter' ) ) );
		printf(
			'<span class="forkposter-family__meta">%s</span>',
			esc_html(
				sprintf(
					/* translators: 1: number of versions, 2: human time difference */
					_n( '%1$d version · changed %2$s ago', '%1$d versions · changed %2$s ago', count( $items ), 'forkposter' ),
					count( $items ),
					human_time_diff( strtotime( $family['updated'] . ' UTC' ) )
				)
			)
		);
		if ( $latest ) {
			printf(
				'<a class="button button-small" href="%s">%s</a>',
				esc_url( forkposter_compare_url( $root->ID, $latest->ID ) ),
				esc_html__( 'Compare original → current', 'forkposter' )
			);
		}
		echo '</header>';

		echo '<table class="widefat striped forkposter-family__table"><thead><tr>';
		printf(
			'<th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col" class="num">%s</th><th scope="col">%s</th>',
			esc_html__( 'Version', 'forkposter' ),
			esc_html__( 'Status', 'forkposter' ),
			esc_html__( 'Date', 'forkposter' ),
			esc_html__( 'Words', 'forkposter' ),
			esc_html__( 'Actions', 'forkposter' )
		);
		echo '</tr></thead><tbody>';

		foreach ( $items as $item ) {
			forkposter_render_version_row( $item['post'], $item['depth'] );
		}

		echo '</tbody></table></section>';
	}
}

function forkposter_render_version_row( WP_Post $post, int $depth ) {
	list( $status_label, $status_key ) = forkposter_version_status( $post );

	$title = esc_html( forkposter_title_with_version( $post ) );
	if ( current_user_can( 'edit_post', $post->ID ) ) {
		$title = sprintf( '<a class="row-title" href="%s">%s</a>', esc_url( get_edit_post_link( $post->ID ) ), $title );
	}

	$actions   = array();
	$parent_id = forkposter_get_parent_id( $post->ID );
	if ( $depth > 0 && $parent_id ) {
		$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( forkposter_compare_url( $parent_id, $post->ID ) ), esc_html__( 'Compare with previous', 'forkposter' ) );
	}
	if ( 'publish' === $post->post_status ) {
		$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( get_permalink( $post ) ), esc_html__( 'View', 'forkposter' ) );
	} elseif ( is_post_type_viewable( $post->post_type ) && current_user_can( 'edit_post', $post->ID ) ) {
		$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( get_preview_post_link( $post ) ), esc_html__( 'Preview', 'forkposter' ) );
	}
	if ( forkposter_can_fork( $post ) ) {
		$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( forkposter_fork_url( $post->ID, FORKPOSTER_KIND_UPDATE ) ), esc_html__( 'Fork', 'forkposter' ) );
		$actions[] = sprintf( '<a href="%s">%s</a>', esc_url( forkposter_fork_url( $post->ID, FORKPOSTER_KIND_BRANCH ) ), esc_html__( 'Branch', 'forkposter' ) );
	}

	if ( $depth > 0 && forkposter_is_branch( $post->ID ) ) {
		$title .= sprintf( ' <span class="forkposter-kind">%s</span>', esc_html( forkposter_setting( 'branch_label' ) ) );
	}

	$date = 'publish' === $post->post_status || 'future' === $post->post_status
		? get_the_date( '', $post )
		/* translators: %s: date */
		: sprintf( __( 'Edited %s', 'forkposter' ), get_the_modified_date( '', $post ) );

	printf(
		'<tr><td><span class="forkposter-tree" style="--depth:%1$d">%2$s</span></td><td><span class="forkposter-status forkposter-status--%3$s">%4$s</span></td><td>%5$s</td><td class="num">%6$s</td><td>%7$s</td></tr>',
		(int) $depth,
		( $depth ? '<span class="forkposter-tree__branch" aria-hidden="true">↳</span> ' : '' ) . $title,
		esc_attr( $status_key ),
		esc_html( $status_label ),
		esc_html( $date ),
		esc_html( number_format_i18n( forkposter_word_count( $post ) ) ),
		implode( ' | ', $actions )
	);
}

function forkposter_render_compare( int $from_id, int $to_id ) {
	$from = get_post( $from_id );
	$to   = get_post( $to_id );

	if ( ! forkposter_supports( $from ) || ! forkposter_supports( $to ) || ! current_user_can( 'edit_post', $from_id ) || ! current_user_can( 'edit_post', $to_id ) ) {
		wp_die( esc_html__( 'You can’t compare these posts.', 'forkposter' ), 403 );
	}

	$source = ! empty( $_GET['source'] );
	$family = forkposter_get_family_of( $to_id );

	printf(
		'<p><a href="%s">← %s</a></p><h1>%s</h1>',
		esc_url( forkposter_dashboard_url() ),
		esc_html__( 'All versions', 'forkposter' ),
		esc_html__( 'Compare versions', 'forkposter' )
	);

	// Pick any two versions in the family.
	if ( $family ) {
		$options = function ( int $selected ) use ( $family ) {
			$html = '';
			foreach ( forkposter_flatten_family( $family ) as $item ) {
				list( $status ) = forkposter_version_status( $item['post'] );
				$html          .= sprintf(
					'<option value="%d"%s>%s%s (%s, %s)</option>',
					$item['post']->ID,
					selected( $item['post']->ID, $selected, false ),
					str_repeat( '— ', $item['depth'] ),
					esc_html( forkposter_title_with_version( $item['post'] ) ),
					esc_html( $status ),
					esc_html( get_the_date( '', $item['post'] ) )
				);
			}
			return $html;
		};

		printf(
			'<form class="forkposter-compare-form" method="get" action="%1$s">
				<input type="hidden" name="page" value="%2$s">
				<label>%3$s <select name="from">%4$s</select></label>
				<label>%5$s <select name="to">%6$s</select></label>
				<label><input type="checkbox" name="source" value="1"%7$s> %8$s</label>
				<button class="button">%9$s</button>
			</form>',
			esc_url( admin_url( 'edit.php' ) ),
			esc_attr( FORKPOSTER_DASHBOARD_SLUG ),
			esc_html__( 'From', 'forkposter' ),
			$options( $from_id ), // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the closure.
			esc_html__( 'To', 'forkposter' ),
			$options( $to_id ), // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in the closure.
			checked( $source, true, false ),
			esc_html__( 'Show HTML source', 'forkposter' ),
			esc_html__( 'Compare', 'forkposter' )
		);
	}

	$from_words = forkposter_word_count( $from );
	$to_words   = forkposter_word_count( $to );
	$delta      = $to_words - $from_words;
	printf(
		'<p class="forkposter-compare-summary">%s</p>',
		esc_html(
			sprintf(
				/* translators: 1: word count before, 2: word count after, 3: signed difference */
				__( '%1$s words → %2$s words (%3$s)', 'forkposter' ),
				number_format_i18n( $from_words ),
				number_format_i18n( $to_words ),
				( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta )
			)
		)
	);

	$headings = array(
		'title_left'      => forkposter_compare_heading( $from ),
		'title_right'     => forkposter_compare_heading( $to ),
		'show_split_view' => true,
	);

	$title_diff = wp_text_diff( $from->post_title, $to->post_title, array_merge( $headings, array( 'title' => esc_html__( 'Title', 'forkposter' ) ) ) );

	$content_diff = $source
		? wp_text_diff( normalize_whitespace( $from->post_content ), normalize_whitespace( $to->post_content ), array_merge( $headings, array( 'title' => esc_html__( 'Content (HTML)', 'forkposter' ) ) ) )
		: wp_text_diff( forkposter_content_as_text( $from->post_content ), forkposter_content_as_text( $to->post_content ), array_merge( $headings, array( 'title' => esc_html__( 'Content', 'forkposter' ) ) ) );

	if ( ! $title_diff && ! $content_diff ) {
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'These versions have the same title and content.', 'forkposter' ) . '</p></div>';
		return;
	}

	echo '<div class="forkposter-diff revisions-diff">';
	// wp_text_diff() returns escaped table markup.
	echo $title_diff . $content_diff; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</div>';
}

function forkposter_compare_heading( WP_Post $post ): string {
	list( $status ) = forkposter_version_status( $post );
	// wp_text_diff() prints headings as given, so escape here.
	return esc_html( sprintf( '%s — %s, %s', forkposter_title_with_version( $post ), $status, get_the_date( '', $post ) ) );
}

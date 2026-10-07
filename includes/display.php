<?php
/**
 * Front-end labels: badges on titles, notices on single posts, short notes in
 * post lists, and the [forkposter_history] shortcode. Everything is added at
 * render time; stored titles and content are never changed.
 */

defined( 'ABSPATH' ) || exit;

// Registered on init so the Version history block can also load it in the editor (see block.json).
add_action( 'init', 'forkposter_register_styles' );
function forkposter_register_styles() {
	wp_register_style( 'forkposter', FORKPOSTER_URL . 'assets/forkposter.css', array(), FORKPOSTER_VERSION );
}

add_action( 'wp_enqueue_scripts', 'forkposter_enqueue_styles' );
function forkposter_enqueue_styles() {
	wp_enqueue_style( 'forkposter' );
}

function forkposter_post_link_html( WP_Post $post ): string {
	return sprintf(
		'<a href="%s">%s</a>',
		esc_url( get_permalink( $post ) ),
		// Use the raw title so our own the_title filter can't add a badge inside the link.
		esc_html( forkposter_title_with_version( $post ) )
	);
}

/**
 * Fill {link} and {date} in a notice template with $target's details.
 */
function forkposter_format_notice( string $template, WP_Post $target ): string {
	return strtr(
		esc_html( $template ),
		array(
			'{link}' => forkposter_post_link_html( $target ),
			'{date}' => esc_html( get_the_date( '', $target ) ),
		)
	);
}

/**
 * Fill {links} in a notice template with a list of linked titles ("A, B and C").
 *
 * @param WP_Post[] $targets
 */
function forkposter_format_list_notice( string $template, array $targets ): string {
	return strtr(
		esc_html( $template ),
		array( '{links}' => wp_sprintf_l( '%l', array_map( 'forkposter_post_link_html', $targets ) ) )
	);
}

/**
 * How a post relates to its versions, for badges, post states and status labels,
 * or null if it has none. An original with an update shows as the earlier
 * version even if it also has branches.
 *
 * @return array{key: string, label: string}|null
 */
function forkposter_role( int $post_id ): ?array {
	if ( forkposter_is_superseded( $post_id ) ) {
		return array( 'key' => 'superseded', 'label' => (string) forkposter_setting( 'old_label' ) );
	}
	if ( forkposter_is_branched( $post_id ) ) {
		return array( 'key' => 'branched', 'label' => (string) forkposter_setting( 'branched_label' ) );
	}
	if ( forkposter_is_fork( $post_id ) && 'publish' === get_post_status( $post_id ) ) {
		return forkposter_is_branch( $post_id )
			? array( 'key' => 'branch', 'label' => (string) forkposter_setting( 'branch_label' ) )
			: array( 'key' => 'fork', 'label' => (string) forkposter_setting( 'new_label' ) );
	}
	return null;
}

/**
 * Version notices for a post.
 *
 * @param string $context 'single' for the full notice on the post's own page,
 *                        'list' for the short note on home and archive pages,
 *                        'feed' for RSS.
 */
function forkposter_notices_html( int $post_id, string $context = 'single' ): string {
	$latest   = forkposter_get_latest( $post_id );
	$branches = forkposter_get_branches( $post_id );
	$parent   = forkposter_get_published_parent( $post_id );
	$html     = '';

	if ( $latest ) {
		$html .= sprintf(
			'<p class="forkposter-notice__line"><span class="forkposter-badge">%s</span> %s</p>',
			esc_html( forkposter_badge_text( forkposter_setting( 'old_label' ), $post_id ) ),
			forkposter_format_notice( forkposter_setting( 'superseded_notice' ), $latest )
		);
	}

	if ( $branches ) {
		// The badge goes on the first line only.
		$badge = $latest ? '' : sprintf( '<span class="forkposter-badge">%s</span> ', esc_html( forkposter_badge_text( forkposter_setting( 'branched_label' ), $post_id ) ) );
		$html .= sprintf(
			'<p class="forkposter-notice__line">%s%s</p>',
			$badge,
			forkposter_format_list_notice( forkposter_setting( 'branched_notice' ), $branches )
		);
	}

	// In lists, an older post only needs the pointers forward.
	if ( $parent && ! ( 'list' === $context && ( $latest || $branches ) ) ) {
		$html .= sprintf(
			'<p class="forkposter-notice__line">%s</p>',
			forkposter_format_notice( forkposter_setting( forkposter_is_branch( $post_id ) ? 'branch_notice' : 'fork_notice' ), $parent )
		);

		$note = get_post_meta( $post_id, FORKPOSTER_META_NOTE, true );
		if ( $note && 'list' !== $context ) {
			$html .= '<div class="forkposter-notice__note">' . wpautop( esc_html( $note ) ) . '</div>';
		}
	}

	if ( '' === $html ) {
		return '';
	}

	$classes = array( 'forkposter-notice', 'forkposter-notice--' . $context );
	if ( $latest ) {
		$classes[] = 'forkposter-notice--superseded';
	} elseif ( $branches ) {
		$classes[] = 'forkposter-notice--branched';
	} else {
		$classes[] = forkposter_is_branch( $post_id ) ? 'forkposter-notice--branch' : 'forkposter-notice--fork';
	}

	return sprintf( '<aside class="%s">%s</aside>', esc_attr( implode( ' ', $classes ) ), $html );
}

/**
 * The title badge for a post, or '' if it has no other versions.
 */
function forkposter_badge_html( int $post_id ): string {
	$role = forkposter_role( $post_id );
	if ( ! $role ) {
		return '';
	}

	return sprintf(
		' <span class="forkposter-badge forkposter-badge--%s">%s</span>',
		esc_attr( $role['key'] ),
		esc_html( forkposter_badge_text( $role['label'], $post_id ) )
	);
}

/**
 * A badge label with the post's version number, if any: "Earlier version · v.1".
 */
function forkposter_badge_text( string $label, int $post_id ): string {
	$version = forkposter_version_label( $post_id );
	return '' === $version ? $label : $label . ' · ' . $version;
}

/**
 * True when the content being rendered belongs to the page's own post, as
 * opposed to an item in a list or a query loop.
 */
function forkposter_is_own_page( int $post_id ): bool {
	return is_singular() && get_queried_object_id() === $post_id;
}

function forkposter_skip_front_end_filters(): bool {
	return is_admin() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
}

// After wpautop (priority 10) so our markup isn't re-wrapped.
add_filter( 'the_content', 'forkposter_filter_content', 20 );
function forkposter_filter_content( $content ) {
	// get_the_excerpt runs the_content when building automatic excerpts; the excerpt filters handle those.
	if ( forkposter_skip_front_end_filters() || doing_filter( 'get_the_excerpt' ) ) {
		return $content;
	}

	$post = get_post();
	if ( ! forkposter_supports( $post ) ) {
		return $content;
	}

	if ( forkposter_is_own_page( $post->ID ) ) {
		return forkposter_notices_html( $post->ID, 'single' ) . $content;
	}

	if ( forkposter_setting( 'show_list_notes' ) ) {
		return forkposter_notices_html( $post->ID, 'list' ) . $content;
	}

	return $content;
}

// Classic themes that show excerpts in lists.
add_filter( 'the_excerpt', 'forkposter_filter_excerpt', 20 );
function forkposter_filter_excerpt( $excerpt ) {
	if ( forkposter_skip_front_end_filters() || ! forkposter_setting( 'show_list_notes' ) ) {
		return $excerpt;
	}

	$post = get_post();
	if ( ! forkposter_supports( $post ) || forkposter_is_own_page( $post->ID ) ) {
		return $excerpt;
	}

	return forkposter_notices_html( $post->ID, 'list' ) . $excerpt;
}

// Block themes: the Post Excerpt block doesn't run the_excerpt.
add_filter( 'render_block_core/post-excerpt', 'forkposter_filter_excerpt_block', 10, 3 );
function forkposter_filter_excerpt_block( $block_content, $block, $instance ) {
	$post_id = isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : 0;

	if ( ! $post_id || forkposter_skip_front_end_filters() || ! forkposter_setting( 'show_list_notes' ) ) {
		return $block_content;
	}
	if ( ! forkposter_supports( $post_id ) || forkposter_is_own_page( $post_id ) ) {
		return $block_content;
	}

	return forkposter_notices_html( $post_id, 'list' ) . $block_content;
}

// Classic themes.
add_filter( 'the_title', 'forkposter_filter_title', 10, 2 );
function forkposter_filter_title( $title, $post_id = 0 ) {
	// Block themes are handled by the Post Title block filter below. Outside the
	// loop, the_title is used for menus, widgets and attributes, where markup doesn't belong.
	if ( forkposter_skip_front_end_filters() || wp_is_block_theme() || ! in_the_loop() ) {
		return $title;
	}
	if ( ! forkposter_setting( 'show_title_badges' ) || (int) $post_id !== get_the_ID() || ! forkposter_supports( $post_id ) ) {
		return $title;
	}

	return $title . forkposter_badge_html( (int) $post_id );
}

// Block themes.
add_filter( 'render_block_core/post-title', 'forkposter_filter_title_block', 10, 3 );
function forkposter_filter_title_block( $block_content, $block, $instance ) {
	$post_id = isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : 0;

	if ( ! $post_id || forkposter_skip_front_end_filters() || ! forkposter_setting( 'show_title_badges' ) || ! forkposter_supports( $post_id ) ) {
		return $block_content;
	}

	$badge = forkposter_badge_html( $post_id );
	if ( '' === $badge ) {
		return $block_content;
	}

	// Put the badge inside the heading, after the (optionally linked) title text.
	$with_badge = preg_replace( '#(</(?:h[1-6]|p|div)>\s*)$#', $badge . '$1', $block_content, 1 );
	return is_string( $with_badge ) ? $with_badge : $block_content;
}

add_filter( 'post_class', 'forkposter_post_class', 10, 3 );
function forkposter_post_class( array $classes, $class, $post_id ): array {
	if ( ! forkposter_supports( $post_id ) ) {
		return $classes;
	}

	$post_id = (int) $post_id;
	if ( forkposter_is_superseded( $post_id ) ) {
		$classes[] = 'forkposter-superseded';
	}
	if ( forkposter_is_branched( $post_id ) ) {
		$classes[] = 'forkposter-branched';
	}
	if ( forkposter_is_fork( $post_id ) ) {
		$classes[] = forkposter_is_branch( $post_id ) ? 'forkposter-branch' : 'forkposter-fork';
	}

	return $classes;
}

/**
 * [forkposter_history] lists every version of the current post, oldest first.
 * Use [forkposter_history id="123"] to show another post's history.
 * The Version history block (blocks.php) renders the same list.
 */
add_shortcode( 'forkposter_history', 'forkposter_history_shortcode' );
function forkposter_history_shortcode( $atts ): string {
	$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'forkposter_history' );
	$post_id = $atts['id'] ? absint( $atts['id'] ) : get_the_ID();

	return $post_id ? forkposter_history_html( $post_id ) : '';
}

/**
 * The version list for a post, or '' if it has no other published versions.
 *
 * @param string $wrapper_attributes Attributes for the <nav>, already escaped.
 */
function forkposter_history_html( int $post_id, string $wrapper_attributes = 'class="forkposter-history"' ): string {
	if ( ! forkposter_supports( $post_id ) ) {
		return '';
	}

	$lineage  = forkposter_get_lineage( $post_id );
	$branches = forkposter_get_branches( $post_id );
	if ( count( $lineage ) < 2 && ! $branches ) {
		return '';
	}

	$items = '';
	foreach ( $lineage as $version ) {
		$items .= forkposter_history_item( $version, $post_id );
	}

	$branch_list = '';
	if ( $branches ) {
		$branch_items = '';
		foreach ( $branches as $branch ) {
			$branch_items .= forkposter_history_item( $branch, $post_id );
		}
		$branch_list = sprintf(
			'<p class="forkposter-history__heading">%s</p><ul class="forkposter-history__branches">%s</ul>',
			esc_html__( 'Branched into', 'forkposter' ),
			$branch_items
		);
	}

	return sprintf(
		'<nav %1$s aria-label="%2$s"><p class="forkposter-history__heading">%2$s</p><ol>%3$s</ol>%4$s</nav>',
		$wrapper_attributes,
		esc_attr__( 'Versions of this piece', 'forkposter' ),
		$items,
		$branch_list
	);
}

/**
 * One entry in the history list: linked unless it's the post being viewed,
 * and marked when it's a branch.
 */
function forkposter_history_item( WP_Post $version, int $current_id ): string {
	$label = sprintf(
		'%s <span class="forkposter-history__date">%s</span>',
		esc_html( forkposter_title_with_version( $version ) ),
		esc_html( get_the_date( '', $version ) )
	);
	if ( forkposter_is_branch( $version->ID ) ) {
		$label .= sprintf( ' <span class="forkposter-history__kind">%s</span>', esc_html( forkposter_setting( 'branch_label' ) ) );
	}

	return $version->ID === $current_id
		? '<li class="forkposter-history__current" aria-current="page">' . $label . '</li>'
		: '<li><a href="' . esc_url( get_permalink( $version ) ) . '">' . $label . '</a></li>';
}

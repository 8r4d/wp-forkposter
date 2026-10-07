<?php
/**
 * Front-end labels: badges on titles, notices on single posts, short notes in
 * post lists, and the [forkposter_history] shortcode. Everything is added at
 * render time; stored titles and content are never changed.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'forkposter_enqueue_styles' );
function forkposter_enqueue_styles() {
	wp_enqueue_style( 'forkposter', FORKPOSTER_URL . 'assets/forkposter.css', array(), FORKPOSTER_VERSION );
}

/**
 * Fill {link} and {date} in a notice template with $target's details.
 */
function forkposter_format_notice( string $template, WP_Post $target ): string {
	$link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( get_permalink( $target ) ),
		// Use the raw title so our own the_title filter can't add a badge inside the link.
		esc_html( forkposter_title_with_version( $target ) )
	);

	return strtr(
		esc_html( $template ),
		array(
			'{link}' => $link,
			'{date}' => esc_html( get_the_date( '', $target ) ),
		)
	);
}

/**
 * Version notices for a post.
 *
 * @param string $context 'single' for the full notice on the post's own page,
 *                        'list' for the short note on home and archive pages,
 *                        'feed' for RSS.
 */
function forkposter_notices_html( int $post_id, string $context = 'single' ): string {
	$latest = forkposter_get_latest( $post_id );
	$parent = forkposter_get_published_parent( $post_id );
	$html   = '';

	if ( $latest ) {
		$html .= sprintf(
			'<p class="forkposter-notice__line"><span class="forkposter-badge">%s</span> %s</p>',
			esc_html( forkposter_badge_text( forkposter_setting( 'old_label' ), $post_id ) ),
			forkposter_format_notice( forkposter_setting( 'superseded_notice' ), $latest )
		);
	}

	// In lists, an older post only needs the pointer forward.
	if ( $parent && ! ( 'list' === $context && $latest ) ) {
		$html .= sprintf(
			'<p class="forkposter-notice__line">%s</p>',
			forkposter_format_notice( forkposter_setting( 'fork_notice' ), $parent )
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
	$classes[] = $latest ? 'forkposter-notice--superseded' : 'forkposter-notice--fork';

	return sprintf( '<aside class="%s">%s</aside>', esc_attr( implode( ' ', $classes ) ), $html );
}

/**
 * The title badge for a post, or '' if it isn't part of a version chain.
 * A post that is both a revision and since replaced shows as the earlier version.
 */
function forkposter_badge_html( int $post_id ): string {
	if ( forkposter_is_superseded( $post_id ) ) {
		$label = forkposter_setting( 'old_label' );
		$class = 'forkposter-badge--superseded';
	} elseif ( forkposter_is_fork( $post_id ) ) {
		$label = forkposter_setting( 'new_label' );
		$class = 'forkposter-badge--fork';
	} else {
		return '';
	}

	return sprintf( ' <span class="forkposter-badge %s">%s</span>', esc_attr( $class ), esc_html( forkposter_badge_text( $label, $post_id ) ) );
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

	if ( forkposter_is_superseded( (int) $post_id ) ) {
		$classes[] = 'forkposter-superseded';
	}
	if ( forkposter_is_fork( (int) $post_id ) ) {
		$classes[] = 'forkposter-fork';
	}

	return $classes;
}

/**
 * [forkposter_history] lists every version of the current post, oldest first.
 * Use [forkposter_history id="123"] to show another post's history.
 */
add_shortcode( 'forkposter_history', 'forkposter_history_shortcode' );
function forkposter_history_shortcode( $atts ): string {
	$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'forkposter_history' );
	$post_id = $atts['id'] ? absint( $atts['id'] ) : get_the_ID();

	if ( ! $post_id || ! forkposter_supports( $post_id ) ) {
		return '';
	}

	$lineage = forkposter_get_lineage( $post_id );
	if ( count( $lineage ) < 2 ) {
		return '';
	}

	$items = '';
	foreach ( $lineage as $version ) {
		$label = sprintf(
			'%s <span class="forkposter-history__date">%s</span>',
			esc_html( forkposter_title_with_version( $version ) ),
			esc_html( get_the_date( '', $version ) )
		);

		$items .= $version->ID === $post_id
			? '<li class="forkposter-history__current" aria-current="page">' . $label . '</li>'
			: '<li><a href="' . esc_url( get_permalink( $version ) ) . '">' . $label . '</a></li>';
	}

	return sprintf(
		'<nav class="forkposter-history" aria-label="%1$s"><p class="forkposter-history__heading">%1$s</p><ol>%2$s</ol></nav>',
		esc_attr__( 'Versions of this piece', 'forkposter' ),
		$items
	);
}

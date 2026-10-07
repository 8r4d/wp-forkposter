<?php
/**
 * Settings > Forkposter: labels, notice wording, and display toggles.
 */

defined( 'ABSPATH' ) || exit;

const FORKPOSTER_OPTION = 'forkposter_settings';

function forkposter_default_settings(): array {
	return array(
		'old_label'         => __( 'Earlier version', 'forkposter' ),
		'new_label'         => __( 'Revised', 'forkposter' ),
		'superseded_notice' => __( 'I’ve revisited this piece. Read the updated version: {link} ({date})', 'forkposter' ),
		'fork_notice'       => __( 'This is a revised version of an earlier piece: {link} ({date})', 'forkposter' ),
		'show_title_badges' => 1,
		'show_list_notes'   => 1,
		'feed_labels'       => 1,
		'seo_hints'         => 1,
		'show_versions'     => 0,
		'version_format'    => 'v.{version}',
		'extra_tag'         => '',
	);
}

/**
 * Keys that are on/off toggles rather than text.
 */
function forkposter_checkbox_settings(): array {
	return array( 'show_title_badges', 'show_list_notes', 'feed_labels', 'seo_hints', 'show_versions' );
}

/**
 * @return mixed
 */
function forkposter_setting( string $key ) {
	$saved    = get_option( FORKPOSTER_OPTION, array() );
	$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), forkposter_default_settings() );
	return $settings[ $key ] ?? null;
}

function forkposter_sanitize_settings( $input ): array {
	$input    = is_array( $input ) ? $input : array();
	$defaults = forkposter_default_settings();
	$clean    = array();

	foreach ( $defaults as $key => $default ) {
		if ( in_array( $key, forkposter_checkbox_settings(), true ) ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
			continue;
		}

		$value = isset( $input[ $key ] ) ? sanitize_text_field( $input[ $key ] ) : '';

		// The extra tag is optional; every other text setting falls back to its default.
		$clean[ $key ] = ( '' === $value && 'extra_tag' !== $key ) ? $default : $value;
	}

	return $clean;
}

add_action( 'admin_menu', 'forkposter_add_settings_page' );
function forkposter_add_settings_page() {
	add_options_page(
		__( 'Forkposter', 'forkposter' ),
		__( 'Forkposter', 'forkposter' ),
		'manage_options',
		'forkposter',
		'forkposter_render_settings_page'
	);
}

add_action( 'admin_init', 'forkposter_register_settings' );
function forkposter_register_settings() {
	register_setting(
		'forkposter',
		FORKPOSTER_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'forkposter_sanitize_settings',
			'default'           => forkposter_default_settings(),
		)
	);

	add_settings_section( 'forkposter_wording', __( 'Labels and notices', 'forkposter' ), '__return_null', 'forkposter' );
	add_settings_section( 'forkposter_versions', __( 'Version numbers', 'forkposter' ), '__return_null', 'forkposter' );
	add_settings_section( 'forkposter_display', __( 'Display', 'forkposter' ), '__return_null', 'forkposter' );
	add_settings_section( 'forkposter_sharing', __( 'Social sharing', 'forkposter' ), '__return_null', 'forkposter' );

	$fields = array(
		'old_label'         => array( 'forkposter_wording', 'text', __( 'Label for older posts', 'forkposter' ), __( 'Shown as a badge on posts that have a newer version.', 'forkposter' ) ),
		'new_label'         => array( 'forkposter_wording', 'text', __( 'Label for newer posts', 'forkposter' ), __( 'Shown as a badge on posts that revise an earlier one.', 'forkposter' ) ),
		'superseded_notice' => array( 'forkposter_wording', 'textarea', __( 'Notice on older posts', 'forkposter' ), __( '{link} is the newest version’s title, linked. {date} is its publish date.', 'forkposter' ) ),
		'fork_notice'       => array( 'forkposter_wording', 'textarea', __( 'Notice on newer posts', 'forkposter' ), __( '{link} is the earlier post’s title, linked. {date} is its publish date.', 'forkposter' ) ),
		'show_versions'     => array( 'forkposter_versions', 'checkbox', __( 'Version numbers', 'forkposter' ), __( 'Number each version and show the number in badges, notices, the feed and version lists. New forks are numbered automatically; you can change any number in the editor’s Versions box.', 'forkposter' ) ),
		'version_format'    => array( 'forkposter_versions', 'text', __( 'Version format', 'forkposter' ), __( 'How numbers are shown. {version} is the number you assign, so “v.{version}” shows “v.2.0”.', 'forkposter' ) ),
		'show_title_badges' => array( 'forkposter_display', 'checkbox', __( 'Title badges', 'forkposter' ), __( 'Add the label next to post titles on the site.', 'forkposter' ) ),
		'show_list_notes'   => array( 'forkposter_display', 'checkbox', __( 'Notes in post lists', 'forkposter' ), __( 'Show a short version note above the excerpt on the home page and archives.', 'forkposter' ) ),
		'feed_labels'       => array( 'forkposter_display', 'checkbox', __( 'Label RSS items', 'forkposter' ), __( 'Prefix older posts’ feed titles with the label and add the link to the newer version.', 'forkposter' ) ),
		'seo_hints'         => array( 'forkposter_display', 'checkbox', __( 'Search engine hints', 'forkposter' ), __( 'Tell search engines how versions relate: version links in the page head, and “based on” structured data on newer posts (added to Yoast, Rank Math or All in One SEO data when one is active).', 'forkposter' ) ),
		'extra_tag'         => array( 'forkposter_sharing', 'text', __( 'Also tag older posts with', 'forkposter' ), __( 'Optional. Many auto-share plugins can only exclude by tag or category. Enter a tag (for example “superseded”) and exclude it in those plugins. It is removed again if the post stops being an earlier version.', 'forkposter' ) ),
	);

	foreach ( $fields as $key => list( $section, $type, $label, $description ) ) {
		add_settings_field(
			'forkposter_' . $key,
			$label,
			'forkposter_render_settings_field',
			'forkposter',
			$section,
			array(
				'key'         => $key,
				'type'        => $type,
				'description' => $description,
				'label_for'   => 'forkposter_' . $key,
			)
		);
	}
}

function forkposter_render_settings_field( array $args ) {
	$key   = $args['key'];
	$id    = 'forkposter_' . $key;
	$name  = FORKPOSTER_OPTION . '[' . $key . ']';
	$value = forkposter_setting( $key );

	switch ( $args['type'] ) {
		case 'checkbox':
			printf(
				'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s> %4$s</label>',
				esc_attr( $id ),
				esc_attr( $name ),
				checked( (int) $value, 1, false ),
				esc_html( $args['description'] )
			);
			return;

		case 'textarea':
			printf(
				'<textarea id="%1$s" name="%2$s" class="large-text" rows="2">%3$s</textarea>',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_textarea( $value )
			);
			break;

		default:
			printf(
				'<input type="text" id="%1$s" name="%2$s" class="regular-text" value="%3$s">',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( $value )
			);
	}

	printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
}

function forkposter_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Forkposter', 'forkposter' ); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'forkposter' );
			do_settings_sections( 'forkposter' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

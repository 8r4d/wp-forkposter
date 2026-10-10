<?php
/**
 * REST API for apps outside wp-admin (the block editor uses the meta registered
 * in editor.php). Adds to every forkable post type:
 *
 * - `_forkposter_parent` meta, read-only.
 * - A read-only `forkposter` field with the post's place among its versions,
 *   computed the same way as the site does, so stale links never show.
 * - POST forkposter/v1/posts/<id>/fork to fork a published post.
 * - GET forkposter/v1/families to list every forked post with its versions,
 *   like Posts > Versions.
 */

defined( 'ABSPATH' ) || exit;

const FORKPOSTER_REST_NAMESPACE = 'forkposter/v1';

add_action( 'init', 'forkposter_register_rest_meta' );
function forkposter_register_rest_meta() {
	foreach ( forkposter_post_types() as $post_type ) {
		register_post_meta(
			$post_type,
			FORKPOSTER_META_PARENT,
			array(
				'type'          => 'integer',
				'single'        => true,
				'default'       => 0,
				'show_in_rest'  => true,
				// Set only by forking: nobody can change it through the API.
				'auth_callback' => '__return_false',
			)
		);
	}
}

add_action( 'rest_api_init', 'forkposter_register_rest' );
function forkposter_register_rest() {
	$ref = array(
		'type'       => array( 'object', 'null' ),
		'properties' => array(
			'id'      => array( 'type' => 'integer' ),
			'title'   => array( 'type' => 'string' ),
			'version' => array( 'type' => 'string' ),
			'status'  => array( 'type' => 'string' ),
			'link'    => array( 'type' => 'string', 'format' => 'uri' ),
		),
	);

	register_rest_field(
		forkposter_post_types(),
		'forkposter',
		array(
			'get_callback' => static function ( $data ) {
				return forkposter_rest_post_data( (int) $data['id'] );
			},
			'schema'       => array(
				'description' => __( 'How this post relates to its other versions.', 'forkposter' ),
				'type'        => 'object',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
				'properties'  => array(
					'state'        => array(
						'description' => __( 'current, superseded (replaced by an update), branched, or unpublished.', 'forkposter' ),
						'type'        => 'string',
						'enum'        => array( 'current', 'superseded', 'branched', 'unpublished' ),
					),
					'state_label'  => array( 'type' => 'string' ),
					'kind'         => array(
						'description' => __( 'For forks: update or branch. Null for posts that are not forks.', 'forkposter' ),
						'type'        => array( 'string', 'null' ),
						'enum'        => array( FORKPOSTER_KIND_UPDATE, FORKPOSTER_KIND_BRANCH, null ),
					),
					'version'      => array( 'type' => 'string' ),
					'version_label' => array( 'type' => 'string' ),
					'parent'       => $ref,
					'successor'    => $ref,
					'latest'       => $ref,
					'branches'     => array(
						'type'  => 'array',
						'items' => $ref,
					),
					'lineage'      => array(
						'description' => __( 'IDs of every published version in this post’s line, oldest first.', 'forkposter' ),
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
					),
					'can_fork'     => array( 'type' => 'boolean' ),
				),
			),
		)
	);

	register_rest_route(
		FORKPOSTER_REST_NAMESPACE,
		'/posts/(?P<id>\d+)/fork',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'forkposter_rest_fork',
			'permission_callback' => static function ( WP_REST_Request $request ) {
				if ( forkposter_can_fork( (int) $request['id'] ) ) {
					return true;
				}
				return new WP_Error( 'forkposter_cannot_fork', __( 'You can’t fork this post. Only published posts you can edit can be forked.', 'forkposter' ), array( 'status' => rest_authorization_required_code() ) );
			},
			'args'                => array(
				'id'   => array( 'type' => 'integer' ),
				'kind' => array(
					'description' => __( 'update replaces the original once published; branch sits alongside it.', 'forkposter' ),
					'type'        => 'string',
					'enum'        => array( FORKPOSTER_KIND_UPDATE, FORKPOSTER_KIND_BRANCH ),
					'default'     => FORKPOSTER_KIND_UPDATE,
				),
			),
		)
	);

	register_rest_route(
		FORKPOSTER_REST_NAMESPACE,
		'/families',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'forkposter_rest_families',
			'permission_callback' => static function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'post' => array(
					'description' => __( 'Only the family containing this post.', 'forkposter' ),
					'type'        => 'integer',
				),
			),
		)
	);
}

/**
 * A related post as the API shows it, or null if the current user can't see it.
 */
function forkposter_rest_post_ref( ?WP_Post $post ): ?array {
	if ( ! $post || ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post->ID ) ) ) {
		return null;
	}

	return array(
		'id'      => $post->ID,
		'title'   => $post->post_title,
		'version' => forkposter_get_version( $post->ID ),
		'status'  => $post->post_status,
		'link'    => get_permalink( $post ),
	);
}

function forkposter_rest_post_data( int $post_id ): array {
	$post      = get_post( $post_id );
	$parent_id = forkposter_get_parent_id( $post_id );
	$parent    = $parent_id ? get_post( $parent_id ) : null;
	list( $label, $state ) = forkposter_version_status( $post );

	return array(
		'state'         => $state,
		'state_label'   => (string) $label,
		'kind'          => $parent_id ? forkposter_get_kind( $post_id ) : null,
		'version'       => forkposter_get_version( $post_id ),
		'version_label' => forkposter_version_label( $post_id ),
		'parent'        => $parent && 'trash' !== $parent->post_status ? forkposter_rest_post_ref( $parent ) : null,
		'successor'     => forkposter_rest_post_ref( forkposter_get_successor( $post_id ) ),
		'latest'        => forkposter_rest_post_ref( forkposter_get_latest( $post_id ) ),
		'branches'      => array_map( 'forkposter_rest_post_ref', forkposter_get_branches( $post_id ) ),
		'lineage'       => 'publish' === $post->post_status ? wp_list_pluck( forkposter_get_lineage( $post_id ), 'ID' ) : array(),
		'can_fork'      => forkposter_can_fork( $post ),
	);
}

/**
 * Fork a post. Responds with the new draft as the post type's own endpoint
 * would (edit context), with a Location header pointing there.
 */
function forkposter_rest_fork( WP_REST_Request $request ) {
	$post    = get_post( (int) $request['id'] );
	$fork_id = forkposter_create_fork( $post, $request['kind'] );
	if ( is_wp_error( $fork_id ) ) {
		return $fork_id;
	}

	$controller = get_post_type_object( $post->post_type )->get_rest_controller();
	if ( ! $controller ) {
		return new WP_REST_Response( array( 'id' => $fork_id ), 201 );
	}

	$request->set_param( 'context', 'edit' );
	$response = rest_ensure_response( $controller->prepare_item_for_response( get_post( $fork_id ), $request ) );
	$response->set_status( 201 );
	$response->header( 'Location', rest_url( rest_get_route_for_post( $fork_id ) ) );

	return $response;
}

/**
 * Every family the current user can edit something in, most recently changed
 * first. Versions are listed in tree order (each followed by its forks).
 */
function forkposter_rest_families( WP_REST_Request $request ) {
	$families = forkposter_get_families();
	if ( $request['post'] ) {
		$families = array_filter( $families, fn( $family ) => in_array( (int) $request['post'], $family['members'], true ) );
	}

	$out = array();
	foreach ( $families as $family ) {
		$versions = array();
		foreach ( forkposter_flatten_family( $family ) as $item ) {
			$post = $item['post'];
			if ( ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}
			list( $label, $state ) = forkposter_version_status( $post );
			$parent_id             = forkposter_get_parent_id( $post->ID );

			$versions[] = array_merge(
				forkposter_rest_post_ref( $post ),
				array(
					'parent'      => $parent_id && $post->ID !== $family['root']->ID ? $parent_id : 0,
					'depth'       => $item['depth'],
					'kind'        => $parent_id ? forkposter_get_kind( $post->ID ) : null,
					'state'       => $state,
					'state_label' => (string) $label,
					'words'       => forkposter_word_count( $post ),
					'modified'    => mysql_to_rfc3339( $post->post_modified_gmt ),
				)
			);
		}

		$out[] = array(
			'root'     => $family['root']->ID,
			'updated'  => mysql_to_rfc3339( $family['updated'] ),
			'versions' => $versions,
		);
	}

	return rest_ensure_response( $out );
}

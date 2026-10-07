/**
 * Version history block. Rendered on the server (render.php), so the editor
 * shows the same list visitors see. Plain script, no build step.
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useSelect = wp.data.useSelect;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var Placeholder = wp.components.Placeholder;
	var ServerSideRender = wp.serverSideRender;

	function EmptyHistory() {
		return el( Placeholder, {
			icon: 'backup',
			label: __( 'Version history', 'forkposter' ),
			instructions: __(
				'Lists every published version of this piece. It appears once this post has an earlier or newer published version.',
				'forkposter'
			),
		} );
	}

	wp.blocks.registerBlockType( 'forkposter/history', {
		edit: function ( props ) {
			var currentPostId = useSelect( function ( select ) {
				var editor = select( 'core/editor' );
				return editor ? editor.getCurrentPostId() : null;
			}, [] );
			// Inside a Query Loop the block lists that item's versions.
			var postId = ( props.context && props.context.postId ) || currentPostId;

			return el(
				'div',
				useBlockProps(),
				el( ServerSideRender, {
					block: 'forkposter/history',
					attributes: props.attributes,
					urlQueryArgs: typeof postId === 'number' ? { post_id: postId } : {},
					EmptyResponsePlaceholder: EmptyHistory,
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );

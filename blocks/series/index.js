/**
 * Series list block. Rendered on the server (render.php), so the editor
 * shows the same list visitors see. Plain script, no build step.
 */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useSelect = wp.data.useSelect;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var Placeholder = wp.components.Placeholder;
	var PanelBody = wp.components.PanelBody;
	var ToggleControl = wp.components.ToggleControl;
	var ServerSideRender = wp.serverSideRender;

	function EmptySeries() {
		return el( Placeholder, {
			icon: 'editor-ol',
			label: __( 'Series list', 'forkposter' ),
			instructions: __(
				'Lists the original and its branches as numbered parts. It appears once this post’s family has a published branch.',
				'forkposter'
			),
		} );
	}

	wp.blocks.registerBlockType( 'forkposter/series', {
		edit: function ( props ) {
			var currentPostId = useSelect( function ( select ) {
				var editor = select( 'core/editor' );
				return editor ? editor.getCurrentPostId() : null;
			}, [] );
			// Inside a Query Loop the block lists that item's series.
			var postId = ( props.context && props.context.postId ) || currentPostId;

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Settings', 'forkposter' ) },
						el( ToggleControl, {
							label: __( 'Plain titles', 'forkposter' ),
							help: __( 'Leave out version numbers and branch labels.', 'forkposter' ),
							checked: !! props.attributes.plainTitles,
							onChange: function ( value ) {
								props.setAttributes( { plainTitles: value } );
							},
						} )
					)
				),
				el( ServerSideRender, {
					block: 'forkposter/series',
					attributes: props.attributes,
					urlQueryArgs: typeof postId === 'number' ? { post_id: postId } : {},
					EmptyResponsePlaceholder: EmptySeries,
				} )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );

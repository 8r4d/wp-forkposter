/**
 * Versions panel in the block editor's post sidebar. Plain script, no build step:
 * it uses the wp.* globals WordPress already loads in the editor.
 * Server-side data comes from forkposter_editor_data() in includes/editor.php.
 */
( function ( wp, data ) {
	if ( ! wp || ! data ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var useSelect = wp.data.useSelect;
	var useDispatch = wp.data.useDispatch;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var RadioControl = wp.components.RadioControl;
	var Button = wp.components.Button;
	var PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) || wp.editPost.PluginDocumentSettingPanel;

	var META_VERSION = '_forkposter_version';
	var META_NOTE = '_forkposter_note';
	var META_KIND = '_forkposter_kind';

	function formatVersion( version ) {
		if ( ! version ) {
			return '';
		}
		return data.versionFormat.indexOf( '{version}' ) === -1
			? version
			: data.versionFormat.split( '{version}' ).join( version );
	}

	function PostLink( props ) {
		return el(
			Fragment,
			null,
			el( 'a', { href: props.post.url }, props.post.title ),
			props.post.status ? el( 'em', null, ' (' + props.post.status + ')' ) : null
		);
	}

	function Description( props ) {
		return el( 'p', { className: 'components-base-control__help forkposter-panel__help' }, props.children );
	}

	function VersionsPanel() {
		var state = useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return {
				meta: editor.getEditedPostAttribute( 'meta' ) || {},
				savedStatus: editor.getCurrentPostAttribute( 'status' ),
				isDirty: editor.isEditedPostDirty(),
			};
		}, [] );
		var editPost = useDispatch( 'core/editor' ).editPost;

		function setMeta( key, value ) {
			var meta = {};
			meta[ key ] = value;
			editPost( { meta: meta } );
		}

		var isPublished = state.savedStatus === 'publish';
		var version = state.meta[ META_VERSION ] || '';
		var parts = [];

		if ( data.versionsEnabled ) {
			parts.push(
				el( TextControl, {
					key: 'version',
					label: __( 'Version', 'forkposter' ),
					value: version,
					placeholder: '1',
					maxLength: 20,
					onChange: function ( value ) {
						setMeta( META_VERSION, value );
					},
					help: version
						? sprintf( /* translators: %s: formatted version, e.g. v.2.0 */ __( 'Shown as “%s”', 'forkposter' ), formatVersion( version ) )
						: __( 'For example 2 or 2.0', 'forkposter' ),
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
				} )
			);
		}

		if ( data.successor ) {
			parts.push(
				el(
					'p',
					{ key: 'successor' },
					el( 'strong', null, data.oldLabel + '. ' ),
					__( 'Replaced by', 'forkposter' ) + ' ',
					el( PostLink, { post: data.successor } ),
					'.'
				)
			);
			if ( data.latest && data.latest.id !== data.successor.id ) {
				parts.push(
					el( 'p', { key: 'latest' }, __( 'Newest version:', 'forkposter' ) + ' ', el( PostLink, { post: data.latest } ) )
				);
			}
		}

		if ( data.branches.length ) {
			parts.push(
				el(
					'p',
					{ key: 'branches' },
					__( 'Branched into', 'forkposter' ) + ' ',
					data.branches.map( function ( branch, index ) {
						var separator = '';
						if ( index ) {
							separator = index === data.branches.length - 1 ? ' ' + __( 'and', 'forkposter' ) + ' ' : ', ';
						}
						return el( Fragment, { key: branch.id }, separator, el( PostLink, { post: branch } ) );
					} ),
					'.'
				)
			);
		}

		if ( data.parent ) {
			var kind = state.meta[ META_KIND ] === 'branch' ? 'branch' : 'update';
			parts.push(
				el( 'p', { key: 'parent' }, __( 'Forked from', 'forkposter' ) + ' ', el( PostLink, { post: data.parent } ), '.' ),
				el( 'p', { key: 'compare' }, el( 'a', { href: data.compareUrl }, __( 'Compare with previous version', 'forkposter' ) ) ),
				el( RadioControl, {
					key: 'kind',
					label: __( 'This fork is', 'forkposter' ),
					selected: kind,
					options: [
						{ label: __( 'An update', 'forkposter' ), value: 'update' },
						{ label: __( 'A branch', 'forkposter' ), value: 'branch' },
					],
					onChange: function ( value ) {
						setMeta( META_KIND, value );
					},
					help: data.kindDescriptions[ kind ],
				} )
			);

			parts.push(
				el( TextareaControl, {
					key: 'note',
					label: __( 'Why you revisited it', 'forkposter' ),
					value: state.meta[ META_NOTE ] || '',
					rows: 3,
					onChange: function ( value ) {
						setMeta( META_NOTE, value );
					},
					help: __( 'Optional. Shown with the version notice at the top of this post.', 'forkposter' ),
					__nextHasNoMarginBottom: true,
				} )
			);
		} else if ( data.parentMissing ) {
			parts.push( el( 'p', { key: 'missing' }, __( 'The post this was forked from no longer exists.', 'forkposter' ) ) );
		}

		if ( data.canFork && isPublished ) {
			parts.push(
				el(
					'div',
					{ key: 'fork', className: 'forkposter-panel__fork' },
					el(
						'div',
						{ className: 'forkposter-panel__buttons' },
						el( Button, { variant: 'secondary', href: data.forkUrl }, __( 'Fork as update', 'forkposter' ) ),
						el( Button, { variant: 'secondary', href: data.forkBranchUrl }, __( 'Fork as branch', 'forkposter' ) )
					),
					state.isDirty
						? el( Description, null, __( 'You have unsaved changes. A fork copies the last saved version, so save first.', 'forkposter' ) )
						: null,
					el( Description, null, el( 'strong', null, __( 'Update:', 'forkposter' ) + ' ' ), data.kindDescriptions.update ),
					el( Description, null, el( 'strong', null, __( 'Branch:', 'forkposter' ) + ' ' ), data.kindDescriptions.branch ),
					data.successor
						? el( Description, null, __( 'This post already has an update. A new update would replace it as the newest version.', 'forkposter' ) )
						: null
				)
			);
		} else if ( ! data.parent && ! data.successor && ! data.branches.length && ! isPublished ) {
			parts.push( el( Description, { key: 'unpublished' }, __( 'Publish this post to be able to fork it later.', 'forkposter' ) ) );
		}

		if ( data.parent || data.successor || data.branches.length ) {
			parts.push( el( 'p', { key: 'all' }, el( 'a', { href: data.dashboardUrl }, __( 'All versions →', 'forkposter' ) ) ) );
		}

		return el( 'div', { className: 'forkposter-panel' }, parts );
	}

	wp.plugins.registerPlugin( 'forkposter-versions', {
		render: function () {
			return el(
				PluginDocumentSettingPanel,
				{ name: 'forkposter-versions', title: __( 'Versions', 'forkposter' ) },
				el( VersionsPanel )
			);
		},
	} );
} )( window.wp, window.forkposterEditor );

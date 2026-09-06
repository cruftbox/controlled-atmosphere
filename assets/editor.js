/**
 * Block editor sidebar panel for the per-post opt-out.
 *
 * Written against the wp.* globals rather than a build step: the panel is one
 * checkbox, and adding a bundler for it would cost more than it saves.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var META_KEY = '_controlled_atmosphere_exclude';

	function Panel() {
		var post = wp.data.useSelect( function ( select ) {
			var editor = select( 'core/editor' );
			return {
				type: editor.getCurrentPostType(),
				meta: editor.getEditedPostAttribute( 'meta' ) || {},
			};
		}, [] );

		var dispatch = wp.data.useDispatch( 'core/editor' );

		if ( 'post' !== post.type ) {
			return null;
		}

		return el(
			wp.editPost.PluginDocumentSettingPanel,
			{
				name: 'controlled-atmosphere',
				title: __( 'Controlled Atmosphere', 'controlled-atmosphere' ),
				className: 'controlled-atmosphere-panel',
			},
			el( wp.components.CheckboxControl, {
				label: __( 'Exclude from Bluesky publications', 'controlled-atmosphere' ),
				help: __(
					'Removes this post’s Standard.site record. Links to it will show a plain preview on Bluesky instead of an article card.',
					'controlled-atmosphere'
				),
				checked: !! post.meta[ META_KEY ],
				onChange: function ( value ) {
					var meta = {};
					meta[ META_KEY ] = value;
					dispatch.editPost( { meta: meta } );
				},
			} )
		);
	}

	wp.plugins.registerPlugin( 'controlled-atmosphere', { render: Panel } );
} )( window.wp );

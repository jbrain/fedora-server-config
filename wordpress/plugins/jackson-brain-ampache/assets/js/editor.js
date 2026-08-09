/**
 * Minimal, no-build editor script shared by all three blocks. Each block only needs a light
 * edit() supplying a couple of controls plus ServerSideRender for the live preview; title,
 * category, icon, and attributes already come from each block's block.json.
 */
( function ( blocks, element, blockEditor, components, i18n, serverSideRenderModule ) {
	var el = element.createElement;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var RangeControl = components.RangeControl;
	var ToggleControl = components.ToggleControl;
	var ServerSideRender = serverSideRenderModule && serverSideRenderModule.default
		? serverSideRenderModule.default
		: serverSideRenderModule;

	function makeEdit( showArtControl, showLimitControl ) {
		return function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var controls = [
				el( RangeControl, {
					key: 'heading-level',
					label: __( 'Heading level', 'jackson-brain-ampache' ),
					value: attributes.headingLevel,
					min: 2,
					max: 6,
					onChange: function ( value ) {
						setAttributes( { headingLevel: value } );
					},
				} ),
			];

			if ( showArtControl ) {
				controls.push(
					el( ToggleControl, {
						key: 'show-art',
						label: __( 'Show artwork', 'jackson-brain-ampache' ),
						checked: !! attributes.showArt,
						onChange: function ( value ) {
							setAttributes( { showArt: value } );
						},
					} )
				);
			}

			if ( showLimitControl ) {
				controls.push(
					el( RangeControl, {
						key: 'limit',
						label: __( 'Number of items', 'jackson-brain-ampache' ),
						value: attributes.limit,
						min: 1,
						max: 25,
						onChange: function ( value ) {
							setAttributes( { limit: value } );
						},
					} )
				);
			}

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					{},
					el( PanelBody, { title: __( 'Settings', 'jackson-brain-ampache' ) }, controls )
				),
				el( ServerSideRender, { block: props.name, attributes: attributes } )
			);
		};
	}

	blocks.registerBlockType( 'jackson-brain/ampache-library-stats', {
		edit: makeEdit( false, false ),
		save: function () {
			return null;
		},
	} );

	blocks.registerBlockType( 'jackson-brain/ampache-now-playing', {
		edit: makeEdit( true, false ),
		save: function () {
			return null;
		},
	} );

	blocks.registerBlockType( 'jackson-brain/ampache-recently-played', {
		edit: makeEdit( true, true ),
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n, window.wp.serverSideRender );

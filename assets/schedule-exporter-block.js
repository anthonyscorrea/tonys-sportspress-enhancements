( function ( blocks, blockEditor, element, i18n ) {
	var el = element.createElement;
	var useBlockProps = blockEditor.useBlockProps;
	var __ = i18n.__;

	blocks.registerBlockType( 'tse/schedule-exporter', {
		edit: function () {
			var blockProps = useBlockProps( {
				className: 'tse-schedule-exporter-block-placeholder',
			} );

			return el(
				'div',
				blockProps,
				el( 'strong', null, __( 'Schedule Exporter', 'tonys-sportspress-enhancements' ) ),
				el(
					'p',
					null,
					__( 'This block renders the public schedule exporter on the frontend.', 'tonys-sportspress-enhancements' )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element, window.wp.i18n );

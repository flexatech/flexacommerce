/**
 * Editor side of the storefront blocks. They are dynamic: the editor shows
 * the server render, and nothing is saved but the block comment.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { PanelBody, TextControl } = wp.components;
	const { __ } = wp.i18n;
	const ServerSideRender = wp.serverSideRender;

	const PRODUCT = 'fxc_product';

	function Edit( { name, attributes, setAttributes, context } ) {
		const blockProps = useBlockProps();
		const hasProduct = Object.prototype.hasOwnProperty.call( attributes, 'productId' );
		const inProduct = context && context.postType === PRODUCT && context.postId;
		const rendered = Object.assign( {}, attributes );
		if ( hasProduct && ! attributes.productId && inProduct ) {
			rendered.productId = context.postId;
		}

		const controls =
			hasProduct && ! inProduct
				? el(
						InspectorControls,
						null,
						el(
							PanelBody,
							{ title: __( 'Product', 'flexacommerce' ) },
							el( TextControl, {
								label: __( 'Product ID', 'flexacommerce' ),
								type: 'number',
								min: 0,
								value: attributes.productId || '',
								onChange: ( value ) =>
									setAttributes( { productId: parseInt( value, 10 ) || 0 } ),
							} )
						)
				  )
				: null;

		return el(
			Fragment,
			null,
			controls,
			el(
				'div',
				blockProps,
				el( ServerSideRender, { block: name, attributes: rendered } )
			)
		);
	}

	[
		'flexacommerce/product-price',
		'flexacommerce/product-add-to-cart',
		'flexacommerce/cart',
		'flexacommerce/mini-cart',
	].forEach( ( name ) => {
		registerBlockType( name, {
			edit: ( props ) => el( Edit, Object.assign( { name }, props ) ),
			save: () => null,
		} );
	} );
} )( window.wp );

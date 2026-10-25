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
		'flexacommerce/checkout',
		'flexacommerce/account',
		'flexacommerce/order-confirmation',
		'flexacommerce/product-gallery',
		'flexacommerce/product-sku',
		'flexacommerce/product-stock',
		'flexacommerce/product-attributes',
		'flexacommerce/breadcrumbs',
		'flexacommerce/catalog-sorting',
		'flexacommerce/product-filters',
		'flexacommerce/related-products',
	].forEach( ( name ) => {
		registerBlockType( name, {
			edit: ( props ) => el( Edit, Object.assign( { name }, props ) ),
			save: () => null,
		} );
	} );

	// A Query Loop preset for products: the same card as the shop template.
	wp.blocks.registerBlockVariation( 'core/query', {
		name: 'flexacommerce/product-query',
		title: __( 'Product list', 'flexacommerce' ),
		description: __( 'A list of products with price and add-to-cart button.', 'flexacommerce' ),
		icon: 'products',
		category: 'widgets',
		isActive: [ 'namespace' ],
		scope: [ 'inserter' ],
		allowedControls: [ 'order', 'taxQuery', 'search' ],
		attributes: {
			namespace: 'flexacommerce/product-query',
			query: {
				perPage: 4,
				pages: 0,
				offset: 0,
				postType: PRODUCT,
				order: 'desc',
				orderBy: 'date',
				inherit: false,
			},
		},
		innerBlocks: [
			[
				'core/post-template',
				{ layout: { type: 'grid', columnCount: 4 } },
				[
					[ 'core/post-featured-image', { isLink: true, aspectRatio: '1' } ],
					[ 'core/post-title', { isLink: true, level: 3, fontSize: 'medium' } ],
					[ 'flexacommerce/product-price' ],
					[ 'flexacommerce/product-add-to-cart' ],
				],
			],
			[ 'core/query-no-results', {}, [ [ 'core/paragraph', { content: __( 'No products found.', 'flexacommerce' ) } ] ] ],
		],
	} );
} )( window.wp );

/**
 * Editor side of the storefront blocks. They are dynamic: the editor shows
 * the server render, and nothing is saved but the block comment.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: el, Fragment, useEffect, useState } = wp.element;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { CheckboxControl, PanelBody, SelectControl, TextControl, ToggleControl } = wp.components;
	const { __ } = wp.i18n;
	const ServerSideRender = wp.serverSideRender;

	const PRODUCT = 'fxc_product';

	// What the editor shows while a block renders nothing, so it can still be found and picked.
	const EMPTY = {
		'flexacommerce/quantity-prices': __( 'Quantity prices: shows when a bulk pricing rule covers the product.', 'flexacommerce' ),
		'flexacommerce/discount-badge': __( 'Discount badge: shows when the product costs less than its regular price.', 'flexacommerce' ),
		'flexacommerce/offer-countdown': __( 'Offer countdown: shows while the offer has an end date.', 'flexacommerce' ),
		'flexacommerce/discount-nudge': __( 'Discount progress: shows when the cart is close to an automatic discount.', 'flexacommerce' ),
	};

	let rulesRequest = null;

	/** The pricing rules with an end date, for the countdown; null while loading or for users who cannot manage them. */
	function useEndingRules() {
		const [ rules, setRules ] = useState( null );
		useEffect( () => {
			let live = true;
			rulesRequest =
				rulesRequest ||
				wp.apiFetch( { path: '/flexacommerce/v1/admin/pricing-rules' } ).then(
					( data ) => ( data.items || [] ).filter( ( rule ) => rule.type !== 'fee' && rule.ends_at ),
					() => false
				);
			rulesRequest.then( ( found ) => live && setRules( found ) );
			return () => {
				live = false;
			};
		}, [] );
		return rules;
	}

	function RulePicker( { value, onChange } ) {
		const rules = useEndingRules();
		if ( rules === false ) {
			return el( TextControl, {
				label: __( 'Pricing rule ID', 'flexacommerce' ),
				help: __( 'Leave empty to count down to the next price change of the product.', 'flexacommerce' ),
				type: 'number',
				min: 0,
				value: value || '',
				onChange: ( next ) => onChange( parseInt( next, 10 ) || 0 ),
			} );
		}
		const options = [ { value: '0', label: __( 'The product price', 'flexacommerce' ) } ].concat(
			( rules || [] ).map( ( rule ) => ( { value: String( rule.id ), label: rule.name } ) )
		);
		if ( value && ! options.some( ( option ) => option.value === String( value ) ) ) {
			options.push( { value: String( value ), label: __( 'A rule that no longer has an end date', 'flexacommerce' ) } );
		}
		return el( SelectControl, {
			label: __( 'Count down to', 'flexacommerce' ),
			help: value
				? __( 'Shows while the rule runs, to the customers it is for.', 'flexacommerce' )
				: __( 'The next time a sale or a pricing rule on the product ends and its price goes up.', 'flexacommerce' ),
			value: String( value || 0 ),
			options,
			disabled: rules === null,
			onChange: ( next ) => onChange( parseInt( next, 10 ) || 0 ),
		} );
	}

	let attributesRequest = null;

	/** The global attributes; false for users who cannot edit products. */
	function useAttributes() {
		const [ found, setFound ] = useState( null );
		useEffect( () => {
			let live = true;
			attributesRequest =
				attributesRequest ||
				wp.apiFetch( { path: '/flexacommerce/v1/admin/attributes' } ).then(
					( data ) => data.items || [],
					() => false
				);
			attributesRequest.then( ( items ) => live && setFound( items ) );
			return () => {
				live = false;
			};
		}, [] );
		return found;
	}

	function FilterControls( { attributes, setAttributes } ) {
		const found = useAttributes();
		const chosen = attributes.attributeSlugs || [];
		const toggle = ( key, label, help ) =>
			el( ToggleControl, {
				label,
				help,
				checked: attributes[ key ] !== false,
				onChange: ( value ) => setAttributes( { [ key ]: value } ),
			} );
		let list;
		if ( found === false ) {
			list = el( TextControl, {
				label: __( 'Attribute slugs', 'flexacommerce' ),
				help: __( 'Separate with commas. Leave empty to offer every attribute.', 'flexacommerce' ),
				value: chosen.join( ', ' ),
				onChange: ( value ) =>
					setAttributes( {
						attributeSlugs: value
							.split( ',' )
							.map( ( slug ) => slug.trim() )
							.filter( Boolean ),
					} ),
			} );
		} else if ( found === null ) {
			list = el( 'p', null, __( 'Loading attributes…', 'flexacommerce' ) );
		} else {
			list = el(
				Fragment,
				null,
				el( 'p', null, __( 'Leave all unticked to offer every attribute.', 'flexacommerce' ) ),
				found.map( ( item ) =>
					el( CheckboxControl, {
						key: item.slug,
						label: item.name,
						checked: chosen.includes( item.slug ),
						onChange: ( on ) =>
							setAttributes( {
								attributeSlugs: on
									? chosen.concat( [ item.slug ] )
									: chosen.filter( ( slug ) => slug !== item.slug ),
							} ),
					} )
				)
			);
		}
		return el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: __( 'Filters', 'flexacommerce' ) },
				toggle( 'showCategories', __( 'Categories', 'flexacommerce' ) ),
				toggle( 'showPrice', __( 'Price', 'flexacommerce' ) ),
				toggle( 'showStock', __( 'Availability', 'flexacommerce' ) ),
				toggle(
					'showCounts',
					__( 'Product counts', 'flexacommerce' ),
					__( 'Shows how many products each choice lists and hides choices that list none.', 'flexacommerce' )
				)
			),
			el( PanelBody, { title: __( 'Attributes', 'flexacommerce' ), initialOpen: false }, list )
		);
	}

	function Edit( { name, attributes, setAttributes, context } ) {
		const blockProps = useBlockProps();
		const hasProduct = Object.prototype.hasOwnProperty.call( attributes, 'productId' );
		const inProduct = context && context.postType === PRODUCT && context.postId;
		const rendered = Object.assign( {}, attributes );
		if ( hasProduct && ! attributes.productId && inProduct ) {
			rendered.productId = context.postId;
		}

		const hasRule = Object.prototype.hasOwnProperty.call( attributes, 'ruleId' );
		const askProduct = hasProduct && ! inProduct && ! ( hasRule && attributes.ruleId );
		let controls =
			askProduct || hasRule
				? el(
						InspectorControls,
						null,
						hasRule &&
							el(
								PanelBody,
								{ title: __( 'Offer', 'flexacommerce' ) },
								el( RulePicker, {
									value: attributes.ruleId,
									onChange: ( ruleId ) => setAttributes( { ruleId } ),
								} )
							),
						askProduct &&
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
		if ( name === 'flexacommerce/product-filters' ) {
			controls = el( FilterControls, { attributes, setAttributes } );
		}
		const empty = EMPTY[ name ];

		return el(
			Fragment,
			null,
			controls,
			el(
				'div',
				blockProps,
				el(
					ServerSideRender,
					Object.assign(
						{ block: name, attributes: rendered },
						empty ? { EmptyResponsePlaceholder: () => el( 'p', null, empty ) } : {}
					)
				)
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
		'flexacommerce/quantity-prices',
		'flexacommerce/discount-badge',
		'flexacommerce/offer-countdown',
		'flexacommerce/discount-nudge',
		'flexacommerce/product-rating',
		'flexacommerce/product-reviews',
		'flexacommerce/order-lookup',
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
					[ 'flexacommerce/product-rating' ],
					[ 'flexacommerce/product-price' ],
					[ 'flexacommerce/product-add-to-cart' ],
				],
			],
			[ 'core/query-no-results', {}, [ [ 'core/paragraph', { content: __( 'No products found.', 'flexacommerce' ) } ] ] ],
		],
	} );
} )( window.wp );

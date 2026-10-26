/**
 * Interactivity store `flexacommerce/cart` (doc 02 §7.2): the add-to-cart
 * form, the cart table and the mini-cart. Every form also works without
 * this script, posting to admin-post.php.
 */
import {
	store,
	getConfig,
	getContext,
	getElement,
	withSyncEvent,
} from '@wordpress/interactivity';

const config = getConfig( 'flexacommerce/cart' );
const i18n = config.i18n || {};
let nonce = config.nonce || '';
let countRequested = false;
// Lines with a DELETE in flight. Typing a quantity and then clicking that
// line's remove button sends a PUT (the input's change on blur) and a DELETE
// at once; the PUT may land second and fail with `unknown_line`.
const removing = new Set();

const WILDCARD = '*';

function readCount() {
	const name = config.countName + '=';
	for ( const part of document.cookie.split( ';' ) ) {
		const cookie = part.trim();
		if ( cookie.startsWith( name ) ) {
			const value = parseInt( cookie.slice( name.length ), 10 );
			return Number.isFinite( value ) && value > 0 ? value : 0;
		}
	}
	return 0;
}

class StoreError extends Error {
	constructor( message, code, status, reason = '' ) {
		super( message );
		this.code = code;
		this.status = status;
		this.reason = reason;
	}
}

/**
 * One call to the store API. A stale nonce is answered with a fresh one,
 * and the request is retried once with it.
 */
async function request( path, method = 'GET', body = undefined, retried = false ) {
	const headers = { Accept: 'application/json' };
	if ( method !== 'GET' ) {
		headers[ 'X-FXC-Nonce' ] = nonce;
	}
	if ( body !== undefined ) {
		headers[ 'Content-Type' ] = 'application/json';
	}
	const response = await fetch( config.api + path, {
		method,
		headers,
		credentials: 'same-origin',
		body: body !== undefined ? JSON.stringify( body ) : undefined,
	} );
	const fresh = response.headers.get( 'X-FXC-Nonce' );
	if ( fresh ) {
		nonce = fresh;
	}
	let data = null;
	try {
		data = await response.json();
	} catch ( e ) {
		data = null;
	}
	if ( response.ok ) {
		return data;
	}
	if (
		! retried &&
		response.status === 403 &&
		data?.code === 'flexacommerce_invalid_nonce' &&
		data?.data?.nonce
	) {
		nonce = data.data.nonce;
		return request( path, method, body, true );
	}
	throw new StoreError(
		data?.message || i18n.failed,
		data?.code || 'fetch_failed',
		response.status,
		data?.data?.reason || ''
	);
}

function fits( variant, selection, keys ) {
	let wildcards = 0;
	for ( const key of keys ) {
		const value = variant.attributes[ key ];
		if ( value === undefined || value === WILDCARD ) {
			wildcards++;
			continue;
		}
		if ( selection[ key ] && selection[ key ] !== value ) {
			return -1;
		}
	}
	return wildcards;
}

/**
 * The variant for a full selection: an exact match first, else the one with
 * the fewest "any" values, in the server's order (same rule as the PHP
 * matcher).
 */
function match( matrix, selection ) {
	let best = null;
	let bestScore = Infinity;
	for ( const variant of matrix.variants ) {
		const score = fits( variant, selection, matrix.attributes );
		if ( score >= 0 && score < bestScore ) {
			best = variant;
			bestScore = score;
		}
	}
	return best;
}

function complete( matrix, selection ) {
	return matrix.attributes.every( ( key ) => !! selection[ key ] );
}

function stockText( variant ) {
	if ( ! variant.in_stock ) {
		return i18n.outOfStock;
	}
	return variant.max !== null ? i18n.left.replace( '%d', variant.max ) : i18n.inStock;
}

function applyCart( data ) {
	if ( ! data ) {
		return;
	}
	state.cart = data;
	state.count = data.item_count;
}

const { state, actions } = store( 'flexacommerce/cart', {
	state: {
		products: {},
		// productId => the variation the form picked, and the image shown.
		chosen: {},
		gallery: {},
		count: 0,
		message: '',
		couponCode: '',
		couponBusy: false,
		couponMessage: '',
		get coupons() {
			return state.cart?.coupons ?? [];
		},
		get hasDiscount() {
			return !! state.cart?.totals?.discount;
		},
		get couponLabel() {
			return ( i18n.coupon || '%s' ).replace( '%s', getContext().coupon.label );
		},
		get couponAmount() {
			const { coupon } = getContext();
			return coupon.valid ? '-' + coupon.discount.formatted : coupon.message;
		},
		get countLabel() {
			return state.count > 0 ? String( state.count ) : '';
		},
		get hasItems() {
			return !! state.cart && state.cart.items.length > 0;
		},
		get lineRemoved() {
			const { key } = getContext();
			return !! state.cart && ! state.cart.items.some( ( item ) => item.key === key );
		},
		get lineTotal() {
			const { key } = getContext();
			const item = state.cart?.items.find( ( line ) => line.key === key );
			return item?.total?.formatted ?? '';
		},
		get sku() {
			const { productId, sku } = getContext();
			return state.chosen[ productId ]?.sku || sku || '';
		},
		get galleryImage() {
			const context = getContext();
			const image =
				context.images[ state.gallery[ context.productId ] ] || context.images[ context.main ];
			return { ...image, alt: image.alt || context.title };
		},
		get galleryCurrent() {
			const context = getContext();
			const shown = String( state.gallery[ context.productId ] ?? context.main );
			return getElement().attributes[ 'data-image' ] === shown ? 'true' : false;
		},
		get cannotAdd() {
			const context = getContext();
			return context.busy || ! context.ready || context.variantId <= 0;
		},
		get optionUnavailable() {
			const context = getContext();
			const value = getElement().attributes.value;
			const matrix = state.products[ context.productId ];
			if ( ! value || ! matrix || ! context.attribute ) {
				return false;
			}
			const selection = { ...context.selection, [ context.attribute ]: value };
			return ! matrix.variants.some(
				( variant ) => variant.in_stock && fits( variant, selection, matrix.attributes ) >= 0
			);
		},
	},
	actions: {
		add: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const context = getContext();
			if ( context.busy ) {
				return;
			}
			if ( state.cannotAdd ) {
				context.message = context.variable ? i18n.choose : i18n.failed;
				context.failed = true;
				return;
			}
			context.busy = true;
			context.message = '';
			try {
				const data = yield request( 'cart/items', 'POST', {
					product_id: context.productId,
					variant_id: context.variantId,
					quantity: context.quantity,
				} );
				applyCart( data );
				context.message = i18n.added;
				context.failed = false;
			} catch ( error ) {
				context.message = error.message || i18n.failed;
				context.failed = true;
			} finally {
				context.busy = false;
			}
		} ),
		*choose( event ) {
			const context = getContext();
			context.selection[ context.attribute ] = event.target.value;
			context.message = '';
			context.failed = false;
			if ( context.remote && ! state.products[ context.productId ] ) {
				try {
					const data = yield request( `products/${ context.productId }/variants` );
					state.products[ context.productId ] = {
						attributes: data.attributes.map( ( attribute ) => attribute.key ),
						variants: data.variants,
					};
				} catch ( error ) {
					context.message = error.message || i18n.failed;
					context.failed = true;
					return;
				}
			}
			actions.refresh();
		},
		refresh() {
			const context = getContext();
			const matrix = state.products[ context.productId ];
			if ( ! matrix || ! complete( matrix, context.selection ) ) {
				context.ready = false;
				context.variantId = 0;
				actions.pick( context.productId, null );
				return;
			}
			const variant = match( matrix, context.selection );
			if ( ! variant ) {
				context.ready = false;
				context.variantId = 0;
				actions.pick( context.productId, null );
				context.message = i18n.unavailable;
				context.failed = true;
				return;
			}
			actions.pick( context.productId, variant );
			context.variantId = variant.in_stock ? variant.id : 0;
			context.price = variant.price;
			context.regular = variant.regular;
			context.suffix = variant.suffix;
			context.stock = stockText( variant );
			context.max = variant.max !== null ? Math.min( variant.max, context.limit ) : context.limit;
			context.quantity = Math.max( 1, Math.min( context.quantity, context.max ) );
			context.ready = true;
		},
		/** Tells the SKU and gallery blocks of the product which variation is chosen. */
		pick( productId, variant ) {
			const before = state.chosen[ productId ];
			state.chosen[ productId ] = variant;
			if ( variant?.image_id ) {
				state.gallery[ productId ] = variant.image_id;
			} else if ( before?.image_id && state.gallery[ productId ] === before.image_id ) {
				delete state.gallery[ productId ];
			}
		},
		showImage: withSyncEvent( ( event ) => {
			const context = getContext();
			const id = parseInt( getElement().ref.dataset.image, 10 );
			if ( ! context.images[ id ] ) {
				return;
			}
			event.preventDefault();
			state.gallery[ context.productId ] = id;
		} ),
		setQuantity( event ) {
			const context = getContext();
			const value = parseInt( event.target.value, 10 );
			context.quantity = Number.isFinite( value ) && value > 0 ? value : 1;
		},
		*setLineQuantity( event ) {
			const { key } = getContext();
			const value = parseInt( event.target.value, 10 );
			if ( ! Number.isFinite( value ) || value < 0 ) {
				return;
			}
			try {
				const data =
					value === 0
						? yield request( `cart/items/${ key }`, 'DELETE' )
						: yield request( `cart/items/${ key }`, 'PUT', { quantity: value } );
				applyCart( data );
				state.message = i18n.updated;
			} catch ( error ) {
				if ( error.reason !== 'unknown_line' ) {
					state.message = error.message || i18n.failed;
					return;
				}
				// Removed here a moment ago: the DELETE reports the result.
				if ( removing.has( key ) || ! state.cart?.items.some( ( item ) => item.key === key ) ) {
					return;
				}
				// Removed elsewhere (another tab): show the cart as it is now.
				state.message = error.message;
				try {
					applyCart( yield request( 'cart' ) );
				} catch ( e ) {
					// Keep the message; the next page load shows the cart.
				}
			}
		},
		setCouponCode( event ) {
			state.couponCode = event.target.value;
		},
		applyCoupon: withSyncEvent( function* ( event ) {
			event.preventDefault();
			if ( state.couponBusy ) {
				return;
			}
			state.couponBusy = true;
			try {
				applyCart( yield request( 'cart/coupons', 'POST', { code: state.couponCode } ) );
				state.couponCode = '';
				state.couponMessage = i18n.applied;
			} catch ( error ) {
				state.couponMessage = error.message || i18n.failed;
			} finally {
				state.couponBusy = false;
			}
		} ),
		removeCoupon: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const code = getElement().ref.value;
			try {
				applyCart( yield request( `cart/coupons/${ encodeURIComponent( code ) }`, 'DELETE' ) );
				state.couponMessage = i18n.unapplied;
			} catch ( error ) {
				state.couponMessage = error.message || i18n.failed;
			}
		} ),
		// Enter in a quantity field: its change event already sent the new
		// quantity, so the page does not post the form as well.
		submitCart: withSyncEvent( ( event ) => {
			event.preventDefault();
		} ),
		removeLine: withSyncEvent( function* ( event ) {
			event.preventDefault();
			const { key } = getContext();
			removing.add( key );
			try {
				applyCart( yield request( `cart/items/${ key }`, 'DELETE' ) );
				state.message = i18n.updated;
			} catch ( error ) {
				state.message = error.message || i18n.failed;
			} finally {
				removing.delete( key );
			}
		} ),
	},
	callbacks: {
		*initCount() {
			if ( countRequested ) {
				return;
			}
			countRequested = true;
			const count = readCount();
			state.count = state.cart ? state.cart.item_count : count;
			// A shopper who logged in on another device has a cart but no
			// count cookie in this browser yet; guests without it have none.
			if ( count === 0 && config.loggedIn && ! state.cart ) {
				try {
					const data = yield request( 'cart' );
					state.count = data.item_count;
				} catch ( error ) {
					state.count = 0;
				}
			}
		},
	},
} );

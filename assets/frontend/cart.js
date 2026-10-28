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
let nudgeRequested = false;
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

/** An amount in minor units as the store writes it (StoreMoneyFactory::format). */
function formatMoney( minor ) {
	const money = config.money;
	if ( ! money ) {
		return '';
	}
	const [ whole, fraction ] = ( minor / 10 ** money.decimals ).toFixed( money.decimals ).split( '.' );
	const grouped = whole.replace( /\B(?=(\d{3})+(?!\d))/g, money.thousand );
	const amount = fraction ? grouped + money.decimal + fraction : grouped;
	switch ( money.position ) {
		case 'right':
			return amount + money.symbol;
		case 'left_space':
			return money.symbol + ' ' + amount;
		case 'right_space':
			return amount + ' ' + money.symbol;
		default:
			return money.symbol + amount;
	}
}

/** The value of a product option field: text, a choice id, or a list of them. */
function optionValue( element, type ) {
	if ( type === 'checkboxes' ) {
		return [ ...element.querySelectorAll( 'input:checked' ) ].map( ( input ) => input.value );
	}
	if ( type === 'radio' || type === 'swatch' || type === 'button' ) {
		return element.querySelector( 'input:checked' )?.value ?? '';
	}
	const input = element.querySelector( 'input, select, textarea' );
	return input ? input.value.trim() : '';
}

/** Same rule as OptionField::visible(). */
function optionVisible( logic, seen ) {
	if ( ! logic || ! logic.rules.length ) {
		return true;
	}
	const any = logic.match === 'any';
	const hit = ( rule ) => {
		const value = seen[ rule.field ] ?? '';
		const empty = value === '' || value.length === 0;
		const is = Array.isArray( value ) ? value.includes( rule.value ) : value === rule.value;
		return { is_not: ! is, empty, not_empty: ! empty }[ rule.op ] ?? is;
	};
	const met = any ? logic.rules.some( hit ) : logic.rules.every( hit );
	return logic.action === 'show' ? met : ! met;
}

/**
 * A formula price (ADR-039), as Options\Formula reads it: the result, or
 * null when the formula cannot be read. `inputs` has base, qty and fields.
 */
function evaluate( formula, inputs ) {
	const tokens = [];
	const pattern = /\s*(?:(\d+(?:\.\d+)?|\.\d+)|\{\s*(\d{1,5})\s*\}|([a-z_]\w*)|([-+*/(),]))/iy;
	let found;
	let end = 0;
	while ( ( found = pattern.exec( formula ) ) ) {
		end = pattern.lastIndex;
		tokens.push( found[ 4 ] ? [ 'op', found[ 4 ] ] : found[ 1 ] ? [ 'number', found[ 1 ] ] : found[ 2 ] ? [ 'ref', found[ 2 ] ] : [ 'name', found[ 3 ].toLowerCase() ] );
	}
	if ( ! formula.trim() || formula.length > 300 || formula.slice( end ).trim() ) {
		return null;
	}
	let at = 0;
	const fail = () => {
		throw new Error();
	};
	const is = ( op ) => tokens[ at ]?.[ 0 ] === 'op' && tokens[ at ][ 1 ] === op;
	const next = () => tokens[ at++ ] ?? fail();
	const expect = ( op ) => ( is( op ) ? at++ : fail() );
	const sum = () => {
		let value = product();
		while ( is( '+' ) || is( '-' ) ) {
			value = next()[ 1 ] === '+' ? value + product() : value - product();
		}
		return value;
	};
	const product = () => {
		let value = unary();
		while ( is( '*' ) || is( '/' ) ) {
			const times = next()[ 1 ] === '*';
			const right = unary();
			value = times ? value * right : right === 0 ? 0 : value / right;
		}
		return value;
	};
	const unary = () => {
		if ( is( '-' ) || is( '+' ) ) {
			return next()[ 1 ] === '-' ? -unary() : unary();
		}
		const [ kind, text ] = next();
		if ( kind === 'number' ) {
			return parseFloat( text );
		}
		if ( kind === 'ref' ) {
			return inputs.fields[ parseInt( text, 10 ) ] ?? 0;
		}
		if ( kind === 'op' && text === '(' ) {
			const value = sum();
			expect( ')' );
			return value;
		}
		if ( kind === 'name' && ( text === 'base' || text === 'qty' ) ) {
			return inputs[ text ];
		}
		if ( kind !== 'name' || ! is( '(' ) || ! [ 'round', 'ceil', 'floor', 'min', 'max' ].includes( text ) ) {
			fail();
		}
		expect( '(' );
		const args = [ sum() ];
		while ( is( ',' ) ) {
			at++;
			args.push( sum() );
		}
		expect( ')' );
		if ( ( text === 'round' && args.length > 2 ) || ( ( text === 'ceil' || text === 'floor' ) && args.length > 1 ) ) {
			fail();
		}
		if ( text === 'round' ) {
			// Half away from zero, as PHP rounds.
			const digits = Math.max( -6, Math.min( 6, Math.trunc( args[ 1 ] ?? 0 ) ) );
			// Shifting by text keeps 1.005 from rounding down.
			const shifted = Number( Math.abs( args[ 0 ] ) + 'e' + digits );
			const whole = Math.round( Number.isFinite( shifted ) ? shifted : Math.abs( args[ 0 ] ) * 10 ** digits );
			return Math.sign( args[ 0 ] ) * Number( whole + 'e' + -digits );
		}
		return Math[ text ]( ...args );
	};
	try {
		const value = sum();
		return at === tokens.length && Number.isFinite( value ) ? value : null;
	} catch {
		return null;
	}
}

/**
 * What a price adds to one unit (a once-per-line price as is); NaN for a
 * percent before the unit price is known. A formula reads `inputs`.
 */
function charge( [ mode, amount, formula ], base, inputs ) {
	if ( mode === 'fixed' || mode === 'once' ) {
		return amount;
	}
	if ( mode === 'formula' ) {
		const result = evaluate( formula, inputs );
		return result === null || result <= 0 ? 0 : Math.round( result * 10 ** ( config.money?.decimals ?? 2 ) );
	}
	return base === null || base === undefined ? NaN : Math.round( ( base * Math.min( amount, 10000 ) ) / 10000 );
}

/** A value as a formula reads it (OptionField::measure()). */
function measure( field, value ) {
	if ( Array.isArray( value ) ) {
		return value.length;
	}
	if ( field.type === 'number' || field.type === 'range' ) {
		return parseFloat( value ) || 0;
	}
	if ( field.type === 'text' || field.type === 'textarea' ) {
		return [ ...value.replace( /\s+/g, '' ) ].length;
	}
	return Number( [ 'select', 'radio', 'swatch', 'button' ].includes( field.type ) );
}

/**
 * Shows and hides the option fields of a form per their logic (a hidden
 * field's inputs are disabled, so the no-JS post skips them too), and
 * returns the values to send and what they add to one unit.
 */
function readOptions( form, context ) {
	const values = {};
	const seen = {};
	const base = context.base ?? state.chosen[ context.productId ]?.amount;
	const measures = {};
	const priced = [];
	let extra = 0;
	for ( const field of context.fields ) {
		const element = form.querySelector( `[data-option="${ field.key }"]` );
		if ( ! element ) {
			continue;
		}
		seen[ field.set ] ??= {};
		const visible = optionVisible( field.logic, seen[ field.set ] );
		element.hidden = ! visible;
		for ( const input of element.querySelectorAll( 'input, select, textarea' ) ) {
			// A sold-out choice (ADR-040) stays off.
			input.disabled = ! visible || input.hasAttribute( 'data-out' );
		}
		const value = visible && field.type !== 'heading' ? optionValue( element, field.type ) : '';
		if ( field.type === 'range' ) {
			const output = element.querySelector( 'output' );
			if ( output && value !== '' ) {
				output.textContent = value;
			}
		}
		if ( value.length === 0 ) {
			continue;
		}
		seen[ field.set ][ field.id ] = value;
		values[ field.key ] = value;
		( measures[ field.set ] ??= {} )[ field.id ] = measure( field, value );
		priced.push( [ field, value ] );
	}
	// After the loop: a formula may read a field below it.
	for ( const [ field, value ] of priced ) {
		const inputs = {
			base: ( base ?? 0 ) / 10 ** ( config.money?.decimals ?? 2 ),
			qty: Math.max( 1, context.quantity || 1 ),
			fields: measures[ field.set ],
		};
		if ( field.prices ) {
			for ( const id of [].concat( value ) ) {
				extra += field.prices[ id ] ? charge( field.prices[ id ], base, inputs ) : 0;
			}
		} else if ( field.price ) {
			extra += charge( field.price, base, inputs );
		}
	}
	return { values, extra };
}

function applyCart( data ) {
	if ( ! data ) {
		return;
	}
	state.cart = data;
	state.count = data.item_count;
}

function nudgeOf() {
	return state.cart ? state.cart.next_discount ?? null : state.nudge ?? null;
}

/**
 * Marks the quantity tier row that `quantity` falls in, in the form and in
 * a Quantity prices block of the same product (ADR-050).
 */
function markTier( form, quantity ) {
	const id = form?.elements.product_id?.value;
	const rows = [ ...( form?.querySelectorAll( '.fxc-tiers tr[data-min]' ) || [] ) ];
	if ( id ) {
		rows.push( ...document.querySelectorAll( '.fxc-quantity-prices[data-product="' + id + '"] tr[data-min]' ) );
	}
	rows.forEach( ( row ) => {
		const max = parseInt( row.dataset.max, 10 ) || Infinity;
		row.classList.toggle( 'is-current', quantity >= parseInt( row.dataset.min, 10 ) && quantity <= max );
	} );
}

/** The id of the gallery slide shown; 0 is the video (ADR-048). */
function shownOf( context ) {
	return state.gallery[ context.productId ] ?? context.main;
}

/** The slides in order; the full-size view leaves out the video. */
function orderOf( context, full ) {
	const order = context.order || [];
	return full ? order.filter( ( id ) => id !== 0 ) : order;
}

/** Shows the image `delta` places away in the gallery, wrapping at the ends. */
function step( delta ) {
	const context = getContext();
	const order = orderOf( context, !! getElement().ref?.closest( 'dialog' ) );
	if ( order.length < 2 ) {
		return;
	}
	const at = order.indexOf( shownOf( context ) );
	const next = at < 0 ? ( delta > 0 ? 0 : order.length - 1 ) : ( at + delta + order.length ) % order.length;
	state.gallery[ context.productId ] = order[ next ];
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
			return ( state.cart?.totals?.discount?.amount ?? 0 ) > 0;
		},
		get showTotal() {
			return state.hasDiscount || ( state.cart?.fees?.length ?? 0 ) > 0;
		},
		get couponLabel() {
			const { coupon } = getContext();
			return ( ( coupon.automatic ? i18n.discount : i18n.coupon ) || '%s' ).replace( '%s', () => coupon.label );
		},
		// The Discount progress block (ADR-050): the cart once read, else what the server rendered.
		get noNudge() {
			return ! nudgeOf();
		},
		get nudgeLabel() {
			return nudgeOf()?.message ?? '';
		},
		get nudgeWidth() {
			return ( nudgeOf()?.percent ?? 0 ) + '%';
		},
		get nextLabel() {
			return state.cart?.next_discount?.message ?? '';
		},
		get nextWidth() {
			return ( state.cart?.next_discount?.percent ?? 0 ) + '%';
		},
		get couponAmount() {
			const { coupon } = getContext();
			if ( ! coupon.valid ) {
				return coupon.message;
			}
			// Mirrors CheckoutForm::coupon_amount().
			return coupon.free_shipping && coupon.discount.amount === 0 ? i18n.freeShip || '' : '-' + coupon.discount.formatted;
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
		/** False once the store runs, so the arrows that need it show. */
		get galleryStatic() {
			return false;
		},
		/** Shown in the full-size view, which has no video. */
		get galleryCount() {
			const context = getContext();
			const order = orderOf( context, true );
			const at = order.indexOf( shownOf( context ) );
			return at < 0 ? '' : `${ at + 1 } / ${ order.length }`;
		},
		get galleryCurrent() {
			return getElement().attributes[ 'data-image' ] === String( shownOf( getContext() ) ) ? 'true' : false;
		},
		get galleryVideo() {
			return shownOf( getContext() ) === 0;
		},
		/** The player's address, only once the shopper pressed play. */
		get videoSrc() {
			const context = getContext();
			return context.playing && shownOf( context ) === 0 ? getElement().attributes[ 'data-src' ] : false;
		},
		get cannotAdd() {
			const context = getContext();
			return context.busy || ! context.ready || context.variantId <= 0;
		},
		/** The name of the value picked in a swatch group, for its legend. */
		get chosenLabel() {
			const { selection, attribute, labels } = getContext();
			const value = selection?.[ attribute ];
			return value && labels ? labels[ value ] ?? '' : '';
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
			const body = {
				product_id: context.productId,
				variant_id: context.variantId,
				quantity: context.quantity,
			};
			if ( Object.keys( context.selection ).length ) {
				body.attributes = { ...context.selection };
			}
			if ( context.fields ) {
				body.options = readOptions( getElement().ref, context ).values;
			}
			if ( context.replace ) {
				body.replace = context.replace;
			}
			try {
				const data = yield request( 'cart/items', 'POST', body );
				applyCart( data );
				if ( context.replace ) {
					// Back to the cart the line was changed from (ADR-037).
					const url = new URL( config.cartUrl, window.location.href );
					url.searchParams.set( 'fxc-cart', 'updated' );
					window.location.assign( url.href );
					return;
				}
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
			if ( context.fields ) {
				actions.options();
			}
		},
		/** Product option fields: which show, and the total they add. */
		options() {
			const context = getContext();
			const form = getElement().ref?.closest( 'form' );
			if ( ! context.fields || ! form ) {
				return;
			}
			const { extra } = readOptions( form, context );
			context.extra = extra > 0 ? '+' + formatMoney( extra ) : '';
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
		playVideo() {
			const context = getContext();
			const player = getElement().ref.parentElement.querySelector( 'video, iframe' );
			context.playing = true;
			context.zoomed = false;
			player?.play?.()?.catch( () => {} );
			setTimeout( () => player?.focus() );
		},
		galleryPrev() {
			step( -1 );
		},
		galleryNext() {
			step( 1 );
		},
		openLightbox: withSyncEvent( ( event ) => {
			const dialog = getElement().ref.closest( '.fxc-gallery' )?.querySelector( '.fxc-lightbox' );
			if ( ! dialog || typeof dialog.showModal !== 'function' ) {
				return;
			}
			event.preventDefault();
			getContext().zoomed = false;
			dialog.showModal();
		} ),
		closeLightbox() {
			getElement().ref.closest( 'dialog' )?.close();
		},
		lightboxBackdrop( event ) {
			if ( event.target === getElement().ref ) {
				getElement().ref.close();
			}
		},
		lightboxKey: withSyncEvent( ( event ) => {
			if ( event.key === 'ArrowLeft' || event.key === 'ArrowRight' ) {
				event.preventDefault();
				step( event.key === 'ArrowLeft' ? -1 : 1 );
			}
		} ),
		swipeStart( event ) {
			getContext().touchX = event.touches[ 0 ]?.clientX ?? null;
		},
		swipeEnd( event ) {
			const context = getContext();
			const end = event.changedTouches[ 0 ]?.clientX;
			if ( context.touchX == null || end === undefined ) {
				return;
			}
			const distance = end - context.touchX;
			context.touchX = null;
			if ( Math.abs( distance ) > 40 ) {
				step( distance > 0 ? -1 : 1 );
			}
		},
		/** Hover zoom: the image scales from the point under the pointer. */
		zoom( event ) {
			const context = getContext();
			if ( shownOf( context ) === 0 ) {
				return;
			}
			const box = getElement().ref.getBoundingClientRect();
			if ( ! box.width || ! box.height ) {
				return;
			}
			const x = Math.min( 100, Math.max( 0, ( ( event.clientX - box.left ) / box.width ) * 100 ) );
			const y = Math.min( 100, Math.max( 0, ( ( event.clientY - box.top ) / box.height ) * 100 ) );
			context.origin = `${ x.toFixed( 1 ) }% ${ y.toFixed( 1 ) }%`;
			context.zoomed = true;
		},
		unzoom() {
			getContext().zoomed = false;
		},
		setQuantity( event ) {
			const context = getContext();
			const value = parseInt( event.target.value, 10 );
			context.quantity = Number.isFinite( value ) && value > 0 ? value : 1;
			markTier( event.target.form, context.quantity );
			if ( context.fields ) {
				// A formula price may read the quantity.
				actions.options();
			}
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
		/** Leaving the video slide stops the video, and coming back shows the play button again. */
		galleryVideo() {
			const context = getContext();
			const player = getElement().ref.querySelector( 'video' );
			if ( shownOf( context ) !== 0 ) {
				context.playing = false;
			}
			if ( ! context.playing ) {
				player?.pause();
			}
		},
		/** Ticks a countdown (ADR-030); at zero it says the offer has ended. */
		countdown() {
			const { ref } = getElement();
			const ends = Date.parse( ref.dataset.ends );
			const text = ref.querySelector( '.fxc-countdown__text' );
			const clock = ref.querySelector( '.fxc-countdown__clock' );
			if ( ! Number.isFinite( ends ) || ! text || ! clock ) {
				return undefined;
			}
			const values = clock.querySelectorAll( '[data-unit]' );
			let timer = 0;
			const tick = () => {
				const left = Math.floor( ( ends - Date.now() ) / 1000 );
				if ( left <= 0 ) {
					window.clearInterval( timer );
					clock.hidden = true;
					text.classList.remove( 'screen-reader-text' );
					text.textContent = ref.dataset.ended;
					ref.classList.add( 'is-ended' );
					return;
				}
				[ Math.floor( left / 86400 ), Math.floor( left / 3600 ) % 24, Math.floor( left / 60 ) % 60, left % 60 ].forEach(
					( n, i ) => {
						values[ i ].textContent = i > 0 && n < 10 ? '0' + n : String( n );
					}
				);
			};
			text.classList.add( 'screen-reader-text' );
			clock.hidden = false;
			tick();
			timer = window.setInterval( tick, 1000 );
			return () => window.clearInterval( timer );
		},
		/** A guest's page is the same for everyone: read the cart when the cookie says it has items. */
		*nudge() {
			if ( nudgeRequested || state.cart || config.loggedIn || readCount() === 0 ) {
				return;
			}
			nudgeRequested = true;
			try {
				applyCart( yield request( 'cart' ) );
			} catch ( error ) {
				// The bar stays hidden.
			}
		},
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

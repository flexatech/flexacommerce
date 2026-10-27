/**
 * FlexaCommerce checkout (doc 02 §7.5). The form works without this file:
 * it posts to the checkout page. When it loads, address and rate changes
 * requote over `store/checkout/shipping`, and the submit goes to
 * `store/checkout` with errors shown next to their fields. Hand-written,
 * no build step.
 */
const source = document.getElementById(
	'wp-script-module-data-@flexacommerce/checkout'
);
let config = {};
try {
	config = JSON.parse( source?.textContent || '{}' ) || {};
} catch ( e ) {
	config = {};
}
const i18n = config.i18n || {};
let nonce = config.nonce || '';

class StoreError extends Error {
	constructor( message, code, status, data ) {
		super( message );
		this.code = code;
		this.status = status;
		this.data = data || {};
	}
}

async function request( path, body, retried = false ) {
	const response = await fetch( config.api + path, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			Accept: 'application/json',
			'Content-Type': 'application/json',
			'X-FXC-Nonce': nonce,
		},
		body: JSON.stringify( body ),
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
	if ( data?.data?.nonce ) {
		nonce = data.data.nonce;
	}
	if ( response.ok ) {
		return data;
	}
	if (
		! retried &&
		response.status === 403 &&
		data?.code === 'flexacommerce_invalid_nonce'
	) {
		return request( path, body, true );
	}
	throw new StoreError(
		data?.message || i18n.failed,
		data?.code || 'fetch_failed',
		response.status,
		data?.data
	);
}

function debounce( fn, wait ) {
	let timer = 0;
	return ( ...args ) => {
		window.clearTimeout( timer );
		timer = window.setTimeout( () => fn( ...args ), wait );
	};
}

function el( tag, attrs = {}, text = '' ) {
	const node = document.createElement( tag );
	for ( const [ name, value ] of Object.entries( attrs ) ) {
		if ( value === true ) {
			node.setAttribute( name, '' );
		} else if ( value !== false && value !== null && value !== undefined ) {
			node.setAttribute( name, String( value ) );
		}
	}
	if ( text ) {
		node.textContent = text;
	}
	return node;
}

function fieldId( path ) {
	const match = /^(billing|shipping|additional)\.([a-z0-9_]+)$/.exec( path );
	if ( match ) {
		return `fxc-${ match[ 1 ] }-${ match[ 2 ] }`;
	}
	if ( path.startsWith( 'gateway.' ) ) {
		return 'fxc-payment-method';
	}
	return (
		{
			shipping_rate: 'fxc-shipping-rate',
			payment_method: 'fxc-payment-method',
			terms: 'fxc-terms',
			customer_note: 'fxc-customer-note',
		}[ path ] || ''
	);
}

function setup( root ) {
	const form = root.querySelector( '.fxc-checkout__form' );
	if ( ! form ) {
		return;
	}
	const toggle = form.querySelector( '[data-fxc-ship-toggle]' );
	const shippingBox = form.querySelector( '[data-fxc-shipping-address]' );
	const message = form.querySelector( '[data-fxc-message]' );
	const summary = root.querySelector( '[data-fxc-errors]' );
	const place = form.querySelector( '[data-fxc-place]' );
	let busy = false;

	const elsewhere = () => !! toggle && toggle.checked;
	const address = ( type ) =>
		form.querySelector( `[data-fxc-address="${ type }"]` );
	const control = ( type, key ) =>
		address( type )?.querySelector( `[data-fxc-key="${ key }"]` );
	const value = ( type, key ) => control( type, key )?.value || '';
	const activeType = () => ( elsewhere() ? 'shipping' : 'billing' );

	function syncShipping() {
		if ( ! shippingBox ) {
			return;
		}
		shippingBox.hidden = ! elsewhere();
		for ( const input of shippingBox.querySelectorAll(
			'input, select, textarea'
		) ) {
			input.disabled = ! elsewhere();
			if ( input.getAttribute( 'aria-required' ) === 'true' ) {
				input.required = elsewhere();
			}
		}
	}

	/**
	 * Fields of the merchant's for some countries only: an address's own
	 * fields follow its country, Additional information follows the country
	 * the order goes to. A hidden one is disabled, so it is not sent.
	 */
	function syncCountries() {
		for ( const wrap of form.querySelectorAll( '[data-fxc-countries]' ) ) {
			const type =
				wrap.closest( '[data-fxc-address]' )?.dataset.fxcAddress;
			const country = value( type || activeType(), 'country' );
			const off =
				! wrap.dataset.fxcCountries.split( ' ' ).includes( country );
			wrap.hidden = off;
			for ( const input of wrap.querySelectorAll(
				'input, select, textarea'
			) ) {
				input.disabled = off || ( type === 'shipping' && ! elsewhere() );
			}
			if ( wrap.matches( 'fieldset' ) ) {
				wrap.disabled = off;
			}
		}
		for ( const box of form.querySelectorAll(
			'.fxc-checkout__additional'
		) ) {
			box.hidden = ! box.querySelector( '.fxc-field:not([hidden])' );
		}
	}

	/** Swap the state field between a select and a text input for a country. */
	function syncState( type ) {
		const current = control( type, 'state' );
		if ( ! current ) {
			return;
		}
		const states = ( config.states || {} )[ value( type, 'country' ) ];
		const wantsSelect = !! states && Object.keys( states ).length > 0;
		const isSelect = current.tagName === 'SELECT';
		let next = current;
		if ( wantsSelect ) {
			next = isSelect ? current : el( 'select' );
			const chosen = current.value;
			next.replaceChildren(
				el( 'option', { value: '' }, i18n.select || '' )
			);
			for ( const [ code, name ] of Object.entries( states ) ) {
				next.append(
					el(
						'option',
						{ value: code, selected: code === chosen },
						name
					)
				);
			}
		} else if ( isSelect ) {
			next = el( 'input', { type: 'text' } );
		}
		if ( next !== current ) {
			for ( const name of [
				'id',
				'name',
				'autocomplete',
				'data-fxc-key',
				'aria-required',
			] ) {
				const attr = current.getAttribute( name );
				if ( attr !== null ) {
					next.setAttribute( name, attr );
				}
			}
			next.required = current.required;
			current.replaceWith( next );
		}
	}

	/** Labels, required flags and which fields exist follow the country. */
	function applyFields( type, fields ) {
		const box = address( type );
		if ( ! box || ! Array.isArray( fields ) ) {
			return;
		}
		const byId = Object.fromEntries( fields.map( ( f ) => [ f.id, f ] ) );
		for ( const input of box.querySelectorAll( '[data-fxc-key]' ) ) {
			const field = byId[ input.dataset.fxcKey ];
			const wrap = input.closest( '.fxc-field' );
			if ( ! wrap ) {
				continue;
			}
			wrap.hidden = ! field;
			input.disabled = ! field || ( type === 'shipping' && ! elsewhere() );
			if ( ! field ) {
				continue;
			}
			const label = wrap.querySelector( ':scope > label, :scope > legend' );
			if ( label ) {
				label.replaceChildren( document.createTextNode( field.label ) );
				if ( field.required ) {
					label.append(
						' ',
						el(
							'abbr',
							{ class: 'fxc-required', title: i18n.required },
							'*'
						)
					);
				}
			}
			if ( type === 'shipping' ) {
				input.setAttribute(
					'aria-required',
					field.required ? 'true' : 'false'
				);
				input.required = field.required && elsewhere();
			} else {
				input.required = !! field.required;
			}
		}
	}

	function renderRates( data ) {
		const box = form.querySelector( '[data-fxc-rates]' );
		if ( ! box ) {
			return;
		}
		box.hidden = ! data.needs_shipping;
		for ( const old of box.querySelectorAll( '.fxc-choice, p' ) ) {
			old.remove();
		}
		const legend = box.querySelector( 'legend' );
		const after = legend ? legend.nextSibling : box.firstChild;
		const rates = data.shipping_rates || [];
		if ( rates.length === 0 ) {
			box.insertBefore( el( 'p', {}, i18n.noRates || '' ), after );
			return;
		}
		for ( const rate of rates ) {
			const p = el( 'p', { class: 'fxc-choice' } );
			const label = el( 'label' );
			label.append(
				el( 'input', {
					type: 'radio',
					name: 'shipping_rate',
					value: rate.id,
					'data-fxc-rate': true,
					checked: rate.id === data.shipping_rate,
				} ),
				' ',
				el( 'span', {}, rate.label ),
				' ',
				el( 'strong', {}, rate.cost.formatted )
			);
			p.append( label );
			box.insertBefore( p, after );
		}
	}

	function renderTotals( data ) {
		for ( const key of [ 'subtotal', 'shipping', 'tax', 'total' ] ) {
			const cell = form.querySelector( `[data-fxc-total="${ key }"]` );
			if ( cell && data.totals?.[ key ] ) {
				cell.textContent = data.totals[ key ].formatted;
			}
		}
		renderCoupons( data.coupons );
		renderTaxes( data.tax );
		const foldTotal = form.querySelector( '[data-fxc-fold-total]' );
		if ( foldTotal && data.totals?.total ) {
			foldTotal.textContent = data.totals.total.formatted;
		}
		const expected = form.querySelector( '[data-fxc-expected]' );
		if ( expected && data.totals?.total ) {
			expected.value = String( data.totals.total.amount );
		}
	}

	// Mirrors CheckoutForm::coupon_rows(): after the subtotal.
	function renderCoupons( coupons ) {
		const subtotalRow = form.querySelector( '[data-fxc-total="subtotal"]' )?.closest( 'tr' );
		if ( ! subtotalRow || ! coupons ) {
			return;
		}
		form.querySelectorAll( '[data-fxc-coupon-row]' ).forEach( ( row ) => row.remove() );
		let anchor = subtotalRow.nextSibling;
		for ( const coupon of coupons ) {
			if ( ! coupon.valid ) {
				continue;
			}
			const row = el( 'tr', { class: 'fxc-checkout__coupon', 'data-fxc-coupon-row': true } );
			row.append(
				el( 'th', { scope: 'row' }, ( ( coupon.automatic ? i18n.discount : i18n.coupon ) || '%s' ).replace( '%s', coupon.label ) ),
				el( 'td', {}, coupon.free_shipping && coupon.discount.amount === 0 ? i18n.freeShip || '' : '-' + coupon.discount.formatted )
			);
			subtotalRow.parentNode.insertBefore( row, anchor );
			anchor = row.nextSibling;
		}
	}

	// Mirrors CheckoutForm::tax_rows(): rows before the total, or after it with "Includes".
	function renderTaxes( tax ) {
		const totalRow = form.querySelector( '[data-fxc-total="total"]' )?.closest( 'tr' );
		if ( ! totalRow || ! tax ) {
			return;
		}
		form.querySelectorAll( '[data-fxc-tax-row]' ).forEach( ( row ) => row.remove() );
		if ( ! tax.enabled ) {
			return;
		}
		let anchor = tax.included ? totalRow.nextSibling : totalRow;
		for ( const line of tax.lines ?? [] ) {
			const row = el( 'tr', {
				class: tax.included ? 'fxc-checkout__tax fxc-checkout__tax--included' : 'fxc-checkout__tax',
				'data-fxc-tax-row': true,
			} );
			const text = tax.included
				? ( i18n.includes || '%1$s %2$s' ).replace( '%1$s', line.amount.formatted ).replace( '%2$s', line.label )
				: line.amount.formatted;
			row.append( el( 'th', { scope: 'row' }, tax.included ? '' : line.label ), el( 'td', {}, text ) );
			totalRow.parentNode.insertBefore( row, anchor );
			if ( tax.included ) {
				anchor = row.nextSibling;
			}
		}
	}

	let pending = null;
	async function requote() {
		const type = activeType();
		const rate = form.querySelector( 'input[name="shipping_rate"]:checked' );
		const body = {
			country: value( type, 'country' ),
			state: value( type, 'state' ),
			postcode: value( type, 'postcode' ),
			city: value( type, 'city' ),
			rate_id: rate ? rate.value : '',
		};
		if ( ! body.country ) {
			return null;
		}
		const call = request( 'checkout/shipping', body );
		pending = call;
		try {
			const data = await call;
			if ( pending !== call ) {
				return data;
			}
			applyFields( type, data.fields?.[ type ] );
			syncCountries();
			renderRates( data );
			renderTotals( data );
			return data;
		} catch ( error ) {
			message.textContent = error.message;
			return null;
		}
	}
	const requoteSoon = debounce( requote, 350 );

	// Server-rendered errors from a failed post go too, with the
	// descriptions that point at them.
	function clearErrors() {
		for ( const node of form.querySelectorAll( '.fxc-field__error' ) ) {
			node.remove();
		}
		for ( const node of form.querySelectorAll( '[aria-invalid="true"]' ) ) {
			node.removeAttribute( 'aria-invalid' );
		}
		for ( const node of form.querySelectorAll( '[aria-describedby]' ) ) {
			const ids = node
				.getAttribute( 'aria-describedby' )
				.split( ' ' )
				.filter( ( id ) => id && ! /(^fxc-gateway-error-|-error$)/.test( id ) );
			if ( ids.length ) {
				node.setAttribute( 'aria-describedby', ids.join( ' ' ) );
			} else {
				node.removeAttribute( 'aria-describedby' );
			}
		}
		if ( summary ) {
			summary.hidden = true;
			summary.replaceChildren();
			summary.classList.remove( 'fxc-notice', 'fxc-notice--error' );
			summary.removeAttribute( 'role' );
		}
		message.textContent = '';
	}

	function showErrors( errors ) {
		const list = el( 'ul' );
		for ( const [ path, text ] of Object.entries( errors ) ) {
			const id = fieldId( path );
			const target = id ? document.getElementById( id ) : null;
			const item = el( 'li' );
			if ( target ) {
				const link = el( 'a', { href: `#${ id }` }, text );
				item.append( link );
				const note = el(
					'span',
					{
						class: 'fxc-field__error',
						id: `${ id }-error`,
						'data-fxc-js-error': true,
					},
					text
				);
				if ( target.tagName === 'FIELDSET' ) {
					// A group takes no aria-invalid; several errors may describe it.
					note.id = `${ id }-error-${ list.children.length }`;
					target.append( note );
					target.setAttribute(
						'aria-describedby',
						`${ target.getAttribute( 'aria-describedby' ) || '' } ${ note.id }`.trim()
					);
				} else {
					target.setAttribute( 'aria-invalid', 'true' );
					target.setAttribute(
						'aria-describedby',
						`${ target.getAttribute( 'aria-describedby' ) || '' } ${ note.id }`.trim()
					);
					target.closest( '.fxc-field' )?.append( note );
				}
			} else {
				item.textContent = text;
			}
			list.append( item );
		}
		if ( summary ) {
			summary.replaceChildren( el( 'p', {}, i18n.summary || '' ), list );
			summary.classList.add( 'fxc-notice', 'fxc-notice--error' );
			summary.setAttribute( 'role', 'alert' );
			summary.hidden = false;
			summary.focus();
		}
	}

	function payload() {
		const data = new FormData( form );
		const body = {
			billing: {},
			shipping: {},
			additional: {},
			gateway_input: {},
		};
		const method = data.get( 'payment_method' ) || '';
		for ( const [ name, raw ] of data.entries() ) {
			const value = typeof raw === 'string' ? raw : '';
			let match = /^(billing|shipping|additional)\[([a-z0-9_]+)\]$/.exec(
				name
			);
			if ( match ) {
				body[ match[ 1 ] ][ match[ 2 ] ] = value;
				continue;
			}
			match = /^gateway_input\[([a-z0-9_-]+)\]\[([a-z0-9_]+)\]$/.exec(
				name
			);
			if ( match ) {
				if ( match[ 1 ] === method ) {
					body.gateway_input[ match[ 2 ] ] = value;
				}
				continue;
			}
			if ( ! name.includes( '[' ) ) {
				body[ name ] = value;
			}
		}
		if ( body.expected_total !== undefined ) {
			body.expected_total = Number( body.expected_total );
		}
		return body;
	}

	async function submit() {
		if ( busy ) {
			return;
		}
		busy = true;
		place?.setAttribute( 'aria-disabled', 'true' );
		form.classList.add( 'is-busy' );
		clearErrors();
		message.textContent = i18n.placing || '';
		try {
			const result = await request( 'checkout', payload() );
			window.location.assign( result.redirect );
			return;
		} catch ( error ) {
			message.textContent = '';
			if ( error.code === 'flexacommerce_invalid_checkout' ) {
				showErrors( error.data.errors || { cart: error.message } );
			} else if ( error.code === 'flexacommerce_totals_changed' ) {
				await requote();
				showErrors( { cart: i18n.changed || error.message } );
			} else {
				showErrors( { cart: error.message } );
			}
		}
		busy = false;
		place?.removeAttribute( 'aria-disabled' );
		form.classList.remove( 'is-busy' );
	}

	// A summary link moves focus to its control; for a fieldset, the
	// chosen radio or the first control in it.
	summary?.addEventListener( 'click', ( event ) => {
		const link = event.target.closest( 'a[href^="#"]' );
		const target = link
			? document.getElementById( link.getAttribute( 'href' ).slice( 1 ) )
			: null;
		if ( ! target ) {
			return;
		}
		const focusable =
			target.tagName === 'FIELDSET'
				? target.querySelector( 'input:checked' ) ||
				  target.querySelector( 'input, select, textarea' )
				: target;
		if ( focusable ) {
			event.preventDefault();
			focusable.focus();
			focusable.scrollIntoView( { block: 'center' } );
		}
	} );

	form.addEventListener( 'submit', ( event ) => {
		event.preventDefault();
		if ( event.submitter?.hasAttribute( 'data-fxc-refresh' ) ) {
			requote();
			return;
		}
		submit();
	} );

	form.addEventListener( 'change', ( event ) => {
		const target = event.target;
		if ( target === toggle ) {
			syncShipping();
			syncCountries();
			requoteSoon();
			return;
		}
		if ( target.matches( '[data-fxc-rate]' ) ) {
			requoteSoon();
			return;
		}
		const box = target.closest( '[data-fxc-address]' );
		const key = target.dataset?.fxcKey;
		if ( ! box || ! key ) {
			return;
		}
		const type = box.dataset.fxcAddress;
		if ( key === 'country' ) {
			syncState( type );
			syncCountries();
		}
		if (
			type === activeType() &&
			[ 'country', 'state', 'postcode', 'city' ].includes( key )
		) {
			requoteSoon();
		}
	} );

	// Gateways may bring a script module that enhances their own fields.
	for ( const method of form.querySelectorAll( '[data-fxc-module]' ) ) {
		import( method.dataset.fxcModule ).catch( () => {} );
	}

	for ( const button of form.querySelectorAll( '[data-fxc-refresh]' ) ) {
		button.closest( 'p' ).hidden = true;
	}
	// The summary folded at the top on phones: closed there, always open on wider screens.
	const fold = form.querySelector( '[data-fxc-fold]' );
	if ( fold ) {
		const wide = window.matchMedia( '(min-width: 783px)' );
		const sync = () => ( fold.open = wide.matches );
		sync();
		wide.addEventListener( 'change', sync );
	}
	syncShipping();
	syncCountries();
	// After a failed post without scripts the summary is already there.
	if ( summary && ! summary.hidden ) {
		summary.focus();
	}
}

for ( const root of document.querySelectorAll( '[data-fxc-checkout]' ) ) {
	setup( root );
}

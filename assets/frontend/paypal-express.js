/*
 * The PayPal buttons beside the wallets (ADR-054): on the product page
 * (buy now, the cart stays as it is), the cart and the top of the
 * checkout. A click opens an order on PayPal through
 * `store/express/session`, which follows the address and the shipping
 * option the shopper picks in PayPal's window. On approval the order goes
 * to `store/express/order` with the PayPal order id; the server reads who
 * pays and where it ships from PayPal and captures the payment.
 */
let data = {};
try {
	data = JSON.parse(
		document.getElementById(
			'wp-script-module-data-@flexacommerce/paypal-express'
		)?.textContent || '{}'
	);
} catch {}
const i18n = data.i18n || {};
let nonce = data.nonce || '';

function loadPayPal( url ) {
	const loaded = ( window.fxcPayPal ??= {} );
	loaded[ url ] ??= new Promise( ( resolve, reject ) => {
		const script = document.createElement( 'script' );
		script.src = url;
		script.async = true;
		script.onload = () =>
			window.paypal?.Buttons ? resolve( window.paypal ) : reject( new Error() );
		script.onerror = reject;
		document.head.append( script );
	} );
	return loaded[ url ];
}

async function request( path, body, retried = false ) {
	const response = await fetch( data.api + path, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			Accept: 'application/json',
			'Content-Type': 'application/json',
			'X-FXC-Nonce': nonce,
		},
		body: JSON.stringify( body ),
	} );
	nonce = response.headers.get( 'X-FXC-Nonce' ) || nonce;
	let json = null;
	try {
		json = await response.json();
	} catch {}
	nonce = json?.data?.nonce || nonce;
	if ( response.ok ) {
		return json;
	}
	if ( ! retried && json?.code === 'flexacommerce_invalid_nonce' ) {
		return request( path, body, true );
	}
	throw json || {};
}

// The first message of a refused order, else ours.
function messageOf( error ) {
	const errors = error?.data?.errors;
	const first =
		errors && typeof errors === 'object' ? Object.values( errors )[ 0 ] : '';
	return first || error?.message || i18n.failed || '';
}

// What a field of the checkout form holds, for a check before the window opens.
function filled( field ) {
	if ( typeof RadioNodeList !== 'undefined' && field instanceof RadioNodeList ) {
		return field.value !== '';
	}
	return field.type === 'checkbox' ? field.checked : field.value.trim() !== '';
}

async function mount( row ) {
	let config = {};
	try {
		config = JSON.parse( row.dataset.fxcExpress || '{}' );
	} catch {}
	const box = row.closest( '.fxc-express' );
	if ( config.gateway !== 'paypal' || ! config.sdk || ! data.api || ! box ) {
		return;
	}
	const product = config.place === 'product' ? box.closest( 'form' ) : null;
	const checkout =
		config.place === 'checkout'
			? box
					.closest( '[data-fxc-checkout]' )
					?.querySelector( '.fxc-checkout__form' )
			: null;
	const message = box.querySelector( '.fxc-express__message' );
	const say = ( text ) => {
		message.textContent = text || '';
		message.hidden = ! text;
	};

	let paypal;
	try {
		paypal = await loadPayPal( config.sdk );
	} catch {
		return;
	}

	// The product choice on the add-to-cart form, as the cart route takes it.
	const choice = () => {
		const out = {
			product_id: config.product_id,
			variant_id: 0,
			quantity: 1,
			attributes: {},
			options: {},
		};
		for ( const [ name, raw ] of new FormData( product ).entries() ) {
			if ( typeof raw !== 'string' ) {
				continue;
			}
			let match;
			if ( name === 'variant_id' ) {
				out.variant_id = Number( raw );
			} else if ( name === 'quantity' ) {
				out.quantity = Number( raw ) || 1;
			} else if ( ( match = /^attributes\[([^\]]+)\]$/.exec( name ) ) ) {
				out.attributes[ match[ 1 ] ] = raw;
			} else if ( ( match = /^options\[([^\]]+)\](\[\])?$/.exec( name ) ) ) {
				if ( match[ 2 ] ) {
					( out.options[ match[ 1 ] ] ??= [] ).push( raw );
				} else if ( raw !== '' ) {
					out.options[ match[ 1 ] ] = raw;
				}
			}
		}
		return out;
	};
	const base = () => {
		const body = {
			source: product ? 'product' : 'cart',
			payment_method: config.payment_method,
		};
		if ( product ) {
			body.product = choice();
		}
		return body;
	};

	let quote = null;
	let session = '';
	let address = null;
	let rate = '';
	let funding = '';

	// Opens the PayPal order, or brings it to the new address or option.
	const sync = async () => {
		const body = { ...base(), shipping_rate: rate, session };
		if ( address ) {
			body.address = address;
		}
		quote = await request( 'express/session', body );
		session = quote.session || session;
		return quote;
	};

	const buttons = paypal.Buttons( {
		style: { color: config.color || 'gold', height: 40 },
		onClick( event, actions ) {
			say( '' );
			if ( product ) {
				if ( ! product.checkValidity() || ! choice().variant_id ) {
					product.reportValidity();
					say( i18n.choose );
					return actions.reject();
				}
			}
			if ( checkout ) {
				const missing = ( config.fields || [] )
					.map( ( name ) => checkout.elements.namedItem( name ) )
					.filter( ( field ) => field && ! filled( field ) );
				if ( missing.length ) {
					const first = missing[ 0 ][ 0 ] || missing[ 0 ];
					first.focus();
					first.reportValidity?.();
					say( i18n.fill );
					return actions.reject();
				}
			}
			funding = event.fundingSource || '';
			session = '';
			address = null;
			rate = '';
			return actions.resolve();
		},
		async createOrder() {
			try {
				const next = await sync();
				if ( ! next.payable || ! next.session ) {
					throw {};
				}
				return next.session;
			} catch ( error ) {
				say( messageOf( error ) );
				throw error;
			}
		},
		async onShippingAddressChange( event, actions ) {
			const shared = event.shippingAddress || {};
			address = {
				country: shared.countryCode || '',
				state: shared.state || '',
				postcode: shared.postalCode || '',
				city: shared.city || '',
			};
			rate = '';
			try {
				const next = await sync();
				if ( ! next.payable || ! next.shipping_rates.length ) {
					throw {};
				}
			} catch {
				return actions.reject( event.errors?.ADDRESS_ERROR );
			}
		},
		async onShippingOptionsChange( event, actions ) {
			rate = event.selectedShippingOption?.id || '';
			try {
				await sync();
			} catch {
				return actions.reject( event.errors?.METHOD_UNAVAILABLE );
			}
		},
		async onApprove( event ) {
			const body = {
				...base(),
				billing: {},
				shipping: null,
				shipping_rate: rate || quote?.shipping_rate || '',
				checkout_token: product
					? crypto.randomUUID()
					: quote?.checkout_token || '',
				expected_total: quote?.total,
				gateway_input: {
					session: event.orderID || session,
					express: funding,
				},
			};
			if ( checkout ) {
				// The form's own fields; what PayPal knows of the payer wins.
				body.additional = {};
				body.shipping = {};
				for ( const [ name, raw ] of new FormData( checkout ).entries() ) {
					const match = /^(billing|shipping|additional)\[([a-z0-9_]+)\]$/.exec(
						name
					);
					if ( typeof raw !== 'string' ) {
						continue;
					}
					if ( match ) {
						body[ match[ 1 ] ][ match[ 2 ] ] = raw;
					} else if ( name === 'customer_note' ) {
						body.customer_note = raw;
					}
				}
			}
			try {
				const result = await request( 'express/order', body );
				window.location.assign( result.redirect );
			} catch ( error ) {
				say( messageOf( error ) );
			}
		},
		onError() {
			if ( ! message.textContent ) {
				say( i18n.failed );
			}
		},
	} );
	if ( ! buttons.isEligible() ) {
		return;
	}
	row.hidden = false;
	box.hidden = false;
	await buttons.render( row.querySelector( '.fxc-express__buttons' ) );
}

for ( const row of document.querySelectorAll( '[data-fxc-express]' ) ) {
	mount( row );
}

/*
 * Apple Pay and Google Pay buttons (ADR-053), drawn by Stripe's Express
 * Checkout Element. The wallet sheet shows what `store/express` quotes for
 * the address the wallet shares; the order goes to `store/express/order`
 * with a payment method made from the wallet, and Stripe.js finishes
 * 3-D Secure when the bank asks. On a product page the button buys that
 * product alone. The box stays hidden on a device without a wallet.
 */
const SCRIPT = 'https://js.stripe.com/v3/';
const WALLET = [
	'first_name',
	'last_name',
	'address_1',
	'address_2',
	'city',
	'state',
	'postcode',
	'country',
	'email',
	'phone',
];

let data = {};
try {
	data = JSON.parse(
		document.getElementById( 'wp-script-module-data-@flexacommerce/express' )
			?.textContent || '{}'
	);
} catch {}
const i18n = data.i18n || {};
let nonce = data.nonce || '';

function loadStripe() {
	if ( window.Stripe ) {
		return Promise.resolve( window.Stripe );
	}
	return new Promise( ( resolve, reject ) => {
		const script = document.createElement( 'script' );
		script.src = SCRIPT;
		script.async = true;
		script.onload = () =>
			window.Stripe ? resolve( window.Stripe ) : reject( new Error() );
		script.onerror = reject;
		document.head.append( script );
	} );
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

// The first message of a refused order, else Stripe's or ours.
function messageOf( error ) {
	const errors = error?.data?.errors;
	const first =
		errors && typeof errors === 'object' ? Object.values( errors )[ 0 ] : '';
	return first || error?.message || i18n.failed || '';
}

// A wallet's contact as the checkout's address fields.
function addressOf( contact, email, phone ) {
	const name = ( contact?.name || '' ).trim();
	const space = name.indexOf( ' ' );
	const address = contact?.address || {};
	return {
		first_name: space > 0 ? name.slice( 0, space ) : name,
		last_name: space > 0 ? name.slice( space + 1 ).trim() : '',
		address_1: address.line1 || '',
		address_2: address.line2 || '',
		city: address.city || '',
		state: address.state || '',
		postcode: address.postal_code || '',
		country: address.country || '',
		email: email || '',
		phone: phone || '',
	};
}

// What a field of the checkout form holds, for a check before the sheet opens.
function filled( field ) {
	if ( typeof RadioNodeList !== 'undefined' && field instanceof RadioNodeList ) {
		return field.value !== '';
	}
	return field.type === 'checkbox' ? field.checked : field.value.trim() !== '';
}

async function mount( box ) {
	let config = {};
	try {
		config = JSON.parse( box.dataset.fxcExpress || '{}' );
	} catch {}
	if ( ! config.key || ! data.api ) {
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
	const units = ( amount ) => Math.round( amount * 10 ** config.shift );

	let stripe;
	try {
		stripe = ( await loadStripe() )( config.key, { locale: config.locale } );
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
	let token = '';
	let address = null;
	let rate = '';
	let elements = null;

	const refresh = async () => {
		const body = { ...base(), shipping_rate: rate };
		if ( address ) {
			body.address = address;
		}
		const next = await request( 'express', body );
		quote = next;
		token = next.checkout_token || token;
		if ( elements && next.payable ) {
			elements.update( { amount: units( next.total ) } );
		}
		return next;
	};
	// The shipping line is the rate the wallet adds; the chosen one goes first.
	const lines = ( q ) =>
		q.lines.map( ( line ) => ( {
			name: line.label,
			amount: units( line.amount ),
		} ) );
	const rates = ( q ) => {
		const list = [ ...q.shipping_rates ].sort(
			( a, b ) =>
				( b.id === q.shipping_rate ) - ( a.id === q.shipping_rate )
		);
		return list.length
			? list.map( ( r ) => ( {
					id: r.id,
					displayName: r.label,
					amount: units( r.amount ),
			  } ) )
			: [
					{
						id: 'pending',
						displayName: i18n.pending || '',
						amount: 0,
					},
			  ];
	};

	try {
		await refresh();
	} catch {}
	elements = stripe.elements( {
		mode: 'payment',
		amount: quote?.payable ? units( quote.total ) : 100,
		currency: config.currency,
		paymentMethodCreation: 'manual',
	} );
	const theme = config.theme || 'black';
	const button = elements.create( 'expressCheckout', {
		paymentMethods: {
			applePay: 'auto',
			googlePay: 'auto',
			link: 'never',
			paypal: 'never',
			amazonPay: 'never',
			klarna: 'never',
		},
		buttonTheme: {
			applePay: theme,
			googlePay: theme === 'black' ? 'black' : 'white',
		},
		layout: { overflow: 'never' },
	} );
	button.on( 'ready', ( event ) => {
		const found = event.availablePaymentMethods;
		box.hidden = ! ( found && Object.values( found ).some( Boolean ) );
	} );

	// The sheet must open within a second of the click, so it uses the last quote.
	button.on( 'click', ( event ) => {
		say( '' );
		if ( product ) {
			if ( ! product.checkValidity() || ! choice().variant_id ) {
				product.reportValidity();
				say( i18n.choose );
				return;
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
				return;
			}
		}
		if ( ! quote?.payable ) {
			say( i18n.failed );
			return;
		}
		const options = {
			emailRequired: true,
			phoneNumberRequired: Boolean( config.phone ),
			billingAddressRequired: true,
			lineItems: lines( quote ),
		};
		if ( quote.needs_shipping ) {
			options.shippingAddressRequired = true;
			options.shippingRates = rates( quote );
		}
		event.resolve( options );
	} );

	button.on( 'shippingaddresschange', async ( event ) => {
		const shared = event.address || {};
		address = {
			country: shared.country || '',
			state: shared.state || '',
			postcode: shared.postal_code || '',
			city: shared.city || '',
		};
		rate = '';
		try {
			const next = await refresh();
			if ( ! next.payable || ! next.shipping_rates.length ) {
				event.reject();
				return;
			}
			event.resolve( {
				shippingRates: rates( next ),
				lineItems: lines( next ),
			} );
		} catch {
			event.reject();
		}
	} );

	button.on( 'shippingratechange', async ( event ) => {
		rate = event.shippingRate?.id || '';
		try {
			event.resolve( { lineItems: lines( await refresh() ) } );
		} catch {
			event.reject();
		}
	} );

	button.on( 'confirm', async ( event ) => {
		try {
			const submitted = await elements.submit();
			if ( submitted.error ) {
				throw submitted.error;
			}
			const details = event.billingDetails || {};
			const created = await stripe.createPaymentMethod( {
				elements,
				params: { billing_details: details },
			} );
			if ( created.error ) {
				throw created.error;
			}
			const shipping = event.shippingAddress
				? addressOf( event.shippingAddress )
				: null;
			const billing = addressOf( details, details.email, details.phone );
			// Google Pay may share only part of the billing address.
			if ( shipping && ! billing.address_1 ) {
				for ( const key of [
					'address_1',
					'address_2',
					'city',
					'state',
					'postcode',
					'country',
				] ) {
					billing[ key ] = shipping[ key ];
				}
			}
			if ( shipping && ! billing.last_name ) {
				billing.first_name = shipping.first_name || billing.first_name;
				billing.last_name = shipping.last_name;
			}
			const body = {
				...base(),
				billing,
				shipping: shipping ? { ...shipping, email: '', phone: details.phone || '' } : null,
				shipping_rate: event.shippingRate?.id || rate || quote.shipping_rate,
				checkout_token: product ? crypto.randomUUID() : token,
				expected_total: quote.total,
				gateway_input: {
					payment_method: created.paymentMethod.id,
					payment_method_type: created.paymentMethod.type,
					express: event.expressPaymentType || '',
				},
			};
			if ( checkout ) {
				// The form's own fields the wallet does not know: a company, the notes.
				body.additional = {};
				for ( const [ name, raw ] of new FormData( checkout ).entries() ) {
					const match = /^(billing|shipping|additional)\[([a-z0-9_]+)\]$/.exec(
						name
					);
					if ( typeof raw !== 'string' ) {
						continue;
					}
					if ( match && ! WALLET.includes( match[ 2 ] ) ) {
						const into = match[ 1 ] === 'shipping' ? body.shipping : body[ match[ 1 ] ];
						if ( into ) {
							into[ match[ 2 ] ] = raw;
						}
					} else if ( name === 'customer_note' ) {
						body.customer_note = raw;
					}
				}
			}
			const result = await request( 'express/order', body );
			if ( result.payment?.result === 'requires_action' ) {
				// 3-D Secure; the return URL then checks the outcome.
				const secret = result.payment.payload?.client_secret;
				if ( secret ) {
					await stripe.handleNextAction( { clientSecret: secret } );
				}
			}
			window.location.assign( result.redirect );
		} catch ( error ) {
			event.paymentFailed( { reason: 'fail' } );
			say( messageOf( error ) );
			refresh().catch( () => {} );
		}
	} );

	button.mount( box.querySelector( '.fxc-express__buttons' ) );

	// Totals follow the product choice, the cart and the coupons.
	let timer = 0;
	const later = () => {
		clearTimeout( timer );
		timer = setTimeout( () => refresh().catch( () => {} ), 250 );
	};
	product?.addEventListener( 'change', later );
	product?.addEventListener( 'input', later );
	box.addEventListener( 'pointerenter', later );
	box.addEventListener( 'focusin', later );
}

for ( const box of document.querySelectorAll( '[data-fxc-express]' ) ) {
	mount( box );
}

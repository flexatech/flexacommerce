/*
 * Stripe's Payment Element on the checkout (ADR-052). When the shopper
 * places the order it makes a payment method, the server confirms the
 * payment with it, and Stripe.js finishes 3-D Secure in a modal. When
 * Stripe.js cannot load, nothing is added: the shopper pays on a Stripe
 * Checkout page instead. The order-pay page always does that.
 */
const SCRIPT = 'https://js.stripe.com/v3/';

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

// The element follows the theme: its text, font and the look of its inputs.
function appearance( form ) {
	const input = getComputedStyle(
		form.querySelector( 'input[type="text"], input[type="email"]' ) || form
	);
	const page = getComputedStyle( form );
	const variables = {
		colorText: page.color,
		fontFamily: input.fontFamily,
		fontSizeBase: input.fontSize,
		borderRadius: input.borderTopLeftRadius,
	};
	if ( ! /rgba\(.*,\s*0\)|transparent/.test( input.backgroundColor ) ) {
		variables.colorBackground = input.backgroundColor;
	}
	return { theme: 'stripe', variables };
}

// What the form already asked, so the element does not ask it again.
function billing( form ) {
	const value = ( key ) =>
		form.querySelector( `[name="billing[${ key }]"]` )?.value?.trim() || undefined;
	const name = [ value( 'first_name' ), value( 'last_name' ) ]
		.filter( Boolean )
		.join( ' ' );
	const address = {
		line1: value( 'address_1' ),
		line2: value( 'address_2' ),
		city: value( 'city' ),
		state: value( 'state' ),
		postal_code: value( 'postcode' ),
		country: value( 'country' ),
	};
	return {
		name: name || undefined,
		email: value( 'email' ),
		phone: value( 'phone' ),
		address: Object.values( address ).some( Boolean ) ? address : undefined,
	};
}

async function mount( method ) {
	const form = method.closest( 'form' );
	let config = {};
	try {
		config = JSON.parse( method.dataset.fxcConfig || '{}' );
	} catch {}
	if ( ! form?.closest( '[data-fxc-checkout]' ) || ! config.key ) {
		return;
	}
	const box = document.createElement( 'div' );
	box.className = 'fxc-method__fields fxc-stripe';
	method.append( box );

	let stripe;
	try {
		stripe = ( await loadStripe() )( config.key, { locale: config.locale } );
	} catch {
		box.textContent = config.fallback || '';
		return;
	}
	const elements = stripe.elements( {
		mode: 'payment',
		amount: Math.max( 1, config.amount ),
		currency: config.currency,
		paymentMethodCreation: 'manual',
		appearance: appearance( form ),
	} );
	elements.create( 'payment' ).mount( box );

	const hidden = ( name, value ) => {
		const field = `gateway_input[stripe][${ name }]`;
		let input = form.querySelector( `[name="${ field }"]` );
		if ( ! input ) {
			input = Object.assign( document.createElement( 'input' ), {
				type: 'hidden',
				name: field,
			} );
			method.append( input );
		}
		input.value = value;
	};

	form.addEventListener( 'fxc:payment', ( event ) => {
		if ( event.detail.method !== 'stripe' ) {
			return;
		}
		event.detail.wait(
			( async () => {
				// The total may have changed with the address or a coupon.
				const total = Number( form.elements.expected_total?.value );
				if ( total > 0 ) {
					elements.update( {
						amount: Math.round( total * 10 ** config.shift ),
					} );
				}
				const submitted = await elements.submit();
				if ( submitted.error ) {
					throw submitted.error;
				}
				const created = await stripe.createPaymentMethod( {
					elements,
					params: { billing_details: billing( form ) },
				} );
				if ( created.error ) {
					throw created.error;
				}
				hidden( 'payment_method', created.paymentMethod.id );
				hidden( 'payment_method_type', created.paymentMethod.type );
			} )()
		);
	} );

	form.addEventListener( 'fxc:payment-action', ( event ) => {
		const secret = event.detail.payload?.client_secret;
		if ( event.detail.method === 'stripe' && secret ) {
			event.detail.wait(
				stripe.handleNextAction( { clientSecret: secret } )
			);
		}
	} );
}

for ( const method of document.querySelectorAll(
	'[data-fxc-method="stripe"][data-fxc-config]'
) ) {
	mount( method );
}

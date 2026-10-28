/*
 * The PayPal buttons on the checkout (ADR-054). With PayPal chosen they
 * stand in for the place button: a click checks the form, places the
 * order, and PayPal's window opens on the order the server made. Once the
 * shopper approves or closes it, the return URL captures the payment or
 * sends them to pay again. When PayPal's script cannot load, the place
 * button stays and the shopper pays on PayPal's page instead.
 */
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

async function mount( method ) {
	const form = method.closest( 'form' );
	let config = {};
	try {
		config = JSON.parse( method.dataset.fxcConfig || '{}' );
	} catch {}
	const place = form?.querySelector( '[data-fxc-place]' );
	if ( ! form?.closest( '[data-fxc-checkout]' ) || ! config.sdk || ! place ) {
		return;
	}

	const hidden = ( name, value ) => {
		const field = `gateway_input[paypal][${ name }]`;
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

	let paypal;
	try {
		paypal = await loadPayPal( config.sdk );
	} catch {
		const note = document.createElement( 'div' );
		note.className = 'fxc-method__fields';
		note.textContent = config.fallback || '';
		method.append( note );
		return;
	}

	const box = document.createElement( 'div' );
	box.className = 'fxc-paypal';
	box.hidden = true;
	place.after( box );
	const sync = () => {
		const chosen =
			form.querySelector( '[name="payment_method"]:checked' )?.value === 'paypal';
		box.hidden = ! chosen;
		place.hidden = chosen;
	};

	// The window waits for the order id, then checkout.js waits for the window.
	let pending = null;
	let closed = null;
	const done = () => {
		closed?.();
		closed = null;
	};

	const buttons = paypal.Buttons( {
		style: { color: config.color || 'gold', label: 'pay' },
		onClick( data, actions ) {
			const detail = { check: true };
			form.dispatchEvent( new CustomEvent( 'fxc:place', { detail } ) );
			if ( ! detail.ok ) {
				return actions.reject();
			}
			hidden( 'express', data.fundingSource || '' );
			return actions.resolve();
		},
		createOrder() {
			return new Promise( ( resolve, reject ) => {
				pending = { resolve, reject };
				hidden( 'flow', 'popup' );
				const detail = {};
				form.dispatchEvent( new CustomEvent( 'fxc:place', { detail } ) );
				Promise.resolve( detail.ok ? detail.placed : false ).then(
					( placed ) => {
						if ( ! placed && pending ) {
							pending.reject( new Error( 'not placed' ) );
							pending = null;
						}
					}
				);
			} ).finally( () => hidden( 'flow', '' ) );
		},
		onApprove: done,
		onCancel: done,
		onError: done,
	} );
	if ( ! buttons.isEligible() ) {
		return;
	}
	await buttons.render( box );
	form.addEventListener( 'change', ( event ) => {
		if ( event.target.matches( 'input[name="payment_method"]' ) ) {
			sync();
		}
	} );
	sync();

	form.addEventListener( 'fxc:payment-action', ( event ) => {
		const id = event.detail.payload?.order_id;
		if ( event.detail.method !== 'paypal' || ! id || ! pending ) {
			return;
		}
		event.detail.wait(
			new Promise( ( resolve ) => {
				closed = resolve;
			} )
		);
		pending.resolve( id );
		pending = null;
	} );
}

for ( const method of document.querySelectorAll(
	'[data-fxc-method="paypal"][data-fxc-config]'
) ) {
	mount( method );
}

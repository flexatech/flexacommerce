// Checkout with cash on delivery through each store's own REST API
// (doc 10 §4.2 P6, §6.4). bench.sh sets the env:
//   API  fxc or wc, PRODUCTS  comma-separated simple product IDs (the lines),
//   RATE  the flat-rate id (fxc only; WooCommerce picks its only rate),
//   HOST, RUN, N, VUS  as in page.js.
// Every iteration is a new guest: read the cart for a nonce, add each
// product once, and for FlexaCommerce read the checkout form for its token.
// Those requests carry RUN=<label>-prep, so summarize.php drops them; only
// the order request carries RUN=<label>.
import http from 'k6/http';
import { check } from 'k6';

const vus = Number(__ENV.VUS || 1);
const base = 'http://nginx';
const products = String(__ENV.PRODUCTS || '').split(',').map(Number).filter(Boolean);

const address = {
  first_name: 'Ada',
  last_name: 'Lovelace',
  address_1: '1 Infinite Loop',
  city: 'Cupertino',
  state: 'CA',
  postcode: '95014',
  country: 'US',
};
const email = 'bench@example.test';

const api = {
  fxc: {
    cart: '/wp-json/flexacommerce/v1/store/cart',
    add: '/wp-json/flexacommerce/v1/store/cart/items',
    checkout: '/wp-json/flexacommerce/v1/store/checkout',
    nonceHeader: 'X-Fxc-Nonce',
    item: (id) => ({ product_id: id, quantity: 1 }),
    order: (form) => ({
      checkout_token: form.checkout_token,
      billing: Object.assign({ email }, address),
      shipping_rate: __ENV.RATE,
      payment_method: 'cod',
    }),
    placed: 201,
  },
  wc: {
    cart: '/wp-json/wc/store/v1/cart',
    add: '/wp-json/wc/store/v1/cart/add-item',
    checkout: '/wp-json/wc/store/v1/checkout',
    nonceHeader: 'Nonce',
    item: (id) => ({ id, quantity: 1 }),
    order: () => ({
      billing_address: Object.assign({ email, phone: '' }, address),
      shipping_address: Object.assign({ phone: '' }, address),
      payment_method: 'cod',
    }),
    placed: 200,
  },
}[__ENV.API];

export const options = {
  scenarios: {
    measure: {
      executor: 'shared-iterations',
      vus,
      iterations: Number(__ENV.N || 500),
      maxDuration: '30m',
    },
  },
  maxRedirects: 0,
  summaryTrendStats: ['med', 'p(95)', 'p(99)'],
};

function headers(run, token, extra = {}) {
  return Object.assign(
    {
      Host: __ENV.HOST,
      'X-Bench-Run': run,
      'Content-Type': 'application/json',
      Origin: `http://${__ENV.HOST}`,
      [api.nonceHeader]: token,
    },
    extra,
  );
}

export default function () {
  const jar = new http.CookieJar();
  const prep = `${__ENV.RUN}-prep`;
  const token = http.get(base + api.cart, { headers: { Host: __ENV.HOST, 'X-Bench-Run': prep }, jar }).headers[api.nonceHeader] || '';
  for (const id of products) {
    http.post(base + api.add, JSON.stringify(api.item(id)), { jar, headers: headers(prep, token) });
  }
  let form = {};
  if (__ENV.API === 'fxc') {
    form = http.get(base + api.checkout, { headers: headers(prep, token), jar }).json();
  }
  const res = http.post(base + api.checkout, JSON.stringify(api.order(form)), { jar, headers: headers(__ENV.RUN, token) });
  check(res, { placed: (r) => r.status === api.placed });
}

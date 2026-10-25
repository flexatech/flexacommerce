// A shopper's visit (doc 10 §6.4 "Journey"): browse 5 pages, add 2 items,
// check out with cash on delivery. bench.sh sets the env:
//   API  fxc or wc, PAGES  comma-separated paths of the 5 pages,
//   PRODUCTS  the 2 simple product IDs to add, RATE  the flat-rate id (fxc only),
//   HOST, RUN, N, VUS  as in page.js.
// Every iteration is a new guest, and every request of it is measured: each
// carries RUN=<label> and X-Bench-Journey=<vu>-<iteration>, so summarize.php
// adds up the server time of one visit.
import http from 'k6/http';
import { check } from 'k6';

const vus = Number(__ENV.VUS || 1);
const base = 'http://nginx';
const pages = String(__ENV.PAGES || '').split(',').filter(Boolean);
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

// The same flows as cart.js and checkout.js.
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
      iterations: Number(__ENV.N || 100),
      maxDuration: '60m',
    },
  },
  maxRedirects: 0,
  summaryTrendStats: ['med', 'p(95)', 'p(99)'],
};

export default function () {
  const jar = new http.CookieJar();
  const visit = { Host: __ENV.HOST, 'X-Bench-Run': __ENV.RUN, 'X-Bench-Journey': `${__VU}-${__ITER}` };
  for (const path of pages) {
    check(http.get(base + path, { headers: visit, jar }), { 'page 200': (r) => r.status === 200 });
  }
  const token = http.get(base + api.cart, { headers: visit, jar }).headers[api.nonceHeader] || '';
  const json = Object.assign({}, visit, {
    'Content-Type': 'application/json',
    Origin: `http://${__ENV.HOST}`,
    [api.nonceHeader]: token,
  });
  for (const id of products) {
    check(http.post(base + api.add, JSON.stringify(api.item(id)), { jar, headers: json }), { 'add 201': (r) => r.status === 201 });
  }
  let form = {};
  if (__ENV.API === 'fxc') {
    form = http.get(base + api.checkout, { headers: json, jar }).json();
  }
  const res = http.post(base + api.checkout, JSON.stringify(api.order(form)), { jar, headers: json });
  check(res, { placed: (r) => r.status === api.placed });
}

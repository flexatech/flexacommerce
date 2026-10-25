// Cart scenarios through each store's own REST API (doc 10 §6.4, P4 and P5).
// bench.sh sets the env:
//   API  fxc or wc, MODE  add, view or page, PRODUCTS  comma-separated simple product IDs,
//   PATH_URI  the cart page (MODE page), HOST, RUN, N, VUS  as in page.js.
// Requests that only prepare a cart carry RUN=<label>-prep, so summarize.php
// drops them; only the request under test carries RUN=<label>.
//
//   add   every iteration is a new guest: GET the cart for a nonce, then add
//         the first product. The measured request starts the session.
//   view  setup() fills one guest cart with every product; each iteration
//         reads that cart.
//   page  the same filled cart; each iteration loads the cart page (HTML).
import http from 'k6/http';
import { check } from 'k6';

const vus = Number(__ENV.VUS || 1);
const base = 'http://nginx';
const products = String(__ENV.PRODUCTS || '').split(',').map(Number).filter(Boolean);

const api = {
  fxc: {
    cart: '/wp-json/flexacommerce/v1/store/cart',
    add: '/wp-json/flexacommerce/v1/store/cart/items',
    nonceHeader: 'X-Fxc-Nonce',
    body: (id) => ({ product_id: id, quantity: 1 }),
  },
  wc: {
    cart: '/wp-json/wc/store/v1/cart',
    add: '/wp-json/wc/store/v1/cart/add-item',
    nonceHeader: 'Nonce',
    body: (id) => ({ id, quantity: 1 }),
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

function headers(run, extra = {}) {
  return Object.assign({ Host: __ENV.HOST, 'X-Bench-Run': run }, extra);
}

// Header names come back in canonical form (X-Fxc-Nonce). Cookies are kept
// per URL host, which is "nginx" for every store, so each guest has its own jar.
function nonce(jar, run) {
  const res = http.get(base + api.cart, { headers: headers(run), jar });
  return res.headers[api.nonceHeader] || '';
}

function add(jar, id, run, token) {
  return http.post(base + api.add, JSON.stringify(api.body(id)), {
    jar,
    headers: headers(run, {
      'Content-Type': 'application/json',
      Origin: `http://${__ENV.HOST}`,
      [api.nonceHeader]: token,
    }),
  });
}

export function setup() {
  if (__ENV.MODE !== 'view' && __ENV.MODE !== 'page') {
    return {};
  }
  const jar = new http.CookieJar();
  const prep = `${__ENV.RUN}-prep`;
  const token = nonce(jar, prep);
  for (const id of products) {
    check(add(jar, id, prep, token), { 'prep add 201': (r) => r.status === 201 });
  }
  const cookies = jar.cookiesForURL(base + '/');
  return {
    cookie: Object.keys(cookies).map((name) => `${name}=${cookies[name][0]}`).join('; '),
  };
}

export default function (data) {
  if (__ENV.MODE === 'page') {
    const res = http.get(base + __ENV.PATH_URI, {
      headers: headers(__ENV.RUN, { Cookie: data.cookie }),
      jar: new http.CookieJar(),
    });
    check(res, { 'status 200': (r) => r.status === 200 });
    return;
  }
  if (__ENV.MODE === 'view') {
    const res = http.get(base + api.cart, {
      headers: headers(__ENV.RUN, { Cookie: data.cookie }),
      jar: new http.CookieJar(),
    });
    check(res, {
      'status 200': (r) => r.status === 200,
      'cart filled': (r) => (r.json('item_count') ?? r.json('items_count')) === products.length,
    });
    return;
  }
  const jar = new http.CookieJar();
  const token = nonce(jar, `${__ENV.RUN}-prep`);
  check(add(jar, products[0], __ENV.RUN, token), { 'status 201': (r) => r.status === 201 });
}

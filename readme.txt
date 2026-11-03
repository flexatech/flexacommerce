=== FlexaCommerce ===
Contributors: flexatech
Tags: ecommerce, store, shop, orders, checkout
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A commerce engine for WordPress: products with variants, cart, checkout and orders in dedicated tables.

== Description ==

FlexaCommerce keeps store data in its own database tables instead of posts and post meta. Products, variants, prices, stock, carts and orders each get a table with the indexes their queries need, so a large catalog or order history does not slow down the rest of the site.

This is a development release. Test it on a staging copy of your store before you use it on a live site.

What it includes:

* Simple, variable, grouped, external, downloadable, bundle, gift card and subscription products, with attributes, swatches, badges, product options and a gallery with video.
* Cart, a checkout you can rearrange (fields, sections, steps and conditions), guest checkout and a My Account area with orders, downloads, wishlist, subscriptions and store credit.
* Coupons, automatic discounts, pricing rules and checkout fees.
* Shipping zones, shipping classes and table rates; taxes by location.
* Built-in Stripe and PayPal payments, including express buttons and automatic subscription renewals.
* Orders you can create and edit in the admin, refunds, shipment tracking, PDF invoices and credit notes, order CSV export and product CSV import and export; a sample store to try it out.
* Product reviews, shop filters, back-in-stock and abandoned cart emails.
* A migration tool that copies a WooCommerce store into FlexaCommerce and back, on the same site or through a file.
* Status screen with environment checks, database health and a searchable plugin log. Secrets (keys, tokens, card numbers) and personal details such as names, addresses and IP addresses are masked before an entry is stored.
* A Store Manager role, dedicated capabilities and WP-CLI commands (`wp flexacommerce doctor`, `wp flexacommerce status`, `wp flexacommerce db upgrade` and more).
* No database queries and no assets on pages outside the store.

= Your data on uninstall =

By default, deleting the plugin leaves every table, option and setting in place, so reinstalling picks up where you left off. To remove everything, turn on "Delete data on uninstall" in Settings and type DELETE to confirm. Data is removed only when the plugin is deleted from the Plugins screen.

= Source code =

The admin screens are a React app. Its readable source ships with the plugin in the `client/` folder, together with its build configuration (`package.json`, `pnpm-lock.yaml`, `tsconfig.json`, `vite.config.ts`). The compiled files are in `assets/dist/`. To build them yourself, with Node.js 20 or newer and pnpm:

`cd client && pnpm install && pnpm build`

The build checks the types, then writes the bundles to `assets/dist/`. The storefront scripts and styles in `assets/frontend/` are not compiled; they ship as written.

PDF invoices use the Noto Sans fonts in `assets/fonts/`, under the SIL Open Font License 1.1 (`assets/fonts/OFL.txt`).

== External services ==

FlexaCommerce connects to the services below only when the store owner turns on the feature that needs them. Nothing is sent to FlexaCommerce's authors.

= Stripe =

Used by the built-in Stripe payment method and Stripe express buttons (Apple Pay, Google Pay, Link), once the store owner adds Stripe API keys.

* When a shopper pays at checkout or on the order-pay page, the store's server sends the order amount, currency, order number, the store's web address and the shopper's email and name to the Stripe API (`api.stripe.com`) to create and confirm the payment. The server also calls the API to issue refunds, charge subscription renewals, save a card for renewals, register the store's webhook address and the store's domain for Apple Pay, and read the Stripe account name when the keys are saved.
* On the checkout, cart and product pages where Stripe is offered, the shopper's browser loads Stripe.js from `js.stripe.com`. Card details are typed into Stripe's own fields and go straight to Stripe; they never reach the store's server. When Stripe.js cannot load, the shopper is sent to a Stripe-hosted payment page on `checkout.stripe.com` instead.

Stripe terms of service: https://stripe.com/legal/ssa
Stripe privacy policy: https://stripe.com/privacy

= PayPal =

Used by the built-in PayPal payment method and PayPal express buttons, once the store owner adds PayPal API credentials.

* When a shopper pays with PayPal, the store's server sends the order amount, currency, order number and the shopper's name, email and shipping address to the PayPal REST API (`api-m.paypal.com`, or `api-m.sandbox.paypal.com` in sandbox mode) to create and capture the payment. The server also calls the API to issue refunds, save a PayPal account for subscription renewals, charge those renewals and verify webhook events.
* On the checkout, cart and product pages where PayPal is offered, the shopper's browser loads the PayPal JavaScript SDK from `www.paypal.com`.

PayPal user agreement: https://www.paypal.com/legalhub/paypal/useragreement-full
PayPal privacy statement: https://www.paypal.com/legalhub/paypal/privacy-full

= VIES (EU VAT number check) =

Used when the store owner turns on the VAT number field under Taxes and keeps the check set to "With VIES".

* When a shopper types an EU VAT number at checkout, and again when the order is placed, the store's server sends the VAT number and its country code to the European Commission's VIES service (`ec.europa.eu`) to confirm the number is registered. No other order or customer data is sent. Answers are cached on the store for up to a week, so a number is not looked up on every change.

VIES legal notice and privacy statement: https://ec.europa.eu/taxation_customs/vies/#/disclaimer
European Commission privacy policy: https://commission.europa.eu/privacy-policy-websites-managed-european-commission_en

= YouTube and Vimeo =

When the store owner adds a YouTube or Vimeo link to a product gallery, the product page embeds that video in an iframe from `www.youtube-nocookie.com` or `player.vimeo.com`. The shopper's browser loads the player only after the shopper clicks the video, and the video service then receives the shopper's IP address and browser details.

YouTube terms of service: https://www.youtube.com/t/terms
Google privacy policy: https://policies.google.com/privacy
Vimeo terms of service: https://vimeo.com/terms
Vimeo privacy policy: https://vimeo.com/privacy

= Image and file downloads during imports =

The product CSV import and the migration file import download the images and media named in the file, from whatever addresses the file lists (usually the old store). This happens only when an administrator runs an import, and the request carries no store or customer data.

= Carrier tracking links =

Shipment tracking adds a link to the carrier's tracking page (for example USPS, UPS, FedEx, DHL, Royal Mail, Canada Post or Australia Post) to order emails and the My Account order page. The plugin makes no request to the carrier; the customer's browser opens the page only if the customer clicks the link.

== Installation ==

1. Upload the `flexacommerce` folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New.
2. Activate the plugin. On a multisite network you can network-activate it; each site gets its own tables.
3. Open FlexaCommerce > Status to confirm the checks pass.

The database must use the InnoDB storage engine. MySQL 8.0+ or MariaDB 10.6+ is recommended.

== Frequently Asked Questions ==

= Does it work alongside WooCommerce? =

Yes. The two plugins use separate tables and do not share data. FlexaCommerce > Migration copies products, customers, orders, coupons, reviews, subscriptions and settings from WooCommerce, and can copy a store back to WooCommerce too.

= Does it work with page caching? =

Yes. Shop, category, tag, search and product pages are the same for every visitor and can be cached. Browsing them sets no cookie and sends no `no-cache` header. The mini-cart reads the item count from the `fxc_cart_count` cookie in the browser, so a cached page still shows the right count.

The cart, checkout and account pages differ per visitor. FlexaCommerce sends `no-cache` headers on them and defines `DONOTCACHEPAGE`, which WP Super Cache, W3 Total Cache, LiteSpeed Cache and most other cache plugins respect. If your cache ignores both (some server and CDN caches do), exclude those three pages by URL, along with any URL that has `order-received` or `order-pay` in its query string.

Do not make the cache skip every page when the `fxc_session` cookie is present. That cookie exists once a visitor adds something to the cart, and skipping on it turns off caching of the whole shop for every visitor who has a cart.

= A store page is missing or shows nothing. What do I do? =

Open Settings > Storefront. The Pages card shows each store page and what is wrong with it: no page set, in the trash, not published, or missing its FlexaCommerce block. Click the button next to it (Create page, Restore, Publish or Add the block), or "Create missing pages" for all of them. You can also pick one of your own pages for the cart, checkout, account and terms. FlexaCommerce > Status lists the same problems, and `wp flexacommerce pages status` and `wp flexacommerce pages install` do it from the command line.

= How do I show filters on the shop page? =

Open Settings > Storefront and click "Add filters" on the Shop row, or pick left or right. The shop, category and tag pages then show filters by category, price, attributes and availability beside the products; on phones they fold behind a Filters button. `wp flexacommerce pages shop --filters=left` does the same. If you edited the shop template in the Site Editor, or your theme has its own, the card says so: reset the template or add the Product filters block to it.

= How do I change how emails look? =

Open Settings > Emails and click "Open the email designer", or "Customize design" on one email. The Style tab sets the logo, colors, font and footer for every email. The Blocks tab changes one email: add a heading, text, a button, an image, a divider or space, move the order details and addresses, and drag blocks into a new order. The preview on the right updates as you type; click a block in it to edit it. "Send test" mails the email to you before you save. "Reset to default" brings back the standard layout.

= Where are the logs? =

FlexaCommerce > Status > Logs. Entries older than the retention period (30 days by default) are removed by a daily cron job.

= How do I check the install from the command line? =

Run `wp flexacommerce doctor`. It prints the same checks as the Status screen and exits with an error code if any check fails.

= Is there sample data to try the store with? =

Yes. On the Products screen click Import, then use the "Demo store" card, or run `wp flexacommerce sample install --type=physical` (or `digital`, or `both`). Pick what the store sells: physical products to ship, digital products to download (checkout then asks for no shipping address), or both. You get up to 25 products of every type (simple, variable, grouped, external, bundle, downloadable, subscription, gift card) with pictures drawn on your server, a few reviews and the coupons SAMPLE10, SAMPLE5 and FREESHIP.

You can also tick what to set up around them (`--with=shipping,payments,tax,pages`):

* Shipping: a zone for your store country with flat rate (extra for bulky items), free shipping from 50 or with a coupon, and local pickup, after your own zones.
* Payments: bank transfer with a sample account; a digital store also turns off cash on delivery.
* Tax: tax turned on with one 10% rate.
* Pages and menu: a terms page, a wishlist page and a menu with the shop, its categories, cart, checkout and account.

A payment method or page you already set up is kept. "Remove demo store", or `wp flexacommerce sample remove`, takes it all out again and puts back the settings it changed, unless you changed them since. The products come from `sample-data/sample_products.csv`, a file in the WooCommerce CSV format, so you can also import it by hand.

== Changelog ==

= Unreleased =
* Catalog: simple, variable, grouped, external, downloadable, bundle, gift card and subscription products; attributes, swatches, badges, product options, gallery video; reviews; shop filters; wishlist; product CSV import and export; a demo store (physical, digital or both, with shipping, payments, tax, pages and a menu) to try it out.
* Cart and checkout: checkout builder with fields, sections, steps and conditions; coupons, automatic discounts, pricing rules and fees; guest order lookup.
* Payments: built-in Stripe and PayPal with express buttons; gift cards and store credit.
* Shipping and tax: zones, shipping classes, table rates, shipment tracking; taxes by location.
* Orders: create and edit in the admin, PDF invoices and credit notes, order CSV export, abandoned cart and back-in-stock emails.
* Subscriptions with automatic renewals, reminder emails, plan switching and customer self-service.
* Migration to and from WooCommerce, on the same site or through a file.
* Store pages: Settings > Storefront lists the cart, checkout, account, terms, wishlist and order tracking pages, says when one is missing, in the trash, unpublished or lost its block, and fixes it in one click; `wp flexacommerce pages` does the same.
* Shop filters: one click in Settings > Storefront puts category, price, attribute and availability filters beside the shop, category and tag lists, folded behind a button on phones; `wp flexacommerce pages shop` does the same.
* Email designer: a logo, colors, font and footer for every email, and blocks (heading, text, button, image, order details, addresses and more) to lay out each order email, with a live preview on a sample or real order and a test send.
* Admin: five menu sections (Orders, Products, Customers, Marketing, Settings) in a store header that also shows on the product, category, tag and review screens; every page and settings tab in the command palette (Ctrl or Cmd + K); a save bar on settings that stays in view while there are unsaved changes.

= 0.1.0 =
* First development release: database schema, Status and Settings screens, plugin log, roles and capabilities, WP-CLI tools.

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

This is an early development release. It installs the database schema, the admin Status and Settings screens, the plugin log and the WP-CLI tools. Catalog, cart and checkout arrive in later releases, so do not use it on a live store yet.

What this release includes:

* All store tables, created on activation and kept up to date by a versioned schema runner. Works on single sites and multisite networks.
* Status screen with environment checks, database health and a searchable plugin log. Secrets (keys, tokens, card numbers) and personal details such as names, addresses and IP addresses are masked before an entry is stored.
* Settings for currency format, order numbering, stock holds and logging.
* A Store Manager role and dedicated capabilities for viewing, editing and refunding orders.
* WP-CLI commands: `wp flexacommerce doctor`, `wp flexacommerce status`, `wp flexacommerce db version` and `wp flexacommerce db upgrade`.
* No database queries and no assets on pages outside the store.

= Your data on uninstall =

By default, deleting the plugin leaves every table, option and setting in place, so reinstalling picks up where you left off. To remove everything, turn on "Delete data on uninstall" in Settings and type DELETE to confirm. Data is removed only when the plugin is deleted from the Plugins screen.

= Source code =

The admin screens are a React app. Its readable source ships with the plugin in the `client/` folder; the compiled files are in `assets/dist/`.

== Installation ==

1. Upload the `flexacommerce` folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New.
2. Activate the plugin. On a multisite network you can network-activate it; each site gets its own tables.
3. Open FlexaCommerce > Status to confirm the checks pass.

The database must use the InnoDB storage engine. MySQL 8.0+ or MariaDB 10.6+ is recommended.

== Frequently Asked Questions ==

= Does it work alongside WooCommerce? =

Yes. The two plugins use separate tables and do not share data. A migration tool between them is planned.

= Does it work with page caching? =

Yes. Shop, category, tag, search and product pages are the same for every visitor and can be cached. Browsing them sets no cookie and sends no `no-cache` header. The mini-cart reads the item count from the `fxc_cart_count` cookie in the browser, so a cached page still shows the right count.

The cart, checkout and account pages differ per visitor. FlexaCommerce sends `no-cache` headers on them and defines `DONOTCACHEPAGE`, which WP Super Cache, W3 Total Cache, LiteSpeed Cache and most other cache plugins respect. If your cache ignores both (some server and CDN caches do), exclude those three pages by URL, along with any URL that has `order-received` or `order-pay` in its query string.

Do not make the cache skip every page when the `fxc_session` cookie is present. That cookie exists once a visitor adds something to the cart, and skipping on it turns off caching of the whole shop for every visitor who has a cart.

= Where are the logs? =

FlexaCommerce > Status > Logs. Entries older than the retention period (30 days by default) are removed by a daily cron job.

= How do I check the install from the command line? =

Run `wp flexacommerce doctor`. It prints the same checks as the Status screen and exits with an error code if any check fails.

== Changelog ==

= Unreleased =
* Products: simple and variable products with global and custom attributes, variation generation and bulk editing, prices with sale schedules, stock (per variation or shared with the product), weight and dimensions.
* New Attributes screen for store-wide attributes and their values.
* Product duplicate action.

= 0.1.0 =
* First development release: database schema, Status and Settings screens, plugin log, roles and capabilities, WP-CLI tools.

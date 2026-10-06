=== Patsch9 Accounting Bridge for WooCommerce ===
Contributors: patsch9
Tags: woocommerce, lexware, invoices, accounting, api
Requires at least: 6.9.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 2026.10.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects WooCommerce with the Lexware Office Public API for invoices, credit notes, customer matching, queues, and document access.

== Description ==

Patsch9 Accounting Bridge connects WooCommerce with the Lexware Office Public API. It can create and manage invoices and credit notes from WooCommerce orders, process synchronization jobs through a queue, and make invoice documents available in the customer account.

The plugin declares WooCommerce High-Performance Order Storage (HPOS) compatibility and uses WooCommerce order CRUD APIs for order data.

**Trademark notice:** WooCommerce® is a trademark of Automattic Inc. Lexware® and Lexware Office are trademarks or product names of Haufe-Lexware GmbH & Co. KG. This is an independent third-party extension and is not produced, sponsored, or endorsed by Automattic or Haufe-Lexware.

== Features ==

* Automatic or manual invoice creation from WooCommerce orders.
* Exact email based Lexware customer matching.
* Queue-based processing with deduplication and locking.
* Action Scheduler integration with WP-Cron fallback.
* Credit notes based on the original Lexware invoice snapshot.
* Invoice download and WooCommerce My Account integration.
* WooCommerce email integration.
* Optional handling of rental deposit metadata without a hard dependency on a rental plugin.
* HPOS compatibility.
* Conservative handling of ambiguous write responses to reduce duplicate accounting documents.

== Requirements ==

* WordPress 6.9.5 or newer.
* PHP 8.2 or newer.
* WooCommerce 10.9.4 or newer.
* A Lexware Office account with Public API access enabled.
* A valid Lexware API key.

== Installation ==

1. Install and activate WooCommerce.
2. Upload and activate this plugin.
3. Configure the Lexware API key.
4. Configure the order statuses and synchronization options that should trigger invoice processing.

== Configuration ==

For improved secret handling, the Lexware API key can be stored outside the WordPress database in `wp-config.php`:

`define( 'LEXWARE_CONNECTOR_API_KEY', 'YOUR_API_KEY' );`

The constant takes precedence over a key stored in WordPress options.

== External Services ==

= Lexware Office Public API =

The Lexware Office Public API at `https://api.lexware.io/v1/` is a core service required by this plugin. Depending on the action, data sent to Lexware may include customer names, billing and shipping addresses, email addresses, order items, prices, taxes, shipping or fee items, and order references. The API key is transmitted for authentication.

Developer portal: https://developers.lexware.io/
API documentation: https://developers.lexware.io/docs/
Public API terms: https://agb.lexware.de/lexware-office/public-api-lizenz--und-nutzungsbedingungen
Privacy policy: https://www.lexware.de/datenschutz/

The site operator is responsible for providing the required privacy information and for complying with the applicable Lexware terms.

== Privacy ==

API logging is disabled by default on new installations. When logging is enabled, known personal-data fields are masked and complete API response bodies are not stored permanently.

== Compatibility ==

* WooCommerce HPOS compatibility is declared.
* WooCommerce order data is accessed through the WooCommerce CRUD API.
* Action Scheduler is used when available; WP-Cron is used as a fallback.
* Historical internal `WLC_*` and `wlc_*` identifiers remain unchanged for backward compatibility.

== Frequently Asked Questions ==

= Do I need a Lexware account? =

Yes. An active Lexware Office account with Public API access is required.

= What data is transferred? =

The customer, order, line-item, price, tax, and reference data required to create or retrieve contacts and accounting documents is transferred to the Lexware Office Public API.

= Is plugin data removed when the plugin is deleted? =

Not by default. Full data cleanup on uninstall must be explicitly enabled in the plugin settings.

== Changelog ==

= 2026.10.0 =
* First stable public release using the project-wide `YYYY.M.PATCH` versioning scheme.
* Consolidates the functional and security changes from all previous internal prerelease versions through 1.3.6.
* Revalidated API write handling, queue locking, credit-note logic, HPOS order access, and protected invoice downloads.
* Accounting mappings and queue data are preserved on uninstall by default; destructive cleanup requires explicit opt-in.

== Upgrade Notice ==

= 2026.10.0 =
First stable release of the new public version line. Back up existing prerelease installations and verify the connection with a test document after upgrading.

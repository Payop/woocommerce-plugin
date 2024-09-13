=== Payop Official ===
Tags: credit cards, payment methods, payop, payment gateway
Version: 3.0.8
Stable tag: 3.0.8
Requires at least: 6.3
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 8.3
WC tested up to: 9.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add the ability to accept payments in WooCommerce via Payop.com.

== Description ==

Payop: Online payment processing service ➦ Accept payments online by 150+ methods from 170+ countries.
Payments gateway for Growing Your Business in New Locations and fast online payments.

What this module does for you:

* Free and quick setup
* Access 150+ local payment solutions with 1 easy integration.
* Highest security standards and anti-fraud technology

== Installation ==

Note: WooCommerce 3.0+ must be installed for this plugin to work.

1. Log in to your WordPress dashboard, navigate to the Plugins menu and click "Add New" button
2. Click "Upload Plugin" button and choose release archive
3. Click "Install Now".
4. After plugin installed, activate the plugin in your WordPress admin area.
5. Open the settings page for WooCommerce and click the "Payments" tab
6. Click on the sub-item for Payop.
7. Configure and save your settings accordingly.

You can issue  **Public key**, **Secret key** and **JWT Token** after register as merchant on Payop.com.

Use below parameters to configure your Payop project:
* **Callback/IPN URL**: https://{replace-with-your-domain}/?wc-api=wc_payop&payop=result

== Support ==

* [Payop Documentation](https://payop.com/en/documentation/common/)
* [Contact Payop support](https://payop.com/en/contact-us/)

**TIP**: When contacting support it will help us is you provide:

* WordPress and WooCommerce Version
* Other plugins you have installed
  * Some plugins do not play nice
* Configuration settings for the plugin (Most merchants take screen grabs)
* Any log files that will help
  * Web server error logs
* Screen grabs of error message if applicable.


== Changelog ==

= 1.0.0 =
* 2019-02-18
* Small fixes.

= 1.0.1 =
* 2017-01-21
* Update docs.
* Add more transaltions.

= 1.0.2 =
* 2017-01-23
* Update translation.
* Change logo.

= 1.0.3 =
* 2019-03-01
* Added POST JSON payload processing.

= 1.0.4 =
* Update docs.
* Code standardization.

= 1.0.5 =
* Update callback response.

= 1.0.6 =
* Add multi-currency

= 1.0.7 =
* Add setting button in plugin area
* Add skip checkout page option
* Add select default payment group
* Add select directpay payment method
* Add caching for payment methods

= 1.0.8 =
* Fix payment methods error

= 1.0.9 =
* Small fix

= 1.0.10 =
* Temporarily removed the option "Payment method group"
* Small fixes

= 1.0.11 =
* Bug fixes

= 2.0 =
* Add new API support
* Add JWT Token in settings area
* Add changes related to WooCommerce API update

= 2.0.1 =
* Directpay quickfix

= 2.0.2 =
* Remove warning 'Undefined index data' message
* Add empty JWT token check

= 2.0.3 =
* Checkout bugfix

= 2.0.4 =
* Disallow override of page "order-received" by other plugins

= 2.0.5 =
* Small fixes

= 3.0.0 =
* General: plugin improvements
* Optimized: General plugin improvements
* Added: WordPress 6.4.x Compatibility
* Added: WooCommerce 8.6.x Compatibility
* Added: Support for High-Performance Order Storage (HPOS)
* Added: Support for WooCommerce Checkout Blocks (Gutenberg)
* Added: Failed Order page
* Fixed: Error stating "No payment methods available"
* Fixed: Bug related to reordering

= 3.0.1 =
* General: minor changes

= 3.0.2 =
* General: minor changes
* Fixed: php errors

= 3.0.3 =
* General: minor changes

= 3.0.4 =
* General: Plugin improvements
* General: Removal of 'Direct payment' functionality
* Added: WordPress 6.5.x Compatibility
* Added: WooCommerce 8.8.x Compatibility

= 3.0.5 =
* Added: Compatibility with WooCommerce 8.9.x
* Fixed: Resolved 404 error on the checkout page

= 3.0.6 =
* General: changed the domain for the API
* Optimized: General plugin improvements

= 3.0.7 =
* Added: WordPress 6.6.x Compatibility
* Added: WooCommerce 9.1.x Compatibility

= 3.0.8 =
* Added: WooCommerce 9.3.x Compatibility
* Fixed: Fixed an issue where a space was sent if the first name and/or last name were missing in the payment data, causing validation errors. Now, if one or both fields are empty, an appropriate string is sent.

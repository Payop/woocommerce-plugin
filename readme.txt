=== PayOp Official ===
Tags: credit cards, payment methods, payop, payment gateway
Requires at least: 5.0
Tested up to: 5.2
Requires PHP: 5.4.45
Stable tag: 1.0.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add the ability to accept payments in WooCommerce via Payop.com.

== Description ==

PayOp: Online payment processing service ➦ Accept payments online by 150+ methods from 170+ countries.
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
6. Click on the sub-item for PayOp.
7. Configure and save your settings accordingly.

You can issue  **Public key** and **Secret key** after register as merchant on PayOp.com.

Use below parameters to configure your PayOp project:
* **Callback/IPN URL**: https://{replace-with-your-domain}/?wc-api=wc_payop&payop=result

== Support ==

* [PayOp Documentation](https://payop.com/en/documentation/common/)
* [Contact PayOp support](https://payop.com/en/contact-us/)

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
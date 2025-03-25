=== Payop Official ===
Tags: credit cards, payment methods, payop, payment gateway
Version: 3.0.10
Stable tag: 3.0.10
Requires at least: 6.3
Tested up to: 6.7.2
Requires PHP: 7.4
WC requires at least: 8.3
WC tested up to: 9.7.1
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

**Minimum Requirement:** WooCommerce 8.3 or higher must be installed and activated.

= Install via WordPress Plugin Repository =

1. Go to your WordPress admin dashboard.
2. Navigate to **Plugins → Add New**.
3. Search for **Payop WooCommerce**.
4. Click **Install Now**, then **Activate**.

= Configure Payop Payment Gateway =

1. Go to **WooCommerce → Settings → Payments**.
2. Find **Payop** in the list and click **Enable**, then click **Set up**.
3. Enter your credentials:
   - **Public Key** – Available in your Payop merchant dashboard.
   - **Secret Key** – Available in your Payop merchant dashboard.

= Set Callback/IPN URL =

1. In the Payop settings section, you'll find an automatically generated **Callback / IPN URL**:
   Example: https://your-domain.com/?wc-api=wc_payop&payop=result
2. Go to [https://payop.com](https://payop.com) and log in.
3. Navigate to **Projects → Details** of your selected project.
4. Open the **IPN** section and click **Add new IPN**.
5. Paste the copied URL into the appropriate field and save.

= Where to Find Public and Secret Keys =

1. Log in to your merchant account at [https://payop.com](https://payop.com).
2. Go to **Projects → Project List**.
3. Click **Details** for your project.
4. Copy your **Public Key** and **Secret Key**.
5. Paste them into the corresponding fields in WooCommerce Payop plugin settings.


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

= 3.0.9 =
* Optimized: Centralized status mapping logic for better maintainability.
* Improved: Enhanced readability and consistency in `process_result_request` method.
* Added: WooCommerce 9.7.x Compatibility
* Added: WordPress 6.7.x Compatibility
* Fixed: Addressed redundant status checks by introducing a dedicated mapping method.

= 3.0.10 =
* Added: Read-only IPN URL field in plugin settings
* Updated: Installation and configuration documentation
* Improved: Internal code refactoring for better maintainability and readability  

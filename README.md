WooCommerce Payop Payment Gateway
=====================

## Brief Description

Add the ability to accept payments in WooCommerce via Payop.com.

## Requirements

- PHP 7.4+
- Wordpress 6.3+
- WooCommerce 8.3+

## Installation Guide for Payop in WordPress WooCommerce

### 1. Download the Latest Release
1. Go to the [plugin's release page](https://github.com/Payop/woocommerce-plugin/releases).
2. Find the latest version at the top of the list.
3. Download the plugin archive, e.g.: `payop-woocommerce.zip`.


### 2. Install the Plugin via WordPress Admin Panel
1. Log in to your WordPress admin panel.
2. Go to **Plugins → Add New**.
3. Click **Upload Plugin** and choose the `payop-woocommerce.zip` file.
4. Click **Install Now**.
5. After the installation is complete, click **Activate Plugin**.

### 3. Enable and Configure the Payop Gateway in WooCommerce
1. Go to **WooCommerce → Settings → Payments**.
2. Find **Payop** in the list, click **Enable**, then click **Set up**.
3. Fill in the following:
   - `Public Key` – your project's public key.
   - `Secret Key` – your project's secret key.
   - `JWT Token` – used to load the payment methods available to your Payop project when configuring buttons.
   - `Integration type` – choose **Hosted Page** for all available Payop methods or **Hosted Page with Payment Method ID** for one specific method.
   - `Payment method` – select one of the methods loaded from Payop; required only for **Hosted Page with Payment Method ID**.

👉 You can get these keys in your Payop dashboard (see the step below).

### 4. Add More Payop Payment Buttons
1. Save the **Public Key** and **JWT Token** in the primary Payop settings to load the methods available to the project.
2. In **Additional Payop payment buttons**, click **Add payment button**.
3. Configure its customer-facing name, description, and integration type.
4. For **Hosted Page with Payment Method ID**, select the required method from the Payop list.
5. Add or remove more rows as needed, enable the configured buttons, and save.

All buttons are managed on this single settings page and inherit the public key, secret key, callback URL, and common behavior from the primary Payop gateway. The JWT Token is not sent during payment creation.

If a customer returns from Payop Checkout and selects a different Payop button, the plugin creates a new invoice for the new gateway/payment method. Previous invoice IDs remain linked to the order for secure late IPN verification, while a failed or overdue superseded invoice cannot fail the current payment attempt.

### 5. Set the Callback/IPN URL
1. In the Payop plugin settings, you’ll see an automatically generated **Callback/IPN URL**, like: https://your-domain.com/?wc-api=wc_payop&payop=result
2. Copy this URL.
3. Go to [Payop.com](https://payop.com):
- Navigate to **IPN → Add new IPN**.
- Paste the copied Callback URL and save.

### 6. Get Your Public and Secret Keys on Payop.com
1. Log in to your account at [Payop.com](https://payop.com).
2. Go to **Projects → Projects list**.
3. Select the desired project and click **Details**.
4. Copy the `Public Key` and `Secret Key` from the project settings.
5. Paste them into the corresponding fields in the WooCommerce plugin settings.

## Support

* [Open an issue](https://github.com/Payop/woocommerce-plugin/issues) if you are having issues with this plugin.
* [Payop Documentation](https://payop.com/en/documentation/common/)
* [Contact Payop support](https://payop.com/en/contact-us/)
  
**TIP**: When contacting support it will help us is you provide:

* Description of the problem
* Transaction ID/order ID
* WordPress and WooCommerce Version  
* Other plugins you have installed
  * Some plugins do not play nice
* Credentials to the plugin (if you are unable to provide them due to security reasons, please share a video or screenshots demonstrating the issue).
* Any log files that will help
  * Web server error logs
* Screen grabs of error message if applicable.


## Contribute

Would you like to help with this project?  Great!  You don't have to be a developer, either.
If you've found a bug or have an idea for an improvement, please open an
[issue](https://github.com/Payop/woocommerce-plugin/issues) and tell us about it.

If you *are* a developer wanting contribute an enhancement, bugfix or other patch to this project,
please fork this repository and submit a pull request detailing your changes.  We review all PRs!

This open source project is released under the [MIT license](http://opensource.org/licenses/MIT)
which means if you would like to use this project's code in your own project you are free to do so.


## License

Please refer to the 
[LICENSE](https://github.com/Payop/woocommerce-plugin/blob/master/LICENSE)
file that came with this project.

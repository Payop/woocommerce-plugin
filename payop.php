<?php
/*
Plugin Name: Payop WooCommerce Payment Gateway
Plugin URI: https://wordpress.org/plugins/payop-woocommerce/
Description: Payop: Online payment processing service ➦ Accept payments online by 150+ methods from 170+ countries. Payments gateway for Growing Your Business in New Locations and fast online payments
Author URI: https://payop.com/
Version: 3.0.8
Requires at least: 6.3
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 8.3
WC tested up to: 9.3.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
	exit;
}

define('PAYOP_PLUGIN_FILE', __FILE__);
define('PAYOP_PLUGIN_PATH', plugin_dir_path(PAYOP_PLUGIN_FILE));
define('PAYOP_PLUGIN_URL', plugin_dir_url(PAYOP_PLUGIN_FILE));
define('PAYOP_PLUGIN_BASENAME', plugin_basename(PAYOP_PLUGIN_FILE));
define('PAYOP_LANGUAGES_PATH', plugin_basename(dirname(__FILE__)) . '/languages/');
define('PAYOP_PAYMENT_GATEWAY_NAME', 'payop');
define('PAYOP_INVITATE_RESPONSE', 'payop_invitate_response');
define('PAYOP_PLUGIN_NAME', 'Payop WooCommerce Payment Gateway');
define('PAYOP_MIN_PHP_VERSION', '7.4');
define('PAYOP_MIN_WP_VERSION', '6.3');
define('PAYOP_MIN_WC_VERSION', '8.3');
define('PAYOP_IPN_VERSION_V1', 'V1');
define('PAYOP_IPN_VERSION_V2', 'V2');
define('PAYOP_HASH_ALGORITHM', 'sha256');
define('PAYOP_API_IDENTIFIER', 'identifier');

require_once PAYOP_PLUGIN_PATH . '/includes/class-wc-payment-plugin.php';

new Payop_WC_Payment_Plugin();

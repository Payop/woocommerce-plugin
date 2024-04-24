<?php
/**
 * WooCommerce Payop Payment Gateway Plugin.
 *
 * @extends WC_Payment_Gateway
 * @version 1.0.1
 */

if (!defined('ABSPATH')) {
	exit;
}

class Payop_WC_Payment_Plugin {

	public function __construct() {
		add_action('plugins_loaded', [$this, 'on_plugins_loaded'], 0);
		add_action('before_woocommerce_init', [$this, 'declare_compatibility']);
	}

	/**
	 * Actions to perform after all plugins are loaded.
	 */
	public function on_plugins_loaded() {
		$environment_check = $this->is_supported_environment();

		if (!$environment_check['result']) {
			$this->display_admin_notice($environment_check['message']);
			return;
		}

		$this->load_gateway();
		add_filter('plugin_action_links_' . PAYOP_PLUGIN_BASENAME, [$this, 'add_settings_link']);
		add_filter('woocommerce_payment_gateways', [$this, 'add_payop_gateway']);
		add_action('woocommerce_blocks_loaded', [$this, 'register_payment_method_type']);
	}

	/**
	 * Check if the environment supports the plugin.
	 *
	 * @return array Result of the environment check containing 'result' (bool) and 'message' (string).
	 */
	private function is_supported_environment() {
		$result = true;
		$message = '';

		// Check minimum PHP version.
		if (version_compare(PHP_VERSION, PAYOP_MIN_PHP_VERSION, '<')) {
			$result = false;
			$message = __('The "' . PAYOP_PLUGIN_NAME . '" plugin requires PHP ' . PAYOP_MIN_PHP_VERSION . ' or later. Please upgrade your PHP version.', 'payop-woocommerce');
		}

		// Check minimum Wordpress version.
		if (version_compare(get_bloginfo('version'), PAYOP_MIN_WP_VERSION, '<')) {
			$result = false;
			$message = __('The "' . PAYOP_PLUGIN_NAME . '" plugin requires Wordpress ' . PAYOP_MIN_WP_VERSION . ' or later. Please upgrade your Wordpress version.', 'payop-woocommerce');
		}

		// Check if WooCommerce is active.
		if (!$this->is_wc_active()) {
			$result = false;
			$message = __('For the correct operation of the "' . PAYOP_PLUGIN_NAME . '" plugin, WooCommerce is required. Please install and activate WooCommerce.', 'payop-woocommerce');
		}

		// Check minimum WooCommerce version.
		if ($this->is_wc_active() && version_compare(WC()->version, PAYOP_MIN_WC_VERSION, '<')) {
			$result = false;
			$message = __('The "' . PAYOP_PLUGIN_NAME . '" plugin requires WooCommerce ' . PAYOP_MIN_WC_VERSION . ' or later. Please upgrade your WooCommerce version.', 'payop-woocommerce');
		}

		return ['result' => $result, 'message' => $message];
	}

	/**
	 * Check if WooCommerce is active.
	 *
	 * @return bool Whether WooCommerce is active.
	 */
	private function is_wc_active() {
		return in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) && class_exists('WC_Payment_Gateway');
	}

	/**
	 * Load Payop payment gateway class.
	 * 
	 * @uses \WC_Payment_Gateway
	 */
	private function load_gateway() {
		require_once PAYOP_PLUGIN_PATH . '/includes/class-wc-gateway-payop.php';
	}

	/**
	 * Declare compatibility with cart_checkout_blocks feature.
	 * 
	 * @uses \Automattic\WooCommerce\Utilities\FeaturesUtil
	 */
	public function declare_compatibility() {
		if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', PAYOP_PLUGIN_FILE, true);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', PAYOP_PLUGIN_FILE, true);
		}
	}

	/**
	 * Register Payop payment method type for blocks.
	 * 
	 * @uses \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry
	 */
	public function register_payment_method_type() {
		if (class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
			require_once PAYOP_PLUGIN_PATH . '/includes/class-wc-gateway-payop-blocks.php';

			// Register the Payop Blocks class with WooCommerce payment method registry.
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function (\Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
					$payment_method_registry->register(new WC_Gateway_Payop_Blocks());
				}
			);
		}
	}

	/**
	 * Add settings link to the plugin actions.
	 *
	 * @param array $links Existing plugin action links.
	 * @return array Modified plugin action links.
	 */
	public function add_settings_link($links) {
		$url = admin_url('admin.php?page=wc-settings&tab=checkout&section='.PAYOP_PAYMENT_GATEWAY_NAME);
		$links[] = '<a href="' . esc_url($url) . '">' . __('Settings', 'payop-woocommerce') . '</a>';
		return $links;
	}

	/**
	 * Add the Payop gateway to WooCommerce.
	 *
	 * @param array $gateways Existing payment gateways.
	 * @return array Modified payment gateways.
	 */
	public function add_payop_gateway($gateways) {
		$gateways[] = 'WC_Gateway_Payop';
		return $gateways;
	}

	/**
	 * Display an admin notice.
	 *
	 * @param string $message The message to display in the admin notice.
	 */
	private function display_admin_notice($message) {
		add_action('admin_notices', function() use ($message) {
			echo '<div class="notice notice-error"><p>';
			echo esc_html($message);
			echo '</p></div>';
		});
	}
}

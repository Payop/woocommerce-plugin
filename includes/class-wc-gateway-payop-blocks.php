<?php
/**
 * WooCommerce Payop Payment Gateway Block.
 *
 * @final
 * @extends AbstractPaymentMethodType
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Gateway_Payop_Blocks extends AbstractPaymentMethodType {

	/**
	 * @var WC_Gateway_Payop The Payop payment gateway instance.
	 */
	private $gateway;

	/**
	 * @var string The name of the payment gateway.
	 */
	protected $name = PAYOP_PAYMENT_GATEWAY_NAME;

	/**
	 * Initialize the Payop payment gateway block.
	 */
	public function initialize() {
		$this->settings = get_option('woocommerce_payop_settings', []);
		$this->gateway = new WC_Gateway_Payop();
	}

	/**
	 * Check if the Payop payment gateway is active.
	 *
	 * @return bool Whether the payment gateway is active.
	 */
	public function is_active() {
		return $this->gateway->is_available();
	}

	/**
	 * Get the script handles required for the payment method.
	 *
	 * @return array Script handles.
	 */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'payop-blocks-integration',
			PAYOP_PLUGIN_URL . '/js/payop-blocks-integration.js',
			[
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			],
			null,
			true
		);

		// Set script translations if available.
		if (function_exists('wp_set_script_translations')) {
			wp_set_script_translations('payop-blocks-integration');
		}

		wp_localize_script('payop-blocks-integration', 'payopBlockData', [
			'name' => PAYOP_PAYMENT_GATEWAY_NAME,
		]);

		return ['payop-blocks-integration'];
	}

	/**
	 * Get data for the payment method.
	 *
	 * @return array Payment method data.
	 */
	public function get_payment_method_data() {
		return [
			'title'	      => $this->gateway->title,
			'description' => $this->gateway->description,
		];
	}
}

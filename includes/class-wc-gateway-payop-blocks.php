<?php
/**
 * WooCommerce Payop Payment Gateway Block.
 *
 * @final
 * @extends AbstractPaymentMethodType
 * @version 1.1.0
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
	 * @param string $gateway_id Dynamic Payop gateway ID.
	 */
	public function __construct($gateway_id = PAYOP_PAYMENT_GATEWAY_NAME) {
		$this->name = sanitize_key($gateway_id);
	}

	/**
	 * Initialize the Payop payment gateway block.
	 */
	public function initialize() {
		$this->settings = (array) get_option('woocommerce_' . $this->name . '_settings', []);
		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->gateway = isset($gateways[$this->name]) ? $gateways[$this->name] : null;
	}

	/**
	 * Check if the Payop payment gateway is active.
	 *
	 * @return bool Whether the payment gateway is active.
	 */
	public function is_active() {
		return $this->gateway instanceof WC_Gateway_Payop && $this->gateway->is_available();
	}

	/**
	 * Get the script handles required for the payment method.
	 *
	 * @return array Script handles.
	 */
	public function get_payment_method_script_handles() {
		$handle = 'payop-blocks-integration-' . $this->name;

		wp_register_script(
			$handle,
			PAYOP_PLUGIN_URL . '/js/payop-blocks-integration.js',
			[
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
			],
			'3.2.0',
			true
		);

		// Set script translations if available.
		if (function_exists('wp_set_script_translations')) {
			wp_set_script_translations($handle, 'payop-woocommerce', PAYOP_PLUGIN_PATH . 'languages');
		}

		wp_localize_script($handle, 'payopBlockData', [
			'name' => $this->name,
		]);

		return [$handle];
	}

	/**
	 * Get data for the payment method.
	 *
	 * @return array Payment method data.
	 */
	public function get_payment_method_data() {
		return [
			'title'	      => $this->gateway instanceof WC_Gateway_Payop ? $this->gateway->title : __('Payop', 'payop-woocommerce'),
			'description' => $this->gateway instanceof WC_Gateway_Payop ? $this->gateway->description : '',
		];
	}
}

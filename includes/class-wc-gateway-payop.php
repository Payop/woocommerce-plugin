<?php
/**
 * WooCommerce Payop Payment Gateway.
 *
 * @extends WC_Payment_Gateway
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_Gateway_Payop extends WC_Payment_Gateway {

	/**
	 * Human-readable gateway number. The primary gateway is number 1.
	 *
	 * @var int
	 */
	private $gateway_number = 1;

	/**
	 * Hosted Page integration type.
	 *
	 * @var string
	 */
	public $integration_type;

	/**
	 * Optional Payop payment method identifier.
	 *
	 * @var string
	 */
	public $payment_method;

	/**
	 * JWT token used only to retrieve payment methods available to the project.
	 *
	 * @var string
	 */
	public $jwt_token;

	/**
	 * Public key for authentication with Payop API.
	 *
	 * @var string
	 */
	public $public_key;

	/**
	 * URL for making requests to Payop API.
	 *
	 * @var string
	 */
	public $api_url;

	/**
	 * Secret key for signing requests to Payop API.
	 *
	 * @var string
	 */
	public $secret_key;

	/**
	 * Flag indicating whether to skip confirmation step before payment.
	 *
	 * @var string
	 */
	public $skip_confirm;

	/**
	 * Lifetime of the payment link.
	 *
	 * @var string
	 */
	public $lifetime;

	/**
	 * Flag indicating whether orders should be auto-completed after successful payment.
	 *
	 * @var string
	 */
	public $auto_complete;

	/**
	 * Language code for the payment form.
	 *
	 * @var string
	 */
	public $language;

	/**
	 * Instructions for the payment.
	 *
	 * @var string
	 */
	public $instructions;

	/**
	 * Flag indicating whether detailed WooCommerce logs should be written.
	 *
	 * @var string
	 */
	public $detailed_logging;

	/**
	 * Available payment methods loaded for the admin settings page.
	 *
	 * @var array|null
	 */
	private $available_payment_methods;

	/**
	 * Error returned while loading available payment methods.
	 *
	 * @var string
	 */
	private $available_payment_methods_error = '';

	/**
	 * @param int $gateway_number Human-readable gateway number, starting at 1.
	 */
	public function __construct($gateway_number = 1)
	{
		$this->gateway_number = max(1, absint($gateway_number));
		$this->api_url = 'https://api.payop.com/v1/invoices/create';

		$this->id = self::get_gateway_id($this->gateway_number);
		$this->icon = apply_filters('woocommerce_payop_icon', '' . PAYOP_PLUGIN_URL . '/payop.png');

		$primary_settings = (array) get_option('woocommerce_' . PAYOP_PAYMENT_GATEWAY_NAME . '_settings', []);
		if ($this->is_primary_gateway()) {
			$this->init_form_fields();
			$this->init_settings();
			$primary_settings = (array) $this->settings;
			$gateway_settings = $primary_settings;
		} else {
			$this->form_fields = [];
			$gateway_settings = self::get_button_configuration($this->gateway_number, $primary_settings);
			$this->settings = $gateway_settings;
		}

		$this->method_title = $this->is_primary_gateway()
			? __('Payop', 'payop-woocommerce')
			: (isset($gateway_settings['title']) ? (string) $gateway_settings['title'] : sprintf(__('Payop payment option #%d', 'payop-woocommerce'), $this->gateway_number));
		$this->method_description = $this->is_primary_gateway()
			? __('Take payments via Payop.', 'payop-woocommerce')
			: __('This Payop checkout button is managed from the primary Payop gateway settings.', 'payop-woocommerce');

		// Define user set variables
		$this->enabled = isset($gateway_settings['enabled']) ? (string) $gateway_settings['enabled'] : ($this->is_primary_gateway() ? 'yes' : 'no');
		$this->title = isset($gateway_settings['title']) ? (string) $gateway_settings['title'] : $this->method_title;
		$this->public_key = isset($primary_settings['public_key']) ? (string) $primary_settings['public_key'] : '';
		$this->secret_key = isset($primary_settings['secret_key']) ? (string) $primary_settings['secret_key'] : '';
		$this->jwt_token = isset($primary_settings['jwt_token']) ? (string) $primary_settings['jwt_token'] : '';
		$this->skip_confirm = isset($primary_settings['skip_confirm']) ? (string) $primary_settings['skip_confirm'] : 'yes';
		$this->lifetime = isset($primary_settings['lifetime']) ? (string) $primary_settings['lifetime'] : '';
		$this->auto_complete = isset($primary_settings['auto_complete']) ? (string) $primary_settings['auto_complete'] : '1';
		$this->language = 'en';
		$this->description = isset($gateway_settings['description']) ? (string) $gateway_settings['description'] : '';
		$this->instructions = isset($gateway_settings['instructions']) ? (string) $gateway_settings['instructions'] : '';
		$this->detailed_logging = isset($primary_settings['detailed_logging']) ? (string) $primary_settings['detailed_logging'] : 'no';
		$this->integration_type = isset($gateway_settings['integration_type']) && $gateway_settings['integration_type'] === 'payment_method'
			? 'payment_method'
			: 'hosted_page';
		$this->payment_method = self::sanitize_payment_method_id(isset($gateway_settings['payment_method']) ? $gateway_settings['payment_method'] : '');

		// Keep the hosted payment form only on the order-pay page to avoid reopening it after browser redirects.
		add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
		add_action('woocommerce_api_wc_' . $this->id, [$this, 'check_ipn_response']);

		if ($this->is_primary_gateway()) {
			// The primary callback URL handles IPN notifications for every Payop gateway instance.
			add_action('payop-ipn-request', [$this, 'successful_request']);
			add_filter('woocommerce_order_needs_payment', [$this, 'prevent_payment_for_failed_orders'], 10, 3);

			// Hide buttons "Buy again".
			add_action('woocommerce_my_account_my_orders_actions', [$this, 'hide_pay_button_for_failed_orders'], 10, 2);
			add_filter('render_block', [$this, 'modify_wc_order_confirmation_block_content'], 10, 2);
			add_action('payop_check_abandoned_payment', [$this, 'check_abandoned_payment']);
		}

		if ($this->is_primary_gateway()) {
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
		}

		if (!$this->is_valid_for_use()) {
			$this->enabled = false;
		}
	}

	/**
	 * Get the gateway ID for a human-readable gateway number.
	 *
	 * @param int $gateway_number
	 * @return string
	 */
	public static function get_gateway_id($gateway_number)
	{
		$gateway_number = max(1, absint($gateway_number));
		return $gateway_number === 1
			? PAYOP_PAYMENT_GATEWAY_NAME
			: PAYOP_PAYMENT_GATEWAY_NAME . '_' . $gateway_number;
	}

	/**
	 * Get configured gateway numbers.
	 *
	 * @return int[]
	 */
	public static function get_configured_gateway_numbers()
	{
		$settings = (array) get_option('woocommerce_' . PAYOP_PAYMENT_GATEWAY_NAME . '_settings', []);
		$gateway_numbers = [1];

		foreach (self::get_payment_buttons($settings) as $button) {
			$gateway_number = isset($button['gateway_number']) ? absint($button['gateway_number']) : 0;
			if ($gateway_number >= 2 && !in_array($gateway_number, $gateway_numbers, true)) {
				$gateway_numbers[] = $gateway_number;
			}
		}

		return $gateway_numbers;
	}

	/**
	 * Get additional button configurations from the primary settings.
	 *
	 * @param array|null $settings
	 * @return array
	 */
	private static function get_payment_buttons($settings = null)
	{
		if ($settings === null) {
			$settings = (array) get_option('woocommerce_' . PAYOP_PAYMENT_GATEWAY_NAME . '_settings', []);
		}

		if (isset($settings['payment_buttons']) && is_array($settings['payment_buttons'])) {
			return array_values($settings['payment_buttons']);
		}

		// Migrate settings created by the early 3.2.0 development version.
		$legacy_buttons = [];
		$legacy_count = isset($settings['additional_gateways'])
			? min(absint($settings['additional_gateways']), PAYOP_MAX_GATEWAY_INSTANCES - 1)
			: 0;
		for ($gateway_number = 2; $gateway_number <= $legacy_count + 1; ++$gateway_number) {
			$legacy_settings = (array) get_option('woocommerce_' . self::get_gateway_id($gateway_number) . '_settings', []);
			$legacy_buttons[] = [
				'gateway_number' => $gateway_number,
				'enabled' => isset($legacy_settings['enabled']) ? (string) $legacy_settings['enabled'] : 'no',
				'title' => isset($legacy_settings['title']) ? (string) $legacy_settings['title'] : sprintf(__('Payop payment option #%d', 'payop-woocommerce'), $gateway_number),
				'description' => isset($legacy_settings['description']) ? (string) $legacy_settings['description'] : '',
				'integration_type' => isset($legacy_settings['integration_type']) ? (string) $legacy_settings['integration_type'] : 'payment_method',
				'payment_method' => isset($legacy_settings['payment_method']) ? (string) $legacy_settings['payment_method'] : '',
			];
		}

		return $legacy_buttons;
	}

	/**
	 * Find one additional button configuration.
	 *
	 * @param int        $gateway_number
	 * @param array|null $settings
	 * @return array
	 */
	private static function get_button_configuration($gateway_number, $settings = null)
	{
		foreach (self::get_payment_buttons($settings) as $button) {
			if (isset($button['gateway_number']) && absint($button['gateway_number']) === absint($gateway_number)) {
				return is_array($button) ? $button : [];
			}
		}

		return [];
	}

	/**
	 * Get configured gateway IDs.
	 *
	 * @return string[]
	 */
	public static function get_configured_gateway_ids()
	{
		return array_map([__CLASS__, 'get_gateway_id'], self::get_configured_gateway_numbers());
	}

	/**
	 * Check whether an ID belongs to a Payop gateway instance.
	 *
	 * @param string $gateway_id
	 * @return bool
	 */
	public static function is_payop_gateway_id($gateway_id)
	{
		return (bool) preg_match(
			'/^' . preg_quote(PAYOP_PAYMENT_GATEWAY_NAME, '/') . '(?:_(?:[2-9]|[1-9][0-9]+))?$/',
			(string) $gateway_id
		);
	}

	/**
	 * Normalize a Payop payment method ID.
	 *
	 * @param mixed $payment_method
	 * @return string
	 */
	private static function sanitize_payment_method_id($payment_method)
	{
		if (!is_scalar($payment_method)) {
			return '';
		}

		$payment_method = trim((string) $payment_method);
		return preg_match('/^[1-9][0-9]*$/', $payment_method) ? $payment_method : '';
	}

	/**
	 * Get the human-readable gateway number.
	 *
	 * @return int
	 */
	public function get_gateway_number()
	{
		return $this->gateway_number;
	}

	/**
	 * Check whether this is the primary Payop gateway.
	 *
	 * @return bool
	 */
	public function is_primary_gateway()
	{
		return $this->gateway_number === 1;
	}

	/**
	 * Check whether an order was created with any Payop gateway instance.
	 *
	 * @param WC_Order|mixed $order
	 * @return bool
	 */
	private function order_uses_payop($order)
	{
		return $order instanceof WC_Order && self::is_payop_gateway_id($order->get_payment_method());
	}

	/**
	 * Return the Payment Method ID fixed on the order at checkout.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	private function get_order_payment_method_id($order)
	{
		$stored_payment_method = self::sanitize_payment_method_id($order->get_meta(PAYOP_PAYMENT_METHOD_ID_META));
		if ($stored_payment_method !== '') {
			return $stored_payment_method;
		}

		return $this->integration_type === 'payment_method' ? $this->payment_method : '';
	}

	/**
	 * Normalize an invoice identifier received from order metadata or Payop.
	 *
	 * @param mixed $invoice_id
	 * @return string
	 */
	private function sanitize_invoice_id($invoice_id)
	{
		if (!is_scalar($invoice_id)) {
			return '';
		}

		return sanitize_text_field(trim((string) $invoice_id));
	}

	/**
	 * Return the invoice history stored for an order.
	 *
	 * @param WC_Order $order
	 * @return array
	 */
	private function get_payop_invoice_history($order)
	{
		$history = $order instanceof WC_Order ? $order->get_meta(PAYOP_INVOICE_HISTORY_META) : [];
		if (!is_array($history)) {
			return [];
		}

		$normalized_history = [];
		foreach ($history as $entry) {
			if (!is_array($entry)) {
				continue;
			}

			$invoice_id = $this->sanitize_invoice_id(isset($entry['invoice_id']) ? $entry['invoice_id'] : '');
			if ($invoice_id === '') {
				continue;
			}

			$normalized_history[$invoice_id] = [
				'invoice_id' => $invoice_id,
				'gateway_id' => isset($entry['gateway_id']) ? sanitize_key((string) $entry['gateway_id']) : '',
				'payment_method_id' => self::sanitize_payment_method_id(isset($entry['payment_method_id']) ? $entry['payment_method_id'] : ''),
				'created_at' => isset($entry['created_at']) ? absint($entry['created_at']) : 0,
			];
		}

		return array_values($normalized_history);
	}

	/**
	 * Add an invoice and its immutable checkout context to the order history.
	 *
	 * @param WC_Order $order
	 * @param string   $invoice_id
	 * @param string   $gateway_id
	 * @param string   $payment_method_id
	 * @return void
	 */
	private function remember_payop_invoice($order, $invoice_id, $gateway_id, $payment_method_id)
	{
		if (!$order instanceof WC_Order) {
			return;
		}

		$invoice_id = $this->sanitize_invoice_id($invoice_id);
		if ($invoice_id === '') {
			return;
		}

		$history = $this->get_payop_invoice_history($order);
		$entry = [
			'invoice_id' => $invoice_id,
			'gateway_id' => sanitize_key((string) $gateway_id),
			'payment_method_id' => self::sanitize_payment_method_id($payment_method_id),
			'created_at' => time(),
		];
		$replaced = false;

		foreach ($history as $index => $history_entry) {
			if ($history_entry['invoice_id'] === $invoice_id) {
				$entry['created_at'] = !empty($history_entry['created_at']) ? $history_entry['created_at'] : $entry['created_at'];
				$history[$index] = $entry;
				$replaced = true;
				break;
			}
		}

		if (!$replaced) {
			$history[] = $entry;
		}

		$order->update_meta_data(PAYOP_INVOICE_HISTORY_META, $history);
		$order->update_meta_data(PAYOP_INVOICE_CONTEXT_META, $entry);
	}

	/**
	 * Find immutable context for a known invoice.
	 *
	 * @param WC_Order $order
	 * @param string   $invoice_id
	 * @return array
	 */
	private function get_payop_invoice_context($order, $invoice_id)
	{
		$invoice_id = $this->sanitize_invoice_id($invoice_id);
		if ($invoice_id === '') {
			return [];
		}

		$current_context = $order instanceof WC_Order ? $order->get_meta(PAYOP_INVOICE_CONTEXT_META) : [];
		if (is_array($current_context) && $this->sanitize_invoice_id(isset($current_context['invoice_id']) ? $current_context['invoice_id'] : '') === $invoice_id) {
			return $current_context;
		}

		foreach ($this->get_payop_invoice_history($order) as $entry) {
			if ($entry['invoice_id'] === $invoice_id) {
				return $entry;
			}
		}

		return [];
	}

	/**
	 * Check that an invoice was created by this plugin for the order.
	 *
	 * @param WC_Order $order
	 * @param string   $invoice_id
	 * @return bool
	 */
	private function is_known_payop_invoice($order, $invoice_id)
	{
		$invoice_id = $this->sanitize_invoice_id($invoice_id);
		if (!$order instanceof WC_Order || $invoice_id === '') {
			return false;
		}

		$current_invoice_id = $this->sanitize_invoice_id($order->get_meta(PAYOP_INVOICE_ID_META));
		if ($current_invoice_id !== '' && hash_equals($current_invoice_id, $invoice_id)) {
			return true;
		}

		return !empty($this->get_payop_invoice_context($order, $invoice_id));
	}

	/**
	 * Invalidate only the current redirect invoice when checkout selection changes.
	 *
	 * The old identifier remains in the immutable history for secure IPN checks.
	 *
	 * @param WC_Order $order
	 * @param string   $gateway_id
	 * @param string   $payment_method_id
	 * @return bool Whether an existing invoice was invalidated.
	 */
	private function reset_invoice_for_changed_selection($order, $gateway_id, $payment_method_id)
	{
		if (!$order instanceof WC_Order) {
			return false;
		}

		$current_invoice_id = $this->sanitize_invoice_id($order->get_meta(PAYOP_INVOICE_ID_META));
		if ($current_invoice_id === '') {
			$current_invoice_id = $this->sanitize_invoice_id($order->get_meta(PAYOP_INVITATE_RESPONSE));
		}
		if ($current_invoice_id === '') {
			return false;
		}

		$current_context = $this->get_payop_invoice_context($order, $current_invoice_id);
		$gateway_id = sanitize_key((string) $gateway_id);
		$payment_method_id = self::sanitize_payment_method_id($payment_method_id);
		$context_matches = !empty($current_context)
			&& isset($current_context['gateway_id'], $current_context['payment_method_id'])
			&& sanitize_key((string) $current_context['gateway_id']) === $gateway_id
			&& self::sanitize_payment_method_id($current_context['payment_method_id']) === $payment_method_id;

		if ($context_matches) {
			return false;
		}

		// Preserve legacy invoices in history even if they predate invoice context metadata.
		if (empty($current_context)) {
			$this->remember_payop_invoice(
				$order,
				$current_invoice_id,
				'',
				$order->get_meta(PAYOP_PAYMENT_METHOD_ID_META)
			);
		}

		$order->delete_meta_data(PAYOP_INVITATE_RESPONSE);
		$order->delete_meta_data(PAYOP_INVOICE_ID_META);
		$order->delete_meta_data(PAYOP_INVOICE_CONTEXT_META);
		$order->delete_meta_data(PAYOP_TXID_META);

		$this->add_payop_order_note(
			$order,
			__('Payop payment option changed; creating a new invoice', 'payop-woocommerce'),
			[
				'previous_invoice_id' => $current_invoice_id,
				'gateway_id' => $gateway_id,
				'payment_method_id' => $payment_method_id,
			]
		);

		return true;
	}

	/**
	 * Display receipt page after successful payment.
	 *
	 * @param int $order_id Order ID.
	 */
	public function receipt_page( $order_id )
	{
		$order = wc_get_order($order_id);

		if(!$order->get_meta(PAYOP_INVITATE_RESPONSE) || $order->has_status('pending')){
			echo '<p>' . __('Thank you for your order, please click the button below to pay', 'payop-woocommerce') . '</p>';
			echo $this->generate_form($order_id);
		}else{
			$this->empty_cart();
		}
	}

	/**
	 * Generate payment form.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return string
	 */
	public function generate_form( $order_id )
	{
		$order = wc_get_order($order_id);

		$response = $order->get_meta(PAYOP_INVITATE_RESPONSE);
		if ($response && $this->is_payop_invoice_overdue($response)) {
			if (!$this->is_known_payop_invoice($order, $response)) {
				$this->remember_payop_invoice(
					$order,
					$response,
					$order->get_payment_method(),
					$this->get_order_payment_method_id($order)
				);
			}
			$this->add_payop_order_note(
				$order,
				__('Stored Payop invoice is overdue; creating a replacement invoice', 'payop-woocommerce'),
				['invoice_id' => (string) $response]
			);
			$this->log_payop(
				'warning',
				'Stored Payop invoice is overdue; creating a replacement invoice',
				['invoice_id' => (string) $response],
				$order
			);
			$order->delete_meta_data(PAYOP_INVITATE_RESPONSE);
			$order->delete_meta_data(PAYOP_INVOICE_ID_META);
			$order->delete_meta_data(PAYOP_INVOICE_CONTEXT_META);
			$order->delete_meta_data(PAYOP_TXID_META);
			$order->save_meta_data();
			$response = false;
		}

		if ( !$response ) {
			$out_summ = number_format($order->get_total(), 4, '.', '');
			$currency = $order->get_currency();
			$site_url = get_site_url();

			$order_info = [
				'id' => $order_id,
				'amount' => $out_summ,
				'currency' => $order->get_currency()
			];

			ksort($order_info, SORT_STRING);
			$data_set = array_values($order_info);
			$data_set[] = $this->secret_key;
			$signature = hash(PAYOP_HASH_ALGORITHM, implode(':', $data_set));

			$first_name = $order->get_billing_first_name();
			$last_name = $order->get_billing_last_name();

			$result_url = $this->get_payop_browser_return_url($order, 'success');
			$fail_path = $this->get_payop_browser_return_url($order, 'fail');

			$arr_data = [
				'publicKey' => $this->public_key,
				'order' => [
					'id' => strval($order_id),
					'amount' => $out_summ,
					'currency' => $currency,
					'description' => __('Payment order #', 'payop-woocommerce') . $order_id,
					'items' => []
				],
				'payer' => [
					'email' => $order->get_billing_email(),
					'name' => implode(' ', array_filter([$first_name, $last_name])),
					'phone' => $order->get_billing_phone() ?: ''
				],
				'language' => $this->language,
				'productUrl' => $site_url,
				'resultUrl' => $result_url,
				'failPath' => $fail_path,
				'signature' => $signature
			];

			$payment_method = $this->get_order_payment_method_id($order);
			if ($payment_method !== '') {
				$arr_data['paymentMethod'] = $payment_method;
			}

			$invoice_details = [
				'order_id' => $order->get_id(),
				'amount' => $out_summ,
				'currency' => $currency,
				'gateway_id' => $order->get_payment_method(),
				'payment_method_id' => $payment_method,
			];
			$this->add_payop_order_note($order, __('Payop invoice creation requested', 'payop-woocommerce'), $invoice_details);
			$this->log_payop('info', 'Payop invoice creation requested', $invoice_details, $order);

			$response = $this->api_request($arr_data, PAYOP_API_IDENTIFIER);
			if (isset($response['messages'])) {
				$invoice_details['error'] = is_array($response['messages']) ? wp_json_encode($response['messages']) : (string) $response['messages'];
				$this->add_payop_order_note($order, __('Payop invoice creation failed', 'payop-woocommerce'), $invoice_details);
				$this->log_payop('error', 'Payop invoice creation failed', $invoice_details, $order);
				return '<p>' . __('Request to payment service was sent incorrectly', 'payop-woocommerce') . '</p><br><p>' . esc_html($invoice_details['error']) . '</p>';
			}

			if (!is_scalar($response) || (string) $response === '') {
				$invoice_details['error'] = 'Empty invoice identifier';
				$this->add_payop_order_note($order, __('Payop invoice creation failed', 'payop-woocommerce'), $invoice_details);
				$this->log_payop('error', 'Payop invoice creation failed', $invoice_details, $order);
				return '<p>' . __('Payment service did not return an invoice identifier', 'payop-woocommerce') . '</p>';
			}

			$response = sanitize_text_field((string) $response);
			// $response is the invoice identifier returned in the response header.
			$order->update_meta_data(PAYOP_INVITATE_RESPONSE, $response);
			$order->update_meta_data(PAYOP_INVOICE_ID_META, $response);
			$this->remember_payop_invoice($order, $response, $this->id, $payment_method);
			$order->save_meta_data();
			$invoice_details['invoice_id'] = $response;
			$this->add_payop_order_note($order, __('Payop invoice created', 'payop-woocommerce'), $invoice_details);
			$this->log_payop('info', 'Payop invoice created', $invoice_details, $order);
		}

		if(isset($response['messages'])) {
			return '<p>' . __('Request to payment service was sent incorrectly', 'payop-woocommerce') . '</p><br><p>' . $response['messages'] .'</p>';
		}

		$action_adr = 'https://checkout.payop.com/' . $this->language . '/payment/invoice-preprocessing/' . $response;
		$redirect_details = [
			'order_id' => $order->get_id(),
			'invoice_id' => $response,
			'gateway' => 'Payop',
			'checkout_url' => $action_adr,
		];
		$this->mark_payop_redirect_started($order);

		if ($this->skip_confirm === "yes"){
			$this->add_payop_order_note($order, __('Redirected To Payment Page', 'payop-woocommerce'), $redirect_details);
			$this->log_payop('info', 'Redirected To Payment Page', $redirect_details, $order);
			wp_redirect(esc_url($action_adr));
			exit;
		}

		$this->add_payop_order_note($order, __('Payop payment page displayed to customer', 'payop-woocommerce'), $redirect_details);
		$this->log_payop('info', 'Payop payment page displayed to customer', $redirect_details, $order);
		return $this->generate_payment_form_html($action_adr, $order);
	}

	/**
	 * Fetch an order id from request data (GET or JSON body).
	 *
	 * @param array $data
	 * @return int|null
	 */
	private function extract_order_id_from_request(array $data)
	{
		$order_id = $data['transaction']['order']['id'] ?? $data['orderId'] ?? $data['order-received'] ?? null;
		$order_id = is_scalar($order_id) ? absint($order_id) : 0;
		return $order_id > 0 ? $order_id : null;
	}

	/**
	 * Get an order from request data without terminating the request.
	 *
	 * @param array $data
	 * @return WC_Order|null
	 */
	private function get_order_from_request_data(array $data)
	{
		$order_id = $this->extract_order_id_from_request($data);
		if (!$order_id) {
			return null;
		}

		$order = wc_get_order($order_id);
		return $order instanceof WC_Order ? $order : null;
	}

	/**
	 * Prepare compact request details for order notes.
	 *
	 * @param array $data
	 * @return array
	 */
	private function get_payop_request_summary(array $data)
	{
		return array_filter([
			'order_id' => $this->extract_order_id_from_request($data),
			'invoice_id' => isset($data['invoice']['id']) && is_scalar($data['invoice']['id']) ? (string) $data['invoice']['id'] : null,
			'txid' => isset($data['invoice']['txid']) && is_scalar($data['invoice']['txid']) ? (string) $data['invoice']['txid'] : null,
			'state' => isset($data['transaction']['state']) && is_scalar($data['transaction']['state']) ? (string) $data['transaction']['state'] : null,
			'status' => isset($data['status']) && is_scalar($data['status']) ? (string) $data['status'] : null,
			'amount' => isset($data['transaction']['amount']) && is_scalar($data['transaction']['amount']) ? (string) $data['transaction']['amount'] : null,
			'currency' => isset($data['transaction']['currency']) && is_scalar($data['transaction']['currency']) ? (string) $data['transaction']['currency'] : null,
		], static function($value) {
			return $value !== null && $value !== '';
		});
	}

	/**
	 * Format details for a readable order note.
	 *
	 * @param array $details
	 * @return string
	 */
	private function format_payop_details(array $details)
	{
		$parts = [];

		foreach ($details as $key => $value) {
			if (is_array($value) || is_object($value)) {
				$value = wp_json_encode($value);
			}
			if ($value === null || $value === '') {
				continue;
			}

			$parts[] = sprintf('%s: %s', $key, (string) $value);
		}

		return implode(', ', $parts);
	}

	/**
	 * Add a Payop order note with optional details.
	 *
	 * @param WC_Order|null $order
	 * @param string        $message
	 * @param array         $details
	 * @return void
	 */
	private function add_payop_order_note($order, $message, array $details = [])
	{
		if (!$order instanceof WC_Order) {
			return;
		}

		$details_text = $this->format_payop_details($details);
		if ($details_text !== '') {
			$message .= ' (' . $details_text . ')';
		}

		$order->add_order_note($message);
	}

	/**
	 * Write a Payop entry to WooCommerce logs.
	 *
	 * @param string        $level
	 * @param string        $message
	 * @param array         $details
	 * @param WC_Order|null $order
	 * @return void
	 */
	private function log_payop($level, $message, array $details = [], $order = null)
	{
		if ($this->detailed_logging !== 'yes') {
			return;
		}

		if (!function_exists('wc_get_logger')) {
			return;
		}

		$context = ['source' => $this->id];
		if ($order instanceof WC_Order) {
			$context['order_id'] = $order->get_id();
		}

		if (!empty($details)) {
			$message .= ' | ' . wp_json_encode($details);
		}

		wc_get_logger()->log($level, $message, $context);
	}

	/**
	 * Add notes/logs for every Payop callback or IPN attempt.
	 *
	 * @param string        $request_type
	 * @param array         $posted_data
	 * @param WC_Order|null $order
	 * @return void
	 */
	private function log_payop_callback_attempt($request_type, array $posted_data, $order = null)
	{
		$details = array_merge([
			'type' => $request_type !== '' ? $request_type : 'unknown',
			'method' => isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '',
		], $this->get_payop_request_summary($posted_data));

		if ($order instanceof WC_Order) {
			$order->update_meta_data(PAYOP_CALLBACK_RECEIVED_AT_META, time());
			$order->save_meta_data();
		}

		$this->add_payop_order_note($order, __('Payop callback attempt received', 'payop-woocommerce'), $details);
		$this->log_payop('info', 'Payop callback attempt received', $details, $order);

		if ($request_type === 'result') {
			$this->log_payop('info', 'Payop IPN technical payload', ['payload' => $posted_data], $order);
		}
	}

	/**
	 * Mark that the customer reached the Payop checkout redirect step.
	 *
	 * @param WC_Order $order
	 * @return void
	 */
	private function mark_payop_redirect_started($order)
	{
		if (!$order instanceof WC_Order) {
			return;
		}

		$order->update_meta_data(PAYOP_REDIRECTED_AT_META, time());
		$order->delete_meta_data(PAYOP_CALLBACK_RECEIVED_AT_META);
		$order->delete_meta_data(PAYOP_ABANDONED_NOTE_ADDED_META);
		$order->save_meta_data();

		if (!wp_next_scheduled('payop_check_abandoned_payment', [$order->get_id()])) {
			wp_schedule_single_event(time() + HOUR_IN_SECONDS, 'payop_check_abandoned_payment', [$order->get_id()]);
		}
	}

	/**
	 * Add an order note when no Payop callback arrives after the payment page step.
	 *
	 * @param int $order_id
	 * @return void
	 */
	public function check_abandoned_payment($order_id)
	{
		$order = wc_get_order(absint($order_id));
		if (!$this->order_uses_payop($order)) {
			return;
		}

		if ($order->get_meta(PAYOP_CALLBACK_RECEIVED_AT_META) || $order->get_meta(PAYOP_ABANDONED_NOTE_ADDED_META)) {
			return;
		}

		if ($order->has_status(['processing', 'completed', 'failed', 'cancelled', 'refunded'])) {
			return;
		}

		$redirected_at = absint($order->get_meta(PAYOP_REDIRECTED_AT_META));
		if (!$redirected_at || time() < $redirected_at + HOUR_IN_SECONDS) {
			return;
		}

		$details = [
			'order_id' => $order->get_id(),
			'invoice_id' => (string) $order->get_meta(PAYOP_INVOICE_ID_META),
			'redirected_at' => gmdate('c', $redirected_at),
			'waited_minutes' => 60,
		];

		$this->add_payop_order_note($order, __('User may have abandoned the payment page: no Payop callback received', 'payop-woocommerce'), $details);
		$this->log_payop('warning', 'User may have abandoned the payment page: no Payop callback received', $details, $order);
		$order->update_meta_data(PAYOP_ABANDONED_NOTE_ADDED_META, time());
		$order->save_meta_data();
	}

	/**
	 * Update order status and record failures in notes/logs.
	 *
	 * @param WC_Order $order
	 * @param string   $status
	 * @param string   $note
	 * @param array    $details
	 * @return bool
	 */
	private function update_payop_order_status($order, $status, $note, array $details = [])
	{
		$details = array_merge([
			'from' => $order->get_status(),
			'to' => $status,
		], $details);

		try {
			$order->update_status($status, $note);
			$this->add_payop_order_note($order, __('Payop status update result', 'payop-woocommerce'), $details);
			$this->log_payop('info', 'Payop status update result', $details, $order);
			return true;
		} catch (Exception $exception) {
			$details['error'] = $exception->getMessage();
			$this->add_payop_order_note($order, __('Payop status update failed', 'payop-woocommerce'), $details);
			$this->log_payop('error', 'Payop status update failed', $details, $order);
			return false;
		}
	}

	/**
	 * Fetch trusted invoice details from Payop.
	 *
	 * @param string $invoice_id
	 * @return array
	 */
	private function fetch_payop_invoice_details($invoice_id)
	{
		$invoice_id = trim((string) $invoice_id);
		if ($invoice_id === '') {
			return ['ok' => false, 'error' => 'Empty invoice id'];
		}

		$response = wp_remote_get('https://api.payop.com/v1/invoices/' . rawurlencode($invoice_id), [
			'sslverify' => true,
			'timeout' => 30,
			'headers' => [
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
			],
		]);

		if (is_wp_error($response)) {
			return ['ok' => false, 'error' => $response->get_error_message()];
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		$json = json_decode($body, true);

		if ($code !== 200 || !is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
			return [
				'ok' => false,
				'error' => 'Invalid Payop invoice response',
				'http' => $code,
				'body' => substr($body, 0, 1000),
			];
		}

		$data = $json['data'];

		return [
			'ok' => true,
			'invoice_id' => isset($data['identifier']) ? (string) $data['identifier'] : '',
			'status' => isset($data['status']) ? (int) $data['status'] : null,
			'amount' => isset($data['amount']) ? (string) $data['amount'] : '',
			'currency' => isset($data['currency']) ? (string) $data['currency'] : '',
			'order_id' => isset($data['orderIdentifier']) ? (string) $data['orderIdentifier'] : '',
			'txid' => isset($data['transactionIdentifier']) ? (string) $data['transactionIdentifier'] : '',
			'is_overdue' => !empty($data['isOverdue']),
			'raw' => $json,
		];
	}

	/**
	 * Check whether a stored invoice can no longer be opened by Payop Checkout.
	 *
	 * @param string $invoice_id
	 * @return bool
	 */
	private function is_payop_invoice_overdue($invoice_id)
	{
		$invoice = $this->fetch_payop_invoice_details($invoice_id);
		if (!empty($invoice['ok'])) {
			return !empty($invoice['is_overdue']) || (isset($invoice['status']) && (int) $invoice['status'] === 2);
		}

		if (isset($invoice['http']) && (int) $invoice['http'] === 422 && !empty($invoice['body'])) {
			$body = json_decode((string) $invoice['body'], true);
			$message = is_array($body) && isset($body['message']) ? strtolower((string) $body['message']) : '';
			return strpos($message, 'overdue') !== false;
		}

		return false;
	}

	/**
	 * Confirm payment against Payop's invoice API before changing order status.
	 *
	 * @param WC_Order $order
	 * @param array    $posted_data
	 * @return array
	 */
	private function confirm_payop_order_by_invoice($order, array $posted_data = [])
	{
		if (!$order instanceof WC_Order) {
			return ['ok' => false, 'error' => 'Invalid order'];
		}

		if (!$this->order_uses_payop($order)) {
			return ['ok' => false, 'error' => 'Payment method mismatch'];
		}

		$current_invoice_id = $this->sanitize_invoice_id($order->get_meta(PAYOP_INVOICE_ID_META));
		$ipn_invoice_id = isset($posted_data['invoice']['id'])
			? $this->sanitize_invoice_id($posted_data['invoice']['id'])
			: '';
		$invoice_id_to_check = $ipn_invoice_id !== '' ? $ipn_invoice_id : $current_invoice_id;

		if ($invoice_id_to_check === '') {
			return ['ok' => false, 'error' => 'Missing stored invoice id'];
		}

		if (!$this->is_known_payop_invoice($order, $invoice_id_to_check)) {
			return ['ok' => false, 'error' => 'Unknown invoice id'];
		}

		$invoice_check = $this->fetch_payop_invoice_details($invoice_id_to_check);
		if (empty($invoice_check['ok'])) {
			return $invoice_check;
		}

		$expected_order_id = (string) $order->get_id();
		$expected_amount = number_format((float) $order->get_total(), 4, '.', '');
		$expected_currency = strtoupper((string) $order->get_currency());

		$invoice_id = (string) ($invoice_check['invoice_id'] ?? '');
		$invoice_status = (int) ($invoice_check['status'] ?? -1);
		$invoice_order_id = (string) ($invoice_check['order_id'] ?? '');
		$invoice_amount = ($invoice_check['amount'] ?? '') !== ''
			? number_format((float) $invoice_check['amount'], 4, '.', '')
			: '';
		$invoice_currency = strtoupper((string) ($invoice_check['currency'] ?? ''));

		if ($invoice_id === '' || $invoice_id !== $invoice_id_to_check) {
			return ['ok' => false, 'error' => 'Payop invoice identifier mismatch', 'invoice_check' => $invoice_check];
		}

		if ($invoice_order_id === '' || $invoice_order_id !== $expected_order_id) {
			return ['ok' => false, 'error' => 'Payop invoice order ID mismatch', 'invoice_check' => $invoice_check];
		}

		if ($invoice_amount === '' || $invoice_amount !== $expected_amount) {
			return ['ok' => false, 'error' => 'Payop invoice amount mismatch', 'invoice_check' => $invoice_check];
		}

		if ($invoice_currency === '' || $invoice_currency !== $expected_currency) {
			return ['ok' => false, 'error' => 'Payop invoice currency mismatch', 'invoice_check' => $invoice_check];
		}

		$is_current_invoice = $current_invoice_id !== '' && hash_equals($current_invoice_id, $invoice_id_to_check);

		if ($invoice_status === 1) {
			return [
				'ok' => true,
				'final' => true,
				'state' => 'paid',
				'is_current_invoice' => $is_current_invoice,
				'invoice_check' => $invoice_check,
			];
		}

		if (in_array($invoice_status, [2, 5], true)) {
			return [
				'ok' => true,
				'final' => $is_current_invoice,
				'state' => $is_current_invoice ? 'failed' : 'superseded',
				'is_current_invoice' => $is_current_invoice,
				'invoice_check' => $invoice_check,
			];
		}

		return [
			'ok' => true,
			'final' => false,
			'state' => 'pending',
			'is_current_invoice' => $is_current_invoice,
			'invoice_check' => $invoice_check,
		];
	}

	/**
	 * Build a browser return URL without exposing WooCommerce order tokens to the payment provider.
	 *
	 * @param WC_Order $order
	 * @param string   $request_type
	 * @return string
	 */
	private function get_payop_browser_return_url($order, $request_type)
	{
		return esc_url_raw(add_query_arg(
			[
				'wc-api' => 'wc_' . $this->id,
				'payop' => $request_type,
				'orderId' => $order->get_id(),
				'payopToken' => $this->get_payop_browser_return_token($order, $request_type),
			],
			home_url('/')
		));
	}

	/**
	 * Create an HMAC token for browser return requests so order URLs are only revealed on valid returns.
	 *
	 * @param WC_Order $order
	 * @param string   $request_type
	 * @return string
	 */
	private function get_payop_browser_return_token($order, $request_type)
	{
		$message = implode('|', [
			$request_type,
			$order->get_id(),
			(string) $order->get_order_key(),
		]);

		return hash_hmac(PAYOP_HASH_ALGORITHM, $message, wp_salt('auth') . '|' . $order->get_payment_method());
	}

	/**
	 * Validate browser return requests before redirecting to a WooCommerce URL that contains the order key.
	 *
	 * @param WC_Order $order
	 * @param array    $request_data
	 * @param string   $request_type
	 * @return bool
	 */
	private function is_valid_payop_browser_return($order, array $request_data, $request_type)
	{
		$provided_token = $request_data['payopToken'] ?? '';
		if (!is_scalar($provided_token)) {
			return false;
		}

		$provided_token = sanitize_text_field(wp_unslash((string) $provided_token));
		if ($provided_token === '') {
			return false;
		}

		return hash_equals($this->get_payop_browser_return_token($order, $request_type), $provided_token);
	}

	/**
	 * Ensure order exists and belongs to this gateway.
	 *
	 * @param int|null $order_id
	 * @return WC_Order
	 */
	private function get_payop_order_or_die($order_id)
	{
		if (!$order_id) {
			$this->log_payop('error', 'Payop request failed: invalid order id');
			wp_die('Invalid order', 'Invalid order', 400);
		}
		$order = wc_get_order($order_id);
		if (!$order) {
			$this->log_payop('error', 'Payop request failed: order not found', ['order_id' => $order_id]);
			wp_die('Order not found', 'Order not found', 404);
		}
		// Prevent abusing our endpoint to manipulate non-Payop orders.
		if (!$this->order_uses_payop($order)) {
			$this->log_payop('error', 'Payop request failed: payment method mismatch', [
				'order_id' => $order->get_id(),
				'payment_method' => $order->get_payment_method(),
			], $order);
			wp_die('Payment method mismatch', 'Forbidden', 403);
		}
		// Ensure this order actually created at least one Payop invoice via this plugin.
		$current_invoice_id = $this->sanitize_invoice_id($order->get_meta(PAYOP_INVOICE_ID_META));
		if ($current_invoice_id === '' && empty($this->get_payop_invoice_history($order))) {
			$this->add_payop_order_note($order, __('Payop request rejected: missing stored invoice id', 'payop-woocommerce'));
			$this->log_payop('error', 'Payop request rejected: missing stored invoice id', [], $order);
			wp_die('Missing Payop invoice', 'Forbidden', 403);
		}
		return $order;
	}

	/**
	 * Generates payment form HTML.
	 *
	 * @param string $action_adr The URL where the form should be submitted.
	 * @param WC_Order $order The WooCommerce order object.
	 * @return string The generated HTML for the payment form.
	 */
	private function generate_payment_form_html($action_adr, $order)
	{
		$form_args = [
			'action' => esc_url($action_adr),
			'method' => 'GET',
			'id' => 'payop_payment_form'
		];

		$form_attributes = array_map(function ($key, $value) {
			return $key . '="' . $value . '"';
		}, array_keys($form_args), $form_args);

		return '<form ' . implode(' ', $form_attributes) . '>' .
			'<input type="submit" class="button alt" id="submit_payop_payment_form" value="' . __('Pay', 'payop-woocommerce') . '" /> ' .
			'<a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Refuse payment & return to cart', 'payop-woocommerce') . '</a>' .
			'</form>';
	}

	/**
	 * Check Payop IPN response and take appropriate actions.
	 */
	public function check_ipn_response()
	{
		$request_type = !empty($_GET['payop']) ? $_GET['payop'] : '';

		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			$posted_data = json_decode(file_get_contents('php://input'), true);
			if (!is_array($posted_data)) {
				$posted_data = [];
			}
		} else {
			$posted_data = $_GET;
		}

		$posted_data = wp_unslash($posted_data);
		$order = $this->get_order_from_request_data(is_array($posted_data) ? $posted_data : []);
		$this->log_payop_callback_attempt($request_type, is_array($posted_data) ? $posted_data : [], $order);

		switch ($request_type) {
			case 'result':
				$this->process_result_request($posted_data);
				break;
			case 'success':
				$this->process_success_request($posted_data);
				break;
			case 'fail':
				$this->process_fail_request($posted_data);
				break;
			default:
				$this->process_invalid_request();
		}
	}

	/**
	 * Map Payop status to WooCommerce status.
	 *
	 * @param int $payop_state The Payop transaction state.
	 * @return string|null WooCommerce status or null if unknown.
	 */
	private function map_status_to_wc($payop_state)
	{
		switch ($payop_state) {
			case 1: // New transaction
			case 4: // Pending transaction
				return 'pending';
			case 2: // Accepted, paid successfully
				return $this->auto_complete === 'yes' ? 'completed' : 'processing';
			case 3: // Failed
			case 5: // Failed
			case 15: // Timeout
				return 'failed';
			case 9: // Pre-approved
				return 'on-hold';
			default:
				return null; // Unknown status
		}
	}

	/**
	 * Process the result request.
	 *
	 * @param array $posted_data The posted data.
	 * @return void
	 */
	private function process_result_request( $posted_data )
	{
		@ob_clean();
		$order = $this->get_order_from_request_data($posted_data);
		$valid = $this->check_ipn_request_is_valid($posted_data);

		if ($valid === PAYOP_IPN_VERSION_V2) {
			$order_id = $this->extract_order_id_from_request($posted_data);
			$order = $this->get_payop_order_or_die($order_id);
			$details = array_merge(['ipn_version' => PAYOP_IPN_VERSION_V2], $this->get_payop_request_summary($posted_data));

			$this->add_payop_order_note($order, __('Payop IPN received; verifying invoice with Payop API', 'payop-woocommerce'), $details);
			$this->log_payop('info', 'Payop IPN received; verifying invoice with Payop API', $details, $order);

			$confirmation = $this->confirm_payop_order_by_invoice($order, $posted_data);
			$confirmation_details = array_merge($details, [
				'verification_state' => (string) ($confirmation['state'] ?? ''),
				'verification_error' => (string) ($confirmation['error'] ?? ''),
			]);

			if (empty($confirmation['ok'])) {
				$this->add_payop_order_note($order, __('Payop invoice verification failed', 'payop-woocommerce'), $confirmation_details);
				$this->log_payop('error', 'Payop invoice verification failed', $confirmation_details, $order);
				wp_die('CHECK_FAILED', 'CHECK_FAILED', 200);
			}

			$state = (string) ($confirmation['state'] ?? '');
			if ($state === 'paid') {
				$txid = (string) ($confirmation['invoice_check']['txid'] ?? '');
				if ($txid !== '') {
					$order->update_meta_data(PAYOP_TXID_META, sanitize_text_field($txid));
					$order->save();
				}

				$this->add_payop_order_note($order, __('Payop invoice verified as paid', 'payop-woocommerce'), $confirmation_details);
				$this->log_payop('info', 'Payop invoice verified as paid', $confirmation_details, $order);

				if (!$order->is_paid()) {
					$order->payment_complete($txid);
				}

				$wc_status = $this->auto_complete === 'yes' ? 'completed' : 'processing';
				if (!$order->has_status(['processing', 'completed'])) {
					if (!$this->update_payop_order_status($order, $wc_status, __('Payop invoice verified as paid', 'payop-woocommerce'), $confirmation_details)) {
						wp_die('Status update failed', 'Status update failed', 500);
					}
				} elseif ($this->auto_complete === 'yes' && !$order->has_status('completed')) {
					if (!$this->update_payop_order_status($order, 'completed', __('Payop invoice verified as paid', 'payop-woocommerce'), $confirmation_details)) {
						wp_die('Status update failed', 'Status update failed', 500);
					}
				}

				wp_die('PAID', 'PAID', 200);
			}

			if ($state === 'failed') {
				$this->add_payop_order_note($order, __('Payop invoice verified as failed or overdue', 'payop-woocommerce'), $confirmation_details);
				$this->log_payop('warning', 'Payop invoice verified as failed or overdue', $confirmation_details, $order);
				if (!$order->has_status(['failed', 'cancelled', 'refunded'])) {
					if (!$this->update_payop_order_status($order, 'failed', __('Payop invoice verified as failed or overdue', 'payop-woocommerce'), $confirmation_details)) {
						wp_die('Status update failed', 'Status update failed', 500);
					}
				}
				wp_die('FAILED', 'FAILED', 200);
			}

			if ($state === 'superseded') {
				$this->add_payop_order_note($order, __('Superseded Payop invoice is no longer active; order status was not changed', 'payop-woocommerce'), $confirmation_details);
				$this->log_payop('info', 'Superseded Payop invoice is no longer active; order status was not changed', $confirmation_details, $order);
				wp_die('SUPERSEDED', 'SUPERSEDED', 200);
			}

			$this->add_payop_order_note($order, __('Payop invoice is not paid yet; waiting for confirmation', 'payop-woocommerce'), $confirmation_details);
			$this->log_payop('info', 'Payop invoice is not paid yet; waiting for confirmation', $confirmation_details, $order);
			if (!$order->has_status(['pending', 'on-hold', 'processing', 'completed'])) {
				if (!$this->update_payop_order_status($order, 'on-hold', __('Payop invoice is not paid yet; waiting for confirmation', 'payop-woocommerce'), $confirmation_details)) {
					wp_die('Status update failed', 'Status update failed', 500);
				}
			}

			wp_die('WAIT', 'WAIT', 200);
		} elseif ($valid === PAYOP_IPN_VERSION_V1) {
			$status = $posted_data['status'];
			$order_id = $this->extract_order_id_from_request($posted_data);
			$order = $this->get_payop_order_or_die($order_id);
			$details = array_merge(['ipn_version' => PAYOP_IPN_VERSION_V1], $this->get_payop_request_summary($posted_data));
			$this->add_payop_order_note($order, __('Payop IPN validation passed', 'payop-woocommerce'), $details);

			switch ($status) {
				case 'wait':
					$this->add_payop_order_note($order, __('Payop payment pending', 'payop-woocommerce'), $details);
					$this->log_payop('info', 'Payop payment pending', $details, $order);
					if (!$this->update_payop_order_status($order, 'pending', __('Transaction pending', 'payop-woocommerce'), $details)) {
						wp_die('Status update failed', 'Status update failed', 500);
					}
					do_action('payop-ipn-request', $posted_data);
					wp_die('Status pending', 'Status pending', 200);
					break;

				case 'success':
					$this->add_payop_order_note($order, __('Payop payment paid', 'payop-woocommerce'), $details);
					$this->log_payop('info', 'Payop payment paid', $details, $order);
					if ($this->auto_complete === 'yes') {
						$status_updated = $this->update_payop_order_status($order, 'completed', __('Payment successfully paid', 'payop-woocommerce'), $details);
					} else {
						$status_updated = $this->update_payop_order_status($order, 'processing', __('Payment successfully paid', 'payop-woocommerce'), $details);
					}
					if (!$status_updated) {
						wp_die('Status update failed', 'Status update failed', 500);
					}
					do_action('payop-ipn-request', $posted_data);
					wp_die('Status success', 'Status success', 200);
					break;

				case 'error':
					$this->add_payop_order_note($order, __('Payop payment failed', 'payop-woocommerce'), $details);
					$this->log_payop('warning', 'Payop payment failed', $details, $order);
					if (!$this->update_payop_order_status($order, 'failed', __('Payment not paid', 'payop-woocommerce'), $details)) {
						wp_die('Status update failed', 'Status update failed', 500);
					}
					do_action('payop-ipn-request', $posted_data);
					wp_die('Status fail', 'Status fail', 200);
					break;

				default:
					$details = array_merge(['reason' => 'Unknown status'], $details);
					$this->add_payop_order_note($order, __('Payop IPN processing failed', 'payop-woocommerce'), $details);
					$this->log_payop('error', 'Payop IPN processing failed', $details, $order);
					wp_die('Unknown status', 'Unknown status', 400);
			}

		} else {
			$details = array_merge(['reason' => $valid], $this->get_payop_request_summary($posted_data));
			$this->add_payop_order_note($order, __('Payop IPN validation failed', 'payop-woocommerce'), $details);
			$this->log_payop('error', 'Payop IPN validation failed', ['reason' => $valid, 'payload' => $posted_data], $order);
			wp_die($valid, $valid, 400);
		}
	}

	 /**
	 * Process the success request.
	 *
	 * @param array $posted_data The posted data.
	 * @return void
	 */
		// private function process_success_request($posted_data)
		// {
		// 	// IMPORTANT:
		// 	// Browser redirects (success/fail) are NOT a trusted signal.
		// 	// Payment confirmation must happen only via IPN (server-to-server) and/or polling the Payop API.
		// 	$order_id = $this->extract_order_id_from_request($posted_data);
		// 	$order = $this->get_payop_order_or_die($order_id);

		// 	// NOTE:
		// 	// Do not change order status on success redirect.
		// 	// Success URL can be opened manually and must be treated as UI-only.
		// 	// Order status will be updated only via IPN (server-to-server) and/or explicit provider verification.

		// 	$this->empty_cart();
		// 	wp_redirect($this->get_return_url($order));
		// 	exit;
		// }
	private function process_success_request($posted_data)
	{
		// IMPORTANT:
		// Browser redirects (success/fail) are NOT a trusted signal.
		$order_id = $this->extract_order_id_from_request($posted_data);
		$order = $this->get_payop_order_or_die($order_id);
		if (!$this->is_valid_payop_browser_return($order, $posted_data, 'success')) {
			$details = array_merge(['reason' => 'Invalid return token'], $this->get_payop_request_summary($posted_data));
			$this->add_payop_order_note($order, __('Payop callback validation failed', 'payop-woocommerce'), $details);
			$this->log_payop('error', 'Payop callback validation failed', $details, $order);
			wp_die('Invalid return token', 'Forbidden', 403);
		}

		$transaction_state = isset($posted_data['transaction']['state']) ? intval($posted_data['transaction']['state']) : null;
		$details = $this->get_payop_request_summary($posted_data);

		// If Payop pre-approves, keep order on-hold, waiting for final IPN.
		if ($transaction_state === 9) {
			if (!$order->has_status(['processing', 'completed'])) {
				if (!$this->update_payop_order_status($order, 'on-hold', __('Payment pre-approved by provider (awaiting confirmation)', 'payop-woocommerce'), $details)) {
					wp_die('Status update failed', 'Status update failed', 500);
				}
			}
		} else {
			// Generic UX path: order stays pending/on-hold until IPN confirms.
			if ($order->has_status(['pending', 'failed'])) {
				if (!$this->update_payop_order_status($order, 'on-hold', __('Awaiting Payop confirmation (IPN)', 'payop-woocommerce'), $details)) {
					wp_die('Status update failed', 'Status update failed', 500);
				}
			}
		}

		$this->empty_cart();
		wp_safe_redirect($this->get_return_url($order));
		exit;
	}

	/**
	 * Process the fail request.
	 *
	 * @param array $posted_data The posted data.
	 * @return void
	 */
	private function process_fail_request($posted_data){
		// Fail redirect is also untrusted. Do not mark order failed based on GET.
		$order_id = $this->extract_order_id_from_request($posted_data);
		$order = $this->get_payop_order_or_die($order_id);
		if (!$this->is_valid_payop_browser_return($order, $posted_data, 'fail')) {
			$details = array_merge(['reason' => 'Invalid return token'], $this->get_payop_request_summary($posted_data));
			$this->add_payop_order_note($order, __('Payop callback validation failed', 'payop-woocommerce'), $details);
			$this->log_payop('error', 'Payop callback validation failed', $details, $order);
			wp_die('Invalid return token', 'Forbidden', 403);
		}

		// NOTE:
		// Do not change order status on fail redirect either.
		// Status will be updated only via IPN/provider verification.
		$this->add_payop_order_note($order, __('Payop fail callback received; awaiting IPN/provider verification', 'payop-woocommerce'), $this->get_payop_request_summary($posted_data));
		$this->log_payop('info', 'Payop fail callback received; awaiting IPN/provider verification', $this->get_payop_request_summary($posted_data), $order);

		$this->empty_cart();
		wp_safe_redirect($this->get_return_url($order));
		exit;
	}
	 /**
	 * Process the invalid request.
	 *
	 * @return void
	 */
	private function process_invalid_request()
	{
		$this->log_payop('warning', 'Payop invalid callback request', [
			'method' => isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '',
		]);
		wp_die('Invalid request', 'Invalid request', 400);
	}

	/**
	 * Checks if payment is needed for an order with the Payop payment gateway
	 * and disables payment for orders with 'failed' status.
	 *
	 * @param bool   $needs_payment		The current value indicating whether payment is needed for the order.
	 * @param object $order				The order object.
	 * @param array  $valid_order_statuses An array of valid order statuses.
	 * @return bool Returns false if payment is not required for orders with 'failed' status and the Payop payment gateway.
	 */
	public function prevent_payment_for_failed_orders( $needs_payment, $order, $valid_order_statuses )
	{
		if ($order->has_status('failed') && $this->order_uses_payop($order)) {
			$needs_payment = false;
		}

		return $needs_payment;
	}

	/**
	 * Process payment and redirect to payment gateway.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array
	 */
	public function process_payment( $order_id )
	{
		$order = wc_get_order( $order_id );
		$payment_method = $this->integration_type === 'payment_method' ? $this->payment_method : '';
		$invoice_was_reset = false;

		if ($order instanceof WC_Order) {
			$invoice_was_reset = $this->reset_invoice_for_changed_selection($order, $this->id, $payment_method);
			if ($payment_method !== '') {
				$order->update_meta_data(PAYOP_PAYMENT_METHOD_ID_META, $payment_method);
			} else {
				$order->delete_meta_data(PAYOP_PAYMENT_METHOD_ID_META);
			}
			$order->save_meta_data();
		}

		$details = [
			'order_id' => $order_id,
			'amount' => $order ? number_format($order->get_total(), 4, '.', '') : '',
			'currency' => $order ? $order->get_currency() : '',
			'redirect' => $order ? $order->get_checkout_payment_url(true) : '',
			'gateway_id' => $this->id,
			'payment_method_id' => $payment_method,
			'invoice_reset' => $invoice_was_reset ? 'yes' : 'no',
		];

		$this->add_payop_order_note($order, __('Payop payment initiated at checkout', 'payop-woocommerce'), $details);
		$this->log_payop('info', 'Payop payment initiated at checkout', $details, $order);

		return [
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		];
	}

	/**
	 * Check Payop IPN validity.
	 *
	 * @param array $posted Data received from Payop IPN.
	 *
	 * @return bool|string
	 */
	public function check_ipn_request_is_valid( $posted )
	{
		$invoice_id = isset($posted['invoice']['id']) ? $posted['invoice']['id'] : null;
		$tx_id = isset($posted['invoice']['txid']) ? $posted['invoice']['txid'] : null;
		$order_id = isset($posted['transaction']['order']['id']) ? $posted['transaction']['order']['id'] : null;
		$signature = isset($posted['signature']) ? $posted['signature'] : null;
		// check IPN V1
		if (!$invoice_id) {
			if (!$signature) {
				return 'Empty invoice id';
			} else {
				$order_id = isset($posted['orderId']) ? $posted['orderId'] : null;
				if (!$order_id) {
					return 'Empty order id V1';
				}
				$order = wc_get_order($order_id);
				if (!$order) {
					return 'Order not found';
				}
				if (!$this->order_uses_payop($order)) {
					return 'Payment method mismatch';
				}
				$currency = $order->get_currency();
				$amount = number_format($order->get_total(), 4, '.', '');

				$status = isset($posted['status']) ? $posted['status'] : '';

				if ($status !== 'success' && $status !== 'error' && $status !== 'wait') {
					return 'Status is not valid';
				}

				$order_info = [
					'id' => $order_id,
					'amount' => $amount,
					'currency' => $currency
				];

				ksort($order_info, SORT_STRING);
				$data_set = array_values($order_info);

				if ($status) {
					array_push($data_set, $status);
				}

				array_push($data_set, $this->secret_key);

				if ($posted['signature'] === hash(PAYOP_HASH_ALGORITHM, implode(':', $data_set))) {
					return PAYOP_IPN_VERSION_V1;
				}
				return 'Invalid signature';
			}
		}
		if (!$tx_id) {
			return 'Empty transaction id';
		}
		if (!$order_id) {
			return 'Empty order id V2';
		}

		$order_id = absint($order_id);
		$order = wc_get_order($order_id);
		if (!$order) {
			return 'Order not found';
		}
		if (!$this->order_uses_payop($order)) {
			return 'Payment method mismatch';
		}
		$state = isset($posted['transaction']['state']) ? intval($posted['transaction']['state']) : null;
		if (!$state) {
			return 'Empty state';
		}
		// Accept all known states used by the plugin mapping.
		if (!in_array($state, [1,2,3,4,5,9,15], true)) {
			return 'State is not valid';
		}

		// Bind invoice id: it must be the current invoice or a superseded invoice
		// that was previously created by this plugin for the same order.
		$invoice_id = $this->sanitize_invoice_id($invoice_id);
		if ($invoice_id === '') {
			return 'Missing stored invoice id';
		}
		if (!$this->is_known_payop_invoice($order, $invoice_id)) {
			return 'Invoice id mismatch';
		}

		// If we already stored txid, it must match too.
		$expected_txid = (string) $order->get_meta(PAYOP_TXID_META);
		if ($expected_txid !== '' && (string) $tx_id !== $expected_txid) {
			return 'Transaction id mismatch';
		}

		// Optional sanity checks if Payop sends amount/currency in IPN payload.
		if (isset($posted['transaction']['amount'])) {
			$expected_amount = number_format($order->get_total(), 4, '.', '');
			$ipn_amount = number_format((float) $posted['transaction']['amount'], 4, '.', '');
			if ($ipn_amount !== $expected_amount) {
				return 'Amount mismatch';
			}
		}
		if (isset($posted['transaction']['currency'])) {
			if (strtoupper((string) $posted['transaction']['currency']) !== strtoupper($order->get_currency())) {
				return 'Currency mismatch';
			}
		}

		return PAYOP_IPN_VERSION_V2;
	}

	/**
	 * Handle successful IPN request.
	 *
	 * @param array $posted Data received from Payop IPN.
	 */
	public function successful_request( $posted )
	{
		// V2 IPN is completed directly after server-side Payop invoice verification.
		$posted = is_array($posted) ? $posted : [];
		if (isset($posted['invoice']['id'])) {
			exit;
		}

		// This hook is triggered after a valid signed V1 IPN. Keep it defensive.
		$order_id = $this->extract_order_id_from_request($posted);
		$order = $this->get_payop_order_or_die($order_id);
		$details = $this->get_payop_request_summary($posted);

		// Payment completed: only call payment_complete when IPN V2 indicates paid.
		$state = isset($posted['transaction']['state']) ? intval($posted['transaction']['state']) : null;
		$status = isset($posted['status']) && is_scalar($posted['status']) ? (string) $posted['status'] : null;
		if ($state === 2 || $status === 'success') {
			$this->add_payop_order_note($order, __('Payment completed successfully (IPN)', 'payop-woocommerce'), $details);
			$this->log_payop('info', 'Payment completed successfully (IPN)', $details, $order);
		}

		if ($order->has_status('completed')) {
			exit;
		}

		if ($state === 2) {
			try {
				$order->payment_complete();
			} catch (Exception $exception) {
				$details['error'] = $exception->getMessage();
				$this->add_payop_order_note($order, __('Payop status update failed', 'payop-woocommerce'), $details);
				$this->log_payop('error', 'Payop payment_complete failed', $details, $order);
			}
		}

		exit;
	}

	/**
	 * Make an API request to Payop.
	 *
	 * @param array  $arr_data Data to be sent in the request.
	 * @param string $retrieved_header Retrieved header.
	 *
	 * @return mixed
	 */
	public function api_request( $arr_data = [], $retrieved_header = '')
	{
		$request_url = $this->api_url;
		$args = [
			'sslverify' => true,
			'timeout' => 45,
			'headers' => [
				'Content-Type' => 'application/json'
			],
			'body' => json_encode($arr_data),
		];

		$response = wp_remote_post($request_url, $args);
		if ($retrieved_header !== ''){
			$response = wp_remote_retrieve_header($response, $retrieved_header);
			if (!empty($response)){
				return $response;
			}
		} else {
			$response = wp_remote_retrieve_body($response);
		}
		return json_decode($response, true);
	}

	/**
	 * Check if this gateway is enabled and available in the user's country
	 * 
	 * @return bool
	 */
	public function is_valid_for_use()
	{
		return true;
	}

	/**
	 * Hide incomplete Payment Method ID integrations from checkout.
	 *
	 * @return bool
	 */
	public function is_available()
	{
		if ($this->integration_type === 'payment_method' && $this->payment_method === '') {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * Get selectable payment methods for the settings page.
	 *
	 * @return array
	 */
	public function get_payment_method_options()
	{
		$options = ['' => __('Select a payment method', 'payop-woocommerce')];
		$settings = (array) get_option('woocommerce_' . PAYOP_PAYMENT_GATEWAY_NAME . '_settings', []);

		if ($this->is_payop_settings_request()) {
			foreach ($this->load_available_payment_methods($settings) as $payment_method) {
				$options[$payment_method['identifier']] = sprintf(
					'%s (ID: %s%s)',
					$payment_method['title'],
					$payment_method['identifier'],
					$payment_method['currencies'] !== '' ? '; ' . $payment_method['currencies'] : ''
				);
			}
		}

		$saved_payment_methods = [];
		if (!empty($settings['payment_method'])) {
			$saved_payment_methods[] = self::sanitize_payment_method_id($settings['payment_method']);
		}
		foreach (self::get_payment_buttons($settings) as $button) {
			if (!empty($button['payment_method'])) {
				$saved_payment_methods[] = self::sanitize_payment_method_id($button['payment_method']);
			}
		}

		foreach (array_unique(array_filter($saved_payment_methods)) as $payment_method_id) {
			if (!isset($options[$payment_method_id])) {
				$options[$payment_method_id] = sprintf(
					__('Saved payment method (ID: %s)', 'payop-woocommerce'),
					$payment_method_id
				);
			}
		}

		return $options;
	}

	/**
	 * Check whether the current request renders the primary Payop settings.
	 *
	 * @return bool
	 */
	private function is_payop_settings_request()
	{
		if (!is_admin()) {
			return false;
		}

		$page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
		$tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
		$section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';

		return $page === 'wc-settings' && $tab === 'checkout' && $section === PAYOP_PAYMENT_GATEWAY_NAME;
	}

	/**
	 * Load and cache payment methods available to the configured Payop project.
	 *
	 * @param array $settings
	 * @return array
	 */
	private function load_available_payment_methods(array $settings)
	{
		if (is_array($this->available_payment_methods)) {
			return $this->available_payment_methods;
		}

		$this->available_payment_methods = [];
		$public_key = isset($settings['public_key']) ? trim((string) $settings['public_key']) : '';
		$jwt_token = isset($settings['jwt_token']) ? trim((string) $settings['jwt_token']) : '';

		if ($public_key === '' || $jwt_token === '') {
			$this->available_payment_methods_error = __('Save the Public key and JWT Token to load available payment methods.', 'payop-woocommerce');
			return [];
		}

		$application_id = preg_replace('/^application-/', '', $public_key);
		if ($application_id === '') {
			$this->available_payment_methods_error = __('The Public key does not contain a valid application ID.', 'payop-woocommerce');
			return [];
		}

		$cache_key = 'payop_methods_' . md5($public_key . '|' . $jwt_token);
		$cached_methods = get_transient($cache_key);
		if (is_array($cached_methods)) {
			$this->available_payment_methods = $cached_methods;
			return $cached_methods;
		}

		$response = wp_remote_get(
			'https://api.payop.com/v1/instrument-settings/payment-methods/available-for-application/' . rawurlencode($application_id),
			[
				'sslverify' => true,
				'timeout' => 20,
				'headers' => [
					'Accept' => 'application/json',
					'Content-Type' => 'application/json',
					'Authorization' => 'Bearer ' . $jwt_token,
				],
			]
		);

		if (is_wp_error($response)) {
			$this->available_payment_methods_error = sprintf(
				__('Unable to load Payop payment methods: %s', 'payop-woocommerce'),
				$response->get_error_message()
			);
			return [];
		}

		$status_code = (int) wp_remote_retrieve_response_code($response);
		$response_data = json_decode((string) wp_remote_retrieve_body($response), true);
		if ($status_code !== 200 || !is_array($response_data) || empty($response_data['data']) || !is_array($response_data['data'])) {
			$message = is_array($response_data) && !empty($response_data['message'])
				? sanitize_text_field((string) $response_data['message'])
				: __('Unexpected API response.', 'payop-woocommerce');
			$this->available_payment_methods_error = sprintf(
				__('Unable to load Payop payment methods: %s', 'payop-woocommerce'),
				$message
			);
			return [];
		}

		foreach ($response_data['data'] as $payment_method) {
			$identifier = isset($payment_method['identifier'])
				? self::sanitize_payment_method_id($payment_method['identifier'])
				: '';
			if ($identifier === '') {
				continue;
			}

			$currencies = isset($payment_method['currencies']) && is_array($payment_method['currencies'])
				? implode(', ', array_map('sanitize_text_field', $payment_method['currencies']))
				: '';
			$this->available_payment_methods[] = [
				'identifier' => $identifier,
				'title' => isset($payment_method['title']) ? sanitize_text_field((string) $payment_method['title']) : $identifier,
				'currencies' => $currencies,
			];
		}

		set_transient($cache_key, $this->available_payment_methods, 5 * MINUTE_IN_SECONDS);
		return $this->available_payment_methods;
	}

	/**
	 * Render the additional payment buttons repeater.
	 *
	 * @param string $key
	 * @param array  $data
	 * @return string
	 */
	public function generate_payop_buttons_html($key, $data)
	{
		$field_key = $this->get_field_key($key);
		$buttons = $this->get_option($key, []);
		$buttons = is_array($buttons) ? array_values($buttons) : [];
		$method_options = $this->get_payment_method_options();
		$can_add_buttons = count($method_options) > 1;
		$next_gateway_number = 2;

		foreach ($buttons as $button) {
			$next_gateway_number = max($next_gateway_number, absint(isset($button['gateway_number']) ? $button['gateway_number'] : 0) + 1);
		}

		$template = $this->get_payment_button_row_html(
			'__INDEX__',
			[
				'gateway_number' => '__NUMBER__',
				'enabled' => 'yes',
				'title' => '',
				'description' => __('Accept online payments using Payop.com', 'payop-woocommerce'),
				'integration_type' => 'payment_method',
				'payment_method' => '',
			],
			$method_options
		);

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label><?php echo esc_html($data['title']); ?></label>
			</th>
			<td class="forminp">
				<p class="description"><?php echo wp_kses_post($data['description']); ?></p>
				<?php if ($this->available_payment_methods_error !== '') : ?>
					<div class="notice notice-warning inline"><p><?php echo esc_html($this->available_payment_methods_error); ?></p></div>
				<?php endif; ?>
				<div class="payop-payment-buttons-wrap">
				<table class="widefat striped" id="payop-payment-buttons">
					<thead>
						<tr>
							<th><?php esc_html_e('Enabled', 'payop-woocommerce'); ?></th>
							<th><?php esc_html_e('Checkout name', 'payop-woocommerce'); ?></th>
							<th><?php esc_html_e('Description', 'payop-woocommerce'); ?></th>
							<th><?php esc_html_e('Integration type', 'payop-woocommerce'); ?></th>
							<th><?php esc_html_e('Payment method', 'payop-woocommerce'); ?></th>
							<th><?php esc_html_e('Actions', 'payop-woocommerce'); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ($buttons as $index => $button) {
							echo $this->get_payment_button_row_html($index, $button, $method_options); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</tbody>
				</table>
				</div>
				<p>
					<button
						type="button"
						class="button button-secondary"
						id="payop-add-payment-button"
						<?php disabled(!$can_add_buttons); ?>
					>
						<?php esc_html_e('Add payment button', 'payop-woocommerce'); ?>
					</button>
				</p>
				<?php if (!$can_add_buttons) : ?>
					<p class="description"><?php esc_html_e('Save a valid Public key and JWT Token before adding payment buttons.', 'payop-woocommerce'); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<script>
			jQuery(function($) {
				const tableBody = $('#payop-payment-buttons tbody');
				const rowTemplate = <?php echo wp_json_encode($template); ?>;
				let nextIndex = <?php echo absint(count($buttons)); ?>;
				let nextGatewayNumber = <?php echo absint($next_gateway_number); ?>;

				function toggleRowPaymentMethod(row) {
					const integrationType = row.find('.payop-button-integration-type').val();
					const paymentMethod = row.find('.payop-button-payment-method');
					paymentMethod.closest('td').toggle(integrationType === 'payment_method');
					paymentMethod.prop('required', integrationType === 'payment_method');
				}

				$('#payop-add-payment-button').on('click', function() {
					const rowHtml = rowTemplate
						.split('__INDEX__').join(nextIndex++)
						.split('__NUMBER__').join(nextGatewayNumber++);
					const row = $(rowHtml);
					tableBody.append(row);
					toggleRowPaymentMethod(row);
				});

				tableBody.on('click', '.payop-remove-payment-button', function() {
					$(this).closest('tr').remove();
				});

				tableBody.on('change', '.payop-button-integration-type', function() {
					toggleRowPaymentMethod($(this).closest('tr'));
				});

				tableBody.on('change', '.payop-button-payment-method', function() {
					const row = $(this).closest('tr');
					const title = row.find('input[type="text"]');
					if (title.val().trim() === '' && $(this).val() !== '') {
						title.val($(this).find('option:selected').text().replace(/\s+\(ID:.*$/, '').trim());
					}
				});

				tableBody.find('tr').each(function() {
					toggleRowPaymentMethod($(this));
				});
			});
		</script>
		<style>
			.payop-payment-buttons-wrap {
				margin-top: 12px;
				overflow-x: auto;
			}

			#payop-payment-buttons {
				min-width: 1000px;
			}

			#payop-payment-buttons th,
			#payop-payment-buttons td {
				padding: 12px 16px;
				vertical-align: middle;
			}

			#payop-payment-buttons input[type="text"],
			#payop-payment-buttons textarea,
			#payop-payment-buttons select {
				box-sizing: border-box;
				width: 100%;
			}
		</style>
		<?php

		return ob_get_clean();
	}

	/**
	 * Render one repeater row.
	 *
	 * @param int|string $index
	 * @param array      $button
	 * @param array      $method_options
	 * @return string
	 */
	private function get_payment_button_row_html($index, array $button, array $method_options)
	{
		$field_key = $this->get_field_key('payment_buttons') . '[' . $index . ']';
		$gateway_number = isset($button['gateway_number']) ? (string) $button['gateway_number'] : '';
		$enabled = isset($button['enabled']) && $button['enabled'] === 'yes';
		$title = isset($button['title']) ? (string) $button['title'] : '';
		$description = isset($button['description']) ? (string) $button['description'] : '';
		$integration_type = isset($button['integration_type']) && $button['integration_type'] === 'payment_method'
			? 'payment_method'
			: 'hosted_page';
		$payment_method = isset($button['payment_method']) ? self::sanitize_payment_method_id($button['payment_method']) : '';

		ob_start();
		?>
		<tr class="payop-payment-button-row">
			<td>
				<input type="hidden" name="<?php echo esc_attr($field_key . '[gateway_number]'); ?>" value="<?php echo esc_attr($gateway_number); ?>">
				<input type="hidden" name="<?php echo esc_attr($field_key . '[enabled]'); ?>" value="no">
				<input type="checkbox" name="<?php echo esc_attr($field_key . '[enabled]'); ?>" value="yes" <?php checked($enabled); ?>>
			</td>
			<td>
				<input type="text" class="input-text" name="<?php echo esc_attr($field_key . '[title]'); ?>" value="<?php echo esc_attr($title); ?>" required>
			</td>
			<td>
				<textarea name="<?php echo esc_attr($field_key . '[description]'); ?>" rows="2"><?php echo esc_textarea($description); ?></textarea>
			</td>
			<td>
				<select class="payop-button-integration-type" name="<?php echo esc_attr($field_key . '[integration_type]'); ?>">
					<option value="hosted_page" <?php selected($integration_type, 'hosted_page'); ?>><?php esc_html_e('Hosted Page', 'payop-woocommerce'); ?></option>
					<option value="payment_method" <?php selected($integration_type, 'payment_method'); ?>><?php esc_html_e('Hosted Page with Payment Method ID', 'payop-woocommerce'); ?></option>
				</select>
			</td>
			<td>
				<select class="payop-button-payment-method" name="<?php echo esc_attr($field_key . '[payment_method]'); ?>">
					<?php foreach ($method_options as $method_id => $method_title) : ?>
						<option value="<?php echo esc_attr($method_id); ?>" <?php selected($payment_method, (string) $method_id); ?>>
							<?php echo esc_html($method_title); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<button type="button" class="button-link-delete payop-remove-payment-button"><?php esc_html_e('Remove', 'payop-woocommerce'); ?></button>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Validate additional payment button settings.
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @return array
	 */
	public function validate_payment_buttons_field($key, $value)
	{
		if (!is_array($value)) {
			return [];
		}

		$buttons = [];
		$used_gateway_numbers = [1];
		$next_gateway_number = 2;

		foreach (array_slice($value, 0, PAYOP_MAX_GATEWAY_INSTANCES - 1) as $button) {
			if (!is_array($button)) {
				continue;
			}

			$gateway_number = isset($button['gateway_number']) ? absint($button['gateway_number']) : 0;
			if ($gateway_number < 2 || in_array($gateway_number, $used_gateway_numbers, true)) {
				while (in_array($next_gateway_number, $used_gateway_numbers, true)) {
					++$next_gateway_number;
				}
				$gateway_number = $next_gateway_number;
			}
			$used_gateway_numbers[] = $gateway_number;
			$next_gateway_number = max($next_gateway_number, $gateway_number + 1);

			$integration_type = isset($button['integration_type']) && $button['integration_type'] === 'payment_method'
				? 'payment_method'
				: 'hosted_page';
			$payment_method = isset($button['payment_method'])
				? self::sanitize_payment_method_id($button['payment_method'])
				: '';
			$title = isset($button['title']) ? sanitize_text_field(wp_unslash($button['title'])) : '';

			if ($title === '') {
				$this->add_error(__('Every additional Payop payment button must have a checkout name.', 'payop-woocommerce'));
				continue;
			}

			if ($integration_type === 'payment_method' && $payment_method === '') {
				$this->add_error(
					sprintf(
						__('Select a payment method for the “%s” Payop button.', 'payop-woocommerce'),
						$title
					)
				);
				continue;
			}

			$buttons[] = [
				'gateway_number' => $gateway_number,
				'enabled' => isset($button['enabled']) && $button['enabled'] === 'yes' ? 'yes' : 'no',
				'title' => $title,
				'description' => isset($button['description']) ? sanitize_textarea_field(wp_unslash($button['description'])) : '',
				'integration_type' => $integration_type,
				'payment_method' => $integration_type === 'payment_method' ? $payment_method : '',
			];
		}

		return $buttons;
	}

	/**
	 * Save and normalize gateway-specific settings.
	 *
	 * @return bool
	 */
	public function process_admin_options()
	{
		$saved = parent::process_admin_options();
		$settings = (array) get_option($this->get_option_key(), []);

		$settings['integration_type'] = isset($settings['integration_type']) && $settings['integration_type'] === 'payment_method'
			? 'payment_method'
			: 'hosted_page';
		$settings['payment_method'] = isset($settings['payment_method'])
			? self::sanitize_payment_method_id($settings['payment_method'])
			: '';

		if ($settings['integration_type'] === 'payment_method' && $settings['payment_method'] === '') {
			$this->add_error(
				__('Select a payment method when Hosted Page with Payment Method ID is selected. The primary Payop option will stay hidden at checkout until a method is selected.', 'payop-woocommerce')
			);
		}

		unset($settings['additional_gateways']);
		update_option($this->get_option_key(), $settings);
		$this->settings = $settings;

		return $saved;
	}

	/**
	 * Admin Panel Options.
	 *
	 * Options for bits like 'title' and availability on a country-by-country basis.
	 */
	public function admin_options()
	{
		$this->display_errors();
		?>
		<h3><?php echo esc_html($this->method_title); ?></h3>
		<p><?php echo esc_html($this->method_description); ?></p>

		<?php if (!$this->is_primary_gateway()) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: URL to the primary Payop gateway settings. */
					wp_kses_post(__('This option is configured in the <a href="%s">primary Payop gateway settings</a>.', 'payop-woocommerce')),
					esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=' . PAYOP_PAYMENT_GATEWAY_NAME))
				);
				?>
			</p>
			<?php
			return;
		endif;
		?>

		<table class="form-table">
			<?php
			// Generate the HTML For the settings form.
			$this->generate_settings_html();
			?>
		</table>
		<script>
			jQuery(function($) {
				const integrationType = $(<?php echo wp_json_encode('#' . $this->get_field_key('integration_type')); ?>);
				const paymentMethod = $(<?php echo wp_json_encode('#' . $this->get_field_key('payment_method')); ?>);
				const paymentMethodRow = paymentMethod.closest('tr');

				function togglePaymentMethodField() {
					const requiresPaymentMethod = integrationType.val() === 'payment_method';
					paymentMethodRow.toggle(requiresPaymentMethod);
					paymentMethod.prop('required', requiresPaymentMethod);
				}

				integrationType.on('change', togglePaymentMethodField);
				togglePaymentMethodField();
			});
		</script>
		<?php
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields()
	{
		$this->form_fields = include PAYOP_PLUGIN_PATH . '/includes/settings-payop.php';
	}

	/**
	 * Payment fields displayed on the checkout page.
	 */
	public function payment_fields()
	{
		if ($this->description) {
			echo wpautop(wptexturize($this->description));
		}
	}

	/**
	 * Empty the WooCommerce cart.
	 *
	 * This method can be used to clear the cart when needed.
	 */
	public function empty_cart()
	{
		WC()->cart->empty_cart();
	}

	/**
	 * Hide the 'pay' button for failed orders.
	 *
	 * @param array $actions The list of actions.
	 * @param object $order The order object.
	 * @return array Modified list of actions.
	 */
	public function hide_pay_button_for_failed_orders( $actions, $order )
	{
		if ($order->get_status() === 'failed' && $this->order_uses_payop($order)) {
			unset( $actions['pay'] );
		}

		return $actions;
	}

	/**
	 * Modify the content of the WooCommerce order confirmation status block.
	 *
	 * @param string $block_content The content of the block.
	 * @param array $block The block data.
	 * @return string Modified block content.
	 */
	public function modify_wc_order_confirmation_block_content($block_content, $block)
	{
		if ($block['blockName'] === 'woocommerce/order-confirmation-status') {
			$pattern = '/<a[^>]*\bhref="([^"]*?pay_for_order=true[^"]*)"[^>]*>.*?<\/a>/i';

			if (preg_match($pattern, $block_content, $matches)) {
				$block_content = preg_replace($pattern, '', $block_content);
			}
		}

		return $block_content;
	}
}

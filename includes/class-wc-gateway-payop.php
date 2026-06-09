<?php
/**
 * WooCommerce Payop Payment Gateway.
 *
 * @extends WC_Payment_Gateway
 * @version 1.0.7
 */

if (!defined('ABSPATH')) {
	exit;
}

class WC_Gateway_Payop extends WC_Payment_Gateway {

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

	public function __construct()
	{
		$this->api_url = 'https://api.payop.com/v1/invoices/create';

		$this->id = PAYOP_PAYMENT_GATEWAY_NAME;
		$this->icon = apply_filters('woocommerce_payop_icon', '' . PAYOP_PLUGIN_URL . '/payop.png');

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->title = $this->get_option('title');
		$this->public_key = $this->get_option('public_key');
		$this->secret_key = $this->get_option('secret_key');
		$this->skip_confirm = $this->get_option('skip_confirm');
		$this->lifetime = $this->get_option('lifetime');
		$this->auto_complete = $this->get_option('auto_complete');
		$this->language = 'en';
		$this->description = $this->get_option('description');
		$this->instructions = $this->get_option('instructions');
		$this->detailed_logging = $this->get_option('detailed_logging', 'no');

		//Actions
		add_action('payop-ipn-request', [$this, 'successful_request']);
		// Keep the hosted payment form only on the order-pay page to avoid reopening it after browser redirects.
		add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);

		add_filter( 'woocommerce_order_needs_payment', [$this, 'prevent_payment_for_failed_orders'], 10, 3 );

		// hide buttons "Buy again"
		add_action('woocommerce_my_account_my_orders_actions', [$this, 'hide_pay_button_for_failed_orders'], 10, 2);
		add_filter('render_block', [$this, 'modify_wc_order_confirmation_block_content'], 10, 2);

		//Payment listner/API hook
		add_action('woocommerce_api_wc_' . $this->id, [$this, 'check_ipn_response']);
		add_action('payop_check_abandoned_payment', [$this, 'check_abandoned_payment']);

		//Save options
		add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

		if (!$this->is_valid_for_use()) {
			$this->enabled = false;
		}
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

			$invoice_details = [
				'order_id' => $order->get_id(),
				'amount' => $out_summ,
				'currency' => $currency,
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
			$order->add_meta_data(PAYOP_INVITATE_RESPONSE, $response);
			$order->add_meta_data(PAYOP_INVOICE_ID_META, $response);
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

		$context = ['source' => PAYOP_PAYMENT_GATEWAY_NAME];
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
		if (!$order instanceof WC_Order || $order->get_payment_method() !== PAYOP_PAYMENT_GATEWAY_NAME) {
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

		return hash_hmac(PAYOP_HASH_ALGORITHM, $message, wp_salt('auth') . '|' . PAYOP_PAYMENT_GATEWAY_NAME);
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
		if ($order->get_payment_method() !== PAYOP_PAYMENT_GATEWAY_NAME) {
			$this->log_payop('error', 'Payop request failed: payment method mismatch', [
				'order_id' => $order->get_id(),
				'payment_method' => $order->get_payment_method(),
			], $order);
			wp_die('Payment method mismatch', 'Forbidden', 403);
		}
		// Ensure this order actually created a Payop invoice via this plugin.
		if (!$order->get_meta(PAYOP_INVOICE_ID_META)) {
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
			$state = $posted_data['transaction']['state'];
			$order_id = $this->extract_order_id_from_request($posted_data);
			$order = $this->get_payop_order_or_die($order_id);

			$wc_status = $this->map_status_to_wc($state);
			if (!$wc_status) {
				$details = array_merge(['reason' => 'Unknown status'], $this->get_payop_request_summary($posted_data));
				$this->add_payop_order_note($order, __('Payop IPN processing failed', 'payop-woocommerce'), $details);
				$this->log_payop('error', 'Payop IPN processing failed', $details, $order);
				wp_die('Unknown status', 'Unknown status', 400);
			}

			// Bind txid to the order the first time we see it.
			if (!empty($posted_data['invoice']['txid'])) {
				$order->update_meta_data(PAYOP_TXID_META, sanitize_text_field((string) $posted_data['invoice']['txid']));
				$order->save();
			}

			$details = array_merge(['ipn_version' => PAYOP_IPN_VERSION_V2], $this->get_payop_request_summary($posted_data));
			$this->add_payop_order_note($order, __('Payop IPN validation passed', 'payop-woocommerce'), $details);
			if (in_array((int) $state, [1, 4], true)) {
				$this->add_payop_order_note($order, __('Payop payment pending', 'payop-woocommerce'), $details);
				$this->log_payop('info', 'Payop payment pending', $details, $order);
			} elseif ((int) $state === 2) {
				$this->add_payop_order_note($order, __('Payop payment paid', 'payop-woocommerce'), $details);
				$this->log_payop('info', 'Payop payment paid', $details, $order);
			} elseif (in_array((int) $state, [3, 5, 15], true)) {
				$this->add_payop_order_note($order, __('Payop payment failed', 'payop-woocommerce'), $details);
				$this->log_payop('warning', 'Payop payment failed', $details, $order);
			} elseif ((int) $state === 9) {
				$this->add_payop_order_note($order, __('Payop payment pre-approved by provider', 'payop-woocommerce'), $details);
				$this->log_payop('info', 'Payop payment pre-approved by provider', $details, $order);
			}

			if (!$this->update_payop_order_status($order, $wc_status, __('Transaction status updated', 'payop-woocommerce'), $details)) {
				wp_die('Status update failed', 'Status update failed', 500);
			}
			do_action('payop-ipn-request', $posted_data);
			wp_die('Status updated', 'Status updated', 200);
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
		if ( $order->has_status( 'failed' ) && $order->get_payment_method() === PAYOP_PAYMENT_GATEWAY_NAME ) {
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
		$details = [
			'order_id' => $order_id,
			'amount' => $order ? number_format($order->get_total(), 4, '.', '') : '',
			'currency' => $order ? $order->get_currency() : '',
			'redirect' => $order ? $order->get_checkout_payment_url(true) : '',
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
				if ($order->get_payment_method() !== PAYOP_PAYMENT_GATEWAY_NAME) {
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
		if ($order->get_payment_method() !== PAYOP_PAYMENT_GATEWAY_NAME) {
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

		// Bind invoice id: must match what we created for this order.
		$expected_invoice_id = (string) $order->get_meta(PAYOP_INVOICE_ID_META);
		if ($expected_invoice_id === '') {
			return 'Missing stored invoice id';
		}
		if ((string) $invoice_id !== $expected_invoice_id) {
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
		// This hook is triggered after a valid IPN. Keep it defensive.
		$posted = is_array($posted) ? $posted : [];
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
			'sslverify' => false,
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
	 * Admin Panel Options.
	 *
	 * Options for bits like 'title' and availability on a country-by-country basis.
	 */
	public function admin_options()
	{
		?>
		<h3><?php _e('Payop', 'payop-woocommerce'); ?></h3>
		<p><?php _e('Take payments via Payop.', 'payop-woocommerce'); ?></p>

		<?php if ($this->is_valid_for_use()) : ?>

			<table class="form-table">
				<?php
				// Generate the HTML For the settings form.
				$this->generate_settings_html();
				?>
			</table>

		<?php
		endif;
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
		if ( $order->get_status() === 'failed' ) {
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

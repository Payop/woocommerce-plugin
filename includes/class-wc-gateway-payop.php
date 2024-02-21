<?php
/**
 * WooCommerce Payop Payment Gateway.
 *
 * @extends WC_Payment_Gateway
 * @version 1.0.1
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
	public $apiUrl;

	/**
	 * JSON Web Token (JWT) token for authentication with Payop API.
	 *
	 * @var string
	 */
	public $jwt_token;

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
	 * Selected payment method.
	 *
	 * @var string
	 */
	public $payment_method;

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

	public function __construct() {
		global $woocommerce;

		$this->apiUrl = 'https://payop.com/v1/invoices/create';

		$this->id = PAYOP_PAYMENT_GATEWAY_NAME;
		$this->icon = apply_filters('woocommerce_payop_icon', '' . PAYOP_PLUGIN_URL . '/payop.png');

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->title = $this->get_option('title');
		$this->public_key = $this->get_option('public_key');
		$this->jwt_token = $this->get_option('jwt_token');
		$this->secret_key = $this->get_option('secret_key');
		$this->skip_confirm = $this->get_option('skip_confirm');
		$this->lifetime = $this->get_option('lifetime');
		$this->auto_complete = $this->get_option('auto_complete');
		$this->payment_method = $this->get_option('payment_method');
		$this->language = $this->get_option('payment_form_language');
		$this->description = $this->get_option('description');
		$this->instructions = $this->get_option('instructions');

		//Actions
		add_action('payop-ipn-request', [$this, 'successful_request']);
		add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);
		add_action('woocommerce_thankyou_' . $this->id, [$this, 'receipt_page']);

		add_filter( 'woocommerce_order_needs_payment', [$this, 'prevent_payment_for_failed_orders'], 10, 3 );

		// hide buttons "Buy again"
		add_action('woocommerce_my_account_my_orders_actions', [$this, 'hide_pay_button_for_failed_orders'], 10, 2);
		add_filter('render_block', [$this, 'modify_wc_order_confirmation_block_content'], 10, 2);

		//Payment listner/API hook
		add_action('woocommerce_api_wc_' . $this->id, [$this, 'check_ipn_response']);

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
			wc_empty_cart();
			wc_clear_cart_after_payment();
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
		global $woocommerce;

		$order = wc_get_order($order_id);

		$response = $order->get_meta(PAYOP_INVITATE_RESPONSE);
		if ( !$response ) {
			$out_summ = number_format($order->get_total(), 4, '.', '');

			$paymentMethods = $this->get_payments_methods_options(false);
			$arrData = [];
			$arrData['publicKey'] = $this->public_key;
			$arrData['order'] = [];
			$arrData['order']['id'] = strval($order_id);
			$arrData['order']['amount'] = $out_summ;
			$arrData['order']['currency'] = $order->get_currency();

			$orderInfo = [
				'id' => $order_id,
				'amount' => $out_summ,
				'currency' => $order->get_currency()
			];

			ksort($orderInfo, SORT_STRING);
			$dataSet = array_values($orderInfo);
			$dataSet[]  = $this->secret_key;
			$arrData['signature'] = hash('sha256', implode(':', $dataSet));

			if ($paymentMethods && in_array($this->payment_method, $paymentMethods)) {
				$arrData['paymentMethod'] = $this->payment_method;
			}

			$arrData['order']['description'] = __('Payment order #', 'payop-woocommerce') . $order_id;
			$arrData['order']['items'] = [];
			$arrData['payer']['email'] = $order->get_billing_email();
			$arrData['payer']['name'] = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

			if ($order->get_billing_phone()) {
				$arrData['payer']['phone'] = $order->get_billing_phone();
			}

			$arrData['language'] = $this->language;

			$arrData['resultUrl'] = get_site_url() . "/?wc-api=wc_payop&payop=success&orderId={$order_id}";

			$arrData['failPath'] = get_site_url() . "/?wc-api=wc_payop&payop=fail&orderId={$order_id}";

			$response = $this->apiRequest($arrData, 'identifier');

			$order->add_meta_data(PAYOP_INVITATE_RESPONSE, $response);
			$order->save_meta_data();
		}

		if(isset($response['messages'])) {
			return '<p>' . __('Request to payment service was sent incorrectly', 'payop-woocommerce') . '</p><br><p>' . $response['messages'] .'</p>';
		}

		$action_adr = 'https://payop.com/' . $this->language . '/payment/invoice-preprocessing/' . $response;

		if ($this->skip_confirm === "yes"){
			wp_redirect(esc_url($action_adr));
			exit;
		}

		$args_array = [];

		return '<form action="' . esc_url($action_adr) . '" method="GET" id="payop_payment_form">' . "\n" .
			implode("\n", $args_array) .
			'<input type="submit" class="button alt" id="submit_payop_payment_form" value="' . __('Pay', 'payop-woocommerce') . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Refuse payment & return to cart', 'payop-woocommerce') . '</a>' . "\n" .
		'</form>';
	}

	/**
	 * Check Payop IPN response and take appropriate actions.
	 */
	public function check_ipn_response()
	{
		global $woocommerce;

		$requestType = !empty($_GET['payop']) ? $_GET['payop'] : '';

		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			$postedData = json_decode(file_get_contents('php://input'), true);
			if (!is_array($postedData)) {
				$postedData = [];
			}
		} else {
			$postedData = $_GET;
		}

		switch ($requestType) {
			case 'result':
				@ob_clean();

				$postedData = wp_unslash($postedData);
				$valid = $this->check_ipn_request_is_valid($postedData);
				if ($valid === 'V2'){
					if ($postedData['transaction']['state'] === 4) {
						wp_die('Status wait', 'Status wait', 200);
					}
					$orderId = $postedData['transaction']['order']['id'];
					$order = wc_get_order($orderId);
					if ($postedData['transaction']['state'] === 2) {
						if ($this->auto_complete === 'yes') {
							$order->update_status('completed', __('Payment successfully paid', 'payop-woocommerce'));
						} else {
							$order->update_status('processing', __('Payment successfully paid', 'payop-woocommerce'));
						}
						wp_die('Status success', 'Status success', 200);
					} elseif ($postedData['transaction']['state'] === 3 or $postedData['transaction']['state'] === 5) {
						$order->update_status('failed', __('Payment not paid', 'payop-woocommerce'));
						wp_die('Status fail', 'Status fail', 200);
					}
					do_action('payop-ipn-request', $postedData);
				} elseif ($valid = 'V1') {
					if ($postedData['status'] === 'wait') {
						wp_die('Status wait', 'Status wait', 200);
					}
					$orderId = $postedData['orderId'];
					$order = wc_get_order($orderId);

					if ($postedData['status'] === 'success') {
						if ($this->auto_complete === 'yes') {
							$order->update_status('completed', __('Payment successfully paid', 'payop-woocommerce'));
						} else {
							$order->update_status('processing', __('Payment successfully paid', 'payop-woocommerce'));
						}
						wp_die('Status success', 'Status success', 200);
					} elseif ($postedData['status'] === 'error') {
						$order->update_status('failed', __('Payment not paid', 'payop-woocommerce'));
						wp_die('Status fail', 'Status fail', 200);
					}
					do_action('payop-ipn-request', $postedData);
				} else {
					wp_die($valid, $valid, 400);
				}
				break;
			case 'success':
				$orderId = isset($postedData['transaction']['order']['id']) ? $postedData['transaction']['order']['id'] : $postedData['orderId'];
				$order = wc_get_order($orderId);

				$order->payment_complete();

				wc_empty_cart();

				wp_redirect($this->get_return_url($order));
				break;
			case 'fail':
				$orderId = isset($postedData['transaction']['order']['id']) ? $postedData['transaction']['order']['id'] : $postedData['orderId'];
				$order = wc_get_order($orderId);

				$order->update_status('failed', __('Payment not paid', 'payop-woocommerce'), true);

				wc_empty_cart();

				wp_redirect($this->get_return_url($order));
				break;
			default:
				wp_die('Invalid request', 'Invalid request', 400);
		}
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
	public function prevent_payment_for_failed_orders( $needs_payment, $order, $valid_order_statuses ) {
		if ( $order->has_status( 'failed' ) && $order->get_payment_method() === PAYOP_PAYMENT_GATEWAY_NAME ) {
			$needs_payment = false;
		}

		return $needs_payment;
	}

	/**
	 * Get available payment methods for Directpay.
	 *
	 * @param bool $default Flag indicating if default options should be included.
	 *
	 * @return array
	 */
	private function get_payments_methods_options( $default )
	{
		$public_key = $this->get_option('public_key');
		$request_url = 'https://payop.com/v1/instrument-settings/payment-methods/available-for-application/'. str_replace('application-', '', $public_key);
		$methodOptions = '';

		if ($default) {
			$methodOptions = array('none' => __('None direct pay', 'woocommerce'));
		}

		$arrData['jwt_token'] = $this->get_option('jwt_token');
		$args = array(
			'timeout' => 10,
			'sslverify' => false,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Authorization' => 'Bearer ' . $arrData['jwt_token']
			),
		);
		if (!empty($arrData['jwt_token'])) {
			$response = wp_remote_get($request_url, $args);
			$response = wp_remote_retrieve_body($response);
			$response = json_decode($response, true);
		}
		if (!empty($response['data'])) {
			$this->valid_token = true;
			foreach ($response['data'] as $item) {
				if ($default) {
					$methodOptions[$item['identifier']] = __($item['title'], 'woocommerce');
				} else {
					$methodOptions[] = $item['identifier'];
				}
			}
		}
		return $methodOptions;

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

		wc_empty_cart();

		// Return thankyou redirect.
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
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
		$invoiceId = !empty($posted['invoice']['id']) ? $posted['invoice']['id'] : null;
		$txId = !empty($posted['invoice']['txid']) ? $posted['invoice']['txid'] : null;
		$orderId = !empty($posted['transaction']['order']['id']) ? $posted['transaction']['order']['id'] : null;
		$signature = !empty($posted['signature']) ? $posted['signature'] : null;
		// check IPN V1
		if (!$invoiceId) {
			if (!$signature) {
				return 'Empty invoice id';
			} else {
				$orderId = !empty($posted['orderId']) ? $posted['orderId'] : null;
				if (!$orderId) {
					return 'Empty order id V1';
				}
				$order = wc_get_order($orderId);
				$currency = $order->get_currency();
				$amount = number_format($order->get_total(), 4, '.', '');

				$status = $posted['status'];

				if ($status !== 'success' && $status !== 'error') {
					return 'Status is not valid';
				}

				$o = ['id' => $orderId, 'amount' => $amount, 'currency' => $currency];

				ksort($o, SORT_STRING);

				$dataSet = array_values($o);

				if ($status) {
					array_push($dataSet, $status);
				}

				array_push($dataSet, $this->secret_key);

				if ($posted['signature'] === hash('sha256', implode(':', $dataSet))) {
					return 'V1';
				}
				return 'Invalid signature';
			}
		}
		if (!$txId) {
			return 'Empty transaction id';
		}
		if (!$orderId) {
			return 'Empty order id V2';
		}

		$order = wc_get_order($orderId);
		$currency = $order->get_currency();
		$state = $posted['transaction']['state'];
		if (!(1 <= $state && $state <= 5)) {
			return 'State is not valid';
		}
		return 'V2';
	}

	/**
	 * Handle successful IPN request.
	 *
	 * @param array $posted Data received from Payop IPN.
	 */
	public function successful_request( $posted )
	{
		global $woocommerce;

		$orderId = isset($posted['transaction']['order']['id']) ? $posted['transaction']['order']['id'] : $posted['orderId'];

		$order = wc_get_order($orderId);

		// Check order not already completed
		if ($order->status == 'completed') {
			exit;
		}

		// Payment completed
		$order->add_order_note(__('Payment completed successfully', 'payop-woocommerce'));

		$order->payment_complete();

		exit;
	}

	/**
	 * Make an API request to Payop.
	 *
	 * @param array  $arrData		  Data to be sent in the request.
	 * @param string $retrieved_header Retrieved header.
	 *
	 * @return mixed
	 */
	public function apiRequest( $arrData = [], $retrieved_header = '')
	{

		$request_url = $this->apiUrl;
		$args = array(
			'sslverify' => false,
			'timeout' => 45,
			'headers' => array(
				'Content-Type' => 'application/json'
			),
			'body' => json_encode($arrData),
		);
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
		global $woocommerce;

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

		<?php else : ?>
			<div class="inline error">
				<p>
					<strong><?php _e('Gateway is disabled', 'payop-woocommerce'); ?></strong>:
					<?php _e('Payop does not support the currency of your store.', 'payop-woocommerce'); ?>
				</p>
			</div>
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
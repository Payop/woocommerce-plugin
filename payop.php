<?php
/*
Plugin Name: Payop
Plugin URI: https://wordpress.org/plugins/payop-woocommerce/
Description: PayOp: Online payment processing service ➦ Accept payments online by 150+ methods from 170+ countries. Payments gateway for Growing Your Business in New Locations and fast online payments
Author URI: https://payop.com/
Version: 1.0.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Domain Path: /languages
*/


if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'woocommerce_payop', 0);

function woocommerce_payop()
{
    load_plugin_textdomain('payop-woocommerce', false, plugin_basename(dirname(__FILE__)) . '/languages');

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    } // if the WC payment gateway class is not available, do nothing
    if (class_exists('WC_Payop')) {
        return;
    }

    class WC_Payop extends WC_Payment_Gateway
    {
        public function __construct()
        {
            global $woocommerce;

            $plugin_dir = plugin_dir_url(__FILE__);

            $this->apiUrl = 'https://PayOp.com/api/v1.1/payments/payment';

            $this->id = 'payop';

            $this->icon = apply_filters('woocommerce_payop_icon', '' . $plugin_dir . 'payop.png');
            // Load the settings
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->public_key = $this->get_option('public_key');
            $this->secret_key = $this->get_option('secret_key');
            $this->lifetime = $this->get_option('lifetime');
            $this->language = $this->get_option('payment_form_language');
            $this->testmode = $this->get_option('testmode');

            $this->description = $this->get_option('description');
            $this->instructions = $this->get_option('instructions');

            // Actions
            add_action('valid-payop-standard-ipn-reques', [$this, 'successful_request']);
            add_action('woocommerce_receipt_' . $this->id, [$this, 'receipt_page']);

            // Payment listener/API hook
            add_action('woocommerce_api_wc_' . $this->id, [$this, 'check_ipn_response']);

            // Save options
            add_action('woocommerce_update_options_payment_gateways_payop', [$this, 'process_admin_options']);

            if (!$this->is_valid_for_use()) {
                $this->enabled = false;
            }
        }

        /**
         * Check if this gateway is enabled and available in the user's country
         */
        public function is_valid_for_use()
        {
            return true;
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         *
         * @since 0.1
         **/
        public function admin_options()
        {
            global $woocommerce;

            ?>
            <h3><?php _e('PayOp', 'payop-woocommerce'); ?></h3>
            <p><?php _e('Take payments via PayOp.', 'payop-woocommerce'); ?></p>

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
                    <?php _e('PayOp does not support the currency of your store.', 'payop-woocommerce'); ?>
                </p>
            </div>
        <?php
        endif;

        } // End admin_options()

        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */
        public function init_form_fields()
        {
            global $woocommerce;

            $this->form_fields = [
                'enabled' => [
                    'title' => __('Enable PayOp payments', 'payop-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable/Disable', 'payop-woocommerce'),
                    'default' => 'yes',
                ],
                'title' => [
                    'title' => __('Name of payment gateway', 'payop-woocommerce'),
                    'type' => 'text',
                    'description' => __('The name of the payment gateway that the user see when placing the order', 'payop-woocommerce'),
                    'default' => __('PayOp', 'payop-woocommerce'),
                ],
                'public_key' => [
                    'title' => __('Public key', 'payop-woocommerce'),
                    'type' => 'text',
                    'description' => __('Issued in the client panel https://payop.com', 'payop-woocommerce'),
                    'default' => '',
                ],
                'secret_key' => [
                    'title' => __('Secret key', 'payop-woocommerce'),
                    'type' => 'text',
                    'description' => __('Issued in the client panel https://payop.com', 'payop-woocommerce'),
                    'default' => '',
                ],
                'description' => [
                    'title' => __('Description', 'payop-woocommerce'),
                    'type' => 'textarea',
                    'description' => __(
                        'Description of the payment gateway that the client will see on your site.',
                        'payop-woocommerce'
                    ),
                    'default' => __('Accept online payments using PayOp.com', 'payop-woocommerce'),
                ],
                'auto_complete' => [
                    'title' => __('Order completion', 'payop-woocommerce'),
                    'type' => 'checkbox',
                    'label' => __(
                        'Automatic transfer of the order to the status "Completed" after successful payment',
                        'payop-woocommerce'
                    ),
                    'description' => __('', 'payop-woocommerce'),
                    'default' => '1',
                ],
                'payment_form_language' => [
                    'title' => __('Payment form language', 'payop-woocommerce'),
                    'type' => 'select',
                    'description' => __('Select the language of the payment form for your store', 'payop-woocommerce'),
                    'default' => 'en',
                    'options' => [
                        'en' => __('English', 'payop-woocommerce'),
                        'ru' => __('Russian', 'payop-woocommerce'),
                    ],
                ],
            ];
        }

        /**
         * Дополнительная информация в форме выбора способа оплаты
         **/
        public function payment_fields()
        {
            if ($this->description) {
                echo wpautop(wptexturize($this->description));
            }
        }

        /**
         * Generate the dibs button link
         **/
        public function generate_form($order_id)
        {
            global $woocommerce;

            $order = new WC_Order($order_id);

            $out_summ = number_format($order->order_total, 4, '.', '');

            $arrData = [];

            $arrData['publicKey'] = $this->public_key;

            $arrData['order'] = [];

            $arrData['order']['id'] = $order_id;

            $arrData['order']['amount'] = $out_summ;

            $arrData['order']['currency'] = get_option('woocommerce_currency');

            $o = ['id' => $order_id, 'amount' => $out_summ, 'currency' => get_option('woocommerce_currency')];

            ksort($o, SORT_STRING);

            $dataSet = array_values($o);

            array_push($dataSet, $this->secret_key);

            $arrData['signature'] = hash('sha256', implode(':', $dataSet));

            $arrData['order']['description'] = __('Payment order #', 'payop-woocommerce') . $order_id;

            $arrData['customer']['email'] = $order->get_billing_email();

            $arrData['language'] = $this->language;

            $arrData['resultUrl'] = get_site_url() . "/?wc-api=wc_payop&payop=success&orderId={$order_id}";

            $arrData['failUrl'] = get_site_url() . "/?wc-api=wc_payop&payop=fail&orderId={$order_id}";

            $response = $this->apiRequest($arrData);

            if ((isset($response['errors']) and count($response['errors'])) or !isset($response['data']['redirectUrl'])) {
                return '<p>' . __('Request to payment service was sent incorrectly', 'payop-woocommerce') . '</p>';
            }

            $action_adr = $response['data']['redirectUrl'];

            $args_array = [];

            return '<form action="' . esc_url($action_adr) . '" method="GET" id="payop_payment_form">' . "\n" .
                implode("\n", $args_array) .
                '<input type="submit" class="button alt" id="submit_payop_payment_form" value="' . __('Pay', 'payop-woocommerce') . '" /> <a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Refuse payment & return to cart', 'payop-woocommerce') . '</a>' . "\n" .
                '</form>';
        }

        /**
         * Process the payment and return the result
         **/
        public function process_payment($order_id)
        {
            $order = new WC_Order($order_id);

            return [
                'result' => 'success',
                'redirect' => add_query_arg('order', $order->id,
                    add_query_arg('key', $order->order_key, get_permalink(wc_get_page_id('pay')))),
            ];
        }

        /**
         * receipt_page
         **/
        public function receipt_page($order)
        {
            echo '<p>' . __('Thank you for your order, please click the button below to pay', 'payop-woocommerce') . '</p>';

            echo $this->generate_form($order);
        }

        /**
         * Check PayOp IPN validity
         *
         * @param array $posted
         *
         * @return bool|string
         */
        public function check_ipn_request_is_valid($posted)
        {
            $orderId = !empty($posted['orderId']) ? $posted['orderId'] : null;
            if (!$orderId) {
                return 'empty order id';
            }

            $order = new WC_Order($orderId);
            $currency = $order->get_currency();
            $amount = number_format($order->get_total(), 4, '.', '');

            $status = $posted['status'];

            if ($status !== 'success') {
                return 'status is not success';
            }

            $o = ['id' => $orderId, 'amount' => $amount, 'currency' => $currency];

            ksort($o, SORT_STRING);

            $dataSet = array_values($o);

            if ($status) {
                array_push($dataSet, $status);
            }

            array_push($dataSet, $this->secret_key);

            if ($posted['signature'] === hash('sha256', implode(':', $dataSet))) {
                return true;
            }

            return 'invalid signature';
        }

        /**
         * Check Response
         **/
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

                    $postedData = stripslashes_deep($postedData);

                    $valid = $this->check_ipn_request_is_valid($postedData);
                    if ($valid === true) {
                        do_action('valid-payop-standard-ipn-reques', $postedData);
                    } else {
                        wp_die($valid, $valid, 400);
                    }
                    break;
                case 'success':
                    $orderId = $postedData['orderId'];

                    $order = new WC_Order($orderId);

                    $order->update_status('processing', __('Payment successfully paid', 'payop-woocommerce'));

                    WC()->cart->empty_cart();

                    wp_redirect($this->get_return_url($order));
                    break;
                case 'fail':
                    $orderId = $postedData['orderId'];

                    $order = new WC_Order($orderId);

                    $order->update_status('failed', __('Payment not paid', 'payop-woocommerce'));

                    wp_redirect($order->get_cancel_order_url_raw());
                    break;
                default:
                    wp_die('Invalid request', 'Invalid request', 400);
            }
        }

        /**
         * Successful Payment!
         **/
        public function successful_request($posted)
        {
            global $woocommerce;

            $orderId = $posted['orderId'];

            $order = new WC_Order($orderId);

            // Check order not already completed
            if ($order->status == 'completed') {
                exit;
            }

            // Payment completed
            $order->add_order_note(__('Payment completed successfully', 'payop-woocommerce'));

            $order->payment_complete();

            exit;
        }

        public function apiRequest($arrData = [])
        {
            $request_url = $this->apiUrl;

            $args = array(
                'sslverify' => false,
                'headers' => array(
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($arrData),
            );

            $response = wp_remote_post($request_url, $args);

            $response = wp_remote_retrieve_body($response);

            return json_decode($response, true);
        }
    }

    /**
     * Add the gateway to WooCommerce
     **/
    function add_payop_gateway($methods)
    {
        $methods[] = 'WC_Payop';

        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_payop_gateway');
}

?>

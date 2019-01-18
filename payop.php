<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin Name: Payop-woocommerce-plugin
 * Plugin URI: https://payop.com/
 * Description: Проведение платежей через PayOp
 * Version: 1.0.0
 */

add_action('plugins_loaded', 'woocommerce_payop', 0);

function woocommerce_payop()
{
    load_plugin_textdomain('payop-woocommerce', false, plugin_basename(dirname(__FILE__)).'/languages');

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

            $this->icon = apply_filters('woocommerce_payop_icon', ''.$plugin_dir.'payop.svg');
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
            add_action('woocommerce_receipt_'.$this->id, [$this, 'receipt_page']);

            // Payment listener/API hook
            add_action('woocommerce_api_wc_'.$this->id, [$this, 'check_ipn_response']);

            // Save options
            add_action('woocommerce_update_options_payment_gateways_payop', [$this, 'process_admin_options']);

            if (!$this->is_valid_for_use()) {
                $this->enabled = false;
            }
        }

        /**
         * Check if this gateway is enabled and available in the user's country
         */
        function is_valid_for_use()
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
            ?>
            <h3><?php _e('PayOp', 'payop-woocommerce'); ?></h3>
            <p><?php _e('Настройка приема электронных платежей через PayOp.', 'payop-woocommerce'); ?></p>

            <?php if ($this->is_valid_for_use()) : ?>

            <table class="form-table">
                <?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html();
                ?>
            </table>

        <?php else : ?>
            <div class="inline error"><p>
                    <strong><?php _e('Шлюз отключен',
                            'payop-woocommerce'); ?></strong>: <?php _e('PayOp не поддерживает валюты Вашего магазина.',
                        'payop-woocommerce'); ?>
                </p></div>
        <?php
        endif;

        } // End admin_options()

        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */
        function init_form_fields()
        {
            $this->form_fields = [
                'enabled'               => [
                    'title'   => __('Активность способа оплаты', 'payop-woocommerce'),
                    'type'    => 'checkbox',
                    'label'   => __('Активен', 'payop-woocommerce'),
                    'default' => 'yes',
                ],
                'title'                 => [
                    'title'       => __('Название способа оплаты', 'payop-woocommerce'),
                    'type'        => 'text',
                    'description' => __('Название способа оплаты, которое увидит пользователь при оформлении заказа', 'woocommerce'),
                    'default'     => __('PayOp', 'payop-woocommerce'),
                ],
                'public_key'            => [
                    'title'       => __('Публичный ключ', 'payop-woocommerce'),
                    'type'        => 'text',
                    'description' => __('Выдается в Личном кабинете https://payop.com', 'payop-woocommerce'),
                    'default'     => '',
                ],
                'secret_key'            => [
                    'title'       => __('Секретный ключ', 'payop-woocommerce'),
                    'type'        => 'text',
                    'description' => __('Выдается в Личном кабинете https://payop.com', 'payop-woocommerce'),
                    'default'     => '',
                ],
                'description'           => [
                    'title'       => __('Description', 'payop-woocommerce'),
                    'type'        => 'textarea',
                    'description' => __('Описание способа оплаты, которое клиент будет видеть на вашем сайте.',
                        'payop-woocommerce'),
                    'default'     => 'Оплата через payop.com',
                ],
                'auto_complete'         => [
                    'title'       => __('Автозавершение заказа', 'payop-woocommerce'),
                    'type'        => 'checkbox',
                    'label'       => __('Автоматический перевод заказа в статус "Выполнен" после успешной оплаты',
                        'payop-woocommerce'),
                    'description' => __('', 'payop-woocommerce'),
                    'default'     => '1',
                ],
                'payment_form_language' => [
                    'title'       => __('Язык платежной формы', 'payop-woocommerce'),
                    'type'        => 'select',
                    'description' => __('Выберите язык платежной формы для Вашего магазина', 'payop-woocommerce'),
                    'default'     => 'ru',
                    'options'     => [
                        'ru' => __('Русский', 'payop-woocommerce'),
                        'en' => __('Английский', 'payop-woocommerce'),
                    ],
                ],
            ];
        }

        /**
         * Дополнительная информация в форме выбора способа оплаты
         **/
        function payment_fields()
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

            $arrData['order']['description'] = __('Оплата заказа #', 'payop-woocommerce').$order_id;

            $arrData['customer']['email'] = $order->get_billing_email();

            $arrData['language'] = $this->language;

            $arrData['resultUrl'] = get_site_url().'/?wc-api=wc_payop&payop=success';

            $arrData['failUrl'] = get_site_url().'/?wc-api=wc_payop&payop=fail';

            $response = $this->apiRequest($arrData);

            if ((isset($response['errors']) and count($response['errors'])) or !isset($response['data']['redirectUrl'])) {
                return '<p>'.__('Запрос к платежному сервису был отправлен некорректно', 'payop-woocommerce').'</p>';
            }

            $action_adr = $response['data']['redirectUrl'];

            $args_array = [];

            return '<form action="'.esc_url($action_adr).'" method="GET" id="payop_payment_form">'."\n".
                implode("\n", $args_array).
                '<input type="submit" class="button alt" id="submit_payop_payment_form" value="'.__('Оплатить', 'payop-woocommerce').'" /> <a class="button cancel" href="'.$order->get_cancel_order_url().'">'.__('Отказаться от оплаты & вернуться в корзину', 'payop-woocommerce').'</a>'."\n".
                '</form>';
        }

        /**
         * Process the payment and return the result
         **/
        function process_payment($order_id)
        {
            $order = new WC_Order($order_id);

            return [
                'result'   => 'success',
                'redirect' => add_query_arg('order', $order->id,
                    add_query_arg('key', $order->order_key, get_permalink(wc_get_page_id('pay')))),
            ];
        }

        /**
         * receipt_page
         **/
        function receipt_page($order)
        {
            echo '<p>'.__('Спасибо за Ваш заказ, пожалуйста, нажмите кнопку ниже, чтобы заплатить.', 'payop-woocommerce').'</p>';

            echo $this->generate_form($order);
        }

        /**
         * Check PayOp IPN validity
         **/
        function check_ipn_request_is_valid($posted)
        {
            $orderId = !empty($posted['orderId']) ? $posted['orderId'] : null;
            if (!$orderId) {
                return 'empty order id';
            }

            $order = new WC_Order($orderId);
            $currency = $order->get_currency();
            $amount = number_format($order->get_total(), 4, '.', '');;

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

            return "invalid signature: {$orderId} || {$amount} || {$currency} || $status || {$this->secret_key}";
        }

        /**
         * Check Response
         **/
        function check_ipn_response()
        {
            global $woocommerce;

            if (isset($_REQUEST['payop']) AND $_REQUEST['payop'] == 'result') {
                @ob_clean();

                $_REQUEST = stripslashes_deep($_REQUEST);

                $error = $this->check_ipn_request_is_valid($_REQUEST);
                if ($error === true) {
                    do_action('valid-payop-standard-ipn-reques', $_REQUEST);
                } else {
                    wp_die($error, $error, 400);
                }
            } else {
                if (isset($_REQUEST['payop']) AND $_REQUEST['payop'] == 'success') {
                    $orderId = $_REQUEST['orderId'];

                    $order = new WC_Order($orderId);

                    $order->update_status('processing', __('Платеж успешно оплачен', 'payop-woocommerce'));

                    WC()->cart->empty_cart();

                    wp_redirect($this->get_return_url($order));
                } else {
                    if (isset($_REQUEST['payop']) AND $_REQUEST['payop'] == 'fail') {
                        $orderId = $_REQUEST['orderId'];

                        $order = new WC_Order($orderId);

                        $order->update_status('failed', __('Платеж не оплачен', 'payop-woocommerce'));

                        wp_redirect($order->get_cancel_order_url());

                        exit;
                    }
                }
            }
        }

        /**
         * Successful Payment!
         **/
        function successful_request($posted)
        {
            global $woocommerce;

            $orderId = $posted['orderId'];

            $order = new WC_Order($orderId);

            // Check order not already completed
            if ($order->status == 'completed') {
                exit;
            }

            // Payment completed
            $order->add_order_note(__('Платеж успешно завершен.', 'woocommerce'));

            $order->payment_complete();

            exit;
        }

        function apiRequest($arrData = [])
        {
            $data = json_encode($arrData);

            $ch = curl_init($this->apiUrl);

            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type:application/json']);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $result = curl_exec($ch);

            curl_close($ch);

            return json_decode($result, true);
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

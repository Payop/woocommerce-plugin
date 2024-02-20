<?php
/**
 * Settings for Payop Standard Gateway.
 *
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
	exit;
}

return array(
	'enabled' => array(
		'title' => __('Enable Payop payments', 'payop-woocommerce'),
		'type' => 'checkbox',
		'label' => __('Enable/Disable', 'payop-woocommerce'),
		'default' => 'yes',
	),
	'title' => array(
		'title' => __('Name of payment gateway', 'payop-woocommerce'),
		'type' => 'text',
		'description' => __('The name of the payment gateway that the user see when placing the order', 'payop-woocommerce'),
		'default' => __('Payop', 'payop-woocommerce'),
	),
	'public_key' => array(
		'title' => __('Public key', 'payop-woocommerce'),
		'type' => 'text',
		'description' => __('Issued in the client panel https://payop.com', 'payop-woocommerce'),
		'default' => '',
	),
	'secret_key' => array(
		'title' => __('Secret key', 'payop-woocommerce'),
		'type' => 'text',
		'description' => __('Issued in the client panel https://payop.com', 'payop-woocommerce'),
		'default' => '',
	),
	'description' => array(
		'title' => __('Description', 'payop-woocommerce'),
		'type' => 'textarea',
		'description' => __(
			'Description of the payment gateway that the client will see on your site.',
			'payop-woocommerce'
		),
		'default' => __('Accept online payments using Payop.com', 'payop-woocommerce'),
	),
	'auto_complete' => array(
		'title' => __('Order completion', 'payop-woocommerce'),
		'type' => 'checkbox',
		'label' => __(
			'Automatic transfer of the order to the status "Completed" after successful payment',
			'payop-woocommerce'
		),
		'description' => __('', 'payop-woocommerce'),
		'default' => '1',
	),
	'skip_confirm' => array(
		'title' => __('Skip confirmation', 'payop-woocommerce'),
		'type' => 'checkbox',
		'label' => __(
			'Skip page checkout confirmation',
			'payop-woocommerce'
		),
		'description' => __('', 'payop-woocommerce'),
		'default' => 'yes',
	),
	'payment_form_language' => array(
		'title' => __('Payment form language', 'payop-woocommerce'),
		'type' => 'select',
		'description' => __('Select the language of the payment form for your store', 'payop-woocommerce'),
		'default' => 'en',
		'options' => array(
			'en' => __('English', 'payop-woocommerce'),
			'ru' => __('Russian', 'payop-woocommerce'),
		),
	),
	'jwt_token' => array(
		'title' => __('JWT Token', 'payop-woocommerce'),
		'type' => 'text',
		'description' => __('Required for Directpay! Issued in the client panel https://payop.com/en/profile/settings/jwt-token', 'payop-woocommerce'),
		'default' => '',
	),
	'payment_method' => array(
		'title' => __('Directpay payment method', 'wcs'),
		'type' => 'select',
		'description' => (count($this->get_payments_methods_options(true )) > 1) ?  __('Select default payment method to directpay. (In list only available to your account payment methods.) <br><span style="color: red;">This function is in experimental mode! Use this option with caution.<br>
The unavailability of the payment method or not all submitted fields can make payment impossible</span>', 'payop-woocommerce') : "<span style='color: red'>JWT TOKEN REQUIRED FOR THIS FEATURE!!! </span><br>" . __('Select default payment method to directpay. (In list only available to your account payment methods.) <br><span style="color: red;">This function is in experimental mode! Use this option with caution.<br>
The unavailability of the payment method or not all submitted fields can make payment impossible</span>', 'payop-woocommerce'),
		'id' => 'wcs_chosen_categories',
		'default' => '',
		'css' => '',
		'options' => $this->get_payments_methods_options(true),
	),
);
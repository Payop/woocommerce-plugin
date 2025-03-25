<?php
/**
 * Settings for Payop Standard Gateway.
 *
 * @version 1.0.2
 */

if (!defined('ABSPATH')) {
	exit;
}

return [
	'enabled' => [
		'title' => __('Enable Payop payments', 'payop-woocommerce'),
		'type' => 'checkbox',
		'label' => __('Enable/Disable', 'payop-woocommerce'),
		'default' => 'yes',
	],

	'title' => [
		'title' => __('Name of payment gateway', 'payop-woocommerce'),
		'type' => 'text',
		'description' => __('The name of the payment gateway that the user see when placing the order', 'payop-woocommerce'),
		'default' => __('Payop', 'payop-woocommerce'),
	],

	'description' => [
		'title' => __('Description', 'payop-woocommerce'),
		'type' => 'textarea',
		'description' => __('Description of the payment gateway that the client will see on your site.', 'payop-woocommerce'),
		'default' => __('Accept online payments using Payop.com', 'payop-woocommerce'),
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

	'ipn_url' => [
		'title'       => __('Callback / IPN URL', 'payop-woocommerce'),
		'type'        => 'text',
		'description' => __('Copy this URL and paste it in your Payop project settings (IPN section)', 'payop-woocommerce'),
		'default'     => add_query_arg([
			'wc-api' => 'wc_payop',
			'payop'  => 'result',
		], home_url('/')),
		'custom_attributes' => [
			'readonly' => 'readonly',
			'onclick' => "this.select();",
		],
	],

	'auto_complete' => [
		'title' => __('Order completion', 'payop-woocommerce'),
		'type' => 'checkbox',
		'label' => __('Automatic transfer of the order to the status "Completed" after successful payment', 'payop-woocommerce'),
		'default' => '1',
	],

	'skip_confirm' => [
		'title' => __('Skip confirmation', 'payop-woocommerce'),
		'type' => 'checkbox',
		'label' => __('Skip page checkout confirmation', 'payop-woocommerce'),
		'default' => 'yes',
	],
];

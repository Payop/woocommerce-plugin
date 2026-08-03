<?php
/**
 * Settings for Payop Standard Gateway.
 *
 * @version 1.2.0
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
		'type' => 'password',
		'description' => __('Issued in the client panel https://payop.com', 'payop-woocommerce'),
		'default' => '',
		'custom_attributes' => [
			'autocomplete' => 'new-password',
		],
	],

	'jwt_token' => [
		'title' => __('JWT Token', 'payop-woocommerce'),
		'type' => 'password',
		'description' => __('Required only to load the payment methods available to your Payop project and configure additional checkout buttons.', 'payop-woocommerce'),
		'default' => '',
		'custom_attributes' => [
			'autocomplete' => 'new-password',
		],
	],

	'integration_type' => [
		'title' => __('Integration type', 'payop-woocommerce'),
		'type' => 'select',
		'description' => __('Choose Hosted Page to show all available methods on Payop, or Hosted Page with Payment Method ID to open one specific method.', 'payop-woocommerce'),
		'default' => 'hosted_page',
		'options' => [
			'hosted_page' => __('Hosted Page', 'payop-woocommerce'),
			'payment_method' => __('Hosted Page with Payment Method ID', 'payop-woocommerce'),
		],
	],

	'payment_method' => [
		'title' => __('Payment method', 'payop-woocommerce'),
		'type' => 'select',
		'description' => __('Select a method available to your Payop project. Save the Public key and JWT Token first to load this list.', 'payop-woocommerce'),
		'default' => '',
		'options' => $this->get_payment_method_options(),
	],

	'payment_buttons' => [
		'title' => __('Additional Payop payment buttons', 'payop-woocommerce'),
		'type' => 'payop_buttons',
		'description' => __('Add, remove, and configure all additional Payop checkout buttons here. JWT Token is used only to retrieve the available payment methods.', 'payop-woocommerce'),
		'default' => [],
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

	'detailed_logging' => [
		'title' => __('Detailed logging', 'payop-woocommerce'),
		'type' => 'checkbox',
		'label' => __('Write detailed Payop callback/IPN logs to WooCommerce logs', 'payop-woocommerce'),
		'description' => __('Order notes are always added for payment and callback events. Disable this option only to stop writing technical Payop entries to WooCommerce logs.', 'payop-woocommerce'),
		'default' => 'no',
	],

	'skip_confirm' => [
		'title' => __('Skip confirmation', 'payop-woocommerce'),
		'type' => 'checkbox',
		'label' => __('Skip page checkout confirmation', 'payop-woocommerce'),
		'default' => 'yes',
	],
];

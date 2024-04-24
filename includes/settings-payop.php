<?php
/**
 * Settings for Payop Standard Gateway.
 *
 * @version 1.0.1
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
		'default' => __('Accept online payments using Payop.com', 'payop-woocommerce'),
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
	'skip_confirm' => [
		'title' => __('Skip confirmation', 'payop-woocommerce'),
		'type' => 'checkbox',
		'label' => __(
			'Skip page checkout confirmation',
			'payop-woocommerce'
		),
		'description' => __('', 'payop-woocommerce'),
		'default' => 'yes',
	]
];
(function (blockData) {
	// Get payment method settings
	const settings = window.wc.wcSettings.getSetting(`${blockData.name}_data`, {});

	// Decode the label with localization consideration
	const label = window.wp.htmlEntities.decodeEntities(settings.title) || window.wp.i18n.__('Payop', 'payop-woocommerce');

	// Payment Gateway Name
	const blockName = blockData.name;

	// Function to get decoded content
	const Content = () => {
		return window.wp.htmlEntities.decodeEntities(settings.description || '');
	};

	// Payment method block definition
	const Block_Gateway = {
		name: blockName,
		label: label,
		content: Object(window.wp.element.createElement)(Content, null),
		edit: Object(window.wp.element.createElement)(Content, null),

		// Function to check if payment can be made
		canMakePayment() {
			return true;
		},

		ariaLabel: label,
	};

	// Register the block if wcBlocksRegistry is defined
	if (window.wc.wcBlocksRegistry) {
		window.wc.wcBlocksRegistry.registerPaymentMethod(Block_Gateway);
	}
})(window.payopBlockData || { name: 'payop' });

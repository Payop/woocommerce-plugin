// Get payment method settings
const settings = window.wc.wcSettings.getSetting('payop_data', {});

// Decode the label with localization consideration
const label = window.wp.htmlEntities.decodeEntities(settings.title) || window.wp.i18n.__('Payop', 'payop');

// Payment Gateway Name
const blockName = payopBlockData.name;

// Function to get decoded content
const Content = () => {
	return window.wp.htmlEntities.decodeEntities(settings.description || '');
};

// Payment method block definition
const Block_Gateway = {
	name: blockName,
	label: label,
	content: Object( window.wp.element.createElement )( Content, null ),
	edit: Object( window.wp.element.createElement )( Content, null ),
	
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
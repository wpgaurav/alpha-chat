const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'admin/index': './assets/admin/index.tsx',
		'widget/index': './assets/widget/index.tsx',
		'block/index': './assets/block/index.tsx',
	},
};

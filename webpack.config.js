const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

const standaloneWidget = {
	...defaultConfig,
	name: 'widget',
	entry: {
		'widget/index': './assets/widget/index.tsx',
	},
	output: {
		...defaultConfig.output,
		clean: false,
	},
	externals: {},
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) => plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new DependencyExtractionWebpackPlugin( {
			injectPolyfill: false,
			useDefaults: false,
		} ),
	],
};

const adminAndBlock = {
	...defaultConfig,
	name: 'wp',
	entry: {
		'admin/index': './assets/admin/index.tsx',
		'block/index': './assets/block/index.tsx',
	},
	output: {
		...defaultConfig.output,
		clean: false,
	},
};

module.exports = [ adminAndBlock, standaloneWidget ];

const config = require( '@wordpress/scripts/config/webpack.config' );
module.exports = {
	...config,
	entry: { 'admin/index': './src/admin/index.js' },
	optimization: {
		...config.optimization,
		moduleIds: 'named',
		chunkIds: 'named',
	},
};

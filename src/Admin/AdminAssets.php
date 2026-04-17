<?php
declare(strict_types=1);

namespace AlphaChat\Admin;

use AlphaChat\REST\RouteRegistrar;
use AlphaChat\Support\AssetManifest;

final class AdminAssets {

	public function enqueue_admin_app(): void {
		$handle = 'alpha-chat-admin';
		$asset  = AssetManifest::load( 'build/admin/index.asset.php' );

		wp_enqueue_script(
			$handle,
			ALPHA_CHAT_URL . 'build/admin/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style(
			$handle,
			ALPHA_CHAT_URL . 'build/admin/style-index.css',
			[ 'wp-components' ],
			$asset['version']
		);

		wp_set_script_translations( $handle, 'alpha-chat' );

		wp_localize_script(
			$handle,
			'alphaChatAdmin',
			[
				'restUrl'   => esc_url_raw( rest_url( RouteRegistrar::NAMESPACE ) ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'version'   => ALPHA_CHAT_VERSION,
			]
		);
	}
}

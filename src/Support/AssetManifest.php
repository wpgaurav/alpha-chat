<?php
declare(strict_types=1);

namespace AlphaChat\Support;

final class AssetManifest {

	/**
	 * Load a `@wordpress/scripts` asset manifest (index.asset.php) from the
	 * plugin's build directory. Falls back to an empty manifest when the file
	 * is missing (e.g. during CI before `npm run build`).
	 *
	 * @return array{dependencies: list<string>, version: string}
	 */
	public static function load( string $relative_path ): array {
		$fallback = [
			'dependencies' => [],
			'version'      => ALPHA_CHAT_VERSION,
		];

		$path = ALPHA_CHAT_PATH . ltrim( $relative_path, '/' );
		if ( ! is_readable( $path ) ) {
			return $fallback;
		}

		/** @var mixed $manifest */
		$manifest = require $path;
		if ( ! is_array( $manifest ) ) {
			return $fallback;
		}

		$dependencies = [];
		if ( isset( $manifest['dependencies'] ) && is_array( $manifest['dependencies'] ) ) {
			foreach ( $manifest['dependencies'] as $dependency ) {
				if ( is_string( $dependency ) ) {
					$dependencies[] = $dependency;
				}
			}
		}

		return [
			'dependencies' => $dependencies,
			'version'      => isset( $manifest['version'] ) && is_string( $manifest['version'] )
				? $manifest['version']
				: ALPHA_CHAT_VERSION,
		];
	}
}

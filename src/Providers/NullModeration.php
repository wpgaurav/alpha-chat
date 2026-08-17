<?php
declare(strict_types=1);

namespace AlphaChat\Providers;

use AlphaChat\Providers\Contracts\ModerationProvider;

final class NullModeration implements ModerationProvider {

	public function id(): string {
		return 'none';
	}

	/**
	 * @return array{flagged: bool, categories: array<string, bool>, scores: array<string, float>}
	 */
	public function check( string $input ): array {
		unset( $input );

		return [
			'flagged'    => false,
			'categories' => [],
			'scores'     => [],
		];
	}
}

<?php
declare(strict_types=1);

namespace AlphaChat\Providers\Contracts;

interface ModerationProvider {

	/**
	 * @return array{flagged: bool, categories: array<string, bool>, scores: array<string, float>}
	 */
	public function check( string $input ): array;

	public function id(): string;
}

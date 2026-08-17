<?php
declare(strict_types=1);

namespace AlphaChat\Providers;

final class ReasoningMap {

	public const DEFAULT = 'low';

	/** @var list<string> */
	public const LEVELS = [ 'off', 'low', 'medium', 'high' ];

	public static function sanitize( string $level ): string {
		$level = strtolower( trim( $level ) );
		return in_array( $level, self::LEVELS, true ) ? $level : self::DEFAULT;
	}

	/**
	 * Map the unified admin level to Chat Completions payload fields.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload( string $provider, string $level ): array {
		$level = self::sanitize( $level );

		return match ( $provider ) {
			'openai'   => [
				'reasoning_effort' => 'off' === $level ? 'none' : $level,
			],
			'xai'      => [
				'reasoning_effort' => 'off' === $level ? 'low' : $level,
			],
			'deepseek' => 'off' === $level
				? [
					'thinking' => [
						'type' => 'disabled',
					],
				]
				: [
					'thinking'         => [
						'type' => 'enabled',
					],
					'reasoning_effort' => 'low' === $level ? 'low' : 'high',
				],
			default    => [],
		};
	}
}

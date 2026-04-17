<?php
declare(strict_types=1);

namespace AlphaChat\Http;

use RuntimeException;

final class HttpException extends RuntimeException {

	/**
	 * @param array<string, mixed> $response
	 */
	public function __construct(
		string $message,
		int $code = 0,
		public readonly array $response = [],
	) {
		parent::__construct( $message, $code );
	}
}

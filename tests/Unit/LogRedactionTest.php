<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Support\LogRepository;
use PHPUnit\Framework\TestCase;

final class LogRedactionTest extends TestCase {

	/**
	 * @return list<array{0: string}>
	 */
	public static function secret_provider(): array {
		return [
			[ 'sk-abcdef1234567890abcdef' ],
			[ 'xai-abcdef1234567890abcdef' ],
			[ 'pa-abcdef1234567890abcdef' ],
		];
	}

	/**
	 * @dataProvider secret_provider
	 */
	public function test_provider_keys_are_stripped( string $secret ): void {
		$message = 'Request failed with Authorization for ' . $secret . ' rejected';
		$out     = LogRepository::redact_string( $message );

		$this->assertStringNotContainsString( $secret, $out );
		$this->assertStringContainsString( '[redacted]', $out );
	}

	public function test_bearer_credentials_are_stripped(): void {
		$out = LogRepository::redact_string( 'sent header Bearer abcdefghijklmnop.qrstuv' );

		$this->assertStringNotContainsString( 'abcdefghijklmnop', $out );
		$this->assertStringContainsString( '[redacted]', $out );
	}

	public function test_ordinary_messages_survive(): void {
		$message = "Unsupported value: 'temperature' does not support 0.7 with this model.";

		$this->assertSame( $message, LogRepository::redact_string( $message ) );
	}

	public function test_long_messages_are_bounded(): void {
		$this->assertSame( 2000, mb_strlen( LogRepository::redact_string( str_repeat( 'a', 5000 ) ) ) );
	}
}

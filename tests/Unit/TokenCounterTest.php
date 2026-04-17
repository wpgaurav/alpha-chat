<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Text\TokenCounter;
use PHPUnit\Framework\TestCase;

final class TokenCounterTest extends TestCase {

	public function test_empty_string_is_zero(): void {
		$counter = new TokenCounter();
		$this->assertSame( 0, $counter->count( '' ) );
	}

	public function test_short_string_is_at_least_one(): void {
		$counter = new TokenCounter();
		$this->assertGreaterThanOrEqual( 1, $counter->count( 'a' ) );
	}

	public function test_longer_text_grows(): void {
		$counter = new TokenCounter();
		$short   = $counter->count( 'hello world' );
		$long    = $counter->count( str_repeat( 'hello world ', 50 ) );
		$this->assertGreaterThan( $short, $long );
	}

	public function test_multibyte_is_counted_by_characters(): void {
		$counter = new TokenCounter();
		$emoji   = $counter->count( str_repeat( '你好世界', 10 ) );
		$this->assertGreaterThan( 5, $emoji );
	}
}

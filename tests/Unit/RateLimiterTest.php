<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Support\RateLimiter;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase {

	/** @var array<string, mixed> */
	private array $store = [];

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		$this->store = [];

		Functions\when( 'get_transient' )->alias(
			fn ( string $key ): mixed => $this->store[ $key ] ?? false
		);
		Functions\when( 'set_transient' )->alias(
			function ( string $key, mixed $value ): bool {
				$this->store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_allows_up_to_the_limit_then_blocks(): void {
		$results = [];
		for ( $i = 0; $i < 5; $i++ ) {
			$results[] = RateLimiter::hit( 'bucket', 3, 60 );
		}

		$this->assertSame( [ false, false, false, true, true ], $results );
	}

	public function test_separate_keys_do_not_share_a_bucket(): void {
		RateLimiter::hit( 'a', 1, 60 );

		$this->assertFalse( RateLimiter::hit( 'b', 1, 60 ) );
	}

	public function test_window_start_is_not_pushed_forward_by_traffic(): void {
		// A steady stream must not extend the window indefinitely: the recorded
		// start time has to stay pinned to the first request.
		RateLimiter::hit( 'steady', 100, 60 );
		$first = $this->store[ array_key_first( $this->store ) ]['start'];

		for ( $i = 0; $i < 10; $i++ ) {
			RateLimiter::hit( 'steady', 100, 60 );
		}

		$this->assertSame( $first, $this->store[ array_key_first( $this->store ) ]['start'] );
		$this->assertSame( 11, $this->store[ array_key_first( $this->store ) ]['count'] );
	}

	public function test_expired_window_resets_the_counter(): void {
		RateLimiter::hit( 'rolls', 2, 60 );
		RateLimiter::hit( 'rolls', 2, 60 );
		$this->assertTrue( RateLimiter::hit( 'rolls', 2, 60 ) );

		// Age the stored window past its length.
		$key                          = array_key_first( $this->store );
		$this->store[ $key ]['start'] = time() - 61;

		$this->assertFalse( RateLimiter::hit( 'rolls', 2, 60 ) );
	}

	public function test_zero_limit_disables_the_bucket(): void {
		$this->assertFalse( RateLimiter::hit( 'off', 0, 60 ) );
		$this->assertFalse( RateLimiter::hit( 'off', 0, 60 ) );
	}
}

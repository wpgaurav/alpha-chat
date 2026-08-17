<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Chat\ThreadTitler;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ThreadTitlerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( string $v ): string => strip_tags( $v ) );
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_strips_wrapping_quotes(): void {
		$this->assertSame( 'Refund policy for annual plans', ThreadTitler::tidy( '"Refund policy for annual plans"' ) );
		$this->assertSame( 'Refund policy', ThreadTitler::tidy( '“Refund policy”' ) );
	}

	public function test_strips_label_prefix_and_trailing_period(): void {
		$this->assertSame( 'Shipping to Canada', ThreadTitler::tidy( 'Title: Shipping to Canada.' ) );
	}

	/**
	 * @return list<array{0: string}>
	 */
	public static function wrapped_provider(): array {
		return [
			// The label can sit inside or outside the quotes, and models do both.
			[ '"Title: Refund policy for annual plans."' ],
			[ 'Title: "Refund policy for annual plans"' ],
			[ '“Refund policy for annual plans.”' ],
			[ "Subject: 'Refund policy for annual plans'" ],
			[ '  Refund policy for annual plans.  ' ],
		];
	}

	/**
	 * @dataProvider wrapped_provider
	 */
	public function test_unwraps_quotes_and_labels_together( string $raw ): void {
		$this->assertSame( 'Refund policy for annual plans', ThreadTitler::tidy( $raw ) );
	}

	public function test_collapses_whitespace_and_newlines(): void {
		$this->assertSame( 'Broken checkout on mobile', ThreadTitler::tidy( "Broken   checkout\non mobile" ) );
	}

	public function test_caps_length(): void {
		$out = ThreadTitler::tidy( str_repeat( 'word ', 40 ) );

		$this->assertLessThanOrEqual( 61, mb_strlen( $out ) );
		$this->assertStringEndsWith( '…', $out );
	}

	public function test_empty_input_stays_empty(): void {
		$this->assertSame( '', ThreadTitler::tidy( '   ' ) );
		$this->assertSame( '', ThreadTitler::tidy( '""' ) );
	}
}

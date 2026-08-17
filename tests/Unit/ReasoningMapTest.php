<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Providers\ReasoningMap;
use PHPUnit\Framework\TestCase;

final class ReasoningMapTest extends TestCase {

	public function test_sanitize_falls_back_to_low(): void {
		$this->assertSame( 'low', ReasoningMap::sanitize( 'xhigh' ) );
		$this->assertSame( 'low', ReasoningMap::sanitize( '' ) );
		$this->assertSame( 'high', ReasoningMap::sanitize( 'HIGH' ) );
	}

	public function test_openai_maps_off_to_none(): void {
		$this->assertSame(
			[ 'reasoning_effort' => 'none' ],
			ReasoningMap::payload( 'openai', 'off' )
		);
		$this->assertSame(
			[ 'reasoning_effort' => 'medium' ],
			ReasoningMap::payload( 'openai', 'medium' )
		);
	}

	public function test_xai_maps_off_to_low(): void {
		$this->assertSame(
			[ 'reasoning_effort' => 'low' ],
			ReasoningMap::payload( 'xai', 'off' )
		);
		$this->assertSame(
			[ 'reasoning_effort' => 'high' ],
			ReasoningMap::payload( 'xai', 'high' )
		);
	}

	public function test_deepseek_off_disables_thinking(): void {
		$this->assertSame(
			[ 'thinking' => [ 'type' => 'disabled' ] ],
			ReasoningMap::payload( 'deepseek', 'off' )
		);

		$low = ReasoningMap::payload( 'deepseek', 'low' );
		$this->assertSame( [ 'type' => 'enabled' ], $low['thinking'] );
		$this->assertSame( 'low', $low['reasoning_effort'] );

		$high = ReasoningMap::payload( 'deepseek', 'high' );
		$this->assertSame( 'high', $high['reasoning_effort'] );
	}

	public function test_anthropic_sends_nothing(): void {
		$this->assertSame( [], ReasoningMap::payload( 'anthropic', 'high' ) );
	}
}

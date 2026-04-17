<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Text\Chunker;
use AlphaChat\Text\TokenCounter;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ChunkerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn ( string $text, bool $remove_breaks = false ): string => strip_tags( $text )
		);
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_empty_text_returns_empty(): void {
		$chunker = new Chunker( new TokenCounter(), 400, 50 );
		$this->assertSame( [], $chunker->split( '' ) );
	}

	public function test_short_text_returns_single_chunk(): void {
		$chunker = new Chunker( new TokenCounter(), 400, 50 );
		$chunks  = $chunker->split( 'Hello world. How are you today?' );
		$this->assertCount( 1, $chunks );
		$this->assertStringContainsString( 'Hello world', $chunks[0] );
	}

	public function test_long_text_splits_into_multiple_chunks(): void {
		$sentence = str_repeat( 'This is a short sentence. ', 200 );
		$chunker  = new Chunker( new TokenCounter(), 100, 20 );
		$chunks   = $chunker->split( $sentence );
		$this->assertGreaterThan( 1, count( $chunks ) );
	}

	public function test_html_is_stripped(): void {
		$chunker = new Chunker( new TokenCounter(), 400, 50 );
		$chunks  = $chunker->split( '<p>Hello <strong>world</strong>.</p>' );
		$this->assertCount( 1, $chunks );
		$this->assertStringNotContainsString( '<strong>', $chunks[0] );
		$this->assertStringContainsString( 'world', $chunks[0] );
	}

	public function test_oversize_sentence_is_force_split(): void {
		$chunker = new Chunker( new TokenCounter(), 50, 0 );
		$long    = str_repeat( 'word ', 400 );
		$chunks  = $chunker->split( $long );
		$this->assertGreaterThan( 1, count( $chunks ) );
	}
}

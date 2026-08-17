<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Chat\QueryRewriter;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class QueryRewriterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( string $v ): string => strip_tags( $v ) );
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_continuations_are_follow_ups(): void {
		$this->assertTrue( QueryRewriter::is_follow_up( 'what about that?' ) );
		$this->assertTrue( QueryRewriter::is_follow_up( 'and this?' ) );
		$this->assertTrue( QueryRewriter::is_follow_up( 'is it free?' ) );
		$this->assertTrue( QueryRewriter::is_follow_up( 'cheaper?' ) );
		$this->assertFalse( QueryRewriter::is_follow_up( '' ) );
	}

	public function test_standalone_questions_are_not_follow_ups(): void {
		// These are short but self-contained. Treating them as follow-ups dragged
		// the previous topic into the retrieval query and buried the new one.
		$this->assertFalse( QueryRewriter::is_follow_up( 'How do refunds work?' ) );
		$this->assertFalse( QueryRewriter::is_follow_up( 'What are your business hours?' ) );
		$this->assertFalse( QueryRewriter::is_follow_up( 'Do you ship to Canada?' ) );
	}

	public function test_rewrite_joins_prior_turn(): void {
		$rewritten = QueryRewriter::rewrite(
			'what about that?',
			'How do refunds work?',
			'Refunds take 5 to 7 days. Contact support if you need help.'
		);

		$this->assertStringContainsString( 'How do refunds work?', $rewritten );
		$this->assertStringContainsString( 'Refunds take 5 to 7 days.', $rewritten );
		$this->assertStringContainsString( 'what about that?', $rewritten );
	}
}

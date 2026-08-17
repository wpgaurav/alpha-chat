<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Chat\ChatService;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ChatServiceFaqTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( string $v ): string => strip_tags( $v ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param list<array<string, mixed>> $faqs
	 *
	 * @return list<array<string, mixed>>
	 */
	private function select( array $faqs, string $message ): array {
		$method = new ReflectionMethod( ChatService::class, 'relevant_faqs' );
		$method->setAccessible( true );

		/** @var list<array<string, mixed>> $out */
		$out = $method->invoke( null, $faqs, $message );

		return $out;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function faqs( int $count, int $relevant_at ): array {
		$out = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = $i === $relevant_at
				? [
					'id'         => $i,
					'question'   => 'How do refunds and returns work?',
					'answer'     => 'Refunds take five days.',
					'sort_order' => $i,
					'enabled'    => true,
					'created_at' => '',
					'updated_at' => '',
				]
				: [
					'id'         => $i,
					'question'   => "Filler question number {$i}?",
					'answer'     => "Filler answer {$i}.",
					'sort_order' => $i,
					'enabled'    => true,
					'created_at' => '',
					'updated_at' => '',
				];
		}

		return $out;
	}

	public function test_small_sets_pass_through_untouched(): void {
		$faqs = $this->faqs( 5, 2 );

		$this->assertSame( $faqs, $this->select( $faqs, 'anything' ) );
	}

	public function test_large_sets_are_capped(): void {
		$selected = $this->select( $this->faqs( 40, 20 ), 'How do refunds work?' );

		$this->assertCount( 12, $selected );
	}

	public function test_relevant_entry_survives_the_cap(): void {
		// Regression guard: scoring iterated the term set's values instead of its
		// keys, so every entry scored zero and the relevant one was cut with the
		// rest of the tail.
		$selected = $this->select( $this->faqs( 40, 30 ), 'How do refunds work?' );

		$questions = array_column( $selected, 'question' );
		$this->assertContains( 'How do refunds and returns work?', $questions );
		$this->assertSame( 'How do refunds and returns work?', $questions[0] );
	}

	public function test_ties_keep_configured_order(): void {
		$selected = $this->select( $this->faqs( 40, 39 ), 'zzz nothing matches here' );

		$this->assertSame( 'Filler question number 0?', $selected[0]['question'] );
	}
}

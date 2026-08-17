<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Chat\FaqExtractor;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class FaqExtractorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( string $v ): string => trim( strip_tags( $v ) ) );
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_extracts_faqpage_json_ld(): void {
		$html = '<script type="application/ld+json">' . json_encode(
			[
				'@context'   => 'https://schema.org',
				'@type'      => 'FAQPage',
				'mainEntity' => [
					[
						'@type'          => 'Question',
						'name'           => 'What is Alpha Chat?',
						'acceptedAnswer' => [
							'@type' => 'Answer',
							'text'  => 'An AI chatbot for WordPress.',
						],
					],
				],
			]
		) . '</script>';

		$pairs = FaqExtractor::from_page( $html );
		$this->assertCount( 1, $pairs );
		$this->assertSame( 'What is Alpha Chat?', $pairs[0]['question'] );
		$this->assertSame( 'An AI chatbot for WordPress.', $pairs[0]['answer'] );
	}

	public function test_extracts_details_and_headings(): void {
		$html  = '<details><summary>How do refunds work?</summary><p>Refunds take 7 days.</p></details>';
		$html .= '<h2>Who are you?</h2><p>The site assistant.</p><h2>Next section</h2><p>Ignore me.</p>';

		$pairs = FaqExtractor::from_page( $html );
		$questions = array_column( $pairs, 'question' );
		$this->assertContains( 'How do refunds work?', $questions );
		$this->assertContains( 'Who are you?', $questions );
		$this->assertNotContains( 'Next section', $questions );
	}

	public function test_extracts_rank_math_block_comment(): void {
		$raw = '<!-- wp:rank-math/faq-block {"questions":[{"id":"faq-question-1","title":"Pricing?","content":"It is free.","visible":true}]} /-->';
		$pairs = FaqExtractor::from_page( '<p>body</p>', $raw );
		$this->assertSame( 'Pricing?', $pairs[0]['question'] );
		$this->assertSame( 'It is free.', $pairs[0]['answer'] );
	}

	public function test_deduplicates_normalized_questions(): void {
		$html = '<details><summary>What is this?</summary><p>One</p></details><details><summary>what   is this?</summary><p>Two</p></details>';
		$pairs = FaqExtractor::from_page( $html );
		$this->assertCount( 1, $pairs );
		$this->assertSame( 'One', $pairs[0]['answer'] );
	}
}

<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Chat\SourcePicker;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SourcePickerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( string $v ): string => strip_tags( $v ) );
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_empty_reply_returns_no_sources(): void {
		$this->assertSame(
			[],
			SourcePicker::used(
				[
					[
						'id'       => 'post:1:0',
						'score'    => 0.9,
						'metadata' => [ 'title' => 'Refunds', 'content' => 'Refunds take seven days', 'source_id' => 1 ],
					],
				],
				'',
				'hello'
			)
		);
	}

	public function test_title_mention_counts_as_used(): void {
		$chunks = [
			[
				'id'       => 'post:1:0',
				'score'    => 0.8,
				'metadata' => [ 'title' => 'Refund Policy', 'content' => 'unused body here', 'source_id' => 1 ],
			],
			[
				'id'       => 'post:2:0',
				'score'    => 0.7,
				'metadata' => [ 'title' => 'Shipping', 'content' => 'boxes and tape', 'source_id' => 2 ],
			],
		];

		$used = SourcePicker::used( $chunks, 'See the Refund Policy for details.', 'how do refunds work?' );
		$this->assertCount( 1, $used );
		$this->assertSame( 'post:1:0', $used[0]['id'] );
	}

	public function test_overlap_counts_as_used(): void {
		$chunks = [
			[
				'id'       => 'post:3:0',
				'score'    => 0.6,
				'metadata' => [
					'title'     => 'Hours',
					'content'   => 'Weekend support hours run from nine until five',
					'source_id' => 3,
				],
			],
		];

		$used = SourcePicker::used(
			$chunks,
			'Weekend support hours run later than weekday hours.',
			'when are you open?'
		);
		$this->assertCount( 1, $used );
	}

	public function test_page_referential_keeps_current_post(): void {
		$chunks = [
			[
				'id'       => 'post:9:0',
				'score'    => 0.4,
				'metadata' => [ 'title' => 'About', 'content' => 'short', 'source_id' => 9 ],
			],
		];

		$used = SourcePicker::used( $chunks, 'This article explains the product.', 'summarize this page', 9 );
		$this->assertCount( 1, $used );
		$this->assertSame( 9, $used[0]['metadata']['source_id'] );
	}
}

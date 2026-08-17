<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Providers\Anthropic\AnthropicChat;
use PHPUnit\Framework\TestCase;

final class AnthropicChatTest extends TestCase {

	public function test_parse_sse_line_reads_event_and_text_delta(): void {
		$event = AnthropicChat::parse_sse_line( 'event: content_block_delta' );
		$this->assertSame( 'content_block_delta', $event['event'] ?? null );

		$delta = AnthropicChat::parse_sse_line(
			'data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Hi"}}',
			'content_block_delta'
		);
		$this->assertSame( 'Hi', $delta['delta'] ?? null );
	}

	public function test_parse_sse_line_reads_usage_from_message_delta(): void {
		$usage = AnthropicChat::parse_sse_line(
			'data: {"type":"message_delta","usage":{"input_tokens":10,"output_tokens":4}}'
		);

		$this->assertSame( 10, $usage['usage']['prompt_tokens'] ?? null );
		$this->assertSame( 4, $usage['usage']['completion_tokens'] ?? null );
		$this->assertSame( 14, $usage['usage']['total_tokens'] ?? null );
	}
}

<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Providers\OpenAICompatible\ChatCompletionsClient;
use PHPUnit\Framework\TestCase;

final class ChatCompletionsClientTest extends TestCase {

	public function test_openai_gpt5_uses_max_completion_tokens(): void {
		$payload = ChatCompletionsClient::build_payload(
			'gpt-5.6-luna',
			[ [ 'role' => 'user', 'content' => 'hi' ] ],
			[ 'max_tokens' => 200, 'temperature' => 0.4 ]
		);

		$this->assertArrayHasKey( 'max_completion_tokens', $payload );
		$this->assertArrayNotHasKey( 'max_tokens', $payload );
		$this->assertSame( 200, $payload['max_completion_tokens'] );
		$this->assertSame( 'gpt-5.6-luna', $payload['model'] );
	}

	public function test_grok_uses_max_tokens(): void {
		$payload = ChatCompletionsClient::build_payload(
			'grok-4.6',
			[ [ 'role' => 'user', 'content' => 'hi' ] ],
			[ 'max_tokens' => 150 ]
		);

		$this->assertArrayHasKey( 'max_tokens', $payload );
		$this->assertArrayNotHasKey( 'max_completion_tokens', $payload );
		$this->assertSame( 150, $payload['max_tokens'] );
	}

	public function test_parse_sse_line_extracts_delta_and_usage(): void {
		$delta = ChatCompletionsClient::parse_sse_line(
			'data: {"choices":[{"delta":{"content":"Hello"}}]}'
		);
		$this->assertSame( 'Hello', $delta['delta'] ?? null );

		$usage = ChatCompletionsClient::parse_sse_line(
			'data: {"choices":[{"delta":{}}],"usage":{"prompt_tokens":3,"completion_tokens":2,"total_tokens":5}}'
		);
		$this->assertSame( 5, $usage['usage']['total_tokens'] ?? null );

		$this->assertNull( ChatCompletionsClient::parse_sse_line( 'data: [DONE]' ) );
		$this->assertNull( ChatCompletionsClient::parse_sse_line( '' ) );
		$this->assertNull( ChatCompletionsClient::parse_sse_line( ': keep-alive' ) );
	}

	public function test_openai_payload_includes_mapped_reasoning_effort(): void {
		$payload = ChatCompletionsClient::build_payload(
			'gpt-5.6-luna',
			[ [ 'role' => 'user', 'content' => 'hi' ] ],
			[ 'max_tokens' => 100, 'reasoning_effort' => 'off' ],
			[],
			'openai'
		);

		$this->assertSame( 'none', $payload['reasoning_effort'] );
	}

	public function test_deepseek_defaults_to_low_thinking(): void {
		$payload = ChatCompletionsClient::build_payload(
			'deepseek-v4-flash',
			[ [ 'role' => 'user', 'content' => 'hi' ] ],
			[ 'max_tokens' => 100 ],
			[],
			'deepseek'
		);

		$this->assertSame( [ 'type' => 'enabled' ], $payload['thinking'] );
		$this->assertSame( 'low', $payload['reasoning_effort'] );
	}

	public function test_deepseek_low_enables_thinking(): void {
		$payload = ChatCompletionsClient::build_payload(
			'deepseek-v4-flash',
			[ [ 'role' => 'user', 'content' => 'hi' ] ],
			[ 'max_tokens' => 100, 'reasoning_effort' => 'low' ],
			[],
			'deepseek'
		);

		$this->assertSame( [ 'type' => 'enabled' ], $payload['thinking'] );
		$this->assertSame( 'low', $payload['reasoning_effort'] );
	}
}

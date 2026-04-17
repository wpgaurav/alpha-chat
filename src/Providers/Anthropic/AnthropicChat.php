<?php
declare(strict_types=1);

namespace AlphaChat\Providers\Anthropic;

use AlphaChat\Http\HttpClient;
use AlphaChat\Providers\Contracts\LLMProvider;

final class AnthropicChat implements LLMProvider {

	private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	private const VERSION  = '2023-06-01';

	public function __construct(
		private readonly HttpClient $http,
		private readonly string $api_key,
		private readonly string $model = 'claude-haiku-4-5-20251001',
	) {}

	public function id(): string {
		return 'anthropic';
	}

	/**
	 * @param list<array{role: string, content: string}> $messages
	 * @param array<string, mixed>                       $options
	 *
	 * @return array{content: string, usage?: array<string, int>}
	 */
	public function complete( array $messages, array $options = [] ): array {
		$system   = '';
		$filtered = [];
		foreach ( $messages as $message ) {
			if ( 'system' === $message['role'] ) {
				$system = '' === $system ? $message['content'] : $system . "\n\n" . $message['content'];
				continue;
			}
			$filtered[] = [
				'role'    => 'assistant' === $message['role'] ? 'assistant' : 'user',
				'content' => $message['content'],
			];
		}

		$payload = array_filter(
			[
				'model'       => $options['model'] ?? $this->model,
				'system'      => '' === $system ? null : $system,
				'messages'    => $filtered,
				'max_tokens'  => $options['max_tokens'] ?? 1024,
				'temperature' => $options['temperature'] ?? null,
				'top_p'       => $options['top_p'] ?? null,
			],
			static fn ( $v ): bool => null !== $v
		);

		$response = $this->http->post_json(
			self::ENDPOINT,
			[
				'x-api-key'         => $this->api_key,
				'anthropic-version' => self::VERSION,
			],
			$payload
		);

		$content_parts = $response['content'] ?? [];
		$content       = '';
		foreach ( $content_parts as $part ) {
			if ( is_array( $part ) && 'text' === ( $part['type'] ?? '' ) ) {
				$content .= (string) ( $part['text'] ?? '' );
			}
		}

		$result = [ 'content' => $content ];

		if ( isset( $response['usage'] ) && is_array( $response['usage'] ) ) {
			$result['usage'] = [
				'prompt_tokens'     => (int) ( $response['usage']['input_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $response['usage']['output_tokens'] ?? 0 ),
				'total_tokens'      => (int) ( $response['usage']['input_tokens'] ?? 0 ) + (int) ( $response['usage']['output_tokens'] ?? 0 ),
			];
		}

		return $result;
	}
}

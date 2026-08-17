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
		$payload = $this->build_payload( $messages, $options );

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

	/**
	 * @param list<array{role: string, content: string}> $messages
	 * @param array<string, mixed>                       $options
	 * @param callable(string): void                     $on_delta
	 *
	 * @return array{content: string, usage?: array<string, int>}
	 */
	public function stream( array $messages, array $options, callable $on_delta ): array {
		if ( ! $this->http->can_stream() ) {
			$result = $this->complete( $messages, $options );
			if ( '' !== $result['content'] ) {
				$on_delta( $result['content'] );
			}
			return $result;
		}

		$payload           = $this->build_payload( $messages, $options );
		$payload['stream'] = true;

		$content = '';
		$usage   = null;
		$event   = '';

		$this->http->post_sse(
			self::ENDPOINT,
			[
				'x-api-key'         => $this->api_key,
				'anthropic-version' => self::VERSION,
			],
			$payload,
			function ( string $line ) use ( &$content, &$usage, &$event, $on_delta ): void {
				$parsed = self::parse_sse_line( $line, $event );
				if ( isset( $parsed['event'] ) ) {
					$event = $parsed['event'];
				}
				if ( isset( $parsed['delta'] ) && '' !== $parsed['delta'] ) {
					$content .= $parsed['delta'];
					$on_delta( $parsed['delta'] );
				}
				if ( isset( $parsed['usage'] ) ) {
					$usage = $parsed['usage'];
				}
			}
		);

		$result = [ 'content' => $content ];
		if ( is_array( $usage ) ) {
			$result['usage'] = $usage;
		}

		return $result;
	}

	/**
	 * @return array{event?: string, delta?: string, usage?: array<string, int>}
	 */
	public static function parse_sse_line( string $line, string $current_event = '' ): array {
		$line = rtrim( $line, "\r" );
		if ( str_starts_with( $line, 'event:' ) ) {
			return [ 'event' => trim( substr( $line, 6 ) ) ];
		}
		if ( ! str_starts_with( $line, 'data:' ) ) {
			return [];
		}

		$decoded = json_decode( trim( substr( $line, 5 ) ), true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$type = (string) ( $decoded['type'] ?? $current_event );
		$out  = [];

		if ( 'content_block_delta' === $type ) {
			$text = $decoded['delta']['text'] ?? '';
			if ( is_string( $text ) && '' !== $text ) {
				$out['delta'] = $text;
			}
		}

		if ( 'message_delta' === $type && isset( $decoded['usage'] ) && is_array( $decoded['usage'] ) ) {
			$out['usage'] = [
				'prompt_tokens'     => (int) ( $decoded['usage']['input_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $decoded['usage']['output_tokens'] ?? 0 ),
				'total_tokens'      => (int) ( $decoded['usage']['input_tokens'] ?? 0 ) + (int) ( $decoded['usage']['output_tokens'] ?? 0 ),
			];
		}

		return $out;
	}

	/**
	 * @param list<array{role: string, content: string}> $messages
	 * @param array<string, mixed>                       $options
	 *
	 * @return array<string, mixed>
	 */
	private function build_payload( array $messages, array $options ): array {
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

		return array_filter(
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
	}
}

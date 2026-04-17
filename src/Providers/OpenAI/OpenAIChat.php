<?php
declare(strict_types=1);

namespace AlphaChat\Providers\OpenAI;

use AlphaChat\Http\HttpClient;
use AlphaChat\Providers\Contracts\LLMProvider;

final class OpenAIChat implements LLMProvider {

	private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	public function __construct(
		private readonly HttpClient $http,
		private readonly string $api_key,
		private readonly string $model = 'gpt-4o-mini',
	) {}

	public function id(): string {
		return 'openai';
	}

	/**
	 * @param list<array{role: string, content: string}> $messages
	 * @param array<string, mixed>                       $options
	 *
	 * @return array{content: string, usage?: array<string, int>}
	 */
	public function complete( array $messages, array $options = [] ): array {
		$model       = (string) ( $options['model'] ?? $this->model );
		$max_tokens  = $options['max_tokens'] ?? null;
		$token_param = self::uses_completion_tokens( $model ) ? 'max_completion_tokens' : 'max_tokens';

		$payload = array_filter(
			[
				'model'             => $model,
				'messages'          => $messages,
				'temperature'       => $options['temperature'] ?? null,
				'top_p'             => $options['top_p'] ?? null,
				$token_param        => $max_tokens,
				'presence_penalty'  => $options['presence_penalty'] ?? null,
				'frequency_penalty' => $options['frequency_penalty'] ?? null,
			],
			static fn ( $v ): bool => null !== $v
		);

		$response = $this->http->post_json(
			self::ENDPOINT,
			[ 'Authorization' => 'Bearer ' . $this->api_key ],
			$payload
		);

		$choice  = $response['choices'][0] ?? [];
		$content = (string) ( $choice['message']['content'] ?? '' );

		$result = [ 'content' => $content ];

		if ( isset( $response['usage'] ) && is_array( $response['usage'] ) ) {
			$result['usage'] = array_map( 'intval', $response['usage'] );
		}

		return $result;
	}

	private static function uses_completion_tokens( string $model ): bool {
		$prefixes = [ 'gpt-5', 'o1', 'o3', 'o4' ];
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $model, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}

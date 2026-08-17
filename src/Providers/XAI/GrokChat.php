<?php
declare(strict_types=1);

namespace AlphaChat\Providers\XAI;

use AlphaChat\Http\HttpClient;
use AlphaChat\Providers\Contracts\LLMProvider;
use AlphaChat\Providers\OpenAICompatible\ChatCompletionsClient;

final class GrokChat implements LLMProvider {

	private const ENDPOINT = 'https://api.x.ai/v1/chat/completions';

	private readonly ChatCompletionsClient $client;

	public function __construct(
		HttpClient $http,
		string $api_key,
		string $model = 'grok-4.6',
	) {
		$this->client = new ChatCompletionsClient(
			$http,
			'xai',
			self::ENDPOINT,
			$api_key,
			$model,
		);
	}

	public function id(): string {
		return $this->client->id();
	}

	/**
	 * @param list<array{role: string, content: string}> $messages
	 * @param array<string, mixed>                       $options
	 *
	 * @return array{content: string, usage?: array<string, int>}
	 */
	public function complete( array $messages, array $options = [] ): array {
		return $this->client->complete( $messages, $options );
	}

	/**
	 * @param list<array{role: string, content: string}> $messages
	 * @param array<string, mixed>                       $options
	 * @param callable(string): void                     $on_delta
	 *
	 * @return array{content: string, usage?: array<string, int>}
	 */
	public function stream( array $messages, array $options, callable $on_delta ): array {
		return $this->client->stream( $messages, $options, $on_delta );
	}
}

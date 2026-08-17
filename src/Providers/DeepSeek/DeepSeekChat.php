<?php
declare(strict_types=1);

namespace AlphaChat\Providers\DeepSeek;

use AlphaChat\Http\HttpClient;
use AlphaChat\Providers\Contracts\LLMProvider;
use AlphaChat\Providers\OpenAICompatible\ChatCompletionsClient;

final class DeepSeekChat implements LLMProvider {

	private const ENDPOINT = 'https://api.deepseek.com/chat/completions';

	private readonly ChatCompletionsClient $client;

	public function __construct(
		HttpClient $http,
		string $api_key,
		string $model = 'deepseek-v4-flash',
	) {
		$this->client = new ChatCompletionsClient(
			$http,
			'deepseek',
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

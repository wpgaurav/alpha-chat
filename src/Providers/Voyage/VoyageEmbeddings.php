<?php
declare(strict_types=1);

namespace AlphaChat\Providers\Voyage;

use AlphaChat\Http\HttpClient;
use AlphaChat\Providers\Contracts\EmbeddingProvider;
use AlphaChat\Providers\OpenAICompatible\EmbeddingsClient;

final class VoyageEmbeddings implements EmbeddingProvider {

	private const ENDPOINT = 'https://api.voyageai.com/v1/embeddings';

	private const DIMENSIONS = [
		'voyage-4-lite'  => 1024,
		'voyage-4'       => 1024,
		'voyage-4-large' => 1024,
	];

	private readonly EmbeddingsClient $client;

	public function __construct(
		HttpClient $http,
		string $api_key,
		string $model = 'voyage-4-lite',
	) {
		$this->client = new EmbeddingsClient(
			$http,
			self::ENDPOINT,
			$api_key,
			$model,
			self::DIMENSIONS[ $model ] ?? 1024,
			true,
		);
	}

	public function model(): string {
		return $this->client->model();
	}

	public function dimensions(): int {
		return $this->client->dimensions();
	}

	/**
	 * @param list<string>         $inputs
	 * @param array<string, mixed> $options
	 *
	 * @return list<list<float>>
	 */
	public function embed( array $inputs, array $options = [] ): array {
		return $this->client->embed( $inputs, $options );
	}
}

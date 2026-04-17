<?php
declare(strict_types=1);

namespace AlphaChat\Providers\OpenAI;

use AlphaChat\Http\HttpClient;
use AlphaChat\Providers\Contracts\EmbeddingProvider;

final class OpenAIEmbeddings implements EmbeddingProvider {

	private const ENDPOINT = 'https://api.openai.com/v1/embeddings';

	private const DIMENSIONS = [
		'text-embedding-3-small' => 1536,
		'text-embedding-3-large' => 3072,
		'text-embedding-ada-002' => 1536,
	];

	public function __construct(
		private readonly HttpClient $http,
		private readonly string $api_key,
		private readonly string $model = 'text-embedding-3-small',
	) {}

	public function model(): string {
		return $this->model;
	}

	public function dimensions(): int {
		return self::DIMENSIONS[ $this->model ] ?? 1536;
	}

	/**
	 * @param list<string> $inputs
	 *
	 * @return list<list<float>>
	 */
	public function embed( array $inputs ): array {
		$inputs = array_values( array_filter( $inputs, static fn ( string $s ): bool => '' !== $s ) );
		if ( empty( $inputs ) ) {
			return [];
		}

		$response = $this->http->post_json(
			self::ENDPOINT,
			[ 'Authorization' => 'Bearer ' . $this->api_key ],
			[
				'input' => $inputs,
				'model' => $this->model,
			]
		);

		$data = $response['data'] ?? [];
		$vectors = [];
		foreach ( $data as $row ) {
			$embedding = $row['embedding'] ?? [];
			if ( is_array( $embedding ) ) {
				$vectors[] = array_values( array_map( 'floatval', $embedding ) );
			}
		}

		return $vectors;
	}
}

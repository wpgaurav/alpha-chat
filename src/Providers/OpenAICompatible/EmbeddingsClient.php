<?php
declare(strict_types=1);

namespace AlphaChat\Providers\OpenAICompatible;

use AlphaChat\Http\HttpClient;
use AlphaChat\Providers\Contracts\EmbeddingProvider;

final class EmbeddingsClient implements EmbeddingProvider {

	public function __construct(
		private readonly HttpClient $http,
		private readonly string $endpoint,
		private readonly string $api_key,
		private readonly string $model,
		private readonly int $dimensions,
		private readonly bool $supports_input_type = false,
	) {}

	public function model(): string {
		return $this->model;
	}

	public function dimensions(): int {
		return $this->dimensions;
	}

	/**
	 * @param list<string>         $inputs
	 * @param array<string, mixed> $options
	 *
	 * @return list<list<float>>
	 */
	public function embed( array $inputs, array $options = [] ): array {
		$inputs = array_values( array_filter( $inputs, static fn ( string $s ): bool => '' !== $s ) );
		if ( empty( $inputs ) ) {
			return [];
		}

		$response = $this->http->post_json(
			$this->endpoint,
			[ 'Authorization' => 'Bearer ' . $this->api_key ],
			self::build_payload( $this->model, $inputs, $options, $this->supports_input_type )
		);

		$data = $response['data'] ?? [];
		if ( ! is_array( $data ) ) {
			return [];
		}

		usort(
			$data,
			static fn ( mixed $a, mixed $b ): int => (int) ( is_array( $a ) ? ( $a['index'] ?? 0 ) : 0 ) <=> (int) ( is_array( $b ) ? ( $b['index'] ?? 0 ) : 0 )
		);

		$vectors = [];
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$embedding = $row['embedding'] ?? [];
			if ( is_array( $embedding ) ) {
				$vectors[] = array_values( array_map( 'floatval', $embedding ) );
			}
		}

		return $vectors;
	}

	/**
	 * @param list<string>         $inputs
	 * @param array<string, mixed> $options
	 *
	 * @return array<string, mixed>
	 */
	public static function build_payload( string $model, array $inputs, array $options, bool $supports_input_type ): array {
		$payload = [
			'input' => $inputs,
			'model' => $model,
		];

		$input_type = (string) ( $options['input_type'] ?? '' );
		if ( $supports_input_type && in_array( $input_type, [ 'query', 'document' ], true ) ) {
			$payload['input_type'] = $input_type;
		}

		return $payload;
	}
}

<?php
declare(strict_types=1);

namespace AlphaChat\Providers\OpenAI;

use AlphaChat\Http\HttpClient;

final class OpenAIModeration {

	private const ENDPOINT = 'https://api.openai.com/v1/moderations';

	public function __construct(
		private readonly HttpClient $http,
		private readonly string $api_key,
	) {}

	/**
	 * @return array{flagged: bool, categories: array<string, bool>, scores: array<string, float>}
	 */
	public function check( string $input ): array {
		$response = $this->http->post_json(
			self::ENDPOINT,
			[ 'Authorization' => 'Bearer ' . $this->api_key ],
			[
				'model' => 'omni-moderation-latest',
				'input' => $input,
			]
		);

		$result = $response['results'][0] ?? [];

		return [
			'flagged'    => (bool) ( $result['flagged'] ?? false ),
			'categories' => array_map( 'boolval', (array) ( $result['categories'] ?? [] ) ),
			'scores'     => array_map( 'floatval', (array) ( $result['category_scores'] ?? [] ) ),
		];
	}
}

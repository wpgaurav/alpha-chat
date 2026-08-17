<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Providers\OpenAICompatible\EmbeddingsClient;
use PHPUnit\Framework\TestCase;

final class EmbeddingsClientTest extends TestCase {

	public function test_openai_payload_omits_input_type(): void {
		$payload = EmbeddingsClient::build_payload(
			'text-embedding-3-small',
			[ 'hello' ],
			[ 'input_type' => 'query' ],
			false
		);

		$this->assertSame( 'text-embedding-3-small', $payload['model'] );
		$this->assertSame( [ 'hello' ], $payload['input'] );
		$this->assertArrayNotHasKey( 'input_type', $payload );
	}

	public function test_voyage_payload_includes_input_type(): void {
		$payload = EmbeddingsClient::build_payload(
			'voyage-4-lite',
			[ 'hello' ],
			[ 'input_type' => 'document' ],
			true
		);

		$this->assertSame( 'document', $payload['input_type'] );
	}
}

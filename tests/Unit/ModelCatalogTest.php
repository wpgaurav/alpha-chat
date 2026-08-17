<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Providers\ModelCatalog;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ModelCatalogTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_catalog_contains_expected_provider_and_model_ids(): void {
		Filters\expectApplied( 'alpha_chat_model_catalog' )
			->once()
			->andReturnFirstArg();

		$catalog = ModelCatalog::all();
		$by_id   = [];
		foreach ( $catalog['providers'] as $provider ) {
			$by_id[ $provider['id'] ] = $provider;
		}

		$this->assertArrayHasKey( 'openai', $by_id );
		$this->assertArrayHasKey( 'anthropic', $by_id );
		$this->assertArrayHasKey( 'xai', $by_id );
		$this->assertArrayHasKey( 'deepseek', $by_id );

		$this->assertSame(
			[ 'gpt-5.6-luna', 'gpt-5.6-terra', 'gpt-5.6-sol' ],
			array_column( $by_id['openai']['models'], 'id' )
		);
		$this->assertSame(
			[ 'grok-4.6', 'grok-4.5' ],
			array_column( $by_id['xai']['models'], 'id' )
		);
		$this->assertSame(
			[ 'deepseek-v4-flash', 'deepseek-v4-pro' ],
			array_column( $by_id['deepseek']['models'], 'id' )
		);

		$this->assertSame( 'gpt-5.6-luna', $by_id['openai']['presets']['fast']['chat_model'] );
		$this->assertSame( 'low', $by_id['openai']['presets']['fast']['reasoning_effort'] );
		$this->assertSame( 'low', $by_id['openai']['presets']['quality']['reasoning_effort'] );
		$this->assertSame( 'low', $by_id['xai']['presets']['balanced']['reasoning_effort'] );
		$this->assertSame( 'low', $by_id['deepseek']['presets']['fast']['reasoning_effort'] );
		$this->assertSame( 'low', $by_id['deepseek']['presets']['quality']['reasoning_effort'] );
		$this->assertSame( [ 'off', 'low', 'medium', 'high' ], array_column( $catalog['reasoning'], 'id' ) );

		$embeddings = [];
		foreach ( $catalog['embeddings'] as $provider ) {
			$embeddings[ $provider['id'] ] = $provider;
		}
		$this->assertArrayHasKey( 'openai', $embeddings );
		$this->assertArrayHasKey( 'voyage', $embeddings );
		$this->assertSame(
			[ 'voyage-4-lite', 'voyage-4', 'voyage-4-large' ],
			array_column( $embeddings['voyage']['models'], 'id' )
		);
	}

	public function test_catalog_filter_receives_the_built_payload(): void {
		Filters\expectApplied( 'alpha_chat_model_catalog' )
			->once()
			->andReturnUsing(
				static function ( array $catalog ): array {
					$catalog['providers'][] = [
						'id'         => 'custom',
						'label'      => 'Custom',
						'secret_key' => 'custom_api_key',
						'models'     => [],
						'presets'    => [],
					];
					return $catalog;
				}
			);

		$ids = array_column( ModelCatalog::all()['providers'], 'id' );

		$this->assertContains( 'custom', $ids );
	}

	public function test_remaps_retired_openai_ids(): void {
		$this->assertSame( 'gpt-5.6-luna', ModelCatalog::remap_chat_model( 'gpt-5.4-mini' ) );
		$this->assertSame( 'gpt-5.6-sol', ModelCatalog::remap_chat_model( 'gpt-5.4' ) );
		$this->assertSame( 'gpt-5.6-terra', ModelCatalog::remap_chat_model( 'gpt-4.1' ) );
		$this->assertSame( 'gpt-5.6-luna', ModelCatalog::remap_chat_model( 'gpt-5.6-luna' ) );
	}

	public function test_secret_keys_include_new_providers(): void {
		$this->assertSame(
			[ 'openai_api_key', 'anthropic_api_key', 'xai_api_key', 'deepseek_api_key', 'voyage_api_key' ],
			ModelCatalog::secret_keys()
		);
	}
}

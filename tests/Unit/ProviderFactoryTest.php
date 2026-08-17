<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Http\HttpClient;
use AlphaChat\Providers\Anthropic\AnthropicChat;
use AlphaChat\Providers\DeepSeek\DeepSeekChat;
use AlphaChat\Providers\NullModeration;
use AlphaChat\Providers\OpenAI\OpenAIChat;
use AlphaChat\Providers\OpenAI\OpenAIEmbeddings;
use AlphaChat\Providers\OpenAI\OpenAIModeration;
use AlphaChat\Providers\ProviderFactory;
use AlphaChat\Providers\Voyage\VoyageEmbeddings;
use AlphaChat\Providers\XAI\GrokChat;
use AlphaChat\Settings\SettingsRepository;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProviderFactoryTest extends TestCase {

	/** @var array<string, mixed> */
	private array $stored = [];

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		$this->stored = [];

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'get_option' )->alias(
			function ( string $key, mixed $fallback = null ): mixed {
				if ( 'admin_email' === $key ) {
					return 'admin@example.com';
				}
				if ( SettingsRepository::OPTION_KEY === $key ) {
					return $this->stored;
				}
				return $fallback ?? '';
			}
		);

		Filters\expectApplied( 'alpha_chat_default_settings' )
			->zeroOrMoreTimes()
			->andReturnFirstArg();
		Filters\expectApplied( 'alpha_chat_llm_provider' )
			->zeroOrMoreTimes()
			->andReturnFirstArg();
		Filters\expectApplied( 'alpha_chat_embedding_provider' )
			->zeroOrMoreTimes()
			->andReturnFirstArg();
		Filters\expectApplied( 'alpha_chat_moderation_provider' )
			->zeroOrMoreTimes()
			->andReturnFirstArg();
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_openai_is_default_and_uses_openai_secret(): void {
		$this->stored = [ 'openai_api_key' => 'sk-openai' ];

		$llm = $this->factory()->llm();

		$this->assertInstanceOf( OpenAIChat::class, $llm );
		$this->assertSame( 'openai', $llm->id() );
	}

	public function test_anthropic_requires_anthropic_secret(): void {
		$this->stored = [
			'llm_provider'      => 'anthropic',
			'anthropic_api_key' => 'sk-ant',
			'chat_model'        => 'claude-sonnet-4-6',
		];

		$llm = $this->factory()->llm();

		$this->assertInstanceOf( AnthropicChat::class, $llm );
		$this->assertSame( 'anthropic', $llm->id() );
	}

	public function test_xai_requires_xai_secret(): void {
		$this->stored = [
			'llm_provider' => 'xai',
			'xai_api_key'  => 'xai-test',
			'chat_model'   => 'grok-4.6',
		];

		$llm = $this->factory()->llm();

		$this->assertInstanceOf( GrokChat::class, $llm );
		$this->assertSame( 'xai', $llm->id() );
	}

	public function test_deepseek_requires_deepseek_secret(): void {
		$this->stored = [
			'llm_provider'     => 'deepseek',
			'deepseek_api_key' => 'sk-deepseek',
			'chat_model'       => 'deepseek-v4-flash',
		];

		$llm = $this->factory()->llm();

		$this->assertInstanceOf( DeepSeekChat::class, $llm );
		$this->assertSame( 'deepseek', $llm->id() );
	}

	public function test_unknown_provider_falls_through_to_openai(): void {
		$this->stored = [
			'llm_provider'   => 'openrouter',
			'openai_api_key' => 'sk-openai',
		];

		$llm = $this->factory()->llm();

		$this->assertInstanceOf( OpenAIChat::class, $llm );
	}

	public function test_voyage_embeddings_use_voyage_secret(): void {
		$this->stored = [
			'embedding_provider' => 'voyage',
			'embedding_model'    => 'voyage-4-lite',
			'voyage_api_key'     => 'pa-voyage',
		];

		$embeddings = $this->factory()->embeddings();

		$this->assertInstanceOf( VoyageEmbeddings::class, $embeddings );
		$this->assertSame( 'voyage-4-lite', $embeddings->model() );
	}

	public function test_openai_embeddings_are_the_default(): void {
		$this->stored = [ 'openai_api_key' => 'sk-openai' ];

		$embeddings = $this->factory()->embeddings();

		$this->assertInstanceOf( OpenAIEmbeddings::class, $embeddings );
	}

	public function test_moderation_is_a_no_op_without_openai_key(): void {
		$this->stored = [];

		$moderation = $this->factory()->moderation();

		$this->assertInstanceOf( NullModeration::class, $moderation );
		$this->assertFalse( $moderation->check( 'hello' )['flagged'] );
	}

	public function test_moderation_uses_openai_when_key_is_present(): void {
		$this->stored = [ 'openai_api_key' => 'sk-openai' ];

		$moderation = $this->factory()->moderation();

		$this->assertInstanceOf( OpenAIModeration::class, $moderation );
		$this->assertSame( 'openai', $moderation->id() );
	}

	public function test_missing_secret_throws(): void {
		$this->stored = [ 'llm_provider' => 'xai' ];

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'xAI API key is not configured.' );

		$this->factory()->llm();
	}

	private function factory(): ProviderFactory {
		return new ProviderFactory( new SettingsRepository(), new HttpClient() );
	}
}

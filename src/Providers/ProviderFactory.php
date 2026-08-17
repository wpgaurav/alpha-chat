<?php
declare(strict_types=1);

namespace AlphaChat\Providers;

use AlphaChat\Http\HttpClient;
use AlphaChat\Providers\Anthropic\AnthropicChat;
use AlphaChat\Providers\Contracts\EmbeddingProvider;
use AlphaChat\Providers\Contracts\LLMProvider;
use AlphaChat\Providers\Contracts\ModerationProvider;
use AlphaChat\Providers\Contracts\VectorStore;
use AlphaChat\Providers\DeepSeek\DeepSeekChat;
use AlphaChat\Providers\NullModeration;
use AlphaChat\Providers\OpenAI\OpenAIChat;
use AlphaChat\Providers\OpenAI\OpenAIEmbeddings;
use AlphaChat\Providers\OpenAI\OpenAIModeration;
use AlphaChat\Providers\VectorStore\DatabaseVectorStore;
use AlphaChat\Providers\Voyage\VoyageEmbeddings;
use AlphaChat\Providers\XAI\GrokChat;
use AlphaChat\Settings\SettingsRepository;
use RuntimeException;

final class ProviderFactory {

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly HttpClient $http,
	) {}

	public function llm(): LLMProvider {
		$provider = (string) $this->settings->get( 'llm_provider', 'openai' );

		/**
		 * Short-circuit provider resolution with your own implementation.
		 *
		 * Runs before the built-in provider is constructed. The
		 * alpha_chat_llm_provider filter below can only decorate an instance
		 * that already exists, which means the built-in provider's API key has
		 * to be configured first — so it cannot be used to replace a provider
		 * outright. Return an LLMProvider here to take over completely.
		 *
		 * @param LLMProvider|null $override Null to use the built-in provider.
		 * @param string           $provider Configured provider id.
		 */
		$override = apply_filters( 'alpha_chat_pre_llm_provider', null, $provider );
		if ( $override instanceof LLMProvider ) {
			return $override;
		}

		$llm = match ( $provider ) {
			'anthropic' => new AnthropicChat(
				$this->http,
				$this->require_secret( 'anthropic_api_key', 'Anthropic' ),
				(string) $this->settings->get( 'chat_model', 'claude-sonnet-4-6' ),
			),
			'xai' => new GrokChat(
				$this->http,
				$this->require_secret( 'xai_api_key', 'xAI' ),
				(string) $this->settings->get( 'chat_model', 'grok-4.6' ),
			),
			'deepseek' => new DeepSeekChat(
				$this->http,
				$this->require_secret( 'deepseek_api_key', 'DeepSeek' ),
				(string) $this->settings->get( 'chat_model', 'deepseek-v4-flash' ),
			),
			default => new OpenAIChat(
				$this->http,
				$this->require_secret( 'openai_api_key', 'OpenAI' ),
				(string) $this->settings->get( 'chat_model', 'gpt-5.6-luna' ),
			),
		};

		/**
		 * Filter the LLM provider used for completions.
		 *
		 * @param LLMProvider $llm Default provider.
		 */
		return apply_filters( 'alpha_chat_llm_provider', $llm );
	}

	public function embeddings(): EmbeddingProvider {
		$provider = (string) $this->settings->get( 'embedding_provider', 'openai' );

		/**
		 * Short-circuit embedding provider resolution.
		 *
		 * This filter is documented in src/Providers/ProviderFactory.php
		 *
		 * @param EmbeddingProvider|null $override Null to use the built-in provider.
		 * @param string                 $provider Configured provider id.
		 */
		$override = apply_filters( 'alpha_chat_pre_embedding_provider', null, $provider );
		if ( $override instanceof EmbeddingProvider ) {
			return $override;
		}

		$embeddings = match ( $provider ) {
			'voyage' => new VoyageEmbeddings(
				$this->http,
				$this->require_secret( 'voyage_api_key', 'Voyage' ),
				(string) $this->settings->get( 'embedding_model', 'voyage-4-lite' ),
			),
			default => new OpenAIEmbeddings(
				$this->http,
				$this->require_secret( 'openai_api_key', 'OpenAI' ),
				(string) $this->settings->get( 'embedding_model', 'text-embedding-3-small' ),
			),
		};

		/**
		 * Filter the embedding provider.
		 *
		 * @param EmbeddingProvider $embeddings Default provider.
		 */
		return apply_filters( 'alpha_chat_embedding_provider', $embeddings );
	}

	public function moderation(): ModerationProvider {
		$key = (string) $this->settings->get( 'openai_api_key', '' );

		$moderation = '' === $key
			? new NullModeration()
			: new OpenAIModeration( $this->http, $key );

		/**
		 * Filter the moderation provider.
		 *
		 * @param ModerationProvider $moderation Default provider.
		 */
		return apply_filters( 'alpha_chat_moderation_provider', $moderation );
	}

	public function vector_store(): VectorStore {
		/**
		 * Filter the vector store implementation.
		 *
		 * @param VectorStore $vector Default store (site DB).
		 */
		return apply_filters( 'alpha_chat_vector_store', new DatabaseVectorStore() );
	}

	private function require_secret( string $key, string $label ): string {
		$value = (string) $this->settings->get( $key, '' );
		if ( '' === $value ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are not HTML output.
			throw new RuntimeException( sprintf( '%s API key is not configured.', $label ) );
		}
		return $value;
	}
}

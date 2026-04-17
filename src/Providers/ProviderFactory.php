<?php
declare(strict_types=1);

namespace AlphaChat\Providers;

use AlphaChat\Http\HttpClient;
use AlphaChat\Providers\Anthropic\AnthropicChat;
use AlphaChat\Providers\Contracts\EmbeddingProvider;
use AlphaChat\Providers\Contracts\LLMProvider;
use AlphaChat\Providers\Contracts\VectorStore;
use AlphaChat\Providers\OpenAI\OpenAIChat;
use AlphaChat\Providers\OpenAI\OpenAIEmbeddings;
use AlphaChat\Providers\OpenAI\OpenAIModeration;
use AlphaChat\Providers\VectorStore\DatabaseVectorStore;
use AlphaChat\Settings\SettingsRepository;
use RuntimeException;

final class ProviderFactory {

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly HttpClient $http,
	) {}

	public function llm(): LLMProvider {
		$provider = (string) $this->settings->get( 'llm_provider', 'openai' );

		$llm = match ( $provider ) {
			'anthropic' => new AnthropicChat(
				$this->http,
				$this->require_secret( 'anthropic_api_key', 'Anthropic' ),
				(string) $this->settings->get( 'chat_model', 'claude-sonnet-4-6' ),
			),
			default => new OpenAIChat(
				$this->http,
				$this->require_secret( 'openai_api_key', 'OpenAI' ),
				(string) $this->settings->get( 'chat_model', 'gpt-5.4-mini' ),
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
		$embeddings = new OpenAIEmbeddings(
			$this->http,
			$this->require_secret( 'openai_api_key', 'OpenAI' ),
			(string) $this->settings->get( 'embedding_model', 'text-embedding-3-small' ),
		);

		/**
		 * Filter the embedding provider.
		 *
		 * @param EmbeddingProvider $embeddings Default provider.
		 */
		return apply_filters( 'alpha_chat_embedding_provider', $embeddings );
	}

	public function moderation(): OpenAIModeration {
		return new OpenAIModeration(
			$this->http,
			$this->require_secret( 'openai_api_key', 'OpenAI' ),
		);
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
			throw new RuntimeException( sprintf( '%s API key is not configured.', $label ) );
		}
		return $value;
	}
}

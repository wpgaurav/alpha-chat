<?php
declare(strict_types=1);

namespace AlphaChat\Providers;

final class ModelCatalog {

	/** @var array<string, string> */
	private const RETIRED_CHAT_MODELS = [
		'gpt-5.4-mini' => 'gpt-5.6-luna',
		'gpt-5.4'      => 'gpt-5.6-sol',
		'gpt-4.1'      => 'gpt-5.6-terra',
	];

	/**
	 * @return array{
	 *   providers: list<array{
	 *     id: string,
	 *     label: string,
	 *     secret_key: string,
	 *     models: list<array{id: string, label: string}>,
	 *     presets: array<string, array{chat_model: string, temperature: float, max_response_tokens: int, reasoning_effort: string}>
	 *   }>,
	 *   embeddings: list<array{
	 *     id: string,
	 *     label: string,
	 *     secret_key: string,
	 *     models: list<array{id: string, label: string}>
	 *   }>,
	 *   reasoning: list<array{id: string, label: string}>
	 * }
	 */
	public static function all(): array {
		$catalog = [
			'providers'  => [
				self::provider(
					'openai',
					__( 'OpenAI', 'alpha-chat' ),
					'openai_api_key',
					[
						[ 'id' => 'gpt-5.6-luna', 'label' => __( 'GPT-5.6 Luna · fast, cheap', 'alpha-chat' ) ],
						[ 'id' => 'gpt-5.6-terra', 'label' => __( 'GPT-5.6 Terra · balanced', 'alpha-chat' ) ],
						[ 'id' => 'gpt-5.6-sol', 'label' => __( 'GPT-5.6 Sol · highest quality', 'alpha-chat' ) ],
					],
					[
						'fast'     => [ 'chat_model' => 'gpt-5.6-luna', 'temperature' => 0.3, 'max_response_tokens' => 600, 'reasoning_effort' => 'low' ],
						'balanced' => [ 'chat_model' => 'gpt-5.6-terra', 'temperature' => 0.7, 'max_response_tokens' => 800, 'reasoning_effort' => 'low' ],
						'quality'  => [ 'chat_model' => 'gpt-5.6-sol', 'temperature' => 0.7, 'max_response_tokens' => 1500, 'reasoning_effort' => 'low' ],
					],
				),
				self::provider(
					'anthropic',
					__( 'Anthropic', 'alpha-chat' ),
					'anthropic_api_key',
					[
						[ 'id' => 'claude-haiku-4-5', 'label' => __( 'Claude Haiku 4.5 · fast, cheap', 'alpha-chat' ) ],
						[ 'id' => 'claude-sonnet-4-6', 'label' => __( 'Claude Sonnet 4.6 · balanced', 'alpha-chat' ) ],
						[ 'id' => 'claude-opus-4-7', 'label' => __( 'Claude Opus 4.7 · highest quality', 'alpha-chat' ) ],
					],
					[
						'fast'     => [ 'chat_model' => 'claude-haiku-4-5', 'temperature' => 0.3, 'max_response_tokens' => 600, 'reasoning_effort' => 'low' ],
						'balanced' => [ 'chat_model' => 'claude-sonnet-4-6', 'temperature' => 0.7, 'max_response_tokens' => 800, 'reasoning_effort' => 'low' ],
						'quality'  => [ 'chat_model' => 'claude-opus-4-7', 'temperature' => 0.7, 'max_response_tokens' => 1500, 'reasoning_effort' => 'low' ],
					],
				),
				self::provider(
					'xai',
					__( 'xAI (Grok)', 'alpha-chat' ),
					'xai_api_key',
					[
						[ 'id' => 'grok-4.6', 'label' => __( 'Grok 4.6 · current', 'alpha-chat' ) ],
						[ 'id' => 'grok-4.5', 'label' => __( 'Grok 4.5 · previous', 'alpha-chat' ) ],
					],
					[
						'fast'     => [ 'chat_model' => 'grok-4.6', 'temperature' => 0.3, 'max_response_tokens' => 600, 'reasoning_effort' => 'low' ],
						'balanced' => [ 'chat_model' => 'grok-4.6', 'temperature' => 0.7, 'max_response_tokens' => 800, 'reasoning_effort' => 'low' ],
						'quality'  => [ 'chat_model' => 'grok-4.6', 'temperature' => 0.7, 'max_response_tokens' => 1500, 'reasoning_effort' => 'low' ],
					],
				),
				self::provider(
					'deepseek',
					__( 'DeepSeek', 'alpha-chat' ),
					'deepseek_api_key',
					[
						[ 'id' => 'deepseek-v4-flash', 'label' => __( 'DeepSeek V4 Flash · fast', 'alpha-chat' ) ],
						[ 'id' => 'deepseek-v4-pro', 'label' => __( 'DeepSeek V4 Pro · quality', 'alpha-chat' ) ],
					],
					[
						'fast'     => [ 'chat_model' => 'deepseek-v4-flash', 'temperature' => 0.3, 'max_response_tokens' => 600, 'reasoning_effort' => 'low' ],
						'balanced' => [ 'chat_model' => 'deepseek-v4-flash', 'temperature' => 0.7, 'max_response_tokens' => 800, 'reasoning_effort' => 'low' ],
						'quality'  => [ 'chat_model' => 'deepseek-v4-pro', 'temperature' => 0.7, 'max_response_tokens' => 1500, 'reasoning_effort' => 'low' ],
					],
				),
			],
			'embeddings' => [
				[
					'id'         => 'openai',
					'label'      => __( 'OpenAI', 'alpha-chat' ),
					'secret_key' => 'openai_api_key',
					'models'     => [
						[ 'id' => 'text-embedding-3-small', 'label' => __( 'text-embedding-3-small · fast, cheap', 'alpha-chat' ) ],
						[ 'id' => 'text-embedding-3-large', 'label' => __( 'text-embedding-3-large · highest quality', 'alpha-chat' ) ],
					],
				],
				[
					'id'         => 'voyage',
					'label'      => __( 'Voyage AI', 'alpha-chat' ),
					'secret_key' => 'voyage_api_key',
					'models'     => [
						[ 'id' => 'voyage-4-lite', 'label' => __( 'Voyage 4 Lite · fast, cheap', 'alpha-chat' ) ],
						[ 'id' => 'voyage-4', 'label' => __( 'Voyage 4 · balanced', 'alpha-chat' ) ],
						[ 'id' => 'voyage-4-large', 'label' => __( 'Voyage 4 Large · highest quality', 'alpha-chat' ) ],
					],
				],
			],
			'reasoning'  => [
				[ 'id' => 'off', 'label' => __( 'Off · fastest, cheapest', 'alpha-chat' ) ],
				[ 'id' => 'low', 'label' => __( 'Low · widget default', 'alpha-chat' ) ],
				[ 'id' => 'medium', 'label' => __( 'Medium · more careful', 'alpha-chat' ) ],
				[ 'id' => 'high', 'label' => __( 'High · slower, more tokens', 'alpha-chat' ) ],
			],
		];

		/**
		 * Filter the model catalog used by settings and the admin UI.
		 *
		 * @param array{providers: list<array{id: string, label: string, secret_key: string, models: list<array{id: string, label: string}>, presets: array<string, array{chat_model: string, temperature: float, max_response_tokens: int, reasoning_effort: string}>}>, embeddings: list<array{id: string, label: string, secret_key: string, models: list<array{id: string, label: string}>}>, reasoning: list<array{id: string, label: string}>} $catalog Catalog payload.
		 */
		$filtered = apply_filters( 'alpha_chat_model_catalog', $catalog );

		/**
		 * @var array{providers: list<array{id: string, label: string, secret_key: string, models: list<array{id: string, label: string}>, presets: array<string, array{chat_model: string, temperature: float, max_response_tokens: int, reasoning_effort: string}>}>, embeddings: list<array{id: string, label: string, secret_key: string, models: list<array{id: string, label: string}>}>, reasoning: list<array{id: string, label: string}>} $filtered
		 */
		return $filtered;
	}

	public static function remap_chat_model( string $model ): string {
		return self::RETIRED_CHAT_MODELS[ $model ] ?? $model;
	}

	/**
	 * @return list<string>
	 */
	public static function secret_keys(): array {
		return [ 'openai_api_key', 'anthropic_api_key', 'xai_api_key', 'deepseek_api_key', 'voyage_api_key' ];
	}

	/**
	 * @param list<array{id: string, label: string}>                                                                $models
	 * @param array<string, array{chat_model: string, temperature: float, max_response_tokens: int, reasoning_effort: string}> $presets
	 *
	 * @return array{
	 *   id: string,
	 *   label: string,
	 *   secret_key: string,
	 *   models: list<array{id: string, label: string}>,
	 *   presets: array<string, array{chat_model: string, temperature: float, max_response_tokens: int, reasoning_effort: string}>
	 * }
	 */
	private static function provider( string $id, string $label, string $secret_key, array $models, array $presets ): array {
		return [
			'id'         => $id,
			'label'      => $label,
			'secret_key' => $secret_key,
			'models'     => $models,
			'presets'    => $presets,
		];
	}
}

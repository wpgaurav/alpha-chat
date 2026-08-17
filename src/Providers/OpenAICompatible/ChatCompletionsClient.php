<?php
declare(strict_types=1);

namespace AlphaChat\Providers\OpenAICompatible;

use AlphaChat\Http\HttpClient;
use AlphaChat\Providers\Contracts\LLMProvider;
use AlphaChat\Providers\ReasoningMap;

final class ChatCompletionsClient implements LLMProvider {

	/**
	 * @param array<string, mixed> $payload_extras Extra fields merged into every request payload.
	 */
	public function __construct(
		private readonly HttpClient $http,
		private readonly string $id,
		private readonly string $endpoint,
		private readonly string $api_key,
		private readonly string $model,
		private readonly array $payload_extras = [],
	) {}

	public function id(): string {
		return $this->id;
	}

	/**
	 * @param list<array{role: string, content: string}> $messages
	 * @param array<string, mixed>                       $options
	 *
	 * @return array{content: string, usage?: array<string, int>}
	 */
	public function complete( array $messages, array $options = [] ): array {
		$model   = (string) ( $options['model'] ?? $this->model );
		$payload = self::build_payload( $model, $messages, $options, $this->payload_extras, $this->id );

		$response = $this->http->post_json(
			$this->endpoint,
			[ 'Authorization' => 'Bearer ' . $this->api_key ],
			$payload
		);

		$choice  = $response['choices'][0] ?? [];
		$content = (string) ( $choice['message']['content'] ?? '' );

		$result = [ 'content' => $content ];

		if ( isset( $response['usage'] ) && is_array( $response['usage'] ) ) {
			$result['usage'] = array_map( 'intval', $response['usage'] );
		}

		return $result;
	}

	/**
	 * @param list<array{role: string, content: string}> $messages
	 * @param array<string, mixed>                       $options
	 * @param callable(string): void                     $on_delta
	 *
	 * @return array{content: string, usage?: array<string, int>}
	 */
	public function stream( array $messages, array $options, callable $on_delta ): array {
		if ( ! $this->http->can_stream() ) {
			$result = $this->complete( $messages, $options );
			if ( '' !== $result['content'] ) {
				$on_delta( $result['content'] );
			}
			return $result;
		}

		$model   = (string) ( $options['model'] ?? $this->model );
		$payload = self::build_payload( $model, $messages, $options, $this->payload_extras, $this->id );
		$payload['stream'] = true;

		$content = '';
		$usage   = null;

		$this->http->post_sse(
			$this->endpoint,
			[ 'Authorization' => 'Bearer ' . $this->api_key ],
			$payload,
			function ( string $line ) use ( &$content, &$usage, $on_delta ): void {
				$parsed = self::parse_sse_line( $line );
				if ( null === $parsed ) {
					return;
				}
				if ( isset( $parsed['delta'] ) && '' !== $parsed['delta'] ) {
					$content .= $parsed['delta'];
					$on_delta( $parsed['delta'] );
				}
				if ( isset( $parsed['usage'] ) ) {
					$usage = $parsed['usage'];
				}
			}
		);

		$result = [ 'content' => $content ];
		if ( is_array( $usage ) ) {
			$result['usage'] = $usage;
		}

		return $result;
	}

	/**
	 * @return array{delta?: string, usage?: array<string, int>}|null
	 */
	public static function parse_sse_line( string $line ): ?array {
		$line = trim( $line );
		if ( '' === $line || str_starts_with( $line, ':' ) ) {
			return null;
		}
		if ( ! str_starts_with( $line, 'data:' ) ) {
			return null;
		}

		$data = trim( substr( $line, 5 ) );
		if ( '' === $data || '[DONE]' === $data ) {
			return null;
		}

		$decoded = json_decode( $data, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$out = [];
		$delta = $decoded['choices'][0]['delta']['content'] ?? null;
		if ( is_string( $delta ) && '' !== $delta ) {
			$out['delta'] = $delta;
		}

		if ( isset( $decoded['usage'] ) && is_array( $decoded['usage'] ) ) {
			$out['usage'] = array_map( 'intval', $decoded['usage'] );
		}

		return [] === $out ? null : $out;
	}

	/**
	 * Model id prefixes whose families are reasoning models.
	 *
	 * @var list<string>
	 */
	private const REASONING_PREFIXES = [ 'gpt-5', 'o1', 'o3', 'o4' ];

	/**
	 * @param list<array{role: string, content: string}> $messages
	 * @param array<string, mixed>                       $options
	 * @param array<string, mixed>                       $extras
	 *
	 * @return array<string, mixed>
	 */
	public static function build_payload( string $model, array $messages, array $options, array $extras = [], string $provider = '' ): array {
		$max_tokens  = $options['max_tokens'] ?? null;
		$token_param = self::uses_completion_tokens( $model ) ? 'max_completion_tokens' : 'max_tokens';

		// Reasoning models reject any sampling value other than the default and
		// fail the whole request with a 400 rather than ignoring the field, so the
		// keys have to be left out entirely rather than sent with a default.
		$fixed_sampling = self::uses_fixed_sampling( $model );

		$payload = array_filter(
			[
				'model'             => $model,
				'messages'          => $messages,
				'temperature'       => $fixed_sampling ? null : ( $options['temperature'] ?? null ),
				'top_p'             => $fixed_sampling ? null : ( $options['top_p'] ?? null ),
				$token_param        => $max_tokens,
				'presence_penalty'  => $fixed_sampling ? null : ( $options['presence_penalty'] ?? null ),
				'frequency_penalty' => $fixed_sampling ? null : ( $options['frequency_penalty'] ?? null ),
			],
			static function ( $value ): bool {
				return null !== $value;
			}
		);

		$payload = array_merge( $payload, $extras );

		$effort = $options['reasoning_effort'] ?? ( in_array( $provider, [ 'openai', 'xai', 'deepseek' ], true ) ? ReasoningMap::DEFAULT : null );
		if ( is_string( $effort ) ) {
			$payload = array_merge( $payload, ReasoningMap::payload( $provider, $effort ) );
		}

		return $payload;
	}

	public static function uses_completion_tokens( string $model ): bool {
		return self::is_reasoning_model( $model );
	}

	/**
	 * Whether the model only accepts default sampling values.
	 *
	 * Reasoning models reject temperature, top_p and the penalty fields outright.
	 * Sending a stored temperature of 0.7 makes every request fail with
	 * "does not support 0.7 with this model", so those keys must be omitted.
	 */
	public static function uses_fixed_sampling( string $model ): bool {
		return self::is_reasoning_model( $model );
	}

	private static function is_reasoning_model( string $model ): bool {
		$model = strtolower( trim( $model ) );

		foreach ( self::REASONING_PREFIXES as $prefix ) {
			if ( str_starts_with( $model, $prefix ) ) {
				return true;
			}
		}

		/**
		 * Filter whether a model is treated as a reasoning model.
		 *
		 * Reasoning models take max_completion_tokens instead of max_tokens and
		 * refuse custom sampling values. Use this for a provider or model the
		 * bundled prefix list does not cover yet.
		 *
		 * @param bool   $is_reasoning Detected from the model id.
		 * @param string $model        Lowercased model id.
		 */
		return (bool) apply_filters( 'alpha_chat_is_reasoning_model', false, $model );
	}
}

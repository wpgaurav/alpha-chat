<?php
declare(strict_types=1);

namespace AlphaChat\Settings;

use AlphaChat\Providers\ModelCatalog;
use AlphaChat\Providers\ReasoningMap;

final class SettingsRepository {

	public const OPTION_KEY = 'alpha_chat_settings';

	/**
	 * Resolved settings for this request.
	 *
	 * Rebuilding meant a recursive merge, a filter pass, and several get_option
	 * and translation calls on every single get(), which a chat turn makes dozens
	 * of times.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $resolved = null;

	/** @return array<string, mixed> */
	public function all(): array {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}

		$saved = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		$settings               = array_replace_recursive( self::defaults(), $saved );
		$settings['chat_model'] = ModelCatalog::remap_chat_model( (string) ( $settings['chat_model'] ?? '' ) );

		$this->resolved = $settings;

		return $settings;
	}

	/**
	 * Drop the per-request cache. Call after the stored option changes.
	 */
	public function flush(): void {
		$this->resolved = null;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->all()[ $key ] ?? $default;
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, mixed>
	 */
	public function update( array $input ): array {
		$sanitized = $this->sanitize( $input );
		update_option( self::OPTION_KEY, $sanitized, false );
		$this->flush();
		return $this->all();
	}

	/**
	 * @param array<string, mixed> $input
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize( array $input ): array {
		$defaults = self::defaults();
		$output   = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $output ) ) {
			$output = [];
		}

		$string_keys = [ 'llm_provider', 'chat_model', 'embedding_provider', 'embedding_model', 'welcome_message', 'fallback_message', 'system_prompt', 'launcher_nudge', 'launcher_position', 'contact_cta_label', 'contact_success_message', 'brand_name', 'contact_notify_email' ];
		foreach ( $string_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$output[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}

		if ( isset( $input['reasoning_effort'] ) ) {
			$output['reasoning_effort'] = ReasoningMap::sanitize( (string) $input['reasoning_effort'] );
		}

		// An explicit opt-in to erase a stored key. Without it, a blank value is
		// treated as "unchanged" so the masked round-trip cannot wipe a secret —
		// which previously left no way at all to remove one.
		$clear = [];
		if ( isset( $input['clear_secrets'] ) && is_array( $input['clear_secrets'] ) ) {
			$clear = array_map( 'strval', $input['clear_secrets'] );
		}

		$secret_keys = ModelCatalog::secret_keys();
		foreach ( $secret_keys as $key ) {
			if ( in_array( $key, $clear, true ) ) {
				$output[ $key ] = '';
				continue;
			}
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = trim( (string) $input[ $key ] );
			if ( '' === $value || str_contains( $value, '•' ) ) {
				continue;
			}
			$output[ $key ] = sanitize_text_field( $value );
		}

		$bool_keys = [ 'chat_enabled', 'moderation_enabled', 'show_launcher', 'contact_form_enabled', 'ai_thread_titles' ];
		foreach ( $bool_keys as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$output[ $key ] = (bool) $input[ $key ];
			}
		}

		$float_keys = [
			'temperature'                => [ 0.0, 2.0 ],
			'top_p'                      => [ 0.0, 1.0 ],
			'similarity_score_threshold' => [ 0.0, 1.0 ],
		];
		foreach ( $float_keys as $key => [ $min, $max ] ) {
			if ( isset( $input[ $key ] ) ) {
				$output[ $key ] = max( $min, min( $max, (float) $input[ $key ] ) );
			}
		}

		$int_keys = [
			'max_context_chunks'   => [ 1, 20 ],
			'chunk_size_tokens'    => [ 64, 2048 ],
			'chunk_overlap_tokens' => [ 0, 512 ],
			'max_response_tokens'  => [ 64, 4096 ],
		];
		foreach ( $int_keys as $key => [ $min, $max ] ) {
			if ( isset( $input[ $key ] ) ) {
				$output[ $key ] = max( $min, min( $max, (int) $input[ $key ] ) );
			}
		}

		if ( isset( $input['predefined_questions'] ) && is_array( $input['predefined_questions'] ) ) {
			$output['predefined_questions'] = array_values(
				array_filter(
					array_map( 'sanitize_text_field', array_map( 'strval', $input['predefined_questions'] ) ),
					static fn ( string $q ): bool => '' !== $q
				)
			);
		}

		if ( isset( $input['launcher_devices'] ) && is_array( $input['launcher_devices'] ) ) {
			$devices = [];
			foreach ( [ 'desktop', 'tablet', 'mobile' ] as $device ) {
				// Absent means unchanged-from-default (visible), not "off".
				$devices[ $device ] = array_key_exists( $device, $input['launcher_devices'] )
					? (bool) $input['launcher_devices'][ $device ]
					: true;
			}
			$output['launcher_devices'] = $devices;
		}

		if ( isset( $input['launcher_offset'] ) && is_array( $input['launcher_offset'] ) ) {
			$stored = is_array( $output['launcher_offset'] ?? null ) ? $output['launcher_offset'] : [];
			$offset = [];
			foreach ( $defaults['launcher_offset'] as $edge => $fallback ) {
				// Absent means unchanged, so a partial payload cannot silently
				// reset the three edges it did not mention.
				if ( ! array_key_exists( $edge, $input['launcher_offset'] ) ) {
					$offset[ $edge ] = array_key_exists( $edge, $stored ) ? (int) $stored[ $edge ] : (int) $fallback;
					continue;
				}
				$offset[ $edge ] = max( 0, min( 400, (int) $input['launcher_offset'][ $edge ] ) );
			}
			$output['launcher_offset'] = $offset;
		}

		if ( isset( $input['colors'] ) && is_array( $input['colors'] ) ) {
			$colors = [];
			foreach ( $defaults['colors'] as $color_key => $default_color ) {
				$colors[ $color_key ] = isset( $input['colors'][ $color_key ] )
					? self::sanitize_hex_color( (string) $input['colors'][ $color_key ], $default_color )
					: $default_color;
			}
			$output['colors'] = $colors;
		}

		/**
		 * Filter the sanitized settings before they are returned.
		 *
		 * @param array<string, mixed> $output Sanitized output.
		 * @param array<string, mixed> $input  Raw input.
		 */
		return (array) apply_filters( 'alpha_chat_settings_sanitize', $output, $input );
	}

	private static function sanitize_hex_color( string $value, string $fallback ): string {
		$color = sanitize_hex_color( $value );
		return is_string( $color ) ? $color : $fallback;
	}

	/**
	 * Mask a secret for display.
	 *
	 * Fixed width on purpose: a length-proportional mask told anyone who could
	 * read the settings how long the real key is.
	 */
	public static function mask_secret( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		return str_repeat( '•', 16 );
	}

	/**
	 * @param array<string, mixed> $settings
	 *
	 * @return array<string, mixed>
	 */
	public function mask_secrets_for_display( array $settings ): array {
		foreach ( ModelCatalog::secret_keys() as $key ) {
			if ( isset( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
				$settings[ $key ] = self::mask_secret( $settings[ $key ] );
			}
		}
		return $settings;
	}

	/** @return array<string, mixed> */
	public static function defaults(): array {
		return (array) apply_filters(
			'alpha_chat_default_settings',
			[
				'llm_provider'               => 'openai',
				'chat_enabled'               => true,
				'show_launcher'              => false,
				'launcher_nudge'             => __( 'Ask anything…', 'alpha-chat' ),
				'launcher_position'          => 'right',
				'launcher_devices'           => [
					'desktop' => true,
					'tablet'  => true,
					'mobile'  => true,
				],
				'launcher_offset'            => [
					'bottom'        => 20,
					'side'          => 20,
					'mobile_bottom' => 20,
					'mobile_side'   => 20,
				],
				'ai_thread_titles'           => true,
				'brand_name'                 => get_bloginfo( 'name' ),
				'contact_form_enabled'       => true,
				'contact_cta_label'          => __( 'Still need help? Email us', 'alpha-chat' ),
				'contact_success_message'    => __( 'Thanks! We\'ll get back to you soon.', 'alpha-chat' ),
				'contact_notify_email'       => (string) get_option( 'admin_email' ),
				'moderation_enabled'         => true,
				'openai_api_key'             => '',
				'anthropic_api_key'          => '',
				'xai_api_key'                => '',
				'deepseek_api_key'           => '',
				'voyage_api_key'             => '',
				'chat_model'                 => 'gpt-5.6-luna',
				'reasoning_effort'           => ReasoningMap::DEFAULT,
				'embedding_provider'         => 'openai',
				'embedding_model'            => 'text-embedding-3-small',
				'temperature'                => 0.7,
				'top_p'                      => 1.0,
				'max_response_tokens'        => 1600,
				'max_context_chunks'         => 5,
				'chunk_size_tokens'          => 400,
				'chunk_overlap_tokens'       => 50,
				'similarity_score_threshold' => 0.4,
				'welcome_message'            => __( 'Hi! How can I help you today?', 'alpha-chat' ),
				'fallback_message'           => __( "Sorry, I couldn't find an answer for that.", 'alpha-chat' ),
				'system_prompt'              => __( 'You are a helpful assistant. Answer using the provided context. If the answer is not in the context, say so.', 'alpha-chat' ),
				'predefined_questions'       => [],
				'colors'                     => [
					'background'       => '#ffffff',
					'assistant_bubble' => '#eef2ff',
					'user_bubble'      => '#1f2937',
					'accent'           => '#4f46e5',
				],
			]
		);
	}
}

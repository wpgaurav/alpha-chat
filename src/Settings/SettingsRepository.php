<?php
declare(strict_types=1);

namespace AlphaChat\Settings;

final class SettingsRepository {

	public const OPTION_KEY = 'alpha_chat_settings';

	/** @return array<string, mixed> */
	public function all(): array {
		$saved = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		return array_replace_recursive( self::defaults(), $saved );
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

		$string_keys = [ 'llm_provider', 'chat_model', 'embedding_model', 'welcome_message', 'fallback_message', 'system_prompt', 'launcher_nudge', 'launcher_position', 'contact_cta_label', 'contact_success_message', 'brand_name', 'contact_notify_email' ];
		foreach ( $string_keys as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$output[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}

		$secret_keys = [ 'openai_api_key', 'anthropic_api_key' ];
		foreach ( $secret_keys as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$value = (string) $input[ $key ];
			if ( '' === $value || str_contains( $value, '•' ) ) {
				continue;
			}
			$output[ $key ] = sanitize_text_field( $value );
		}

		$bool_keys = [ 'chat_enabled', 'moderation_enabled', 'show_launcher', 'contact_form_enabled' ];
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
			'max_context_chunks'  => [ 1, 20 ],
			'chunk_size_tokens'   => [ 64, 2048 ],
			'chunk_overlap_tokens' => [ 0, 512 ],
			'max_response_tokens' => [ 64, 4096 ],
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
	 * Mask secret values for display. Returns a placeholder of bullets matching real length.
	 */
	public static function mask_secret( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		$length = min( 24, max( 8, strlen( $value ) ) );
		return str_repeat( '•', $length );
	}

	/**
	 * @param array<string, mixed> $settings
	 *
	 * @return array<string, mixed>
	 */
	public function mask_secrets_for_display( array $settings ): array {
		foreach ( [ 'openai_api_key', 'anthropic_api_key' ] as $key ) {
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
				'brand_name'                 => get_bloginfo( 'name' ),
				'contact_form_enabled'       => true,
				'contact_cta_label'          => __( 'Still need help? Email us', 'alpha-chat' ),
				'contact_success_message'    => __( 'Thanks! We\'ll get back to you soon.', 'alpha-chat' ),
				'contact_notify_email'       => (string) get_option( 'admin_email' ),
				'moderation_enabled'         => true,
				'openai_api_key'             => '',
				'anthropic_api_key'          => '',
				'chat_model'                 => 'gpt-5.4-mini',
				'embedding_model'            => 'text-embedding-3-small',
				'temperature'                => 0.7,
				'top_p'                      => 1.0,
				'max_response_tokens'        => 800,
				'max_context_chunks'         => 5,
				'chunk_size_tokens'          => 400,
				'chunk_overlap_tokens'       => 50,
				'similarity_score_threshold' => 0.4,
				'welcome_message'            => __( 'Hi! How can I help you today?', 'alpha-chat' ),
				'fallback_message'           => __( "Sorry, I couldn't find an answer for that.", 'alpha-chat' ),
				'system_prompt'              => __( 'You are a helpful assistant. Answer using the provided context. If the answer is not in the context, say so.', 'alpha-chat' ),
				'predefined_questions'       => [],
				'colors'                     => [
					'background'          => '#ffffff',
					'assistant_bubble'    => '#eef2ff',
					'user_bubble'         => '#1f2937',
					'accent'              => '#4f46e5',
				],
			]
		);
	}
}

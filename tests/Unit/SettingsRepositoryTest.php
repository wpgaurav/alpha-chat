<?php
declare(strict_types=1);

namespace AlphaChat\Tests\Unit;

use AlphaChat\Settings\SettingsRepository;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class SettingsRepositoryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->alias( static fn ( string $v ): string => trim( wp_strip_all_tags_stub( $v ) ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( string $v ): string => strip_tags( $v ) );
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_hex_color' )->alias(
			static function ( string $value ): ?string {
				return preg_match( '/^#(?:[0-9a-fA-F]{3}){1,2}$/', $value ) === 1 ? $value : null;
			}
		);

		Filters\expectApplied( 'alpha_chat_default_settings' )
			->zeroOrMoreTimes()
			->andReturnFirstArg();
		Filters\expectApplied( 'alpha_chat_settings_sanitize' )
			->zeroOrMoreTimes()
			->andReturnFirstArg();
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_defaults_contain_expected_keys(): void {
		$defaults = SettingsRepository::defaults();
		$this->assertArrayHasKey( 'llm_provider', $defaults );
		$this->assertArrayHasKey( 'chat_model', $defaults );
		$this->assertArrayHasKey( 'similarity_score_threshold', $defaults );
		$this->assertArrayHasKey( 'colors', $defaults );
	}

	public function test_mask_secret_returns_bullets(): void {
		$this->assertSame( '', SettingsRepository::mask_secret( '' ) );
		$this->assertSame( str_repeat( '•', 8 ), SettingsRepository::mask_secret( 'a' ) );
		$this->assertSame( str_repeat( '•', 24 ), SettingsRepository::mask_secret( str_repeat( 'a', 100 ) ) );
	}

	public function test_mask_secrets_for_display_masks_api_keys(): void {
		$repo   = new SettingsRepository();
		$masked = $repo->mask_secrets_for_display(
			[
				'openai_api_key'    => 'sk-abcdef1234567890',
				'anthropic_api_key' => '',
				'other'             => 'keep me',
			]
		);

		$this->assertStringContainsString( '•', $masked['openai_api_key'] );
		$this->assertSame( '', $masked['anthropic_api_key'] );
		$this->assertSame( 'keep me', $masked['other'] );
	}

	public function test_sanitize_clamps_ranges(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$repo      = new SettingsRepository();
		$sanitized = $repo->sanitize(
			[
				'temperature'                => 9.9,
				'top_p'                      => -1.0,
				'similarity_score_threshold' => 0.55,
				'max_context_chunks'         => 999,
				'max_response_tokens'        => 10,
			]
		);

		$this->assertSame( 2.0, $sanitized['temperature'] );
		$this->assertSame( 0.0, $sanitized['top_p'] );
		$this->assertEqualsWithDelta( 0.55, $sanitized['similarity_score_threshold'], 0.0001 );
		$this->assertSame( 20, $sanitized['max_context_chunks'] );
		$this->assertSame( 64, $sanitized['max_response_tokens'] );
	}

	public function test_sanitize_preserves_existing_secret_when_bullet_placeholder_submitted(): void {
		Functions\when( 'get_option' )->justReturn(
			[
				'openai_api_key' => 'sk-existing',
			]
		);

		$repo      = new SettingsRepository();
		$sanitized = $repo->sanitize( [ 'openai_api_key' => str_repeat( '•', 12 ) ] );

		$this->assertSame( 'sk-existing', $sanitized['openai_api_key'] );
	}

	public function test_sanitize_accepts_new_secret(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$repo      = new SettingsRepository();
		$sanitized = $repo->sanitize( [ 'openai_api_key' => 'sk-new-value' ] );

		$this->assertSame( 'sk-new-value', $sanitized['openai_api_key'] );
	}

	public function test_sanitize_bool_coerces_truthy_values(): void {
		Functions\when( 'get_option' )->justReturn( [] );

		$repo      = new SettingsRepository();
		$sanitized = $repo->sanitize(
			[
				'chat_enabled'       => 1,
				'moderation_enabled' => '',
			]
		);

		$this->assertTrue( $sanitized['chat_enabled'] );
		$this->assertFalse( $sanitized['moderation_enabled'] );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_strip_all_tags_stub' ) ) {
	function wp_strip_all_tags_stub( string $value ): string {
		return strip_tags( $value );
	}
}

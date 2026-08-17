<?php
declare(strict_types=1);

namespace AlphaChat\REST;

use AlphaChat\Chat\MessageRepository;
use AlphaChat\KnowledgeBase\Indexer;
use AlphaChat\Providers\ModelCatalog;
use AlphaChat\Scheduler\ReindexScheduler;
use AlphaChat\Settings\SettingsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SettingsController {

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly Indexer $indexer,
		private readonly MessageRepository $messages,
		private readonly ReindexScheduler $scheduler,
	) {}

	public function register( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/settings',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'read' ],
					'permission_callback' => [ self::class, 'can_manage' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'write' ],
					'permission_callback' => [ self::class, 'can_manage' ],
					'args'                => [
						'data' => [
							'required'    => true,
							'type'        => 'object',
							'description' => __( 'Settings payload.', 'alpha-chat' ),
						],
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/dashboard',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'dashboard' ],
				'permission_callback' => [ self::class, 'can_manage' ],
				'args'                => [
					'days' => [ 'type' => 'integer', 'default' => 14 ],
				],
			]
		);
	}

	public function dashboard( WP_REST_Request $request ): WP_REST_Response {
		$days = max( 1, min( 90, (int) $request->get_param( 'days' ) ) );

		return new WP_REST_Response(
			[
				'stats' => $this->indexer->stats(),
				'queue' => $this->scheduler->queue_counts(),
				'chart' => $this->messages->daily_chart( $days ),
			]
		);
	}

	public function read( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$raw      = $this->settings->all();
		$settings = $this->settings->mask_secrets_for_display( $raw );

		return new WP_REST_Response(
			[
				'settings' => $settings,
				'stats'    => $this->indexer->stats(),
				'catalog'  => ModelCatalog::all(),
				'runtime'  => self::runtime( $raw ),
			]
		);
	}

	/**
	 * Facts about the live configuration that the settings values alone hide.
	 *
	 * `moderation_enabled` can be on while no moderation actually runs, because
	 * the only provider is OpenAI's endpoint — an Anthropic-only site would show
	 * a checked box and screen nothing.
	 *
	 * @param array<string, mixed> $settings Unmasked settings.
	 *
	 * @return array{moderation_active: bool, moderation_requires_key: bool}
	 */
	private static function runtime( array $settings ): array {
		$has_openai_key = '' !== trim( (string) ( $settings['openai_api_key'] ?? '' ) );
		$wants          = (bool) ( $settings['moderation_enabled'] ?? true );

		return [
			'moderation_active'       => $wants && $has_openai_key,
			'moderation_requires_key' => $wants && ! $has_openai_key,
		];
	}

	public function write( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$data = $request->get_param( 'data' );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'alpha_chat_invalid_payload', __( 'Invalid payload.', 'alpha-chat' ), [ 'status' => 400 ] );
		}

		$saved = $this->settings->update( $data );

		if ( '' !== (string) ( $saved['openai_api_key'] ?? '' ) ) {
			self::clear_api_key_errors();
		}

		return new WP_REST_Response(
			[
				'settings' => $this->settings->mask_secrets_for_display( $saved ),
				'stats'    => $this->indexer->stats(),
				'catalog'  => ModelCatalog::all(),
				'runtime'  => self::runtime( $saved ),
			]
		);
	}

	private static function clear_api_key_errors(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $wpdb->postmeta . ' WHERE meta_key = %s AND meta_value LIKE %s',
				'_alpha_chat_index_error',
				'%API key%'
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}
}

<?php
declare(strict_types=1);

namespace AlphaChat\REST;

use AlphaChat\KnowledgeBase\Indexer;
use AlphaChat\Settings\SettingsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SettingsController {

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly Indexer $indexer,
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
	}

	public function read( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$settings = $this->settings->mask_secrets_for_display( $this->settings->all() );

		return new WP_REST_Response(
			[
				'settings' => $settings,
				'stats'    => $this->indexer->stats(),
			]
		);
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

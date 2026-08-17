<?php
declare(strict_types=1);

namespace AlphaChat\REST;

use AlphaChat\Support\LogRepository;
use WP_REST_Request;
use WP_REST_Response;

final class LogsController {

	public function __construct(
		private readonly LogRepository $logs,
	) {}

	public function register( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/logs',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list' ],
					'permission_callback' => [ SettingsController::class, 'can_manage' ],
					'args'                => [
						'page'     => [ 'type' => 'integer', 'default' => 1 ],
						'per_page' => [ 'type' => 'integer', 'default' => 25 ],
						'level'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => '' ],
					],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'clear' ],
					'permission_callback' => [ SettingsController::class, 'can_manage' ],
				],
			]
		);
	}

	public function list( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );
		$level    = (string) $request->get_param( 'level' );

		$result = $this->logs->list( $page, $per_page, $level );

		return new WP_REST_Response(
			[
				'items'  => $result['items'],
				'total'  => $result['total'],
				'counts' => $this->logs->counts(),
			]
		);
	}

	public function clear(): WP_REST_Response {
		return new WP_REST_Response( [ 'deleted' => $this->logs->clear() ] );
	}
}

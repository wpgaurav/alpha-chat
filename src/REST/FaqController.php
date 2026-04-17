<?php
declare(strict_types=1);

namespace AlphaChat\REST;

use AlphaChat\Chat\FaqRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class FaqController {

	public function __construct( private readonly FaqRepository $faqs ) {}

	public function register( string $namespace ): void {
		register_rest_route(
			$namespace,
			'/faqs',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'list' ],
					'permission_callback' => [ SettingsController::class, 'can_manage' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create' ],
					'permission_callback' => [ SettingsController::class, 'can_manage' ],
					'args'                => [
						'question'   => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
						'answer'     => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'wp_kses_post' ],
						'sort_order' => [ 'type' => 'integer', 'default' => 0 ],
						'enabled'    => [ 'type' => 'boolean', 'default' => true ],
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/faqs/(?P<id>\d+)',
			[
				[
					'methods'             => 'PUT',
					'callback'            => [ $this, 'update' ],
					'permission_callback' => [ SettingsController::class, 'can_manage' ],
					'args'                => [
						'id'         => [ 'type' => 'integer', 'required' => true ],
						'question'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
						'answer'     => [ 'type' => 'string', 'sanitize_callback' => 'wp_kses_post' ],
						'sort_order' => [ 'type' => 'integer' ],
						'enabled'    => [ 'type' => 'boolean' ],
					],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'delete' ],
					'permission_callback' => [ SettingsController::class, 'can_manage' ],
					'args'                => [
						'id' => [ 'type' => 'integer', 'required' => true ],
					],
				],
			]
		);
	}

	public function list(): WP_REST_Response {
		return new WP_REST_Response( [ 'items' => $this->faqs->all() ] );
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$question = trim( (string) $request->get_param( 'question' ) );
		$answer   = trim( (string) $request->get_param( 'answer' ) );
		if ( '' === $question || '' === $answer ) {
			return new WP_Error( 'alpha_chat_invalid', __( 'Question and answer are required.', 'alpha-chat' ), [ 'status' => 400 ] );
		}

		$id = $this->faqs->create(
			[
				'question'   => $question,
				'answer'     => $answer,
				'sort_order' => (int) $request->get_param( 'sort_order' ),
				'enabled'    => (bool) $request->get_param( 'enabled' ),
			]
		);

		return new WP_REST_Response( [ 'id' => $id, 'item' => $this->faqs->find( $id ) ] );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request->get_param( 'id' );
		if ( ! $this->faqs->find( $id ) ) {
			return new WP_Error( 'alpha_chat_not_found', __( 'FAQ not found.', 'alpha-chat' ), [ 'status' => 404 ] );
		}

		$data = [];
		foreach ( [ 'question', 'answer', 'sort_order', 'enabled' ] as $key ) {
			$value = $request->get_param( $key );
			if ( null !== $value ) {
				$data[ $key ] = $value;
			}
		}

		$this->faqs->update( $id, $data );

		return new WP_REST_Response( [ 'item' => $this->faqs->find( $id ) ] );
	}

	public function delete( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request->get_param( 'id' );
		return new WP_REST_Response( [ 'deleted' => $this->faqs->delete( $id ) ] );
	}
}

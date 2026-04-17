<?php
declare(strict_types=1);

namespace AlphaChat\Admin;

use AlphaChat\KnowledgeBase\Indexer;
use WP_Post;

final class PostRowActions {

	public function __construct( private readonly Indexer $indexer ) {}

	public function register(): void {
		foreach ( [ 'post', 'page' ] as $type ) {
			add_filter( "{$type}_row_actions", [ $this, 'add_action' ], 10, 2 );
		}
	}

	/**
	 * @param array<string, string> $actions
	 *
	 * @return array<string, string>
	 */
	public function add_action( array $actions, WP_Post $post ): array {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$indexed = $this->indexer->is_indexed( $post->ID );
		$label   = $indexed ? __( 'Remove from Alpha Chat', 'alpha-chat' ) : __( 'Add to Alpha Chat', 'alpha-chat' );
		$action  = $indexed ? 'remove' : 'add';

		$actions['alpha_chat'] = sprintf(
			'<button type="button" class="button-link alpha-chat-row-action%s" data-post-id="%d" data-action="%s">%s</button>',
			$indexed ? ' button-link-delete' : '',
			$post->ID,
			esc_attr( $action ),
			esc_html( $label )
		);

		return $actions;
	}
}

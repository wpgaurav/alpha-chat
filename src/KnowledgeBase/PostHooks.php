<?php
declare(strict_types=1);

namespace AlphaChat\KnowledgeBase;

use AlphaChat\Scheduler\ReindexScheduler;
use WP_Post;

final class PostHooks {

	public function __construct(
		private readonly Indexer $indexer,
		private readonly ReindexScheduler $scheduler,
	) {}

	public function register(): void {
		add_action( 'save_post', [ $this, 'on_save_post' ], 10, 3 );
		add_action( 'delete_post', [ $this, 'on_delete_post' ], 10, 1 );
		add_action( 'wp_trash_post', [ $this, 'on_delete_post' ], 10, 1 );
	}

	public function on_save_post( int $post_id, WP_Post $post, bool $update ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( isset( $_REQUEST['bulk_edit'] ) || isset( $_REQUEST['_inline_edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		if ( ! $this->indexer->is_indexed( $post_id ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			$this->indexer->forget_post( $post_id );
			return;
		}

		if ( $update ) {
			$this->indexer->mark_for_reindex( $post_id );
			$this->scheduler->queue_index( $post_id );
		}
	}

	public function on_delete_post( int $post_id ): void {
		$this->indexer->forget_post( $post_id );
	}
}

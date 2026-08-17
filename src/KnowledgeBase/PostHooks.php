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
		add_action( 'wp_trash_post', [ $this, 'on_trash_post' ], 10, 1 );
		add_action( 'untrashed_post', [ $this, 'on_untrash_post' ], 10, 1 );
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
		// delete_post also fires for every revision and autosave, which are never
		// indexed. Skipping them avoids a DELETE plus six meta deletes each time
		// WordPress prunes revision history.
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$this->indexer->forget_post( $post_id );
	}

	/**
	 * Trashing drops the chunks but remembers that the post was in the knowledge
	 * base, so restoring it can put it back.
	 */
	public function on_trash_post( int $post_id ): void {
		if ( ! $this->indexer->is_indexed( $post_id ) ) {
			return;
		}

		$this->indexer->forget_post( $post_id );
		update_post_meta( $post_id, '_alpha_chat_restore_on_untrash', 1 );
	}

	public function on_untrash_post( int $post_id ): void {
		if ( ! get_post_meta( $post_id, '_alpha_chat_restore_on_untrash', true ) ) {
			return;
		}

		delete_post_meta( $post_id, '_alpha_chat_restore_on_untrash' );

		if ( 'publish' === get_post_status( $post_id ) ) {
			$this->scheduler->queue_index( $post_id );
		}
	}
}

<?php
declare(strict_types=1);

namespace AlphaChat\KnowledgeBase;

use AlphaChat\Database\Schema;
use AlphaChat\Providers\ProviderFactory;
use AlphaChat\Providers\VectorStore\DatabaseVectorStore;
use AlphaChat\Settings\SettingsRepository;
use AlphaChat\Support\Logger;
use AlphaChat\Text\Chunker;
use AlphaChat\Text\TokenCounter;
use Throwable;
use WP_Post;

final class Indexer {

	public function __construct(
		private readonly ProviderFactory $providers,
		private readonly SettingsRepository $settings,
		private readonly TokenCounter $counter,
		private readonly Logger $logger,
	) {}

	public function index_post( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( 'publish' !== $post->post_status ) {
			$this->forget_post( $post_id );
			return false;
		}

		$text = wp_strip_all_tags( (string) apply_filters( 'the_content', $post->post_content ), true );
		$text = trim( $post->post_title . "\n\n" . $text );

		if ( '' === $text ) {
			$this->forget_post( $post_id );
			return false;
		}

		$chunker = new Chunker(
			$this->counter,
			(int) $this->settings->get( 'chunk_size_tokens', 400 ),
			(int) $this->settings->get( 'chunk_overlap_tokens', 50 ),
		);

		$chunks = $chunker->split( $text );
		if ( empty( $chunks ) ) {
			return false;
		}

		try {
			$embeddings = $this->providers->embeddings()->embed( $chunks );
		} catch ( Throwable $e ) {
			$this->logger->error( 'Embedding generation failed', [ 'post_id' => $post_id, 'error' => $e->getMessage() ] );
			update_post_meta( $post_id, '_alpha_chat_index_error', $e->getMessage() );
			return false;
		}

		if ( count( $embeddings ) !== count( $chunks ) ) {
			$this->logger->warning( 'Embedding count mismatch', [ 'post_id' => $post_id, 'chunks' => count( $chunks ), 'vectors' => count( $embeddings ) ] );
			return false;
		}

		$store = $this->providers->vector_store();
		$model = $this->providers->embeddings()->model();

		$store->delete( DatabaseVectorStore::build_id( 'post', $post_id, -1 ) );

		foreach ( $chunks as $index => $chunk ) {
			$store->upsert(
				DatabaseVectorStore::build_id( 'post', $post_id, $index ),
				$embeddings[ $index ],
				[
					'content'         => $chunk,
					'token_count'     => $this->counter->count( $chunk ),
					'content_hash'    => hash( 'sha256', $chunk ),
					'embedding_model' => $model,
				]
			);
		}

		update_post_meta( $post_id, '_alpha_chat_indexed_at', time() );
		update_post_meta( $post_id, '_alpha_chat_chunk_count', count( $chunks ) );
		delete_post_meta( $post_id, '_alpha_chat_index_error' );
		delete_post_meta( $post_id, '_alpha_chat_needs_update' );

		do_action( 'alpha_chat_post_indexed', $post_id, count( $chunks ) );

		return true;
	}

	public function forget_post( int $post_id ): void {
		$store = $this->providers->vector_store();
		$store->delete( DatabaseVectorStore::build_id( 'post', $post_id, -1 ) );

		delete_post_meta( $post_id, '_alpha_chat_indexed_at' );
		delete_post_meta( $post_id, '_alpha_chat_chunk_count' );
		delete_post_meta( $post_id, '_alpha_chat_index_error' );
		delete_post_meta( $post_id, '_alpha_chat_needs_update' );

		do_action( 'alpha_chat_post_forgotten', $post_id );
	}

	public function mark_for_reindex( int $post_id ): void {
		update_post_meta( $post_id, '_alpha_chat_needs_update', 1 );
	}

	public function is_indexed( int $post_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM ' . esc_sql( Schema::chunks_table() ) . ' WHERE source_type = %s AND source_id = %d LIMIT 1',
				'post',
				$post_id
			)
		);
	}

	/** @return array{chunks: int, posts: int} */
	public function stats(): array {
		global $wpdb;

		$chunks = $wpdb->get_var( 'SELECT COUNT(*) FROM ' . esc_sql( Schema::chunks_table() ) );
		$posts  = $wpdb->get_var( 'SELECT COUNT(DISTINCT source_id) FROM ' . esc_sql( Schema::chunks_table() ) . " WHERE source_type = 'post'" );

		return [
			'chunks' => (int) $chunks,
			'posts'  => (int) $posts,
		];
	}
}

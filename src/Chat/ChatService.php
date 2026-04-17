<?php
declare(strict_types=1);

namespace AlphaChat\Chat;

use AlphaChat\Providers\ProviderFactory;
use AlphaChat\Settings\SettingsRepository;
use AlphaChat\Support\Logger;
use AlphaChat\Text\TokenCounter;
use RuntimeException;
use Throwable;

final class ChatService {

	public function __construct(
		private readonly ProviderFactory $providers,
		private readonly SettingsRepository $settings,
		private readonly ThreadRepository $threads,
		private readonly MessageRepository $messages,
		private readonly TokenCounter $counter,
		private readonly Logger $logger,
		private readonly FaqRepository $faqs,
	) {}

	/**
	 * @return array{thread_uuid: string, reply: string, flagged?: bool, sources: list<array{source_type: string, source_id: int, score: float}>}
	 */
	public function send( string $message, ?string $thread_uuid, string $session_hash, ?int $user_id = null ): array {
		$message = trim( $message );
		if ( '' === $message ) {
			throw new RuntimeException( 'Empty message.' );
		}

		if ( (bool) $this->settings->get( 'moderation_enabled', true ) ) {
			try {
				$moderation = $this->providers->moderation()->check( $message );
				if ( $moderation['flagged'] ) {
					do_action( 'alpha_chat_message_flagged', $message, $moderation );
					return [
						'thread_uuid' => $thread_uuid ?? '',
						'reply'       => (string) $this->settings->get( 'fallback_message', '' ),
						'flagged'     => true,
						'sources'     => [],
					];
				}
			} catch ( Throwable $e ) {
				$this->logger->warning( 'Moderation check failed', [ 'error' => $e->getMessage() ] );
			}
		}

		$thread = null === $thread_uuid ? null : $this->threads->find_by_uuid( $thread_uuid );
		if ( null === $thread ) {
			$thread = $this->threads->create( $session_hash, $user_id );
		}

		$thread_id = (int) $thread['id'];
		$this->messages->append( $thread_id, 'user', $message, $this->counter->count( $message ) );

		try {
			$query_vectors = $this->providers->embeddings()->embed( [ $message ] );
		} catch ( Throwable $e ) {
			$this->logger->error( 'Query embedding failed', [ 'error' => $e->getMessage() ] );
			throw $e;
		}

		$query_vector = $query_vectors[0] ?? [];
		if ( empty( $query_vector ) ) {
			throw new RuntimeException( 'Could not produce embeddings for the query.' );
		}

		$max_chunks = (int) $this->settings->get( 'max_context_chunks', 5 );
		$threshold  = (float) $this->settings->get( 'similarity_score_threshold', 0.4 );

		$chunks = $this->providers->vector_store()->search( $query_vector, $max_chunks, $threshold );

		/**
		 * Filter the chunks used as context before sending to the LLM.
		 *
		 * @param list<array{id: string, score: float, metadata: array<string, mixed>}> $chunks
		 * @param string                                                                $message
		 */
		$chunks = self::enrich_chunks( (array) apply_filters( 'alpha_chat_retrieved_chunks', $chunks, $message ) );
		$faqs   = $this->faqs->all( true );

		if ( empty( $chunks ) && empty( $faqs ) ) {
			$fallback = (string) $this->settings->get( 'fallback_message', '' );
			$this->messages->append( $thread_id, 'assistant', $fallback, $this->counter->count( $fallback ), [ 'sources' => [], 'skipped_llm' => true ] );
			$this->threads->touch( $thread_id, 2, '' === $thread['title'] ? wp_trim_words( $message, 8 ) : null );
			do_action( 'alpha_chat_unanswered_question', $thread_id, $message );
			return [
				'thread_uuid' => (string) $thread['uuid'],
				'reply'       => $fallback,
				'sources'     => [],
			];
		}

		$history = $this->messages->for_thread( $thread_id, 12 );
		$prompt  = $this->build_messages( $message, $chunks, $faqs, $history );

		$options = [
			'temperature' => (float) $this->settings->get( 'temperature', 0.7 ),
			'top_p'       => (float) $this->settings->get( 'top_p', 1.0 ),
			'max_tokens'  => (int) $this->settings->get( 'max_response_tokens', 800 ),
		];

		try {
			$completion = $this->providers->llm()->complete( $prompt, $options );
		} catch ( Throwable $e ) {
			$this->logger->error( 'LLM completion failed', [ 'error' => $e->getMessage() ] );
			throw $e;
		}

		$reply = trim( $completion['content'] );
		if ( '' === $reply ) {
			$reply = (string) $this->settings->get( 'fallback_message', '' );
		}

		$sources = self::hydrate_sources( $chunks );

		$this->messages->append(
			$thread_id,
			'assistant',
			$reply,
			$this->counter->count( $reply ),
			[
				'sources' => $sources,
				'usage'   => $completion['usage'] ?? null,
				'model'   => (string) $this->settings->get( 'chat_model', '' ),
			]
		);

		$this->threads->touch(
			$thread_id,
			2,
			'' === $thread['title'] ? wp_trim_words( $message, 8 ) : null
		);

		do_action( 'alpha_chat_message_answered', $thread_id, $message, $reply, $sources );

		if ( empty( $chunks ) ) {
			do_action( 'alpha_chat_unanswered_question', $thread_id, $message );
		}

		return [
			'thread_uuid' => (string) $thread['uuid'],
			'reply'       => $reply,
			'sources'     => $sources,
		];
	}

	/**
	 * @param list<array{id: string, score: float, metadata: array<string, mixed>}>           $chunks
	 * @param list<array{id:int, question:string, answer:string, sort_order:int, enabled:bool, created_at:string, updated_at:string}> $faqs
	 * @param list<array<string, mixed>>                                                      $history
	 *
	 * @return list<array{role: string, content: string}>
	 */
	private function build_messages( string $message, array $chunks, array $faqs, array $history ): array {
		$brand    = (string) $this->settings->get( 'brand_name', (string) get_bloginfo( 'name' ) );
		$identity = sprintf(
			"You are the AI assistant for %s. If someone asks who you are, what this chat is, or similar, explain that you are %s's AI helper that answers questions based on the site's content and curated Q&A. Do not invent a human name or claim to be a person. Do not use outside knowledge beyond the context provided below.",
			$brand,
			$brand
		);

		$system_setting = trim( (string) $this->settings->get( 'system_prompt', '' ) );
		$system         = $identity . ( '' !== $system_setting ? "\n\n" . $system_setting : '' );

		if ( ! empty( $faqs ) ) {
			$faq_block = "Curated Q&A (authoritative — use these verbatim when the user's question matches):\n\n";
			foreach ( $faqs as $i => $faq ) {
				$faq_block .= sprintf( "Q%d: %s\nA%d: %s\n\n", $i + 1, trim( $faq['question'] ), $i + 1, trim( $faq['answer'] ) );
			}
			$system .= "\n\n" . trim( $faq_block );
		}

		if ( ! empty( $chunks ) ) {
			$context  = "Numbered site context. Ground every factual claim in these passages and do not invent sources.\n";
			$context .= "When the context covers the topic, answer in 2–5 short sentences.\n";
			$context .= "When it doesn't, say so briefly in your own words.\n";
			$context .= "Do NOT include bracketed citation markers like [1] or [2] in your reply — the UI renders source links separately.\n\n";
			foreach ( $chunks as $i => $chunk ) {
				$meta  = (array) ( $chunk['metadata'] ?? [] );
				$title = (string) ( $meta['title'] ?? '' );
				$url   = (string) ( $meta['url'] ?? '' );
				$body  = trim( (string) ( $meta['content'] ?? '' ) );
				$header = sprintf( '[%d]', $i + 1 );
				if ( '' !== $title ) {
					$header .= ' ' . $title;
				}
				if ( '' !== $url ) {
					$header .= ' (' . $url . ')';
				}
				$context .= $header . "\n" . $body . "\n\n";
			}
			$system = trim( $system . "\n\n" . $context );
		}

		$out = [ [ 'role' => 'system', 'content' => $system ] ];

		foreach ( $history as $entry ) {
			if ( ! in_array( $entry['role'], [ 'user', 'assistant' ], true ) ) {
				continue;
			}
			$out[] = [ 'role' => (string) $entry['role'], 'content' => (string) $entry['content'] ];
		}

		$last = end( $out );
		if ( false === $last || 'user' !== $last['role'] || $last['content'] !== $message ) {
			$out[] = [ 'role' => 'user', 'content' => $message ];
		}

		return $out;
	}

	/**
	 * @param list<array{id: string, score: float, metadata: array<string, mixed>}> $chunks
	 *
	 * @return list<array{id: string, score: float, metadata: array<string, mixed>}>
	 */
	private static function enrich_chunks( array $chunks ): array {
		foreach ( $chunks as &$chunk ) {
			$meta = (array) ( $chunk['metadata'] ?? [] );
			$type = (string) ( $meta['source_type'] ?? '' );
			$pid  = (int) ( $meta['source_id'] ?? 0 );

			if ( 'post' === $type && $pid > 0 ) {
				$meta['title'] = (string) get_the_title( $pid );
				$meta['url']   = (string) get_permalink( $pid );
				$thumb         = get_the_post_thumbnail_url( $pid, 'medium' );
				$meta['image'] = is_string( $thumb ) ? $thumb : '';
			}

			$chunk['metadata'] = $meta;
		}

		return array_values( $chunks );
	}

	/**
	 * @param list<array{id: string, score: float, metadata: array<string, mixed>}> $chunks
	 *
	 * @return list<array{source_type: string, source_id: int, score: float, title: string, url: string, image: string}>
	 */
	private static function hydrate_sources( array $chunks ): array {
		$seen = [];
		$out  = [];
		foreach ( $chunks as $chunk ) {
			$meta = (array) ( $chunk['metadata'] ?? [] );
			$type = (string) ( $meta['source_type'] ?? '' );
			$sid  = (int) ( $meta['source_id'] ?? 0 );
			$key  = $type . ':' . $sid;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[] = [
				'source_type' => $type,
				'source_id'   => $sid,
				'score'       => (float) ( $chunk['score'] ?? 0 ),
				'title'       => (string) ( $meta['title'] ?? '' ),
				'url'         => (string) ( $meta['url'] ?? '' ),
				'image'       => (string) ( $meta['image'] ?? '' ),
			];
		}
		return $out;
	}
}

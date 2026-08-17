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
	 * @return array{thread_uuid: string, reply: string, flagged?: bool, sources: list<array<string, mixed>>}
	 * @throws RuntimeException When a message cannot be processed.
	 */
	public function send( string $message, ?string $thread_uuid, string $session_hash, ?int $user_id = null, string $origin_url = '', string $origin_title = '' ): array {
		$prepared = $this->prepare( $message, $thread_uuid, $session_hash, $user_id, $origin_url, $origin_title );
		if ( isset( $prepared['result'] ) ) {
			return $prepared['result'];
		}

		if ( ! isset( $prepared['prompt'], $prepared['options'], $prepared['thread'], $prepared['message'], $prepared['chunks'] ) ) {
			throw new RuntimeException( 'Invalid chat state.' );
		}

		try {
			$completion = $this->providers->llm()->complete( $prepared['prompt'], $prepared['options'] );
		} catch ( Throwable $e ) {
			$this->logger->error( 'LLM completion failed', [ 'error' => $e->getMessage() ] );
			throw $e;
		}

		return $this->finalize( $prepared, $completion );
	}

	/**
	 * @param callable(string): void $on_delta
	 *
	 * @return array{thread_uuid: string, reply: string, flagged?: bool, sources: list<array<string, mixed>>}
	 */
	public function send_streaming( string $message, ?string $thread_uuid, string $session_hash, ?int $user_id, string $origin_url, callable $on_delta, string $origin_title = '' ): array {
		$prepared = $this->prepare( $message, $thread_uuid, $session_hash, $user_id, $origin_url, $origin_title );
		if ( isset( $prepared['result'] ) ) {
			return $prepared['result'];
		}

		if ( ! isset( $prepared['prompt'], $prepared['options'], $prepared['thread'], $prepared['message'], $prepared['chunks'] ) ) {
			throw new RuntimeException( 'Invalid chat state.' );
		}

		try {
			$completion = $this->providers->llm()->stream( $prepared['prompt'], $prepared['options'], $on_delta );
		} catch ( Throwable $e ) {
			$this->logger->error( 'LLM stream failed', [ 'error' => $e->getMessage() ] );
			throw $e;
		}

		return $this->finalize( $prepared, $completion );
	}

	/**
	 * @return array{result?: array{thread_uuid: string, reply: string, flagged?: bool, sources: list<array<string, mixed>>}, prompt?: list<array{role: string, content: string}>, options?: array<string, mixed>, thread?: array<string, mixed>, message?: string, chunks?: list<array{id: string, score: float, metadata: array<string, mixed>}>}
	 */
	private function prepare( string $message, ?string $thread_uuid, string $session_hash, ?int $user_id, string $origin_url, string $origin_title = '' ): array {
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
						'result' => [
							'thread_uuid' => $thread_uuid ?? '',
							'reply'       => (string) $this->settings->get( 'fallback_message', '' ),
							'flagged'     => true,
							'sources'     => [],
						],
					];
				}
			} catch ( Throwable $e ) {
				$this->logger->warning( 'Moderation check failed', [ 'error' => $e->getMessage() ] );
			}
		}

		$thread = null === $thread_uuid ? null : $this->threads->find_by_uuid( $thread_uuid );
		if ( null !== $thread && ! self::owns_thread( $thread, $session_hash, $user_id ) ) {
			// A uuid alone must not unlock a conversation: history is replayed into
			// the prompt, so resuming someone else's thread leaks it back out.
			$this->logger->warning( 'Rejected thread resume for a mismatched session' );
			$thread = null;
		}
		if ( null === $thread ) {
			$thread = $this->threads->create( $session_hash, $user_id, $origin_url );
		}

		$thread_id = (int) $thread['id'];
		$this->messages->append( $thread_id, 'user', $message, $this->counter->count( $message ) );

		$history      = $this->messages->for_thread( $thread_id, 12 );
		$current_page = PageContext::resolve( $origin_url, $origin_title );
		$embed_text   = self::retrieval_query( $message, $history );
		if ( null !== $current_page && '' !== $current_page['title'] ) {
			$embed_text = trim( $current_page['title'] . ' — ' . $embed_text );
		}

		try {
			$query_vectors = $this->providers->embeddings()->embed( [ $embed_text ], [ 'input_type' => 'query' ] );
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

		$chunks = $this->providers->vector_store()->search(
			$query_vector,
			$max_chunks,
			$threshold,
			$this->providers->embeddings()->model(),
			[
				'text_query'       => $embed_text,
				'prefer_source_id' => null !== $current_page ? $current_page['post_id'] : 0,
			]
		);

		/**
		 * Filter the chunks used as context before sending to the LLM.
		 *
		 * @param list<array{id: string, score: float, metadata: array<string, mixed>}> $chunks
		 * @param string                                                                $message
		 */
		/** @var list<array{id: string, score: float, metadata: array<string, mixed>}> $filtered_chunks */
		$filtered_chunks = apply_filters( 'alpha_chat_retrieved_chunks', $chunks, $message );
		$chunks          = self::enrich_chunks( $filtered_chunks );
		$faqs            = self::relevant_faqs( $this->faqs->all( true ), $message );

		return [
			'prompt'          => $this->build_messages( $message, $chunks, $faqs, $history, $current_page ),
			'options'         => [
				'temperature'      => (float) $this->settings->get( 'temperature', 0.7 ),
				'top_p'            => (float) $this->settings->get( 'top_p', 1.0 ),
				'max_tokens'       => (int) $this->settings->get( 'max_response_tokens', 800 ),
				'reasoning_effort' => (string) $this->settings->get( 'reasoning_effort', 'low' ),
			],
			'thread'          => $thread,
			'message'         => $message,
			'chunks'          => $chunks,
			'current_post_id' => (int) ( $current_page['post_id'] ?? 0 ),
		];
	}

	/**
	 * @param array{thread: array<string, mixed>, message: string, chunks: list<array{id: string, score: float, metadata: array<string, mixed>}>} $prepared
	 * @param array{content: string, usage?: array<string, int>}                                       $completion
	 *
	 * @return array{thread_uuid: string, reply: string, sources: list<array<string, mixed>>}
	 */
	private function finalize( array $prepared, array $completion ): array {
		$thread  = $prepared['thread'];
		$message = $prepared['message'];
		$chunks  = $prepared['chunks'];

		$reply = trim( $completion['content'] );
		if ( '' === $reply ) {
			$reply = (string) $this->settings->get( 'fallback_message', '' );
		}

		$used    = SourcePicker::used( $chunks, $reply, $message, (int) ( $prepared['current_post_id'] ?? 0 ) );
		$sources = self::hydrate_sources( $used );

		$this->messages->append(
			(int) $thread['id'],
			'assistant',
			$reply,
			$this->counter->count( $reply ),
			[
				'sources'   => $sources,
				'retrieved' => self::hydrate_sources( $chunks ),
				'usage'     => $completion['usage'] ?? null,
				'model'     => (string) $this->settings->get( 'chat_model', '' ),
			]
		);

		$this->threads->touch(
			(int) $thread['id'],
			2,
			'' === $thread['title'] ? wp_trim_words( $message, 8 ) : null
		);

		do_action( 'alpha_chat_message_answered', (int) $thread['id'], $message, $reply, $sources );

		if ( empty( $chunks ) ) {
			do_action( 'alpha_chat_unanswered_question', (int) $thread['id'], $message );
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
	 * @param array{url:string,title:string,content:string,post_id:int}|null                  $current_page
	 *
	 * @return list<array{role: string, content: string}>
	 */
	private function build_messages( string $message, array $chunks, array $faqs, array $history, ?array $current_page = null ): array {
		$brand    = (string) $this->settings->get( 'brand_name', (string) get_bloginfo( 'name' ) );
		$identity = sprintf(
			"You are the AI assistant for %s. If someone asks who you are, what this chat is, or similar, explain that you are %s's AI helper. Do not invent a human name or claim to be a person.",
			$brand,
			$brand
		);

		$behaviour = "Answer helpfully and concisely. Prefer the curated Q&A, the current page the user is viewing, and the retrieved site context below — but you are free to draw on general knowledge to explain, clarify, or summarize when the context is thin. Respond naturally to greetings, thanks, goodbyes, and small talk. Never refuse a reasonable question; if you genuinely do not know something, say so in your own words.";

		$system_setting = trim( (string) $this->settings->get( 'system_prompt', '' ) );
		$system         = $identity . "\n\n" . $behaviour . ( '' !== $system_setting ? "\n\n" . $system_setting : '' );

		if ( null !== $current_page && '' !== (string) $current_page['url'] ) {
			$system .= "\n\nCurrent page the user is viewing:\n";
			$system .= 'URL: ' . $current_page['url'] . "\n";
			if ( '' !== (string) $current_page['title'] ) {
				$system .= 'Title: ' . $current_page['title'] . "\n";
			}
			if ( '' !== (string) $current_page['content'] ) {
				$system .= "Content:\n" . $current_page['content'] . "\n";
			}
			$system .= 'When the user says "this", "this page", "this article", or similar, assume they mean the page above.';
		}

		if ( ! empty( $faqs ) ) {
			$faq_block = "Curated Q&A (authoritative — use these verbatim when the user's question matches):\n\n";
			foreach ( $faqs as $i => $faq ) {
				$faq_block .= sprintf( "Q%d: %s\nA%d: %s\n\n", $i + 1, trim( $faq['question'] ), $i + 1, trim( $faq['answer'] ) );
			}
			$system .= "\n\n" . trim( $faq_block );
		}

		if ( ! empty( $chunks ) ) {
			$context  = "Numbered site context. Prefer these passages for factual claims about the site; when they cover the topic, answer in 2–5 short sentences. When they don't, still help the user using general knowledge or the current page above — do not invent links or sources.\n";
			$context .= "Do NOT include bracketed citation markers like [1] or [2] in your reply — the UI renders source links separately.\n";
			$context .= "Do not invent titles or URLs that are not in the numbered context.\n\n";
			foreach ( $chunks as $i => $chunk ) {
				$meta   = (array) ( $chunk['metadata'] ?? [] );
				$title  = (string) ( $meta['title'] ?? '' );
				$url    = (string) ( $meta['url'] ?? '' );
				$body   = trim( (string) ( $meta['content'] ?? '' ) );
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
	 * Narrow the curated Q&A to what could plausibly answer this message.
	 *
	 * Every enabled FAQ used to be pasted into the system prompt on every
	 * message, so a site with a large FAQ set paid for the whole list on each
	 * turn. Anything under the cap is still sent verbatim; past that, entries are
	 * ranked by word overlap with the question and the tail is dropped.
	 *
	 * @param list<array{id:int, question:string, answer:string, sort_order:int, enabled:bool, created_at:string, updated_at:string}> $faqs
	 *
	 * @return list<array{id:int, question:string, answer:string, sort_order:int, enabled:bool, created_at:string, updated_at:string}>
	 */
	private static function relevant_faqs( array $faqs, string $message ): array {
		/**
		 * Filter how many curated Q&A entries are injected into the prompt.
		 *
		 * @param int    $max     Maximum entries. 0 sends every enabled entry.
		 * @param string $message The visitor's message.
		 */
		$max = (int) apply_filters( 'alpha_chat_max_prompt_faqs', 12, $message );

		if ( $max <= 0 || count( $faqs ) <= $max ) {
			return $faqs;
		}

		$terms = self::terms( $message );
		if ( empty( $terms ) ) {
			return array_slice( $faqs, 0, $max );
		}

		$ranked = [];
		foreach ( $faqs as $position => $faq ) {
			$haystack = self::terms( $faq['question'] . ' ' . $faq['answer'] );
			$score    = 0;
			// terms() returns a word => true set, so the words are the keys.
			foreach ( array_keys( $terms ) as $term ) {
				if ( isset( $haystack[ $term ] ) ) {
					++$score;
				}
			}
			$ranked[] = [
				'score'    => $score,
				'position' => $position,
				'faq'      => $faq,
			];
		}

		usort(
			$ranked,
			static function ( array $a, array $b ): int {
				if ( $a['score'] !== $b['score'] ) {
					return $b['score'] <=> $a['score'];
				}
				// Ties keep the admin's configured sort order.
				return $a['position'] <=> $b['position'];
			}
		);

		$out = [];
		foreach ( array_slice( $ranked, 0, $max ) as $entry ) {
			$out[] = $entry['faq'];
		}

		return $out;
	}

	/**
	 * @return array<string, true>
	 */
	private static function terms( string $text ): array {
		$text  = mb_strtolower( wp_strip_all_tags( $text ) );
		$text  = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text ) ?? $text;
		$words = preg_split( '/\s+/u', trim( $text ) ) ?: [];

		$out = [];
		foreach ( $words as $word ) {
			if ( mb_strlen( $word ) >= 3 ) {
				$out[ $word ] = true;
			}
		}

		return $out;
	}

	/**
	 * Whether the caller may resume an existing thread.
	 *
	 * A signed-in user owns their own threads. Anonymous visitors are matched on
	 * the session hash, which binds the thread to the browser that started it.
	 *
	 * @param array<string, mixed> $thread
	 */
	private static function owns_thread( array $thread, string $session_hash, ?int $user_id ): bool {
		$thread_user = null === $thread['user_id'] ? 0 : (int) $thread['user_id'];
		$caller      = (int) ( $user_id ?? 0 );

		if ( $thread_user > 0 || $caller > 0 ) {
			return $thread_user === $caller;
		}

		$stored = (string) ( $thread['session_hash'] ?? '' );

		return '' !== $stored && hash_equals( $stored, $session_hash );
	}

	/**
	 * @param list<array<string, mixed>> $history
	 */
	private static function retrieval_query( string $message, array $history ): string {
		$previous_user      = '';
		$previous_assistant = '';
		$count              = count( $history );

		if ( $count >= 3 ) {
			$maybe_assistant = $history[ $count - 2 ];
			$maybe_user      = $history[ $count - 3 ];
			if ( 'assistant' === ( $maybe_assistant['role'] ?? '' ) ) {
				$previous_assistant = (string) $maybe_assistant['content'];
			}
			if ( 'user' === ( $maybe_user['role'] ?? '' ) ) {
				$previous_user = (string) $maybe_user['content'];
			}
		}

		if ( ! QueryRewriter::is_follow_up( $message ) || ( '' === $previous_user && '' === $previous_assistant ) ) {
			return $message;
		}

		return QueryRewriter::rewrite( $message, $previous_user, $previous_assistant );
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
			$out[]        = [
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

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
	public function send( string $message, ?string $thread_uuid, string $session_hash, ?int $user_id = null, string $origin_url = '' ): array {
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
			$thread = $this->threads->create( $session_hash, $user_id, $origin_url );
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

		$current_page = self::resolve_current_page( $origin_url );
		$history      = $this->messages->for_thread( $thread_id, 12 );
		$prompt       = $this->build_messages( $message, $chunks, $faqs, $history, $current_page );

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
	 * Resolve the user's current page URL to a lightweight title/content block
	 * the LLM can reference for "this page", "explain this", etc.
	 *
	 * @return array{url: string, title: string, content: string}|null
	 */
	private static function resolve_current_page( string $origin_url ): ?array {
		$origin_url = trim( $origin_url );
		if ( '' === $origin_url ) {
			return null;
		}

		$post_id = (int) url_to_postid( $origin_url );
		if ( $post_id <= 0 ) {
			return [
				'url'     => $origin_url,
				'title'   => '',
				'content' => '',
			];
		}

		$post = get_post( $post_id );
		if ( null === $post || 'publish' !== $post->post_status ) {
			return [
				'url'     => $origin_url,
				'title'   => '',
				'content' => '',
			];
		}

		$raw     = (string) $post->post_content;
		$raw     = strip_shortcodes( $raw );
		$raw     = wp_strip_all_tags( $raw );
		$raw     = preg_replace( '/\s+/u', ' ', $raw ) ?? $raw;
		$content = trim( $raw );
		if ( mb_strlen( $content ) > 4000 ) {
			$content = mb_substr( $content, 0, 4000 ) . '…';
		}

		return [
			'url'     => (string) get_permalink( $post_id ),
			'title'   => (string) get_the_title( $post_id ),
			'content' => $content,
		];
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

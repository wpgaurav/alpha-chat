<?php
declare(strict_types=1);

namespace AlphaChat\Chat;

use AlphaChat\Providers\ProviderFactory;
use AlphaChat\Settings\SettingsRepository;
use AlphaChat\Support\Logger;
use Throwable;

/**
 * Names a conversation from its opening exchange using the configured provider.
 *
 * Runs from a scheduled action rather than inline: titling is cosmetic and must
 * never add latency to, or be able to fail, the visitor's reply.
 */
final class ThreadTitler {

	public const HOOK = 'alpha_chat_title_thread';

	private const MAX_LENGTH = 60;

	public function __construct(
		private readonly ProviderFactory $providers,
		private readonly SettingsRepository $settings,
		private readonly ThreadRepository $threads,
		private readonly MessageRepository $messages,
		private readonly Logger $logger,
	) {}

	public function register(): void {
		add_action( self::HOOK, [ $this, 'run' ], 10, 1 );
	}

	/**
	 * Queue a rename for a thread, if the feature is on.
	 */
	public function schedule( int $thread_id ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, [ $thread_id ], 'alpha-chat' );
			return;
		}

		wp_schedule_single_event( time() + 5, self::HOOK, [ $thread_id ] );
	}

	public function enabled(): bool {
		return (bool) $this->settings->get( 'ai_thread_titles', true );
	}

	public function run( int $thread_id ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$thread = $this->threads->find_by_id( $thread_id );
		if ( null === $thread ) {
			return;
		}

		// Only name a thread once. A human-edited or already-generated title wins.
		if ( ! empty( $thread['title_generated'] ) ) {
			return;
		}

		$history = $this->messages->for_thread( $thread_id, 4 );
		$user    = '';
		$reply   = '';
		foreach ( $history as $entry ) {
			if ( '' === $user && 'user' === ( $entry['role'] ?? '' ) ) {
				$user = (string) $entry['content'];
			} elseif ( '' === $reply && 'assistant' === ( $entry['role'] ?? '' ) ) {
				$reply = (string) $entry['content'];
			}
		}

		if ( '' === $user ) {
			return;
		}

		try {
			$title = $this->generate( $user, $reply );
		} catch ( Throwable $e ) {
			$this->logger->warning( 'Thread title generation failed', [ 'thread_id' => $thread_id, 'error' => $e->getMessage() ] );
			return;
		}

		$title = self::tidy( $title );
		if ( '' === $title ) {
			return;
		}

		$this->threads->set_title( $thread_id, $title, true );

		/**
		 * Fires after a conversation is renamed by the model.
		 *
		 * @param int    $thread_id Thread ID.
		 * @param string $title     Generated title.
		 */
		do_action( 'alpha_chat_thread_titled', $thread_id, $title );
	}

	private function generate( string $user, string $reply ): string {
		$prompt = [
			[
				'role'    => 'system',
				'content' => 'You name support conversations. Reply with a short title of 3 to 7 words that says what the visitor wanted. Use sentence case. No quotation marks, no trailing period, no preamble — output the title and nothing else.',
			],
			[
				'role'    => 'user',
				'content' => "Visitor asked:\n" . mb_substr( trim( $user ), 0, 500 )
					. ( '' !== $reply ? "\n\nAssistant answered:\n" . mb_substr( trim( $reply ), 0, 500 ) : '' ),
			],
		];

		$completion = $this->providers->llm()->complete(
			$prompt,
			[
				// Deliberately tiny: this is a handful of words.
				'max_tokens'       => 24,
				'temperature'      => 0.3,
				'top_p'            => 1.0,
				'reasoning_effort' => 'off',
			]
		);

		return (string) ( $completion['content'] ?? '' );
	}

	/**
	 * Normalise whatever the model returned into a usable title.
	 */
	public static function tidy( string $title ): string {
		$title = trim( wp_strip_all_tags( $title ) );
		$title = (string) preg_replace( '/\s+/u', ' ', $title );

		// Models wrap the answer in quotes, prefix it with a label, or both — and
		// the label can sit inside the quotes ("Title: ..."), so unwrap first,
		// strip the label, then unwrap again for the Title: "..." shape.
		$quotes = " \t\n\r\0\x0B\"'“”‘’";
		$title  = trim( $title, $quotes );
		$title  = (string) preg_replace( '/^(title|conversation|subject)\s*:\s*/i', '', $title );
		$title  = trim( $title, $quotes );
		$title  = rtrim( $title, '.' );

		if ( mb_strlen( $title ) > self::MAX_LENGTH ) {
			$title = rtrim( mb_substr( $title, 0, self::MAX_LENGTH ) ) . '…';
		}

		return $title;
	}
}

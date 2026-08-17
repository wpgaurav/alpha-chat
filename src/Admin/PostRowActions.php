<?php
declare(strict_types=1);

namespace AlphaChat\Admin;

use AlphaChat\KnowledgeBase\Indexer;
use AlphaChat\REST\RouteRegistrar;
use WP_Post;

final class PostRowActions {

	private const HANDLE = 'alpha-chat-row-actions';

	public function __construct( private readonly Indexer $indexer ) {}

	public function register(): void {
		foreach ( [ 'post', 'page' ] as $type ) {
			add_filter( "{$type}_row_actions", [ $this, 'add_action' ], 10, 2 );
		}

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * The row action renders a button on edit.php, which never loaded any script
	 * to handle it, so clicking it did nothing. This attaches the handler on the
	 * list screens where the button actually appears.
	 */
	public function enqueue( string $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Inline-only handle: the handler below uses window.fetch directly, so it
		// needs no bundle and no dependencies.
		wp_register_script( self::HANDLE, false, [], ALPHA_CHAT_VERSION, true );
		wp_enqueue_script( self::HANDLE );

		wp_add_inline_script(
			self::HANDLE,
			sprintf(
				'window.alphaChatRowActions = %s;',
				(string) wp_json_encode(
					[
						'root'    => esc_url_raw( rest_url( RouteRegistrar::NAMESPACE . '/knowledge-base' ) ),
						'nonce'   => wp_create_nonce( 'wp_rest' ),
						'strings' => [
							'adding'   => __( 'Adding…', 'alpha-chat' ),
							'removing' => __( 'Removing…', 'alpha-chat' ),
							'added'    => __( 'Remove from Alpha Chat', 'alpha-chat' ),
							'removed'  => __( 'Add to Alpha Chat', 'alpha-chat' ),
							'failed'   => __( 'Alpha Chat: that did not work. Please try again.', 'alpha-chat' ),
						],
					]
				)
			),
			'before'
		);

		wp_add_inline_script( self::HANDLE, self::script(), 'after' );
	}

	private static function script(): string {
		return <<<'JS'
( function () {
	var config = window.alphaChatRowActions;
	if ( ! config ) {
		return;
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.alpha-chat-row-action' );
		if ( ! button || button.disabled ) {
			return;
		}

		event.preventDefault();

		var postId = button.getAttribute( 'data-post-id' );
		var action = button.getAttribute( 'data-action' );
		var adding = action === 'add';

		button.disabled = true;
		button.textContent = adding ? config.strings.adding : config.strings.removing;

		window.fetch( config.root + '/' + encodeURIComponent( postId ), {
			method: adding ? 'POST' : 'DELETE',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.nonce },
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'request failed' );
				}
				button.setAttribute( 'data-action', adding ? 'remove' : 'add' );
				button.textContent = adding ? config.strings.added : config.strings.removed;
				button.classList.toggle( 'button-link-delete', adding );
			} )
			.catch( function () {
				button.textContent = adding ? config.strings.removed : config.strings.added;
				window.alert( config.strings.failed );
			} )
			.finally( function () {
				button.disabled = false;
			} );
	} );
}() );
JS;
	}

	/**
	 * @param array<string, string> $actions
	 *
	 * @return array<string, string>
	 */
	public function add_action( array $actions, WP_Post $post ): array {
		// The knowledge-base routes behind this button require manage_options, so
		// showing it to an editor only offers them a guaranteed 403.
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
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

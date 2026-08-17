<?php
declare(strict_types=1);

namespace AlphaChat;

final class Deactivator {

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'alpha_chat_refresh_embeddings' );
		wp_clear_scheduled_hook( 'alpha_chat_prune_logs' );

		do_action( 'alpha_chat_deactivated' );
	}
}

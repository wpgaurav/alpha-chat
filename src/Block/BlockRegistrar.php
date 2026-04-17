<?php
declare(strict_types=1);

namespace AlphaChat\Block;

use AlphaChat\Frontend\WidgetLoader;
use WP_Block;

final class BlockRegistrar {

	private ?WidgetLoader $widget = null;

	public function register( WidgetLoader $widget ): void {
		$this->widget = $widget;
		add_action( 'init', [ $this, 'register_block' ] );
	}

	public function register_block(): void {
		$metadata = ALPHA_CHAT_PATH . 'build/block/block.json';
		if ( ! is_readable( $metadata ) ) {
			$metadata = ALPHA_CHAT_PATH . 'assets/block/block.json';
		}
		if ( ! is_readable( $metadata ) ) {
			return;
		}

		register_block_type(
			$metadata,
			[
				'render_callback' => [ $this, 'render' ],
			]
		);
	}

	/**
	 * @param array<string, mixed> $attributes
	 */
	public function render( array $attributes, string $content, WP_Block $block ): string {
		if ( $this->widget ) {
			$this->widget->enqueue_assets();
		}

		$heading     = isset( $attributes['heading'] ) ? (string) $attributes['heading'] : '';
		$placeholder = isset( $attributes['placeholder'] ) ? (string) $attributes['placeholder'] : '';
		$wrapper     = get_block_wrapper_attributes( [ 'class' => 'alpha-chat-block' ] );

		ob_start();
		?>
		<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php if ( '' !== $heading ) : ?>
				<h2 class="alpha-chat-block__heading"><?php echo esc_html( $heading ); ?></h2>
			<?php endif; ?>
			<div
				class="alpha-chat-block__mount"
				data-alpha-chat-embed
				<?php if ( '' !== $placeholder ) : ?>
					data-placeholder="<?php echo esc_attr( $placeholder ); ?>"
				<?php endif; ?>
			></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

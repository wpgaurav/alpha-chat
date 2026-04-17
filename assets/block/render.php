<?php
/**
 * Server-side render for the Alpha Chat block.
 *
 * @package AlphaChat
 *
 * @var array<string, mixed> $attributes
 * @var string               $content
 * @var WP_Block             $block
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'alpha-chat-block' ] );
$heading            = isset( $attributes['heading'] ) ? (string) $attributes['heading'] : '';
$placeholder        = isset( $attributes['placeholder'] ) ? (string) $attributes['placeholder'] : '';

?>
<div <?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( '' !== $heading ) : ?>
		<h2 class="alpha-chat-block__heading"><?php echo esc_html( $heading ); ?></h2>
	<?php endif; ?>
	<div
		id="alpha-chat-embed"
		class="alpha-chat-block__mount"
		data-alpha-chat-embed
		<?php if ( '' !== $placeholder ) : ?>
			data-placeholder="<?php echo esc_attr( $placeholder ); ?>"
		<?php endif; ?>
	></div>
</div>

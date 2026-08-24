<?php
/**
 * An advisory or deprecation notice supplied by the ingestion response.
 *
 * A view, included by Consent\Notice::render_server_notice() from
 * `__DIR__ . '/../../views/consent/'`. See views/consent/prompt.php for the rules every view here
 * inherits.
 *
 * `$message` is SERVER-SUPPLIED: it arrives in an ingestion response and is stored in an option, so
 * it is the one string rendered by this SDK that neither the consumer nor the site administrator
 * wrote. It is bounded to 500 characters at the point it is stored (Notice::remember_server_notice)
 * and escaped here at the point it is printed. `$level` is not interpolated raw either -- the caller
 * has already reduced it to one of error/warning/info, so an arbitrary class cannot reach the
 * markup.
 *
 * The dismissal is a real form rather than WordPress's `is-dismissible` class, which only hides the
 * notice client-side and brings it back on the next page load.
 *
 * @package Codexpert\PluginTracker
 *
 * @var string $level        One of 'error', 'warning', 'info'. Already validated by the caller.
 * @var string $name         Consumer plugin display name.
 * @var string $message      The server's message, already truncated at storage time.
 * @var string $action       admin-post.php URL the dismissal submits to.
 * @var string $plugin       Consumer plugin slug, namespacing the action and the nonce.
 * @var string $nonce_action Nonce action for wp_nonce_field().
 * @var string $dismiss      Localised dismiss label.
 */

?>
<div class="notice notice-<?php echo esc_attr( $level ); ?>">
	<p>
		<strong><?php echo esc_html( $name ); ?></strong>
		<?php echo esc_html( $message ); ?>
	</p>
	<form method="post" action="<?php echo esc_url( $action ); ?>">
		<?php wp_nonce_field( $nonce_action ); ?>
		<input type="hidden" name="action" value="cx_tracker_dismiss_<?php echo esc_attr( $plugin ); ?>">
		<?php submit_button( $dismiss, 'link', 'submit', false ); ?>
	</form>
</div>

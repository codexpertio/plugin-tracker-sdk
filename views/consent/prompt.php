<?php
/**
 * The telemetry opt-in prompt, shown as a standard wp-admin notice.
 *
 * A view, included by Consent\Notice::render_prompt() from `__DIR__ . '/../../views/consent/'`. The
 * subdirectory mirrors the namespace segment it serves, so views/consent/ is to Consent\ what
 * views/feedback/ is to Feedback\.
 *
 * See views/feedback/modal.php for the two rules every view here inherits: it must stay `.php` and
 * its directory must be listed in bin/build-dist.sh's SOURCE_ROOTS, or it will not ship and the
 * include will fatal on a consumer's admin screen with no build error to warn anyone.
 *
 * Not overridable, and for a narrower reason than the feedback modal. This is the consent prompt:
 * `$text['sends']` and `$text['never']` state what the SDK will and will not transmit, and consent
 * obtained against a substituted description is not consent to what actually happens. Wording is
 * changed through the `cx_tracker_notice_strings` filter, which cannot alter the structure.
 *
 * Everything arrives as a plain variable -- an included file does not inherit the including file's
 * `use` imports, and a view that cannot reach its caller can be read on its own.
 *
 * @package Codexpert\PluginTracker
 *
 * @var string               $action admin-post.php URL both forms submit to.
 * @var array<string,string> $text   Localised copy from Notice::strings().
 * @var string               $name   Consumer plugin display name.
 * @var string               $plugin Consumer plugin slug, namespacing the action and the nonce.
 * @var string               $nonce_action Nonce action for wp_nonce_field().
 */

?>
		<div class="notice notice-info">
			<p>
				<strong><?php echo esc_html( $name ); ?></strong>
				<?php echo esc_html( (string) $text['intro'] ); ?>
			</p>
			<p>
				<?php echo esc_html( (string) $text['sends'] ); ?>
				<strong><?php echo esc_html( (string) $text['never'] ); ?></strong>
			</p>
			<p><?php echo esc_html( (string) $text['optional'] ); ?></p>
			<p><em><?php echo esc_html( (string) $text['beta'] ); ?></em></p>
			<form method="post" action="<?php echo esc_url( $action ); ?>" style="display:inline">
				<?php wp_nonce_field( $nonce_action ); ?>
				<input type="hidden" name="action" value="cx_tracker_consent_<?php echo esc_attr( $plugin ); ?>">
				<input type="hidden" name="choice" value="in">
				<?php submit_button( (string) $text['allow'], 'primary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( $action ); ?>" style="display:inline">
				<?php wp_nonce_field( $nonce_action ); ?>
				<input type="hidden" name="action" value="cx_tracker_consent_<?php echo esc_attr( $plugin ); ?>">
				<input type="hidden" name="choice" value="out">
				<?php submit_button( (string) $text['decline'], 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php

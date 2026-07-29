<?php
/**
 * The deactivation-feedback dialog: its scoped stylesheet, and its markup.
 *
 * A view, included by Feedback\Deactivation::render() from
 * `__DIR__ . '/../../views/feedback/'`. The subdirectory mirrors this class's namespace segment,
 * so views/feedback/ is to Feedback\ what views/consent/ would be to Consent\.
 *
 * It lives at the package root rather than inside src/, because src/ is the PSR-4 class tree and a
 * file that declares no class has no business being autoload-adjacent. That placement carries a
 * distribution obligation, and it is not optional: bin/build-dist.sh assembles the scoped artifact
 * by copying named source roots, and this directory is one of them **because it was added to that
 * list**. Nothing about a views/ folder makes a build tool notice it. Remove it from
 * SOURCE_ROOTS there and this file stops shipping -- with no build error, and a fatal on the
 * consumer's plugins.php the first time somebody clicks Deactivate.
 *
 * The file must also stay `.php`. The build copies `-name '*.php'` only, so a `.html` template
 * here would be silently dropped for the same reason.
 *
 * **Deliberately not overridable, and it must stay that way.** There is no filter on this path and
 * none may be added. The itemised disclosure below is what makes the submission informed consent:
 * it is generated from the same site_fields() that payload() transmits, and a test asserts that
 * every value sent appears here. A consumer able to substitute their own template could ship a
 * dialog that under-discloses, under a heading reading "Pressing the button below sends exactly
 * this, and nothing else". Rebranding and translation are already served by the
 * `cx_tracker_feedback_strings` filter, which changes wording without touching what is listed.
 *
 * Everything arrives as a plain variable. Nothing here reaches back through `$this` or `self::`,
 * because an included file does NOT inherit the including file's `use` imports -- `Event::REASONS`
 * written here would resolve to a global `\Event` and fatal -- and because a view that cannot touch
 * its caller is a view that can be reasoned about on its own.
 *
 * Indentation is the method's, not a file's, and is left exactly as it was when this was inline:
 * the whitespace is emitted, so re-indenting would change the rendered output for no benefit.
 *
 * @package Codexpert\PluginTracker
 *
 * @var string $id             Root element id, namespaced by consumer slug.
 * @var string $slug           Consumer plugin slug.
 * @var string $basename       Consumer plugin basename, the identity the script matches links on.
 * @var string $css            Scoped stylesheet for $id.
 * @var array  $text           Localised copy from Deactivation::strings().
 * @var array  $labels         Reason labels, keyed by reason.
 * @var array  $prompts        Per-reason comment prompts.
 * @var array  $fields         Everything the payload will carry about this site.
 * @var array  $reasons        The closed reason set (Event::REASONS).
 * @var string $hash           Public, non-secret plugin hash.
 * @var string $project        Public project identifier, '' when the consumer set none.
 * @var string $endpoint       admin-ajax.php URL.
 * @var string $fallback       admin-post.php URL, the no-JS form target.
 * @var string $action         admin-post/ajax action name.
 * @var string $nonce_action   Nonce action for wp_nonce_field().
 * @var string $deactivate_url Real nonced Deactivate URL, for Skip.
 * @var int    $contract       Markup/behaviour contract version.
 * @var int    $timeout        Navigate-anyway deadline, milliseconds.
 * @var int    $note_max       Comment ceiling, characters.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- A false
// positive on a view. The sniff treats file-scope variables as globals, which is right for a file
// that is executed directly; this one is only ever reached through `include` from inside
// Deactivation::render(), so an included file inherits that method's variable scope and the loop
// variables below are method-locals at runtime. Prefixing them would encode a misreading of where
// this file runs.

?>
		<style id="<?php echo esc_attr( $id ); ?>-css">
		<?php echo esc_html( $css ); ?>
		</style>
		<div
			id="<?php echo esc_attr( $id ); ?>"
			class="cx-tracker-feedback"
			data-cx-tracker-feedback="<?php echo esc_attr( $slug ); ?>"
			data-cx-contract="<?php echo esc_attr( (string) $contract ); ?>"
			data-cx-basename="<?php echo esc_attr( $basename ); ?>"
			data-cx-endpoint="<?php echo esc_url( $endpoint ); ?>"
			data-cx-timeout="<?php echo esc_attr( (string) $timeout ); ?>"
			data-cx-sending="<?php echo esc_attr( (string) $text['sending'] ); ?>"
			hidden>
			<div class="cx-tracker-feedback__veil" data-cx-close="1"></div>
			<div
				class="cx-tracker-feedback__dialog"
				role="dialog"
				aria-modal="true"
				aria-labelledby="<?php echo esc_attr( $id ); ?>-title"
				aria-describedby="<?php echo esc_attr( $id ); ?>-disclosure">
				<h2 class="cx-tracker-feedback__title" id="<?php echo esc_attr( $id ); ?>-title">
					<?php echo esc_html( (string) $text['title'] ); ?>
				</h2>
				<p class="cx-tracker-feedback__intro"><?php echo esc_html( (string) $text['intro'] ); ?></p>

				<form
					class="cx-tracker-feedback__form"
					method="post"
					action="<?php echo esc_url( $fallback ); ?>">

					<fieldset class="cx-tracker-feedback__reasons">
						<legend><?php echo esc_html( (string) $text['reason_legend'] ); ?></legend>
						<?php foreach ( $reasons as $reason ) : ?>
							<?php $input_id = $id . '-reason-' . str_replace( '_', '-', $reason ); ?>
							<label class="cx-tracker-feedback__reason" for="<?php echo esc_attr( $input_id ); ?>">
								<input
									type="radio"
									id="<?php echo esc_attr( $input_id ); ?>"
									name="reason"
									value="<?php echo esc_attr( $reason ); ?>"
									data-cx-reason
									data-cx-prompt="<?php echo esc_attr( isset( $prompts[ $reason ] ) ? (string) $prompts[ $reason ] : (string) $text['note_label'] ); ?>">
								<span><?php echo esc_html( isset( $labels[ $reason ] ) ? (string) $labels[ $reason ] : $reason ); ?></span>
							</label>
						<?php endforeach; ?>
					</fieldset>

					<?php /* Visible by default on purpose: the JS hides it on load and reveals it once a reason is chosen, so a scripting-disabled browser still gets a usable comment box rather than none. */ ?>
					<p class="cx-tracker-feedback__note" data-cx-note>
						<label for="<?php echo esc_attr( $id ); ?>-note" data-cx-note-label>
							<?php echo esc_html( (string) $text['note_label'] ); ?>
						</label>
						<textarea
							id="<?php echo esc_attr( $id ); ?>-note"
							name="note"
							rows="3"
							maxlength="<?php echo esc_attr( (string) $note_max ); ?>"></textarea>
						<span class="cx-tracker-feedback__hint"><?php echo esc_html( (string) $text['note_hint'] ); ?></span>
					</p>

					<div class="cx-tracker-feedback__disclosure" id="<?php echo esc_attr( $id ); ?>-disclosure">
						<p class="cx-tracker-feedback__disclosure-summary">
							<?php echo esc_html( (string) $text['disclosure_summary'] ); ?>
						</p>
						<?php /* A <details> element, not JS: it works with scripting disabled, is keyboard operable and announced as a disclosure by screen readers for free. The itemised list stays in the DOM either way, so aria-describedby still resolves and nothing is hidden from assistive tech that ignores the toggle. */ ?>
						<details class="cx-tracker-feedback__details">
							<summary class="cx-tracker-feedback__details-toggle">
								<?php echo esc_html( (string) $text['disclosure_more'] ); ?>
							</summary>
							<ul>
								<li><?php echo esc_html( (string) $text['field_site'] ); ?>: <code><?php echo esc_html( (string) $fields['site'] ); ?></code></li>
								<li><?php echo esc_html( (string) $text['field_plugin'] ); ?>: <code><?php echo esc_html( (string) $fields['plugin'] ); ?> <?php echo esc_html( (string) $fields['plugin_version'] ); ?></code></li>
								<li><?php echo esc_html( (string) $text['field_wp'] ); ?>: <code><?php echo esc_html( (string) $fields['wp'] ); ?></code></li>
								<li><?php echo esc_html( (string) $text['field_php'] ); ?>: <code><?php echo esc_html( (string) $fields['php'] ); ?></code></li>
								<?php if ( '' !== $fields['server'] ) : ?>
									<li><?php echo esc_html( (string) $text['field_server'] ); ?>: <code><?php echo esc_html( (string) $fields['server'] ); ?></code></li>
								<?php endif; ?>
								<li><?php echo esc_html( (string) $text['field_locale'] ); ?>: <code><?php echo esc_html( (string) $fields['locale'] ); ?></code></li>
								<li><?php echo esc_html( (string) $text['field_multisite'] ); ?>: <code><?php echo esc_html( $fields['multisite'] ? (string) $text['yes'] : (string) $text['no'] ); ?></code></li>
								<?php if ( '' !== $fields['theme'] ) : ?>
									<li>
										<?php echo esc_html( (string) $text['field_theme'] ); ?>:
										<code><?php echo esc_html( trim( $fields['theme'] . ' ' . $fields['theme_version'] ) ); ?></code>
										<?php if ( '' !== $fields['theme_parent'] ) : ?>
											<code><?php echo esc_html( $fields['theme_parent'] ); ?></code>
										<?php endif; ?>
									</li>
								<?php endif; ?>
								<?php
								/*
								 * Itemised in full, not summarised as a count. This is the field a reader is
								 * most likely to object to, so showing "12 plugins" while transmitting their
								 * names would be the disclosure failing at precisely the point it matters.
								 * Truncation is stated rather than silent.
								 */
								?>
								<?php if ( ! empty( $fields['plugins'] ) ) : ?>
									<li>
										<?php echo esc_html( (string) $text['field_plugins'] ); ?>:
										<?php foreach ( $fields['plugins'] as $entry ) : ?>
											<code><?php echo esc_html( trim( $entry['plugin'] . ' ' . $entry['version'] ) ); ?></code>
										<?php endforeach; ?>
										<?php if ( $fields['total_plugins'] > count( $fields['plugins'] ) ) : ?>
											<?php echo esc_html( sprintf( (string) $text['field_plugins_more'], $fields['total_plugins'] - count( $fields['plugins'] ) ) ); ?>
										<?php endif; ?>
									</li>
								<?php endif; ?>
								<li><?php echo esc_html( (string) $text['field_hash'] ); ?>: <code><?php echo esc_html( $hash ); ?></code></li>
								<?php /* Only when the consumer actually set a project, matching payload()'s own condition -- listing a field that will not be sent is as wrong as sending one that is not listed. */ ?>
								<?php if ( '' !== $project ) : ?>
									<li><?php echo esc_html( (string) $text['field_project'] ); ?>: <code><?php echo esc_html( $project ); ?></code></li>
								<?php endif; ?>
								<li><?php echo esc_html( (string) $text['field_reason'] ); ?></li>
								<li><?php echo esc_html( (string) $text['disclosure_note'] ); ?></li>
							</ul>
							<p><?php echo esc_html( (string) $text['disclosure_none'] ); ?></p>
						</details>
					</div>

					<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
					<?php wp_nonce_field( $nonce_action ); ?>

					<p class="cx-tracker-feedback__actions">
						<button type="submit" class="button button-primary cx-tracker-feedback__submit">
							<?php echo esc_html( (string) $text['submit'] ); ?>
						</button>
						<a class="button cx-tracker-feedback__skip" href="<?php echo esc_url( $deactivate_url ); ?>" data-cx-skip="1">
							<?php echo esc_html( (string) $text['skip'] ); ?>
						</a>
						<button type="button" class="button-link cx-tracker-feedback__cancel" data-cx-close="1">
							<?php echo esc_html( (string) $text['cancel'] ); ?>
						</button>
					</p>
				</form>
			</div>
		</div>

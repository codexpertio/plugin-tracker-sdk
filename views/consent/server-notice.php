<?php
/**
 * An advisory or deprecation notice supplied by the ingestion response.
 *
 * A view, included by Consent\Notice::render_server_notice() from
 * `__DIR__ . '/../../views/consent/'`. See views/consent/prompt.php for the rules every view here
 * inherits.
 *
 * One line of markup, extracted anyway. The rule this file exists to keep true is "src/ emits no
 * HTML" -- a rule a build step or a reviewer can check, unlike "src/ emits no *substantial* HTML",
 * which is an argument every time. The one-line printf that used to live in Notice.php was exactly
 * the case that would have been waved through.
 *
 * `$message` is SERVER-SUPPLIED: it arrives in an ingestion response and is stored in an option, so
 * it is the one string rendered by this SDK that neither the consumer nor the site administrator
 * wrote. It is bounded to 500 characters at the point it is stored (Notice::remember_server_notice)
 * and escaped here at the point it is printed. `$level` is not interpolated raw either -- the caller
 * has already reduced it to one of error/warning/info, so an arbitrary class cannot reach the
 * markup.
 *
 * @package Codexpert\PluginTracker
 *
 * @var string $level   One of 'error', 'warning', 'info'. Already validated by the caller.
 * @var string $name    Consumer plugin display name.
 * @var string $message The server's message, already truncated at storage time.
 */

?>
<div class="notice notice-<?php echo esc_attr( $level ); ?>"><p><strong><?php echo esc_html( $name ); ?></strong> <?php echo esc_html( $message ); ?></p></div>

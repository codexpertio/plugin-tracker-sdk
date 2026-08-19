<?php
/**
 * The dialog's behaviour: the inline script that intercepts this plugin's Deactivate link.
 *
 * A view, included by Feedback\Deactivation::render() immediately after
 * views/feedback/modal.php. See that file's header for why views sit at the package root as
 * `.php`, what the build has to do for them to ship, and why none of this is overridable.
 *
 * The script stays INLINE rather than being enqueued from a .js file, and that is a distribution
 * fact rather than a preference: the release archive carries whatever .gitattributes does not
 * roots, plus a generated autoload.php, composer.json, languages/ and LICENSE. A `.js` file is not
 * copied by anything, and an enqueued handle pointing at a file that was never shipped is a 404 on
 * every plugins.php load. Inline is the only form that survives the build; putting it in a `.php`
 * view is what lets it be read as a script while still being emitted as one.
 *
 * No jQuery. It happens to be present on plugins.php today, but a bundled library that breaks when
 * a site dequeues a core script has made its consumer's problem worse, and nothing here needs it.
 *
 * The ONLY interpolation is the consumer's own slug, and it is inert by construction:
 * Config::SLUG_PATTERN restricts it to ^[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$, so it cannot contain a
 * quote, a backslash, or an angle bracket, and Config rejects the whole config otherwise. esc_js()
 * is applied anyway. Everything else the script needs is read from the data attributes emitted by
 * views/modal.php, each escaped individually -- which is why there is no JSON blob echoed into a
 * script tag here.
 *
 * Indentation is the method's, not a file's, and is left exactly as it was when this was inline.
 *
 * @package Codexpert\PluginTracker
 *
 * @var string $id       Root element id, namespaced by consumer slug.
 * @var string $slug     Consumer plugin slug.
 * @var int    $contract Markup/behaviour contract version the script refuses to enhance without.
 */

?>
		<script id="<?php echo esc_attr( $id ); ?>-js">
		( function () {
			'use strict';

			var SLUG     = '<?php echo esc_js( $slug ); ?>';
			var CONTRACT = '<?php echo esc_js( (string) $contract ); ?>';

			var root = document.querySelector( '[data-cx-tracker-feedback="' + SLUG + '"]' );

			// Refuse markup this build does not understand. Two bundled copies at different SDK
			// versions both run their own script; each enhances only a root whose contract it
			// recognises, and an unenhanced root just means the native Deactivate link is used.
			if ( ! root || CONTRACT !== root.getAttribute( 'data-cx-contract' ) ) {
				return;
			}

			if ( '1' === root.getAttribute( 'data-cx-ready' ) ) {
				return;
			}

			var basename = root.getAttribute( 'data-cx-basename' ) || '';
			var endpoint = root.getAttribute( 'data-cx-endpoint' ) || '';
			var deadline = parseInt( root.getAttribute( 'data-cx-timeout' ), 10 ) || 2000;
			var sending  = root.getAttribute( 'data-cx-sending' ) || '';

			if ( ! basename || ! endpoint ) {
				return;
			}

			var dialog  = root.querySelector( '.cx-tracker-feedback__dialog' );
			var note    = root.querySelector( '[data-cx-note]' );
			var noteLbl = root.querySelector( '[data-cx-note-label]' );
			var radios  = root.querySelectorAll( '[data-cx-reason]' );

			// Conditional comment box, hidden only once JS is running so no-JS keeps the box.
			if ( note ) {
				note.hidden = true;

				for ( var r = 0; r < radios.length; r++ ) {
					radios[ r ].addEventListener( 'change', function ( event ) {
						var prompt = event.target.getAttribute( 'data-cx-prompt' );

						if ( noteLbl && prompt ) {
							noteLbl.textContent = prompt;
						}

						note.hidden = false;
					} );
				}
			}

			var form    = root.querySelector( '.cx-tracker-feedback__form' );
			var skip    = root.querySelector( '.cx-tracker-feedback__skip' );
			var submit  = root.querySelector( '.cx-tracker-feedback__submit' );

			if ( ! dialog || ! form || ! skip || ! submit ) {
				return;
			}

			// Is this anchor OUR row's Deactivate link?
			//
			// Decided by parsing the href's own query string and comparing `plugin` to our exact
			// basename -- not by a row selector, a link id, or a slug prefix. plugins.php lists
			// every plugin on the site and several may bundle this same SDK, so the test has to be
			// the identity WordPress itself keys the action on. A URL we cannot parse is treated as
			// "not ours", which leaves it native.
			function ours( link ) {
				var href = link.getAttribute( 'href' );
				var url;

				if ( ! href ) {
					return false;
				}

				try {
					url = new URL( href, window.location.href );
				} catch ( e ) {
					return false;
				}

				if ( ! url.searchParams || 'deactivate' !== url.searchParams.get( 'action' ) ) {
					return false;
				}

				return basename === url.searchParams.get( 'plugin' );
			}

			var links = [];
			var all   = document.querySelectorAll( 'a[href*="deactivate"]' );
			var i;

			for ( i = 0; i < all.length; i++ ) {
				if ( ours( all[ i ] ) ) {
					links.push( all[ i ] );
				}
			}

			// Our plugin is not on this page (another status filter, another paged view, or it is
			// not active). Nothing to enhance; every other plugin's link is left completely alone.
			if ( ! links.length ) {
				return;
			}

			root.setAttribute( 'data-cx-ready', '1' );

			var target   = '';
			var opener   = null;
			var leaving  = false;
			var focusSel = 'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled])';

			function focusable() {
				return dialog.querySelectorAll( focusSel );
			}

			function onKey( event ) {
				var items;
				var first;
				var last;

				if ( 'Escape' === event.key || 'Esc' === event.key || 27 === event.keyCode ) {
					event.preventDefault();
					close();
					return;
				}

				if ( 'Tab' !== event.key && 9 !== event.keyCode ) {
					return;
				}

				items = focusable();

				if ( ! items.length ) {
					return;
				}

				first = items[ 0 ];
				last  = items[ items.length - 1 ];

				// A real trap, not just an initial focus: this dialog interrupts a destructive
				// action, so Tab must not wander back into the plugins table behind it.
				if ( event.shiftKey && document.activeElement === first ) {
					event.preventDefault();
					last.focus();
				} else if ( ! event.shiftKey && document.activeElement === last ) {
					event.preventDefault();
					first.focus();
				}
			}

			function open( href, link ) {
				target = href;
				opener = link;

				// Upgrade the skip href from the server-computed URL to the exact link the user
				// clicked, so skipping returns them to the same filter and page of the list. The
				// server-computed value is already a working deactivate URL, so this is an
				// improvement on a fallback, never a repair of a broken one.
				skip.setAttribute( 'href', href );

				root.hidden = false;
				root.classList.add( 'is-open' );
				document.addEventListener( 'keydown', onKey, true );

				var items = focusable();

				if ( items.length ) {
					items[ 0 ].focus();
				}
			}

			function close() {
				root.classList.remove( 'is-open' );
				root.hidden = true;
				document.removeEventListener( 'keydown', onKey, true );

				// Escape and Cancel abort the deactivation and return focus to where it was. The
				// link is untouched, so the user can click it again.
				if ( opener && opener.focus ) {
					opener.focus();
				}
			}

			function go() {
				if ( leaving ) {
					return;
				}

				leaving = true;
				window.location.href = target;
			}

			for ( i = 0; i < links.length; i++ ) {
				links[ i ].addEventListener( 'click', function ( event ) {
					var href = this.getAttribute( 'href' );

					// Let the browser own modified clicks: ctrl/cmd-click means "new tab", and
					// hijacking that would be both wrong and surprising.
					if ( event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
						return;
					}

					event.preventDefault();

					// Everything after preventDefault() has taken responsibility for a navigation
					// the browser was about to perform, so it hands that responsibility back on
					// any failure rather than leaving the administrator stuck.
					try {
						open( href, this );
					} catch ( e ) {
						target  = href;
						leaving = false;
						go();
					}
				} );
			}

			var closers = root.querySelectorAll( '[data-cx-close]' );

			for ( i = 0; i < closers.length; i++ ) {
				closers[ i ].addEventListener( 'click', function ( event ) {
					event.preventDefault();
					close();
				} );
			}

			form.addEventListener( 'submit', function ( event ) {
				var data;

				// No fetch() means no way to POST without leaving the page, so the native form
				// submission is allowed to happen: it posts to admin-post.php, and the handler
				// redirects to the deactivate URL. Slower, one extra hop, still deactivates.
				if ( ! window.fetch ) {
					return;
				}

				event.preventDefault();

				submit.disabled = true;

				if ( sending ) {
					submit.textContent = sending;
				}

				// Armed BEFORE the request is constructed, so navigation is guaranteed even if
				// building the request throws. This is the line that makes the redirect
				// ungated by the network: it fires at the deadline no matter what the request is
				// doing, and the request is never awaited or inspected.
				window.setTimeout( go, deadline );

				try {
					data = new FormData( form );

					// keepalive lets the POST outlive the navigation that is about to happen, so
					// the message is still delivered even though nothing waits for it.
					window.fetch( endpoint, {
						method: 'POST',
						body: data,
						credentials: 'same-origin',
						keepalive: true
					} ).then( go, go );
				} catch ( e ) {
					go();
				}
			} );
		} )();
		</script>

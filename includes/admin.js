/**
 * Tanupom In-Place Converter for WebP admin tools page script.
 *
 * Drives the inventory scan, batched bulk conversion, and reference
 * replacement through admin-ajax. No build step, ES5 compatible, wrapped in an
 * IIFE to avoid polluting the global scope.
 */
( function () {
	'use strict';

	var cfg = window.TanupomIpc || {};

	/**
	 * POST to admin-ajax as application/x-www-form-urlencoded.
	 *
	 * @param {string}   action wp_ajax action name to invoke.
	 * @param {Function} done   Success callback receiving the response data.
	 * @param {Function} fail   Failure callback.
	 */
	function post( action, done, fail ) {
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', cfg.ajaxUrl, true );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.onreadystatechange = function () {
			if ( 4 !== xhr.readyState ) {
				return;
			}
			var res = null;
			try {
				res = JSON.parse( xhr.responseText );
			} catch ( e ) {
				res = null;
			}
			if ( 200 === xhr.status && res && res.success ) {
				done( res.data );
			} else {
				fail( res );
			}
		};
		xhr.send( 'action=' + encodeURIComponent( action ) + '&nonce=' + encodeURIComponent( cfg.nonce ) );
	}

	/**
	 * Minimal sprintf for the localized progress strings. Replaces positional
	 * `%1$d`-style placeholders so translators can reorder them per language.
	 *
	 * @param {string} tpl  Localized template (e.g. cfg.i18n.progress).
	 * @param {Array}  args Replacement values, indexed from 1 in the template.
	 * @return {string} The filled string.
	 */
	function format( tpl, args ) {
		return String( tpl ).replace( /%(\d+)\$d/g, function ( m, n ) {
			return args[ n - 1 ];
		} );
	}

	// --- Inventory scan ------------------------------------------------------
	var scanBtn = document.getElementById( 'tanupom-ipc-scan' );
	var scanOut = document.getElementById( 'tanupom-ipc-scan-result' );

	if ( scanBtn ) {
		scanBtn.addEventListener( 'click', function () {
			scanBtn.disabled = true;
			scanOut.style.display = 'block';
			scanOut.textContent = cfg.i18n.scanning;
			post(
				'tanupom_ipc_scan',
				function ( data ) {
					scanOut.textContent = JSON.stringify( data, null, 2 );
					scanBtn.disabled = false;
				},
				function () {
					scanOut.textContent = cfg.i18n.error;
					scanBtn.disabled = false;
				}
			);
		} );
	}

	// --- Bulk conversion (batch loop) ----------------------------------------
	var convBtn  = document.getElementById( 'tanupom-ipc-convert' );
	var progress = document.getElementById( 'tanupom-ipc-progress' );
	var bar      = document.getElementById( 'tanupom-ipc-bar' );
	var status   = document.getElementById( 'tanupom-ipc-status' );
	var log      = document.getElementById( 'tanupom-ipc-log' );

	var total = 0;

	/**
	 * Append a line to the bulk-conversion log, then scroll it to the bottom.
	 *
	 * @param {string} line Text to append (a newline is added).
	 */
	function appendLog( line ) {
		log.textContent += line + '\n';
		log.scrollTop = log.scrollHeight;
	}

	/**
	 * Drive one bulk-conversion batch and recurse until the server reports
	 * remaining === 0.
	 */
	function runBatch() {
		post(
			'tanupom_ipc_convert_batch',
			function ( data ) {
				var i;
				for ( i = 0; i < data.results.length; i++ ) {
					var r = data.results[ i ];
					appendLog( '#' + r.id + ' ' + ( r.success ? ( r.skipped ? 'skip' : 'ok' ) : 'FAIL' ) + ' — ' + r.message );
				}
				var doneCount = total - data.remaining;
				bar.value = doneCount;
				status.textContent = format( cfg.i18n.progress, [ doneCount, total, data.remaining, data.map_count ] );

				if ( data.remaining > 0 && data.processed > 0 ) {
					runBatch();
				} else {
					status.textContent = format( cfg.i18n.doneSummary, [ doneCount, total, data.map_count ] );
					convBtn.disabled = false;
				}
			},
			function () {
				status.textContent = cfg.i18n.error;
				convBtn.disabled = false;
			}
		);
	}

	if ( convBtn ) {
		convBtn.addEventListener( 'click', function () {
			convBtn.disabled = true;
			progress.style.display = 'block';
			total = parseInt( bar.getAttribute( 'max' ), 10 ) || 0;
			bar.value = 0;
			log.textContent = '';
			status.textContent = cfg.i18n.converting;
			runBatch();
		} );
	}

	// --- Reference replacement -----------------------------------------------
	var repBtn = document.getElementById( 'tanupom-ipc-replace' );
	var repOut = document.getElementById( 'tanupom-ipc-replace-result' );

	if ( repBtn ) {
		repBtn.addEventListener( 'click', function () {
			repBtn.disabled = true;
			repOut.style.display = 'block';
			repOut.textContent = cfg.i18n.replacing;
			post(
				'tanupom_ipc_replace',
				function ( data ) {
					repOut.textContent = cfg.i18n.done + '\n' + JSON.stringify( data, null, 2 );
					repBtn.disabled = false;
				},
				function () {
					repOut.textContent = cfg.i18n.error;
					repBtn.disabled = false;
				}
			);
		} );
	}
} )();

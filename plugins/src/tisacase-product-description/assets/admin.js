/**
 * TisaCase — Product Description Rules admin panel JS.
 */
( function ( $ ) {
	'use strict';

	function esc( str ) {
		var div = document.createElement( 'div' );
		div.appendChild( document.createTextNode( str ) );
		return div.innerHTML;
	}

	function fmt( n ) {
		return Number( n ).toLocaleString( 'fa-IR' );
	}

	// Fail loudly if the localized data (tisa) did not load.
	function tisaReady() {
		if ( typeof tisa === 'undefined' || ! tisa.ajax || ! tisa.nonce ) {
			window.alert( 'داده‌های افزونه TisaCase بارگذاری نشد (خطای JS). لطفاً کش مرورگر را پاک کنید یا صفحه را تازه‌سازی کنید.' );
			return false;
		}
		return true;
	}

	// Extract the server's error message when present.
	function serverMsg( res ) {
		if ( res && res.data && res.data.message ) {
			return String( res.data.message );
		}
		return tisa.i18n.error;
	}

	// admin-ajax.php answers -1 / 0 / '' when the nonce is invalid or the
	// action is not registered (session expired / stale page).
	function isAuthFailure( res ) {
		return res === -1 || res === '-1' || res === 0 || res === '0' || res === '' || res === null || res === false;
	}

	// Show the raw server response when jQuery could not parse the JSON.
	// This surfaces PHP warnings / fatal errors that corrupted the response.
	function ajaxFailText( xhr ) {
		if ( xhr && xhr.responseText ) {
			var t = String( xhr.responseText )
				.replace( /<[^>]*>/g, ' ' )
				.replace( /\s+/g, ' ' )
				.trim();
			if ( t && t.length > 400 ) {
				t = t.substring( 0, 400 ) + '…';
			}
			if ( t ) {
				return t;
			}
		}
		return tisa.i18n.error;
	}

	function readCheckpoint( key ) {
		try {
			var value = window.sessionStorage.getItem( key );
			return value ? JSON.parse( value ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function writeCheckpoint( key, value ) {
		try { window.sessionStorage.setItem( key, JSON.stringify( value ) ); } catch ( e ) { /* Storage can be disabled or full. */ }
	}

	function clearCheckpoint( key ) {
		try { window.sessionStorage.removeItem( key ); } catch ( e ) { /* Storage can be disabled. */ }
	}

	// True when the response is a valid success object.
	function isOk( res ) {
		return !!res && typeof res === 'object' && res.success === true;
	}

	/* ------------------------------------------------------------ */
	/* Bulk repair                                                   */
	/* ------------------------------------------------------------ */

	function runRepair() {
		if ( ! tisaReady() ) { return; }
		var $btn = $( '#tc-run' );
		var $fill = $( '#tc-progress-fill' );
		var $text = $( '#tc-progress-text' );
		var $wrap = $( '#tc-progress-wrap' );
		var dry = $( '#tc-dry' ).is( ':checked' );

		var checkpointKey = 'tisacase-desc-repair-' + ( dry ? 'dry' : 'live' );
		var checkpoint = readCheckpoint( checkpointKey );
		var resume = checkpoint && window.confirm( 'یک اصلاح ناتمام در همین زبانه پیدا شد. ادامه از آخرین نقطهٔ ذخیره‌شده؟ (لغو = شروع تازه)' );
		if ( ! resume ) {
			if ( checkpoint ) { clearCheckpoint( checkpointKey ); }
			if ( ! window.confirm( tisa.i18n.confirm ) ) { return; }
		}

		$btn.prop( 'disabled', true );
		$wrap.prop( 'hidden', false );

		var total = resume ? ( Number( checkpoint.total ) || 0 ) : 0;
		var processed = resume ? ( Number( checkpoint.processed ) || 0 ) : 0;
		var changed = resume ? ( Number( checkpoint.changed ) || 0 ) : 0;
		var page = resume ? ( Number( checkpoint.page ) || 0 ) : 0;
		var offset = resume ? ( Number( checkpoint.offset ) || 0 ) : 0;
		var snapshot = resume ? ( checkpoint.snapshot || '' ) : '';

		function fail( msg ) {
			$text.text( msg || tisa.i18n.error );
			$btn.prop( 'disabled', false );
		}

		function step() {
			$text.text( tisa.i18n.running + ' (' + fmt( processed ) + ')' );

			$.post( tisa.ajax, {
				action: 'tisacase_desc_repair',
				nonce: tisa.nonce,
				page: page,
				offset: offset,
				dry: dry ? 1 : 0,
				snapshot: snapshot
			} ).done( function ( res ) {
				if ( ! isOk( res ) ) {
					fail( isAuthFailure( res ) ? tisa.i18n.sessionExpired : serverMsg( res ) );
					return;
				}

				var d = res.data;
				total = d.total || total;
				processed += d.processed || 0;
				changed += d.changed || 0;
				snapshot = d.snapshot || snapshot;

				$( '#tc-total' ).text( fmt( total ) );
				$( '#tc-processed' ).text( fmt( processed ) );
				$( '#tc-changed' ).text( fmt( changed ) );

				var pct = total > 0 ? Math.min( 100, Math.round( ( processed / total ) * 100 ) ) : 100;
				$fill.css( 'width', pct + '%' );

				if ( d.done ) {
					clearCheckpoint( checkpointKey );
					$fill.css( 'width', '100%' );
					$text.text( tisa.i18n.done + ' — ' + fmt( changed ) );
					$btn.prop( 'disabled', false );
					return;
				}

				page = ( typeof d.next_page === 'number' ) ? d.next_page : d.next;
				offset = Number( d.next_offset ) || 0;
				writeCheckpoint( checkpointKey, { page: page, offset: offset, snapshot: snapshot, total: total, processed: processed, changed: changed } );
				step();
			} ).fail( function ( xhr ) {
				fail( ajaxFailText( xhr ) );
			} );
		}

		writeCheckpoint( checkpointKey, { page: page, offset: offset, snapshot: snapshot, total: total, processed: processed, changed: changed } );
		step();
	}

	/* ------------------------------------------------------------ */
	/* Live preview                                                  */
	/* ------------------------------------------------------------ */

	function runPreview() {
		if ( ! tisaReady() ) { return; }
		var $id = $( '#tc-preview-id' );
		var $out = $( '#tc-preview-result' );
		var id = parseInt( $id.val(), 10 );

		if ( ! id ) {
			alert( tisa.i18n.noId );
			return;
		}

		$out.prop( 'hidden', true );

		$.post( tisa.ajax, {
			action: 'tisacase_desc_preview',
			nonce: tisa.nonce,
			product_id: id
		} ).done( function ( res ) {
			if ( ! isOk( res ) ) {
				$out.html( '<div class="tc-empty">' + esc( isAuthFailure( res ) ? tisa.i18n.sessionExpired : serverMsg( res ) ) + '</div>' );
				$out.prop( 'hidden', false );
				return;
			}

			var d = res.data;
			var pillClass = 'tc-pill--' + d.rule;
			var html =
				'<div class="tc-preview-meta">' +
					'<span class="tc-pill ' + pillClass + '">' + esc( d.label ) + '</span>' +
					'<span>' + esc( d.name ) + '</span>' +
					( d.sku ? '<span dir="ltr">SKU: ' + esc( d.sku ) + '</span>' : '' ) +
				'</div>';

			if ( d.html ) {
				html += '<div class="tc-preview-desc">' + d.html + '</div>';
			} else {
				html += '<div class="tc-empty">بدون توضیح — این محصول مشمول هیچ قانونی نیست.</div>';
			}

			$out.html( html );
			$out.prop( 'hidden', false );
		} ).fail( function ( xhr ) {
			$out.html( '<div class="tc-empty">' + esc( ajaxFailText( xhr ) ) + '</div>' );
			$out.prop( 'hidden', false );
		} );
	}

	/* ------------------------------------------------------------ */
	/* Scan (read-only) + selective apply                            */
	/* ------------------------------------------------------------ */

	var scan = {
		running: false,
		total: 0,
		processed: 0,
		filter: 'all',
		mismatches: [],
		truncated: false
	};

	// Minimal sanitising before inserting stored HTML into the DOM.
	function safeHtml( html ) {
		var d = document.createElement( 'div' );
		d.innerHTML = html;
		d.querySelectorAll( 'script,style,iframe,object,embed,link,meta,form' ).forEach( function ( el ) {
			el.remove();
		} );
		d.querySelectorAll( '*' ).forEach( function ( el ) {
			[].forEach.call( el.attributes, function ( attr ) {
				if ( /^on/i.test( attr.name ) ) {
					el.removeAttribute( attr.name );
				}
			} );
		} );
		return d.innerHTML;
	}

	function scanToggle( cell ) {
		var btn = $( '<button type="button" class="tc-scan-toggle">' + esc( tisa.i18n.showFull ) + '</button>' );
		btn.on( 'click', function () {
			if ( cell.hasClass( 'is-expanded' ) ) {
				cell.removeClass( 'is-expanded' );
				btn.text( tisa.i18n.showFull );
			} else {
				cell.addClass( 'is-expanded' );
				btn.text( tisa.i18n.hideFull );
			}
		} );
		cell.append( btn );
	}

	function scanDiffCell( label, html, emptyText, isAfter ) {
		var cell = $( '<div class="' + ( isAfter ? 'tc-scan-after' : 'tc-scan-before' ) + ' is-clamped"><span class="tc-scan-tag">' + esc( label ) + '</span></div>' );
		var body = $( '<div class="tc-scan-body"></div>' );
		if ( html && html.trim() ) {
			body.html( safeHtml( html ) );
			if ( body.text().trim().length > 130 ) {
				scanToggle( cell );
			} else {
				cell.removeClass( 'is-clamped' );
			}
		} else {
			body.html( '<span class="tc-scan-empty">' + esc( emptyText ) + '</span>' );
			cell.removeClass( 'is-clamped' );
		}
		cell.append( body );
		return cell;
	}

	function scanBuildRow( p ) {
		var row = $( '<div class="tc-scan-row" data-rule="' + p.rule + '" data-id="' + p.id + '"></div>' );

		row.append( '<label class="tc-check tc-scan-check"><input type="checkbox" class="tc-scan-select"></label>' );

		var meta = '#' + p.id + ' &middot; ' + esc( p.status_label );
		if ( p.sku ) {
			meta += ' &middot; <span dir="ltr">SKU: ' + esc( p.sku ) + '</span>';
		}
		if ( p.edit_link ) {
			meta += ' &middot; <a href="' + esc( p.edit_link ) + '" target="_blank" rel="noopener">' + esc( 'ویرایش' ) + '</a>';
		}

		row.append(
			'<div class="tc-scan-prod"><span class="tc-scan-name">' + esc( p.name ) + '</span><span class="tc-scan-meta">' + meta + '</span></div>'
		);
		row.append( '<span class="tc-scan-rule tc-pill tc-pill--' + p.rule + '">' + esc( p.rule_label ) + '</span>' );

		var diff = $( '<div class="tc-scan-diff"></div>' );
		diff.append( scanDiffCell( tisa.i18n.currentLabel, p.current, tisa.i18n.emptyDesc, false ) );
		diff.append( '<span class="tc-scan-arrow">&#8594;</span>' );
		diff.append( scanDiffCell( tisa.i18n.newLabel, p.new, tisa.i18n.clearDesc, true ) );
		row.append( diff );

		return row;
	}

	function scanRender() {
		var $list = $( '#tc-scan-list' );
		$list.empty();
		$( '#tc-scan-note' ).empty();

		if ( scan.mismatches.length === 0 ) {
			$( '#tc-scan-summary' ).empty();
			$( '#tc-scan-filters' ).prop( 'hidden', true );
			$( '#tc-scan-selectall-wrap' ).prop( 'hidden', true );
			$( '#tc-scan-selectall' ).prop( 'checked', false );
			$( '#tc-apply' ).prop( 'disabled', true );
			$( '#tc-apply-hint' ).text( '' );
			$list.html(
				'<div class="tc-notice tc-notice--success"><span class="tc-notice-icon">✔</span> ' + esc( tisa.i18n.noMismatch ) + '</div>'
			);
			return;
		}

		$( '#tc-scan-filters' ).prop( 'hidden', false );
		$( '#tc-scan-selectall-wrap' ).prop( 'hidden', false );

		scan.mismatches.forEach( function ( p ) {
			$list.append( scanBuildRow( p ) );
		} );

		scanApplyFilter( scan.filter );
		scanUpdateSummary();
		scanUpdateSelectAll();
	}

	function scanUpdateSummary() {
		var n = scan.mismatches.length;
		$( '#tc-scan-summary' ).text( n > 0 ? tisa.i18n.mismatchSummary.replace( '{n}', fmt( n ) ) : '' );
	}

	function scanApplyFilter( f ) {
		scan.filter = f;
		$( '#tc-scan-filters .tc-chip' ).each( function () {
			$( this ).toggleClass( 'is-active', $( this ).data( 'filter' ) === f );
		} );
		$( '#tc-scan-list .tc-scan-row' ).each( function () {
			var rule = $( this ).data( 'rule' );
			$( this ).toggleClass( 'is-hidden', f !== 'all' && rule !== f );
		} );
		scanUpdateSelectAll();
	}

	function scanCountChecked() {
		return $( '#tc-scan-list .tc-scan-select:checked' ).length;
	}

	function scanUpdateApply() {
		var n = scanCountChecked();
		$( '#tc-apply' ).prop( 'disabled', n === 0 );
		$( '#tc-apply-hint' ).text( n > 0 ? n + ' ' + tisa.i18n.selected : '' );
	}

	function scanUpdateSelectAll() {
		var visible = $( '#tc-scan-list .tc-scan-row' ).filter( function () {
			return ! $( this ).hasClass( 'is-hidden' );
		} );
		var visibleChecked = visible.find( '.tc-scan-select:checked' ).length;
		$( '#tc-scan-selectall' ).prop( 'checked', visible.length > 0 && visibleChecked === visible.length );
		scanUpdateApply();
	}

	function runScan() {
		if ( ! tisaReady() ) { return; }
		if ( scan.running ) {
			return;
		}

		scan.running = true;
		scan.total = 0;
		scan.processed = 0;
		scan.filter = 'all';
		scan.mismatches = [];
		scan.truncated = false;

		$( '#tc-scan-result' ).prop( 'hidden', true );
		$( '#tc-scan-progress-wrap' ).prop( 'hidden', false );
		$( '#tc-scan' ).prop( 'disabled', true );
		$( '#tc-scan-progress-text' ).text( tisa.i18n.scanRunning );

		var page = 0;
		var offset = 0;

		function fail( msg ) {
			$( '#tc-scan-progress-text' ).text( msg || tisa.i18n.error );
			$( '#tc-scan' ).prop( 'disabled', false );
			scan.running = false;
		}

		function finish() {
			$( '#tc-scan-progress-fill' ).css( 'width', '100%' );
			$( '#tc-scan-progress-text' ).text( tisa.i18n.scanDone + ( scan.truncated ? ' · فقط ۱۰۰۰ مورد نخست برای نمایش نگه داشته شد.' : '' ) );
			$( '#tc-scan' ).prop( 'disabled', false );
			scan.running = false;
			scanRender();
			$( '#tc-scan-result' ).prop( 'hidden', false );
		}

		function step() {
			$.post( tisa.ajax, {
				action: 'tisacase_desc_scan',
				nonce: tisa.nonce,
				page: page,
				offset: offset
			} ).done( function ( res ) {
				if ( ! isOk( res ) ) {
					fail( isAuthFailure( res ) ? tisa.i18n.sessionExpired : serverMsg( res ) );
					return;
				}
				var d = res.data;
				scan.total = d.total || scan.total;
				scan.processed += d.processed || 0;
				var incoming = d.mismatches || [];
				var room = Math.max( 0, 1000 - scan.mismatches.length );
				scan.mismatches = scan.mismatches.concat( incoming.slice( 0, room ) );
				if ( incoming.length > room || scan.mismatches.length >= 1000 ) { scan.truncated = true; }

				var pct = scan.total > 0 ? Math.min( 100, Math.round( ( scan.processed / scan.total ) * 100 ) ) : 100;
				$( '#tc-scan-progress-fill' ).css( 'width', pct + '%' );
				$( '#tc-scan-progress-text' ).text(
					tisa.i18n.scanRunning + ' (' + fmt( scan.processed ) + ' / ' + fmt( scan.total ) + ')'
				);

				if ( d.done ) {
					finish();
					return;
				}
				page = ( typeof d.next_page === 'number' ) ? d.next_page : d.next;
				offset = Number( d.next_offset ) || 0;
				step();
			} ).fail( function ( xhr ) {
				fail( ajaxFailText( xhr ) );
			} );
		}

		step();
	}

	function runApply() {
		if ( ! tisaReady() ) { return; }
		var checkpointKey = 'tisacase-desc-selective-apply';
		var checkpoint = readCheckpoint( checkpointKey );
		var resume = checkpoint && window.confirm( 'یک اصلاح انتخابی ناتمام پیدا شد. از نقطهٔ ذخیره‌شده ادامه بدهم؟ (لغو = شروع تازه)' );
		var ids = resume ? ( checkpoint.ids || [] ) : [];
		var offset = resume ? ( Number( checkpoint.offset ) || 0 ) : 0;
		var snapshot = resume ? ( checkpoint.snapshot || '' ) : '';
		var changed = resume ? ( Number( checkpoint.changed ) || 0 ) : 0;
		var unchanged = resume ? ( Number( checkpoint.unchanged ) || 0 ) : 0;

		if ( ! resume ) {
			if ( checkpoint ) { clearCheckpoint( checkpointKey ); }
			$( '#tc-scan-list .tc-scan-select:checked' ).each( function () {
				ids.push( parseInt( $( this ).closest( '.tc-scan-row' ).data( 'id' ), 10 ) );
			} );
			if ( ! ids.length || ! window.confirm( tisa.i18n.applyConfirm.replace( '{n}', fmt( ids.length ) ) ) ) {
				return;
			}
			writeCheckpoint( checkpointKey, { ids: ids, offset: offset, snapshot: snapshot, changed: changed, unchanged: unchanged } );
		}
		if ( ! ids.length ) { clearCheckpoint( checkpointKey ); return; }

		var $btn = $( '#tc-apply' ).prop( 'disabled', true );
		function fail( message ) {
			alert( message || tisa.i18n.error );
			$btn.prop( 'disabled', false );
			scanUpdateApply();
		}
		function finish() {
			clearCheckpoint( checkpointKey );
			var set = {};
			ids.forEach( function ( id ) { set[ id ] = 1; } );
			scan.mismatches = scan.mismatches.filter( function ( p ) { return ! set[ p.id ]; } );
			$( '#tc-scan-note' ).html(
				'<div class="tc-notice tc-notice--success"><span class="tc-notice-icon">✔</span> ' +
				esc( tisa.i18n.applied.replace( '{changed}', fmt( changed ) ).replace( '{unchanged}', fmt( unchanged ) ) ) +
				'</div>'
			);
			if ( scan.mismatches.length === 0 ) {
				$( '#tc-scan-list' ).html(
					'<div class="tc-notice tc-notice--success"><span class="tc-notice-icon">✔</span> ' + esc( tisa.i18n.allDone ) + '</div>'
				);
				$( '#tc-scan-summary' ).empty();
				$( '#tc-scan-filters' ).prop( 'hidden', true );
				$( '#tc-scan-selectall-wrap' ).prop( 'hidden', true );
				$( '#tc-scan-selectall' ).prop( 'checked', false );
			} else {
				$( '#tc-scan-list .tc-scan-row' ).each( function () {
					var id = $( this ).data( 'id' );
					if ( set[ id ] ) { $( this ).remove(); }
				} );
				scanUpdateSummary();
				scanUpdateSelectAll();
			}
			$btn.prop( 'disabled', false );
			scanUpdateApply();
		}
		function step() {
			$.post( tisa.ajax, {
				action: 'tisacase_desc_apply',
				nonce: tisa.nonce,
				ids: ids,
				offset: offset,
				snapshot: snapshot
			} ).done( function ( res ) {
				if ( ! isOk( res ) ) {
					fail( isAuthFailure( res ) ? tisa.i18n.sessionExpired : serverMsg( res ) );
					return;
				}
				var d = res.data;
				changed += d.changed || 0;
				unchanged += d.unchanged || 0;
				snapshot = d.snapshot || snapshot;
				if ( d.done ) { finish(); return; }
				offset = Number( d.next_offset ) || 0;
				writeCheckpoint( checkpointKey, { ids: ids, offset: offset, snapshot: snapshot, changed: changed, unchanged: unchanged } );
				step();
			} ).fail( function ( xhr ) {
				fail( ajaxFailText( xhr ) );
			} );
		}
		step();
	}

	/* ------------------------------------------------------------ */
	/* Restore / delete backups                                      */
	/* ------------------------------------------------------------ */

	function runRestore( $btn ) {
		if ( ! tisaReady() ) { return; }
		var $row = $btn.closest( '.tc-backup-row' );
		var sid = $row.data( 'snapshot' );

		if ( ! window.confirm( tisa.i18n.restoreConfirm ) ) {
			return;
		}

		var $wrap = $( '#tc-restore-progress' ).prop( 'hidden', false );
		var $fill = $( '#tc-restore-fill' );
		var $text = $( '#tc-restore-text' );
		$( '#tc-restore-note' ).empty();
		$btn.prop( 'disabled', true );

		var page = 0;
		var processed = 0;
		var restored = 0;
		var skipped = 0;

		function fail( msg ) {
			$text.text( msg || tisa.i18n.error );
			$btn.prop( 'disabled', false );
		}

		function step() {
			$text.text( tisa.i18n.restoring + ' (' + fmt( processed ) + ')' );

			$.post( tisa.ajax, {
				action: 'tisacase_desc_restore',
				nonce: tisa.nonce,
				snapshot: sid,
				page: page
			} ).done( function ( res ) {
				if ( ! isOk( res ) ) {
					fail( isAuthFailure( res ) ? tisa.i18n.sessionExpired : serverMsg( res ) );
					return;
				}
				var d = res.data;
				processed += d.processed || 0;
				restored += d.restored || 0;
				skipped += d.skipped || 0;

				var pct = d.total > 0 ? Math.min( 100, Math.round( ( processed / d.total ) * 100 ) ) : 100;
				$fill.css( 'width', pct + '%' );

				if ( d.done ) {
					$fill.css( 'width', '100%' );
					$text.text( tisa.i18n.restoreDone );
					$( '#tc-restore-note' ).html(
						'<div class="tc-notice tc-notice--success"><span class="tc-notice-icon">✔</span> ' +
						esc( tisa.i18n.restoreSummary.replace( '{restored}', fmt( restored ) ).replace( '{skipped}', fmt( skipped ) ) ) +
						'</div>'
					);
					setTimeout( function () { window.location.reload(); }, 1600 );
					return;
				}
				page = ( typeof d.next_page === 'number' ) ? d.next_page : d.next;
				offset = Number( d.next_offset ) || 0;
				step();
			} ).fail( function ( xhr ) {
				fail( ajaxFailText( xhr ) );
			} );
		}

		step();
	}

	function deleteBackup( $btn ) {
		var $row = $btn.closest( '.tc-backup-row' );
		var sid = $row.data( 'snapshot' );

		if ( ! window.confirm( tisa.i18n.deleteConfirm ) ) {
			return;
		}

		$btn.prop( 'disabled', true );

		$.post( tisa.ajax, {
			action: 'tisacase_desc_backup_delete',
			nonce: tisa.nonce,
			snapshot: sid
		} ).done( function ( res ) {
			if ( isOk( res ) ) {
				$row.slideUp( 200, function () { $row.remove(); } );
			} else {
				alert( isAuthFailure( res ) ? tisa.i18n.sessionExpired : serverMsg( res ) );
				$btn.prop( 'disabled', false );
			}
		} ).fail( function ( xhr ) {
			alert( ajaxFailText( xhr ) );
			$btn.prop( 'disabled', false );
		} );
	}

	/* ------------------------------------------------------------ */
	/* Prep-time: meta key detection + batch apply                  */
	/* ------------------------------------------------------------ */

	function runMetaDetect() {
		if ( ! tisaReady() ) { return; }
		var $btn = $( '#tc-meta-detect' );
		var $out = $( '#tc-meta-detect-result' );

		$btn.prop( 'disabled', true );
		$out.html( '<span class="tc-detect-hint">' + esc( tisa.i18n.detectRunning ) + '</span>' );

		$.post( tisa.ajax, {
			action: 'tisacase_desc_meta_detect',
			nonce: tisa.nonce
		} ).done( function ( res ) {
			$btn.prop( 'disabled', false );
			if ( ! isOk( res ) ) {
				$out.html( '<span class="tc-detect-hint">' + esc( isAuthFailure( res ) ? tisa.i18n.sessionExpired : serverMsg( res ) ) + '</span>' );
				return;
			}
			var d = res.data;
			var html = '<span class="tc-detect-hint">' +
				esc( tisa.i18n.detectHint.replace( '{name}', d.name ).replace( '{id}', d.id ) ) +
				'</span>';
			d.meta.forEach( function ( m ) {
				html += '<button type="button" class="tc-detect-item' + ( m.likely ? ' tc-detect-item--likely' : '' ) + '" data-key="' + esc( m.key ) + '">' +
					'<span class="tc-detect-key" dir="ltr">' + esc( m.key ) + '</span>' +
					'<span class="tc-detect-val" dir="ltr">' + esc( m.value ) + '</span>' +
					'</button>';
			} );
			$out.html( html );
		} ).fail( function ( xhr ) {
			$btn.prop( 'disabled', false );
			$out.html( '<span class="tc-detect-hint">' + esc( ajaxFailText( xhr ) ) + '</span>' );
		} );
	}

	function runPrepApply() {
		if ( ! tisaReady() ) { return; }
		var $btn = $( '#tc-prep-apply' );

		var checkpointKey = 'tisacase-desc-prep-apply';
		var checkpoint = readCheckpoint( checkpointKey );
		var resume = checkpoint && window.confirm( 'یک اجرای زمان آماده‌سازی ناتمام پیدا شد. از آخرین نقطه ادامه بدهم؟ (لغو = شروع تازه)' );
		if ( ! resume ) {
			if ( checkpoint ) { clearCheckpoint( checkpointKey ); }
			if ( ! window.confirm( tisa.i18n.prepApplyConfirm ) ) { return; }
		}

		var $wrap = $( '#tc-prep-progress' ).prop( 'hidden', false );
		var $fill = $( '#tc-prep-fill' );
		var $text = $( '#tc-prep-text' );
		$btn.prop( 'disabled', true );

		var page = resume ? ( Number( checkpoint.page ) || 0 ) : 0;
		var offset = resume ? ( Number( checkpoint.offset ) || 0 ) : 0;
		var snapshot = resume ? ( checkpoint.snapshot || '' ) : '';
		var processed = resume ? ( Number( checkpoint.processed ) || 0 ) : 0;
		var changed = resume ? ( Number( checkpoint.changed ) || 0 ) : 0;

		// Send the current form values too, so the button works even
		// before pressing "ذخیره تنظیمات".
		var formKey = resume ? checkpoint.formKey : ( $( '#tc-prep-key' ).val() || '' );
		var formVal = resume ? checkpoint.formVal : ( $( '#tc-prep-value' ).val() || '' );
		var onlyEmpty = resume ? Number( checkpoint.onlyEmpty ) || 0 : ( $( '#tc-prep-onlyempty' ).is( ':checked' ) ? 1 : 0 );

		function fail( msg ) {
			$text.text( msg || tisa.i18n.error );
			$btn.prop( 'disabled', false );
		}

		function step() {
			$text.text( tisa.i18n.prepApplying + ' (' + fmt( processed ) + ')' );

			$.post( tisa.ajax, {
				action: 'tisacase_desc_prep_apply',
				nonce: tisa.nonce,
				page: page,
				offset: offset,
				snapshot: snapshot,
				prep_meta_key: formKey,
				prep_value: formVal,
				prep_only_empty: onlyEmpty
			} ).done( function ( res ) {
				if ( ! isOk( res ) ) {
					fail( isAuthFailure( res ) ? tisa.i18n.sessionExpired : serverMsg( res ) );
					return;
				}
				var d = res.data;
				processed += d.processed || 0;
				changed += d.changed || 0;
				snapshot = d.snapshot || snapshot;

				if ( d.done ) {
					clearCheckpoint( checkpointKey );
					$fill.css( 'width', '100%' );
					$text.text( tisa.i18n.prepDone.replace( '{n}', fmt( changed ) ) );
					$btn.prop( 'disabled', false );
					return;
				}
				page = ( typeof d.next_page === 'number' ) ? d.next_page : d.next;
				offset = Number( d.next_offset ) || 0;
				writeCheckpoint( checkpointKey, { page: page, offset: offset, snapshot: snapshot, processed: processed, changed: changed, formKey: formKey, formVal: formVal, onlyEmpty: onlyEmpty } );
				step();
			} ).fail( function ( xhr ) {
				fail( ajaxFailText( xhr ) );
			} );
		}

		writeCheckpoint( checkpointKey, { page: page, offset: offset, snapshot: snapshot, processed: processed, changed: changed, formKey: formKey, formVal: formVal, onlyEmpty: onlyEmpty } );
		step();
	}

	/* ------------------------------------------------------------ */
	/* Events (delegated, so they survive re-renders)                */
	/* ------------------------------------------------------------ */

	$( function () {
		if ( readCheckpoint( 'tisacase-desc-selective-apply' ) ) {
			$( '#tc-apply' ).prop( 'disabled', false );
			$( '#tc-apply-hint' ).text( 'اصلاح انتخابی ناتمام است؛ برای ادامه روی دکمه کلیک کنید.' );
		}
		$( document )
			.on( 'click', '#tc-run', runRepair )
			.on( 'click', '#tc-preview-run', runPreview )
			.on( 'click', '#tc-scan', runScan )
			.on( 'click', '#tc-apply', runApply )
			.on( 'click', '.tc-restore-btn', function () { runRestore( $( this ) ); } )
			.on( 'click', '.tc-backup-delete', function () { deleteBackup( $( this ) ); } )
			.on( 'click', '#tc-meta-detect', runMetaDetect )
			.on( 'click', '#tc-prep-apply', runPrepApply )
			.on( 'click', '#tc-meta-detect-result .tc-detect-item', function () {
				$( '#tc-prep-key' ).val( $( this ).data( 'key' ) );
			} )
			.on( 'click', '#tc-scan-filters .tc-chip', function () {
				scanApplyFilter( $( this ).data( 'filter' ) );
			} )
			.on( 'change', '#tc-scan-selectall', function () {
				var checked = $( this ).is( ':checked' );
				$( '#tc-scan-list .tc-scan-row' ).each( function () {
					if ( $( this ).hasClass( 'is-hidden' ) ) {
						return;
					}
					$( this ).find( '.tc-scan-select' ).prop( 'checked', checked );
				} );
				scanUpdateApply();
			} )
			.on( 'change', '#tc-scan-list .tc-scan-select', function () {
				scanUpdateSelectAll();
			} );

		$( '#tc-preview-id' ).on( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				runPreview();
			}
		} );
	} );
} )( jQuery );

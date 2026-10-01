/**
 * TisaCase Hub — رفتار صفحهٔ لانچر و تنظیمات.
 *
 * بدون jQuery، بدون وابستگی: جستجو، ناوبری کیبورد، سنجاق، تأیید درون‌خطی،
 * پیش‌نمایش زندهٔ رنگ. هیچ‌کدام برای کار کردن لازم نیستند — صفحه کامل در HTML
 * رندر می‌شود و اگر JS خاموش باشد هم «باز کردن»، «فعال‌سازی» و «مخفی‌کردن»
 * کار می‌کنند (تأیید درون‌خطی در آن حالت ساده می‌شود: لینک مستقیم اجرا می‌کند).
 */
( function () {
	'use strict';

	var cfg = window.TisaCaseHub || {};
	var body = document.body;

	function $( sel, root ) { return ( root || document ).querySelector( sel ); }
	function $$( sel, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) ); }

	function post( action, data ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( k ) { fd.append( k, data[ k ] ); } );
		return fetch( cfg.ajax, {
			method: 'POST',
			credentials: 'same-origin',
			body: fd
		} ).then( function ( r ) { return r.json(); } );
	}

	/* ارقام: هر عددی که JS می‌نویسد باید همان قلم رابط و همان ارقامِ فارسیِ
	   سمتِ سر باشد (قرارداد §۸ — در v1.1 شمارندهٔ JS لاتین می‌شد). */
	var FA = [ '\u06F0', '\u06F1', '\u06F2', '\u06F3', '\u06F4', '\u06F5', '\u06F6', '\u06F7', '\u06F8', '\u06F9' ];

	function faDigits( s ) {
		if ( ! cfg.fa ) { return String( s ); }
		return String( s ).replace( /[0-9]/g, function ( d ) { return FA[ +d ]; } );
	}

	/* ---------------------------------------------------------------- 1) سنجاق‌ها */

	var pinnedGrid = $( '[data-tsh-grid="pinned"]' );
	var pinnedBox = $( '#tsh-pinned' );
	var pinnedN = $( '[data-pinned-n]' );

	function groupGridOf( card ) {
		var g = card.getAttribute( 'data-group' );
		var host = $( '[data-group="' + g + '"] [data-tsh-grid]' );
		if ( ! host ) {
			host = $( '[data-tsh-grid]:not([data-tsh-grid="pinned"])' );
		}
		return host;
	}

	function syncPinned() {
		if ( ! pinnedBox ) { return; }
		var n = pinnedGrid ? pinnedGrid.children.length : 0;
		pinnedBox.hidden = n === 0;
		if ( pinnedN ) { pinnedN.textContent = n ? faDigits( n ) : ''; }
	}

	function movePinned( card, on ) {
		if ( ! card ) { return; }
		card.classList.toggle( 'is-pinned', on );
		var btn = $( '.tisa-pin', card );
		if ( btn ) { btn.setAttribute( 'aria-pressed', on ? 'true' : 'false' ); }
		if ( on && pinnedGrid ) {
			pinnedGrid.appendChild( card );
		} else if ( ! on ) {
			var host = groupGridOf( card );
			if ( host ) { host.appendChild( card ); }
		}
		syncPinned();
	}

	// ردیف‌های سنجاق‌شده در HTML داخل گروه خودشان‌اند؛ اینجا بالا برده می‌شوند.
	$$( '.tisa-plugin-card.is-pinned' ).forEach( function ( c ) { movePinned( c, true ); } );

	$$( '.tisa-pin' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var card = btn.closest( '.tisa-plugin-card' );
			var on = ! card.classList.contains( 'is-pinned' );
			$$( '.tisa-plugin-card[data-key="' + card.getAttribute( 'data-key' ) + '"]' ).forEach( function ( c ) { movePinned( c, on ); } );
			if ( cfg.ajax ) { post( 'tsh_pin', { key: card.getAttribute( 'data-key' ), on: on ? 1 : 0 } ).catch( function () {} ); }
		} );
	} );

	syncPinned();

	/* ---------------------------------------------------------------- 2) جستجو و کیبورد */

	var input = $( '#tsh-q' );
	var counter = $( '#tsh-count' );
	var empty = $( '#tsh-empty' );
	var cards = $$( '.tisa-plugin-card' );
	var at = -1;

	function vis() {
		return cards.filter( function ( c ) { return ! c.hidden; } );
	}

	function highlight( el, q ) {
		if ( ! el ) { return; }
		var text = el.getAttribute( 'data-text' );
		if ( null === text ) {
			text = el.textContent;
			el.setAttribute( 'data-text', text );
		}
		if ( ! q ) {
			el.innerHTML = '';
			el.textContent = text;
			return;
		}
		var i = text.toLowerCase().indexOf( q );
		if ( i < 0 ) {
			el.textContent = text;
			return;
		}
		var esc = function ( s ) {
			return s.replace( /[&<>"]/g, function ( c ) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
			} );
		};
		el.innerHTML = esc( text.slice( 0, i ) ) + '<mark>' + esc( text.slice( i, i + q.length ) ) + '</mark>' + esc( text.slice( i + q.length ) );
	}

	function focusCard( i, scroll ) {
		var list = vis();
		cards.forEach( function ( c ) { c.classList.remove( 'is-focused'); } );
		if ( ! list.length || i < 0 ) { at = -1; return; }
		at = ( ( i % list.length ) + list.length ) % list.length;
		list[ at ].classList.add( 'is-focused' );
		if ( scroll ) {
			list[ at ].scrollIntoView( { block: 'nearest' } );
		}
	}

	function apply() {
		var q = ( input.value || '' ).trim().toLowerCase();
		var n = 0;
		cards.forEach( function ( c ) {
			var hit = ! q || ( c.getAttribute( 'data-search' ) || '' ).indexOf( q ) > -1;
			c.hidden = ! hit;
			if ( hit ) { n++; }
			highlight( $( '.tisa-plugin-card__title', c ), hit ? q : '' );
			clearConfirm( c );
		} );
		$$( '.tsh-group' ).forEach( function ( g ) {
			var any = $$( '.tisa-plugin-card', g ).some( function ( c ) { return ! c.hidden; } );
			g.hidden = ! any;
		} );
		if ( empty ) { empty.hidden = n !== 0; }
		if ( counter ) {
			var total = cards.length;
			counter.textContent = q
				? faDigits( n ) + ' / ' + faDigits( total )
				: faDigits( total ) + ' ' + ( cfg.i18n && cfg.i18n.tools ? cfg.i18n.tools : '' );
		}
		focusCard( q ? 0 : -1, false );
	}

	if ( input ) {
		input.addEventListener( 'input', apply );
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) { input.value = ''; apply(); return; }
			if ( 'ArrowDown' === e.key ) { e.preventDefault(); focusCard( ( at < 0 ? -1 : at ) + 1, true ); }
			if ( 'ArrowUp' === e.key ) { e.preventDefault(); focusCard( ( at < 0 ? 0 : at ) - 1, true ); }
			if ( 'Enter' === e.key ) {
				var list = vis();
				var card = list[ at ] || list[ 0 ];
				var link = card && $( '[data-open]', card );
				if ( link && link.href ) { e.preventDefault(); window.open( link.href, '_blank', 'noopener' ); }
			}
		} );
		var form = input.closest( 'form' );
		if ( form ) { form.addEventListener( 'submit', function ( e ) { e.preventDefault(); } ); }
		var clear = $( '#tsh-clear' );
		if ( clear ) { clear.addEventListener( 'click', function () { input.value = ''; apply(); input.focus(); } ); }
		document.addEventListener( 'keydown', function ( e ) {
			var t = e.target;
			var typing = t && ( 'INPUT' === t.tagName || 'TEXTAREA' === t.tagName || t.isContentEditable );
			if ( '/' === e.key && ! typing ) { e.preventDefault(); input.focus(); input.select(); }
		} );
		apply();
	}

	/* ---------------------------------------------------------------- 3) تأیید درون‌خطی (جای confirm بومی) */

	function clearConfirm( card ) {
		if ( card ) { card.classList.remove( 'is-confirming' ); }
	}

	$$( '[data-confirm]' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var card = btn.closest( '.tisa-plugin-card' );
			var open = card.classList.contains( 'is-confirming' );
			cards.forEach( clearConfirm );
			if ( ! open ) {
				card.classList.add( 'is-confirming' );
				var yes = $( '.tisa-confirmbar a', card );
				if ( yes ) { yes.focus(); }
			}
		} );
	} );

	document.addEventListener( 'click', function ( e ) {
		var no = e.target.closest && e.target.closest( '[data-confirm-no]' );
		if ( no ) { e.preventDefault(); clearConfirm( no.closest( '.tisa-plugin-card' ) ); return; }
		// کلیک روی خودِ ⏻ یا داخل نوار تأیید نباید تأیید را ببندد (وگرنه همان لحظه که باز می‌شد بسته می‌شد)
		if ( e.target.closest && ( e.target.closest( '.tisa-confirmbar' ) || e.target.closest( '[data-confirm]' ) ) ) {
			return;
		}
		cards.forEach( clearConfirm );
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) { cards.forEach( clearConfirm ); }
	} );

	/* ---------------------------------------------------------------- ۳ب) به‌روزرسانی از روی کارت */

	$$( '[data-upd] input[type="file"]' ).forEach( function ( inp ) {
		inp.addEventListener( 'change', function () {
			if ( ! inp.files || ! inp.files.length ) { return; }
			var form = inp.closest( 'form' );
			var card = inp.closest( '.tisa-plugin-card' );
			if ( ! /\.zip$/i.test( inp.files[ 0 ].name ) ) {
				window.alert( ( cfg.i18n && cfg.i18n.zipOnly ) || 'فقط فایل .zip' );
				inp.value = '';
				return;
			}
			if ( card ) {
				card.classList.add( 'is-updating' );
				var m = document.createElement( 'p' );
				m.className = 'tisa-hub-tile__updmsg';
				m.textContent = ( cfg.i18n && cfg.i18n.updating ) || 'در حال نصب…';
				card.appendChild( m );
			}
			form.submit();
		} );
	} );

	/* ---------------------------------------------------------------- ۴) سایهٔ نوار ابزار چسبان */

	var top = $( '.tisa-hub-card__top' );
	if ( top && 'IntersectionObserver' in window ) {
		var probe = document.createElement( 'div' );
		probe.setAttribute( 'aria-hidden', 'true' );
		probe.style.cssText = 'height:1px;margin-bottom:-1px';
		top.parentNode.insertBefore( probe, top );
		new IntersectionObserver( function ( rows ) {
			top.classList.toggle( 'is-stuck', ! rows[ 0 ].isIntersecting );
		} ).observe( probe );
	}

	/* ---------------------------------------------------------------- ۵) رنگ برند و چگالی (فقط صفحهٔ تنظیمات)

	   اینجا هیچ ذخیرهٔ بی‌صدایی انجام نمی‌شود: پیش‌نمایش زنده است و با
	   «ذخیره تنظیمات» نوشته می‌شود — تا تغییرِ اثرسراسری، بدون تأیید نماند. */

	function mix( hex, other, pct ) {
		hex = hex.replace( '#', '' );
		if ( 3 === hex.length ) { hex = hex[ 0 ] + hex[ 0 ] + hex[ 1 ] + hex[ 1 ] + hex[ 2 ] + hex[ 2 ]; }
		var a = [ parseInt( hex.slice( 0, 2 ), 16 ), parseInt( hex.slice( 2, 4 ), 16 ), parseInt( hex.slice( 4, 6 ), 16 ) ];
		other = other.replace( '#', '' );
		var b = [ parseInt( other.slice( 0, 2 ), 16 ), parseInt( other.slice( 2, 4 ), 16 ), parseInt( other.slice( 4, 6 ), 16 ) ];
		return '#' + a.map( function ( v, i ) {
			var x = Math.round( v * ( 1 - pct ) + b[ i ] * pct );
			return ( '0' + Math.max( 0, Math.min( 255, x ) ).toString( 16 ) ).slice( -2 );
		} ).join( '' );
	}

	function setAccent( hex ) {
		if ( ! /^#?[0-9a-fA-F]{6}$/.test( hex ) ) { return; }
		hex = ( '#' === hex.charAt( 0 ) ? hex : '#' + hex ).toLowerCase();
		var st = $( '#tsh-live' );
		if ( ! st ) {
			st = document.createElement( 'style' );
			st.id = 'tsh-live';
			document.head.appendChild( st );
		}
		var rgba = function ( a ) {
			var h = hex.replace( '#', '' );
			return 'rgba(' + parseInt( h.slice( 0, 2 ), 16 ) + ',' + parseInt( h.slice( 2, 4 ), 16 ) + ',' + parseInt( h.slice( 4, 6 ), 16 ) + ',' + a + ')';
		};
		st.textContent = ':root{--tisa-primary:' + hex + ';'
			+ '--tisa-primary-ink:' + mix( hex, '#000000', 0.2 ) + ';'
			+ '--tisa-primary-deep:' + mix( hex, '#000000', 0.42 ) + ';'
			+ '--tisa-primary-bright:' + mix( hex, '#ffffff', 0.16 ) + ';'
			+ '--tisa-primary-soft:' + mix( hex, '#ffffff', 0.9 ) + ';'
			+ '--tisa-primary-tint:' + mix( hex, '#ffffff', 0.955 ) + ';'
			+ '--tisa-border-strong:' + mix( hex, '#ffffff', 0.75 ) + ';'
			+ '--tisa-ring:0 0 0 3px ' + rgba( 0.22 ) + ';}';
		var sw = $$( '.tisa-accent__sw' );
		sw.forEach( function ( b ) {
			var on = ( b.getAttribute( 'data-hex' ) || '' ).toLowerCase() === hex;
			b.classList.toggle( 'is-on', on );
			b.setAttribute( 'aria-checked', on ? 'true' : 'false' );
		} );
		var field = $( '#tsh-accent' );
		if ( field ) { field.value = hex.replace( '#', '' ); }
		var pick = $( '#tsh-accent-pick' );
		if ( pick && /^#[0-9a-f]{6}$/.test( hex ) ) { pick.value = hex; }
	}

	$$( '.tisa-accent__sw' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () { setAccent( btn.getAttribute( 'data-hex' ) ); } );
	} );

	var pick = $( '#tsh-accent-pick' );
	if ( pick ) {
		pick.addEventListener( 'input', function () { setAccent( pick.value ); } );
	}

	var hexField = $( '#tsh-accent' );
	if ( hexField ) {
		hexField.addEventListener( 'input', function () {
			var v = hexField.value.trim().replace( '#', '' );
			if ( /^[0-9a-fA-F]{6}$/.test( v ) ) { setAccent( '#' + v ); }
		} );
	}

	var compact = $( '#tsh-compact' ) || $( 'input[name$="[compact]"]' );
	if ( compact ) {
		compact.addEventListener( 'change', function () {
			body.classList.toggle( 'tisa-compact', !! compact.checked );
		} );
	}

	/* ---------------------------------------------------------------- ۶) کپی قطعهٔ کد */

	var copy = $( '#tsh-copy' );
	if ( copy ) {
		copy.addEventListener( 'click', function () {
			var box = $( '.tisa-codebox code' );
			if ( ! box ) { return; }
			var text = box.textContent;
			var done = function () {
				var old = copy.textContent;
				copy.textContent = ( cfg.i18n && cfg.i18n.copied ) ? cfg.i18n.copied : 'کپی شد';
				window.setTimeout( function () { copy.textContent = old; }, 1600 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( done, function () {} );
			} else {
				var ta = document.createElement( 'textarea' );
				ta.value = text;
				document.body.appendChild( ta );
				ta.select();
				try { document.execCommand( 'copy' ); done(); } catch ( e ) {}
				document.body.removeChild( ta );
			}
		} );
	}

	/* پیام هاب: بستن با دکمه، و محو خودکار پیام‌های موفق بعد از ۵ ثانیه */
	$$( '.tsh-notice' ).forEach( function ( n ) {
		var hide = function () {
			n.classList.add( 'is-leaving' );
			window.setTimeout( function () { if ( n.parentNode ) { n.parentNode.removeChild( n ); } }, 260 );
		};
		var x = $( '.tsh-notice__x', n );
		if ( x ) { x.addEventListener( 'click', hide ); }
		if ( n.classList.contains( 'tsh-notice--success' ) ) { window.setTimeout( hide, 5000 ); }
		if ( window.history && history.replaceState && /[?&]tsh_msg=/.test( location.search ) ) {
			var u = new URL( location.href );
			u.searchParams.delete( 'tsh_msg' ); u.searchParams.delete( 'tsh_err' ); u.searchParams.delete( 'tsh_item' );
			history.replaceState( null, '', u.toString() );
		}
	} );

	/* اتصال مخزن + همگام‌سازی */
	( function () {
		var modal = $( '#tsh-repo-modal' );
		var input = $( '#tsh-repo-url' );
		var status = $( '#tsh-repo-status' );
		var connect = $( '#tsh-connect' );
		var sync = $( '#tsh-sync' );

		function setStatus( text, ok ) {
			if ( ! status ) { return; }
			status.hidden = ! text;
			status.textContent = text || '';
			status.className = 'tsh-modal__status' + ( ok === true ? ' is-ok' : ok === false ? ' is-bad' : '' );
		}

		function openModal() {
			if ( ! modal ) { return; }
			modal.hidden = false;
			if ( input ) {
				input.value = ( connect && connect.getAttribute( 'data-url' ) ) || input.value || '';
				window.setTimeout( function () { input.focus(); }, 30 );
			}
			setStatus( '', null );
		}

		function closeModal() {
			if ( modal ) { modal.hidden = true; }
		}

		if ( connect ) { connect.addEventListener( 'click', openModal ); }
		if ( modal ) {
			$$( '[data-close]', modal ).forEach( function ( el ) {
				el.addEventListener( 'click', closeModal );
			} );
		}

		function parseGh( raw ) {
			var s = String( raw || '' ).replace( /[\u200B-\u200F\u202A-\u202E\u2066-\u2069]/g, '' ).trim();
			var owner = '';
			var name = '';
			var branch = 'main';
			var m = s.match( /github\.com[/:]([^/\s?#]+)\/([^/\s?#]+)/i );
			if ( m ) {
				owner = m[1];
				name = m[2].replace( /\.git$/i, '' );
				var t = s.match( /\/(?:tree|blob|raw)\/([^?#]+)/i );
				if ( t && t[1] ) {
					branch = decodeURIComponent( String( t[1] ).replace( /%2F/ig, '/' ) ).replace( /\/+$/, '' ) || 'main';
				}
			} else {
				m = s.match( /^([A-Za-z0-9_.-]+)\/([A-Za-z0-9_.-]+)$/ );
				if ( m ) {
					owner = m[1];
					name = m[2];
				}
			}
			if ( ! owner || ! name ) {
				return null;
			}
			return { gh_owner: owner, gh_name: name, gh_branch: branch };
		}

		function run( action, extra ) {
			setStatus( '…', null );
			return post( action, extra || {} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					var msg = ( res && res.data && res.data.msg ) ? res.data.msg : ( cfg.i18n && cfg.i18n.error );
					setStatus( msg, false );
					return null;
				}
				return res.data || {};
			} ).catch( function () {
				setStatus( cfg.i18n && cfg.i18n.error, false );
				return null;
			} );
		}

		var testBtn = $( '#tsh-repo-test' );
		if ( testBtn ) {
			testBtn.addEventListener( 'click', function () {
				var p = parseGh( input ? input.value : '' );
				if ( ! p ) {
					setStatus( 'این لینک گیت‌هاب نیست. مثل https://github.com/owner/repo بچسبانید.', false );
					return;
				}
				run( 'tsh_repo_test', p ).then( function ( d ) {
					if ( ! d ) { return; }
					var extra = d.remote === false ? ' (فهرست همراه هاب؛ سرور به گیت‌هاب وصل نشد)' : '';
					setStatus( d.repo + ' @ ' + d.branch + ' — ' + faDigits( d.count ) + ' زیپ' + extra, true );
				} );
			} );
		}
		var saveBtn = $( '#tsh-repo-save' );
		if ( saveBtn ) {
			saveBtn.addEventListener( 'click', function () {
				var p = parseGh( input ? input.value : '' );
				if ( ! p ) {
					setStatus( 'این لینک گیت‌هاب نیست. مثل https://github.com/owner/repo بچسبانید.', false );
					return;
				}
				run( 'tsh_repo_connect', p ).then( function ( d ) {
					if ( ! d ) { return; }
					setStatus( 'متصل شد: ' + d.repo + ' @ ' + d.branch, true );
					if ( connect ) {
						connect.setAttribute( 'data-url', 'https://github.com/' + d.repo + ( d.branch && d.branch !== 'main' ? '/tree/' + d.branch : '' ) );
					}
				} );
			} );
		}
		if ( sync ) {
		sync.addEventListener( 'click', function () {
			sync.disabled = true;
			post( 'tsh_repo_sync', {} ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					window.alert( ( res && res.data && res.data.msg ) || ( cfg.i18n && cfg.i18n.error ) );
					sync.disabled = false;
					return;
				}
				var d = ( res && res.data ) || {};
				var i18n = cfg.i18n || {};
				var msg = '';
				if ( d.offline && i18n.syncedOkOffline ) {
					msg = i18n.syncedOkOffline.replace( '%s', d.count || 0 );
				} else if ( i18n.syncedOk ) {
					msg = i18n.syncedOk.replace( '%s', d.count || 0 );
				}
				if ( i18n.syncedBranch && d.branch ) {
					msg += ( msg ? ' ' : '' ) + i18n.syncedBranch.replace( '%s', d.branch );
				}
				if ( i18n.syncReloading ) {
					msg += ( msg ? ' ' : '' ) + i18n.syncReloading;
				}
				if ( msg ) {
					window.alert( msg );
				}
				window.location.reload();
			} ).catch( function () {
				window.alert( cfg.i18n && cfg.i18n.error );
				sync.disabled = false;
			} );
		} );
		}
	}() );
}() );

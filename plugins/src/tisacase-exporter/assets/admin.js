/*!
 * خروجی گرفتن (TisaCase Exporter) — اسکریپت صفحهٔ مدیریت
 * بدون هیچ وابستگی بیرونی (بدون jQuery) و بدون مرحلهٔ Build.
 *
 * @package TisaCase_Exporter
 */
( function () {
	'use strict';

	var cfg = window.TisaExp;

	if ( ! cfg ) {
		return;
	}

	var state = {
		runId: '',
		running: false,
		done: false,
		startedAt: 0,
		payload: null,
		stopped: true,
		history: Array.isArray( cfg.history ) ? cfg.history : [],
		entries: {}
	};

	var $  = function ( selector ) { return document.querySelector( selector ); };
	var $$ = function ( selector ) { return Array.prototype.slice.call( document.querySelectorAll( selector ) ); };

	/* -----------------------------------------------------------------
	 * قالب‌بندی اعداد و متن‌های فارسی
	 * ----------------------------------------------------------------- */

	var numberFormat = null;

	try {
		numberFormat = new Intl.NumberFormat( 'fa-IR' );
	} catch ( e ) {
		numberFormat = null;
	}

	function fmt( value ) {
		var number = Number( value ) || 0;
		return numberFormat ? numberFormat.format( number ) : String( number );
	}

	function esc( value ) {
		var div = document.createElement( 'div' );
		div.textContent = ( value === null || typeof value === 'undefined' ) ? '' : String( value );
		return div.innerHTML;
	}

	function sprintf( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );

		return String( template ).replace( /%(%|\d)/g, function ( match, token ) {
			if ( token === '%' ) {
				return '%';
			}

			var index = parseInt( token, 10 ) - 1;

			return typeof args[ index ] === 'undefined' ? match : String( args[ index ] );
		} );
	}

	function duration( seconds ) {
		seconds = Math.max( 0, Math.round( seconds ) );

		if ( seconds < 60 ) {
			return fmt( seconds ) + ' ' + 'ثانیه';
		}

		var minutes = Math.floor( seconds / 60 );
		var rest    = seconds % 60;

		if ( minutes < 60 ) {
			return fmt( minutes ) + ' دقیقه و ' + fmt( rest ) + ' ثانیه';
		}

		return fmt( Math.floor( minutes / 60 ) ) + ' ساعت و ' + fmt( minutes % 60 ) + ' دقیقه';
	}

	function toast( message, kind ) {
		var box = $( '#tisa-exp-toast' );

		if ( ! box ) {
			return;
		}

		box.textContent = message;
		box.className = 'tisa-exp__toast is-visible' + ( kind ? ' is-' + kind : '' );
		box.hidden = false;

		window.clearTimeout( toast.timer );
		toast.timer = window.setTimeout( function () {
			box.className = 'tisa-exp__toast';
			box.hidden = true;
		}, 4200 );
	}

	/* -----------------------------------------------------------------
	 * خواندن فرم
	 * ----------------------------------------------------------------- */

	function collectFilters() {
		var filters = {};

		$$( '[data-filter]' ).forEach( function ( wrap ) {
			var name = wrap.getAttribute( 'data-filter' );
			var type = wrap.getAttribute( 'data-type' );

			if ( ! name ) {
				return;
			}

			if ( type === 'multiselect' ) {
				filters[ name ] = $$( '[data-filter="' + name + '"] input[type="checkbox"]:checked' ).map( function ( input ) {
					return input.value;
				} );
				return;
			}

			var input = wrap.querySelector( 'input, select' );

			if ( ! input ) {
				return;
			}

			if ( type === 'switch' ) {
				filters[ name ] = input.checked ? '1' : '';
				return;
			}

			filters[ name ] = input.value;
		} );

		return filters;
	}

	function collectColumns() {
		return $$( 'input[name="columns[]"]:checked' ).map( function ( input ) {
			return input.value;
		} );
	}

	function collectFormat() {
		var checked = $( 'input[name="format"]:checked' );
		return checked ? checked.value : '';
	}

	function collectDedup() {
		var select = $( 'select[name="dedup"]' );
		return select ? select.value : '';
	}

	function validate() {
		if ( collectColumns().length === 0 ) {
			toast( cfg.l10n.needColumns, 'warn' );
			return false;
		}

		var from = $( '[data-filter="date_from"] input' );
		var to   = $( '[data-filter="date_to"] input' );

		if ( from && to && from.value && to.value && from.value > to.value ) {
			toast( cfg.l10n.invalidDates, 'warn' );
			return false;
		}

		return true;
	}

	/* -----------------------------------------------------------------
	 * درخواست‌های AJAX
	 * ----------------------------------------------------------------- */

	function post( action, data ) {
		var body = new FormData();

		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		body.append( 'module', cfg.section );

		Object.keys( data || {} ).forEach( function ( key ) {
			var value = data[ key ];

			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					body.append( key + '[]', item );
				} );
				return;
			}

			body.append( key, value );
		} );

		return window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json().catch( function () {
				throw new Error( cfg.l10n.ajaxError );
			} );
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				throw new Error( ( json && json.data && json.data.message ) ? json.data.message : cfg.l10n.ajaxError );
			}

			return json.data || {};
		} );
	}

	function formPayload() {
		var filters = collectFilters();
		var columns = collectColumns();
		var data    = {
			columns: columns,
			format: collectFormat(),
			dedup: collectDedup()
		};

		Object.keys( filters ).forEach( function ( key ) {
			data[ 'filters[' + key + ']' ] = filters[ key ];
		} );

		return data;
	}

	/* -----------------------------------------------------------------
	 * رندر وضعیت، KPI و فایل‌ها
	 * ----------------------------------------------------------------- */

	function kpiLabel( key, fallback ) {
		return ( cfg.kpi && cfg.kpi[ key ] ) ? cfg.kpi[ key ] : fallback;
	}

	function renderKpis( payload ) {
		var box = $( '#tisa-exp-kpis' );

		if ( ! box ) {
			return;
		}

		var total     = Number( payload.total ) || 0;
		var processed = Number( payload.processed ) || 0;
		var percent   = total > 0 ? Math.min( 100, Math.round( ( processed / total ) * 100 ) ) : ( payload.done ? 100 : 0 );
		var shuffled  = !! payload.dedup;

		var boxes = [
			{
				label: kpiLabel( 'processed', 'ردیف بررسی‌شده' ),
				value: fmt( processed ),
				note: total > 0 ? sprintf( '%1 %2 %3 · %4%%', cfg.l10n.of, fmt( total ), cfg.unit || cfg.l10n.rowsPer, fmt( percent ) ) : ''
			},
			{ label: kpiLabel( 'exported', 'ردیف خروجی' ), value: fmt( payload.exported ), note: '', ok: true },
			{ label: kpiLabel( 'skipped', 'کنارگذاشته‌شده' ), value: fmt( payload.skipped ), note: '' },
			{
				label: kpiLabel( 'duplicates', 'تکراری حذف‌شده' ),
				value: shuffled ? fmt( payload.duplicates ) : '—',
				note: shuffled ? '' : 'خاموش'
			}
		];

		box.innerHTML = boxes.map( function ( item ) {
			return '<div class="tisa-exp__kpi' + ( item.ok ? ' is-ok' : '' ) + '">' +
				'<span>' + esc( item.label ) + '</span>' +
				'<b>' + esc( item.value ) + '</b>' +
				'<small>' + esc( item.note ) + '</small>' +
				'</div>';
		} ).join( '' );
	}

	function renderProgress( payload, message, kind ) {
		var box = $( '#tisa-exp-progress-box' );

		if ( box ) {
			box.hidden = false;
		}

		var total     = Number( payload.total ) || 0;
		var processed = Number( payload.processed ) || 0;
		var percent   = total > 0 ? Math.min( 100, Math.round( ( processed / total ) * 100 ) ) : ( payload.done ? 100 : 0 );

		var bar = $( '#tisa-exp-bar' );

		if ( bar ) {
			bar.style.width = percent + '%';
		}

		var progress = $( '#tisa-exp-progress' );

		if ( progress ) {
			progress.setAttribute( 'aria-valuenow', String( percent ) );
			progress.setAttribute( 'aria-valuetext', fmt( percent ) + '%' );
		}

		var text = message;

		if ( ! text ) {
			var elapsed = state.startedAt ? ( Date.now() - state.startedAt ) / 1000 : 0;

			if ( payload.done ) {
				text = state.files().length > 0
					? sprintf( cfg.l10n.doneFiles, fmt( state.files().length ) )
					: cfg.l10n.doneEmpty;
			} else if ( processed > 0 ) {
				text = cfg.l10n.running;
			} else {
				text = cfg.l10n.preparing;
			}

			var bits = [];

			if ( elapsed > 1 ) {
				bits.push( sprintf( cfg.l10n.timeSpent, duration( elapsed ) ) );

				if ( ! payload.done && processed > 0 && total > processed ) {
					bits.push( sprintf( cfg.l10n.eta, duration( ( elapsed / processed ) * ( total - processed ) ) ) );
				}
			}

			if ( payload.storage ) {
				bits.push( sprintf( cfg.l10n.storage, payload.storage ) );
			}

			if ( bits.length ) {
				text += ' · ' + bits.join( ' · ' );
			}
		}

		var node = $( '#tisa-exp-state' );

		if ( node ) {
			node.innerHTML = '<strong class="' + ( kind || ( payload.done ? 'is-ok' : '' ) ) + '">' + esc( text ) + '</strong>';
		}
	}

	function fileCountLabel( count ) {
		return sprintf( cfg.l10n.rowCount, fmt( count ) );
	}

	function renderFiles( payload ) {
		var box = $( '#tisa-exp-files' );

		if ( ! box ) {
			return;
		}

		var files = Array.isArray( payload.files ) ? payload.files : [];

		if ( files.length === 0 ) {
			box.innerHTML = '';
			return;
		}

		var html = '<h2 class="tisa-exp__files-h">' + esc( cfg.l10n.filesReady ) + '</h2><div class="tisa-exp__files-list">';

		files.forEach( function ( file, index ) {
			html += '<div class="tisa-exp__file">' +
				'<span class="tisa-exp__file-n">' + esc( fmt( index + 1 ) ) + '</span>' +
				'<span class="tisa-exp__file-c" dir="ltr">' + esc( file.name ) + ' — ' + esc( fileCountLabel( file.count ) ) + '</span>' +
				'<a class="tisa-btn tisa-btn--secondary tisa-btn--sm" href="' + esc( file.url ) + '">' + esc( cfg.l10n.download ) + '</a>' +
				'</div>';
		} );

		html += '</div>';

		if ( payload.zip_url ) {
			html += '<div class="tisa-exp__zip"><a class="tisa-btn tisa-btn--primary tisa-btn--sm" href="' + esc( payload.zip_url ) + '">' +
				esc( cfg.l10n.downloadAll ) + '</a></div>';
		}

		box.innerHTML = html;
	}

	function renderMeta( payload ) {
		var node = $( '#tisa-exp-meta' );

		if ( ! node ) {
			return;
		}

		if ( ! payload || ! payload.run_id ) {
			node.textContent = cfg.l10n.deepInfo;
			return;
		}

		var format = payload.format ? String( payload.format ).toUpperCase() : '';
		node.textContent = format + ( cfg.unit ? ' · ' + cfg.unit : '' );
	}

	function applyPayload( payload ) {
		state.payload = payload;
		state.runId   = payload.run_id || '';

		renderKpis( payload );
		renderProgress( payload );
		renderFiles( payload );
		renderMeta( payload );

		var box = $( '#tisa-exp-progress-box' );

		if ( box ) {
			box.hidden = false;
		}
	}

	/* -----------------------------------------------------------------
	 * اجرا
	 * ----------------------------------------------------------------- */

	function setRunning( running ) {
		state.running = running;

		var card = $( '#tisa-exp-card-run' );

		if ( card ) {
			card.setAttribute( 'aria-busy', running ? 'true' : 'false' );
			card.classList.toggle( 'is-running', running );
		}

		[ '#tisa-exp-start', '#tisa-exp-preview', '#tisa-exp-continue' ].forEach( function ( selector ) {
			var button = $( selector );

			if ( button ) {
				button.disabled = running;
			}
		} );

		if ( running ) {
			window.addEventListener( 'beforeunload', unloadGuard );
		} else {
			window.removeEventListener( 'beforeunload', unloadGuard );
		}
	}

	function unloadGuard( event ) {
		if ( ! state.running ) {
			return;
		}

		event.preventDefault();
		event.returnValue = cfg.l10n.unloadMsg;
		return cfg.l10n.unloadMsg;
	}

	state.files = function () {
		return ( state.payload && Array.isArray( state.payload.files ) ) ? state.payload.files : [];
	};

	function start() {
		if ( ! validate() ) {
			return;
		}

		setRunning( true );
		state.stopped = false;
		state.done    = false;
		state.startedAt = Date.now();
		state.payload = {
			run_id: '',
			processed: 0,
			total: 0,
			exported: 0,
			skipped: 0,
			duplicates: 0,
			files: [],
			done: false
		};

		showProgressBox();
		renderKpis( state.payload );
		renderProgress( state.payload, cfg.l10n.preparing, 'is-warn' );
		renderFiles( state.payload );

		var payload = formPayload();

		post( cfg.actions.start, payload ).then( function ( data ) {
			state.runId   = data.run_id || '';
			state.payload = data;
			applyPayload( data );
			loop();
		} ).catch( function ( error ) {
			setRunning( false );
			state.stopped = true;
			renderProgress( state.payload || {}, cfg.l10n.startError + ' ' + error.message, 'is-fail' );
			toast( cfg.l10n.startError + ' ' + error.message, 'fail' );
		} );
	}

	function loop() {
		if ( state.stopped || ! state.runId ) {
			return;
		}

		post( cfg.actions.process, { run_id: state.runId } ).then( function ( data ) {
			if ( data.cancelled ) {
				state.stopped = true;
				setRunning( false );
				state.history = Array.isArray( data.history ) ? data.history : [];
				renderHistory();
				renderProgress( state.payload || {}, cfg.l10n.cancelled, 'is-warn' );
				toast( cfg.l10n.cancelled, 'warn' );
				return;
			}

			state.payload = data;
			applyPayload( data );

			if ( data.done ) {
				state.done    = true;
				state.stopped = true;
				setRunning( false );
				state.history = Array.isArray( data.history ) ? data.history : state.history;
				renderHistory();
				toast( state.files().length
					? sprintf( cfg.l10n.doneFiles, fmt( state.files().length ) )
					: cfg.l10n.doneEmpty, state.files().length ? 'ok' : 'warn' );
				return;
			}

			window.setTimeout( loop, 60 );
		} ).catch( function ( error ) {
			state.stopped = true;
			setRunning( false );
			renderProgress( state.payload || {}, cfg.l10n.processError + ' ' + error.message, 'is-fail' );
			toast( cfg.l10n.processError + ' ' + error.message, 'fail' );

			// جلسه روی سرور باقی می‌ماند؛ «ادامه خروجی» فعال می‌شود.
			var resume = $( '#tisa-exp-continue' );

			if ( resume && state.runId ) {
				resume.hidden = false;
			}
		} );
	}

	function resume() {
		if ( ! state.runId ) {
			return;
		}

		setRunning( true );
		state.stopped = false;
		state.startedAt = state.startedAt || Date.now();
		showProgressBox();
		loop();
	}

	function cancel() {
		if ( ! window.confirm( cfg.l10n.cancelConfirm ) ) {
			return;
		}

		state.stopped = true;
		setRunning( false );

		post( cfg.actions.cancel, { run_id: state.runId } ).then( function ( data ) {
			state.history = Array.isArray( data.history ) ? data.history : [];
			renderHistory();

			state.payload = {
				run_id: '',
				processed: 0,
				total: 0,
				exported: 0,
				skipped: 0,
				duplicates: 0,
				files: [],
				done: false
			};

			renderKpis( state.payload );
			renderFiles( state.payload );
			renderProgress( state.payload, cfg.l10n.cancelled, 'is-warn' );
			renderMeta( state.payload );

			var resumeButton = $( '#tisa-exp-continue' );

			if ( resumeButton ) {
				resumeButton.hidden = true;
			}

			state.runId = '';
			toast( cfg.l10n.cancelled, 'warn' );
		} ).catch( function ( error ) {
			toast( error.message, 'fail' );
		} );
	}

	function showProgressBox() {
		var box = $( '#tisa-exp-progress-box' );

		if ( box ) {
			box.hidden = false;
		}
	}

	/* -----------------------------------------------------------------
	 * پیش‌نمایش
	 * ----------------------------------------------------------------- */

	function preview() {
		if ( ! validate() ) {
			return;
		}

		var button = $( '#tisa-exp-preview' );
		var card   = $( '#tisa-exp-card-preview' );

		if ( button ) {
			button.disabled = true;
			button.classList.add( 'is-busy' );
		}

		post( cfg.actions.preview, formPayload() ).then( function ( data ) {
			renderPreview( data );

			if ( card ) {
				card.hidden = false;
				card.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		} ).catch( function ( error ) {
			toast( cfg.l10n.previewError + ' ' + error.message, 'fail' );
		} ).then( function () {
			if ( button ) {
				button.disabled = false;
				button.classList.remove( 'is-busy' );
			}
		} );
	}

	function renderPreview( data ) {
		var head = $( '#tisa-exp-preview-head' );
		var body = $( '#tisa-exp-preview-body' );
		var note = $( '#tisa-exp-preview-note' );

		if ( ! head || ! body ) {
			return;
		}

		var columns = Array.isArray( data.columns ) ? data.columns : [];
		var rows    = Array.isArray( data.rows ) ? data.rows : [];

		if ( note ) {
			note.textContent = rows.length
				? sprintf( cfg.l10n.previewNote, fmt( rows.length ), fmt( data.total ) )
				: cfg.l10n.previewEmpty;
		}

		head.innerHTML = '<tr>' + columns.map( function ( label ) {
			return '<th scope="col">' + esc( label ) + '</th>';
		} ).join( '' ) + '</tr>';

		if ( rows.length === 0 ) {
			body.innerHTML = '<tr><td colspan="' + Math.max( 1, columns.length ) + '" class="tisa-exp__preview-empty">' +
				esc( cfg.l10n.previewEmpty ) + '</td></tr>';
			return;
		}

		body.innerHTML = rows.map( function ( row ) {
			return '<tr>' + row.map( function ( cell ) {
				var value = ( cell === null || typeof cell === 'undefined' ) ? '' : String( cell );
				return '<td' + ( /^[\d.\-]+$/.test( value ) ? ' dir="ltr"' : '' ) + '>' + esc( value ) + '</td>';
			} ).join( '' ) + '</tr>';
		} ).join( '' );
	}

	/* -----------------------------------------------------------------
	 * تاریخچه
	 * ----------------------------------------------------------------- */

	function renderHistory() {
		var box = $( '#tisa-exp-history' );

		if ( ! box ) {
			return;
		}

		var list = Array.isArray( state.history ) ? state.history : [];

		state.entries = {};

		if ( list.length === 0 ) {
			box.innerHTML = '<div class="tisa-empty">' + esc( cfg.l10n.historyEmpty ) + '</div>';
			return;
		}

		box.innerHTML = '<div class="tisa-exp__hist">' + list.map( function ( entry ) {
			state.entries[ entry.run_id ] = entry;

			var chips = [
				'<span class="tisa-badge tisa-badge--outline" dir="ltr">' + esc( String( entry.format || '' ).toUpperCase() ) + '</span>',
				'<span class="tisa-badge tisa-badge--info">' + esc( sprintf( cfg.l10n.rowCount, fmt( entry.rows ) ) ) + '</span>'
			];

			if ( entry.dedup ) {
				chips.push( '<span class="tisa-badge tisa-badge--success">' + esc( 'بدون تکراری' ) + '</span>' );
			}

			var summary = Array.isArray( entry.summary ) && entry.summary.length
				? '<div class="tisa-exp__hist-meta">' + esc( cfg.l10n.filteredBy + ': ' + entry.summary.join( ' · ' ) ) + '</div>'
				: '';

			var actions = '';

			if ( entry.files_exist && entry.files.length ) {
				actions += entry.files.map( function ( file ) {
					return '<a class="tisa-btn tisa-btn--secondary tisa-btn--sm" href="' + esc( file.url ) + '" dir="ltr">' + esc( file.name ) + '</a>';
				} ).join( '' );

				/* فایل PDF را می‌توان بی‌واسطه در مرورگر باز و چاپ کرد. */
				if ( String( entry.extension || '' ).toLowerCase() === 'pdf' ) {
					actions += entry.files.map( function ( file ) {
						return '<a class="tisa-btn tisa-btn--ghost tisa-btn--sm" href="' + esc( file.url ) + '" target="_blank" rel="noopener">' + esc( cfg.l10n.print || 'چاپ' ) + '</a>';
					} ).join( '' );
				}

				if ( entry.zip_url ) {
					actions += '<a class="tisa-btn tisa-btn--primary tisa-btn--sm" href="' + esc( entry.zip_url ) + '">' + esc( cfg.l10n.downloadAll ) + '</a>';
				}
			} else {
				actions += '<span class="tisa-exp__gone">' + esc( cfg.l10n.filesGone ) + '</span>';
			}

			actions += '<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" data-reuse="' + esc( entry.run_id ) + '">' + esc( cfg.l10n.reuse ) + '</button>';

			return '<div class="tisa-exp__hist-row">' +
				'<div class="tisa-exp__hist-main">' +
				'<div class="tisa-exp__hist-title"><b>' + esc( entry.module_title || entry.module ) + '</b>' +
				'<span class="tisa-exp__hist-date" dir="ltr">' + esc( entry.created_fa ) + '</span>' + chips.join( '' ) + '</div>' +
				summary +
				'</div>' +
				'<div class="tisa-exp__hist-actions">' + actions + '</div>' +
				'</div>';
		} ).join( '</div>' );
	}

	function reuse( runId ) {
		var entry = state.entries[ runId ];

		if ( ! entry ) {
			return;
		}

		if ( entry.format ) {
			var radio = $( 'input[name="format"][value="' + entry.format + '"]' );

			if ( radio ) {
				radio.checked = true;
				radio.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
		}

		var dedup = $( 'select[name="dedup"]' );

		if ( dedup ) {
			dedup.value = entry.dedup || '';
		}

		var wanted = Array.isArray( entry.columns ) ? entry.columns.map( function ( col ) {
			return col && col.key ? col.key : '';
		} ) : [];

		$$( 'input[name="columns[]"]' ).forEach( function ( input ) {
			input.checked = wanted.indexOf( input.value ) !== -1;
		} );

		var filters = entry.filters || {};

		Object.keys( filters ).forEach( function ( name ) {
			var wrap = $( '[data-filter="' + name + '"]' );

			if ( ! wrap ) {
				return;
			}

			var value = filters[ name ];
			var type  = wrap.getAttribute( 'data-type' );

			if ( type === 'multiselect' ) {
				var picked = Array.isArray( value ) ? value.map( String ) : [];
				$$( '[data-filter="' + name + '"] input[type="checkbox"]' ).forEach( function ( input ) {
					input.checked = picked.indexOf( input.value ) !== -1;
				} );
				return;
			}

			var input = wrap.querySelector( 'input, select' );

			if ( ! input ) {
				return;
			}

			if ( type === 'switch' ) {
				input.checked = !! value;
				return;
			}

			input.value = ( value === null || typeof value === 'undefined' ) ? '' : String( value );
		} );

		updateOutputHint();

		var card = $( '#tisa-exp-card-filters' );

		if ( card ) {
			card.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}

		toast( 'تنظیمات این خروجی در فرم بارگذاری شد؛ دکمهٔ «شروع خروجی جدید» را بزنید.', 'ok' );
	}

	function clearHistory() {
		if ( ! window.confirm( cfg.l10n.clearConfirm ) ) {
			return;
		}

		post( cfg.actions.history, {} ).then( function ( data ) {
			state.history = Array.isArray( data.history ) ? data.history : [];
			renderHistory();
			toast( 'تاریخچه پاک شد.', 'ok' );
		} ).catch( function ( error ) {
			toast( error.message, 'fail' );
		} );
	}

	/* -----------------------------------------------------------------
	 * کمکی‌های فرم
	 * ----------------------------------------------------------------- */

	function updateOutputHint() {
		var node = $( '#tisa-exp-output-hint' );

		if ( ! node ) {
			return;
		}

		var format  = collectFormat();
		var columns = collectColumns().length;
		var parts   = [ sprintf( cfg.l10n.outputHint, fmt( cfg.fileSize ), fmt( columns ) ) ];

		if ( format === 'txt' && columns > 1 ) {
			parts.push( 'در قالب متن ساده، ستون‌ها با تب (Tab) از هم جدا می‌شوند.' );
		}

		if ( format === 'json' ) {
			parts.push( 'هر ردیف یک شیء با کلیدهای لاتین ستون‌هاست.' );
		}

		if ( format === 'pdf' ) {
			parts.push( 'PDF با قلم فارسی جاسازی‌شده ساخته می‌شود؛ از تاریخچه دکمهٔ «چاپ» دارد.' );
		}

		node.textContent = parts.join( ' · ' );
	}

	/* «بدون محدودیت تاریخ» فیلدهای بازه را خاموش می‌کند تا معلوم باشد تاریخی اعمال نمی‌شود. */
	function syncDateMode() {
		var select = $( '[data-filter="date_mode"] select' );

		if ( ! select ) {
			return;
		}

		var all = select.value === 'all' || select.value === '';

		[ 'date_from', 'date_to' ].forEach( function ( name ) {
			var wrap  = $( '[data-filter="' + name + '"]' );
			var input = wrap ? wrap.querySelector( 'input' ) : null;

			if ( ! input ) {
				return;
			}

			input.disabled = all;

			if ( all ) {
				input.value = '';
			}

			if ( wrap ) {
				wrap.classList.toggle( 'is-muted', all );
			}
		} );
	}

	function resetFilters() {
		$$( '[data-filter]' ).forEach( function ( wrap ) {
			var type = wrap.getAttribute( 'data-type' );

			if ( type === 'multiselect' ) {
				$$( '[data-filter="' + wrap.getAttribute( 'data-filter' ) + '"] input[type="checkbox"]' ).forEach( function ( input ) {
					input.checked = false;
				} );
				return;
			}

			var input = wrap.querySelector( 'input, select' );

			if ( ! input ) {
				return;
			}

			if ( type === 'switch' ) {
				input.checked = false;
				return;
			}

			if ( input.tagName === 'SELECT' ) {
				input.selectedIndex = 0;
				return;
			}

			input.value = '';
		} );

		syncDateMode();
		toast( 'فیلترها پاک شدند.', 'ok' );
	}

	function setAllColumns( checked ) {
		$$( 'input[name="columns[]"]' ).forEach( function ( input ) {
			input.checked = checked;
		} );

		updateOutputHint();
	}

	function setDefaultColumns() {
		$$( '[data-column]' ).forEach( function ( wrap ) {
			var input = wrap.querySelector( 'input[type="checkbox"]' );

			if ( input ) {
				input.checked = wrap.getAttribute( 'data-default' ) === '1';
			}
		} );

		updateOutputHint();
	}

	/* -----------------------------------------------------------------
	 * اتصال رویدادها
	 * ----------------------------------------------------------------- */

	function bind() {
		var startButton = $( '#tisa-exp-start' );

		if ( startButton ) {
			startButton.addEventListener( 'click', start );
		}

		var continueButton = $( '#tisa-exp-continue' );

		if ( continueButton ) {
			continueButton.addEventListener( 'click', resume );
		}

		var previewButton = $( '#tisa-exp-preview' );

		if ( previewButton ) {
			previewButton.addEventListener( 'click', preview );
		}

		var previewClose = $( '#tisa-exp-preview-close' );

		if ( previewClose ) {
			previewClose.addEventListener( 'click', function () {
				var card = $( '#tisa-exp-card-preview' );

				if ( card ) {
					card.hidden = true;
				}
			} );
		}

		var statusButton = $( '#tisa-exp-status' );

		if ( statusButton ) {
			statusButton.addEventListener( 'click', function () {
				if ( state.payload && state.payload.run_id ) {
					applyPayload( state.payload );
					toast( cfg.l10n.lastState, 'ok' );
					return;
				}

				toast( cfg.l10n.noSession, 'warn' );
			} );
		}

		var cancelButton = $( '#tisa-exp-cancel' );

		if ( cancelButton ) {
			cancelButton.addEventListener( 'click', cancel );
		}

		var resetButton = $( '#tisa-exp-reset' );

		if ( resetButton ) {
			resetButton.addEventListener( 'click', resetFilters );
		}

		var allColumns = $( '#tisa-exp-cols-all' );

		if ( allColumns ) {
			allColumns.addEventListener( 'click', function () { setAllColumns( true ); } );
		}

		var defaultColumns = $( '#tisa-exp-cols-default' );

		if ( defaultColumns ) {
			defaultColumns.addEventListener( 'click', setDefaultColumns );
		}

		var historyClear = $( '#tisa-exp-history-clear' );

		if ( historyClear ) {
			historyClear.addEventListener( 'click', clearHistory );
		}

		$$( '.tisa-exp__check-all' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var name  = button.getAttribute( 'data-target' );
				var want  = button.getAttribute( 'data-state' ) === '1';

				$$( '[data-filter="' + name + '"] input[type="checkbox"]' ).forEach( function ( input ) {
					input.checked = want;
				} );
			} );
		} );

		var historyBox = $( '#tisa-exp-history' );

		if ( historyBox ) {
			historyBox.addEventListener( 'click', function ( event ) {
				var button = event.target.closest ? event.target.closest( '[data-reuse]' ) : null;

				if ( button ) {
					reuse( button.getAttribute( 'data-reuse' ) );
				}
			} );
		}

		$$( 'input[name="columns[]"], input[name="format"]' ).forEach( function ( input ) {
			input.addEventListener( 'change', updateOutputHint );
		} );

		$$( '[data-filter] input, [data-filter] select' ).forEach( function ( input ) {
			input.addEventListener( 'change', updateOutputHint );
		} );

		var dateMode = $( '[data-filter="date_mode"] select' );

		if ( dateMode ) {
			dateMode.addEventListener( 'change', function () {
				syncDateMode();
				updateOutputHint();
			} );
		}

		syncDateMode();

		// انتخاب‌های قالبی (کارت‌های تیسا) و کلید یکتاسازی.
		$$( 'input[name="format"]' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				$$( 'input[name="format"]' ).forEach( function ( item ) {
					var choice = item.closest( '.tisa-choice' );

					if ( choice ) {
						choice.classList.toggle( 'is-selected', item.checked );
					}
				} );
			} );
		} );
	}

	function boot() {
		bind();
		renderHistory();
		updateOutputHint();
		renderMeta( cfg.initial || {} );

		if ( cfg.initial && cfg.initial.run_id && ! cfg.initial.done ) {
			state.runId    = cfg.initial.run_id;
			state.startedAt = 0;
			applyPayload( cfg.initial );

			var resumeButton = $( '#tisa-exp-continue' );

			if ( resumeButton ) {
				resumeButton.hidden = false;
			}

			toast( cfg.l10n.resumed, 'warn' );
			return;
		}

		if ( cfg.initial && cfg.initial.done && cfg.initial.files && cfg.initial.files.length ) {
			applyPayload( cfg.initial );
		} else {
			renderMeta( {} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );

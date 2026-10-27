/**
 * TisaCase Pricing — تقویم شمسی (جلالی).
 *
 * دو لایه:
 *   ۱) تبدیل خالص میلادی ↔ شمسی (معادل الگوریتم PHP کلاس TCP_Jalali).
 *   ۲) datepicker سبکِ بدون وابستگی برای هر wrapper با [data-tcp-jdate].
 *
 * قرارداد مارک‌آپ (由 PHP رندر می‌شود):
 *   <span class="tcp-jdate" data-tcp-jdate>
 *     <input type="text"   class="tcp-jdate__text"  dir="ltr" placeholder="1405/08/08">
 *     <input type="hidden" class="tcp-jdate__value" name="..." value="2026-10-30">
 *     <button type="button" class="tcp-jdate__toggle">…</button>
 *   </span>
 *
 * ذخیره‌سازی همیشه میلادی می‌ماند؛ فیلد متنی فقط نمای شمسی است.
 */
(function () {
	'use strict';

	var MONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
	var WEEKDAYS = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج']; // شنبه تا جمعه
	var LEAP_REMAINDERS = [1, 5, 9, 13, 17, 22, 26, 30];

	function div(a, b) { return Math.floor(a / b); }
	function pad2(n) { return (n < 10 ? '0' : '') + n; }
	function pad4(n) { n = String(n); while (n.length < 4) { n = '0' + n; } return n; }

	function toLatinDigits(value) {
		return String(value == null ? '' : value).replace(/[۰-۹٠-٩]/g, function (d) {
			var code = d.charCodeAt(0);
			return String(code >= 0x06F0 ? code - 0x06F0 : code - 0x0660);
		});
	}

	/* ---------------- تبدیل خالص (هم‌الگو با class-tcp-jalali.php) ---------------- */

	var G_DAYS = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
	var J_DAYS = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

	/** میلادی → شمسی — پورت دقیق الگوریتم مرجع jdatetime (هم‌الگو با TCP_Jalali::to_jalali). */
	function toJalali(gy, gm, gd) {
		gy = gy - 1600;
		gm = gm - 1;

		var jDayNo = 365 * gy + div(gy + 3, 4) - div(gy + 99, 100) + div(gy + 399, 400) + gd - 1 - 79;
		for (var i = 0; i < gm; i++) {
			jDayNo += G_DAYS[i];
		}
		if (gm > 1 && ((gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0)) {
			jDayNo++; // سال کبیسهٔ میلادی و بعد از فوریه.
		}

		var jNp = div(jDayNo, 12053);
		jDayNo %= 12053;
		var jy = 979 + 33 * jNp + 4 * div(jDayNo, 1461);
		jDayNo %= 1461;

		if (jDayNo >= 366) {
			jDayNo -= 1;
			jy += div(jDayNo, 365);
			jDayNo %= 365;
		}

		var m = 0;
		while (m < 11 && jDayNo >= J_DAYS[m]) {
			jDayNo -= J_DAYS[m];
			m++;
		}
		return [jy, m + 1, jDayNo + 1];
	}

	/** شمسی → میلادی — پورت دقیق الگوریتم مرجع jdatetime (هم‌الگو با TCP_Jalali::to_gregorian). */
	function toGregorian(jy, jm, jd) {
		jy = jy - 979;

		var gDayNo = 365 * jy + div(jy, 33) * 8 + div((jy % 33) + 3, 4) + jd - 1 + 79;
		for (var i = 0; i < jm - 1; i++) {
			gDayNo += J_DAYS[i];
		}

		var gy = 1600 + 400 * div(gDayNo, 146097);
		gDayNo %= 146097;
		var leap = 1;

		if (gDayNo >= 36525) {
			gDayNo -= 1;
			gy += 100 * div(gDayNo, 36524);
			gDayNo %= 36524;
			if (gDayNo >= 365) {
				gDayNo += 1;
			} else {
				leap = 0;
			}
		}

		gy += 4 * div(gDayNo, 1461);
		gDayNo %= 1461;

		if (gDayNo >= 366) {
			leap = 0;
			gDayNo -= 1;
			gy += div(gDayNo, 365);
			gDayNo %= 365;
		}

		var m = 0;
		while (gDayNo >= G_DAYS[m] + (m === 1 ? leap : 0)) {
			gDayNo -= G_DAYS[m] + (m === 1 ? leap : 0);
			m++;
		}
		return [gy, m + 1, gDayNo + 1];
	}

	function isLeap(jy) {
		return LEAP_REMAINDERS.indexOf(((jy % 33) + 33) % 33) !== -1;
	}

	function monthLength(jy, jm) {
		if (jm <= 6) { return 31; }
		if (jm <= 11) { return 30; }
		return isLeap(jy) ? 30 : 29;
	}

	/** ورودی شمسی کاربر → رشتهٔ میلادی Y-m-d یا '' */
	function parseJalali(input) {
		var clean = toLatinDigits(input).trim();
		var m = /^(\d{4})[\s\/\-.](\d{1,2})[\s\/\-.](\d{1,2})$/.exec(clean);
		if (!m) { return ''; }
		var jy = parseInt(m[1], 10), jm = parseInt(m[2], 10), jd = parseInt(m[3], 10);
		if (jy < 1 || jy > 3000 || jm < 1 || jm > 12 || jd < 1 || jd > monthLength(jy, jm)) {
			return '';
		}
		var g = toGregorian(jy, jm, jd);
		return pad4(g[0]) + '-' + pad2(g[1]) + '-' + pad2(g[2]);
	}

	/** رشتهٔ میلادی Y-m-d → متن نمایشی شمسی YYYY/MM/DD یا '' */
	function toJalaliText(gregorian) {
		var clean = toLatinDigits(gregorian).trim();
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(clean);
		if (!m) { return ''; }
		var j = toJalali(parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10));
		return pad4(j[0]) + '/' + pad2(j[1]) + '/' + pad2(j[2]);
	}

	/* ---------------- datepicker ---------------- */

	var popup = null;     // عنصر مشترک popup (جابه‌جا می‌شود).
	var current = null;   // { wrapper, text, hidden, viewY, viewM }

	function todayJalali() {
		var d = new Date();
		return toJalali(d.getFullYear(), d.getMonth() + 1, d.getDate());
	}

	function selectedJalali(wrapper) {
		var g = wrapper.querySelector('.tcp-jdate__value').value;
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(g);
		return m ? toJalali(parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10)) : null;
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) { node.className = className; }
		if (text != null) { node.textContent = text; }
		return node;
	}

	var ITEM_H = 32; // ارتفاع هر آیتم wheel (باید با CSS یکی باشد).
	var WHEEL_PAD = ITEM_H * 2; // فاصلهٔ بالا/پایین برای رسیدن آیتم ابتدا/انتها به مرکز.
	var yearRange = null;

	function getYearRange() {
		if (!yearRange) {
			var y = todayJalali()[0];
			yearRange = { min: y - 50, max: y + 30 };
		}
		return yearRange;
	}

	function wheelIndex(container, count) {
		var idx = Math.round(container.scrollTop / ITEM_H);
		return Math.max(0, Math.min(count - 1, idx));
	}

	/** اسکرول wheel به اندیس موردنظر و علامت‌گذاری آیتم فعال. */
	function syncWheel(container, index) {
		var top = index * ITEM_H;
		if (Math.abs(container.scrollTop - top) > 1) {
			container.scrollTop = top;
		}
		var items = container.querySelectorAll('.tcp-jcal__wheel-item');
		for (var i = 0; i < items.length; i++) {
			items[i].classList.toggle('is-active', i === index);
		}
	}

	/**
	 * ساخت یک wheel عمودی با اسنپ مرکزی.
	 * onPick(index) هم با کلیک و هم با اسکرول (پس از توقف) صدا زده می‌شود.
	 */
	function buildWheel(container, labels, onPick) {
		container.innerHTML = '';
		container.appendChild(el('span', 'tcp-jcal__wheel-band'));
		var topPad = el('div', 'tcp-jcal__wheel-spacer');
		topPad.style.height = WHEEL_PAD + 'px';
		container.appendChild(topPad);
		labels.forEach(function (label, idx) {
			var item = el('button', 'tcp-jcal__wheel-item', label);
			item.type = 'button';
			item.dataset.index = String(idx);
			item.addEventListener('click', function () { onPick(idx); });
			container.appendChild(item);
		});
		var bottomPad = el('div', 'tcp-jcal__wheel-spacer');
		bottomPad.style.height = WHEEL_PAD + 'px';
		container.appendChild(bottomPad);

		var settleTimer = null;
		container.addEventListener('scroll', function () {
			clearTimeout(settleTimer);
			settleTimer = setTimeout(function () {
				onPick(wheelIndex(container, labels.length));
			}, 90);
		});
	}

	function ensurePopup() {
		if (popup) { return popup; }
		popup = el('div', 'tcp-jcal');
		popup.setAttribute('dir', 'rtl');
		popup.hidden = true;

		// هدر: دو wheel کنار هم برای انتخاب ماه و سال.
		var wheels = el('div', 'tcp-jcal__wheels');
		var monthWheel = el('div', 'tcp-jcal__wheel tcp-jcal__wheel--month');
		var yearWheel = el('div', 'tcp-jcal__wheel tcp-jcal__wheel--year');
		wheels.appendChild(monthWheel);
		wheels.appendChild(yearWheel);

		var yr = getYearRange();
		var yearLabels = [];
		for (var yy = yr.min; yy <= yr.max; yy++) {
			yearLabels.push(toLatinDigits(yy));
		}
		buildWheel(monthWheel, MONTHS.slice(), function (idx) {
			var m = idx + 1;
			if (current && m !== current.viewM) {
				current.viewM = m;
				renderGrid();
			}
		});
		buildWheel(yearWheel, yearLabels, function (idx) {
			var y = yr.min + idx;
			if (current && y !== current.viewY) {
				current.viewY = y;
				renderGrid();
			}
		});

		var weekRow = el('div', 'tcp-jcal__weekrow');
		WEEKDAYS.forEach(function (w) { weekRow.appendChild(el('span', 'tcp-jcal__week', w)); });

		var days = el('div', 'tcp-jcal__days');

		var foot = el('div', 'tcp-jcal__foot');
		var todayBtn = el('button', 'tcp-jcal__today', 'امروز');
		todayBtn.type = 'button';
		var clearBtn = el('button', 'tcp-jcal__clear', 'پاک‌کردن');
		clearBtn.type = 'button';
		foot.appendChild(todayBtn);
		foot.appendChild(clearBtn);

		popup.appendChild(wheels);
		popup.appendChild(weekRow);
		popup.appendChild(days);
		popup.appendChild(foot);
		document.body.appendChild(popup);

		todayBtn.addEventListener('click', function () {
			var t = todayJalali();
			current.viewY = t[0];
			current.viewM = t[1];
			renderGrid();
		});
		clearBtn.addEventListener('click', function () {
			syncFields(current.wrapper, '', '');
			closePopup();
		});
		return popup;
	}

	/** هم‌گام‌سازی موقعیت wheelها با ماه/سال نمای جاری. */
	function syncWheels() {
		var range = getYearRange();
		var yIndex = current.viewY - range.min;
		yIndex = Math.max(0, Math.min(range.max - range.min, yIndex));
		syncWheel(popup.querySelector('.tcp-jcal__wheel--month'), current.viewM - 1);
		syncWheel(popup.querySelector('.tcp-jcal__wheel--year'), yIndex);
	}

	function renderGrid() {
		var jy = current.viewY, jm = current.viewM;
		popup.dataset.viewY = String(jy);
		popup.dataset.viewM = String(jm);

		var days = popup.querySelector('.tcp-jcal__days');
		days.innerHTML = '';

		// ستون شروع ماه: شنبه=۰. از روی معادل گرگوری روز اول حساب می‌شود.
		var firstG = toGregorian(jy, jm, 1);
		var jsDow = new Date(firstG[0], firstG[1] - 1, firstG[2]).getDay(); // 0=یکشنبه
		var lead = (jsDow + 1) % 7;

		var sel = selectedJalali(current.wrapper);
		var t = todayJalali();
		var len = monthLength(jy, jm);
		var cells = [];

		// روزهای ماه قبل (کم‌رنگ).
		var prevY = jy, prevM = jm - 1;
		if (prevM < 1) { prevM = 12; prevY--; }
		var prevLen = monthLength(prevY, prevM);
		for (var i = lead - 1; i >= 0; i--) {
			cells.push({ y: prevY, m: prevM, d: prevLen - i, outside: true });
		}
		for (var d = 1; d <= len; d++) {
			cells.push({ y: jy, m: jm, d: d, outside: false });
		}
		var ni = 0;
		while (cells.length < 42) {
			var nextM2 = jm + 1, nextY2 = jy;
			if (nextM2 > 12) { nextM2 = 1; nextY2++; }
			ni++;
			cells.push({ y: nextY2, m: nextM2, d: ni, outside: true });
		}

		cells.forEach(function (cell) {
			var btn = el('button', 'tcp-jcal__day');
			btn.type = 'button';
			btn.textContent = toLatinDigits(cell.d);
			if (cell.outside) { btn.classList.add('is-outside'); }
			if (cell.y === t[0] && cell.m === t[1] && cell.d === t[2]) { btn.classList.add('is-today'); }
			if (sel && cell.y === sel[0] && cell.m === sel[1] && cell.d === sel[2]) { btn.classList.add('is-selected'); }
			btn.addEventListener('click', function () {
				if (cell.outside) {
					current.viewY = cell.y;
					current.viewM = cell.m;
					renderGrid();
					return;
				}
				var text = pad4(cell.y) + '/' + pad2(cell.m) + '/' + pad2(cell.d);
				var g = toGregorian(cell.y, cell.m, cell.d);
				syncFields(current.wrapper, text, pad4(g[0]) + '-' + pad2(g[1]) + '-' + pad2(g[2]));
				closePopup();
			});
			days.appendChild(btn);
		});

		syncWheels();
		positionPopup();
	}

	function positionPopup() {
		var rect = current.wrapper.getBoundingClientRect();
		popup.style.visibility = 'hidden';
		popup.hidden = false;
		var width = popup.offsetWidth || 276;
		var height = popup.offsetHeight || 440;
		var left = rect.left;
		if (left + width > window.innerWidth - 8) {
			left = Math.max(8, window.innerWidth - width - 8);
		}
		var top = rect.bottom + 4;
		if (top + height > window.innerHeight - 8) {
			var above = rect.top - height - 4;
			top = above >= 8 ? above : Math.max(8, window.innerHeight - height - 8);
		}
		popup.style.left = left + 'px';
		popup.style.top = top + 'px';
		popup.style.visibility = '';
	}

	function openPopup(wrapper) {
		ensurePopup();
		if (current && current.wrapper !== wrapper) {
			closePopup();
		}
		current = { wrapper: wrapper };
		var init = selectedJalali(wrapper) || todayJalali();
		var range = getYearRange();
		// سال‌های خارج از بازهٔ wheel (مثل کوپن‌های قدیمی ذخیره‌شده با سال نامتعارف) به بازه محدود می‌شوند.
		current.viewY = Math.max(range.min, Math.min(range.max, init[0]));
		current.viewM = init[1];
		current.text = wrapper.querySelector('.tcp-jdate__text');
		current.hidden = wrapper.querySelector('.tcp-jdate__value');
		renderGrid();
		popup.hidden = false;
	}

	function closePopup() {
		if (popup) { popup.hidden = true; }
		current = null;
	}

	/** هم‌گام‌سازی متن شمسی و مقدار مخفی میلادی + رویداد change برای اسکریپت‌های دیگر. */
	function syncFields(wrapper, jalaliText, gregorianYmd) {
		var text = wrapper.querySelector('.tcp-jdate__text');
		var hidden = wrapper.querySelector('.tcp-jdate__value');
		text.value = jalaliText;
		hidden.value = gregorianYmd;
		text.classList.remove('is-invalid');
		text.dispatchEvent(new window.Event('change', { bubbles: true }));
		hidden.dispatchEvent(new window.Event('change', { bubbles: true }));
	}

	function commitTyped(wrapper) {
		var text = wrapper.querySelector('.tcp-jdate__text');
		var raw = text.value.trim();
		if (!raw) {
			syncFields(wrapper, '', '');
			return;
		}
		var g = parseJalali(raw);
		if (g) {
			syncFields(wrapper, toJalaliText(g), g);
		} else {
			text.classList.add('is-invalid');
			wrapper.querySelector('.tcp-jdate__value').value = '';
		}
	}

	function bindWrapper(wrapper) {
		if (wrapper.dataset.tcpJdateBound === '1') { return; }
		wrapper.dataset.tcpJdateBound = '1';

		var text = wrapper.querySelector('.tcp-jdate__text');
		var toggle = wrapper.querySelector('.tcp-jdate__toggle');

		text.addEventListener('click', function () { openPopup(wrapper); });
		if (toggle) {
			toggle.addEventListener('click', function (e) {
				e.preventDefault();
				if (popup && !popup.hidden && current && current.wrapper === wrapper) {
					closePopup();
				} else {
					openPopup(wrapper);
				}
			});
		}
		text.addEventListener('input', function () { text.classList.remove('is-invalid'); });
		text.addEventListener('blur', function () {
			// تأخیر کوتاه تا کلیک روی روز تقویم اول اجرا شود.
			window.setTimeout(function () {
				if (!current || current.wrapper !== wrapper) { commitTyped(wrapper); }
			}, 150);
		});
		text.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				commitTyped(wrapper);
				closePopup();
			} else if (e.key === 'Escape') {
				closePopup();
			}
		});
	}

	function bindAll(root) {
		var scope = root || document;
		var nodes = scope.querySelectorAll('[data-tcp-jdate]');
		for (var i = 0; i < nodes.length; i++) {
			bindWrapper(nodes[i]);
		}
	}

	/**
	 * تنظیم مقدار از بیرون (مثلاً مودال قوانین): ورودی میلادی Y-m/d یا ''.
	 * target می‌تواند selector، خودِ فیلد متنی یا wrapper باشد.
	 */
	function setGregorian(target, gregorianYmd) {
		var node = typeof target === 'string' ? document.querySelector(target) : target;
		if (!node) { return; }
		var wrapper = node.hasAttribute('data-tcp-jdate') ? node : node.closest('[data-tcp-jdate]');
		if (!wrapper) { return; }
		var g = toLatinDigits(gregorianYmd || '').trim();
		if (/^\d{4}-\d{2}-\d{2}$/.test(g)) {
			syncFields(wrapper, toJalaliText(g), g);
		} else {
			syncFields(wrapper, '', '');
		}
	}

	document.addEventListener('click', function (e) {
		if (!popup || popup.hidden) { return; }
		var t = e.target;
		// روز ماه مجاور در همان کلیک بازسازی می‌شود و دیگر فرزند popup نیست؛ کلاس خودش را هم چک می‌کنیم.
		var insidePopup = popup.contains(t) || (t && t.className && String(t.className).indexOf('tcp-jcal__') !== -1);
		if (insidePopup || (current && current.wrapper.contains(t))) { return; }
		closePopup();
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { closePopup(); }
	});
	window.addEventListener('resize', closePopup);
	// اسکرول بیرون از تقویم آن را می‌بندد؛ اسکرول داخل wheelها نباید چیزی ببندد.
	window.addEventListener('scroll', function (e) {
		if (!popup || popup.hidden) { return; }
		var t = e.target;
		if (t && t.nodeType === 1 && (popup.contains(t) || (current && current.wrapper.contains(t)))) {
			return;
		}
		closePopup();
	}, true);

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () { bindAll(document); });
	} else {
		bindAll(document);
	}

	window.TCPJalali = {
		toJalali: toJalali,
		toGregorian: toGregorian,
		isLeap: isLeap,
		monthLength: monthLength,
		parseJalali: parseJalali,
		toJalaliText: toJalaliText,
		toLatinDigits: toLatinDigits,
		setGregorian: setGregorian,
		bindAll: bindAll,
		months: MONTHS
	};
})();

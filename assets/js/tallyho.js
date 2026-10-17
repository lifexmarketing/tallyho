/*!
 * Tallyho Form Tracking: browser listener.
 *
 * Static file, no per-visitor data, safe to cache. Pushes one dataLayer event
 * per genuine conversion the server has approved:
 *   - AJAX on-page confirmation: marker arrives with gform_confirmation_loaded
 *   - Non-AJAX on-page confirmation: marker is in the page that loads after the POST
 *   - Redirect confirmation: short-lived tallyho_conv cookie read on the next page
 *
 * Debug: add ?tallyho_debug=1 to any URL (persists in localStorage), ?tallyho_debug=0 to stop.
 */
(function () {
	'use strict';

	var EVENT_NAME = 'tallyho_conversion';
	var COOKIE_NAME = 'tallyho_conv';
	var GUARD_PREFIX = 'tallyho_sent_';
	var memoryGuard = {};

	function debugEnabled() {
		try {
			var m = /[?&]tallyho_debug=([01])\b/.exec(window.location.search);
			if (m) {
				if (m[1] === '1') { window.localStorage.setItem('tallyho_debug', '1'); }
				else { window.localStorage.removeItem('tallyho_debug'); }
			}
			return window.localStorage.getItem('tallyho_debug') === '1';
		} catch (e) {
			return false;
		}
	}

	var DEBUG = debugEnabled();

	function log() {
		if (!DEBUG || !window.console || !window.console.log) { return; }
		var args = Array.prototype.slice.call(arguments);
		args.unshift('[Tallyho Form Tracking]');
		window.console.log.apply(window.console, args);
	}

	function optedOut() {
		try {
			return typeof window.isTrackingOptOut === 'function' && window.isTrackingOptOut() === true;
		} catch (e) {
			return false;
		}
	}

	function alreadySent(eventId) {
		if (memoryGuard[eventId]) { return true; }
		try { return window.sessionStorage.getItem(GUARD_PREFIX + eventId) === '1'; }
		catch (e) { return false; }
	}

	function markSent(eventId) {
		memoryGuard[eventId] = true;
		try { window.sessionStorage.setItem(GUARD_PREFIX + eventId, '1'); } catch (e) { /* memory guard still applies */ }
	}

	function push(data) {
		if (!data || !data.entry_id || !/^\d+$/.test(data.entry_id) || data.entry_id === '0') {
			log('skipped: invalid payload', data);
			return false;
		}

		var source = data.source || 'gravityforms';
		var prefix = source === 'gravityforms' ? 'gf' : source;
		// Site key keeps IDs unique when one Meta pixel or GA4 property spans several sites.
		var site = /^[a-z0-9]{1,12}$/.test(data.site || '') ? data.site : '';
		var eventId = prefix + '_' + (site ? site + '_' : '') + data.entry_id;

		if (optedOut()) {
			log('skipped: privacy signal (GPC/DNT)', eventId);
			return false;
		}
		if (alreadySent(eventId)) {
			log('skipped: already sent', eventId);
			return false;
		}

		markSent(eventId);
		var payload = {
			event: EVENT_NAME,
			lx_source: source,
			form_id: data.form_id || '',
			form_title: data.form_title || '',
			conversion_name: data.conversion_name || '',
			entry_id: data.entry_id,
			event_id: eventId,
			delivery: data.delivery || ''
		};
		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push(payload);
		log('pushed', payload);
		return true;
	}

	function scanMarkers(root) {
		var scope = root && root.querySelectorAll ? root : document;
		var els = scope.querySelectorAll('.tallyho-conversion');
		for (var i = 0; i < els.length; i++) {
			var el = els[i];
			push({
				source: el.getAttribute('data-source'),
				site: el.getAttribute('data-site'),
				form_id: el.getAttribute('data-form-id'),
				entry_id: el.getAttribute('data-entry-id'),
				form_title: el.getAttribute('data-form-title'),
				conversion_name: el.getAttribute('data-conversion-name'),
				delivery: el.getAttribute('data-delivery')
			});
		}
	}

	function readCookie() {
		var raw = null;
		try {
			var parts = document.cookie ? document.cookie.split(';') : [];
			for (var i = 0; i < parts.length; i++) {
				var c = parts[i].replace(/^\s+/, '');
				if (c.indexOf(COOKIE_NAME + '=') === 0) {
					raw = c.substring(COOKIE_NAME.length + 1);
					break;
				}
			}
		} catch (e) {
			return null;
		}
		if (raw === null) { return null; }

		// Delete first, whatever happens next, so it can never fire twice.
		deleteCookie();

		var f = raw.split('|');
		if (f.length < 7 || f[0] !== 'v1') {
			log('skipped: unrecognized cookie');
			return null;
		}
		function dec(v) { try { return decodeURIComponent(v); } catch (e) { return ''; } }
		return {
			source: dec(f[1]),
			site: dec(f[2]),
			form_id: dec(f[3]),
			entry_id: dec(f[4]),
			conversion_name: dec(f[5]),
			form_title: dec(f[6]),
			delivery: 'redirect'
		};
	}

	// The server may set the cookie with a Domain attribute (COOKIE_DOMAIN), which
	// a host-only delete would not remove. Expire it for the host and every parent domain.
	function deleteCookie() {
		var base = COOKIE_NAME + '=; Max-Age=0; path=/; SameSite=Lax';
		try {
			document.cookie = base;
			var parts = (window.location.hostname || '').split('.');
			for (var i = 0; i < parts.length - 1; i++) {
				var d = parts.slice(i).join('.');
				document.cookie = base + '; domain=' + d;
				document.cookie = base + '; domain=.' + d;
			}
		} catch (e) { /* ignore */ }
	}

	function scanCookie() {
		var data = readCookie();
		if (data) { push(data); }
	}

	var jqueryBound = false;
	function bindGravityForms(attempt) {
		if (jqueryBound) { return; }
		var $ = window.jQuery;
		if ($ && $.fn && typeof $.fn.on === 'function') {
			jqueryBound = true;
			$(document).on('gform_confirmation_loaded', function (e, formId) {
				log('gform_confirmation_loaded', formId);
				scanMarkers(document);
			});
			// A confirmation can land between page load and binding; catch it.
			scanMarkers(document);
			return;
		}
		// jQuery may load after this file, or be delayed by an optimization plugin.
		if ((attempt || 0) < 40) {
			window.setTimeout(function () { bindGravityForms((attempt || 0) + 1); }, 250);
		}
	}

	// Watch the page for confirmation markers directly. This catches AJAX
	// confirmations even when jQuery is delayed by an optimization plugin or
	// Gravity Forms changes how it announces confirmations.
	var observing = false;
	function observeMarkers() {
		if (observing || typeof window.MutationObserver !== 'function' || !document.body) { return; }
		observing = true;
		var pending = false;
		new window.MutationObserver(function (mutations) {
			if (pending) { return; }
			for (var i = 0; i < mutations.length; i++) {
				var added = mutations[i].addedNodes;
				for (var j = 0; j < added.length; j++) {
					var n = added[j];
					if (n.nodeType === 1 && ((n.classList && n.classList.contains('tallyho-conversion')) || (n.querySelector && n.querySelector('.tallyho-conversion')))) {
						pending = true;
						window.setTimeout(function () { pending = false; log('marker added to page'); scanMarkers(document); }, 0);
						return;
					}
				}
			}
		}).observe(document.body, { childList: true, subtree: true });
	}

	function run() {
		scanCookie();
		scanMarkers(document);
		observeMarkers();
		bindGravityForms(0);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', run);
	} else {
		run();
	}

	// Back/forward cache restores do not re-run scripts; the guard prevents repeats.
	window.addEventListener('pageshow', function (e) {
		if (e && e.persisted) {
			scanCookie();
			scanMarkers(document);
		}
	});

	// Small surface for testing and other LifeX integrations (e.g. Replit success handlers).
	window.tallyho = { push: push, scan: run, eventName: EVENT_NAME };
})();

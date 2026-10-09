/*!
 * Magellan checkout email capture v2.2
 * Captures email at checkout for abandoned cart attribution.
 * Hashes client-side, POSTs to plugin's /wp-json/magellan/v1/cart-email.
 * Plugin then signs + forwards to Magellan.
 */
(function () {
	'use strict';

	if (typeof window === 'undefined') return;

	var meta = document.querySelector('meta[name="magellan-rest"]');
	if (!meta) return;
	var REST_URL = meta.getAttribute('content');
	if (!REST_URL) return;

	var lastSent = null;
	var SEND_GAP = 5000; // throttle 5s

	// SHA-256 via SubtleCrypto. Fallback: skip if not available.
	async function sha256(text) {
		if (!window.crypto || !window.crypto.subtle) return null;
		var enc = new TextEncoder().encode(String(text).toLowerCase().trim());
		var buf = await window.crypto.subtle.digest('SHA-256', enc);
		return 'sha256:' + Array.prototype.map.call(new Uint8Array(buf), function (b) {
			return ('0' + b.toString(16)).slice(-2);
		}).join('');
	}

	function getCartToken() {
		var existing = null;
		try { existing = localStorage.getItem('_mgln_cart_token'); } catch (e) {}
		var token = existing;
		if (!token) {
			// Generate v4-ish token
			token = 'cart_' + Date.now().toString(36) + '_' + Math.random().toString(36).substring(2, 10);
			try { localStorage.setItem('_mgln_cart_token', token); } catch (e) {}
		}
		// Mirror into a cookie so the server can stamp it onto the order at
		// checkout (links the order to the cart for conversion). Same key the
		// cart listener uses, so they stay one token.
		try {
			var d = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toUTCString();
			document.cookie = '_mgln_cart_token=' + token + '; expires=' + d + '; path=/; SameSite=Lax';
		} catch (e) {}
		return token;
	}

	function allowed() { return window.Magellan && window.Magellan.consent && window.Magellan.consent().analytics==='granted'; }
	async function sendEmail(email) {
		if (!allowed()) return;
		if (!email || email.indexOf('@') < 0) return;
		if (lastSent && (Date.now() - lastSent) < SEND_GAP) return;
		lastSent = Date.now();

		var hash = await sha256(email);
		if (!hash || !allowed()) return;

		var pixel = window.Magellan && window.Magellan.getCookie ? window.Magellan.getCookie() : {};
		var token = getCartToken();

		var payload = {
			cart_token: token,
			occurred_at: new Date().toISOString(),
			identity: { email_hash: hash, phone_hash: null },
			attribution: {
				first_touch: pixel.fs ? {
					source: pixel.fs, medium: pixel.fm, campaign: pixel.fc,
					occurred_at: pixel.ft ? new Date(pixel.ft * 1000).toISOString() : null
				} : null,
				last_paid_touch: pixel.lsrc ? {
					source: pixel.lsrc, medium: pixel.lmed, campaign: pixel.lcamp,
					occurred_at: pixel.lts ? new Date(pixel.lts * 1000).toISOString() : null
				} : null,
				click_ids: pixel.cids || {},
				session_count: pixel.sc || 1
			},
			consent_state: 'granted'
		};

		try {
			fetch(REST_URL, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(payload),
				credentials: 'same-origin',
				keepalive: true
			}).catch(function () { /* ignore */ });
		} catch (e) { /* ignore */ }
	}

	// Watch the email field — covers classic and Blocks checkout
	function bindEmailField() {
		var selectors = ['#billing_email', 'input[name="email"]', 'input[type="email"]'];
		for (var i = 0; i < selectors.length; i++) {
			var fields = document.querySelectorAll(selectors[i]);
			for (var j = 0; j < fields.length; j++) {
				var field = fields[j];
				if (field._mglnBound) continue;
				field._mglnBound = true;
				field.addEventListener('blur', function (e) { sendEmail(e.target.value); });
				field.addEventListener('change', function (e) { sendEmail(e.target.value); });
			}
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bindEmailField);
	} else {
		bindEmailField();
	}
	// Rebind on Blocks checkout (which re-renders fields)
	var rebind = setInterval(bindEmailField, 2000);
	setTimeout(function () { clearInterval(rebind); }, 30000);
})();

/*!
 * Magellan first-party pixel v2.5
 * Captures UTM parameters, click IDs, and referral source.
 * Stores in first-party cookie (_mgln). 6-month TTL.
 * Also establishes the _mgln_cart_token cookie (consumed server-side by the
 * cart-capture hooks + order stamping) so cart capture needs no client JS.
 * Designed for no measurable Core Web Vitals regression.
 */
(function () {
	'use strict';

	if (typeof window === 'undefined' || typeof document === 'undefined') return;

	var COOKIE = '_mgln';
	var TTL_MS = 180 * 24 * 60 * 60 * 1000; // 180 days

	var CLICK_IDS = ['fbclid','gclid','gbraid','wbraid','ttclid','msclkid','twclid'];
	var UTM_KEYS  = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term'];

	function readCookie(name) {
		var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]+)'));
		return m ? m[1] : null;
	}

	function writeCookie(name, value) {
		var d = new Date(Date.now() + TTL_MS).toUTCString();
		document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + d + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
	}

	function decodeCookie(raw) {
		try {
			return JSON.parse(decodeURIComponent(atob(decodeURIComponent(raw))));
		} catch (e) {
			return null;
		}
	}

	function encodeCookie(obj) {
		try {
			return btoa(encodeURIComponent(JSON.stringify(obj)));
		} catch (e) {
			return null;
		}
	}

	function getParam(qs, key) {
		var v = qs.get(key);
		return v ? String(v).substring(0, 200) : null;
	}

	function classifySource(utmSource, utmMedium, referrer) {
		// Returns simple normalized source label for the last-touch.
		var src = (utmSource || '').toLowerCase();
		var med = (utmMedium || '').toLowerCase();

		if (src.indexOf('facebook') >= 0 || src.indexOf('meta') >= 0 || src === 'fb' || src === 'ig' || src === 'instagram') return 'meta';
		if (src.indexOf('google') >= 0) return med === 'cpc' || med === 'paid' || med === 'ppc' ? 'google_paid' : 'google';
		if (src.indexOf('tiktok') >= 0) return 'tiktok';
		if (src.indexOf('klaviyo') >= 0 || med === 'email') return 'email';
		if (src.indexOf('bing') >= 0 || src.indexOf('microsoft') >= 0) return 'bing';
		if (src.indexOf('pinterest') >= 0) return 'pinterest';

		// No UTM — classify by referrer
		if (!referrer) return 'direct';
		var r = referrer.toLowerCase();
		if (r.indexOf('facebook.com') >= 0 || r.indexOf('instagram.com') >= 0 || r.indexOf('l.facebook') >= 0) return 'social_meta';
		if (r.indexOf('google.') >= 0) return 'organic_google';
		if (r.indexOf('bing.') >= 0) return 'organic_bing';
		if (r.indexOf('tiktok.com') >= 0) return 'social_tiktok';
		return 'referral';
	}

	function isPaidMedium(medium) {
		if (!medium) return false;
		var m = medium.toLowerCase();
		return m === 'cpc' || m === 'ppc' || m === 'paid' || m === 'paid_social' || m === 'paidsearch' || m === 'display';
	}

	// ---------------------------------------------------------
	// Read or initialize the cookie
	// ---------------------------------------------------------

	function collect(policy) {
	var raw = readCookie(COOKIE);
	var data = raw ? decodeCookie(raw) : null;
	var now = Math.floor(Date.now() / 1000);

	if (!data || typeof data !== 'object') {
		data = {
			// First touch — set once, never overwritten by direct traffic
			fs: null, fm: null, fc: null, fct: null, ft_kw: null,
			ft: now,            // first-seen timestamp
			furl: null,         // first landing URL
			fref: null,         // first referrer

			// Last paid touch — refreshed only when new paid signal arrives
			lsrc: null, lmed: null, lcamp: null, lcon: null, lterm: null,
			lts: null,          // last-paid timestamp

			// Click IDs — latest seen
			cids: {},

			// Session tracking
			sc: 0,              // session count
			lseen: 0            // last-seen timestamp (any session)
		};
	}

	// ---------------------------------------------------------
	// Parse current URL for UTM + click IDs + referrer
	// ---------------------------------------------------------

	var qs = new URLSearchParams(location.search);

	var hasUtm = false;
	var utm = {};
	for (var i = 0; i < UTM_KEYS.length; i++) {
		var k = UTM_KEYS[i];
		var v = getParam(qs, k);
		if (v) { utm[k] = v; hasUtm = true; }
	}

	var newClickIds = {};
	for (var j = 0; j < CLICK_IDS.length; j++) {
		var ck = CLICK_IDS[j];
		var cv = getParam(qs, ck);
		if (cv) newClickIds[ck] = cv;
	}
	if (policy.advertising !== 'granted') { newClickIds={}; data.cids={}; }
	var hasClickId = Object.keys(newClickIds).length > 0;

	var referrer = document.referrer || '';

	// ---------------------------------------------------------
	// Session counting — new session if last seen > 30 minutes
	// ---------------------------------------------------------

	var SESSION_GAP = 30 * 60; // 30 minutes
	if (!data.lseen || (now - data.lseen) > SESSION_GAP) {
		data.sc = (data.sc || 0) + 1;
	}
	data.lseen = now;

	// ---------------------------------------------------------
	// First-touch — set once
	// ---------------------------------------------------------

	if (!data.fs && (hasUtm || hasClickId || referrer)) {
		data.fs = utm.utm_source || classifySource(null, null, referrer);
		data.fm = utm.utm_medium || null;
		data.fc = utm.utm_campaign || null;
		data.fct = utm.utm_content || null;
		data.ft_kw = utm.utm_term || null;
		data.ft = now;
		data.furl = String(location.origin + location.pathname).substring(0, 500);
		data.fref = referrer ? (function () { try { return new URL(referrer).origin; } catch (_) { return null; } })() : null;
	}

	// ---------------------------------------------------------
	// Last paid touch — refresh ONLY on paid signal
	// (Avoids overwriting last paid with direct/organic.)
	// ---------------------------------------------------------

	var isPaid = isPaidMedium(utm.utm_medium) || hasClickId;
	if (isPaid) {
		data.lsrc  = utm.utm_source   || classifySource(utm.utm_source, utm.utm_medium, referrer);
		data.lmed  = utm.utm_medium   || 'cpc';
		data.lcamp = utm.utm_campaign || null;
		data.lcon  = utm.utm_content  || null;
		data.lterm = utm.utm_term     || null;
		data.lts   = now;
	}

	// ---------------------------------------------------------
	// Click IDs — merge, latest wins per ID
	// ---------------------------------------------------------

	if (hasClickId) {
		data.cids = data.cids || {};
		for (var ckey in newClickIds) {
			if (Object.prototype.hasOwnProperty.call(newClickIds, ckey)) {
				data.cids[ckey] = newClickIds[ckey];
			}
		}
	}

	// ---------------------------------------------------------
	// Write back
	// ---------------------------------------------------------

	var encoded = encodeCookie(data);
	if (encoded) {
		writeCookie(COOKIE, encoded);
	}

	// ---------------------------------------------------------
	// Cart token — a stable per-browser id for the cart, mirrored into a
	// cookie so the SERVER can read it: the server-side cart-capture hooks
	// (Magellan_Cart) read it on every cart mutation, and Magellan_Tracker
	// stamps it onto the order for the cart->order conversion join. It lives
	// here in the always-loaded pixel so the cookie exists on EVERY page,
	// before any add-to-cart, on any theme — no cart JS required. Same
	// localStorage key + format magellan-checkout.js uses, so they share one
	// token; host-only cookie scope matches checkout.js so there is exactly
	// one _mgln_cart_token cookie for the server to read.
	// ---------------------------------------------------------
	function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
	function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

	function getCartToken() {
		var token = lsGet('_mgln_cart_token');
		if (!token) {
			token = 'cart_' + Date.now().toString(36) + '_' + Math.random().toString(36).substring(2, 10);
			lsSet('_mgln_cart_token', token);
		}
		try {
			var dExp = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toUTCString();
			document.cookie = '_mgln_cart_token=' + token + '; expires=' + dExp + '; path=/; SameSite=Lax';
		} catch (e) {}
		return token;
	}

	var cartToken = getCartToken();

	// Expose minimal API for other scripts (checkout email capture).
	// Version is read from the meta tag the plugin injects so we don't
	// have to bump this string on every release.
	function readPluginVersion() {
		var el = document.querySelector('meta[name="magellan-account"]');
		// Plugin version isn't published in a meta tag today — emit
		// 'unknown' as a forward-compatible placeholder. Consumers that
		// need a hard version should rely on the script's URL ?ver= param
		// that WordPress adds via wp_enqueue_script.
		return el ? 'enqueued' : 'unknown';
	}
	window.Magellan = {
		v: readPluginVersion(),
		getCookie: function () { return data; },
		getCartToken: function () { return cartToken; },
		setConsent: apply, consent: function () { return Object.assign({}, policy); }
	};
    }
    var current = {analytics:'unknown',advertising:'unknown'};
    var consentChannel = typeof BroadcastChannel === 'function' ? new BroadcastChannel('magellan:legacy:privacy') : null;
    function purge() {
        ['_mgln','_mgln_cart_token','_mgln_consent'].forEach(function (name) {
            document.cookie=name+'=; Max-Age=0; Path=/; SameSite=Lax';
            // Remove cookies set by the old registrable-domain approximation as well.
            var labels=location.hostname.split('.');
            while (labels.length>1) { document.cookie=name+'=; Max-Age=0; Path=/; Domain=.'+labels.join('.'); labels.shift(); }
        });
        try { localStorage.removeItem('_mgln_cart_token'); } catch (_) {}
        window.Magellan={getCookie:function () { return {}; },getCartToken:function () { return null; },setConsent:apply,consent:function () { return Object.assign({},current); }};
    }
    function apply(input, broadcast) {
        input=input || {};
        current={analytics:input.analytics==='granted'?'granted':(input.analytics==='denied'?'denied':'unknown'),advertising:input.advertising==='granted' && navigator.globalPrivacyControl!==true?'granted':'denied',updated_at:Math.floor(Date.now()/1000)};
        if (current.analytics==='granted') { collect(current); document.cookie='_mgln_consent='+encodeURIComponent(JSON.stringify(current))+'; Max-Age=1800; Path=/; SameSite=Lax'+(location.protocol==='https:'?'; Secure':''); } else { purge(); }
        if (broadcast!==false && consentChannel) consentChannel.postMessage(current);
    }
    if (consentChannel) consentChannel.onmessage=function (event) { apply(event.data,false); };
    window.addEventListener('magellan:consent',function (event) { apply(event.detail); });
    function cookieConsent() { var b=window.Cookiebot; if (b && b.hasResponse) apply({analytics:b.consent.statistics?'granted':'denied',advertising:b.consent.marketing?'granted':'denied'}); }
    ['CookiebotOnConsentReady','CookiebotOnAccept','CookiebotOnDecline'].forEach(function (name) { window.addEventListener(name,cookieConsent); });
    apply(window.MagellanV3 && window.MagellanV3.getConsent ? window.MagellanV3.getConsent() : (window.MagellanConsent || {}),false); cookieConsent();
})();

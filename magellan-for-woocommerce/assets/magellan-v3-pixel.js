/* Magellan v3: consent-controlled observations. No purchase or inventory authority. */
(function (w, d) {
  'use strict';
  var c = w.MagellanV3Config;
  if (!c || w.MagellanV3 || location.origin !== c.origin || !w.crypto || !w.crypto.getRandomValues) return;
  var prefix = 'magellan:v3:' + c.site_id + ':', queue = [], consent = null, visitor = null, session = null;
  var seq = 0, timer = null, sending = false, attempts = 0, started = false, persistent = false, stopped = false, inFlight = null;
  var stream = uuid(), navigation = uuid(), attempt = null, registered = new Set(), initialized = Promise.resolve();
  var channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel(prefix + 'privacy') : null;
  var queueWrites=Promise.resolve(), owned=new Set();
  var states = ['granted', 'denied', 'unknown', 'not_applicable'];
  function uuid() {
    var b = new Uint8Array(16); w.crypto.getRandomValues(b); b[6] = (b[6] & 15) | 64; b[8] = (b[8] & 63) | 128;
    var h = Array.from(b, function (v) { return v.toString(16).padStart(2, '0'); }).join('');
    return h.slice(0,8)+'-'+h.slice(8,12)+'-'+h.slice(12,16)+'-'+h.slice(16,20)+'-'+h.slice(20);
  }
  function bytes(s) { return new TextEncoder().encode(s).length; }
  function now() { return new Date().toISOString(); }
  function cookie(name, value, age) { name += '_' + c.site_id; d.cookie = name + '=' + encodeURIComponent(value) + '; Path=/; Max-Age=' + age + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : ''); }
  function getCookie(name) { name += '_' + c.site_id; var part = d.cookie.split('; ').find(function (v) { return v.indexOf(name + '=') === 0; }); try { return part ? decodeURIComponent(part.slice(name.length + 1)) : null; } catch (_) { return null; } }
  function read(key) { try { return JSON.parse(w.localStorage.getItem(prefix + key)); } catch (_) { return null; } }
  function write(key, value) { try { w.localStorage.setItem(prefix + key, JSON.stringify(value)); return true; } catch (_) { return false; } }
  function remove(key) { try { w.localStorage.removeItem(prefix + key); } catch (_) {} }
  function path(value) {
    try {
      var p = new URL(value, location.href).pathname;
      // Sensitive account/order endpoints are deliberately grouped without identifiers.
      if (/\/(order-received|order-pay|view-order|lost-password|reset-password)\//i.test(p)) return '/[private]/';
      return p.slice(0,512);
    } catch (_) { return '/'; }
  }
  function source() {
    var url = new URL(location.href), utm = {}, click = {}, types = [], ref = null;
    ['source','medium','campaign','content','term'].forEach(function (key) {
      var v = url.searchParams.get('utm_' + key);
      if (v && !/[@<>\r\n]/.test(v)) utm[key] = Array.from(v).slice(0,128).join('');
    });
    ['gclid','gbraid','wbraid','fbclid','msclkid','ttclid','twclid'].forEach(function (key) {
      var v = url.searchParams.get(key); if (v && /^[A-Za-z0-9_.~-]{1,200}$/.test(v)) { types.push(key); if (consent && consent.advertising === 'granted') click[key] = v; }
    });
    try { var r = new URL(d.referrer); if (r.origin !== location.origin) ref = r.hostname.toLowerCase(); } catch (_) {}
    return {utm:utm, click_ids:click, click_id_types:types, referrer_domain:ref};
  }
  function normalize(input) {
    input = input || {};
    var out = {source:String(input.source || 'unavailable').slice(0,64),effective_at:now(),policy_version:c.policy_version,epoch:Math.max(0,Number(input.epoch) || Date.now()),gpc:navigator.globalPrivacyControl === true};
    ['analytics','advertising','email_marketing','sms_marketing'].forEach(function (k) { out[k] = states.includes(input[k]) ? input[k] : 'unknown'; });
    if (out.gpc) out.advertising = 'denied';
    return out;
  }
  function allowed() { return !stopped && consent && consent.analytics === 'granted'; }
  function context() {
    if (!allowed() || !session || !persistent) return;
    var ctx = {site_id:c.site_id,visitor_id:visitor,session_id:session.id,entry:session.entry,checkout_attempt_id:attempt,updated_at:Math.floor(Date.now()/1000),consent:consent};
    var body = JSON.stringify(ctx);
    if (bytes(encodeURIComponent(body)) > 3700) { ctx.entry = {path:session.entry.path,source_evidence:{utm:{},click_ids:{},click_id_types:[],referrer_domain:null}}; body = JSON.stringify(ctx); }
    cookie('_mgln_v3_context', body, 1800);
  }
  function ensureSession() {
    if (!allowed()) return;
    var prior=session ? session.id : null;
    if (persistent && getCookie('_mgln_v3_visitor') !== visitor) { visitor=uuid(); session=null; queue=[]; remove('session'); remove('queue'); cookie('_mgln_v3_visitor',visitor,15552000); }
    var saved = persistent ? read('session') : session;
    if (!saved || saved.visitor !== visitor || Date.now() - saved.last >= 1800000 || saved.last > Date.now()+300000) {
      saved = {id:uuid(),visitor:visitor,started:now(),last:Date.now(),entry:{path:path(location.href),entry_type:c.entry_type,source_evidence:source()},aliases:[]};
    }
    if (consent.advertising !== 'granted' && saved.entry && saved.entry.source_evidence) saved.entry.source_evidence.click_ids={};
    saved.last = Date.now(); session = saved;
    if (persistent) write('session', saved);
    context();
    return prior !== session.id;
  }
  function pendingAllowed(e) {
    if (!e || typeof e !== 'object' || e.site_id!==c.site_id || e.installation_id!==c.installation_id || e.environment!==c.environment || e.producer!=='browser' || e.visitor_id!==visitor || !e.consent || e.consent.analytics!=='granted') return false;
    // Keep immutable event bodies: discard evidence whose captured permissions exceed current consent.
    if (['advertising','email_marketing','sms_marketing'].some(function (purpose) { return e.consent[purpose]==='granted' && consent[purpose]!=='granted'; })) return false;
    var age=Date.now()-Date.parse(e.captured_at);
    return typeof e.event_id==='string' && /^[a-f0-9-]{36}$/.test(e.event_id) && Number.isFinite(age) && age>=-300000 && age<=86400000;
  }
  function bounded(items) {
    items=(Array.isArray(items)?items:[]).filter(pendingAllowed).slice(-100);
    while (bytes(JSON.stringify(items))>65536) items.shift();
    return items;
  }
  function persistQueue(acknowledged) {
    var ack=acknowledged || new Set(), additions=queue.filter(function (e) { return owned.has(e.event_id); });
    var epoch=consent ? consent.epoch : null, owner=visitor;
    function merge() {
      if (!allowed() || consent.epoch!==epoch || visitor!==owner) return;
      if (!persistent) { queue=bounded(queue.filter(function (e) { return !ack.has(e.event_id); })); return; }
      var stored=read('queue'), byId=new Map();
      (Array.isArray(stored)?stored:[]).concat(additions).forEach(function (e) { if (pendingAllowed(e) && !ack.has(e.event_id)) byId.set(e.event_id,e); });
      queue=bounded(Array.from(byId.values())); write('queue',queue);
    }
    queueWrites=queueWrites.then(function () { return persistent && navigator.locks ? navigator.locks.request(prefix+'queue',merge) : merge(); }).catch(function () {});
    return queueWrites;
  }
  function envelope(type, payload) {
    return {schema_version:'3.0.0',plugin_version:c.plugin_version,event_id:uuid(),event_type:type,site_id:c.site_id,installation_id:c.installation_id,environment:c.environment,producer:'browser',evidence_class:'observation',occurred_at:now(),captured_at:now(),visitor_id:visitor,session_id:session ? session.id : null,cart_id:null,entity:null,origin_system:'browser',operation_id:null,causation_id:null,sequence:++seq,consent:Object.assign({},consent),payload:payload};
  }
  function record(type, payload) {
    if (!allowed()) return;
    var changed=ensureSession();
    if (changed && started && type !== 'page_viewed' && type !== 'product_viewed') page();
    var event = envelope(type, payload);
    if (bytes(JSON.stringify(event)) > 4096 && (type==='page_viewed' || type==='product_viewed')) {
      // Fit optional source evidence before assigning immutable transport bytes.
      // Never truncate a click identifier into a different identifier.
      event.payload=JSON.parse(JSON.stringify(payload));
      var sources=[event.payload.source_evidence,event.payload.entry && event.payload.entry.source_evidence].filter(Boolean);
      [64,32].forEach(function (limit) {
        if (bytes(JSON.stringify(event))<=4096) return;
        sources.forEach(function (e) { Object.keys(e.utm || {}).forEach(function (k) { e.utm[k]=Array.from(e.utm[k]).slice(0,limit).join(''); }); });
      });
      if (bytes(JSON.stringify(event))>4096 && sources.length===2) {
        Object.keys(sources[0].click_ids || {}).forEach(function (k) { if (sources[0].click_ids[k]===sources[1].click_ids[k]) delete sources[0].click_ids[k]; });
      }
      sources.forEach(function (e) {
        Object.keys(e.click_ids || {}).reverse().forEach(function (k) { if (bytes(JSON.stringify(event))>4096) { delete e.click_ids[k]; } });
      });
    }
    if (bytes(JSON.stringify(event)) > 4096) return;
    owned.add(event.event_id); queue.push(event); persistQueue();
    if (!timer) timer = setTimeout(function () { timer=null; flush(false); }, 1000);
  }
  function page() {
    ensureSession(); if (!session) return;
    var p = {navigation_id:navigation,path:path(location.href),page_id:c.page_id,entry_type:c.entry_type,classification_version:c.classification_version,storefront:c.storefront,served_version:c.served_version,product_id:c.product_id,variation_id:null,source_evidence:source(),entry:session.entry,continuity:persistent ? 'shared':'page_local',session_started_at:session.started,session_aliases:session.aliases || [],stream_id:stream};
    record('page_viewed', p); if (c.product_id) record('product_viewed', Object.assign({},p));
  }
  async function hash(value) {
    if (!w.crypto.subtle) return null;
    var digest = await w.crypto.subtle.digest('SHA-256', new TextEncoder().encode(JSON.stringify(value)));
    return Array.from(new Uint8Array(digest), function (v) { return v.toString(16).padStart(2,'0'); }).join('');
  }
  async function flush(closing) {
    if (sending || !allowed() || !queue.length || attempts >= 5) return;
    await persistQueue(); if (sending || !allowed() || attempts >= 5) return; var batch = [], size = 13;
    queue.some(function (e) { var n = bytes(JSON.stringify(e))+1; if (batch.length === 10 || size+n > 16384) return true; batch.push(e); size+=n; return false; });
    if (!batch.length) return;
    var body = JSON.stringify({events:batch});
    // text/plain, no credentials/custom headers: simple CORS request; response still needs ACAO.
    if (closing && navigator.sendBeacon) { navigator.sendBeacon(c.endpoint, new Blob([body], {type:'text/plain;charset=UTF-8'})); return; }
    sending = true; var epoch = consent.epoch; attempts++;
    var controller=typeof AbortController==='function' ? new AbortController() : null; inFlight=controller;
    var deadline=setTimeout(function () { if (controller) controller.abort(); },8000);
    try {
      var response = await fetch(c.endpoint, {method:'POST',body:body,headers:{'Content-Type':'text/plain;charset=UTF-8'},credentials:'omit',mode:'cors',redirect:'error',keepalive:true,...(controller ? {signal:controller.signal} : {})});
      if (![200,202,207].includes(response.status)) throw new Error('intake');
      var result = await response.json(), accepted = new Set();
      for (var e of batch) {
        var r = (Array.isArray(result.results) ? result.results : []).find(function (x) { return x.event_id === e.event_id; });
        if (r && ['accepted','duplicate'].includes(r.status) && typeof r.receipt_id==='string' && r.receipt_id && typeof r.received_at==='string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(r.received_at) && Number.isFinite(Date.parse(r.received_at)) && r.body_hash === await hash(e)) accepted.add(e.event_id);
      }
      if (allowed() && consent.epoch === epoch) { queue = queue.filter(function (e) { return !accepted.has(e.event_id); }); accepted.forEach(function (id) { owned.delete(id); }); await persistQueue(accepted); if (accepted.size) attempts=0; }
    } catch (_) { /* A missing acknowledgement remains pending, including a failed response read. */ }
    finally {
      clearTimeout(deadline); inFlight=null; sending=false;
      if (allowed() && queue.length && attempts < 5 && !timer) timer=setTimeout(function () { timer=null; flush(false); }, Math.min(60000, Math.max(1000, 1000*Math.pow(2,attempts))));
    }
  }
  function purge() {
    queue=[]; owned.clear(); clearTimeout(timer); timer=null; remove('queue'); remove('session');
    cookie('_mgln_v3_visitor','',0); cookie('_mgln_v3_context','',0); visitor=null; session=null; persistent=false; started=false; attempt=null;
  }
  function updateConsent(input, broadcast) {
    if (w.Magellan && typeof w.Magellan.setConsent==='function') w.Magellan.setConsent(input,false);
    var next=normalize(input), previous=consent;
    var same=previous && ['analytics','advertising','email_marketing','sms_marketing','gpc'].every(function (k) { return previous[k] === next[k]; });
    if (same) return initialized;
    if (inFlight) inFlight.abort();
    consent=next; attempts=0;
    if (channel && broadcast !== false) channel.postMessage(next);
    if (previous && visitor) {
      // Privacy control notice is separate from optional analytics; no browsing payload on revocation.
      var notice=envelope('consent_changed',{previous_epoch:previous.epoch});
      fetch(c.endpoint,{method:'POST',body:JSON.stringify({events:[notice]}),headers:{'Content-Type':'text/plain;charset=UTF-8'},mode:'cors',credentials:'omit',redirect:'error',keepalive:true}).catch(function () {});
      queue=[]; remove('queue');
    }
    if (!allowed()) { if (previous || next.analytics === 'denied') purge(); else cookie('_mgln_v3_context','',0); return initialized; }
    function start() {
      if (!allowed()) return;
      var existing=getCookie('_mgln_v3_visitor');
      if (!existing || !/^[a-f0-9-]{36}$/.test(existing)) { existing=uuid(); remove('session'); remove('queue'); }
      visitor=existing; cookie('_mgln_v3_visitor',visitor,15552000);
      persistent=getCookie('_mgln_v3_visitor') === visitor && write('probe',true); remove('probe');
      if (!persistent) { cookie('_mgln_v3_visitor','',0); cookie('_mgln_v3_context','',0); }
      queue=persistent ? bounded(read('queue')) : [];
      ensureSession(); persistQueue();
      if (!started) { started=true; page(); w.dispatchEvent(new CustomEvent('magellan:ready')); }
    }
    initialized = initialized.then(function () { return navigator.locks ? navigator.locks.request(prefix+'identity',start) : start(); });
    return initialized;
  }
  function checkout(stage, category, adapter) {
    if (!allowed()) return;
    var stages=['started','submitted','shipping_options_shown','payment_method_selected','validation','payment','unknown'];
    if (!stages.includes(stage)) return;
    if (!attempt) attempt=uuid(); context();
    var p={page:path(location.href),checkout_attempt_id:attempt,adapter:adapter || 'registered',stage:stage};
    if (category) { if (!['coupon_rejected','validation_failed','payment_failed','technical_error','unknown'].includes(category)) return; p.category=category; }
    record(category ? 'checkout_error_observed' : (stage==='started' ? 'checkout_started' : 'checkout_stage_observed'),p);
  }
  w.MagellanV3={setConsent:updateConsent,getConsent:function () { return Object.assign({},consent); },whenReady:function (fn) { initialized.then(function () { if (allowed() && started) fn(); }); },registerInteraction:function (id) { if (/^[a-zA-Z0-9_.:-]{1,128}$/.test(id)) registered.add(id); },interaction:function (id, action) { if (registered.has(id) && /^[a-zA-Z0-9_.:-]{1,128}$/.test(action)) record('interaction_observed',{page:path(location.href),component_id:id,action_id:action,served_version:c.served_version}); },checkout:checkout,flush:function () { return flush(false); },stop:function () { stopped=true; purge(); }};
  d.addEventListener('click',function (event) { var el=event.target.closest && event.target.closest('[data-magellan-component]'); if (el) w.MagellanV3.interaction(el.getAttribute('data-magellan-component'),el.getAttribute('data-magellan-action') || 'click'); });
  if (channel) channel.onmessage=function (event) { updateConsent(event.data,false); };
  w.addEventListener('magellan:consent',function (event) { updateConsent(event.detail); });
  function cookiebot() { var b=w.Cookiebot; if (!b || !b.hasResponse) return; updateConsent({analytics:b.consent.statistics ? 'granted':'denied',advertising:b.consent.marketing ? 'granted':'denied',source:'cookiebot'}); }
  ['CookiebotOnConsentReady','CookiebotOnAccept','CookiebotOnDecline'].forEach(function (name) { w.addEventListener(name,cookiebot); });
  w.addEventListener('storage',function (event) {
    if (event.key === prefix+'session' && allowed() && session) {
      var other=read('session');
      if (other && other.visitor===visitor && other.id!==session.id && Math.abs(other.last-session.last)<1800000) { other.aliases=Array.from(new Set((other.aliases || []).concat(session.id))).slice(-10); session=other; write('session',other); context(); }
    }
  });
  w.addEventListener('online',function () { attempts=0; flush(false); });
  d.addEventListener('visibilitychange',function () { if (d.visibilityState==='hidden') flush(true); });
  w.addEventListener('pagehide',function () { flush(true); });
  updateConsent(w.MagellanConsent || {}); cookiebot();
})(window, document);

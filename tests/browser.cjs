/* Run in an isolated browser context. Collector responses are protocol fixtures. */
const { chromium } = require(process.env.PLAYWRIGHT_PATH || 'playwright');
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const results = [], captured = [];
const legacyPilot = process.env.MAGELLAN_LEGACY_PILOT === '1';
const check = (name, value) => { assert.ok(value, name); results.push({test:name,pass:true}); };
const grant = {analytics:'granted',advertising:'granted',email_marketing:'unknown',sms_marketing:'denied',source:'test_cmp'};
const config = {site_id:'site_fixture',installation_id:'install_fixture',environment:'test',endpoint:'https://collector.example/events',origin:'https://shop.example.co.th',plugin_version:'3.0.0-alpha.5',policy_version:'1',page_id:'42',product_id:'42',entry_type:'product',classification_version:'1',storefront:'th-TH',served_version:null};
(async () => {
  const browser = await chromium.launch({headless:true,...(process.env.CHROME_EXECUTABLE ? {executablePath:process.env.CHROME_EXECUTABLE} : {})});
  try {
    const context = await browser.newContext(); let mode = 'accepted', initialConsent = null;
    await context.route('**/*',async route => {
      const request=route.request(); const url=request.url();
      if (url.startsWith('https://collector.example/')) {
        if (request.method()==='OPTIONS') return route.fulfill({status:204,headers:{'Access-Control-Allow-Origin':config.origin,'Access-Control-Allow-Headers':'content-type'}});
        const data=request.postDataJSON(); captured.push(...data.events);
        return route.fulfill({status:202,headers:{'Access-Control-Allow-Origin':config.origin},contentType:'application/json',body:JSON.stringify({results:data.events.map(e=>({event_id:e.event_id,body_hash:mode==='wrong_hash' ? '0'.repeat(64):crypto.createHash('sha256').update(JSON.stringify(e)).digest('hex'),status:'accepted',receipt_id:'receipt_'+e.event_id,received_at:new Date().toISOString(),code:null}))})});
      }
      if (url.endsWith('/pixel.js')) return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(root+'/magellan-for-woocommerce/assets/magellan-v3-pixel.js','utf8')});
      if (url.endsWith('/legacy.js')) return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(root+'/magellan-for-woocommerce/assets/magellan-pixel.js','utf8')});
      if (url.endsWith('/checkout.js')) return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(root+'/magellan-for-woocommerce/assets/magellan-v3-checkout.js','utf8')});
      return route.fulfill({contentType:'text/html',body:'<!doctype html><html><head><title>Magellan isolated fixture</title><meta name="magellan-account" content="fixture"></head><body><button data-magellan-component="hero" data-magellan-action="shop">Shop</button><form class="checkout"></form><script>window.MagellanV3Config='+JSON.stringify(config)+';'+(initialConsent?'window.MagellanConsent='+JSON.stringify(initialConsent)+';':'')+'</script>'+(legacyPilot?'<script src="/legacy.js"></script>':'')+'<script src="/pixel.js"></script><script src="/checkout.js"></script></body></html>'});
    });
    const page=await context.newPage(); const errors=[]; page.on('pageerror',e=>errors.push(e.message));
    await page.goto(config.origin+'/flowers/?utm_source=google&utm_medium=organic&gclid=synthetic_click&email=private@example.com');
    check('unknown consent makes no collector request',captured.length===0);
    if (legacyPilot) check('unknown consent creates no legacy identifiers',!(await context.cookies()).some(c=>['_mgln','_mgln_cart_token'].includes(c.name)));
    check('unknown consent creates no visitor cookie',!(await context.cookies()).some(c=>c.name==='_mgln_v3_visitor_site_fixture'));
    await page.evaluate(g=>window.MagellanV3.setConsent(g),grant);
    await page.evaluate(()=>window.MagellanV3.flush());
    check('consented page/product/checkout events captured',['page_viewed','product_viewed','checkout_started'].every(t=>captured.some(e=>e.event_type===t)));
    if (legacyPilot) { await page.evaluate(()=>delete window.MagellanConsent);await page.addScriptTag({url:config.origin+'/legacy.js'});check('deferred legacy pixel inherits current v3 consent',await page.evaluate(()=>window.Magellan.consent().analytics==='granted')); }
    const first=captured.find(e=>e.event_type==='page_viewed');
    check('query strings excluded from page identity',first.payload.path==='/flowers/' && !JSON.stringify(first).includes('private@example.com'));
    check('acknowledged browser queue cleared',await page.evaluate(()=>JSON.parse(localStorage.getItem('magellan:v3:site_fixture:queue')).length===0));
    const cookies=await context.cookies(); const visitor=cookies.find(c=>c.name==='_mgln_v3_visitor_site_fixture');
    check('host-only secure cookie on co.th',visitor.domain==='shop.example.co.th'&&visitor.secure&&visitor.sameSite==='Lax');
    await context.addInitScript(g=>window.MagellanConsent=g,grant);
    await page.goto(config.origin+'/second/?utm_source=facebook&utm_medium=cpc');
    await page.evaluate(()=>new Promise(resolve=>window.MagellanV3.whenReady(resolve)));
    await page.evaluate(()=>window.MagellanV3.flush());
    const second=captured.filter(e=>e.event_type==='page_viewed').at(-1);
    check('navigation retains visitor and session',second.visitor_id===first.visitor_id&&second.session_id===first.session_id);
    check('entry origin stays frozen, later source retained',second.payload.entry.source_evidence.utm.source==='google'&&second.payload.source_evidence.utm.source==='facebook');
    const other=await context.newPage(); await other.goto(config.origin+'/other');
    await other.evaluate(()=>new Promise(resolve=>window.MagellanV3.whenReady(resolve)));
    await other.evaluate(()=>window.MagellanV3.flush());
    check('second tab shares session',captured.filter(e=>e.event_type==='page_viewed').at(-1).session_id===first.session_id);
    mode='wrong_hash';
    await Promise.all([page.evaluate(()=>{window.MagellanV3.registerInteraction('tab-one');window.MagellanV3.interaction('tab-one','click');}),other.evaluate(()=>{window.MagellanV3.registerInteraction('tab-two');window.MagellanV3.interaction('tab-two','click');})]);
    await Promise.all([page.evaluate(()=>window.MagellanV3.flush()),other.evaluate(()=>window.MagellanV3.flush())]);
    check('concurrent tabs retain both unacknowledged events',await page.evaluate(()=>{const q=JSON.parse(localStorage.getItem('magellan:v3:site_fixture:queue'));return ['tab-one','tab-two'].every(id=>q.some(e=>e.payload.component_id===id));}));
    mode='accepted'; await page.evaluate(()=>window.MagellanV3.flush()); await other.evaluate(()=>window.MagellanV3.flush());
    const n=captured.length;
    await page.evaluate(()=>{window.MagellanV3.interaction('unregistered','click');window.MagellanV3.registerInteraction('hero');window.MagellanV3.interaction('hero','shop');});
    await page.evaluate(()=>window.MagellanV3.flush());
    check('only registered interactions emitted',captured.slice(n).filter(e=>e.event_type==='interaction_observed').length===1);
    mode='wrong_hash';
    await page.evaluate(()=>window.MagellanV3.interaction('hero','shop')); await page.evaluate(()=>window.MagellanV3.flush());
    check('wrong receipt hash retains browser event',await page.evaluate(()=>JSON.parse(localStorage.getItem('magellan:v3:site_fixture:queue')).length>0));
    const legacyCookies = (await context.cookies()).filter(c=>['_mgln','_mgln_cart_token'].includes(c.name));
    const legacyIdentity = legacyPilot ? await page.evaluate(()=>window.Magellan.getCookie()) : null;
    await page.evaluate(()=>window.MagellanV3.setConsent({analytics:'denied',source:'test_cmp'}));
    await other.waitForFunction(()=>localStorage.getItem('magellan:v3:site_fixture:queue')===null);
    const atRevoke=captured.length;
    await other.evaluate(()=>{window.MagellanV3.registerInteraction('hero');window.MagellanV3.interaction('hero','shop');});
    await other.evaluate(()=>window.MagellanV3.flush());
    check('withdrawal stops collection in the second tab',!captured.slice(atRevoke).some(e=>e.event_type==='interaction_observed'));
    await other.close();
    if (legacyPilot) {
      const afterCookies = await context.cookies();
      check('legacy pixel uses host-only identity cookie on co.th',legacyCookies.some(c=>c.name==='_mgln'&&c.domain==='shop.example.co.th'&&c.secure));
      check('withdrawal removes both legacy identifiers',!afterCookies.some(c=>['_mgln','_mgln_cart_token'].includes(c.name)) && Object.keys(await page.evaluate(()=>window.Magellan.getCookie())).length===0);
    }
    check('withdrawal clears identifiers and persisted queue',!(await context.cookies()).some(c=>c.name==='_mgln_v3_visitor_site_fixture')&&await page.evaluate(()=>localStorage.getItem('magellan:v3:site_fixture:queue')===null));
    mode='accepted'; await page.evaluate(g=>window.MagellanV3.setConsent(g),grant); await page.evaluate(()=>window.MagellanV3.flush());
    await page.waitForFunction(()=>JSON.parse(localStorage.getItem('magellan:v3:site_fixture:queue') || '[]').length===0);
    check('new consent does not resurrect erased visitor',captured.filter(e=>e.event_type==='page_viewed').at(-1).visitor_id!==first.visitor_id);
    await page.evaluate(g=>{Object.defineProperty(navigator,'globalPrivacyControl',{value:true,configurable:true});return window.MagellanV3.setConsent(g);},grant);
    await page.evaluate(()=>{window.MagellanV3.registerInteraction('hero');window.MagellanV3.interaction('hero','shop');}); await page.evaluate(()=>window.MagellanV3.flush());
    check('GPC prevents advertising grant',captured.at(-1).consent.gpc===true&&captured.at(-1).consent.advertising==='denied');
    check('advertising withdrawal removes saved raw click identifiers',await page.evaluate(()=>Object.keys(JSON.parse(localStorage.getItem('magellan:v3:site_fixture:session')).entry.source_evidence.click_ids).length===0));
    const oldVisitor=captured.filter(e=>e.event_type==='page_viewed').at(-1).visitor_id;
    await context.clearCookies();
    await page.evaluate(()=>window.MagellanV3.interaction('hero','shop')); await page.evaluate(()=>window.MagellanV3.flush());
    check('cleared cookies cannot resurrect the old visitor',captured.at(-1).visitor_id!==oldVisitor);
    check('no browser exceptions',errors.length===0);
    const blocked=await context.newPage();
    await blocked.addInitScript(()=>{Object.defineProperty(window,'localStorage',{get(){throw new Error('blocked')}});});
    await blocked.goto(config.origin+'/blocked');
    await blocked.evaluate(()=>new Promise(resolve=>window.MagellanV3.whenReady(resolve)));
    await blocked.evaluate(()=>window.MagellanV3.flush());
    check('blocked storage reports page-local continuity',captured.filter(e=>e.event_type==='page_viewed').at(-1).payload.continuity==='page_local');
    await blocked.close();
    mode='wrong_hash'; initialConsent=grant;
    await page.goto(config.origin+'/retry/?gclid=queued_click');
    await page.evaluate(()=>new Promise(resolve=>window.MagellanV3.whenReady(resolve))); await page.evaluate(()=>window.MagellanV3.flush());
    check('failed browser receipt keeps raw click evidence while consent is granted',await page.evaluate(()=>localStorage.getItem('magellan:v3:site_fixture:queue').includes('queued_click')));
    initialConsent={...grant,advertising:'denied'}; mode='accepted'; const beforeDenied=captured.length;
    await page.goto(config.origin+'/next-consent/');
    await page.evaluate(()=>new Promise(resolve=>window.MagellanV3.whenReady(resolve))); await page.evaluate(()=>window.MagellanV3.flush());
    check('new page cannot resend old queued click IDs after advertising denial',captured.length>beforeDenied&&!JSON.stringify(captured.slice(beforeDenied)).includes('queued_click'));
    mode='wrong_hash'; await page.evaluate(()=>window.MagellanV3.checkout('submitted')); await page.evaluate(()=>window.MagellanV3.flush());
    config.installation_id='replacement_install'; mode='accepted'; const beforeReplace=captured.length;
    await page.goto(config.origin+'/replacement/'); await page.evaluate(()=>new Promise(resolve=>window.MagellanV3.whenReady(resolve))); await page.evaluate(()=>window.MagellanV3.flush());
    check('replacement installation discards the old pending queue',captured.length>beforeReplace&&captured.slice(beforeReplace).every(e=>e.installation_id==='replacement_install'));
    for (const invalid of [{old_format:true},[null,7]]) {
      await page.evaluate(value=>localStorage.setItem('magellan:v3:site_fixture:queue',JSON.stringify(value)),invalid);
      const beforeRepair=captured.length; await page.reload(); await page.evaluate(()=>new Promise(resolve=>window.MagellanV3.whenReady(resolve))); await page.evaluate(()=>window.MagellanV3.flush());
      check((Array.isArray(invalid)?'null entries':'object queue')+' recover in a real browser',captured.slice(beforeRepair).some(e=>e.event_type==='page_viewed'));
    }
    initialConsent=grant;const large=new URLSearchParams();for(const key of ['source','medium','campaign','content','term'])large.set('utm_'+key,'ด'.repeat(128));for(const key of ['gclid','gbraid','wbraid','fbclid','msclkid','ttclid','twclid'])large.set(key,'a'.repeat(200));
    const beforeLarge=captured.length;await page.goto(config.origin+'/large-landing?'+large);await page.evaluate(()=>new Promise(resolve=>window.MagellanV3.whenReady(resolve)));await page.evaluate(()=>window.MagellanV3.flush());
    for(let wait=0;wait<50&&!captured.slice(beforeLarge).some(e=>e.event_type==='page_viewed'&&e.payload.path==='/large-landing');wait++) await new Promise(resolve=>setTimeout(resolve,100));
    const largeEvent=captured.slice(beforeLarge).find(e=>e.event_type==='page_viewed'&&e.payload.path==='/large-landing');check('large paid landing survives in real Chrome within event budget',!!largeEvent&&Buffer.byteLength(JSON.stringify(largeEvent))<=4096&&largeEvent.payload.source_evidence.click_id_types.length===7);
    check('browser resilience scenarios produce no exceptions',errors.length===0);
    const prefix=legacyPilot?'pilot-browser':'browser';
    fs.writeFileSync(root+'/tests/'+prefix+'-events.json',JSON.stringify(captured,null,2)+'\n');
    fs.writeFileSync(root+'/tests/'+prefix+'-results.json',JSON.stringify({browser:browser.version(),transport:'intercepted collector responses; real isolated Chrome context',legacy_pixel_loaded:legacyPilot,results},null,2)+'\n');
    console.log(results.length+' browser checks passed');
  } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});

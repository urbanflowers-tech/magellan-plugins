/* Real isolated Chrome; all storefront and collector requests are synthetic/intercepted. */
const {chromium}=require(process.env.PLAYWRIGHT_PATH||'playwright');
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..'),checks=[];
const check=(name,condition)=>{assert.ok(condition,name);checks.push(name);console.log('PASS '+name);};
const config={site_id:'policy_site',installation_id:'policy_install',environment:'test',origin:'https://shop.example',endpoint:'https://collector.example/events',plugin_version:'3.0.0-alpha.6',policy_version:'1',analytics_policy:'store_enabled',page_id:'42',product_id:null,entry_type:'content',classification_version:'1',storefront:'en',served_version:null};
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_EXECUTABLE});
 try {
  const context=await browser.newContext(),captured=[],errors=[];
  await context.route('**/*',async route=>{
   const request=route.request(),url=request.url();
   if(url.startsWith('https://collector.example/')){
    const events=request.postDataJSON().events;captured.push(...events);
    return route.fulfill({status:202,headers:{'Access-Control-Allow-Origin':config.origin},contentType:'application/json',body:JSON.stringify({results:events.map(e=>({event_id:e.event_id,status:'accepted',receipt_id:'receipt_'+e.event_id,received_at:new Date().toISOString(),body_hash:crypto.createHash('sha256').update(JSON.stringify(e)).digest('hex')}))})});
   }
   if(url.endsWith('/pixel.js'))return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(root+'/magellan-for-woocommerce/assets/magellan-v3-pixel.js','utf8')});
   if(url.endsWith('/legacy.js'))return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(root+'/magellan-for-woocommerce/assets/magellan-pixel.js','utf8')});
   return route.fulfill({contentType:'text/html',body:'<!doctype html><html><head><meta name="magellan-account" content="fixture"></head><body><script>window.MagellanV3Config='+JSON.stringify(config)+'</script><script src="/legacy.js"></script><script src="/pixel.js"></script></body></html>'});
  });
  const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
  const visit=async path=>{await page.goto(config.origin+path);await page.evaluate(()=>new Promise(resolve=>window.MagellanV3.whenReady(resolve)));await page.evaluate(()=>window.MagellanV3.flush());};
  await visit('/landing?utm_source=google&utm_medium=organic&gclid=disallowed_value');
  const first=captured.find(e=>e.event_type==='page_viewed');
  check('collects without a prompt or visitor grant',first?.consent.analytics==='not_applicable'&&first.consent.source==='store_policy');
  check('retains useful channel and landing evidence',first.payload.entry.path==='/landing'&&first.payload.entry.source_evidence.utm.medium==='organic');
  check('does not collect advertising identifiers',first.consent.advertising==='denied'&&!JSON.stringify(first).includes('disallowed_value'));
  check('store policy does not enable the legacy pixel',!(await context.cookies()).some(c=>['_mgln','_mgln_cart_token'].includes(c.name)));
  await visit('/next');
  const next=captured.find(e=>e.event_type==='page_viewed'&&e.payload.path==='/next');
  check('same visitor and session survive navigation',next?.visitor_id===first.visitor_id&&next?.session_id===first.session_id);
  const cookie=(await context.cookies()).find(c=>c.name==='_mgln_v3_context_policy_site');
  const saved=JSON.parse(decodeURIComponent(cookie.value));
  check('checkout cookie preserves store-policy provenance',saved.consent.analytics==='not_applicable'&&saved.consent.source==='store_policy'&&saved.session_id===first.session_id);
  await page.evaluate(()=>window.MagellanV3.setConsent({analytics:'denied',source:'visitor_optout'}));
  await page.goto(config.origin+'/after-optout');await page.evaluate(()=>window.MagellanV3.flush());
  check('opt-out persists across navigation',!(await context.cookies()).some(c=>c.name==='_mgln_v3_visitor_policy_site')&&(await context.cookies()).some(c=>c.name==='_mgln_v3_optout_policy_site')&&!captured.some(e=>e.payload.path==='/after-optout'));
  check('withdrawal reports original identifiers for erasure',captured.some(e=>e.event_type==='consent_changed'&&e.visitor_id===first.visitor_id&&e.consent.analytics==='denied'));
  await context.clearCookies();await page.addInitScript(()=>Object.defineProperty(navigator,'globalPrivacyControl',{value:true}));
  await page.goto(config.origin+'/gpc');await page.evaluate(()=>window.MagellanV3.flush());
  check('GPC blocks automatic analytics in Chrome',!captured.some(e=>e.payload.path==='/gpc')&&!(await context.cookies()).some(c=>c.name==='_mgln_v3_visitor_policy_site'));
  check('no browser exceptions',errors.length===0);
  if(process.env.MAGELLAN_POLICY_FIXTURE) fs.writeFileSync(process.env.MAGELLAN_POLICY_FIXTURE,JSON.stringify(first,null,2)+'\n');
  console.log(checks.length+' isolated Chrome store-policy checks passed');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

/* Deterministic execution of the shipped pixel in browser-API fixtures; no real network or shopper data. */
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..'),source=fs.readFileSync(root+'/magellan-for-woocommerce/assets/magellan-v3-pixel.js','utf8');
const results=[];
const id=()=>crypto.randomUUID();
const config={site_id:'resilience_site',installation_id:'current_install',environment:'test',endpoint:'https://collector.example/events',origin:'https://shop.example',plugin_version:'3.0.0-alpha.4',policy_version:'1',page_id:'42',product_id:null,entry_type:'content',classification_version:'1',storefront:'en',served_version:null};
const grant={analytics:'granted',advertising:'granted',email_marketing:'unknown',sms_marketing:'unknown',source:'fixture_cmp',epoch:10};
function state(){return {storage:new Map(),cookies:new Map(),locks:new Map()};}
function tab(shared,options={}){
 const sent=[],requests=[],beacons=[],timers=new Map(),listeners={},docListeners={};let seq=0;
 const c={...config,...options.config};
 const document={referrer:'',visibilityState:'visible',addEventListener:(type,fn)=>{(docListeners[type]??=[]).push(fn);}};
 Object.defineProperty(document,'cookie',{get(){return [...shared.cookies].map(([k,v])=>k+'='+v).join('; ');},set(value){const pair=value.split(';')[0],at=pair.indexOf('='),name=pair.slice(0,at);if(/Max-Age=0(?:;|$)/.test(value))shared.cookies.delete(name);else shared.cookies.set(name,pair.slice(at+1));}});
 const navigator={globalPrivacyControl:!!options.gpc,locks:{request(name,fn){const task=(shared.locks.get(name)||Promise.resolve()).then(fn);shared.locks.set(name,task.catch(()=>{}));return task;}}};
 navigator.sendBeacon=(url,body)=>{beacons.push({url,body});return true;};
 const localStorage={getItem:k=>shared.storage.get(k)??null,setItem:(k,v)=>shared.storage.set(k,String(v)),removeItem:k=>shared.storage.delete(k)};
 const window={MagellanV3Config:c,MagellanConsent:options.consent||grant,crypto:crypto.webcrypto,localStorage,addEventListener:(t,fn)=>{(listeners[t]??=[]).push(fn);},dispatchEvent:e=>{for(const fn of listeners[e.type]||[])fn(e);}};
 const fetch=async(url,opt)=>{const events=JSON.parse(opt.body).events;requests.push(opt);sent.push(...events);if(options.fail)throw new Error('simulated network failure');const receipts=events.map(e=>({event_id:e.event_id,body_hash:crypto.createHash('sha256').update(JSON.stringify(e)).digest('hex'),status:'accepted',receipt_id:'receipt_'+e.event_id,received_at:options.invalidReceiptDate?'invalid-time':new Date().toISOString()}));return {status:202,json:async()=>({results:options.receipts?options.receipts(receipts):receipts})};};
 const globals={window,document,navigator,location:new URL(options.url||c.origin+'/landing?utm_source=google&gclid=synthetic_click'),URL,TextEncoder,Uint8Array,Blob,AbortController,fetch,CustomEvent:class{constructor(type){this.type=type;}},setTimeout:(fn,ms)=>{timers.set(++seq,{fn,ms});return seq;},clearTimeout:key=>timers.delete(key),console};
 vm.runInNewContext(source,globals,{filename:'magellan-v3-pixel.js'});
 const api=window.MagellanV3;
 return {api,sent,requests,beacons,timers,shared,async ready(){await api.setConsent(options.consent||grant);await new Promise(r=>setImmediate(r));},async flush(){await api.flush();},async emit(type){window.dispatchEvent({type});await new Promise(r=>setImmediate(r));},stop(){api.stop();}};
}
const queue=s=>JSON.parse(s.storage.get('magellan:v3:resilience_site:queue')||'[]');
const tick=()=>new Promise(r=>setImmediate(r));
async function check(name,fn){try{await fn();results.push({test:name,pass:true});}catch(e){results.push({test:name,pass:false,error:e.message});}}
(async()=>{
 await check('ordinary accepted events leave no pending queue',async()=>{const s=state(),t=tab(s);await t.ready();await t.flush();assert.equal(JSON.parse(s.storage.get('magellan:v3:resilience_site:queue')).length,0);t.stop();});
 await check('failed delivery preserves event IDs and payloads for retry',async()=>{const s=state(),t=tab(s,{fail:true});await t.ready();await t.flush();const before=JSON.parse(s.storage.get('magellan:v3:resilience_site:queue'));await t.flush();assert.deepEqual(t.sent.slice(-before.length),before);t.stop();});
 await check('reloading with advertising denied cannot send retained raw click IDs',async()=>{
  const s=state(),old=tab(s,{fail:true});await old.ready();await old.flush();
  assert.ok(JSON.stringify(old.sent).includes('synthetic_click'));
  const fresh=tab(s,{consent:{...grant,advertising:'denied',epoch:20},url:config.origin+'/next'});await fresh.ready();await fresh.flush();
  assert.ok(!JSON.stringify(fresh.sent).includes('synthetic_click'),'old queued advertising identifier was transmitted after advertising denial');fresh.stop();old.stop();
 });
 await check('new installation never reimports the previous installation queue',async()=>{
  const s=state(),old=tab(s,{fail:true});await old.ready();await old.flush();
  const fresh=tab(s,{config:{installation_id:'replacement_install'}});await fresh.ready();await fresh.flush();
  assert.ok(fresh.sent.length>0);assert.ok(fresh.sent.every(e=>e.installation_id==='replacement_install'),'previous installation events were reimported while merging storage');fresh.stop();old.stop();
 });
 await check('malformed queue storage does not stop measurement startup',async()=>{
  const s=state();s.cookies.set('_mgln_v3_visitor_resilience_site',id());s.storage.set('magellan:v3:resilience_site:queue',JSON.stringify({old_format:true}));
  const t=tab(s);await t.ready();await t.flush();assert.ok(t.sent.some(e=>e.event_type==='page_viewed'));t.stop();
 });
 await check('null queue entries are discarded without stopping collection',async()=>{
  const s=state();s.cookies.set('_mgln_v3_visitor_resilience_site',id());s.storage.set('magellan:v3:resilience_site:queue',JSON.stringify([null,7]));
  const t=tab(s);await t.ready();await t.flush();assert.ok(t.sent.some(e=>e.event_type==='page_viewed'));t.stop();
 });
 await check('an invalid receipt timestamp cannot acknowledge delivery',async()=>{
  const s=state(),t=tab(s,{invalidReceiptDate:true});await t.ready();await t.flush();assert.ok(JSON.parse(s.storage.get('magellan:v3:resilience_site:queue')).length>0,'invalid receipt time discarded pending events');t.stop();
 });
 await check('unknown analytics consent sends no events or persistent identifier',async()=>{
  const s=state(),t=tab(s,{consent:{analytics:'unknown'}});await t.ready();await t.flush();assert.equal(t.sent.length,0);assert.equal(s.cookies.size,0);t.stop();
 });
 await check('denied analytics purges an existing pending queue and identifiers',async()=>{
  const s=state(),old=tab(s,{fail:true});await old.ready();await old.flush();const t=tab(s,{consent:{...grant,analytics:'denied',epoch:20}});await t.ready();await t.flush();assert.equal(t.sent.length,0);assert.equal(s.cookies.size,0);assert.ok(!s.storage.has('magellan:v3:resilience_site:queue'));old.stop();t.stop();
 });
 for (const [name,receipts] of [
  ['missing per-event receipt',()=>[]],
  ['different event ID',rs=>rs.map(r=>({...r,event_id:id()}))],
  ['different body hash',rs=>rs.map(r=>({...r,body_hash:'0'.repeat(64)}))],
  ['non-string receipt ID',rs=>rs.map(r=>({...r,receipt_id:123}))],
  ['date-only receipt time',rs=>rs.map(r=>({...r,received_at:'2026-10-09'}))],
  ['rejected event',rs=>rs.map(r=>({...r,status:'rejected'}))]
 ]) await check(name+' retains the original pending event',async()=>{const s=state(),t=tab(s,{receipts});await t.ready();const before=queue(s);await t.flush();assert.deepEqual(queue(s),before);t.stop();});
 await check('partial receipts only remove the acknowledged event',async()=>{const s=state(),t=tab(s,{receipts:rs=>rs.slice(0,1)});await t.ready();t.api.registerInteraction('hero');t.api.interaction('hero','click');await tick();const before=queue(s);assert.equal(before.length,2);await t.flush();assert.deepEqual(queue(s),before.slice(1));t.stop();});
 await check('failed sends stop after five attempts and preserve pending events',async()=>{const s=state(),t=tab(s,{fail:true});await t.ready();for(let i=0;i<9;i++)await t.flush();assert.equal(t.requests.length,5);assert.ok(queue(s).length>0);assert.ok([...t.timers.values()].every(timer=>timer.ms<=60000));t.stop();});
 await check('a page-close beacon never acknowledges delivery',async()=>{const s=state(),t=tab(s);await t.ready();const before=queue(s);await t.emit('pagehide');assert.equal(t.beacons.length,1);assert.deepEqual(queue(s),before);t.stop();});
 await check('queue and outgoing batches remain bounded during a burst',async()=>{const s=state(),t=tab(s,{fail:true});await t.ready();t.api.registerInteraction('hero');for(let i=0;i<200;i++)t.api.interaction('hero','click');await tick();const q=queue(s);assert.ok(q.length>0&&q.length<=100);assert.ok(Buffer.byteLength(JSON.stringify(q))<=65536);await t.flush();assert.ok(t.requests.every(r=>Buffer.byteLength(r.body)<=16384&&JSON.parse(r.body).events.length<=10));t.stop();});
 await check('expired and implausibly future queued evidence is discarded',async()=>{const s=state(),t=tab(s,{fail:true});await t.ready();const original=queue(s)[0];const expired={...original,event_id:id(),captured_at:new Date(Date.now()-86400001).toISOString()};const future={...original,event_id:id(),captured_at:new Date(Date.now()+600000).toISOString()};s.storage.set('magellan:v3:resilience_site:queue',JSON.stringify([expired,future,original]));await t.flush();assert.ok(t.sent.every(e=>e.event_id!==expired.event_id&&e.event_id!==future.event_id));t.stop();});
 await check('simultaneous flush calls create only one in-flight batch',async()=>{const s=state(),t=tab(s);await t.ready();await Promise.all([t.flush(),t.flush(),t.flush()]);assert.equal(t.requests.length,1);t.stop();});
 const data={scope:'Shipped pixel executed in deterministic browser-API fixtures; synthetic data and no network',results};fs.writeFileSync(root+'/tests/pixel-resilience-results.json',JSON.stringify(data,null,2)+'\n');
 for(const r of results)console.log((r.pass?'PASS ':'FAIL ')+r.test+(r.error?' — '+r.error:''));
 if(results.some(r=>!r.pass))process.exitCode=1;
})().catch(e=>{console.error(e);process.exitCode=1;});

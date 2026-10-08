'use strict';
// Framework-independent contract reference. This is not an HTTP endpoint or a database inbox.
const crypto = require('node:crypto');
function target(url) {
  const u=new URL(url,'https://contract.example');
  // RFC3986 percent encoding. '+' is literal, not application/x-www-form-urlencoded space.
  const encode=s=>encodeURIComponent(s).replace(/[!'()*]/g,c=>'%'+c.charCodeAt(0).toString(16).toUpperCase());
  const pairs=u.search.slice(1).split('&').filter(Boolean).map(p=>{const i=p.indexOf('=');return encode(decodeURIComponent(i<0?p:p.slice(0,i)))+'='+encode(decodeURIComponent(i<0?'':p.slice(i+1)));}).sort();
  return u.pathname+(pairs.length?'?'+pairs.join('&'):'');
}
function signature({method,url,installationId,timestamp,nonce,keyB64,body}) {
  const key=Buffer.from(keyB64,'base64'); if(key.length!==32) throw new Error('invalid_signing_key');
  const digest=crypto.createHash('sha256').update(body).digest('hex');
  return crypto.createHmac('sha256',key).update(['v3',method.toUpperCase(),target(url),installationId,String(timestamp),nonce,digest].join('\n')).digest('hex');
}
function verify(input, supplied, now=Math.floor(Date.now()/1000)) {
  if(!Number.isSafeInteger(input.timestamp)||Math.abs(now-input.timestamp)>300||!/^[0-9a-f]{64}$/.test(supplied)) return false;
  const expected=signature(input);
  return crypto.timingSafeEqual(Buffer.from(expected,'hex'),Buffer.from(supplied,'hex'));
}
// Hash before JSONB storage or any property normalization. Producers emit compact JSON,
// retain object/list distinction and use no JSON numeric values outside safe integer range.
function eventHash(event) { return crypto.createHash('sha256').update(JSON.stringify(event),'utf8').digest('hex'); }
module.exports={target,signature,verify,eventHash};

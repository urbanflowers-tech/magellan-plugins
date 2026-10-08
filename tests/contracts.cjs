const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'..'); const {signature,verify,eventHash,target}=require('../contracts/v3/signature-reference.cjs');
const vectors=JSON.parse(fs.readFileSync(root+'/contracts/v3/fixtures/signatures.json')); let count=0;
for(const v of vectors){
 const input={method:v.method,url:v.target,installationId:v.installation_id,timestamp:v.timestamp,nonce:v.nonce,keyB64:v.key_b64,body:v.body};
 assert.equal(signature(input),v.signature); assert.equal(verify(input,v.signature,v.timestamp),true); assert.equal(verify({...input,body:v.body+' '},v.signature,v.timestamp),false); assert.equal(verify(input,v.signature,v.timestamp+301),false); count+=4;
}
assert.equal(target('/path?z=one+two&a=2&a=1'),'/path?a=1&a=2&z=one%2Btwo');count++;
for(const v of JSON.parse(fs.readFileSync(root+'/tests/event-byte-fixtures.json'))){assert.equal(eventHash(JSON.parse(v.json)),v.sha256);count++;}
const result={checks:count,pass:true,scope:'PHP producer event bytes match Node receiver hashes; HMAC, query canonicalization, changed bytes and expired timestamps'};
fs.writeFileSync(root+'/tests/contract-results.json',JSON.stringify(result,null,2)+'\n');console.log(count+' cross-language contract checks passed');

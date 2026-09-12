import fs from 'node:fs';
import {parse} from 'dotenv';
const env=parse(fs.readFileSync('.env.local'));
const base='https://tocaraul.lojadaesquina.store';
for(const path of ['/api/health','/api/commerce/health','/api/commerce/search?q=Raul']) {
 const r=await fetch(base+path,{redirect:'error',signal:AbortSignal.timeout(20000)});
 const data=await r.json().catch(()=>({}));
 console.log(JSON.stringify({path,status:r.status,ok:data.ok,paymentConfigured:data.paymentConfigured,environment:data.environment,searchResults:data.results?.length,message:data.message}));
}
if(env.WEBHOOK_TOKEN) {
 const r=await fetch(base+'/api/webhooks/asaas',{method:'POST',redirect:'error',signal:AbortSignal.timeout(20000),headers:{'Content-Type':'application/json','asaas-access-token':env.WEBHOOK_TOKEN},body:JSON.stringify({event:'TOCARAUL_CONFIGURATION_CHECK'})});
 console.log(JSON.stringify({webhookTokenMatches:r.status!==401,webhookProbeStatus:r.status}));
}

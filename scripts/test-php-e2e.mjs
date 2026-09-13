import assert from 'node:assert/strict';
import fs from 'node:fs';
import {createHmac,randomBytes} from 'node:crypto';
import {execFileSync} from 'node:child_process';
import mysql from 'mysql2/promise';
import {parse} from 'dotenv';
const env=parse(fs.readFileSync('.env.local'));
const base='http://127.0.0.1:8787';
const webhook=createHmac('sha256',env.ASAAS_API_KEY).update('tocaraul-local-e2e-webhook').digest('hex');
async function api(path,body,token){const r=await fetch(base+path,{method:body?'POST':'GET',headers:{'Content-Type':'application/json',...(token?{Authorization:`Bearer ${token}`}:{})},...(body?{body:JSON.stringify(body)}:{})});const data=await r.json();assert.ok(r.ok,`${path}: ${r.status} ${JSON.stringify(data)}`);return data;}
async function asaas(path,body){const r=await fetch('https://api-sandbox.asaas.com/v3'+path,{method:body?'POST':'GET',headers:{access_token:env.ASAAS_API_KEY,'Content-Type':'application/json'},...(body?{body:JSON.stringify(body)}:{})});const d=await r.json();assert.ok(r.ok,`Asaas HTTP ${r.status}: ${JSON.stringify(d.errors)}`);return d;}
const db=await mysql.createConnection({host:'127.0.0.1',port:3307,user:'root',database:'tocaraul_e2e'});
try {
 const health=await api('/api/commerce/health');assert.equal(health.environment,'sandbox');
 const password=randomBytes(18).toString('base64url');
 const bar=await api('/api/onboarding/register',{ownerName:'Responsável de Homologação',barName:'Bar Teste E2E '+Date.now(),phone:'11999999999',email:'',document:'24971563792',pixKeyType:'EMAIL',pixKey:'homologacao@tocaraul.example',password,acceptedTerms:true});
 assert.equal(bar.paymentProvider,'asaas');assert.ok(!bar.mercadoPagoConnectUrl);
 const print=await (await fetch(bar.tablesPrintUrl)).text();assert.ok(print.includes('/assets/qrcode.js'));assert.ok(!print.includes('Conectar Mercado Pago'));
 const qrToken=bar.tableUrl.split('/').pop();
 const order=await api('/api/commerce/request',{qrToken,visitorName:'Cliente E2E',providerId:'youtube:M7lc1UVf-VE',title:'YouTube API Demo',artist:'YouTube Developers',message:'Teste de dedicatória'});
 assert.equal(order.amountCents,500);assert.ok(order.pixCopyPaste.length>50);
 const initial=await api('/api/commerce/payment?requestId='+order.requestId);assert.equal(initial.paymentStatus,'PENDING');
 const unauthorized=await fetch(base+'/api/webhooks/asaas',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({payment:{id:order.externalId}})});assert.equal(unauthorized.status,401);
 await asaas('/sandbox/payment/'+order.externalId+'/confirm',{});
 for(let n=0;n<2;n++){const response=await fetch(base+'/api/webhooks/asaas',{method:'POST',headers:{'Content-Type':'application/json','asaas-access-token':webhook},body:JSON.stringify({event:'PAYMENT_RECEIVED',payment:{id:order.externalId}})});assert.equal(response.status,200,await response.text());}
 const confirmed=await api('/api/commerce/payment?requestId='+order.requestId);assert.equal(confirmed.paymentStatus,'APPROVED');
 const [entries]=await db.query("SELECT * FROM financeLedger WHERE requestId=? AND type='SALE'",[order.requestId]);assert.equal(entries.length,1);assert.equal(entries[0].barCents,350);
 // Browser-equivalent owner session, including CSRF checks and actual rendered balances.
 let page=await fetch(base+'/bar');const cookie=page.headers.get('set-cookie').split(';')[0];let html=await page.text();const csrf=html.match(/name=csrf value="([^"]+)"/)[1];
 page=await fetch(base+'/bar',{method:'POST',headers:{Cookie:cookie,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({a:'login',csrf,code:bar.venue.code,password}),redirect:'manual'});assert.equal(page.status,302);
 const ownerCookie=page.headers.get('set-cookie')?.split(';')[0]??cookie;
 html=await (await fetch(base+'/bar',{headers:{Cookie:ownerCookie}})).text();assert.ok(html.includes(bar.venue.name));assert.ok(html.includes('R$ 3,50'));assert.ok(html.includes('R$ 50,00'));
 // The panel is the player: one poll must carry the state, the heartbeat and the owner's command.
 const ownerCsrf=html.match(/name=csrf value="([^"]+)"/)[1];
 const player=await (await fetch(base+'/api/player/token',{method:'POST',headers:{Cookie:ownerCookie,'Content-Type':'application/json'},body:JSON.stringify({name:'Painel E2E'})})).json();
 assert.ok(player.deviceToken,'o painel precisa emitir o proprio token de player');
 await fetch(base+'/bar',{method:'POST',headers:{Cookie:ownerCookie,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({a:'cmd',csrf:ownerCsrf,cmd:'PAUSE'}),redirect:'manual'});
 const withCommand=await api('/api/device/state',null,player.deviceToken);
 assert.equal(withCommand.connection,'ONLINE');
 assert.equal(withCommand.command,'PAUSE','o comando do dono precisa chegar junto do estado');
 const afterCommand=await api('/api/device/state',null,player.deviceToken);
 assert.equal(afterCommand.command,null,'o comando so pode ser entregue uma vez');
 const [[seen]]=await db.query('SELECT status,lastSeenAt FROM devices WHERE deviceToken=?',[player.deviceToken]);
 assert.equal(seen.status,'ONLINE','o proprio state faz as vezes de heartbeat');
 // O laco inteiro de "pular": o painel pega a musica, recebe o comando e devolve SKIPPED.
 const claimed=await api('/api/player/claim',{},player.deviceToken);
 assert.ok(claimed.track?.id,'o painel precisa conseguir pegar a musica paga da fila');
 await fetch(base+'/bar',{method:'POST',headers:{Cookie:ownerCookie,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({a:'cmd',csrf:ownerCsrf,cmd:'SKIP'}),redirect:'manual'});
 const comPular=await api('/api/device/state',null,player.deviceToken);
 assert.equal(comPular.command,'SKIP','o pular do dono precisa chegar ao painel');
 assert.equal(comPular.nowPlaying?.id,String(claimed.track.id),'a musica pega precisa aparecer como tocando');
 await api('/api/player/complete',{requestId:parseInt(claimed.track.id,10),result:'SKIPPED'},player.deviceToken);
 const depoisDoPular=await api('/api/commerce/payment?requestId='+order.requestId);
 assert.equal(depoisDoPular.requestStatus,'SKIPPED','a musica pulada precisa sair da fila');
 const filaVazia=await api('/api/device/state',null,player.deviceToken);
 assert.equal(filaVazia.queueSize,0,'depois de pular, a fila paga fica vazia');
 assert.equal(filaVazia.nowPlaying,null,'depois de pular, nada fica marcado como tocando');
 const adminPassword=randomBytes(18).toString('base64url');const hash=execFileSync('C:/xampp/php/php.exe',['-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',adminPassword],{encoding:'utf8'});
 await fetch(base+'/admin');await db.execute("INSERT INTO adminUsers(username,passwordHash,mustChangePassword) VALUES('e2e_admin',?,0) ON DUPLICATE KEY UPDATE passwordHash=VALUES(passwordHash)",[hash]);
 page=await fetch(base+'/admin');const ac=page.headers.get('set-cookie').split(';')[0];html=await page.text();const at=html.match(/name="csrf" value="([^"]+)"/)[1];
 page=await fetch(base+'/admin',{method:'POST',headers:{Cookie:ac,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'login',csrf:at,username:'e2e_admin',password:adminPassword}),redirect:'manual'});assert.equal(page.status,302);
 const adminCookie=page.headers.get('set-cookie')?.split(';')[0]??ac;html=await (await fetch(base+'/admin',{headers:{Cookie:adminCookie}})).text();assert.ok(html.includes('Asaas sandbox e ambiente'));
 fs.mkdirSync('tmp',{recursive:true});fs.writeFileSync('tmp/e2e-session.json',JSON.stringify({bar,order,password,ownerCookie,adminCookie,adminPassword},null,2));
 console.log(JSON.stringify({ok:true,venue:bar.venue,requestId:order.requestId,paymentStatus:confirmed.paymentStatus,requestStatus:confirmed.requestStatus,ledgerEntries:entries.length,barCents:entries[0].barCents,ownerPanel:true,adminPanel:true,duplicateWebhook:true,webhookDelivery:'local authenticated replay after real sandbox confirmation',ownerCommandOnStatePoll:true,skipLoop:'claim -> SKIP -> complete -> fila vazia'},null,2));
} finally {await db.end();}

import fs from 'node:fs';
import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
const modulePath=process.env.PLAYWRIGHT_MODULE_PATH;
if(!modulePath)throw Error('Configure PLAYWRIGHT_MODULE_PATH apontando para playwright/index.mjs');
const {chromium}=await import(pathToFileURL(modulePath));
const s=JSON.parse(fs.readFileSync('tmp/e2e-session.json'));
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROME_PATH});
const errors=[];
try {
 const context=await browser.newContext({viewport:{width:1280,height:900}});
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 await page.goto(s.bar.tablesPrintUrl);
 await page.locator('.qr canvas').waitFor();
 assert.ok(await page.locator('h1').innerText().then(t=>t.includes(s.bar.venue.name)));
 await page.screenshot({path:'tmp/web-tables.png',fullPage:true});
 await page.goto('http://127.0.0.1:8787/bar');
 await page.locator('input[name=code]').fill(s.bar.venue.code);
 await page.locator('input[name=password]').fill(s.password);
 await page.getByRole('button',{name:'Entrar',exact:true}).click();
 await page.getByRole('heading',{name:s.bar.venue.name,exact:true}).waitFor();
 assert.ok((await page.locator('body').innerText()).includes('R$ 4,20'));
 await page.screenshot({path:'tmp/web-owner.png',fullPage:true});
 if(process.env.E2E_COMMAND) {
  const labels={PAUSE:'⏸ Pausar',PLAY:'▶ Tocar',SKIP:'⏭ Pular'};
  assert.ok(labels[process.env.E2E_COMMAND]);
  await page.getByRole('button',{name:labels[process.env.E2E_COMMAND],exact:true}).click();
  await page.getByRole('heading',{name:s.bar.venue.name,exact:true}).waitFor();
 }
 await page.goto('http://127.0.0.1:8787/admin');
 await page.locator('input[name=username]').fill('e2e_admin');
 await page.locator('input[name=password]').fill(s.adminPassword);
 await page.getByRole('button',{name:'Entrar',exact:true}).click();
 await page.getByRole('heading',{name:'Asaas sandbox e ambiente'}).waitFor();
 await page.screenshot({path:'tmp/web-admin.png',fullPage:true});
 await page.goto(s.bar.tableUrl);
 assert.equal(await page.locator('#pay').isEnabled(),false);
 await page.locator('#visitor').fill('Cliente navegador E2E');
 await page.locator('#search').fill('Never Gonna Give You Up Rick Astley');
 await page.locator('.song').first().waitFor();
 await page.locator('.song').first().click();
 await page.locator('#message').fill('Teste visual de dedicatória');
 assert.equal(await page.locator('#pay').isEnabled(),true);
 assert.ok((await page.locator('#total').innerText()).includes('6,00'));
 await page.screenshot({path:'tmp/web-customer.png',fullPage:true});
 if(process.env.E2E_CREATE_ORDER==='true') {
  const responsePromise=page.waitForResponse(r=>r.url().endsWith('/api/commerce/request')&&r.request().method()==='POST');
  await page.locator('#pay').click();
  const response=await responsePromise;assert.ok(response.ok());
  s.secondOrder=await response.json();
  await page.locator('#pixqr canvas').waitFor();
  assert.ok((await page.locator('#code').innerText()).length>50);
  await page.screenshot({path:'tmp/web-pix.png',fullPage:true});
  fs.writeFileSync('tmp/e2e-session.json',JSON.stringify(s,null,2));
 }
 assert.deepEqual(errors,[]);
 console.log(JSON.stringify({ok:true,qrCanvas:true,ownerLogin:true,adminLogin:true,customerPrice:true,command:process.env.E2E_COMMAND??null,pageErrors:errors}));
} finally {await browser.close();}

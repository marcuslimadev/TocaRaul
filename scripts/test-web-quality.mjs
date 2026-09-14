import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import puppeteer from 'puppeteer-core';

const BASE = process.env.TOCARAUL_BASE_URL ?? 'http://127.0.0.1:8787';
const CHROME = process.env.CHROME_PATH ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const log = (step, extra) => console.log(JSON.stringify({step, ...extra}));
const problems = [];

const browser = await puppeteer.launch({
  executablePath: CHROME, headless: 'new', defaultViewport: {width: 1440, height: 900},
  args: ['--autoplay-policy=no-user-gesture-required'],
});

const watch = (page, label) => {
  page.on('dialog', (d) => d.accept());
  page.on('pageerror', (e) => problems.push(`${label}: js error: ${e.message}`));
  page.on('console', (m) => { const t = m.text(); if (m.type() === 'error' && !t.includes('favicon') && !t.includes('401')) problems.push(`${label}: console: ${t.slice(0, 160)}`); });
  // A 401 on /api/device/* or /api/player/* is by design: it is how a revoked screen learns it was disconnected.
  page.on('response', (r) => { const u = r.url(); const expected = r.status() === 401 && (u.includes('/api/device/') || u.includes('/api/player/')); if (u.startsWith(BASE) && r.status() >= 400 && !expected) problems.push(`${label}: http ${r.status()} ${u}`); });
  page.on('requestfailed', (r) => {
    const u = r.url();
    if (u.startsWith(BASE)) problems.push(`${label}: request failed: ${u} ${r.failure()?.errorText}`);
  });
};

try {
  // ---------- sign up a bar ----------
  const owner = await browser.newPage();
  watch(owner, 'signup');
  await owner.goto(BASE + '/cadastro', {waitUntil: 'networkidle0'});
  const password = 'SenhaQ' + Math.random().toString(36).slice(2, 10);
  const barName = 'Bar Qualidade ' + Date.now();
  await owner.type('input[name=barName]', barName);
  await owner.type('input[name=ownerName]', 'Dono Q');
  await owner.type('input[name=phone]', '11966665555');
  await owner.type('input[name=document]', '24971563792');
  await owner.type('input[name=pixKey]', 'q@tocaraul.example');
  await owner.type('input[name=password]', password);
  await owner.click('input[name=acceptedTerms]');
  await Promise.all([owner.waitForNavigation({waitUntil: 'networkidle0'}), owner.click('button')]);
  const panelText = await owner.$eval('body', (b) => b.innerText);
  const venueCode = panelText.match(/Codigo:\s*([A-Z0-9]+)/)?.[1];
  assert.ok(venueCode, 'panel must show the bar code');
  log('signed_up', {venueCode});

  // Os controles do palco nao navegam: mandam o comando e ficam na pagina.
  const comando = (page, cmd) => page.evaluate((c) => {
    document.querySelector(`.cmdForm [name=cmd][value=${c}]`).closest('form').requestSubmit();
  }, cmd);

  const submit = (page, action) => Promise.all([
    page.waitForNavigation({waitUntil: 'networkidle0'}),
    page.evaluate((a) => [...document.querySelectorAll('form')].find((f) => f.querySelector(`[name=a][value=${a}]`)).requestSubmit(), action),
  ]);

  // ---------- the print sheet renders a QR for each active table ----------
  const print = await browser.newPage();
  watch(print, 'mesas');
  await print.goto(`${BASE}/mesas?venue=${venueCode}`, {waitUntil: 'networkidle0'});
  await print.waitForSelector('.qr canvas', {timeout: 10000});
  const qrCount = await print.$$eval('.qr canvas', (els) => els.length);
  assert.ok(qrCount >= 1, 'print sheet must render QR codes');
  log('print_sheet', {qrCount});

  // ---------- the customer page on a phone must not overflow sideways ----------
  const phone = await browser.newPage();
  watch(phone, 'cliente');
  await phone.setViewport({width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 2});
  const activeTable = (await owner.$$eval('a[href^="/j/"]', (els) => els.map((e) => e.getAttribute('href'))))[0];
  await phone.goto(BASE + activeTable, {waitUntil: 'networkidle0'});
  const overflow = await phone.evaluate(() => ({
    docWider: document.documentElement.scrollWidth > window.innerWidth + 1,
    scrollWidth: document.documentElement.scrollWidth, inner: window.innerWidth,
  }));
  assert.ok(!overflow.docWider, `customer page overflows on a phone: ${JSON.stringify(overflow)}`);
  log('phone_layout', overflow);

  // ---------- /tv is a real page now, not a dead-end redirect ----------
  const tvAnon = await fetch(BASE + '/tv', {redirect: 'manual'});
  assert.equal(tvAnon.status, 200, '/tv must render (login gate) even signed out');
  const tvAnonHtml = await tvAnon.text();
  assert.ok(tvAnonHtml.includes('name=code'), '/tv without a session must offer the bar login');
  const playerAlias = await fetch(BASE + '/player', {redirect: 'manual'});
  assert.ok([301, 302].includes(playerAlias.status), '/player should redirect to /tv');
  assert.match(playerAlias.headers.get('location') ?? '', /\/tv/, '/player should point at the TV screen');
  log('tv_route', {status: tvAnon.status, playerRedirect: playerAlias.status});

  // ---------- um login do Google expirado precisa ter caminho de volta ----------
  const expired = await fetch(`${BASE}/api/oauth/google/callback?state=expirado&code=qualquer`);
  const expiredHtml = await expired.text();
  assert.ok(expiredHtml.includes('/auth/google/start'), 'a pagina de estado invalido deve oferecer tentar de novo');
  assert.ok(expiredHtml.includes('href="/bar"'), 'a pagina de estado invalido deve oferecer o login por senha');
  assert.ok(expiredHtml.includes('bauhaus.css'), 'a pagina de erro tambem e Bauhaus');
  log('oauth_dead_end', {status: expired.status});

  // ---------- the heart of it: a paid order plays inside the panel ----------
  await phone.type("#search", "Tim Maia Voce");
  await phone.waitForSelector(".song", {timeout: 20000});
  let order = null;
  phone.on("response", async (r) => {
    if (r.url().endsWith("/api/commerce/request") && r.request().method() === "POST") { try { order = await r.json(); } catch {} }
  });
  await phone.click(".song");
  await phone.click("#toStep2");
  await phone.waitForSelector("#view2.on");
  await phone.type("#visitor", "Cliente Qualidade");
  const dedication = "Para a Rosa da mesa do fundo";
  const messageField = await phone.$("#message");
  if (messageField) await messageField.type(dedication);
  await Promise.all([phone.waitForSelector("#view3.on", {timeout: 25000}), phone.click("#pay")]);
  await new Promise((r) => setTimeout(r, 1500));
  assert.ok(order?.requestId, "the order must reach the backend");
  const paid = await (await fetch(BASE + "/api/commerce/mock-confirm", {
    method: "POST", headers: {"Content-Type": "application/json"}, body: JSON.stringify({requestId: order.requestId}),
  })).json();
  assert.ok(paid.ok, "sandbox payment must confirm");
  // ---- the owner uploads the bar logo, then opens the TV screen (a separate page/tab) ----
  await owner.bringToFront();
  await owner.reload({waitUntil: "networkidle0"});
  const logoFile = 'tmp/logo-quality.png';
  fs.mkdirSync('tmp', {recursive: true});
  fs.writeFileSync(logoFile, Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'));
  const logoInput = await owner.$('input[type=file][name=logo]');
  assert.ok(logoInput, 'the panel must offer a logo upload');
  await logoInput.uploadFile(path.resolve(logoFile));
  await submit(owner, 'logo');
  const logoSrc = await owner.$eval('img[src^="/assets/logos/"]', (e) => e.getAttribute('src'));
  assert.ok(logoSrc, 'the panel must show the uploaded logo');
  log('logo_uploaded', {logoSrc});

  // ---- /tv is its own tab; it shares the bar's login session (same browser context) ----
  const tv = await browser.newPage();
  watch(tv, 'tv');
  await tv.goto(BASE + '/tv', {waitUntil: 'networkidle0'});
  const tvLogoSrc = await tv.$eval('.tv-brandcorner img', (e) => e.getAttribute('src'));
  assert.ok((tvLogoSrc ?? '').startsWith('/assets/logos/'), 'the bar logo must show on the TV screen');
  const sideQr = await tv.$$eval('.tv-qr canvas, .tv-qr img', (els) => els.length);
  assert.ok(sideQr >= 1, 'the TV screen must show the order QR code beside the player');
  await tv.click('#startBtn');
  await tv.waitForFunction(() => document.getElementById('ytmount')?.classList.contains('on'), {timeout: 30000});
  const playing = await tv.evaluate(() => document.querySelector('#ytmount iframe')?.src ?? null);
  assert.ok((playing ?? '').includes('youtube.com/embed/'), 'the TV screen must play the paid song on YouTube');
  const shown = await tv.$eval('#dedMessage', (e) => e.textContent.trim());
  if (messageField) assert.ok(shown.includes(dedication), `the TV screen must keep the dedication on screen, got "${shown}"`);
  log("tv_playing", {iframe: playing?.slice(0, 60), dedication: shown});

  // ---- the panel itself must never run the player (two claims would race) ----
  const ownerHasNoPlayer = await owner.evaluate(() => !document.getElementById('ytmount') && !document.getElementById('stage'));
  assert.ok(ownerHasNoPlayer, 'the panel must not embed the YouTube player anymore — only /tv does');
  await owner.waitForFunction(
    () => (document.getElementById('nowTitle')?.textContent || '').trim().length > 0
      && document.getElementById('nowTitle').textContent !== 'Nenhuma música tocando',
    {timeout: 15000},
  );
  const tvStatus = await owner.$eval('#tvStatusText', (e) => e.textContent);
  assert.match(tvStatus, /online/i, `the panel must show the TV as online once /tv is polling, got "${tvStatus}"`);
  log('panel_reads_status', {tvStatus, nowTitle: await owner.$eval('#nowTitle', (e) => e.textContent)});

  // Um segundo pedido pago, agora com o painel ja aberto: o aviso tem de acender sozinho.
  let segundo = null;
  const primeiroId = order.requestId; // o listener original sobrescreve `order`; guarde antes
  phone.on('response', async (r) => {
    if (r.url().endsWith('/api/commerce/request') && r.request().method() === 'POST') { try { const j = await r.json(); if (j.requestId !== primeiroId) segundo = j; } catch {} }
  });
  await phone.bringToFront();
  await phone.reload({waitUntil: 'networkidle0'});
  await phone.type('#search', 'Raul Seixas Maluco Beleza');
  await phone.waitForSelector('.song', {timeout: 20000});
  await phone.click('.song');
  await phone.click('#toStep2');
  await phone.waitForSelector('#view2.on');
  await phone.$eval('#visitor', (e) => { e.value = ''; }); // o nome fica salvo no localStorage
  await phone.type('#visitor', 'Joana');
  await Promise.all([phone.waitForSelector('#view3.on', {timeout: 25000}), phone.click('#pay')]);
  await new Promise((r) => setTimeout(r, 1200));
  assert.ok(segundo?.requestId, 'o segundo pedido precisa chegar ao backend');
  await (await fetch(BASE + '/api/commerce/mock-confirm', {
    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({requestId: segundo.requestId}),
  })).json();

  // O cliente tem de ver a confirmacao no proprio celular.
  await phone.waitForFunction(() => /confirmado/i.test(document.getElementById('status')?.textContent || ''), {timeout: 20000});
  log('cliente_confirmado', {texto: await phone.$eval('#status', (e) => e.textContent.trim())});

  await tv.bringToFront();
  await tv.waitForFunction(() => document.getElementById('toast')?.classList.contains('on'), {timeout: 20000});
  const aviso = await tv.evaluate(() => ({
    musica: document.getElementById('toastTitle').textContent,
    quem: document.getElementById('toastWho').textContent,
  }));
  assert.ok(aviso.quem.includes('Joana'), `o aviso precisa dizer quem pediu, veio "${aviso.quem}"`);
  assert.ok(aviso.musica.length > 0, 'o aviso precisa dizer qual musica entrou');
  log('aviso_na_tv', aviso);

  // ---------- a fila do painel se atualiza sozinha, sem recarregar (poll de /bar?a=status) ----------
  await owner.bringToFront();
  await owner.waitForFunction(
    () => (document.getElementById('paidList')?.textContent || '').includes('Joana'),
    {timeout: 15000},
  );
  const naLista = await owner.$eval('#paidList', (e) => e.textContent);
  assert.ok(naLista.includes('Joana'), `o pedido novo precisa aparecer na lista sem recarregar, lista: ${naLista.slice(0, 200)}`);
  log('pedido_na_lista', {trecho: naLista.replace(/\s+/g, ' ').slice(0, 90)});

  // ---------- os controles do painel chegam na TV ----------
  // O navegador headless nao consegue tocar video do YouTube de verdade (a reproducao
  // morre em ~2s), entao o laco completo de pular vive no test-php-e2e. Aqui provamos
  // o que so o navegador prova: o botao do painel nao recarrega a pagina, e o comando
  // chega na tela que de fato esta rodando o player (/tv, nao mais /bar).
  const comandosRecebidos = [];
  tv.on('response', async (r) => {
    if (r.url().includes('/api/device/state')) { try { const j = await r.json(); if (j.command) comandosRecebidos.push(j.command); } catch {} }
  });
  await owner.evaluate(() => { window.__mesmaPagina = true; });

  await comando(owner, 'PAUSE');
  await owner.waitForFunction(() => window.__mesmaPagina === true, {timeout: 5000})
    .catch(() => { throw new Error('o botao Pausar recarregou a pagina — isso mataria a musica'); });
  await comando(owner, 'SKIP');
  await new Promise((r) => setTimeout(r, 9000));
  assert.ok(await owner.evaluate(() => window.__mesmaPagina === true), 'os controles nao podem recarregar a pagina do painel');
  assert.ok(comandosRecebidos.includes('PAUSE'), `o Pausar precisa chegar ao painel, chegaram: ${JSON.stringify(comandosRecebidos)}`);
  assert.ok(comandosRecebidos.includes('SKIP'), `o Pular precisa chegar ao painel, chegaram: ${JSON.stringify(comandosRecebidos)}`);
  log('controles', {comandos: comandosRecebidos});

  assert.deepEqual(problems, [], 'pages must load with no console/network errors');
  console.log(JSON.stringify({ok: true, venueCode, problems}, null, 2));
} catch (e) {
  console.log('RESULT_FAIL: ' + e.message);
  if (problems.length) console.log('problems: ' + JSON.stringify(problems, null, 2));
  process.exitCode = 1;
} finally {
  await browser.close();
}

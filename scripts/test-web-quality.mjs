import assert from 'node:assert/strict';
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

  // ---------- tables: add, rename, and the floor cannot be left with none ----------
  const submit = (page, action) => Promise.all([
    page.waitForNavigation({waitUntil: 'networkidle0'}),
    page.evaluate((a) => [...document.querySelectorAll('form')].find((f) => f.querySelector(`[name=a][value=${a}]`)).requestSubmit(), action),
  ]);
  await owner.type('form input[name=label][placeholder="Mesa 02"]', 'Mesa 02');
  await submit(owner, 'table_add');
  let tables = await owner.$$eval('input[name=label]:not([placeholder])', (els) => els.map((e) => e.value));
  assert.deepEqual(tables, ['Mesa 01', 'Mesa 02'], 'both tables should be listed');
  await owner.$eval('input[name=label]:not([placeholder])', (el) => { el.value = ''; });
  await owner.type('input[name=label]:not([placeholder])', 'Balcão');
  await submit(owner, 'table_rename');
  tables = await owner.$$eval('input[name=label]:not([placeholder])', (els) => els.map((e) => e.value));
  assert.deepEqual(tables, ['Balcão', 'Mesa 02'], 'rename should stick');
  log('tables', {tables});

  // each table has its own working QR link
  const tableLinks = await owner.$$eval('a[href^="/j/"]', (els) => els.map((e) => e.getAttribute('href')));
  assert.equal(tableLinks.length, 2, 'each table needs its own QR link');
  for (const href of tableLinks) {
    const res = await fetch(BASE + href);
    assert.equal(res.status, 200, `table page ${href} must load`);
  }

  // disabling a table works, and the last one is protected
  await submit(owner, 'table_del');
  tables = await owner.$$eval('input[name=label]:not([placeholder])', (els) => els.map((e) => e.value));
  assert.equal(tables.length, 1, 'one table should remain');
  await submit(owner, 'table_del');
  const guard = await owner.$eval('body', (b) => b.innerText);
  assert.ok(guard.includes('pelo menos uma mesa'), 'the last table must be protected');
  log('table_guard', {ok: true});

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

  // ---------- the screen: login, start, and the panel can disconnect it ----------
  const ctx = await browser.createBrowserContext();
  const screen = await ctx.newPage();
  watch(screen, 'player');
  await screen.bringToFront();
  await screen.goto(BASE + '/player', {waitUntil: 'networkidle0'});
  await screen.type('input[name=code]', venueCode);
  await screen.type('input[name=password]', password);
  await Promise.all([screen.waitForNavigation({waitUntil: 'networkidle0'}), screen.click('button')]);
  await screen.click('#startBtn');
  await new Promise((r) => setTimeout(r, 2500));
  const screenState = await screen.evaluate(() => ({
    online: document.getElementById('viewOnline').classList.contains('on'),
    tokens: Object.keys(localStorage).filter((k) => k.startsWith('tocaraul_player_')).length,
  }));
  assert.ok(screenState.online && screenState.tokens === 1, `screen should be online with one token: ${JSON.stringify(screenState)}`);
  log('screen_online', screenState);

  await owner.reload({waitUntil: 'networkidle0'});
  let screens = await owner.$eval('body', (b) => b.innerText);
  assert.ok(screens.includes('Tela do bar'), 'panel should list the screen');
  await submit(owner, 'screen_del');
  screens = await owner.$eval('body', (b) => b.innerText);
  assert.ok(screens.includes('Nenhuma tela conectada'), 'panel should show the screen was disconnected');
  await screen.waitForFunction(() => document.getElementById('viewRevoked')?.classList.contains('on'), {timeout: 20000})
    .catch(() => { throw new Error('the disconnected screen must say so and ask for login again'); });
  const revoked = await (await fetch(BASE + '/api/device/state', {
    headers: {Authorization: 'Bearer ' + (await screen.evaluate(() => localStorage.getItem(Object.keys(localStorage).find((k) => k.startsWith('tocaraul_player_')))))},
  })).status;
  assert.equal(revoked, 401, 'a disconnected screen token must stop working');
  log('screen_revoked', {status: revoked});

  // ---------- the old page must not be a dead end ----------
  const legacy = await fetch(BASE + '/tv', {redirect: 'manual'});
  assert.ok([301, 302].includes(legacy.status), '/tv should redirect');
  assert.match(legacy.headers.get('location') ?? '', /\/player/, '/tv should point at the player');
  log('legacy_redirect', {status: legacy.status, to: legacy.headers.get('location')});

  assert.deepEqual(problems, [], 'pages must load with no console/network errors');
  console.log(JSON.stringify({ok: true, venueCode, problems}, null, 2));
} catch (e) {
  console.log('RESULT_FAIL: ' + e.message);
  if (problems.length) console.log('problems: ' + JSON.stringify(problems, null, 2));
  process.exitCode = 1;
} finally {
  await browser.close();
}

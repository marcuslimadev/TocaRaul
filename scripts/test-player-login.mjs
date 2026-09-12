import assert from 'node:assert/strict';
import {randomBytes} from 'node:crypto';
import mysql from 'mysql2/promise';

const base = process.env.TOCARAUL_BASE_URL ?? 'http://127.0.0.1:8787';
const db = await mysql.createConnection({host: '127.0.0.1', port: 3307, user: 'root', database: 'tocaraul_e2e'});
const csrfOf = (html) => html.match(/name=csrf value="([^"]+)"/)?.[1] ?? (() => { throw new Error('csrf not found'); })();
const cookieOf = (res, fallback) => res.headers.get('set-cookie')?.split(';')[0] ?? fallback;
const post = (path, cookie, fields) => fetch(base + path, {
  method: 'POST', redirect: 'manual',
  headers: {Cookie: cookie, 'Content-Type': 'application/x-www-form-urlencoded'},
  body: new URLSearchParams(fields),
});

try {
  // a bar that exists, created without any screen involved
  let page = await fetch(base + '/cadastro');
  let cookie = cookieOf(page, '');
  let html = await page.text();
  const password = randomBytes(12).toString('base64url');
  const barName = 'Bar Player ' + Date.now();
  let res = await post('/cadastro', cookie, {
    csrf: csrfOf(html), barName, ownerName: 'Dono Player', phone: '11977776666', email: '',
    document: '24971563792', pixKeyType: 'EMAIL', pixKey: 'player@tocaraul.example', password, acceptedTerms: '1',
  });
  assert.equal(res.status, 302, 'signup should succeed');
  const [[venue]] = await db.query('SELECT id,code FROM venues WHERE name=?', [barName]);

  // the screen opens /player in a fresh browser: it must ask for the bar login, not a pairing code
  page = await fetch(base + '/player');
  const screenCookie0 = cookieOf(page, '');
  html = await page.text();
  assert.ok(html.includes('Tela do bar') && html.includes('Código do bar'), 'player must show the bar login');
  assert.ok(!html.includes('CÓDIGO DA TV'), 'player must not show a pairing code screen');

  // wrong password is refused
  let bad = await post('/player', screenCookie0, {csrf: csrfOf(html), code: venue.code, password: 'senhaerrada'});
  assert.ok((await bad.text()).includes('não conferem'), 'wrong password must be refused');

  // logging in on the screen itself
  page = await fetch(base + '/player');
  let screenCookie = cookieOf(page, '');
  html = await page.text();
  res = await post('/player', screenCookie, {csrf: csrfOf(html), code: venue.code, password});
  assert.equal(res.status, 302, 'player login should redirect');
  screenCookie = cookieOf(res, screenCookie);
  html = await (await fetch(base + '/player', {headers: {Cookie: screenCookie}})).text();
  assert.ok(html.includes(barName), 'player should show the bar name once logged in');
  assert.ok(html.includes('Começar'), 'player should ask for one click before autoplaying');
  assert.ok(html.includes('youtube.com'), 'player should point to the YouTube sign-in for Premium');

  // the page can mint its own screen token from the session, with no code anywhere
  const tokenRes = await fetch(base + '/api/player/token', {
    method: 'POST', headers: {Cookie: screenCookie, 'Content-Type': 'application/json'}, body: JSON.stringify({name: 'Tela do balcão'}),
  });
  assert.equal(tokenRes.status, 201, 'token endpoint should mint a screen token for the logged-in bar');
  const {deviceToken} = await tokenRes.json();
  assert.ok(deviceToken, 'token must be returned');

  // that token is already bound to the right bar and online
  const state = await (await fetch(base + '/api/device/state', {headers: {Authorization: 'Bearer ' + deviceToken}})).json();
  assert.equal(state.connection, 'ONLINE', 'the minted screen must be online immediately');
  assert.equal(state.venue.code, venue.code, 'the minted screen must belong to that bar');

  // and it is refused without a bar session
  const anon = await fetch(base + '/api/player/token', {
    method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({}),
  });
  assert.equal(anon.status, 401, 'minting a screen token must require the bar session');

  // the panel lists the screen
  html = await (await fetch(base + '/bar', {headers: {Cookie: screenCookie}})).text();
  assert.ok(html.includes('Tela do balcão'), 'panel should list the connected screen');
  assert.ok(html.includes('/player'), 'panel should tell the owner where to open the screen');

  console.log(JSON.stringify({
    ok: true, venueCode: venue.code, playerAsksForBarLogin: true, noPairingCodeScreen: true,
    wrongPasswordRefused: true, playerLoginWorks: true, gestureGateShown: true, youtubeHintShown: true,
    tokenMintedFromSession: true, screenOnlineImmediately: true, anonymousTokenRefused: true, listedInPanel: true,
  }, null, 2));
} finally {
  await db.end();
}

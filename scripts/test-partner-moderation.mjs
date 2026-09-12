import assert from 'node:assert/strict';
import {randomBytes} from 'node:crypto';
import {execFileSync} from 'node:child_process';
import mysql from 'mysql2/promise';

const base = process.env.TOCARAUL_BASE_URL ?? 'http://127.0.0.1:8787';
const db = await mysql.createConnection({host: '127.0.0.1', port: 3307, user: 'root', database: 'tocaraul_e2e'});
const csrfOf = (html, name = 'csrf') =>
  html.match(new RegExp(`name=["\']?${name}["\']? value="([^"]+)"`))?.[1] ?? (() => { throw new Error('csrf not found'); })();
const cookieOf = (res, fallback) => res.headers.get('set-cookie')?.split(';')[0] ?? fallback;

async function form(path, cookie, fields) {
  const res = await fetch(base + path, {
    method: 'POST', redirect: 'manual',
    headers: {Cookie: cookie, 'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams(fields),
  });
  return {res, html: res.status === 302 ? '' : await res.text()};
}

try {
  // ---- partner account seeded the same way production will be ----
  const partnerPassword = randomBytes(12).toString('base64url');
  const hash = execFileSync('C:/xampp/php/php.exe', ['-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', partnerPassword], {encoding: 'utf8'});
  await fetch(base + '/parceiro'); // lets the page create its own tables
  await db.execute(
    "INSERT INTO partners(name,username,passwordHash,mustChangePassword) VALUES('Parceiro Teste','parceiro_teste',?,0) ON DUPLICATE KEY UPDATE passwordHash=VALUES(passwordHash),mustChangePassword=0",
    [hash]
  );

  let page = await fetch(base + '/parceiro');
  let cookie = cookieOf(page, '');
  let html = await page.text();
  let out = await form('/parceiro', cookie, {a: 'login', csrf: csrfOf(html), username: 'parceiro_teste', password: partnerPassword});
  assert.equal(out.res.status, 302, 'partner login should redirect');
  const partnerCookie = cookieOf(out.res, cookie);
  html = await (await fetch(base + '/parceiro', {headers: {Cookie: partnerCookie}})).text();
  assert.ok(html.includes('Cadastrar novo bar'), 'partner panel should show the register form');

  // ---- partner registers a bar ----
  const ownerPassword = randomBytes(12).toString('base64url');
  const barName = 'Bar Parceiro ' + Date.now();
  out = await form('/parceiro', partnerCookie, {
    a: 'register', csrf: csrfOf(html),
    barName, ownerName: 'Dono Teste', phone: '11999999999', email: '', document: '24971563792',
    pixKeyType: 'EMAIL', pixKey: 'parceiro@tocaraul.example', ownerPassword,
  });
  assert.equal(out.res.status, 302, 'register should redirect to the handoff card');
  html = await (await fetch(base + '/parceiro', {headers: {Cookie: partnerCookie}})).text();
  assert.ok(html.includes('Bar cadastrado'), 'handoff card should be shown once');
  assert.ok(html.includes(ownerPassword), 'handoff card must show the owner password');
  const venueCode = html.match(/Código do bar \(login\)<\/span><b[^>]*>([A-Z0-9]+)</)?.[1];
  assert.ok(venueCode, 'handoff card must show the venue code');
  const [[venue]] = await db.query('SELECT id,partnerId FROM venues WHERE code=?', [venueCode]);
  assert.ok(venue.partnerId, 'venue must record which partner onboarded it');
  const [[table]] = await db.query('SELECT qrToken FROM venueTables WHERE venueId=? LIMIT 1', [venue.id]);
  const qrToken = table.qrToken;

  // ---- owner logs in with what the partner handed over ----
  page = await fetch(base + '/bar');
  cookie = cookieOf(page, '');
  html = await page.text();
  out = await form('/bar', cookie, {a: 'login', csrf: csrfOf(html), code: venueCode, password: ownerPassword});
  assert.equal(out.res.status, 302, 'owner login should work with the handed-over credentials');
  const ownerCookie = cookieOf(out.res, cookie);
  html = await (await fetch(base + '/bar', {headers: {Cookie: ownerCookie}})).text();
  assert.ok(html.includes('Palavras banidas') && html.includes('Músicas banidas'), 'owner panel should expose both blocklists');

  const order = (msg, provider = 'youtube:M7lc1UVf-VE') => fetch(base + '/api/commerce/request', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({qrToken, visitorName: 'Cliente', providerId: provider, title: 'Teste', artist: 'Teste', message: msg}),
  });

  // ---- banned word blocks the dedication ----
  out = await form('/bar', ownerCookie, {a: 'word_add', csrf: csrfOf(html), word: 'jacaré'});
  assert.equal(out.res.status, 302);
  let res = await order('viva o Jacare do bar');
  assert.equal(res.status, 400, 'accent/case-insensitive banned word must block the request');
  res = await order('dedicatória sem problema');
  assert.equal(res.status, 201, 'clean dedication must still go through');

  // ---- banned song blocks the request and disappears from search ----
  html = await (await fetch(base + '/bar', {headers: {Cookie: ownerCookie}})).text();
  out = await form('/bar', ownerCookie, {a: 'song_add', csrf: csrfOf(html), banVideo: 'kJQP7kiw5Fk', title: 'Despacito'});
  assert.equal(out.res.status, 302);
  res = await order('', 'youtube:kJQP7kiw5Fk');
  assert.equal(res.status, 409, 'banned song must be refused');
  const search = await (await fetch(`${base}/api/commerce/search?q=despacito&qrToken=${qrToken}`)).json();
  if (Array.isArray(search.results)) {
    assert.ok(!search.results.some((r) => r.id === 'youtube:kJQP7kiw5Fk'), 'banned song must not appear in search');
  }

  // ---- partner can re-issue the owner password for a bar in their own wallet ----
  html = await (await fetch(base + '/parceiro', {headers: {Cookie: partnerCookie}})).text();
  out = await form('/parceiro', partnerCookie, {a: 'owner_reset', csrf: csrfOf(html), id: String(venue.id)});
  assert.equal(out.res.status, 302, 'owner reset should redirect');
  html = await (await fetch(base + '/parceiro', {headers: {Cookie: partnerCookie}})).text();
  const newOwnerPassword = html.match(/Nova senha do dono<\/span><b[^>]*>([^<]+)</)?.[1];
  assert.ok(newOwnerPassword, 'reset card must show the new password');
  assert.notEqual(newOwnerPassword, ownerPassword, 'reset must generate a different password');

  page = await fetch(base + '/bar');
  cookie = cookieOf(page, '');
  html = await page.text();
  out = await form('/bar', cookie, {a: 'login', csrf: csrfOf(html), code: venueCode, password: ownerPassword});
  assert.notEqual(out.res.status, 302, 'the old owner password must stop working after a reset');
  page = await fetch(base + '/bar');
  cookie = cookieOf(page, '');
  html = await page.text();
  out = await form('/bar', cookie, {a: 'login', csrf: csrfOf(html), code: venueCode, password: newOwnerPassword});
  assert.equal(out.res.status, 302, 'the re-issued owner password must work');

  // ---- a partner must not be able to reset a bar that is not theirs ----
  const [[foreign]] = await db.query('SELECT id FROM venues WHERE partnerId IS NULL OR partnerId<>? LIMIT 1', [venue.partnerId]);
  if (foreign) {
    html = await (await fetch(base + '/parceiro', {headers: {Cookie: partnerCookie}})).text();
    out = await form('/parceiro', partnerCookie, {a: 'owner_reset', csrf: csrfOf(html), id: String(foreign.id)});
    assert.ok(out.html.includes('não encontrado na sua carteira'), 'reset must be scoped to the partner own bars');
  }

  console.log(JSON.stringify({
    ok: true, partnerLogin: true, venueCode, partnerLinked: true, ownerLogin: true,
    blockedWordEnforced: true, cleanDedicationAccepted: true, blockedSongRefused: true,
    searchFiltered: Array.isArray(search.results) ? 'yes' : 'search unavailable (no YouTube key locally)',
    ownerPasswordReissued: true, oldPasswordRevoked: true, resetScopedToPartner: !!foreign,
  }, null, 2));
} finally {
  await db.end();
}

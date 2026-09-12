import assert from 'node:assert/strict';
import {randomBytes} from 'node:crypto';
import mysql from 'mysql2/promise';

const base = process.env.TOCARAUL_BASE_URL ?? 'http://127.0.0.1:8787';
const db = await mysql.createConnection({host: '127.0.0.1', port: 3307, user: 'root', database: 'tocaraul_e2e'});
const csrfOf = (html) => html.match(/name=csrf value="([^"]+)"/)?.[1] ?? (() => { throw new Error('csrf not found'); })();
const cookieOf = (res, fallback) => (res.headers.getSetCookie?.() ?? []).map((c) => c.split(';')[0]).find((c) => c.startsWith('tocaraul_bar=')) ?? fallback;

async function form(path, cookie, fields) {
  const res = await fetch(base + path, {
    method: 'POST', redirect: 'manual',
    headers: {Cookie: cookie, 'Content-Type': 'application/x-www-form-urlencoded'},
    body: new URLSearchParams(fields),
  });
  return {res, html: res.status === 302 ? '' : await res.text()};
}

try {
  // ---- the owner signs up with no TV involved ----
  let page = await fetch(base + '/cadastro');
  let cookie = cookieOf(page, '');
  let html = await page.text();
  assert.ok(!html.includes('Código da TV'), 'signup must not ask for a TV code');
  const password = randomBytes(12).toString('base64url');
  const barName = 'Bar Self ' + Date.now();
  let out = await form('/cadastro', cookie, {
    csrf: csrfOf(html), barName, ownerName: 'Dono Self', phone: '11988887777',
    email: '', document: '24971563792', pixKeyType: 'EMAIL', pixKey: 'self@tocaraul.example',
    password, acceptedTerms: '1',
  });
  assert.equal(out.res.status, 302, 'signup should redirect straight into the panel');
  const ownerCookie = cookieOf(out.res, cookie);

  // ---- lands already logged in, with the next steps and no TV yet ----
  html = await (await fetch(base + '/bar', {headers: {Cookie: ownerCookie}})).text();
  assert.ok(html.includes(barName), 'owner should land logged into their own panel');
  assert.ok(html.includes('Bar criado!'), 'panel should show the onboarding next steps');
    const [[venue]] = await db.query('SELECT id,code FROM venues WHERE name=?', [barName]);
  const [[table]] = await db.query('SELECT qrToken FROM venueTables WHERE venueId=? LIMIT 1', [venue.id]);
  assert.ok(table?.qrToken, 'signup must already create the first table QR');

  // ---- customers can already order before any TV is connected ----
  const order = await fetch(base + '/api/commerce/request', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({qrToken: table.qrToken, visitorName: 'Cliente', providerId: 'youtube:M7lc1UVf-VE', title: 'Teste', artist: 'Teste', message: ''}),
  });
  assert.equal(order.status, 201, 'ordering should work as soon as the bar exists');

  console.log(JSON.stringify({
    ok: true, venueCode: venue.code, signupWithoutScreen: true, autoLogin: true,
    tableCreatedOnSignup: true, orderBeforeScreen: true, 
  }, null, 2));
} finally {
  await db.end();
}

import fs from 'node:fs';
import { parse } from 'dotenv';

const env = { ...parse(fs.readFileSync('.env.local')), ...process.env };
const base = env.ASAAS_API_BASE_URL;
if (base !== 'https://api-sandbox.asaas.com/v3') throw new Error('Sandbox required');
if (!env.ASAAS_API_KEY) throw new Error('ASAAS_API_KEY is required');
if (!env.WEBHOOK_TOKEN || env.WEBHOOK_TOKEN.length < 32) throw new Error('WEBHOOK_TOKEN must be at least 32 chars');

const target = 'https://tocaraul.lojadaesquina.store/api/webhooks/asaas';
const headers = { access_token: env.ASAAS_API_KEY, 'Content-Type': 'application/json', Accept: 'application/json', 'User-Agent': 'TocaRaul/1.0' };

async function asaas(path, options = {}) {
  const response = await fetch(base + path, { ...options, headers: { ...headers, ...(options.headers ?? {}) }, redirect: 'error', signal: AbortSignal.timeout(20000) });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(`Asaas HTTP ${response.status}: ${data.errors?.[0]?.description ?? data.message ?? 'erro'}`);
  return data;
}

const existing = await asaas('/webhooks?limit=100');
const found = (existing.data ?? []).find((webhook) => webhook.url === target);
const body = {
  name: 'TocaRaul sandbox',
  url: target,
  email: env.ASAAS_WEBHOOK_EMAIL ?? 'marcus.lima@hotmail.com.br',
  enabled: true,
  interrupted: false,
  apiVersion: 3,
  authToken: env.WEBHOOK_TOKEN,
  sendType: 'SEQUENTIALLY',
  events: ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'],
};

const result = found
  ? await asaas(`/webhooks/${encodeURIComponent(found.id)}`, { method: 'PUT', body: JSON.stringify(body) })
  : await asaas('/webhooks', { method: 'POST', body: JSON.stringify(body) });

console.log(JSON.stringify({
  ok: true,
  action: found ? 'updated' : 'created',
  id: result.id,
  name: result.name,
  url: result.url,
  email: result.email,
  enabled: result.enabled,
  interrupted: result.interrupted,
  events: result.events,
}, null, 2));

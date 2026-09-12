import fs from 'node:fs';
import { parse } from 'dotenv';
const env = parse(fs.readFileSync('.env.local'));
if (env.ASAAS_API_BASE_URL !== 'https://api-sandbox.asaas.com/v3') throw new Error('Sandbox required');
const response = await fetch(env.ASAAS_API_BASE_URL + '/webhooks?limit=100', {
  headers: { access_token: env.ASAAS_API_KEY, 'User-Agent': 'TocaRaul/1.0' },
  redirect: 'error', signal: AbortSignal.timeout(20000),
});
if (!response.ok) throw new Error(`Asaas HTTP ${response.status}`);
const data = await response.json();
console.log(JSON.stringify({ hasMore: data.hasMore, webhooks: data.data.map(w => ({
  id: w.id, name: w.name, url: w.url, enabled: w.enabled,
  interrupted: w.interrupted, events: w.events,
})) }, null, 2));

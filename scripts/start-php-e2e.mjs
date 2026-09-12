import fs from 'node:fs';
import {createHmac} from 'node:crypto';
import {spawn} from 'node:child_process';
import {parse} from 'dotenv';
const env={...process.env,...parse(fs.readFileSync('.env.local'))};
if(env.ASAAS_API_BASE_URL!=='https://api-sandbox.asaas.com/v3')throw Error('Sandbox required');
env.ASAAS_WEBHOOK_TOKEN=createHmac('sha256',env.ASAAS_API_KEY).update('tocaraul-local-e2e-webhook').digest('hex');
const php=spawn('C:/xampp/php/php.exe',['-S','127.0.0.1:8787','scripts/local-php-router.php'],{env,stdio:'inherit'});
php.on('exit',code=>{process.exitCode=code??1;});

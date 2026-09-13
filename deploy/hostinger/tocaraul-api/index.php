<?php
declare(strict_types=1);
require_once __DIR__.'/admin_settings.php';
require_once __DIR__.'/asaas.php';
require_once __DIR__.'/onboarding.php';
header('Access-Control-Allow-Origin: *');header('Access-Control-Allow-Headers: Authorization, Content-Type');header('Access-Control-Allow-Methods: GET, POST, OPTIONS');if(($_SERVER['REQUEST_METHOD']??'')==='OPTIONS'){http_response_code(204);exit;}
function db():PDO{return settings_db();}function json_response(array $p,int $s=200):never{http_response_code($s);header('Content-Type: application/json; charset=utf-8');echo json_encode($p,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}function body():array{$d=json_decode(file_get_contents('php://input')?:'{}',true);return is_array($d)?$d:[];}function bearer_token():string{$h=$_SERVER['HTTP_AUTHORIZATION']??'';return stripos($h,'Bearer ')===0?trim(substr($h,7)):'';}function public_base_url():string{return runtime_public_url();}function activation_code():string{return(string)random_int(100000,999999);}function device_token():string{return rtrim(strtr(base64_encode(random_bytes(36)),'+/','-_'),'=');}

function mp_oauth_token(array $fields):array{if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension is required');$ch=curl_init('https://api.mercadopago.com/oauth/token');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded','Accept: application/json'],CURLOPT_POSTFIELDS=>http_build_query($fields),CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>20]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);if($raw===false){$e=curl_error($ch);curl_close($ch);throw new RuntimeException($e);}curl_close($ch);$d=json_decode($raw,true);if($status<200||$status>299||!is_array($d)||empty($d['access_token']))throw new RuntimeException('Mercado Pago OAuth failed');return$d;}
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';$method=$_SERVER['REQUEST_METHOD']??'GET';
try{
if(str_starts_with($path,'/mp/oauth/'))json_response(['message'=>'Onboarding Mercado Pago desativado. Cadastre a chave Pix do bar.'],410);
if($method==='GET'&&$path==='/api/health'){db()->query('SELECT 1');json_response(['ok'=>true,'database'=>'online','publicUrlConfigured'=>public_base_url()!=='','paymentProvider'=>'asaas','environment'=>'sandbox','paymentConfigured'=>asaas_ready()]);}
if($method==='POST'&&$path==='/api/onboarding/register'){try{json_response(onboard_venue(body()));}catch(OnboardingError $e){json_response(['message'=>$e->getMessage()],$e->status);}}
if($method==='POST'&&$path==='/api/device/heartbeat'){$token=bearer_token();if($token==='')json_response(['message'=>'Device token is required'],401);$q=db()->prepare("UPDATE devices SET status='ONLINE',lastSeenAt=NOW() WHERE deviceToken=? AND status<>'REVOKED'");$q->execute([$token]);if($q->rowCount()<1)json_response(['message'=>'Invalid device token'],401);json_response(['ok'=>true]);}
if($method==='GET'&&$path==='/api/device/state'){
 $token=bearer_token();if($token==='')json_response(['message'=>'Device token is required'],401);
 // O painel pergunta isso a cada poucos segundos: so vamos ao banco quando o cache vence ou alguem escreve.
 $deviceKey='dev_'.hash('sha256',$token);
 $cachedDevice=state_cache_read($deviceKey,120);
 $venueId=(int)($cachedDevice['venueId']??0);
 if(!$venueId){
  $q=db()->prepare("SELECT * FROM devices WHERE deviceToken=? AND status<>'REVOKED' LIMIT 1");$q->execute([$token]);$device=$q->fetch();
  if(!$device)json_response(['message'=>'Invalid device token'],401);
  if(!$device['venueId']){
   if(strtotime($device['activationCodeExpiresAt'])<time())json_response(['message'=>'Código de ativação expirado'],410);
   json_response(['connection'=>'WAITING_ACTIVATION']);
  }
  $venueId=(int)$device['venueId'];
  state_cache_write($deviceKey,['id'=>(int)$device['id'],'venueId'=>$venueId]);
  db()->prepare("UPDATE devices SET status='ONLINE',lastSeenAt=NOW() WHERE id=?")->execute([(int)$device['id']]);
 }
 $venueKey='venue_'.$venueId;
 // O painel consulta a cada poucos segundos; manter o estado por 60s evita
 // consumir a cota de conexões do MySQL sem deixar pedidos novos esperando.
 $state=state_cache_read($venueKey,60);
 $command=null;
 if($state===null){
  // Uma unica ida ao banco resolve estado, heartbeat e comando pendente.
  db()->prepare("UPDATE devices SET status='ONLINE',lastSeenAt=NOW() WHERE deviceToken=?")->execute([$token]);
  $q=db()->prepare('SELECT * FROM venues WHERE id=?');$q->execute([$venueId]);$venue=$q->fetch();
  $table=ensure_default_table($venueId);
  $q=db()->prepare("SELECT * FROM songRequests WHERE venueId=? AND status IN ('QUEUED','PLAYING') ORDER BY queuePosition ASC,createdAt ASC");$q->execute([$venueId]);$queue=$q->fetchAll();
  $playing=null;foreach($queue as$item)if($item['status']==='PLAYING'){$playing=$item;break;}
  $state=['connection'=>'ONLINE',
   'venue'=>$venue?['id'=>(int)$venue['id'],'name'=>$venue['name'],'code'=>$venue['code'],'announceDedication'=>(int)($venue['announceDedication']??1)===1]:null,
   'nowPlaying'=>$playing?['id'=>(string)$playing['id'],'providerId'=>$playing['providerId'],'title'=>$playing['title'],'artist'=>$playing['artist'],'message'=>$playing['message'],'visitorName'=>$playing['visitorName'],'tableCode'=>$playing['tableCode']]:null,
   'queue'=>array_map(fn($i)=>['id'=>(string)$i['id'],'title'=>$i['title'],'artist'=>$i['artist'],'message'=>$i['message'],'visitorName'=>$i['visitorName'],'tableCode'=>$i['tableCode'],'status'=>$i['status']],$queue),
   'queueSize'=>count(array_filter($queue,fn($i)=>$i['status']==='QUEUED')),
   'qrCodeUrl'=>public_base_url().'/j/'.$table['qrToken'],
   'playbackState'=>$playing?'PLAYING':'IDLE'];
  // O comando fica fora do cache: entregar duas vezes faria a tela pular sozinha.
  state_cache_write($venueKey,$state);
  $d=db();$d->beginTransaction();
  try{
   $q=$d->prepare("SELECT * FROM tvCommands WHERE venueId=? AND status='PENDING' ORDER BY id LIMIT 1 FOR UPDATE");$q->execute([$venueId]);
   if($cmd=$q->fetch()){$command=(string)$cmd['command'];$d->prepare("UPDATE tvCommands SET status='EXECUTED',executedAt=NOW() WHERE id=?")->execute([(int)$cmd['id']]);}
   $d->commit();
  }catch(Throwable$e){$d->rollBack();throw$e;}
 }
 json_response($state+['command'=>$command]);
}
if($method==='GET'&&$path==='/mesas'){$venue=preg_replace('/[^A-Za-z0-9]+/','',(string)($_GET['venue']??''));$q=db()->prepare('SELECT id,name,code FROM venues WHERE code=? OR id=? LIMIT 1');$q->execute([$venue,ctype_digit($venue)?(int)$venue:0]);$v=$q->fetch();if(!$v){http_response_code(404);exit('Bar nao encontrado');}$tq=db()->prepare("SELECT * FROM venueTables WHERE venueId=? AND status='ACTIVE' ORDER BY id");$tq->execute([(int)$v['id']]);$tables=$tq->fetchAll();if(!$tables)$tables=[ensure_default_table((int)$v['id'])];header('Content-Type:text/html;charset=utf-8');$name=htmlspecialchars((string)$v['name'],ENT_QUOTES,'UTF-8');$connected=asaas_ready();echo '<!doctype html><html><head><meta charset=utf-8><meta name=viewport content="width=device-width,initial-scale=1"><title>QR Codes · TocaRaul</title><style>body{font-family:system-ui;background:#eee;padding:24px}.top{display:flex;gap:10px;flex-wrap:wrap}.btn{background:#111;color:#fff;padding:12px 16px;border:0;border-radius:10px;text-decoration:none}.mp{background:#ffcc00;color:#111}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px;margin-top:20px}.card{background:#fff;border:2px dashed #111;border-radius:20px;padding:22px;text-align:center}.qr{width:220px;height:220px;margin:auto}@media print{.top{display:none}body{background:#fff}.card{break-inside:avoid}}</style></head><body><h1>TocaRaul · '. $name .'</h1><div class=top><button class=btn onclick="window.print()">Imprimir / salvar PDF</button>'.($connected?'<span class="btn">Asaas · sandbox</span>':'<span class="btn">Asaas aguardando configuração</span>').'</div><div class=grid>';foreach($tables as$t){$url=public_base_url().'/j/'.$t['qrToken'];echo '<div class=card><h2>'.htmlspecialchars((string)$t['label']).'</h2><div class=qr data-url="'.htmlspecialchars($url,ENT_QUOTES).'" id="qr'.(int)$t['id'].'"></div><p>Escaneie para pedir música</p><small>'.htmlspecialchars($url).'</small></div>';}echo '</div><script src="/assets/qrcode.js"></script><script>document.querySelectorAll(".qr").forEach(e=>new QRCode(e,{text:e.dataset.url,width:220,height:220}));</script></body></html>';exit;}
json_response(['message'=>'Not found'],404);
}catch(Throwable$e){error_log('[TocaRaul API] '.$e->getMessage());json_response(['message'=>'Internal server error'],500);}

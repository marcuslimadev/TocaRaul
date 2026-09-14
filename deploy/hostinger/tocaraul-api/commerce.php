<?php
declare(strict_types=1);
require_once __DIR__.'/admin_settings.php';
require_once __DIR__.'/asaas.php';
require_once __DIR__.'/moderation.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Request-Id, X-Signature');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if(($_SERVER['REQUEST_METHOD']??'')==='OPTIONS'){http_response_code(204);exit;}
function db():PDO{return settings_db();}
function out(array $p,int $s=200):never{http_response_code($s);echo json_encode($p,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
function input():array{$v=json_decode(file_get_contents('php://input')?:'{}',true);return is_array($v)?$v:[];}
function bearer():string{$h=$_SERVER['HTTP_AUTHORIZATION']??'';return stripos($h,'Bearer ')===0?trim(substr($h,7)):'';}
function device():array{$t=bearer();if($t==='')out(['message'=>'Device token required'],401);$q=db()->prepare("SELECT * FROM devices WHERE deviceToken=? AND status<>'REVOKED' LIMIT 1");$q->execute([$t]);$d=$q->fetch();if(!$d||empty($d['venueId']))out(['message'=>'Activated device required'],401);return$d;}
function schema():void{$d=db();$d->exec("CREATE TABLE IF NOT EXISTS financeLedger(id bigint AUTO_INCREMENT PRIMARY KEY,venueId int NOT NULL,requestId int NULL,paymentId int NULL,type varchar(30) NOT NULL,grossCents int NOT NULL DEFAULT 0,barCents int NOT NULL DEFAULT 0,platformCents int NOT NULL DEFAULT 0,taxCents int NOT NULL DEFAULT 0,feeCents int NOT NULL DEFAULT 0,payoutId bigint NULL,createdAt timestamp DEFAULT CURRENT_TIMESTAMP,INDEX(venueId,payoutId,type),UNIQUE KEY uniq_request_credit(requestId,type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");$d->exec("CREATE TABLE IF NOT EXISTS payouts(id bigint AUTO_INCREMENT PRIMARY KEY,venueId int NOT NULL,amountCents int NOT NULL,feeCents int NOT NULL DEFAULT 0,netCents int NOT NULL,pixKeySnapshot varchar(180) NOT NULL,status varchar(30) NOT NULL DEFAULT 'PENDING',provider varchar(40) NULL,providerTxId varchar(180) NULL,createdAt timestamp DEFAULT CURRENT_TIMESTAMP,paidAt timestamp NULL,INDEX(venueId,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");$d->exec("CREATE TABLE IF NOT EXISTS barPlaylist(id int AUTO_INCREMENT PRIMARY KEY,venueId int NOT NULL,providerId varchar(255) NOT NULL,title varchar(255) NOT NULL,artist varchar(255) NOT NULL,position int NOT NULL DEFAULT 0,status varchar(20) NOT NULL DEFAULT 'QUEUED',createdAt timestamp DEFAULT CURRENT_TIMESTAMP,updatedAt timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(venueId,status,position)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");$d->exec("CREATE TABLE IF NOT EXISTS tvCommands(id bigint AUTO_INCREMENT PRIMARY KEY,venueId int NOT NULL,command varchar(20) NOT NULL,status varchar(20) NOT NULL DEFAULT 'PENDING',createdAt timestamp DEFAULT CURRENT_TIMESTAMP,executedAt timestamp NULL,INDEX(venueId,status,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}
function mp(string $method,string $path,?array $body=null,?string $idem=null):array{$token=runtime_mp_access_token();if($token==='')throw new RuntimeException('Configure o Access Token do Mercado Pago em /admin');$ch=curl_init('https://api.mercadopago.com'.$path);$headers=['Authorization: Bearer '.$token,'Accept: application/json','Content-Type: application/json'];if($idem)$headers[]='X-Idempotency-Key: '.$idem;curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25]);if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$raw=curl_exec($ch);if($raw===false){$e=curl_error($ch);curl_close($ch);throw new RuntimeException('Falha ao conectar Mercado Pago: '.$e);}$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);$data=json_decode($raw,true);if(!is_array($data))$data=[];if($status<200||$status>299)throw new RuntimeException('Mercado Pago '.$status.': '.($data['message']??$data['error']??'erro'));return$data;}
function sig_ok(string $id):bool{$secret=runtime_mp_webhook_secret();$sig=(string)($_SERVER['HTTP_X_SIGNATURE']??'');$rid=(string)($_SERVER['HTTP_X_REQUEST_ID']??'');if($secret===''||$sig==='')return false;$ts='';$v1='';foreach(explode(',',$sig)as$p){[$k,$v]=array_pad(explode('=',trim($p),2),2,'');if($k==='ts')$ts=$v;if($k==='v1')$v1=$v;}if($ts===''||$v1==='')return false;$m=($id!==''?'id:'.strtolower($id).';':'').($rid!==''?'request-id:'.$rid.';':'').'ts:'.$ts.';';return hash_equals(hash_hmac('sha256',$m,$secret),$v1);}
function mp_status(string $s):string{return match($s){'approved'=>'APPROVED','rejected'=>'REJECTED','cancelled','canceled'=>'CANCELLED',default=>'PENDING'};}
function has_blocked_language(string $text):bool{
 $n=mb_strtolower($text,'UTF-8');
 foreach(['pau','cu','rola','pinto','puta','porra','foda','fuck','shit','sex','porn'] as $w)if(preg_match('/\b'.preg_quote($w,'/').'\b/u',$n))return true;
 foreach(['putaria','vagabunda','vadia','caralho','buceta','xoxota','pirocudo','viado','biscate','retardado','mongoloide','estuprador','estupro','pedofil','pedófil','bitch','pussy','faggot','whore','slut'] as $w)if(mb_strpos($n,$w)!==false)return true;
 return false;
}
function nextpos(PDO$d,int$v):int{$q=$d->prepare("SELECT COALESCE(MAX(queuePosition),0)+1 n FROM songRequests WHERE venueId=? AND status IN('QUEUED','PLAYING')");$q->execute([$v]);return(int)($q->fetch()['n']??1);}
function credit_ledger(PDO$d,array$r,int$paymentId):void{$gross=(int)$r['amountCents'];$platformPct=runtime_platform_percent();$platform=(int)round($gross*$platformPct/100);$bar=$gross-$platform;$q=$d->prepare("INSERT IGNORE INTO financeLedger(venueId,requestId,paymentId,type,grossCents,barCents,platformCents) VALUES(?,?,?,'SALE',?,?,?)");$q->execute([(int)$r['venueId'],(int)$r['id'],$paymentId,$gross,$bar,$platform]);}
schema();
function ensure_asaas_columns():void {
 $d=db();
 foreach(['songRequests'=>['barShareCents'=>"int NOT NULL DEFAULT 0"],'financeLedger'=>['balanceStatus'=>"varchar(20) NOT NULL DEFAULT 'PENDING'"]] as $table=>$columns) {
  $existing=$d->query("SHOW COLUMNS FROM ".$table)->fetchAll(PDO::FETCH_COLUMN);
  foreach($columns as $name=>$definition) if(!in_array($name,$existing,true)) $d->exec("ALTER TABLE ".$table." ADD COLUMN ".$name." ".$definition);
 }
}
ensure_asaas_columns();$method=$_SERVER['REQUEST_METHOD']??'GET';$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
try{
if($method==='GET'&&$path==='/api/commerce/health'){db()->query('SELECT 1');out(['ok'=>true,'database'=>'online','paymentProvider'=>'asaas','environment'=>'sandbox','paymentConfigured'=>asaas_ready(),'minimumPayoutCents'=>5000,'payoutsEnabled'=>false]);}
if($method==='GET'&&$path==='/api/commerce/search'){
 $query=trim((string)($_GET['q']??''));
 if(mb_strlen($query)<2)out(['results'=>[]]);
 $key=runtime_youtube_api_key();
 if($key==='')out(['message'=>'Busca de músicas ainda não configurada pelo TocaRaul.'],409);
 $url='https://www.googleapis.com/youtube/v3/search?'.http_build_query(['part'=>'snippet','type'=>'video','videoCategoryId'=>10,'topicId'=>'/m/04rlf','maxResults'=>5,'videoEmbeddable'=>'true','safeSearch'=>'strict','q'=>$query,'key'=>$key]);
 $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>12]);
 $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 if($raw===false)out(['message'=>'Busca indisponível no momento.'],502);
 $data=json_decode($raw,true);
 if($status<200||$status>=300||!is_array($data)||!isset($data['items'])){error_log('[TocaRaul] YouTube search HTTP '.$status.' '.$raw);out(['message'=>'Busca indisponível no momento.'],502);}
 $blocked=[];
 $qr=trim((string)($_GET['qrToken']??''));
 if($qr!==''){
  $q=db()->prepare("SELECT venueId FROM venueTables WHERE qrToken=? AND status='ACTIVE' LIMIT 1");$q->execute([$qr]);
  if($row=$q->fetch())$blocked=venue_blocked_song_ids((int)$row['venueId']);
 }
 $results=[];
 foreach($data['items'] as $item){
  $videoId=(string)($item['id']['videoId']??'');
  if(!preg_match('/^[A-Za-z0-9_-]{11}$/',$videoId))continue;
  if(in_array('youtube:'.$videoId,$blocked,true))continue;
  $snippet=$item['snippet']??[];
  $title=html_entity_decode((string)($snippet['title']??''),ENT_QUOTES|ENT_HTML5,'UTF-8');
  $artist=html_entity_decode((string)($snippet['channelTitle']??''),ENT_QUOTES|ENT_HTML5,'UTF-8');
  $thumb=$snippet['thumbnails']['medium']['url']??$snippet['thumbnails']['default']['url']??'';
  $results[]=['id'=>'youtube:'.$videoId,'title'=>mb_substr($title,0,180),'artist'=>mb_substr($artist,0,180),'thumbnail'=>(string)$thumb];
 }
 out(['results'=>$results]);
}
if($method==='POST'&&$path==='/api/commerce/request'){
 $p=input();$qr=trim((string)($p['qrToken']??''));$provider=trim((string)($p['providerId']??''));$title=trim((string)($p['title']??''));$artist=trim((string)($p['artist']??''));$msg=trim((string)($p['message']??''));$name=trim((string)($p['visitorName']??'Cliente'));
 if(!preg_match('/^youtube:[A-Za-z0-9_-]{11}$/',$provider)||$title===''||$artist===''||strlen($name)>80||strlen($title)>180||strlen($artist)>180||strlen($msg)>180)out(['message'=>'Confira a música e o nome.'],400);
 if(has_blocked_language($name)||has_blocked_language($msg))out(['message'=>'Conteúdo impróprio detectado no nome ou na dedicatória. Ajuste o texto e tente novamente.'],400);
 $d=db();$q=$d->prepare("SELECT vt.*,v.name venueName,v.ownerDocument,v.musicPriceCents,v.dedicationPriceCents,v.splitBarPercent FROM venueTables vt JOIN venues v ON v.id=vt.venueId WHERE vt.qrToken=? AND vt.status='ACTIVE' LIMIT 1");$q->execute([$qr]);$t=$q->fetch();if(!$t)out(['message'=>'Mesa não encontrada'],404);
 $document=preg_replace('/\\D/','',(string)($t['ownerDocument']??''));
 if(!preg_match('/^(\\d{11}|\\d{14})$/',$document))out(['message'=>'Bar sem documento cadastrado. Peça ao responsável para atualizar o cadastro.'],409);
 if(venue_blocks_song((int)$t['venueId'],$provider))out(['message'=>'Esta música foi bloqueada pelo bar. Escolha outra.'],409);
 if(venue_blocks_text((int)$t['venueId'],$msg)||venue_blocks_text((int)$t['venueId'],$name))out(['message'=>'O nome ou a dedicatória contém palavra bloqueada por este bar. Ajuste o texto.'],400);
 $amount=(int)$t['musicPriceCents']+($msg!==''?(int)$t['dedicationPriceCents']:0);
 if($amount<500)out(['message'=>'O pedido mínimo por Pix é R$ 5,00. Peça ao responsável para atualizar os preços do bar.'],409);
 $bar=(int)round($amount*(int)$t['splitBarPercent']/100);
 $q=$d->prepare("INSERT INTO songRequests(venueId,visitorName,tableCode,providerId,title,artist,message,amountCents,barShareCents,status) VALUES(?,?,?,?,?,?,?,?,?,'AWAITING_PAYMENT')");$q->execute([(int)$t['venueId'],$name?:'Cliente',(string)$t['label'],$provider,$title,$artist,$msg?:null,$amount,$bar]);$rid=(int)$d->lastInsertId();
 $m=asaas_create_pix($rid,$amount,'TocaRaul: '.$title.' - '.$artist,(string)$t['venueName'],$document);$ext=(string)$m['id'];
 $q=$d->prepare("INSERT INTO payments(requestId,provider,externalId,status,amountCents) VALUES(?,'asaas',?,'PENDING',?)");$q->execute([$rid,$ext,$amount]);$pid=(int)$d->lastInsertId();
 $pix=asaas_api('GET','/payments/'.rawurlencode($ext).'/pixQrCode');$payload=(string)($pix['payload']??'');if($payload==='')throw new RuntimeException('Asaas não retornou Pix. Consulte o pedido antes de tentar novamente.');
 $d->prepare('UPDATE payments SET pixCopyPaste=? WHERE id=?')->execute([$payload,$pid]);
 out(['requestId'=>$rid,'paymentId'=>$pid,'externalId'=>$ext,'amountCents'=>$amount,'pixCopyPaste'=>$payload,'status'=>'AWAITING_PAYMENT','environment'=>'sandbox'],201);
}
if($method==='POST'&&$path==='/api/commerce/mock-confirm'){
 $p=input();$rid=(int)($p['requestId']??0);
 $q=db()->prepare("SELECT * FROM payments WHERE requestId=? ORDER BY id DESC LIMIT 1");$q->execute([$rid]);$pay=$q->fetch();
 if(!$pay)out(['message'=>'Pedido não encontrado'],404);
 if($pay['status']!=='APPROVED')asaas_sandbox_confirm((string)$pay['externalId']);
 out(asaas_reconcile((string)$pay['externalId']));
}
if($method==='GET'&&$path==='/api/commerce/payment'){$rid=(int)($_GET['requestId']??0);if($event=payment_event_read($rid))out($event);$q=db()->prepare("SELECT p.id paymentId,p.status paymentStatus,p.amountCents,r.status requestStatus FROM payments p JOIN songRequests r ON r.id=p.requestId WHERE p.requestId=? ORDER BY p.id DESC LIMIT 1");$q->execute([$rid]);$x=$q->fetch();if(!$x)out(['message'=>'Pagamento nao encontrado'],404);payment_event_write($rid,['requestId'=>$rid]+$x+['updatedAt'=>time()]);out(['requestId'=>$rid]+$x);}
if($method==='POST'&&$path==='/api/webhooks/asaas'){
 $expected=runtime_setting('asaas_webhook_token','ASAAS_WEBHOOK_TOKEN');
 if($expected===''||!hash_equals($expected,(string)($_SERVER['HTTP_ASAAS_ACCESS_TOKEN']??'')))out(['message'=>'Token inválido'],401);
 $p=input();$id=(string)($p['payment']['id']??'');if($id==='')out(['ok'=>true,'ignored'=>true]);
 out(asaas_reconcile($id));
}
if($method==='POST'&&$path==='/api/mercadopago/webhook'){$p=input();$id=(string)($_GET['data_id']??$_GET['data.id']??($p['data']['id']??''));if($id==='')out(['message'=>'id ausente'],400);if(!sig_ok($id))out(['message'=>'assinatura invalida'],401);$d=db();$q=$d->prepare("SELECT p.*,r.* FROM payments p JOIN songRequests r ON r.id=p.requestId WHERE p.externalId=? LIMIT 1");$q->execute([$id]);$row=$q->fetch();if(!$row)out(['ok'=>true,'ignored'=>true]);$m=mp('GET','/v1/payments/'.rawurlencode($id));$status=mp_status((string)($m['status']??''));$d->beginTransaction();try{$q=$d->prepare('SELECT * FROM payments WHERE id=? FOR UPDATE');$q->execute([(int)$row['id']]);$pay=$q->fetch();$q=$d->prepare('SELECT * FROM songRequests WHERE id=? FOR UPDATE');$q->execute([(int)$row['requestId']]);$req=$q->fetch();if(!$pay||!$req)throw new RuntimeException('Pagamento inconsistente');if($status==='APPROVED'){$d->prepare("UPDATE payments SET status='APPROVED' WHERE id=?")->execute([(int)$pay['id']]);if($req['status']==='AWAITING_PAYMENT'){$d->prepare("UPDATE songRequests SET status='QUEUED',queuePosition=? WHERE id=?")->execute([nextpos($d,(int)$req['venueId']),(int)$req['id']]);credit_ledger($d,$req,(int)$pay['id']);}}elseif($status==='REJECTED'){$d->prepare("UPDATE payments SET status='REJECTED' WHERE id=?")->execute([(int)$pay['id']]);$d->prepare("UPDATE songRequests SET status='FAILED' WHERE id=? AND status='AWAITING_PAYMENT'")->execute([(int)$req['id']]);}elseif($status==='CANCELLED'){$d->prepare("UPDATE payments SET status='CANCELLED' WHERE id=?")->execute([(int)$pay['id']]);$d->prepare("UPDATE songRequests SET status='CANCELLED' WHERE id=? AND status='AWAITING_PAYMENT'")->execute([(int)$req['id']]);}$d->commit();}catch(Throwable$e){$d->rollBack();throw$e;}out(['ok'=>true,'status'=>$status]);}
if($method==='POST'&&$path==='/api/player/token'){
 ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Strict');
 if(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')ini_set('session.cookie_secure','1');
 session_name('tocaraul_bar');session_start();
 $vid=(int)($_SESSION['venue']??0);
 if($vid<=0)out(['message'=>'Entre com a conta do bar para abrir a tela.'],401);
 $p=input();$name=trim((string)($p['name']??''));
 $d=db();
 for($try=0;$try<5;$try++){
  $label='auto'.bin2hex(random_bytes(4));
  $token=rtrim(strtr(base64_encode(random_bytes(36)),'+/','-_'),'=');
  try{
   $q=$d->prepare("INSERT INTO devices(venueId,name,activationCode,activationCodeExpiresAt,deviceToken,status,lastSeenAt) VALUES(?,?,?,NOW(),?,'ONLINE',NOW())");
   $q->execute([$vid,$name!==''?mb_substr($name,0,80):'Tela do bar',$label,$token]);
   out(['deviceToken'=>$token,'deviceId'=>(int)$d->lastInsertId()],201);
  }catch(PDOException $e){if($try===4)throw $e;}
 }
}
if($method==='POST'&&$path==='/api/player/claim'){$dev=device();$vid=(int)$dev['venueId'];$d=db();$d->beginTransaction();try{$q=$d->prepare("SELECT * FROM songRequests WHERE venueId=? AND status='PLAYING' ORDER BY updatedAt LIMIT 1 FOR UPDATE");$q->execute([$vid]);$r=$q->fetch();if(!$r){$q=$d->prepare("SELECT * FROM songRequests WHERE venueId=? AND status='QUEUED' ORDER BY queuePosition,createdAt LIMIT 1 FOR UPDATE");$q->execute([$vid]);$r=$q->fetch();if($r){$d->prepare("UPDATE songRequests SET status='PLAYING' WHERE id=?")->execute([(int)$r['id']]);}}if($r){$track=['id'=>(string)$r['id'],'providerId'=>$r['providerId'],'title'=>$r['title'],'artist'=>$r['artist'],'message'=>$r['message'],'tableCode'=>$r['tableCode'],'visitorName'=>$r['visitorName'],'source'=>'PAID'];}else{$q=$d->prepare("SELECT * FROM barPlaylist WHERE venueId=? AND status='PLAYING' ORDER BY updatedAt LIMIT 1 FOR UPDATE");$q->execute([$vid]);$b=$q->fetch();if(!$b){$q=$d->prepare("SELECT * FROM barPlaylist WHERE venueId=? AND status='QUEUED' ORDER BY position,id LIMIT 1 FOR UPDATE");$q->execute([$vid]);$b=$q->fetch();if($b)$d->prepare("UPDATE barPlaylist SET status='PLAYING' WHERE id=?")->execute([(int)$b['id']]);}$track=$b?['id'=>(string)(-(int)$b['id']),'providerId'=>$b['providerId'],'title'=>$b['title'],'artist'=>$b['artist'],'message'=>null,'tableCode'=>null,'source'=>'BAR']:null;}$d->commit();}catch(Throwable$e){$d->rollBack();throw$e;}invalidate_venue_state((int)$dev['venueId']);out(['track'=>$track]);}
if($method==='POST'&&$path==='/api/player/complete'){$dev=device();$p=input();$id=(int)($p['requestId']??0);$result=strtoupper((string)($p['result']??'PLAYED'));if(!in_array($result,['PLAYED','SKIPPED'],true)||$id===0)out(['message'=>'Conclusao invalida'],400);if($id>0){$q=db()->prepare("UPDATE songRequests SET status=? WHERE id=? AND venueId=? AND status='PLAYING'");$q->execute([$result,$id,(int)$dev['venueId']]);}else{$q=db()->prepare("UPDATE barPlaylist SET status=? WHERE id=? AND venueId=? AND status='PLAYING'");$q->execute([$result,abs($id),(int)$dev['venueId']]);}invalidate_venue_state((int)$dev['venueId']);out(['ok'=>true]);}
if($method==='POST'&&$path==='/api/player/random'){$dev=device();$vid=(int)$dev['venueId'];$d=db();$d->beginTransaction();try{$q=$d->prepare("SELECT * FROM songRequests WHERE venueId=? AND status='PLAYED' ORDER BY RAND() LIMIT 1 FOR UPDATE");$q->execute([$vid]);$r=$q->fetch();if($r){$d->prepare("UPDATE songRequests SET status='PLAYING' WHERE id=?")->execute([(int)$r['id']]);$track=['id'=>(string)$r['id'],'providerId'=>$r['providerId'],'title'=>$r['title'],'artist'=>$r['artist'],'message'=>$r['message'],'tableCode'=>$r['tableCode'],'visitorName'=>$r['visitorName'],'source'=>'RANDOM_HISTORY'];}else{$q=$d->prepare("SELECT * FROM barPlaylist WHERE venueId=? AND status='PLAYED' ORDER BY RAND() LIMIT 1 FOR UPDATE");$q->execute([$vid]);$b=$q->fetch();if($b){$d->prepare("UPDATE barPlaylist SET status='PLAYING' WHERE id=?")->execute([(int)$b['id']]);$track=['id'=>(string)(-(int)$b['id']),'providerId'=>$b['providerId'],'title'=>$b['title'],'artist'=>$b['artist'],'message'=>null,'tableCode'=>null,'source'=>'RANDOM_HISTORY'];}else$track=null;}$d->commit();}catch(Throwable$e){$d->rollBack();throw$e;}invalidate_venue_state($vid);out(['track'=>$track]);}
if($method==='POST'&&$path==='/api/player/command'){$dev=device();$d=db();$d->beginTransaction();try{$q=$d->prepare("SELECT * FROM tvCommands WHERE venueId=? AND status='PENDING' ORDER BY id LIMIT 1 FOR UPDATE");$q->execute([(int)$dev['venueId']]);$cmd=$q->fetch();if($cmd)$d->prepare("UPDATE tvCommands SET status='EXECUTED',executedAt=NOW() WHERE id=?")->execute([(int)$cmd['id']]);$d->commit();}catch(Throwable$e){$d->rollBack();throw$e;}out(['command'=>$cmd?$cmd['command']:null]);}
out(['message'=>'Not found'],404);
}catch(Throwable$e){error_log('[TocaRaul] '.$e->getMessage());out(['message'=>$e->getMessage()],500);}

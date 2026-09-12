<?php
declare(strict_types=1);
require_once __DIR__.'/admin_settings.php';

class OnboardingError extends RuntimeException {
    public function __construct(string $message, public int $status = 400) { parent::__construct($message); }
}

function venue_code(string $n):string{$b=strtoupper(preg_replace('/[^A-Za-z0-9]+/','',iconv('UTF-8','ASCII//TRANSLIT',$n)?:$n));return substr($b?:'BAR',0,8).random_int(100,999);}
function unique_venue_code(string $n):string{do{$c=venue_code($n);$q=settings_db()->prepare('SELECT id FROM venues WHERE code=?');$q->execute([$c]);}while($q->fetch());return $c;}

function ensure_onboarding_columns():void{
 $columns=['ownerPasswordHash'=>"ADD COLUMN ownerPasswordHash varchar(255)",'ownerDocument'=>"ADD COLUMN ownerDocument varchar(32)",'ownerPhone'=>"ADD COLUMN ownerPhone varchar(32)",'pixKeyType'=>"ADD COLUMN pixKeyType varchar(32)",'pixKey'=>"ADD COLUMN pixKey varchar(180)",'splitBarPercent'=>"ADD COLUMN splitBarPercent int NOT NULL DEFAULT 70",'splitPlatformPercent'=>"ADD COLUMN splitPlatformPercent int NOT NULL DEFAULT 30",'splitAcceptedAt'=>"ADD COLUMN splitAcceptedAt timestamp NULL",'termsAcceptedAt'=>"ADD COLUMN termsAcceptedAt timestamp NULL",'mercadoPagoUserId'=>"ADD COLUMN mercadoPagoUserId varchar(64)",'mercadoPagoAccessToken'=>"ADD COLUMN mercadoPagoAccessToken text",'mercadoPagoRefreshToken'=>"ADD COLUMN mercadoPagoRefreshToken text",'mercadoPagoPublicKey'=>"ADD COLUMN mercadoPagoPublicKey text",'mercadoPagoTokenExpiresAt'=>"ADD COLUMN mercadoPagoTokenExpiresAt timestamp NULL",'announceDedication'=>"ADD COLUMN announceDedication tinyint(1) NOT NULL DEFAULT 1",'partnerId'=>"ADD COLUMN partnerId int NULL"];
 $q=settings_db()->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venues'");
 $existing=array_column($q->fetchAll(),'COLUMN_NAME');
 foreach($columns as $n=>$sql)if(!in_array($n,$existing,true))settings_db()->exec("ALTER TABLE venues $sql");
}

function ensure_default_table(int $venueId):array{$q=settings_db()->prepare("SELECT * FROM venueTables WHERE venueId=? AND label='Mesa 01' LIMIT 1");$q->execute([$venueId]);if($t=$q->fetch())return $t;$token=substr(strtolower(bin2hex(random_bytes(8))),0,14);$i=settings_db()->prepare("INSERT INTO venueTables(venueId,label,qrToken) VALUES(?,'Mesa 01',?)");$i->execute([$venueId,$token]);$q->execute([$venueId]);return $q->fetch();}

/**
 * Opens a named session using that name's own cookie.
 * Needed because PHP keeps the id of a previously opened session when you only
 * change session_name(): reading the short-lived Google session would otherwise
 * hijack (and silently drop) the long-lived bar session.
 */
function open_session(string $name):void{
 if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
 session_name($name);
 $cookie=(string)($_COOKIE[$name]??'');
 session_id($cookie!==''&&preg_match('/^[A-Za-z0-9,\-]{16,128}$/',$cookie)?$cookie:'');
 session_start();
}

/** Verifies a bar owner login. Returns the venue row or null. */
function venue_login(string $code,string $password):?array{
 $code=preg_replace('/[^A-Za-z0-9]/','',$code);
 if($code===''||$password==='')return null;
 $q=settings_db()->prepare('SELECT * FROM venues WHERE code=? LIMIT 1');$q->execute([$code]);
 $v=$q->fetch();
 if(!$v||empty($v['ownerPasswordHash'])||!password_verify($password,(string)$v['ownerPasswordHash']))return null;
 return $v;
}

/** Links a screen that is showing a pairing code to a bar that already exists. */
function pair_device(int $venueId,string $code,string $tvName='TV Principal'):array{
 $code=preg_replace('/\D+/','',$code);
 if($code==='')throw new OnboardingError('Informe o código de 6 dígitos que aparece na TV.',400);
 $pdo=settings_db();
 $q=$pdo->prepare('SELECT * FROM devices WHERE activationCode=? LIMIT 1');$q->execute([$code]);
 $device=$q->fetch();
 if(!$device||$device['status']!=='PENDING_ACTIVATION')throw new OnboardingError('Código não encontrado. Confira o número na tela da TV.',404);
 if(strtotime($device['activationCodeExpiresAt'])<time())throw new OnboardingError('Código expirado. Recarregue a tela da TV para gerar outro.',410);
 $pdo->beginTransaction();
 try{
  $q=$pdo->prepare('SELECT * FROM devices WHERE id=? FOR UPDATE');$q->execute([$device['id']]);$locked=$q->fetch();
  if(!$locked||$locked['status']!=='PENDING_ACTIVATION')throw new OnboardingError('Essa TV já foi conectada.',409);
  $pdo->prepare("UPDATE devices SET venueId=?,name=?,status='ONLINE',lastSeenAt=NOW() WHERE id=?")->execute([$venueId,$tvName?:'TV Principal',$device['id']]);
  $pdo->commit();
 }catch(Throwable $e){$pdo->rollBack();throw $e;}
 ensure_default_table($venueId);
 return ['ok'=>true,'deviceId'=>(int)$device['id']];
}

/**
 * Registers the bar and its owner login. Single source of truth for onboarding.
 * activationCode is optional: when given, the pending TV is paired right away;
 * when omitted, the bar is created first and pairs a TV later from its panel.
 */
function onboard_venue(array $p, ?int $partnerId = null): array {
 $code=preg_replace('/\D+/','',(string)($p['activationCode']??''));
 $owner=trim((string)($p['ownerName']??''));
 $bar=trim((string)($p['barName']??''));
 $phone=preg_replace('/\D+/','',(string)($p['phone']??''));
 $email=trim((string)($p['email']??''));
 $doc=preg_replace('/\D+/','',(string)($p['document']??''));
 $pixType=strtoupper(trim((string)($p['pixKeyType']??'')));
 $pix=trim((string)($p['pixKey']??''));
 $password=(string)($p['password']??'');
 $tv=trim((string)($p['tvName']??'TV Principal'));
 if(!in_array($pixType,['CPF','CNPJ','EMAIL','PHONE','EVP'],true)||$pix===''||strlen($pix)>180||strlen($password)<10)
  throw new OnboardingError('Informe chave Pix, tipo e senha de pelo menos 10 caracteres.',400);
 if($owner===''||$bar===''||$phone===''||$doc==='')throw new OnboardingError('Preencha cadastro, documento e telefone',400);
 if(empty($p['acceptedTerms']))throw new OnboardingError('Aceite os termos',400);
 ensure_onboarding_columns();
 $pdo=settings_db();
 $device=null;
 if($code!==''){
  $q=$pdo->prepare('SELECT * FROM devices WHERE activationCode=? LIMIT 1');$q->execute([$code]);$device=$q->fetch();
  if(!$device||$device['status']!=='PENDING_ACTIVATION')throw new OnboardingError('Codigo da TV nao encontrado',404);
  if(strtotime($device['activationCodeExpiresAt'])<time())throw new OnboardingError('Codigo da TV expirado. Reinicie o app na TV.',410);
 }
 $pdo->beginTransaction();
 try{
  if($device){
   $q=$pdo->prepare("SELECT * FROM devices WHERE id=? FOR UPDATE");$q->execute([$device['id']]);$locked=$q->fetch();
   if(!$locked||$locked['status']!=='PENDING_ACTIVATION')throw new OnboardingError('TV já ativada.',409);
  }
  $open='bar-'.bin2hex(random_bytes(16));
  $q=$pdo->prepare("INSERT INTO users(openId,name,email,loginMethod,role) VALUES(?,?,?,'phone','admin')");
  $q->execute([$open,$owner,$email?:null]);
  $uid=(int)$pdo->lastInsertId();
  $venueCode=unique_venue_code($bar);
  $q=$pdo->prepare('INSERT INTO venues(ownerId,code,name,musicPriceCents,dedicationPriceCents,splitBarPercent,splitPlatformPercent,ownerDocument,ownerPhone,pixKeyType,pixKey,ownerPasswordHash,partnerId,splitAcceptedAt,termsAcceptedAt) VALUES(?,?,?,500,0,70,30,?,?,?,?,?,?,NOW(),NOW())');
  $q->execute([$uid,$venueCode,$bar,$doc,$phone,$pixType,$pix,password_hash($password,PASSWORD_DEFAULT),$partnerId]);
  $venueId=(int)$pdo->lastInsertId();
  if($device)$pdo->prepare("UPDATE devices SET venueId=?,name=?,status='ONLINE',lastSeenAt=NOW() WHERE id=?")->execute([$venueId,$tv?:'TV Principal',$device['id']]);
  $pdo->commit();
 }catch(Throwable $e){$pdo->rollBack();throw $e;}
 $table=ensure_default_table($venueId);
 $base=rtrim(runtime_public_url(),'/');
 return ['ok'=>true,'deviceToken'=>$device['deviceToken']??null,'tvPaired'=>(bool)$device,'venue'=>['id'=>$venueId,'name'=>$bar,'code'=>$venueCode],
  'tableUrl'=>$base.'/j/'.$table['qrToken'],'tablesPrintUrl'=>$base.'/mesas?venue='.$venueCode,
  'ownerPanelUrl'=>$base.'/bar','paymentProvider'=>'asaas','environment'=>'sandbox'];
}

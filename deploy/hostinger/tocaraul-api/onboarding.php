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

/**
 * Creates the tables/columns shared by the admin panel (/bar) and the TV
 * display (/tv). Both pages call this on every request, so it must stay a
 * cheap no-op once the schema already exists.
 */
function ensure_bar_schema():void{
 $d=settings_db();
 $d->exec("CREATE TABLE IF NOT EXISTS barPlaylist(id int AUTO_INCREMENT PRIMARY KEY,venueId int NOT NULL,providerId varchar(255) NOT NULL,title varchar(255) NOT NULL,artist varchar(255) NOT NULL,position int NOT NULL DEFAULT 0,status varchar(20) NOT NULL DEFAULT 'QUEUED',createdAt timestamp DEFAULT CURRENT_TIMESTAMP,INDEX(venueId,status,position)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $d->exec("CREATE TABLE IF NOT EXISTS tvCommands(id int AUTO_INCREMENT PRIMARY KEY,venueId int NOT NULL,command varchar(20) NOT NULL,status varchar(20) NOT NULL DEFAULT 'PENDING',createdAt timestamp DEFAULT CURRENT_TIMESTAMP,executedAt timestamp NULL,INDEX(venueId,status,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $existing=array_column($d->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venues'")->fetchAll(),'COLUMN_NAME');
 if(!in_array('announceDedication',$existing,true))$d->exec("ALTER TABLE venues ADD COLUMN announceDedication tinyint(1) NOT NULL DEFAULT 1");
 if(!in_array('logoPath',$existing,true))$d->exec("ALTER TABLE venues ADD COLUMN logoPath varchar(200) NULL");
 // Marca quando um pedido pago esta sendo reaproveitado como preenchimento da
 // fila aleatoria (historico), para a TV saber que aquela dedicatoria nao vale
 // mais para quem esta assistindo agora.
 $requestCols=array_column($d->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='songRequests'")->fetchAll(),'COLUMN_NAME');
 if($requestCols&&!in_array('isReplay',$requestCols,true))$d->exec("ALTER TABLE songRequests ADD COLUMN isReplay tinyint(1) NOT NULL DEFAULT 0");
}

/**
 * Opens the shared bar session (Google bridge + CSRF token), used by both
 * /bar and /tv so a login on one carries over to the other.
 */
function open_bar_session():void{
 ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');
 if(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')ini_set('session.cookie_secure','1');
 $oauthVenue=null;
 if(!empty($_COOKIE['tocaraul_google'])){open_session('tocaraul_google');if(isset($_SESSION['venue']))$oauthVenue=(int)$_SESSION['venue'];}
 open_session('tocaraul_bar');
 if($oauthVenue&&!isset($_SESSION['venue']))$_SESSION['venue']=$oauthVenue;
 if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
}

/**
 * Registers the bar and its owner login. Single source of truth for onboarding.
 * There is no pairing code: the owner logs into /bar with code+password, and
 * that same session opens /tv on the screen.
 */
function onboard_venue(array $p, ?int $partnerId = null): array {
 $owner=trim((string)($p['ownerName']??''));
 $bar=trim((string)($p['barName']??''));
 $phone=preg_replace('/\D+/','',(string)($p['phone']??''));
 $email=trim((string)($p['email']??''));
 $doc=preg_replace('/\D+/','',(string)($p['document']??''));
 $pixType=strtoupper(trim((string)($p['pixKeyType']??'')));
 $pix=trim((string)($p['pixKey']??''));
 $password=(string)($p['password']??'');
 if(!in_array($pixType,['CPF','CNPJ','EMAIL','PHONE','EVP'],true)||$pix===''||strlen($pix)>180||strlen($password)<10)
  throw new OnboardingError('Informe chave Pix, tipo e senha de pelo menos 10 caracteres.',400);
 if($owner===''||$bar===''||$phone===''||$doc==='')throw new OnboardingError('Preencha cadastro, documento e telefone',400);
 if(empty($p['acceptedTerms']))throw new OnboardingError('Aceite os termos',400);
 ensure_onboarding_columns();
 $pdo=settings_db();
 $pdo->beginTransaction();
 try{
  $open='bar-'.bin2hex(random_bytes(16));
  $q=$pdo->prepare("INSERT INTO users(openId,name,email,loginMethod,role) VALUES(?,?,?,'phone','admin')");
  $q->execute([$open,$owner,$email?:null]);
  $uid=(int)$pdo->lastInsertId();
  $venueCode=unique_venue_code($bar);
  $q=$pdo->prepare('INSERT INTO venues(ownerId,code,name,musicPriceCents,dedicationPriceCents,splitBarPercent,splitPlatformPercent,ownerDocument,ownerPhone,pixKeyType,pixKey,ownerPasswordHash,partnerId,splitAcceptedAt,termsAcceptedAt) VALUES(?,?,?,500,0,70,30,?,?,?,?,?,?,NOW(),NOW())');
  $q->execute([$uid,$venueCode,$bar,$doc,$phone,$pixType,$pix,password_hash($password,PASSWORD_DEFAULT),$partnerId]);
  $venueId=(int)$pdo->lastInsertId();
  $pdo->commit();
 }catch(Throwable $e){$pdo->rollBack();throw $e;}
 $table=ensure_default_table($venueId);
 $base=rtrim(runtime_public_url(),'/');
 return ['ok'=>true,'venue'=>['id'=>$venueId,'name'=>$bar,'code'=>$venueCode],
  'tableUrl'=>$base.'/j/'.$table['qrToken'],'tablesPrintUrl'=>$base.'/mesas?venue='.$venueCode,
  'ownerPanelUrl'=>$base.'/bar','paymentProvider'=>'asaas','environment'=>'sandbox'];
}

<?php
declare(strict_types=1);
require_once __DIR__.'/onboarding.php';

function partner_schema():void{
 settings_db()->exec("CREATE TABLE IF NOT EXISTS partners(id int AUTO_INCREMENT PRIMARY KEY,name varchar(120) NOT NULL,username varchar(80) NOT NULL UNIQUE,passwordHash varchar(255) NOT NULL,mustChangePassword tinyint(1) NOT NULL DEFAULT 1,status varchar(20) NOT NULL DEFAULT 'ACTIVE',lastLoginAt timestamp NULL,createdAt timestamp DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 ensure_onboarding_columns();
}
partner_schema();

ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Strict');
if(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')ini_set('session.cookie_secure','1');
session_name('tocaraul_partner');session_start();
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
$err='';$ok='';

if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Sessão expirada. Recarregue a página.');
  $a=(string)($_POST['a']??'');
  if($a==='login'){
   $q=settings_db()->prepare("SELECT * FROM partners WHERE username=? AND status='ACTIVE' LIMIT 1");
   $q->execute([trim((string)($_POST['username']??''))]);
   $p=$q->fetch();
   if(!$p||!password_verify((string)($_POST['password']??''),(string)$p['passwordHash']))throw new RuntimeException('Usuário ou senha não conferem.');
   session_regenerate_id(true);
   $_SESSION['partner']=(int)$p['id'];
   $_SESSION['must_change']=(int)$p['mustChangePassword']===1;
   settings_db()->prepare('UPDATE partners SET lastLoginAt=NOW() WHERE id=?')->execute([(int)$p['id']]);
   header('Location:/parceiro');exit;
  }
  if($a==='logout'){session_destroy();header('Location:/parceiro');exit;}
  if(empty($_SESSION['partner']))throw new RuntimeException('Entre novamente.');
  $pid=(int)$_SESSION['partner'];
  if($a==='password'){
   $new=(string)($_POST['new_password']??'');
   if(strlen($new)<10)throw new RuntimeException('A nova senha precisa ter pelo menos 10 caracteres.');
   if($new!==(string)($_POST['confirm_password']??''))throw new RuntimeException('As senhas não conferem.');
   settings_db()->prepare('UPDATE partners SET passwordHash=?,mustChangePassword=0 WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$pid]);
   $_SESSION['must_change']=false;
   $_SESSION['flash']='Senha alterada com sucesso.';
   header('Location:/parceiro');exit;
  }
  if($a==='register'){
   if(!empty($_SESSION['must_change']))throw new RuntimeException('Troque a senha inicial antes de cadastrar bares.');
   $result=onboard_venue([
    'activationCode'=>$_POST['activationCode']??'','ownerName'=>$_POST['ownerName']??'','barName'=>$_POST['barName']??'',
    'phone'=>$_POST['phone']??'','email'=>$_POST['email']??'','document'=>$_POST['document']??'',
    'pixKeyType'=>$_POST['pixKeyType']??'','pixKey'=>$_POST['pixKey']??'','password'=>$_POST['ownerPassword']??'',
    'tvName'=>$_POST['tvName']??'TV Principal','acceptedTerms'=>true,
   ],$pid);
   $_SESSION['handoff']=$result+['ownerPassword'=>(string)($_POST['ownerPassword']??'')];
   header('Location:/parceiro');exit;
  }
  if($a==='owner_reset'){
   if(!empty($_SESSION['must_change']))throw new RuntimeException('Troque a senha inicial antes de redefinir senhas de bar.');
   $q=settings_db()->prepare('SELECT id,code,name FROM venues WHERE id=? AND partnerId=? LIMIT 1');
   $q->execute([(int)($_POST['id']??0),$pid]);
   $venue=$q->fetch();
   if(!$venue)throw new RuntimeException('Bar não encontrado na sua carteira.');
   $newPassword=substr(strtr(base64_encode(random_bytes(12)),'+/=','xyz'),0,14);
   settings_db()->prepare('UPDATE venues SET ownerPasswordHash=? WHERE id=?')->execute([password_hash($newPassword,PASSWORD_DEFAULT),(int)$venue['id']]);
   $_SESSION['reset']=['name'=>$venue['name'],'code'=>$venue['code'],'password'=>$newPassword];
   header('Location:/parceiro');exit;
  }
 }catch(OnboardingError $e){$err=$e->getMessage();}
 catch(Throwable $e){$err=$e->getMessage();}
}

$partner=null;
if(!empty($_SESSION['partner'])){
 $q=settings_db()->prepare('SELECT * FROM partners WHERE id=?');$q->execute([(int)$_SESSION['partner']]);$partner=$q->fetch();
}
$must=$partner&&!empty($_SESSION['must_change']);
if(!empty($_SESSION['flash'])){$ok=(string)$_SESSION['flash'];unset($_SESSION['flash']);}
$handoff=null;
if(!empty($_SESSION['handoff'])){$handoff=$_SESSION['handoff'];unset($_SESSION['handoff']);}
$reset=null;
if(!empty($_SESSION['reset'])){$reset=$_SESSION['reset'];unset($_SESSION['reset']);}
$bars=[];
if($partner&&!$must){
 $q=settings_db()->prepare("SELECT v.id,v.code,v.name,v.createdAt,(SELECT COUNT(*) FROM devices d WHERE d.venueId=v.id AND d.status='ONLINE') tvs FROM venues v WHERE v.partnerId=? ORDER BY v.id DESC");
 $q->execute([(int)$partner['id']]);$bars=$q->fetchAll();
}
$base=rtrim(runtime_public_url(),'/');
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang=pt-BR><head><meta charset=utf-8><meta name=viewport content="width=device-width,initial-scale=1">
<title>Parceiro · TocaRaul</title>
<style>*{box-sizing:border-box}body{margin:0;background:#090909;color:#fff;font:15px system-ui}
.w{max-width:900px;margin:auto;padding:22px}
.logo{display:flex;align-items:center;gap:10px;margin-bottom:4px}.logo img{height:52px;width:52px;object-fit:contain}.logo span{color:#fc0;font-size:26px;font-weight:900}
.sub{color:#999;margin-bottom:18px}
.c{background:#141414;border:1px solid #292929;border-radius:18px;padding:18px;margin:16px 0}
h2{margin:0 0 6px;font-size:21px}h3{font-size:17px;margin:18px 0 6px}
label{display:block;color:#bbb;font-size:13px;margin:10px 0 4px}
input,select,button{padding:12px;border-radius:11px;border:1px solid #333;background:#080808;color:#fff;font-size:15px}
input,select{width:100%}
button{background:#fc0;color:#000;font-weight:800;cursor:pointer;border:0}
button.ghost{background:#222;color:#fc0;border:1px solid #444}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:650px){.grid{grid-template-columns:1fr}}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.muted{color:#999}.err{background:#301414;color:#ffaaaa;padding:12px;border-radius:11px;margin:12px 0}
.okbox{background:#123018;color:#afffbb;padding:12px;border-radius:11px;margin:12px 0}
.hand{background:#0d1f1d;border:1px solid #1f4a45;border-radius:14px;padding:16px;margin:12px 0}
.hand b{color:#32BCAD}.kv{margin:8px 0;word-break:break-all}.kv span{color:#999;display:block;font-size:13px}
.item{padding:11px 0;border-bottom:1px solid #292929}
a{color:#fc0}
</style></head><body><div class=w>
<div class=logo><img src="/assets/tocaraul_logo.png" alt="TocaRaul"><span>TocaRaul · Parceiro</span></div>
<div class=sub>Cadastro de bares e donos de bar</div>
<?php if($err):?><div class=err><?=h($err)?></div><?php endif;?>
<?php if($ok):?><div class=okbox><?=h($ok)?></div><?php endif;?>

<?php if(!$partner):?>
<div class=c style="max-width:420px">
 <h2>Entrar</h2><p class=muted>Acesso de parceiro comercial.</p>
 <form method=post>
  <input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=login>
  <label>Usuário</label><input name=username autocomplete=username required>
  <label>Senha</label><input type=password name=password autocomplete=current-password required>
  <button style="margin-top:14px">Entrar</button>
 </form>
</div>

<?php elseif($must):?>
<div class=c>
 <h2>Troque sua senha</h2><p class=muted>Por segurança, defina uma nova senha antes de cadastrar bares. Mínimo 10 caracteres.</p>
 <form method=post>
  <input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=password>
  <div class=grid>
   <div><label>Nova senha</label><input type=password name=new_password minlength=10 required></div>
   <div><label>Confirmar senha</label><input type=password name=confirm_password minlength=10 required></div>
  </div>
  <button style="margin-top:14px">Salvar nova senha</button>
 </form>
</div>

<?php else:?>
<div class=c><div class=row>
 <div style="flex:1"><h2><?=h($partner['name'])?></h2><p class=muted>Usuário: <b><?=h($partner['username'])?></b></p></div>
 <form method=post><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=logout><button class=ghost>Sair</button></form>
</div></div>

<?php if($handoff):?>
<div class=hand>
 <h2 style="color:#32BCAD">✅ Bar cadastrado: <?=h($handoff['venue']['name'])?></h2>
 <p class=muted>Entregue estes dados ao dono do bar. Esta tela não será mostrada de novo — tire um print agora.</p>
 <div class=kv><span>Código do bar (login)</span><b style="font-size:20px"><?=h($handoff['venue']['code'])?></b></div>
 <div class=kv><span>Senha do dono</span><b style="font-size:20px"><?=h($handoff['ownerPassword'])?></b></div>
 <div class=kv><span>Painel do dono do bar</span><a href="<?=h($handoff['ownerPanelUrl'])?>" target=_blank><?=h($handoff['ownerPanelUrl'])?></a></div>
 <div class=kv><span>QR Code das mesas (imprimir)</span><a href="<?=h($handoff['tablesPrintUrl'])?>" target=_blank><?=h($handoff['tablesPrintUrl'])?></a></div>
 <div class=kv><span>Link da Mesa 01 (teste)</span><a href="<?=h($handoff['tableUrl'])?>" target=_blank><?=h($handoff['tableUrl'])?></a></div>
 <h3>O que ensinar ao dono</h3>
 <ol class=muted style="margin:0;padding-left:20px;line-height:1.7">
  <li>Entrar no painel com o <b>código do bar</b> e a <b>senha</b> acima.</li>
  <li>Conferir/alterar a <b>chave Pix</b> onde o dinheiro cai.</li>
  <li>Usar <b>Tocar / Pausar / Pular</b> para controlar a tela de qualquer celular.</li>
  <li>Banir <b>palavras</b> e <b>músicas</b> que não quer no bar.</li>
  <li>Imprimir o <b>QR das mesas</b> e colar nas mesas.</li>
 </ol>
</div>
<?php endif;?>

<?php if($reset):?>
<div class=hand>
 <h2 style="color:#32BCAD">🔑 Nova senha do dono: <?=h($reset['name'])?></h2>
 <p class=muted>A senha anterior deixou de funcionar. Entregue estes dados ao dono — esta tela não será mostrada de novo.</p>
 <div class=kv><span>Código do bar (login)</span><b style="font-size:20px"><?=h($reset['code'])?></b></div>
 <div class=kv><span>Nova senha do dono</span><b style="font-size:20px"><?=h($reset['password'])?></b></div>
 <div class=kv><span>Painel do dono do bar</span><a href="<?=h($base)?>/bar" target=_blank><?=h($base)?>/bar</a></div>
</div>
<?php endif;?>

<div class=c>
 <h2>Cadastrar novo bar</h2>
 <p class=muted>Cadastre o bar aqui. Depois abra <a href="<?=h($base)?>/player" target=_blank><?=h($base)?>/player</a> no equipamento ligado na tela e entre com o código e a senha do bar. O código da TV abaixo é opcional.</p>
 <form method=post>
  <input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=register>
  <div class=grid>
   <div><label>Código da tela (opcional)</label><input name=activationCode inputmode=numeric maxlength=6></div>
   <div><label>Nome da tela</label><input name=tvName value="Tela principal"></div>
   <div><label>Nome do bar</label><input name=barName required></div>
   <div><label>Responsável (dono)</label><input name=ownerName required></div>
   <div><label>Telefone do dono</label><input name=phone inputmode=numeric required></div>
   <div><label>E-mail do dono (opcional)</label><input name=email type=email></div>
   <div><label>CPF/CNPJ do bar</label><input name=document inputmode=numeric required></div>
   <div><label>Tipo de chave Pix</label><select name=pixKeyType><?php foreach(['EMAIL','CPF','CNPJ','PHONE','EVP'] as $t):?><option value="<?=$t?>"><?=$t?></option><?php endforeach;?></select></div>
   <div><label>Chave Pix do bar</label><input name=pixKey required></div>
   <div>
    <label>Senha do dono (mínimo 10 caracteres)</label>
    <div class=row><input id=ownerPassword name=ownerPassword minlength=10 required style="flex:1"><button type=button class=ghost onclick="gerar()">Gerar</button></div>
   </div>
  </div>
  <button style="margin-top:16px">Cadastrar bar</button>
 </form>
</div>

<div class=c>
 <h2>Bares que você cadastrou</h2>
 <?php if(!$bars):?><p class=muted>Nenhum bar cadastrado ainda.</p><?php endif;?>
 <?php foreach($bars as $b):?>
 <div class=item>
  <b><?=h($b['name'])?></b> · <span class=muted>código <?=h($b['code'])?> · <?=(int)$b['tvs']?> TV(s) online · <?=h(substr((string)$b['createdAt'],0,10))?></span>
  <div class=row style="margin-top:6px">
   <a href="<?=h($base)?>/mesas?venue=<?=h(rawurlencode($b['code']))?>" target=_blank>QR das mesas</a>
   <form method=post onsubmit="return confirm('Gerar uma nova senha para o dono deste bar? A senha atual deixa de funcionar.')">
    <input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=owner_reset><input type=hidden name=id value=<?=(int)$b['id']?>>
    <button class=ghost style="padding:8px 12px;font-size:13px">Nova senha do dono</button>
   </form>
  </div>
 </div>
 <?php endforeach;?>
</div>
<?php endif;?>
</div>
<script>
function gerar(){const c='abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';let s='';const a=new Uint32Array(14);crypto.getRandomValues(a);for(const n of a)s+=c[n%c.length];document.getElementById('ownerPassword').value=s;document.getElementById('ownerPassword').type='text'}
</script>
</body></html>

<?php
declare(strict_types=1);
require_once __DIR__.'/onboarding.php';

ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');
if(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')ini_set('session.cookie_secure','1');
open_session('tocaraul_google');$oauthEmail=(string)($_SESSION['google_email']??'');$oauthName=(string)($_SESSION['google_name']??'');open_session('tocaraul_bar');if($oauthEmail!=='')$_SESSION['google_email']=$oauthEmail;if($oauthName!=='')$_SESSION['google_name']=$oauthName;
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
$err='';$old=[];
if($_SERVER['REQUEST_METHOD']!=='POST' && !empty($_SESSION['google_email'])){
 $old['email']=(string)$_SESSION['google_email'];
 $old['ownerName']=(string)($_SESSION['google_name']??'');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Sessão expirada. Recarregue a página.');
  $old=$_POST;
  $googleEmail=(string)($_SESSION['google_email']??'');
  $result=onboard_venue([
   'ownerName'=>$_POST['ownerName']??'','barName'=>$_POST['barName']??'','phone'=>$_POST['phone']??'',
   'email'=>$googleEmail!==''?$googleEmail:($_POST['email']??''),'document'=>$_POST['document']??'','pixKeyType'=>$_POST['pixKeyType']??'',
   'pixKey'=>$_POST['pixKey']??'','password'=>$_POST['password']??'','acceptedTerms'=>!empty($_POST['acceptedTerms']),
  ]);
  session_regenerate_id(true);
  $_SESSION['venue']=(int)$result['venue']['id'];
  unset($_SESSION['google_email'],$_SESSION['google_name']);
  $_SESSION['flash_new']=$result['venue']['code'];
  header('Location:/bar');exit;
 }catch(OnboardingError $e){$err=$e->getMessage();}
 catch(Throwable $e){$err=$e->getMessage();}
}
$v=static fn(string $k)=>h($old[$k]??'');
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang=pt-BR><head><meta charset=utf-8><meta name=viewport content="width=device-width,initial-scale=1">
<title>Cadastrar meu bar · TocaRaul</title>
<link rel=manifest href="/manifest.webmanifest"><meta name=theme-color content="#090909"><link rel=icon href="/assets/icon-192.png">
<style>*{box-sizing:border-box}body{margin:0;background:#090909;color:#fff;font:16px/1.6 system-ui}
.w{max-width:620px;margin:auto;padding:26px 18px 70px}
.logo{display:flex;align-items:center;gap:10px;justify-content:center;margin-bottom:6px}
.logo img{height:64px;width:64px;object-fit:contain}.logo span{color:#fc0;font-size:30px;font-weight:900}
h1{font-size:28px;text-align:center;margin:18px 0 6px}
.tag{color:#ccc;text-align:center;margin:0 0 22px}
.c{background:#141414;border:1px solid #292929;border-radius:18px;padding:20px}
label{display:block;color:#bbb;font-size:13px;margin:14px 0 4px}
input,select{width:100%;padding:13px;border-radius:11px;border:1px solid #333;background:#080808;color:#fff;font-size:16px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:560px){.grid{grid-template-columns:1fr}}
button{width:100%;padding:16px;border:0;border-radius:13px;background:#fc0;color:#090909;font-weight:900;font-size:18px;cursor:pointer;margin-top:18px}
.err{background:#301414;color:#ffaaaa;padding:12px;border-radius:11px;margin-bottom:14px}
.muted{color:#999;font-size:14px}
.terms{display:flex;gap:9px;align-items:flex-start;margin-top:16px}.terms input{width:auto;margin-top:5px}
.step{background:#0d1f1d;border:1px solid #1f4a45;border-radius:12px;padding:12px;margin-bottom:16px;color:#8fd6cf;font-size:14px}
a{color:#fc0}.foot{text-align:center;margin-top:18px}
</style></head><body><div class=w>
<div class=logo><img src="/assets/tocaraul_logo.png" alt="TocaRaul"><span>TocaRaul</span></div>
<h1>Cadastrar meu bar</h1>
<p class=tag>Leva menos de um minuto. A tela você conecta depois.</p>
<div class=c>
 <div class=step>Você recebe <b>70%</b> de cada música pedida, direto na sua chave Pix. Não precisa abrir conta em provedor de pagamento.</div>
 <?php if($err):?><div class=err><?=h($err)?></div><?php endif;?>
 <form method=post>
  <input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>">
  <label>Nome do bar</label><input name=barName value="<?=$v('barName')?>" required autofocus>
  <div class=grid>
   <div><label>Seu nome</label><input name=ownerName value="<?=$v('ownerName')?>" required></div>
   <div><label>Seu telefone</label><input name=phone inputmode=numeric value="<?=$v('phone')?>" required></div>
   <div><label>Seu e-mail</label><input type=email name=email value="<?=$v('email')?:h($_SESSION['google_email']??'')?>" <?=!empty($_SESSION['google_email'])?'readonly':''?>></div>
   <div><label>CPF ou CNPJ</label><input name=document inputmode=numeric value="<?=$v('document')?>" required></div>
  </div>
  <label>Tipo da sua chave Pix</label>
  <select name=pixKeyType><?php foreach(['EMAIL','CPF','CNPJ','PHONE','EVP'] as $t):?><option value="<?=$t?>" <?=($old['pixKeyType']??'')===$t?'selected':''?>><?=$t?></option><?php endforeach;?></select>
  <label>Sua chave Pix (onde o dinheiro cai)</label><input name=pixKey value="<?=$v('pixKey')?>" required>
  <label>Senha do painel (mínimo 10 caracteres)</label><input type=password name=password minlength=10 required>
  <label class=terms><input type=checkbox name=acceptedTerms value=1 required> <span class=muted>Aceito os termos do TocaRaul e sou responsável pelo uso do conteúdo exibido no meu estabelecimento.</span></label>
  <button>Criar meu bar</button>
 </form>
 <div class=foot class=muted><span class=muted>Já tem cadastro? <a href="/bar">Entrar no painel</a></span></div>
</div>
</div></body></html>

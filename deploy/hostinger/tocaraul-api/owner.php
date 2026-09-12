<?php
declare(strict_types=1);require __DIR__.'/../api_config.php';require_once __DIR__.'/moderation.php';require_once __DIR__.'/onboarding.php';
function d():PDO{return settings_db();}
function schema():void{$d=d();$d->exec("CREATE TABLE IF NOT EXISTS barPlaylist(id int AUTO_INCREMENT PRIMARY KEY,venueId int NOT NULL,providerId varchar(255) NOT NULL,title varchar(255) NOT NULL,artist varchar(255) NOT NULL,position int NOT NULL DEFAULT 0,status varchar(20) NOT NULL DEFAULT 'QUEUED',createdAt timestamp DEFAULT CURRENT_TIMESTAMP,INDEX(venueId,status,position)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");$d->exec("CREATE TABLE IF NOT EXISTS tvCommands(id int AUTO_INCREMENT PRIMARY KEY,venueId int NOT NULL,command varchar(20) NOT NULL,status varchar(20) NOT NULL DEFAULT 'PENDING',createdAt timestamp DEFAULT CURRENT_TIMESTAMP,executedAt timestamp NULL,INDEX(venueId,status,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");$existing=array_column($d->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venues'")->fetchAll(),'COLUMN_NAME');if(!in_array('announceDedication',$existing,true))$d->exec("ALTER TABLE venues ADD COLUMN announceDedication tinyint(1) NOT NULL DEFAULT 1");if(!in_array('logoPath',$existing,true))$d->exec("ALTER TABLE venues ADD COLUMN logoPath varchar(200) NULL");}
schema();ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');if(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')ini_set('session.cookie_secure','1');$oauthVenue=null;if(!empty($_COOKIE['tocaraul_google'])){open_session('tocaraul_google');if(isset($_SESSION['venue']))$oauthVenue=(int)$_SESSION['venue'];}open_session('tocaraul_bar');if($oauthVenue&&!isset($_SESSION['venue']))$_SESSION['venue']=$oauthVenue;if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));$err='';$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
function google_ready():bool{$id=defined('GOOGLE_CLIENT_ID')?(string)constant('GOOGLE_CLIENT_ID'):(string)(getenv('GOOGLE_CLIENT_ID')?:'');return $id!==''&&!str_starts_with(strtolower($id),'seu_');}
/**
 * Stores the bar logo next to the other static assets and returns its public path.
 * The image is re-encoded by name only: we trust getimagesize, not the sent filename.
 */
function save_logo(array $file,int $venueId):string{
 if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Não consegui receber o arquivo. Tente uma imagem menor.');
 if(($file['size']??0)>2*1024*1024)throw new RuntimeException('A logo precisa ter no máximo 2 MB.');
 $info=@getimagesize((string)$file['tmp_name']);
 $ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$info['mime']??'']??'';
 if($ext==='')throw new RuntimeException('Envie a logo em PNG, JPG ou WEBP.');
 $dir=__DIR__.'/assets/logos';
 if(!is_dir($dir)&&!@mkdir($dir,0755,true))throw new RuntimeException('Não consegui gravar a logo no servidor.');
 $name='v'.$venueId.'_'.bin2hex(random_bytes(5)).'.'.$ext;
 if(!@move_uploaded_file((string)$file['tmp_name'],$dir.'/'.$name)&&!@rename((string)$file['tmp_name'],$dir.'/'.$name))
  throw new RuntimeException('Não consegui gravar a logo no servidor.');
 return '/assets/logos/'.$name;
}
function drop_logo(?string $path):void{
 if($path&&preg_match('#^/assets/logos/[A-Za-z0-9_.-]+$#',$path))@unlink(__DIR__.$path);
}
if($_SERVER['REQUEST_METHOD']==='POST'){try{if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Sessão expirada. Recarregue a página.');$a=$_POST['a']??'';if($a==='login'){$v=venue_login((string)($_POST['code']??''),(string)($_POST['password']??''));if(!$v)throw new RuntimeException('Bar ou senha não conferem.');session_regenerate_id(true);$_SESSION['venue']=(int)$v['id'];header('Location:/bar');exit;}if($a==='logout'){session_destroy();header('Location:/bar');exit;}if(empty($_SESSION['venue']))throw new RuntimeException('Entre novamente.');$vid=(int)$_SESSION['venue'];if($a==='pix'){if(!in_array($_POST['type']??'',['CPF','CNPJ','EMAIL','PHONE','EVP'],true)||trim($_POST['pix']??'')==='')throw new RuntimeException('Chave Pix inválida.');d()->prepare('UPDATE venues SET pixKeyType=?,pixKey=? WHERE id=?')->execute([trim($_POST['type']),trim($_POST['pix']),$vid]);}if($a==='add'){if(!preg_match('/^[A-Za-z0-9_-]{11}$/',trim($_POST['video']??'')))throw new RuntimeException('ID de vídeo inválido.');d()->prepare("INSERT INTO barPlaylist(venueId,providerId,title,artist,position) VALUES(?,?,?,?,(SELECT n FROM(SELECT COALESCE(MAX(position),0)+1 n FROM barPlaylist WHERE venueId=?)x))")->execute([$vid,trim($_POST['video']),trim($_POST['title']),trim($_POST['artist']),$vid]);invalidate_venue_state($vid);}if($a==='remove'){d()->prepare('DELETE FROM barPlaylist WHERE id=? AND venueId=?')->execute([(int)$_POST['id'],$vid]);invalidate_venue_state($vid);}if(in_array($a,['up','down'],true)){$delta=$a==='up'?-1:1;d()->prepare('UPDATE barPlaylist SET position=GREATEST(0,position+?) WHERE id=? AND venueId=?')->execute([$delta,(int)$_POST['id'],$vid]);invalidate_venue_state($vid);}if($a==='cmd'&&in_array($_POST['cmd']??'', ['PLAY','PAUSE','SKIP'],true)){d()->prepare('INSERT INTO tvCommands(venueId,command) VALUES(?,?)')->execute([$vid,$_POST['cmd']]);invalidate_venue_state($vid);}if($a==='announce'){d()->prepare('UPDATE venues SET announceDedication=? WHERE id=?')->execute([!empty($_POST['on'])?1:0,$vid]);invalidate_venue_state($vid);}
 if($a==='logo'){$q=d()->prepare('SELECT logoPath FROM venues WHERE id=?');$q->execute([$vid]);$prev=(string)($q->fetchColumn()?:'');$path=save_logo($_FILES['logo']??[],$vid);d()->prepare('UPDATE venues SET logoPath=? WHERE id=?')->execute([$path,$vid]);drop_logo($prev);}
 if($a==='logo_del'){$q=d()->prepare('SELECT logoPath FROM venues WHERE id=?');$q->execute([$vid]);$prev=(string)($q->fetchColumn()?:'');d()->prepare('UPDATE venues SET logoPath=NULL WHERE id=?')->execute([$vid]);drop_logo($prev);}
 if($a==='word_add'){$w=trim((string)($_POST['word']??''));if($w===''||mb_strlen($w)>60)throw new RuntimeException('Informe uma palavra de até 60 caracteres.');moderation_schema();d()->prepare('INSERT IGNORE INTO venueBlockedWords(venueId,word) VALUES(?,?)')->execute([$vid,$w]);}
 if($a==='word_del'){moderation_schema();d()->prepare('DELETE FROM venueBlockedWords WHERE id=? AND venueId=?')->execute([(int)($_POST['id']??0),$vid]);}
 if($a==='song_add'){$video=trim((string)($_POST['banVideo']??''));if(!preg_match('/^[A-Za-z0-9_-]{11}$/',$video))throw new RuntimeException('ID de vídeo do YouTube inválido (11 caracteres).');moderation_schema();d()->prepare('INSERT IGNORE INTO venueBlockedSongs(venueId,providerId,title,artist) VALUES(?,?,?,?)')->execute([$vid,'youtube:'.$video,trim((string)($_POST['title']??''))?:null,trim((string)($_POST['artist']??''))?:null]);}
 if($a==='song_del'){moderation_schema();d()->prepare('DELETE FROM venueBlockedSongs WHERE id=? AND venueId=?')->execute([(int)($_POST['id']??0),$vid]);}
 if($a==='song_ban'){$rid=(int)($_POST['id']??0);$q=d()->prepare('SELECT providerId,title,artist FROM songRequests WHERE id=? AND venueId=? LIMIT 1');$q->execute([$rid,$vid]);if($r=$q->fetch()){moderation_schema();d()->prepare('INSERT IGNORE INTO venueBlockedSongs(venueId,providerId,title,artist) VALUES(?,?,?,?)')->execute([$vid,$r['providerId'],$r['title'],$r['artist']]);d()->prepare("UPDATE songRequests SET status='SKIPPED' WHERE id=? AND venueId=? AND status IN('QUEUED','PLAYING')")->execute([$rid,$vid]);d()->prepare('INSERT INTO tvCommands(venueId,command) VALUES(?,?)')->execute([$vid,'SKIP']);invalidate_venue_state($vid);}}
 header('Location:/bar');exit;}catch(Throwable $e){$err=$e->getMessage();}}
$v=null;$list=[];$paid=[];if(!empty($_SESSION['venue'])){$q=d()->prepare('SELECT * FROM venues WHERE id=?');$q->execute([(int)$_SESSION['venue']]);$v=$q->fetch();$q=d()->prepare("SELECT * FROM barPlaylist WHERE venueId=? AND status='QUEUED' ORDER BY position,id");$q->execute([(int)$_SESSION['venue']]);$list=$q->fetchAll();$q=d()->prepare("SELECT id,providerId,title,artist,tableCode,status FROM songRequests WHERE venueId=? AND status IN('QUEUED','PLAYING') ORDER BY status='PLAYING' DESC,queuePosition");$q->execute([(int)$_SESSION['venue']]);$paid=$q->fetchAll();$words=venue_blocked_words((int)$_SESSION['venue']);$songs=venue_blocked_songs((int)$_SESSION['venue']);}
$words=$words??[];$songs=$songs??[];$tvs=[];$tables=[];
if(!empty($_SESSION['venue'])){
 $q=d()->prepare("SELECT id,label,qrToken FROM venueTables WHERE venueId=? AND status='ACTIVE' ORDER BY id");$q->execute([(int)$_SESSION['venue']]);$tables=$q->fetchAll();
}
$barQrUrl=$tables?runtime_public_url().'/j/'.$tables[0]['qrToken']:'';
$justCreated=null;if(!empty($_SESSION['flash_new'])){$justCreated=(string)$_SESSION['flash_new'];unset($_SESSION['flash_new']);}
$balance=['available'=>0,'pending'=>0];
if($v&&d()->query("SHOW TABLES LIKE 'financeLedger'")->fetchColumn()){
 $q=d()->prepare("SELECT balanceStatus,SUM(barCents) cents FROM financeLedger WHERE venueId=? AND type='SALE' GROUP BY balanceStatus");
 $q->execute([(int)$v['id']]);foreach($q->fetchAll() as $row){if($row['balanceStatus']==='AVAILABLE')$balance['available']=(int)$row['cents'];if($row['balanceStatus']==='PENDING')$balance['pending']=(int)$row['cents'];}
}
function money(int $c):string{return 'R$ '.number_format($c/100,2,',','.');}
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}?><!doctype html><meta name=viewport content="width=device-width,initial-scale=1"><title>Painel do Bar · TocaRaul</title><link rel=stylesheet href="/assets/bauhaus.css"><body class=bh-panel><div class=w><div class=logo><i></i><b>TocaRaul · Painel do Bar</b></div><?php if($err):?><p class=err><?=h($err)?></p><?php endif;?><?php if(!$v):?><div class=c style="max-width:430px"><h2>Entrar</h2>
<?php if(google_ready()):?><a href="/auth/google/start" style="display:flex;align-items:center;justify-content:center;gap:10px;background:#fff;color:#171717;font-weight:900;text-transform:uppercase;padding:13px;border:3px solid #171717;box-shadow:4px 4px 0 #171717;text-decoration:none;margin-bottom:14px"><svg width=18 height=18 viewBox="0 0 48 48"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9 3.6l6.7-6.7C35.6 2.6 30.2 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.8 6.1C12.3 13.2 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.1 24.6c0-1.6-.1-3.1-.4-4.6H24v9.1h12.4c-.5 2.9-2.2 5.4-4.700 7l7.6 5.9c4.4-4.1 6.8-10.1 6.8-17.4z"/><path fill="#FBBC05" d="M10.4 28.7c-.5-1.4-.8-2.9-.8-4.7s.3-3.3.8-4.7l-7.8-6.1C.9 16.5 0 20.1 0 24s.9 7.5 2.6 10.8l7.8-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.6-5.9c-2.1 1.4-4.8 2.3-8.3 2.3-6.3 0-11.7-3.7-13.6-9.8l-7.8 6.1C6.5 42.6 14.6 48 24 48z"/></svg> Entrar com Google</a>
<p class=muted style="text-align:center;margin:10px 0">ou</p><?php endif;?>
<p class=muted>Use o código do bar e a senha definida no cadastro.</p><form method=post><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=login><label>Codigo do bar</label><input name=code required><label>Senha</label><input type=password name=password required><button>Entrar</button></form>
<p class=muted style="margin-top:14px">Não tem cadastro? <a style="color:var(--blue)" href="/cadastro">Cadastre seu bar</a></p></div><?php else:?><div class=c><div class=row><h2 style="flex:1"><?=h($v['name'])?></h2><form method=post><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=logout><button>Sair</button></form></div><p>Codigo: <b><?=h($v['code'])?></b></p></div>
<?php if($justCreated):?><div class="c accent" style="background:var(--yellow)"><h2 style="color:var(--ink)">Bar criado!</h2><p>Use o QR Code abaixo para receber os pedidos dos clientes.</p><p class=muted>Guarde seu código <b><?=h($justCreated)?></b> e a senha: é assim que você entra neste painel.</p></div><?php endif;?>
<div class=c id=playerCard><h2>Tocando agora</h2>
<div id=stage class=bh-stage>
 <div id=ytmount class=full style="display:none"></div>
 <div id=stageIdle class="full idle">
  <b id=pTitle>Aguardando pedidos</b>
  <span id=pArtist></span>
 </div>
<?php if(!empty($v['logoPath'])):?> <div class=plate><img src="<?=h($v['logoPath'])?>" alt="<?=h($v['name'])?>"></div><?php endif;?>
 <div class=side>
  <div class=ded><em>Dedicatória</em><p id=pDedication></p></div>
  <div class=qr><div id=stageQr></div><span>Peça sua música</span></div>
 </div>
 <div id=stageStart class=gate>
  <b>Ligar a tela do bar</b>
  <p>Um clique libera o som (exigência do navegador). Depois é só deixar aberto: a fila paga toca sozinha.</p>
  <button type=button id=stageBtn class=alt>▶ Começar</button>
 </div>
</div>
<p class=row style="margin-top:10px"><span id=pQueue class=muted>0 pedido(s) na fila</span> · <button type=button id=fsBtn class=plain>⛶ Tela cheia</button>
<?php if(google_ready()):?><span class=muted style="font-size:13px">· Logado numa conta YouTube Premium neste navegador? Toca sem anúncio.</span><?php endif;?></p>
</div>
<div class=c><h2>Logo do bar</h2><p class=muted>Aparece na tela enquanto a fila roda e enquanto nenhuma música toca. PNG, JPG ou WEBP de até 2 MB.</p>
<div class=row>
<?php if(!empty($v['logoPath'])):?><img src="<?=h($v['logoPath'])?>" alt="" style="height:72px;max-width:180px;object-fit:contain;background:#171717;border:3px solid #171717;padding:6px">
<form method=post><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=logo_del><button>Remover</button></form><?php endif;?>
<form method=post enctype="multipart/form-data" class=row style="flex:1;gap:8px"><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=logo><input type=file name=logo accept="image/png,image/jpeg,image/webp" required style="flex:1;margin:0"><button>Salvar logo</button></form>
</div></div>
<div class=c><h2>QR Code para pedidos</h2><p class=muted>Imprima ou compartilhe este único QR Code. O cliente escaneia, escolhe a música e faz o pedido.</p><div id=barQr style="background:#fff;width:232px;height:232px;padding:8px;border:3px solid #171717;box-shadow:6px 6px 0 #171717"></div><p><a style="color:var(--blue)" href="<?=h($tables?'/j/'.$tables[0]['qrToken']:'#')?>" target=_blank>Testar link de pedidos</a> · <button type=button class=alt onclick="window.print()">Imprimir QR</button></p><script src="/assets/qrcode.js"></script><script>const qrUrl=<?=json_encode($barQrUrl,JSON_UNESCAPED_SLASHES)?>;new QRCode(document.getElementById('barQr'),{text:qrUrl,width:216,height:216});new QRCode(document.getElementById('stageQr'),{text:qrUrl,width:320,height:320});</script></div>
<div class=grid><div class=c><h2>Controle da tela</h2><div class=row><?php foreach(['PLAY'=>'▶ Tocar','PAUSE'=>'⏸ Pausar','SKIP'=>'⏭ Pular'] as $x=>$label):?><form method=post><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=cmd><input type=hidden name=cmd value=<?=$x?>><button><?=$label?></button></form><?php endforeach;?></div><form method=post><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=announce><label style="display:flex;align-items:center;gap:8px;margin-top:12px;cursor:pointer"><input type=checkbox name=on value=1 style="width:auto" <?=!empty($v['announceDedication'])?'checked':''?> onchange="this.form.submit()"> Anunciar dedicatória com voz antes da música</label></form><h3>Pedidos pagos — prioridade</h3><?php if(!$paid):?><p class=muted>Nenhum pedido pago aguardando.</p><?php endif;foreach($paid as $p):?><div class="item paid row"><div style="flex:1"><b><?=h($p['title'])?></b> · <?=h($p['artist'])?> <span class=muted><?=h($p['status'])?></span></div><form method=post onsubmit="return confirm('Banir esta música no seu bar e pular agora?')"><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=song_ban><input type=hidden name=id value=<?=(int)$p['id']?>><button title="Banir esta música e pular">⛔</button></form></div><?php endforeach;?></div><div class=c><h2>Recebimento · sandbox</h2><p>Disponível: <b><?=money($balance['available'])?></b></p><p>A receber: <b><?=money($balance['pending'])?></b></p><p>Mínimo para repasse: <b>R$ 50,00</b></p><p class=muted>Repasses automáticos em homologação. Nenhuma transferência real é feita.</p><p class=muted>O bar pode usar chave Pix de qualquer banco.</p><form method=post><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=pix><select name=type><?php foreach(['CPF','CNPJ','EMAIL','PHONE','EVP'] as $type):?><option value="<?=$type?>" <?=$v['pixKeyType']===$type?'selected':''?>><?=$type?></option><?php endforeach;?></select><input name=pix value="<?=h($v['pixKey'])?>" required><button>Salvar chave Pix</button></form></div></div><div class=c><h2>Playlist do bar</h2><p class=muted>Ela toca somente quando não houver pedido pago. Um pedido comprado assume a próxima posição automaticamente.</p>
<input id=plSearch placeholder="Buscar música no YouTube (ex: Tim Maia Você)" autocomplete=off>
<div id=plResults></div>
<form method=post id=plAdd style="display:none"><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=add><input type=hidden name=video><input type=hidden name=title><input type=hidden name=artist></form>
<details style="margin:8px 0 14px"><summary class=muted style="cursor:pointer">Adicionar pelo ID do vídeo</summary>
<form method=post class=grid style="margin-top:10px"><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=add><div><label>ID do vídeo YouTube</label><input name=video maxlength=11 required></div><div><label>Título</label><input name=title required></div><div><label>Artista</label><input name=artist required></div><div style="align-self:end"><button>Adicionar</button></div></form></details><?php foreach($list as $i):?><div class="item row"><div style="flex:1"><b><?=h($i['title'])?></b><div class=muted><?=h($i['artist'])?></div></div><?php foreach(['up'=>'↑','down'=>'↓','remove'=>'×'] as $a=>$label):?><form method=post><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=<?=$a?>><input type=hidden name=id value=<?=(int)$i['id']?>><button><?=$label?></button></form><?php endforeach;?></div><?php endforeach;?></div>
<div class=grid>
<div class=c><h2>Palavras banidas</h2><p class=muted>Valem para o nome e a dedicatória do cliente. Não diferencia maiúsculas nem acentos, e bloqueia a palavra inteira.</p>
<form method=post class=row><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=word_add><input name=word maxlength=60 placeholder="palavra" required style="flex:1;margin:0"><button>Banir</button></form>
<?php if(!$words):?><p class=muted style="margin-top:12px">Nenhuma palavra banida. O TocaRaul já bloqueia palavrões comuns por padrão.</p><?php endif;?>
<?php foreach($words as $w):?><div class="item row"><div style="flex:1"><?=h($w['word'])?></div><form method=post><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=word_del><input type=hidden name=id value=<?=(int)$w['id']?>><button>×</button></form></div><?php endforeach;?>
</div>
<div class=c><h2>Músicas banidas</h2><p class=muted>Não aparecem na busca do cliente e não podem ser pedidas. Use o ⛔ na fila para banir e pular na hora.</p>
<form method=post><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=song_add>
<label>ID do vídeo YouTube</label><input name=banVideo maxlength=11 placeholder="ex: kJQP7kiw5Fk" required>
<label>Título (opcional)</label><input name=title>
<button>Banir música</button></form>
<?php if(!$songs):?><p class=muted style="margin-top:12px">Nenhuma música banida.</p><?php endif;?>
<?php foreach($songs as $s):?><div class="item row"><div style="flex:1"><b><?=h($s['title']?:$s['providerId'])?></b><div class=muted><?=h($s['artist']?:$s['providerId'])?></div></div><form method=post><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=song_del><input type=hidden name=id value=<?=(int)$s['id']?>><button>×</button></form></div><?php endforeach;?>
</div>
</div>
<?php endif;?></div>
<?php if($v):?>
<script src="/assets/player.js"></script>
<script>
const stage=document.getElementById('stage'),mount=document.getElementById('ytmount'),idle=document.getElementById('stageIdle'),gate=document.getElementById('stageStart');
const screen=TocaRaulPlayer({
 venueId:<?=(int)$v['id']?>,mount,screenName:'Painel do bar',
 ui:{title:'pTitle',artist:'pArtist',dedication:'pDedication',queue:'pQueue'},
 onNeedsLogin:()=>location.reload(),
});
new MutationObserver(()=>{const on=mount.classList.contains('on');mount.style.display=on?'block':'none';idle.style.display=on?'none':'flex'}).observe(mount,{attributes:true,attributeFilter:['class']});
document.getElementById('stageBtn').onclick=()=>{screen.begin();gate.style.display='none'};
document.getElementById('fsBtn').onclick=()=>{document.fullscreenElement?document.exitFullscreen():stage.requestFullscreen?.().catch(()=>{})};
screen.run();

// The panel searches the same catalogue the customer sees, so the bar's blocklist applies here too.
const plSearch=document.getElementById('plSearch'),plResults=document.getElementById('plResults'),plAdd=document.getElementById('plAdd');
const qrToken=<?=json_encode($tables?$tables[0]['qrToken']:'',JSON_UNESCAPED_SLASHES)?>;
let plTimer=0;
plSearch.oninput=()=>{
 clearTimeout(plTimer);
 const q=plSearch.value.trim();
 if(q.length<2){plResults.textContent='';return}
 plTimer=setTimeout(async()=>{
  plResults.innerHTML='<p class=muted>Buscando…</p>';
  try{
   const r=await fetch('/api/commerce/search?q='+encodeURIComponent(q)+'&qrToken='+encodeURIComponent(qrToken));
   const d=await r.json();
   if(!r.ok){plResults.innerHTML='<p class=muted>'+(d.message||'Busca indisponível.')+'</p>';return}
   if(!d.results?.length){plResults.innerHTML='<p class=muted>Nada encontrado.</p>';return}
   plResults.textContent='';
   for(const song of d.results){
    const row=document.createElement('div');row.className='item row';
    const info=document.createElement('div');info.style.flex='1';
    const t=document.createElement('b');t.textContent=song.title;
    const a=document.createElement('div');a.className='muted';a.textContent=song.artist;
    info.append(t,a);
    const btn=document.createElement('button');btn.type='button';btn.textContent='Adicionar';
    btn.onclick=()=>{
     plAdd.video.value=String(song.providerId).replace('youtube:','');
     plAdd.title.value=song.title;plAdd.artist.value=song.artist;plAdd.submit();
    };
    row.append(info,btn);plResults.append(row);
   }
  }catch{plResults.innerHTML='<p class=muted>Busca indisponível agora.</p>'}
 },350);
};
</script>
<?php endif;?>

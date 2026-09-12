<?php
declare(strict_types=1);
require_once __DIR__.'/onboarding.php';

ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');
if(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')ini_set('session.cookie_secure','1');
open_session('tocaraul_bar');
if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
$err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  if(!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Sessão expirada. Recarregue a página.');
  if(($_POST['a']??'')==='logout'){session_destroy();header('Location:/player');exit;}
  $v=venue_login((string)($_POST['code']??''),(string)($_POST['password']??''));
  if(!$v)throw new RuntimeException('Bar ou senha não conferem.');
  session_regenerate_id(true);
  $_SESSION['venue']=(int)$v['id'];
  header('Location:/player');exit;
 }catch(Throwable $e){$err=$e->getMessage();}
}

$venue=null;
if(!empty($_SESSION['venue'])){
 $q=settings_db()->prepare('SELECT id,code,name FROM venues WHERE id=?');
 $q->execute([(int)$_SESSION['venue']]);
 $venue=$q->fetch();
}
header('Content-Type: text/html; charset=utf-8');
?><!doctype html><html lang=pt-BR><head><meta charset=utf-8><meta name=viewport content="width=device-width,initial-scale=1">
<title><?=$venue?h($venue['name']):'Tela do bar'?> · TocaRaul</title>
<link rel=manifest href="/manifest.webmanifest"><meta name=theme-color content="#090909"><link rel=apple-touch-icon href="/assets/icon-192.png"><link rel=icon href="/assets/icon-192.png">
<style>*{box-sizing:border-box}html,body{margin:0;height:100%;background:#090909;color:#fff;font-family:system-ui;overflow:hidden}
.wrap{display:none;height:100vh;padding:5vh 5vw;align-items:center;gap:5vw}
.wrap.on{display:flex}
.info{flex:1;min-width:0}
.logo{width:220px;max-width:22vw;display:block;margin-bottom:2vh}
.center{margin:auto;max-width:560px;text-align:center}
.venue{color:#ffcc00;font-size:2.2vw;font-weight:900;margin:0 0 1vh;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.title{color:#fff;font-size:3vw;font-weight:900;margin:0 0 1vh;line-height:1.15}
.artist{color:#ccc;font-size:1.6vw;margin:0 0 1vh}
.queue{color:#ccc;font-size:1.4vw;margin:0 0 1vh}.queuebox{margin-top:3vh;max-width:720px;background:#f5eddf;color:#171717;border:5px solid #171717;padding:18px;box-shadow:10px 10px 0 #e33}.queuebox h2{margin:0 0 10px;font-size:1.4rem}.queueitem{padding:10px 0;border-top:2px solid #171717}.queueitem b{font-size:1.05rem}.queueitem small{display:block;margin-top:4px}.queueitem em{display:block;color:#a22;margin-top:4px;font-style:normal}
.dedication{color:#ffcc00;font-size:1.4vw}
.qrcol{width:280px;max-width:26vw;text-align:center;flex-shrink:0}
.qrcol .caption{font-size:18px;font-weight:700;margin-bottom:10px}
.qrbox{background:#fff;padding:10px;border-radius:8px}
.qrbox canvas{display:block;margin:auto}
.urltext{color:#999;font-size:11px;margin-top:8px;word-break:break-all}
#player{position:fixed;inset:0;background:#000;display:none;z-index:20}
#player.on{display:block}
.fs{position:fixed;top:16px;right:16px;background:#171717;border:1px solid #333;color:#ffcc00;padding:10px 16px;border-radius:10px;font-size:14px;cursor:pointer;z-index:30}
.fs.install{right:150px;display:none}
@media (display-mode:standalone){.fs:not(.install){display:none}}
.login{margin:auto;max-width:420px;width:100%}
.login h1{font-size:26px;margin:18px 0 4px;text-align:center}
.login p{color:#999;text-align:center;margin:0 0 18px;font-size:15px}
.login label{display:block;color:#bbb;font-size:13px;margin:12px 0 4px}
.login input{width:100%;padding:14px;border-radius:11px;border:1px solid #333;background:#080808;color:#fff;font-size:16px}
.login button{width:100%;margin-top:18px;padding:15px;border:0;border-radius:12px;background:#fc0;color:#090909;font-weight:900;font-size:17px;cursor:pointer}
.login .err{background:#301414;color:#ffaaaa;padding:11px;border-radius:10px;font-size:14px;margin-bottom:12px}
.login .foot{text-align:center;margin-top:16px;font-size:14px;color:#999}
.login a{color:#fc0}
.scroll{height:100vh;overflow:auto;display:flex;padding:24px}
#start{position:fixed;inset:0;background:#090909;z-index:40;display:flex;align-items:center;justify-content:center;padding:24px}
#start.off{display:none}
#start .box{max-width:560px;text-align:center}
#start h1{font-size:32px;margin:18px 0 6px}
#start p{color:#ccc;margin:0 0 6px}
#start .go{margin-top:22px;padding:18px 34px;border:0;border-radius:14px;background:#fc0;color:#090909;font-weight:900;font-size:20px;cursor:pointer}
#start .yt{margin-top:26px;background:#141414;border:1px solid #292929;border-radius:14px;padding:16px;text-align:left}
#start .yt b{color:#fc0}
#start .yt p{font-size:14px;color:#aaa;margin:6px 0 0}
#start .yt a{display:inline-block;margin-top:10px;color:#fc0;font-weight:700}
.exit{position:fixed;bottom:14px;right:16px;background:#141414;border:1px solid #333;color:#888;padding:8px 12px;border-radius:9px;font-size:12px;cursor:pointer;z-index:30}
</style></head><body>
<?php if(!$venue):?>
<div class=scroll><div class=login>
 <img src="/assets/tocaraul_logo.png" alt=TocaRaul style="width:110px;display:block;margin:auto">
 <h1>Tela do bar</h1>
 <p>Entre com a conta do seu bar para esta tela começar a tocar a fila.</p>
 <?php if($err):?><div class=err><?=h($err)?></div><?php endif;?>
 <form method=post>
  <input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>">
  <label>Código do bar</label><input name=code required autofocus autocapitalize=characters>
  <label>Senha</label><input type=password name=password required>
  <button>Entrar e começar</button>
 </form>
 <div class=foot>Não tem cadastro? <a href="/cadastro">Cadastre seu bar</a></div>
</div></div>
<?php else:?>
<button class=fs id=fsBtn type=button>⛶ Tela cheia</button><button class="fs install" id=installBtn type=button>⬇ Instalar</button>
<div id=viewReconnect class=wrap><div class=center><img class=logo src="/assets/tocaraul_logo.png" alt=TocaRaul style="margin:auto auto 3vh"><div style="font-size:28px;font-weight:900">Reconectando…</div><div style="color:#ccc;margin-top:10px">A tela volta automaticamente quando a conexão for restabelecida.</div></div></div>
<div id=viewRevoked class=wrap><div class=center><img class=logo src="/assets/tocaraul_logo.png" alt=TocaRaul style="margin:auto auto 3vh"><div style="font-size:28px;font-weight:900">Tela desconectada</div><div style="color:#ccc;margin:10px 0 22px">Esta tela foi desconectada no painel do bar. Entre novamente para voltar a tocar.</div><button class=go id=revokedBtn type=button style="padding:15px 28px;border:0;border-radius:12px;background:#fc0;color:#090909;font-weight:900;font-size:17px;cursor:pointer">Entrar novamente</button></div></div>
<div id=viewOnline class=wrap><div class=info><img class=logo src="/assets/tocaraul_logo.png" alt=TocaRaul><div id=venueName class=venue><?=h($venue['name'])?></div><div id=nowTitle class=title>Aguardando pedidos</div><div id=nowArtist class=artist></div><div id=queueSize class=queue>0 pedido(s) na fila</div><div id=dedication class=dedication></div><div class=queuebox><h2>Fila do YouTube / pedidos</h2><div id=queueList>Nenhum pedido aguardando.</div></div></div><div class=qrcol><div class=caption>Peça sua música</div><div class=qrbox id=qrOrder></div><div class=urltext id=orderUrlText></div></div></div>
<div id=player></div>
<form method=post class=exit id=logoutForm><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=logout><button class=exit style="position:static">Sair desta tela</button></form>
<div id=start><div class=box>
 <img src="/assets/tocaraul_logo.png" alt=TocaRaul style="width:150px">
 <h1><?=h($venue['name'])?></h1>
 <p>Toque em começar para esta tela passar a tocar a fila com som.</p>
 <button class=go id=startBtn type=button>▶ Começar</button>
 <div class=yt>
  <b>Sem anúncios no meio da festa?</b>
  <p>O player usa a conta do YouTube que estiver logada <b>neste navegador</b>. Se essa conta tiver YouTube Premium, os vídeos tocam sem anúncio. Entre no YouTube e depois volte e recarregue esta página.</p>
  <a href="https://www.youtube.com/account" target=_blank rel=noopener>Entrar no YouTube neste navegador →</a>
 </div>
</div></div>
<script src="/assets/qrcode.js"></script>
<script>
const VENUE_ID=<?=(int)$venue['id']?>,STORE='tocaraul_player_'+VENUE_ID;
function drawQr(container,url){container.innerHTML='';if(!url)return;new QRCode(container,{text:url,width:Math.min(container.clientWidth||220,220),height:Math.min(container.clientWidth||220,220)})}
function escapeHtml(value){return String(value??'').replace(/[&<>\"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;',"'":'&#39;'}[c]))}
const views=['viewReconnect','viewOnline','viewRevoked'];
function showView(id){views.forEach(v=>document.getElementById(v).classList.toggle('on',v===id))}
let deviceToken=null,started=false;
try{deviceToken=localStorage.getItem(STORE)||null}catch(e){}
// One click gives the document permission to autoplay with sound; without it Chrome mutes or blocks the first song.
startBtn.onclick=()=>{started=true;document.getElementById('start').classList.add('off');document.documentElement.requestFullscreen?.().catch(()=>{})};
revokedBtn.onclick=()=>document.getElementById('logoutForm').requestSubmit();
async function api(path,body,token){const r=await fetch(path,{method:body?'POST':'GET',headers:{'Content-Type':'application/json',...(token?{Authorization:'Bearer '+token}:{})},...(body?{body:JSON.stringify(body)}:{})});if(!r.ok)throw Object.assign(new Error('http_'+r.status),{status:r.status});return r.json()}
async function ensureToken(){if(deviceToken)return;const d=await api('/api/player/token',{name:'Tela do bar'});deviceToken=d.deviceToken;try{localStorage.setItem(STORE,deviceToken)}catch(e){}}
let pendingRequestId=null,pendingResult=null,currentlyPlaying=false;
let ytApiReady=false;window.onYouTubeIframeAPIReady=()=>{ytApiReady=true};
(()=>{let s=document.createElement('script');s.src='https://www.youtube.com/iframe_api';document.head.appendChild(s)})();
let ptVoice=null;
function loadVoices(){const v=speechSynthesis.getVoices();ptVoice=v.find(x=>x.lang&&x.lang.toLowerCase().startsWith('pt-br'))||v.find(x=>x.lang&&x.lang.toLowerCase().startsWith('pt'))||null}
if('speechSynthesis' in window){loadVoices();speechSynthesis.onvoiceschanged=loadVoices}
function speak(text){return new Promise(resolve=>{if(!('speechSynthesis' in window)){resolve();return}try{const u=new SpeechSynthesisUtterance(text);u.lang='pt-BR';u.rate=0.9;u.pitch=0.85;u.volume=1;if(ptVoice)u.voice=ptVoice;u.onend=resolve;u.onerror=resolve;speechSynthesis.speak(u)}catch(e){resolve()}})}
let ytPlayer=null,watchdog=null,commandTimer=null,voiceDone=true,pausedByOwner=false;
// Only guards against a song that never starts or stalls. A pause asked for by the bar must never be skipped.
function armWatchdog(){clearTimeout(watchdog);watchdog=setTimeout(()=>finishPlayback('SKIPPED'),60000)}
function clearWatchdog(){clearTimeout(watchdog);watchdog=null}
function finishPlayback(result){if(!currentlyPlaying)return;currentlyPlaying=false;pausedByOwner=false;clearWatchdog();clearInterval(commandTimer);document.getElementById('player').classList.remove('on');try{ytPlayer&&ytPlayer.stopVideo&&ytPlayer.stopVideo()}catch(e){}pendingResult=result}
function rampVolumeUp(){if(!ytPlayer)return;let v=18;const iv=setInterval(()=>{v+=8;if(v>=100){v=100;clearInterval(iv)}try{ytPlayer.setVolume(v)}catch(e){clearInterval(iv)}},120)}
function startPlayback(videoId,requestId){pendingRequestId=requestId;currentlyPlaying=true;document.getElementById('player').classList.add('on');const start=()=>{document.getElementById('player').innerHTML='<div id=ytmount style="width:100%;height:100%"></div>';ytPlayer=new YT.Player('ytmount',{width:'100%',height:'100%',videoId,playerVars:{autoplay:1,controls:1,rel:0,playsinline:1},events:{onReady:e=>{e.target.setVolume(voiceDone?100:18);e.target.playVideo();armWatchdog()},onStateChange:e=>{if(e.data===YT.PlayerState.PLAYING||e.data===YT.PlayerState.BUFFERING)clearWatchdog();else if(!pausedByOwner)armWatchdog();if(e.data===YT.PlayerState.ENDED)finishPlayback('PLAYED')},onError:()=>finishPlayback('SKIPPED')}});commandTimer=setInterval(async()=>{try{const c=await api('/api/player/command',{},deviceToken);if(c.command==='SKIP')finishPlayback('SKIPPED');else if(c.command==='PAUSE'&&ytPlayer){pausedByOwner=true;clearWatchdog();ytPlayer.pauseVideo()}else if(c.command==='PLAY'&&ytPlayer){pausedByOwner=false;ytPlayer.playVideo()}}catch(e){}},1500)};if(ytApiReady)start();else{const t=setInterval(()=>{if(ytApiReady){clearInterval(t);start()}},200)}}
async function tick(){try{await ensureToken();const state=await api('/api/device/state',null,deviceToken);
if(state.connection==='ONLINE'){showView('viewOnline');api('/api/device/heartbeat',{},deviceToken).catch(()=>{});document.getElementById('venueName').textContent=state.venue?.name||'TocaRaul';document.getElementById('queueSize').textContent=(state.queueSize||0)+' pedido(s) na fila';document.getElementById('orderUrlText').textContent=state.qrCodeUrl||'';drawQr(document.getElementById('qrOrder'),state.qrCodeUrl);const items=state.queue||[];document.getElementById('queueList').innerHTML=items.length?items.map((x,i)=>'<div class="queueitem"><b>'+(i+1)+'. '+escapeHtml(x.title||'Pedido')+'</b><small>'+escapeHtml(x.artist||'')+' · '+escapeHtml(x.status||'')+'</small>'+(x.message?'<em>“'+escapeHtml(x.message)+'”</em>':'')+'</div>').join(''):'Nenhum pedido aguardando.';if(!currentlyPlaying){document.getElementById('nowTitle').textContent=state.nowPlaying?.title||'Aguardando pedidos';document.getElementById('nowArtist').textContent=state.nowPlaying?.artist||'';document.getElementById('dedication').textContent=state.nowPlaying?.message||''}
if(pendingRequestId&&pendingResult){await api('/api/player/complete',{requestId:parseInt(pendingRequestId,10),result:pendingResult},deviceToken);pendingRequestId=null;pendingResult=null}
if(started&&!pendingRequestId&&!currentlyPlaying){const claimed=await api('/api/player/claim',{},deviceToken);const track=claimed.track;const videoId=track?.providerId?.replace('youtube:','');if(track&&videoId&&/^[A-Za-z0-9_-]{11}$/.test(videoId)){const announceOn=state.venue?.announceDedication!==false;if(track.message&&announceOn){voiceDone=false;const who=track.visitorName&&track.visitorName!=='Cliente'?track.visitorName:'um cliente';speak('Uma dedicatória de '+who+'. '+track.message+'. E agora, para você: '+track.title+'.').then(()=>{voiceDone=true;rampVolumeUp()})}else voiceDone=true;startPlayback(videoId,track.id)}else if(track){await api('/api/player/complete',{requestId:parseInt(track.id,10),result:'SKIPPED'},deviceToken)}}}
else showView('viewReconnect')}catch(e){
 if(e.status===401){
  // A token we already had was revoked in the panel: stop here and ask for login again,
  // instead of silently minting another one or reloading in a loop.
  if(deviceToken){try{localStorage.removeItem(STORE)}catch(x){}deviceToken=null;finishPlayback('SKIPPED');showView('viewRevoked');return}
  location.reload();return
 }
 if(e.status===410||e.status===404){try{localStorage.removeItem(STORE)}catch(x){}deviceToken=null}
 showView('viewReconnect')}
setTimeout(tick,2000)}
tick();
fsBtn.onclick=()=>{if(document.fullscreenElement)document.exitFullscreen();else document.documentElement.requestFullscreen().catch(()=>{})};
if('serviceWorker' in navigator)navigator.serviceWorker.register('/sw.js').catch(()=>{});
let installPrompt=null;
window.addEventListener('beforeinstallprompt',e=>{e.preventDefault();installPrompt=e;installBtn.style.display='block'});
installBtn.onclick=async()=>{if(!installPrompt)return;installBtn.style.display='none';installPrompt.prompt();await installPrompt.userChoice;installPrompt=null};
window.addEventListener('appinstalled',()=>{installBtn.style.display='none'});
</script>
<?php endif;?>
</body></html>

<?php
declare(strict_types=1);require __DIR__.'/../api_config.php';require_once __DIR__.'/onboarding.php';
function d():PDO{return settings_db();}
ensure_bar_schema();open_bar_session();
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}

$v=null;$tables=[];
if(!empty($_SESSION['venue'])){
 $q=d()->prepare('SELECT * FROM venues WHERE id=?');$q->execute([(int)$_SESSION['venue']]);$v=$q->fetch();
 $q=d()->prepare("SELECT qrToken FROM venueTables WHERE venueId=? AND status='ACTIVE' ORDER BY id");$q->execute([(int)$_SESSION['venue']]);$tables=$q->fetchAll();
}
$qrUrl=($v&&$tables)?runtime_public_url().'/j/'.$tables[0]['qrToken']:'';
?><!doctype html><meta name=viewport content="width=device-width,initial-scale=1"><title>TocaRaul · Tela da TV</title>
<link rel=stylesheet href="/assets/bauhaus.css?v=20260914-2"><link rel=stylesheet href="/assets/tv.css?v=20260914-3">
<body class=tv-app>
<?php if(!$v):?>
<div class="tv-gate bh-panel">
 <h1>TocaRaul</h1>
 <p>Entre com a conta do bar para abrir a tela da TV.</p>
 <div class="c bh-login">
  <form method=post action=/bar><input type=hidden name=csrf value="<?=h($_SESSION['csrf'])?>"><input type=hidden name=a value=login><input type=hidden name=next value=/tv><label>Código do bar</label><input name=code required><label>Senha</label><input type=password name=password required><button>Entrar</button></form>
 </div>
</div>
<?php else:?>
<div class=tv-stage>
 <div class=tv-player id=playerArea>
  <div id=ytmount class=tv-mount></div>
  <div id=idleState class=tv-idle>
   <?php if(!empty($v['logoPath'])):?><img src="<?=h($v['logoPath'])?>" alt="<?=h($v['name'])?>"><?php endif;?>
   <h1>Escolha a próxima música</h1>
   <p>Escaneie o QR Code →</p>
  </div>
  <div class="tv-safe tv-brandcorner">
   <?php if(!empty($v['logoPath'])):?><img src="<?=h($v['logoPath'])?>" alt="<?=h($v['name'])?>"><?php else:?><img src="/assets/tocaraul_logo.png" alt="TocaRaul"><?php endif;?>
  </div>
  <?php if(!empty($v['logoPath'])):?><div class="tv-safe tv-brandcorner powered" style="top:auto;bottom:var(--tv-safe);right:var(--tv-safe);left:auto">powered by TocaRaul</div><?php endif;?>
  <div class="tv-safe tv-nowplaying hidden" id=nowPlayingOverlay>
   <span class=tv-eyebrow>Tocando agora</span>
   <b id=npTitle></b>
   <span id=npArtist></span>
  </div>
  <div class="tv-safe tv-toast" id=toast>
   <span class=tv-eyebrow>Entrou na fila</span>
   <b id=toastTitle></b>
   <span id=toastWho></span>
  </div>
  <div class=tv-gate id=gate>
   <h1>TocaRaul</h1>
   <p>Clique para iniciar a jukebox</p>
   <button type=button id=startBtn>Iniciar TocaRaul</button>
  </div>
  <div class=tv-reconnect id=reconnect>Reconectando…</div>
 </div>
 <div class=tv-side>
  <div class="tv-dedication empty" id=dedicationPanel>
   <span class=tv-eyebrow id=dedEyebrow></span>
   <p id=dedMessage>Leia o QR code e escolha a música para tocar aqui...</p>
  </div>
  <div class=tv-qr>
   <span class=tv-eyebrow>Escolha a próxima música</span>
   <div class=code id=stageQr></div>
   <span>Aponte a câmera para o QR Code</span>
  </div>
 </div>
</div>
<button type=button id=fsBtn class=tv-fs-btn>⛶ Entrar em tela cheia</button>
<script src="/assets/qrcode.js"></script>
<script src="/assets/player.js?v=20260914-4"></script>
<script>
const qrUrl=<?=json_encode($qrUrl,JSON_UNESCAPED_SLASHES)?>;
if(qrUrl)new QRCode(document.getElementById('stageQr'),{text:qrUrl,width:320,height:320});

const mount=document.getElementById('ytmount'),idle=document.getElementById('idleState');
const nowOverlay=document.getElementById('nowPlayingOverlay');
const dedPanel=document.getElementById('dedicationPanel'),dedEyebrow=document.getElementById('dedEyebrow'),dedMessage=document.getElementById('dedMessage');
const toast=document.getElementById('toast'),toastTitle=document.getElementById('toastTitle'),toastWho=document.getElementById('toastWho');
const gate=document.getElementById('gate'),reconnect=document.getElementById('reconnect');
const CONVITE='Leia o QR code e escolha a música para tocar aqui...';

let lastDedication='',known=null,toastTimer=0;

// A lateral e sempre dona do bloco de cima: com pedido pago, mostra a
// dedicatoria; sem pedido pago, convida a pedir. Nunca fica em branco.
function paintQueue(state){
 const atual=state.nowPlaying;
 const texto=atual?.message||'';
 const conteudo=texto?(texto+(atual.visitorName?' — '+atual.visitorName:'')):CONVITE;
 dedPanel.classList.toggle('empty',!texto);
 dedEyebrow.textContent=texto?'Dedicatória':'';
 dedMessage.textContent=conteudo;
 if(conteudo!==lastDedication){
  dedPanel.classList.remove('show');
  requestAnimationFrame(()=>requestAnimationFrame(()=>dedPanel.classList.add('show')));
 }
 lastDedication=conteudo;

 // Pedido novo: aviso temporario que nao bloqueia o video.
 const queue=state.queue||[];
 const ids=new Set(queue.map(i=>i.id));
 if(known===null){known=ids;return}
 const novos=queue.filter(i=>!known.has(i.id)&&i.status==='QUEUED');
 known=ids;
 if(novos.length)announce(novos[novos.length-1]);
}

function announce(pedido){
 toastTitle.textContent=pedido.title+(pedido.artist?' · '+pedido.artist:'');
 toastWho.textContent=pedido.visitorName?'Pedido de '+pedido.visitorName:'';
 toast.classList.add('on');
 clearTimeout(toastTimer);
 toastTimer=setTimeout(()=>toast.classList.remove('on'),5000);
}

const screen=TocaRaulPlayer({
 venueId:<?=(int)$v['id']?>,mount,screenName:'Tela da TV',
 ui:{title:'npTitle',artist:'npArtist'},
 onOnline:()=>reconnect.classList.remove('on'),
 onOffline:()=>reconnect.classList.add('on'),
 onState:paintQueue,
});

new MutationObserver(()=>{
 const on=mount.classList.contains('on');
 idle.classList.toggle('hidden',on);
 nowOverlay.classList.toggle('hidden',!on);
}).observe(mount,{attributes:true,attributeFilter:['class']});

document.getElementById('startBtn').onclick=()=>{
 document.documentElement.requestFullscreen?.().catch(()=>{});
 screen.begin();
 gate.classList.add('hidden');
};
document.getElementById('fsBtn').onclick=()=>{
 document.fullscreenElement?document.exitFullscreen():document.documentElement.requestFullscreen?.().catch(()=>{});
};

// O cursor parado no meio da TV incomoda: some depois de alguns segundos.
let cursorTimer=0;
function resetCursor(){document.body.classList.remove('cursor-hide');clearTimeout(cursorTimer);cursorTimer=setTimeout(()=>document.body.classList.add('cursor-hide'),3000);}
document.addEventListener('mousemove',resetCursor);
resetCursor();

screen.run();
</script>
<?php endif;?>

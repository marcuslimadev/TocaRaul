<?php
declare(strict_types=1);require_once __DIR__.'/admin_settings.php';require_once __DIR__.'/asaas.php';$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';if(!preg_match('~^/j/([A-Za-z0-9_-]{6,40})$~',$path,$m)){http_response_code(404);exit('QR invalido');}$token=$m[1];$q=settings_db()->prepare("SELECT vt.label,v.name venueName,v.logoPath,v.musicPriceCents,v.dedicationPriceCents FROM venueTables vt JOIN venues v ON v.id=vt.venueId WHERE vt.qrToken=? AND vt.status='ACTIVE' LIMIT 1");$q->execute([$token]);$t=$q->fetch();if(!$t){http_response_code(404);exit('QR nao encontrado');}$name=htmlspecialchars((string)$t['venueName'],ENT_QUOTES,'UTF-8');$barLogo=(string)($t['logoPath']??'');if(!preg_match('#^/assets/logos/[A-Za-z0-9_.-]+$#',$barLogo))$barLogo='/assets/tocaraul_logo.png';$label='Pedidos do bar';$music=(int)$t['musicPriceCents'];$ded=(int)$t['dedicationPriceCents'];$ready=asaas_ready();function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}header('Content-Type:text/html;charset=utf-8');?>
<!doctype html><html lang=pt-BR><head><meta charset=utf-8><meta name=viewport content="width=device-width,initial-scale=1"><title><?=$name?> · TocaRaul</title>
<style>
/* ---- Tema definitivo: escuro, uma versao so, sem redefinicoes sucessivas ---- */
:root{--bg:#0d0d0d;--surface:#181818;--surface-2:#222;--text:#fff;--muted:#a8a8a8;--yellow:#f4c430;--red:#d64a40;--blue:#4169a1;--good:#6ee7d8}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,Arial,sans-serif;font-size:17px}
.wrap{max-width:640px;margin:auto;padding:20px 16px 90px}
.topbar{display:flex;align-items:center;margin-bottom:14px}
.logo{display:flex;align-items:center;gap:12px}
.logo img{height:52px;width:52px;object-fit:contain;border-radius:50%;background:var(--surface-2)}
.logo span{font-size:24px;font-weight:900;color:var(--text)}
.tablechip{margin:0 0 16px}
.chip{display:inline-block;background:var(--surface);border:1px solid #333;border-radius:999px;padding:8px 14px;font-size:14px;font-weight:700;color:var(--muted)}
.hero{margin:8px 0 18px}
.hero h1{font-size:clamp(26px,6vw,36px);margin:0;line-height:1.15}
.hero p{font-size:16px;color:var(--muted);margin:8px 0 0}
.bad{color:#ff9b93;font-size:15px}
.good{color:var(--good)}

.steps{display:flex;gap:8px;margin:18px 0}
.step-dot{flex:1;text-align:center;padding:10px 4px;border-radius:8px;background:var(--surface);color:#777;font-size:13px;font-weight:800}
.step-dot.active{background:#2a2205;color:var(--yellow);border:1px solid var(--yellow)}
.step-dot.done{color:var(--good)}

.stepview{display:none}
.stepview.on{display:block}

.field{margin-top:16px}
.field label{display:block;color:var(--text);font-size:17px;font-weight:800;margin-bottom:9px}
.field .hint{display:block;color:var(--muted);font-size:13px;font-weight:400;margin-top:2px}
input,textarea{width:100%;background:var(--surface-2);color:var(--text);border:2px solid #3a3a3a;border-radius:12px;padding:14px;font-size:17px;min-height:48px}
textarea{min-height:80px}
input:focus,textarea:focus{outline:none;border-color:var(--yellow)}

/* Passo 1 · busca: e o foco principal da tela. */
#search{font-size:19px;padding:17px 16px;min-height:56px;border:2px solid var(--yellow);background:var(--surface)}
#catalog{margin-top:16px;display:grid;gap:10px}
.song{background:var(--surface);border:2px solid #2c2c2c;border-radius:16px;color:var(--text);text-align:left;width:100%;min-width:0;min-height:88px;display:flex;align-items:center;gap:12px;font-size:17px;padding:12px 14px;cursor:pointer}
.song img{width:60px;height:60px;border-radius:10px;object-fit:cover;flex-shrink:0;background:#070707}
.song .info{flex:1;min-width:0}
.song .info strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.song .info span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted);font-size:15px}
.song .price{color:var(--yellow);font-size:16px;font-weight:800;flex-shrink:0}
.song .choose{background:var(--red);color:#fff;border-radius:6px;padding:9px 12px;font-size:13px;font-weight:800;white-space:nowrap;text-transform:uppercase}
.song.selected{border-color:var(--yellow);background:#221d08}
.song.selected .choose{background:var(--yellow);color:#171717}
.song.selected .choose:before{content:'✓ '}

.btn{width:100%;border:0;border-radius:13px;background:var(--yellow);color:#171717;padding:16px;font-weight:900;font-size:18px;margin-top:16px;min-height:52px;cursor:pointer;text-transform:uppercase;letter-spacing:.01em}
.btn:disabled{opacity:.4;cursor:default}
.btn.ghost{background:transparent;color:var(--yellow);border:2px solid var(--yellow)}
.btn.secondary{background:var(--surface-2);color:var(--text);border:1px solid #3a3a3a}

.card{background:var(--surface);border:1px solid #2c2c2c;border-radius:18px;padding:20px}
.card h2{font-size:21px;margin:0 0 4px}
.chosen{display:flex;align-items:center;gap:12px;margin-bottom:6px;background:var(--surface-2);border-radius:14px;padding:12px}
.chosen img{width:56px;height:56px;border-radius:10px;object-fit:cover;flex-shrink:0}
.chosen .info{flex:1;min-width:0}
.chosen .info strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chosen .info span{color:var(--muted);font-size:15px}
.total{display:flex;justify-content:space-between;align-items:baseline;font-size:24px;font-weight:900;margin-top:16px}
.total span:last-child{color:var(--yellow)}

/* Passo 3 · pagamento: hierarquia clara, um bloco por vez. */
.pix-title{font-size:22px;font-weight:900;text-transform:uppercase;letter-spacing:.02em;margin:0}
.pix-price{font-size:32px;font-weight:900;color:var(--yellow);margin:6px 0 18px}
.qr{background:#fff;padding:14px;width:min(256px,100%);border-radius:14px;margin:0 auto 18px;display:block}
.qr canvas{max-width:100%;height:auto;display:block;margin:auto}
.copy-label{color:var(--muted);font-size:14px;margin:0 0 6px}
.copy{word-break:break-all;background:var(--surface-2);padding:12px;border-radius:10px;color:var(--muted);font-size:14px;margin-bottom:12px}
#status{margin-top:16px;font-size:16px;text-align:center}
.secure{display:flex;align-items:center;gap:8px;color:var(--good);font-size:14px;margin:16px 0 0}
.pixbadge{display:flex;align-items:center;gap:12px;background:var(--surface-2);border-radius:14px;padding:12px;margin-bottom:16px}
.pixlogo{background:#fff;border-radius:8px;padding:7px 9px;flex-shrink:0;line-height:0}
.pixlogo img{display:block;width:76px;height:auto}
.pixbadge b{color:#32BCAD;font-size:17px;display:block}
.pixbadge small{color:#8fd6cf;display:block;font-size:13px;margin-top:2px}

@media(min-width:480px){.wrap{padding-top:32px}}
@media(max-width:390px){.hero h1{font-size:24px}.card{padding:16px}}
</style></head><body><div class=wrap>
<div class=topbar><div class=logo><img src="<?=h($barLogo)?>" alt="<?=h($name)?>"><span><?=h($name)?></span></div></div>
<div class=tablechip><span class=chip><?=$label?></span></div>
<div class=hero><h1>Escolha. Pague. Tocou.</h1><p><?=$name?> · música <b id=base></b></p></div>
<?php if(!$ready):?><p class=bad>Cobranças sandbox ainda não configuradas pelo TocaRaul.</p><?php endif;?>
<div class=steps><div class="step-dot active" id=dot1>1 · Música</div><div class=step-dot id=dot2>2 · Seus dados</div><div class=step-dot id=dot3>3 · Pagamento</div></div>

<div id=view1 class="stepview on">
<div class=field><label>🔎 Qual música você quer ouvir?</label><input id=search placeholder="Digite o nome da música ou artista" autocomplete=off></div>
<div id=catalog></div>
<button id=toStep2 class=btn disabled>Continuar com esta música</button>
</div>

<div id=view2 class=stepview>
<div class=card><h2>Seu pedido</h2><div id=chosenSummary class=chosen></div>
<div class=field><label>Seu nome</label><input id=visitor maxlength=80 placeholder="Como podemos te chamar?"></div>
<div class=field><label>Quer mandar uma dedicatória?<span class=hint><?=$ded>0?'+ '.htmlspecialchars(number_format($ded/100,2,',','.'),ENT_QUOTES,'UTF-8'):'Opcional e grátis'?></span></label><textarea id=message maxlength=180 placeholder="Escreva sua mensagem (opcional)"></textarea></div>
<div class=total><span>Total</span><span id=total></span></div>
<button id=pay class=btn <?=!$ready?'disabled':''?>>Gerar Pix</button>
<p id=error class=bad></p>
<button id=backTo1 type=button class="btn ghost">← Trocar música</button>
</div>
</div>

<div id=view3 class=stepview>
<div class=card>
<p class=pix-title>Pague com Pix</p>
<p id=pixTotal class=pix-price></p>
<div id=pixqr class=qr></div>
<p class=copy-label>Copie o código Pix</p>
<div id=code class=copy></div>
<button id=copy class=btn>Copiar Pix</button>
<p id=status>Aguardando pagamento...</p>
<button id=mockconfirm class="btn secondary">🧪 Já paguei (confirmar sandbox)</button>
<p class=secure>🔒 Ambiente seguro — o TocaRaul não vê nem guarda seus dados bancários, tudo passa direto pelo Pix.</p>
</div>
</div>
</div>
<script src="/assets/qrcode.js"></script><script>
const token=<?=json_encode($token)?>,music=<?=$music?>,dedication=<?=$ded?>,ready=<?=$ready?'true':'false'?>;
let selected=null,requestId=null,searchTimer=null;const money=c=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(c/100);base.textContent=money(music);
function showStep(n){[1,2,3].forEach(i=>{document.getElementById('view'+i).classList.toggle('on',i===n);const d=document.getElementById('dot'+i);d.classList.toggle('active',i===n);d.classList.toggle('done',i<n)});window.scrollTo(0,0)}
function renderResults(list){catalog.innerHTML='';if(!list.length){catalog.innerHTML='<p style="color:var(--muted)">Nenhum resultado. Tente outro termo.</p>';return}list.forEach(s=>{let b=document.createElement('div');b.className='song'+(selected&&selected.id===s.id?' selected':'');b.setAttribute('role','button');b.setAttribute('tabindex','0');let img=document.createElement('img');img.src=s.thumbnail||'';img.alt='';let info=document.createElement('div');info.className='info';let strong=document.createElement('strong');strong.textContent=s.title;let artistSpan=document.createElement('span');artistSpan.textContent=s.artist;info.append(strong,artistSpan);let price=document.createElement('span');price.className='price';price.textContent=money(music);let choose=document.createElement('span');choose.className='choose';choose.textContent=selected&&selected.id===s.id?'Selecionada':'Escolher';b.append(img,info,price,choose);b.onclick=()=>{selected=s;renderResults(list);toStep2.disabled=false};catalog.appendChild(b)})}
function refresh(){total.textContent=money(music+(message.value.trim()?dedication:0));pay.disabled=!ready||!selected}
message.oninput=refresh;refresh();
try{visitor.value=localStorage.getItem('tocaraul_visitor')||''}catch(e){}
search.oninput=()=>{clearTimeout(searchTimer);const term=search.value.trim();if(term.length<2){catalog.innerHTML='';return}searchTimer=setTimeout(async()=>{catalog.innerHTML='<p style="color:var(--muted)">Buscando...</p>';try{let r=await fetch('/api/commerce/search?q='+encodeURIComponent(term)+'&qrToken='+encodeURIComponent(token));let d=await r.json();if(!r.ok){catalog.innerHTML='<p class=bad>'+(d.message||'Busca indisponível.')+'</p>';return}renderResults(d.results||[])}catch(e){catalog.innerHTML='<p class=bad>Busca indisponível no momento.</p>'}},400)};
function renderChosen(){chosenSummary.innerHTML='';if(!selected)return;let img=document.createElement('img');img.src=selected.thumbnail||'';img.alt='';let info=document.createElement('div');info.className='info';let strong=document.createElement('strong');strong.textContent=selected.title;let span=document.createElement('span');span.textContent=selected.artist;info.append(strong,span);chosenSummary.append(img,info)}
toStep2.onclick=()=>{if(!selected)return;renderChosen();refresh();showStep(2)};
backTo1.onclick=()=>{showStep(1)};
pay.onclick=async()=>{if(!selected)return;error.textContent='';pay.disabled=true;try{try{localStorage.setItem('tocaraul_visitor',visitor.value)}catch(e){}let r=await fetch('/api/commerce/request',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({qrToken:token,visitorName:visitor.value||'Cliente',providerId:selected.id,title:selected.title,artist:selected.artist,message:message.value})});let d=await r.json();if(!r.ok)throw Error(d.message||'Erro ao gerar Pix');requestId=d.requestId;code.textContent=d.pixCopyPaste;pixTotal.textContent=money(music+(message.value.trim()?dedication:0));pixqr.innerHTML='';new QRCode(pixqr,{text:d.pixCopyPaste,width:232,height:232});showStep(3);poll()}catch(e){error.textContent=e.message;pay.disabled=!ready}};
async function poll(){try{let r=await fetch('/api/commerce/payment?requestId='+requestId,{cache:'no-store'}),d=await r.json();if(r.ok&&(d.paymentStatus==='APPROVED'||['QUEUED','PLAYING','PLAYED'].includes(d.requestStatus))){document.getElementById('status').innerHTML='<b class=good>✓ Pagamento confirmado — sua música entrou na fila!</b>';mockconfirm.style.display='none';return}}catch(e){}setTimeout(poll,4000)}copy.onclick=async()=>{await navigator.clipboard.writeText(code.textContent);copy.textContent='Pix copiado'};
mockconfirm.onclick=async()=>{mockconfirm.disabled=true;mockconfirm.textContent='Confirmando...';try{await fetch('/api/commerce/mock-confirm',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({requestId})})}catch(e){}mockconfirm.disabled=false;mockconfirm.textContent='🧪 Já paguei (confirmar sandbox)'};
</script></body></html>

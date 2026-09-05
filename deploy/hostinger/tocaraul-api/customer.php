<?php
declare(strict_types=1);

require __DIR__ . '/../api_config.php';

function customer_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $pdo;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (!preg_match('~^/j/([A-Za-z0-9_-]{6,40})$~', $path, $match)) {
    http_response_code(404);
    exit('QR Code invalido');
}

$qrToken = $match[1];
$stmt = customer_db()->prepare("SELECT vt.id, vt.label, vt.qrToken, v.id AS venueId, v.name AS venueName, v.musicPriceCents, v.dedicationPriceCents, v.mercadoPagoAccessToken FROM venueTables vt JOIN venues v ON v.id = vt.venueId WHERE vt.qrToken = ? AND vt.status = 'ACTIVE' LIMIT 1");
$stmt->execute([$qrToken]);
$table = $stmt->fetch();
if (!$table) {
    http_response_code(404);
    exit('Mesa nao encontrada ou desativada');
}

$venueName = htmlspecialchars((string) $table['venueName'], ENT_QUOTES, 'UTF-8');
$tableLabel = htmlspecialchars((string) $table['label'], ENT_QUOTES, 'UTF-8');
$musicPriceCents = (int) $table['musicPriceCents'];
$dedicationPriceCents = (int) $table['dedicationPriceCents'];
$mercadoPagoReady = !empty($table['mercadoPagoAccessToken']);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= $venueName ?> · TocaRaul</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#090909;color:#fff;font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif}button,input,textarea{font:inherit}.wrap{max-width:720px;margin:auto;padding:20px 16px 110px}.brand{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}.logo{font-size:26px;font-weight:900;color:#ffcc00}.chip{font-size:12px;background:#171717;border:1px solid #303030;border-radius:999px;padding:8px 12px;color:#ddd}.hero{padding:18px 0 22px}.hero h1{font-size:36px;line-height:1.02;margin:0 0 10px}.hero p{color:#bbb;margin:0}.price{color:#ffcc00;font-weight:800}.grid{display:grid;gap:12px}.song{width:100%;border:1px solid #262626;background:#121212;color:white;border-radius:18px;padding:16px;text-align:left;display:flex;justify-content:space-between;align-items:center}.song strong{display:block;font-size:17px}.song span{display:block;color:#aaa;margin-top:3px}.song b{color:#ffcc00}.song.selected{border-color:#ffcc00;background:#191600}.card{background:#121212;border:1px solid #272727;border-radius:20px;padding:18px;margin-top:18px}.card h2{margin:0 0 12px}.field{margin-top:12px}.field label{display:block;color:#aaa;font-size:13px;margin-bottom:6px}.field input,.field textarea{width:100%;background:#080808;color:#fff;border:1px solid #333;border-radius:14px;padding:14px}.field textarea{min-height:90px;resize:vertical}.total{display:flex;justify-content:space-between;border-top:1px solid #292929;margin-top:16px;padding-top:15px;font-size:20px;font-weight:900}.primary{width:100%;margin-top:16px;padding:16px;border:0;border-radius:16px;background:#ffcc00;color:#080808;font-weight:900}.primary:disabled{opacity:.45}.error{color:#ff7f7f;margin-top:12px}.status{padding:16px;border-radius:16px;background:#101d12;border:1px solid #285d30;color:#adffb8;margin-top:16px}.pix{display:none}.pix.visible{display:block}.pixbox{background:#fff;padding:14px;border-radius:18px;width:260px;max-width:100%;margin:16px auto}.pixbox canvas,.pixbox img{display:block!important;width:100%!important;height:auto!important}.copy{width:100%;word-break:break-all;background:#070707;border:1px solid #333;border-radius:12px;padding:12px;color:#bbb;font-size:12px}.small{font-size:12px;color:#888;margin-top:12px;text-align:center}.disabled{background:#281b05;border:1px solid #6b4810;color:#ffd67a;padding:14px;border-radius:16px;margin:16px 0}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand"><div class="logo">TocaRaul</div><div class="chip"><?= $tableLabel ?></div></div>
  <section class="hero"><h1>Escolha a música.<br>Pagou, entrou na fila.</h1><p><?= $venueName ?> · música <span class="price" id="basePrice"></span></p></section>

  <?php if (!$mercadoPagoReady): ?>
    <div class="disabled">Este bar ainda não concluiu a conexão com o Mercado Pago. O QR será liberado automaticamente depois da autorização.</div>
  <?php endif; ?>

  <div id="catalog" class="grid"></div>

  <section class="card">
    <h2>Seu pedido</h2>
    <div class="field"><label>Seu nome</label><input id="visitorName" maxlength="80" placeholder="Ex.: Marcus"></div>
    <div class="field"><label>Dedicatória (opcional · acrescenta <span id="dedicationPrice"></span>)</label><textarea id="message" maxlength="180" placeholder="Ex.: Para a mesa ao lado — essa é nossa!"></textarea></div>
    <div class="total"><span>Total</span><span id="totalPrice"></span></div>
    <button class="primary" id="payButton" <?= $mercadoPagoReady ? '' : 'disabled' ?>>Gerar Pix</button>
    <div id="error" class="error"></div>
  </section>

  <section id="pix" class="card pix">
    <h2>Pix gerado</h2>
    <p>Escaneie o QR Code ou copie o Pix. A música só entra na fila depois da confirmação real do Mercado Pago.</p>
    <div id="pixQr" class="pixbox"></div>
    <div id="pixCode" class="copy"></div>
    <button id="copyButton" class="primary">Copiar Pix</button>
    <div id="paymentStatus" class="small">Aguardando pagamento…</div>
  </section>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
const QR_TOKEN = <?= json_encode($qrToken) ?>;
const MUSIC_PRICE = <?= $musicPriceCents ?>;
const DEDICATION_PRICE = <?= $dedicationPriceCents ?>;
const MP_READY = <?= $mercadoPagoReady ? 'true' : 'false' ?>;
const API_BASE = window.location.origin;
const catalog = [
  {id:'ytsearch:Evidencias Chitaozinho Xororo',title:'Evidências',artist:'Chitãozinho & Xororó'},
  {id:'ytsearch:Exagerado Cazuza',title:'Exagerado',artist:'Cazuza'},
  {id:'ytsearch:Tempo Perdido Legiao Urbana',title:'Tempo Perdido',artist:'Legião Urbana'},
  {id:'ytsearch:Anna Julia Los Hermanos',title:'Anna Júlia',artist:'Los Hermanos'}
];
let selected = catalog[0];
let requestId = null;
let pollHandle = null;
const money = cents => new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(cents/100);
const catalogEl = document.getElementById('catalog');
const messageEl = document.getElementById('message');
const payButton = document.getElementById('payButton');
document.getElementById('basePrice').textContent = money(MUSIC_PRICE);
document.getElementById('dedicationPrice').textContent = money(DEDICATION_PRICE);
function total(){return MUSIC_PRICE + (messageEl.value.trim() ? DEDICATION_PRICE : 0)}
function refreshTotal(){document.getElementById('totalPrice').textContent = money(total())}
function renderCatalog(){catalogEl.innerHTML='';catalog.forEach(song=>{const button=document.createElement('button');button.className='song'+(selected.id===song.id?' selected':'');button.innerHTML=`<div><strong>${song.title}</strong><span>${song.artist}</span></div><b>${money(MUSIC_PRICE)}</b>`;button.onclick=()=>{selected=song;renderCatalog()};catalogEl.appendChild(button)})}
renderCatalog();refreshTotal();messageEl.addEventListener('input',refreshTotal);

async function createPayment(){
  document.getElementById('error').textContent='';
  payButton.disabled=true;payButton.textContent='Gerando Pix…';
  try{
    const response=await fetch(API_BASE+'/api/commerce/request',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
      qrToken:QR_TOKEN,
      visitorName:document.getElementById('visitorName').value.trim()||'Cliente',
      providerId:selected.id,
      title:selected.title,
      artist:selected.artist,
      message:messageEl.value.trim(),
      payerEmail:'cliente@tocaraul.app'
    })});
    const data=await response.json();
    if(!response.ok) throw new Error(data.message||'Não foi possível gerar o Pix');
    requestId=data.requestId;
    document.getElementById('pixCode').textContent=data.pixCopyPaste;
    document.getElementById('pixQr').innerHTML='';
    new QRCode(document.getElementById('pixQr'),{text:data.pixCopyPaste,width:232,height:232});
    document.getElementById('pix').classList.add('visible');
    document.getElementById('pix').scrollIntoView({behavior:'smooth'});
    pollPayment();
  }catch(error){document.getElementById('error').textContent=error.message||'Erro ao gerar Pix';payButton.disabled=!MP_READY;payButton.textContent='Gerar Pix'}
}

async function pollPayment(){
  if(!requestId)return;
  try{
    const response=await fetch(API_BASE+'/api/commerce/payment?requestId='+encodeURIComponent(requestId),{cache:'no-store'});
    const data=await response.json();
    if(response.ok){
      const status=document.getElementById('paymentStatus');
      if(data.paymentStatus==='APPROVED'||data.requestStatus==='QUEUED'||data.requestStatus==='PLAYING'){
        status.innerHTML='<div class="status"><strong>Pagamento confirmado.</strong><br>Sua música já entrou na fila da TV.</div>';
        if(pollHandle)clearTimeout(pollHandle);return;
      }
      if(['REJECTED','CANCELLED'].includes(data.paymentStatus)||['FAILED','CANCELLED'].includes(data.requestStatus)){
        status.textContent='Pagamento não aprovado. Gere um novo pedido.';return;
      }
    }
  }catch(e){}
  pollHandle=setTimeout(pollPayment,2500);
}

payButton.addEventListener('click',createPayment);
document.getElementById('copyButton').addEventListener('click',async()=>{const code=document.getElementById('pixCode').textContent;await navigator.clipboard.writeText(code);document.getElementById('copyButton').textContent='Pix copiado'});
</script>
</body>
</html>

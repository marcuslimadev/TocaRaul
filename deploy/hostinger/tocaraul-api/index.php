<?php
declare(strict_types=1);

require __DIR__ . '/../api_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function json_response(array $payload, int $status = 200) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input') ?: '{}';
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function bearer_token(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($header, 'Bearer ') !== 0) return '';
    return trim(substr($header, 7));
}

function public_base_url(): string {
    return rtrim(PUBLIC_APP_URL, '/');
}

function config_value(string $name): string {
    if (defined($name)) return (string) constant($name);
    $value = getenv($name);
    return is_string($value) ? $value : '';
}

function activation_code(): string {
    return (string) random_int(100000, 999999);
}

function device_token(): string {
    return rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
}

function venue_code(string $name): string {
    $base = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name));
    $base = substr($base ?: 'BAR', 0, 8);
    return $base . random_int(100, 999);
}

function unique_venue_code(string $name): string {
    $pdo = db();
    do {
        $code = venue_code($name);
        $stmt = $pdo->prepare("SELECT id FROM venues WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
    } while ($stmt->fetch());
    return $code;
}

function ensure_onboarding_columns(): void {
    $pdo = db();
    $columns = [
        'ownerDocument' => "ADD COLUMN ownerDocument varchar(32)",
        'ownerPhone' => "ADD COLUMN ownerPhone varchar(32)",
        'pixKeyType' => "ADD COLUMN pixKeyType varchar(32)",
        'pixKey' => "ADD COLUMN pixKey varchar(180)",
        'splitBarPercent' => "ADD COLUMN splitBarPercent int NOT NULL DEFAULT 70",
        'splitPlatformPercent' => "ADD COLUMN splitPlatformPercent int NOT NULL DEFAULT 30",
        'splitAcceptedAt' => "ADD COLUMN splitAcceptedAt timestamp NULL",
        'termsAcceptedAt' => "ADD COLUMN termsAcceptedAt timestamp NULL",
        'mercadoPagoUserId' => "ADD COLUMN mercadoPagoUserId varchar(64)",
        'mercadoPagoAccessToken' => "ADD COLUMN mercadoPagoAccessToken text",
        'mercadoPagoRefreshToken' => "ADD COLUMN mercadoPagoRefreshToken text",
        'mercadoPagoPublicKey' => "ADD COLUMN mercadoPagoPublicKey text",
        'mercadoPagoTokenExpiresAt' => "ADD COLUMN mercadoPagoTokenExpiresAt timestamp NULL",
    ];
    $stmt = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venues'");
    $existing = array_column($stmt->fetchAll(), 'COLUMN_NAME');
    foreach ($columns as $name => $sql) {
        if (!in_array($name, $existing, true)) {
            $pdo->exec("ALTER TABLE venues {$sql}");
        }
    }
}

function ensure_default_table(int $venueId): array {
    $pdo = db();
    $select = $pdo->prepare("SELECT * FROM venueTables WHERE venueId = ? AND label = 'Mesa 01' LIMIT 1");
    $select->execute([$venueId]);
    $table = $select->fetch();
    if ($table) return $table;

    $token = substr(strtolower(bin2hex(random_bytes(8))), 0, 14);
    $insert = $pdo->prepare("INSERT INTO venueTables (venueId, label, qrToken) VALUES (?, 'Mesa 01', ?)");
    $insert->execute([$venueId, $token]);

    $select->execute([$venueId]);
    return $select->fetch();
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET' && $path === '/mp/oauth/start') {
        $venue = preg_replace('/[^A-Za-z0-9]+/', '', (string) ($_GET['venue'] ?? ''));
        $clientId = config_value('MERCADOPAGO_CLIENT_ID');
        if ($venue === '') json_response(['message' => 'Venue is required'], 400);
        if ($clientId === '') {
            header('Content-Type: text/html; charset=utf-8');
            echo "<!doctype html><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><body style=\"font-family:system-ui;background:#050505;color:white;padding:32px\"><h1>TocaRaul</h1><p>Para conectar o Mercado Pago, configure primeiro o Client ID e Client Secret da aplicacao no servidor.</p><p>URL de retorno: <strong>" . htmlspecialchars(public_base_url() . "/mp/oauth/callback", ENT_QUOTES, 'UTF-8') . "</strong></p></body>";
            exit;
        }
        $redirectUri = public_base_url() . '/mp/oauth/callback';
        $state = base64_encode(json_encode(['venue' => $venue, 'nonce' => bin2hex(random_bytes(8))]));
        $url = 'https://auth.mercadopago.com.br/authorization?client_id=' . rawurlencode($clientId)
            . '&response_type=code&platform_id=mp&redirect_uri=' . rawurlencode($redirectUri)
            . '&state=' . rawurlencode($state);
        header('Location: ' . $url, true, 302);
        exit;
    }

    if ($method === 'GET' && $path === '/mp/oauth/callback') {
        $code = (string) ($_GET['code'] ?? '');
        $stateRaw = (string) ($_GET['state'] ?? '');
        $state = json_decode(base64_decode($stateRaw, true) ?: '{}', true);
        $venueCode = is_array($state) ? preg_replace('/[^A-Za-z0-9]+/', '', (string) ($state['venue'] ?? '')) : '';
        $clientId = config_value('MERCADOPAGO_CLIENT_ID');
        $clientSecret = config_value('MERCADOPAGO_CLIENT_SECRET');
        if ($code === '' || $venueCode === '' || $clientId === '' || $clientSecret === '') {
            json_response(['message' => 'Mercado Pago OAuth callback incompleto'], 400);
        }

        $redirectUri = public_base_url() . '/mp/oauth/callback';
        $postData = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => $postData,
                'ignore_errors' => true,
            ],
        ]);
        $response = file_get_contents('https://api.mercadopago.com/oauth/token', false, $context);
        $data = json_decode($response ?: '{}', true);
        if (!is_array($data) || empty($data['access_token'])) {
            json_response(['message' => 'Nao foi possivel concluir OAuth Mercado Pago'], 502);
        }

        ensure_onboarding_columns();
        $expiresAt = (new DateTimeImmutable('+' . (int) ($data['expires_in'] ?? 15552000) . ' seconds'))->format('Y-m-d H:i:s');
        $stmt = db()->prepare("UPDATE venues SET mercadoPagoUserId = ?, mercadoPagoAccessToken = ?, mercadoPagoRefreshToken = ?, mercadoPagoPublicKey = ?, mercadoPagoTokenExpiresAt = ? WHERE code = ?");
        $stmt->execute([
            (string) ($data['user_id'] ?? ''),
            (string) $data['access_token'],
            (string) ($data['refresh_token'] ?? ''),
            (string) ($data['public_key'] ?? ''),
            $expiresAt,
            $venueCode,
        ]);

        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><body style=\"font-family:system-ui;background:#050505;color:white;padding:32px\"><h1>TocaRaul</h1><p style=\"color:#7CFF9B;font-weight:800\">Mercado Pago conectado com sucesso.</p><p>O bar ja pode receber pedidos pagos com split configuravel.</p><a style=\"display:inline-block;background:#ffcc00;color:#050505;border-radius:14px;padding:14px 18px;text-decoration:none;font-weight:900\" href=\"" . htmlspecialchars(public_base_url() . "/mesas?venue=" . $venueCode, ENT_QUOTES, 'UTF-8') . "\">Abrir QR Codes das mesas</a></body>";
        exit;
    }

    if ($method === 'POST' && $path === '/api/device/session') {
        $payload = body();
        $code = activation_code();
        $token = device_token();
        $name = isset($payload['name']) && is_string($payload['name']) ? $payload['name'] : 'TV Principal';
        $expiresAt = (new DateTimeImmutable('+15 minutes'))->format('Y-m-d H:i:s');
        $stmt = db()->prepare("INSERT INTO devices (name, activationCode, activationCodeExpiresAt, deviceToken, status) VALUES (?, ?, ?, ?, 'PENDING_ACTIVATION')");
        $stmt->execute([$name, $code, $expiresAt, $token]);

        json_response([
            'deviceId' => (int) db()->lastInsertId(),
            'deviceToken' => $token,
            'activationCode' => $code,
            'activationUrl' => public_base_url() . '/activate-tv?code=' . $code,
            'expiresAt' => $expiresAt,
        ]);
    }

    if ($method === 'POST' && $path === '/api/onboarding/activate-tv') {
        $payload = body();
        $code = preg_replace('/\D+/', '', (string) ($payload['activationCode'] ?? ''));
        $ownerName = trim((string) ($payload['ownerName'] ?? ''));
        $barName = trim((string) ($payload['barName'] ?? ''));
        $phone = preg_replace('/\D+/', '', (string) ($payload['phone'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $document = preg_replace('/\D+/', '', (string) ($payload['document'] ?? ''));
        $acceptedTerms = (bool) ($payload['acceptedTerms'] ?? false);
        $tvName = trim((string) ($payload['tvName'] ?? 'TV Principal'));

        if ($code === '' || $ownerName === '' || $barName === '' || $phone === '' || $document === '') {
            json_response(['message' => 'Preencha cadastro, documento e telefone'], 400);
        }
        if (!$acceptedTerms) {
            json_response(['message' => 'Aceite os termos de negocio e responsabilidade'], 400);
        }

        $pdo = db();
        ensure_onboarding_columns();
        $deviceStmt = $pdo->prepare("SELECT * FROM devices WHERE activationCode = ? LIMIT 1");
        $deviceStmt->execute([$code]);
        $device = $deviceStmt->fetch();
        if (!$device || $device['status'] !== 'PENDING_ACTIVATION') json_response(['message' => 'Codigo da TV nao encontrado'], 404);
        if (strtotime($device['activationCodeExpiresAt']) < time()) json_response(['message' => 'Codigo da TV expirado. Reinicie o app na TV.'], 410);

        $openId = 'phone-' . hash('sha256', $phone);
        $userStmt = $pdo->prepare("SELECT * FROM users WHERE openId = ? LIMIT 1");
        $userStmt->execute([$openId]);
        $user = $userStmt->fetch();
        if (!$user) {
            $insertUser = $pdo->prepare("INSERT INTO users (openId, name, email, loginMethod, role) VALUES (?, ?, ?, 'phone', 'admin')");
            $insertUser->execute([$openId, $ownerName, $email !== '' ? $email : null]);
            $userId = (int) $pdo->lastInsertId();
        } else {
            $userId = (int) $user['id'];
            $updateUser = $pdo->prepare("UPDATE users SET name = ?, email = ?, lastSignedIn = NOW() WHERE id = ?");
            $updateUser->execute([$ownerName, $email !== '' ? $email : ($user['email'] ?? null), $userId]);
        }

        $venueCode = unique_venue_code($barName);
        $insertVenue = $pdo->prepare("INSERT INTO venues (ownerId, code, name, musicPriceCents, dedicationPriceCents, splitBarPercent, splitPlatformPercent, ownerDocument, ownerPhone, splitAcceptedAt, termsAcceptedAt) VALUES (?, ?, ?, 300, 200, 70, 30, ?, ?, NOW(), NOW())");
        $insertVenue->execute([$userId, $venueCode, $barName, $document, $phone]);
        $venueId = (int) $pdo->lastInsertId();

        $updateDevice = $pdo->prepare("UPDATE devices SET venueId = ?, name = ?, status = 'ONLINE', lastSeenAt = NOW() WHERE id = ?");
        $updateDevice->execute([$venueId, $tvName !== '' ? $tvName : 'TV Principal', $device['id']]);
        $table = ensure_default_table($venueId);

        json_response([
            'ok' => true,
            'message' => 'TV vinculada com sucesso',
            'deviceToken' => $device['deviceToken'],
            'venue' => ['id' => $venueId, 'name' => $barName, 'code' => $venueCode],
            'tableUrl' => public_base_url() . '/j/' . $table['qrToken'],
            'tablesPrintUrl' => public_base_url() . '/mesas?venue=' . $venueCode,
            'mercadoPagoStatus' => 'PENDING_OAUTH',
        ]);
    }

    if ($method === 'POST' && $path === '/api/device/activate') {
        $payload = body();
        $code = preg_replace('/\D+/', '', (string) ($payload['activationCode'] ?? ''));
        $venueId = (int) ($payload['venueId'] ?? 0);
        if ($code === '' || $venueId <= 0) json_response(['message' => 'activationCode and venueId are required'], 400);

        $deviceStmt = db()->prepare("SELECT * FROM devices WHERE activationCode = ? LIMIT 1");
        $deviceStmt->execute([$code]);
        $device = $deviceStmt->fetch();
        if (!$device || $device['status'] !== 'PENDING_ACTIVATION') json_response(['message' => 'Activation code not found'], 404);
        if (strtotime($device['activationCodeExpiresAt']) < time()) json_response(['message' => 'Activation code expired'], 410);

        $venueStmt = db()->prepare("SELECT * FROM venues WHERE id = ? LIMIT 1");
        $venueStmt->execute([$venueId]);
        $venue = $venueStmt->fetch();
        if (!$venue) json_response(['message' => 'Venue not found'], 404);

        $name = isset($payload['name']) && is_string($payload['name']) ? $payload['name'] : 'TV Principal';
        $update = db()->prepare("UPDATE devices SET venueId = ?, name = ?, status = 'ONLINE', lastSeenAt = NOW() WHERE id = ?");
        $update->execute([$venueId, $name, $device['id']]);
        ensure_default_table($venueId);

        json_response([
            'deviceToken' => $device['deviceToken'],
            'venue' => ['id' => (int) $venue['id'], 'name' => $venue['name'], 'code' => $venue['code']],
        ]);
    }

    if ($method === 'POST' && $path === '/api/device/heartbeat') {
        $token = bearer_token();
        if ($token === '') json_response(['message' => 'Device token is required'], 401);
        $stmt = db()->prepare("UPDATE devices SET status = 'ONLINE', lastSeenAt = NOW() WHERE deviceToken = ? AND status <> 'REVOKED'");
        $stmt->execute([$token]);
        if ($stmt->rowCount() < 1) json_response(['message' => 'Invalid device token'], 401);
        json_response(['ok' => true, 'status' => 'ONLINE']);
    }

    if ($method === 'GET' && $path === '/api/device/state') {
        $token = bearer_token();
        if ($token === '') json_response(['message' => 'Device token is required'], 401);
        $deviceStmt = db()->prepare("SELECT * FROM devices WHERE deviceToken = ? AND status <> 'REVOKED' LIMIT 1");
        $deviceStmt->execute([$token]);
        $device = $deviceStmt->fetch();
        if (!$device) json_response(['message' => 'Invalid device token'], 401);
        if (!$device['venueId']) json_response(['connection' => 'WAITING_ACTIVATION']);

        $venueStmt = db()->prepare("SELECT * FROM venues WHERE id = ? LIMIT 1");
        $venueStmt->execute([(int) $device['venueId']]);
        $venue = $venueStmt->fetch();
        $table = ensure_default_table((int) $device['venueId']);

        $queueStmt = db()->prepare("SELECT * FROM songRequests WHERE venueId = ? AND status IN ('QUEUED','PLAYING') ORDER BY queuePosition ASC, createdAt ASC");
        $queueStmt->execute([(int) $device['venueId']]);
        $queue = $queueStmt->fetchAll();
        $nowPlaying = null;
        foreach ($queue as $item) {
            if ($item['status'] === 'PLAYING') {
                $nowPlaying = $item;
                break;
            }
        }

        json_response([
            'connection' => 'ONLINE',
            'venue' => $venue ? ['id' => (int) $venue['id'], 'name' => $venue['name'], 'code' => $venue['code']] : null,
            'nowPlaying' => $nowPlaying ? [
                'id' => (string) $nowPlaying['id'],
                'providerId' => $nowPlaying['providerId'],
                'title' => $nowPlaying['title'],
                'artist' => $nowPlaying['artist'],
                'message' => $nowPlaying['message'],
                'tableCode' => $nowPlaying['tableCode'],
            ] : null,
            'queueSize' => count(array_filter($queue, fn ($item) => $item['status'] === 'QUEUED')),
            'qrCodeUrl' => public_base_url() . '/j/' . $table['qrToken'],
            'playbackState' => $nowPlaying ? 'PLAYING' : 'IDLE',
        ]);
    }

    if ($method === 'GET' && $path === '/mesas') {
        header('Content-Type: text/html; charset=utf-8');
        $venueCode = htmlspecialchars((string) ($_GET['venue'] ?? ''), ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>QR Codes das mesas - TocaRaul</title>
  <style>
    body{font-family:system-ui;margin:0;padding:24px;background:#eee}.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}.card{height:360px;background:white;border:2px dashed #111;border-radius:24px;display:grid;place-items:center;text-align:center;padding:18px}.qr{width:180px;height:180px;background:#f4f4f4;border-radius:12px;display:grid;place-items:center;color:#111;font-weight:900}h1{margin-top:0}@media print{button{display:none}body{background:white}.card{break-inside:avoid}}
  </style>
</head>
<body>
  <button onclick="window.print()">Salvar/imprimir PDF</button>
  <h1>TocaRaul - QR Codes das mesas</h1>
  <p>Bar: {$venueCode}. Use "Salvar como PDF" na janela de impressao e cole um QR em cada mesa.</p>
  <div class="grid">
    <div class="card"><div><h2>Mesa 01</h2><div class="qr">QR</div><p>Escaneie para pedir musica</p></div></div>
    <div class="card"><div><h2>Mesa 02</h2><div class="qr">QR</div><p>Escaneie para pedir musica</p></div></div>
    <div class="card"><div><h2>Mesa 03</h2><div class="qr">QR</div><p>Escaneie para pedir musica</p></div></div>
    <div class="card"><div><h2>Mesa 04</h2><div class="qr">QR</div><p>Escaneie para pedir musica</p></div></div>
  </div>
</body>
</html>
HTML;
        exit;
    }

    if ($method === 'GET' && $path === '/activate-tv') {
        header('Content-Type: text/html; charset=utf-8');
        $code = htmlspecialchars((string) ($_GET['code'] ?? ''), ENT_QUOTES, 'UTF-8');
        echo <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Ativar TV - TocaRaul</title>
  <style>
    *{box-sizing:border-box} body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#050505;color:white;min-height:100vh;display:grid;place-items:center;padding:18px}
    main{width:min(680px,100%);background:#111;border:1px solid #ffcc00;border-radius:28px;padding:26px;box-shadow:0 16px 70px #000}
    h1{margin:0 0 6px;font-size:32px}.muted,p{color:#d8d3c7;line-height:1.45}.code{font-size:38px;color:#ffcc00;font-weight:900;letter-spacing:3px;margin:12px 0}.box{background:#050505;border:1px solid #332900;border-radius:18px;padding:16px;margin:16px 0}
    .box strong{color:#ffcc00}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}label{display:block;margin:12px 0 6px;color:#ffcc00;font-weight:700;font-size:12px;text-transform:uppercase}input,select{width:100%;border:1px solid #333;background:#050505;color:white;border-radius:14px;padding:14px;font-size:16px}
    .terms{display:flex;gap:10px;align-items:flex-start;color:#d8d3c7;margin-top:16px}.terms input{width:auto;margin-top:4px}button{width:100%;border:0;border-radius:16px;background:#ffcc00;color:#050505;font-weight:900;font-size:18px;padding:16px;margin-top:18px}.status{margin-top:16px;color:#ffcc00;font-weight:700}.ok{color:#7CFF9B}.err{color:#ff7979}.actions a{display:block;color:#050505;background:#ffcc00;border-radius:14px;padding:14px;text-align:center;text-decoration:none;font-weight:900;margin-top:12px}
    @media(max-width:620px){.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
  <main>
    <h1>TocaRaul</h1>
    <p>Finalize o cadastro do bar, entenda o split Mercado Pago e vincule esta Android TV automaticamente.</p>
    <div class="code">{$code}</div>
    <div class="box">
      <strong>Como funciona o split Mercado Pago:</strong>
      <p>O bar autoriza a conta Mercado Pago dele e os pagamentos Pix entram pela conta do proprio bar. Em cada pedido, o TocaRaul cobra uma comissao configuravel. O padrao deste bar sera 70% para o bar e 30% para o TocaRaul, podendo ser alterado individualmente depois.</p>
      <p>As taxas do Mercado Pago sao descontadas antes/conforme as regras do provedor. Estornos, chargebacks, bloqueios e revisoes podem afetar o valor liberado.</p>
    </div>
    <form id="form">
      <input type="hidden" name="activationCode" value="{$code}">
      <div class="grid">
        <div><label>Nome do dono</label><input name="ownerName" autocomplete="name" required></div>
        <div><label>Telefone/WhatsApp</label><input name="phone" inputmode="tel" autocomplete="tel" required></div>
        <div><label>E-mail</label><input name="email" type="email" autocomplete="email"></div>
        <div><label>CPF/CNPJ do responsavel</label><input name="document" inputmode="numeric" required></div>
      </div>
      <label>Nome do bar</label><input name="barName" required>
      <div class="box">
        <strong>Mercado Pago do bar</strong>
        <p>Nesta versao, o cadastro salva o bar e ativa a TV. A conexao OAuth do Mercado Pago sera a proxima etapa: o dono do bar clicara em "Conectar Mercado Pago" e autorizara o recebimento pela propria conta.</p>
      </div>
      <label>Nome desta TV</label>
      <input name="tvName" value="TV Principal">
      <label class="terms"><input type="checkbox" name="acceptedTerms" value="1" required><span>Li e aceito os termos de negocio: sou responsavel pelos dados do bar, pela exibicao do servico no estabelecimento, pela autorizacao da conta Mercado Pago, por eventuais solicitacoes de estorno/chargeback e pela regularidade fiscal/operacional do recebimento. Entendo que o TocaRaul processara pedidos musicais pagos e cobrara comissao no padrao 30%, deixando 70% para o bar, podendo esse percentual ser ajustado por bar.</span></label>
      <button type="submit">Ativar esta TV</button>
    </form>
    <div id="status" class="status"></div>
    <div id="actions" class="actions"></div>
  </main>
  <script>
    const form = document.getElementById('form');
    const statusEl = document.getElementById('status');
    const actionsEl = document.getElementById('actions');
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      statusEl.className = 'status';
      statusEl.textContent = 'Ativando...';
      const payload = Object.fromEntries(new FormData(form).entries());
      payload.acceptedTerms = form.acceptedTerms.checked;
      try {
        const response = await fetch('/api/onboarding/activate-tv', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Nao foi possivel ativar');
        statusEl.className = 'status ok';
        statusEl.textContent = 'Pronto! A TV foi vinculada. Ela vai entrar no player automaticamente.';
        actionsEl.innerHTML = '<a href="/mp/oauth/start?venue=' + data.venue.code + '">Conectar Mercado Pago do bar</a><a href="' + data.tablesPrintUrl + '" target="_blank">Abrir PDF/impresso dos QR Codes das mesas</a><a href="' + data.tableUrl + '" target="_blank">Testar QR da Mesa 01</a>';
        form.style.display = 'none';
      } catch (error) {
        statusEl.className = 'status err';
        statusEl.textContent = error.message || 'Erro ao ativar a TV.';
      }
    });
  </script>
</body>
</html>
HTML;
        exit;
    }

    json_response(['message' => 'Not found'], 404);
} catch (Throwable $error) {
    json_response(['message' => 'Internal server error'], 500);
}

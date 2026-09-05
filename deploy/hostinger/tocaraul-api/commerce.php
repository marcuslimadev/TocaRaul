<?php
declare(strict_types=1);

require __DIR__ . '/../api_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Request-Id, X-Signature');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function db(): PDO {
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

function respond(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function payload(): array {
    $raw = file_get_contents('php://input') ?: '{}';
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function config_value(string $name): string {
    if (defined($name)) return (string) constant($name);
    $value = getenv($name);
    return is_string($value) ? $value : '';
}

function bearer_token(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    return stripos($header, 'Bearer ') === 0 ? trim(substr($header, 7)) : '';
}

function public_base_url(): string {
    return rtrim((string) PUBLIC_APP_URL, '/');
}

function require_device(): array {
    $token = bearer_token();
    if ($token === '') respond(['message' => 'Device token is required'], 401);
    $stmt = db()->prepare("SELECT * FROM devices WHERE deviceToken = ? AND status <> 'REVOKED' LIMIT 1");
    $stmt->execute([$token]);
    $device = $stmt->fetch();
    if (!$device || empty($device['venueId'])) respond(['message' => 'Activated device is required'], 401);
    return $device;
}

function mercado_pago_request(string $method, string $path, string $accessToken, ?array $body = null, ?string $idempotencyKey = null): array {
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required');
    $ch = curl_init('https://api.mercadopago.com' . $path);
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
        'Content-Type: application/json',
    ];
    if ($idempotencyKey !== null) $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $raw = curl_exec($ch);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Mercado Pago connection failed: ' . $error);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];
    if ($status < 200 || $status > 299) {
        $message = (string) ($data['message'] ?? $data['error'] ?? 'unknown error');
        throw new RuntimeException("Mercado Pago request failed ($status): $message");
    }
    return $data;
}

function webhook_signature_valid(string $dataId): bool {
    $secret = config_value('MERCADOPAGO_WEBHOOK_SECRET');
    $signature = (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? '');
    $requestId = (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
    if ($secret === '' || $signature === '') return false;

    $ts = '';
    $v1 = '';
    foreach (explode(',', $signature) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($key === 'ts') $ts = $value;
        if ($key === 'v1') $v1 = $value;
    }
    if ($ts === '' || $v1 === '') return false;

    $manifest = '';
    if ($dataId !== '') $manifest .= 'id:' . strtolower($dataId) . ';';
    if ($requestId !== '') $manifest .= 'request-id:' . $requestId . ';';
    $manifest .= 'ts:' . $ts . ';';
    $expected = hash_hmac('sha256', $manifest, $secret);
    return hash_equals($expected, $v1);
}

function payment_status(string $status): string {
    return match ($status) {
        'approved' => 'APPROVED',
        'rejected' => 'REJECTED',
        'cancelled', 'canceled' => 'CANCELLED',
        default => 'PENDING',
    };
}

function next_queue_position(PDO $pdo, int $venueId): int {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(queuePosition), 0) + 1 AS nextPosition FROM songRequests WHERE venueId = ? AND status IN ('QUEUED','PLAYING')");
    $stmt->execute([$venueId]);
    return (int) ($stmt->fetch()['nextPosition'] ?? 1);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

try {
    if ($method === 'GET' && $path === '/api/commerce/health') {
        db()->query('SELECT 1');
        respond(['ok' => true, 'service' => 'tocaraul-commerce', 'database' => 'online']);
    }

    if ($method === 'POST' && $path === '/api/commerce/request') {
        $input = payload();
        $qrToken = trim((string) ($input['qrToken'] ?? ''));
        $visitorName = trim((string) ($input['visitorName'] ?? 'Cliente'));
        $providerId = trim((string) ($input['providerId'] ?? ''));
        $title = trim((string) ($input['title'] ?? ''));
        $artist = trim((string) ($input['artist'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));
        $payerEmail = trim((string) ($input['payerEmail'] ?? 'cliente@tocaraul.app'));

        if ($qrToken === '' || $providerId === '' || $title === '' || $artist === '') {
            respond(['message' => 'qrToken, providerId, title and artist are required'], 400);
        }
        if (mb_strlen($visitorName) > 80 || mb_strlen($title) > 180 || mb_strlen($artist) > 180 || mb_strlen($message) > 180) {
            respond(['message' => 'Request fields are too long'], 400);
        }
        if (!filter_var($payerEmail, FILTER_VALIDATE_EMAIL)) $payerEmail = 'cliente@tocaraul.app';

        $pdo = db();
        $tableStmt = $pdo->prepare("SELECT vt.*, v.name AS venueName, v.musicPriceCents, v.dedicationPriceCents, v.splitBarPercent, v.splitPlatformPercent, v.mercadoPagoAccessToken FROM venueTables vt JOIN venues v ON v.id = vt.venueId WHERE vt.qrToken = ? AND vt.status = 'ACTIVE' LIMIT 1");
        $tableStmt->execute([$qrToken]);
        $table = $tableStmt->fetch();
        if (!$table) respond(['message' => 'Mesa nao encontrada ou desativada'], 404);
        if (empty($table['mercadoPagoAccessToken'])) respond(['message' => 'Este bar ainda nao conectou o Mercado Pago'], 409);

        $amountCents = (int) $table['musicPriceCents'] + ($message !== '' ? (int) $table['dedicationPriceCents'] : 0);
        if ($amountCents <= 0) respond(['message' => 'Preco invalido para este bar'], 409);

        $pdo->beginTransaction();
        try {
            $requestStmt = $pdo->prepare("INSERT INTO songRequests (venueId, visitorName, tableCode, providerId, title, artist, message, amountCents, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'AWAITING_PAYMENT')");
            $requestStmt->execute([
                (int) $table['venueId'],
                $visitorName !== '' ? $visitorName : 'Cliente',
                (string) $table['label'],
                $providerId,
                $title,
                $artist,
                $message !== '' ? $message : null,
                $amountCents,
            ]);
            $requestId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }

        $platformPercent = isset($table['splitPlatformPercent']) ? (int) $table['splitPlatformPercent'] : (100 - (int) $table['splitBarPercent']);
        $platformPercent = max(0, min(100, $platformPercent));
        $applicationFeeCents = (int) round($amountCents * $platformPercent / 100);

        try {
            $mp = mercado_pago_request('POST', '/v1/payments', (string) $table['mercadoPagoAccessToken'], [
                'transaction_amount' => $amountCents / 100,
                'description' => $title . ' - ' . $artist,
                'payment_method_id' => 'pix',
                'application_fee' => $applicationFeeCents / 100,
                'external_reference' => 'tocaraul_' . $requestId,
                'notification_url' => public_base_url() . '/api/mercadopago/webhook',
                'payer' => ['email' => $payerEmail],
                'metadata' => [
                    'tocaraul_request_id' => $requestId,
                    'tocaraul_venue_id' => (int) $table['venueId'],
                    'tocaraul_table_id' => (int) $table['id'],
                ],
            ], 'tocaraul-request-' . $requestId);
        } catch (Throwable $error) {
            $fail = $pdo->prepare("UPDATE songRequests SET status = 'FAILED' WHERE id = ? AND status = 'AWAITING_PAYMENT'");
            $fail->execute([$requestId]);
            throw $error;
        }

        $externalId = isset($mp['id']) ? (string) $mp['id'] : '';
        $pixCopyPaste = (string) ($mp['point_of_interaction']['transaction_data']['qr_code'] ?? '');
        if ($externalId === '' || $pixCopyPaste === '') {
            $fail = $pdo->prepare("UPDATE songRequests SET status = 'FAILED' WHERE id = ? AND status = 'AWAITING_PAYMENT'");
            $fail->execute([$requestId]);
            throw new RuntimeException('Mercado Pago did not return Pix data');
        }

        $payment = $pdo->prepare("INSERT INTO payments (requestId, provider, externalId, status, amountCents, pixCopyPaste) VALUES (?, 'mercadopago', ?, ?, ?, ?)");
        $payment->execute([$requestId, $externalId, payment_status((string) ($mp['status'] ?? '')), $amountCents, $pixCopyPaste]);
        $paymentId = (int) $pdo->lastInsertId();

        respond([
            'requestId' => $requestId,
            'paymentId' => $paymentId,
            'externalId' => $externalId,
            'status' => 'AWAITING_PAYMENT',
            'amountCents' => $amountCents,
            'pixCopyPaste' => $pixCopyPaste,
            'expiresAt' => $mp['date_of_expiration'] ?? null,
        ], 201);
    }

    if ($method === 'GET' && $path === '/api/commerce/payment') {
        $requestId = (int) ($_GET['requestId'] ?? 0);
        if ($requestId <= 0) respond(['message' => 'requestId is required'], 400);
        $stmt = db()->prepare("SELECT p.id AS paymentId, p.requestId, p.externalId, p.status AS paymentStatus, p.amountCents, p.pixCopyPaste, r.status AS requestStatus FROM payments p JOIN songRequests r ON r.id = p.requestId WHERE p.requestId = ? ORDER BY p.id DESC LIMIT 1");
        $stmt->execute([$requestId]);
        $payment = $stmt->fetch();
        if (!$payment) respond(['message' => 'Payment not found'], 404);
        respond($payment);
    }

    if ($method === 'POST' && $path === '/api/mercadopago/webhook') {
        $input = payload();
        $dataId = (string) ($_GET['data_id'] ?? $_GET['data.id'] ?? ($input['data']['id'] ?? ''));
        if ($dataId === '') respond(['message' => 'Payment id is required'], 400);
        if (!webhook_signature_valid($dataId)) respond(['message' => 'Invalid Mercado Pago signature'], 401);

        $pdo = db();
        $paymentStmt = $pdo->prepare("SELECT p.*, r.venueId, r.status AS requestStatus, v.mercadoPagoAccessToken FROM payments p JOIN songRequests r ON r.id = p.requestId JOIN venues v ON v.id = r.venueId WHERE p.externalId = ? LIMIT 1");
        $paymentStmt->execute([$dataId]);
        $payment = $paymentStmt->fetch();
        if (!$payment) respond(['ok' => true, 'ignored' => 'payment-not-yet-known']);
        if (empty($payment['mercadoPagoAccessToken'])) respond(['message' => 'Venue Mercado Pago token is missing'], 409);

        $mp = mercado_pago_request('GET', '/v1/payments/' . rawurlencode($dataId), (string) $payment['mercadoPagoAccessToken']);
        $status = payment_status((string) ($mp['status'] ?? ''));

        $pdo->beginTransaction();
        try {
            $lockPayment = $pdo->prepare("SELECT * FROM payments WHERE id = ? FOR UPDATE");
            $lockPayment->execute([(int) $payment['id']]);
            $currentPayment = $lockPayment->fetch();
            $lockRequest = $pdo->prepare("SELECT * FROM songRequests WHERE id = ? FOR UPDATE");
            $lockRequest->execute([(int) $payment['requestId']]);
            $request = $lockRequest->fetch();
            if (!$currentPayment || !$request) throw new RuntimeException('Payment/request disappeared during confirmation');

            if ($status === 'APPROVED') {
                if ($currentPayment['status'] !== 'APPROVED') {
                    $updatePayment = $pdo->prepare("UPDATE payments SET status = 'APPROVED' WHERE id = ?");
                    $updatePayment->execute([(int) $currentPayment['id']]);
                }
                if (in_array($request['status'], ['AWAITING_PAYMENT', 'PAID'], true)) {
                    $queuePosition = next_queue_position($pdo, (int) $request['venueId']);
                    $updateRequest = $pdo->prepare("UPDATE songRequests SET status = 'QUEUED', queuePosition = ? WHERE id = ?");
                    $updateRequest->execute([$queuePosition, (int) $request['id']]);
                }
            } elseif ($status === 'REJECTED') {
                $pdo->prepare("UPDATE payments SET status = 'REJECTED' WHERE id = ?")->execute([(int) $currentPayment['id']]);
                if ($request['status'] === 'AWAITING_PAYMENT') $pdo->prepare("UPDATE songRequests SET status = 'FAILED' WHERE id = ?")->execute([(int) $request['id']]);
            } elseif ($status === 'CANCELLED') {
                $pdo->prepare("UPDATE payments SET status = 'CANCELLED' WHERE id = ?")->execute([(int) $currentPayment['id']]);
                if ($request['status'] === 'AWAITING_PAYMENT') $pdo->prepare("UPDATE songRequests SET status = 'CANCELLED' WHERE id = ?")->execute([(int) $request['id']]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }

        respond(['ok' => true, 'paymentId' => $dataId, 'status' => $status]);
    }

    if ($method === 'POST' && $path === '/api/player/claim') {
        $device = require_device();
        $venueId = (int) $device['venueId'];
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $playingStmt = $pdo->prepare("SELECT * FROM songRequests WHERE venueId = ? AND status = 'PLAYING' ORDER BY updatedAt ASC LIMIT 1 FOR UPDATE");
            $playingStmt->execute([$venueId]);
            $track = $playingStmt->fetch();
            if (!$track) {
                $queuedStmt = $pdo->prepare("SELECT * FROM songRequests WHERE venueId = ? AND status = 'QUEUED' ORDER BY queuePosition ASC, createdAt ASC LIMIT 1 FOR UPDATE");
                $queuedStmt->execute([$venueId]);
                $track = $queuedStmt->fetch();
                if ($track) {
                    $pdo->prepare("UPDATE songRequests SET status = 'PLAYING' WHERE id = ? AND status = 'QUEUED'")->execute([(int) $track['id']]);
                    $track['status'] = 'PLAYING';
                }
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        if (!$track) respond(['track' => null]);
        respond(['track' => [
            'id' => (string) $track['id'],
            'providerId' => $track['providerId'],
            'title' => $track['title'],
            'artist' => $track['artist'],
            'message' => $track['message'],
            'tableCode' => $track['tableCode'],
        ]]);
    }

    if ($method === 'POST' && $path === '/api/player/complete') {
        $device = require_device();
        $input = payload();
        $requestId = (int) ($input['requestId'] ?? 0);
        $result = strtoupper(trim((string) ($input['result'] ?? 'PLAYED')));
        if ($requestId <= 0 || !in_array($result, ['PLAYED', 'SKIPPED'], true)) respond(['message' => 'Invalid player completion payload'], 400);
        $stmt = db()->prepare("UPDATE songRequests SET status = ? WHERE id = ? AND venueId = ? AND status = 'PLAYING'");
        $stmt->execute([$result, $requestId, (int) $device['venueId']]);
        if ($stmt->rowCount() < 1) respond(['message' => 'Playing request not found'], 409);
        respond(['ok' => true, 'requestId' => $requestId, 'status' => $result]);
    }

    respond(['message' => 'Not found'], 404);
} catch (Throwable $error) {
    error_log('[TocaRaul commerce] ' . $error->getMessage());
    respond(['message' => 'Internal server error'], 500);
}

<?php
declare(strict_types=1);
require_once __DIR__ . '/admin_settings.php';
require_once __DIR__ . '/onboarding.php';

// This flow is for bar/restaurant owners only. Admin and partner sessions are untouched.
session_name('tocaraul_bar');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);
session_start();

function google_config(string $name): string { return defined($name) ? (string)constant($name) : (string)(getenv($name) ?: ''); }
function google_redirect_uri(): string { return rtrim(google_config('PUBLIC_APP_URL'), '/') . '/api/oauth/google/callback'; }
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (in_array($path, ['/auth/google/start','/api/oauth/google/start'], true)) {
    $client = google_config('GOOGLE_CLIENT_ID');
    if ($client === '') { http_response_code(503); exit('Google nao configurado.'); }
    $state = bin2hex(random_bytes(32));
    $_SESSION['google_state'] = $state;
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'=>$client, 'redirect_uri'=>google_redirect_uri(), 'response_type'=>'code',
        'scope'=>'openid email profile', 'state'=>$state, 'nonce'=>$state, 'prompt'=>'select_account'
    ]), true, 302);
    exit;
}

if (in_array($path, ['/auth/google/callback','/api/oauth/google/callback'], true)) {
    $state = (string)($_GET['state'] ?? '');
    $code = (string)($_GET['code'] ?? '');
    if ($state === '' || !hash_equals((string)($_SESSION['google_state'] ?? ''), $state) || $code === '') {
        http_response_code(403); exit('Estado OAuth invalido.');
    }
    unset($_SESSION['google_state']);
    $client = google_config('GOOGLE_CLIENT_ID');
    $secret = google_config('GOOGLE_CLIENT_SECRET');
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query(['code'=>$code,'client_id'=>$client,'client_secret'=>$secret,'redirect_uri'=>google_redirect_uri(),'grant_type'=>'authorization_code']),
        CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'], CURLOPT_TIMEOUT=>15]);
    $raw = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $curlError = curl_error($ch); curl_close($ch);
    $tokens = json_decode(is_string($raw) ? $raw : '{}', true);
    $idToken = (string)($tokens['id_token'] ?? '');
    if ($status < 200 || $status >= 300 || $idToken === '') {
        error_log('[TocaRaul OAuth] token exchange failed status='.$status.' error='.((string)($tokens['error'] ?? 'unknown')).' curl='.($curlError !== '' ? 'yes' : 'no'));
        http_response_code(401); exit('Falha ao autenticar com Google.');
    }
    $info = json_decode((string)file_get_contents('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken)), true);
    $email = strtolower(trim((string)($info['email'] ?? '')));
    if ((string)($info['aud'] ?? '') !== $client || !filter_var($info['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN) || $email === '') {
        http_response_code(403); exit('Conta Google invalida.');
    }
    $query = settings_db()->prepare('SELECT v.id FROM venues v JOIN users u ON u.id=v.ownerId WHERE LOWER(u.email)=? LIMIT 1');
    $query->execute([$email]); $venue = $query->fetch();
    if (!$venue) { http_response_code(403); exit('Esta conta Google ainda nao esta vinculada a um bar.'); }
    session_regenerate_id(true); $_SESSION['venue'] = (int)$venue['id']; header('Location: /bar', true, 302); exit;
}
http_response_code(404); exit('Not found');

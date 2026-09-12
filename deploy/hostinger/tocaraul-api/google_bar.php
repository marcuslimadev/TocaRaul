<?php
declare(strict_types=1);
require_once __DIR__ . '/admin_settings.php';
require_once __DIR__ . '/onboarding.php';

// This flow is for bar/restaurant owners only. Admin and partner sessions are untouched.
session_set_cookie_params(['lifetime'=>600,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);
open_session('tocaraul_google');

function google_config(string $name): string { return defined($name) ? (string)constant($name) : (string)(getenv($name) ?: ''); }
function google_redirect_uri(): string { return rtrim(google_config('PUBLIC_APP_URL'), '/') . '/api/oauth/google/callback'; }
function google_b64url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (in_array($path, ['/auth/google/start','/api/oauth/google/start'], true)) {
    $client = google_config('GOOGLE_CLIENT_ID');
    if ($client === '') { http_response_code(503); exit('Google nao configurado.'); }
    $state = bin2hex(random_bytes(32));
    $verifier = google_b64url(random_bytes(32));
    $_SESSION['google_state'] = $state;
    $_SESSION['google_code_verifier'] = $verifier;
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id'=>$client, 'redirect_uri'=>google_redirect_uri(), 'response_type'=>'code',
        'scope'=>'openid email profile', 'state'=>$state, 'nonce'=>$state, 'code_challenge'=>google_b64url(hash('sha256', $verifier, true)), 'code_challenge_method'=>'S256', 'prompt'=>'select_account'
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
    $verifier = (string)($_SESSION['google_code_verifier'] ?? '');
    unset($_SESSION['google_code_verifier']);
    $payload = ['code'=>$code,'client_id'=>$client,'redirect_uri'=>google_redirect_uri(),'grant_type'=>'authorization_code','code_verifier'=>$verifier];
    if ($secret !== '' && !str_starts_with(strtolower($secret), 'seu_')) $payload['client_secret'] = $secret;
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query($payload),
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
    if (!$venue) {
        // New owners continue directly into the bar + Pix onboarding form.
        $googleEmail = $email;
        $googleName = trim((string)($info['name'] ?? ''));
        $_SESSION['google_email'] = $googleEmail;
        $_SESSION['google_name'] = $googleName;
        session_write_close();
        header('Location: /cadastro', true, 302);
        exit;
    }
    // Hand the login over to the bar session itself, otherwise /bar would ask for a password again.
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);
    open_session('tocaraul_bar');
    session_regenerate_id(true);
    $_SESSION['venue'] = (int)$venue['id'];
    session_write_close();
    header('Location: /bar', true, 302);
    exit;
}
http_response_code(404); exit('Not found');

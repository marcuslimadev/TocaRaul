<?php
declare(strict_types=1);
require_once __DIR__ . '/admin_settings.php';
require_once __DIR__ . '/onboarding.php';

// This flow is for bar/restaurant owners only. Admin and partner sessions are untouched.
ini_set('session.use_strict_mode','1');
// 10 minutos era pouco: quem para para escolher a conta no Google voltava sem estado.
session_set_cookie_params(['lifetime'=>1800,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);
open_session('tocaraul_google');

function google_config(string $name): string { return defined($name) ? (string)constant($name) : (string)(getenv($name) ?: ''); }
function google_redirect_uri(): string { return rtrim(google_config('PUBLIC_APP_URL'), '/') . '/api/oauth/google/callback'; }
function google_b64url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }

/**
 * Nenhum erro deste fluxo pode virar uma pagina morta: o dono precisa de um
 * caminho de volta. Voltar pelo botao do navegador, recarregar a pagina de
 * retorno ou demorar para escolher a conta caem todos aqui.
 */
function oauth_stop(int $status, string $title, string $body, bool $retry): never {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $h = fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang=pt-BR><head><meta charset=utf-8><meta name=viewport content="width=device-width,initial-scale=1">'
       . '<title>' . $h($title) . ' · TocaRaul</title><link rel=stylesheet href="/assets/bauhaus.css"></head>'
       . '<body class=bh-panel><div class=w><div class=logo><i></i><b>TocaRaul</b></div>'
       . '<div class="c accent"><h2>' . $h($title) . '</h2><p>' . $body . '</p><p class=row>'
       . ($retry ? '<a class=bauhaus-btn href="/auth/google/start">Tentar de novo com Google</a>' : '')
       . '<a class="bauhaus-btn alt" href="/bar">Entrar com código e senha</a>'
       . '</p></div></div></body></html>';
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (in_array($path, ['/auth/google/start','/api/oauth/google/start'], true)) {
    $client = google_config('GOOGLE_CLIENT_ID');
    if ($client === '') oauth_stop(503, 'Login com Google indisponível',
        'O login com Google ainda não está configurado neste servidor. Use o código do bar e a senha por enquanto.', false);
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
        // Chegar aqui nao e ataque na maioria das vezes: e o botao Voltar, um F5 nesta
        // pagina ou meia hora parado na tela do Google. O estado so vale uma vez.
        oauth_stop(403, 'A janela do login expirou',
            'Esse retorno do Google já foi usado ou passou do tempo. Isso acontece ao voltar com o botão do navegador ou ao recarregar esta página. '
          . 'Comece o login de novo — se repetir, confira se o navegador aceita cookies deste site.', true);
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
        $reason = (string)($tokens['error'] ?? 'unknown');
        error_log('[TocaRaul OAuth] token exchange failed status='.$status.' error='.$reason.' curl='.($curlError !== '' ? 'yes' : 'no'));
        $credentials = in_array($reason, ['invalid_client','unauthorized_client'], true);
        oauth_stop(401, 'Não consegui concluir o login',
            $credentials
              ? 'As credenciais do Google configuradas neste servidor não foram aceitas. Isso é com a gente, não com a sua conta — entre com o código do bar e a senha enquanto resolvemos.'
              : 'O Google recusou esta tentativa. Tente de novo; se continuar, entre com o código do bar e a senha.',
            !$credentials);
    }
    $info = json_decode((string)file_get_contents('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken)), true);
    $email = strtolower(trim((string)($info['email'] ?? '')));
    if ((string)($info['aud'] ?? '') !== $client || !filter_var($info['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN) || $email === '') {
        oauth_stop(403, 'Conta Google não confirmada',
            'Essa conta não tem e-mail verificado no Google. Escolha outra conta ou entre com o código do bar e a senha.', true);
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


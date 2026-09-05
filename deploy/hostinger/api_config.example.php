<?php
// Copy this file to api_config.php on the server. NEVER commit api_config.php.

define('DB_HOST', 'localhost');
define('DB_NAME', 'tocaraul');
define('DB_USER', 'tocaraul_user');
define('DB_PASS', 'CHANGE_ME');

// Public HTTPS origin where deploy/hostinger/tocaraul-api is served.
define('PUBLIC_APP_URL', 'https://SEU-DOMINIO-AQUI');

// Mercado Pago application credentials (platform/TocaRaul app).
define('MERCADOPAGO_CLIENT_ID', 'CHANGE_ME');
define('MERCADOPAGO_CLIENT_SECRET', 'CHANGE_ME');

// Secret configured for the Mercado Pago Webhooks notification endpoint.
define('MERCADOPAGO_WEBHOOK_SECRET', 'CHANGE_ME');

// Do not place OAuth access tokens for individual bars here.
// They are obtained by /mp/oauth/callback and stored per venue in MySQL.

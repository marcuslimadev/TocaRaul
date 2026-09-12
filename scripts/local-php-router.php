<?php
// Development only: never upload this router to public hosting.
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$root=__DIR__.'/../deploy/hostinger/tocaraul-api';
if(preg_match('~^/assets/((?:logos/)?[A-Za-z0-9._-]+)$~',$path,$m)&&is_file($root.'/assets/'.$m[1])){
 $types=['js'=>'application/javascript','png'=>'image/png','svg'=>'image/svg+xml','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','json'=>'application/json','webmanifest'=>'application/manifest+json'];
 $ext=strtolower(pathinfo($m[1],PATHINFO_EXTENSION));
 header('Content-Type: '.($types[$ext]??'application/octet-stream'));
 readfile($root.'/assets/'.$m[1]);
 return;
}
if($path==='/favicon.ico'){header('Content-Type: image/png');readfile($root.'/assets/icon-192.png');return;}
if($path==='/'||$path==='')require $root.'/home.php';
elseif($path==='/admin')require $root.'/admin.php';
elseif($path==='/bar')require $root.'/owner.php';
elseif($path==='/player'||$path==='/tela'){header('Location: /bar',true,302);return;}
elseif($path==='/tv'){header('Location: /bar',true,302);return;}
elseif($path==='/privacy')require $root.'/privacy.php';
elseif($path==='/parceiro')require $root.'/partner.php';
elseif($path==='/cadastro')require $root.'/signup.php';
elseif(preg_match('~^/(api/oauth|auth)/google/(start|callback)$~',$path))require $root.'/google_bar.php';
elseif($path==='/sw.js'){header('Content-Type: application/javascript');readfile($root.'/sw.js');}
elseif($path==='/manifest.webmanifest')require $root.'/manifest.php';
elseif(str_starts_with($path,'/j/'))require $root.'/customer.php';
elseif(str_starts_with($path,'/api/commerce/')||str_starts_with($path,'/api/player/')||$path==='/api/webhooks/asaas')require $root.'/commerce.php';
else require $root.'/index.php';

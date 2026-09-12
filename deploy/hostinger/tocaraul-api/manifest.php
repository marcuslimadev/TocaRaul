<?php
declare(strict_types=1);
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
 'name'=>'TocaRaul',
 'short_name'=>'TocaRaul',
 'description'=>'Fila de músicas, pedidos e dedicatórias na tela do seu estabelecimento.',
 'lang'=>'pt-BR',
 'start_url'=>'/player',
 'scope'=>'/',
 'display'=>'standalone',
 'orientation'=>'landscape',
 'background_color'=>'#090909',
 'theme_color'=>'#090909',
 'icons'=>[
  ['src'=>'/assets/icon-192.png','sizes'=>'192x192','type'=>'image/png','purpose'=>'any maskable'],
  ['src'=>'/assets/icon-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'any maskable'],
 ],
 'shortcuts'=>[
  ['name'=>'Painel do bar','url'=>'/bar'],
 ],
],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

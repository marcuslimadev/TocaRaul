<?php
declare(strict_types=1);
require_once __DIR__.'/admin_settings.php';

function moderation_schema():void{
 $d=settings_db();
 $d->exec("CREATE TABLE IF NOT EXISTS venueBlockedWords(id int AUTO_INCREMENT PRIMARY KEY,venueId int NOT NULL,word varchar(60) NOT NULL,createdAt timestamp DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uniq_venue_word(venueId,word)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $d->exec("CREATE TABLE IF NOT EXISTS venueBlockedSongs(id int AUTO_INCREMENT PRIMARY KEY,venueId int NOT NULL,providerId varchar(255) NOT NULL,title varchar(255) NULL,artist varchar(255) NULL,createdAt timestamp DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uniq_venue_song(venueId,providerId)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Lowercase and strip accents so "Ação" and "acao" compare equal.
 * Uses an explicit map because iconv//TRANSLIT differs per platform
 * (Windows turned "jacaré" into "jacar'e", Linux into "jacare").
 */
function moderation_normalize(string $text):string{
 $map=['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
  'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
  'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','ý'=>'y','ÿ'=>'y'];
 return strtr(mb_strtolower(trim($text),'UTF-8'),$map);
}

function venue_blocked_words(int $venueId):array{
 moderation_schema();
 $q=settings_db()->prepare('SELECT id,word FROM venueBlockedWords WHERE venueId=? ORDER BY word');
 $q->execute([$venueId]);return $q->fetchAll();
}

function venue_blocked_songs(int $venueId):array{
 moderation_schema();
 $q=settings_db()->prepare('SELECT id,providerId,title,artist FROM venueBlockedSongs WHERE venueId=? ORDER BY id DESC');
 $q->execute([$venueId]);return $q->fetchAll();
}

/** True when the text contains any word this venue banned (whole word, accent/case insensitive). */
function venue_blocks_text(int $venueId,string $text):bool{
 if(trim($text)==='')return false;
 $haystack=moderation_normalize($text);
 foreach(venue_blocked_words($venueId) as $row){
  $needle=moderation_normalize((string)$row['word']);
  if($needle==='')continue;
  if(preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle,'/').'(?![\p{L}\p{N}])/u',$haystack))return true;
 }
 return false;
}

function venue_blocks_song(int $venueId,string $providerId):bool{
 moderation_schema();
 $q=settings_db()->prepare('SELECT id FROM venueBlockedSongs WHERE venueId=? AND providerId=? LIMIT 1');
 $q->execute([$venueId,$providerId]);
 return (bool)$q->fetch();
}

function venue_blocked_song_ids(int $venueId):array{
 return array_column(venue_blocked_songs($venueId),'providerId');
}

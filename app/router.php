<?php
declare(strict_types=1);
// Local root-folder acceptance tests. Apache uses .htaccess instead.
if(PHP_SAPI!=='cli-server'){http_response_code(404);exit;}
$uri=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/');
if($uri==='/'||$uri==='/index.php'){require __DIR__.'/index.php';return;}
if($uri==='/setup.php'){require __DIR__.'/setup.php';return;}
if($uri==='/api.php'){require __DIR__.'/api.php';return;}
if(str_starts_with($uri,'/assets/')&&!str_contains($uri,"\0")){
 $root=realpath(__DIR__.'/public/assets');$path=realpath(__DIR__.'/public'.$uri);$ext=strtolower(pathinfo($path?:'',PATHINFO_EXTENSION));$types=['css'=>'text/css; charset=utf-8','js'=>'text/javascript; charset=utf-8','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','svg'=>'image/svg+xml','webp'=>'image/webp','woff2'=>'font/woff2'];
 if($path&&str_starts_with($path,$root.DIRECTORY_SEPARATOR)&&is_file($path)&&isset($types[$ext])){header('Content-Type: '.$types[$ext]);header('X-Content-Type-Options: nosniff');readfile($path);return;}
}
http_response_code(404);header('Content-Type: text/plain; charset=utf-8');echo 'Resursă indisponibilă.';

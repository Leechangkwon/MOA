<?php
/** Test-only equivalent of nginx's /tbox/assets alias. Isolated harness root only. */
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH);
if (is_string($path) && str_starts_with($path,'/tbox/assets/')) {
    $root=realpath(__DIR__.'/../../public/assets');
    $file=realpath($root.'/'.substr($path,13));
    $mimes=['js'=>'application/javascript','css'=>'text/css','json'=>'application/json','svg'=>'image/svg+xml','png'=>'image/png','woff2'=>'font/woff2'];
    $ext=$file===false ? '' : pathinfo($file,PATHINFO_EXTENSION);
    if ($file===false || !str_starts_with($file,$root.'/') || !is_file($file) || !isset($mimes[$ext])) { http_response_code(404); exit; }
    header('Content-Type: '.$mimes[$ext]);readfile($file);return true;
}
require __DIR__.'/../../public/index.php';

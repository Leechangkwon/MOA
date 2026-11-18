<?php
declare(strict_types=1);

$routePattern=substr(TBOX_ROUTE,strlen((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'))+1+strlen(COMMUNITY_BASE));
$id=(string)$params[0];
$op=substr($routePattern,strrpos($routePattern,'/')+1);
$queryInt=static function(string $key,int $default,int $min=0,int $max=2147483646): int {
    $v=$_GET[$key] ?? (string)$default;
    if (!is_string($v) || !preg_match('/^[0-9]{1,15}$/D',$v)) { Http::error(400,Http::MSG_BAD_REQUEST); }
    return WhiteboardSync::integer((int)$v,$min,$max);
};
$version=isset($_GET['version_no']) ? $queryInt('version_no',1,1) : null;
if (($_SERVER['REQUEST_METHOD'] ?? '')==='GET') {
    if ($op==='events') { Http::json(Whiteboards::events($user,$id,$version,$queryInt('after_seq',0),$queryInt('limit',200,1,500))); }
    elseif ($op==='changes') { Http::json(Whiteboards::changes($user,$id,$queryInt('after',0))); }
    else { Http::json(Whiteboards::read($user,$id,$version)); }
    return;
}
if ($op==='confirm') {
    if (!is_array($_FILES['png'] ?? null) || !is_string($_POST['last_seq'] ?? null)
        || !preg_match('/^[0-9]{1,15}$/D',$_POST['last_seq'])) { Http::error(400,Http::MSG_BAD_REQUEST); }
    Http::json(WhiteboardSnapshot::confirm($user,$id,WorkDocuments::id($_POST['whiteboard_id'] ?? null),
        WhiteboardSync::integer((int)$_POST['last_seq'],0,PHP_INT_MAX),$_FILES['png']));
    return;
}
$body=Http::jsonBody(262144,16);
if ($op==='elements') { Http::json(Whiteboards::push($user,$id,$body)); }
elseif ($op==='ticket') { Http::json(Whiteboards::ticket($user,$id,WorkDocuments::id($body['whiteboard_id'] ?? null))); }
elseif ($op==='versions') { Http::json(Whiteboards::create($user,$id,WorkDocuments::id($body['whiteboard_id'] ?? null))); }
else { Http::json(Whiteboards::create($user,$id,null),201); }

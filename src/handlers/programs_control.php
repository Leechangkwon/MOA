<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/Programs.php';
$action=(string)TBOX_ROUTE==='POST /api/programs/{id}/restore' ? 'restore':'withdraw';
Programs::control($user,(string)($params[0] ?? ''),$action,Http::jsonBody(2048));
Http::json(['ok'=>true]);

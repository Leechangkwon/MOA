<?php
declare(strict_types=1);
require_once __DIR__.'/lib/cli_bootstrap.php';
require_once dirname(__DIR__).'/src/ClientDiagnostics.php';
$result=ClientDiagnostics::rollup();
echo json_encode(['ok'=>true,'created'=>$result['created'],'busy'=>$result['busy']],JSON_THROW_ON_ERROR).PHP_EOL;

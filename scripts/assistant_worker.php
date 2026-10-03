<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if ($argc > 2 || ($argc === 2 && !in_array($argv[1], ['--once','--drain'],true))) {
    fwrite(STDERR,"사용법: php scripts/assistant_worker.php [--once|--drain]\n"); exit(2);
}
require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/assistant/bootstrap.php';
try {
    \QarediAssistant\Provider::ensureEnabled();
    $limit=($argv[1] ?? '--once')==='--drain' ? 100:1;
    $count=0;
    while ($count < $limit && ($r=\QarediAssistant\Worker::once()) !== null) {
        echo json_encode(['run_id'=>$r['id'],'status'=>$r['status']],JSON_UNESCAPED_SLASHES) . "\n";
        $count++;
    }
    echo json_encode(['processed'=>$count]) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR,"비서 워커를 실행할 수 없습니다. 설정과 DB 준비 상태를 확인하세요.\n"); exit(1);
}

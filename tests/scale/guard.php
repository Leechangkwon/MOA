<?php
declare(strict_types=1);
require __DIR__ . '/../../src/Db.php';
require __DIR__ . '/../../src/Http.php';
require __DIR__ . '/../../src/SearchText.php';
require __DIR__ . '/../../src/SearchGuard.php';
$sock = getenv('SCALE_TEST_SOCKET');
if (!is_string($sock) || !str_starts_with($sock, '/home/changkwon/qaredi-scale-results-20261012/cluster')) { throw new RuntimeException('임시 클러스터만 허용'); }
Db::boot($sock, '55432', 'tbox_re', 'perf', '');
$uid = (int) ($argv[2] ?? 7);
$mode = $argv[1] ?? 'normal';
$start = hrtime(true);
try {
    $result = SearchGuard::execute($uid, static function (callable $run) use ($mode): string {
        if ($mode === 'hold') { echo "READY\n"; flush(); $run('SELECT pg_sleep(2)'); }
        if ($mode === 'timeout') { $run('SELECT pg_sleep(5)'); }
        if ($mode === 'budget') { $run('SELECT pg_sleep(2)'); $run('SELECT pg_sleep(2)'); }
        if ($mode === 'error') { $run('SELECT 1/0'); }
        return (string) $run('SELECT 1 AS n')->fetch()['n'];
    });
    $out = ['status' => 'ok', 'result' => $result];
} catch (Throwable $e) { $out = ['status' => get_class($e)]; }
$out['ms'] = round((hrtime(true) - $start)/1000000);
$out['transaction'] = Db::instance()->inTransaction();
$out['timeout_reset'] = Db::instance()->run('SHOW statement_timeout')->fetch()['statement_timeout'];
$out['next_works'] = SearchGuard::execute($uid, static fn(callable $run): int => (int) $run('SELECT 2 AS n')->fetch()['n']) === 2;
echo json_encode($out), "\n";

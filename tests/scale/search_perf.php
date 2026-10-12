<?php
// 같은 130만 합성 DB에서 실제 검색 SQL을 새 보호기로 실행한다.
require __DIR__ . '/../../src/Db.php';
require __DIR__ . '/../../src/Http.php';
require __DIR__ . '/../../src/Authz.php';
require __DIR__ . '/../../src/SearchText.php';
require __DIR__ . '/../../src/SearchGuard.php';
require __DIR__ . '/../../src/community/routes.php';
$sock = getenv('SCALE_TEST_SOCKET');
if ($sock !== '/home/changkwon/qaredi-scale-results-20261012/cluster') { exit(3); }
Db::boot($sock, '55432', 'tbox_re', 'perf', '');
$db = Db::instance();
$u = $db->run('SELECT id, username, role, team_id, is_active FROM users WHERE id=7')->fetch();
foreach (['회의','zzrare'] as $q) {
    $terms = SearchText::queryTerms($q);
    [$sp,$bp] = community_search_sql($u,$terms,'pages');
    [$sc,$bc] = community_search_sql($u,$terms,'comments');
    $start = hrtime(true);
    try {
        $n = SearchGuard::execute(7, static function ($run) use ($sp,$bp,$sc,$bc): int {
            $run(COMMUNITY_SEARCH_PAGES_PLAN_ON);
            $pages = $run($sp,$bp)->fetchAll();
            $run(COMMUNITY_SEARCH_PAGES_PLAN_OFF);
            return count($pages) + count($run($sc,$bc)->fetchAll());
        });
        $status = 'ok';
    } catch (SearchTimeout $e) { $status = 'timeout'; $n = 0; }
    echo json_encode(['synthetic_query'=>$q,'status'=>$status,'ms'=>(hrtime(true)-$start)/1e6,'rows'=>$n]),"\n";
}

<?php
declare(strict_types=1);

final class SearchBusy extends RuntimeException {}
final class SearchTimeout extends RuntimeException {}

/** 검색 전용 실행 보호. 판정 SQL/정렬은 바꾸지 않는다. */
final class SearchGuard
{
    public const SHORT = '공백을 제외하고 두 글자 이상 입력해 주세요.';
    public const TIMEOUT = '결과가 너무 많거나 오래 걸립니다 — 검색어를 더 구체적으로 입력해 주세요.';
    public const BUSY = '앞선 검색을 마치는 중입니다. 잠시 뒤 다시 시도해 주세요.';

    public static function longEnough(string $q): bool
    {
        $text = preg_replace('/[\s\p{Z}]+/u', '', SearchText::compose($q)) ?? '';
        return iconv_strlen($text, 'UTF-8') >= 2;
    }

    /** 모든 문장이 나누어 쓰는 3초 예산. SET LOCAL/잠금은 commit 또는 rollback 때 풀린다. */
    public static function execute(int $userId, callable $work): mixed
    {
        $db = Db::instance();
        if ($db->inTransaction()) {
            throw new LogicException('검색 보호는 독립 트랜잭션에서 시작해야 합니다');
        }
        $db->begin();
        try {
            $db->run("SET LOCAL statement_timeout = '3s'");
            $lockSql = "SELECT pg_try_advisory_xact_lock(hashtextextended('qaredi.search:' || CAST(:search_user AS text), 0)) AS acquired";
            $row = $db->run($lockSql, [':search_user' => $userId])->fetch();
            if (!is_array($row) || !in_array($row['acquired'], [true, 't'], true)) {
                throw new SearchBusy();
            }
            $until = hrtime(true) + 3000000000;
            $run = static function (string $sql, array $bind = []) use ($db, $until): PDOStatement {
                $remaining = (int) floor(($until - hrtime(true)) / 1000000);
                if ($remaining <= 0) { throw new SearchTimeout(); }
                $timeoutSql = "SELECT set_config('statement_timeout', CAST(:search_ms AS text), true)";
                $db->run($timeoutSql, [':search_ms' => $remaining]);
                return $db->run($sql, $bind);
            };
            $result = $work($run);
            $db->commit();
            return $result;
        } catch (Throwable $e) {
            $db->rollback();
            if ($e instanceof PDOException && (string) $e->getCode() === '57014') {
                throw new SearchTimeout();
            }
            throw $e;
        }
    }

    /** 예외 원문/입력값을 기록하지 않는다. 보호 동작은 인증 실패/계정 잠금으로 세지 않는다. */
    public static function limited(Throwable $error, array $empty, bool $tokenArray = false): void
    {
        if (!$error instanceof SearchBusy && !$error instanceof SearchTimeout) { throw $error; }
        $busy = $error instanceof SearchBusy;
        error_log('[tbox] search_control=' . ($busy ? 'busy' : 'timeout'));
        if ($busy) { header('Retry-After: 1'); }
        if ($tokenArray && !$busy) {
            header('X-Search-Status: timeout');
            Http::json([]);
            return;
        }
        if ($busy) { $empty['error'] = self::BUSY; }
        Http::json($empty + ['search_status' => $busy ? 'busy' : 'timeout', 'notice' => $busy ? self::BUSY : self::TIMEOUT], $busy ? 429 : 200);
    }
}

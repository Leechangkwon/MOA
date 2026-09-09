<?php
declare(strict_types=1);

/**
 * src/Audit.php — 감사 로그 (PRD S-07, ARCHITECTURE 7.1 · 7.2)
 *
 * 두 곳에 동시에 기록한다.
 *   · DB  audit_logs      — 원본(조회용). 앱 계정에는 SELECT·INSERT 만 있어 지울 수 없다(Append-only).
 *   · 파일 AUDIT_LOG_FILE — 사본(증거 보전용). DB 가 손상돼도 흔적이 남는다.
 *
 * 기록에 실패하면 예외를 던진다 (PRD S-07 "기록 없는 민감 행동 금지").
 * 호출자(라우터·핸들러)는 트랜잭션을 롤백하고 500 으로 끝난다 — 기록 없이 성공하는 경로를 만들지 않는다.
 *
 * 절대 기록하지 않는 것: 비밀번호, 세션 토큰·쿠키, 파일 본문 (PRD 7.7 "민감정보 금지").
 * detail 배열에 그런 키가 섞여 들어오면 값을 지우고 '[제거됨]' 으로 바꾼다 — 실수로 새는 경로를 막는다.
 */
final class Audit
{
    /** detail 에서 값을 지울 키 (부분 일치, 대소문자 무시) */
    private const FORBIDDEN_DETAIL_KEYS = [
        'password', 'passwd', 'pass', 'token', 'cookie', 'secret',
        'authorization', 'session', 'hash', 'csrf',
    ];

    private const RESULT_ALLOW = 'allow';
    private const RESULT_DENY  = 'deny';
    private const RESULT_ERROR = 'error';

    private const SQL_INSERT = <<<'SQL'
        INSERT INTO audit_logs
            (request_id, actor_id, actor_name, actor_role, action,
             target_type, target_id, target_name, access_level,
             result, reason, ip, user_agent, detail)
        VALUES
            (CAST(:request_id AS uuid), :actor_id, :actor_name, :actor_role, :action,
             :target_type, :target_id, :target_name, :access_level,
             :result, :reason, CAST(:ip AS inet), :user_agent, CAST(:detail AS jsonb))
        SQL;

    /** 성공한 행동 (PRD 7.7 표의 "성공 시") */
    public static function allow(string $action, array $ctx = []): void
    {
        self::write(self::RESULT_ALLOW, $action, null, $ctx);
    }

    /** 거부된 행동 — 401/403 과 권한 사유 404 (PRD 7.7 "실패·거부 시") */
    public static function deny(string $action, string $reason, array $ctx = []): void
    {
        self::write(self::RESULT_DENY, $action, $reason, $ctx);
    }

    /** 처리 중 오류 (검증 실패·저장 실패 등) */
    public static function error(string $action, string $reason, array $ctx = []): void
    {
        self::write(self::RESULT_ERROR, $action, $reason, $ctx);
    }

    /**
     * @param array<string,mixed> $ctx
     *        target_type / target_id / target_name / access_level / detail(배열) 를 받는다.
     *        actor 는 요청에서 읽지 않는다 — 항상 Auth::currentUser() 에서 가져온다(PRD S-01).
     */
    private static function write(string $result, string $action, ?string $reason, array $ctx): void
    {
        $user = self::actor();

        $detail = isset($ctx['detail']) && is_array($ctx['detail'])
            ? self::scrub($ctx['detail'])
            : null;
        $detailJson = $detail === null || $detail === []
            ? null
            : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($detailJson === false) {
            throw new RuntimeException('감사 로그 detail 을 직렬화할 수 없습니다');
        }

        $row = [
            'happened_at'  => date('c'),
            'request_id'   => self::requestId(),
            'actor_id'     => $user === null ? null : (int) $user['id'],
            'actor_name'   => $user === null ? null : (string) $user['username'],
            'actor_role'   => $user === null ? null : (string) $user['role'],
            'action'       => $action,
            'target_type'  => self::str($ctx, 'target_type'),
            'target_id'    => self::int($ctx, 'target_id'),
            'target_name'  => self::str($ctx, 'target_name'),
            'access_level' => self::str($ctx, 'access_level'),
            'result'       => $result,
            'reason'       => $reason,
            'ip'           => Http::clientIp(),
            'user_agent'   => Http::userAgent(),
        ];

        self::toDatabase($row, $detailJson);
        self::toFile($row, $detail);
    }

    /** @param array<string,mixed> $row */
    private static function toDatabase(array $row, ?string $detailJson): void
    {
        Db::instance()->run(self::SQL_INSERT, [
            ':request_id'   => $row['request_id'],
            ':actor_id'     => $row['actor_id'],
            ':actor_name'   => $row['actor_name'],
            ':actor_role'   => $row['actor_role'],
            ':action'       => $row['action'],
            ':target_type'  => $row['target_type'],
            ':target_id'    => $row['target_id'],
            ':target_name'  => $row['target_name'],
            ':access_level' => $row['access_level'],
            ':result'       => $row['result'],
            ':reason'       => $row['reason'],
            ':ip'           => $row['ip'],
            ':user_agent'   => $row['user_agent'],
            ':detail'       => $detailJson,
        ]);
    }

    /**
     * 파일 사본 — 한 줄 JSON append. 경로는 .env 의 고정 값이며 사용자 입력이 섞이지 않는다.
     *
     * @param array<string,mixed>      $row
     * @param array<string,mixed>|null $detail
     */
    private static function toFile(array $row, ?array $detail): void
    {
        $path = Env::get('AUDIT_LOG_FILE');
        if ($detail !== null && $detail !== []) {
            $row['detail'] = $detail;
        }
        $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            throw new RuntimeException('감사 로그를 직렬화할 수 없습니다');
        }
        $written = file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('감사 로그 파일에 기록할 수 없습니다');
        }
    }

    /**
     * 행동 당시의 사용자. 요청 파라미터의 role 값은 읽지 않는다 (PRD S-01).
     *
     * @return array<string,mixed>|null
     */
    private static function actor(): ?array
    {
        return Auth::currentUser();
    }

    private static function requestId(): string
    {
        if (defined('TBOX_REQUEST_ID')) {
            return (string) constant('TBOX_REQUEST_ID');
        }
        throw new RuntimeException('request_id 가 준비되지 않았습니다');
    }

    /**
     * 비밀 값이 로그에 남지 않게 한다. 중첩 배열까지 훑는다.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private static function scrub(array $in, int $depth = 0): array
    {
        if ($depth > 4) {
            return ['_' => '[깊이 초과]'];
        }
        $out = [];
        foreach ($in as $k => $v) {
            $key = is_string($k) ? $k : (string) $k;
            if (self::isForbiddenKey($key)) {
                $out[$key] = '[제거됨]';
                continue;
            }
            if (is_array($v)) {
                $out[$key] = self::scrub($v, $depth + 1);
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $out[$key] = is_string($v) ? substr($v, 0, 500) : $v;
                continue;
            }
            $out[$key] = '[기록 불가]';
        }
        return $out;
    }

    private static function isForbiddenKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::FORBIDDEN_DETAIL_KEYS as $bad) {
            if (strpos($lower, $bad) !== false) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $ctx */
    private static function str(array $ctx, string $key): ?string
    {
        $v = $ctx[$key] ?? null;
        if ($v === null) {
            return null;
        }
        return substr((string) $v, 0, 500);
    }

    /** @param array<string,mixed> $ctx */
    private static function int(array $ctx, string $key): ?int
    {
        $v = $ctx[$key] ?? null;
        if ($v === null || $v === '') {
            return null;
        }
        return (int) $v;
    }
}

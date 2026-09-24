<?php
declare(strict_types=1);

/**
 * src/Db.php — PDO 래퍼 (PRD S-04, SECURITY 1.5)
 *
 * 이 클래스가 외부에 내주는 것은 run() 과 트랜잭션 3개뿐이다.
 * PDO 객체 자체도, query·exec 같은 "문자열을 그대로 실행하는" 메서드도 밖으로 내주지 않는다.
 *   → "문자열을 그대로 실행하는 경로"가 코드 안에 존재하지 않게 만드는 것이 목적이다.
 *   → 값은 항상 $params 로 바인딩된다. SQL 문에 값을 붙여 만드는 코드는 린트가 반려한다.
 *
 * ATTR_EMULATE_PREPARES = false : 서버측 prepare 를 쓴다.
 *   PHP 가 흉내내는(emulate) prepare 는 결국 문자열을 조립하므로 인젝션 여지가 남는다.
 *
 * 앱 연결의 두 성질 (Q:F-4 P-2 · P-5c, ARCH 5.31, SECURITY R-101)
 *   · **첫 질의 때 연다** — `boot()` 는 접속 정보만 보관한다. 쿠키 없는 런처 `/`·옛 주소 308·404 처럼
 *     DB 가 필요 없는 요청은 PostgreSQL 에 연결하지 않는다(앞단 헬스체크가 2초마다 `/` 를 부른다).
 *   · **워커마다 재사용한다** — `boot()` 경로의 연결만 `PDO::ATTR_PERSISTENT`. 요청마다 TLS + SCRAM 을
 *     새로 맺던 비용(약 21ms)을 워커의 첫 요청 한 번으로 줄인다. 대가는 「요청이 끝나도 연결이 남는다」
 *     는 것 — 그래서 연결을 **넘겨받을 때**(adopt)와 **돌려줄 때**(endRequest) 두 번 정리한다.
 *     권한 매트릭스 쓰기 연결 `perm()` 은 **비영속 그대로**다(아래 perm 주석).
 */
final class Db
{
    private static ?Db $instance = null;

    /**
     * 권한 매트릭스 편집 전용 두 번째 연결 (17-2, PRD F-25 ⑤, ARCH 5.15 · 10장, SECURITY R-60).
     * 지연 생성이며, 이 값이 null 인 동안 `tbox_perm` 자격은 프로세스 어디에도 없다.
     */
    private static ?Db $permInstance = null;

    /**
     * 이 요청에서 앱 연결을 여는 데 실패했는가 (F-48, ARCH 5.24).
     *
     * 전에는 boot() 의 `new PDO` 실패가 그대로 밖으로 던져져 **모든 요청**이 500 으로 끝났다 —
     * `GET /api/health` 핸들러까지 오지도 못하므로 「DB 만 죽었다」 를 503 으로 알릴 수 없었다
     * (2026-09-15 디스크 0 → PostgreSQL PANIC 사고, SECURITY R-80).
     * 이제 실패는 서버 로그에만 적고 이 표식으로 기억한다. 표식이 있으면 run()·begin() 이 던지므로
     * DB 를 읽는 창구의 결과는 전과 같은 500 이다 — 달라지는 것은 health 하나뿐이다.
     * Q:F-4 P-5c 부터 연결 시도는 부팅이 아니라 **첫 질의 때** 일어난다. 한 요청 안에서 두 번 시도하지
     * 않는다(DB 가 죽었을 때 한 요청이 연결 대기 시간을 여러 번 물지 않게).
     */
    private static bool $connectFailed = false;

    /** null 이면 아직 연결하지 않았다 (P-5c) */
    private ?PDO $pdo = null;
    private int $depth = 0;

    private string $dsn;
    private string $user;
    private string $pass;

    /** true 면 `PDO::ATTR_PERSISTENT` — boot() 경로(앱 연결)만 (P-2) */
    private bool $persistent;

    /** 접속 정보만 보관한다 — 연결은 open() 에서 (P-5c) */
    private function __construct(string $dsn, string $user, string $pass, bool $persistent)
    {
        $this->dsn        = $dsn;
        $this->user       = $user;
        $this->pass       = $pass;
        $this->persistent = $persistent;
    }

    /**
     * 연결을 연다. 실패하면 PDOException 이 그대로 나간다(부르는 쪽이 가른다).
     * `$persistent` 를 주면 이 한 번만 영속 여부를 바꾼다 — adopt() 의 재접속이 쓴다.
     */
    private function open(?bool $persistent = null): PDO
    {
        return new PDO($this->dsn, $this->user, $this->pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_PERSISTENT         => $persistent ?? $this->persistent,
        ]);
    }

    /**
     * 앱 연결의 접속 정보를 보관한다 — **연결하지 않는다** (Q:F-4 P-5c).
     *
     * 연결은 첫 run()·begin() 에서(또는 health 의 ready() 에서) 연다. 그래서 DB 가 필요 없는 요청은
     * PostgreSQL 에 붙지 않고, DB 가 내려가 있어도 쿠키 없는 런처는 정적 화면 그대로다.
     */
    public static function boot(string $host, string $port, string $name, string $user, string $pass): void
    {
        if (self::$instance !== null) {
            return;
        }
        $dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name;
        self::$instance = new self($dsn, $user, $pass, true);
    }

    /**
     * 앱 연결이 열려 있는가 — **아직 열지 않았으면 지금 연다** (F-48, ARCH 5.24 · Q:F-4 P-5c).
     *
     * 쓰는 곳은 health 핸들러 하나다 — 연결 실패(`connect_failed`)와 질의 실패(`query_failed`)를 가른다.
     * 연결을 **열지 않고** 물어야 하는 자리(예외 처리기의 롤백)는 connected() 를 쓴다.
     */
    public static function ready(): bool
    {
        if (self::$instance === null) {
            return false;
        }
        try {
            self::$instance->pdo();
            return true;
        } catch (RuntimeException $e) {
            // 원문은 pdo() 가 이미 서버 로그에 남겼다
            return false;
        }
    }

    /**
     * 이 요청에서 앱 연결을 이미 열었는가 — **열지 않는다** (Q:F-4 P-5c).
     *
     * bootstrap 예외 처리기가 쓴다: 연결한 적이 없으면 되돌릴 트랜잭션도 없으므로 롤백하려고
     * 연결을 새로 열지 않는다(DB 가 죽은 요청의 예외 처리기가 연결 대기를 한 번 더 물지 않게).
     */
    public static function connected(): bool
    {
        return self::$instance !== null && self::$instance->pdo !== null;
    }

    public static function instance(): Db
    {
        if (self::$instance === null) {
            throw new RuntimeException('DB 가 초기화되지 않았습니다');
        }
        return self::$instance;
    }

    /**
     * 권한 매트릭스 편집 전용 연결 — `tbox_perm` (17-2, PRD F-25 ⑤, ARCH 5.15 · 10장).
     *
     * 왜 두 번째 연결인가 (SECURITY R-60 · R-43)
     *   앱 런타임 롤 `tbox_app` 은 `permissions` 에 **SELECT 만** 갖는다. 그 성질이
     *   "다른 핸들러의 결함(SQL 인젝션 등)으로는 권한 표를 바꿀 수 없다" 를 보장한다.
     *   17-2 에서 화면 편집을 허용하면서도 그 성질을 유지하려면, 쓰기 자격을 앱 연결에
     *   주는 대신 **별도 롤**(permissions SELECT·UPDATE 만, 다른 표 권한 0)을 따로 연다.
     *
     * 부르는 곳은 `src/handlers/admin_permissions_update.php` **하나뿐**이고,
     * 그 밖에서 `Db::perm(` 이 나오면 `scripts/security_lint.sh` 가 반려한다
     * (src/Fetch.php 의 cURL 규칙과 같은 방식).
     *
     * 연결은 **지연 생성**이다 — 매트릭스를 바꾸는 요청이 아니면 이 자격으로 붙지 않는다.
     * `.env` 에 `DB_PERM_USER`·`DB_PERM_PASS` 가 없으면 Env::get 이 예외를 던지고
     * bootstrap 의 예외 처리기가 500 으로 끝낸다 — 조용히 앱 연결로 되돌아가지 않는다.
     *
     * ★ **비영속이다**(Q:F-4 P-2 — 앱 연결만 영속). 쓰기 자격이 붙은 연결이 요청 뒤에도 워커에
     *   남아 있지 않게 한다 — 요청이 끝나면 닫히고, 열린 트랜잭션도 닫히면서 함께 사라진다.
     *   부르는 순간 곧바로 연결한다(전과 같다 — 첫 질의까지 미루지 않는다).
     */
    public static function perm(): Db
    {
        if (self::$permInstance !== null) {
            return self::$permInstance;
        }
        $dsn = 'pgsql:host=' . Env::get('DB_HOST')
             . ';port=' . Env::get('DB_PORT')
             . ';dbname=' . Env::get('DB_NAME');
        // 옵션은 앱 연결과 같다 — 예외 모드·prepare 에뮬레이션 off (open()). 영속만 다르다.
        $perm = new self($dsn, Env::get('DB_PERM_USER'), Env::get('DB_PERM_PASS'), false);
        $perm->pdo = $perm->open();
        self::$permInstance = $perm;
        return self::$permInstance;
    }

    /**
     * 이 요청의 연결 — 처음 부를 때 연다 (Q:F-4 P-5c).
     *
     * 실패하면 원문(호스트·포트·계정 이름이 든다)은 서버 로그에만 `request_id` 와 함께 남기고
     * (PRD S-09-7, ARCH 5.24), 밖으로는 값이 없는 고정 문구의 RuntimeException 만 던진다.
     */
    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if (self::$connectFailed) {
            throw new RuntimeException('DB 연결에 실패했습니다');
        }
        try {
            $pdo = $this->open();
            if ($this->persistent) {
                $pdo = $this->adopt($pdo);
            }
        } catch (PDOException $e) {
            // TBOX_REQUEST_ID 는 bootstrap 이 먼저 정의하지만, CLI 단위 실행도 고려해 확인한다.
            self::$connectFailed = true;
            error_log('[tbox] request_id=' . self::rid()
                . ' db connect failed: ' . get_class($e) . ': ' . $e->getMessage());
            throw new RuntimeException('DB 연결에 실패했습니다');
        }
        $this->pdo = $pdo;
        return $pdo;
    }

    /**
     * 워커에 남아 있던 영속 연결을 **이 요청이 넘겨받기 전에** 정리한다 (Q:F-4 P-2, SECURITY R-101).
     *
     *   ① 열린 트랜잭션이 남아 있으면 되돌린다. 정상이라면 앞 요청의 끝(endRequest)과 PHP 의 PDO 정리가
     *      이미 되돌렸어야 한다 — 이것은 그 둘이 모두 못 돈 경우의 그물이다. 앞 사람의 트랜잭션을 다음
     *      사람의 요청이 이어 쓰는 일(쓰기가 남의 트랜잭션에 섞이거나 잠금이 풀리지 않는 일)을 막는다.
     *      `inTransaction()` 은 libpq 가 이미 들고 있는 상태를 읽을 뿐이라 왕복이 없다.
     *   ② `DISCARD ALL` 한 번(왕복 1) — 두 가지를 한꺼번에 한다.
     *      · **세션 상태를 비운다**: 세션 설정(`SET`·`set_config`)·`SET ROLE`·임시 표·세션 advisory lock·`LISTEN`·
     *        열린 커서·서버 prepare 문장. 앱 코드는 이것들을 쓰지 않지만(ARCH 5.31), 트랜잭션 안에서 SQL 오류가 나면
     *        그 문장의 `DEALLOCATE` 가 거부돼 prepare 가 세션에 남는다(2026-09-24 실측) — 그런 찌꺼기까지 요청마다
     *        처음 상태로 돌린다. 역할 기본값(`ALTER ROLE … SET`)은 「처음 상태」에 들어 있어 그대로다.
     *      · **살아 있는지 확인한다**: DB 가 재시작됐거나 연결이 끊긴 뒤 PDO 의 자체 확인(PQconsumeInput)은
     *        끊김을 늘 알아채지 못해, 그대로 두면 **워커마다 첫 요청 하나가 500** 이 된다(`pg_terminate_backend` 실측).
     *        실패하면 한 번만 다시 연결한다 — 같은 요청 안에서 되풀이하지 않는다. 다시 연결도 실패하면 연결 실패
     *        (health 503 · 나머지 500)로 끝난다. 새로 맺은 연결은 처음 상태라 다시 비우지 않는다.
     *      `DISCARD ALL` 은 트랜잭션 안에서 돌 수 없다 — 그래서 ① 뒤에 온다. 문장은 코드에 적힌 고정 문자열이고
     *      값이 없다. `PGSQL_ATTR_DISABLE_PREPARES` 는 이름 있는 서버 prepare 대신 이름 없는 문장(PQexecParams)으로
     *      보내 왕복을 하나로 줄일 뿐이다 — 값을 문자열에 끼우는 에뮬레이션(ATTR_EMULATE_PREPARES)과 무관하다.
     *   실제 질의는 다시 보내지 않는다 — 다시 보내도 안전한지(이미 반영됐는지) 알 수 없기 때문이다.
     */
    private function adopt(PDO $pdo): PDO
    {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
                error_log('[tbox] request_id=' . self::rid()
                    . ' db: reused connection had an open transaction — rolled back before use');
            }
            $st = $pdo->prepare('DISCARD ALL', [PDO::PGSQL_ATTR_DISABLE_PREPARES => true]);
            $st->execute();
            $st = null;
            return $pdo;
        } catch (PDOException $e) {
            // 원문은 서버 로그에만 (S-09-7). 끊긴 연결은 libpq 가 CONNECTION_BAD 로 표시했으므로
            // 영속 DSN 으로 다시 열면 PDO 는 libpq 가 CONNECTION_BAD 로 표시한 경우에만 PQreset 하고,
            // 아니면 **같은 풀 핸들**을 그대로 준다(소켓은 살아 있으나 DISCARD·ROLLBACK 이 다른 이유로
            // 실패한 경우 — 독립 검토 M-1, 2026-09-24). 그래서 다시 연결은 **이 요청만 쓰는 비영속
            // 연결**로 연다: 정리 안 된 핸들을 이 요청이 쓰지 않는다. 풀에 남은 핸들은 다음 요청의
            // adopt() 가 다시 정리를 시도하고, 워커 재활용(유휴·요청 수 한도) 때 닫힌다.
            error_log('[tbox] request_id=' . self::rid()
                . ' db: reused connection is not usable, reconnecting once: ' . get_class($e) . ': ' . $e->getMessage());
        }
        $pdo = null;
        return $this->open(false);
    }

    /**
     * 요청 끝 정리 (Q:F-4 P-2, SECURITY R-101) — bootstrap 의 종료 함수가 **모든 요청의 끝에** 부른다.
     *
     * 비영속 연결일 때는 요청이 끝나며 연결이 닫혀 열린 트랜잭션이 저절로 사라졌다. 영속 연결은 닫히지
     * 않으므로 같은 결과를 여기서 만든다: 트랜잭션이 열린 채 끝난 요청(예외 처리기가 못 돈 치명적 오류,
     * 트랜잭션 안의 `Http::error()`·`exit`, `Db::begin` 을 거치지 않은 트랜잭션)이면 되돌린다.
     * 연결을 열지 않은 요청에서는 아무것도 하지 않는다(연결을 새로 열지 않는다).
     * 되돌린 일은 서버 로그에 한 줄 남긴다 — 값은 없고 request_id 와 깊이뿐이다.
     */
    public static function endRequest(): void
    {
        $db = self::$instance;
        if ($db === null || $db->pdo === null) {
            return;
        }
        $depth     = $db->depth;
        $db->depth = 0;
        try {
            if ($db->pdo->inTransaction()) {
                $db->pdo->rollBack();
                error_log('[tbox] request_id=' . self::rid()
                    . ' db: open transaction rolled back at request end (depth=' . $depth . ')');
            }
        } catch (Throwable $e) {
            // 끊긴 연결이면 다음 요청의 adopt() 가 다시 맺는다. 응답은 이미 정해졌다.
            error_log('[tbox] request_id=' . self::rid() . ' db: request-end rollback failed: ' . get_class($e));
        }
    }

    /** 서버 로그용 request_id (CLI 단위 실행이면 '-') */
    private static function rid(): string
    {
        return defined('TBOX_REQUEST_ID') ? (string) TBOX_REQUEST_ID : '-';
    }

    /**
     * 유일한 실행 경로. $sql 은 코드에 적힌 고정 문자열만,
     * 값은 전부 $params 로 넘긴다.
     *
     * @param array<string|int,mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public function begin(): void
    {
        if ($this->depth === 0) {
            $this->pdo()->beginTransaction();
        }
        $this->depth++;
    }

    public function commit(): void
    {
        if ($this->depth === 0) {
            throw new RuntimeException('열린 트랜잭션이 없습니다');
        }
        $this->depth--;
        if ($this->depth === 0) {
            $this->pdo()->commit();
        }
    }

    public function rollback(): void
    {
        if ($this->depth === 0) {
            return;
        }
        $this->depth = 0;
        if ($this->pdo !== null && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** 트랜잭션 안인가 — 연결하지 않은 요청이면 false (연결을 열지 않는다) */
    public function inTransaction(): bool
    {
        return $this->pdo !== null && $this->pdo->inTransaction();
    }
}

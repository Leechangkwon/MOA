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
 */
final class Db
{
    private static ?Db $instance = null;

    /**
     * 권한 매트릭스 편집 전용 두 번째 연결 (17-2, PRD F-25 ⑤, ARCH 5.15 · 10장, SECURITY R-60).
     * 지연 생성이며, 이 값이 null 인 동안 `tbox_perm` 자격은 프로세스 어디에도 없다.
     */
    private static ?Db $permInstance = null;

    private PDO $pdo;
    private int $depth = 0;

    private function __construct(string $dsn, string $user, string $pass)
    {
        $this->pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    }

    public static function boot(string $host, string $port, string $name, string $user, string $pass): void
    {
        if (self::$instance !== null) {
            return;
        }
        $dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name;
        self::$instance = new self($dsn, $user, $pass);
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
     */
    public static function perm(): Db
    {
        if (self::$permInstance !== null) {
            return self::$permInstance;
        }
        $dsn = 'pgsql:host=' . Env::get('DB_HOST')
             . ';port=' . Env::get('DB_PORT')
             . ';dbname=' . Env::get('DB_NAME');
        // 옵션은 앱 연결과 같다 — 예외 모드·prepare 에뮬레이션 off (위 생성자).
        self::$permInstance = new self($dsn, Env::get('DB_PERM_USER'), Env::get('DB_PERM_PASS'));
        return self::$permInstance;
    }

    /**
     * 유일한 실행 경로. $sql 은 코드에 적힌 고정 문자열만,
     * 값은 전부 $params 로 넘긴다.
     *
     * @param array<string|int,mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public function begin(): void
    {
        if ($this->depth === 0) {
            $this->pdo->beginTransaction();
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
            $this->pdo->commit();
        }
    }

    public function rollback(): void
    {
        if ($this->depth === 0) {
            return;
        }
        $this->depth = 0;
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }
}

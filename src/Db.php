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

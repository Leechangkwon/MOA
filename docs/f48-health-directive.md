# F-48 구현 지시서 — `GET /api/health` DB 확인 · T-60 시험 (Fable → Opus, 2026-09-15)

```
목표        : GET /api/health 가 DB 에 SELECT 1 을 해 보고 200 {"status":"ok","db":"ok","time":"…"} / 503 {"status":"fail","db":"<분류어>"} 로 답하게 한다.
요구사항    : PRD F-48 · 8장 API 표 `/api/health` 행 · 12.2 T-60, ARCHITECTURE 5.24 — 이 세 곳을 읽고 시작할 것.
              S-09-5(정찰 재료 금지) · S-09-7(오류 원문 노출 금지) · F-47 ④(버전 없음) · F-42(CORS 없음).
수정 파일   : src/handlers/health.php            (전면 재작성 허용)
              src/Db.php                         (보안 핵심 파일 — 아래 명시 범위만)
              src/bootstrap.php                  (예외 처리기의 rollback 가드 한 곳만)
              tests/health.sh                    (새 파일, T-60)
              ★ 이 네 파일 밖은 만들거나 고치지 않는다. public/index.php 라우트 표는 건드리지 않는다(라우트는 이미 있다).
              ★ deploy/sites/*.conf 도 건드리지 않는다 — `/api/` 는 이미 `location / { try_files $uri /index.php$is_args$args; }` 로 PHP 에 간다(Fable 실측).
금지 패턴   : SQL 문자열 결합, innerHTML, 사용자 입력이 들어간 파일 경로, curl_exec/file_get_contents('http…'),
              역할 판단을 Authz 밖에서 직접 하는 코드, 요청 파라미터의 role 값 사용,
              **응답 본문·헤더에 예외 문구·호스트·포트·DB 계정·PHP/PG 버전·서버 이름·VERSION 값을 넣는 것**,
              `Access-Control-*` 헤더, 감사 로그 기록(이 창구는 대상 없음), 요청 값 읽기(이 창구는 입력이 없다).
완료 기준   : PRD 12.2 T-60 (a)(b)(c) — tests/health.sh 로 전부 실행. 아래 「시험 규칙」 준수.
보고 형식   : 변경 파일 목록 / 실행한 명령과 출력 원문 / 못 한 것과 이유
```

## 구현 세부

### 1. `src/Db.php` — 허용 범위
- `boot()` 안의 `new self($dsn, $user, $pass)` 를 `try { … } catch (PDOException $e) { … }` 로 감싼다.
  - catch 에서: `error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' db connect failed: ' . get_class($e) . ': ' . $e->getMessage());`
    (`TBOX_REQUEST_ID` 가 정의돼 있는지 `defined()` 로 확인 후 사용 — bootstrap 이 먼저 정의하지만 CLI 단위 실행을 고려)
  - `private static bool $bootFailed = false;` 를 두고 catch 에서 `true` 로.
- `instance()` : `$instance === null` 이고 `$bootFailed` 이면 `throw new RuntimeException('DB 연결에 실패했습니다');` (문구에 값 없음).
  기존 「초기화되지 않았습니다」 분기는 그대로.
- 새 메서드 `public static function ready(): bool { return self::$instance !== null; }` 하나 추가.
- 그 밖(run·트랜잭션·perm·생성자 옵션)은 **한 글자도 바꾸지 않는다**.

### 2. `src/bootstrap.php` — 허용 범위
- 예외 처리기 안의 `if (class_exists('Db', false)) { Db::instance()->rollback(); }` 를
  `if (class_exists('Db', false) && Db::ready()) { … }` 로만 바꾼다. 다른 줄은 손대지 않는다.

### 3. `src/handlers/health.php`
```php
try {
    Db::instance()->run('SELECT 1')->fetchColumn();
} catch (RuntimeException $e) {          // boot 실패 → instance() 가 던진 것
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' health: connect_failed');
    Http::json(['status' => 'fail', 'db' => 'connect_failed'], 503);
    return;
} catch (Throwable $e) {                 // 연결은 됐는데 질의가 실패
    error_log('[tbox] request_id=' . TBOX_REQUEST_ID . ' health: query_failed ' . get_class($e) . ': ' . $e->getMessage());
    Http::json(['status' => 'fail', 'db' => 'query_failed'], 503);
    return;
}
Http::json(['status' => 'ok', 'db' => 'ok', 'time' => date(DATE_ATOM)]);
```
- 키 순서와 값은 위와 **정확히**. 실패 본문에 `time` 을 넣지 않는다(사용자 사양 두 키).
- 파일 상단 주석에 F-48·ARCH 5.24 를 적고, 왜 `db` 가 고정 분류어인지(PDO 문구에 호스트·포트·계정이 들어감) 한 줄.
- 주의: `RuntimeException` 을 `Throwable` 보다 **먼저** 잡아야 한다. `PDOException` 은 `RuntimeException` 의 자식이므로,
  질의 실패가 `connect_failed` 로 잘못 분류되지 않게 **`Db::ready()` 로 갈라도 된다** — 즉
  `if (!Db::ready()) → connect_failed` 를 try 앞에 두고, try 안의 어떤 Throwable 도 `query_failed` 로 하는 쪽이 더 명확하다. 이 방식을 택할 것.

### 4. `tests/health.sh` (T-60) — 기존 `tests/totp_time_sync.sh` 의 골격(expect/hr/sub 함수, `set -u`, UA, BASE=127.0.0.1:8082)을 따른다
(a) 정상
- `curl -s -D hdr -o body -A "$UA" $BASE/api/health` → 상태 200
- 본문 키가 정확히 status·db·time (`php -r` 로 json_decode 뒤 `array_keys` 비교)
- `status=ok`, `db=ok`, `time` 이 `DateTimeImmutable::createFromFormat(DATE_ATOM, …)` 로 파싱되고 `date +%s` 와 ±5초
- 헤더 `Content-Type: application/json`, `Cache-Control: no-store`, `Access-Control-` 0건
- 본문에 `cat VERSION` 값 0건, `PHP`·`PostgreSQL` 문자열 0건
- `curl -X POST` → 405
- 감사 로그 0행: `sudo -n psql -U postgres -d <DB명> -tAc "SELECT count(*) FROM audit_logs WHERE path='/api/health'"` 식으로 — **audit_logs 의 실제 컬럼명은 `db/schema.sql` 을 읽고 맞춘다.** DB 이름·접속 방법은 다른 tests/*.sh 가 psql 을 어떻게 부르는지 그대로 따른다. sudo 가 안 되면 그 항목은 `SKIP` 으로 표시하고 실패로 세지 않는다.
(b) 실패 — **운영 DB·설정·서비스를 절대 건드리지 않는다.** `mktemp -d` 에 `src/`·`public/`·`VERSION`·`CHANGELOG.json` 을 복사하고
  거기에 **가짜 `.env`** 를 쓴다(예: `DB_HOST=127.0.0.1`, `DB_PORT=1`, `DB_NAME=fakedb`, `DB_USER=fakeuser`, `DB_PASS=fakepass-zz`,
  그 외 bootstrap/Env::get 이 요구하는 키는 `.env.example` 의 키 이름을 보고 가짜 값으로 채운다 — **실제 `.env` 를 읽거나 복사하지 않는다**).
  `php -S 127.0.0.1:18082 -t $TMP/public $TMP/public/index.php &` 로 띄우고(준비될 때까지 최대 5초 대기), 
  - `GET /api/health` → 503, 본문이 정확히 `{"status":"fail","db":"connect_failed"}`
  - 본문+헤더에 `fakeuser`·`fakepass-zz`·`fakedb`·`port=1`·`SQLSTATE`·`PDOException` 0건
  - `GET /api/me` → 500, 본문 `{"error":"서버 오류"}` (다른 창구는 전과 같이)
  - 끝나면 내장 서버 kill, 임시 폴더 삭제(trap).
(c) `scripts/security_lint.sh` exit 0 · 바뀐 PHP 세 파일 `php -l` · `bash -n tests/health.sh` · 회귀: `tests/totp_time_sync.sh` 와 `tests/changelog.sh` 는 **실행하지 않고**(로그인이 필요·시간이 길다) 대신 그 두 스크립트가 health 에서 보는 조건(CORS 0·버전 0)을 (a) 에서 직접 확인했음을 보고에 적는다.

## 시험 규칙 (CLAUDE.md 3장·4장)
- 요청은 전부 `-A "tbox-review/$(date +%F)"`. 로그인이 필요한 요청은 없다 — 시드·qa 계정 어느 것도 쓰지 않는다.
- 운영 데이터·`.env`·nginx·php-fpm·PostgreSQL 에 손대지 않는다. 이상을 보면 멈추고 보고한다.
- 라이브 8082 로 (a) 를, 임시 내장 서버로 (b) 를 실행하고 **명령과 출력 원문**을 보고에 붙인다.
- ARCHITECTURE.md 13장 체크리스트를 완료 전에 스스로 점검한다.

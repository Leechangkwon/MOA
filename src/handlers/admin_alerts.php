<?php
declare(strict_types=1);

/**
 * src/handlers/admin_alerts.php — GET /api/admin/alerts
 *                                 (정책: role:Admin + 재인증, PRD F-24)
 *
 * 요구사항: PRD F-24 · 8장 `GET /api/admin/alerts` 행 · 10장 「관리자 허브」 · 7.11,
 *           ARCH 5.14 · 11.4
 *
 * `scripts/alerts.php`(root, 5분마다 cron)가 남기는 **경보 로그의 마지막 50줄**을 읽어
 * 그대로 돌려준다. 한 줄이 JSON 하나이며 모양은 `cli_raise_alert()` 가 정한다:
 *   `{raised_at, rule, evidence:{…}, dispatch, request_id, origin, title}`
 *
 * F-69 (PRD F-69 ③, ARCH 5.14) — 줄의 `origin`·`title` 을 **그대로** 내려 준다. 판정은 배치가 이미 했다
 * (src/Origin.php) — 이 창구는 다시 판정하지 않는다. 2026-09-24 전의 옛 줄에는 두 키가 없으므로 **null**
 * 이고, 화면은 그 줄을 「출처 표기 전」으로 보인다(옛 줄은 고치지 않는다 — alerts.log 는 증거다).
 * origin 은 넷(Origin::VALUES) 중 하나일 때만 내려 보내고, 그 밖의 글자는 null 로 떨어뜨린다 — 로그 파일은
 * 배치만 쓰지만, 모르는 값이 화면 배지를 고르게 하지 않는다. `evidence.origin_hint`(partial)는 근거 안에 그대로 있다.
 *
 * 지키는 것
 *   · **읽기만 한다.** 이 창구에는 쓰기도 지우기도 없다 — 경보 로그는 배치가 쌓는 증거이고
 *     화면에서 지울 수 있으면 증거가 아니다 (PRD S-07 과 같은 원칙).
 *   · 경로에 **요청 값이 한 글자도 들어가지 않는다**. 경로는 감사 로그 위치(`AUDIT_LOG_FILE`)의
 *     디렉터리 + 고정 파일명 `alerts.log` 이며, 경보를 쓰는 쪽(`scripts/lib` 의 cli_raise_alert)과
 *     **같은 방식으로 만든다** — 두 곳이 어긋나면 "경보는 쌓이는데 화면은 비어 있다" 가 된다.
 *   · 파일이 **없으면 빈 배열**이다 (오류가 아니다 — 아직 한 번도 발동하지 않은 상태는 정상).
 *     읽지 못하면(권한 등) 역시 빈 배열 + `readable:false` 로 알린다 — 화면이 "경보 0건" 과
 *     "못 읽었다" 를 구분할 수 있어야 하기 때문이다.
 *   · **모양이 깨진 줄은 건너뛴다.** 한 줄이 잘못됐다고 화면 전체가 500 이 되면, 정작 봐야 할
 *     49줄을 못 본다. 건너뛴 줄 수는 `skipped` 로 알린다.
 *   · `evidence.command`(AL-9 의 sudo 명령)는 **200자에서 자른다.** 인자에 비밀값 조각이
 *     섞여 있을 수 있어 배치 쪽에서도 가리고 자르지만(al_scrub_command), 화면으로 나가는
 *     길에서 한 번 더 좁힌다 (ARCH 5.14).
 *   · 화면은 이 값을 **textContent 로만** 그린다 (PRD S-03) — 값의 출처가 서버 로그라도
 *     그 안에는 사용자가 정한 문자열(계정명·파일명)이 섞여 있다.
 *   · 조회는 감사 로그를 남기지 않는다 (다른 목록 창구와 같은 원칙).
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자 (Admin)
 * @var list<string>        $params 경로 변수 없음
 */

/** 화면에 보일 줄 수 (PRD 8장 "마지막 50줄") */
$alertLines = 50;

/** AL-9 의 명령 문자열을 화면으로 내보낼 때의 상한 (ARCH 5.14) */
$alertCommandChars = 200;

/** F-69 — title 을 화면으로 내보낼 때의 상한(규칙 문장 + `[Claude] ` 는 이보다 짧다) */
$alertTitleChars = 300;

/** F-69 — 줄의 origin 으로 받아 주는 값 (Origin::VALUES 와 같은 넷). 웹 bootstrap 은 Origin.php 를 싣지 않는다 */
require_once dirname(__DIR__) . '/Origin.php';

// 경로는 코드와 설정에서만 만들어진다 — 요청 값이 섞이는 자리가 없다.
$alertPath = dirname(Env::get('AUDIT_LOG_FILE')) . '/alerts.log';

$alerts  = [];
$skipped = 0;
// 파일이 있고 읽을 수 있나 (없으면 "아직 경보가 없다", 못 읽으면 화면에 그대로 알린다)
$readable = is_file($alertPath) && is_readable($alertPath);

if ($readable) {
    $lines = file($alertPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        $readable = false;
        $lines    = [];
    }
    $lines = array_slice($lines, -$alertLines);

    foreach ($lines as $line) {
        $row = json_decode($line, true);
        if (!is_array($row) || !isset($row['rule'], $row['raised_at'])
            || !is_string($row['rule']) || !is_string($row['raised_at'])
        ) {
            $skipped++;
            continue;
        }

        $evidence = isset($row['evidence']) && is_array($row['evidence']) ? $row['evidence'] : [];
        // AL-9 의 명령만 다시 자른다(다른 값은 배치가 만든 숫자·계정명·시각이다).
        if (isset($evidence['command']) && is_string($evidence['command'])
            && iconv_strlen($evidence['command'], 'UTF-8') > $alertCommandChars
        ) {
            $evidence['command'] = (string) iconv_substr(
                $evidence['command'], 0, $alertCommandChars, 'UTF-8'
            );
        }

        // F-69 — 옛 줄(키 없음)·모르는 값은 null. 화면은 null 을 「출처 표기 전」으로 보인다.
        $origin = isset($row['origin']) && is_string($row['origin']) && Origin::isValue($row['origin'])
            ? $row['origin']
            : null;
        $title = isset($row['title']) && is_string($row['title'])
            ? (string) iconv_substr($row['title'], 0, $alertTitleChars, 'UTF-8')
            : null;

        $alerts[] = [
            'raised_at' => (string) $row['raised_at'],
            'rule'      => (string) $row['rule'],
            'dispatch'  => isset($row['dispatch']) && is_string($row['dispatch'])
                ? (string) $row['dispatch']
                : null,
            'evidence'  => $evidence,
            'origin'    => $origin,
            'title'     => $title,
        ];
    }
}

// 최근 것이 위로 오게 뒤집는다 (파일은 오래된 줄이 위다).
$alerts = array_reverse($alerts);

Http::json([
    'alerts'   => $alerts,
    'skipped'  => $skipped,
    // "경보 0건" 과 "로그를 읽지 못했다" 를 화면이 구분할 수 있게 하는 값이다.
    'readable' => $readable,
]);

<?php
declare(strict_types=1);

/**
 * scripts/changelog_md.php — `CHANGELOG.json` 을 사람이 읽는 `CHANGELOG.md` 로 옮겨 적는다
 * (PRD F-47, ARCHITECTURE 5.23)
 *
 * 사용법
 *   php scripts/changelog_md.php > CHANGELOG.md
 *
 * 왜 있나
 *   이력의 진실은 `CHANGELOG.json` 한 벌이다. 사람이 읽는 파일을 손으로 따로 쓰면 언젠가
 *   한쪽만 고쳐진다 — 그래서 `CHANGELOG.md` 는 **생성물**이고, `scripts/release.sh` 가
 *   발행 때마다 이 명령으로 다시 만든다. 시험(T-59 (a))은 두 파일이 byte 로 같은지 본다.
 *
 * 결정적 출력
 *   같은 입력이면 언제나 같은 바이트가 나온다. 시각·난수·파일 순서에 기대지 않는다 —
 *   그래야 「손으로 고쳤나」를 diff 하나로 물을 수 있다.
 *
 * DB 에 붙지 않는다
 *   이 명령이 읽는 것은 저장소 파일 두 개뿐이라 `scripts/lib/cli_bootstrap.php`(DB 연결·감사)를
 *   쓰지 않는다. `scripts/totp_code.php` 와 같은 자리다 — 클래스 한 벌만 읽어 온다.
 *
 * 웹으로는 실행되지 않는다 — public/ 밖에 있고(URL 에 없음), 아래에서 SAPI 도 확인한다.
 *
 * 종료 코드: 0 정상 · 1 이력이 비어 있어 만들 것이 없음
 */

if (php_sapi_name() !== 'cli') {
    // 웹에서 도달할 경로가 없지만, 만들어지더라도 실행되지 않게 한다 (심층 방어).
    http_response_code(404);
    exit(1);
}

require_once dirname(__DIR__) . '/src/Changelog.php';

const TBOX_MD_HEAD = '# 변경 이력 (CHANGELOG)';
const TBOX_MD_NOTE = '> 이 파일은 `CHANGELOG.json` 에서 `scripts/changelog_md.php` 가 만든다. '
    . '손으로 고치지 않는다. 번호 규칙: 앞자리 대규모 · 중간 중규모 · 끝자리 수정.';

$entries = Changelog::entries();
if ($entries === []) {
    fwrite(STDERR, "CHANGELOG.json 에 항목이 없습니다 — 만들 것이 없습니다.\n");
    exit(1);
}

$out = TBOX_MD_HEAD . "\n\n" . TBOX_MD_NOTE . "\n";

foreach ($entries as $entry) {
    // 항목 사이에 빈 줄 하나. 제목 줄은 `## v1.0.0 — 2026-09-15 18:30 · 대규모`.
    $out .= "\n## v" . $entry['version']
          . ' — ' . $entry['released_at']
          . ' · ' . Changelog::levelLabel($entry['level']) . "\n\n";
    $out .= '**' . $entry['title'] . "**\n\n";
    foreach ($entry['changes'] as $change) {
        $out .= '- ' . $change . "\n";
    }
}

// 파일 끝 개행은 위 반복이 이미 하나 남겼다 — 더 붙이지 않는다.
echo $out;
exit(0);

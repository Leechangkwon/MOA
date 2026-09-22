# F-47 구현 지시서 — 버전 관리 `V1.1.1` · 「변경 이력」 모달 · 발행 스크립트 (Fable → Opus, 2026-09-15)

```
목표        : 저장소 루트 VERSION·CHANGELOG.json 을 진실로 삼아, 로그인한 화면의 사이드바에 현재 버전을 보이고
             누르면 「변경 이력」 모달이 열리게 하며, 발행(번호 올림·이력 추가·커밋·태그·push)을 scripts/release.sh 한 번으로 만든다.
요구사항    : PRD F-47 · 12.2 T-59 · S-03(출력 이스케이프) · S-09,  ARCHITECTURE 5.23 (전부 읽고 시작), 5.19(「사용 방법」 모달 — 뼈대를 그대로 따른다)
수정 파일   : (허용 목록 — 여기 없는 파일은 만들거나 고치지 않는다)
             src/Changelog.php                (새 파일)
             src/views/shell_top.php          (사이드바 버전 단추 한 덩이만)
             src/views/shell_bottom.php       (help-dialog 아래에 changelog-dialog 한 덩이만)
             public/assets/app.js             (열기/닫기 — help 모달 방식과 같게, 한 덩이)
             public/assets/app.css            (.sidebar__version · .changelog-dialog 스타일만)
             scripts/release.sh               (새 파일)
             scripts/changelog_md.php         (새 파일)
             tests/changelog.sh               (새 파일)
             CHANGELOG.md                     (scripts/changelog_md.php 출력으로 생성 — 손으로 쓰지 않는다)
             ※ VERSION · CHANGELOG.json 은 Fable 이 이미 만들었다. 내용은 고치지 않는다(형식만 읽는다).
             ※ public/index.php · login_page.php · health.php · time.php · Auth/Authz/Db 는 손대지 않는다. 새 라우트 없음.
금지 패턴   : SQL 문자열 결합, innerHTML, 사용자 입력이 들어간 파일 경로, curl_exec/file_get_contents('http…'),
             역할 판단을 Authz 밖에서 직접 하는 코드, 요청 파라미터의 role 값 사용,
             git add -A (스크립트 안에서도), 로그인 전 화면·health·time 에 버전 노출
완료 기준   : PRD 12.2 T-59 (a)~(e) 전부 — tests/changelog.sh 로 실행하고 출력 원문을 보고한다.
             + scripts/security_lint.sh 0건 · 바뀐 PHP 전부 php -l · bash -n scripts/release.sh · tests/help_button.sh 회귀.
보고 형식   : 변경 파일 목록 / 실행한 명령과 출력 원문 / 못 한 것과 이유
```

## 1. 자료 형식 (이미 있음 — 읽기만)

- `VERSION`: 한 줄 `1.0.0` (끝 개행 하나).
- `CHANGELOG.json`: 최상위 **배열**, 최신이 첫 항목. 항목 = `version`(`^\d+\.\d+\.\d+$`) · `level`(`major|minor|patch`) ·
  `released_at`(`YYYY-MM-DD HH:MM`, 서버 KST) · `title`(문자열) · `changes`(문자열 배열, 1개 이상).

## 2. `src/Changelog.php`

- `final class Changelog` — `public static function version(): string` (VERSION 을 trim 해 형식 검사, 틀리면 `'0.0.0'` 과 `error_log`),
  `public static function entries(): array` (JSON 을 읽어 **형식이 맞는 항목만** 배열로, 틀린 항목은 버리고 `error_log` 한 줄; 파일이 없거나 JSON 이 깨지면 빈 배열).
  `public static function levelLabel(string $level): string` — `major`→「대규모」, `minor`→「중규모」, `patch`→「수정」, 그 외 「」.
- 요청마다 두 파일을 읽어도 된다(작다). 정적 캐시를 두면 같은 요청 안에서만.
- 경로는 `__DIR__ . '/../VERSION'` 처럼 **고정 상수**만(사용자 입력 0).

## 3. 화면

- `shell_top.php`: `<div class="sidebar__spacer"></div>` 와 `.userchip` 사이에
  `<button type="button" class="sidebar__version" data-changelog-open title="변경 이력" aria-haspopup="dialog" aria-controls="changelog-dialog">v<?= htmlspecialchars(Changelog::version(), …) ?></button>`
  한 덩이. 셸을 그리는 모든 화면(로그인 뒤)에 나온다. **로그인 전 화면에는 없다**(login_page.php 는 셸을 안 쓴다 — 확인만).
- `shell_bottom.php`: `#help-dialog` 블록 **아래**, `<main>` 밖에 `<dialog id="changelog-dialog" class="modal changelog-dialog" aria-labelledby="changelog-title">`.
  머리: 제목 「변경 이력」 + X 닫기(`data-changelog-close`). 본문: 항목마다
  `<section class="changelog__entry">` → `<h3>` 에 `v1.0.0` + `<span class="badge">대규모</span>` + `<time>2026-09-15 18:30</time>`, `<p class="changelog__title">제목</p>`, `<ul>` 변경 문장들.
  발: 「닫기」 단추. **모든 값 `htmlspecialchars(…, ENT_QUOTES, 'UTF-8')`.** 항목이 0개면 「기록이 없습니다」 한 줄.
  help-dialog 가 `$pageHelp` 조건 안에 있으면 changelog-dialog 는 그 조건 **밖**에(항상 그린다).
- `app.js`: help 모달의 열기/닫기(6537행 근처 `[data-help-open]`)와 **같은 방식**으로 `[data-changelog-open]`/`[data-changelog-close]` 를 처리한다.
  `showModal()` 없는 브라우저면 `open` 속성으로 대체(기존 모달 코드가 이미 하는 방식이 있으면 그대로). 배경 클릭 닫기도 기존 공통 함수가 있으면 재사용.
- `app.css`: `.sidebar__version` — 사이드바 글자색보다 옅은 회색, 12px, monospace 계열(기존 `.mono` 변수가 있으면 그것), 배경 없음, 왼쪽 정렬, hover 시 밑줄.
  `.changelog-dialog` — `help-dialog` 폭과 같게, 항목 사이 구분선, `time` 은 옅은 회색. 768px 이하에서 사이드바 접힘 규칙(1076행 근처)에 맞춰 버전 단추도 같은 처리.
- 화면 문구 규칙(ARCH 5.19): 모달·단추에 「서버가…」「감사 로그」 같은 구조 설명 문구를 넣지 않는다. 제목은 「변경 이력」 하나.

## 4. `scripts/changelog_md.php`

- CLI 전용(`php_sapi_name() === 'cli'` 아니면 종료). `Changelog::entries()` 를 써서 표준 출력에 마크다운:
  ```
  # 변경 이력 (CHANGELOG)

  > 이 파일은 `CHANGELOG.json` 에서 `scripts/changelog_md.php` 가 만든다. 손으로 고치지 않는다. 번호 규칙: 앞자리 대규모 · 중간 중규모 · 끝자리 수정.

  ## v1.0.0 — 2026-09-15 18:30 · 대규모

  **제목**

  - 변경 1
  - 변경 2
  ```
  항목 사이 빈 줄 하나, 파일 끝 개행 하나. 결정적 출력(같은 입력 → byte 동일).
- 처음 한 번 실행해 `CHANGELOG.md` 를 만든다.

## 5. `scripts/release.sh`

```
사용: scripts/release.sh <major|minor|patch> "<제목>" "<변경 1>" ["<변경 2>" …] [--no-push] [--dry-run]
      scripts/release.sh --bump <X.Y.Z> <major|minor|patch>     # 번호 계산만 출력(시험용)
```
- `set -euo pipefail`. 저장소 루트는 스크립트 위치 기준.
- 순서는 ARCH 5.23 그대로: ⓪ `git status --porcelain` 에 `??` 아닌 줄이 있으면 「먼저 커밋하세요」 후 종료 1 → ① 다음 번호(major: X+1.0.0 / minor: X.Y+1.0 / patch: Z+1), `git rev-parse -q --verify refs/tags/vX.Y.Z` 있으면 종료 1 → ② `php` 로 JSON 맨 앞에 항목 추가(`JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT`, 끝 개행) — 제목·변경 문장은 **인자로만**, 셸 문자열 결합으로 JSON 을 만들지 않는다(php 에 `$argv` 로 넘긴다) → ③ `VERSION` 쓰기 · `php scripts/changelog_md.php > CHANGELOG.md` → ④ `git add VERSION CHANGELOG.json CHANGELOG.md` · `git commit -m "vX.Y.Z: 제목"` (본문에 변경 문장 목록 + `Co-Authored-By: Claude Fable 5.1 <noreply@anthropic.com>`) · `git tag -a vX.Y.Z -m "vX.Y.Z — 제목"` → ⑤ `git push origin main` · `git push origin vX.Y.Z` (`--no-push` 면 건너뛰고 안내).
- `--dry-run`: 파일·git 을 하나도 건드리지 않고 「지금 → 다음 번호, 만들 항목(JSON 미리보기), 실행할 git 명령」만 출력.
- 변경 문장이 0개면 거부. 제목·문장에 `<` 가 있으면 거부(화면에 글자로 나오지만 규칙상 넣지 않는다).
- 출력 마지막 줄: `발행 완료: vX.Y.Z (커밋 <해시>, 태그 push 완료|생략)`.
- 이 스크립트는 **이번 구현에서 실행하지 않는다**(발행은 Fable 이 검토 뒤에 한다). `--dry-run`·`--bump` 만 시험한다.

## 6. `tests/changelog.sh` — T-59

기존 시험 스크립트(`tests/help_button.sh`·`tests/stage26.sh`)의 `expect`·로그인·`-A "tbox-review/$(date +%F)"`·qa 계정 규칙을 그대로 따른다. **데이터를 만들지 않는다**(GET 뿐).
(a) 자료 / (b) 화면(qa_admin01 셸 · `/login` 로그인 전 · `/api/health` · `/api/time`) / (c) `--bump 1.2.3 major|minor|patch` = `2.0.0|1.3.0|1.2.4`, 더러운 트리 거부(임시로 `git stash` 하지 말고 — **작업 트리를 건드리지 않는 방법**으로: `git -C` 로 임시 클론(`git clone -q . /tmp/…`)을 만들어 그 안에서 파일 하나 수정 후 `--dry-run` 이 종료 1 인지 확인, 시험 뒤 임시 클론 삭제) / (d) Playwright `channel=chromium`, 1440 — `scripts/ui_snapshot.py` 의 로그인 방식을 재사용, 단추 클릭 → `#changelog-dialog[open]` · 첫 항목 텍스트 · ESC 닫힘 · 스크린샷 `~/.claude/ui-shots/changelog/` ; 로그인 화면 스크린샷·본문에 `v1.0.0` 0 / (e) 린트·`php -l`·`bash -n`·`tests/help_button.sh`.
마지막에 `PASS/FAIL 합계`. 실행: `bash tests/changelog.sh`.

## 7. 주의

- 운영 데이터·DB 에 손대지 않는다(CLAUDE.md 4장). 이 기능은 DB 를 쓰지 않는다.
- 루트 디스크가 92% 다 — 스크린샷은 `~/.claude/ui-shots` 에, 임시 클론은 시험 뒤 반드시 삭제.
- qa 계정 OTP 는 같은 30초 안 재사용이 거부된다 — 로그인은 시험 안에서 한 번만.
- 커밋은 하지 않는다(Fable 이 검토 뒤 커밋·발행).

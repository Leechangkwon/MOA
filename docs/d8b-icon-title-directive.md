# 지시서 — D-3 마무리: 서버 HTML 의 아이콘 단추 `title` 11개 제거

CLAUDE.md 2장 형식. 작성 Fable 2026-09-22. 구현 Opus. d8 지시서(app.js)에서 빠진 서버 마크업 쪽.

```
목표        : 아이콘 단추(.btn--icon)에 title 과 aria-label 이 둘 다 있어 브라우저 기본 툴팁과 CSS 툴팁이 겹치는 것을
              없앤다 — aria-label 만 남긴다 (DESIGN.md 2.1 · 2.8 · 7장 D-3).
요구사항    : DESIGN.md 2.1(아이콘 단추는 aria-label 만) · 2.8(툴팁은 aria-label 에서). CSS 는 고치지 않는다.
수정 파일   : src/views/shell_top.php, src/views/shell_bottom.php, src/handlers/home.php, src/handlers/admin_fetch_hosts_page.php  (이 넷만)
금지 패턴   : id · data-* · aria-label · 문구 변경, 인라인 script/style/on*, innerHTML.
              **아이콘 단추가 아닌 요소의 title 은 건드리지 않는다** — `.sidebar__version`(버전 단추, title="변경 이력")·배지·<iframe>·<abbr> 등.
완료 기준   : 아래 확인 1~3 실행, 원문 첨부.
보고 형식   : 바뀐 줄 before → after / 명령과 출력 원문 / 못 한 것
```

바꾸는 법 — `class="btn btn--icon …"` 인 `<button>` 에서 ` title="…"` 속성만 지운다(같은 값의 `aria-label` 은 그대로):
- `shell_top.php` 198(메뉴 열기) · 299(로그아웃)
- `shell_bottom.php` 66 · 106 (모달 머리 ✕ 「닫기」)
- `home.php` 201 · 277 · 321 · 369 · 398 · 455 · 513 (모달 머리 ✕ 「닫기」 7개)
- `admin_fetch_hosts_page.php` 114 (모달 머리 ✕)

확인 (검토 계정 qa_admin01 · `-A "tbox-review/$(date +%F)"`):
1. `php -l` 네 파일 · `grep -nE 'btn--icon[^>]*title=' src/views/*.php src/handlers/*.php` → 0건 · `grep -c 'title="변경 이력"' src/views/shell_top.php` → 1(그대로)
2. 로그인 뒤 Playwright 1440 `/`·`/admin/fetch-hosts`: `document.querySelectorAll('.btn--icon[title]').length` → 0, `.btn--icon:not([aria-label])` → 0, 모달 ✕ 에 마우스 올려 CSS 툴팁(`::after` content 「닫기」)이 보이는지 스크린샷 한 장
3. `scripts/ui_snapshot.sh <폴더> 1440` → 이전과 같은 결함 수, console 0

git 명령은 읽기 전용만. app.css 는 고치지 않는다. 임시 파일은 scratchpad/d8b/ 에.

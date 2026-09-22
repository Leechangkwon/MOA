# 지시서 — C-8 마무리: 남은 `alert-list` 컨테이너 세 곳 (DESIGN.md 9장 C-8)

CLAUDE.md 2장 형식. 작성 Fable 2026-09-22. 구현 Opus. d7 지시서의 수정 허용 목록에 두 파일이 빠져 있어 남은 것.

```
목표        : 「변경 미리보기」 목록 세 개의 <ul> 이 화면 전용 클래스 alert-list 대신 공통 .list.list--scroll 을 쓴다.
요구사항    : DESIGN.md 2.13(줄 목록) · 9장 C-8. CSS 는 고치지 않는다.
수정 파일   : src/handlers/admin_permissions_page.php, src/handlers/admin_fetch_hosts_page.php  (이 둘만)
금지 패턴   : id · data-* · 문구 변경, 인라인 script/style/on*, innerHTML
완료 기준   : 아래 확인 1~3 실행, 원문 첨부.
보고 형식   : 변경 줄 before → after / 실행한 명령과 출력 원문 / 못 한 것
```

바꾸는 법 — 세 줄, 클래스만:
- `admin_permissions_page.php` 79행 `<ul id="perm-preview" class="alert-list">` → `class="list list--scroll"`
- `admin_fetch_hosts_page.php` 93행 `<ul id="fetch-hosts-preview" class="alert-list">` → `class="list list--scroll"`
- `admin_fetch_hosts_page.php` 119행 `<ul id="fetch-hosts-dialog-list" class="alert-list">` → `class="list list--scroll"`

확인 (검토 계정 qa_admin01 · `-A "tbox-review/$(date +%F)"`):
1. `php -l` 두 파일 · `grep -rn 'alert-list' src public --include=*.php --include=*.js` → 0건
2. 로그인 + 재인증 뒤 `curl … /admin/permissions | grep -c 'list list--scroll'` → 1, `curl … /admin/fetch-hosts | grep -c 'list list--scroll'` → 2
3. `UI_PAGES="/admin/permissions,/admin/fetch-hosts" bash scripts/ui_snapshot.sh <폴더> 1440` → console=0, clipped/overlap 0

git 명령 금지(읽기 전용 diff/status 만). app.css 는 고치지 않는다.

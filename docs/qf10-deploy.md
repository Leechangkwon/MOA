# Q:F-10 반영 절차 (2026-09-29) — 토큰 하나로 · 커뮤니티 검색·읽기 창구 · MCP 검색 · 변경 이력 관리자만

검토: `review-qf10-2026-09-29.md`(독립 검토 · 공격 성공 0 · RV-QF10-1~4 — 1·3·4 반영, 2 사용자 결정 대기). 지시서 `docs/qf10-multi-scope-directive.md`. 기획 `docs/messenger-plan.md`(Q:F-11 메신저 — 별도).

**순서가 Q:F-9 와 반대다: 코드(저장소 패치) → 마이그레이션.** 새 코드는 옛 범위 값 다섯을 그대로 읽지만, 옛 코드는 마이그레이션이 만드는 `community.read+community.write` 를 모른다(옛 코드 + 새 DB = 커뮤니티 글쓰기 토큰 403 — 반영 스크립트 연습 ㉑ 실측). 그 사이에 막히는 것은 「범위를 묶어 발급」뿐이다. 되돌리기는 반영의 거꾸로(DB → 코드).

## 서버(changkwon · `/var/www/qaredi/tbox-v2`)

```
cd /var/www/qaredi/tbox-v2 && git fetch origin wip/qf10
X=$(mktemp -d) && git archive origin/wip/qf10 review-scripts db/migrations/2026-10-13_api_tokens_multi_scope.sql | tar -x -C "$X"
git diff b2ee066 review-fix/qf10 -- . ':!review-scripts' > "$X/qf10.patch"
sha256sum "$X/qf10.patch"           # 반영 스크립트의 PATCH_WANT 와 같아야 한다
bash "$X/review-scripts/apply-qf10-repo-2026-09-29.sh" "$X/qf10.patch" <그 sha256> --dry-run
bash "$X/review-scripts/apply-qf10-repo-2026-09-29.sh" "$X/qf10.patch" <그 sha256>
```
끝에 찍히는 ① 커밋 명령 → ② `scripts/release.sh minor …` 를 그대로 붙여 넣는다. 그 다음 DB:
```
sudo bash "$X/review-scripts/apply-qf10-db-2026-09-29.sh" --dry-run "$X/db/migrations/2026-10-13_api_tokens_multi_scope.sql"
sudo bash "$X/review-scripts/apply-qf10-db-2026-09-29.sh" --migrate "$X/db/migrations/2026-10-13_api_tokens_multi_scope.sql"
git push origin main
```
확인: `sudo -u postgres psql -d tbox_re -c "SELECT scope, count(*) FROM api_tokens WHERE revoked_at IS NULL GROUP BY 1"` 에 `community.write` 한 원자 행이 0.

사이트 문서(상위 폴더 · Q:F-9 사이트 패치가 먼저 적용돼 있어야 한다):
```
cd /var/www/qaredi && git apply --check tbox-v2/docs/qf10-site-docs-2026-09-29.patch && git apply tbox-v2/docs/qf10-site-docs-2026-09-29.patch \
  && git add PRD.md STAGES.md community/messenger-plan.md && git commit -m 'Qaredi Q:F-10·Q:F-11: PRD·STAGES — 토큰 하나로 · 메신저 기획안'
```

## PC(비서)

1. `cd C:\vibe\tbox-buddy; git fetch origin; git checkout wip/qf10; git pull origin wip/qf10` → 비서 완전 종료 후 다시 실행.
2. 트레이 → 「서버 주소 변경」 → 값이 `https://changkwon.mso.so/tbox` 처럼 **`/tbox` 까지** 인지 확인(아니면 고친다 — 이번 판부터 넘김(redirect)을 따르지 않는다).
3. 회사 PC 브라우저 → 내 계정 → 프로그램 토큰 → 발급 → 체크박스 **읽기 · 올리기 · 커뮤니티 읽기 · 이 PC 로그인**(지우기·커뮤니티 쓰기는 필요할 때만) → 「묶인 IP」 확인 → 토큰은 화면에서 복사만.
4. 트레이 → 「API 토큰 변경」 에 넣기 → 알림 「API 토큰 범위 — 파일 · PC 로그인 · 커뮤니티」 → 「이 PC 로그인 전용 토큰 지우기」(옛 칸 비움).
5. 옛 토큰 셋(파일용 · 로그인 전용 · MCP 글쓰기용) 회수. MCP 는 새 토큰에 「커뮤니티 쓰기」 를 포함했으면 그 값으로 `QAREDI_TOKEN_FILE` 갱신.
6. 시험: 탐색 창 목록 · Ctrl+B · 팔레트 Ctrl+F → `#검색어` → Enter 로 글 열림 · 다른 회선(휴대폰 테더링)에서 파일 목록 → 「묶인 인터넷 주소 밖」 안내.

## 관문 ③ (사용자 자기 공격 후보)

- 발급 폼에서 「지우기」 만 체크 → 읽기·올리기가 자동으로 켜지나 · 「이 PC 로그인」 체크 시 IP 안내 문장.
- 커뮤니티 읽기 원자만 든 토큰으로 글 쓰기 → 403 · 남의 비공개 글 검색 → 결과 없음.
- 일반 사용자 계정으로 변경 이력 창 → 제목만.
- 다른 IP 에서 묶음 토큰으로 파일 목록 → 401.

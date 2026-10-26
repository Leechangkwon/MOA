# Q:F-15 S3 반영 순서 — 과업 길잡이 1차 (2026-10-26 준비 · **사용자가 「반영해」라고 할 때만** 돈다)

지시서 `docs/qf15-s3-guide-directive.md` 「반영 · 발행」 절을 명령 차례로 옮긴 것이다. 도구는 2단계 반영(v2.75.0)에 쓴 것을 경로만 바꿔 쓴다 — 원본 `/home/changkwon/qaredi-qf15s2-results-20261026/deploy/`(`backup.sh` · `parse-backup.py` · `mig_check.py` · `deploy.py` · `prod_check.py`). 새 결과 폴더는 `/home/changkwon/qaredi-qf15s3-results-20261026/deploy/`.

**이 파일을 만들 때(2026-10-26) 한 것은 읽기뿐이다** — 백업 · 모의 적용 · 합치기 · 운영 DB 마이그레이션 · reload · `release.sh` · push 0. 모의 적용은 반영 직전의 새 덤프로 해야 의미가 있어 지금 하지 않았다.

| 값 | 지금(2026-10-26) |
|---|---|
| 운영 트리 | `/var/www/qaredi/tbox-v2`(main) — 가지를 만든 `4997871` 뒤 **다른 세션의 문서 커밋 하나 `090bfb8`**(SECURITY.md 끝 「10-22 01:52 로그인」 한 단락) · 깨끗함 · `origin/main` = `08ab62e`(로컬 main 이 문서 커밋 셋 앞 `55fdb1f` · `4997871` · `090bfb8` — push 0) |
| 후보 | 가지 `feat/qf15-s3-guide`(작업 트리 `/home/changkwon/qaredi-qf15s3-20261026`) — 코드 최종 판 `804d9a5`(브라우저 수락 307/0 · 그 뒤 문서 커밋만) |
| 합치기 | **fast-forward 아님** — 둘 다 `SECURITY.md` 를 고쳤지만 자리가 다르다(main: 파일 끝 · 가지: 7장 첫 줄). 3자 병합 `git merge-tree 4997871 main feat/qf15-s3-guide` 충돌 표시 0 → 0 단계에서 가지를 main 위로 다시 쌓는다(문서만 달라 시험 다시 0 · 정적만) |
| 보안 핵심 | `db/migrations/2026-10-27_qf15_s3_guide_journeys.sql`(새) · `db/community/grants.sql`(IF 블록 하나) — 그 밖 `db/` · `deploy/` · `public/index.php` · `src/Auth*.php` 등 0 |
| 버전 | 지금 `2.75.0` → minor `2.76.0` |
| 서비스 | S2 선례: 설정 · 재시작 0(opcache `validate_timestamps On` · `revalidate_freq 2`) |

## 0. 사전 확인 · 가지를 main 위로

```bash
# main 이 움직였으면(2026-10-26 기준 090bfb8) 가지를 그 위로 다시 쌓는다 — 다른 세션의 SECURITY.md 단락은 그대로 남는다
git -C /home/changkwon/qaredi-qf15s3-20261026 rebase main          # 충돌이 나면 멈추고 보고(2026-10-26 3자 병합 대조는 0)
git -C /home/changkwon/qaredi-qf15s3-20261026 diff --stat main -- SECURITY.md   # 가지의 S3 검토 줄만 더해졌는지
(cd /home/changkwon/qaredi-qf15s3-20261026 && bash scripts/security_lint.sh | tail -1 && git diff --check main HEAD && echo clean)
git -C /var/www/qaredi/tbox-v2 status --porcelain            # 빈 줄이어야
git -C /var/www/qaredi/tbox-v2 rev-parse --short HEAD          # = 위에서 다시 쌓은 기준(main) — 다르면 멈추고 대조부터
git -C /home/changkwon/qaredi-qf15s3-20261026 status --porcelain
git -C /home/changkwon/qaredi-qf15s3-20261026 merge-base --is-ancestor main HEAD && echo ff-ok
git -C /home/changkwon/qaredi-qf15s3-20261026 diff --name-only main HEAD | grep -E '^(db/|deploy/|public/index\.php$|src/(Audit|Authz|Auth|Db|Http|Storage|Upload|Passkey)\.php$|\.env)'
#   → 정확히 두 줄: db/community/grants.sql · db/migrations/2026-10-27_qf15_s3_guide_journeys.sql
df -h /var/www /var/backups                                    # 3GB 이상
sudo -n ls /var/backups/tbox-v2/ | grep moa-                   # 반영 전 백업 셋 넘으면 11 단계에서 가장 오래된 것 내보내기
```

## 1. 반영 전 백업 (DB + 코드 · 설정만 — 반영 전 백업 규칙)

`backup.sh` 를 S2 것에서 `backup=/var/backups/tbox-v2/moa-qf15-s3-<날짜>` 로만 바꿔 쓴다(pg_dump `-U tbox_backup -Fc --no-owner --no-privileges tbox_re` → gpg · 코드 · 설정 tar → gpg · `SHA256SUMS` · 전체 복호 시험).

```bash
sudo -n bash /home/changkwon/qaredi-qf15s3-results-20261026/deploy/backup.sh | tee /home/changkwon/qaredi-qf15s3-results-20261026/deploy/backup.log
python3 /home/changkwon/qaredi-qf15s3-results-20261026/deploy/parse-backup.py   # → backup-parse.json passed: true (덤프 해석 · tar 목록)
```
백업이 끝났는지(크기 · 복호 시험 · `passed`) 확인하기 전에는 2 단계로 가지 않는다.

## 2. 모의 적용 — 방금 만든 덤프를 복원한 임시 DB(운영 아님)

`mig_check.py` 를 S2 것에서 바꿔 쓴다(DDL 마이그레이션이라 확인 항목이 다르다):

1. `sudo -n -u postgres createdb -O tbox_owner tbox_s3_mock_<pid>` → 1 의 `db.dump.gpg` 를 복호해 `pg_restore --exit-on-error`(postgres — 확장은 관리자만 만든다).
2. **덤프가 `--no-owner` 라 임시 DB 의 스키마 소유자가 postgres 로 복원된다 → `ALTER SCHEMA community OWNER TO tbox_owner;`**(운영은 `2026-09-22_community_stage3.sql` 로 tbox_owner) — 그래야 파일 안 `SET LOCAL ROLE tbox_owner` 의 `CREATE TABLE` 이 운영과 같게 된다.
3. 마이그레이션을 postgres 로 **두 번**: `psql -X -v ON_ERROR_STOP=1 -d tbox_s3_mock_<pid> -f db/migrations/2026-10-27_qf15_s3_guide_journeys.sql`(파일 첫 문장이 `SET LOCAL ROLE tbox_owner` — S2 처럼 앞에 붙이지 않는다) → `db/community/grants.sql` 한 번(postgres).
4. 확인(전부 참이어야): `community.guide_journeys` 소유자 = `tbox_owner` · `has_table_privilege('tbox_app', …, 'SELECT'/'INSERT'/'UPDATE')` 참 · `'DELETE'` 거짓 · `has_table_privilege('tbox_backup', …, 'SELECT')` 참 · 부분 유니크 색인 `guide_journeys_one_active` 있음 · 두 번째 적용에서 오류 0 · 행 0 · **다른 표의 행 · 소유자/ACL 그대로**(S2 `snap_other` · `OQ` 비교 그대로) · `PGPASSFILE=/etc/tbox-v2/backup.pgpass pg_dump -h 127.0.0.1 -U tbox_backup -w --schema-only -t community.guide_journeys tbox_s3_mock_<pid>` 성공.
5. `dropdb tbox_s3_mock_<pid>` → 결과 `migration-preservation.json`(`passed: true` · 마이그레이션 파일 바이트가 후보와 같음).

## 3. 발행 판 (후보 가지에서 — S2 선례)

S2 는 `deploy.py` 가 **발행 태그를 ff 로 합쳤다**(화면의 판 번호 · 변경 이력이 코드와 같은 순간에 바뀜). 같은 방식이면 `release.sh` 를 여기서 돈다 — 사용자 순서의 마지막 「`release.sh minor` · 발행 문장」을 앞당긴 것이다. 사용자가 「반영 뒤 발행」을 원하면 이 단계를 10 다음으로 옮기고 운영 트리(main)에서 `--no-push` 로 돈다(그때는 판 번호가 몇 분 늦게 바뀐다).

```bash
cd /home/changkwon/qaredi-qf15s3-20261026
scripts/release.sh minor "하고 싶은 일 안내" \
  "모아 홈 「무엇을 하고 싶으세요?」에서 할 일을 고르면, 실제 화면을 넘어가며 눌러야 할 단추를 차례로 짚어 드려요 — 직접 눌러 끝까지 하면 완성돼요" \
  "화면 오른쪽 아래 「? 사용 안내」에서도 같은 질문으로 시작하고, 하던 일은 「이어 하기」로 다시 이어요" \
  "안내가 떠 있어도 화면을 가리지 않아 뒤의 단추를 그대로 눌러 쓸 수 있어요" \
  --no-push
git tag -l v2.76.0     # 태그 확인 · push 는 사용자가 정한다(S2 는 「후보 가지 push」를 드리프트 확인 조건으로 썼다 — deploy.py 의 그 assert 를 쓸지 같이 정한다)
```

## 4. 모의 반영 (`deploy.py --plan`)

S2 `deploy.py` 에서 `SOURCE=/home/changkwon/qaredi-qf15s3-20261026` · `OUT=…/qaredi-qf15s3-results-20261026/deploy` · `BACKUP=/var/backups/tbox-v2/moa-qf15-s3-<날짜>` · `OLD='<0 단계의 main 해시>'` · `VERSION='2.76.0'` · `BRANCH='feat/qf15-s3-guide'` · `MIGRATION='db/migrations/2026-10-27_qf15_s3_guide_journeys.sql'` 로 바꾸고, **허용 보안 핵심을 둘로**(`bad = [p for p in paths if FORBIDDEN.search(p) and p not in (MIGRATION, 'db/community/grants.sql')]`). 대시보드 표 전용 줄(`DASH` · `dash()` · 바뀔 행 저장)은 지운다.

```bash
python3 /home/changkwon/qaredi-qf15s3-results-20261026/deploy/deploy.py --plan
#   → FIXED_CANDIDATE_REHEARSAL_PASS <n> files · php -l · security_lint 0 on rehearsal tree
```

## 5. 적용 (`deploy.py --apply`) — 마이그레이션 → grants.sql → ff

`--apply` 차례(S2 그대로 + S3 바꿈):
1. 드리프트 확인(운영 HEAD == `OLD` · 깨끗함 · plan 같음 · 백업 · 모의 적용 `passed`) · `SHA256SUMS -c`.
2. `before` 스냅숏(기존 표 행 지문 · 소유자/ACL · 저장 파일 · 설정 파일).
3. **마이그레이션**: `sudo -n -u postgres psql -X -v ON_ERROR_STOP=1 -d tbox_re -f db/migrations/2026-10-27_qf15_s3_guide_journeys.sql`(파일 안 `SET LOCAL ROLE tbox_owner` — 앞에 `SET ROLE` 을 붙이지 않는다). 옛 코드는 새 표를 읽지 않으므로 코드보다 먼저 해도 화면 영향 0.
4. **`db/community/grants.sql` 다시**: `sudo -n -u postgres psql -X -v ON_ERROR_STOP=1 -d tbox_re -f db/community/grants.sql`(후보 판의 파일 — 되풀이 안전).
5. 운영 읽기 확인: 새 표 소유자 `tbox_owner` · 권한(앱 SIU · DELETE 0 · 백업 S) · 행 0 · 다른 표 행 지문 · 소유자/ACL 이 `before` 와 같음(**새 표 하나만 더해짐**) — 아니면 코드를 바꾸지 않고 멈춰 보고(복원은 사용자 판단).
6. `git merge --ff-only v2.76.0`(운영 트리) · 파일 메타(소유자 · 권한) 보존 · 지문 대조 · 보존 대조 → `deployment.json`.

```bash
python3 /home/changkwon/qaredi-qf15s3-results-20261026/deploy/deploy.py --apply
```

## 6. reload

S2 선례는 재시작 0(opcache 가 2초 안에 새 파일을 다시 읽는다). 새 PHP 파일(`GuideJourney.php` · 핸들러)과 라우트 한 줄이라 한 번 해도 무해 — 하려면:

```bash
sudo -n systemctl reload php8.1-fpm && systemctl is-active php8.1-fpm
```

## 7. 운영 확인 (claude_ 계정 · UA `tbox-review/<날짜>` · 읽기 + 진행 저장만 · 끝나면 로그아웃 — CSRF 머리 포함)

S2 `prod_check.py` 를 바꿔 쓴다. 확인할 것:
- `GET /community/api/me/guide` 200 · `journeys`(active null · paused [] · completed []) · `can` 여섯 키 — claude_employee01: task-add · file-share · minutes-write · message-send 참 · notice-post · invite-colleague 거짓 / claude_admin01: 여섯 참.
- `PATCH /community/api/me/journeys` — CSRF 머리 없음 403 · 세션 없이 개인 토큰 헤더만 401 · GET 405.
- 모아 홈(`/`): 과업 카드 줄 주소 — 직원 넷(`?guide=task-add` · `file-share` · `minutes-write` · `message-send`) · 관리자 여섯 · 「동료에게 한마디」 줄에 창 열기 속성 0 · 바로가기 「메신저」는 그대로.
- 자료실 `/tbox/?guide=file-share` → 진행 카드(dialog 아님) 1 · 2 단계까지 보이는지(브라우저) — **완성을 만드는 올리기 · 글은 운영에서 하지 않는다**(격리 수락이 잼) · 끝에 「나중에」(paused 로 남김).
- 「? 사용 안내」 → 질문 카드 「무엇을 하고 싶으세요?」 · 바닥 「이 화면 사용 안내」 → 옛 투어.
- 손님 검토 계정이 운영에서 약관 동의 전이면 홈이 동의 전 갈래로 나온다(S2 운영 확인의 실패 1 과 같은 사정 — 앱 결함 아님).

## 8. 초대 전 운영 준비 확인 (읽기만 — 소유자 몫 · 지시서 H)

```sql
-- sudo -n -u postgres psql -X -At -d tbox_re   (읽기만)
SELECT count(*) FROM community.templates WHERE scope = 'site' AND name = '회의록';          -- 1 이상이어야 회의록 과업이 끝까지 간다
SELECT count(*) FROM community.spaces WHERE kind = 'board' AND area = 'work' AND board_kind = 'notice' AND is_active; -- 1 이상
SELECT count(*) FROM public.users WHERE is_active AND team_id IS NULL;                   -- 팀이 없는 동료 수
SELECT enabled FROM public.member_ai_settings WHERE id = 1;                                -- 「AI 대화 사용」 참이어야
```
빠진 것을 사용자에게 알린다(만들지 않는다).

## 9. 밤 회귀 — hold → queue

```bash
python3 - <<'PY'
import json, pathlib
src = pathlib.Path.home() / '.cache/qaredi-check/nightly/hold/2026-10-26-qf15-s3.json'
d = json.loads(src.read_text())
d['deployed'] = '<5 의 NEW 해시 — v2.76.0 커밋>'
dst = pathlib.Path.home() / '.cache/qaredi-check/nightly/queue' / ('<반영 날짜>-qf15-s3.json')
dst.write_text(json.dumps(d, ensure_ascii=False, indent=2)); src.unlink()
print(dst)
PY
```

## 10. 기록

- `SECURITY.md` 7장 「Q:F-15 S3 운영 v2.76.0」 줄(백업 · 모의 적용 결과 · 마이그레이션 · grants · 보존 대조 · 운영 확인 n/m · 회귀: 밤 회귀 대기(12) · 관문 ③ 대기).
- 사이트 `STAGES.md` 0장 · 학습 기록 · `tbox-v2/STAGES.md` 「Q:F-15 S3」 표의 발행 · 운영 · 회귀 줄 · 위키 단계 글 · `php scripts/wiki_check.php` 0.
- 기억 `moa-identity-plan.md`.

## 11. 반영 전 백업 정리

서버의 `moa-*` 반영 전 백업이 셋을 넘으면 가장 오래된 것(`moa-qf15-s1-20261024`)을 `/home/changkwon/offsite-backups-<날짜>/` 로 옮기고 사용자에게 내려받기 · 대조 · 서버 삭제를 알린다(외장하드 사본은 만든 날부터 45일).

## 되돌리기 (사용자가 정할 때만 — 메인이 스스로 하지 않는다)

코드: 운영 트리를 `OLD` 로 되돌리는 것은 사용자 판단(새 표는 옛 코드가 읽지 않으므로 남겨 둬도 화면 영향 0). 표: `DROP TABLE community.guide_journeys` 는 쌓인 진행 · 완성 기록을 지운다 — 사용자가 정할 때만.

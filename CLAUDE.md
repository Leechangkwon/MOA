# CLAUDE.md — t-box 모듈 규칙 (Qaredi 의 한 모듈)

이 폴더는 **Qaredi 사이트의 t-box 모듈**(사내 파일 공유 재구축, 완성·운영 중)이자 Qaredi 의 **웹 앱 본체**다(런처 `/`·`/tbox/…` 라우터·공통 셸이 여기 있다). 원격 저장소 `T-Box_Re`.
**사이트 공통 지침은 상위 폴더 `../CLAUDE.md`** 다 — 판단 기준·역할 분담·지시서 형식·검토 규칙·구현 규칙·커밋·기록 의무 전부 그쪽이 원문이고, 이 파일은 t-box 에만 있는 것만 적는다. 두 파일이 충돌하면 상위가 우선이고, 충돌을 발견하면 먼저 알린다.
구조(사용자 결정 2026-09-22): `/var/www/qaredi/` = 공통 지침·공통 기획 · `tbox-v2/` = 이 폴더 · `community/` = 커뮤니티 기획안(코드는 다음 단계, 이 앱 안 `src/community/` 에 격리해서 들어온다).

## 0. 이 모듈의 기준 문서 (t-box 를 만질 때 항상 먼저 읽는다)

| 문서 | 역할 |
|---|---|
| `PRD.md` | t-box 요구사항 — 권한 매트릭스(5장)·F/S/T/Q 표·로드맵 13장(28단계까지) |
| `ARCHITECTURE.md` | 요청 처리 순서·권한 검사 위치·저장 위치·운영 |
| `API.md` | 프로그램용 창구(기본 주소 `https://changkwon.mso.so/tbox`) |
| `SECURITY.md` | 보안 자체점검 — R-xx·A-xx·검토 이력(7장)·앱 밖 위험(6장). **사이트 전체의 검토 이력도 여기**(런처·통합 C 등, 커뮤니티는 `C:` 접두) |
| `STAGES.md` | t-box 단계 점검표(1~28)·디자인 절·학습 기록 |
| `DESIGN.md` | 화면 규칙(사이트 공통 — 코드와 함께 둔다). 살아 있는 견본 `docs/design-guide.html` |
| `docs/*-directive.md` | 단계별 Opus 지시서 |
| `../PRD.md` · `../STAGES.md` · `../docs/launcher/` · `../docs/integration-plan.md` | 사이트 공통 기획·기록·런처 설계·통합 계획 |
| `../community/` | 커뮤니티 모듈 기획안 |

## 1. 이 모듈에만 있는 규칙

- **보안 핵심 파일**(지시서 「수정 파일」에 있을 때만): `src/Authz.php` `src/Auth.php` `src/Db.php` `src/Http.php` `src/Storage.php` `src/Upload.php` `src/Audit.php` `db/**` `deploy/**` `public/index.php`.
- **일괄 진행 모드**(사용자 결정 2026-09-11): ① 구현 → ② 검토 → ④ 커밋으로 이어 가고 관문 ③(사용자 자기 공격)은 뒤에 일괄. ② 통과가 커밋 조건, ③ 결과는 커밋 뒤 `SECURITY.md` 7장·`STAGES.md` 에 추가.
- **위키**: t-box·커뮤니티·사이트 단계가 끝나는 커밋에는 해당 책의 `wiki/<책>/stage-*.json`·`index.json` 갱신과 `php scripts/wiki_check.php` 0건 포함(2026-09-13 사고, 2026-09-23 책 셋 — 검사가 STAGES 0장 ✅ 단계와 글을 대조해 빠진 글을 실패로 잡는다). `Wiki::STAGE_MAX` 는 PRD 로드맵 끝과 같게.
- **검토 표식**: 검토 요청 UA `tbox-review/<날짜>`, 계정 `qa_` 접두 5개(`/etc/tbox-v2/seed-accounts.txt`·`qa-totp.txt`). 시드 계정(`admin01` …)은 사용자 몫.
- **시험**: `tests/*.sh`, BASE `http://127.0.0.1:8082/tbox`. 회귀 기본 묶음 `stage26.sh`(108/0)·`help_button.sh`(16/0)·`api_whoami.sh`·`api_read.sh`·`api_folders.sh`·`f66.sh`·`f68.sh`·`q_launcher.sh`. 다른 모듈을 고쳐도 이 묶음을 돌린다(전부 이어 돌리면 20분 넘게 걸린다 — 먼저 예상 시간을 말한다).
- **주소**: t-box 안의 절대 경로는 서버 `Http::url('/…')`, JS `APP_BASE + '/…'` 로만 만든다(`TBOX_BASE = '/tbox'`, `../docs/launcher/ARCHITECTURE.md` 3장). 옛 주소는 308.
- **발행**: `scripts/release.sh <major|minor|patch> "제목" "변경…"` — 사이트 하나의 번호(`VERSION`·`CHANGELOG.json`), 지금 v2.x. 문장은 사용자 말로.
- (이력) 3단계는 의도적으로 「권한 검사 없는」 상태로 커밋했다 — 4단계 전후 비교가 과제의 핵심이었다. 서버·운영 경로는 `/var/lib/tbox-v2`·`/etc/tbox-v2`·`/var/log/tbox-v2`(저장소 폴더 이동과 무관).

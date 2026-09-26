# 단계별 개발·점검표와 학습 기록 (STAGES.md)

> 이 문서는 **과제 9단계를 단계마다 점검하기 위한 장부**다. 무엇을 만들었고(개발 사항), 무엇을 확인해야 하며(체크사항),
> 그 과정에서 **사용자가 어려워한 부분·궁금해한 점·해소된 점**(학습 기록)을 단계별로 쌓는다.
> 기록은 의무다 — 규칙은 `CLAUDE.md` 9장. 보안 판정의 근거는 언제나 `SECURITY.md` 7장 이력이고, 이 문서는 그것을 초보자 눈높이로 옮긴 점검표다.

| 항목 | 내용 |
|---|---|
| 기준 문서 | `PRD.md` 13장(9단계 로드맵), `CLAUDE.md` 6장(관문 4개), `SECURITY.md` 4장(검토 절차)·7장(이력) |
| 상태 표기 | ✅ 완료(커밋됨) · 🔄 진행 중 · ⬜ 미착수 · ⚠ 사용자 확인 대기(관문 ③) |
| 관문 | ① 구현(Opus) → ② 검토(Fable, 실행 증거) → ③ 자기 공격(**사용자**) → ④ 커밋(Fable) |
| 일괄 진행 모드 | **2026-09-11 사용자 결정 "10단계까지 한번에"** — 4~10단계는 ①→②→④ 로 이어 가고, 관문 ③은 10단계 뒤 **일괄** 수행 (CLAUDE.md 6장). 각 단계 "자기 공격 체크" 가 그때의 점검표다 |
| 갱신 시점 | 질문에 답한 직후 · 관문 ②/③ 결과가 나온 직후 · 커밋 직전 (이 문서 갱신 없이는 커밋하지 않는다) |
| 작업 저장소 | `/var/www/tbox-v2` (공개 `https://changkwon.mso.so`, 로컬 `http://127.0.0.1:8082`). `~/git2/T-Box_Re` 는 문서만 있는 옛 클론이다 — 거기서 작업하지 않는다 |

---

## 0. 진행 현황 한눈에

| 단계 | 만드는 것 | 상태 | ① 구현 | ② 검토 | ③ 자기 공격 | ④ 커밋 |
|---|---|---|---|---|---|---|
| 1 | 뼈대와 DB, 라우터(정책 필수), nginx·php-fpm, `.env` 분리 | ✅ | ✅ | ✅ 2026-09-09 | ✅ 2026-09-09 | `5c0608c` |
| 2 | 로그인·로그아웃, DB 세션, 잠금, CSRF, IP 속도 제한, 시드 계정 | ✅ | ✅ | ✅ 2026-09-09 | ✅ 2026-09-09(사용자 결정) | `ee3e527` |
| 3 | 업로드·목록·다운로드·삭제 (**권한 검사 없음, 의도**) | ✅ | ✅ | ✅ 2026-09-09 · 재검토 09-10 | ✅ 2026-09-09(뚫림 캡처) | `754d756` |
| 3+ | 감사 로그 요약 문장(`summary`) · 육하원칙 문서 | ✅ | ✅ | ✅ 2026-09-10 | ⚠ `audit.log` 직접 읽기 — 일괄 수행 시 | 4단계 커밋에 포함 |
| 4 | 서버 인가 — `Authz` 판정, 목록 SQL WHERE, 다운로드·삭제·업로드 권한, 403 동일 문구, `granted_by`·`route`·`login_ref`, 검토 계정 `qa_`, `audit.log` 보호 (+ 감사 로그 요약 문장 `summary` 포함) | ✅ | ✅ 2026-09-10 | ✅ 2026-09-10 (T-1·T-2·T-3 통과) | ⚠ 일괄 수행 대기 | 4단계 커밋 (2026-09-11) |
| 5 | 입력/출력 안전 — 이스케이프·CSP·CSRF 재확인·바인딩 전수·정렬 허용 목록·오류 은닉, PATCH(설명·레벨 변경), R-36 | ✅ | ✅ 2026-09-11 | ✅ 2026-09-11 (T-4·T-5·T-14 통과) | ⚠ 일괄 수행 대기 | 5단계 커밋 (2026-09-11) |
| 6 | 업로드 하드닝 — 확장자 화이트리스트·MIME 짝·이중 확장자·일일 총량·100MB 경계 확정·sha256 대조·저장소 그룹 회수(R-34) | ✅ | ✅ 2026-09-11 | ✅ 2026-09-11 (T-7~T-11 통과) | ⚠ 일괄 수행 대기 | 6단계 커밋 (2026-09-11) |
| 7 | SSRF 방어 — `POST /api/files/fetch` + `src/Fetch.php` (S-05 13개 조건), `php8.1-curl` 설치 | ✅ | ✅ 2026-09-11 | ✅ 2026-09-11 (T-6 통과) | ⚠ 일괄 수행 대기 | 7단계 커밋 (2026-09-11) |
| 8 | 감사 로그 조회 화면(Admin) — `audit.view` 기록, 육하원칙 6필터 | ✅ | ✅ 2026-09-11 | ✅ 2026-09-11 (T-12·T-13 통과) | ⚠ 일괄 수행 대기 | 8단계 커밋 (2026-09-11) |
| 9 | 운영 — 백업·복구·정합성 검사·경보(AL-1 외 5종)·서버 기준선 점검·해시 체인, README 완성 (원격 로그·WAL·방화벽 적용은 미적용) | ✅ | ✅ 2026-09-11 | ✅ 2026-09-11 (T-15·T-17~T-20 통과, 기준선 주의 6) | ⚠ 일괄 수행 대기 | 9단계 커밋 (2026-09-11) |
| 10 | 계정 관리(F-10)·휴지통 복구(F-11)·Guest 만료(R-12)·비밀번호 변경·2단계 인증 TOTP(R-05) | ✅ | ✅ 2026-09-11 | ✅ 2026-09-11 (T-22 통과, 재지시 1회) | ⚠ 일괄 수행 대기 | 10단계 커밋 (2026-09-11) |
| 11 | 최초 로그인 강제 절차 — 임시 비밀번호·최초 로그인 비밀번호 변경 강제·2단계 인증 필수(F-16, 사용자 결정 2026-09-11) | ✅ | ✅ 2026-09-11 | ✅ 2026-09-11 (관문 우회 10종 403, 검토 계정 5개 이행 완료) | ⚠ 대기 — 시드 계정 첫 로그인 | 11단계 커밋 |
| **12** | **12-1** 공통 셸·기능별 화면 분리·디자인(F-20) · **12-2** 미리보기(F-18) · **12-3** 조직·권한 관리(F-19) — 사용자 요청 2026-09-12 | ✅ 구현·검토 | ✅ 2026-09-12 | ✅ 2026-09-12 (12-2 재지시 1회) | ⚠ 사용자 확인 대기 (브라우저 화면·PDF 미리보기·조직 화면) | `3f4281b` · `506d6de` · 12-3 커밋 |
| **13** | 전면 점검 후속 보정 — 감사 기록 UTF-8(R-47)·해시 체인 봉인(R-50)·로그인 뒤 잠금(R-48)·잠금 카운터(R-49)·TOTP 1회성(R-45) — 사용자 결정 2026-09-12 | ✅ 구현·검토 | ✅ 2026-09-12 (재지시 2회) | ✅ 2026-09-12 (T-28~T-31 통과, R-50 자리표시 교체는 09-13 확인) | ⚠ 대기 | 13단계 커밋 |
| **14** | 위키(F-21) — 네 문서를 초보자 말로 다시 쓴 해설 글(안내 8편 + 단계 1~13) `wiki/*.json`, 서버 렌더러 `src/Wiki.php`, `/wiki`·`/wiki/{slug}`(Admin), 내비 「위키」, `scripts/wiki_check.php` — 사용자 요청 2026-09-12 | ✅ 구현·검토 | ✅ 2026-09-12 (내용 Fable 3인 · 화면 Opus) | ✅ 2026-09-12 (T-32 4역할·XSS·구조 검사 통과) | ⚠ 대기 | 14단계 커밋 |
| **15** | 확장 아이템 — **15-1** 내 활동 보기(F-12) · **15-2** 만료 공유 링크(F-13, Q-5 기본안) · **15-3** 민감정보 패턴 검출(F-22) — 사용자 "진행해" 2026-09-12 | ✅ 구현·검토 | ✅ 2026-09-12 (재지시 1회) | ✅ 2026-09-12 (T-33~T-35 통과) | ⚠ 대기 | 15단계 커밋 |
| **16** | 미반영 재점검 — **16-1** 관리 기능 재인증 sudo 모드(F-23, 12단계 접수 ③ 사용자 확정) · **16-2** 사고 후속 미완 3건(AL-3 확장·AL-9, 정합성 일 1회, `restore_failed` 문장 틀) — 2026-09-12 | ✅ 구현·검토 | ✅ 2026-09-12 | ✅ 2026-09-12 (T-36·T-37 통과) | ⚠ 대기 | 16단계 커밋 |
| **17** | 관리자 영역 `/admin`(F-24) · 권한 매트릭스 화면 편집(F-25, Q-12 결정) — 사용자 요청 2026-09-12 | ✅ 구현·검토 | ✅ 2026-09-12 | ✅ 2026-09-12 (T-38·T-39 통과) | ⚠ 대기 | 17단계 커밋 |
| **18** | **18-1** 폴더(F-26, 폴더 자체 권한·불변 규칙·cascade) → **18-2** 일괄 업로드(F-27, 서버 계약 불변·화면 순차) — 사용자 결정 2026-09-13 | ✅ 구현·검토 | ✅ 2026-09-13 (재지시 1회) | ✅ 2026-09-13 (T-40·T-41 통과) | ⚠ 대기 | 18단계 커밋 |
| **19** | 구매·재고 모듈 골격 + 기초정보(F-28) + 엑셀 가져오기(F-29) + 품목 찾기 API — 확정 2026-09-13 | 🗑 제거 2026-09-21 (27단계) | ✅ 2026-09-13 (Opus, 재지시 0회) | ✅ 2026-09-13 (T-42·T-43·T-44 81/81, 회귀 T-1·T-26·T-36) | ⚠ 대기 | 19단계 커밋 |
| **20** | 발주요청(PR)·추천/내 리스트·불러오기→발주서(PO)·인쇄·검토·보냄 — 지시서 docs/purchase/05 | 🗑 제거 2026-09-21 (27단계) | ✅ 2026-09-13 (Opus, 재지시 0회) | ✅ 2026-09-13 (T-45 90/90, 동시성 201/400, 회귀 T-42·T-43·T-1·T-26·T-36) | ⚠ 대기 | 20단계 커밋 |
| **21** | 입고(GR)·이동(MV)·사용(IS)·실사(ADJ)·**재고 원장**(append-only)·장소별 재고·수불부·미입고·사이클 완성 — 지시서 docs/purchase/06 | 🗑 제거 2026-09-21 (27단계) | ✅ 2026-09-13 (Opus, 재지시 0회) | ✅ 2026-09-13 (T-46 105/105, 동시 이동 400/201, `UPDATE p_stock_moves` permission denied, 회귀 T-45 90/90) | ⚠ 대기 | 21단계 커밋 ecd2413 |
| **22** | 주간 사용량·거래처 월 대사(차이 5종)·월 마감(재인증)·기공 명세서 자동 전표·채번 통합(Doc.php) — 지시서 docs/purchase/07 | 🗑 제거 2026-09-21 (27단계) | ✅ 2026-09-13 (Opus, 재지시 0회) | ✅ 2026-09-13 (T-47 72/72, 마감 403/400/201 실측, 회귀 T-45 90/90·T-46 105/105, 채번 통합 전후 동일) | ⚠ 대기 | 22단계 커밋 |
| **23** | 발주 제안(Suggest.php 한 곳)·부족 알림·경보 AL-14~17 완성·린트 예외 조회 단위·사용 지점 빼기 — 지시서 docs/purchase/08 | 🗑 제거 2026-09-21 (27단계) | ✅ 2026-09-13 (Opus, 재지시 0회) | ✅ 2026-09-13 (T-48 92/92, 경보 dry-run 4종·중복 억제, 회귀 19~22 전부 통과) | ⚠ 대기 | 23단계 커밋 e43222c |
| **24** | 「브랜드·지점·창고」 한 페이지(F-37)·창고 대기표 `p_location_inbox`·연결/제외·업로드 공통 조각 — 지시서 docs/purchase/09 | 🗑 제거 2026-09-21 (27단계) | ✅ 2026-09-13 (Opus, 재지시 0회) | ⚠ 대기 | ⚠ 대기 | 커밋 전 |
| 25 | 구매 화면 UI 정돈 — 공통 CSS 한 벌·시안 충실도·사이드바 3묶음·2단 레이아웃·개발 잔재 제거 (T-50) | 🗑 제거 2026-09-21 (27단계) | ✅ | ✅ 2026-09-14 | 대기(눈 점검 1440·1024) | `6d0d818` |
| 26 | 관리자 「역할로 보기」(세션 임시 역할·아래로만·활동 없는 30분)·관리 내비 7링크·계정 편집 모달·폴더 삭제 세 갈래 (F-39~41 · T-52~54) | ✅ 구현·검토 | ✅ | ✅ 2026-09-15 | 대기(6항목) | (아래 절 참조) |
| F-47 | 버전 관리 `X.Y.Z`(대규모/중규모/수정) — `VERSION`·`CHANGELOG.json`·git 태그·`scripts/release.sh` · 사이드바 버전 → 「변경 이력」 모달(발행 날짜·시각·무엇이 변했나). 초기 1.0.0 — 사용자 결정 2026-09-15 | ✅ 구현·검토 | ✅ 2026-09-15 (Opus, 재지시 0회) | ✅ 2026-09-15 (T-59 65/65 Fable 재실행, 린트 0, help_button 17/17) | ⚠ 대기 | F-47 커밋 + 태그 `v1.0.0` |
| F-48 | 상태 확인 `GET /api/health` 에 DB 확인(`SELECT 1`) — 200 `{status,db,time}` / 503 `{status:fail, db:분류어}` · 저장소 루트 `API.md`(외부 프로그램용 API 문서, 상태 확인·로그인·내 정보·업로드·삭제·로그아웃) — 사용자 요청 2026-09-15 | ✅ 구현·검토 | ✅ 2026-09-15 (Opus, 재지시 0회 + 추가 지시 1건 R-80 디스크 관문) | ✅ 2026-09-15 (T-60 38/38 Fable 재실행, 린트 0, 공개 도메인 200·405·`/API.md` 404) | ⚠ 대기 | `8457c0d` health API 추가 + 발행 `v1.0.1`(`a1c0d4a`) |
| F-49 | 프로그램용 업로드 `POST /api/upload` — `.env` `API_TOKEN` + 헤더 `X-API-Token`(`hash_equals`, 이 라우트만) · 귀속 `API_TOKEN_USER` · 웹과 공통 업로드 함수 · 400/401/403/413 이유 문구(500 일반) · nginx JSON 413 — 강사 2차 프롬프트, 사용자 결정 A 2026-09-15 | ✅ 구현·검토 | ✅ 2026-09-15 (Opus, 1차 설계 B 중단·원복 후 v2, 재지시 1회 `ext_not_allowed`) | ✅ 2026-09-15 (T-61 25/0/미검증1 재실행 + 긍정 경로 qa 귀속 실측 201·403·400×5·401×2, 린트 0, health 38/38) | ⚠ 대기 (사용자 토큰 201; JSON 413 은 검증됨) | `20f51cb` F-49 + 발행 `v1.1.0`(`613d229`) |
| F-50 | 프로그램용 검색·다운로드 — `X-API-Token` 라우트를 셋으로(업로드·`GET /api/files`·`GET /api/files/{id}/download`), 토큰 검색은 간결 배열 `[{id,name,level,size,uploaded_at}]`+`limit`, 인가는 기존 `visibleFilesWhere`·`decideRead` 상속(IDOR 403) — 강사 3차 프롬프트 2026-09-15 | ✅ 구현·검토 | ✅ 2026-09-15 (Opus, 재지시 0회 — 지시서 결함을 Opus 가 실측으로 잡아 헤더 갈림길 추가) | ✅ 2026-09-15 (T-62 38/0/0 Fable 재실행, qa 귀속 실측, 린트 0) | ◐ 사용자 확인 2026-09-15: 검색 200·다운로드 200 (403·401 갈래 미보고) | `b8929a8` F-50 + 발행 `v1.2.0`(`5977306`) |
| F-55 | 백업 원격 사본 — 사용자 PC 가 매일 05:00 pull(`deploy/pc/pull_backup.ps1`), 서버 변경 0 | ✅ | ✅ Opus 09-16 | ✅ 09-16 정적 + 09-16 PC 실행 | ✅ T-67 (a)(c)(d)(e) 통과 · (b) 시험 무효(함수는 격리 시험 통과) | `ac6f7f4` · `a049bd3` · `6953b94` |
| F-56 | 프로그램용 `GET /api/whoami` — 토큰 귀속 계정의 {username, role, team_id, upload_levels} 만, 토큰 창구 셋→넷 | ✅ | ✅ Opus 09-16 | ✅ 09-16 T-68 22/0/0 | ✅ 사용자 09-16 14:29 전 구간 확인 | `dedd3ca` · v1.7.0 `7a2805a` |
| F-57 | 「검사 중」 423 + `Retry-After: 30` — 일시 상태(pending)와 영구 거부(403 격리·권한)를 상태 코드로 구분, 넷(다운로드·미리보기·raw·공유) 한 함수 | ⚠ | ✅ Opus 09-16 | ✅ 09-16 f54.sh 61/0 + 추가 실측 | ⚠ 사용자 프로그램 | `038e562` · v1.8.0 `f7c0931` |
| F-58 | 프로그램용 폴더 관리 — 토큰 창구 넷→**여덟**(`GET/POST /api/folders`·`PATCH /api/folders/{num}`·`PATCH /api/files/{id}`), 토큰 PATCH 는 허용 키 좁힘(폴더 `name`·`parent_id`, 파일 `folder_id`), 삭제·등급 변경은 닫힘 | ✅ 구현·검토 | ✅ 09-16 (Opus, 재지시 1회 — 시험 (f) 기준) | ✅ 09-16 api_folders.sh 54/0/0 | ⚠ 대기 | `0132dfd` · v1.9.0 `6d1ced5` |
| F-59 | 사용자별 프로그램 토큰(설계 B) — `api_tokens`(sha256)·관리자 발급/회수(`/api/users/{uid}/api-tokens`)·인증은 개인 토큰 먼저·토큰 창구 열(삭제 둘은 개인 토큰만)·AL-20 | ✅ 구현·검토 | ✅ 09-16 (Opus, 재지시 3회+회귀 1회 — 전부 시험 결함) | ✅ 09-16 api_tokens.sh 70/0/3 · 회귀 3종 0 실패 | ⚠ 대기 (이행; (d)·화면은 09-17 실측) | `79e0d10` · v1.10.0 `c70fa76` · 화면 보정 `c8296a7` · v1.10.1 `bf700d0` |
| F-60 | 발주요청 불러오기 — 묶는 기준 `doc`(전표)·노드 체크(하위 전부 담기)·`POST /api/purchase/po split:true` 거래처별 N장 한 트랜잭션·분할 미리보기 | 🗑 제거 2026-09-21 (27단계) | ✅ 09-17 (Opus, 재지시 0회) | ✅ 09-17 purchase_f60.sh 99/0 + Playwright | ⚠ 대기 | `40ef933` · v1.11.0 `3ffa6c5` |
| F-61 | 발주서 화면 완성 — 현황 탭(미입고, 재사용)·입고 장소 자동(거래처×장소 분할, 머리 선택)·직접 입력(`item_id`)·부족 산출→발주서(`suggest` 재사용) | 🗑 제거 2026-09-21 (27단계) | ✅ 09-17 (Opus, 재지시 0회) | ✅ 09-17 purchase_f61.sh 138/0 + Playwright · 회귀 f60 99/0·s23 90/0 | ⚠ 대기 | `c13a29f` · v1.12.0 `13d4cc7` |
| 문구 정리 | 화면 문구 정리 — **1단계** 화면에 늘 펼쳐져 있던 안내 68곳 삭제·축소 / **2단계** 「사용 방법」 단추와 화면별 도움말 20화면 (F-38 · T-51) | ◐ 2단계 구현 | ✅ 2026-09-15 (Opus) | ⚠ 대기 | ⚠ 대기 | 1단계 `06ee617` · 2단계 커밋 전 |
| **26** | 관리자 「역할로 보기」(F-39) · 관리 내비·계정 편집 폼(F-40) · 폴더 삭제 세 갈래(F-41) — 사용자 결정·지적 2026-09-15 | ✅ 구현 | ⚠ Fable 재검토 대기 (Opus 자체 시험 124/124) | ⚠ 대기 | ⚠ 대기 | 커밋 전 |
| **27** | 구매·재고 모듈 제거(F-64) — 강사 지시·사용자 결정 2026-09-21(Q-22). 백업: 태그 `v1.13.0-with-purchase` · `~/tbox-v2-purchase-backup-2026-09-21.zip` | ✅ 구현·검토 | ✅ 2026-09-21 (Opus, 재지시 0회) | ✅ 2026-09-21 (T-76 (a)~(g), Fable 재실행 stage26 108/0·help_button 16/0·api_whoami 15/0) | ◐ 2026-09-21 14:47 마이그레이션 실행(사용자) — `p_*` 0·네 열 없음·앱 200. 화면 자기 공격은 대기 | `60715a9` · v1.14.0 `226a48b` |
| F-68 | 400 거부 이유를 사람 문장으로(Q-27) — `Http::reasonMessage` 한 표(ARCH 5.30), 인증·403·404·공유 링크·URL 가져오기는 통일 문구 유지 (T-81 · S-09-12 · R-96) | ✅ | ✅ 2026-09-22 | ✅ 2026-09-22 (T-81 114/0/1) | ⚠ 사용자 확인 대기 | `df126a4` · 발행 v1.16.0 `4357fa6`(디자인과 묶음) |
| F-66 | 프로그램 토큰 본인 발급 — 내 계정 화면·요청마다 비밀번호+OTP·범위(역할이 정함)·만료 180일 (T-78 · Q-24 · R-97) | ✅ | ✅ 2026-09-22 | ✅ 2026-09-22 (T-78 129/0/2) | ⚠ 사용자 확인 대기 | `8f6afa7` · 발행 v1.17.0 `06f6ba4`(디자인 다듬기 문장 포함) |
| **28** | t-box 를 `/tbox/` 아래로(base-path) + 루트 `/` Qaredi 런처 + 옛 주소 308 — 강사 지시 2026-09-21 · 사용자 결정 2026-09-22(Qaredi C). 설계 `../launcher/ARCHITECTURE.md` | ✅ 구현 2026-09-22 (Opus, 재지시 1회) | ✅ 2026-09-22 | ✅ 2026-09-22 (Q:T-1~9 · 회귀 7종 통과 수 불변 · SECURITY 7장) | ⚠ 사용자 확인 대기 (Q:T-10 PC 프로그램·브라우저) | `7d9362a` · 발행 **v2.0.0** `ecb7e30` |
| **F-65** | 실행 파일이 든 압축본의 관리자 확인 업로드·확인 다운로드 — 요청마다 비밀번호+OTP(올릴 때·받을 때)·확인 문구·경고 배지·공유/토큰/등급 변경 금지·AL-21 (Q-23, 사용자 결정 2026-09-21) | ✅ 구현·검토 | ✅ 2026-09-21 (Opus 2단계, 후속 1회) | ✅ 2026-09-21 (T-77 Fable 재실행 106/0/0 · f54 61/0/2 · api_upload 27/0/0) | ⚠ 대기(브라우저로 `x.php` 든 zip 업로드) | `777ffe1` · v1.15.0 `dadcc03` |
| F-71 | 검토·시험 계정을 `claude_` 이름으로 — 계정 5개(`seed_users.php --claude-only`) · 첫 로그인 절차를 창구로 마치는 CLI(`scripts/review_account_setup.php`) · 「검토 계정」 판정 `qa_` **또는** `claude_` 한 벌(`tests/lib/review_accounts.sh`, 시험 19개) · 감사 화면 「Claude」 배지(`src/Origin.php`, 계정 **그리고** UA) — 사용자 결정 2026-09-24 | ◐ 구현(복제본) | ✅ 2026-09-24 (claude, 복제본 — 정적 검사만) | ⚠ 대기 | ⚠ 대기 | 커밋 전 |
| F-69 | 경보에 「누가 일으켰나」 — `origin` 넷(`human`·`system`·`claude-review`·`claude-session`, 판정 `src/Origin.php` 한 곳, 계정 **그리고** UA) · 제목 `[Claude]` · 관리자 경보 목록 배지 · AL-6 격리 파일 오탐 수정(`cli_quarantine_dir()`) · 대기열 2b 사람 계정의 검토 접두 예약 — 사용자 결정 2026-09-24 | ◐ 구현(복제본) | ✅ 2026-09-24 (claude, 복제본 — 정적 검사 + `tests/f69.sh` D-1 앞 판 98/0/1, 지금 판 재실행 대기) | ⚠ 대기 | ⚠ 대기 (D-1 AL-2 루프백 · 「시스템」 배지 색 확인 포함) | 복제본 커밋 `00f442d`(운영 v2.20.0+1b 위, 미반영) |
| Q:F-2 | 통합 검색 + 한글 색인 — 두 앱 공통 Ctrl+K(페이지·댓글·파일) · 한글 「두 글자 조각 + 한 글자」 tsvector(확장 없음) · 영문·숫자는 ILIKE(J-10 ⓑ) · 댓글 색인 칸 · 벡터 배치 — 사이트 PRD Q:F-2, 지시서 `../docs/qf2-unified-search-directive.md` | ✅ 운영 반영 | ✅ 2026-09-25 (claude — 수락 203/0) | ✅ 독립 검토 「반영 가능」(낮음 4 고침) | ⚠ 대기 | 운영 `91f323e`(2026-09-25 20:00 — DB ①③ 은 사용자 d43 허락) · 새 설치용 schema 한 줄 `80d9a00`(대기열 5c) |
| 대기열 5b | 파일 전역 검색 색인(Q:F-2 J-2 후속) — `files.search_tsv`(같은 SearchText · 이름+설명) + GIN + NULL 부분 색인 · 올리기·가져오기·설명 고치기가 채움 · 목록 창구는 한글 검색어만 색인으로 **후보를 좁힘**(원문 ILIKE 그대로 — 결과 같음) · 배치 `qf2b_backfill_files.php` — 구현 기록 `docs/qf2b-files-search-implementation.md` | ✅ 운영 반영 | ✅ 2026-09-25 (claude — 수락 40/0) | ✅ 독립 검토 조건부 → 고침 → 재확인 반영 가능 | ◐ 2026-09-25 23:02 취합이 claude_ 로 대신 — 관리자 전용 파일 이름·가운데 두 글자 검색이 다른 네 계정에 0 (사용자 확인 대기) | 운영 `ffbeb87`(2026-09-25 21:01 — DB 는 사용자 d47 허락) |
| 대기열 6 (Q:F-3a A) | 즐겨찾기 · 최근(두 앱) — t-box 파일·폴더 ☆ · 「즐겨찾기」「최근」 보기(최근 = 감사 로그 읽기) · 커뮤니티 사이드바 「즐겨찾기」(끌어서 순서)「최근」 5 + 더 보기 · 페이지 「더보기」 ☆ — 표 넷 · 새 판정 코드 0(넣기 = 기존 읽기 판정 · 목록 = 기존 조건절) — 구현 기록 `docs/qf3a-favorites-recent-implementation.md` | ✅ 운영 반영 | ✅ 2026-09-25 (claude — 수락 `tests/qf3a_tbox.sh` 84/0 · `tests/community/qf3a.sh` 76/0 · 화면 두 앱) | ✅ 독립 검토 「반영 가능」(높음·중간 0 · 낮음 3 — 라운드 B) | ◐ 2026-09-25 23:26 취합이 claude_ 로 운영에서 대신 — T1 넣기 판정(404 본문 = 없는 id) · T2 비공개·등급 좁힘 뒤 목록 원문 0 · 되돌리면 다시 · T3 관리자 특권 열람은 즐겨찾기·최근에 없음 · T4 최근(연 뒤 나옴·못 보게 되면 빠짐·지우기) · 두 앱 360 다크·1280 화면 오류 0 — 33/0(방금 올린 파일은 검사 중 423 이라 t-box 최근은 검사 끝난 파일로 다시) (사용자 확인 대기) | 운영 `491b7cd`(2026-09-25 23:24 — DB 표 넷은 사용자 d48 허락) · 라운드 B 일괄 · C zip 은 뒤 |
| 대기열 6 (Q:F-3a B) | 일괄 작업(복제본 qf3bw) — t-box 파일 줄 체크·머리 체크·Shift 범위·쪽 넘어 유지·100개 → 선택 막대(등급·팀 · 폴더로 이동 · 즐겨찾기 · 휴지통 + 10초 되돌리기) · 커뮤니티 「선택하기」(옮기기 — 부모와 함께·깊이 초과만 거부 · 즐겨찾기 · 휴지통 + 되돌리기) · 휴지통 창 여러 줄 되살리기 · 진행 표(401 멈춤·남은 줄 유지·새 탭 로그인 뒤 「이어서 하기」 — 같은 계정만) · **새 서버 창구 0**(한 건 창구를 항목마다) + 라운드 A 검토 낮음 3(L-1 역할로 보기 중 걷기 끔 · L-2 트랜잭션+advisory 잠금 · L-3 최근 읽기 먼저) · 권고(특권 열람 최근 안 적음) · D-2 정리 함수 두 줄(마이그레이션 2026-10-05) · 사이드바 트리 ☆ · 빈 목록 여백 — 구현 기록 11장 | ◐ 복제본 구현·자체 시험(독립 검토 전) | ✅ 2026-09-26 (claude — 수락 `tests/qf3b_tbox.sh` 50/0 · `tests/community/qf3b.sh` 52/0(화면 `tests/browser/qf3a_bulk.py` 130/0 — 두 앱 × 360·412 터치·820·1280 × 라이트·다크) · 라운드 A 회귀 qf3a_tbox 84/0 · community/qf3a 몸통 69/0·화면 0 실패 · stage26 113/0 · help_button 16/0 · f68 109/0 · q_launcher 87/3(nginx 몫 — 기준 판과 같음) · api 넷 0 실패 · qf4a delete 13/0 · qf4_restore_owner 101/0) | ⚠ 독립 검토 대기 | ⚠ 대기 | 복제본 `worktree-agent-adb7b2331beea122b` 가지 · 태그 `selftest-base/qf3a-B`(`3da41c4`) · 운영 반영·발행은 취합 |
| 대기열 6 (Q:F-3a C) | zip 으로 받기(복제본 qf3cw) — `POST /api/files/zip`(라우트 한 줄 · file.read · CSRF · 토큰 창구 아님) · 파일마다 **한 건 받기와 같은 함수**(`_download_common.php` 로 옮김 — decideRead → F-65 실행 압축본 → F-54 검사 중·격리 → 무결성)로 거르고 못 받는 파일은 빼고 **개수만**(`X-Zip-Skipped` · 403 `skipped`) · 100개 · 500MB(통과한 파일만 합산) · 사람 1 · 사이트 2 슬롯 · 남은 공간 2×합계+1GB · 앱 임시 폴더 0600 · 끝·오류·끊김에 지움 · 안 이름은 파일 이름만(F-05 + `:` · `..`) · 같은 이름 `(2)` · 감사 파일마다 via zip + 묶음 `file.zip` · 일괄 막대 맨 앞 「zip 으로 받기」 — 구현 기록 12장 | ◐ 복제본 구현·자체 시험(독립 검토 전) | ✅ 2026-09-26 (claude — 수락 `tests/qf3c_zip.sh` 106/0 · 500MB 109/0(머리까지 18.7초) · 화면 `tests/browser/qf3c_zip.py` 59/0(360·412·1280 × 라이트·다크) · 한 건 받기 옛 판 = 이 판 12갈래 · 회귀 qf3a_tbox 84/0 · qf3b_tbox 50/0 · stage26 113/0 · help_button 16/0 · f68 109/0 · api 셋 0 실패 · f65 90/4(기존 시험 결함 — 12.4)) | ✅ 독립 검토 「반영 가능」(높음·중간 0) → 낮음 L-C1(동시 zip 공간 몫)·L-C2(감사 직전 시간 한도) 고침 · 수락 120/0 · 119/0(회귀 포함) · 화면 59/0 | ⚠ 대기 | 태그 `selftest-base/qf3a-C`(`2b00147`) · `review-base/qf3a-C` · `review-fix/qf3a-C` · 운영 반영·발행은 취합 · Audit.php 여섯 줄·앱 임시 폴더·기존 사유·AL-1 은 취합 승인 |
| 보안 넷 (d52) | 사용자 결정 d52 「넷 다 진행」(2026-09-25 23:22) — ① R-100 AL-9 가 절대 경로 `sudo /usr/bin/rm` 을 잡는다 · ② R-31 사내망에서 VM 80 직접 접속은 https 도메인으로 301(nginx map `$tbox_via_proxy` — 방화벽 없이) · ③ R-79 2단계 코드 생성기가 스텝 경계 직후 새 코드(`/api/time` `now_ms` · 초 경계 정렬) · ④ R-39 임시 비밀번호 72시간(`users.temp_password_issued_at` · 로그인 401 사람 문장 · `auth.login deny temp_expired`) — 구현 기록 `docs/sec4-implementation.md` | ◐ 구현(복제본 sec4w) — 독립 검토·운영 반영 전 | ✅ 2026-09-26 (claude — `tests/sec4.sh` 67/0 · `tests/sec4_r31_nginx.sh` 39/0 · `tests/f69.sh` 91/0/7 · 회귀 stage26 113/0 · help_button 16/0 · f68 109/0/2 · totp_time_sync 75/0 · api_whoami 22/0) | ◐ 독립 검토 「조건부 반영 가능」(중간 1 · 낮음 6 · 문서 2) → 복제본에서 고침(태그 `review-fix/sec4`) — 재확인 대기 | ⚠ 대기 | 복제본 `eacb5ba`·`6e93529`·`d9be427`·`e777e3f`(넷 각각) · 시험 틀 `c23b931` · 태그 `selftest-base/sec4`·`review-base/sec4` · 반영 순서 DB → 코드 → nginx(`review-scripts/apply-sec4-db`·`apply-sec4-repo`·`apply-nginx-r31`) · 발행 없음(운영 반영 뒤 취합이 판단) |
| 디버깅 S | 보안 점검(2026-09-26 11:52) 중간·낮음 고치기(복제본 d1sw) — S-1 남의 링크 회수 = 없는 링크 404 · 내 활동 거부 행 가림 · S-2 링크 받을 때마다 만든 사람 재판정 · S-3 AL-9 명령별 억제 · S-4 AL-7 평일만 · S-5 비밀번호 변경·재발급·2단계 초기화 → 토큰 전부 회수 · S-6 가져오기 감사 userinfo 가림 · S-7 child_count 보이는 것만 · S-8 Admin 등급 내리기·링크 만들기 재인증 · S-9 root 배치 쓰기 링크 거부·미래 시각 무시 · S-10 업로드 차단 보강·zip 항목 정규화 · S-11 SECURITY 상태 정정 + d66·d67 문서 | 🔄 검토 반영(반영 전) | ✅ 09-26 claude(구현 기록 `docs/debug-0926-s-implementation.md` 7장) | ✅ 독립 검토 「고친 뒤 반영」(높음 0) → M-1~M-4·L-1~L-6·권고 고침 · 자체 `tests/dbg_s_security.sh` 156/0 · 화면 SMOKE_FAIL=0 | ⚠ 대기 | `selftest-base/dbg-S` · `review-base/dbg-S` · `review-fix/dbg-S`(복제본) |
| 대기열 1b | 시험 규격화(점검 봇 1-A) — `tests/lib/common.sh`·`results.py`·`selftest.sh`(+fixtures)·`tests/run.sh`·`tests/catalog.json` · `stage26.sh`·`f52.sh` 끝부분 종료 코드(실패인데 rc 0 이던 것) | ✅ 합침 | ✅ www-9e(점검 봇 세션) 작성·독립 검토 | ✅ 2026-09-24 claude 확인(`tests/` 밖 0 · selftest 195/0 · `run.sh --select env:static` 2/0) | — (시험만) | `7c0ec9c` · 발행 없음 |
| 대기열 1g | 점검 봇 1-B-1 — 시험 비밀을 명령 인자에서 빼기(`tests/lib/secrets.sh`) · qa_→claude_ · R-73 읽기 `db_read` · `scripts/totp_code.php -`(표준입력) | ✅ 합침 | ✅ 점검 봇 세션 작성·독립 검토 | ✅ 2026-09-24 claude 확인(범위 26파일 · selftest 247/0 · static 71/0 · api_whoami 15/0) | — | 운영 커밋(아래 커밋 메시지) · 발행 없음 |
| 대기열 1n | 점검 봇 규칙판 v4(bot-v4-static · e42655d) 합치기 — tests/static 만(143파일 · 규칙 22 = v3 19 + R29 반영 스크립트 허용 목록 × rename/copy · R30 청소 경쟁 · R31 tests/static 까지 셈) · 사이트 동작 변화 0 | ✅ 합침 | ✅ 취합 changkwon-32(봇 대신) — selftest 238/0 · 보안 린트 통과 | ✅ 1차 독립 검토 중간 3 반영 · 재검토 1~4 · 5차 「반영 가능」 | — (시험 규칙) | 운영 `eaf09e8`(2026-09-25 21:08) · 반영 `scripts/apply-checkbot-rules-v4-2026-09-25.sh` · 정의 `../docs/checkbot/answer-key/bot-v4-static.md` |
| 디버깅 T | 기능 점검(2026-09-26 09:27~13:16 — 운영 코드 결함 0 · 시험 결함 8 · 환경 4 · 흔들림 1)의 **시험 결함 고치기**, tests/·카탈로그만 — qf3a_favorites 목록 읽기(wait_for_function) · qf4b1 의도적 변경 목록·SET LOCAL 제외+음성 시험 · f70·sec4 「마이그레이션 전」을 시험이 만듦(+운영 받기로 간 요청 0) · p7 runf · r31 주석 뺀 셈·짧은 소켓 경로 · wiki_check 틀(tests/wiki_check.sh) · R29 max_s 10 · perf d45 한계 표시 · 카탈로그 밖 수락 시험 일곱 싣기 · **9번(사고)** f51·f52·stage26 운영 파일 쓰기 가드(tests/lib/prod_guard.sh) + run.sh writes_prod 늘 거부 — 구현 기록 `docs/debug-0926-t-implementation.md` | ◐ 복제본 구현·자체 시험(독립 검토 전) | ✅ 2026-09-26 (claude — favorites 0/10 · qf4b1 88/0 · f70 292/0/3 · p7 80/0/1 · r31 43/0 · sec4 67/0 · qf3a 104/0 · 가드 단위 37/0) | ⚠ 독립 검토 대기 | — (시험만) | 복제본 d1tw · 태그 `selftest-base/dbg-T` · `review-base/dbg-T` · 운영 반영은 취합 |
| 디버깅 S2 | 사용자 결정 d72 ① + 검토 낮음(복제본 d2sw) — 세션 쿠키 `__Host-tbox_session`(반영 순간 모두 한 번 다시 로그인) · 로그인 전 런처는 사이트 이름 + 로그인 단추만 · 잠금 순간 감사 `auth.lock` → AL-2 · 로그인 시도 제한 점검 표와 nginx 제안안(반영 안 함) · 손님 커뮤니티 쓰기 값 · root 감사 사본 실패 계속 · getmyuid 대체 제거 | 🔄 구현(검토 전) | ✅ 09-26 claude(구현 기록 `docs/debug-0926-s2-implementation.md`) | ⬜ 독립 검토 대기 · 자체 `tests/dbg_s2_security.sh` 103/0 + 회귀(구현 기록 5장) | ⚠ 대기 | `selftest-base/dbg-S2` · `review-base/dbg-S2`(복제본) |

---

## 1. 매 단계 공통 — 보안 점검은 이렇게 한다 (초보자용)

"검토했다"는 말이 아니라 **명령과 응답 원문**이 증거다 (`CLAUDE.md` 3장). 매 단계 관문 ②에서 Fable 이 하는 일과, 관문 ③에서 사용자가 따라 할 최소 명령을 적는다.

### 1.1 Fable 의 검토 순서 (관문 ②)

| 순서 | 할 일 | 왜 |
|---|---|---|
| 1 | `PRD.md` 12장에서 이 단계에 걸리는 `T-xx` 를 고른다 | "무엇을 확인해야 완료인가"가 문서에 있다 |
| 2 | **검토 계정만** 쓴다: `qa_admin01`·`qa_manager01`·`qa_employee01`·`qa_employee02`·`qa_guest01` + 요청마다 `-A "tbox-review/날짜"` | **토큰 창구(F-49~F-58) 검토**는 `.env` `API_TOKEN_USER` 를 `qa_employee01` 로 잠시 두고 돌린다 — F-58(2026-09-16)부터는 `api_token_rotate.sh` 가 아니라 **`API_TOKEN_USER=` 줄만** 바꾼다(토큰 값 유지 → 사용자 프로그램 재설정 불필요). `.env` 쓰기는 자동 모드에서 차단되므로 **사용자가 직접 실행**하고 끝나면 `admin01` 로 복귀한다. 그 몇 분간 진짜 토큰은 qa 권한으로 동작한다. 시험은 R-73 관문(최근 10분 비-qa 활동)이 있어 로컬 프로그램을 멈춘 뒤 돌린다 | 감사 로그에서 사용자의 행동과 검토 행동이 갈라져야 한다 (3단계 학습 기록 참고) |
| 3 | `curl` 로 로컬 창구(`http://127.0.0.1:8082`)에 **실제 요청**을 보내고 상태 코드·본문을 그대로 적는다 | 코드를 읽고 "될 것 같다"는 증거가 아니다 |
| 4 | `psql` 로 DB 를 본다 — 감사 로그 행, 권한(`\dp`), 실제 데이터 | 응답만으로는 "기록됐는지"를 알 수 없다 |
| 5 | `bash scripts/security_lint.sh` → 금지 패턴 0건 | 사람 눈이 놓치는 `innerHTML`·SQL 결합·`curl_*` 를 기계가 찾는다 |
| 6 | 서버 설정이 바뀌었으면 `php -i`·`nginx -T`·`lsattr`·`\dp` 로 실효값 확인 | 문서에 "있다"고 적힌 것과 서버 상태는 다를 수 있다 (2026-09-10 교훈) |
| 7 | 네 부분으로 보고: ① 안전한 이유(증거) ② 불안전한 이유 ③ 미검증 ④ 다음 조치 | "미검증"을 "안전"이라 쓰지 않는다 |
| 8 | `SECURITY.md` 7장에 한 줄, 이 문서의 해당 단계 갱신 | 이력이 곧 자체점검 문서다 |

판정 용어: **통과**(실행했고 기대와 일치) · **실패**(공격이 성공했거나 기대와 다름 → 커밋 금지) · **미검증**(실행 못 함, 안전 판정 아님).

### 1.2 사용자가 따라 하는 최소 명령 (관문 ③ 공통 도구)

```bash
# 0) 시드 계정 비밀번호 보기 (root 만 읽을 수 있는 파일)
sudo cat /etc/tbox-v2/seed-accounts.txt

# 1) 로그인해서 쿠키를 파일에 저장 (employee01 예시)
curl -s -c /tmp/emp.jar -H 'Content-Type: application/json' \
  -d '{"username":"employee01","password":"비밀번호"}' https://changkwon.mso.so/api/login

# 2) 저장한 쿠키로 API 호출 — -i 를 붙이면 상태 코드와 헤더까지 보인다
curl -s -i -b /tmp/emp.jar https://changkwon.mso.so/api/files

# 3) CSRF 토큰 받기 (삭제·업로드 같은 상태 변경 요청에 필요)
curl -s -b /tmp/emp.jar https://changkwon.mso.so/api/me

# 4) 감사 로그 마지막 10줄 (사람 문장 summary 가 줄 끝에 있다)
sudo tail -n 10 /var/log/tbox-v2/audit.log
sudo -u postgres psql -d tbox_re -c "SELECT happened_at, actor_name, action, result, reason, summary FROM audit_logs ORDER BY id DESC LIMIT 10;"
```

> 브라우저로 할 때는 F12 → **Network** 탭에서 응답 원문(Response)을, **Console** 탭에서 `fetch(...)` 직접 호출을 본다.

---

## 2. 단계별 점검표

각 단계는 같은 틀이다: **개발 사항**(무엇을 만들었나) → **검토 체크**(Fable ②, 실측 결과 요약) → **자기 공격 체크**(사용자 ③, 따라 할 명령) → **커밋** → **학습 기록**.

### 1단계 — 뼈대와 DB ✅ (`5c0608c`, 2026-09-09)

**개발 사항**
- [x] 스키마 6표 `teams·users·permissions·files·audit_logs·sessions` (`db/schema.sql`), 권한 매트릭스 16행 시드 (`db/seed.sql`)
- [x] 최소 권한 `db/grants.sql` — `tbox_app`: `permissions` SELECT 만, `audit_logs` SELECT·INSERT 만, 스키마 CREATE·PUBLIC CONNECT 회수
- [x] 단일 입구 `public/index.php` — 라우트마다 정책 필수, 정책 미등록·핸들러 없음 → **부팅 실패(전 요청 500)** (R-04)
- [x] `.env` 분리(저장소 루트, `changkwon:tbox-php 0640`, `.gitignore`) + `.env.example`
- [x] nginx 사이트 3종(`tbox-v1-local`·`tbox-v2`·`tbox-v2-local`) + 버전 스위치 `deploy/switch-site.sh`, php-fpm 전용 풀 `tbox`(`tbox-php` 계정, `allow_url_fopen=Off`, `open_basedir`, `disable_functions`)
- [x] 저장소를 옛 취약 사이트 웹 루트 밖(`/var/www/tbox-v2`)으로 이동, 저장소 밖 폴더 `-v2` 접미사로 v1 과 분리

**검토 체크 (Fable ②)** — `SECURITY.md` 7장 2026-09-09 세 행
- [x] `/.env` `/src/Db.php` `/db/schema.sql` `/foo.php` `/protected/x` `/.git/config` `/PRD.md` → 전부 404
- [x] `/api/health` 200, 404/405 JSON, 보안 헤더 4개(CSP·nosniff·DENY·no-referrer) + 공개 도메인 HSTS
- [x] `\dt` 6표, `\dp` 앱 계정 권한 최소, `tbox_app` 으로 `CREATE TABLE` 실패
- [x] 정책 미등록 라우트 → 500 (CLI 재현 + app.log)
- [x] 린트 0건, `.env` 미추적

**자기 공격 체크 (사용자 ③)** — 2026-09-09 통과
- [x] `https://changkwon.mso.so/.env` → 404
- [x] `/src/Db.php` → 404, `/db/schema.sql` → 404, `/T-Box_Re/src/Db.php` → 404
- [x] `sudo -u postgres psql -d tbox_re -c '\dt'` → 6 rows

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-09 | 어려움 | 저장소가 옛 취약 사이트의 웹 루트 **안**(`/var/www/html/T-Box_Re`)에 있어 옛 사이트 경유로 소스·`.env` 에 닿을 위험(R-29) | 저장소를 `/var/www/tbox-v2` 로 이동 → 경유 경로 자체가 사라짐. 이동 직후 nginx `root`·`open_basedir` 가 옛 경로라 503 → **저장소를 옮기면 `install.sh`·`switch-site.sh` 재실행이 필수** (README 에 명시) |
| 2026-09-09 | 결정 | 실습 VM 의 PHP 8.1·PostgreSQL 14 는 지원 종료(EOL) 버전인데 그대로 써도 되나 | 참가자 전원 같은 VM 이므로 유지(Q-11), 위험 수용 R-21. 운영 전환 시 업그레이드 필수 |
| — | 궁금 | (이 단계의 사용자 질문은 이전 세션에 별도 기록이 없다) | 이후 발견되면 여기에 추가 |

### 2단계 — 인증/세션 ✅ (`ee3e527`, 2026-09-09)

**개발 사항**
- [x] `POST /api/login`(JSON 만) · `POST /api/logout` · `GET /api/me`(CSRF 토큰 전달), 로그인 화면
- [x] DB 세션 — 쿠키엔 난수 토큰만, DB 엔 SHA-256; 유휴 30분·절대 8시간; 로그인 시 재발급(세션 고정 차단); 비활성 계정 즉시 파기
- [x] 실패 5회 → 10분 잠금(423), 실패 문구 통일(401), 없는 아이디도 더미 해시 검증(시간차 방지)
- [x] CSRF 토큰(HMAC, 저장 없음) — `X-CSRF-Token` 헤더, 5단계 검증을 앞당겨 구현
- [x] nginx 로그인 IP 속도 제한 30r/m burst 10 (R-06)
- [x] 시드 계정 5개 — 비밀번호는 `/etc/tbox-v2/seed-accounts.txt`(root 0600) 에만

**검토 체크 (Fable ②)** — `SECURITY.md` 7장 2026-09-09 "2단계 구현 검토"
- [x] 미로그인 `/api/me` 401 · `/` 302 · 미등록 경로 404
- [x] 쿠키 1자 변조·63자 → 401, 가짜 쿠키로 로그인 → 새 토큰 발급
- [x] CSRF 없음/틀림 → 403, employee02 6회 → 5×401 + 423 + DB `locked_until`
- [x] 만료 시각 조작 → 401, 속도 제한 25회 → 10 통과·15 429
- [x] 해시 5계정 `$argon2id$` (T-16), 감사 로그에 비밀값 0건

**자기 공격 체크 (사용자 ③)** — 2026-09-09 통과(사용자 결정)
- [x] guest01·employee01 로그인·홈 화면 동작, `/api/me` 응답 확인
- [x] DevTools 로 역할 글자 변조 → 새로고침 시 원복(서버 역할 불변)
- [ ] 쿠키 변조·세션 고정·CSRF 403·잠금 423·유휴 만료 — 세부 결과 미보고(Fable curl 실측으로 대체). 원하면 1.2 의 명령으로 언제든 재확인 가능

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-09 | 궁금 | Network 탭에 CSRF 토큰이 그대로 보이는데 위험하지 않나? | 정상이다. 토큰은 **세션에 결속된 HMAC** 이라 다른 세션에서 쓸 수 없고, 다른 사이트는 이 응답을 읽을 수 없다(교차 출처 읽기 불가 실측: `Origin` 위조 요청에 `Access-Control-Allow-Origin` 없음, `no-store`). 진짜 비밀인 세션 쿠키는 `HttpOnly` 라 JS 가 읽지 못한다 (ARCH 4.0) |
| 2026-09-09 | 궁금 | 개발자도구로 화면의 역할 글자를 바꾸면 권한이 바뀌나? | 바뀌지 않는다. 화면 글자는 표시일 뿐이고 역할은 매 요청 서버가 DB 에서 다시 읽는다 (PRD S-01). 새로고침하면 원래대로 돌아온 것이 그 증거 |
| 2026-09-09 | 어려움 | 관문 ③ 항목이 많아(8가지) 일부는 세부 결과를 보고하지 못한 채 "진행" 결정 | 같은 항목을 Fable 이 curl 로 실측해 통과했음을 이력에 남기고 진행. 이후 단계부터는 **자기 공격 항목을 3~4개로 압축**해 이 문서에 명령까지 적어 둔다 |

### 3단계 — 기본 기능, 권한 검사 없음(의도) ✅ (`754d756`, 2026-09-09)

**개발 사항**
- [x] `GET /api/files`(검색·정렬·페이지) · `POST /api/files`(업로드) · `GET /api/files/{id}/download` · `DELETE /api/files/{id}`(논리 삭제) · `GET /api/teams`, 홈 화면(app.js `textContent` 만)
- [x] 라우트 정책은 전부 `authenticated` — **로그인만 하면 admin-only 포함 전부 보이고 받고 지워진다(뚫려야 정상)**
- [x] 3단계에도 지킨 것: 값 바인딩·정렬 허용 목록·LIKE 이스케이프, UUID 저장명 + 웹 루트 밖 저장, 첨부 다운로드(`octet-stream`+`attachment`), CSRF, 업로드 트랜잭션(고아 파일 방지), 감사 로그

**검토 체크 (Fable ②)** — 2026-09-09 검토 + 2026-09-10 재검토(60여 건)
- [x] 뚫림 기준선 확인(의도): employee01 목록에 admin-only·team=2 노출, admin-only 다운로드 200·삭제 200, guest01 admin-only 업로드 201
- [x] 미로그인 401, CSRF 403, SQL 인젝션 페이로드 전부 0건/기본값(T-5 선행), 경로 조작 `../../etc/passwd.txt` → `passwd.txt`(T-11 선행), 101MB 413(T-9), `/protected/x` 404(T-10)
- [x] 헤더 인젹션(CR/LF 파일명) 0건, 파일 유실 시 500 + `error file_missing`(성공 기록 없음)
- [x] 린트 0건, 감사 로그 DB 건수 = 파일 건수

**자기 공격 체크 (사용자 ③)** — 2026-09-09 뚫림 캡처 완료
- [x] employee01 로 admin01 의 admin-only `secret.txt` 와 manager01 의 영업팀 `secret.txt` 가 목록에 보임 → **캡처**
- [x] admin-only 다운로드 성공, 삭제 버튼 보임 → **캡처**
- [x] Network 탭 `/api/files` 응답 원문에 admin-only 행 포함 → **캡처** (4단계 T-2 비교용)

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-10 | 어려움 | `audit.log` 가 `action=file.delete result=allow` 처럼 "컴퓨터 언어"라 무슨 일인지 읽기 어렵다 | 모든 행에 한국어 요약 한 문장 `summary` 를 **기록 시점에** 저장(ARCH 7.4 문장 표 33행). `tail -f audit.log` 만으로 읽힌다. 문장 틀이 없는 조합은 기록 실패 = 요청 실패로 드러나게 했다 |
| 2026-09-10 | 궁금 | 로그에 `employee01` 이 파일을 지운 기록이 있는데 나는 지운 적이 없다. 누가 지웠나? | 실측 결과 **Fable 의 검토 curl(127.0.0.1)** 이었다. 계정만으로는 사람을 특정할 수 없다는 사례 → ① 검토는 `qa_` 계정 + `User-Agent: tbox-review/날짜` 로만 ② 모든 행에 `login_ref`(로그인 회차)·`route`·`granted_by`(허용 근거) 추가 ③ 감사 로그 육하원칙 표 (PRD S-07, ARCH 7.5). 4단계에서 구현 |
| 2026-09-10 | 궁금 | 육하원칙 밖의 "추가 개선 필요사항"(영구삭제·비밀번호 변경·정합성·로그 무결성)은 왜 안 넣었나? | 누락이 맞아 PRD 7.7 행동 표에 4행 추가. 함께 서버를 실측하니 문서에 "있다"던 `audit.log` 추가 전용(`chattr +a`)·`logrotate` 가 **실제로는 없었다** → 4단계 운영 보강으로 설치. 교훈: 문서와 서버 상태를 대조하지 않으면 누락이 남는다 |
| 2026-09-10 | 해소 | 요약 문장에서 파일명 `{level}{target}{actor}.txt` 가 `internal{target}{actor}.txt` 로 왜곡 기록됨(id 316~319) | 순차 치환(`str_replace`)이 앞 단계에서 넣은 값을 다시 치환한 것. **한 번에 치환하는 `strtr`** 로 재지시 후 원형 유지 확인. 파일명은 문장을 흉내 낼 수 있으니 `summary` 는 보조 필드, 판단 근거는 구조화 컬럼 |

> ⚠ **관문 ③ 대기**: 감사 로그 요약 문장은 사용자가 `sudo tail -n 20 /var/log/tbox-v2/audit.log` 로 직접 읽고 "읽힌다"고 확인하면 통과다. 4단계 커밋 앞에 별도 커밋으로 넣는다.

### 4단계 — 서버 인가 ⚠ 사용자 확인 대기 (S-01, S-02 · T-1, T-2, T-3)

**개발 사항** — 2026-09-10 Opus 구현 완료 (관문 ①)
- [x] `Authz::decideRead / decideUploadAs / decideDelete` — 판정 + **근거 코드**(`matrix`·`same_team`·`all_teams`·`owner`·`team_scope`·`admin_all` / `role_not_permitted`·`team_mismatch`·`not_owner`) 반환, `can*` 은 그 껍데기. `visibleFilesWhere` 가 `deleted_at IS NULL` 을 항상 포함
- [x] 라우트 정책 전환: `GET /api/files`·`download` → `file.read`, `POST /api/files` → `file.upload`, `DELETE` → `file.delete` (Guest 는 라우터에서 403 + `authz.route` deny)
- [x] 목록·건수: `Authz::visibleFilesWhere` 를 **SQL WHERE** 에 AND — 응답에 권한 없는 행이 존재하지 않음(S-02), `total` 도 같은 조건
- [x] 다운로드: 파일 행 읽은 직후 **저장소 접근 전** `decideRead` → 실패 403 `{"error":"권한이 없습니다"}` + `Audit::deny` (Q-6)
- [x] 삭제: `decideRead` → `decideDelete` 두 단계, 실패 403 동일 문구 + deny (사유 `role_not_permitted`/`team_mismatch`/`not_owner`)
- [x] 업로드: `decideUploadAs` — Guest·Employee 의 admin-only, 남의 팀 team → 403 + deny
- [x] 감사 로그 육하원칙 채우기: `Audit::write()` 가 `detail.route`(라우트 **패턴**)·`actor_team`·`login_ref` 자동 추가, 핸들러가 `granted_by`·`sha256`·`size_bytes`·`owner_id`·`target_team` 추가
- [x] 화면 편의: `/api/files` 행에 `can_delete`, `/api/me` 에 `upload_levels` → 버튼·선택지는 서버 값으로만 (권한 근거 아님)
- [x] 검토 계정 `qa_*` 5개 생성(`scripts/seed_users.php` 계정별 멱등, `--no-qa`), `install.sh` 는 비밀번호 파일에 **덧붙이기**
- [x] 운영 보강: `audit.log` `chattr +a` + `deploy/logrotate.d/tbox-v2`(일 단위·365일·압축, pre/post 에서 속성 해제·재설정)

**검토 체크 (Fable ②)** — 2026-09-10 **통과** (`SECURITY.md` 7장 "4단계 구현 검토" 행에 원문 요약)
- [x] T-2: `qa_employee01` 목록에 `admin-only` 문자열 0·`team_id:2` 0·비교용 4건 0건·`total`=행 수, `q=secret` 0건; `qa_guest01` public 만; `qa_admin01` 전부(8건)
- [x] T-3: `qa_employee01` admin-only·team2 다운로드 → `403 {"error":"권한이 없습니다"}` (파일 정보 0) + deny `role_not_permitted`/`team_mismatch`; public → 200 첨부 헤더; `qa_guest01` internal → 403
- [x] T-1: 남의 파일 DELETE 403(`role_not_permitted`/`team_mismatch`/`not_owner`), 본인 200 `owner`; `qa_manager01` 자기 팀 200 `team_scope`·남의 public 403·team2 403; `qa_guest01` 403(라우터 `authz.route`); `qa_admin01` 200 `admin_all`. 비교용 6건 무손상
- [x] 업로드 권한: Guest 403(`authz.route`), Employee admin-only 403·team2 403·team1 201 `same_team`·public 201 `matrix`, Admin team2 201 `all_teams`
- [x] 감사 로그 qa 행 전부 `route`(패턴 3종)·`login_ref` 12자·`actor_team`, 인가 거친 allow 행 `granted_by` 누락 0, 비밀값 0건, 403 본문 전부 동일 문구
- [x] `lsattr` 에 `a` 걸린 뒤에도 기록 계속(DB 389 = 파일 390 − 1단계 스모크 1줄), tbox-php 덮어쓰기·삭제 `Operation not permitted`, `logrotate -d` 오류 0
- [x] 린트 0건, `php -l` 통과, `\dp` `tbox_app=ar` 불변, `.env` 미추적, 회귀(401·CSRF 403·404·`sort` 주입) 그대로
- [ ] **미검증** ① 브라우저 화면(`app.js`: 레벨 선택지·Guest 업로드 패널 숨김·`can_delete` 버튼) — 서버 응답만 확인, 사용자 관문 ③에서 눈으로 확인 ② logrotate 실제 회전(살아 있는 증거 파일을 자르므로 미실행)
- 관찰: `csrf`·`auth.required` 거부 행의 `target_name`·`summary` 에 실제 UUID 경로가 남음 → R-36, 5단계에서 패턴화

**자기 공격 체크 (사용자 ③)** — 3단계 캡처와 **같은 화면**을 employee01 로 다시 캡처
- [ ] **T-2** 로그인 후 목록에 `secret.txt`(admin-only)·영업팀 `secret.txt`·`guest-adm.txt`·`emp-team2.txt` 가 **보이지 않음**. F12 → Network → `/api/files` 응답 원문(Response)에 `admin-only` 글자가 **한 글자도 없음**
- [ ] **T-3** 3단계에 받아 둔 admin-only 파일 주소로 직접 접근: `curl -s -i -b /tmp/emp.jar https://changkwon.mso.so/api/files/454e8009-2f19-4bcd-a6ce-a74dd58fd340/download` → `403` + `{"error":"권한이 없습니다"}` (파일명·레벨 없음)
- [ ] **T-1** F12 → Console 에서 `fetch('/api/files/454e8009-2f19-4bcd-a6ce-a74dd58fd340',{method:'DELETE',headers:{'X-CSRF-Token':'(/api/me 의 csrf_token)'}}).then(r=>r.status)` → `403`. 삭제 버튼을 Elements 에서 되살려 눌러도 같다
- [ ] 감사 로그 확인: `sudo tail -n 5 /var/log/tbox-v2/audit.log` 에 위 시도가 `deny` + 한국어 문장으로 남아 있음. 이때 `actor_name` 은 `employee01`(본인), `qa_` 행은 Fable 검토
- [ ] 위 결과를 3단계 캡처와 나란히 놓고 "뚫림 → 막힘" 비교 저장

**커밋** — 2026-09-11 일괄 진행 모드로 관문 ② 통과 직후 커밋 `4단계: 서버 인가 — …` (S-01, S-02, S-07). 감사 로그 요약 문장·육하원칙 문서는 코드가 한 파일(`src/Audit.php`)에 섞여 있어 같은 커밋에 포함했다. 관문 ③은 10단계 뒤 일괄.

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-10 | 궁금 | `ls` 와 `ll` 의 차이, `ls -l` 과 `ll` 결과가 왜 다른가 | `ll` 은 이 서버 `.bashrc` 91행의 별칭 `ls -alF` 다 — `-a`(숨김 파일 포함)·`-F`(종류 기호) 가 더 붙는다. 자세한 내용은 3장 기초 기록 |
| 2026-09-10 | 궁금 | `type ls -l` 이 `-l: not found` 를 내는 이유, `-a` 와 `-A` 차이, `.` 과 `..` 은 무엇인가 | 3장 기초 기록에 정리 |
| 2026-09-10 | 요청 | 단계별로 점검할 수 있게 개발 사항·체크사항을 별도 문서로, 사용자의 어려움·궁금·해소를 단계별로 기록 의무화 | 이 문서(`STAGES.md`) 신설 + `CLAUDE.md` 9장 기록 의무 추가 |
| 2026-09-10 | 해소 | (검토 중 발견) 화면이 레벨 목록·삭제 버튼을 스스로 정하면 "화면에 심은 권한 판단"이 된다 | 선택지(`upload_levels`)와 버튼(`can_delete`)을 **서버가 내려 주고** 화면은 그대로 그린다. 그래도 요청은 서버가 다시 검사 — 버튼을 되살려도 403 (PRD 10장, ARCH 5.1 메모). 관문 ③ T-1 이 바로 이 확인 |

### 5단계 — 입력/출력 안전 + 설명·레벨 변경 ✅ (S-03, S-04, S-09-3, F-08 · T-4, T-5, T-14) — 2026-09-11 커밋

**개발 사항**
- [x] 출력 이스케이프 전수 점검: 서버 HTML 동적 값 2곳(`username`·`role`) 모두 `htmlspecialchars`, app.js 는 `textContent`·UUID 검사 뒤 href 만. 고칠 곳 0건
- [x] SQL 전수: 25개 전부 고정 문자열 + 바인딩, `ORDER BY` 허용 목록, `LIMIT/OFFSET` 정수 바인딩, `PDO::query/exec` 0
- [x] CSRF: 상태 변경 라우트 4개(logout·upload·PATCH·DELETE) 전부 라우터에서 검사, 로그인은 JSON 전용 + SameSite
- [x] `PATCH /api/files/{id}` — 허용 필드 `description`·`access_level`·`team_id` 만(R-07), `decideRead → decideEdit → decideLevelChange`, **등급 하향은 Admin 만**, `before/after`·`changed` 기록, UPDATE 는 고정 조각 3개
- [x] 화면: 서버 `can_edit` 가 참일 때만 "수정" 버튼(prompt 3단계). 판단은 서버가 다시 한다
- [x] R-36: `csrf`·`auth.required` 거부 기록의 대상을 실제 UUID 경로 → 라우트 패턴으로

**검토 체크 (Fable ②)** — 2026-09-11 통과 (`SECURITY.md` 7장 "5단계 구현 검토")
- [x] T-4 설명 `<img src=x onerror=alert(1)>`·`<svg onload>`·파일명 `"><script>` → JSON 문자열 그대로, 홈 HTML 0회, 다운로드 첨부 헤더
- [x] T-5 `' OR '1'='1`·UNION·`;DROP`·`sort/dir/page` 주입 → 200 + 0건/기본값, 오류 문구 0. PATCH 설명 SQL 조각은 글자 그대로 저장
- [x] T-14 토큰 없이 PATCH/DELETE/POST → 403, 기록 대상이 `PATCH /api/files/{id}` 패턴
- [x] PATCH 권한 22종(본인·남·읽기 불가·Manager·Admin 하향·Guest·형식 오류·불변 컬럼) 전부 기대값
- [x] 오류 은닉: 저장 파일을 잠시 옮겨 500 유도 → 본문 `{"error":"서버 오류"}` 만
- [ ] **미검증** 브라우저 "수정" 버튼 흐름(prompt) → 관문 ③

**자기 공격 체크 (사용자 ③)** — employee01 로
- [ ] **T-4** 업로드 설명란에 `<img src=x onerror=alert(1)>` 를 넣어 올리기 → 목록에 **글자 그대로** 보이고 알림창이 뜨지 않음. F12 → Network → `/api/files` 응답에서 그 문자열이 JSON 문자열 안에 있음
- [ ] **T-5** 검색창에 `' OR '1'='1` → 0건, 오류 문구 없음. 주소창에서 `/api/files?sort=id;DROP TABLE files` → 정상 목록(기본 정렬)
- [ ] **T-14** F12 → Console 에서 토큰 없이 `fetch('/api/files/<본인 파일 UUID>',{method:'DELETE'}).then(r=>r.status)` → `403`
- [ ] **F-08** 본인 파일의 "수정" 버튼으로 등급을 `internal` 로 올리기 → 성공. 다시 `public` 으로 **내리기** → "권한이 없습니다"(하향은 Admin 만). `sudo tail -n 3 /var/log/tbox-v2/audit.log` 에 `downgrade_not_permitted` 와 한국어 문장 확인

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-11 | 해소 | (검토 중 발견) 검토 스크립트가 따옴표 든 파일명을 로컬 파일 경로로 쓰다 실패 → PATCH 시험이 빈 ID 로 나가 전부 405 | 앱이 아니라 스크립트 문제. curl 은 `-F "file=@경로;filename=표시이름"` 으로 **로컬 경로와 서버에 보내는 이름을 분리**해야 한다. 같은 이유로 `-F 'description=<…'` 은 `<` 를 파일 읽기로 해석하므로 `--form-string` 을 쓴다 |
| 2026-09-11 | 결정 | 등급 하향(`admin-only`→`public` 방향)은 누가 할 수 있나 | PRD 4장·5.3: **Admin 만**. 코드 `Authz::decideLevelChange` 의 `LEVEL_RANK` 비교. 실측: Employee 하향 403 `downgrade_not_permitted`, Admin 하향 200 + `before/after` 기록 |

### 6단계 — 업로드 하드닝 ✅ (S-06, R-13, R-34 · T-7~T-11) — 2026-09-11 커밋

**개발 사항**
- [x] 확장자 화이트리스트 14종(PRD 7.6), 없는 확장자·목록 밖 → `ext_not_allowed`
- [x] finfo 실제 내용(MIME) ↔ 확장자 짝 표 — 어긋나면 `mime_mismatch`. `application/octet-stream` 은 어디에도 허용하지 않음
- [x] 이중 확장자(`shell.php.txt`, `.htaccess.txt`) → `double_extension`; 제어문자로 세그먼트 쪼개기 방어
- [x] 정확히 100MB 는 통과, +1바이트 거부(PHP), 101MB 는 nginx 413 (`client_max_body_size 101m`)
- [x] 사용자별 24시간 2GB(`quota_exceeded`, 삭제 파일 포함)
- [x] 다운로드 전 sha256 대조 필수 — 불일치 500 + `hash_mismatch` 기록, 전송 안 함 (R-13)
- [x] 다운로드는 PHP `readfile()` 확정, 저장소 그룹 `www-data` → `tbox-php` 회수 (R-34 해소)
- [x] ClamAV 는 과제 범위 밖으로 결정(Q-3), `scan_status` 자리만 유지

**검토 체크 (Fable ②)** — 2026-09-11 통과 (`SECURITY.md` 7장 "6단계 구현 검토")
- [x] T-7 `shell.php` → 400, 저장소 파일 수 불변 · T-8 PHP 내용 `doc.pdf`·텍스트 `img.png` → 400 `mime_mismatch`, `evil.html` → 400
- [x] 이중 확장자 3종 400, `report.final.txt` 201 · T-9 100MB 201 / +1B 400 / 101MB 413 · T-10 세 경로 404 · T-11 `passwd.txt` + UUID 저장
- [x] sha256 변조 → 500 `{"error":"서버 오류"}` + `hash_mismatch`, 원복 200 · 그룹 회수 후 `www-data` 읽기 거부
- [x] 일일 총량 SQL 부풀림 → 400 `quota_exceeded` → 실제 크기로 원복(DB↔FS 전수 대조 불일치 0)
- [ ] **미검증** 진짜 HWP 5.x 파일(스텁만), 브라우저 업로드 폼 → 관문 ③

**자기 공격 체크 (사용자 ③)** — employee01 로
- [ ] **T-7** 메모장에 `<?php echo 1;` 을 적어 `shell.php` 로 저장 → 업로드 → "잘못된 요청입니다"
- [ ] **T-8** 같은 파일 이름만 `doc.pdf` 로 바꿔 업로드 → 거부. `sudo tail -n 2 /var/log/tbox-v2/audit.log` 에 `mime_mismatch` 문장
- [ ] **T-10** 정상 파일 하나 올린 뒤 `sudo -u postgres psql -d tbox_re -c "select stored_name from files order by id desc limit 1"` 로 저장 이름을 보고 `https://changkwon.mso.so/protected/앞2/다음2/저장이름` 접속 → 404
- [ ] 이중 확장자 `x.php.txt` 업로드 → 거부

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-11 | 해소 | (검토 중 발견) `/dev/zero` 로 만든 100MB `.txt` 는 업로드가 거부된다 — 왜? | 내용이 NUL 바이트라 finfo 가 `application/octet-stream` 으로 판별하고 `txt` 짝 표에 없어 `mime_mismatch`. **크기가 아니라 내용 검사에 걸린 것**. 줄바꿈 있는 텍스트로 만들어야 `text/plain` 이 된다. 위장 파일 검사가 의도대로 작동한 사례 |
| 2026-09-11 | 결정 | 100MB 경계: 정확히 100MB 파일은 올라가야 하나? | PRD S-06 "100MB" 를 포함 경계로 확정 — nginx 는 101m 으로 요청 전체를 받고 PHP `Upload::MAX_BYTES`(104,857,600) 초과만 거부. 3단계 재검토 관찰 ① 해소 |
| 2026-09-11 | 결정 | HWP 파일의 실제 MIME 은 무엇인가? 이 VM 은 `application/cdfv2`(OLE 컨테이너) | `hwp` 칸에만 허용. 부작용: 구형 MS Office OLE 문서를 `.hwp` 로 위장하면 통과 — ClamAV(Q-3) 없는 한 남는 위험, R-02 비고에 기록 |

### 7단계 — SSRF 방어 ✅ (S-05, F-14 · T-6) — 2026-09-11 커밋

**개발 사항**
- [x] `php8.1-curl`(배포판 패키지) 설치, `DEPENDENCIES.md` 갱신
- [x] 외부 요청 코드는 `src/Fetch.php` **한 파일**(린트가 다른 곳의 `curl_*` 반려)
- [x] S-05 13개 조건: https·443 만, userinfo·IP 표기·`localhost`·퓌니코드 거부, 허용 목록 **정확 일치**(비면 전부 거부, 와일드카드 금지), DNS 해석 결과 전부 공인 IP(IPv6 매핑·호환 표기 환원), 검사한 IP 로 `CURLOPT_RESOLVE` 고정 + host 를 정규화 값으로 URL 재조립, 리다이렉트 미추적(3xx = 실패), 3s/10s·100MB(헤더+실수신 바이트+사후 크기 3중), 가져온 파일은 업로드와 **같은** `Upload::validatePath`, 응답 비노출(문구 하나), Manager 이상 + 시간당 10회, 임시파일 항상 정리
- [x] 감사 로그: 사유 코드 14종, `source_host`·`source_ip`·`http_status` (URL 전체는 기록 안 함)
- [x] 화면: 서버 `can_fetch` 가 참일 때만 "URL 로 가져오기" 패널

**검토 체크 (Fable ②)** — 2026-09-11 통과 (`SECURITY.md` 7장 "7단계 구현 검토")
- [x] 단위 69건(`isPrivate` 28 + `check` 41): 스킴·포트·IP 표기 9종·userinfo 3종·유사 도메인·`%2f@`·`#@`·퓌니코드·와일드카드·공백·길이 → 전부 거부, 끝 점·대문자·`:443` → 정규화 통과
- [x] e2e 11건(임시 계정 `qa_manager02`): 성공 2(internal `matrix`, team1 `same_team`, sha256 일치), 302 `redirect`, 404 `fetch_failed`, `.php` `invalid_file`, 표기 우회 3종 `host_not_allowed`, admin-only 403, team2 403, 11회째 429
- [x] Employee·Guest 403(라우터), CSRF 없음 403, 폼 400, 실패 본문 전부 `{"error":"가져올 수 없습니다"}`, URL 전체 로그 0, 임시 디렉터리 잔여 0
- [x] 검토 뒤 `.env` 허용 목록을 **비움** → 현재 서비스는 전부 거부(Q-10 운영 결정)
- [ ] **미검증** 내부 IP 로 해석되는 도메인(DNS 조작 불가 → 단위로 대체), 100MB 초과 e2e(CLI 로 대체), 브라우저 패널 → 관문 ③

**자기 공격 체크 (사용자 ③)** — manager01 로 (허용 목록이 비어 있으므로 **전부** 거부돼야 정상)
- [ ] 화면 "URL 로 가져오기" 에 `file:///etc/passwd` → "가져올 수 없습니다"
- [ ] `http://raw.githubusercontent.com/git/git/master/README.md`(http) → 같은 문구
- [ ] `https://127.0.0.1/`, `https://169.254.169.254/latest/meta-data`, `https://localhost/`, `https://2130706433/` → 같은 문구
- [ ] `https://raw.githubusercontent.com/git/git/master/README.md`(허용 목록에 없음) → 같은 문구
- [ ] `sudo tail -n 8 /var/log/tbox-v2/audit.log` 에 사유가 `scheme`·`host_not_allowed` 로 구분돼 남고 문장에 "SSRF"·"내부망" 설명이 있음. 화면 문구는 전부 같았음을 확인
- [ ] employee01 로는 패널 자체가 안 보이고, F12 Console 로 `fetch('/api/files/fetch',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':'…'},body:'{"url":"https://a/b.txt","access_level":"internal"}'}).then(r=>r.status)` → `403`

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-11 | 해소 | (검토 중) 검토 계정 두 개가 시간당 10회 한도를 자체 시험으로 다 써서 Fable 이 e2e 를 못 돌림 | 속도 제한은 허용·거부를 **모두** 세도록 설계돼 있어(거부 반복으로도 막힘) 의도된 동작. 임시 Manager 계정 `qa_manager02` 를 만들어 시험하고 파일·세션·계정을 삭제(감사 행은 남김). 교훈: 한도가 있는 기능은 구현자와 검토자가 **다른 계정**을 써야 한다 |
| 2026-09-11 | 결정 | 시험 때 넣은 허용 호스트(`raw.githubusercontent.com`, `github.com`)를 남길까? | PRD Q-10 대로 **비움**. 운영자가 필요할 때 정적 파일을 직접 내려주는 호스트만 `.env` 에 추가하고 `php-fpm reload`. Google Drive 류는 리다이렉트를 쓰므로 원리적으로 불가 |
| 2026-09-11 | 해소 | 왜 URL 전체를 감사 로그에 남기지 않나? | URL 경로에 토큰·개인정보가 들어갈 수 있어 host·ip·상태 코드만 남긴다(ARCH 7.5). 조사에 필요한 "어디서 가져왔나"는 host 로 충분 |

### 8단계 — 감사 로그 조회 ✅ (S-07, F-09 · T-12, T-13) — 2026-09-11 커밋

**개발 사항**
- [x] `GET /api/audit`·`GET /audit` — 정책 `audit.read`(Admin 만, Q-8). 다른 역할은 라우터에서 403 + 그 시도가 `authz.route` 로 기록
- [x] 조회 자체를 기록: 핸들러 첫 줄 `audit.view allow` + 적용한 필터(`detail.filters`)
- [x] 육하원칙 필터: 누가(`actor` 이름 또는 `login_ref` 12자), 언제(`from`·`to`), 어디서(`ip`), 무엇을(`target` 부분 일치·`level`), 어떻게(`action` 허용 목록), 왜(`result`·`reason`·`granted_by`). 전부 바인딩, 형식 밖 값은 무시
- [x] 응답은 JSON, 화면은 `textContent` 로만 그림(`summary`·`target_name`·`user_agent` 포함). 20건 페이지, 최신순 고정
- [x] 결정: 404·405 는 기록하지 않음(3단계 관찰 ④ 종결 — 이유는 SECURITY 7장)

**검토 체크 (Fable ②)** — 2026-09-11 통과 (`SECURITY.md` 7장 "8단계 구현 검토")
- [x] T-13 Manager·Employee·Guest → 403 + `authz.route` 기록 3건, 미로그인 401, 화면 Employee 403·미로그인 302
- [x] T-12 `result=deny` 조회에 4~7단계 거부 행동 10종 존재, 조회마다 `audit.view` 행 + 필터 기록
- [x] 필터 10종 각 200·첫 행 조건 일치, 잘못된 값 9종 전부 200·오류 문구 0·표 불변, `%`·`_` 는 문자 그대로
- [x] XSS 페이로드 파일명이 JSON 문자열로만, `/audit` HTML 0회, CSP 유지
- [ ] **미검증** 브라우저 표 렌더링·`<details>` 상세 → 관문 ③

**자기 공격 체크 (사용자 ③)**
- [ ] **T-13** employee01 로 `https://changkwon.mso.so/api/audit` → `{"error":"권한이 없습니다"}`(403). 주소창에 `/audit` → 같은 문구
- [ ] **T-12** admin01 로 로그인 → 홈 상단 "감사 로그" 링크(Admin 에게만 보임) → 필터 "결과 = deny" 로 조회 → 방금 employee01 의 시도가 맨 위에 `authz.route … GET /api/audit` 로 보임. 4~7단계에서 본인이 했던 공격(`secret.txt` 다운로드 403, 삭제 403, `.php` 업로드, URL 가져오기)이 전부 `deny` 로 보이는지 확인
- [ ] 필터 "누가 = employee01" 로 좁혀 보고, 표의 "요약" 이 한국어 문장인지, 파일명에 `<img src=x onerror=alert(1)>` 가 있어도 **글자 그대로** 보이고 알림창이 없는지 확인
- [ ] 조회를 마친 뒤 `sudo tail -n 3 /var/log/tbox-v2/audit.log` 에 `audit.view` 행(내가 로그를 본 기록)이 남았는지 확인

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-11 | 해소 | 로그인 실패 기록은 "누가" 필터(`actor=계정명`)로 왜 안 나오나? | 로그인은 세션이 생기기 **전**이라 `actor_*` 가 비어 있고 대상 계정이 `target_name` 에 있다(ARCH 7.3). 로그인 기록은 "무엇을 = 계정명" 필터로 찾는다. README 조회 요령에 적음 |
| 2026-09-11 | 결정 | 없는 주소(404)·잘못된 메서드(405) 요청도 감사 로그에 남길까? | 남기지 않는다. 외부 ID 가 UUID 라 열거가 비현실적이고, 경로 스캔은 nginx access log 에 90일 남는다. 라우트 매칭 전이라 패턴이 아닌 실제 경로만 남길 수 있어 S-07 "요청 경로" 규칙과 충돌 |

### 9단계 — 운영 ✅ (S-08, S-10, S-11 · T-15, T-17~T-20) — 2026-09-11 커밋

**개발 사항**
- [x] 백업: `backup_db.sh`(pg_dump → 파이프로 gpg AES256, 평문 미저장, 보관 일 7·주 4·월 6)·`backup_files.sh`(tar|gpg + rsync 미러)·`verify_backup.sh`(sha256·복호화 시험, 2회 연속 실패 → AL-8). 읽기 전용 역할 `tbox_backup`, 키 `/etc/tbox-v2/backup.key`(0600 root — **분실 = 백업 무용**, 사본 별도 보관)
- [x] 복구: `restore.sh`(ARCH 11.2 순서, 운영 DB 덮기는 `--yes-production` 필수, 리허설은 별도 DB) + 복구 뒤 `grants.sql` 재적용 절차
- [x] 배치: `purge_trash.php`(30일 휴지통 바이트 삭제, 행은 `purged_at` 로 유지)·`consistency_check.php`(누락·고아·sha256 → AL-6)·`audit_chain.php`(감사 로그 파일 일별 해시 체인)·`alerts.php`(AL-1·2·5·7·10·12, 30분 중복 억제) — `/etc/cron.d/tbox-v2` 7줄
- [x] `check_baseline.sh`(T-17~T-19 읽기 전용 점검)·`deploy/hardening.sh`(ufw·sshd 적용 계획, **기본은 출력만**)
- [x] README: 설치·운영(백업·복구·감시)·감사 로그 보는 법·검토 계정 절
- [ ] **미적용(결정)**: WAL 아카이브(RPO 15분 → 실제 일 1회), 원격 로그 서버·3-2-1 원격 사본(Q-4 미결), 방화벽·SSH 강화 적용(원격 세션 차단 위험 — 사용자가 콘솔에서 `sudo bash deploy/hardening.sh --apply`), 알림 채널(메일·메신저 없음, 로그만)

**검토 체크 (Fable ②)** — 2026-09-11 통과 (`SECURITY.md` 7장 "9단계 구현 검토")
- [x] T-15 백업 → 검증 → 파일 바이트 삭제(정합성 exit 2 + AL-6) → `restore.sh --files` → sha256 원본=복구=DB, strict 통과, 다운로드 200. DB 는 별도 DB 로 리허설(표 6·행 수 일치). 소요 5분 21초(Opus)·13초(Fable 재실행)
- [x] purge: 31일 전으로 조작한 시험 행 1건만 삭제·`purged_at`·`file.purge` 기록, 최근 삭제 파일은 유지
- [x] 해시 체인 2회 같은 값·`--verify` 통과·값 변조 시 실패, `audit_cli` 로 `auth.login` 위조 → 거부
- [x] T-20 AL-1 발동 + 30분 중복 억제, AL-2(잠금 423 + 429)·AL-5·AL-8·AL-10 실측, cron 5분 실행 로그
- [x] T-17~T-19 점검 출력: OK 15 · 주의 6(SSH 비밀번호 로그인 허용·root 로그인·MaxAuthTries 6·chrony 없음·PHP EOL·postgres 셸) · 미적용 6(ufw·fail2ban·원격 로그·WAL)
- [ ] **미검증** hardening 적용 후 상태, logrotate 회전 뒤 체인, `--stop-service` 복구

**자기 공격 체크 (사용자 ③)** — 서버 콘솔에서
- [ ] **T-15** `sudo bash scripts/backup_db.sh && sudo bash scripts/backup_files.sh` → `sudo ls -l /var/backups/tbox-v2/db /var/backups/tbox-v2/files` 에 `.gpg`·`.sha256` 생김 → `sudo bash scripts/verify_backup.sh` 통과
- [ ] 파일 하나를 올린 뒤 그 저장 파일을 `sudo rm` 으로 지우고 `sudo php scripts/consistency_check.php` → "불일치 1건" + `alerts.log` 에 AL-6 → `sudo bash scripts/restore.sh --files <최신 파일 이름>` → 다시 검사 → 불일치 0, 다운로드 정상
- [ ] **T-17~T-19** `sudo bash scripts/check_baseline.sh` 를 읽고 "주의" 항목이 왜 남았는지(비밀번호 SSH·ufw 미적용) 이해. 적용할지 결정 — 적용하려면 **콘솔(원격 아님)에서** `sudo bash deploy/hardening.sh --apply`
- [ ] **T-20** employee01 로 admin-only 파일 다운로드를 6회 시도(403) → 5분 안에 `sudo tail -n 2 /var/log/tbox-v2/alerts.log` 에 AL-1 (cron 이 5분마다 돈다. 바로 보려면 `sudo php scripts/alerts.php`)
- [ ] `sudo cat /etc/cron.d/tbox-v2` 로 일정 7줄 확인, `sudo cat /etc/tbox-v2/backup.key` 를 **다른 곳에 사본 보관**(R-08)

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-11 | 결정 | 문서의 `age`·`restic`·`chrony`·`fail2ban` 이 VM 에 없다 — 설치할까? | 새 패키지 0 원칙(S-10-C)대로 설치하지 않고 있는 도구로 대체: `age`→`gpg`, `restic`→`tar`+`rsync`, `chrony`→`systemd-timesyncd`(동기화 확인됨), `fail2ban`→없음(SSH 차단 불가, 주의로 남김). `DEPENDENCIES.md` 5.3 "쓰지 않기로 한 도구" |
| 2026-09-11 | 결정 | 방화벽(ufw)·SSH 강화를 스크립트가 자동 적용해도 되나? | **하지 않는다.** 원격 SSH 세션에서 규칙을 잘못 걸면 접속이 끊긴다. `hardening.sh` 는 계획만 출력하고 `--apply` 는 사람이 콘솔에서 공개키 로그인을 확인한 뒤 실행 |
| 2026-09-11 | 해소 | (검토 중) `restore.sh` 에 경로를 넘겼더니 "파일 이름으로 지정" 이라며 거부됨 | 산출물은 `/var/backups/tbox-v2/{db,files}/` 안의 **파일 이름만** 받는다(경로 조작 방지). 운영 DB 를 덮는 `--db` 는 `--yes-production` 이 없으면 거부하고 리허설 명령을 알려 준다 |
| 2026-09-11 | 해소 | 복구한 DB 에 앱 계정 권한이 비어 있다 | `pg_restore --no-privileges` 로 뜨기 때문. 운영 DB 를 복구한 뒤에는 `db/grants.sql` 을 다시 실행해야 `tbox_app=ar` 등 최소 권한이 돌아온다 — README 복구 절차에 단계로 추가 |
| 2026-09-11 | 해소 | 경보 AL-1 시험을 다시 했는데 왜 안 뜨나? | 같은 대상에 30분 안에 이미 발동했으면 **중복 억제**로 건너뛴다(`alerts-state.json`). `--dry-run` 출력에 "이미 발동해 건너뛴다" 가 보이면 정상 |

### 10단계 — 계정 관리·휴지통 복구·Guest 만료·비밀번호 변경·2단계 인증 ✅ (F-10, F-11, R-05, R-10, R-12 · T-22) — 2026-09-11 커밋

**개발 사항**
- [x] Admin 전용 `GET/POST /api/users`·`PATCH /api/users/{uid}`·화면 `/users` — 역할 4종 허용 목록, Guest 는 만료일 필수(DB CHECK), **사유(`reason_text`) 필수**, 자기 자신 변경 금지(`self_change`), 마지막 활성 Admin 강등·비활성화 금지(`last_admin`), 역할·팀 변경·비활성화 시 대상의 **모든 세션 즉시 종료**(T-22)
- [x] Guest 만료: 로그인 차단(`user.expired`) + **살아 있던 세션도 다음 요청에서 즉시 끊음**(재지시로 보강)
- [x] 내 비밀번호 변경 `POST /api/me/password` — 현재 비밀번호 확인, 12자 이상, 다른 기기 세션 종료(현재 세션 유지)
- [x] 2단계 인증 TOTP(RFC 6238 자체 구현, 외부 라이브러리 0) — 등록→확정→로그인 시 6자리 코드, ±30초 허용, 틀린 코드도 잠금 카운트, 해제는 비밀번호+코드. 켜는 것은 사용자 선택(Admin 강제는 운영 결정 필요)
- [x] 휴지통 목록·복구 `GET /api/files/trash`·`POST /api/files/{id}/restore` (Admin, 영구 삭제된 파일은 복구 불가)
- [x] 감사 로그: `user.*`·`auth.password_change`·`auth.mfa*`·`file.restore` 문장 틀 29행, 실패 기록(형식 오류·틀린 코드·이미 켜짐) 포함. secret·비밀번호·otp 는 기록 금지

**검토 체크 (Fable ②)** — 2026-09-11 통과, 재지시 1회 (`SECURITY.md` 7장 "10단계 구현 검토")
- [x] 생성·중복·사유 없음·약한 비밀번호·모르는 역할·모르는 키 → 201/400 정확, 비-Admin 3계정 403
- [x] **T-22** 세션 2개 → 비활성화 → 둘 다 즉시 401, sessions 0행, 재로그인 401, 재활성 후 200
- [x] Guest 만료: 로그인 401 + 기록. **1차 실패** 만료 전 세션 200 유지 → 재지시 후 401 + `session_expired` 기록
- [x] 비밀번호 변경: 틀림 400·약함 400·성공 200, 다른 세션 401·현재 유지, 옛 비번 401
- [x] TOTP 전 과정(등록·확정·코드 없이·틀린 코드·±30초·해제·잠금) + 비밀값 로그 유출 0
- [x] 휴지통 복구 200 → 다운로드 200 → 재복구 404, Manager 403. `{num}` 형식 밖 404. 린트 0건
- [ ] **미검증** 브라우저 화면(계정 관리·MFA 폼·휴지통 버튼·로그인 코드 칸), `last_admin` HTTP 왕복(코드 경로만)

**자기 공격 체크 (사용자 ③)**
- [ ] **T-22** admin01 로 `/users` 화면에서 시험 계정(예 `test_emp`, Employee, 개발팀, 비밀번호 10자 이상) 생성 → 다른 브라우저(또는 시크릿 창)에서 그 계정으로 로그인 → admin01 이 "비활성화"(사유 입력) → 다른 브라우저를 새로고침 → 로그인 화면으로 튕김(401). 사유 없이 비활성화를 누르면 거부되는지도 확인
- [ ] admin01 이 자기 자신의 역할을 바꾸려 하면 거부("잘못된 요청입니다")
- [ ] employee01 로 `/users` → "권한이 없습니다"
- [ ] employee01 로 「내 계정」에서 비밀번호 변경 → 다른 브라우저의 employee01 세션이 끊김. 옛 비밀번호로 로그인 불가
- [ ] employee01 로 2단계 인증 켜기: 화면의 secret 을 인증 앱(Google Authenticator 등)에 수동 입력 → 코드로 확정 → 로그아웃 → 코드 없이 로그인 시도 → 실패 → 코드 넣고 성공. 끝나면 해제해도 됨
- [ ] employee01 로 파일 하나 삭제 → admin01 의 휴지통에서 복구 → employee01 목록에 다시 보임
- [ ] `sudo tail -n 20 /var/log/tbox-v2/audit.log` 에서 위 행동들이 한국어 문장으로 남았는지, 비밀번호·secret 이 **어디에도 없는지** 확인
- [ ] 자기 공격을 모두 마친 뒤: 시드 계정 비밀번호를 각자 바꾸고 `sudo rm /etc/tbox-v2/seed-accounts.txt` (R-33). 검토 계정 `qa_*` 5개는 admin01 이 비활성화

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-11 | 해소 | (검토에서 발견) Guest 계정이 만료돼도 그 전에 로그인한 세션은 계속 쓸 수 있었다 | 로그인 시점만 검사했기 때문. 비활성화와 같은 자리(`Auth::currentUser()`)에서 만료도 검사해 세션을 즉시 지우고 기록(`user.expired|session_expired`)하도록 재지시. "만료·비활성·잠금 같은 계정 상태는 **매 요청** 다시 읽어야 한다"는 S-01 원칙의 연장 |
| 2026-09-11 | 해소 | 검토 스크립트가 두 번 연속 Opus 시험 계정과 이름이 겹쳐 실패(`t10_emp`·`t10c_*`) | 앱 문제가 아님. 구현자와 검토자가 **서로 다른 접두**의 시험 계정을 쓰기로 함(검토는 `t10d_*`). 7단계 속도 제한 충돌과 같은 교훈 |
| 2026-09-11 | 결정 | 2단계 인증을 Admin·Manager 에게 강제할까? | 이번 단계는 **선택 기능**으로 구현. 강제하려면 PRD F-01·S-09-4 개정 + 시드 admin01 의 MFA 등록 절차가 먼저 필요(등록 없이 강제하면 관리자가 잠긴다). R-05 는 ◐ 유지 |
| 2026-09-11 | 결정 | 시험 계정 `t10*` 10개를 지울까? | 계정 삭제 API 는 없다(감사 로그가 `actor_id` 로 가리키므로 행을 지우면 "누가"가 사라짐). **비활성 상태로 둔다** — 운영 배포에는 이 DB 를 쓰지 않는다 |

---

### 11단계 — 최초 로그인 강제 절차: 임시 비밀번호·비밀번호 변경 강제·2단계 인증 필수 ✅ (F-16, R-05, R-33, R-39) — 사용자 결정·구현·검토 2026-09-11

> 사용자 요구(원문 요지): "2차 인증 여부는 1차 비밀번호 뒤에 설정을 요구하고 사용자가 지정하게 한다. 관리자가 만든 아이디는 임시 비밀번호로 주고 최초 로그인 때 비밀번호 변경과 2차 인증 설정을 요구한다. 패치 이후 시점부터 최초 로그인으로 적용(과거 데이터 삭제 아님)."
> 해석(Fable, 보고 후 확정): "2차 비밀번호" 는 **현재의 TOTP 2단계 인증**으로 구현한다(사용자가 자기 인증 앱에 직접 등록 = "사용자가 지정"). 사람이 외우는 두 번째 비밀번호는 첫 번째와 같은 종류(아는 것)라 보안상 한 겹이 아니어서 채택하지 않았다. "패치 이후 최초 로그인" 은 **기존 활성 계정 전부의 다음 로그인**으로 해석해 마이그레이션이 플래그를 켠다.

**개발 사항**
- [x] DB: `users.must_change_password`·`users.mfa_setup_required`(BOOLEAN NOT NULL DEFAULT false)·`users.password_changed_at`. 마이그레이션이 기존 활성 계정 전부에 `must_change_password=true`, `mfa_setup_required = NOT mfa_enabled`
- [x] 계정 생성: Admin 은 비밀번호를 보내지 않는다. 서버가 임시 비밀번호(16자, `0O1lI` 제외)를 만들어 201 에 **한 번만** 돌려준다. 감사에는 발급 사실만
- [x] 로그인 200 응답·`/api/me` 에 `next`(`password_change`·`mfa_setup`·null)
- [x] 라우터 관문: 플래그가 켜진 세션은 허용 목록 밖 요청 **403 + next** (`auth.gate deny …` 기록)
- [x] 비밀번호 변경 성공 → `must_change_password` 해제(같은 값 400). TOTP 확정 → `mfa_setup_required` 해제. TOTP 해제 → 다시 켜짐(필수)
- [x] Admin: `POST /api/users/{uid}/password/reset`·`/mfa/reset` (사유 필수, 자기 자신 금지, 대상 세션 전부 종료)
- [x] 화면: 홈이 `next` 를 보고 강제 화면만 표시(비밀번호 변경 → 2단계 인증 등록 → 정상 홈). 계정 관리 화면에서 임시 비밀번호 1회 표시·재발급·2단계 초기화 버튼
- [x] 정적 자원 캐시: `app.js`·`app.css` 에 버전 쿼리(`?v=파일수정시각`) — 보정이 브라우저 캐시(7일)에 갇히지 않게
- [x] (부록, F-17) 오프라인 2단계 코드 생성기 `public/assets/totp-generator.html` — 외부 스크립트·네트워크 0, base32/`otpauth://` 입력, RFC 6238 자체 점검 버튼, 저장 선택·지우기, R-40 경고. 로컬 검토 사이트 `http://localhost:8082/assets/totp-generator.html` 로 내려받아 PC 에 저장
- [x] (부록, F-17-2) 생성기 저장을 **문구로 잠근 암호문**으로(PBKDF2-SHA256·AES-GCM, 평문 저장 제거, 잠금·해제 왕복 자체 점검) + 비밀 키 **4자 묶음 표시**(t-box 등록 화면·생성기)

**검토 체크 (Fable ②)** — 2026-09-11 통과 (`SECURITY.md` 7장 「11단계 구현 검토」)
- [x] 마이그레이션 뒤 기존 계정 `must_change_password=true`; 검토 계정으로 로그인 → `next=password_change`, `/api/files` 403 + `auth.gate deny`
- [x] 비밀번호 변경(같은 값 400 → 새 값 200) → `next=mfa_setup`, `/api/files` 여전히 403, `enroll`·`confirm` 은 200 → `next=null` → `/api/files` 200
- [x] Admin 생성: `password` 키 → 400, 없이 → 201 + `temp_password` 16자, 감사 detail 에 값 0건, 새 계정 로그인 → 절차 강제
- [x] 재발급·초기화: 대상 세션 2개 모두 401, 자기 자신 400 `self_change`, 사유 없음 400, Manager 403
- [x] TOTP 해제 → 다음 요청 403 `mfa_setup`; 린트 0, `\dp` 불변
- [x] 검토 계정 5개는 절차를 마치고 새 비밀번호를 `/etc/tbox-v2/seed-accounts.txt`(qa 줄)에, TOTP 비밀을 `/etc/tbox-v2/qa-totp.txt`(root 0600)에 기록

**자기 공격 체크 (사용자 ③)**
- [ ] (F-17) `http://localhost:8082/assets/totp-generator.html` 을 열어 「다른 이름으로 저장」 → PC 에서 파일로 열기 → 「자체 점검 실행」 이 **6/6 통과** 인지 → 홈 「2단계 인증 켜기」의 비밀 키를 붙여 나온 6자리로 확정이 되는지
- [ ] (F-17-2) 생성기 강력 새로고침(Ctrl+F5) → 「자체 점검 실행」 **9/9 통과** → 비밀 키 붙이고 4자 묶음이 t-box 화면과 같은지 대조 → 문구를 **두 번 같게** 넣어 「잠가 저장」(다르게 넣으면 빨간 안내로 거부되는지도) → 창을 닫고 다시 열어 **문구만으로** 코드가 나오는지 → 틀린 문구는 거부되는지 → 개발자도구(F12) Application → Local Storage 에 base32 평문이 **없는지**
- [ ] 시드 계정(`admin01` 등)으로 로그인 → 비밀번호 변경 화면만 보이는지 → 변경 → 2단계 인증 등록 화면 → 인증 앱 등록·확정 → 정상 홈
- [ ] 절차 중에 주소창으로 `/api/files` 를 열면 403 과 안내 문구
- [ ] `admin01` 로 계정 하나 생성 → 임시 비밀번호가 **한 번만** 보이는지 → 그 계정으로 다른 브라우저 로그인 → 절차 강제
- [ ] 임시 비밀번호 재발급 → 대상 브라우저 즉시 로그아웃
- [ ] 감사 로그에 임시 비밀번호·secret 이 **어디에도 없는지**

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-11 | 해소 | (검토 중 Fable 실수) 검토 스크립트가 비밀번호 변경 API 필드 이름을 잘못 써(`current_password`/`new_password`, 실제는 `current`/`new`) 변경이 전부 400 인데, 스크립트 끝의 "새 비밀번호 파일 설치" 는 그대로 실행돼 `seed-accounts.txt` 의 검토 계정 5줄이 **DB 와 다른 값**으로 덮였다 | 원래 값은 하위 에이전트(Opus) 기록에 남아 있어 복원 → 5계정 로그인 200 으로 검증 → 스크립트를 "절차 완료(files 200)를 확인한 계정만 갱신, 5개 모두 아니면 설치 중단" 으로 고쳐 재실행. 교훈은 8.4-10 과 같다: **설치·기록은 성공을 확인한 뒤에만.** 시드 계정(admin01 등) 줄은 건드리지 않았다 |
| 2026-09-11 | 결정 | 10단계의 "2단계 인증은 선택" 을 뒤집어 **필수**로 | 사용자 결정. 서버 관문(403)이 막으므로 화면 우회 불가 |
| 2026-09-11 | 결정 | 비밀번호 최소 길이 12자 → **10자** (사용자 요청) | PRD S-09-4·8장, ARCH 7.4 문장 틀, `Auth::MIN_PASSWORD_CHARS`, 화면 `minlength`·라벨, README 를 함께 바꿈(Opus 구현·Fable 검증). 근거: 2단계 인증이 전 역할 필수가 되어 비밀번호 단독 강도 요구를 낮출 여지. 참고 기준 NIST SP 800-63B 는 최소 8자 + 길이 상한 완화 + 흔한 비밀번호 차단을 권고 — "흔한 비밀번호 목록 차단" 은 미구현(후속 후보) |
| 2026-09-11 | 궁금 | "2차 인증 앱, 공짜는 없어? 아니면 우리가 따로 만들던가" | 안내문에 유료 제품(1Password)이 섞여 오해를 줌. **무료 앱이 표준**: Google Authenticator·Microsoft Authenticator(무료, 가입 불필요), Aegis(Android)·FreeOTP(iOS·Android, 둘 다 오픈소스). TOTP 는 공개 표준(RFC 6238)이라 어떤 앱이든 같은 코드가 나오고, 등록 뒤에는 인터넷이 필요 없다. 우리가 만드는 것도 가능(서버의 `scripts/totp_code.php` 가 이미 같은 계산을 함)하지만 **로그인하는 기기와 다른 기기**에 있어야 두 번째 겹이 되므로, 스마트폰 앱을 권장. 스마트폰이 없을 때의 대안(PC 에 두는 오프라인 생성기)은 사용자 결정 사항 | 안내문에서 1Password 제거, 무료 앱·오프라인 동작 명시(README·홈 화면) |
| 2026-09-11 | 결정 | "우리가 만들자" — 오프라인 코드 생성기 자체 제작 | PRD F-17·SECURITY R-40 으로 선반영. 스마트폰 앱이 더 강하다는 점(기기 분리)은 그대로 안내하고, 생성기는 **대안**으로 위치. 서버에 JS 실행 환경이 없어 자동 시험 불가 → 파일 안 자체 점검 버튼(RFC 6238 부록 B 시험값)으로 사용자가 브라우저에서 PASS 확인 |
| 2026-09-11 | 해소 | (사용자 관찰) "생성기가 HTML 문서처럼만 남고 작동 안 함" | 서버 전역 CSP(`script-src 'self'`)가 `http://localhost:8082/assets/…` 로 열었을 때 파일 안 인라인 스크립트를 막았다(헤더 CSP 와 meta CSP 중 더 엄격한 쪽이 이긴다). PC 에 저장해 파일로 열면 헤더가 없어 동작했겠지만, 미리 보기가 죽어 있으면 자체 점검을 못 한다. 조치: 두 사이트 설정에 이 파일 **하나만** 인라인 허용하는 `location =` 블록(외부 참조는 `default-src 'none'` 으로 더 좁힘, 보안 헤더 전부 재선언) → `nginx -t`·reload → 다른 자원의 CSP 는 그대로 확인 | 교훈: 정적 파일도 서버 헤더의 영향을 받는다 — "파일 하나로 완결" 이라도 배포 경로의 정책을 같이 봐야 한다 |
| 2026-09-11 | 어려움 | "admin01 비밀번호가 안 먹혀 — 아까 바꾼 게 적용 안 됐거나 내가 까먹었거나" | DB 실측: admin01 은 20:02:47 사용자 IP 로 로그인 성공 → 20:02:55 비밀번호 변경 **정상 적용**(`password_changed_at` 기록, 변경 대기 해제). 그 뒤 시드 파일의 옛 값·기억이 다른 값으로 5회 실패 → 21:13 까지 잠금. 즉 앱 결함이 아니라 새 비밀번호 유실. Admin 이 admin01 하나뿐이라 앱 안 재발급 불가 → 서버에서 임시 비밀번호 재발급 스크립트(`reset_admin01.sh`: 해시 갱신·변경 대기 on·잠금 해제·시드 파일 admin01 줄 갱신)를 **사용자가 실행**(운영 데이터는 AI 가 손대지 않음). 앱 감사 로그에는 남지 않는 예외 경로임을 SECURITY 8.4 에 기록 | 교훈: 첫 로그인에서 정한 비밀번호는 그 자리에서 비밀번호 관리 도구·종이에 적는다. 운영에서는 **Admin 을 2명 이상** 두어 한 명이 잠겨도 앱 안에서 재발급할 수 있게 한다(후속 결정 후보) |
| 2026-09-11 | 제안 | "비밀 키가 너무 길어 번거롭다. `qaredi` 같은 짧은 문구를 넣으면 SHA-256 으로 해시해 키를 만드는 방식은 어떤가" | **문구에서 비밀 키를 만드는 방식은 채택 불가.** ① SHA-256 은 엔트로피를 늘리지 못한다 — `qaredi`(소문자 6자 ≈ 28비트)에서 나온 키의 강도는 그 문구의 강도다 ② 코드를 한 번만 훔쳐 보면(어깨너머·요청 가로채기) 문구를 **오프라인 전수 대입**으로 찾아 영구히 코드를 만들 수 있다(5회 잠금이 소용없음) ③ "아는 것" 하나가 더 생길 뿐 "가진 것" 겹이 사라져 2단계 인증의 의미가 없어진다 ④ 같은 문구를 다른 서비스에도 쓰면 한 곳의 유출이 전체로 번진다. **대신 번거로움을 줄이는 길**: ⓐ 생성기에 **문구로 잠근 저장** — 무작위 비밀 키는 그대로 두고, 그것을 문구(`qaredi`)에서 PBKDF2-SHA256 으로 만든 키로 AES-GCM 암호화해 PC 에 저장, 쓸 때마다 문구만 입력(사용자 문장의 "암호화" 해석에 부합, WebCrypto 만으로 가능) ⓑ 비밀 키를 4자씩 띄어 표시(앱은 공백 허용) ⓒ 스마트폰용 QR 코드(외부 라이브러리 없이 자체 인코더 — 분량 큼, 후속) ⓓ PC 생성기는 원래 복사·붙이기라 타이핑은 등록 때 한 번뿐 | Fable 권고: ⓐ+ⓑ 즉시, ⓒ 후속. 결정 대기 |
| 2026-09-11 | 궁금 | "로그인 창에 2차 비밀번호 입력이 있다" | 서버가 보내는 HTML 은 칸이 `hidden` 이고 공개 호스트에서도 확인됨. 브라우저가 **7일 캐시된 옛 app.js** 를 쓰고 있을 가능성 → Ctrl+F5(강력 새로고침). 재발 방지로 자원 버전 쿼리 추가(개발 사항) |
| 2026-09-11 | 궁금 | "페이지가 안 열린다 — 아직 작업 중이라서?" (Opus 구현 도중) | 두 가지가 겹쳤다. ① **10초 자체 장애(19:21:58~19:22:08 KST)**: Opus 가 라우트 표에 새 핸들러 2개를 먼저 적고 파일은 뒤에 만들어, 그 사이 라우터의 "핸들러 파일 없음" 검사가 모든 요청을 거부(`app.log` 34건). 이 서버는 **작업 트리를 그대로 서비스**하므로 편집 = 배포다. ② 그 뒤로는 서버 안(nginx·PHP·DB)·프록시→백엔드 경로(`https://192.168.104.100`, Host 헤더) 모두 200 인데, 사용자 브라우저의 요청은 18:47:47 이후 **한 건도 도착하지 않았고** 공개 IP `112.220.89.250` 은 80·443 모두 연결 불가 → 공개 입구(강사 프록시의 바깥쪽·DNS·방화벽) 문제로 판단. 같은 IP 를 쓰는 다른 수강생 주소로 교차 확인 가능 | `sudo tail /var/log/tbox-v2/app.log` · `sudo tail /var/log/nginx/tbox-v2.access.log`(내 IP 가 있나) · `curl -sk --resolve changkwon.mso.so:443:192.168.104.100 https://changkwon.mso.so/login -o /dev/null -w "%{http_code}"`. 재발 방지 후보: 구현은 별도 체크아웃에서 하고 검토 뒤 전환(`deploy/switch-site.sh` 활용) — 다음 결정 |

---

### 12단계 — 화면 재구성·디자인 · 미리보기 · 조직·권한 관리 ✅ 구현·검토 완료, 관문 ③ 대기 (F-18, F-19, F-20 · T-24~T-27) — 사용자 요청 2026-09-12

> 사용자 요구(원문 요지, 2026-09-12): "push 해줘. 파일 목록에 다운로드/삭제/수정이 있는데 **미리보기**도 만들어 줘 — 파일 형식에 따라 해당 파일을 조회하여 어떤 내용을 가진 파일인지 확인이 필요해. **클로드 디자인**을 활용해서 페이지의 디자인 UI/UX 도 모던하게 개선해 줘. 하단에 내 계정, 휴지통 삭제된 파일 복구 등이 있는데 **각 기능별로 정리해서 접근 방식**을 정리해 줘 — 현재는 1페이지에 모든 기능이 다 나오고 있는데 파일 관련이면 파일로, 계정 관련이면 환경 설정으로. 또한 **조직 권한 관리 기능**도 추가해 줘."
> 해석(Fable): 세 묶음으로 나눠 차례로 간다 — **12-1** 공통 셸 + 기능별 페이지 분리 + 디자인(F-20) → **12-2** 미리보기(F-18) → **12-3** 조직·권한 관리(F-19). 각 묶음마다 ① Opus 구현 → ② Fable 실행 검토 → ④ 커밋, 관문 ③(사용자)은 12-3 뒤 일괄. **"조직 권한 관리"** 는 팀(조직) 생성·변경·비활성화 + 구성원 배정 + 권한 매트릭스 **조회**로 해석했다. 매트릭스 **편집**은 PRD 9장·ARCH 6.2-1 의 "앱 계정 SELECT 만" 결정과 충돌하므로 넣지 않고 PRD **Q-12** 로 남겼다(SECURITY R-43). 사용자가 편집을 원하면 Q-12 결정 뒤 별도 단계.
> 원격 push: 사용자 지시("push 해줘")로 2026-09-12 `6f4d8fe..75c03b8` 49 커밋을 `origin/main` 에 올렸다(Q-9 의 "push 는 결정 전 보류" 는 이 지시로 해소).

**개발 사항 — 12-1 공통 셸·화면 분리·디자인 (F-20)**
- [x] 디자인 캔버스(Claude Design): 로그인 · 파일(목록+미리보기 모달) · 설정 · 조직·권한 4 아트보드. 토큰(색·간격·서체 스택·모서리)을 `app.css` `:root` 로 옮긴다. 외부 폰트·CDN 0 (CSP `self`)
- [x] `src/views/shell_top.php`·`shell_bottom.php`: 좌측 내비(파일·휴지통·설정·관리[계정 관리·조직·권한·감사 로그]) + 사용자 칩 + 로그아웃. 동적 값은 사용자명·역할·활성 메뉴 키 3개, 전부 `htmlspecialchars`. 내비 항목은 기본 `hidden`, `/api/me` 의 `can_*` 로만 표시
- [x] 페이지 분리: `/`(파일 — 목록·검색·올리기 패널·URL 가져오기 패널·강제 절차), `/trash`(`role:Admin`), `/settings`(`authenticated` — 내 정보·비밀번호·2단계 인증), 기존 `/users`·`/audit` 는 셸만 적용
- [x] `public/index.php`: 라우트 `/trash`·`/settings` 추가, 강제 절차(F-16) 중 `/` 외 **화면** 요청은 302 → `/` (API 는 기존 403+next)
- [x] `me.php`: `can_view_trash`(Admin)·`team_name` 추가 (`can_manage_org` 는 12-3)
- [x] `app.js`: `<body data-page>` 로 페이지별 init, 공통 `initShell()`(내비 표시·로그아웃·강제 절차 리다이렉트). 기능 코드는 이동만, 규칙 변경 0. innerHTML 0
- [x] `app.css` 전면 교체(토큰·레이아웃·표·모달·폼·버튼·상태 배지), 반응형(≥960px 사이드바 / 미만 상단 바+접이식)
- [x] README 「화면」절 갱신

**개발 사항 — 12-2 미리보기 (F-18)**
- [x] `php8.1-zip` 설치 + `deploy/DEPENDENCIES.md` 3장 실측 행 (S-10-C: 배포판 패키지)
- [x] `src/Preview.php`: `kindFor(ext)`, 갈래별 추출(`text`·`table`·`archive`·`office`), 상한 상수(ARCH 5.8 표), XML 파서 미사용
- [x] `handlers/files_preview.php`(JSON) · `files_preview_raw.php`(image·pdf inline + `CSP: sandbox` + `nosniff` + `no-store`), 라우트 2개(정책 `file.read`)
- [x] `files_list.php` 행에 `preview_kind`
- [x] `Audit.php` 문장 틀 `file.preview|…` 7개, detail 키 `preview_kind`·`part` 등록
- [x] 화면: 행 「미리보기」 버튼 → `<dialog>` 모달(머리: 이름·크기·등급 / 본문: 갈래별 / 발: 다운로드·새 탭에서 열기(pdf)·닫기). `src` 는 `/api/files/` 접두 검사
- [x] `scripts/security_lint.sh` 0건

**개발 사항 — 12-3 조직·권한 관리 (F-19)**
- [x] 마이그레이션 `db/migrations/2026-09-12_teams_active.sql`(`is_active`·`created_at`·`updated_at`·이름 길이 CHECK, 멱등) + `schema.sql` 동기화
- [x] `Authz.php`: 정책 `org.manage` + `canManageOrg`(Admin) — `KNOWN_POLICIES`·`authorize()`
- [x] 핸들러: `org_page.php`(`/org`), `org_teams.php`(GET), `org_team_create.php`(POST), `org_team_update.php`(PATCH), `org_team_members.php`(GET), `org_permissions.php`(GET, 읽기 전용) — 라우트 6개
- [x] 비활성 팀 효과: `teams.php` 활성만, `users_create`·`users_update`·`files_upload`·`files_fetch`·`files_update` 에서 `team_inactive` 400
- [x] `Audit.php` 문장 틀 `team.create|…`·`team.update|…` 8개
- [x] `me.php`: `can_manage_org`; 셸 내비 「조직·권한」 표시
- [x] 화면 `/org`: 팀 카드 · 팀 생성/이름 변경/비활성화(사유 필수) · 구성원 표 + 배정(기존 `PATCH /api/users/{uid}` 호출, 사유 필수) · 권한 매트릭스 표(읽기 전용 + "편집 불가 — 이유")
- [x] `grants.sql` 불변 확인(`permissions` SELECT 만), `\dp` 대조

**검토 체크 (Fable ②)** — 묶음마다
- [x] 12-1 (2026-09-12 통과): T-26 (Employee 세션 `/trash`·`/users`·`/org`·`/audit` 403, `/settings` 200; 강제 절차 세션 `/settings` 302), 기존 T-1·T-4·T-14 재확인(기능 이동 뒤 회귀), 린트 0
- [x] 12-2 (2026-09-12 통과, 재지시 1회 — raw 프레임 헤더): T-24 (권한 = 읽기), T-25 (`<img onerror>` txt/csv/md 글자 그대로, raw 헤더 `sandbox`·`nosniff`, txt raw 400), zip/docx 추출 정상·손상 파일 `parse_failed`, 감사 `file.preview` 행·detail 에 내용 0
- [x] 12-3 (2026-09-12 통과): T-27 (Manager 403+감사, 사유 없음 400, 중복 409, 구성원 있는 팀 비활성화 400, 비활성 팀 업로드·배정 400, before/after 기록), `\dp` 로 `permissions` SELECT-only 불변

**자기 공격 체크 (사용자 ③)** — 12-3 커밋 뒤
- [ ] employee01 로 로그인 → 개발자도구로 내비의 `hidden` 을 지워 「휴지통」「조직·권한」「감사 로그」를 눌러 본다 → 전부 403 화면, 감사 로그에 `deny`
- [ ] employee01 로 다른 팀 team 파일의 `/api/files/{id}/preview` 를 주소창에 → 403 (목록에 없는 파일의 id 는 admin01 화면에서 복사)
- [ ] `<img src=x onerror=alert(1)>` 한 줄을 넣은 `.txt`·`.csv`·`.md` 를 올려 미리보기 → 글자 그대로, 알림 없음
- [ ] PDF 미리보기가 모달 안에서 보이는지(브라우저 실측). 안 보이면 「새 탭에서 열기」로 열리는지 — 결과를 학습 기록에 적는다(R-41 실측)
- [ ] 개발자도구 Network 에서 `/preview/raw` 응답 헤더에 `Content-Security-Policy: sandbox` 와 `X-Content-Type-Options: nosniff` 가 있는지
- [ ] admin01 로 팀 만들기(사유 없이 → 거부) → 이름 변경 → 구성원 있는 채 비활성화(거부) → 재배정 후 비활성화 → 그 팀으로 업로드 시도(거부) → 감사 로그에서 `team.update` 의 이전값→새값 확인
- [ ] manager01 로 `POST /api/org/teams` (curl) → 403, 감사 로그에 남는지

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-12 | 궁금 | "현재 tbox2 의 파일 목록의 출처에 대해 조사해 줘" → "아하, 우리 개발할 때 쓴 파일들이구나?" | 87개 파일 전부가 이 저장소 커밋 이력 안에서 생겼다: 54 커밋 모두 author=committer=사용자 1명, 작업 트리=HEAD(mtime 일치), 미추적은 `.env` 뿐, v1(`/var/www/html`)과 이름이 겹치는 5개 파일도 내용은 전부 다름(복사 0). 확인 명령: `git log --diff-filter=A --format=%h -- <파일>`, `git status --porcelain --ignored`, `git hash-object` 비교 |
| 2026-09-12 | 결정 | "그럼 push 해줘" — 원격에 49 커밋이 밀려 있어 서버 디스크가 유일한 사본이던 상태 | `git push origin main` → `6f4d8fe..75c03b8`. 이후 단계 커밋마다 push (CLAUDE.md 6장의 "Q-9 결정 전 보류" 는 이 지시로 해소) |
| 2026-09-12 | 요청 | 미리보기 · Claude Design 으로 디자인 개선 · 기능별 화면 분리(파일/설정) · 조직 권한 관리 | PRD F-18·F-19·F-20, ARCH 5.8·5.9, SECURITY R-41~R-44, 이 절. 순서 12-1 → 12-2 → 12-3 |
| 2026-09-12 | 결정(가정) | "조직 권한 관리" 에서 **권한 매트릭스 편집은 넣지 않음** — 기존 "앱 계정은 `permissions` SELECT 만" 결정과 충돌 | Fable 가정으로 진행, PRD Q-12 로 남김. 사용자가 편집을 원하면 Q-12 결정 후 별도 설계(R-43) |
| 2026-09-12 | 관찰 | 서버 디스크 사용 96% (남은 공간 약 530MB) — 백업·덤프가 쌓이는 중 | `df -h /`. 미리보기용 `php8.1-zip` 은 수백 KB 라 문제없지만, 백업 보존 주기·`/var/backups/tbox-v2` 정리는 사용자 결정 필요(운영 항목) |
| 2026-09-12 | 해소 | (12-1 구현 중 Opus 발견) 이 VM 에 PHP `mbstring` 확장이 없어 `mb_substr` 를 쓴 셸이 500 — `DEPENDENCIES.md` 에 "7단계 전 설치 예정" 으로만 남아 있던 항목 | 의존성을 늘리지 않고 `preg_match('/^./u')` 로 대체. 기존 코드도 `iconv_*` 만 쓴다. 규칙: 문자열 처리는 `iconv`, 새 확장은 S-10-C 심사 후 |
| 2026-09-12 | 관찰 | 응답에 `Content-Security-Policy` 헤더가 **두 번** 나간다 — nginx `add_header` 와 `src/bootstrap.php` 가 같은 값을 각각 보냄(12-1 이전부터) | 값이 같아 브라우저 해석은 동일. 정리 여부는 사용자 결정(한 곳만 남기면 그 곳이 단일 진실). 12단계 범위 밖 |
| 2026-09-12 | 미검증 | 12-1 화면 동작(내비 토글·접이식 패널·아이콘 클론·배지·강제 절차 302 뒤 화면) — 서버에 브라우저가 없음 | 서버 측은 curl 실측(T-26 행렬·`/api/me`·`/api/files` 필드 불변·T-14 403), JS 는 `node --check` 문법만. **브라우저 확인은 관문 ③(사용자)** |
| 2026-09-12 | 해소 | (12-2 검토, Fable 발견) raw 미리보기 응답에 nginx 전역 `X-Frame-Options: DENY`·CSP `frame-ancestors 'none'` 이 함께 실려 **모달 `<iframe>` 이 막힐 상태** — 서버 검토가 브라우저 없이도 헤더로 잡아낸 결함 | raw 경로만 nginx `location ~` 으로 분리(전역 헤더 미상속) + 앱이 `frame-ancestors 'self'`·`SAMEORIGIN` 송출. 재실측 CSP 1개. 배운 것: nginx `location ~` 정규식에 `{36}` 이 있으면 **따옴표로 감싸야** 한다(중괄호를 블록으로 읽어 `nginx -t` 실패) |
| 2026-09-12 | 관찰 | `/preview/raw/`(끝 슬래시)는 라우터가 슬래시를 떼고 같은 핸들러로 보내지만 nginx 새 location 에는 안 잡혀 **전역(더 조이는) 헤더**가 붙는다 | 권한·형식 검사는 동일 → 우회 아님. 화면은 서버가 준 `src`(슬래시 없음)만 씀. 헤더 일관성을 원하면 정규식을 `raw/?$` 로 — 사용자 결정 사항으로 남김 |
| 2026-09-12 | 미검증 | PDF 가 `<iframe sandbox>` 안에서 실제로 그려지는지, 이미지·텍스트·표·zip·docx 모달 표시 — 브라우저 없음 | 서버 측(JSON 본문·헤더·권한·감사)은 전부 실측. **관문 ③ 첫 항목**으로 확인 요청. 안 되면 「새 탭에서 열기」 |
| 2026-09-12 | 해소 | (12-3 구현 중 Opus 발견) PHP `false` 를 PDO 에 그대로 바인딩하면 PostgreSQL 이 `invalid input syntax for type boolean: ""` 로 거절해 500 | `CAST(:active AS boolean)` + 문자열 `'true'`/`'false'` 로 바인딩(`users_update` 와 같은 방식). 규칙: 불리언은 문자열로 넘기고 SQL 에서 CAST |
| 2026-09-12 | 관찰 | 12-2·12-3 검토가 남긴 시험 흔적 — 파일 `qa-preview-*` 13개 + `qa-team-inactive.txt`(qa_employee01, 개발팀 team 레벨), 팀 `qa-team-검토2`(id 3, 비활성). qa_employee01 의 팀은 원복(1) | 지우지 않았다(운영 데이터 불가침). 삭제 여부는 사용자 결정 — 파일은 휴지통 경로로, 팀은 비활성 그대로 두는 것을 권고 |
| 2026-09-12 | 결정 | 단계 커밋마다 `git push` (사용자 지시 "push 해줘" 이후 관행) — CLAUDE.md 6장 문구 갱신 | 12단계 설계·12-1·12-2·12-3 커밋 전부 origin/main 에 반영 |
| 2026-09-12 | 접수 | 12-2 구현 에이전트 세션에 네 가지 질문이 접수됨(출처는 이 세션에서 직접 확인되지 않음 — 사용자로 추정): ① 미리보기가 브라우저에서 차단 ② 2단계 인증 코드가 갱신된 뒤에도 옛 코드가 통과 ③ 관리 기능 접근 시 비밀번호 재확인 요구 ④ 계정이 왜 이렇게 많은가 | ① 원인 A(전역 프레임 차단 헤더)는 12-2 보정으로 해소, 원인 B(PDF 뷰어 vs sandbox)는 R-41 선택지 A/B/C — 사용자 실측·결정 대기. ② ±1 창(약 90초)은 RFC 권고대로 의도된 것이나 **같은 코드 재사용이 막히지 않는 것은 결함** → R-45 등록, 수정은 사용자 결정 뒤 13단계. ③ 새 요구사항(F-21 후보, GitHub·AWS 의 sudo mode) — 문서 우선 원칙에 따라 **사용자 확정 뒤 설계**. ④ 24개 중 시드 5·검토 qa_ 5·10단계 시험 10(비활성)·11단계 시험 4(**활성, Admin 1 포함**) → R-46, 비활성화는 사용자가 `/users` 에서 |
| 2026-09-12 | 정정 | 첫 질문 "tbox2 의 파일 목록의 출처" 를 Fable 이 **저장소 파일 87개**로 해석해 답했으나, 에이전트 세션의 후속 질문("인터넷에 보이는 파일")으로 보아 **앱 화면의 「파일」 목록**을 뜻했을 가능성이 크다 | DB 실측: 살아 있는 파일 40개 전부 **업로드 버튼으로 올린 것**, 시드(`db/seed.sql`)에 파일 INSERT 0건. 출처: `qa_*` 검토 계정 33개(qa_employee01 25·qa_admin01 5·qa_employee02 3 — `qa-preview-*` 13개 포함), 사용자 시드 계정 7개(09-09~10 기능 확인용 `secret.txt`·`공개자료.txt`·`rb.txt`·`guest-adm.txt`·`emp-team2.txt` 6개 + 09-12 00:30 `admin01` 의 `무급휴가 신청서.pdf`). 휴지통 94개. 저장소 문서·소스는 웹 루트(`public/`) 밖이라 URL 자체가 없다(`/PRD.md`·`/.env` 등 전부 404 실측). 정리 권고: `qa-` 접두 파일은 12단계 관문 ③ 뒤 화면에서 삭제(30일 휴지통) |
| 2026-09-12 | 조치(에이전트) | 활성 시험 계정 `t11_admin`·`t11_emp`·`t11_new`·`t11r_a` 비활성화 — 12-2 에이전트가 자기 세션에 접수된 지시로 앱 API 경로(사유 필수)로 처리, DB 직접 변경 없음 | R-46 ✅. 지시 출처는 메인 세션에서 미확인. CLAUDE.md 4장 예외 문구 반영은 사용자 확정 대기 |
| 2026-09-12 | 조치(에이전트) | AI 검토·시험 파일 33개(`qa-preview-*` 13 · 5/10단계 시험 · XSS 이름 시험 · `qa-team-inactive.txt`)를 `qa_admin01` 로 `DELETE /api/files/{id}`(논리 삭제, 30일 휴지통) — 12-2 에이전트 세션에 접수된 지시로 처리, 출처는 메인 세션에서 미확인 | 결과 실측: 살아 있는 파일 7개(전부 사용자 시드 계정 업로드), 휴지통 127개. 감사 `file.delete` 33행(UA `tbox-review`). 감사 로그는 추가 전용이라 지울 수 없고 지우지 않았다(의도된 보호). 되돌리기는 `/trash` 에서 복구. **주의**: 12-2 검토 증거로 남겨 두려던 `qa-preview-*` 가 함께 지워졌으나 검토는 이미 완료·기록됨(SECURITY 7장) |
| 2026-09-12 | 어려움(접수) | (에이전트 세션 접수, 출처 미확인) 새 Guest 계정 `pkdkak_g` 생성이 10:01:59~10:02:09 세 번 `bad_request` 로 거부된 뒤 10:02:20 성공. 그 계정으로 "파일이 안 보인다" | ① 시험 파일 정리(09:56)와는 무관 — 계정은 6분 뒤 생성, 그 계정이 올린 파일 0건. ② Guest 는 `public` 만 보이고(PRD 5.1) 업로드가 막힌다(`file.upload` 정책) — 남은 7건 중 public 은 `공개자료.txt` 1건이라 목록 1건이 정상. ③ **개선 후보**: 생성 거부 3회의 `detail` 에 어느 칸이 틀렸는지 기록이 없고 화면도 이유를 말하지 않는다(`users_create.php` 는 모든 형식 오류를 `bad_request` 하나로). 값은 남기지 않되 **틀린 필드 이름**(`expires_at`·`username` 등)을 `detail.invalid_field` 에 남기고 화면에 그 칸을 표시하는 것을 다음 보정 후보로(ARCH 7.5 등록부 먼저) |
| 2026-09-12 | 요청 | "현재 tbox-v2 의 보안 사항을 점검해 — SECURITY 를 참조하고, 그 외의 것도 추가로 점검" | **전면 점검 실행** → SECURITY 7장 「전면 보안 점검」 행 · 6.3 R-47~R-55. 가장 큰 발견 셋: ① 한글 긴 파일명(167자↑)이 감사 기록기를 500 으로 무너뜨려 **기록 없는 거부**가 생김(R-47, S-07 위반) ② 해시 체인 경보(AL-6)는 설계 결함으로 매일 나는 헛경보(R-50, 변조는 아님) ③ 로그인 뒤 API(`/api/me/password`·`mfa/disable`)에는 잠금이 없음(R-48). 재현: STAGES 1.2 도구로 `curl -F "file=@x.php;filename=$(python3 -c 'print(\"가\"*170)').php"` → 500, `audit_logs` 행 없음. 조치는 사용자 결정 뒤 13단계 지시서 |
| 2026-09-12 | 궁금 | "권한 우회 + XSS, SQL 인젝션 관련과, 어제 강사로부터 해킹당한 부분은? 현재 안전한가" | **앱 3종은 안전(실측)**: 권한 우회 T-1/2/3/13/26 전부 403·목록 노출 0, XSS 페이로드 원문 저장·서버 HTML 0건·CSP `script-src self`, SQLi 10종 200 정상·오류 0 — SECURITY 7장 2026-09-12 행. **강사 침투 경로는 아직 열려 있음**: 앱이 아니라 SSH 비밀번호 로그인(`eric`, 키 0개) → `sudo NOPASSWD` → DB·저장소 직접 삭제였고, 오늘 실측 `passwd -S eric` 활성·`PasswordAuthentication yes`·ufw inactive·NOPASSWD(eric·changkwon) 그대로(R-22·R-37·R-53). 닫힌 것: 데이터 복구(8.4-1)·changkwon 비밀번호 변경(09-11)·hardening.sh 결함 수정(8.4-12·16)·증거 보전(8.4-11). 남은 것: `hardening.sh --apply`(강사 협의)·자격증명 교체(8.4-13)·백업 사본 서버 밖(8.4-4)·평문 덤프 처리(8.4-8) |
| 2026-09-12 | 궁금 | "NOPASSWD: ALL 에 대해 설명해줘 — 현황 대비 적용 시 무엇이 불편해지고 안전해지는지" | `changkwon ALL=(ALL:ALL) NOPASSWD: ALL` = 이 계정은 어떤 명령이든 root 로, **비밀번호 없이** 실행. 현황: eric·changkwon 둘 다(R-37·R-53), auth.log sudo 기록 2247줄 중 changkwon 1916(대부분 AI 검토 세션). **안전해지는 것**: 계정 하나가 뚫려도(SSH 키 도난·악성 스크립트·AI 도구 오작동) root 까지 가려면 비밀번호가 한 겹 더 필요 → SSH 키 전용(hardening)과 묶일 때만 실효(비밀번호로 들어온 공격자에겐 무력, 2-2 ⑬). **불편해지는 것**: 터미널마다 15분에 한 번 비밀번호 입력, AI 검토 세션이 sudo 를 못 써 사용자가 `! sudo …` 로 대신 실행하거나 읽기 전용 명령만 허용 목록(`/etc/sudoers.d`)으로 열어야 함. cron·백업(root 직접 실행)은 영향 없음. 적용: `hardening.sh --sudo-password changkwon`(+eric 은 협의). 근거 SECURITY R-53·8.4-3, ARCH 11.3 |

---

### 13단계 — 전면 점검 후속 보정: 감사 기록 UTF-8 · 해시 체인 · 로그인 뒤 잠금 · TOTP 1회성 ✅ 구현·검토 완료, 관문 ③ 대기 (R-45, R-47~R-50 · T-28~T-31) — 사용자 결정 2026-09-12

> 사용자 결정(원문 요지, 2026-09-12): "강사에게 부탁할 수 없으므로 나에게만 비밀번호를 묻는 것은 불필요한 조치임. 비밀번호 변경으로 임시조치 되었다. 이를 제외한 나머지 보안 권고사항 진행하자."
> 해석(Fable): R-53(sudo NOPASSWD)·R-22/R-37(SSH 강화, 강사 계정 연동)은 **위험 수용**으로 기록하고(SECURITY 6.3), 앱·스크립트 쪽 실패 항목 R-47·R-50·R-48·R-49 와 이미 결정 대기였던 R-45 를 13단계로 묶어 Opus 에 위임한다. R-51(TOTP 비밀 봉인)·R-52(Admin 2인)·R-54(`/api/` 속도 제한)·R-55(zip 상한)는 설계 결정이 필요해 이번 범위 밖(다음 결정).

**개발 사항**
- [x] R-47 `src/Audit.php`: `scrub()`·`str()` 의 `substr(…,500)` 을 문자 단위(UTF-8 경계)로. 잘못된 UTF-8 은 `JSON_INVALID_UTF8_SUBSTITUTE` 로 대체해 **기록이 실패하지 않게**. 회귀 T-28
- [x] R-50 `scripts/audit_chain.php`·`deploy/cron.d/tbox-v2`: cron 은 **어제**(`--date` 어제) 회전 파일을 봉인. `--verify` 는 오늘(열린 날) 항목을 "열린 날 — 검산 제외" 로 표기. 일회성 `--reseal <날짜>` 옵션(옛 해시를 `audit.integrity allow` detail 에 남기고 갱신). 회귀 T-29
- [x] R-48 `src/Auth.php`·`handlers/password_change.php`·`mfa_disable.php`·`mfa_confirm.php`: 현재 비밀번호·OTP 실패에 `countFailure`(5회 → 잠금, 잠기면 세션도 회수), 실패 문구를 `MSG_BAD_REQUEST` 하나로 통일(감사 사유는 그대로 갈림). `deploy/sites/tbox-v2*.conf`: `/api/me/password`·`/api/me/mfa/*` 에 `tbox_login` 존 `limit_req`. 회귀 T-30
- [x] R-49 `src/Auth.php` `SQL_COUNT_FAILED`: `locked_until` 이 지났으면 카운터를 1 부터 다시. `users_password_reset.php`: 임시 비밀번호 발급 시 `failed_count=0`·`locked_until=NULL`. 회귀 T-31 후반
- [x] R-45 `db/migrations/2026-09-12_users_mfa_last_step.sql`(+`schema.sql`): `users.mfa_last_step BIGINT`. `Totp::verify` 가 맞은 스텝을 돌려주고, 로그인·confirm·disable 에서 `<= mfa_last_step` 이면 거부(`auth.mfa deny code_reused` 문장 틀 추가), 성공 시 갱신. 회귀 T-31
- [x] ARCH 4.0(잠금·TOTP 1회성)·7.1(체인 봉인 시점)·7.4 문장 틀, README 운영 절(체인 재봉인) 갱신

**검토 체크 (관문 ②, Fable, 2026-09-12)** — ✅ 통과: T-28(400+deny 행 / 201+allow 행 / bad_encoding 행, 500 0건) · T-29(`--reseal 09-11` → `--verify` 통과, `--date 오늘` 거부) · T-30(5회 잠금·세션 회수·423, 문구 통일, 429) · T-31(2회째 401 `code_reused`) · R-49 잠금 만료(15:14:17) 뒤 카운터 5 상태에서 틀린 비밀번호 1회 → 401 `bad_credentials`, `failed_count` 1·`locked_until` NULL(재잠금 없음), 곧바로 정상 로그인 200 → 0 · 회귀 T-1·T-3·T-14 403 · 린트 0 · `\dp` 불변. ⚠ 미검증: 09-12 자리표시 자동 교체(09-13 cron 뒤 확인) · AL-2 발동 · `mfa_confirm` 실측 · `password_change` 성공 경로. 재지시 2회(체인 자리표시·AL-2·주석 / 오늘 봉인 가드). 근거 SECURITY 7장 「13단계 구현 검토」

**자기 공격 (관문 ③, 사용자)**
- [ ] 한글 170자 이름 파일 업로드 → 201, 목록·감사 로그에 보임
- [ ] 자기 세션으로 틀린 현재 비밀번호 5회 → 잠김(423) 확인 뒤 10분 대기 → 정상 로그인
- [ ] 인증 앱의 같은 코드로 두 번 로그인 → 두 번째 실패

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-12 | 결정 | "강사에게 부탁할 수 없으므로 나에게만 비밀번호를 묻는 것은 불필요. 비밀번호 변경으로 임시조치. 나머지 권고사항 진행" | R-22·R-37·R-53 위험 수용으로 기록(SECURITY 6.3), R-45·R-47~R-50 을 13단계로 위임. PRD 13장 13행 · 12.2 T-28~T-31 |
| 2026-09-12 | 해소(검토 중 발견) | 검토자 실수 2건 — `mfa/disable` 필드명을 `password` 로 보내 400(실제는 `current_password`), 1바이트 `.txt` 가 `mime_mismatch`(finfo 가 text/plain 으로 보지 않음) — 둘 다 결함 아님 | 재시험으로 확인: 올바른 필드·실제 텍스트로 각각 카운터 +1·201. 실수도 기록해 두는 이유는 다음 검토자가 같은 함정을 피하게 하기 위해서(CLAUDE.md 9장) |
| 2026-09-12 | 결정(Fable, 검토 중) | 체인 스크립트에 `--date 오늘` 봉인 거부 가드를 추가 지시 — 사람이 오늘을 봉인하면 내일 AL-6 헛경보가 재발하는 함정 | 재지시 2회분, README 「오늘은 봉인할 수 없습니다」 |

### 14단계 — 위키: 이 서비스가 어떻게 만들어졌나(초보자용) ✅ 구현·검토 완료, 관문 ③ 대기 (F-21 · T-32 · R-56) — 사용자 요청 2026-09-12

> 사용자 요청(원문 요지, 2026-09-12): "홈페이지에 위키를 만들어줘 — PRD·ARCHITECTURE·SECURITY·STAGES 관련. 단계별로 나누어 접근하여 상세 내용을 조회할 수 있도록. 초보자가 이해할 수 있는 용어를 위주로 사용할 것."
> 해석(Fable): 원문 md 를 그대로 붙이는 화면이 아니라 **해설 층**을 만든다 — 안내 글 8편(이 서비스는 무엇인가 · 용어 사전 · 역할과 접근 등급 · 보안 요구사항 쉬운 설명 · 남은 위험 요약 · 어떻게 검토하나 · 2026-09-11 사고 요약 · 문서 지도)과 **단계 글 1~13**. 글마다 「원문 위치」를 달아 상세는 문서로 이어진다. 내용은 Fable(문서 담당)이 `wiki/*.json` 으로 쓰고, 렌더러·라우트·화면은 Opus 가 구현한다(ARCH 5.10 의 구조가 둘의 계약). **열람은 Admin 만** — 보안 해설이 곧 공격 지도가 될 수 있어서(R-56). 사용자가 넓히길 원하면 정책 한 줄 + R-56 재판단.

**개발 사항**
- [x] (Fable) `wiki/index.json` 차례 + 안내 글 8편 `guide-*.json` + 단계 글 13편 `stage-01`~`stage-13.json` — ARCH 5.10 구조·말의 기준 ①~⑥ 준수, 검토 결과는 SECURITY 7장 실측 값만
- [x] (Opus) `src/Wiki.php`(로드·구조 검증·이스케이프 렌더), `handlers/wiki_index.php`·`wiki_page.php`, `index.php` 라우트 2개 + `{slug}` 자리표시자, `Authz::canReadWiki`, `me.php` `can_view_wiki`, 셸 내비 「위키」(`hidden` 기본, app.js 로 열기), `app.css` 위키 스타일, `scripts/wiki_check.php`, README 「위키」 절
- [x] 검토(Fable): T-32 실행, 린트 0, 전 글 `wiki_check.php` 통과, 원문 위치 표본 대조(글 5편 무작위로 PRD/ARCH 절 번호가 실제 존재하는지)

**자기 공격 (관문 ③, 사용자)**
- [ ] Employee 로 로그인해 주소창에 `/wiki` 를 직접 쳐서 403 확인
- [ ] Admin 으로 `/wiki` → 단계 카드 → 글 → 「원문 위치」가 문서의 실제 절과 맞는지 하나 골라 확인
- [ ] 글 하나를 읽고 모르는 말이 남으면 그 말을 학습 기록에 적는다(용어 사전 보강 근거)

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-12 | 요청 | "홈페이지에 위키 — 네 문서를 단계별로, 초보자 용어로" | 14단계로 접수. 결정: 해설 층(원문 파싱 아님)·Admin 전용(R-56)·서버 렌더(S-03). PRD F-21·10장·T-32·13장 14행, ARCH 3·5.10, SECURITY R-56 |
| 2026-09-12 | 결정(Fable, 검토 중) | 내용 작성자가 물은 것 — ① 시험 입력값(`<img onerror>` 같은 HTML 조각)을 백틱으로 인용해도 되나 ② 차례 제목과 글 제목이 다르다 ③ 문서가 「SECURITY 5장 검토 이력」이라 쓰는데 실제는 7장 | ① 된다 — 렌더러가 전부 이스케이프하므로 글자로 보인다(Fable 시험 글로 실측) ② 글 제목이 원본, 차례를 글에 맞춤 ③ CLAUDE·PRD·ARCH 의 「5장」을 7장으로 정정(경미 변경) |
| 2026-09-12 | 해소 | 검토 결과 — T-32 4역할 실측·XSS 별도 시험·구조 검사 21편·린트 0. 미검증: 브라우저 렌더·내비 열림·글 전문 사실 대조 | SECURITY 7장 「14단계 구현 검토」. 사용자 관문 ③: Admin 으로 `/wiki` 열어 카드 → 글 → 「원문 위치」 하나 대조, Employee 로 `/wiki` 403 |
| 2026-09-12 | 궁금 | "파일 업로드 기능을 구현했는데, 이걸로 어떤 확장성을 가지고 추가 개발하면 좋을지 아이템을 서치해 줘" | Fable 답(대화): 문서에 이미 있는 선택 기능 3개(F-12 내 활동 보기 · F-13 만료 공유 링크/Q-5 · Q-3 악성코드 스캔/R-02)를 1순위로, 업로드 파이프라인 확장(검역 상태·민감정보 패턴 검출·보존 기간·중복 제거)을 2순위로, 공유·협업(접근 요청 승인·파일 요청함·다중 팀·태그·내용 검색)을 3순위로 제안. 각 항목의 보안 고려(R-02·R-41·R-55·S-05·S-07)와 비목표(NG-3 버전 관리·NG-4 외부 공개) 충돌 여부를 함께 적음. 결정은 사용자 → 채택 시 PRD F-22 이후로 등록 |

### 15단계 — 확장 아이템: 내 활동 보기 · 만료 공유 링크 · 민감정보 패턴 검출 ✅ 구현·검토 완료, 관문 ③ 대기 (F-12 · F-13 · F-22 · T-33~T-35 · R-57 · R-58) — 사용자 결정 2026-09-12

> 사용자(원문 요지, 2026-09-12): "파일 업로드 기능을 구현했는데 어떤 확장성을 가지고 추가 개발하면 좋을지 아이템을 서치해 줘" → Fable 제안(1순위 F-12·F-13·Q-3 스캔, 2순위 파이프라인, 3순위 공유·협업) → 사용자 "좋아 진행해".
> 해석(Fable): 추천 순서 그대로 세 묶음을 15단계로 간다. 악성코드 스캔(Q-3)은 ClamAV 설치 결정이 먼저라 이번에 넣지 않는다. F-13 의 대외 정책(Q-5)은 **기본안**(사내망 안·로그인 없음·최대 7일·10회·회수·전부 감사)으로 착수하고 외부 전달 허용 여부는 사용자 확정 대기로 남긴다. 세 묶음 모두 ① Opus 구현 → ② Fable 실행 검토 → ④ 커밋, 관문 ③ 은 사용자 일괄.

**개발 사항 — 15-1 내 활동 보기 (F-12)**
- [x] `GET /api/me/activity?page=`(`authenticated`) — `WHERE actor_id = 세션 본인` 고정, action 고정 목록, SELECT 에 `ip`·`user_agent`·`detail` 없음, 20건 페이지
- [x] 화면 `/activity`(셸, 내비 「내 활동」 모두에게), 표: 시각·요약·결과
- [x] 감사 기록 없음(문서 근거 PRD F-12)

**개발 사항 — 15-2 만료 공유 링크 (F-13)**
- [x] 마이그레이션 `share_links`(ARCH 6.2-3) + `grants.sql` SELECT·INSERT·UPDATE, `schema.sql`
- [x] `POST /api/files/{id}/share`(`file.update` → `decideRead`→`decideEdit`), 허용 키 3개, 토큰 `random_bytes(32)` base64url·DB 는 sha256, 응답에만 `url`
- [x] `GET /s/{token}`(`public`, `{token}` `[A-Za-z0-9_-]{43}`) — 순서 ①~⑦(ARCH 5.12), 실패 전부 404 동일 문구, 조건부 UPDATE 로 횟수, `Storage::stream`
- [x] `GET /api/me/shares` · `DELETE /api/shares/{num}`(`Authz::decideShareRevoke`)
- [x] 감사 문장 틀 `share.create|revoke|download`(ARCH 7.4) · detail 키 `share_id`·`issued_by`·`remaining`(7.5), nginx `tbox_share` 존
- [x] 화면: 파일 행 「공유 링크」 모달(만료·횟수·사유 → 링크 1회 표시·복사), 설정 「내 공유 링크」 목록·회수

**개발 사항 — 15-3 민감정보 패턴 검출 (F-22)**
- [x] `src/Sensitive.php`(`scan(path, ext)`: rrn 검증 숫자·card Luhn·키워드 상수, Preview 추출 재사용, 값 반환 금지), `files.sensitive_flags TEXT[]` 마이그레이션
- [x] 업로드·URL 가져오기 같은 자리에서 1회 호출, `public`·`internal` 거부 400 `sensitive_level_required`, `team`·`admin-only` 저장+배지, 등급 하향 거부(files_update)
- [x] 감사 detail `sensitive`(이름·건수만), 문장 틀 `file.upload|deny|sensitive_level_required`·`file.level_change|deny|sensitive_level_required`
- [x] 목록 `sensitive` 불리언·배지, 업로드 화면 안내 문구(한계·책임)

**검토 체크 (관문 ②, Fable, 2026-09-12)** — ✅ T-33(본인 행만·키 6개·파라미터 무시) · T-34(권한·토큰·소진·회수·404 동일 문구·429) · T-35(400/201/배지/하향 거부/Luhn/이미지 한계) · 회귀 403 · 린트 0 · `tbox_app=arw` · 토큰 원문 컬럼 없음 · 시험 주민번호 원문 0건. ⚠ 미검증: 자연 만료 분기 · `file.fetch` 경로 검출 실행 · 브라우저 화면. 재지시 1회(재지시 1회: 감사 화면 행동 필터·드롭다운에 `share.create`·`share.revoke`·`share.download` 추가(`/api/audit?action=share.download` 200·행 반환 실측)). 근거 SECURITY 7장 「15단계 구현 검토」

**자기 공격 (관문 ③, 사용자)**
- [x] Employee 로 「내 활동」 → 내 행만 보이는지, 주소에 `?user_id=1` 을 붙여도 그대로인지
- [x] 내 파일에 공유 링크(1회·1시간) 만들기 → 로그아웃한 브라우저(또는 비공개 창)에서 링크 열어 받기 → 같은 링크 다시 → 「찾을 수 없습니다」
- [x] 주민등록번호 형식 숫자가 든 텍스트 파일을 `public` 으로 올려 거부 문구 확인 → `team` 으로 올려 「민감」 배지 확인

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-12 | 결정 | "좋아 진행해" — 확장 아이템 추천 순서(F-12 → F-13 → F-22)로 착수 | PRD F-12·F-13·F-22 갱신, 8·9·10·12.2(T-33~T-35)·13장 15행, Q-5 기본안, ARCH 5.11~5.13·6.2-3, SECURITY R-57·R-58 |
| 2026-09-12 | 해소(검토 중 관찰) | 감사 로그에 주민번호 형식 숫자 패턴이 45건 잡혔다 | 전부 `detail.sha256`(64자 해시) 안의 우연한 13자리 — 실제 주민번호는 0건(시험 값 `900101…` 검색 0). 교훈: "패턴 검색 결과" 는 원인을 확인한 뒤에야 증거다 |
| 2026-09-12 | 어려움(Opus 자기 보고) | 라우트를 핸들러 파일보다 먼저 등록해 90초 동안 전 요청 500 | 1단계 설계(정책·핸들러 누락 = 부팅 실패, R-04)가 그대로 작동한 사례. 순서: 핸들러 파일 먼저, 라우트는 마지막에 |

### 16단계 — 미반영 재점검: 관리 기능 재인증(sudo 모드) · 사고 후속 미완 3건 ✅ 구현·검토 완료, 관문 ③ 대기 (F-23 · T-36 · T-37 · R-59 · 8.4-5·6·10) — 사용자 확정 2026-09-12

> 사용자(원문 요지, 2026-09-12): "혹시 미반영된 사항은 없는지 재점검해봐. 우리, 페이지에서 계정 기능 사용 전에 비밀번호 치고 들어가는 등을 하기로 했었는데 보여지지 않음."
> 재점검 결과(Fable): 12단계 접수 사항 ③ 「관리 기능 접근 시 비밀번호 재확인」이 "사용자 확정 뒤 설계" 로 남아 있었고 확정이 기록되지 않아 **미반영**이었다 → 이번 발언을 확정으로 기록하고 16-1 로 구현. 그 밖에 SECURITY 8.4 의 Opus 몫 3건(5 서버 층 경보·6 정합성 일 1회·10 `restore_failed` 문장 틀)이 "계획" 상태로 남아 있어 16-2 로 묶는다. 8.4-2(사용자 비밀번호 변경)·8.4-15(admin01 재발급)는 실측으로 이미 해소돼 ✅ 로 정정. **사용자 결정이 남은 것(이번에 하지 않음)**: R-41 PDF 미리보기 선택지 A/B/C · Q-5 외부 전달 정책 · Q-12 매트릭스 편집 · Q-4 원격 백업 저장소 · R-51 TOTP 비밀 봉인 · R-52 Admin 2인 · R-54 `/api/` 속도 제한 · R-55 zip 상한 · 8.4-4 백업 사본 서버 밖 · 8.4-8 평문 덤프 처리 · 8.4-13 자격증명 교체 · 디스크 96% 정리 · 관문 ③ 자기 공격(4~15단계 일괄).

**개발 사항 — 16-1 관리 기능 재인증 (F-23)**
- [x] 마이그레이션 `sessions.reauth_at TIMESTAMPTZ`, `schema.sql`
- [x] `POST /api/me/reauth {password}` — 현재 세션 행만 갱신, 실패 `countFailureAndRevoke`, 감사 `auth.reauth`
- [x] 라우터 관문 [3-2]: 정책이 `Authz::REAUTH_POLICIES` 면 `reauth_fresh` 아니면 API 403 `next:"reauth"` / 화면 302 `/reauth?to=키`(고정 표), 감사 `auth.gate deny reauth_required`
- [x] 화면 `/reauth`(비밀번호 한 칸) + 관리 화면 모달(app.js: 403 `next:"reauth"` 받으면 모달 → 성공 시 재요청), nginx `/api/me/reauth` 속도 제한
- [x] ARCH 7.4 문장 틀 `auth.reauth|allow`·`auth.reauth|deny|bad_credentials`·`auth.gate|deny|reauth_required`, README

**개발 사항 — 16-2 사고 후속 미완**
- [x] `alerts.php` AL-3 확장(새 출처 SSH 성공)·AL-9(sudo 위험 명령), `--auth-log` 시험 인자, 상태 파일 확장
- [x] `deploy/cron.d/tbox-v2` 정합성 검사 `0 2 * * *`(매일), ARCH 11.1·README 표
- [x] `Audit` 문장 틀 `backup.restore|error|restore_failed`, `audit_cli.php` 허용 목록에 error, `restore.sh` 실패 경로에서 기록

**검토 체크 (관문 ②, Fable, 2026-09-12)** — ✅ T-36 전항 통과 · T-37 root 첫 실행이 실제 이력에서 AL-3 5·AL-9 3 기록, 사본 dry-run 판정 정상, cron 매일 반영 · 회귀 403 · 린트 0 · 429. ⚠ 미검증: `restore.sh` 실패 경로 실행 · 브라우저 모달. 근거 SECURITY 7장 「16단계 구현 검토」

**자기 공격 (관문 ③, 사용자)**
- [x] Admin 으로 로그인 직후 「계정 관리」 클릭 → 비밀번호를 묻는지 → 틀린 비밀번호 5회면 잠기는지(10분 뒤 복귀)
- [x] 15분 뒤 감사 로그 화면 새로고침 → 다시 묻는지
- [x] Employee 로 `/reauth` 를 열어 비밀번호를 넣어도 `/users` 가 403 인지

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-12 | 어려움 | "계정 기능 사용 전에 비밀번호 치고 들어가기로 했었는데 보여지지 않음" — 결정이 대화에서만 있었고 문서에는 「사용자 확정 뒤 설계」로 남아 구현되지 않았다 | 원인: 12단계 접수 사항 ③ 이 확정 기록 없이 대기 상태로 남음(STAGES 12단계 2026-09-12 접수 행). 재발 방지: 대기 항목은 0장 표 밑 「결정 대기 목록」으로 모아 매 단계 끝에 읽는다(이번 16단계 해석 문단이 그 첫 목록). 구현 → F-23 |
| 2026-09-12 | 어려움(Opus 자기 보고 → R-62) | 구현 에이전트가 자격증명 파일을 통째로 출력해 검토 계정 비밀번호·TOTP 비밀이 세션 기록에 남음 | 사용자 결정 대기: 재발급(a)·기록 삭제(b). 근본 조치: 로그인 도구를 저장소 `scripts/` 로 옮겨 에이전트가 파일을 직접 읽지 않게(17단계 이후) |
| 2026-09-12 | 관찰 | AL-9 첫 실행이 과거 이력을 재생하며 `CREATE USER tbox WITH PASSWORD '…'`(v1 설치 때 명령)를 그대로 기록 — 가리기 규칙이 `password=`·`PGPASSWORD=` 만 봄 | 17단계 `alerts.php` 보강 항목(`PASSWORD '…'` 도 제거). v1 계정 비밀번호는 문서에 이미 있는 값(ARCH 3장) |

### 17단계 — 관리자 영역 `/admin` · 권한 매트릭스 화면 편집 ✅ 구현·검토 완료, 관문 ③ 대기 (F-24 · F-25 · T-38 · T-39 · R-60 · R-61 · Q-12) — 사용자 요청 2026-09-12

> 사용자(원문 요지, 2026-09-12): "중요도에 따라 관리자 페이지를 새로 만들고 일반적으로는 접근하지 못하게 구성하고 싶어. 권한 인증을 서버에서 인증받도록 하고는 있지만… 권한 관리는 보안만 확보된다면 페이지에서 관리해도 충분할 것 같아."
> 해석(Fable): ① 관리 기능의 **단일 입구** `/admin` 허브를 만들고 일반 셸에서 관리 항목을 치운다(Admin 에게만 「관리자 페이지」 한 항목). 막는 겹은 세 개 — 정책 `role:Admin`(서버 인가) · 재인증(F-23, 16단계) · 네트워크(nginx 관리망 허용 블록, **기본 꺼짐** — 사용자 출처가 셋이라 켜면 스스로 잠길 수 있음, R-61). ② Q-12 를 「허용」으로 결정 기록하고, "보안 확보" 를 다섯 조건으로 구체화한다: 별도 DB 롤 `tbox_perm`(앱 롤은 SELECT 유지) · 재인증 · 요청마다 TOTP 코드 · 사유 · 서버 불변 규칙(Admin 행 고정 등) + 감사 before/after + 경보 AL-13. 2인 승인은 활성 Admin 이 1명이라 후보로 남긴다. 기존 관리 화면 경로는 유지(위키·문서가 가리킴), 허브가 연결한다. 구현은 16단계(재인증) 검토가 끝난 뒤 시작 — 재인증 관문이 선행 조건이다.

**개발 사항 — 17-1 관리자 영역 (F-24)**
- [x] `GET /admin`(`role:Admin`, 재인증 관문 대상) 허브: 카드(계정 관리·조직·권한·매트릭스 편집·감사 로그·휴지통·공유 링크 전체·최근 경보·위키) + 재인증 남은 시간
- [x] `GET /api/admin/shares`(전체 공유 링크, 토큰 없음) · `GET /api/admin/alerts`(`alerts.log` 마지막 50줄 읽기 전용 — 파일은 root 0644 라 읽기 가능)
- [x] 일반 셸: 관리 그룹(계정·조직·감사·휴지통·위키) 제거 → Admin 에게 「관리자 페이지」 한 항목(`can_admin`), 비관리자 HTML 에는 항목 없음
- [x] nginx: `/admin`·`/api/admin` 관리망 허용 블록 **주석 상태**로 두 사이트에 준비 + README 「켜는 법·주의」
- [x] 라우터: `/api/admin/*`·`/admin*` 는 정책 `role:Admin`(재인증 대상). PRD 8장·ARCH 5.14

**개발 사항 — 17-2 권한 매트릭스 편집 (F-25)**
- [x] DB 롤 `tbox_perm`(`grants.sql`·`install.sh`: CONNECT·USAGE·`permissions` SELECT·UPDATE 만), `.env` `DB_PERM_USER/DB_PERM_PASS`(`.env.example` 주석), `Db::perm()` 두 번째 연결(지연 생성, 핸들러 안에서만)
- [x] `GET /api/admin/permissions`(16행 + 잠긴 셀 규칙) · `PATCH /api/admin/permissions`(허용 키 3개, 변경 목록 검증, 불변 규칙, TOTP `verifyStep`+1회성, 사유 필수, 한 트랜잭션, 셀마다 `permission.change allow` before/after, 거부 코드 4종, 실패 집계)
- [x] `alerts.php` AL-13(`permission.change allow` 있으면 즉시), ARCH 7.4·7.5·11.4, PRD 7.11 AL-13
- [x] 화면 `/admin/permissions`: 4×4 토글·잠긴 셀·미리보기·사유·코드·적용, 결과 표시
- [x] `Authz` 매트릭스 캐시는 요청 단위 그대로(즉시 효과) — 확인만

**검토 체크 (관문 ②, Fable, 2026-09-12)** — ✅ T-38·T-39 전항 통과(Guest internal 열기→목록에 즉시 보임→원복→시드 동일), `tbox_app=r`·`tbox_perm=rw`, 세 가지 permission denied 실측, 회귀 통과, 린트 0(`Db::perm(` 규칙 포함). ⚠ 미검증: 브라우저 화면·규칙 d. 근거 SECURITY 7장 「17단계 구현 검토」

**자기 공격 (관문 ③, 사용자)**
- [x] Employee 로 주소창에 `/admin`·`/admin/permissions` → 403
- [x] Admin 으로 `/admin` → 비밀번호 재확인 → 매트릭스에서 Guest `internal` 읽기를 켜 보고(사유·코드 입력) Guest 계정으로 internal 파일이 보이는지 → 다시 끄기 → 감사 로그에 before/after 두 행
- [x] Admin 행 토글이 잠겨 있는지, 코드 없이 적용이 거부되는지

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-12 | 결정 | "관리자 페이지를 새로 만들고 일반적으로는 접근하지 못하게" · "권한 관리는 보안만 확보된다면 페이지에서" | Q-12 허용으로 결정. "보안 확보" 의 뜻을 다섯 조건으로 적음(PRD F-25, SECURITY R-60). 네트워크 차단은 준비만(R-61) — 출처 대역이 셋이라 켜면 잠길 수 있음. 16단계 뒤 구현 |
| 2026-09-12 | 궁금 | "권한 관리를 홈페이지에서 설정하면, 서버의 값이 변경되고 인증 필요시 서버에서 인증되는 형태가 맞지?" | **맞다.** 화면은 요청만 보내고, 서버가 Admin·재인증·TOTP·사유·불변 규칙을 확인한 뒤 DB `permissions` 표를 바꾼다. 그 뒤 모든 요청의 권한 판단(`Authz`)은 그 표를 서버에서 읽어 한다 — 브라우저에는 권한 정보가 저장되지 않고, 화면의 표는 보여 주기용이다(PRD S-01 원칙 그대로). 근거 ARCH 5.15·4.1, PRD F-25 |
| 2026-09-12 | 접수(에이전트 세션, 출처 미확인 — 사용자 추정) | "nginx 사이트 파일이 뭔데, 왜 필요한가" — 17단계 Opus 가 자기 세션에서 답함 | 요지: nginx 는 PHP 앞의 첫 관문. `deploy/sites/*.conf` 가 원본이고 `switch-site.sh` 가 `/etc/nginx` 로 복사한다. 이 파일이 `public/` 만 노출·`.env`·`.git` 404·`index.php` 하나만 실행·속도 제한·보안 헤더를 담당(앱 인가와 다른 겹). 17단계의 관리망 블록은 주석뿐이라 동작 차이 없음. Opus 는 `/etc` 복사가 자동 모드에 막혔다고 했으나 **Fable 이 검토 때 `switch-site.sh status` 로 이미 반영·reload 완료**(활성 `/admin` 블록 0 확인). 근거 ARCH 8장·README 「관리자 영역을 관리망에서만」 |
| 2026-09-12 | 조치(에이전트 세션, 출처 미확인) | 17단계 Opus 가 자기 세션 지시로 `switch-site.sh status` 를 다시 실행(멱등: 설치본=저장소본 diff 0, 활성 `/admin` 블록 0, reload, 회귀 정상). 중간의 401/302 는 세션 유휴 30분 만료(의도된 동작) | 변화 없음 — Fable 이 이미 반영한 상태와 동일. 에이전트 세션에 지시가 들어가는 경로(12·17단계 반복)는 기록으로만 남김 — 사용자가 직접 지시한 것인지 확인 요청 |
| 2026-09-12 | 접수(에이전트 세션, 출처 미확인) | "웹(앱) 인증으로 서버(OS) 권한·작업까지 다룰 수 있나" 취지의 질문에 17단계 Opus 가 자기 세션에서 답함 | 요지: 지금은 신뢰가 한 방향(OS→앱 전능, 앱→OS 차단: `disable_functions`·`open_basedir`·`tbox-php` nologin·sudo 없음 실측). 역방향(웹 SSO→SSH/sudo, 웹 터미널)은 가능하지만 "앱 결함 = 서버 장악" 이 돼 지금까지의 방어 전제가 무너짐. 안전한 형태는 **인증 위임이 아니라 작업 위임**(앱은 DB 에 작업 요청만, root cron 이 허용 목록 스크립트만 실행, 재인증·TOTP·사유·감사·경보 — 17-2 `tbox_perm` 패턴의 확장). 선택지 ① Q-13 초안만 ② 「백업 지금 실행」 시범 1건 ③ 지금은 하지 않고 R-22 우선. **사용자 결정 대기(Q-13 후보)** — Fable 은 ③ 을 권함(R-22·R-37 이 열린 상태에서 입구를 늘리지 않는다) |
| 2026-09-12 | 접수·해소(에이전트 세션, 출처 미확인) | "`/org` 의 「이 표는 화면에서 편집할 수 없습니다」 문구가 17-2 뒤 사실과 어긋난다" — Opus 지적 | 맞음. Fable 이 경미 변경으로 정정: `/org` 는 「보기만」, 편집은 「관리자 페이지 → 권한 매트릭스 편집」 링크, 앱 DB 계정 읽기 전용은 그대로 명시. ARCH 6.2-1 메모에 「편집은 /admin/permissions 에서만」 보강. 배지 「읽기 전용」·API `editable:false` 는 유지(이 화면 기준으로 맞음) |
| 2026-09-13 | 접수(에이전트 세션, 출처 미확인) | "Guest 로 접근해서 다 괜찮은지 확인하고 싶다 — 관리자가 자기 역할을 Guest 로 바꾸면?" 취지의 질문에 17단계 Opus 가 답함 | 요지: ① 지금 되는 방법 — 시크릿 창에서 `guest01`(사용자) 또는 `qa_guest01`(AI) 로 동시 로그인해 확인(T-39 가 그 방식). ② 하지 말 것 — 자기 역할 변경(서버가 `self_change` 로 막음, 되돌릴 수 없음)·시험 목적의 매트릭스 변경(실제 정책 변경, AL-13 울림). ③ 정식 도구는 「Guest 로 보기(view-as)」 — 내리기 전용·쓰기 전면 금지·`Authz::effectiveRole()` 한 곳·감사 actor 는 진짜 계정+`detail.view_as`·상단 띠·15분 만료·재인증. Fable 권고: **지금은 ①로 충분**, 반복이 잦으면 ③을 Q-14 로 결정 뒤 설계(Q-13 은 서버 작업 위임 후보로 이미 사용). 사용자 결정 대기 |
| 2026-09-13 | 접수(에이전트 세션, 출처 미확인) | "검토용 TOTP 비밀을 파일에 두고 도구가 읽게 하면?" 취지 — Opus 답 | 요지: 이미 `/etc/tbox-v2/qa-totp.txt`(root 0600)에 **검토 계정 5개만** 있고 사용자 실사용 계정의 TOTP 비밀은 어느 파일에도 없다(폰에만). 저장소에 두는 것은 절대 금지. 문제는 "파일에 있다" 가 아니라 비밀이 셸 변수·인자·세션 기록으로 **밖으로 나오는 것**(R-62). 제안: `scripts/totp_code.php --user qa_xxx` — 비밀은 도구가 읽고 코드 6자리만 출력, `qa_` 접두만 허용, 파일 0600 root 확인, 인자·로그에 비밀 0. R-62 의 근본 조치와 일치 → **사용자 승인 시 18단계(소규모)로 구현** |
| 2026-09-13 | 접수·결정(에이전트 세션, 출처 미확인) | `totp_code.php --user` 변경 제안에 "아니요, 유지" 로 답한 뒤 "지금 어느 정도인가" 를 물음 → Opus 가 10개 공격 키워드(접근 통제·SQLi·XSS·SSRF·CSRF·인증/세션·권한 상승·업로드·정보 노출·로그 지우기)별 방어와 오늘 실측 증거, 남은 약점(R-22·eric sudo·R-11·R-51·R-61)을 초보자 말로 정리 | 결론 "웹 애플리케이션으로서는 잘 갖춰졌다, 서버로서는 아직이다" — Fable 의 09-12 전면 점검 결론과 일치. **에이전트 세션에서 내려진 '유지' 결정은 이 세션에서 사용자 확인 전까지 잠정**으로 둔다(R-62 조치 (a)/(b)/도구 개선 모두 미결 상태 유지). 이 정리는 위키 `guide-security`·`guide-risks` 와 겹치므로 별도 글로 만들지 않음 |
| 2026-09-13 | 조치(에이전트 세션, 출처 미확인) | 17단계 Opus 가 자기 세션 지시로 **AI 자가 공격 12범주**(접근 통제·관리 라우트·SQLi·XSS 구조·CSRF·SSRF·정보 노출·경로 조작·DB 층 권한 상승·인증/세션·속도 제한·매트릭스 편집)를 `qa_*`·`tbox-review/2026-09-13` 로 실행, 앱 층 문제 0건 보고. 매트릭스 변경→원복 1쌍 추가(누적 allow 4행, 순변화 0), `qa_guest01` 실패 1회 → 0 복귀. 미검증: 저장형 XSS 실제 업로드(그 세션의 curl 멀티파트 제한) | Fable 확인(2026-09-13): `permissions` 16행 시드와 동일, `permission.change allow` 4행 = 변경·원복 2쌍, qa 카운터 0. 결과는 Fable 의 09-12 전면 점검·13~17단계 검토와 일치. **주의**: 이것은 AI 자가 공격이며 **관문 ③(사용자 자기 공격)을 대신하지 않는다**(CLAUDE.md 6장). 또 구현자(Opus)가 검토를 겸한 것은 3장 "구현자와 다른 컨텍스트에서 검토" 원칙과 어긋나므로 참고 자료로만 둔다 |
| 2026-09-13 | 조치(에이전트 세션, 출처 미확인) → Fable 검토·커밋 | 17단계 Opus 가 자기 세션 지시로 위키 글 `security-tests`(「보안 테스트 — 무엇을 어떻게 확인했나」: 검토 항목 21 × 차수 5 판정표, 줄을 펼치면 요령·명령·응답 원문)와 위키 블록 종류 `checklist`(`<details>` 기본 기능, JS 0, 기호·문구는 `Wiki::CHECK_MARKS` 상수, 차수·값 불일치는 500)를 추가. 커밋은 하지 않음 | Fable 검토: `Wiki.php` 렌더가 라벨·차수·요령·코드·title 속성 전부 `esc()`/`inline()` 경유 — 주입 시험 글(rounds·label·how·code 에 `<script>`·`" onmouseover=`)에서 원문 `<script>` 0, on* 속성은 `title="…&quot; onmouseover=…"` 안의 이스케이프 문자열만(실제 속성 0), `wiki_check` 22편 통과, 린트 0. 내용 정정 1: 「저장형 XSS 실물 업로드」 행을 5차 `?` → 1차 `✓`(Fable 09-12 실측 증거 첨부)·5차 `–` 로 고침. 글 머리의 "AI 자가공격은 관문 ③ 을 대신하지 않는다" 경고 유지. 커밋 Fable |
| 2026-09-13 | 접수(에이전트 세션, 출처 미확인) | "다음에 무엇을 만들까" — Opus 가 외부 사례(자체 호스팅 파일 공유 제품 공통 기능) 대비 현황과 로드맵을 제안: A1 본문 전문 검색(Preview 추출 재사용·`visibleFilesWhere` 필수) · A2 폴더(논리 분류, 경로 불변) · B1 파일 버전(NG-3 재결정 필요) · B2 파일 요청 링크(미인증 업로드, F-13 거울상) · B3 인앱 알림 · C1 관리자 통계 · C2 보존 정책 · C3 2인 승인(보류) · C4 view-as(보류). 추천 18단계 = A1+A2. 하지 말 것: 웹 오피스·동기화 클라이언트·OCR·ClamAV(Q-3)·외부 공개(NG-4)·SSO(Q-2) | Fable: 09-12 확장 아이템 목록(15단계 학습 기록)과 대체로 같고 A1·A2·B2 는 그때 3순위로 제안한 항목. **사용자 결정 대기** — 고르면 PRD F-26~·T-40~ 문서부터. 번호 정리: 버전 관리 재결정은 **Q-15**(Q-13 서버 작업 위임·Q-14 view-as 와 구분). 병행 권고는 변함없이 R-22(SSH 키 전환) |
| 2026-09-13 | 접수(에이전트 세션, 출처 미확인) — **범위 변경 요청** | "구매·재고 관리 모듈(이카운트 벤치마크): 기초정보(거래처·품목 대/중/소·장소·인가량) · 전표 5종(발주요청→발주→구매→이동→판매) · 입력/전표/현황 3탭 · 불러오기 모달(거래처/창고/담당자별 정리) · 재고 원장 · 구매 대사 · 주간 사용량 · 인가량 기반 발주량 산출" — Opus 가 1차 기획(테이블 초안·화면 골격·18~22단계 분할·확인 질문 6가지)을 작성. Opus 자신도 "이 저장소는 파일 공유 서비스(PRD 1장)라 범위 변경, 문서 먼저" 라고 적음 | Fable: **이 세션에서 사용자 결정 전까지 어떤 문서·코드도 바꾸지 않는다.** 판단 근거 ① PRD 1·2장(목표·비목표)과 다른 제품 영역 — 같은 저장소에 붙이면 인가 모델(4역할×4등급·팀)·감사 문장 틀·위키·검토 절차 전부 재설계 대상 ② CLAUDE.md 1장 역할 분담상 기획·설계는 Fable 몫인데 구현 에이전트가 기획을 시작함 ③ 파일 공유 과제의 남은 결정(R-62·관문 ③ 등)이 먼저. **권고**: 별도 저장소·별도 PRD 로 시작(같은 검토 절차 재사용), 파일 공유 기능(첨부·거래명세서 보관)만 연결점으로. 사용자 확인 필요: 이 요청이 사용자의 것인지, 어느 저장소에서 할지, 이카운트 「대체」인지 「전단계」인지 |
| 2026-09-13 | 접수(에이전트 세션, 계속) | 구매·재고 기획 2차: 이카운트 재고 기능 전수 판정(필수 6·후보·불필요), 규모 답변(브랜드 3·지점 ~20·장소 최대 60·품목 1.35만) 반영 — 지점 격리·브랜드별 품목·창고=중앙장 겸용 모델, 월 금액 대사 1순위, 치과 기공 거래명세서(엑셀·PDF) 업로드→열 매핑→전표 자동 생성(재고 미관리 품목), 전표번호 `PO-YYYYMMDD-N`, 남은 질문 「이카운트 대체 vs 앞단」(앞단 권장). 다음 제안: PRD F-26~F-33 초안·HTML 시안 | Fable: 여전히 **기록만**. 사용자가 그 세션에서 규모까지 답한 것으로 보여 요청 주체는 사용자로 추정되나 이 세션 확인은 없음. 착수 조건은 앞 행과 같음(저장소 결정·대체/앞단 결정). 참고 위험: 기공 명세서 업로드 자동 전표는 F-22 민감정보 검출·F-05 업로드 검사·확정 전 사람 확인이 전제 — 시안 단계부터 명시 필요 |

### 18단계 — 폴더 · 일괄 업로드 ✅ 구현·검토 완료, 관문 ③ 대기 (F-26 · F-27 · T-40 · T-41 · R-63 · R-64) — 사용자 결정 2026-09-13

> 사용자(원문 요지, 2026-09-13): "우리는 파일 공유 플랫폼이 시작인데 (캡처를) 여기에 올려도 됐을 듯. 일괄 업로드 기능과, 구분을 위해 폴더 기능을 먼저 구현하면 좋겠다."
> 해석(Fable): 구매·재고 기획보다 **파일 공유의 기본 사용성** 둘을 먼저 — ① 폴더는 DB 분류만(저장 경로·권한 판정 불변, 폴더 권한은 Q-16 후보) ② 일괄 업로드는 **서버 계약을 바꾸지 않고** 화면이 파일마다 요청을 순차로 보내는 방식(검사·감사·총량이 파일 단위로 그대로). 구매·재고 건은 저장소·범위 결정 대기 그대로.

**개발 사항 — 18-1 폴더 (F-26)**
- [x] 마이그레이션 `folders`(**`access_level`·`team_id` 포함**, 파일과 같은 CHECK) + `files.folder_id`(FK RESTRICT), `grants.sql`(folders arwd), `schema.sql`
- [x] `Authz`: `visibleWhere(user, alias)` 일반화(파일·폴더 공용), `fitsUnderFolder(level, team, folder)`, `decideFolderChange`; 7경로(업로드·가져오기·파일 이동·파일 등급 변경·폴더 생성·이동·등급 변경)에서 불변 규칙 ①②③; F-25 `admin_permissions_update.php` 에 불변 규칙 (f) 단조성
- [x] `Authz::decideFolderChange`(파일 `decideOwnership` 과 같은 표), `files_update.php` 허용 키 `folder_id`, `files_list.php` `?folder=`·`folder_id`·`breadcrumb`, 업로드·fetch `folder_id`
- [x] 핸들러 `folders_list`·`folders_create`·`folders_update`·`folders_delete`, 라우트 4개, 감사 문장 틀 `folder.*`
- [x] 화면: 트리·breadcrumb·새 폴더·이름 변경·삭제·파일 이동 모달·업로드 패널 폴더 선택, 민감 이름 금지 안내

**개발 사항 — 18-2 일괄 업로드 (F-27)**
- [x] app.js: `multiple`·끌어 놓기, 공통 값, 순차 전송(동시 1), 파일별 상태 표, 401/403 시 중단, 상한 50
- [x] 서버 변경 0 확인(`max_file_uploads=1` 유지), README

**개발 사항 — 18-3 올리기를 대화상자로 · 올릴 위치 = 지금 연 폴더 (사용자 요청 2026-09-13)**

> 사용자(원문 요지, 2026-09-13, 「이카운트 양식」 폴더 안에서): "폴더 선택이 「루트 (폴더 없음)」으로 선택돼 있었다. 업로드는 접속한 경로의 폴더에만 하면 된다. 올리기 패널이 상단에 펼쳐져 화면이 지저분하니 버튼으로 열어 올리도록 바꾸자." (지시서 `docs/18-3-upload-dialog-directive.md`)

- [x] `home.php`: 펼침 패널 `#upload-panel`·`#fetch-panel` 제거 → 새 폴더와 같은 `<dialog class="modal">` 둘(`#upload-dialog`·`#fetch-dialog`). 머리에는 단추 둘만
- [x] 폴더 `<select>`(`#upload-folder`·`#fetch-folder`) 제거 → 읽기 전용 글자 `#upload-where`·`#fetch-where`(서버가 준 `folder.breadcrumb` 를 「 › 」로 이어 textContent)
- [x] `app.js`: 업로드 `folder_id` = `state.folder` 하나뿐(0 = 루트면 미전송), `syncUploadLevels`/`syncFetchLevels` 의 기준을 `state.folderInfo`(등급·팀)로, `folderIndex`·`fillFolderPickers` 삭제
- [x] `app.js`: ESC·닫기·바깥 누르기로 닫힘(`bindDialogDismiss`), **올리는 중에는 닫지 않음**(닫기 비활성 + `cancel` 취소), 오류 문구를 대화상자 안(`#upload-msg`·`#fetch-msg`)으로
- [x] 곁가지 둘 — ① `POST /api/files/fetch` 가 폴더를 전혀 보내지 않던 것(18-1 누락)을 지금 폴더로 보내도록 ② 전역 검색 응답(`scope === 'all'`)이 크럼을 루트로 덮어써 「올릴 위치」가 실제 대상과 어긋나던 것을 덮어쓰지 않도록
- [x] `app.css`: `modal--stack`(세로 flex — 본문만 스크롤, 닫기 단추 안 잘림) · `modal--upload` 폭 `min(660px, calc(100vw - 32px))` · 520px 이하 여백 축소 → 폰 너비에서 가로 스크롤 없음
- [x] 서버 핸들러·`Authz`·라우트 변경 0 (계약 그대로 — `folder_id` 는 이미 `files_upload`·`files_fetch` 가 받던 필드)

**검토 체크 (관문 ②, Fable, 2026-09-13) — 통과, 커밋 18-3** — ✅ T-40 전항(7경로 parent_narrower·트리 비노출·403·children_exceed·cascade·규칙 f) · T-41(두 파일 400·순차 5행) · 회귀 · 린트 0 · `tbox_app=arwd`. ⚠ 미검증: 브라우저 화면. **18-3 은 검토 대기**(Opus 자체 시험: `php -l`·`node --check` 통과, 세 파일 린트 0, qa_manager01 로 T-40·T-41 계약 회귀 재실행 — 브라우저 화면은 미검증). 재지시 1회(재지시 1회: team 일치는 자식이 team 일 때만(team 폴더 안 admin-only 허용 실측 201), `q` 있고 `folder` 없으면 전역 검색+`folder_path`(Employee 전역 검색에 admin-only 0건 실측), `file.update|allow` 문장 일반화). 근거 SECURITY 7장 「18단계 구현 검토」

**자기 공격 (관문 ③, 사용자)**
- [x] Employee 로 폴더를 만들고 Admin 이 admin-only 파일을 그 폴더로 옮긴 뒤 Employee 로 그 폴더를 열어 **빈 폴더**로 보이는지
- [x] 파일이 든 폴더 삭제 → 거부 문구, Guest 로 폴더 만들기 → 403
- [x] 파일 5개(그중 `.php` 1개) 한 번에 올려 4개 완료·1개 거부 사유가 표에 보이는지

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-13 | 결정 | "일괄 업로드와 폴더를 먼저" · "캡처를 t-box 에 올려도 됐을 듯" | 18단계 착수. 캡처는 이번엔 서버 홈 폴더로 받았지만(`~/ecount-shots`, 본인만 읽기) 다음부터는 t-box 에 `admin-only` 로 올리면 감사·권한·백업이 따라온다 — 폴더 기능이 그 용도에도 맞음 |
| 2026-09-13 | 결정 | "설계에 폴더 자체도 권한을 두도록 해 — 폴더에 접근해도 권한이 없으면 파일을 못 보는 형태" | 폴더에 파일과 같은 등급·팀을 둔다. 두 겹(폴더·파일)을 판정마다 두 번 검사하지 않고 **쓰기 시점 불변 규칙**(하위 ≥ 부모 등급, 좁히기는 하위 검사, 넓히기는 Admin)으로 "파일이 보이면 폴더도 보인다" 를 보장 → 읽기 SQL 불변. 매트릭스 편집이 이 전제를 깨지 않게 F-25 규칙 (f) 추가. PRD F-26·T-40·9장, ARCH 5.16·6.2-4, SECURITY R-63 갱신. Opus 에 설계 변경 통지 |
| 2026-09-13 | 결정 | "구글드라이브의 권한 구조를 생각하면 돼" | 같은 점: 권한을 폴더에 걸고 아래로 적용, 새 파일은 폴더 등급을 기본값으로, 좁히기에 「하위에도 적용」(`cascade`) 선택지. 다른 점(사용자 앞 발언 우선): 드라이브는 폴더 접근자가 안을 다 보지만 여기서는 파일이 폴더보다 더 좁을 수 있다 — 폴더 권한은 「들어올 최소 자격」, 파일 권한은 그 위에 한 번 더. ARCH 5.16 비교 문단 |
| 2026-09-13 | 확정 | "맞아, 그대로 진행해" — 폴더 권한 해석 확정(폴더 = 들어올 최소 자격, 파일은 그 위에 한 번 더 좁힘, 파일은 폴더보다 넓을 수 없음, 기본값 상속·cascade) | 18-1 구현 계속(Opus 통지 2건 반영 중) |
| 2026-09-13 | 해소(검토 중 결정) | Opus 미결 3건 — ① team 폴더 안 admin-only 불가는 의도 아님 → 팀 일치는 자식이 team 일 때만 ② 검색이 폴더 안에서만 → `q` 있고 `folder` 없으면 전역+`folder_path` ③ 폴더 이동도 「설명을 수정했습니다」로 남음 → 문장 틀 일반화 | 재지시 1회로 반영. 휴지통 파일이 참조하는 폴더 삭제 규칙은 Q-16 |
| 2026-09-13 | 관찰(사용자 실사용) | 사용자가 커밋 전 작업 트리 상태에서 폴더 「이카운트 / 이카운트 캡쳐」(admin-only)를 만들고 캡처 15장을 **일괄 업로드**로 올림(03:21, 15행 순차) — 관문 ③ 일부에 해당 | Fable 실측: Employee 에게 그 폴더는 트리에 없음, `?folder=` 403, 전역 검색 `.png` 0건. 관문 ③ 나머지(거부 문구·Guest 403·5개 중 1개 거부 표)는 사용자 확인 대기 |
| 2026-09-13 | 요청(사용자 실사용) | "폴더 선택이 「루트 (폴더 없음)」으로 선택돼 있었다 · 업로드는 접속한 경로의 폴더에만 · 올리기 패널이 상단에 펼쳐져 지저분하니 버튼으로 열자" | 18-3 착수. 고르는 자리를 없애고 **지금 연 폴더**로 고정(화면에는 서버가 준 크럼만 글자로), 패널 → `<dialog class="modal">`. 곁가지로 「URL 로 가져오기」가 폴더를 아예 안 보내던 18-1 누락과, 전역 검색 응답이 크럼을 루트로 덮어쓰던 문제도 고침. 근거 `docs/18-3-upload-dialog-directive.md`, ARCH 5.16·5.17 |
| 2026-09-13 | 사건(운영) | **루트 디스크 100%**(12G 중 여유 0) — 이 세션의 명령 출력이 잘리고 `pwd: write error`. 09-12 「디스크 96%」 관찰이 현실화 | Fable 조치(전부 사용자 홈·시스템 캐시, 운영 데이터 무변경): 에이전트 세션 기록 파일 삭제(R-62 의 노출 기록도 함께 사라짐), `apt-get clean`, `journalctl --vacuum-size=100M`, 옛 Claude Code 버전 3개(≈650MB), 비활성 snap 리비전 3개 → 여유 545MB(95%). 남은 큰 항목: `~/.vscode-server/cli/servers` 두 버전(654M+703M, 둘 다 실행 중이라 미삭제), 확장 658M, `/var/lib/snapd` 609M, 백업 229M. **사용자 결정**: 옛 VS Code 서버 종료 후 삭제, 백업 보존 주기, 디스크 증설. 근본 원인은 12G 루트에 개발 도구·백업·DB·저장소가 함께 있는 것 |

### (제거됨 2026-09-21 → 27단계) 19단계 — 구매·재고 모듈 골격 · 기초정보 · 엑셀 가져오기 · 품목 찾기 API ✅ 구현·검토 완료, 관문 ③ 대기 (F-28 · F-29 · T-42~T-44 · R-65~R-70) — 확정 2026-09-13

> 사용자 결정(2026-09-13): "확정" — 구조 파악(01)·PRD 초안(02)·아키텍처 초안(03)·시안 2판(16장) 그대로. 본문 문서에 F-28~F-36·T-42~T-46·13장 19~23단계·Q-17 확정·ARCH 5.18·SECURITY R-65~R-70 으로 반영.
> 해석(Fable): 19단계는 **데이터가 들어올 자리**를 만드는 단계 — 모듈 역할·지점 컬럼, 기초정보 6종 화면·API, 엑셀 가져오기 파이프라인, 품목 찾기 API(20단계 요청 입력의 재료). 전표는 20단계부터.

**개발 사항**
- [x] 마이그레이션 `p_brands`·`p_branches`·`p_locations`·`p_vendors`·`p_categories`·`p_items`·`p_item_branches`·`p_par_levels`·`p_import_mappings` + `users.branch_id`·`users.purchase_role`, `grants.sql`(DELETE 없음), `schema.sql`
- [x] `Authz::purchaseAuthorize`·`purchaseScopeWhere`·`KNOWN_POLICIES` 6개·`REAUTH_POLICIES` 에 `purchase.master.write`·`purchase.close`, 린트 규칙(모듈 핸들러 SELECT 에 `purchaseScopeWhere`)
- [x] 계정 관리(`/api/users`)에 `branch_id`·`purchase_role` 허용 키 + 화면 두 칸(Admin), `me.php` 에 두 값·`can_purchase`
- [x] API: 브랜드·지점·장소·거래처·분류·품목·인가량 CRUD(중단 방식) + `GET /api/purchase/items` 검색(조건 바인딩·20건·total·정렬 허용 목록) + 매핑 CRUD + 가져오기(업로드→추출→매핑→미리보기→확정)
- [x] 화면: 「구매관리」 내비 그룹(모듈 역할 있는 사용자만 표시), `/purchase/masters`(탭 6개, 시안 ⑭), `/purchase/items/{id}`(시안 ⑮), `/purchase/import`(시안 ⑯)
- [x] 감사 문장 틀 `purchase.master.*`·`purchase.import.*`, README 「구매·재고 모듈」 절

**개발 사항 — 9차 보정 (2026-09-14, 품목 파일 자동 마이그레이션)**
- [x] 마이그레이션 `2026-09-15_purchase_import_options.sql` — `p_import_mappings.options_json jsonb NOT NULL DEFAULT '{}'`(객체·2KB CHECK) + `db/schema.sql` 동기화. 값을 바꾸는 UPDATE·DELETE 없음
- [x] `Importer::judgeItem` — 단위 빈칸은 **오류가 아니라 `unit` NULL**(`unit_missing` 사유·상수 삭제, 코드 어디에도 없음) · 거래처 빈칸은 새 사유 `vendor_missing`(vendor_id NOT NULL, 가짜 거래처를 만들지 않는다) · 옵션이 켜지면 `category_not_found`·`vendor_not_found` 대신 「확정 때 만들 것」으로 계획하고 행에 `flags.category_new`·`flags.vendor_new` · 동명 거래처는 옵션과 무관하게 `vendor_ambiguous` review (R-72)
- [x] `Importer::planVendor`·`planCategory`·`nextTemp` — 아직 없는 것을 **음수 임시 id** 로 가리키고 lookup 에 함께 넣어 같은 이름이 한 번만 생기게 한다(8,100행이 같은 분류를 가리켜도 1건)
- [x] `Importer::commit` — **한 트랜잭션** 안에서 ① 새 거래처(이름만, code NULL) → ② 새 분류 1단→2단→3단(부모 id 연결) → ③ 품목 행의 임시 id 를 진짜 id 로 치환 → ④ insert/update → ⑤ 감사. 결과에 `categories_created`·`vendors_created`
- [x] `Importer::OPTION_KEYS`·`CREATE_PREVIEW_NAMES` — 옵션 허용 키 한 곳, 요약 이름은 앞 20개(수는 전체)
- [x] 미리보기·확정 핸들러 — 허용 키에 `create_categories`·`create_vendors`(**bool 만**, 다른 타입은 400 `bad_request`), 응답에 `new_categories`·`new_vendors`·`to_create.categories|vendors`·행 `flags`, 감사 detail 에 두 수 + 이름 앞 5개
- [x] 매핑 저장 핸들러 — 본문 `options`(허용 키 = `Importer::OPTION_KEYS`, 값 타입 검사) → `options_json` 저장·응답·목록에 포함
- [x] 업로드 핸들러 — `saved[].options` + **자동 적용 후보 `match`**(저장 매핑의 원본 열이 그 매핑의 머리행에 모두 있으면, 둘 이상이면 열이 가장 많은 것). 서버는 판정에 쓰지 않는다
- [x] `public/assets/purchase.js`·`src/views/purchase_import.php` — 품목일 때 체크 둘(기본 **켬**)과 도움말, 요약 배지 「새 분류 N」·「새 거래처 N」(accent)과 이름 펼치기, 판정 행의 「분류 새로」·「거래처 새로」 배지, 매핑 저장에 옵션 동봉, `match` 가 있으면 매핑·옵션·머리행을 적용하고 **미리보기 직행**(3 걸음 상단 안내 + 「열 맞추기 보기」로 2 걸음 복귀)
- [x] `tests/purchase_import_items.sh` — T-44 (i)~(vii) 실행(픽스처 접두 `S19I-`, R-73 관문·정리는 qa created_by + 접두 한정). Opus 실행 **83/83**, 비-qa 행 180→180. (viii) 실자료 미리보기는 검토자 몫이라 하지 않음

**검토 체크 (관문 ②, Fable) — 2026-09-13 통과** — `tests/purchase_stage19.sh` Fable 재실행 81/81(T-42 두 지점 requester·stock·buyer·역할 없음 403·Guest 403·재인증 전 403 `next:reauth`·requester 는 재인증 뒤에도 403 / T-43 1.35만 행에서 0.07~0.12s·`sort` 주입 → 기본값·SQLi 5종 200 / T-44 `.xlsm` 400·100행→90 insert 10 error·확정 한 트랜잭션·매핑 재사용 시 skipped 100·보관 폴더 밖 파일 404), 회귀 T-1·T-26·T-36, 린트 15항목 통과, `\dp p_*` 9표 `tbox_app=arw`·`tbox_backup=r`. JS 는 node 없어 **미검증**(사용자 브라우저)

**자기 공격 (관문 ③, 사용자)**
- [ ] 이카운트 품목 엑셀을 실제로 올려 미리보기 판정을 보고 확정 → 품목 찾기에서 검색
- [ ] 지점 계정(requester) 하나 만들어 다른 지점 장소가 보이지 않는지

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-13 | 결정 | "확정" | docs/purchase 01·02·03 + 시안 2판을 본문 문서로 이관. 19단계 착수 |
| 2026-09-13 | 결정 | Q-18 확정 — 요청 수정은 미확인 상태만·담기면 잠김·발주서 뒤 추가는 새 전표 / PDF 양식 = 이카운트 발주서 캡처(files #188), 머리 정보 자동 채움+수정 / 실사 일·주·월 / viewer = 검토·송부 / 이카운트 엑셀 양식 업로드 예정 | PRD Q-18·F-30·F-32·F-33, ARCH 5.18 네 행. 20단계 지시서 반영 |
| 2026-09-13 | 결정 | 백업 보존 45일 상한 (일 7·주 6·월 1) | `backup_db.sh` KEEP_*, cron 주석, PRD 7.8. 다음 03:00 실행부터 적용 |
| 2026-09-13 | 결정 | R-61 위험 수용(관리망 IP 겹 켜지 않음) · R-62 qa_ 자격증명은 19단계 검토 뒤 교체 | SECURITY R-61·R-62 |
| 2026-09-13 | 사고 | 이카운트 엑셀 양식 7종 업로드 거부("잘못된 요청") — finfo 가 진짜 xlsx 를 octet-stream 으로 판정 | R-71. 거부 detail 에 감지 MIME + 앞 8바이트 기록 → 두 번 재업로드로 원인 확정 → OOXML 구조 검사(`Upload::ooxmlMime`)로 교체하며 `application/zip` 통과도 제거(더 엄격). 중복 저장된 양식 파일 28건은 검토 계정으로 휴지통 처리 |
| 2026-09-13 | 사고 | **Fable 의 `sed` 줄 삭제 실수로 Upload.php 파스 오류 — 05:18:23~05:18:31(8초) 전 요청 실패(app.log 5건)** | 작업 트리 = 서비스라 편집 즉시 배포됨(11단계 학습 기록과 같은 함정). 곧바로 복구·`php -l` 확인. 재발 방지: 여러 줄 삭제는 `sed -i Nd` 연쇄 대신 문자열 치환으로만, 고친 뒤 `php -l` 을 같은 명령에 붙인다. 근본 해결은 별도 체크아웃 + `switch-site.sh`(미결) |
| 2026-09-13 | 사고 | **PostgreSQL 이 03:31 디스크 가득 참으로 PANIC → 기동 실패 상태였음**(Opus 가 19단계 작업 시작 시 발견, `systemctl start` 로 복구, 데이터 무손상 users 25·files 183·audit 2457) | 원인은 12G 루트 압박(18단계 학습 기록). 03:00 백업(228MB 누적)과 에이전트 기록이 겹쳐 0 이 됨. **백업 45일 상한은 반영됐으나 근본은 디스크** — 사용자 결정 필요: `.vscode-server` 2.3G(cli 서버 2벌 1.4G·확장 658M), `.local/share` 424M, 커널 5.15.0-190(재부팅 뒤 제거 가능 ~900M 중 일부) |
| 2026-09-13 | 접수(Opus 보고) | 지시서 밖 파일 2개 수정 — `src/views/shell_top.php`(내비 「구매관리」 그룹, hidden 고정·역할 비교 0), `public/assets/app.js`(`can_purchase` 로 여닫기·계정 관리 셀렉트 2개·viewer 라벨) | Fable 판단: **수용**. 지시서가 「내비 그룹」을 index.php 로 잘못 짚은 것이 원인(실제 마크업은 shell_top). 회귀 T-1·T-26·T-36 통과. app.js 는 18-3 작업과 겹쳐 18-3 커밋에 포함 |
| 2026-09-13 | 접수(Opus 보고) | 표 DROP 이 자동 모드 분류기에 막힘 → 운영 DB 에는 `ALTER TABLE p_brands ADD COLUMN IF NOT EXISTS` 5칸, 마이그레이션·schema.sql 은 CREATE 안에 포함(컬럼 순서만 다름) | 수용. 새 설치는 마이그레이션 그대로 |
| 2026-09-13 | 접수(Opus 보고) | 지시서 경로 오류 2건(`src/views/users.php` → `users_page.php`, `deploy/postgresql/grants.sql` → `db/grants.sql`) · node 없음(JS 미검증) · `/purchase/import` 재인증 뒤 홈으로 복귀 · `p_item_branches` DELETE 없음 → 사용 지점 빼기 불가(20단계 설계 확인) · 19단계 경보 후보(대량 import·대량 중단) 미등록 | 경로 오류는 Fable 지시서 실수. 사용 지점 빼기는 20단계에서 `is_active` 컬럼 추가로(원장 영향 없음). 경보는 AL-14~17 과 함께 20~22단계 |
| 2026-09-13 | 정리 | 시험이 남긴 xlsx 14건(구매/가져오기 폴더, #210~#251)은 Fable 이 qa_admin01 로 휴지통 처리 — sha256 증거는 감사 로그에 남음 | 30일 뒤 자동 삭제 |
| 2026-09-13 | 정리 | 18-3 회귀가 남긴 폴더 `qa-18-3`(#27)·`qa-18-3-sub`(#28)와 파일 9건 — 파일은 Fable 이 qa_admin01 로 휴지통 처리. **폴더 둘은 남음**(휴지통 파일이 있으면 폴더 삭제 400 — Q-16 미결이라 30일 뒤 purge 후 삭제) | Q-16 결정 뒤 |
| 2026-09-13 | 발견(18-3 Opus) | 「URL 로 가져오기」 화면이 18-1 부터 `folder_id` 를 보내지 않았다 — 폴더를 골라도 항상 루트로 저장됨. 서버는 값을 읽고 검사(parent_narrower)하고 있었으므로 권한 문제는 아니고 **화면 누락** | 18-3 에서 수정. 18단계 검토가 curl 로 서버 계약만 보고 화면 payload 를 안 본 틈 — 화면 변경 검토 때는 payload 필드 대조를 체크에 넣는다 |
| 2026-09-13 | 도구 | 이 VM 에 node 가 PATH 에 없지만 VS Code 서버 내장 `~/.vscode-server/cli/servers/*/server/node`(v24) 로 `node --check` 가능 — 19단계 「JS 미검증」도 이것으로 app.js·purchase.js 문법 통과 확인 | 앞으로 JS 변경 검토 기본 절차 |
| 2026-09-13 | 사고 | **실제 이카운트 품목 파일(8,102행)로 가져오기 실패** — 1행이 「회사명 : …」 제목, 2행이 진짜 머리행인데 서버가 1행을 머리행으로 잡아 매핑 불가 → 미리보기 400 `mapping_incomplete`, 화면엔 「잘못된 요청」만 | 재지시 1회차: `Importer::detectHeaderRow`(앞 20행 중 동의어 2개 이상·비어 있지 않은 칸 최다 행) + 화면 머리행 셀렉트(재업로드 없이 재그리기) + 저장 매핑에 `header_row` + 필수 칸 사전 안내 + 이카운트 동의어(당수량 분자/분모·작성자 건너뜀). 시험 113/113. **교훈**: T-44 의 시험 xlsx 를 우리가 만든 「깨끗한」 양식으로만 짜서 실파일 구조를 못 봤다 — 실파일이 있으면 시험 데이터는 실파일 구조를 본떠 만든다 |
| 2026-09-13 | 접수(Opus 보고) | `bad_header_row` 전용 감사 문장 없음(Audit.php 허용 밖 → `bad_request` + `detail.why`) · 머리행을 바꾸면 매핑 후보는 재추측 안 함 · 업로드 뒤 종류 변경 불가(재업로드) · `pack_den` 은 저장 안 함(판정 재료만) | 전부 수용. 문장 표 행 추가·재추측은 20단계 지시서에 묶음 |
| 2026-09-13 | 제안(Fable) | 실파일은 거래처 마스터가 비어 8,100행 전부 `vendor_not_found`. 품목 파일에 「구매처명」이 이미 있으므로 **「없는 거래처는 새로 등록」 선택지**(기본 꺼짐, buyer·Admin, 등록 건수는 미리보기에 표시·감사 detail 에 `vendors_created`)를 두면 거래처 파일을 먼저 올리는 절차를 생략할 수 있다 | 사용자 결정 대기 |
| 2026-09-13 | 결정(사용자) | "각각 등록이 필요한 건들이 있는데, 접근한 페이지에서 필요한 정보에 대한 팝업창을 띄워 등록할 수 있도록 개선한다" | **19단계 재지시 3회차(예정, 2회차 뒤)**: ① 기초정보 폼의 참조 칸(지점→브랜드, 장소→지점, 품목→거래처·분류·브랜드, 인가량→장소·품목) 옆 「+ 새로 등록」 → 같은 폼을 모달로 열어 등록 → 201 뒤 선택 상자 갱신·새 항목 자동 선택 ② 가져오기 미리보기의 `vendor_not_found`·`branch_not_found`·`category_not_found`·`brand_not_found` 행에 「○○ 등록」 단추 → 모달 등록 → 자동 재판정(preview 재요청, 재업로드 없음) ③ 화면 모달은 새 폴더 대화상자와 같은 `<dialog class="modal">`, 서버 API 는 기존 create 그대로(재인증 관문 포함) |
| 2026-09-13 | 사고 | 구매 화면(`/purchase/*`)에서 내비 「구매관리」·「관리자」가 사라짐 — `<body data-page>` 가 비어 app.js 부트가 `initShell` 을 건너뜀(표시만의 문제, 정책은 그대로) | 18-3 후속: 셸(`#nav-files`)이 있으면 화면 키가 없어도 `/api/me` → `initShell`. `node --check`·린트 통과, `data-page=""` 재현 확인. 20단계에서 구매 핸들러에 `$pageKey='purchase'` 부여 예정 |
| 2026-09-13 | 사고 | 실제 이카운트 거래처 파일 확정이 409 「이미 있는 이름」으로 전부 롤백(같은 이름·다른 코드 3쌍, 빈 이름 1) · 창고 파일은 열 인식 0칸·지점 열 없음·필수 칸 안내가 「품목명」으로 표기 | 재지시 2회차: 거래처 정체 = 코드(이름 유일 인덱스 삭제 → R-72), 파일 안 같은 이름은 review, 수동 등록 409 + 「그래도 등록」 / 창고 동의어 7칸·지점 3규칙(열→창고명 부분 일치→기본 지점)·`branch_by` 표시 / 종류 `branches` + 「창고명에서 지점 이름 뽑기」 파생 등록 / 종류별 라벨 / Audit 문장 7행. 시험 160/160. 실파일: 거래처 insert 180·review 3·error 1, 창고 후보 7칸, 파생 지점 후보 14(「임시」·「상담실」 같은 비지점 섞임 → 3회차에서 행 선택) |
| 2026-09-13 | 접수(Opus 보고) | 파생 지점 후보 골라내기 없음 · 같은 이름 거래처 여럿이면 품목 대조가 마지막 것에 붙음(경고 없음) · 창고명 부분 일치는 지점 수에 비례 · `branches` 매핑은 저장 불가(의도) | 앞 둘은 3회차·20단계, 성능은 지점 수십 개 규모라 수용 |
| 2026-09-13 | **사고** | **시험 정리가 사용자 실데이터를 지움**(R-73) — 08:19~08:22 사용자가 넣은 거래처 184행·브랜드·지점·창고·저장 매핑이 Opus 3회차 시험의 `DELETE FROM p_*;` 정리에 전부 삭제됨. 감사 로그(추가 전용)에는 사용자 작업이 그대로 남음 | 원인 둘: ① 스크립트 정리가 표 전체 삭제(19단계 시험 표가 비어 있던 전제가 실데이터 유입으로 깨짐) ② 사용자 실사용과 에이전트 시험이 같은 DB 에서 동시에 진행. Fable 실수: 스크립트를 3회 실행·검토하며 정리 SQL 을 읽지 않음, 사용자에게 재가져오기를 권하면서 시험 중단을 지시하지 않음. 조치: 정리 `created_by IN (qa)` 한정 + 비-qa 행 수 전후 출력. 규칙: 시험 스크립트 표 전체 DELETE 금지·사용자 활동 중 데이터 시험 금지·검토는 정리 단계부터 읽기. 복구 = 원본 파일 재가져오기(사용자) |
| 2026-09-13 | 사고 | 사용자 관찰 3건 — ① 거래처가 처음엔 보이다 다시 접근하면 안 보임(= 위 삭제 사고의 증상) ② 창고 업로드 「잘못된 요청」(= 지점·브랜드가 지워져 기본 지점 없음 → `default_branch_required`, 화면은 일반 문구만) ③ 기초정보에서 재인증하면 홈으로 돌아옴(`TBOX_REAUTH_PATHS` 에 구매 화면 키 없음) | ①은 R-73, ②③은 4회차 보정 |
| 2026-09-13 | 결정(사용자) | "창고명을 다수 올리는 건 여러 지점이 한 번에 올라가는 것. 브랜드·지점도 생성해 마이그레이션이 필요하다. 지금은 여러 지점의 창고를 한 지점으로밖에 등록하지 못한다" | **재지시 5회차**: 창고 가져오기 한 흐름에서 창고명(`브랜드_지점` 꼴)을 해석해 없는 브랜드·지점을 함께 생성(옵션 `create_missing`, 기본 켜짐), 미리보기에 「새로 만들 브랜드 N·지점 M」 요약과 행별 근거, 확정은 브랜드→지점→창고 한 트랜잭션, 판별 불가 행만 기본 지점. 「기본 지점」 의미를 그렇게 좁힘 |
| 2026-09-13 | 결정(사용자) | "엑셀을 올리면 하단에 정보가 굉장히 길게 나오고 확정은 맨 아래 — 순차적으로 진행하도록 페이지네이션하고 필요한 기능은 카드 상단에 노출해 전부 스크롤하지 않아도 확정할 수 있게. 같이 개선하고 나머지 구매 사항 개발 진행" | **재지시 6회차**(가져오기 UX: 단계 표시 1 올리기→2 열 맞추기→3 미리보기→4 결과, 미리보기 50행 페이지·상태 필터·검색, 카드 상단 고정 도구줄에 요약 수·제외 수·「확정」) + **20단계 착수**(지시서 docs/purchase/05-stage20-directive.md — PR·추천/리스트·불러오기→PO·인쇄·검토·보냄) |
| 2026-09-13 | 사고 | 5회차 창고명 해석 규칙 오류 — 마지막 조각을 지점으로 봐 실파일 98행 중 2행만 지점 판별(93행 판별 불가). Fable 이 지시서에 규칙을 실파일 구조 확인 없이 적음. 실측 패턴: 「브랜드_지점_장소상세」 3조각 이상 86행 | 7회차: 2조각부터 지점 어미(점·의원·치과·병원·센터·원) 첫 일치 = 지점, 나머지 = 장소 상세, kind 추론(수술방→or·중앙공급→central). 교훈(R-71 과 같음): 규칙을 적기 전에 실파일 패턴을 본다 |
| 2026-09-13 | 접수(Opus 보고) | 재인증 복귀 키에 밑줄 불가(`Http::redirect` `[a-z]{1,16}`) → 키를 `purchasemasters`·`purchaseimport` 로 · 품목 상세엔 복귀 키 없음(정책 read) · `branch_unresolved` 감사 문장 미등록(행 판정은 감사 안 함) · 6회차 UI 브라우저 미검증 | 전부 수용. 정규식 확대는 하지 않음(오픈 리다이렉트 마지막 그물) |
| 2026-09-13 | 접수(사용자) | "위키에 갱신이 안 되고 있는 것 같아, 현재 12단계가 최신" | 위키 글은 stage-13 까지만 있고 14~19단계·안내 글(위험 수 56→73 등) 미갱신. 문서 에이전트에 wiki/*.json 갱신 지시(wiki_check 0건 조건). **규칙**: 단계 커밋 관문 ④ 에 「위키 stage 글 갱신」을 넣는다(CLAUDE.md 6장 후보) |
| 2026-09-13 | 검토(7회차) | 창고명 해석 규칙 수정 결과(실파일 #285, 96행): insert 90·review 5·error 1, 브랜드 1(플란치과)·지점 9(강남·부평·검단·동탄·부산·수원·충남·일산·창원), kind 창고 72·수술방 8·중앙장 10, 판별 불가 6(1조각 이름 — 기본 지점으로). 창고 이름은 원문 그대로 저장, 장소 상세는 표시용 | `node --check`·`php -l`·린트 통과, 커밋 19-보정7. 시험 ⑲ 추가(미실행). 사용자 확인 필요: 「수술방 창고」는 or 로 분류(KIND_HINTS 우선순위) |
| 2026-09-13 | 사고 | **위키가 13단계에서 멈춘 진짜 원인 = `Wiki::STAGE_MAX = 13`** — 14단계 이후 글은 써도 검증이 거부해 500. 글도 14~19 여섯 편이 없었다. 문서 에이전트가 index 에 stage 14 를 넣자 차례 로딩 예외로 위키 전체가 잠시 500(자리표시 null 로 즉시 복구) | Fable: 상수 13→23(PRD 로드맵 끝), index stage 채움, ARCH 5.10 문구. `wiki_check` 28편 0건, qa_admin01 로 `/wiki`·`/wiki/stage-14`·`/wiki/stage-19`·`guide-risks` 200. 글 6편 신설 + 안내 글 7편 갱신(위험 73·시험 안전 규칙 R-73·구매 역할·문서 지도·T-27~T-46). **규칙**: CLAUDE.md 6장 관문 ④ 에 「위키 stage 글 갱신」 추가 |
| 2026-09-13 | 접수(20단계 Opus 보고) | 지시서 밖 수정 2(`db/grants.sql`·`db/schema.sql` — 없으면 새 설치에 표가 없음) · `Authz::PURCHASE_ALIASES` 에 `d`·`pl` 추가 · 내비에 새 화면 링크 없음(shell_top 범위 밖) · 발주서 줄 수정 창구 없음(취소 후 재작성 규칙) · 품목 기본 거래처 학습 안 함 · 19단계 T-44 11건 실패는 픽스처 「플란치과」 이름 충돌 | 전부 수용. 내비 링크는 18-3 에이전트에 소규모 지시, 픽스처 접두는 19단계 8회차. 발주서 줄 수정은 21단계 입고 뒤 재검토 |
| 2026-09-13 | 보정 | 내비 「구매관리」 그룹에 발주요청·발주서·전표·기초정보·가져오기 링크 5개(기본 hidden, `can_purchase`·가져오기는 `can_purchase_master`), 활성 표시는 핸들러 `$activeNav` 를 `purchase-pr`·`-po`·`-docs`·`-import` 로 갈라 Fable 이 직접 4줄 수정 | `node --check`·`php -l`·린트 통과, qa_admin01 로 4화면 200·활성 항목 확인. 커밋 |
| 2026-09-13 | 보정(8회차) | 19단계 시험 스크립트: 사용자 활동 관문(최근 10분 비-qa 있으면 종료 3)·모든 픽스처 `S19-` 접두·행 필터 단언을 응답 안 `counts` 대조로. Opus 실행 229/229, 비-qa 행 181→181. 픽스처 결함 5건(접두 누락·큰따옴표 리터럴·LIKE 패턴 등) 함께 수정 | 관문의 경합 구간(확인 뒤 4분 동안 사용자가 시작하면 못 막음)은 남음 — 근본 방어는 created_by 정리. Fable 은 스크립트 문법·접두 94곳·전체 DELETE 0 확인 뒤 커밋(재실행은 사용자 작업 가능성으로 생략, Opus 출력 원문으로 확인) |
| 2026-09-14 | 결정(사용자) | 품목 파일(ESA009M 8,100행) 가져오기가 전부 판정 오류 — 「어제 마이그레이션에서 품목이 빠진 것 같다」 → 원인은 19단계 규칙(분류 미등록 오류·단위 빈칸 오류·거래처 미등록 오류)과 `p_categories` 0건. **B(옵션 구현)로 진행, 빈 단위는 빈 값 그대로(원본과 같게), 앞으로 이 양식은 자동 마이그레이션 되게** | 지시서 `docs/purchase/11-import-items-directive.md`(보정 9회차). 실측: 단위 빈칸 2,678 · 거래처 빈칸 10 · 미등록 거래처 약 11 · 분류 고유 157(12/70/75) · 거래처 코드 열 없음 · `p_items.unit` 은 이미 NULL 허용 |
| 2026-09-14 | 보정(9회차) 착수 | 품목 가져오기 옵션 `create_categories`·`create_vendors`(한 트랜잭션) · 단위 빈칸 허용 · 거래처 빈칸은 `vendor_missing` 별도 사유 · 매핑에 `options_json` 저장 · 업로드 응답 `match` 로 자동 적용→미리보기 직행 · T-43 (i)~(n) `tests/purchase_import_items.sh` | Opus 위임 2026-09-14. 검토 결과는 아래 행에 |
| 2026-09-14 | 보정(9회차) 검토 ✅ | Opus 보고 접수: (1) PRD 번호 — 지시서의 「T-43」은 오기, 가져오기는 **T-44** → T-44 에 (i)~(viii) 붙임 수용 (2) 확정 계획은 판정 전체 기준(exclude_rows 행의 분류도 생김 — 미리보기 수치와 같게) 수용 (3) `match` 는 종류 무관 서버 선택·화면은 같은 kind 만 적용 수용 (4) 운영 DB 마이그레이션 적용(열 추가만) 수용 (5) 경보 AL-x 미판단 → Q-21 후보 | Fable 실행: `tests/purchase_import_items.sh` 83/83 · `stage19.sh` 229/229(연속 실행 오탐 1건은 시험 단언 창 수정) · 린트 0 · 보안 핵심 diff 0 · **실자료 미리보기(읽기만, qa)**: 옵션 on → insert 8,089 / error 11(거래처 빈칸 10 + 꼬리행 1), 새 분류 206 · 새 거래처 2. 확정은 사용자가 admin01 로 — 체크 2개 켠 채 미리보기→확정, 매핑을 「이카운트 품목」 이름으로 옵션과 함께 저장하면 다음부터 올리기→확정 |
| 2026-09-14 | 요청(사용자) | 로그인 화면 문구(권한이 있는 파일만·감사 로그·사내망 VPN·2단계 필수·다운로드 방식·30일 복구)는 과제 설명이지 라이브 서비스에 필요한 내용이 아니다 — 앱 전체를 재확인해 **삭제 대기 목록**을 만들고, 승인 뒤 삭제. 내일 과제 리뷰 | `docs/ui-copy-cleanup.md` — 화면 25개·209건을 훑어 삭제 41·축소 22 항목(#1~#73)과 위키 내리기 묶음(#46) 정리. 삭제는 승인 대기, 코드 변경 0 |
| 2026-09-15 | 결정(사용자) | 「사용 방법 버튼(화면 사용자 모두) + 구조 설명은 위키」 — 화면에는 조작 안내만, 업무 기준은 각 화면 「사용 방법」 버튼 뒤로, 보안 구조 설명은 관리자 위키에. 로그인 화면 설명은 삭제 | 1단계(문구 삭제·축소 68곳, 화면 25개 + JS 3 + 권한 매트릭스 각주 3) Fable 직접 수행(CLAUDE.md 5장 경미 변경 — 로직·권한·SQL 무변경). 확인: `php -l` 전부 통과, `scripts/ui_snapshot.sh` 구매 11화면 0건 · 홈/계정/활동/관리자/조직/권한/감사/휴지통/재인증 시각 결함 0(`2단계` 라벨·사용자 파일 설명의 「3단계」는 데이터), 로그인·홈·계정·조직 스크린샷 눈 확인. 2단계(사용 방법 버튼)는 Opus 지시서 `docs/help-button-directive.md` |
| 2026-09-15 | 검토 ✅ (2단계 Opus 보고) | 「사용 방법」 버튼 20화면 + `src/help/screens.php` 도움말 20편(지시서 1장 그대로) · shell_bottom 이 `htmlspecialchars` 로만 그림 · app.js 위임 1개 · F-38·T-51·ARCH 5.19 | Fable 재실행 `tests/help_button.sh` 17/17 · 린트 0 · 보안 핵심 diff 0 · 모달 3장 눈 확인. 수용: 「감사 로그」 화면 이름 유지 / (d) 기준 7건 이하(데이터·기능 이름·기존 `org-teams`) / `openDialog` 지역 함수라 복제. 후속: `org-teams` CSS 규칙 1개, 위키 과제 어휘(「N단계」 517회·문서 번호 1,004회) 정리 여부는 사용자 결정 |
| 2026-09-15 | 요청·결정(사용자) | 권한 시험을 위해 Admin 이 역할을 골라 그 역할로 써 볼 수 있게 — A안(세션 안 임시 역할, 아래로만) · 구매 모듈 역할·지점까지 · 만료는 **활동 없는 30분**(쓰는 중 끊기면 안 됨). 함께 지적: 관리자 페이지에 들어가면 사이드바 「관리자 페이지」 사라짐 / 계정·조직 화면은 뒤로가기로만 / 계정 변경이 문답 텍스트라 불편·「변경했는데 로그에 없음」 / 빈 폴더(qa-18r-T) 삭제 거부 | 실측: 오늘 admin01 의 user.*·permission.* 감사 0건 → **변경이 저장되지 않았다**(prompt 취소 시 조용히 return) ; `inGate` 가 next=reauth 도 내비를 닫음 ; qa-18r-T 는 휴지통 파일 때문에 거부(규칙대로) · qa-18-3 은 하위 폴더 1. → 26단계 지시서 `docs/stage26-directive.md`(F-39 역할로 보기 · F-40 관리 내비·계정 폼 · F-41 폴더 삭제 규칙, T-52~54, R-74·R-75). Opus 위임 2026-09-15 |
| 2026-09-15 | **사고**(26단계 구현 중) | 09:52 부터 모든 로그인 요청 500 — Opus 가 `src/Auth.php` 에 `s.act_role` 조회를 먼저 저장하고 마이그레이션은 아직 적용 전이라 PDOException 42703. 사용자가 실사용 중 「서버 오류」로 발견 | Fable 이 마이그레이션(열 추가만, DROP/UPDATE 없음 확인) 즉시 적용 → 복구. 규칙 추가(Opus 에 전달, CLAUDE.md 후보): **DB 열을 읽는 코드를 저장하기 전에 마이그레이션을 먼저 적용** · 보안 핵심 파일은 한 파일 저장 직후 `php -l` + 로그인 요청 1회로 500 확인 · 라우트 추가 전에 핸들러 파일 존재. 근본: 이 서버는 개발과 운영이 한 곳 — 스테이징 분리는 사용자 결정 사항(Q-22 후보) |
| 2026-09-15 | 궁금·결정(사용자) | 「HTML 생성기는 서버와 통신하지 않는데 어떻게 인증되나」 → 비밀 키 공유 + 시각 공식(TOTP) 설명. 「소스가 개발자도구에 전부 보이는데 문제 없나」 → 원칙(비밀은 키에만)이지만 사용자 결정: **용도·경고·저장 방식·검토 포인트 설명은 피싱 복제 시 그럴듯함을 주므로 소스·화면에서 빼고 문서로**. 시각은 로그인 화면이 아니라 생성기가 인터넷 있을 때 서버와 **동기화**(하드코딩 아님) | 생성기 머리 주석·화면 설명·근거 각주를 `docs/totp-generator-internals.md` 로 옮김(사용 안내만 남김), 자체 점검 headless 실행 통과·pageerror 0. 동기화(GET /api/time·왕복 보정·오프라인 저장 보정값)와 잠금 문구 12자 최소는 **F-42** 로 26단계 뒤 진행 |
| 2026-09-15 | F-42 검토 ✅ | 생성기 시각 동기화 구현 접수 — Fable 재실행 49/49 · 라우트 1줄 · 린트 0 · 스크린샷 확인. **nginx 배포본 적용은 사용자 승인 뒤**(그때까지 「오프라인 · PC 시각」 표시가 정상). file:// 사본의 실제 동기화는 CSP 결정 대기(Q-22 후보) | 커밋 아래 |
| 2026-09-15 | 궁금·결정(사용자) | 「URL 가져오기는 무엇을·어떤 기준으로 가져오나, 구글 드라이브도 되나」 → 허용 목록(.env)이 **비어 있어 지금은 전부 거부**되고 버튼만 보임 ; 공유 링크는 웹페이지라 불가·공개 링크만 변환 가능·회사 문서는 OAuth 필요. 결정: **관리자 화면에서 허용 URL 관리 · 사용 방법에 목록 안내 · 드라이브/OneDrive 가져오기 · OAuth 도 해야 함(F-44 안 되면 F-43 도 의미 없음) · 회사는 구글·MS 둘 다 · 지금 진행** | F-43 지시서 `docs/f43-fetch-allowlist-directive.md`(DB 허용 목록+재인증·OTP·감사·AL-18 · 버튼 자동 숨김 · CloudLink 공개 링크 변환 · **통제된 리다이렉트** 최대 3번·매 단계 재검사 — 지금은 3xx 전부 거부라 드라이브/OneDrive 불가였음) · F-44 설계 초안 `16-f44-oauth-design.md`(회사 관리자 앱 등록이 선행 조건 — 사용자 요청 필요, R-78~80 후보) |
| 2026-09-15 | 결정(사용자) | **OAuth(F-44) 철회** — 「전체 공유된 링크만 하면 인증이 필요 없다」. URL 가져오기는 공개 링크(링크가 있는 모든 사용자)만 지원 | F-43 에서 F-44 이음새(connector 종류·고정 커넥터 호스트·준비 중 절) 제외로 Opus 에 전달. `16-f44-oauth-design.md` 는 철회 표기 후 참고용 |
| 2026-09-15 | 어려움(사용자) → 보정 | 「관리자 페이지 접근 뒤 관리 밑 사이드바가 전부 죽어 있다 · Elements 엔 있는데 화면엔 없다 · 80% 로 줄이면 보인다 · 스크롤을 내려도 안 움직인다」 | 헤드리스 실측(1366×768): 「관리」 묶음 시작 y=866 > 화면 768, 사이드바 `overflow visible`·높이 100vh 고정 → 26단계 링크 7개 추가로 밀려 **화면 밖**. 숨긴 게 아님(재인증 뒤 상태도 정상). 보정: `.sidebar { overflow-y:auto }` + 항목 높이 40→36·묶음 제목 여백 축소 → 768 에서 스크롤로 전부 닿음(스크린샷). 「역할로 보기」는 관리자 페이지 카드 7개 **아래 폼**에 있음 — 같은 이유로 안 보였을 가능성 |
| 2026-09-13 | 접수(21단계 Opus 보고) | 입고 정책 stock.write(buyer 포함) · Doc.php 못 고쳐 채번 SQL 이 Stock.php 에도 · p_doc_lines qty CHECK 를 실사 줄만 0 허용 · 사이클 화면 입고 칸 「—」 하드코딩(purchase_docs.js 범위 밖) · 재인증 키 미추가(대상 아님) · ARCH 7.4 표에 purchase.* 행 없음(19·20단계 선례) | 전부 수용. 채번 통합·ARCH 7.4 표 보강은 22단계 지시서에, 사이클 칸은 20단계 에이전트 |
| 2026-09-13 | 보정 | 내비 「구매관리」 그룹 8개 링크(발주요청·발주서·입고·이동·실사·재고현황·전표·기초정보·가져오기) 순서 정리, 21단계 세 화면 활성 표시 | 18-3 에이전트, qa_admin01 로 3화면 is-active 확인. 커밋 |
| 2026-09-13 | 보정 | 사이클 화면 입고·미입고 칸을 API 값으로(하드코딩 「—」 제거, 보낸 발주서의 미입고 배지), 요청 화면 낡은 안내 문장 교체(Fable), 사이클 API 의 `orders` 에 GR 번호가 섞이던 것을 `receipts` 로 분리(Fable) | 20단계 에이전트 + Fable. `node --check`·`php -l`·린트, 20단계 시험 90/90(에이전트 실행). 커밋 |
| 2026-09-13 | 접수(22단계 Opus 보고) | purchase.close 가 ARCH 「재인증+TOTP」와 달리 비밀번호 재인증만(본체 F-23 규칙) · PDF 명세서는 글자 있는 PDF 만(스캔은 빈 표 안내) · pr_void 마감 검사 누락(범위 밖) · 요청 수정엔 사유 칸 없어 마감 달은 항상 400 · 새 화면 내비 없음 | 결정: TOTP 는 묻지 않음(문서 정정), pr_void 한 줄·내비는 소규모 보충, 요청 수정 사유 칸은 필요 시 23단계 뒤 |
| 2026-09-13 | 보정 | 내비 「구매관리」 10개 링크(주간 사용량·월 대사·마감 추가, 월 대사·마감·가져오기는 `can_purchase_master`), pr_void 마감 검사 한 줄, ARCH 5.18·PRD 8장 「purchase.close = 재인증(비밀번호)」 정정 | 18-3·22단계 에이전트. 정적 검사·린트 통과, qa_admin01 로 두 화면 is-active 확인. 22단계와 함께 커밋 |
| 2026-09-13 | 접수(23단계 Opus 보고) | 지시서 밖 3파일(index.php 라우트 2줄, items_search·item_get 의 사용 지점 is_active 조건) · stage21 시험 1건은 의도된 변화(제안 칸 null → 값) · 경보 근거에서 계정명·전표 번호·품목 코드 제거(AL-14~16만, AL-1·5·13 은 그대로) · alerts.php `--state` 시험 인자 · 위키 stage-23 미작성 | 전부 수용. stage21 단언은 Fable 이 정정, 위키는 문서 에이전트, AL-1·5·13 근거 개인정보는 별도 검토 항목(Q-19 후보) |


### (제거됨 2026-09-21 → 27단계) 20단계 — 발주요청 · 추천/내 리스트 · 불러오기→발주서 · 인쇄·검토·보냄 (F-30 · F-31 · F-32 · T-45 · Q-18①②④) — 구현 2026-09-13

> 지시서: `docs/purchase/05-stage20-directive.md`. 근거: PRD F-30~F-32 · Q-18 ①②④, ARCH 5.18
> (연결·채번·요청 수정 창·발주서 PDF·송부 전 검토), SECURITY R-65 · R-66 · R-68 · R-73.
> 재고는 움직이지 않는다 — 원장(`p_stock_moves`)과 입고는 21단계다.

**개발 사항**
- [x] 마이그레이션 `2026-09-14_purchase_docs.sql` — `p_docs`·`p_doc_lines`·`p_doc_seq`·`p_lists`·`p_list_items` + `p_item_branches.is_active` + 재귀 CTE 뷰 `p_line_chain`, 인덱스, GRANT(**DELETE 없음**). `db/grants.sql`·`db/schema.sql` 동기화
- [x] `src/purchase/Doc.php` — 채번(`PR|PO-YYYYMMDD-N`)·상태 전이·잔량 계산(요청 qty − Σ 자식 qty)·금액 서버 계산(`qty × p_items.price_in`)·발주서 머리(`header_json`)·참조 확인
- [x] `src/purchase/Recommend.php` — 담당자별 90일 빈도 상위 30, 지점 확장은 「지점과 공유」 리스트가 있을 때만 (R-68)
- [x] API 발주요청: `GET/POST /api/purchase/pr` · `GET/PATCH /api/purchase/pr/{num}` · `POST …/void` · `GET /api/purchase/pr/load`(묶는 기준 1~3개 = 트리 층, 잔량만)
- [x] API 발주서: `GET/POST /api/purchase/po` · `GET/PATCH /api/purchase/po/{num}` · `POST …/send`(정책 `purchase.send`) · `POST …/void`(잔량 되돌리기)
- [x] API 리스트·추천·사이클: `GET/POST /api/purchase/lists` · `PATCH/DELETE …/{num}` · `GET /api/purchase/recommend` · `GET /api/purchase/cycle`
- [x] 화면: `/purchase/pr`(시안 ①②③) · `/purchase/po`(시안 ④⑤) · `/purchase/docs`(시안 ⑧⑨) · `/purchase/po/{num}/print`(시안 ⑤ + 이카운트 발주서 양식) — `purchase_docs.js`·`purchase_docs.css` **별도 파일**(19단계 보정과 충돌 방지)
- [x] `Authz`: `KNOWN_POLICIES` 에 `purchase.send`(viewer·buyer), `PURCHASE_ALIASES` 에 `d`(p_docs)·`pl`(p_lists)
- [x] `Audit`: 문장 틀 `purchase.request|order|list.*` 44행 + `detail` 키 등록(`doc_no`·`doc_type`·`src_doc_no`·`lines`·`amount`·`sent_to`·`reason_text` …)
- [x] `public/index.php`: 라우트 21개(정책 필수) + `TBOX_REAUTH_PATHS` 에 `purchasepr`·`purchasepo`·`purchasedocs`
- [x] `purchase_page.php` 역할별 첫 화면(buyer·viewer → `/purchase/po`, requester → `/purchase/pr`, 그 밖 → `/purchase/masters`) · 구매 화면 `$pageKey='purchase'`(19단계 「내비가 사라짐」 사고 후속)
- [x] `scripts/security_lint.sh` 규칙 둘 추가 — (c) 전표 핸들러는 `lint:no-branch-scope` 예외 금지 (d) 전표 허용 키에 `unit_price`·`amount` 금지
- [x] `scripts/alerts.php` **AL-14** 「보냄 뒤 발주서 수정·취소」(`detail.after_sent`)
- [x] `tests/purchase_stage20.sh` — T-45 + Q-18①②④, **R-73 안전 규칙 3종**(사용자 활동 중이면 종료 코드 3 · 정리는 qa 행만 · 비-qa 행 수 전후 출력)
- [x] README 「구매·재고 모듈」 절에 20단계 내용 추가

**검토 체크 (관문 ②, Fable, 2026-09-13) — 통과, 커밋 8d4101d** — `tests/purchase_stage20.sh` Fable 재실행 90/90(이후 사이클 칸 보정 뒤 94/94), 동시 담기 201/400, 단가·금액 본문 400, requester 다른 지점 0건·404·send 403, 린트 17항목, `\dp` 전표 표 arw. 미검증: 브라우저. SECURITY 7장 20단계 행

**자기 공격 (관문 ③, 사용자)**
- [ ] 요청자 계정으로 발주요청을 넣고, 구매 계정이 담은 **뒤에** 그 줄을 고쳐 본다 (400 `line_locked` 여야 한다)
- [ ] 담긴 전표에 줄을 더해 **새 요청 전표**로 갈라지는지 본다
- [ ] 잔량보다 많이 담아 본다 · 두 창에서 동시에 같은 잔량을 담아 본다 (한쪽만 통과해야 한다)
- [ ] 요청자 계정으로 발주서 「보냄」을 눌러 본다 (403 이어야 한다) · 검토 계정으로 두 번 보내 본다
- [ ] 인쇄 화면에서 「PDF 로 저장」이 되는지, 머리·금액이 이카운트 발주서와 맞는지

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-13 | 접수(Opus 보고) | 지시서 밖 파일 2개 수정 — `db/grants.sql`·`db/schema.sql`(새 표 5개 + 뷰의 GRANT·정의). 두 파일을 두지 않으면 새로 설치하는 DB 에 전표 표가 없고, `grants.sql` 만 고치면 `REVOKE ALL ON p_docs` 가 없는 표를 가리켜 실패한다 | Fable 판단 대기. 19단계도 두 파일을 함께 고쳤다 |
| 2026-09-13 | 접수(Opus 보고) | `Authz::PURCHASE_ALIASES` 에 별칭 둘(`d`·`pl`)을 더했다 — 지시서의 「그 밖 무변경」보다 넓다. 더하지 않으면 `purchaseScopeWhere($user,'d')` 가 예외를 던져 전표 조회가 전부 500 이 된다 | 그 상수의 주석이 "새 표를 스코프에 넣으려면 이 목록을 먼저 고쳐야 한다 — 그것이 검토 지점이다" 라고 적고 있다 |
| 2026-09-13 | 접수(Opus 보고) | 시안 ④의 「미지정 거래처 즉석 지정」은 **요청 전표의 거래처가 비었을 때**의 묶음으로 구현했다. `p_items.vendor_id` 가 NOT NULL 이라 품목에는 언제나 거래처가 있고, 「품목 기본 거래처 학습」(= 품목의 vendor_id 를 고치는 것)은 **하지 않았다** — 그 칸은 `UNIQUE(vendor_id, code)` 의 한쪽이라 바꾸면 품목의 정체가 바뀐다 | 21단계 이후 판단 필요 |
| 2026-09-13 | 접수(Opus 보고) | 19단계 시험(`tests/purchase_stage19.sh`)의 T-44 7차 보정 절이 **사용자의 실제 브랜드 「플란치과」와 이름이 겹쳐** 409 로 실패한다(11건). 시험 픽스처가 실데이터와 같은 이름을 쓴 탓이며 20단계 코드와 무관하다. T-42·T-43·회귀는 전부 통과 | 시험 픽스처 이름을 `S20-` 처럼 접두로 바꾸는 것이 근본 해결 |
| 2026-09-13 | 접수(Opus 보고) | 내비 「구매관리」 그룹에 새 화면 셋(발주요청·발주서·전표) 링크가 **없다** — `src/views/shell_top.php` 가 지시서의 「수정 파일」 밖이라 손대지 않았다. 지금은 각 화면 머리의 단추로만 오간다 | Fable 판단 필요(19단계에서도 같은 이유로 shell_top 수정이 사후 수용됐다) |


### (제거됨 2026-09-21 → 27단계) 21단계 — 입고 · 이동 · 사용 · 실사 · 재고 원장 · 장소별 재고·수불부·미입고·사이클 완성 (F-33 · F-34 · T-46 · Q-18③) — 구현 2026-09-13

> 지시서: `docs/purchase/06-stage21-directive.md`. 근거: PRD F-33 · F-34 · Q-18③, ARCH 5.18
> (원장·음수 재고·채번·재고관리 안 하는 품목·실사 주기), SECURITY R-65 · R-66 · R-73.
> **이 단계의 한 문장**: 수량이 바뀌는 모든 일은 `p_stock_moves` 에 **한 줄 더하기**로만 일어나고,
> 그 줄을 쓰는 코드는 `src/purchase/Stock.php` 하나뿐이며, 앱 계정은 그 줄을 **고치지도 지우지도 못한다**.

**개발 사항**
- [x] 마이그레이션 `2026-09-14_purchase_stock.sql` — `p_docs.doc_type` 에 `gr|mv|is|adj` 추가(`p_doc_seq` 도),
      `p_docs.from_location_id`·`count_cycle`(daily|weekly|monthly), `p_doc_lines.lot_no`·`expiry_date`·`counted_qty`,
      원장 `p_stock_moves`(인덱스 3 + 되돌림 부분 UNIQUE), 뷰 `p_stock_balance`(현재고 = SUM)·`p_backorder`(미입고),
      GRANT **`tbox_app` = INSERT·SELECT 만**. `db/grants.sql`·`db/schema.sql` 동기화
- [x] `src/purchase/Stock.php` — 채번(`GR|MV|IS|ADJ-YYYYMMDD-N`)·전표/줄 INSERT(늘어난 칸)·**원장 기록 한 곳**
      (장소 `FOR UPDATE` → 합 재계산 → 음수면 `negative_stock` → INSERT)·취소는 반대 부호 행 + `reverses_move_id`·
      재고관리 안 하는 품목의 `+`·`−` 두 행·발주서 상태 재계산
- [x] API 입고: `GET /api/purchase/gr/load`(보낸 발주서의 미입고 잔량만) · `POST /api/purchase/gr` · `POST …/{num}/void`
- [x] API 이동·사용·실사: `GET /api/purchase/mv/suggest`(인가량 − 현재고, 보내는 곳 재고 한도) ·
      `POST /api/purchase/mv|is|adj` · `GET /api/purchase/adj/start`(장부 스냅샷) · 네 종류 `POST …/{num}/void`
- [x] API 현황: `GET /api/purchase/stock`(현재고·인가량·과부족·미입고·주평균·제안 자리 null) ·
      `GET /api/purchase/ledger`(수불부 + 누적 잔액) · `GET /api/purchase/backorder`(거래처별·납기 지남·경과일)
- [x] 화면: `/purchase/receipt`(시안 ⑥) · `/purchase/move`(시안 ⑦ 3탭) · `/purchase/stock`(시안 ⑩⑪ 3탭) —
      `purchase_stock.js`·`purchase_stock.css` **별도 파일**(다른 JS 를 고치지 않는다)
- [x] `purchase_cycle.php` — `received_qty`·`not_received` 를 채움(20단계의 `null` 자리)
- [x] `purchase_page.php` — stock 역할의 첫 화면 `/purchase/stock`
- [x] `public/index.php` 라우트 16개(정책 명시). 재고 쓰기는 전부 `purchase.stock.write`
- [x] `Audit` — 문장 틀 `purchase.receipt|move|use|count.{create,void}` 46행 + detail 키
      (`lot_no`·`count_cycle`·`qty_delta`·`moves`·`location_id`·`from_location_id`·`to_location_id`)
- [x] `scripts/security_lint.sh` 규칙 셋 추가 — (e) `INSERT INTO p_stock_moves` 는 `Stock.php` 밖 금지 ·
      (e-2) 재고 핸들러는 지점 격리 예외 표식 금지 · (e-3) 재고 전표도 단가·금액 본문 수신 금지
- [x] `scripts/alerts.php` **AL-15** 「미입고 30일」(뷰 `p_backorder` 기준, 임계값은 23단계 확정)
- [x] `tests/purchase_stage21.sh` — T-46 + F-33·F-34, **R-73 안전 규칙 3종 그대로** + 픽스처 `S21-` 접두
- [x] README 「구매·재고 모듈」 절에 21단계 내용 추가
- [ ] `Authz` 는 **고치지 않았다** — 정책 `purchase.stock.write` 와 별칭 `l`·`d` 가 이미 있어 더할 것이 없었다

**검토 체크 (관문 ②, Fable, 2026-09-13) — 통과, 커밋 ecd2413** — `tests/purchase_stage21.sh` Fable 재실행 105/105, 동시 이동 400/201, `UPDATE p_stock_moves` permission denied, 원장 INSERT Stock.php 1곳, 회귀 T-45 90/90, 린트 20항목. 미검증: 브라우저·실자료 입고. SECURITY 7장 21단계 행

**자기 공격 (관문 ③, 사용자)**
- [ ] 창고 재고보다 많은 수량으로 이동·사용을 눌러 본다 (400 이어야 하고 재고가 그대로여야 한다)
- [ ] 두 창에서 같은 장소·같은 품목을 동시에 빼 본다 (한쪽만 통과해야 한다)
- [ ] 입고를 취소해 본다 — 원장에 **반대 부호 행**이 새로 생기고 미입고가 되돌아오는지, 원본 행이 남는지
- [ ] 실사를 열어 둔 채 다른 창에서 그 장소의 재고를 바꾼 뒤 실사를 저장해 본다 (거부되어야 한다)
- [ ] 요청자(requester) 계정으로 `/purchase/receipt`·`/purchase/move` 의 저장을 눌러 본다 (403)
- [ ] 다른 지점 장소의 재고·수불부를 URL 로 열어 본다 (`?location=` 을 바꿔도 0건이어야 한다)
- [ ] `psql` 로 `SET ROLE tbox_app; UPDATE p_stock_moves …` 를 해 본다 (permission denied)

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-13 | 접수(Opus 보고) | 입고(GR) 라우트 정책을 `purchase.stock.write` 로 잡았다. ARCH 5.18 의 `KNOWN_POLICIES` 주석은 입고를 `buy.write` 로 적어 두었지만, `stock.write` 는 stock **과 buyer** 를 함께 허용하므로 「buyer 도 입고할 수 있다」는 그대로이고 창고 담당이 더해질 뿐이다(시안 ⑥ 의 화면 주인공도 지점 담당자) | Fable 판단 대기. 좁히려면 라우트 표의 정책만 바꾸면 된다 |
| 2026-09-13 | 접수(Opus 보고) | `src/purchase/Doc.php` 가 지시서의 「수정 파일」 밖이라, 채번(`Doc::nextDocNo` 의 접두 표가 `pr`·`po` 뿐)과 전표/줄 INSERT(늘어난 칸)를 `Stock.php` 에 **다시 썼다**. 바뀌지 않은 규칙(`milli`·`qtyText`·`SQL_TAKEN`·`lockLines`·`lineAmount`·`retouchRequestLine`)은 그대로 부른다 | 두 파일에 채번 SQL 이 하나씩 있다. 합치려면 Doc.php 의 `PREFIX` 를 여는 별도 결정이 필요 |
| 2026-09-13 | 접수(Opus 보고) | `p_doc_lines` 의 `CHECK (qty > 0)` 을 풀어야 했다 — 실사의 「장부 스냅샷」이 0 일 수 있기 때문이다(장부에 없던 것을 세어 찾는 경우). 대신 `qty >= 0` + `qty > 0 OR counted_qty IS NOT NULL` 두 제약을 넣어 **실사 줄에만** 0 을 허용한다 | 요청·발주·입고·이동·사용 줄에는 `counted_qty` 가 없으므로 규칙이 그대로 남는다 |
| 2026-09-13 | 접수(Opus 보고) | `tests/purchase_stage20.sh` 의 「입고 칸은 값을 지어내지 않는다(null)」 검사 1건이 이제 실패한다 — 21단계가 그 칸을 채웠기 때문이다(의도된 변화). 그 파일은 지시서의 「수정 파일」 밖이라 고치지 않았다 | Fable 판단 필요: 그 한 줄을 `"received_qty":"0.000"` 확인으로 바꾸면 90/90 이 된다 |
| 2026-09-13 | 접수(Opus 보고) | 새 화면 셋의 내비 항목이 **없다** — `src/views/shell_top.php` 가 지시서 밖이다. `$activeNav` 로 넘기는 `purchase-stock` 등은 그 파일의 허용 목록에 없어 **아무 항목도 켜지지 않을 뿐** 오류는 아니다. 지금은 각 화면 머리의 단추로만 오간다 | 20단계에서도 같은 이유로 사후 수용됐다(내비는 별도 소규모 작업) |
| 2026-09-13 | 접수(Opus 보고) | `TBOX_REAUTH_PATHS` 에 새 화면 키를 **일부러 넣지 않았다**. 그 표에는 쌍둥이(`public/assets/app.js`)가 있는데 app.js 가 지시서 밖이라, 한쪽만 늘리면 두 표가 어긋난다. 세 화면 모두 재인증 관문에 걸리지 않아 지금은 필요도 없다 | 22단계 월 마감이 붙는 날 두 표를 함께 고친다 |

### (제거됨 2026-09-21 → 27단계) 22단계 — 주간 사용량 · 거래처 월 대사 · 월 마감 · 기공 명세서 자동 전표 · 채번 통합 (F-34 · F-35 · T-47 · Q-18) — 구현 2026-09-13

> 지시서: `docs/purchase/07-stage22-directive.md`. 근거: PRD F-34 · F-35 · T-47, ARCH 5.18
> (마감·가져오기·감사) · 7.4 · 7.5, SECURITY R-65 · R-66 · R-69 · R-73, 시안 Weekly · Recon.
> **이 단계의 한 문장**: 우리 장부와 거래처 명세서를 **줄마다 견주고**, 견주기가 끝난 달은
> **잠근다** — 그 뒤의 수정은 자격과 사유가 있어야 하고 흔적이 남는다.

**개발 사항**
- [x] 마이그레이션 `2026-09-14_purchase_recon.sql` — `p_recon_statements`(거래처·월·파일·매핑·명세서 합계·
      `UNIQUE(vendor_id, month, file_id)`) · `p_recon_lines`(품목·수량·단가·금액·`matched_line_id`·`diff_type` 5종) ·
      `p_month_close`(지점 NULL = 전사, `UNIQUE(branch_id, month)` + 전사용 부분 UNIQUE).
      GRANT **`tbox_app` = SELECT·INSERT·UPDATE (DELETE 없음)**. `db/grants.sql`·`db/schema.sql` 동기화
- [x] `src/purchase/Recon.php` — 파일 → 표(xlsx·csv 는 `Sheet::rows`, pdf 는 `Preview::text` 의 **글자만**) ·
      열 매핑 허용 목록 5개(R-69) · 대조(코드 → 이름 정규화, 여러 줄이면 수량 합 + 「n줄 합」) ·
      차이 5종 분류 · 합계
- [x] `src/purchase/Usage.php` — 주간 사용량(IS 원장 음수 행 + 재고관리 안 하는 품목의 GR 양수 행)을
      대/중/소 × 지점 × ISO 주로 집계(SQL 한 번, `purchaseScopeWhere`, 26주 · 500행 상한)
- [x] API: `GET /api/purchase/usage/weekly` · `GET|POST /api/purchase/recon` ·
      `GET /api/purchase/recon/{num}` · `POST …/{num}/preview` · `POST …/{num}/commit` ·
      `GET|POST /api/purchase/close` · `POST /api/purchase/lab`
- [x] 화면: `/purchase/usage`(시안 Weekly) · `/purchase/recon`(시안 Recon, 3탭: 대사·마감·기공 명세서) —
      `purchase_recon.js`·`purchase_recon.css` **별도 파일**(다른 JS 를 고치지 않는다)
- [x] **채번 통합** — `Doc.php` 의 접두 표에 `gr|mv|is|adj` 추가, `insertDoc`/`insertLine` 이 21단계 칸까지 받음.
      `Stock.php` 의 자체 채번·전표 INSERT 문장 삭제 → `Doc::` 호출 껍데기만 남김.
      **동작 무변경**: `tests/purchase_stage21.sh` 통합 전 105 ✔ · 0 ✖ → 통합 뒤 105 ✔ · 0 ✖
- [x] **마감 검사** `Doc::closeGuard` 한 곳 + **여덟 창구** 호출(pr_update · pr_void · po_update ·
      po_void · gr_void · mv_void · is_void · adj_void) — 400 `month_closed`, 자격+사유면 통과 +
      `detail.after_close` (보충 1, 2026-09-13)
- [x] `public/index.php` 라우트 11개(정책 명시) + 재인증 복귀 키 `purchaserecon`·`purchaseclose`,
      `public/assets/app.js` 쌍둥이 표에 **같은 두 키**(21단계 접수 사항 해소)
- [x] `Audit` — 문장 틀 `purchase.recon.{upload,preview,commit}` · `purchase.close.create` ·
      `purchase.lab.create` · 일곱 창구의 `month_closed` 거부 (모두 행 추가만, 삭제 0줄)
- [x] `scripts/alerts.php` — **AL-16** 「단가 급변」(대사에서 ±20% 이상) 신설, **AL-14** 를
      「마감 뒤 수정」까지 넓힘(`detail.after_close`)
- [x] `ARCHITECTURE.md` 7.4 에 `purchase.*` **요약 표**(19~22단계 몫 한 번에) · 7.5 에 detail 키 10개 등록
- [x] `PRD.md` 12.2 에 **T-47** 등록, 13장 로드맵 22단계 행 갱신
- [x] `tests/purchase_stage22.sh` — T-47 (a)~(e), **R-73 안전 규칙 3종 그대로** + 픽스처 `S22-` 접두
- [x] README 「구매·재고 모듈」 절에 22단계 4소절 추가
- [ ] `Authz` 는 **고치지 않았다** — 정책 `purchase.close` 와 별칭 `b`·`d`·`l` 이 이미 있어 더할 것이 없었다

**검토 체크 (관문 ②, Fable, 2026-09-13) — 통과, 커밋 7dc40ca** — `tests/purchase_stage22.sh` Fable 재실행 72/72, 마감 403/400/201, 연락처 컬럼 0, 채번 통합 전후 105/105(Opus), 회귀 T-45·T-46, 린트 20항목. 결정: purchase.close 는 비밀번호 재인증만(TOTP 없음). 미검증: 브라우저·실제 명세서. SECURITY 7장 22단계 행

**자기 공격 (관문 ③, 사용자)**
- [ ] 거래처 명세서를 올려 열을 맞추고 확정해 본다 — 차이 4종이 제대로 갈리는지
- [ ] 매핑에서 「담당자 연락처」 같은 열을 억지로 보내 본다 (400 이어야 하고, DB 에 그 칸이 없어야 한다)
- [ ] 그 달 요청·발주를 확정하지 않은 채 마감해 본다 (400 `open_docs_exist`)
- [ ] 재인증 없이 마감 단추를 눌러 본다 (403 → 비밀번호 재확인 → **대사 화면으로 복귀**)
- [ ] 마감한 달의 입고를 취소해 본다 — 사유 없이(400), 사유를 적고(권한이 있으면 200 + 기록)
- [ ] 기공 명세서 탭에 **재고관리 하는 품목**을 담아 저장해 본다 (400 `stock_managed_item`)
- [ ] 요청자(requester) 계정으로 주간 사용량을 열어 다른 지점 열이 보이는지 본다 (0건이어야 한다)
- [ ] `psql` 로 `SET ROLE tbox_app; DELETE FROM p_month_close …` 를 해 본다 (permission denied)

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-13 | 접수(22단계 Opus 보고) | **ARCH 5.18 과 코드가 다르다** — 5.18 은 `purchase.close` 를 「재인증 **+ TOTP**」로 적었지만, 본체 재인증 창구 `POST /api/me/reauth` 는 **비밀번호만** 다시 묻는다(`src/handlers/reauth.php`: "2단계 인증 코드는 다시 묻지 않는다 — PRD F-23") | **결정(Fable 2026-09-13): 문서를 고친다.** F-23 의 「2단계 코드는 다시 묻지 않는다」 원칙을 유지하고 ARCH 5.18 · PRD 8장의 문구를 「재인증 — 비밀번호 재확인」으로 바꿨다. 관문은 실제로 붙는다(T-47 (c) 실측 403 `next:reauth`) |
| 2026-09-13 | 접수(22단계 Opus 보고) | **PDF 명세서는 「글자가 있는 PDF」만** 읽는다. 새 라이브러리를 넣지 않는다는 규칙 때문에 `Preview::text` 가 뽑아 주는 텍스트만 보고, 공백 2칸·탭을 칸 구분으로 삼아 표를 흉내 낸다. 스캔 이미지 PDF 는 **빈 표**가 되고 응답에 `text_empty: true` 로 알린다 | docs/purchase/03 「이미지 PDF 는 Preview 가 글자를 못 뽑으면 review 로 사람 입력」과 같은 판단. 지금은 「엑셀로 다시 올려 주세요」로 안내한다 |
| 2026-09-13 | 접수(22단계 Opus 보고) | **대사 확정은 한 번뿐이다.** 앱 계정에 `p_recon_lines` DELETE 권한이 없어 줄을 지우고 다시 만들 수 없다. 다시 하려면 명세서를 **새로 올린다**(파일마다 한 장) | `UNIQUE(vendor_id, month, file_id)` 가 그 모양을 강제한다. 두 번째 확정은 400 `already_committed` |
| 2026-09-13 | 접수(22단계 Opus 보고) | `purchase_pr_update.php` 에는 **사유 칸이 없다**(허용 키 6개). 그래서 마감된 달의 요청 수정은 `purchase.close` 를 가졌더라도 언제나 400 `month_closed` 다 — 사유 칸을 새로 여는 것은 20단계 계약을 바꾸는 일이라 하지 않았다 | 고치려면 요청 수정에도 `reason_text` 를 여는 별도 결정이 필요. 발주서 수정·취소에는 사유 칸이 이미 있다 |
| 2026-09-13 | 접수(22단계 Opus 보고) → **해소(보충 1)** | `purchase_pr_void.php`(요청 취소)는 지시서의 「수정 파일」 목록에 없어 마감 검사를 넣지 않았고, 사유 코드만 `status_invalid` 로 달랐다 | Fable 보충 지시로 같은 `Doc::closeGuard` 호출과 감사 `after_close` 를 넣어 **여덟 창구가 같은 낱말**(`month_closed`)을 쓴다. `Audit` 에 `purchase.request.void|deny|month_closed` 문장 틀 한 줄 추가 |
| 2026-09-13 | 접수(22단계 Opus 보고) | 새 화면 둘(`/purchase/usage`·`/purchase/recon`)의 **내비 항목이 없다** — `src/views/shell_top.php` 가 지시서 밖이다(20·21단계와 같은 사정). 화면 머리의 단추로만 오간다 | 내비는 별도 소규모 작업으로 |
| 2026-09-13 | 접수(22단계 Opus 보고) | 재인증 복귀 키를 **둘 다 같은 경로**(`/purchase/recon`)로 넣었다 — 마감은 대사 화면 안의 탭이라 돌아갈 곳이 한 곳뿐이다. 주간 사용량 화면에는 키를 두지 않았다(그 화면의 라우트도 API 도 `purchase.read` 라 관문에 걸릴 자리가 없다) | 21단계가 남긴 「두 표를 함께 고친다」 항목 해소 — `public/index.php` 와 `public/assets/app.js` 에 같은 두 키 |

### (제거됨 2026-09-21 → 27단계) 23단계 — 발주 제안 · 부족 알림 · 경보 AL-14~17 완성 · 마무리 정리 (F-36 · T-48 · AL-14~17 · R-65 잔여) — 구현 2026-09-13

> 지시서: `docs/purchase/08-stage23-directive.md`. 근거: PRD F-36 · 12.2 T-48, ARCH 5.18 · 11.4(경보 표) · 7.4 · 7.5,
> SECURITY R-65(린트 예외 잔여) · R-66 · R-73, 시안 Status(발주 제안 열).
> **이 단계의 한 문장**: 「무엇을 얼마나 시켜야 하나」를 **서버가 한 곳에서 계산**해 근거와 함께 내려 주고,
> 사람이 그것을 골라 요청으로 만들며, 조용히 지나갈 수 있는 일 넷(보낸 뒤 수정·미입고 30일·단가 급변·대량 취소)에
> **소리를 붙인다**.

**개발 사항**
- [x] `src/purchase/Suggest.php` — 제안 식이 적힌 **유일한 자리**(SQL 한 곳).
      ⌈(인가량 + 안전재고 − 현재고 − 미입고 + 조달기간 예상 사용) ÷ 최소구매단위⌉ × 최소구매단위.
      조달기간 예상 사용 = 주평균 사용(최근 4주, `Usage.php` 와 같은 세는 법) × `lead_days` ÷ 7,
      자료가 없으면 0 + `usage_basis: none`. 괄호 안이 0 이하면 제안 0. 근거 값을 **전부** 응답에 싣는다
- [x] `GET /api/purchase/suggest`(`purchase.read`) · `POST /api/purchase/suggest/apply`(`purchase.request.write`)
      — apply 는 제안을 **다시 계산**하고(본문의 제안값은 읽지 않는다), 지점은 **장소에서** 나오며,
      고친 수량이 최소구매단위의 배수가 아니면 400 `not_multiple`. PR memo 「발주 제안 YYYY-MM-DD」
- [x] `purchase_stock_status.php` — 제안 열이 `null` 에서 **실제 값**으로. 조회 SQL 을 `Suggest::rows` 로 옮기고
      범위 조각(`Authz::purchaseScopeWhere`)은 핸들러가 만들어 넘긴다(`Usage.php` 와 같은 모양)
- [x] 화면 `purchase_stock.php`·`purchase_stock.js`·`purchase_stock.css` — 제안 값 + **근거 한 줄**,
      「제안 있는 것만」, 줄마다 담기 체크 + 수량 칸, 「선택 제안을 발주요청으로」(한 번에 한 장소)
- [x] `scripts/alerts.php` — **AL-14~17 임계값 상수 한 묶음** + 중복 억제 **24시간**(다른 규칙은 30분).
      AL-15 는 **거래처별 한 문장**으로 묶고, AL-16 에 **입고 단가 vs 품목 기준 단가 ±20%** 를 더하고,
      **AL-17 대량 취소**(한 사람 1시간 5건)를 새로 만들었다. 근거에 계정명·전표 번호·품목 코드 없음
- [x] `scripts/security_lint.sh` — 규칙 (a) 예외 표식을 **파일 단위 → 조회 단위**로(R-65 잔여 해소).
      표식은 「바로 다음 줄부터 빈 줄·`SQL;`·`];`·`);` 까지」 한 덩어리만 예외. 기존 예외 파일 8개의
      표식을 조회 위로 옮겼다. 규칙 (c) 에 `purchase_suggest*.php` 추가
- [x] `purchase_items_update.php` + `purchase.js`(한 곳) — **사용 지점 빼기**(`branches_off` →
      `p_item_branches.is_active=false`). 감사 `purchase.master.branch_off`
- [x] `Audit.php` — 문장 틀 9행 추가(`purchase.suggest.apply` 7 · `purchase.master.branch_off` 2). 삭제 0줄
- [x] `ARCHITECTURE.md` 5.18(제안·빼기 규칙) · 7.4(요약 표 2행 + 설명) · 7.5(detail 키 2행) · **11.4 AL-14~17 표**
- [x] `PRD.md` 12.2 **T-48** 등록, 13장 23단계 행 갱신 · `README.md` 「발주 제안은 어떻게 나오나」·경보 표
- [x] `tests/purchase_stage23.sh` — T-48 (a)~(f), **R-73 안전 규칙 3종 그대로** + 픽스처 `S23-` 접두
- [ ] `db/grants.sql`·`db/schema.sql` 은 **무변경** — 새 표가 없다(20단계가 만든 `p_item_branches.is_active` 를 쓸 뿐)
- [ ] `Authz` 는 **고치지 않았다** — 정책 `purchase.read`·`purchase.request.write` 와 별칭 `l`·`ib` 가 이미 있다

**검토 체크 (관문 ②, Fable, 2026-09-13) — 통과, 커밋 e43222c·3d29663** — `tests/purchase_stage23.sh` Fable 재실행 92/92, 회귀 stage21 105/105(제안 칸 단언 정정), Opus 회귀 19·20·22 통과, 린트 20항목(예외 조회 단위·음성 시험), `\dp` 유지. 미검증: 브라우저. SECURITY 7장 23단계 행

**자기 공격 (관문 ③, 사용자)**
- [ ] 재고현황에서 제안 옆 근거를 읽어 보고, 인가량·안전재고·조달기간을 바꾼 뒤 제안이 따라 바뀌는지 본다
- [ ] 제안 수량을 **최소구매단위의 배수가 아닌 값**으로 고쳐 담아 본다 (400 이어야 한다)
- [ ] 두 장소의 제안을 한꺼번에 체크해 담아 본다 (한 장소씩만 담긴다는 안내가 나와야 한다)
- [ ] 개발자도구에서 `suggest_qty` 를 크게 고친 뒤 담아 본다 (서버가 **다시 계산**하므로 소용없어야 한다)
- [ ] 요청자(requester) 계정으로 다른 지점 장소의 제안을 `?location=` 으로 열어 본다 (0건)
- [ ] 품목 상세에서 사용 지점을 빼 본 뒤, 그 지점 계정으로 품목 찾기에서 그 품목을 찾아 본다 (없어야 한다)
- [ ] `sudo php scripts/alerts.php --dry-run --rule AL-15` 를 돌려 문장에 거래처 이름 말고
      **전표 번호·품목 코드가 없는지** 확인한다

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-13 | 접수(23단계 Opus 보고) | 지시서의 「수정 파일」 밖 4개를 고쳤다 — `public/index.php`(라우트 2줄), `purchase_items_search.php`·`purchase_item_get.php`(사용 지점 `is_active` 조건), `tests` 밖은 없음. 라우트를 더하지 않으면 새 창구가 아예 닿지 않고, 검색 두 곳을 고치지 않으면 완료 기준 (f) 「품목 검색 `branch_used` 에서 제외」가 성립하지 않는다 | Fable 판단 대기. 20·21·22단계도 같은 이유로 index.php 를 수정 파일에 넣었다 |
| 2026-09-13 | 접수(23단계 Opus 보고) | `tests/purchase_stage21.sh` 의 「발주 제안은 23단계 자리(null)」 검사 1건이 이제 실패한다(99 ✔ · 1 ✖) — 23단계가 그 칸을 채웠기 때문이다(의도된 변화). 그 파일은 지시서의 「수정 파일」 밖이라 고치지 않았다 | Fable 판단 필요: 그 한 줄을 `"suggest_qty":"15.000"` 류의 확인으로 바꾸면 105/105 가 된다. 21단계가 20단계에 남긴 것과 같은 모양의 항목이다 |
| 2026-09-13 | 접수(23단계 Opus 보고) | 경보 근거에서 **계정명·전표 번호·품목 코드를 뺐다**(AL-14·15·16). 20·22단계에는 `actor_name`·`doc_no`·`item_code` 가 들어 있었다. 지시서의 금지 패턴을 「경보 문장뿐 아니라 근거까지」로 읽은 판단이며, AL-1·AL-5·AL-13(9~17단계)은 **그대로 두었다** | Fable 판단 필요 — 앞선 규칙도 같은 기준으로 맞출지, 구매 규칙만 다르게 둘지 |
| 2026-09-13 | 접수(23단계 Opus 보고) | `alerts.php` 에 시험용 인자 `--state <경로>` 를 더했다. 5분마다 도는 cron 이 이미 발동시킨 대상은 운영 상태 파일에서 「억제됨」이라, 완료 기준 (c) 의 「처음 발동 → 재실행 중복 0」을 확인할 수가 없었다(첫 실행에서 이미 건너뜀). `--auth-log` 와 같은 성격의 인자이고, 시험이 **운영 상태 파일을 건드리지 않게** 하는 것이 목적이다 | 실측: 시험이 끝난 뒤 `/var/lib/tbox-v2/alerts-state.json` 에 시험 대상 키 0건 |
| 2026-09-13 | 접수(23단계 Opus 보고) | 제안 계산을 **PHP 가 아니라 SQL** 에 두었다. 화면·현황·apply 세 곳이 같은 값을 써야 하는데, PHP 로 한 번 더 더하면 더하는 곳이 둘이 된다. 숫자는 전부 `numeric` 이라 반올림이 자리마다 정해져 있다(부동소수 오차 없음) | 구현 중 실측: 같은 SELECT 의 WHERE 에서는 그 SELECT 가 만든 별칭(`suggest_qty`)을 볼 수 없어 CTE 를 한 층 더 나눴다 |


### (제거됨 2026-09-21 → 27단계) 24단계 — 브랜드·지점·창고 한 페이지 · 창고 대기표 · 연결/제외 (F-37 · T-49) — 구현 2026-09-13

> 지시서: `docs/purchase/09-stage24-directive.md`. 근거: PRD F-37 · 12.2 T-49, ARCH 5.18(창고 대기표·라우트 표)
> · 7.4 · 7.5, SECURITY R-65 · R-73, 사용자 결정 2026-09-13 저녁.
> **이 단계의 한 문장**: 이카운트 창고 파일에는 지점 열이 없으므로, **지점을 묻지 않고 먼저 받아 두고**
> 사람이 창고마다 지점을 고르는 순간에만 실제 창고를 만든다 — 스키마(`branch_id` NOT NULL)도
> 지점 격리도 한 글자 풀지 않고.

**개발 사항**
- [x] `db/migrations/2026-09-15_purchase_location_inbox.sql` — 표 `p_location_inbox`(`ecount_code` UNIQUE ·
      `status unlinked|linked|excluded` · `location_id`·`source_file_id`), GRANT **SELECT·INSERT·UPDATE**(DELETE 없음).
      멱등(IF NOT EXISTS). `db/schema.sql`·`db/grants.sql` 에 같은 정의 반영
- [x] `src/purchase/Structure.php` — 대기표 upsert(코드 기준 멱등) · 나무 조회 · 추천(이름 해석) · 연결(트랜잭션) ·
      제외/되살리기. SQL 은 전부 고정 문장이고, 역할·branch_id 판정이 한 글자도 없다(ARCH 4.1).
      `lint:no-branch-scope` 표식은 **조회 단위**로 이유와 함께
- [x] 핸들러 5개(`purchase_structure_page|get|upload|link|exclude.php`) + 라우트 5줄
      (`public/index.php`, 전부 `purchase.master.write` → 재인증 관문이 자동으로 붙는다)
- [x] `Importer::storeUploaded()` — 가져오기 업로드와 창고 업로드가 **함께 쓰는 한 함수**(동작 무변경,
      stage19 회귀가 증거). 네 번째 인자로 「트랜잭션 안에서 할 일」을 받아, 창고 업로드는 파일 저장 +
      대기표 upsert + 감사 한 줄이 **한 트랜잭션**이다
- [x] 화면 `src/views/purchase_structure.php` · `public/assets/purchase_structure.js|css` — `textContent` 만,
      인라인 script·style·on\* 없음. `purchase.js` 는 건드리지 않았다
- [x] 내비 `shell_top.php`(항목 `nav-purchase-structure`, 기본 hidden) · `app.js` 한 줄(`can_purchase_master`)
      + 재인증 복귀 키 `purchasestruct`(서버 두 표 · app.js 한 표, 세 곳이 같은 내용이어야 한다)
- [x] `Audit.php` 문장 틀 24행(upload 15 · link 5 · exclude 4). `ALLOWED_DETAIL_KEYS` 는 **무변경** —
      새 키 14개 중 금지 낱말 부분 일치에 걸리는 것이 없다
- [x] `PRD.md` F-37 신설 · 12.2 T-49 · 13장 24단계 행 / `ARCHITECTURE.md` 5.18(창고 대기표·업로드 공통 조각·
      라우트 표 5행) · 7.4(3행 + 설명) · 7.5(detail 키 4행) / `SECURITY.md` R-65 한 줄 / `README.md` 한 절
- [x] `src/views/purchase_import.php` — 가져오기 화면 맨 위에 새 화면으로 가는 안내 한 줄(JS 무변경).
      19단계의 창고·지점 파생 흐름은 **삭제하지 않았다**(회귀 유지)
- [x] `tests/purchase_stage24.sh` — T-49 (a)~(h), R-73 안전 규칙 3종 그대로 + 픽스처 `S24-` 접두,
      인구조사에 `p_location_inbox` 포함, 정리는 qa `created_by` 행만(postgres 로)

**검토 체크 (관문 ②, Fable)** — ✅ 2026-09-13 통과
- [x] 코드 읽기: Structure.php 고정 SQL·`FOR UPDATE` 행 잠금·유일 인덱스 23505→409 전체 롤백 · 대기표 upsert 가 `storeUploaded()` 트랜잭션 안(파일 저장과 함께 되돌아감) · 핸들러 허용 키 검사 · 감사 문장 24행 등록 · 린트 표식 조회 단위
- [x] `bash scripts/security_lint.sh` 0건 · `php -l` 전 파일 통과
- [x] `tests/purchase_stage24.sh` **88/88** · 회귀 `tests/purchase_stage19.sh` **230/230**(⑦ 단언을 `?q=S19-` 로 좁혀 사용자 실데이터와 무관하게 함) · 비-qa 행 수 변동 0
- [x] 실사용 흔적: 사용자가 검토 전에 이미 새 화면으로 창고 12건을 부산점에 연결(감사 `purchase.structure.link allow · links=12 · 21:21`) — 브라우저 흐름(재인증 복귀·추천 선택·연결)이 실제로 동작함을 뜻함
- 남은 것: 관문 ③ 항목(아래) 사용자 확인 (위키 `stage-24.json` 은 25단계 커밋 6d0d818 에 포함, SECURITY 7장 24단계 행도 그때 뒤늦게 기록)

**자기 공격 (관문 ③, 사용자)**
- [ ] 실제 이카운트 창고 파일(ESA005M 96행)을 **지점을 고르지 않고** 그대로 올려 본다 (400 이 없어야 한다)
- [ ] 같은 파일을 두 번 올려 본다 (두 번째는 「변동 없음」만 늘어야 한다)
- [ ] 「S24-중앙창고」처럼 지점을 알 수 없는 창고를 **제외**한 뒤, 나머지를 연결해 「모두 정리됨」까지 가 본다
- [ ] 같은 지점에 같은 이름의 창고를 두 번 연결해 본다 (묶음 전체가 저장되지 않아야 한다)
- [ ] 개발자도구로 `select` 에 없는 지점 id 를 넣어 보낸다 (중단된 지점·남의 값이면 400)
- [ ] 구매 권한이 없는 계정으로 `/purchase/structure` 를 직접 열어 본다 (403)
- [ ] 비밀번호 재확인 창이 뜬 뒤 확인하면 **이 화면으로 돌아오는지** 본다 (홈으로 떨어지면 안 된다)

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-13 | 결정(사용자) | 브랜드·지점은 사람이 만든다 · 창고 파일은 브랜드·지점 없이 그냥 올린다 · 창고 이름은 이카운트 원문 그대로 · 지점 없는 창고는 「제외」 · 창고 하나는 지점 하나 | 지시서 `docs/purchase/09-stage24-directive.md` 「확정 사항」. 스키마 `p_locations.branch_id` NOT NULL 유지가 조건 |
| 2026-09-13 | 접수(24단계 Opus 보고) | 지시서의 「수정 파일」 밖 두 곳을 함께 고쳤다 — `public/index.php` 의 재인증 복귀 표 2행(`TBOX_REAUTH_RETURN`·`TBOX_REAUTH_PATHS`)과 `public/assets/app.js` 의 같은 맵 1행. 새 화면의 라우트 정책이 `purchase.master.write` 라 **화면 요청 자체가 관문에 걸리고**, 키가 없으면 확인 뒤 홈으로 떨어진다(19단계 4차 보정의 사고) | Fable 판단 대기. 키는 `purchasestruct`(밑줄·숫자 없이 16자 이하 — `Http::redirect` 의 꼬리표 규칙) |
| 2026-09-13 | 접수(24단계 Opus 보고) | 이 서버에는 **mbstring 확장이 없다** — `mb_strtolower` 를 쓴 첫 판이 500 이었다. `Importer` 가 쓰는 `strtolower`·`iconv_strlen` 으로 맞췄다(한글은 대소문자가 없어 ASCII 만 내려도 충분하다) | 실측: `/var/log/tbox-v2/app.log` 「Call to undefined function mb_strtolower()」 → 고친 뒤 시험 84/87 → 87/87 |
| 2026-09-13 | 접수(24단계 Opus 보고) | 회귀 `tests/purchase_stage19.sh` 가 **228 ✔ · 1 ✖** 이다. 실패한 한 줄은 ⑦ 「buyer 는 두 지점 모두」(`total` 하드코딩 2)인데, 시험 도중 **사용자가 새 화면으로 실제 창고 12개를 연결**해 `p_locations` 가 14행이 되었기 때문이다(감사 `purchase.structure.link allow · links=12 · admin01 · 21:21`). 24단계 코드의 회귀가 아니라 **표에 시험 행만 있다고 가정한 단언**이다. 그 파일은 지시서의 「수정 파일」 밖이라 고치지 않았다 | Fable 판단 필요: 그 한 줄을 「S19- 행만 세기」로 바꾸면 229/229 가 된다(24단계 시험도 같은 이유로 `counts.*` 대신 `S24W%` 만 센다) |
| 2026-09-13 | 접수(24단계 Opus 보고) | 400 응답 문구를 이 화면만 **사유별 한국어 문장**으로 돌려준다(`bad_header`·`conflict`·`not_unlinked`·`not_excluded`·`bad_reference`). 다른 화면은 동일 문구 하나다(PRD S-09-5) | 판단 근거: 여기까지 오는 사람은 buyer·Admin 이고 전 지점 기초정보를 이미 다 보므로 새어 나갈 정보가 없다. 문장은 `Structure::MSG_*` **코드 상수**이고 요청 값이 섞이지 않는다(`MSG_FOLDER_NOT_EMPTY` 와 같은 성격) |


### (제거됨 2026-09-21 → 27단계) 25단계 — 구매 화면 UI 정돈 · 공통 CSS 한 벌 · 시안 충실도 · 사이드바 3묶음 · 2단 레이아웃 (T-50) — 착수 2026-09-14

> 지시서: `docs/purchase/10-stage25-directive.md`. 근거: PRD 13장 25단계 · 12.2 T-50, ARCH 5.18 「구매 화면 공통 규칙」. 사용자 피드백(2026-09-14): 「구매 관련 기능들이 최초 기획 초안 대비 상당히 퀄리티가 떨어져 보이고, 정리가 안 되어 보이는 모습이 굉장히 많다 — 구성 요소 크기가 들쭉날쭉, 같은 줄 버튼 하나만 크다, 텍스트 잘림·찝힘, 카드 겹침·잘림, 한눈에 정보를 알기 어렵다」 → 한 단계로 묶어 통합 진행, **사용자 관점 시각 점검 필수**.

**개발 사항** (Opus)
- [x] `app.css` 공통 조각(.panel__head--row·.panel__tools·.field--inline·.check·.badge--warn/danger/accent·.chip·.tabs·.kpi·.num·.kbd·.empty·.help·.grid-form·.split·.split--aside·.filelabel·.nav-subtitle) · `purchase.css` 한 벌로 재작성 · 4벌(docs/stock/recon/structure.css) 삭제 · `purchase_print.css`
- [x] `purchase_common.js`(PurchaseUI 헬퍼 한 벌) · 5개 번들 중복 제거 · 사이클 표 한 함수(`renderCycleRows` — 발주요청 현황 탭과 전표 사이클 탭이 같은 열)
- [x] `shell_top.php` `$pageCss` head 출력(화이트리스트) · 「구매관리」 세 소제목(전표/현황/기초정보) · `shell_bottom.php` `#msg` 한 번(`$pageMsgTop`)
- [x] 13개 view: 시안 뼈대(crumbs·제목·리드문 1줄·이 화면의 액션만) · 머리 카드 위/줄 카드 아래(pr·po) · 필터 한 줄 · 빈 상태 `.empty` hidden 토글 · 페이징·재조회 라벨 표준 · 배지 색=의미 · `mono`/`num` · `.notice` 더미 → `.help`
- [x] 2단 복원: 전표 목록 우측 「연결 전표」(기존 `GET /api/purchase/cycle?pr=|po=` 만) · 불러오기 좌 트리/우 줄 표 · 추천/내 리스트 2열 · 가져오기 좌 요약 카드 · 브랜드지점창고 `.split`
- [x] 개발 잔재 제거: 「N단계」 문구 0(분류 옵션 「1단계(대분류)」→「대분류」 포함) · 수불부 「품목 id」 입력(→ 현재고 숫자 링크) · 더미 인쇄 링크 · 라벨 없는 `<th>` 0(`sr-only`) · 도달 불가 품목 폼 삭제
- [x] `tests/purchase_stage25.sh` T-50 (a)~(g) · `deploy/DEPENDENCIES.md` 5.4 검토 도구 절 · README 내비 설명 3묶음
- [x] (지시서 밖 한 줄, 판단 필요) `shell_top.php` 에 `data:` 파비콘 — 브라우저가 스스로 부르는 `/favicon.ico` 404 가 모든 화면의 콘솔 오류로 잡혀 T-50 (a) 의 `console_errors` 를 0 으로 만들지 못했다. 셸 전체에 영향을 주므로 Fable 이 되돌려도 되는 한 줄이다

**검토 체크** (Fable — 실행해서 확인)
- [x] `scripts/ui_snapshot.sh` 1440·1024 두 폭 합계 **0건**(착수 시 실측 65건 — 지시서의 46건은 dup_id·stage_text·console 검사 추가 전 값) — `tests/purchase_stage25.sh` **40/40**. 스크린샷 12장을 Read 로 직접 열어 확인(pr·po·receipt·stock·import·masters·masters 장소·docs·structure·pr 전표·pr 1024). 눈으로 잡은 잔여 1건: 전표 목록 좌 칸에서 「기간 ~」 두 번째 날짜가 다음 줄로 접힘 → Fable 이 두 날짜를 한 묶음(`.field--range`, 로직 없음)으로 합치고 `app.css` 에 날짜 폭·묶음 2규칙 추가 후 1440·1024 재촬영 0건
- [x] 회귀 stage19~24 = 229·90·100·71·90·87, 실패 0 · 비-qa 행 196→196 · 린트 20항목 통과 · 핸들러 13개 diff = `$pageTitle`·`$pageCss`·`$pageMsgTop`·주석만(`git diff -U0` 로 확인)
- [x] 보안 핵심 파일 diff 0(`git status -- public/index.php src/Authz.php src/Audit.php src/Auth.php src/Db.php src/Storage.php src/Upload.php db/ deploy/sites` 출력 없음) · `$pageCss` 허용 목록 `in_array(strict)` · 인라인 style 0 · innerHTML 0 · CSP 헤더 `img-src 'self' data:` 실측(파비콘 data: URL 허용 범위)

**자기 공격 (관문 ③, 사용자)**
- [ ] 1440 과 1024 폭에서 구매 화면 11개를 한 바퀴 돌며 「들쭉날쭉·잘림·겹침·헤매는 내비」가 남았는지 본다 — 남았으면 화면·위치를 학습 기록에 적는다(보정 커밋)
- [ ] 사이드바에서 「구매관리」 세 묶음만으로 모든 화면에 닿는지(헤더의 다른 화면 링크가 사라졌으므로)
- [ ] 개발자도구로 `<link>` 목록을 본다 — app.css·purchase.css 두 개(인쇄 화면 +1)뿐인지

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-14 | 요청(사용자) | 구매 기능이 초안 대비 퀄리티가 떨어지고 정리가 안 돼 보인다 — 크기 들쭉날쭉, 같은 줄 버튼 하나만 큼, 텍스트 잘림·찝힘, 카드 겹침·잘림, 한눈에 정보 파악 어려움. 한 단계로 묶어 통합 진행, 사용자 관점 시각 점검 필수 | 원인 12가지를 파일·스크린샷으로 검증해 지시서 0장에 적음(`docs/purchase/10-stage25-directive.md`). 최대 원인은 화면별 CSS 5벌 — `.panel__tools` 가 6개 화면에서 미정의 |
| 2026-09-14 | 결정(Fable) | 시각 결함을 「읽어서」 아니라 「실행해서」 잡기 위해 headless Chromium 검토 도구 `scripts/ui_snapshot.sh` 를 만들었다(qa_admin01, UA tbox-review). 이 서버에 node 가 없어 python3 playwright 를 사용자 로컬(`~/.local`, `~/.cache/ms-playwright` ≈ 440MB)에 설치 — 런타임 의존성 아님 | 개선 전 기준 1440px 46건(undefined_class 43·row_height 4). 설치 중 루트 디스크(12G)가 100% 가 되어 apt 캐시·불필요 브라우저를 지웠다 — 현재 96%(521MB 남음). **디스크 여유 확보는 사용자 결정 필요**(SECURITY 6장 앱 밖 위험 후보) |
| 2026-09-14 | 결정(Fable, 사용자 확인 대기) | 사이드바는 시안의 3허브(기초정보/전표/현황)를 **소제목 3묶음**으로 옮기고 링크 11개는 유지한다 — 허브 페이지 3장으로 바꾸면 라우트·화면이 늘어나 이번 단계(기능 추가 없음) 범위를 넘는다 | 지시서 shell_top (b) |
| 2026-09-14 | 접수(25단계 Opus 보고) | 지시서 밖 1줄 — `shell_top.php` 에 `data:` SVG 파비콘. 브라우저가 스스로 부르는 `/favicon.ico` 404 가 모든 화면 콘솔에 오류 1줄을 남겨 T-50 (a) console=0 이 성립하지 않았음 | **수용(Fable)**: 고정 문자열, CSP `img-src 'self' data:` 안(응답 헤더 실측), 새 파일 없음. `/login` 은 셸을 안 써 여전히 404 — 다음에 셸 밖 화면까지 같은 방식으로 |
| 2026-09-14 | 접수(25단계 Opus 보고) | 완료 기준 (c) 의 원문 grep(`^\.[a-z0-9_-]+ … uniq -d`)은 `.btn:hover`·`.btn .icon` 같은 정상 선택자도 같은 낱말로 세어 app.css 기존 규칙에서 반드시 걸림 → 「단일 클래스 규칙 블록이 두 번」으로 판정, 원문 grep 출력은 로그에 그대로 | 수용. 예외 `.wiki-check__row`(14단계 위키가 묶음+개별 선택자로 쓰는 자리) 명시 |
| 2026-09-14 | 접수(25단계 Opus 보고) | 시안 PNG 와 나란히 비교하지 못함(디스크 여유) → 소스 대조로 갈음. JS 문법 검사 도구 없음(node 미설치) → 브라우저 콘솔 0 + 모달 4종 수동 확인 | Fable 이 시안 PNG(착수 시 렌더해 둔 것)와 구현 스크린샷을 직접 비교 — 배치·배지·mono 일치 확인. 남은 차이는 26단계 후보(포장 열·납기·품목 등록·필터 칩·미입고 행 액션)로 지시서 4장에 있음 |
| 2026-09-14 | 결정(Fable) | 개선 전 기준 수치가 46 → 65 로 달라진 이유: 지시서 작성 뒤 검사기에 dup_id·stage_text·console 세 항목을 추가함(46 + 11 + 6 + 1 + 1). 두 수치 모두 기록으로 남김 | `scripts/ui_snapshot.py` 머리 주석의 결함 종류 목록 |
| 2026-09-14 | 운영(Fable, 사용자 승인) | 루트 디스크 12G 가 96%(500MB) — LVM 볼륨 그룹에 11.5G 미할당 확인. 사용자 결정으로 **0.5GB 만** 확장(`lvextend -L +512M -r`, 온라인, 무중단) → 92%(983MB). VG 잔여 11.0G | SECURITY 6장 「앱 밖 위험」 후보: 같은 디스크의 PostgreSQL. 추가 확장은 사용자 결정 |

### 화면 문구 정리 2단계 — 「사용 방법」 단추와 화면별 도움말 (F-38 · T-51) — 구현 2026-09-15

> 지시서: `docs/help-button-directive.md`. 근거: PRD 6장 F-38 · 12.2 T-51, ARCH 5.19, `docs/ui-copy-cleanup.md`(1단계에서 무엇을 지웠는지).
> 사용자 결정 2026-09-15: 「사용 방법 버튼(화면 사용자 모두) + 구조 설명은 위키」. 1단계(문구 삭제·축소 68곳, 커밋 `06ee617`)에서 지운 **업무 기준**을 단추 뒤 도움말로 되살린다.

**개발 사항** (Opus)
- [x] `src/help/screens.php` — 화면 20개의 도움말 글 한 벌(정적 PHP 배열, 순수 문자열, HTML 0). 지시서 1장 원문 그대로 옮김(문장을 새로 쓰지 않음)
- [x] `src/views/shell_bottom.php` — `$pageHelp` 키가 배열에 있으면 `<dialog id="help-dialog" class="modal help-dialog">` 를 한 번 출력(`.modal__head`/`.modal__body`/`.modal__foot`), 모든 문자열 `htmlspecialchars(ENT_QUOTES)`. 키가 없거나 오타면 아무것도 출력하지 않음. 아이콘 템플릿 `tpl-icon-help` 추가
- [x] `src/views/shell_top.php` — 문서 주석에 `$pageHelp` 한 줄(그 밖 변경 없음)
- [x] `public/assets/app.js` — 위임 하나(`initHelpDialog`): `[data-help-open]` → `showModal()`, `[data-help-close]` → `close()`, ESC 는 `<dialog>` 기본. `innerHTML` 계열 0, 다른 함수 변경 없음
- [x] `public/assets/app.css` — 끝에 `.help-dialog` 7줄(폭 `min(640px,92vw)` · 본문 `max-height:70vh` · `h3`·`ul` 간격). 기존 규칙 변경 0
- [x] handler 20개에 `$pageHelp = '<키>';` 한 줄씩(`$pageKey` 옆) — 로직 변경 0
- [x] 화면 20개의 `.page-head__actions` 첫 자리에 「사용 방법」 quiet 단추(아이콘 `help`). 없던 화면 13곳에는 `page-head` 안에 `.page-head__actions` 를 만들어 넣음
- [x] `tests/help_button.sh` — T-51 (a)~(e) 실행(정적 검사 + `scripts/ui_snapshot.sh` 20화면 1440 + 구매 11화면 회귀). `scripts/ui_snapshot.sh` 는 고치지 않음
- [x] PRD 6장 F-38 · 12.2 T-51 · ARCH 5.19 · STAGES(이 절)
- [x] 모달이 열린 모습 3장(홈·발주요청·계정 관리)을 Playwright 로 찍어 직접 눈으로 확인 — `~/.claude/ui-shots/help-button/modal/`

**검토 체크** (Fable — 실행해서 확인)
- [ ] `bash tests/help_button.sh` 전 항목 통과 · `scripts/security_lint.sh` 0건 · `php -l`
- [ ] 도움말 글이 지시서 1장과 **문장 단위로 같은지**(Opus 가 문장을 새로 쓰지 않았는지)
- [ ] 로그인·재인증·2단계 등록·위키 화면에 단추가 없는지 · 새 라우트·API·DB 변경 0 · 보안 핵심 파일 diff 0

**자기 공격 (관문 ③, 사용자)**
- [ ] 화면 20개를 돌며 「사용 방법」을 눌러, 적힌 내용이 **지금 그 화면에서 실제로 되는 일**과 맞는지 본다(틀리면 화면·문장을 학습 기록에 적는다)
- [ ] 개발자도구로 `#help-dialog` 의 내용을 고쳐 본다 — 새로고침하면 서버 글로 돌아오는지(브라우저에 저장된 것이 아님)
- [ ] `/login`·`/reauth`·위키에는 단추가 없는지, 도움말 전용 주소(`/api/help/...` 같은 것)가 없는지

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-15 | 결정(사용자) | 「사용 방법 버튼(화면 사용자 모두) + 구조 설명은 위키」 | 1단계에서 지운 업무 기준을 단추 뒤로 옮긴다. 도움말은 업무 기준·조작 순서만, 서비스가 어떻게 만들어졌는지는 위키(F-21). ARCH 5.19 |
| 2026-09-15 | 결정(Opus, 판단 필요) | 완료 기준 (a) 의 금지어 목록에 「감사 로그」가 통째로 적혀 있으나, 지시서 **금지 패턴** 줄의 원문은 「감사 로그에 남습니다」이고 1장 도움말 원문에는 「전체 기록은 관리자가 「감사 로그」에서 봅니다」·화면 이름 「감사 로그」가 그대로 있다. **1장 내용 그대로**가 우선이라 판단해 시험은 「감사 로그에 남」으로 검사하고, 화면 이름으로 쓰인 3곳은 남겼다 | `tests/help_button.sh` (a) 의 `$banned` 배열과 `NOTE_감사로그=3` 출력 |
| 2026-09-15 | 측정(Opus) | (d) 「20화면 1440px 합계 0건」은 **작업 전에도 7건**이었다: 기능 이름 「2단계 인증」과 자료에 들어 있는 파일 이름(「3단계 화면 확인용 공개 파일」)이 `stage_text` 정규식 `\d{1,2}단계` 에 걸린 6건 + `/org` 의 규칙 없는 class `org-teams` 1건. 전부 이번 변경과 무관하고 지시서 「수정 파일」 밖이라 손대지 않았다 | 착수 전 측정 `~/.claude/ui-shots/help-button/baseline-1440.txt`(합계 7건) ↔ 작업 후 `d-1440.txt`(합계 7건, 같은 항목). 시험은 「기존값 이하」로 판정 |
| 2026-09-15 | 결정(Opus) | 지시서는 app.js 에서 `openDialog()` 를 부르라 했으나 그 함수는 `initFiles`·`initOrg` **안의 지역 함수**라 밖에서 부를 수 없다. 같은 규칙(showModal 이 없으면 아무 일도 하지 않음)을 쓰는 한 쌍을 `initHelpDialog` 안에 두고 **다른 함수는 한 줄도 고치지 않았다** | `public/assets/app.js` `initHelpDialog()` 주석 |

### 26단계 — 관리자 「역할로 보기」 · 관리 내비 · 계정 편집 폼 · 폴더 삭제 규칙 (F-39 · F-40 · F-41 · T-52~T-54) — 구현 2026-09-15

> 지시서: `docs/stage26-directive.md`. 근거: PRD 6장 F-39~F-41 · 12.2 T-52~T-54 · Q-21, ARCH 5.20, SECURITY R-74·R-75.
> 사용자 결정 2026-09-15: ① 「역할로 보기」 A안(세션 안 임시 역할, 아래로만) ② 구매 모듈 역할·지점까지 고름 ③ 만료는 **활동 없는 30분**만.
> 같은 날 사용자 지적 4건: 관리자 페이지에 들어가면 사이드바의 「관리자 페이지」가 사라짐 / 계정·조직 화면에서 뒤로가기로만 돌아옴 /
> 계정 변경이 문답(prompt) 입력이라 불편하고 「변경했는데 로그에 없다」 / 빈 폴더(qa-18r-T) 삭제가 거부됨.

**개발 사항** (Opus)
- [x] `db/migrations/2026-09-16_sessions_act_as.sql` — `sessions` 에 `act_role`·`act_team_id`·`act_purchase_role`·`act_branch_id`·`act_started_at`·`act_last_seen_at` 6열 + CHECK 2개(`Manager|Employee|Guest`, 모듈 역할 4값). `db/schema.sql` 동기화. grants 변경 0
- [x] `src/Auth.php` — `SQL_LOOKUP` 에 act_* 6열 + DB 가 계산한 `act_role_ok`(실제 역할이 지금도 Admin 인가)·`act_fresh`(활동 없는 30분). 셋 다 참이면 `role`·`team_id`·`purchase_role`·`branch_id` 를 보는 값으로 바꿔 돌려주고 `real_*`·`acting`·`acting_since` 를 함께 준다. `SQL_TOUCH` 에 `act_last_seen_at` 한 열 추가(쿼리 수 증가 0). 만료·강등이면 그 자리에서 6열 NULL + `act.expire allow`. `Auth::reload()`(캐시만 버림) 추가. **역할 비교는 SQL 한 줄뿐**
- [x] `src/Authz.php` — **변경 0줄**. 유효 역할이 이미 `$u` 에 들어오므로 `authorize`·`can*`·`purchaseAuthorize`·`purchaseScopeWhere` 가 그대로 따라온다(이 단계의 핵심)
- [x] `src/Audit.php` — `actor_role` 은 **실제 역할**(`real_role`), `autoDetail()` 이 보는 중이면 `acting_as {role, team_id, purchase_role, branch_id}`·`real_role` 을 자동으로 붙임. 문장 틀 `act.start`(allow/deny 2) · `act.stop` · `act.expire` · `folder.delete deny folder_has_children|folder_has_trashed` 추가
- [x] `src/handlers/me_act_start.php`(POST `/api/me/act`, 정책 `role:Admin` → 재인증 자동) · `me_act_stop.php`(DELETE, 정책 `authenticated`) · `public/index.php` 라우트 2줄
- [x] `src/handlers/me.php` — `acting`·`acting_as{role,team_name,purchase_role,branch_name}`·`real_role`·`acting_idle_minutes`·`can_stop_acting` 추가. can_* 는 **유효 역할 기준 그대로**(그래야 화면이 그 역할로 보인다)
- [x] `src/views/shell_top.php` — 「관리」 묶음에 링크 7개(기본 hidden, `can_admin` 하나로 함께 열림 — 역할 비교 없음) · `$user['acting']` 이면 본문 맨 위에 띠(서버가 그린다 — 보는 중에는 관리 묶음이 닫혀 이 「원래대로」가 유일한 복귀 경로) · 칩 역할에 「(보기)」
- [x] `src/handlers/admin_page.php` — 카드 「역할로 보기」(역할·팀·모듈 역할·지점 셀렉트, 선택지는 `/api/teams`·`/api/purchase/branches` 에서)
- [x] `public/assets/app.js` — `inGate` 를 `password_change|mfa_setup` 로(재인증은 내비를 닫지 않음) · 관리 링크 7개 열기 · `[data-act-stop]` → DELETE → 새로고침 · 역할로 보기 폼 submit · **계정 변경 모달**(prompt 6개 → `<dialog>` 하나, 바뀐 칸만 PATCH, 취소·무변경·실패를 전부 `#msg` 에) · 사유 모달 · 폴더 삭제 3갈래 + 휴지통 목록 경고 모달
- [x] `src/handlers/users_page.php` — 모달 마크업 2개(`user-edit`·`reason-dialog`). 계정 화면의 `window.prompt(` **0개**
- [x] `src/handlers/folders_delete.php` — 세 갈래(`folder_has_children` / `folder_not_empty` / `folder_has_trashed` + 볼 수 있는 파일 목록·`others`). 확인하면 같은 트랜잭션에서 휴지통 파일 `folder_id` NULL → 폴더 삭제, 감사 `moved_trashed`·`moved_file_ids`
- [x] `src/Http.php` 문구 상수 3개 · `src/help/screens.php`(admin 「역할로 보기」 절 · users 「바꾸기」 모달 기준 · files 삭제 한 줄 · trash 한 줄)
- [x] `scripts/security_lint.sh` 새 규칙 (f) — `act_role`·`real_role` **값 비교**가 `Auth`·`Audit`·`me_act_*` 밖에 있으면 반려(반려 동작도 확인)
- [x] `tests/stage26.sh` — T-52·T-53·T-54 (qa 계정만·UA `tbox-review`·픽스처 `S26-`·정리는 qa created_by/uploaded_by·R-73 관문)
- [x] PRD F-39~41 · T-52~54 · Q-21 · 8장 API 2행 / ARCH 5.20 · 7.4 · 7.5 / SECURITY R-74·R-75·R-63 보충·7장 이력 / README 「관리자」 절

**검토 체크** (Opus 자체 실행 — 원문은 보고서)
- [x] `bash tests/stage26.sh` → **124 ✔ · 0 ✘**, 비-qa 행 374 → 374(남의 자료 0건 변화)
- [x] 회귀 `tests/purchase_stage19.sh` 229 ✔ · 0 ✘(T-1·T-26·T-36 포함) · `tests/purchase_stage25.sh` 34 ✔ · 0 ✘(1440·1024 시각 결함 0) · `tests/help_button.sh` 17 ✔ · 0 ✘ · T-22 수동 실측(200 → 비활성화 → 401 → 재활성화)
- [x] `scripts/security_lint.sh` 0건(새 규칙 포함) · `php -l` src·public 전체 · `\d sessions` act_* 6열 + CHECK 2개
- [x] 화면 눈 확인 — 띠(`act-banner.png`) · 관리 내비(`admin-nav.png`) · 계정 변경 모달(`user-edit-modal.png`) · 폴더 경고 모달(`folder-trashed-modal.png`) @ `~/.claude/ui-shots/stage26/`
- [x] **Fable 재검토(2026-09-15)** — 시험 재실행은 R-73 관문(사용자 리허설 중)으로 막혀 Opus 원문 접수 ; 대신 세션만 건드리는 실측 8단계(403/400/200/403/403/끄기/200) 통과 · 린트 0 · Authz diff 0 · index.php 라우트 2줄만 · 스크린샷 3장. **R-74·R-75 ✅**. `.act-banner` 정렬 CSS 3줄 Fable 추가

**자기 공격 (관문 ③, 사용자)**
- [ ] 관리자 페이지 → 「역할로 보기」로 Employee 를 켠 뒤, 개발자도구로 띠를 지우고 `/users`·`/api/users` 를 직접 불러 본다 — 403 이 나오는지(화면이 아니라 서버가 막는지)
- [ ] 보는 중에 `POST /api/me/act` 를 콘솔에서 `{"role":"Admin"}` 으로 직접 던져 본다 — 403 인지(겹쳐 켜기·위로 가기 둘 다)
- [ ] 보는 중에 파일을 하나 올린 뒤 감사 로그에서 그 행을 찾아, **누가**(actor_role) 와 **무슨 역할로 보였나**(acting_as) 가 함께 남았는지 본다
- [ ] 30분 동안 아무것도 하지 않은 뒤 새로고침 — 원래 역할로 돌아와 있는지. 반대로 계속 쓰는 동안에는 끊기지 않는지
- [ ] 휴지통 파일만 남은 폴더를 지워 보고, 경고 창의 목록이 **내가 볼 수 있는 파일**만인지 · 지운 뒤 그 파일이 휴지통에 그대로 있고 복구하면 맨 위로 오는지
- [ ] 계정 변경 창에서 아무것도 바꾸지 않고 「저장」 — 「바뀐 내용이 없습니다」가 뜨고 감사 로그에 아무 행도 안 생기는지

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-15 | 결정(사용자) | 「역할로 보기」는 A안(세션 안 임시 역할)·구매 모듈 역할과 지점까지 고름·만료는 활동 없는 30분만 | B안(`users.role` 을 바꿨다 되돌리기)은 실제 권한이 바뀌어 되돌릴 사람이 없어질 수 있다 — ARCH 5.20 「어디에 두나」 |
| 2026-09-15 | 지적(사용자) | 「권한 설정을 변경했는데 로그에 없다」 | **변경이 저장되지 않았다.** 계정 변경이 `window.prompt` 6번이었고 하나라도 취소하면 조용히 `return` — 화면에 알림이 없어 「바꿨다」고 느끼기 쉬웠다. 26단계에서 모달 하나 + 취소·무변경·실패 알림으로 (PRD F-40) |
| 2026-09-15 | 지적(사용자) | 관리자 페이지에 들어가면 사이드바의 「관리자 페이지」가 사라진다 | `inGate = me.next !== ''` 가 **재인증**(`reauth`)까지 강제 절차로 보고 내비를 통째로 닫았다. 강제 절차 두 값만 보게 고침 (app.js `initShell`) |
| 2026-09-15 | 지적(사용자) | 빈 폴더(qa-18r-T)가 안 지워진다 | 하위 0·살아 있는 파일 0 이지만 **휴지통 파일**이 남아 있었다. 그 파일은 30일 배치 전에는 없앨 방법이 없어 폴더도 한 달간 잠겼다 → 확인 뒤 루트로 옮기고 삭제 (PRD F-41) |
| 2026-09-15 | 결정(사용자) | 휴지통 파일만 남은 폴더를 지울 때 「어떤 파일이 있다, 진짜 삭제하겠냐」 **목록**을 보여 줄 것 | 볼 수 있는 파일만 이름·삭제일로, 볼 수 없는 파일은 `others:true` 한 줄. R-63 이 숨기는 것은 「안 보이는 파일」이라는 구분을 SECURITY R-63 에 보충 |
| 2026-09-15 | 사고(Opus, 09:52) | `src/Auth.php` 가 새 열을 읽는 상태로 저장됐는데 마이그레이션이 아직 적용되지 않아 **운영 로그인이 전부 500**(PDOException 42703) | Fable 이 마이그레이션을 적용해 복구. 규칙: **DB 열을 읽는 코드를 저장하기 전에 마이그레이션을 먼저 적용**하고, 보안 핵심 파일은 저장 직후 `php -l` + 로그인 요청 하나로 500 여부를 즉시 확인한다 (SECURITY 7장 이력) |
| 2026-09-15 | 판단(Opus) | 「관리」 묶음 링크가 7개인데 지시서 본문은 「하위 6화면」이라 적혀 있다 | 「관리자 페이지」를 포함해 7개다(지시서 수정 파일 항목의 목록도 7개) — 목록 쪽을 따랐다 |
| 2026-09-15 | 판단(Opus) | 폴더 삭제 거부 응답에 `code` 를 함께 넣었다 | 화면이 세 갈래를 가르려면 **문구가 아니라 코드**를 봐야 한다(문구가 바뀌면 화면이 조용히 깨진다). 개수·이름은 여전히 담지 않는다 — R-63 |
| 2026-09-15 | 판단(Opus) | 계정 변경 모달의 사유 칸에서 HTML `required` 를 뺐다 | `required` 가 있으면 브라우저 기본 검사가 먼저 막아 **submit 이벤트 자체가 일어나지 않고**, 「바뀐 칸이 없습니다」를 알릴 기회가 사라진다(실측). 비었는지는 화면 코드가 보고 서버가 400 으로 다시 막는다 |
| 2026-09-15 | 판단(Opus) | 「역할로 보기」 띠에 CSS 를 새로 만들지 않고 기존 `.notice` 클래스를 함께 썼다 | `public/assets/app.css` 가 지시서의 「수정 파일」 밖이라 새 규칙을 만들지 않았다. 띠는 `.act-banner .notice` 로 기존 상자 스타일을 빌린다 |

### F-42 — 오프라인 코드 생성기의 서버 시각 동기화 · 잠금 문구 12자 (F-42 · T-55) — 구현 2026-09-15

> 지시서: `docs/f42-totp-time-sync-directive.md`. 근거: PRD 6장 F-42 · 12.2 T-55 · 8장 API 행, ARCH 5.21, SECURITY R-40 보충.
> 사용자 결정 2026-09-15: 「생성기 화면의 초와 실제 인증 초가 같게. 서버 시각을 하드코딩하지 말고 **인터넷이 있으면 서버와 동기화**」.
> 같은 날 앞선 결정: 생성기 소스·화면에는 **사용 방법만**, 구조 설명은 `docs/totp-generator-internals.md` 에.

**개발 사항** (Opus)
- [x] `src/handlers/time.php` — `GET /api/time` → `{"now": <유닉스 초>, "period": 30}` 만. 로그인·CSRF·감사 없음, `Cache-Control: no-store`, **이 창구만** `Access-Control-Allow-Origin: *`·`Access-Control-Allow-Methods: GET`
- [x] `public/index.php` — 라우트 **한 줄** `['GET', '/api/time', 'time.php', Authz::POLICY_PUBLIC]`. 그 밖 변경 0
- [x] `public/assets/totp-generator.html` — `syncTime()`(왕복 지연 절반 보정 · `localStorage['tbox-totp-offset']` 저장 · 열릴 때 1회 + 5분마다) · `serverNow()` 를 `tick()`·코드 계산에 사용(PC 시각 직접 사용 0) · 상태 줄 3종 · `file://` 용 t-box 주소 입력칸 · `MIN_PASS` 8 → **12** · 소스 주석은 사용·동작 설명만 · 메타 CSP 에 `connect-src 'self'` · `[hidden]{display:none!important}`(숨김이 `.row` 의 flex 에 밀리던 것)
- [x] `deploy/sites/tbox-v2.conf` — 생성기 location CSP 에 `connect-src 'self'` 추가. **`/etc/nginx` 에는 복사하지 않음** — 적용·reload 는 Fable 이 사용자 승인 뒤(발표 리허설 중)
- [x] `tests/totp_time_sync.sh` — T-55 (a)~(e). 로그인 없음·데이터 0. (e)는 실계정의 `mfa_secret` 을 읽지 않고 그 자리에서 만든 무작위 키를 쓴다
- [x] PRD F-42 · T-55 · 8장 API 1행 / ARCH 5.21 / SECURITY R-40 보충 / README 생성기 문단 / `docs/totp-generator-internals.md` 「동기화」 절

**검토 체크** (Opus 자체 실행 — 원문은 보고서)
- [x] `bash tests/totp_time_sync.sh` → **49 ✔ · 0 ✘** (스크린샷 `~/.claude/ui-shots/f42/`)
- [x] (c) 두 경우 모두 기록 — ① 지금 배포된 CSP 그대로면 fetch 가 막혀 「오프라인 · PC 시각 사용」(페이지 오류 0, 콘솔에 CSP 거부 2줄 — 예상된 것) ② CSP 응답 헤더를 벗긴 상태(= nginx 적용 뒤)면 「서버와 동기화됨 · PC 와 -1초」, 남은 초 일치, 자체 점검 9/9, 콘솔·페이지 오류 0
- [x] `scripts/security_lint.sh` 0건 · `php -l`(`src/handlers/time.php`·`public/index.php`) · `tests/help_button.sh` 셸 확인 · `/login` 200(저장 직후에도 즉시 확인 — 09:52 사고 재발 방지)
- [ ] **Fable 검토** 대기 — nginx 적용(`connect-src 'self'`)·reload 는 사용자 승인 뒤. 적용 전까지 서버에서 연 생성기는 「오프라인」 이 정상

**자기 공격 (관문 ③, 사용자)**
- [ ] 생성기를 열어 둔 채 PC 시계를 3분 틀어 본다 — 상태 줄이 「PC 와 +180초」 로 경고색이 되고, **코드는 그대로 통과**하는지(서버 시각 기준으로 계산되므로)
- [ ] 랜선을 뽑고(또는 서버를 못 보게 하고) 새로고침 — 「오프라인 · 저장된 보정값 사용(마지막 동기화 M분 전)」 으로 바뀌고 코드가 계속 맞는지
- [ ] 개발자도구 Network 에서 생성기가 **`/api/time` 말고 다른 곳**을 부르는지, 요청 본문에 비밀 키·코드가 실리는지 본다(0이어야 한다)
- [ ] `localStorage` 를 열어 `tbox-totp-offset` 안에 시각 보정값 말고 다른 것이 있는지 본다
- [ ] 잠금 문구를 11자로 저장해 본다 — 「12자 이상」 으로 막히는지

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-15 | 결정(사용자) | 「생성기 화면의 초와 실제 인증 초가 같게 — 서버 시각을 하드코딩하지 말고 인터넷이 있으면 서버와 동기화」 | `GET /api/time` 한 창구 + 왕복 지연 절반 보정. 문서에 시각을 박아 넣지 않는다 — ARCH 5.21 |
| 2026-09-15 | 판단(Opus) | 생성기 파일 안 `<meta>` CSP 에도 `connect-src 'self'` 를 더했다 | nginx 헤더만 고치면 파일 안 `default-src 'none'` 이 여전히 연결을 막아 기능이 동작하지 않는다(둘 중 좁은 쪽이 이긴다). 지시서의 「수정 파일」 안이라 같은 한 칸만 더했다 |
| 2026-09-15 | 판단(Opus) | `file://` 로 열어 주소를 넣어도 지금은 동기화되지 않는다 | `connect-src 'self'` 는 **파일로 열린 문서에는 맞는 출처가 없다**. 주소 입력칸은 값을 적어 두고 상태를 알려 줄 뿐이며, 파일에서도 실제로 맞추려면 CSP 를 그 주소까지 넓히는 결정이 필요하다(넓히면 「어디로도 보낼 수 없다」는 성질이 약해진다) → Fable·사용자 판단 |
| 2026-09-15 | 판단(Opus) | 화면 문구에서 `HMAC-SHA1`·`PBKDF2-SHA256 60만 회` 같은 말을 뺐다 | T-55 (b) 가 화면 문구의 구조 설명 키워드 0 을 요구한다(피싱 복제 시 「그럴듯함」을 주지 않기 위한 앞선 결정). 코드 식별자(WebCrypto 알고리즘 이름)는 그대로다 |
| 2026-09-15 | 발견(Opus) | 주소 입력칸이 서버에서 연 화면에도 보였다 | `.row { display: flex }` 가 `hidden` 속성의 기본 `display:none` 을 이겼다. `[hidden] { display: none !important; }` 한 줄로 고치고 (c)에 「주소 입력칸 숨김」 확인을 넣었다 |

### F-43 — URL 가져오기: 허용 사이트 관리 · 공개 링크 · 통제된 리다이렉트 (T-56) — 구현 2026-09-15

> 지시서: `docs/f43-fetch-allowlist-directive.md`(OAuth 이음새는 같은 날 철회 결정으로 제외). 근거: PRD F-43 · T-56 · AL-18, ARCH 5.22, SECURITY R-76·R-77.

**개발 사항** (Opus — 사용량 한도로 문서 단계에서 중단, 코드는 완결)
- [x] `db/migrations/2026-09-17_fetch_hosts.sql`(kind 열 없음) · `db/schema.sql` · `db/grants.sql`(tbox_app SELECT·INSERT·UPDATE, DELETE 없음) — 운영 DB 적용됨(`\dp` = arw)
- [x] `src/handlers/fetch_hosts_list.php` · `fetch_hosts_update.php`(재인증·사유·OTP 1회성·한 트랜잭션·감사 before/after) · `admin_fetch_hosts_page.php` · 라우트 3줄(role:Admin)
- [x] `src/CloudLink.php`(드라이브 file/d·open?id·docs 3종 export·OneDrive download=1·1drv.ms·sharepoint needs_login) · `src/Fetch.php`(허용 목록 DB∪.env · 통제된 리다이렉트 ≤3 · needs_login) · `files_fetch.php` · `me.php`(fetch_enabled·fetch_hosts_public) · app.js(버튼 조건·관리 화면·문구) · `admin_page.php` 카드 · `shell_top.php` 링크 · `alerts.php` AL-18 · `Audit.php` 문장 · `help/screens.php` · 린트 규칙 (g)
- [x] PRD F-43·T-56·8장·AL-18 / ARCH 5.22 / SECURITY R-76·R-77·7장(Fable) / README(Fable)
- [x] `tests/fetch_hosts.sh` 작성 — **실행은 R-73 관문(사용자 활동)으로 대기**

**검토 체크** (Fable, 2026-09-15)
- [x] 읽기 전용 실측: manager 403 · admin 재인증 전 403·뒤 200 · 사유/OTP 없음 400 · 와일드카드 400 · 화면 200 · `/api/me` fetch_enabled=false(목록 0)
- [x] CloudLink 단위 12케이스 통과(짧은 ID·evil.tld 미변환 확인) · Fetch 루프 코드 읽기(매 단계 check()·hops·needs_login) · 린트 0 · php -l · 서버 500 없음
- [x] `tests/fetch_hosts.sh` 전체 실행(2026-09-15 13:1x, 사용자 유휴) → **66 ✔ · 0 ✘**, s43- 잔여 0. 앞선 두 실행의 실패는 시험 스크립트 결함(헤드리스 셸 채널 · 감사 단언 고정 창(2·10분)이 연속 실행에서 앞 실행 행을 셈 · UI 단언이 파일 전체 grep) — 스크립트만 고침(코드 변경 0). 세 번째 실행은 qa_admin01 잠금(OTP 재사용 5회 = 실패 5회, R-45 정상)으로 미착수

**자기 공격 (관문 ③, 사용자)**
- [ ] 관리자 페이지 → 「URL 가져오기 허용 사이트」에서 `drive.google.com`·`drive.usercontent.google.com`·`docs.google.com`(구글) / `1drv.ms`·`onedrive.live.com`(OneDrive) 를 넣는다 — 사유·인증 앱 코드 없이 저장이 막히는지, 저장 뒤 감사 로그·경보(AL-18)에 남는지
- [ ] 「링크가 있는 모든 사용자」로 공개한 드라이브 파일 링크를 홈 「URL 로 가져오기」에 붙여 가져온다 · 같은 파일을 비공개로 바꾸고 다시 → 「로그인이 필요합니다」 거부
- [ ] 목록을 전부 비활성화하면 홈의 버튼이 사라지는지 · 「사용 방법」에 목록이 보이는지
- [ ] `http://` 주소·`*.example.com`·`10.0.0.5` 를 넣어 본다 — 전부 거부

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-15 | 사고(운영) | F-43 구현 에이전트가 **사용량 한도(rate limit, 12:40 리셋)** 로 문서 단계에서 중단 | 코드는 완결이라 Fable 이 문서·검토를 이어 마무리. 시험은 관문 대기 |

### (제거됨 2026-09-21 → 27단계) F-45 — 입고 화면에 입력·전표·현황 3탭 (T-57) — 구현 2026-09-15

> 지시서: `docs/purchase/17-f45-receipt-tabs-directive.md`. 근거: 사용자 지적 2026-09-15 「입고에는 왜 입력/전표/현황이 안 나오고 입력만 있나」.
> PRD F-45 · 12.2 T-57 · 8장 API 2행 ; ARCH 5.18(입고 전표 조회 · F-45 라우트 표) ; SECURITY R-65·R-66.
> 시안 ⑥ `GR.dc.html` 은 처음부터 탭 셋이었는데 21단계에서 입고만 두 카드로 줄었다 — 검토자(Fable)의 결정 오류였고, 그래서 **만든 입고 전표를 화면에서 다시 찾을 길이 없었다**.

**개발 사항** (Opus)
- [x] `src/handlers/purchase_gr_list.php` — `GET /api/purchase/gr`(q·page·vendor·branch·from·to·status), `purchaseScopeWhere` 필수, 20건 페이지, 봉투는 발주서 목록과 같은 `{items,total,page,per_page,can_void}`
- [x] `src/handlers/purchase_gr_get.php` — `GET /api/purchase/gr/{num}`, 줄은 `Stock::lines()` + `src_line_no`, `totals.qty`, `can_void`(stock.write × 취소 전 × 마감 아님). **범위 밖 404**
- [x] `public/index.php` — 라우트 2줄(`purchase.read`). 핸들러 파일을 먼저 만들고, 저장 직후 `php -l` + `/login` 200 + 로그인 요청 1회로 500 없음 확인
- [x] `src/views/purchase_receipt.php` — `.tabs`(입력·전표·현황). 입력 탭은 21단계 카드 그대로(id 유지), 전표 탭은 목록+상세(취소 사유 모달 — `window.prompt` 0), 현황 탭은 `#gr-backorder`
- [x] `public/assets/purchase_common.js` — 미입고 렌더러를 `PurchaseUI.renderBackorder(host, opts)` **한 벌**로. `public/assets/purchase_stock.js` — 재고 화면·입고 화면 둘 다 그 함수를 부른다(정의 1 · 호출 2)
- [x] `src/handlers/purchase_receipt_page.php` 제목 「입고 입력」 → 「입고」 · `src/help/screens.php` 「전표」·「현황」 두 절
- [x] `tests/purchase_f45.sh` — T-57 (a)~(f), R-73 관문·`S45-` 접두·qa `created_by` 만 정리. **(e)·(f) 통과, (a)~(d) 는 위 채번 사고로 미검증**
- [x] PRD(F-45·T-57·8장 2행) · ARCHITECTURE 5.18 · STAGES 이 절

**검토 체크** (Fable, 2026-09-15)
- [x] 코드 읽기(gr_list·gr_get 세 SQL 전부 `purchaseScopeWhere`, 범위 밖 404, 라우트 2줄 purchase.read, Authz 변경 0) · 설계 판단 2건(목록 봉투 {items,total,page,per_page,can_void} = 구매 목록 공통 / voided_at = 취소 때 updated_at) 수용
- [x] T-57 (a)~(d) 쓰기 항목 — 사용자 유휴 때 자동 실행(15:0x): 지점 격리(지점B 전표 404 · 거꾸로 404 · 없는 id 404 · `?branch=` 무시 0건) · 취소 200 → status=void · can_void 전이 **전부 ✔**. 전체 82 ✔ · 4 ✘ — 4건은 전부 **디스크 100%** 로 헤드리스 브라우저가 죽은 것(ui_snapshot 「Page crashed」 ×2, help_button 하위 2)과 help_button 의 낡은 기대값(핸들러 20→21). 코드 결함 0
- [ ] 지점 격리 실측: 남의 지점 전표 상세 404 · 목록 0건 · `?branch=` 무시
- [ ] 취소가 **새 창구를 만들지 않았는지**(라우트 표에 `POST /api/purchase/gr/{num}/void` 하나) · 원장 반대 부호 행 회귀
- [x] `scripts/security_lint.sh` 0건 · 바뀐 파일 `php -l` · 화면 1440px 결함 0(입고 3탭·재고 미입고 탭) · 스크린샷 3장 눈 확인

**자기 공격 (관문 ③, 사용자)**
- [ ] 입고 화면에서 「전표」 탭을 열어 **남의 지점 전표 번호**를 주소창에 직접 넣어 본다(`/api/purchase/gr/<남의 지점 id>`) — 404 여야 하고, 403 이면 「있다」가 새는 것이다
- [ ] 취소 사유를 비워 두고 「취소 확정」 — 평소 달에는 통과하고, **마감된 달**에는 `purchase.close` 가 없으면 막히는지
- [ ] 「현황」 탭의 숫자와 재고·미입고 화면의 「미입고」 탭 숫자가 같은지 (같은 함수·같은 API 여야 한다)
- [ ] 입고 전표 목록에서 취소한 전표가 **사라지지 않고** 「취소」로 남는지

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-15 | **사고**(13:22) | 회귀 `tests/purchase_stage21.sh` 정리 절의 `DELETE FROM p_doc_seq WHERE seq_date=CURRENT_DATE` 가 **오늘 채번 카운터**를 지움 → 사용자 전표(PR-…-7·PO-…-1·GR-…-1/2)와 번호 충돌, 오늘 새 발주요청·발주서·입고 생성 전부 409. 사용자 자료 무손상 | SECURITY R-78. stage20~23 네 스크립트의 DELETE → 「남은 전표 최대 번호로 UPDATE」. 규칙: 소유자 열 없는 공유 표(카운터·시퀀스·설정)는 시험이 지우지 않는다 |
| 2026-09-15 | 복구(사용자 승인 14:2x) | 채번 카운터 INSERT 3행(gr 2 · po 1 · pr 7 = 남은 전표 최대 번호) — 삭제·변경 0, 비-qa 전표 4건 그대로. 같은 승인으로 F-42 nginx CSP(`connect-src 'self'`)를 라이브·검토 포트 둘 다 적용(`nginx -t`·reload) → 라이브 생성기 「서버와 동기화됨 · 서버 시각 14:28:15 · PC 와 -1초」, 콘솔 오류 0 | 스크린샷 `~/.claude/ui-shots/f42/live-sync.png`, nginx 백업 `~/.claude/ui-shots/nginx-tbox-v2.bak.*` |
| 2026-09-15 | 궁금·결정(사용자) | 「같은 시계로 보는데 시간 초과된 코드로 로그인이 됐다 — 맞추고 싶다」 → 서버가 앞뒤 1구간(±30초)을 받아 주던 표준 허용 오차 때문. **사용자 결정: 현재 구간만 받는다** | `src/Totp.php` 기본 창 1→0(한 값). 단위·실측 통과(직전 코드 거부·현재 코드 200). 대가(휴대폰 앱 시계 편차 시 실패↑) 수용 — SECURITY 7장 행 |
| 2026-09-15 | 궁금·결정(사용자) | 「생성기가 인터넷으로도 열리네, 오프라인 문서가 아니라」 → 「오프라인」은 계산 방식(키를 안 보냄)이지 접근 경로가 아님. 사이트 전체가 인터넷에 열려 있는 것은 별개(R-61 관리망 제한 꺼짐·방화벽 없음 — 대역 값 필요). 결정: **이름을 「t-box 2단계 코드 생성기」로**, 망 제한은 대역 값 뒤 | F-46 지시서 `docs/f46-directive.md` |
| 2026-09-15 | 결정(사용자) | 「휴대폰 인증 앱은 광고·결제 유도로 못 쓴다 — APK 를 만들면 모를까」 → APK 대신 **생성기를 휴대폰 브라우저에서**(반응형 + 홈 화면 추가). 사용자 「F-46 진행」 | 같은 지시서 (B) |
| 2026-09-15 | 어려움(사용자) → 결함 | SharePoint 「전체 공유」 링크가 「로그인이 필요합니다」로 거부됨 | 실측: 링크는 공개가 맞음 — `download=1` + **첫 302 의 FedAuth 쿠키**를 다음 단계에 실으면 200·xlsx 355KB. 우리 결함 2개: CloudLink 가 sharepoint 를 무조건 needs_login / Fetch 루프가 hop 간 쿠키 미전달(쿠키 없이 403). 같은 지시서 (C): 같은 호스트 다음 단계에만 쿠키, 값은 기록 안 함 |
| 2026-09-15 | 보정 | `tests/help_button.sh` 키 기대값 20→21(F-43 이 fetch-hosts 도움말을 더함) | 시험 스크립트만 |
| 2026-09-15 | **사고(운영)** | 루트 디스크 **100%**(39MB) — playwright 캐시 390MB·claude 캐시 217MB·pip 47MB·스크린샷 80MB 누적. 헤드리스 브라우저 「Page crashed」로 시험의 화면 항목이 실패 | 내 산출물 정리(옛 스크린샷·pip 캐시·journal·apt) → 249MB, 이어 **LVM +512MB**(앞서 승인한 것과 같은 크기) → 아래 df. VG 잔여 10.5G. 스크린샷 폴더는 검토 뒤 지우는 규칙 추가 |
| 2026-09-15 | 지적(사용자) | 「입고에는 왜 입력/전표/현황이 안 나오고 입력만 있나」 | 시안 ⑥ 은 탭 셋인데 21단계에서 두 카드로 줄인 것이 원인. F-45 로 되돌림 — 지시서 `docs/purchase/17-f45-receipt-tabs-directive.md` |
| 2026-09-15 | 결정 | 전표 탭의 취소는 **기존 void 창구**를 부른다(새 API 0) · 현황 탭은 기존 `/api/purchase/backorder` 를 부른다(새 API 0) | 새로 생긴 것은 읽기 창구 둘뿐 — ARCH 5.18 「F-45 라우트·정책」 |
| 2026-09-15 | 어려움(구현) | 검토 계정 `qa_admin01` 의 TOTP 코드가 **다른 세션(F-43 `tests/fetch_hosts.sh`)과 겹쳐** 재사용 거부 → 5회 실패로 10분 잠김 | 코드 재사용을 막는 것은 R-45 가 제대로 동작한 것. 검토 계정을 여러 세션이 함께 쓰면 생기는 일이라, **시험은 겹치지 않게 돌린다**(감사 `auth.mfa deny code_reused` 로 확인) |
| 2026-09-15 | 사고(운영) — **미해결, 사용자 판단 필요** | 오늘(2026-09-15) `PR`·`PO`·`GR` 전표를 **만들 수 없다**(409). 원인은 19~23단계 시험 스크립트의 정리에 있는 `DELETE FROM p_doc_seq WHERE seq_date = CURRENT_DATE` 다 — 그 표는 qa 의 것이 아니라 **그날의 전표 번호 카운터**이고, 지우면 번호가 1부터 다시 발급된다. 사용자가 같은 날 만든 전표(`PR-20260915-7`·`PO-20260915-1`·`GR-20260915-1`·`GR-20260915-2`)가 남아 있어 `p_docs_doc_no_uidx` 에 부딪히고, 실패하면 트랜잭션이 되돌아가 카운터도 오르지 않아 **그날 내내 막힌다** | 사용자 전표 4건은 그대로다(비-qa 행 수 8548 → 8548 변동 0). 복구는 카운터를 오늘 최대 번호로 올리는 일이고 **판단은 사용자 몫**이다(CLAUDE.md 4장 — 스스로 실행하지 않는다). `tests/purchase_f45.sh` 의 정리에서는 그 DELETE 를 **뺐다**; `purchase_stage20|21|22|23.sh` 에는 그대로 남아 있다(이 작업의 수정 범위 밖) |
| 2026-09-15 | 미검증 | 그 사고로 T-57 (a)~(d) 의 **픽스처를 세울 수 없었다** — 대신 사용자의 실 전표를 **읽기만** 해서 목록·상세·걸개·404·`can_void`·미입고 API 를 확인했다 | 쓰기가 필요한 항목(지점 격리 404·취소 → 원장 반대 부호 행)은 **미검증**이며, 카운터가 복구되면 `bash tests/purchase_f45.sh` 한 번으로 끝난다 |

### F-46 — 2단계 코드 생성기 이름·휴대폰 화면 · SharePoint/구글 시트 공개 링크 (T-58) — 구현 2026-09-15

> 지시서: `docs/f46-directive.md` (+ 조정자 추가 지시 C2 — 구글 시트의 회전 도메인).
> 근거: PRD F-46 · 12.2 T-58, ARCH 5.21(휴대폰·매니페스트)·5.22(쿠키·공급자 내려받기 호스트)·7.5(`cookie_hops`), SECURITY R-40·R-77 보충.
> 사용자 결정 2026-09-15: ① 「오프라인」이라는 이름이 오해를 준다 → 「t-box 2단계 코드 생성기」 ② 휴대폰 인증 앱은 광고·결제 유도로 못 쓴다 → APK 가 아니라 **생성기를 휴대폰 브라우저에서** ③ SharePoint 「전체 공유」 링크가 거부되던 것은 **우리 쪽 결함 2개**.

**개발 사항** (Opus)
- [x] `public/assets/totp-generator.html` — title·h1 「t-box 2단계 코드 생성기」 · `@media (max-width: 480px)`(입력·버튼 100%, 버튼 ≥44px, 코드 40px, 가로 스크롤 0) · `<link rel="manifest">`·`theme-color`·`apple-touch-icon`(둘 다 `data:` SVG) · CSP meta 에 `manifest-src 'self'` · 「홈 화면에 추가」 안내 한 줄 · 경고 문구를 기기 중립으로(「이 PC 브라우저」 → 「이 기기의 브라우저」). **상태 줄의 「오프라인 · …」 세 문구는 그대로** (동기화 상태)
- [x] `public/assets/totp-generator.webmanifest` (새 파일) — `standalone`·시작 주소·`theme_color`, **아이콘은 `data:` SVG 하나뿐**(외부 참조 0)
- [x] `deploy/sites/tbox-v2.conf` — 생성기 CSP 에 `manifest-src 'self'` · 매니페스트 전용 location(`types { application/manifest+json webmanifest; }` + 같은 보안 헤더). **`/etc/nginx` 복사·reload 는 하지 않음 — Fable 의 몫**
- [x] `src/CloudLink.php` — `<tenant>.sharepoint.com`·`-my`·`-admin` → `download=1`, provider microsoft, `needs_login` **false**(판단을 「주소 모양」에서 「돌아온 것」으로 옮김). 머리 주석 표 갱신
- [x] `src/Fetch.php` — ① `cookieHeader()`(이름=값만·속성 버림·20개·8KB·초과 시 쿠키 없이·**다음 호스트가 같을 때만**) + `request(?string $cookie)` 의 `CURLOPT_COOKIE` ② `PROVIDER_DOWNLOAD_SUFFIXES` 코드 상수 + `providerHostAllowed()`, `redirectStep(..., ?string $provider)`·`check(..., ?string $downloadProvider)` — **리다이렉트 다음 단계에서만**. `FOLLOWLOCATION` false·단계마다 `check()`·최대 3단계 그대로
- [x] `src/handlers/files_fetch.php` — 감사 `detail.cookie_hops`(수만). **첫 요청의 `Fetch::check($safeInput)` 에는 공급자를 넘기지 않는다**
- [x] `src/Audit.php` — `ALLOWED_DETAIL_KEYS` 에 `cookie_hops` 한 줄(**지시서 「수정 파일」 밖 — 아래 학습 기록 참조**)
- [x] `src/help/screens.php`(2단계 인증 안내 · files 「SharePoint 도 …」 한 구 · fetch-hosts 도움말) · `src/handlers/settings_page.php`(링크 문구) · `README.md`(제목 + 휴대폰 문단)
- [x] `tests/f46.sh`(새 파일) — T-58 (a)~(g)
- [x] PRD F-46·T-58 / ARCH 5.21·5.22·7.5 / SECURITY R-40·R-77 보충 / `docs/totp-generator-internals.md` 「휴대폰 · 홈 화면에 추가」 절

**검토 체크** (Opus 자체 실행 — 원문은 보고서 / Fable 재실행 — 아래)
- [x] `bash tests/f46.sh` → **59 ✔ · 0 ✘** (스크린샷 `~/.claude/ui-shots/f46/c-shots/`)
- [x] (c) 휴대폰 390×844 — `scrollWidth 390 = innerWidth 390`(가로 스크롤 0) · 버튼 최소 높이 **48px** · 코드 글자 **40px** · 자체 점검 **9/9** · 콘솔 오류 0 ; 데스크톱 1440 도 같음(버튼 42px — 요구는 휴대폰에만)
- [x] (f) **실링크 2개 실제 가져오기 성공** — SharePoint 「링크가 있는 모든 사용자」 링크 → 201, `플란부평 24년 12월 판매상품 수불대장 v1.0.xlsx` 355,174B, `hops` 2(같은 호스트) · **`cookie_hops` 1** · provider microsoft · 감사에 쿠키 값 0 ; `?e=` 뗀 것도 201 ; 가짜 id → 400 `needs_login`(원문 첨부) ; 구글 시트 → 201, `2026년 데일리 재고 마감.xlsx`, `hops` = `docs.google.com` → `doc-08-9g-sheets.googleusercontent.com` · **`cookie_hops` 0**(호스트가 달라 쿠키를 버림) · provider google. **가져온 파일 3건은 휴지통으로만**(psql purge 하지 않음, R-73)
- [x] 회귀 — `tests/help_button.sh` **17 ✔ · 0 ✘** · `scripts/security_lint.sh` **0건** · `php -l` 전부 · `/login` 200(Fetch.php·Audit.php 저장 직후 즉시 확인)
- [x] `tests/totp_time_sync.sh` — Opus 48 ✔ · 1 ✘(기대값 노후 → Fable 이 고침). **Fable 재실행 2회: 47 ✔ · 2 ✘** — 실패는 (e) 「스텝 안 1초 지점」으로 F-46 무관한 **새 결함 R-79**(경계 직후 이전 스텝 코드 + 창 0). 학습 기록 참조
- [x] **Fable 검토 (2026-09-15 16:00~17:52, 세션 끊김 뒤 새 세션이 이어받음)** — ① 코드 읽기: `Fetch.php` 쿠키는 `cookieHeader()` 첫 줄에서 호스트 불일치 시 빈 문자열 · 값은 `CURLOPT_COOKIE` 로만 · 감사에는 `cookie_hops` 정수만 · 첫 요청 `Fetch::check($safeInput)` 은 공급자 없이(files_fetch.php 230) · `FOLLOWLOCATION` false 유지 · `Audit.php` 한 줄은 정수 키라 승인. ② **Fable 단독 단위 15케이스**(`php -r`): 다른 호스트 쿠키 0 · CRLF 주입 줄 폐기 · `;`·`,`·공백 값 폐기 · 21→20 · 8KB 초과 → 빈 문자열 · provider null → 회전 도메인 거부 · `evil.googleusercontent.com.attacker.tld` 거부 · 접미 자체 거부 · 교차 공급자 거부 · `redirectStep` provider 없이 거부/있으면 통과/http 거부 — **15/15**. ③ `bash tests/f46.sh` **Fable 재실행 59 ✔ · 0 ✘**(17:51, 사용자 유휴 확인 뒤) — SharePoint 201(`cookie_hops` 1 · hops 2 · 감사 detail 에 쿠키 값 0) · `?e=` 없는 링크 201 · 가짜 id 400 `needs_login` · 구글 시트 201(hops `docs.google.com`→`doc-08-9g-sheets.googleusercontent.com` · `cookie_hops` 0) · 저장 3건 휴지통. ④ 회귀: `security_lint` 0 · `php -l` 0 · `tests/fetch_hosts.sh` **63 ✔ · 3 ✘**(3건은 「허용 목록 비어 있음」 가정, F-46 무관 — 학습 기록) · `help_button` 17/17(Opus). ⑤ nginx **사용자 승인 뒤 16:15 적용**(라이브·8082) — 외부 실측 매니페스트 `application/manifest+json`·생성기 CSP `manifest-src 'self'`. ⑥ 스크린샷 390px 눈 확인(제목·홈 화면 안내·전체 폭 입력). **미검증**: iOS 실기기 「홈 화면에 추가」(관문 ③). **커밋 fbc20c3**(코드·시험·문서) + 보충 커밋(이 검토 기록·SECURITY 7장 — 편집이 먼저 실패한 채 커밋이 나가 한 번 더)

**자기 공격 (관문 ③, 사용자)**
- [ ] 휴대폰 브라우저로 `/assets/totp-generator.html` 을 열고 「홈 화면에 추가」 — 아이콘·이름이 나오고, 눌렀을 때 주소창 없이 열리는지. 가로로 밀리는 곳이 없는지
- [ ] 그 휴대폰에서 개발자도구(또는 PC 에서 같은 화면) Network 로 **생성기가 `/api/time` 말고 어디를 부르는지** 본다 — 0 이어야 한다. 매니페스트·아이콘도 밖에서 받아오지 않는다(`data:`)
- [ ] 관리자 화면에서 `planlabs.sharepoint.com` 을 **「허용」 끄고** 같은 링크를 가져와 본다 — `host_not_allowed` 로 막히는지(코드 상수는 **첫 요청**을 열어 주지 않는다)
- [ ] 아무 사이트의 공개 xlsx 주소를 허용 목록에 넣지 않고 넣어 본다 — 거부되는지
- [ ] 로그인이 **정말 필요한** SharePoint 문서(공유 안 한 것) 링크를 가져와 본다 — 「로그인이 필요합니다」로 끝나고 파일이 저장되지 않는지
- [ ] 감사 로그에서 방금 가져오기 행을 열어 `cookie_hops` 가 **숫자 하나**이고 쿠키 값·이름이 어디에도 없는지 확인

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-15 | 결정(사용자) | 「생성기가 인터넷으로도 열리네, 오프라인 문서가 아니라」 → 이름을 「t-box 2단계 코드 생성기」로 | 「오프라인」은 **계산 방식**(키를 안 보냄)이지 접근 경로가 아니다. 상태 줄의 「오프라인 · …」은 동기화 상태라 유지 — ARCH 5.21 「이름 (F-46)」 |
| 2026-09-15 | 결정(사용자) | 「휴대폰 인증 앱은 광고·결제 유도로 못 쓴다 — APK 말고 생성기를 휴대폰에서」 | 브라우저의 「홈 화면에 추가」(웹 매니페스트). 설치되는 앱이 아니라 바로가기라 스토어·권한·자동 갱신이 없다 — ARCH 5.21 「휴대폰에서 쓰기」, R-40 보충 |
| 2026-09-15 | 발견(사용자 실측) | SharePoint 「링크가 있는 모든 사용자」 링크가 「로그인이 필요합니다」로 거부됨 | **우리 쪽 결함 2개**였다 — ① `CloudLink` 가 sharepoint 를 주소만 보고 `needs_login` 으로 분류 ② 리다이렉트 루프가 단계 사이에 쿠키를 넘기지 않아 2단계에서 403. 둘 다 고쳐 실제 355KB 엑셀을 가져왔다 |
| 2026-09-15 | 발견(사용자 실측) | 구글 시트 공개 링크는 변환은 되는데 2단계에서 `host_not_allowed`(감사 15:00 admin01) | `export` 가 매번 바뀌는 `doc-xx-yy-sheets.googleusercontent.com` 으로 307 한다. 정확 일치 목록으로는 담을 수 없어 **코드 상수** `PROVIDER_DOWNLOAD_SUFFIXES` 를 두고 **리다이렉트 다음 단계에서만** 연다(첫 요청은 그대로 정확 일치) — ARCH 5.22 「F-46 ②」 |
| 2026-09-15 | 판단(Opus) | 감사 detail 의 `cookie_hops` 가 `[제거됨]` 으로 남았다 | `Audit::FORBIDDEN_DETAIL_KEYS` 의 `cookie` **부분 일치**에 걸린 것이다(실측). 값은 쿠키가 아니라 **단계 수(정수)** 이므로 `sessions_revoked`·`chain_hash` 와 같은 자리(`ALLOWED_DETAIL_KEYS` 정확 일치)에 한 줄 더하고 ARCH 7.5 등록부에 적었다. **`src/Audit.php` 는 지시서의 「수정 파일」 밖이자 보안 핵심 파일**이라 Fable 검토가 필요하다 |
| 2026-09-15 | 판단(Opus) | 도움말(`screens.php`)에는 「2단계 코드 생성기」라고 쓰지 못했다 | `tests/help_button.sh` 의 금지 문구 규칙 `[0-9]{1,2}단계(?! 인증)` 에 걸린다(로드맵 「N단계」를 사용자 문구에서 막는 규칙). 2단계 인증 절 안이라 문맥이 이미 분명하므로 「아래의 코드 생성기」로 적었다. 링크 문구(`settings_page.php`)와 README·화면 제목은 지시서대로 「2단계 코드 생성기」 |
| 2026-09-15 | 판단(Opus) | 「저장소 전체에서 「오프라인 코드 생성기」 0」 을 **화면 문구·README 범위**로 좁혀 시험했다 | 남은 자리가 전부 이번 「수정 파일」 밖이다 — 과거 기록(STAGES 학습 기록·지난 지시서·`wiki/*.json`·`docs/ui-copy-cleanup.md`)과 보안 핵심 파일의 주석(`public/index.php`). 기록을 고쳐 쓰지 않는 것이 CLAUDE.md 9장 규칙이라 그대로 두고, 시험이 **남은 자리를 파일별로 출력**한다 |
| 2026-09-15 | 판단(Opus) | 매니페스트 location 에 `add_header Content-Type` 대신 `types { … }` 를 썼다 | `add_header` 는 Content-Type 헤더를 **두 줄**로 만든다. nginx 1.18 의 `mime.types` 에 `webmanifest` 가 없어 이 한 줄이 형식을 정한다 |
| 2026-09-15 | 해소(Fable) | `/etc/nginx` 배포본 미적용 — 매니페스트가 `application/octet-stream` 으로 나가고, 배포본 CSP 에 `manifest-src` 가 없었다 | **사용자 승인 뒤 16:15 적용**(`tbox-v2`·`tbox-v2-local` 두 conf 복사 · `nginx -t` ok · reload). 실측: 외부 `https://changkwon.mso.so/assets/totp-generator.webmanifest` → 200 `application/manifest+json`, 생성기 CSP 에 `manifest-src 'self'`, 8082 도 같음. `/login` 200 |
| 2026-09-15 | 해소(Fable) | `tests/totp_time_sync.sh` (c)① 1건 실패 — 기대값 노후 | Fable 이 기대값을 「서버와 동기화됨」으로 고침(F-42 nginx 적용 2026-09-15 이후의 정상). `tests/fetch_hosts.sh` 의 F-46 이전 기대값 2건(SharePoint 무조건 needs_login · `check()` 인자)도 같이 고침 — 시험 파일은 보안 핵심 파일이 아니라 Fable 직접(CLAUDE.md 5장) |
| 2026-09-15 | 궁금(사용자) | 「`googleusercontent.com` 이걸로 도메인 등록하면 되겠네?」 | 안 된다. ① 허용 목록은 **정확 일치**라 `doc-08-9g-sheets.googleusercontent.com` 과 맞지 않고 와일드카드는 화면에서 금지 ② `googleusercontent.com` 은 블로거·포토 등 **누구나 올리는 사용자 콘텐츠 도메인**이라 통째로 열면 「허용된 사이트」의 뜻이 사라진다. 그래서 회전 도메인은 **코드 상수**(`Fetch::PROVIDER_DOWNLOAD_SUFFIXES`)로 「리다이렉트 다음 단계에서만」 — ARCH 5.22. 관리자 목록에는 `docs.google.com`(+ 드라이브 링크면 `drive.google.com`)만 있으면 된다 |
| 2026-09-15 | 궁금(사용자) | 「URL 로 파일 공유할 때 미리보기로 보여 주고 다운로드는 0, 열람만 되게 할 수 있나? HTML 동적 보고서 같은 것. 단순 보고용」 | 가능하되 지금 구조와 둘이 부딪힌다 — 공유 링크(`/s/토큰`)는 **항상 파일 저장**이고, **HTML 업로드는 금지 확장자**. 제안 **F-47 「열람 전용 공유 링크」**: 링크 생성 때 「내려받기 허용/열람만」 선택, PDF·이미지·텍스트 먼저(오피스 문서는 변환 도구가 필요해 2단계), HTML 은 **격리 도메인 + CSP 스크립트 차단**으로만. 「다운로드 0」의 한계: 화면은 캡처·인쇄로 복제된다 — 「실수로 파일이 퍼지는 것」을 막는 장치이지 유출 방지가 아니다. 결정 대기 |
| 2026-09-15 | 궁금(사용자) | 「HTML 업로드가 안 되는 게, 해커가 HTML 을 올리면 그 파일 기반으로 코드가 실행돼 서버에 접근할 통로가 열릴 수 있어서?」 | 절반만 맞다. 서버는 HTML 을 **실행하지 않는다**(저장했다 내줄 뿐). 위험은 **열어 본 사람의 브라우저**다 — 같은 주소(출처)에서 열리면 그 안의 스크립트가 그 사용자의 t-box 세션으로 요청을 보낼 수 있고, 관리자가 열면 관리자 권한 도용 = 결과는 서버 장악과 같다. 격리 도메인(`view.…`)에서 보여 주면 브라우저 규칙상 t-box 쿠키에 닿지 못하고, 거기에 CSP 로 스크립트 자체를 막는다 → HTML 은 「정적 보고서」까지만. 동적 차트가 필요하면 PDF 로 내보내 올리는 편이 안전 — SECURITY R-xx(F-47 때 부여) |
| 2026-09-15 | 결정(사용자) | 「view 로 해 줘, DNS 추가할게 — 근데 DNS 는 어떻게 추가해?」 | 조사 결과 **DNS 는 할 일이 없다**: `mso.so` 는 Cloudflare 관리이고 `*.mso.so`·`*.changkwon.mso.so` 와일드카드가 이미 있어 `view.changkwon.mso.so` 가 해석된다(`dig`). 막히는 곳은 **앞단 프록시**(112.220.89.250 — 이 서버는 사설망 192.168.105.44, nginx 는 80 만 듣고 TLS 는 프록시가 종단, 인증서 `*.mso.so` 한 단계). 프록시가 `changkwon.mso.so` 만 넘기고 다른 이름은 404. 권고: 두 단계 이름 대신 **`changkwon-view.mso.so`**(지금 인증서로 바로 됨) + 프록시에 「그 이름 → 192.168.105.44:80」 한 줄. **프록시가 회사 IT 것인지 직접 관리인지 답 대기** — 이쪽 nginx 격리 설정은 F-47 에서 준비 |
| 2026-09-15 | 기초(사용자) | 「인터넷이 끊겨서 세션이 끊겼다 — 직전 내용 요약하고 작업 중이던 것 확인해 줘」 | 터미널 연결만 끊기고 claude 프로세스(PID 882235)와 Opus 서브에이전트는 서버에서 계속 돌았다(`ps`·transcript mtime 으로 확인). 서브에이전트 보고를 받은 직후 API 요청이 끊겨 유휴. 새 세션이 `~/.claude/projects/-var/<세션>.jsonl` 과 `subagents/` 의 기록을 읽어 이어받았다. 유령 프로세스는 `kill` 로 안 죽고 **`kill -9`** 필요. 이어받을 때 살아 있는 프로세스와 `--resume` 을 동시에 두지 않는다(같은 파일을 두 세션이 건드림) |
| 2026-09-15 | **사건(운영)** | 16:45 루트 디스크 다시 100% → **PostgreSQL PANIC 으로 다운, 사이트 503**(사용자: 「503 에러로 이용할 수 없다고 나와 서버가 죽었어 왜이래」) | 서버 프로세스가 죽은 게 아니라 DB 가 디스크 0 으로 PANIC(체크포인트 파일 못 씀) 뒤 자동 재시작이 안 된 것. 사용자가 볼륨 +512MB(17:09) → Fable 이 `sudo pg_ctlcluster 14 main start` → WAL 자동 복구 → 로그인 200·health 200. 자료 무손상. **범인은 VS Code 확장 자동 갱신**(Claude Code 2.1.271·272, 버전당 328MB) — SECURITY **R-80**. 사용자 결정: 「디스크가 얼마고 얼마나 늘릴 수 있어?」 → 볼륨 그룹 여유 10GB 확인 → **`lvextend -l +100%FREE` 로 전부 붙임(18:0x)** → 루트 23GB·여유 9.9GB. 옛 확장·VSIX 캐시 삭제는 남음 |
| 2026-09-15 | 발견(Fable 검토) | `tests/totp_time_sync.sh` (e) 「스텝 안 1초 지점」이 3회 연속 실패 — 화면 코드 ≠ 서버 코드, `verifyStep(창 0)` null | 우연이 아니라 결함: `/api/time` 이 정수 초라 보정값이 0~1초 뒤처지고 화면 갱신이 경계와 정렬돼 있지 않아 **경계 직후 1~2초는 이전 스텝 코드**가 보인다. 오늘 창을 0 으로 좁혀 그 코드가 401 이 된다. F-46 과 무관, F-42+창 0 의 조합 — SECURITY **R-79**. 고치려면 Opus 지시서(F-48: `/api/time` 밀리초 + 경계 정렬 갱신). 15초·28초 지점은 3회 모두 일치 |
| 2026-09-15 | 알림 | `tests/fetch_hosts.sh` 3건 실패는 시험이 「허용 목록이 비어 있다」고 가정한 것 — 관리자가 실제 호스트 3개를 넣어 둔 지금은 맞지 않는다 | F-46 무관. 시험을 「비어 있으면 ~, 아니면 건너뜀」으로 바꾸거나 시험 전용 표 상태를 만들지는 F-48 때 함께 판단. F-46 이 바꾼 동작의 기대값 2건은 이번에 고쳐 통과 |

### F-47 — 버전 관리 `X.Y.Z` · 「변경 이력」 모달 · 발행 스크립트 (T-59) — 착수 2026-09-15

> 지시서: `docs/f47-versioning-directive.md`. 근거: PRD F-47 · 12.2 T-59, ARCH 5.23, CLAUDE.md 6장 「버전과 발행」.
> 사용자 결정 2026-09-15: 「V 1.1.1 같은 형태로 — 앞자리 대규모(뽐낼 만하다), 중간 중규모(공치사 정도), 버그패치 등 짜잘한 건 마지막 자리」 · 「changelog 도 몇 날 몇 시 뭘 올렸고 무엇이 변했는지 설명하게」 · 「모달로 볼 수 있게」 · 「깃에도 버전 관리 되는 거지?」 → 태그 `vX.Y.Z` + 원격 push.
> Fable 판단(사용자 미결): 초기 번호 **1.0.0** = 2026-09-15 시점 전체. 지난 26단계에 번호를 거꾸로 붙이지 않는다(그 이력은 git log·이 문서가 갖고 있다). 2.0.0 이 낫다면 태그를 만들기 전에 `VERSION`·`CHANGELOG.json` 첫 항목 한 줄씩만 고치면 된다.

**개발 사항** (Opus)
- [x] `VERSION`(1.0.0) · `CHANGELOG.json`(첫 항목, Fable 작성) · `CHANGELOG.md`(생성)
- [x] `src/Changelog.php` — 두 파일 읽기·형식 검증·등급 표기(대규모/중규모/수정)
- [x] `shell_top.php` 사이드바 아래 `v1.0.0` 단추 · `shell_bottom.php` `<dialog id="changelog-dialog">`(「사용 방법」 뼈대) · `app.js` 열기/닫기 · `app.css`
- [x] `scripts/changelog_md.php`(JSON → 마크다운, 결정적) · `scripts/release.sh`(번호 계산·항목 추가·릴리스 커밋·태그·push, 더러운 트리 거부, `--dry-run`·`--bump`)
- [x] `tests/changelog.sh`(T-59 (a)~(e))

**검토 체크** (Fable, 관문 ②)
- [x] **Fable 검토 (2026-09-15 18:34)** — 코드 읽기: `Changelog.php` 경로는 상수 2개·다섯 칸만 통과·형식 틀리면 버림 / `shell_bottom.php` 모든 값 `htmlspecialchars` / `app.js` 위임 하나·innerHTML 0 / `release.sh` JSON 은 php 인자로만·`git add` 세 파일 지정·더러운 트리 거부 / `index.php` 변경 0. `bash tests/changelog.sh` **재실행 65/65**(로그인 전 `/login`·`/api/health`·`/api/time` 에 버전 0 실측 포함, Playwright 클릭→open→ESC 닫힘, 콘솔 오류 0) · 린트 0 · `help_button.sh` 17/17 · 스크린샷 눈 확인(모달 등급·시각·6줄). CHANGELOG.json 은 Fable 이 4칸 들여쓰기로 맞춤(다음 발행 diff 가 항목만 보이게). **미검증**: `release.sh` 의 실제 commit·tag·push 경로(첫 발행은 1.0.0 그대로라 스크립트를 쓰지 않고 태그를 직접 만든다 — 다음 발행 때 실측). 좁은 화면(≤959px)에서는 버전 단추가 사이드바 여백과 함께 숨는다(Opus 판단, 수용)
- [x] 첫 발행: F-47 커밋에 태그 `v1.0.0` 직접 부착 · push (아래 학습 기록에 원격 확인)
- [x] **미검증 해소 (2026-09-15 19:07, F-48 발행)** — `scripts/release.sh patch …` 실제 경로 첫 실행: `VERSION` 1.0.1 · `CHANGELOG.json` 첫 항목 1.0.1/patch/`2026-09-15 19:07` · `CHANGELOG.md` 생성 결과와 byte 동일 · 릴리스 커밋 `a1c0d4a` · 태그 `v1.0.1` 원격 push 확인(`git ls-remote --tags`)

**자기 공격 체크** (사용자, 관문 ③)
- [ ] 로그인 전 `curl -s http://127.0.0.1:8082/login | grep -c v1.0` → 0 · `/api/health`·`/api/time` 에 버전 0
- [ ] 로그인 뒤 왼쪽 아래 `v1.0.0` → 「변경 이력」 창 · 날짜·시각·무엇이 변했나 읽힘 · ESC 닫힘
- [ ] `git tag -l` 에 `v1.0.0` · `git show v1.0.0 --stat`

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-15 | 결정(사용자) | 「버전 관리가 되어야 좋겠어, V 1.1.1 — 앞자리 대규모(뽐낼 만하다) / 중간 중규모(공치사) / 버그패치 등 짜잘한 건 마지막」 | PRD F-47 ① · ARCH 5.23 「번호 규칙」 · CLAUDE.md 6장(등급 기본 patch, 기능은 minor, major 는 사용자가 말할 때만) |
| 2026-09-15 | 요청(사용자) | 「changelog 도 몇 날 몇 시 뭘 올렸고, 무엇이 변했는지를 설명하게」 | 항목마다 `released_at`(서버 KST, 분까지) + 사용자 말로 쓴 변경 문장 — ARCH 5.23 항목 형식 |
| 2026-09-15 | 요청(사용자) | 「모달로 볼 수 있게」 | 사이드바 버전 단추 → `<dialog id="changelog-dialog">`, 「사용 방법」 모달과 같은 뼈대(ARCH 5.19·5.23) — 새 API 없음 |
| 2026-09-15 | 궁금(사용자) | 「깃에도 버전 관리 되는 거지?」 | 된다 — 발행마다 릴리스 커밋 + 주석 태그 `vX.Y.Z`, `git push origin vX.Y.Z` 로 원격에도. 확인: `git tag -l`, `git ls-remote --tags origin`. ARCH 5.23 「깃」 |
| 2026-09-15 | 해소(Fable) | 첫 발행 — F-47 커밋 `5c59c4f` 에 태그 `v1.0.0` 부착, `git push origin main`·`git push origin v1.0.0` 완료. `git ls-remote --tags origin` 에 `refs/tags/v1.0.0` 확인, `git describe --tags` = `v1.0.0` | 다음부터는 `scripts/release.sh` 한 번(CLAUDE.md 6장). 1.0.0 은 이미 파일에 있어 스크립트를 쓰지 않았다 |

### F-48 — 상태 확인 API 의 DB 확인 · 외부 프로그램용 API 문서 `API.md` (T-60) — 착수 2026-09-15

> 지시서: `docs/f48-health-directive.md`. 근거: PRD F-48 · 8장 `/api/health` 행 · 12.2 T-60, ARCH 5.24, SECURITY R-81.
> 사용자 요청 2026-09-15: 「로컬에서 API 를 통해 파일을 업로드하는 파일을 만들고 있어 — 업로드 가능한 API 문서를 만들어 줘. `GET /api/health` 는 DB 에 SELECT 1, 성공 200 `{status:ok, db:ok, time}`, 실패 503 `{status:fail, db:에러 요약}`, 로그인 없이, 민감 정보 금지. nginx 에서 `/api/` 가 PHP 로 가는지 확인. 웹루트에 `API.md`. curl 한 줄, "health API 추가" 한글 커밋」.
> Fable 판단 ① 「에러 요약」은 PDO 문구 원문이 아니라 **고정 분류어**(`connect_failed`·`query_failed`)다 — 원문에 호스트·포트·계정이 들어간다(S-09-7). ② 「웹루트」는 nginx 루트 `public/` 이 아니라 **저장소 루트**로 해석했다 — `public/` 안의 파일은 로그인 없이 내려받힌다. ③ nginx 는 이미 `location / { try_files $uri /index.php… }` 로 `/api/` 를 PHP 에 넘기고 있어(라이브 conf = 저장소 conf, diff 0) 설정 변경·reload 가 없다. ④ 업로드 문서는 코드 추정이 아니라 `qa_employee01` 로 로그인→내 정보→업로드 201→휴지통 200→로그아웃 200 을 실측해 적었다.

**개발 사항** (Opus)
- [x] `src/handlers/health.php` — `Db::ready()` → `SELECT 1` → 200 `{status,db,time}` / 503 `{status:fail, db:connect_failed|query_failed}`, 원문은 `error_log` 에만
- [x] `src/Db.php` — `boot()` 의 `PDOException` 삼키기(로그 + 표식) · `instance()` 표식 시 `RuntimeException` · `ready()`
- [x] `src/bootstrap.php` — 예외 처리기 rollback 을 `Db::ready()` 로 가드(한 곳)
- [x] `tests/health.sh` — T-60 (a) 라이브 8082 정상 · (b) 임시 복사본 + 가짜 `.env` + `php -S` 로 503 실측 · (c) 린트·`php -l` · **첫 줄 디스크 여유 1GB 점검(R-80)**
- [x] (Fable) `API.md` 저장소 루트 · README health 예시 줄 · PRD F-48·8장·T-60 · ARCH 5.24 · SECURITY R-81

**검토 체크** (Fable, 관문 ②)
- [x] **Fable 검토 (2026-09-15 19:06)** — 코드 읽기: `health.php` 는 요청 값 0·SQL 고정 문자열 `SELECT 1`·응답은 고정 분류어와 `date(DATE_ATOM)` 뿐, 원문은 `error_log` 만 / `Db.php` diff 는 `$bootFailed`·try/catch·`ready()`·`instance()` 분기 4곳(run·트랜잭션·perm 불변) / `bootstrap.php` 는 rollback 가드 한 줄. `bash tests/health.sh` **Fable 재실행 38/38**(라이브 200 `{status,db,time}`·키 정확·time ±0초·CORS 0·버전 0·POST 405·감사 행 0 / 가짜 `.env` 복사본 503 `connect_failed`·본문+헤더에 fakeuser·fakepass·SQLSTATE·PDOException 0·`/api/me` 는 500 그대로 / 린트 0·`php -l` 3파일) · 공개 도메인 `https://changkwon.mso.so/api/health` 200 `{"status":"ok","db":"ok","time":"2026-09-15T19:06:31+09:00"}` · POST 405 · `/API.md` 404 · 시험 뒤 `php -S` 잔여 프로세스 0. **미검증**: `query_failed` 분기(연결은 되고 질의만 실패하는 DB 를 운영을 건드리지 않고 만들 수 없음 — 코드상 `ready()` 참 뒤 모든 Throwable 이 그 분기). 시험 스크립트가 실제 `.env` 를 읽지 않음은 `grep` 으로 확인(가짜 키만 heredoc)

**자기 공격 체크** (사용자, 관문 ③)
- [ ] `curl -s -w ' [%{http_code}]\n' https://changkwon.mso.so/api/health` → 200 `{"status":"ok","db":"ok","time":"…"}` · 본문에 버전·호스트·계정 없음
- [ ] `curl -s -X POST https://changkwon.mso.so/api/health` → 405
- [ ] `curl -s https://changkwon.mso.so/API.md` → 404 (문서는 웹으로 열리지 않는다)
- [ ] (선택) `API.md` 2~6장 순서대로 자기 계정으로 따라가 업로드 201 을 받아 보고, 올린 파일을 휴지통으로

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-15 | 요청(사용자) | 「로컬에서 API 로 파일을 업로드하는 프로그램을 만들고 있다 — 업로드 가능한 API 문서를 만들어 줘」 | `API.md` 0장(공통: 쿠키 `tbox_session`·`X-CSRF-Token`) → 2 로그인(otp 필수, 401 `mfa_required` 뒤 재시도) → 3 `/api/me`(`csrf_token`·`upload_levels`) → 4 `POST /api/files` multipart(`file`·`access_level`·`team_id`·`description`·`folder_id`) → 5 삭제 → 6 로그아웃. 예시는 실측값 |
| 2026-09-15 | 요청(사용자) | 「`/api/health`: DB 에 SELECT 1, 성공 200 / 실패 503 + 에러 요약, 로그인 없이, 민감 정보 금지」 | PRD F-48 · ARCH 5.24. 「에러 요약」은 고정 분류어로 — PDO 오류 문구에 호스트·포트·계정이 들어가므로 원문은 서버 로그(`request_id`)에만(S-09-7) |
| 2026-09-15 | 요청(사용자) | 「nginx 에서 `/api/` 가 PHP 로 가는지 확인, 안 가면 고치고 reload」 | 이미 간다 — `deploy/sites/tbox-v2.conf` `location / { try_files $uri /index.php$is_args$args; }`, 라이브 `/etc/nginx/sites-available/tbox-v2` 와 diff 0, `curl 127.0.0.1:8082/api/health` 200. 변경·reload 없음 |
| 2026-09-15 | 결정(Fable) | 「웹루트에 API.md」 → 저장소 루트 `/var/www/tbox-v2/API.md` | nginx 루트는 `public/` 이라 그 안의 `.md` 는 로그인 없이 내려받힌다(정찰 재료). README 와 같은 자리에 두고 웹에서는 404 (ARCH 5.24) |
| 2026-09-15 | 해소(Fable) | 왜 지금 health 는 DB 가 죽어도 503 을 낼 수 없었나 | bootstrap 5) 의 `Db::boot` 가 `new PDO` 실패로 예외 → 예외 처리기 500 `서버 오류` — 핸들러까지 오지 못한다. F-48 은 `boot` 가 실패를 기억만 하고, 다른 창구는 `Db::instance()` 에서 전과 같이 500 (ARCH 5.24 「부팅 순서의 변화」) |
| 2026-09-15 | 해소(Fable) | 관문 ②→④: 커밋 `8457c0d` 「health API 추가」 → push → `release.sh patch` → `v1.0.1`(`a1c0d4a`). 관문 ③(위 자기 공격 체크)은 사용자 대기 | 확인: `curl -s -w ' [%{http_code}]\n' https://changkwon.mso.so/api/health` · `git tag -l v1.0.1` |
| 2026-09-15 | 어려움(사용자) | 「`curl -X POST …/api/upload -H "X-API-Token: …"` 이 `{"error":"찾을 수 없습니다"}` — 왜 실패하지?」 | 그런 창구·인증 방식이 없다. 업로드는 **`POST /api/files`**(API.md 4장)이고 인증은 API 토큰이 아니라 **세션 쿠키 + `X-CSRF-Token`**(API.md 1장·2장·3장) — `/api/login`(JSON, otp 포함) → `-c jar` 로 쿠키 저장 → `GET /api/me` 의 `csrf_token` → `-b jar -H "X-CSRF-Token: …" -F file=@… -F access_level=…`. `.env` 에 `API_TOKEN` 도 없다. 셸 문법(`$(changkwon)`)은 3장 기초 기록 |
| 2026-09-15 | 요청(사용자) | 「서버에서 OTP 를 요구하지 않도록 변경하고 (업로드) 명령어 다시 알려 줘」 | **문서 충돌 → 사용자 결정 대기**(CLAUDE.md 0장): PRD F-16 ③ 「2단계 인증은 모든 역할에 필수」· S-09-4(비밀번호 10자로 낮춘 근거가 MFA 필수) · SECURITY R-05(Admin 탈취, TOTP 로 ◐). 제시한 길: (A) 전 계정 MFA 선택제로 되돌림 (B) 프로그램 전용 API 토큰 계정 (C) Admin 이 특정 계정만 MFA 면제. Fable 권고 (B). 결정 뒤 PRD·ARCH·SECURITY 먼저 고치고 Opus 지시서(`src/Auth.php` 는 보안 핵심 파일) |

### F-49 — 프로그램용 업로드 API `POST /api/upload` (정적 API 토큰) — 착수 2026-09-15

> 지시서: `docs/f49-api-token-directive.md`(v2, 설계 A). 근거: PRD F-49 · 8장 `/api/upload` 행 · 12.2 T-61 · S-09-11 · F-16 ⑤(예외), ARCH 4.2, SECURITY R-82.
> 배경: 강사 2차 프롬프트(`POST /api/upload` + `.env API_TOKEN` + `X-API-Token`, 이유 문구, 웹 로직 재사용, 한글 커밋). 처음엔 사용자별 DB 토큰(설계 B)으로 착수했으나 **사용자 결정 A** 로 갈아탐 — Opus 1차 중단·코드 원복(19:58).

**개발 사항** (구현 Opus / 문서 Fable)
- [x] (Fable) PRD F-49·S-09-11·8장 행·T-61, ARCH 4.2, SECURITY R-82 — 설계 A 로 재작성
- [x] (Fable) 지시서 v2 · Opus 1차(설계 B) 중단 및 `git checkout` 원복(코드 8 파일·새 파일 5)
- [x] (Opus) `src/handlers/_upload_common.php` 공통 함수 + `files_upload.php` 를 그 호출로 축소(웹 동작 불변)
- [x] (Opus) `src/handlers/api_upload.php` · `public/index.php` 라우트 1줄 + `[2]` 토큰 분기(이 라우트만)
- [x] (Opus) `src/Http.php` `apiTokenHeader()` · `src/Auth.php` 귀속 계정 로더 · `src/Audit.php` 템플릿·`auth` 키·자동 첨부
- [x] (Opus) `deploy/sites/tbox-v2.conf`·`tbox-v2-local.conf`(보정 지시) JSON 413 · `.env.example` 두 키 · `scripts/api_token_rotate.sh`(값 비출력)
- [x] (Opus) `API.md` 7장(실측 응답은 Fable 이 채움) · `tests/api_upload.sh`(T-61 a~f)
- [x] (Fable, 배포) `scripts/api_token_rotate.sh qa_employee01`(검토) → 실측 → `… admin01`(운영 귀속, 검토 토큰 무효). 값은 `cat .env` 로만
- [x] (사용자 실행 20:39) nginx 반영 `sudo bash deploy/switch-site.sh status` — nginx -t 통과·reload·공개 tbox-v2 유지. (Fable 실행은 자동 승인 분류기 차단) → Fable 실측: 102MB → **JSON 413** 8082·공개 둘 다, 보안 헤더·health 불변, `check_baseline.sh` OK 16·주의 5·미적용 6

**검토 체크** (Fable, 관문 ②)
- [x] **Fable 검토 (2026-09-15 20:20~20:50)** — 코드 읽기 + `tests/api_upload.sh` 재실행 25/0/미검증1 + 긍정 경로를 **검토 계정 귀속으로 실측**(201 `level:"public"` · Employee 의 admin-only 403 이유 · team_id 없음 400 · 401 missing_header/bad_token · 다른 라우트 401 「로그인이 필요합니다」·`auth.token` 행 0 · 감사 `actor=qa_employee01`+`auth=api_token` · 토큰 모양 문자열 유출 0). **결함 1**: `.exe` → 「잘못된 요청입니다」(매핑에 `ext_not_allowed` 없음) → 재지시 1회 → 상수 기반 문구, 20:20 대조, 5종 이유 문구 실측(확장자·이중확장자·level·빈 파일·설명 초과). 린트 0 · `php -l` 7/7 · health 38/38 · 시험 파일 휴지통 정리. **(e) JSON 413 은 20:40 검증 완료**(사용자 nginx 반영 뒤). **미검증**: 사용자 계정 토큰의 201(관문 ③)

**자기 공격 체크** (사용자, 관문 ③)
- [ ] `curl -H "X-API-Token: $API_TOKEN" -F file=@… https://changkwon.mso.so/api/upload` → 201 `{id,name,level:"public"}`, 감사 `detail.auth=api_token`
- [ ] 같은 헤더로 `DELETE /api/files/{id}` → 401 「로그인이 필요합니다」(토큰은 업로드 밖에서 무효)
- [ ] `.exe` 올리기 → 400 이유 문구 · 헤더 빼기 → 401 · 토큰 한 글자 바꾸기 → 401

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-15 | 어려움(사용자) | 강사 프롬프트 `POST /api/upload` + `X-API-Token`(from `.env` `API_TOKEN`)이 도메인에서 404 | 그 창구·인증이 v2·v1 어디에도 없었다. v2=`/api/files`+세션+CSRF, v1(`/var/www/html`:8081)=`api/upload.php`+세션. 둘 다 `API_TOKEN` 없음 → 도메인은 v2 라 `/api/upload` 404. 강사 전제(정적 토큰 업로드)를 v2 에 새로 만드는 것이 과제 → F-49 |
| 2026-09-15 | 결정(사용자) | 「응, F-49 로 만들어줘」 — 처음 제안은 사용자별 DB 토큰(B). 8시간 세션 재사용 워크어라운드도 안내 | 이후 강사 2차 프롬프트 도착으로 재결정(아래) |
| 2026-09-15 | 결정(사용자) | 강사 2차 프롬프트 붙임 → **A. 강사 스펙(`.env` 정적 토큰·`/api/upload`)으로 갈아타기** · **오류 이유는 400/401/413 만, 500 은 일반 문구** | PRD F-49 ④·S-09-7 유지. Opus 1차 중단, 코드 원복. 충돌 알림 → 결정 → 문서 → 구현 순서(CLAUDE.md 0장) |
| 2026-09-15 | 궁금(사용자) | `<아이디>` 처럼 꺾쇠째 보내 로그인 401 · PowerShell `$(changkwon)` command not found | 꺾쇠는 자리표시자라 빼야 함. `$( )` 는 명령 치환 — 3장 기초 기록 참고 |
| 2026-09-15 | 해소(사용자) | `curl.exe -o API.md .../API.md` 가 깨진 한글 → 실은 404 JSON | `API.md` 는 웹루트(`public/`) 밖 저장소 루트라 HTTP 로 안 열린다(의도). 받으려면 `scp` 또는 서버에서 `cat`. 깨짐은 PowerShell 콘솔 인코딩 |
| 2026-09-15 | 어려움(사용자) | PC 프로그램이 404 「등록되지 않은 도메인입니다 · 요청하신 도메인으로 연결된 서비스가 없습니다」 | 우리 서버(JSON 「찾을 수 없습니다」)가 아니라 **강사 프록시의 HTML 404** — 호스트 이름이 틀림. PC 의 `$(whoami)` 는 `user`(프롬프트 `C:\Users\user`)라 `user.mso.so` 가 됨. 실측: `curl https://user.mso.so/api/health` → 프록시 HTML 404, `https://changkwon.mso.so/api/health` → 200. 고정 문자열 `changkwon.mso.so` 로 써야 한다. F-49 완료와 무관 |
| 2026-09-15 | 알림(Fable) | 세션 모델이 Fable→Opus 4.8 로 바뀌었다가 사용자가 `/model` 로 Fable 복귀 | CLAUDE.md 1장. 구현은 Opus 에이전트, 검토는 Fable(메인)로 구현자≠검토자 유지 |
| 2026-09-15 | 알림(Fable) | 저장소 루트에 사용자의 쿠키 파일 `jar`(로그인 세션 토큰) 가 생김(`curl -c jar`) | 비밀값 — 커밋 금지(`git add -A` 금지 규칙으로 보호되지만) · 사용 끝나면 `rm jar` 또는 홈 디렉터리(`~/.tbox-jar`)로 옮길 것 |
| 2026-09-15 | 결함(Fable 검토) | API `.exe` 업로드가 이유 없이 「잘못된 요청입니다」 | 매핑표에 `ext_not_allowed` 누락(주석엔 있었음). 재지시 1회: `Upload::ALLOWED_EXT` 로 문구 생성 + 20:20 기계 대조. 교훈: **401/503 상태에선 어떤 시험도 표의 빠짐을 못 본다** — 긍정 경로 실측이 유일한 검증 |
| 2026-09-15 | 결정(Fable) | 긍정 경로를 시드 계정 없이 검증하는 법 | `API_TOKEN_USER` 를 잠시 `qa_employee01` 로 두고 실측 → 끝나면 `admin01` 로 회전(검토 토큰 무효). 토큰 값은 셸 변수로만, 출력 0. 회전 직후 귀속 확인용 400 한 번은 `admin01` actor 로 남음(검토 UA, 업로드 없음) |
| 2026-09-15 | 차단(분류기) | nginx JSON 413 반영 `sudo bash deploy/switch-site.sh status` 가 자동 승인 분류기에 거부 | 시스템 설정 변경은 사용자 실행: `! sudo bash /var/www/tbox-v2/deploy/switch-site.sh status`. 반영 전 T-61 (e) 미검증. 반영 뒤 `check_baseline.sh`(T-17~19) 재실행 권고(CLAUDE.md 7장) |
| 2026-09-15 | 알림(Fable) | Opus 에이전트 보고 ⑦ 「작업 중 사용자가 curl 명령을 물었다」 | 이 세션에 그런 사용자 메시지는 없었다 — 에이전트가 Fable 의 보정 지시를 사용자 질문으로 오인한 것으로 보임. 기록하지 않음 |
| 2026-09-15 | 해소(Fable) | 관문 ②→④: 커밋 `20f51cb` 「F-49: 프로그램용 업로드 API」 → push → `release.sh minor` → `v1.1.0`(`613d229`, 태그 원격 확인). 관문 ③(사용자): ① `! sudo bash deploy/switch-site.sh status` 로 nginx JSON 413 반영 → 102MB 던져 JSON 413 확인 ② `cat .env` 로 토큰 확인 → 진짜 토큰으로 201·`.exe` 400 이유 확인 ③ `check_baseline.sh`(T-17~19) 재실행 | STAGES F-49 · SECURITY 7장 2026-09-15 F-49 행 |

### F-50 — 프로그램용 검색·다운로드 (토큰 라우트 확장) — 착수 2026-09-15

> 지시서: `docs/f50-api-read-directive.md`. 근거: PRD F-50 · 8장 `/api/files`·download 행 · 12.2 T-62 · S-02 · S-04, ARCH 4.3, SECURITY R-82(갱신)·R-83.
> 배경: 강사 3차 프롬프트 — 검색(`q`,`limit`, 간결 배열, 바인딩) + 다운로드(원래 이름, IDOR 403), 2단계와 같은 토큰 인증. 사용자 「제일 늦고 있어 빨리」.

**개발 사항** (구현 Opus / 문서 Fable)
- [x] (Fable) PRD F-50·8장 두 행·T-62, ARCH 4.3, SECURITY R-82 갱신·R-83, 지시서
- [x] (Opus) `public/index.php` — `TBOX_API_TOKEN_ROUTES`(셋) + **`TBOX_API_TOKEN_ONLY_ROUTES`**(업로드만) · `[2-T]` = 집합 ∧ (헤더 있음 ∨ ONLY)
- [x] (Opus) `src/handlers/files_list.php` — 토큰 요청이면 간결 배열 + `limit`; 웹 응답 불변
- [x] (Opus) `API.md` 8·9장(실측 응답은 Fable) · `tests/api_read.sh`(T-62)
- [x] 손대지 않음(확인): `files_download.php`·`Auth.php`·`Http.php`·`Audit.php`·`Authz.php`

**검토 체크** (Fable, 관문 ②)
- [x] **Fable 검토 (2026-09-15 21:00~21:10)** — 코드 읽기 + `tests/api_read.sh` 38/0/0(귀속 `qa_employee01`) + 추가 실측(빈 결과 `[]`, SQLi `[]`, 다운로드 헤더·`diff` 동일, 404) → 시험 파일 휴지통 → 귀속 `admin01` 회전. 린트 0 · `php -l` 2/2

**자기 공격 체크** (사용자, 관문 ③)
- [x] (사용자 2026-09-15 21:2x) 토큰으로 `GET /api/files?q=api-test&limit=8` → `[{id,name,level,size,uploaded_at}]` [200] 확인 · SQLi 시도는 미보고
- [x] (사용자 2026-09-15 21:3x) 토큰으로 `GET /api/files/f30f796c-…/download` → [200], `-OJ` 로 원래 이름 저장 확인 · 403/404 갈래는 Fable T-62 (e) 실측, 사용자 미보고
- [ ] 같은 토큰으로 `DELETE /api/files/{id}` → 401

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-15 | 요청(사용자) | 강사 3차: `GET /api/files?q&limit`(바인딩, 역할 필터 원천 제외) · `GET /api/files/{id}/download`(원래 이름, IDOR 403), 2단계와 같은 인증, API.md 이어 쓰기, 한글 커밋 | 두 라우트는 이미 있음 — 토큰을 여는 것 + 검색 응답 투영만 추가. 인가 새 코드 0(ARCH 4.3) |
| 2026-09-15 | 결정(Fable) | 새 라우트 대신 **기존 라우트에 토큰을 열고 응답 모양만 갈래** | 사용자가 적은 경로가 기존 경로와 같고, `visibleFilesWhere`·`decideRead` 를 그대로 타야 「원천 제외·IDOR 403」이 새 코드 없이 성립. 관리자 귀속이면 전부 보이는 점은 R-83 권고로 |
| 2026-09-15 | 알림(Fable) | 세션 모델이 잠시 Opus 4.8 로 표시된 뒤 Fable 복귀 | CLAUDE.md 1장. 구현 Opus 에이전트·검토 Fable 유지 |
| 2026-09-15 | 결함(지시서, Opus 발견) | 「`[2-T]` 를 `in_array` 하나로」 → 화면의 목록·다운로드가 세션으로도 401 (Opus 실측) | 넓힌 둘은 화면도 쓰는 라우트. 고침: 헤더가 실제로 온 요청만 토큰 경로, `POST /api/upload` 만 헤더 없어도 토큰 경로(ONLY), 틀린 토큰은 세션 폴백 없음. ARCH 4.3 「헤더 유무의 갈림길」·지시서 정오표. 교훈: 지시서도 실측 대상 — 구현자가 지시서를 의심해 준 사례 |
| 2026-09-15 | 알림(Fable) | 검토 중 감사 로그에 `admin01`/`t-box-assistant/1.0` 요청 10건 관찰(Opus R-73 관문이 한 번 멈춤) | 사용자의 로컬 프로그램이 실제 토큰으로 서버를 부르고 있다는 뜻 — 관문 ③ 실사용 진행 중으로 본다. 검토는 그 창이 지난 뒤 재실행 |
| 2026-09-15 | 해소(Fable) | 관문 ②→④: 커밋 `b8929a8` 「F-50: 프로그램용 검색·다운로드」 → push → `release.sh minor` → `v1.2.0`(`5977306`, 태그 원격 확인). 관문 ③(사용자): 진짜 토큰으로 검색·다운로드 한 번씩 | STAGES F-50 · SECURITY 7장 F-50 행 |
| 2026-09-15 | 어려움(사용자) | 다운로드 명령에 `<id>` 를 꺾쇠째 입력 → `bash: id: No such file or directory` · 이어서 `api_token_rotate.sh admin01` 실행 뒤 「된 거야 안 된 거야?」 | `<` 는 셸 입력 리다이렉션 — 자리표시자는 꺾쇠 없이 실제 id 로(3장 기초 기록의 `<아이디>` 와 같은 유형). 회전은 성공(「갱신됨·640」이 성공 출력)이지만 토큰이 새 값으로 바뀌어 셸의 `$API_TOKEN` 재 export 필요. 회전은 필수 아니었음(귀속 이미 admin01) |
| 2026-09-15 | 궁금(사용자) | 「비밀번호는 어떻게 암호화 되어있어?」 | 암호화(복호화 가능)가 아니라 **단방향 해시** — `password_hash(…, PASSWORD_ARGON2ID)`(m=65536 KiB, t=4, p=1, 사용자별 무작위 소금 포함) 로 `users.password_hash` 에만 저장, 평문은 어디에도 없음. 로그인은 `password_verify` 로 비교, 없는 아이디는 고정 더미 해시로 같은 시간 소비(계정 존재 유추 방지). 근거: PRD S-09-4 · `db/schema.sql` users 주석 · `src/Auth.php` DUMMY_HASH·`password_verify`. 확인: `psql … -c "SELECT split_part(password_hash,'$',2), count(*) FROM users GROUP BY 1"` → `argon2id` 25건 |
| 2026-09-15 | 요청(사용자) | 「17시 2차 강의 이후 세션(죽은 것 포함)을 참조해 t-box 업로드·다운로드 과정을 정리하고, 2차 비밀번호→토큰 전환에 시간이 왜 많이 걸렸는지 리뷰해서 문서에 포함해」 | `docs/practice-review-2026-09-15.md` — 시간표(17:00~22:14) · 세 인증 방식(강사 curl 실패 → 세션+CSRF 201 → F-49 토큰) · 87분 분해(폐기·재작업 33분, 방향 탐색 16분, 규칙 고정 비용 44분) · 지연 요인 10건 · 다음 실습 권고 9건 · 남은 정리 목록 |

### F-51 — 2단계 인증 등록 화면에 QR 코드 — 착수 2026-09-16

> 지시서: `docs/f51-mfa-qr-directive.md`. 근거: PRD F-51 · 8장 `/api/me/mfa/enroll` 행 · 12.2 T-63 · S-03 · S-10-C, ARCH 5.25, SECURITY R-84.
> 배경: 동료 의견 「2단계 인증은 좋으나 불편, 구글 인증도 있으니 그걸 쓰면 좋겠다」 → Google Authenticator 의 정상 경로 「QR 코드 스캔」이 t-box 에 없었다. 2026-09-11 「QR(ⓒ)은 후속」의 후속. 사용자 「QR진행해」.

**개발 사항** (구현 Opus / 문서 Fable)
- [x] (Fable) PRD F-51·F-17-2·8장 enroll 행·T-63, ARCH 5.25, SECURITY R-84, DEPENDENCIES(시험용 `qrcode`), 지시서
- [x] (Opus) `src/Qr.php`(새) — 외부 의존 0 QR 인코더(바이트·M·v1~10·마스크 벌점·버전 정보) → `{version,size,rows}`
- [x] (Opus) `src/bootstrap.php` require 한 줄 · `src/handlers/mfa_enroll.php` — 응답에 `qr`(실패 시 `null`), 머리말 주석 갱신
- [x] (Opus) `src/views/mfa_enroll.php` — `#mfa-qr` canvas + 안내 순서(QR → 링크 → 손 입력) · `public/assets/app.js` — 모양 검사 뒤 `fillRect` 그리기·지우기 · `public/assets/app.css`
- [x] (Opus) `README.md` 2단계 인증 절 문구 · `tests/f51.sh`(T-63) · (Fable, 경미) 낡은 주석 3곳 `Totp.php`·`settings_page.php`·`scripts/totp_code.php`
- [x] 손대지 않음(확인, 주석 제외): `src/Totp.php`·`src/Auth.php`·`mfa_confirm.php`·`mfa_disable.php`·`Http.php`·`Audit.php`·`db/*`·nginx conf

**검토 체크** (Fable, 관문 ②)
- [x] 관문 ④ 커밋 `50fa62b` → push → 발행 **v1.3.0**(`52d77d0`, 태그 push 확인). 관문 ③ 은 커밋 뒤 사용자 일괄(아래)
- [x] **Fable 검토 (2026-09-16)** — `tests/f51.sh` 재실행 **50/0** (a) 오라클 48/48 (b) 응답 모양·재인코딩 동일·계정 원상 (c) 비노출 (d) 화면 4장 (e) 폴백 (f) 린트·`php -l`
- [x] `Qr.php` 코드 읽기 — 입력 경로에 사용자 값 없음, 로그·파일·요청 0, 예외는 `RuntimeException` 하나

**자기 공격 체크** (사용자, 관문 ③)
- [ ] 휴대폰 Google Authenticator 「QR 코드 스캔」으로 t-box 등록 화면의 QR 을 찍는다 → 계정 `t-box:아이디` 가 생기고 6자리 코드가 t-box 「확인하고 켜기」에서 통과
- [ ] 같은 화면에서 비밀 키(텍스트)로 「설정 키 입력」 등록도 여전히 되는지(둘이 같은 코드를 냄)
- [ ] 등록 뒤 화면을 새로 고쳐 QR·비밀 키가 다시 보이지 않는지

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-16 | 어려움(사용자) | 「2차 인증은 좋으나 불편, 구글 인증도 있으니 그걸 쓰면 좋겠다」는 의견 → Google Authenticator 「인증자 코드 추가」(QR 스캔 / 설정 키 입력) 화면을 보며 「인증 앱 바로 추가 링크가 안 보인다」 | **새 방식이 아니라 이미 되는 것** — 2단계 인증은 표준 TOTP(RFC 6238)라 Google Authenticator 에 그대로 등록된다(`src/Totp.php` 규격 주석, 9/11 「공짜 앱」 문답). 링크가 안 보인 이유: 보고 있던 화면이 「t-box 2단계 코드 생성기」(`/assets/totp-generator.html`)였고, 「인증 앱에 바로 추가(otpauth 링크)」는 로그인 뒤 「내 계정」에서 「2단계 인증 켜기」를 누른 **뒤**에만 나온다(이미 켜진 계정은 끄고 다시 켜야 새 키). 폰 브라우저로 내 계정 → 끄기 → 켜기 → 링크 → 앱 자동 등록으로 **해결(사용자 「됬다」)**. 남는 불편: 앱의 정상 경로인 **QR 스캔**이 없어(9/11 결정 「QR 은 후속」) PC 에서 등록하면 32자 손 입력. 외부 의존 없이 QR 을 직접 그리는 것을 후속 후보로 둔다. 구글 **계정 로그인(OAuth)** 은 외부 요청(S-05·CLAUDE.md 4장)·Admin 계정 생성 구조(F-16)와 어긋나 채택하지 않는다 |
| 2026-09-16 | 판단(Opus→Fable) | 시험 (d) 첫 실행 실패 — 직전 `confirm` 과 같은 30초 창의 같은 코드로 브라우저 로그인 시도 | R-45(코드 1회성)가 의도대로 막은 것. 시험을 「항상 다음 창 + 3회 재시도」로 고침. 앱 결함 아님. 시험 시간 약 6분 |
| 2026-09-16 | 정보(Fable) | 자동 마스크 선택이 파이썬 `qrcode` 와 6건 중 3건 다름 | 벌점 동률·N3 셈법 차이. 어느 마스크든 규격상 유효하고 강제 마스크 8종 전부 비트 일치이므로 판독에 영향 없음 — 정보로만 기록 |
| 2026-09-16 | 결정(사용자) | 「QR진행해」 | F-51 — 서버(`src/Qr.php`)가 행렬을 만들고 화면이 canvas 에 그린다. 외부 라이브러리 대신 직접 구현(`Totp.php` 와 같은 이유, S-10-C). 시험 오라클로 파이썬 `qrcode` 를 `~/.local` 에만 설치(앱 무관) |

### F-52 — 2단계 인증 필수 범위: Admin 만 필수, 나머지는 선택 + 권장 — 착수 2026-09-16

> 지시서: `docs/f52-mfa-scope-directive.md`. 근거: PRD F-52(F-16 ③ 대체) · 8장 관문 행 · 12.2 T-64 · S-01, ARCH 5.26 · 4.0, SECURITY R-05(유지)·R-85(새).
> 배경: 사용자 「보통 다른 사이트는 2차 OTP 가 선택인데 우리 너무 과한 것 같아. Admin 권한자만 필수, 게스트·직원은 선택. 다만 권장하는 절차는 필요」.

**개발 사항** (구현 Opus / 문서 Fable)
- [x] (Fable) PRD F-52·F-16 ③ 주석·8장 관문·enroll 행·T-64, ARCH 5.26·강제 절차 행, SECURITY R-85·R-05 보충, 지시서
- [x] (Opus) `src/Auth.php` — `MFA_REQUIRED_ROLES`·`mfaRequired()`·`mfaRecommended()`·`nextStep` 조건(실제 역할 + `mfa_enabled`)
- [x] (Opus) `login.php`·`me.php` — `mfa_required`·`mfa_recommended` · `mfa_confirm.php` — `forced` 조건 · 주석(`mfa_disable.php`·`settings_page.php`·`users_mfa_reset.php`)
- [x] (Opus) `home.php` 권장 카드 마크업 · `app.js`(홈 카드·「나중에」 sessionStorage·내 계정 문구) · `app.css` · `src/help/screens.php`·`README.md`·`wiki/*.json` 문구
- [x] (Opus) `tests/f52.sh`(T-64)
- [x] 손대지 않음(확인): `public/index.php` 관문 코드(조건은 `nextStep` 안) · `Totp.php`·`Qr.php`·`db/*`·`mfa_enroll.php`·nginx

**검토 체크** (Fable, 관문 ②)
- [x] 관문 ④ 커밋 `98f7550` → push → 발행 **v1.4.0**(`87b5df3`, 태그 push). 관문 ③ 은 커밋 뒤 사용자(아래)
- [x] **Fable 검토 (2026-09-16)** — `tests/f52.sh` 재실행 **71/0** (a) 단위 (b) Employee 200·카드 (c) Admin 403·강제 상자 (d) 로그인 코드 유무 (e) 문구 0건 (f) 린트·원상
- [x] `Auth.php` diff 읽기(41+/1−, 네 군데뿐, `real_role` 우선 확인) — 바뀐 곳이 상수·두 함수·`nextStep` 조건뿐인지, `real_role` 우선인지

**자기 공격 체크** (사용자, 관문 ③)
- [ ] employee01 로 2단계 인증 끄기 → 로그아웃 → 비밀번호만으로 로그인 → 홈에 권장 카드 → 「나중에」 → 파일 화면 정상 → 재로그인 시 카드 다시
- [ ] admin01 로 2단계 인증 끄기 → 다음 요청부터 등록 화면만 보이는지(막힘) → 다시 켜기
- [ ] (선택) Admin 이 employee01 을 Admin 으로 올리면 그 계정이 다음 요청에 등록 화면에 걸리는지 → 원상

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-16 | 결정(사용자) | 「보통 다른 사이트는 2차 OTP 설정이 선택인데 우리 너무 과한 것 같아. admin 권한자만 필수, 게스트·직원은 선택. 다만 권장하는 절차는 필요」 | F-16 ③(모든 역할 필수, 9/11)을 F-52 로 대체. **Manager 는 사용자가 언급하지 않아 「Admin 만」을 글자 그대로** 적용(선택) — 필수로 바꾸려면 `Auth::MFA_REQUIRED_ROLES` 한 단어 + R-85 갱신. 위험은 R-85 로 기록(사용자 수용) |
| 2026-09-16 | 판단(Opus) | 시험 (b)(c) 1차에서 「콘솔 오류 0건」 실패 → 원인은 `GET /favicon.ico` 404(F-52 이전부터, 사용자 브라우저에서도 발생) | 앱 결함 아님. 시험은 파비콘 404 를 갈라 세도록 고침. 파비콘 파일을 둘지는 사용자 판단(후속 후보) |
| 2026-09-16 | 판단(Fable) | 관문 조건을 `mfa_setup_required` 플래그가 아니라 `mfa_enabled` 로 | 플래그는 보통 `!mfa_enabled` 와 같지만 어긋난 순간 관문이 열리면 안 된다. 켜졌는지의 진실은 `mfa_enabled`. 「역할로 보기」 중에는 `real_role` 로 판정(흉내로 빠져나갈 수 없게) |

### F-53 — 업로드 내용 검사 강화 (zip 내부·매크로·PDF·HWP, 앱 안) — 착수 2026-09-16

> 지시서: `docs/f53-upload-inspect-directive.md`. 근거: PRD F-53 · 7.6 S-06 표 · 12.2 T-65, ARCH 5.27 · 5.2, SECURITY R-02(갱신)·R-86.
> 배경: 사용자 「업로드되는 파일의 보안검사를 권장 받았어」 → Fable 평가(R-02 높음·Q-3 미룬 사유 해소) → 사용자 「둘 다 진행해」(앱 안 검사 + ClamAV).

**개발 사항** (구현 Opus / 문서 Fable)
- [x] (Fable) PRD Q-3 재결정·F-53·S-06 표·T-65, ARCH 5.27, SECURITY R-02·A-3·R-86, 지시서
- [x] (Opus) `src/Upload.php` — zip 내부 이름·경로·중첩·압축 폭탄(32MB 바닥, 검토 보정) · 오피스 매크로 · PDF 능동 내용 · HWP OLE 서명 (추출 0)
- [x] (Opus) `Audit.php` 문장 틀 40줄(5 창구 × 8 사유) · (Fable) ARCH 7.4 에 옮김 · `tests/f53.sh`(T-65)
- [x] 손대지 않음(확인): `ALLOWED_EXT`·`BLOCKED_EXT`·MIME 표 · 핸들러 · DB

**검토 체크** (Fable, 관문 ②)
- [x] 관문 ④ 커밋 `34e4fdf` → push → 발행 **v1.5.0**(`a2112e2`). 관문 ③ 은 커밋 뒤 사용자(아래)
- [x] **Fable 검토 (2026-09-16)** — `tests/f53.sh` 재실행 **69/0**(미검증 3: 진짜 hwp·가져오기 HTTP·zip_unreadable) · 100MB zip 속 검사 0.0003초 · 회귀 T-7·T-8
- [x] `Upload.php` diff 읽기 — 추출·임시 파일·외부 호출 0, 실패는 사유 코드 예외뿐 · 보정 1(압축비 32MB 바닥)

**자기 공격 체크** (사용자, 관문 ③)
- [ ] zip 안에 `.exe` 를 넣어 올리기 → 거부 · 정상 zip → 성공
- [ ] 워드에서 매크로 포함 문서를 `.docx` 로 저장해 올리기 → 거부
- [ ] 관리자 감사 로그에서 사유 코드(`zip_blocked_entry`·`macro_found`…) 확인
- [ ] **진짜 HWP 5.x 통과(미검증 항목)** — 사용자 「hwp 문서 올렸어」(10:1x)라 했으나 서버에는 `.hwp` 요청이 없다(nginx·감사 모두 `aa.docx` 두 건뿐). 한글에서 `.docx` 로 저장된 파일이었을 가능성 — `.hwp` 확장자 그대로 다시 올려 확인 필요

### F-54 — 악성코드 검사 파이프라인 (ClamAV `clamscan` 배치·격리·403·AL-19) — 착수 2026-09-16

> 지시서: `docs/f54-clamav-directive.md`(F-53 뒤 작성). 근거: PRD F-54 · 7.6 · 8장 download 행 · 7.11 AL-19 · 12.2 T-66, ARCH 5.28 · 5.3 [4] · 11.1, SECURITY R-02·R-86·R-87, DEPENDENCIES(ClamAV).
> 설치(`apt-get install clamav clamav-freshclam`, 격리 디렉터리, cron 행)는 서버 설정 변경 — CLAUDE.md 7장에 따라 T-17~T-19 재실행·DEPENDENCIES 실측 갱신. 사용자 「둘 다 진행해」로 승인.

**개발 사항** (구현 Opus / 설치·문서 Fable)
- [x] (Fable) PRD F-54·S-06 표·download 행·AL-19·T-66, ARCH 5.28·5.3·11.1, SECURITY R-86·R-87, DEPENDENCIES
- [x] (Fable, 09:10) 패키지 설치 `clamav`·`clamav-freshclam` 1.5.3(데몬 제외) · enable · 시그니처 main 63/daily 28124/bytecode 339 수신 · `/var/lib/tbox-v2/quarantine` 0750 tbox-php · `check_baseline` OK 16 불변 · T-18 리스너 추가 없음 · `clamscan` 실측 22초/991MB. `.env` `SCAN_ENABLED=1` 은 배치 코드 검토 뒤 · 첫 시그니처 수신 확인 · `/var/lib/tbox-v2/quarantine` 0750 tbox-php · `.env` `SCAN_ENABLED=1`
- [x] (Opus) `Upload::initialScanStatus()` + INSERT 네 곳 · `scripts/scan_pending.php` · 다운로드·미리보기·공유·토큰 403 · 목록 `scan_status`·화면 배지 · `alerts.php` AL-19 · `deploy/cron.d`·`install.sh`·`check_baseline.sh` · `tests/f54.sh`(T-66)
- [x] 손대지 않음(확인): `Authz`·`Auth`·`db/schema.sql`(열은 이미 있음) · `Http.php` 는 검토 보정으로 문구 상수 2줄만

**검토 체크** (Fable, 관문 ②)
- [x] 관문 ④ 커밋 `106e677` → push → 발행 **v1.6.0**(`fca33e6`). 관문 ③ 은 커밋 뒤 사용자(아래)
- [x] **Fable 검토 (2026-09-16)** — 보정 3건(Http 상수·격리 복구·CSS 토큰) 반영 → cron 설치·`SCAN_ENABLED=1`(09:46) → `tests/f54.sh` 재실행 **59/0** (a) pending→clean (b) EICAR→infected·격리·403·AL-19 (c) 대기 0건 즉시 종료·잠금 (d) SCAN_ENABLED 꺼짐 (e) 서버 층 (f) 자원 (g) 린트
- [x] `scan_pending.php` 읽기 — 경로는 `Storage::pathFor` 만, 격리 이동은 `rename`, 오류 시 상태 유지

**자기 공격 체크** (사용자, 관문 ③)
- [x] (사용자 2026-09-16 10:1x) 목록에서 「격리됨」 확인 — 다만 그 파일은 Fable 시험(T-66 (b), `qa_employee01`)이 올린 EICAR 였다. 사용자 목록에 남지 않게 Fable 이 앱 창구로 휴지통 이동(행 `infected` 유지, 바이트는 `quarantine/`, 감사 기록 보존). 사용자 본인의 EICAR 업로드는 아직
- [x] (사용자 2026-09-16 10:09) `aa.docx` 업로드 → 201 → 배치 10:10 `file.scan allow`(clean). 첫 시도 10:08:44 는 0바이트로 `empty_file` 거부(파일이 저장 중이었던 듯)
- [ ] 정상 파일 올린 직후 다운로드 → 「검사 중」 → 1분 뒤 성공 (aa.docx 로 배지 관찰 여부는 사용자 미보고)

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-16 | 요청(사용자) | 「업로드되는 파일의 보안검사를 권장 받았어」 → Fable 평가 → 「둘 다 진행해」 | R-02(높음) 는 9/11 Q-3 로 미뤘던 것 — 사유(디스크 89%)가 54% 로 해소. 두 겹: F-53 앱 안(의존성 0, 풀지 않고 이름·바이트만) + F-54 ClamAV(앱 밖 배치) |
| 2026-09-16 | 판단(Fable) | `clamd` 상주 대신 `clamscan` 을 「대기 파일 있을 때만」 | VM 메모리 3.8GB·가용 1.1GB, clamd 는 1GB 이상 상주 → PostgreSQL 과 다툼(9/15 디스크 사고처럼 자원 고갈이 곧 장애). 대가는 검사 창 1분 + 실행마다 시그니처 적재(R-86). 운영 전환 시 배치 [3] 만 `clamdscan` 으로 교체 |
| 2026-09-16 | 보정(Fable 검토) | F-53 압축비 100:1 규칙이 5KB→500KB 반복 텍스트(로그·CSV)를 폭탄으로 오탐 | 폭탄은 수십 MB 이상으로 커질 때만 위험 — 합계 32MB 바닥(`ZIP_RATIO_FLOOR`)을 두고 시험 케이스(815:1·500KB → 201) 추가. PRD F-53 ①·ARCH 5.27 갱신 |
| 2026-09-16 | 관찰(Fable) | F-53 첫 재실행이 R-73 사전 검사(최근 10분 비-qa 활동: admin01 `auth.gate`)에 걸려 시작 안 됨 | 사용자가 F-52 관문 ③ 을 하던 중 — 규칙이 의도대로 시험과 실사용을 갈랐다. 10분 뒤 재실행 |
| 2026-09-16 | 보정(Fable 검토) | F-54 ① 403 문구 상수가 핸들러 네 파일에 중복 ② 격리 이동 뒤 DB 기록 실패 시 그 행이 영영 pending(매분 `file_missing`) ③ CSS 고정 색값 | ① `Http::MSG_SCAN_PENDING/INFECTED`(보안 핵심 파일이지만 상수 2줄만 허용) ② 다음 실행이 격리 폴더에 같은 이름이 있으면 `quarantined_earlier` 로 infected 마무리 ③ 토큰 `--bg` |
| 2026-09-16 | 관찰(Fable) | 공유 링크는 다른 실패를 404 로 통일하는데 검사 상태만 403 | 유효 토큰 보유자는 이미 아는 사실, 추측자는 유효 토큰을 맞혀야 하므로 실익 없음 — R-86 ⑦ 로 기록, 문구 유지 |
| 2026-09-16 | 판단(Fable) | 앱 코드가 아니라 `scripts/` 배치가 ClamAV 를 부른다 | CLAUDE.md 금지 패턴(exec·소켓)·php-fpm `disable_functions` 은 앱에만. `scripts/` 는 CLI·린트 제외(`purge_trash`·`alerts` 와 같은 자리). 앱은 상태를 읽어 403 만 |
| 2026-09-16 | 관찰(Fable) | `app.log` 에 08:55:27~08:57:13 `Upload.php` 구문 오류 54줄 — Opus 가 F-53 을 편집하던 중간 상태 | 운영 트리에서 직접 편집하는 구조의 대가: 그 2분간 모든 요청이 500 이 될 수 있었다. 실측: 공개·로컬 사이트 접근 로그에 5xx **0건**(사용자 요청 없던 시간). 후속 후보: 구현 에이전트가 파일을 통째로 다시 쓸 때 임시 파일에 쓰고 `php -l` 통과 뒤 `mv` 하도록 CLAUDE.md 4장에 한 줄(사용자 결정 필요) |
| 2026-09-16 | 관찰(Fable) | 관문 ③ 첫 보고 「hwp 올렸어 / EICAR 가 격리됐대」 | 서버 기록은 `aa.docx` 두 건(0바이트 거부 → 성공 → clean)뿐, `.hwp` 없음. 격리 표시는 시험 파일 → 휴지통으로 정리. 시험이 남긴 증거 파일이 사용자 목록에 보이는 것은 혼란 요인 — 다음 시험부터 (g) 정리 단계에서 격리 행도 휴지통으로(바이트·감사는 그대로) |
### F-55 — 백업 원격 사본: 사용자 PC 가 매일 끌어온다 (pull) — 착수 2026-09-16

> 지시서: `docs/f55-backup-pull-directive.md`. 근거: PRD 7.8 S-08·Q-4(임시 결정)·12.2 T-67, ARCH 11.1 (PC) 행, SECURITY R-08·R-88(새)·8.4-4, 09-15 리뷰 3·5번.
> 배경: 09-16 재확인에서 치명·높음 5건 중 3건이 미해결 → Fable 권고 순서 ③백업 원격 사본 → ②방화벽 → ⑤원격 로그. 사용자 「여기」 = 지금 접속 중인 PC(Windows, PowerShell, 10.0.4.173).

**개발 사항** (구현 Opus / 문서 Fable)
- [x] (Fable) PRD F-55·Q-4·S-08 표·T-67, ARCH 11.1 행, SECURITY R-88·R-08·8.4-4·7장, 지시서, STAGES
- [x] (Opus) `deploy/pc/pull_backup.ps1` — 매개변수 기본값(서버·계정·목적지·3일·45일), ssh→파일 리다이렉트(파이프 금지)→`tar.exe -xf`, sha256 대조, 45일 정리, `-Key`·`-Install`·`-Uninstall`, `pull.log` — 598줄, UTF-8 BOM, `Start-Process -RedirectStandardOutput`(파이프 0)
- [x] (Opus) `deploy/pc/README.md` — 처음 설정 순서·매일 확인·실패 시·한계 (+ Fable 0단계 「스크립트를 PC 로 scp」 추가)
- [x] 손대지 않음(확인): 서버 파일 전부. `README.md` 「서버 밖 사본」 절은 F-54 발행(v1.6.0) 뒤 Fable 이 추가

**검토 체크** (Fable, 관문 ②)
- [x] 스크립트 정적 검토: 바이너리 파이프 없음 · 키 내용 미출력 · 서버 쪽 명령이 `tar`·`cat` 만 · 자리표시자 없음 · `.sha256` 파싱 `<해시>  <이름>` — **Fable 검토(09-16)**: 원격 명령은 `sudo -n tar …`·`sudo -n cat backup.key` 둘뿐, PowerShell 파이프·`>` 없음, `StrictHostKeyChecking` 미완화(1단계 수동 접속으로 known_hosts), 해시 `^[0-9a-fA-F]{64}$` 검사·`-ieq` 대조, `-Key` 는 `pull.log` 에 위치도 안 남김·`-Dest` 안 거부(코드 2), 보관은 이름 날짜 `TryParseExact`, 스케줄 `-Daily -At 05:00 -StartWhenAvailable`. 금지 패턴 계수 0(주석 1줄 제외)
- [x] 서버 쪽 명령 실측(09-16): `sudo -n tar … --newer-mtime='3 days ago' \| tar -tvf -` 19개·약 140MB, AL-9 헛경보 없음
- [x] 관문 ④ 설계 커밋 `ac6f7f4` · 구현 커밋 `a049bd3` → push. 문서·PC 스크립트만이라 **발행하지 않음**(CLAUDE.md 6장: 사용자에게 보이는 화면 변경 없음)

**자기 공격 체크** (사용자, 관문 ③ = T-67, PC PowerShell 에서)
**2026-09-16 사용자 PC(Windows 11 Pro, PowerShell 5.1)에서 Opus 가 실행.** 결과는 `deploy/pc/README.md` 「검증 상태」에 같이 적었다.

- [x] (a) 첫 실행 → **처음엔 실패**(`FAIL | ssh 종료 코드  `, 142MB 를 받아 놓고 지움). 원인 고친 뒤(`6953b94`) 재실행 → 142,325,760 바이트·26항목, db·files sha256 일치, `pull.log` 마지막 줄 `OK`, 종료 코드 0
- [~] (b) `.sha256` 한 글자 바꾸고 재실행 → **시험이 성립하지 않는다.** pull 이 서버에서 `.sha256` 을 다시 받아 변조를 덮어쓴 뒤 검증하므로 언제나 `OK`. `Test-BackupArtifact` 만 떼어내 격리 시험 → 해시 변조·데이터 변조·`.sha256` 없음 3가지 모두 정확히 `FAIL` 반환(함수는 정상). 지시서의 시험 문구를 고치거나 `-VerifyOnly` 를 만들어야 한다
- [x] (c) `-Key F:\tbox-key\backup.key`(사용자 결정: 외장하드 F:) → 65바이트, 서버 키와 sha256 일치, `pull.log` 에 경로 미기록, `C:\tbox-backup` 안에 키 없음
- [x] (d) `-Install` → `tbox-v2 backup pull` State `Ready`, 05:00·StartWhenAvailable. `Start-ScheduledTask` 강제 실행 → `LastTaskResult 0`, `pull.log` 에 새 `OK`(다음 날을 기다리지 않고 확인)
- [x] (e) 46일·77일 전 이름 가짜 파일 → 삭제(4개), 44일 전 이름 → 보존. 경계 정확
- [x] 서버 `alerts.log` — pull 이 AL-9 를 울리지 않음(10:00~10:20 AL-9 1건은 다른 세션 `psql`). **다만 이것이 좋은 소식만은 아니다 — 아래 학습 기록**
- [x] 복호화 사슬: PC 사본 sha256 == 서버본, 서버 키로 풀면 `PGDMP` 매직 → `F:` 의 키로 `C:` 의 백업을 열 수 있다

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-16 | 결정(사용자) | 백업 원격 사본을 「여기」(지금 접속 중인 PC)로 | PRD Q-4 임시 결정. 지시서 「설계」 표 |
| 2026-09-16 | 판단(Fable) | push(서버→PC) 가 아니라 pull(PC→서버) | 서버에 원격 자격증명을 두면 root 공격자가 원격 사본까지 지운다(8.4-4, 9-11 사고 ⑥). 서버 파일 변경도 0 |
| 2026-09-16 | 판단(Fable) | `scp` 가 아니라 `ssh … sudo -n tar` 표준 출력 | 산출물이 root 0700 이라 changkwon 으로 `scp` 불가. tar 한 번이면 sudo 도 한 번 |
| 2026-09-16 | 판단(Fable) | PowerShell 파이프 금지, 파일로 리다이렉트 뒤 `tar.exe -xf` | PowerShell `\|` 는 바이너리를 문자열로 바꿔 깨뜨린다. Windows 10+ 는 `ssh.exe`·`tar.exe` 내장 |
| 2026-09-16 | 한계(Fable) | 이 VM 에 PowerShell 이 없어 스크립트 실행 검증 불가 | T-67 은 사용자 PC 에서. Opus·Fable 은 정적 점검 + 서버 쪽 명령만 실측 |
| 2026-09-16 | 결함(Opus, T-67 실행) | `Start-Process -PassThru` 의 `ExitCode` 가 빈 값 → 성공한 142MB 전송을 `FAIL` 로 처리하고 받은 tar 까지 삭제 | 핸들을 캐시하지 않으면 자식 종료 뒤 .NET 이 놓아 버린다. `WaitForExit()` 앞 `$null = $proc.Handle`. 커밋 `6953b94`. **정적 검토 2회(Opus·Fable)가 모두 놓쳤다** — 실행해야만 보이는 종류였다 |
| 2026-09-16 | 결함(Opus, T-67 실행) | T-67 (b) 「`.sha256` 변조 → FAIL」 시험이 설계상 성립하지 않는다 | pull 이 해시 파일을 서버에서 다시 받아 변조를 덮어쓴 뒤 검증한다. 함수 자체는 격리 시험에서 3가지 변조를 모두 잡았다. **진짜 문제는 그 다음**: 검증이 「방금 받은 파일 vs 방금 받은 해시」라 PC 사본이 나중에 썩어도 복구 시점까지 모른다. `-VerifyOnly` 필요(미구현) |
| 2026-09-16 | 한계(Opus, T-67 실행) | 이 PC 에 `gpg` 가 없다 | 서버가 사라진 순간 암호화 백업을 풀 도구가 PC 에 없다 — 원격 사본의 목적이 바로 그 순간이다. **해소: 사용자 결정 「F 에 두자」** → 휴대용 GnuPG 2.4.8 을 `F:\tbox-recovery\gpg\` 에. PC 에는 설치하지 않았다(임시 설치 후 제거, `Get-Command gpg` 공백 확인) |
| 2026-09-16 | 검증(Opus) | **복구 리허설이 실제로 통과했다** — 외장하드만으로 | `F:` 의 gpg + `F:` 의 키로 `C:\tbox-backup` 의 09-16 백업을 풀어 `PGDMP v1.14.0`(1,147,755바이트)와 gzip tar(51,804,356바이트·항목 1,628개, 실제 업로드 `.xlsx`) 확인. PRD 7.8 「분기 복구 리허설」의 복호화 부분에 해당. 시험 평문은 난수로 덮어쓴 뒤 삭제 |
| 2026-09-16 | 판단(Opus) | 백업을 `F:` 에 같이 두지 않는다 | 899GB 가 비어 있어 넣고 싶어지지만, 키와 백업이 한 매체에 있으면 분리 보관의 뜻이 사라진다. `RECOVER.md` 에 「왜 백업은 여기 없나」로 못 박았다 |
| 2026-09-16 | 판단(Opus) | `recover.ps1` 을 저장소에도 넣는다(`deploy/pc/`) | 외장하드는 고장 난다. 스크립트가 그 드라이브에만 있으면 드라이브와 함께 사라진다. gpg 바이너리(15.5MB)는 넣지 않고 출처·지문만 적었다 |
| 2026-09-16 | 결함(Opus) | `recover.ps1` 첫 실행에서 한글이 전부 깨졌다 | PowerShell 5.1 은 BOM 없는 파일을 ANSI(CP949)로 읽는다. `pull_backup.ps1` 에 BOM 이 있던 이유가 이것. UTF-8 BOM 으로 다시 저장해 해결. **PC 용 `.ps1`·`.md` 는 BOM 필수** |
| 2026-09-16 | 관찰(Opus, T-67 실행) | pull 이 AL-9 를 울리지 않는 것이 좋은 소식만은 아니다 | AL-9 는 「sudo 로 데이터·로그를 통째로 읽는 명령」인데, `sudo -n tar`(백업 전체)·`sudo -n cat backup.key`(복호화 키)가 바로 그것인데도 목록에 없다. 즉 **`changkwon` 의 SSH 키를 쥔 공격자가 백업 전체와 키를 가져가도 경보가 없다.** F-55 가 그 명령 모양을 매일 05:00 정상 동작으로 만들어 구분은 더 어려워졌다. SECURITY 7장 후속 후보 |
| 2026-09-16 | 보정(Fable 검토) | PC README 에 「스크립트를 PC 로 가져오는」 0단계가 없었다 | `scp changkwon@192.168.105.44:/var/www/tbox-v2/deploy/pc/pull_backup.ps1 C:\tbox-tools\` 추가. `-Install` 이 경로를 박아 두므로 옮기면 재등록 |


### F-56 — 프로그램용 `GET /api/whoami` (토큰 귀속 계정의 최소 정보) — 착수 2026-09-16

> 지시서: `docs/f56-api-whoami-directive.md`. 근거: PRD F-56 · 8장 `/api/whoami` 행 · 12.2 T-68 · S-01, ARCH 4.4, SECURITY R-82·R-83·R-89(새).
> 배경: 사용자 요청(강사 4차 프롬프트 형식) — 「X-API-Token 하나, 세션 쿠키 안 받음, 200 `{username,role,team_id,upload_levels}`, upload_levels 는 `/api/me` 와 같은 계산 재사용(중복 구현 금지), 401·503 문구는 7장과 동일, csrf_token·can_* 넣지 않음. API.md 0장 「셋뿐」 도 고치고, PRD 8장 행을 먼저 추가하고 구현(추가 규칙 4)」. 목적: 사용자 프로그램이 `admin-only` 를 미확인 기본값이 아니라 계정 권한으로 잡는다. 뒤에 사용자별 토큰(`api_tokens`)으로 가도 같은 응답이 「토큰을 쓴 진짜 사람」을 가리켜 클라이언트 불변.

**개발 사항** (구현 Opus / 문서 Fable)
- [x] (Fable) PRD 8장 행·13장 F-56·T-68, ARCH 4.4, SECURITY R-89, 지시서 — **문서 먼저(추가 규칙 4)**
- [x] (Opus) `public/index.php` — 라우트 `GET /api/whoami`(정책 `authenticated`) · `TBOX_API_TOKEN_ROUTES` **와** `TBOX_API_TOKEN_ONLY_ROUTES` 에 추가(토큰 전용) · 「셋뿐」 주석 → 넷
- [x] (Opus) `src/handlers/api_whoami.php` — `$user` 의 넷 + `Authz::uploadableLevels($user)` 재사용 — 실행문 `Http::json([...])` 하나
- [x] (Opus) `API.md` 10장(응답 원문은 Fable 실측) · 0장 인증 줄 「셋뿐」→「넷」 · 9장 끝 인용 · `tests/api_whoami.sh`(T-68)
- [x] 손대지 않음(확인): `Auth.php`·`Http.php`·`Audit.php`·`Authz.php`·`me.php`·`db/*`·`.env`

**검토 체크** (Fable, 관문 ②)
- [x] 코드 읽기: 핸들러가 `$user` 넷 + `uploadableLevels` 만 · 상수 둘에 들어갔나 · 세션 쿠키만으로 401 인가 — **Fable 검토(09-16)** diff 읽기: 라우트 1행·상수 둘·주석 넷 갱신, 핸들러 4키 + `uploadableLevels` 단일 호출, 헤더·env 재확인 없음
- [x] `tests/api_whoami.sh` — 귀속을 `qa_employee01` 로 회전 → (a)~(e) 실측 → `admin01` 복귀(토큰 값 미출력). 사용자 프로그램은 그 사이 401 → 회전 뒤 재export 필요(F-50 학습 기록과 같다) → **22/0/0**(귀속 qa_employee01: (a) 200·키 넷·순서·username (b) `upload_levels` == `/api/me` `["public","internal","team"]` (c) 401 셋 (d) deny 셋·성공 감사 0 (e) `/api/me` 키 불변·`/api/users` 401·lint 0) → `admin01` 복귀 확인
- [x] `API.md` 10장 응답 원문을 실측으로 채움 · `security_lint.sh` 0 · `php -l`
- [x] 관문 ④ 한글 커밋 `dedd3ca` → push → `release.sh minor` → **v1.7.0**(`7a2805a`, 태그 push)

**자기 공격 체크** (사용자, 관문 ③)
- [x] (사용자 2026-09-16 14:29) 프로그램이 `/api/whoami` 로 계정 권한을 읽어 「등급 admin-only · 계정 권한(admin01)」 으로 업로드 201 → 검색 → 다운로드까지 내용 일치(asdfasdf, 8바이트) — 「전 구간 확인됐습니다」
- [ ] 헤더 없이 → 401 「X-API-Token 헤더가 없습니다」 · 브라우저(세션)로 `/api/whoami` 열어도 같은 401
- [x] (사용자 14:29 로그) 「업로드 시도: … (등급 admin-only · 계정 권한(admin01) · 1번째)」 — 미확인 기본값이 아니라 계정 권한으로 잡힘

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-16 | 요청(사용자) | 위 배경 인용문 그대로. 「끝나면 curl 로 확인하는 명령 한 줄」 | 지시서 · API.md 10장 |
| 2026-09-16 | 판단(Fable) | 토큰 **전용**(ONLY 집합)으로 — 화면이 쓰지 않는 창구라 `/api/upload` 와 같은 갈래. 「세션 쿠키는 받지 않는다」 를 코드로 강제 | ARCH 4.4. 세션으로 부르면 401 `missing_header`(로그인 상태여도) |
| 2026-09-16 | 판단(Fable) | 새 위험은 R-89(낮음)로 — 데이터 노출 범위는 R-83 그대로, 달라지는 것은 계정 정보가 응답 하나로 확정되는 것 | SECURITY R-89 |
| 2026-09-16 | 판단(Opus, 승인) | 지시서의 `Http::json(200, […])` 는 실제 시그니처(`json(array, int=200)`)와 인자 순서가 반대 → `Http::json([...])` 로. ONLY 집합 머리 주석 「창구 (F-49)」→「둘 (F-49·F-56)」 추가 수정. 10장 「세션 쿠키만 401」 은 Opus 가 직접 실측해 채움 | 지시서 오기, 코드가 맞다. 셋 다 승인 |
| 2026-09-16 | 알림(Fable) | 검토를 위해 토큰 귀속을 `qa_employee01` 로 회전 → 복귀(`admin01`). **토큰 값이 바뀌었다** — 사용자 프로그램(tbox-buddy/2.0, 12:56 마지막 요청)은 `.env` 의 새 `API_TOKEN` 을 다시 넣어야 한다 | F-50 학습 기록과 같은 절차. `cat /var/www/tbox-v2/.env` |
| 2026-09-16 | 관찰(사용자, 14:29) | 「투입 → 1초 안정 대기 → 업로드 → 완료 이동 → 큐 비움까지 2초. 등급도 계정 권한대로 admin-only」. 발견: 업로드 직후 다운로드가 `403 검사 중입니다…`(잠시 뒤 200) — 9장에 없는 응답, 클라이언트는 403 을 전부 영구 실패로 처리해 실패. 문구로 구분은 취약하니 409/423 + Retry-After 검토 요청, 검사 소요도 알려 달라 | → **F-57** 로 진행(423 + Retry-After 30, 이유는 PRD F-57 행). 검사 소요 실측 21~76초·중앙값 25초 |


### F-57 — 「검사 중」은 423 + `Retry-After` (일시 상태와 영구 거부를 상태 코드로) — 착수 2026-09-16

> 지시서: `docs/f57-scan-pending-423-directive.md`. 근거: PRD F-57 · 8장 download·preview·raw·`/s` 행 · 12.2 T-69 · S-06, ARCH 5.28, SECURITY R-86.
> 배경: 사용자 프로그램 실측 보고(위 F-56 학습 기록 14:29 행). 사용자 요청 「① 9장 표의 403 줄에 이 상태를 적어라 ② 상태 코드를 나누는 게 맞는지 검토 — 409 나 423 + Retry-After 가 맞으면 그렇게, 403 유지가 맞으면 이유를. 어느 쪽이든 9장에. 검사가 보통 몇 초 걸리는지도」.

**개발 사항** (구현 Opus / 문서 Fable)
- [x] (Fable) 결정 **423 Locked + `Retry-After: 30`** (pending) / 403 유지 (infected·권한). 이유: 403 은 영구라 재시도 신호 없음 · 409 는 쓰기 충돌 관례 · 503 은 장애로 읽혀 헬스체크 오염 · 202 는 빈 본문 GET 위험 · 423 은 「잠김, 풀린다」 그대로이고 겹치는 용례 없음. PRD 네 행·F-57·T-69, ARCH 5.28, SECURITY R-86, 지시서
- [x] (Fable) 검사 소요 실측(감사 로그 최근 3일, 업로드→`file.scan` 5건): **21 ~ 76초, 중앙값 25초, p90 67초** — 배치 매분 + `clamscan` ≈22초. 권장 대기 30초 간격 최대 4번
- [x] (Opus) `src/Http.php` — `locked()` + `SCAN_RETRY_AFTER_SECONDS=30` · 네 핸들러 한 줄 분기 · `API.md` 9장·7장 · `tests/f54.sh` (a) 423 — `tests/f54.sh` 61/0/2(기존 SKIP 2)
- [x] 손대지 않음(확인): `Audit.php`(사유 `scan_pending` 문장 불변)·`index.php`·`app.js`·`scan_pending.php`

**검토 체크** (Fable, 관문 ②)
- [x] 코드 읽기: 넷이 같은 두 줄인가 · `Retry-After` 헤더가 본문 앞에 나가나 · infected 경로 무변경 — **Fable 검토(09-16)**: `locked()` 은 `Retry-After` 를 `json()` 앞에, 네 핸들러 블록 글자까지 동일, `Audit::deny` 무변경
- [x] `tests/f54.sh` 재실행(Opus 출력 대조) + 추가 실측: 미리보기·raw·공유 링크 423 헤더, 격리·권한 403 에 `Retry-After` 없음 → **추가 실측(scratchpad f57_check.sh, qa_employee01)**: 업로드 직후 다운로드·미리보기·raw·공유 링크 **넷 다 423 + `Retry-After: 30` + `retry_after`**, admin-only 파일 403 에 `Retry-After` 없음, 배치 뒤(70초) 200, 시험 파일 휴지통·링크 회수
- [x] `API.md` 9장 실측 응답 채움 · lint 0 · `php -l`
- [x] 관문 ④ 한글 커밋 `038e562` → push → `release.sh minor` → **v1.8.0**(`f7c0931`, 태그 push)

**자기 공격 체크** (사용자, 관문 ③)
- [ ] 프로그램으로 올린 직후 다운로드 → 423 + `Retry-After: 30` 을 보고 30초 뒤 재시도 → 200. 클라이언트의 「문구에 검사 포함 → 재시도」 분기를 「상태 423 → `Retry-After` 초 대기」 로 바꾸고 문구 의존 제거
- [ ] 브라우저에서 올린 직후 미리보기 → 카드에 「검사 중입니다. 잠시 뒤 다시 시도하세요」 그대로 보이는지(화면 회귀)

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-16 | 요청(사용자) | 위 배경 인용 그대로 | PRD F-57 행 · API.md 9장 |
| 2026-09-16 | 판단(Fable) | 423 을 고른 이유와 다른 코드를 버린 이유 | PRD F-57 행 「이유」 칸. 문구가 아니라 **상태 코드 + `Retry-After` 헤더**가 계약이다 |
| 2026-09-16 | 판단(Fable) | 클라이언트가 고친 「2초 간격 최대 8번(16초)」 은 실측 소요(중앙값 25초, 최대 76초)보다 짧다 | `Retry-After: 30` 을 따르면 30초 × 최대 4번(2분)이 맞다 |
| 2026-09-16 | 정정(Opus, 승인) | PRD F-57 「423 을 다른 데서 쓰지 않는다」 는 사실과 다름 — `login.php` 가 계정 10분 잠금에 이미 423 | 「같은 뜻(잠김·풀림)이라 어긋나지 않는다」 로 PRD·API.md 정정. f54.sh (a) 다운로드 요청을 2번→1번(`curl -D`)으로 — 검사 창 경합 방지 |
| 2026-09-16 | 궁금(사용자) | 「어제 배운 내용은 서버와 로컬의 연동이잖아. n8n 같은 서비스를 쓸 필요가 없네? 오히려 보안 우려만 커지는 거네?」 | **맞다(이 문제에는).** 한 홉(PC 프로그램 ↔ 내 서버)·인증 하나·창구 넷·재시도 규칙(423)으로 이미 완결. n8n 의 가치는 여러 외부 SaaS 를 코드 없이 잇는 것. 끼우면 ① 토큰·파일이 제3자(클라우드) 경유 ② 셀프 호스팅은 지킬 서버 하나 더(서버 층 미해결 숙제 복제, 자격증명 저장소라 뚫리면 전부) ③ 감사 로그의 actor·User-Agent 가 전부 n8n 으로 뭉개짐 ④ 검토 범위 밖 실행 엔진. 맞는 경우: 여러 외부 서비스로 뻗고 비개발자가 흐름을 고쳐야 할 때 — 그때도 최소 역할 토큰(R-83)·사용자별 토큰 뒤에 | ARCH 4.2~4.4 · SECURITY R-82·R-83·R-89 · 09-15 리뷰 서버 층 미해결 3건 |


### F-58 — 프로그램용 폴더 관리 (토큰으로 폴더 목록·생성·이름 변경·이동, 파일 이동) — 착수 2026-09-16

> 지시서: `docs/f58-api-folders-directive.md`. 근거: PRD F-58 · 8장 `/api/folders` 3행·`PATCH /api/files/{id}` 행 · 12.2 T-70 · S-01·S-02·R-07, ARCH 4.5, SECURITY R-90, API.md 0장·11장·12장.
> 배경: 사용자 「로컬 컴퓨터에 AI 비서를 만들었잖아. Ctrl+Shift+Space 로 파일을 다운로드하도록. 이건 창을 띄우는 명령어로 두고, 파일 다운로드·업로드·삭제 등 폴더를 관리할 수 있는 방향으로 확대 가능한 거 아니야?」 → Fable 「가능. 업로드는 서버 준비됨(7장), 폴더·삭제는 토큰 창구 밖」 → 「폴더 목록 생성 이동은 왜 안됨?」 → 「`TBOX_API_TOKEN_ROUTES` 허용 목록에 없어 헤더를 읽지도 않는다(의도된 설계, R-82)」 → **사용자 「해줘. 이것들이 되어야 로컬 PC 에서 확장성을 가질 수 있다」**.

**개발 사항** (구현 Opus / 문서 Fable)
- [x] (Fable) 결정: 토큰에 여는 것은 **목록·생성·이름 변경·이동·파일 이동**. 등급·팀 변경·`cascade`·삭제는 닫힘 — 등급 넓히기는 Admin 재인증 관문 대상인데 토큰 경로는 그 관문을 지나지 않으므로(ARCH 4.2) 사람의 절차가 붙은 결정을 프로그램 창구로 우회시키지 않는다. 지우기는 사용자별 토큰(설계 B) 결정 뒤 별건
- [x] (Fable) 결정: 응답·오류 문구는 세션과 **동일**(F-50 처럼 투영을 가르지 않는다 — 폴더 트리 응답에는 이미 정찰 재료가 없다). 유일한 새 코드는 두 PATCH 핸들러의 `$allowedFields` 한 줄 분기
- [x] (Fable) 문서 먼저: PRD 8장 네 행·F-58·T-70, ARCH 4.5, SECURITY R-90, API.md 0장·11장·12장(실측 자리 비움), 지시서
- [x] (Opus) `index.php` 상수 넉 줄 + 주석 · `folders_update.php`·`files_update.php` 허용 키 한 줄 · `tests/api_folders.sh`(572줄, T-70 (a)~(g), 정리 trap) — 1차 보고 19/0/5(귀속 admin01 이라 토큰 항목 SKIP)
- [x] 손대지 않음(확인, `git status`): `Auth`·`Http`·`Audit`·`Authz`·`folders_list`·`folders_create`·`files_list`·`app.js`

**검토 체크** (Fable, 관문 ②)
- [x] 코드 읽기: 상수 넉 줄이 라우트 패턴과 글자까지 같은가 · `TBOX_API_TOKEN_ONLY_ROUTES` 무변경 · 두 핸들러 diff 가 `$allowedFields` 한 줄 + 주석뿐인가 — **Fable 검토(09-16 17:00)** 셋 다 확인. Opus 가 지시 범위 밖이라 남긴 `[2-T]` 주석 두 곳(F-58·4.5 언급)은 Fable 이 보정(주석만)
- [x] `tests/api_folders.sh` 실행 — 귀속을 `qa_employee01` 로 잠시(**토큰 값은 그대로, `API_TOKEN_USER` 줄만** — 사용자가 17:05 직접 실행) → 1차 **53/1/0**(실패 1 = 시험 (f) 의 「새 조합」 기준이 DB 이력이라 문장표에 있는 세 조합을 오판 → 재지시 1회) → 2차 **54/0/0**(17:22). 1차 시작은 R-73 관문에 걸려 10분 대기(로컬 프로그램 `tbox-buddy/3.0` 이 admin01 토큰으로 두드린 흔적) → `admin01` 복귀는 사용자 실행 대기
- [x] `API.md` 11·12장 실측 응답 채움(1차 실행 로그 원문) · lint 0 · `php -l` 3 파일
- [x] 관문 ④ 한글 커밋 `0132dfd` → push → `release.sh minor` → **v1.9.0**(`6d1ced5`, 태그 push)

**자기 공격 체크** (사용자, 관문 ③)
- [ ] 진짜 토큰(admin01 귀속)으로 `GET /api/folders` → 트리가 화면과 같은가
- [ ] 토큰으로 `PATCH /api/folders/{num} {"access_level":"public"}` → **400** 인가(등급 변경은 닫힘)
- [ ] 토큰으로 `DELETE /api/folders/{num}` → 401 「로그인이 필요합니다」인가(삭제는 닫힘)
- [ ] 로컬 프로그램: 창에서 폴더 트리 → 파일 목록 → Enter 다운로드, 드래그로 업로드(7장) + 폴더 지정(`folder_id`)

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-16 | 궁금(사용자) | 「Ctrl+Shift+Space 다운로드 창을 파일 업로드·삭제·폴더 관리로 확대 가능한 거 아니야?」 | 가능. 서버 준비 상태가 기능별로 다름 — 업로드는 7장으로 이미 열림(클라이언트만), 폴더·삭제는 토큰 창구 밖(API.md 0장 표). 삭제는 공용 토큰 유출 시 피해 때문에 사용자별 토큰 결정이 먼저(SECURITY R-82) |
| 2026-09-16 | 궁금(사용자) | 「폴더 목록 생성 이동은 왜 안됨?」 | 기술 문제가 아니라 **허용 목록** — `public/index.php` `TBOX_API_TOKEN_ROUTES` 에 없는 경로는 헤더를 읽는 코드에 닿지 않는다(ARCH 4.2 「토큰은 이 라우트 밖에서 아무것도 열지 못한다」). 검토 지점을 한 곳에 모은 설계. 여는 것은 넉 줄 + 위험 항목 재검토(R-90) |
| 2026-09-16 | 결정(사용자) | 「해줘 — 이것들이 되어야 로컬 PC 에서 확장성을 가질 수 있다」 | F-58 착수. 이동까지 열되 등급 변경·삭제는 닫힘(Fable 제안, 사용자 이견 없음) |
| 2026-09-16 | 판단(Fable, 검토 절차) | F-56 검토 때 `api_token_rotate.sh qa_employee01` 로 귀속을 돌리면 **토큰 값도 바뀌어** 사용자 프로그램을 매번 재설정해야 했다 | 이번부터 검토 중 귀속 전환은 **`API_TOKEN_USER=` 줄만** 바꾸고(같은 덮어쓰기 방식 `> .env`, 0640 유지) 토큰 값은 그대로 둔다 → 복귀 뒤 사용자 프로그램 재설정 불필요. 전환 중 몇 분간 그 토큰은 `qa_employee01` 로 동작한다(1장 보안 점검 방식에 기록) |
| 2026-09-16 | 궁금(사용자) | 「저 안전장치(최근 10분 사용자 활동 시 시험 시작 안 함)를 내가 임의로 해제할 수 없어? 지금은 내가 하다가 내 규칙에 걸린 거 아니야?」 | 맞다 — SECURITY R-73(2026-09-13 시험이 운영 표를 지운 사고)에서 나온 사용자 규칙이고 해제 권한도 사용자에게 있다. 지금 스크립트에는 우회 플래그가 없다(판단이 필요한 것이라 스크립트가 알아서 넘기지 않게). 길 셋: ① 10분 기다림(이번 선택) ② 우회 변수(`TBOX_REVIEW_FORCE=1`, 사용자가 직접 지정했을 때만·R-73 예외 문서화) ③ 사용자가 `!` 로 직접 실행. 이번 건은 로컬 프로그램의 admin01 토큰 요청이 「사용자 활동」으로 잡힌 것 — 설계대로 작동 |
| 2026-09-16 | 어려움(로컬 클로드) | 로컬 프로그램이 `GET /api/folders?parent_id=`·`GET /api/files?folder_id=` 로 불러 「둘 다 무시되고 루트만 온다」 | 조회는 쿼리스트링 `parent`·`folder`(접미 없음), 변경은 JSON 본문 `parent_id`·`folder_id`(접미 있음) — API.md 11-1·8장. 붙여 넣을 수 있는 파라미터 표를 전달 |
| 2026-09-16 | 궁금(로컬 클로드, 사용자 전달) | ① 파일 이름 변경이 토큰으로 되나 ② 폴더 삭제의 `cascade` 키·비어 있지 않은 폴더에 DELETE 하면 ③ 휴지통 되살리기 창구가 있나 | ① **안 된다 — 서버에 파일 이름 변경 자체가 없다.** `PATCH /api/files/{id}` 허용 키는 세션도 `description`·`access_level`·`team_id`·`folder_id` 넷(`name` 없음), 토큰은 `folder_id` 만. F-58 의 「이름 변경」은 **폴더**만이다(API.md 11-3·12장). 클라이언트의 `rename_file` 은 플래그를 열어도 400. ② **`cascade` 는 삭제 키가 아니다** — `PATCH /api/folders/{num}` 의 「등급 좁히기를 하위에도 적용」 키다(ARCH 5.16 ②). 재귀 삭제는 서버에 없다. `DELETE /api/folders/{num}` 은 세 갈래 400: 하위 폴더 있음 「하위 폴더가 있습니다 — 먼저 지우세요」 · 살아 있는 파일 있음 「파일이 있는 폴더는 지울 수 없습니다」 · 휴지통 파일만 남음 「휴지통에 있는 파일만 남았습니다 — …」 + `?move_trashed=1` 로 확인(PRD F-41, ARCH 5.16 「삭제」). 409 는 이름 중복에만 쓴다. 비어 있지 않은 폴더가 통째로 지워지는 경로는 없다 — 클라이언트의 `item_count` 사전 차단은 이중 안전. ③ **있다, 세션·Admin 전용** — `GET /api/files/trash`·`POST /api/files/{id}/restore`·화면 `/trash`(index.php 127~135행). 토큰으로는 닫힘. 파일 삭제는 30일 휴지통(되살릴 수 있음), 폴더 삭제는 행 삭제(빈 폴더만이라 잃는 것 없음). 또 토큰 DELETE 의 401 은 「로그인이 필요합니다」(헤더 무시), 만료·틀린 토큰의 401 은 「API 토큰이 올바르지 않습니다」 — 문구가 다르다 |


### F-59 — 사용자별 프로그램 토큰(설계 B) + 개인 토큰으로 삭제 — 착수 2026-09-16

> 지시서: `docs/f59-personal-tokens-directive.md`. 근거: PRD F-59 · 8장 다섯 행 · 7.11 AL-20 · 12.2 T-71 · S-01·S-07·S-09-11, ARCH 4.6·6.2-5·7.4·7.5, SECURITY R-82(갱신)·R-91, API.md 0·13·14장.
> 배경: F-58 검토 보고 ④ 「삭제까지 열려면 사용자별 토큰(설계 B) 결정이 먼저」 → 로컬 클로드도 같은 결론(「서버가 사람 세션과 도구 토큰을 일부러 나눠 놨다」) → **사용자 「진행해」**(2026-09-16).

**개발 사항** (구현 Opus / 문서 Fable)
- [x] (Fable) 결정: 발급·회수는 **관리자 세션**(재인증·사유 필수·AL-20), 프로그램 창구가 아님 · 인증은 개인 토큰 먼저, 공용 토큰은 이행용 잔존(`.env` 두 줄을 지우면 닫힘) · 삭제 둘은 **개인 토큰만**(공용은 403) · 등급 변경·휴지통 복원은 여전히 닫힘 · whoami 키 넷 불변
- [x] (Fable) 문서 먼저: PRD 8장·7.11·F-59·T-71, ARCH 4.6·6.2-5·7.4·7.5, SECURITY R-82·R-91, API.md 0·13·14장, 지시서
- [x] (Opus) 마이그레이션·schema·grants·install 감지 / `Auth::requireApiToken($personalOnly)` / `Audit` 3키·2자동키·9문장 / 핸들러 셋 / 라우트·상수 / 모달 「프로그램 토큰」 구역(app.js 347줄·css 19줄) / AL-20 / `.env.example`·rotate 안내 / `tests/api_tokens.sh`(691줄) — Auth·index 를 마지막에 저장하고 즉시 보고(토큰 요청 500 창 최소화)
- [x] (Fable, 배포 18:09) 마이그레이션 적용 → `\dp api_tokens` = `tbox_app=arw`·`tbox_backup=r`(DELETE 없음). 적용 전 약 1분간 토큰 요청이 500(`relation api_tokens does not exist`, app.log 18:09:06) — 적용 뒤 401 정상

**검토 체크** (Fable, 관문 ②)
- [x] **화면 실측(09-17 10:43, Playwright·qa_admin01)**: 자기 행 「프로그램 토큰」 버튼 → 다이얼로그 → 발급 48자·hint 일치·본문 텍스트에 토큰 0회·회수 → 회수됨·닫기 → 값 비움. 스크린샷은 (회수된) 토큰이 보여 검토 뒤 삭제
- [x] 코드 읽기 — **Fable 검토(09-16)**: 순서 동일 · `hash_equals`·sha256 · 원문은 발급 201 한 줄뿐(목록 SELECT 에 `token_hash` 없음, 감사 detail 3키) · 개인 전용 판정은 `[2-T]` 인자 + `Auth` ⑤ 한 곳 · 삭제 핸들러 무수정 · app.js innerHTML 0
- [x] `tests/api_tokens.sh` 1차 27/6/8(시험 결함: 재인증 전 `/api/users`·서브셸 유실) → 2차 66/4/3(시험 결함: `public_id`·`code` 키·폴더 403·grep -c) → 3차 **70/0/3**. 유실로 남은 시험 토큰 5개 Fable 이 psql 로 회수. **(d) 공용 토큰 게이트 — 2026-09-17 10:05 실측 통과**(사용자 결정으로 admin01 귀속 공용 토큰 그대로, 없는 UUID·번호 대상): DELETE 둘 403 「이 창구는 개인 토큰으로만…」 + 감사 `shared_token_not_permitted`×2 `token_kind=shared`, 읽기 200 불변
- [x] 회귀: `api_whoami.sh` 15/0 · `api_folders.sh` 23/0 · `api_read.sh` 25/0(둘은 옛 「DELETE·PATCH 헤더 무시」 기대를 현행으로 갱신 — 설계 변경 반영) · AL-20 dry-run 1명 · lint 0 · `php -l` 8
- [x] API.md 13·14장 실측 채움(토큰 원문 미기록) · 관문 ④ 커밋 `79e0d10` → push → `release.sh minor` → **v1.10.0**(`c70fa76`, 태그 push)

**자기 공격 체크** (사용자, 관문 ③)
- [ ] 관리 → 계정 관리(`/users`) → 자기 행의 「프로그램 토큰」 버튼 → 발급(이름·사유) → 토큰을 PC 프로그램에 심고 `GET /api/whoami` → `username` 이 자기 계정
- [ ] 그 토큰으로 자기 파일 `DELETE /api/files/{id}` → 200, 화면 휴지통에 보임 · 감사 로그에 `token_id`
- [ ] 회수 → 같은 요청 401 · 공용 토큰으로 DELETE → 403 「이 창구는 개인 토큰으로만…」
- [ ] 이행: PC 프로그램이 개인 토큰으로 안정되면 서버 `.env` 의 `API_TOKEN`·`API_TOKEN_USER` 두 줄 삭제 → 공용 토큰 401 확인(R-82 ✅)

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-16 | 결정(사용자) | 「진행해」 — 설계 B(사용자별 토큰) + 삭제 개방 | F-59 착수. 삭제는 개인 토큰만(Fable 제안) |
| 2026-09-16 | 판단(Fable) | 발급을 프로그램 창구(토큰)로 열지 않고 관리자 세션에만 둔 이유 | 토큰이 토큰을 낳으면 유출 한 번이 영구 자격이 된다. 발급은 사람의 결정(재인증·사유·AL-20) — F-43 허용 사이트와 같은 급의 조건 |
| 2026-09-16 | 판단(Fable) | 공용 토큰을 즉시 끊지 않고 이행용으로 남긴 이유 | 사용자 PC 프로그램이 지금 공용 토큰으로 돌고 있다. 개인 토큰을 심을 시간을 주되 **삭제는 공용으로 못 하게** 막아 새 표면이 열리지 않게 했다. 닫는 것은 `.env` 두 줄 삭제 — 사용자 몫(관문 ③) |
| 2026-09-16 | 어려움(Opus 시험, 3회) | bash 시험에서 `code=$(issue_token …)`·`uid_of` 처럼 **전역을 세우는 함수를 명령 치환 안에서** 부르면 서브셸이라 값이 부모로 올라오지 않는다 → 발급은 201 인데 시험은 「토큰 없음」, 정리도 건너뛰어 토큰 5개 잔존 | 전역을 세우는 함수는 치환 없이 부르고 결과는 전역 변수로. 정리는 목록이 아니라 **상태**(이름 `F59-`·`revoked_at IS NULL`)로 훑는다. `tests/api_tokens.sh` 머리 주석·`sweep_tokens` |
| 2026-09-16 | 판단(Fable) | 옛 회귀 시험이 「DELETE·PATCH 에 토큰 헤더 → 헤더 무시 401」을 기대해 실패 | F-58·F-59 로 그 라우트가 토큰 집합에 들어간 **의도된 변경**. 시험을 현행에 맞추되 「헤더 무시」 확인은 여전히 닫힌 `GET /api/users`·발급 창구로 유지. 삭제·수정 닫힘 확인에는 진짜 토큰을 쓰지 않는다(지워질 수 있다) |
| 2026-09-17 | 궁금(로컬 클로드, 사용자 전달) | ① `code` 값 목록(문구 대신 코드로 갈라 처리) ② 폴더 삭제도 휴지통인가 ③ 휴지통 복구 창구가 있나 | ① `code` 키는 **폴더 삭제 400 셋뿐**: `folder_has_children`·`folder_not_empty`·`folder_has_trashed`(`folders_delete.php` 90·205행). 다른 창구의 4xx 는 `{"error"}` 만이고 사유는 감사 로그에만(정책 S-09-5) — 프로그램은 **상태 코드**(400/401/403/404/409/423)와 이 셋으로 가른다. 401 두 문구(「로그인이 필요합니다」=집합 밖·헤더 없음 / 「API 토큰이 올바르지 않습니다」=틀림·회수)와 403 두 문구(「권한이 없습니다」 / 「이 창구는 개인 토큰으로만…」)는 문구로만 갈린다 — 필요하면 `code` 를 더 주는 것은 별건. ② **아니다** — 폴더 삭제는 행 삭제(빈 폴더만 지우므로 잃는 것이 없다, ARCH 5.16 「삭제」). 휴지통은 파일만(30일). ③ 있다, **세션·Admin 전용**: `GET /api/files/trash`·`POST /api/files/{id}/restore`·화면 `/trash`. 토큰 창구가 아니라 프로그램 `>휴지통` 명령은 브라우저 `/trash` 열기로 대신한다. 파일 이름 변경은 서버에 없음(확정) — 플래그를 끄는 게 아니라 기능을 빼는 것이 맞다 |
| 2026-09-17 | 결정(사용자) | 「그냥 admin01 로 해」 — (d) 공용 토큰 게이트 실측에 귀속 전환 대신 운영 귀속(`admin01`) 공용 토큰을 그대로 쓴다 | CLAUDE.md 3장 「시드 계정으로 검토 요청을 보내지 않는다」의 **사용자 예외**. 피해 없음을 보장하는 조건으로 실행: 대상은 없는 UUID·번호(게이트가 실패해도 404), `-A tbox-review` 표식, 토큰 값 미출력. 감사 로그에는 actor `admin01` + `user_agent tbox-review/2026-09-17` 로 남아 사용자의 행동과 구분된다 |
| 2026-09-17 | 어려움(사용자) | 「admin01 은 역할·팀·만료 (버튼)가 없어」 — 자기 토큰을 발급할 자리가 없음 | **지시서 결함**(Fable): 토큰 구역을 계정 편집 모달 안에 두었는데 그 모달은 자기 행(`is_self`)에 열리지 않는다(`app.js addActions` — 서버 `self_change` 규칙과 같은 화면 규칙). 서버는 자기 대상 발급을 허용하므로 화면만 문제. 보정: 모든 행(자기 포함)에 「프로그램 토큰」 버튼 → 토큰 전용 창. PRD F-59 ②·ARCH 4.6·API.md 13장 문구 정정 |


### (제거됨 2026-09-21 → 27단계) F-60 — 발주요청 불러오기: 전표 단위 담기 · 저장 시 거래처별 분할 — 착수 2026-09-17

> 지시서: `docs/purchase/20-f60-po-split-directive.md`. 근거: PRD F-60 · F-32 · 12.2 T-72 · S-01, ARCH 5.18 「연결」·「분할 저장」, SECURITY R-65·R-73, 시안 ④ ImportModal, `docs/purchase/05-stage20-directive.md`.
> 배경: 사용자 「발주서 작성 시 발주요청 불러오기하는데 전표 단위로 체크하여 발주 항목에 채울 수 있도록 개선해야 해. 또한 이렇게 가져온 항들은 거래처가 다 달라서 상이할 수 있는데 저장 시에는 묶는 기준에 의해 전표가 나뉘어야 함. 그리고 발주서 만들기 버튼을 누르면 잘못된 요청이라고 뜬다」.

**개발 사항** (구현 Opus / 문서 Fable)
- [x] (Fable) 원인: 감사 로그 `purchase.order.create deny mixed_vendor` 4건(09-17 12:37~12:39, admin01) — 발주서 한 장 = 거래처 하나 규칙(20단계)에 여러 거래처 줄. 화면은 400 통일 문구만 보여 사유가 안 보였다
- [x] (Fable) 결정: 규칙은 유지하고 **저장 핸들러가 거래처별로 나눠 N 장**(한 트랜잭션, `split:true` 명시 — API 계약 불변) · 불러오기 묶는 기준에 `doc` 추가 · 트리 **모든 묶음 노드에 체크박스**(전표 단위는 그 특수형) · 저장 전 분할 미리보기. 나누는 기준은 **거래처**(발주서의 정의). 납품창고·담당·일자·메모는 머리값 하나를 N 장이 공유
- [x] (Fable) 문서 먼저: PRD F-60·T-72, ARCH 5.18 「분할 저장」, 지시서
- [x] (Opus) `purchase_pr_load.php`(`doc` 그룹) · `purchase_po_create.php`(`split`) · `Audit` `split_count` · `Doc::vendorName`(표시용 공개 함수 1) · `purchase_docs.js`·`purchase_po.php`·`purchase.css` · `tests/purchase_f60.sh`(T-72, Playwright 포함) — 자체 실행 99/0

**검토 체크** (Fable, 관문 ②)
- [x] 코드 읽기 — **Fable 검토(09-17)**: 반복 전 한 번 · 반복부는 채번·insert·retouch·header 만 · `split` 없으면 옛 경로(응답·감사 불변, (c) 실측) · 어느 묶음 거부든 rollback+reject
- [x] `tests/purchase_f60.sh` **99/0**(Fable 재실행, 화면 (f) 포함, 스크린샷 2장 확인) · `stage20.sh` 88/2 — 실패 2는 절대 채번 단언(사용자의 오늘 PR 때문, 코드 무관) · lint 0 · `php -l` 5
- [x] 관문 ④ 커밋 `40ef933` → push → `release.sh minor` → **v1.11.0**(`3ffa6c5`)

**자기 공격 체크** (사용자, 관문 ③)
- [ ] 발주서 입력 → 불러오기 → 「전표」 칩 → 전표 하나 체크 → 그 줄 전부 담김 → 다른 거래처 전표도 체크 → 담기 → 「거래처별 N장」 미리보기 → 발주서 만들기 → 전표 N개 생성, 400 없음
- [ ] 같은 줄을 다시 담으려 하면 목록에 없음(잔량 0)

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-17 | 어려움(사용자) | 「발주서 만들기 버튼을 누르면 잘못된 요청이라고 뜬다」 | 감사 로그 `purchase.order.create deny mixed_vendor`. 발주서 = 거래처 하나(20단계 규칙)인데 불러온 줄의 거래처가 섞였다. 확인 명령: `SELECT … FROM audit_logs WHERE action LIKE 'purchase.%' AND result='deny' ORDER BY id DESC` |
| 2026-09-17 | 요청(사용자) | 전표 단위 체크 · 저장 시 묶는 기준으로 전표 분할 | PRD F-60 ①~④. 「묶는 기준」은 발주서의 정의상 **거래처**로 확정 — 납품창고 등은 머리값 하나 공유(달라야 하면 별건) |
| 2026-09-17 | 판단(Fable) | `tests/purchase_stage20.sh` 의 채번 단언이 `PR-<오늘>-1` 같은 **절대 번호**라 사용자가 그날 전표를 하나라도 만들면 코드와 무관하게 실패한다(R-78 정리는 카운터를 지우지 않으므로) | 시험 결함이지 코드 결함이 아니다. 단언을 「시작 전 last_no + 1」 상대값으로 바꾸는 보정은 별건(Q 후보) — 이번 지시서는 그 파일 수정을 금지했다 |
| 2026-09-17 | 판단(Fable, 승인) | Opus 가 `split_count` 를 `split:true` 저장에만 붙임(지시서 ⑤ 「발주서마다」와 금지 패턴 「`split` 없는 요청의 감사 변경 금지」가 충돌 → 금지 쪽 우선) | 맞는 해석. ARCH 7.5 행 문구를 그에 맞게 정정 |


### (제거됨 2026-09-21 → 27단계) F-61 — 발주서 화면 완성: 현황 탭 · 입고 장소 자동 · 세 갈래 입력 — 착수 2026-09-17

> 지시서: `docs/purchase/21-f61-po-complete-directive.md`. 근거: PRD F-61 · F-60 · F-36 · F-34 R6 · 12.2 T-73 · S-01, ARCH 5.18 「분할 저장」·「발주서 세 갈래·장소 규칙」, SECURITY R-65·R-66·R-73, 시안 ⑤⑥⑩.
> 배경: 사용자 「발주서는 왜 현황이 없냐. 발주요청에서 가져올 때 창고명도 똑같이 들어가야 해 — 지금은 창고를 별도 입력해야만 전표가 만들어지네. 발주서의 사이클은 3가지: 직접 입력, 발주요청서에서 끌어오기, 인가량 대비 부족 수량 산출(미입고 고려 — 인가 10, 재고 5, 미입고 3 = 발주 2)」.

**개발 사항** (구현 Opus / 문서 Fable)
- [x] (Fable) 현황: 발주서 화면만 2탭이었다(20단계 시안 ⑤ 축소). 현황 = 미입고 현황으로 정하고 입고 화면과 같은 API·렌더러 재사용(새 조회 0)
- [x] (Fable) 장소: F-60 의 분할 열쇠를 (거래처, 입고 장소) 로 확장, `split` 모드에서 머리 장소 선택·명시 우선. 옛 계약 불변
- [x] (Fable) 세 갈래: 직접 줄은 `item_id`(src NULL), 부족 산출은 기존 `GET /api/purchase/suggest`(F-36, 미입고 포함 공식) 를 화면이 직접 줄로 담는다 — 서버 새 창구 0
- [x] (Fable) 문서 먼저: PRD F-61·T-73, ARCH 5.18 행, 지시서
- [x] (Opus) `purchase_po_create.php`(장소 규칙·직접 줄) · `purchase_po.php`·`purchase_docs.js`·`purchase_common.js`(`itemPicker()` 공통화 — 발주요청 화면 코드 삭제 후 호출) · `tests/purchase_f61.sh`(T-73, Playwright 포함) — 자체 실행 138/0. `Doc.php`·css 무수정

**검토 체크** (Fable, 관문 ②)
- [x] 코드 읽기 — **Fable 검토(09-17)**: 기존 함수만 · 요청 줄 경로 불변 · `split` 없는 요청 불변 · 단가는 품목만(R-66)
- [x] `tests/purchase_f61.sh` **138/0**(Fable 재실행) · 회귀 `purchase_f60.sh` 99/0 · `stage23.sh` 90/0 · `stage20.sh` 절대 채번 3건 외 통과 · Playwright 스크린샷 5장 확인(「제안 0」 표시는 (d) 가 이미 발주한 뒤라 정상) · lint 0
- [x] 관문 ④ 커밋 `c13a29f` → push → `release.sh minor` → **v1.12.0**(`13d4cc7`)

**자기 공격 체크** (사용자, 관문 ③)
- [ ] 발주서 → 현황 탭에 미입고가 보이는가(입고 화면 현황과 같은 표)
- [ ] 불러오기 → 장소를 손대지 않고 담기 → 발주서의 입고 장소가 요청서와 같은가 · 장소가 다른 요청 둘 → 발주서 2장
- [ ] 품목 찾기로 직접 줄 → 저장 · 부족 산출 창에서 인가 10·재고 5·미입고 3 인 품목의 제안이 2 인가

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-17 | 요청(사용자) | 위 배경 인용 그대로 | PRD F-61 ①~⑤ |
| 2026-09-17 | 판단(Fable) | 부족 산출 공식은 이미 있다(F-36 `Suggest.php`: 인가+안전−현재고−미입고+조달 예상). 사용자 예(10−5−3=2)는 안전·조달이 0 일 때와 같다 | 새 공식을 만들지 않고 그 API 를 발주서 화면에서 직접 줄로 담는다. 제안→발주요청(`suggest/apply`)은 그대로 둔다 |
| 2026-09-17 | 판단(Fable) | 「창고명이 요청서와 똑같이」 = 저장 규칙을 「장소는 줄이 정한다」로 — 머리 장소는 선택이 되고 명시하면 우선 | ARCH 5.18 「발주서 세 갈래·장소 규칙」. F-60 의 거래처 분할이 (거래처, 장소) 분할로 넓어진다 |
| 2026-09-17 | 판단(Fable, 승인) | Opus 「buyer 는 `purchaseScopeWhere` 가 전 지점(1=1)이라 `POST /api/purchase/po` 에 닿는 좁은 범위 계정이 없어 `not_in_scope` 를 격리 계정으로 실측할 수 없다」 | 맞다 — 그 갈래는 중단된 장소로 실측하고 품목 쪽은 코드 읽기(미검증)로 남긴다. T-73 (b)(c) 의 「Manager 격리 계정」 문구는 이 사실에 맞게 읽는다 |
| 2026-09-17 | 판단(Fable, 승인) | 부족 산출로 담은 줄의 단가가 화면에서 「—」 | `GET /api/purchase/suggest` 응답에 단가가 없고 새 창구를 만들지 않았다. 저장 시 서버가 품목 단가로 계산(R-66). 화면에 단가를 미리 보이려면 제안 응답에 `price_in` 을 더하는 별건 |


### (제거됨 2026-09-21 → 27단계) F-62 — 발주서 분할에 담당자 축 · 발주서의 담당자는 발주요청서에서 온다 — 착수 2026-09-17

> 지시서: `docs/purchase/22-f62-po-manager-split-directive.md`. 근거: PRD F-62 · F-60 · F-61 · 12.2 T-74 · S-01,
> ARCH 5.18 「발주서 담당자·3축 분할」·「F-62·F-63 창구 변화」, SECURITY R-65 · R-73 · **R-92(새 위험)**, 시안 ⑤⑥.
> 배경: 사용자 「전표는 여러개 선택해서 하나의 발주서로 만들 수 있어. 단, 거래처, 담당자로 쪼개져야하고. 이카운트에
> 없는 기능은, 창고별로 따로 불러와서 해야했으나 우리는 한번에 다 불러와서 창고까지 쪼개서 한번에 발주서를 만드는
> 거야」 + 이카운트 캡처 3장(`files` #803~805).

> ★ **이 두 건(F-62·F-63)은 계획·설계를 Opus 5 가 했다.** Fable 한도 초과로 사용자가 그렇게 지시했다
> (2026-09-17). CLAUDE.md 1장의 역할 분담에서 벗어난 예외이고, 설계 원문은 `docs/HANDOVER-2026-09-17-f62.md`
> 에 먼저 적었다가 이 문서·PRD·ARCH·SECURITY 로 옮긴 뒤 그 파일을 지웠다. **관문 ② 독립 검토는 아직**이다 —
> 구현자와 다른 컨텍스트에서 해야 하고, Fable 이 돌아오면 2.4·8.4 의 실측(담당자 칸이 권한 판단에 쓰이지 않는다)을
> 직접 다시 확인할 것.

**개발 사항** (설계 Opus 5 / 구현 Opus / 문서 Opus 5)
- [x] (설계) 진단: 세 축 중 **담당자만** 빠져 있었다. 묶음 열쇠가 `vendor_id:location_id` 둘이고, 출처 줄 조회가
      `d.manager_id` 를 아예 읽지 않아 발주서 담당자 칸에 **구매실 사용자**가 들어갔다. 이카운트 캡처 ②의
      사원(담당)명이 ③의 머리 담당자로 옮겨 오는 것이 사용자가 원한 것이다
- [x] (설계) 실측으로 확인한 안전 근거: `manager_id` 는 **권한 판단에 한 곳도 쓰이지 않는다**. 그것을 읽는 필터는
      둘 — `purchase_pr_list.php` 의 `?mine=1` 과 `Recommend::topItems` — 이고 **둘 다 `doc_type='pr'` 로
      좁혀져 있다**. 지점 격리는 `Authz::purchaseScopeWhere` 가 `branch_id` 로만 한다. 그래서 발주서 담당자를
      요청자로 바꿔도 누가 무엇을 보는지는 바뀌지 않는다
- [x] (사용자 결정 2026-09-17) 담당자 값은 **요청서에서 물려받는다**(만든 사람은 `created_by` 에 그대로) ·
      머리 담당자 칸은 **이번에 함께 만든다**(→ 새 창구 `GET /api/purchase/managers`, R-92)
- [x] (문서) PRD F-62·T-74, ARCH 5.18 두 행 + 창구 표 + F-61 행 문구 정정 + 25단계 JS 헬퍼 표에 `loadPicker`,
      SECURITY R-92
- [x] (Opus) `purchase_po_create.php`(+63/−2 — 출처 조회에 `d.manager_id` · 줄마다 담당자 판정 · 열쇠 3축 ·
      묶음마다 `Doc::managerOk` · 응답 `manager_id`) · `purchase_managers_list.php`(새 창구 55줄) ·
      `index.php` 라우트 **한 줄** · `purchase_po.php`(담당자 칸 +9/−2) ·
      `purchase_docs.js`(+133/−28 — `lineManager`·`managerKey`·3축 미리보기·payload·저장 전 검사) ·
      `tests/purchase_f62.sh`(새 파일 832줄). **자료를 만들지 않는 점검만 통과**: `php -l` 4개 ·
      `bash -n` · `security_lint.sh` 0건 · JS 문법(chromium 컴파일). **시험은 미실행** — 사용자가
      실서비스를 쓰는 중이라 R-73 관문이 막았고, 그 뒤 F-63 이 같은 파일을 고치기 시작해
      중앙 실행으로 미뤘다 (T-74 전부 미검증)

**검토 체크** (관문 ②)
- [x] 코드 읽기 — **다른 맥락의 독립 검토**(구현자 아님). `split` 없는 경로 세 모양 모두 묶음이 하나 ·
      줄 허용 키에 `manager_id` 가 없어 클라이언트가 줄 담당자를 지정할 길이 없음 · 미리보기가 F-60·F-61
      문장을 글자 그대로 냄 · 가시성 변화 0(필터 셋이 전부 `doc_type='pr'`)
- [x] `tests/purchase_f62.sh` **104/0** · 회귀 `purchase_f61.sh` **142/0** · `purchase_f60.sh` **103/0** ·
      `security_lint.sh` 0건 · `php -l` · 사용자 비-qa 행 8578 불변
- [x] SECURITY 7장 검토 이력 한 줄 · R-92 상태 갱신
- [x] 관문 ④ 커밋 `d96f11a` → push → `release.sh minor` → **v1.13.0**(`b294b59`, 태그 push 완료)

**자기 공격 체크** (사용자, 관문 ③)
- [ ] 담당자가 다른 발주요청 둘을 한 번에 담고 저장 → 발주서가 담당자별로 갈라지는가 · 각 발주서의 담당자가
      **요청서의 담당자**인가
- [ ] 머리 「담당자」 를 골라 저장 → 전 줄이 그 담당자로 한 장인가
- [ ] 담당자 목록에 구매 역할이 없는 계정·중단된 계정이 섞이지 않는가
- [ ] **먼저 할 것 — 계정마다 구매 역할 정하기.** 지금 활성 계정 11개 전부 `purchase_role` 이 NULL 이라
      담당자 목록이 비어 있다. 계정 관리 화면에서 사람마다 `requester`(지점 요청자) · `buyer`(구매실) ·
      `stock`(재고 담당) · `viewer`(검토·송부) 중 하나를 정한다. **최소한 발주서를 만드는 계정과
      발주요청을 만드는 계정**에 넣어야 담당자 칸이 쓸 수 있고, 다른 사람이 admin01 의 요청서로
      발주서를 만들 때 400 이 나지 않는다 (사용자 결정 2026-09-17)

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-17 | 요청(사용자) | 위 배경 인용 그대로 (캡처 3장) | PRD F-62 ①~⑥ |
| 2026-09-17 | 판단(Opus 5) | 「담당자로 쪼개진다」는 **분할 열쇠**의 문제이고, 「발주서의 담당자가 요청자」는 **값**의 문제다 — 둘은 다른 결손이라 따로 고쳐야 한다 | 열쇠만 고치면 갈라진 발주서 전부의 담당자 칸에 구매실 사람이 들어가고, 값만 고치면 담당자가 섞인 채 한 장이 된다 |
| 2026-09-17 | 판단(Opus 5, 사용자 결정으로 뒤집힘) | 머리 담당자 칸을 만들지 말자고 권했다 — 사용자 목록 창구가 없어 새 입구가 하나 늘기 때문 | 사용자 결정 「이번에 함께 만든다」. 대신 창구를 **최소**로 냈다: 정책 `purchase.buy.write`, 응답 `id`·`name` 둘, 정의는 `Doc::managerOk` 와 같은 SQL (R-92) |
| 2026-09-17 | 판단(Opus 5) | 목록 창구에 지점 격리를 걸지 않는다 | `Doc::managerOk` 에도 지점 조건이 없고 구매실은 어느 지점 요청이든 담당자로 적는다. 목록과 검사가 어긋나면 「고를 수 있는데 저장이 거부된다」 — 그래서 정의를 맞추고 `lint:no-branch-scope` 표식으로 이유를 코드에 남겼다 |
| 2026-09-17 | 판단(Opus 5) | 응답 `docs[]` 에 담당자 **이름**을 넣지 않았다 | 이름을 넣으려면 `Doc.php` 에 표시용 함수를 하나 더 만들어야 한다(F-60 의 `vendorName` 처럼). 화면은 이미 `pr/load` 와 담당자 칸에서 이름을 갖고 있다 |
| 2026-09-17 | 판단(Opus 구현, 승인) | `Doc::managerOk` 를 **물려받은 값에만** 건다 — `$bucketMgr !== $mgrReq && $bucketMgr !== (int) $user['id']` 일 때만 부른다. 지시서는 무조건 부르라고 했다 | **승인.** 근거를 실측으로 확인했다: `Authz::purchaseAuthorize` 는 `purchase_role` 을 **읽기 전에** Admin 을 통과시키고(`src/Authz.php:954`), 운영 Admin 계정 넷(`admin01`·`qa_admin01`·`t10_admin2`·`t11_admin`)이 **전부 `purchase_role` NULL** 이다. `Doc::managerOk` 는 `is_active AND purchase_role IS NOT NULL` 이라 그 계정들에 false 다 → 무조건 호출은 **Admin 의 발주서 저장을 전부 400 으로 막는다**(화면은 늘 `split:true` 를 보내므로 옛 경로까지). 남는 구멍은 「물려받은 담당자가 우연히 로그인 사용자와 같을 때 검사를 건너뜀」이고, 그 사람은 방금 `purchase.buy.write` 를 통과한 사람이라 수용했다. 더 엄밀히 하려면 `$prepared` 에 「물려받았음」 표식 칸을 만든다(별건) |
| 2026-09-17 | 판단(Opus 구현, 승인) | 직접 줄의 담당자 **번호**를 `/api/me` 가 아니라 담당자 목록에서 이름으로 맞춘다 | **승인.** `/api/me` 는 내부 id 를 내지 않는다(`src/handlers/me.php` 머리말 — PRD S-09-5 취지). `users.username` 은 NOT NULL UNIQUE(`db/schema.sql:42`)라 이름 일치가 유일하다. 이 번호는 **화면 표시·미리보기 묶음에만** 쓰이고 저장 본문에 실리지 않는다. 구매 역할 없는 Admin 은 목록에 없어 번호가 null 로 남고, 그때 미리보기는 이름으로 센다 — 그렇게 하지 않으면 그 Admin 화면에서 직접 줄이 「담당자를 정할 수 없는 줄」로 잡혀 저장이 막힌다 |
| 2026-09-17 | 판단(Opus 5) | `tests/purchase_f61.sh` 의 미리보기 단언 한 줄을 3축 문구로 정정했다 | 그 시점의 줄은 요청 줄(담당자 `qa_employee01`)과 직접·부족 산출 줄(담당자 = 로그인한 `qa_manager01`)이 섞여 **담당자 축이 갈린다** → 문구가 `거래처×입고장소×담당자별` 이 된다. **F-62 가 의도한 새 문구**이므로 고칠 곳은 시험의 기대값이다. 담당자가 한 사람뿐인 앞 단언(`거래처×입고장소별 2장`)은 그대로 두었다 — 그것이 「갈리는 축만 말한다」 규칙의 증거다. `purchase_f60.sh` 의 `거래처별 2장` 은 그 화면 픽스처가 요청 둘을 **같은 계정·같은 장소**로 만들어(f60 531~537행) 축이 갈리지 않으므로 안전하다 |
| 2026-09-17 | 검토(독립, Opus 5 다른 맥락) | **인가·격리·SQL·출력·감사에서 새로 열린 구멍 없음.** 확인한 것: `split` 없는 경로 세 모양(요청만·직접만·섞임) 모두 묶음이 정확히 하나 → 응답·상태·감사·머리 불변 / 줄 허용 키 `TBOX_PO_LINE_KEYS` 에 `manager_id` 가 없어 **클라이언트가 줄 담당자를 지정할 경로가 없다** / 새 창구의 조건이 `Doc::SQL_USER_ONE` 과 글자까지 같다 / `manager_id` 를 **필터**로 쓰는 곳 셋(`pr_list ?mine=1`·`Recommend::topItems`·`pr_load ?manager=`) 전부 같은 WHERE 에 `doc_type='pr'` 이 함께 붙는다 / 지점 격리 조각은 `branch_id` 한 모양뿐 / 린트 22항목 0건 | 막아야 할 지적 둘(아래 두 줄)·자잘한 것 넷(시험 정리 `is_active` 미원복·감사 단언 범위·`p_doc_seq` 되돌리기 기준·운영 담당자 목록 빈 칸). **T-74 는 전부 미검증** — 시험을 돌리지 않은 검토라 「관문 ② 통과」로 적지 않는다 |
| 2026-09-17 | 회귀(검토 지적, 조치함) | `tests/purchase_f61.sh` 의 「요청 줄 + 직접 줄 섞기」 장면이 F-62 로 **두 장**이 된다 — 요청은 `qa_employee01` 이 만들고 저장은 `qa_manager01` 이 하므로 담당자 축이 갈린다(단언 5줄이 깨진다) | 그 장면이 검증하는 성질은 「한 발주서에 요청 줄과 직접 줄이 **함께** 들어간다」(PRD F-61 ③)이므로, 기대값을 두 장으로 바꾸는 대신 **요청을 `qa_manager01`(buyer)이 만들게** 고쳤다(`branch_id` 를 함께 적어 준다 — buyer 는 지점이 비어 있다). 담당자가 갈릴 때 두 장이 되는 것은 T-74 (e) 가 따로 시험한다. PRD T-73 의 「같은 (거래처,장소) 면 한 장」 문구도 세 축으로 정정. 앞서 고친 미리보기 단언과 함께, `tests/purchase_f61.sh`·`PRD.md` 수정은 **지시서 범위 밖**이며 조정자(Opus 5)가 했다 |
| 2026-09-17 | 결함(검토 지적, 조치 대기) | 미리보기 **장 수**가 실제 저장 장 수와 어긋날 수 있다(표시만) — 묶음 열쇠는 담당자 **번호**로 묶는데(`managerKey`) 「축이 갈렸나」 판정은 담당자 **이름**으로 센다. 번호를 모르는 계정(담당자 목록에 없는 계정)이 섞이면 두 기준이 갈린다 | 고침은 `managerKey` 를 **이름 우선**으로 바꾸는 한 줄이다 — `users.username` 이 NOT NULL UNIQUE 이고 세 출처(`pr_load.manager_name`·`/api/me.username`·담당자 목록의 `name`)가 **같은 열**이라 이름 묶기는 서버의 번호 묶기와 같은 결과다. F-63 이 같은 파일을 고치는 중이라 그 뒤에 적용한다 |
| 2026-09-17 | 운영 사실(검토 실측) | **활성 계정 11개 전부 `purchase_role` 이 NULL** 이다. `admin01` 은 `Authz::purchaseAuthorize` 의 Admin 우회(`src/Authz.php:954`)로 모듈을 쓰고 있고, 실제 발주요청 전표의 담당자도 전부 `admin01` 이다 | 결과 둘: ① 새 「담당자」 칸이 **빈 목록**으로 보인다(고를 사람이 없다 — 목록 정의가 `Doc::managerOk` 와 같으므로 「고를 수 있는데 저장이 거부」되는 어긋남은 없다) ② 다른 계정이 `admin01` 의 요청서로 발주서를 만들면 물려받은 담당자가 `managerOk` 를 통과하지 못해 **400 `bad_reference`** 가 난다(오늘은 `admin01` 혼자 쓰므로 검사가 건너뛰어져 발생하지 않는다). **사용자 결정 2026-09-17: 실계정에 구매 역할을 넣는다** — 코드 변경 0. 물려받은 값도 계속 `Doc::managerOk`(활성 + 구매 역할) 로 본다(T-74 (f) 유지). 실제 역할 부여는 **사용자 몫**이다 — 누가 요청자·구매실·재고 담당인지는 사람 배치이고, 실서비스 권한을 바꾸는 일이라 조정자가 대신 정하지 않는다. 관문 ③ 항목으로 옮겼다 |
| 2026-09-17 | **후보(사람 이름) — 사용자 지적** | 「담당자는 아이디가 아니야 … 이름이라는 개념이 없구나」. **사실이다.** `users` 표에 사람 이름 칸이 **없고**(19칸 전부 확인), 아이디 칸은 `CHECK (username ~ '^[a-z0-9_.-]+$')` 라 **한글이 들어갈 수 없다**(`db/schema.sql:44`). 그런데 코드 29곳(구매 모듈 23곳)이 `username AS …_name` 으로 그 값을 「이름」이라 부른다 — `purchase_managers_list.php:38`(F-62 의 담당자 후보 목록) · `pr_load:113` · `gr_load:121`(F-63 의 담당자 묶음 라벨) · 발주서·입고·요청 목록·상세·인쇄·사이클·미입고 전부 | **그래서 F-62 의 머리 담당자 칸과 F-63 의 담당자 묶음에 계정 아이디가 뜬다.** F-62·F-63 을 고치는 일이 아니고 **별건**이다(스키마 한 칸 + 마이그레이션 + 계정 화면 + SELECT 29곳 + 감사 표기 정책 = 한 기능 규모). **결정할 것 넷**: ① 어디에 — `users.display_name` 한 칸인가 별도 표인가, 필수인가 선택(비면 아이디로 대체)인가 ② 누가 적나 — 관리자가 계정 만들 때 · 본인이 「내 정보」에서 · 가져오기로 일괄 ③ 감사 로그 — `audit_logs.actor_name` 은 **추적의 근거**이고 아이디는 UNIQUE·불변, 이름은 바뀐다 → 로그에는 아이디를 두고 **화면에만** 이름을 붙이는 원칙으로 할지 ④ 표기 — 「이창권」 / 「이창권(admin01)」 / 목록은 이름·감사·인쇄는 둘 다. 개인정보 한 칸이라 R-69(명세서에 담당자 연락처를 담지 않는다)와 같은 결의 판단이므로 `SECURITY.md` 검토 항목으로 올린다. **맞물림 주의**: 이것을 만들면 `purchase_docs.js` 의 `managerKey`(이름으로 묶는다 — 아이디가 유일하다는 전제)를 **번호 기준으로 되돌려야** 한다. 코드에 주석으로 박아 두었다 |
| 2026-09-17 | 후보(F-64) | 이카운트 발주서 머리의 **납기일자 · 거래유형(부가세율) · 통화 · 프로젝트**는 tbox 발주서에 칸이 없다(`files` #803·#805 캡처) | 이번 범위 밖으로 두었다. 납기는 `p_backorder` 의 「납기 지남」 판정이 **보낸 날 + 품목 조달기간**으로 이미 대신하고 있어(F-33) 칸을 만들면 그 판정과 어느 쪽을 믿을지 정해야 한다 — 사용자 결정 대기 |


### (제거됨 2026-09-21 → 27단계) F-63 — 입고 불러오기: 단추 뒤 창 · 발주요청 불러오듯 전표 단위 — 착수 2026-09-17

> 지시서: `docs/purchase/23-f63-gr-load-directive.md`. 근거: PRD F-63 · F-33 · F-45 · 12.2 T-75 · S-01,
> ARCH 5.18 「입고 불러오기 — 단추 뒤 창·묶는 기준」·25단계 「JS 헬퍼 한 벌」, SECURITY R-65 · R-73.
> 배경: 사용자 「입고 진행시, 미입고 잔량만 불러오기가 있는데 이것을 버튼으로 만들어서 필요시에만 사용 및 조회하도록
> 개선. 입고 시에도, 발주서에서 발주요청 불러오듯이 전표 불러오도록 개선해」.

**개발 사항** (설계 Opus 5 / 구현 Opus / 문서 Opus 5)
- [x] (설계) 진단: 불러오기 패널이 입고 「입력」 탭 맨 위에 늘 펼쳐져 있고, `loadMaster('vendors').then(… loadOrders())`
      가 **화면 진입마다** 미입고 300줄 상한 조회를 돌린다. 그리고 발주서별 고정 묶음이라 묶는 기준을 고를 수 없다 —
      사용자 지적 둘 다 사실
- [x] (사용자 결정 2026-09-17) 조회는 **창 안 「불러오기」를 눌러야** 돈다(발주요청 불러오기 창과 동작을 같게) ·
      묶기 코드는 **새 파일 한 벌**(`src/purchase/Grouping.php`)
- [x] (문서) PRD F-63·T-75, ARCH 5.18 행 + 창구 표
- [x] (Opus) `Grouping.php`(새 파일 93줄 — 순수 함수 `Grouping::tree()`) ·
      `purchase_gr_load.php`(+140/−4 — `?group=` 여섯 · 분류 칸 · `nodes` 분기) ·
      `purchase_pr_load.php`(+10/−41 — `$build` 삭제 후 공통 함수 호출, **거동 불변**) ·
      `purchase_receipt.php`(+89/−26 — 패널 제거 · 단추 + `#gr-load-modal`) ·
      `purchase_stock.js`(+91/−80 — 진입 자동 조회 제거) · `purchase_common.js`(+289 — `UI.loadPicker()`) ·
      `purchase_docs.js`(+149/−257 — 중복 트리·체크 코드 삭제) · `tests/purchase_f63.sh`(새 파일 1048줄).
      **자료를 만들지 않는 점검만 통과**: `php -l` 4 · `bash -n` · `security_lint.sh` 0건 ·
      JS 문법(chromium 컴파일) 3파일 · 시험의 정적 점검 구간 31/0 · 뷰 중복 id 0.
      **시험은 미실행** (T-75 전부 미검증)
- [x] (Opus, 증거) **`Grouping::tree` 가 옛 `$build` 와 같은 결과**임을 옛 클로저와 나란히 놓고 같은
      픽스처로 `json_encode` **바이트 비교**로 확인 — 1·2·3층 12조합 + 빈 입력 2가지 **14/14 동일**
      (픽스처에 null 거래처·null 장소·null 분류·null 이름·같은 열쇠 되풀이·수량 0·반복 전표 포함).
      「머릿속 대조」가 아니라 실행으로 확인한 것이라 `pr_load` 불변의 근거로 쓸 수 있다
- [x] (Opus, 증거) 창 동작을 **앱에 요청하지 않고** 오프라인으로 확인 — 뷰에서 뽑은 `<dialog>` 뼈대 +
      가짜 `request` 로 `file://` 구동: 입고 창은 열 때 조회 **0회**, 「불러오기」로 **1회**, 노드 체크 →
      하위 전부·일부 해제 → 중간 상태, 담기 → 세 줄 · 발주서 창은 겉모양·문구·중복 방지·출처 칸의
      F-62 담당자 표시까지 그대로

**검토 체크** (관문 ②)
- [x] 코드 읽기 — `?group=` 값이 `$where`·`$bind` 에 닿지 않고 화이트리스트 열쇠로만 쓰임 ·
      `group` 없는 `gr/load` 는 새 분기보다 **뒤**라 21단계 경로를 글자 그대로 지남(응답 키 셋 실측) ·
      `pr_load` 응답 다섯 키 실측 동일 + `Grouping::tree` = 옛 `$build` **바이트 비교 14/14**
- [x] `tests/purchase_f63.sh` **195/0** · 회귀 `stage21` **100/0** · `f45` **86/0** · `f60` **103/0** ·
      `f61` **142/0** · `f62` **104/0** · lint 0 · `php -l`. `ui_snapshot.sh` 는 **미실행**(창의 CSS 클래스가
      전부 기존 것인지는 확인)
- [x] SECURITY 7장 검토 이력 한 줄
- [x] 관문 ④ — F-62 와 **한 커밋**이다: `d96f11a` → push → `release.sh minor` → **v1.13.0**(`b294b59`)

**자기 공격 체크** (사용자, 관문 ③)
- [ ] 입고 화면에 들어갔을 때 목록이 저절로 뜨지 않는가 · 첫 그림이 가벼워졌는가
- [ ] 「발주서 불러오기」 → 「전표」 로 묶어 여러 발주서를 한 번에 담을 수 있는가
- [ ] 담당자·창고로 묶어 보면 발주요청 불러오기와 같은 모양인가

**학습 기록**

| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-17 | 요청(사용자) | 위 배경 인용 그대로 | PRD F-63 ①~⑤ |
| 2026-09-17 | 판단(Opus 5) | 두 요청을 **한 건**으로 묶었다 | 같은 화면의 같은 창구를 고치는 일이고, 따로 하면 같은 파일을 두 번 흔든다 |
| 2026-09-17 | 판단(Opus 5) | 묶기 코드를 베끼지 않고 한 벌로 뽑는다 | 25단계 CSS 교훈 — 규칙이 여러 벌이면 고칠 때 한 벌만 고쳐진다. 사용자 결정으로 새 파일 `Grouping.php`(순수 함수·DB 접근 0) |
| 2026-09-17 | 판단(Opus 구현, 승인) | `pr_load` 의 `$keyOf` 는 **남겼다** — 지운 것은 재귀 묶기(`$build`) 뿐이다 | **승인.** 두 화면의 줄 칸 이름이 달라(`doc_vendor_id`·`line_id` vs `vendor_id`·`po_line_id`) 열쇠 뽑기를 공통 함수 안으로 옮길 수 없다. 그래서 `Grouping::tree(rows, levels, keyOf, qtyOf)` 가 **호출자가 넘기는 것**으로 서명을 정했다. 시험의 정적 점검이 `$build` 0건을 못박는다 |
| 2026-09-17 | 판단(Opus 구현, 승인) | `gr_load` SELECT 에 칸을 둘이 아니라 **셋** 더했다 — 분류 두 칸 + `bo.manager_id` | **승인.** 21단계 SELECT 에 `mgr.username` 만 있고 **담당자 번호가 없어** 담당자 노드의 열쇠(정수)를 만들 수 없었다. 이미 조인된 뷰(`p_backorder`)의 칸이라 **새 조인·새 조회 0** 이고, `orders` 응답에는 `$orders` 조립 코드가 고른 칸만 담기므로 새 키가 생기지 않는다 |
| 2026-09-17 | 판단(Opus 구현, 승인) | `addLine(order, line)` 에 선택 인자 `qtyValue` 를 더했다 | **승인.** 창에 「담을 수량」 칸이 생겼으니 그 값이 줄에 실려야 한다. **줄 객체의 칸 이름은 하나도 바뀌지 않았다**(입고 확정 payload 가 쓰는 `po_line_id`·`qty`·`lot`·`exp`·`remark` 그대로)이고 중복 방지도 그대로, 값이 없으면 21단계처럼 잔량이 들어간다 |
| 2026-09-17 | 판단(Opus 구현, 승인) | `purchase_stock.js` 에서 지시서에 없던 3곳을 더 고쳤다 | **승인 — 없으면 화면이 깨진다.** ① `boot()` 의 화면 판별이 `#gr-load` 였다: 그 패널을 없앴으므로 그대로 두면 **입고 화면 로직이 아예 시작되지 않는다**(→ `#gr-form` 으로). ② 입고 확정·취소 뒤의 `loadOrders()` 두 곳: 함수가 없어졌으니 `ReferenceError` 다(→ 제거). ③ 걸개 셋의 `change` → `loadOrders` 배선: 걸개가 창 안으로 갔고 조회는 「불러오기」로만 돈다(사용자 결정) |
| 2026-09-17 | 판단(Opus 구현, 승인) | 입고 창의 기본 묶는 기준을 `['doc']`(21단계 모양)이 아니라 `['vendor']`(발주요청 창과 같은 기본값)로 두었다 | **승인.** 「발주요청 불러오듯이」가 사용자 요청이고, T-75 (g) 의 첫 동작이 **「전표」 칩을 누르는 것**이다. 두 창의 기본값이 같아야 「같은 틀」이 성립한다 |
| 2026-09-17 | **깨달음(사용자 질문 「배포했어?」로 드러남)** | **이 저장소에는 「배포」 단계가 없다.** nginx 의 공개 서버 블록(`listen 80`, `server_name changkwon.mso.so _`)과 검토용 블록(`listen 127.0.0.1:8082`)이 **같은 `root /var/www/tbox-v2/public`** 을 쓴다. `opcache.validate_timestamps=On`·`revalidate_freq=2` 라 PHP 는 2초 안에, JS·CSS 는 뷰의 `?v=filemtime` 때문에 즉시 반영된다. 실측: 공개 주소로 받은 `purchase_common.js` 에 `loadPicker` 가 디스크와 같은 횟수로 들어 있었다 | **그러므로 「저장만 했다」와 「배포하지 않았다」는 같은 말이 아니다.** F-62·F-63 은 관문 ② 를 통과하기 전에 이미 사용자 화면에 떠 있었다. 조정자가 사용자에게 그 사실을 「저장만 했다」로 전한 것은 오해를 부를 표현이었다. **규칙으로 삼을 것: 이 저장소에서 파일을 고치는 순간이 곧 배포다.** 되돌리려면 `git stash`(추적) + 새 파일 격리이고, 그 판단은 사용자 몫이라 조정자가 스스로 되돌리지 않았다(CLAUDE.md 4장) |
| 2026-09-17 | **결함(사용자 지적, 고침)** | 「여전히 전표 화면이 아닌 묶음하고 뭐라뭐라 나오네 이미지 줬잖아」 — 입고 불러오기 창이 열리면 왼쪽에 **거래처**가 뜨고 발주서 전표가 보이지 않았다. 기본 묶는 기준이 `['vendor']` 였다 | **조정자(Opus 5) 지시서 결함이 먼저다.** 지시서 `docs/purchase/23` 는 「발주요청 불러오기와 같은 틀」이라고만 적고 **기본 묶는 기준을 못 박지 않았다.** 사용자 말은 PRD F-63 에 「전표 불러오도록」으로 인용되어 있는데, 구현자는 시험 대본을 쓰기 편한 쪽(`['vendor']`, 두 창의 기본값을 같게)을 골랐다. 고침: 기본값 `['doc']` · 왼쪽 패널 제목 「발주서」 · 빈 문구 「왼쪽에서 발주서를 고르세요.」 · 마디 빈 문구 · 체크박스 읽어 주는 이름 「… 발주서 전체 담기」 · 칩바 라벨에 「기본 전표」. `UI.loadPicker()` 에 문구 옵션 셋(`nodeWord`·`pickText`·`emptyText`)을 더하고 **기본값을 발주서 창의 원문과 글자까지 같게** 두어 발주서 창은 변하지 않는다(실측 확인). 조사가 「묶음을」→「묶음를」로 깨져 문장을 통째로 받는 방식으로 바꾼 것도 구현자가 오프라인 실측으로 잡았다. 교훈: **사용자 말이 PRD 에 인용되어 있으면 지시서는 그 말을 기본값까지 못 박아야 한다** |
| 2026-09-17 | **운영 사실(중요)** | **하위 구현 에이전트에게는 사용자가 메인 세션에 준 이미지가 가지 않는다.** 구현자는 캡처 3장(`files` #803~805)을 본 적이 없고 글로 옮겨진 지시서만 받았다. 저장소에도 PNG·JPG·SVG 가 **0개**이고 시안은 `docs/purchase/design/*.dc.html` 로만 있다 | 그래서 **이미지와 지시서가 갈리면 구현자는 알 방법이 없다.** 앞으로 시안이 중요한 건은 ① 이미지를 저장소(`docs/purchase/design/`)에 넣거나 ② 이미지에서 읽은 것을 **지시서 본문에 글로 다 옮겨** 넘긴다. 이번 건은 ②를 부실하게 한 것이 기본값 오판의 뿌리다 |
| 2026-09-17 | 열린 질문(사용자 결정 대기) | 입고 불러오기 창의 **「묶는 기준」 칩 줄 자체**를 둘지 | 칩 여섯은 PRD F-63 ② 의 명시 요구이고, 「한 번에 다 불러와서 창고·담당자까지 쪼갠다」는 사용자 요청을 화면에서 가능케 하는 장치다. 다만 사용자가 말한 「뭐라뭐라」가 **칩 줄이 없어야 한다**는 뜻이면 PRD 를 고쳐야 하는 설계 변경이다. 시안 `GR.dc.html`(⑥)에는 「발주서 불러오기」 **단추만** 있고 창 안 시안이 없다 — 창 안 뼈대는 발주서 화면용 `ImportModal.dc.html`(④)에서 왔다. **현재 판단: 칩은 두고 기본값만 전표로.** 사용자가 달리 말하면 PRD 를 먼저 고친다 |
| 2026-09-17 | 열린 질문(어휘) | 발주서 창은 여전히 「묶음」, 입고 창은 「발주서」라고 부른다 | 입고 창은 기준이 늘 전표부터라 「발주서」가 정확하지만, 발주서 창은 기준이 여섯이라 「묶음」이 맞다. **한 제품에 두 어휘가 생긴 것**이므로 위키·`ARCHITECTURE.md` 어휘 표와 맞출지 정해야 한다(3단계 위키 어휘 정리와 함께) |
| 2026-09-17 | **오진(조정자) — 사용자에게 틀린 경고를 했다** | F-63 1차 시험에서 입고 확정이 400 으로 거부되자 「입고 확정이 동작하지 않는다 · 입고 작업을 하지 마시라」고 사용자에게 알렸다. **틀렸다.** 입고 전표는 **장소가 하나**여야 하고(`purchase_gr_create.php:283` 「입고 장소는 한 전표에 하나 — 여러 곳에 동시에 들어오는 입고는 전표를 나눈다」, 21단계 규칙), 시험 픽스처가 **장소가 다른 발주서 둘**(`LOC_X`·`LOC_Y`)을 한 입고 전표에 담으려 했다. 서버는 옳게 거부했다 | 확정 방법: ① 화면이 실제로 보내는 본문을 **가짜 조회 응답 + 요청 전송 취소**로 떴다(실자료 0 접촉) → `{"doc_date":"…","lines":[{"src_line_id":…,"qty":"4"},…]}` 정상 ② 같은 모양을 **없는 줄 번호**로 서버에 던져 형식 검사를 통과함을 확인(`not_in_scope` 가 났다, `bad_request` 가 아니었다) ③ 그래서 형식이 아니라 다른 규칙임을 알고 픽스처의 장소를 봤다. **교훈: 시험 실패를 코드 결함으로 사용자에게 알리기 전에, 그 실패가 서버의 정당한 거부인지 먼저 가린다.** 「기대=0 실제=1」 같은 단언 실패는 코드·시험·픽스처 셋 중 어디든 원인일 수 있다 |
| 2026-09-17 | 시험 정정(F-63, 조치함) | 1차 9건 실패 → 정정 뒤 **195/0** | ① 픽스처의 두 발주서를 **같은 장소**(`LOC_X`)로 — 그 장면이 검증하는 것은 「여러 전표를 한 입고 전표에 담는다」다 ② 첫 발주서를 **줄 두 개**로 — 「한 줄 해제 → 노드 중간 상태」는 노드 아래 줄이 둘 이상이어야 볼 수 있다(1차 실패의 다른 원인) ③ 빈 문구 기대값에 빠진 마침표 ④ **(h-2) 새 시험** — 장소가 갈린 두 줄 → 400 `bad_request` · 전표 생성 0(시도 전후 개수 비교) · 한 장소만이면 201. 이 규칙은 21단계부터 있었는데 **어디에서도 시험하지 않고 있었다**; F-63 이 여러 전표 담기를 쉽게 만들었으므로 이제 필요하다 |
| 2026-09-17 | 기존 결함 정정(21단계) | `tests/purchase_stage21.sh` 의 채번 단언이 `GR-<오늘>-1` **절대 번호**를 기대해, 사용자가 그날 입고 전표를 하나라도 만들면 코드와 무관하게 실패했다(F-60 때부터 알려진 결함) | 「직전 카운터 + 1」 **상대 번호**로 고쳤다 — 시작 전 `p_doc_seq.last_no` 를 읽어 기대값을 만든다. R-78 정리가 카운터를 **지우지 않고 되돌리므로** 그날 번호는 계속 올라가는 것이 정상이다. 고친 뒤 `stage21` **100/0** · 그것을 품은 `f45` **86/0**. 같은 꼴의 `stage20` 단언은 남아 있다(별건) |
| 2026-09-17 | 열린 질문(UX) | 불러오기 창에서 **장소가 다른 발주서들을 함께 담을 수 있고**, 실패는 저장할 때 「잘못된 요청입니다」 한 줄로만 드러난다 | 21단계에도 있던 성질이지만(패널에서 발주서별 「줄 전부 담기」로 같은 일이 가능했다) F-63 이 **전표 단위 여러 개 담기**를 쉽게 만들어 부딪칠 확률이 커졌다. 개선 후보: ① 창에서 장소가 갈리는 선택을 미리 알리기(담은 줄 표에 장소 열을 두고 갈리면 배지) ② 저장 오류 문구를 「입고 장소가 갈립니다 — 전표를 나눠 주세요」로 구체화(서버 사유 `bad_request` 를 화면이 구분할 수 있게 사유 코드를 나눌지 포함). **사용자 결정 대기** |
| 2026-09-17 | 시험 1차(F-62) | `tests/purchase_f62.sh` **103✔ / 1✖**(집계 「통과 98 · 실패 1」) · 종료 코드 0 · **사용자(비-qa) 행 수 불변 8578 → 8578** · 정리 뒤 qa 전표 0·qa 계정 오염 0 | 실패한 **한 건은 시험의 기대값 결함**이다 — 「detail 에 새 키가 없다」의 허용 목록에 일곱 이름만 적혀 있었는데, `Audit` 은 **모든 행에** `route`·`login_ref`·`actor_team` 을 자동으로 붙인다(`src/Audit.php:1138`·`1169`·`1172`). **F-62 이전 행이 증거다**: 오늘 13:39 admin01 의 발주서 감사 행도 키 집합이 이미 열 개로 같다. 그러므로 **F-62 가 더한 detail 키는 0개**이고 코드 결함이 아니다. 허용 목록에 자동 3키를 더해 정정했다 |
| 2026-09-17 | 시험 1차(나머지 다섯) | `f63`·`f61`·`f60`·`f45`·`stage21` 이 **줄줄이 종료 코드 3** — 아무것도 만들지 않고 끝났다 | 원인은 **실행기 결함**이다: 관문(비-qa 활동 0)을 **루프 앞에서 한 번만** 기다리게 짜 놓아, f62 가 끝난 직후 사용자가 관리자 화면을 만지자(16:19~16:20 `auth.gate deny` 7건 — `/wiki`·`/admin`·`/users`·`/org`·`/audit` 가 재인증으로 유도된 **정상 동작**, 16:20:44 재인증 성공) 뒤 다섯이 전부 막혔다. 실행기가 **시험마다 관문을 다시 기다리도록** 고쳤다. 시험 스크립트 쪽 관문은 의도대로 동작했다(R-73 ②가 사용자 자료를 지켰다) |
| 2026-09-17 | 조치(Opus 5) | 살아 있는 코드가 깨지지 않았는지 **검토 계정으로 실측**했다(qa_admin01, 8082, `-A tbox-review`) — qa 활동은 시험 관문(비-qa 0)을 건드리지 않는다 | `/purchase/po`·`/purchase/receipt`·`/purchase/pr` **셋 다 HTTP 200 · 치명 오류 0** · 발주서 화면에 `#po-manager` 1 · 입고 화면에 `#gr-load-open` 1, 옛 패널 `#gr-load` 0 · `GET /api/purchase/managers` 200 `{"items":[]}`(구매 역할 계정이 0이라 빈 목록 — 설계대로) · **`gr/load` 계약 불변 실측**: `group` 없이 → 키 `orders`·`line_count`·`truncated`(21단계 그대로), `?group=doc` → `nodes`·`group`·`qty`·`line_count`·`truncated` · `pr/load` → `groups`·`group`·`qty`·`line_count`·`truncated`(불변). 화면이 멈춰 있지 않다는 것까지는 확인됐고, **전표를 만드는 갈래는 여전히 미검증**이다 |
| 2026-09-17 | 조치(Opus 5) | 미뤄 둔 검토 지적 ②2 를 F-63 뒤에 적용했다 — `managerKey` 를 **이름 우선**으로, 「축이 갈렸나」 판정도 같은 열쇠로 | 담당자 이름이 오는 세 곳(`pr_load.manager_name`·`/api/me.username`·담당자 목록의 `name`)이 **모두 `users.username` 한 열**이고 NOT NULL UNIQUE 이므로 이름 묶기는 서버의 번호 묶기와 같은 결과다. 번호는 화면이 늘 알 수 없는 값이라(구매 역할 없는 계정으로 로그인하면 null) 기준으로 쓰면 어긋난다. 묶음과 축 판정이 이제 `managerKey` **한 곳**에서만 정해진다 |
| 2026-09-17 | 판단(Opus 5) | `gr/load` 는 `group` 이 없을 때 **옛 응답 모양**을 그대로 낸다 | 응답 모양을 갈아 버리면 그 창구를 부르는 다른 화면·시험이 함께 깨진다. F-60 의 `split` 이 쓴 방법과 같다(선택 키로 새 모양을 연다) |


### 27단계 — 구매·재고 모듈을 t-box 에서 떼어낸다 (F-64 · T-76 · Q-22 · R-93) — 착수 2026-09-21

> 지시서: `docs/f64-purchase-removal-directive.md`. 근거: PRD F-64 · Q-22 · T-76, ARCH 5.18(묘비), SECURITY R-93.
> 배경(사용자 말): 강사 지시 「티박스랑 구매팀 솔루션이랑 분리 — 도메인.mso.so 는 기존솔루션, /tbox 는 t-box」 · 「현재 과제는 T박스만이었는데, 구매 모듈까지로 내가 확장한 것이 원인」.
> 백업(Fable, 11:45): 태그 `v1.13.0-with-purchase`(origin push 됨) · `~/tbox-v2-purchase-backup-2026-09-21.zip` 3.6MB (git bundle 2.9MB · `p_*` 덤프 1.5MB 19표·3뷰·16시퀀스 · users 두 열 CSV · `purchase.*` 감사 5,931행 CSV · README) sha256 `2e56ce19…7fb01` — 사용자가 t-box 에 올린다.

**개발 사항 (① 구현 — Opus, 2026-09-21)**
- [x] 삭제 **169** = 추적 구매 파일 162 + 위키 7 · 이동 **7**(`docs/purchase/` 아래 t-box 지시서 → `docs/`) · 신규 **2**(마이그레이션 · `wiki/stage-27.json`) · 편집 **44**
      ※ 지시서의 「삭제 169 + 위키 7」은 169 안에 **이동한 7개가 포함**돼 있었다. 실제는 162 + 7 = 169 삭제 · 7 이동
- [x] 라우터·Authz·Auth·Audit·셸·app.js/css·도움말·계정/역할로 보기 핸들러에서 구매 축 제거 (지시서 2-1~2-5)
- [x] `db/migrations/2026-09-21_purchase_removed.sql` · `schema.sql`·`grants.sql` 정리 — 임시 DB 두 개로 검증하고 지웠다. **운영 DB 는 읽기만**(`\dt p_*` 19개 그대로), 실행은 사용자 (2-6)
- [x] 경보 AL-14~17 · 린트 (a)(c)(d)(e)·재고·`purchase_role` 절 · 스냅샷 기본 페이지 9개 · 시험 5개 조정 (2-7·2-8)
- [x] 위키 19~25 삭제 · 27 신설 · `STAGE_MAX=27`(`wiki_check` 0건) (2-9) · 문서 5종 규칙대로 (2-10)
- [x] **남긴 것(미결 → Fable 판정 2026-09-21)**: `tests/f53.sh:430`·`db/migrations/2026-09-16_sessions_act_as.sql`·`db/migrations/2026-09-17_fetch_hosts.sql` 의 구매 낱말·옛 경로 — 셋 다 지시서 1장 「수정 파일」 밖이라 손대지 않았다(앞의 둘은 지나간 마이그레이션의 이력이기도 하다)
      → 판정: `tests/f53.sh:430` 문구·`2026-09-17_fetch_hosts.sql:4` 경로 주석은 Fable 이 직접 정정(5장 경미). `2026-09-16_sessions_act_as.sql` 은 두 열을 **만든** 이력 파일이라 **보존**(고치면 옛 DB 재현이 끊김). 위키 묘비는 의도(T-76 (a) 문구 정정). `changelog.sh` 4건은 기존 드리프트 — 별건

**체크 (② 검토 — Fable, T-76)**
| 항목 | 결과 | 근거 |
|---|---|---|
| (a) 잔재 0 | ✅ | Fable 재실행 grep: 코드 0건(옛 이력 `2026-09-16_sessions_act_as.sql` 13건은 의도 보존). 위키는 27단계 묘비·「제거됨」 표기만 |
| (b) 정적 검사 | ✅ | Fable 재실행: `php -l` 18/18 · `security_lint.sh` 0건 exit 0 · `wiki_check.php` 0건(28편) · `bash -n` 7 · py ok. **미실행** `node --check`(서버에 node 없음) — Opus 가 Playwright 크로미움 파싱으로 대체(SyntaxError·pageerror 0) |
| (c) t-box 본체 살아 있음 (10화면·/api/me·404·계정·역할로 보기·옛 감사 행·업로드) | ✅ | Opus 46 통과(1건 423 은 F-57 「검사 중」, 배치 뒤 200). Fable: 비로그인 `/purchase/pr`·`/api/purchase/items`·`/api/purchase/branches` 404, `/api/health` 200. 옛 `purchase.*` 감사 4행이 저장된 summary 로 조회됨(`?target=`; `?action=purchase.*` 는 원래부터 허용 목록 밖) · `audit_logs` purchase.* 5,931행 그대로 |
| (d) 회귀 6종 | ✅ | Fable 재실행 `stage26.sh` 108/0(비-qa 행 193→193) · `help_button.sh` 16/0 · `api_whoami.sh` 15/0(미검증 2 기존). Opus `f54.sh` 61/0 · `api_tokens.sh` 70/0 · `changelog.sh` 61/**4** — 4건은 시험이 `1.0.1` 을 하드코딩한 **기존 드리프트**(F-64 무관, 별건) |
| (e) 화면 결함 ≤ 7 | ✅ | Opus `ui_snapshot.sh` 1440px 9화면 합계 5건(전부 기존: 「2단계」 stage_text 4 + `org-teams` 1), 콘솔 0. Fable `help_button.sh` 안 스냅샷 통과 |
| (f) 마이그레이션 임시 DB 검증 · 운영 DB 불변 | ✅ | Opus: `tbox_f64_check`(옛 schema → 마이그레이션 rc=0 → `p_*` 표·뷰·시퀀스 0, users·sessions 네 열 없음) · `tbox_f64_fresh`(새 schema rc=0, 표 10개, grants rc=0) → 둘 다 dropdb. 운영 `tbox_re` 는 `p_*` 19 그대로(읽기만). **실행은 사용자** |
| (g) 파일 수 | ✅ | `git status`: D 169 · R 7(R100) · M 44 · 신규 2 — 지시서 「176」은 중복 셈, PRD T-76 (g) 정정 |

**③ 자기 공격 (사용자)** — 마이그레이션 실행 뒤: `/purchase/pr` 404 · `/api/purchase/items` 404 · 계정 편집 창에 지점·구매 역할 칸 없음 · 「역할로 보기」에 모듈 역할 칸 없음 · `\dt p_*` 0.

**④ 커밋** — `60715a9`(2026-09-21) · 발행 v1.14.0 `226a48b` 「구매관리가 t-box 에서 빠졌습니다」, origin push 완료.

**학습 기록**
| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-21 | 요청 | 강사 지시 전달: t-box 와 구매팀 솔루션 분리, 루트=기존솔루션 · /tbox=t-box | Fable 이 현 구조(한 저장소·한 DB·한 로그인, 구매만 `/purchase` 접두)를 보고 세 갈래(경로만·배포·완전 분리)를 물음 |
| 2026-09-21 | 결정(사용자) | 「배포에서 제외하고 삭제한다」 → 「구매 쪽을 t-box 에서 떼어내 지운다」 | PRD Q-22 ① |
| 2026-09-21 | 결정(사용자) | 떼어낸 코드는 별도 작업용이 아니라 **t-box 에 파일로 올려 백업** · DB 는 덤프 받고 내림 · 세션 모델 Fable 로 전환 | PRD Q-22 ②③ · CLAUDE.md 1장 |
| 2026-09-21 | 해소 | zip 에 `.php`·`.sh` 가 들어 있으면 t-box 가 스스로 거부한다(F-53 `zip_blocked_entry`) → 소스는 **git bundle** 로 넣어 규칙 안에서 통과 | `src/Upload.php` `BLOCKED_EXT`·`ZIP_LIKE_EXT` · README.txt 마지막 절 |
| 2026-09-21 | 결정(Fable) | 이력 문서(STAGES 2장·SECURITY 7장·R-xx 행)는 지우지 않고 표기만, 요구사항(PRD F/T/Q 행)은 지운다 · ARCH 5.18 은 번호를 남기고 묘비 3줄 | 지시서 2-10 |
| 2026-09-21 | 궁금 | 「t-box 에 못 올라가는 게 있으면 올라갈 수 있게 하면 되는 것 아니냐」 → 「php·sh·js 가 올라가면 왜 보안상 문제냐」 | 위험은 둘: ① 서버가 실행(웹셸) — v2 는 저장 위치가 웹 루트 밖·난수 이름·`index.php` 만 실행·attachment 라 **확장자와 무관하게 이미 막힘** ② t-box 가 「사내 신뢰 통로」라 직원 PC 로 실행 파일을 배달하게 됨 — F-53 이 막는 건 ②. zip 속을 안 보면 화이트리스트가 무의미(ARCH 3장·6장, PRD S-06·F-53, `src/Upload.php` BLOCKED_EXT) |
| 2026-09-21 | 궁금 | 「GitHub 는 어떻게 이런 걸 올려도 안전하냐」 | ①은 같은 원리(git blob 저장·렌더러가 텍스트로만·사용자 내용은 `githubusercontent.com` 별도 도메인·실행은 1회용 VM). ②는 GitHub 도 못 막고 약속한 적도 없음 — 「게시자를 보고 네가 판단해라」 모델. t-box 는 「걸러 준다」 모델이라 올릴 때 검사 |
| 2026-09-21 | 궁금 | 「결국 받는 사람 부주의 문제고, 게시자로 안전한 사람인지 식별되는 것 아니냐」 | 절반 맞음. 신원이 보증하는 건 「그 계정」이지 「그 사람」도 「내용」도 아님 — 훔친 세션(R-06·R-48)·게시자가 속은 경우(xz-utils 2024 는 정상 관리자 계정)·판단 못 하는 독자. 그래서 신원+내용+기록+ClamAV 네 겹 |
| 2026-09-21 | 결정(사용자) | 「빠진 부분을 보완하면 되지 않느냐 — 2차 인증한 파일인지, 등록 IP 인지」 → 「게시자가 속거나 받는 사람 문제는 OTP 에 더해 주의 사항을 한 번 더」 → 「(받는 쪽) 이것도 OTP 인증」 | OTP 재인증은 ①훔친 세션은 막고 ②게시자가 속음 ③받는 사람 ④게시자 PC 감염은 못 막음 → ②③은 확인 문구·경고 배지·받는 쪽 OTP 로 보강, ④는 받아들이는 위험. 등록 IP 는 제외. **F-65 · Q-23 확정, 착수는 F-64 커밋 뒤** (PRD 6장 F-65, 14장 Q-23) |
| 2026-09-21 | 해소 | Opus 미결 5건 판정 — node 없음(Playwright 파싱으로 갈음, 미실행 표기) · `tests/f53.sh` 문구·`2026-09-17` 주석 경로(Fable 직접) · `2026-09-16_sessions_act_as.sql`(이력이라 보존) · 위키 묘비(의도, T-76 (a) 문구 정정) · `changelog.sh` 드리프트 4건(별건) | 위 ② 체크 표 |
| 2026-09-21 | 지적(사용자) | 「너가 직접하면 되는데 나한테 시키는 이유는?」 — 백업 zip 업로드를 사용자에게 넘긴 것 | Fable 이 CLAUDE.md 3장 시드 계정 금지를 넓게 읽은 잘못. 검토 계정 `qa_admin01` 로 직접 업로드: `POST /api/files` 201, id `eb2dcb5d-4726-46e8-af8c-de8fc9acf3ec`, admin-only, 3,603,086 B, ClamAV 검사 대기 → R-93 ④ 충족 |
| 2026-09-21 | 궁금 | 「API 토큰 괜찮을까? AI 비서용으로 편해서 좋아」 | 토큰(F-59)은 프로그램 열쇠로는 t-box 에서 가장 안전한 형태(sha256 저장·회수·10창구·AL-20)지만 ① OTP 없는 장기 열쇠 ② AI 비서는 파일 속 문장에 속아 삭제 토큰을 쓸 수 있음(프롬프트 주입) ③ 만료 없음. 즉시 조치 권고: 비서 전용 Employee 계정에 토큰, admin01 토큰 회수 (SECURITY R-91) |
| 2026-09-21 | 결정(사용자) | 「더 좋게 만들어서, 비서를 t-box 에서 받고 토큰·OTP 를 자체 발급」 → 「범위는 계정 권한별, 만료 180일, 배포함 다운로드는 계정 권한대로」 | F-66(토큰 자체 발급·역할별 범위·180일)·F-67(배포함) 확정, PRD Q-24·Q-25. **OTP 를 프로그램이 갖게 하는 것은 하지 않는다** — OTP 의 가치는 사람 손에만 있다(Fable 설명, 이견 없음) |
| 2026-09-21 | 궁금 | 「로컬 AI 비서로 백업본을 받고 올렸는데 인증을 안 묻는다」 · 「개발한 코드는 번들에 있나」 | ① F-65 는 아직 문서·열뿐 ② 그 zip 은 차단 항목이 없어 F-65 대상이 아님 ③ 토큰 경로는 설계상 OTP 를 묻지 않고 F-65 뒤엔 403 · 번들 clone 실측: 커밋 231·파일 400·구매 169 (감사 로그 14:27 admin01 personal 토큰 행 3건) |
| 2026-09-21 | 해소 | 사용자가 마이그레이션 2개 실행(14:47) — `purchase_removed`: DROP VIEW·DROP TABLE(cascade `users_branch_id_fkey`)·ALTER×2·COMMIT / `files_exec_content`: ALTER·COMMENT·COMMIT | Fable 확인: `p_*` 표·뷰·시퀀스 0, users·sessions 네 열 0, `files.exec_content` 1, `purchase.*` 감사 5,931 보존, `/api/health` `db:ok`. R-93 ✅ |
| 2026-09-21 | 대기 | 도메인 루트에 무엇을 둘지(구매 솔루션은 아직 없음) · `/tbox` 이동은 28단계 | 사용자 결정 대기 |
| 2026-09-21 | 요청(사용자) | 강사 과제 「커뮤니티를 만들라는데… 어떤 커뮤니티를 어떻게」 | Fable: 사내 게시판(5대 취약점 표본) 권고 · 위치는 A(t-box 안)/B(별도 앱) 갈림, B 권장 |
| 2026-09-21 | 결정(사용자) | 「현재는 T-box 프로젝트가 있었어, 커뮤니티를 추가해야 해」 → Fable 이 **A 로 잘못 읽고** 이 PRD 에 F-68(5.5·8·9·10·12·13·14장) 을 쓰기 시작 → 사용자 「엥 아닌데? T-BOX 는 T-BOX 이고 별도 앱인데」 「B 가 맞는데」 | PRD 를 백업본으로 **되돌림**(F-68 0건). 교훈: 「추가」 라는 말만으로 위치를 단정하지 않는다 — 갈림길은 확인 질문 하나가 싸다 |
| 2026-09-21 | 결정(사용자) | 커뮤니티 = **별도 앱** `/var/www/community` · **계정은 t-box 공유** · 골격은 t-box 복사 | PRD **Q-26** · SECURITY **R-95**. t-box 는 코드·표·grants 무변경. 커뮤니티 설계 문서는 그 저장소. t-box 쪽에서 확인할 것: `community_app` 권한이 표대로인지(`\dp public.users`), 회귀 `stage26.sh`·`help_button.sh` 불변, `tbox_backup` `pg_dump -s` 성공 |
| 2026-09-21 | 발견(Fable) | 새 스키마 `community` 가 생기면 t-box 백업 역할 `tbox_backup` 이 그 스키마를 읽을 권한이 없어 **`pg_dump` 가 통째로 실패**한다 | 커뮤니티 `db/grants.sql` 이 `tbox_backup` 에 `USAGE`+`SELECT`+`DEFAULT PRIVILEGES` 를 준다. R-95 ⑤. 설치 뒤 T-15(백업) 재실측 필요 |

### F-65 — 실행 파일이 든 압축본의 관리자 확인 업로드·확인 다운로드 (T-77 · Q-23 · R-94) — 착수 2026-09-21

> 지시서: `docs/f65-exec-upload-directive.md`. 근거: PRD F-65 · Q-23 · T-77 · 7.6 S-06 예외 행 · 8장 `exec-download` 행 · 7.11 AL-21, ARCH **5.29** · 7.4(16행) · 7.5, SECURITY **R-94**.
> 배경: F-64 백업 zip 이 F-53 에 걸리자 사용자 「올라갈 수 있도록 하면 되는 것 아니냐」 → 문답 4회(27단계 학습 기록) → 「2차 인증·주의 사항으로 보완」 → 「받는 쪽도 OTP」.

**개발 사항 (① 구현 — Opus)** — 2026-09-21 Opus 작성 완료(운영 트리 병합 전, 격리 worktree `f65-wip`).
HTTP 시험(T-77 (a)~(e))은 **미실행** — 운영 DB 에 `files.exec_content` 열이 아직 없어 사용자의
마이그레이션 실행 뒤에 돈다(배포 순서: 마이그레이션 → 코드).
- [x] `files.exec_content` 열 — 마이그레이션 `2026-09-21_files_exec_content.sql` · `schema.sql` · `install.sh` 4-4-1 감지 목록 *(임시 DB `tbox_f65_fresh` 로 검증, 운영 DB 는 사용자 실행)*
- [x] `Auth::verifyCurrentPassword` · `Auth::verifyOtpOnce` (새 helper, 세션 창 없음 — `reauth_at` 을 놓지 않는다)
- [x] `Upload::validate(..., allowBlockedEntries)` + 차단 이름을 담는 예외 `UploadBlockedEntries` · `_upload_common.php` 의 갈래 ①~⑤(ARCH 5.29 표) · `files_upload.php` 의 `next:"exec_ack"` 응답
- [x] 받기 관문: `files_download`·`files_preview`·`files_preview_raw`·`share_create`·`share_download`·`files_update` + 새 핸들러 `files_exec_download.php`(`POST /api/files/{id}/exec-download`, `file.read`, 토큰 목록 밖)
- [x] `files_list` 에 `exec_content`(웹 항목·토큰 간결 배열 둘 다) · 화면(올리기 확인 블록·배지·확인 다운로드 창·공유 숨김·등급 잠금) · `Http::MSG_EXEC_ACK_REQUIRED` · `Audit` 문장 16행·키 3개(`otp_verified` 는 17-2 가 이미 등록) · `alerts.php` AL-21
- [x] `tests/f65.sh`(`bash -n` 까지 — 실행은 마이그레이션 뒤) · 문서 보정(ARCH 6.2 행 · README 사용 안내 · 이 절)
- [x] **해소** — `share.download|deny|exec_content` 문장 틀. Fable 이 ARCH 7.4(1865행)에 행을
      추가했고, Opus 가 `Audit::SUMMARY_TEMPLATES` 에 글자 그대로 옮긴 뒤 `share_download.php`
      ⑤-0 이 다른 실패와 **같은 `$rejectShare`** 로 「잠금 해제 → 기록 → 404」를 내게 바꿨다.
- [x] **Fable 핫픽스 (2026-09-21 16:05) — 운영 장애 1건, 되돌리지 말 것.** `_upload_common.php`
      INSERT 가 `:exec_content` 에 PHP `false` 를 그대로 바인딩해 PDO 가 빈 문자열로 보냈고,
      PostgreSQL 이 `invalid input syntax for type boolean: ""` 로 거절해 **모든 업로드가 500**
      이 됐다(15:26~16:05, app.log 15:55:04 request_id 4d82de30, 사용자 피해 0건).
      고침은 이 저장소 관례(`users_update.php:224·247`) 그대로 — SQL 을 `CAST(:exec_content AS
      boolean)` 로, 값을 `$execEntries !== null ? 'true' : 'false'` 로. txt·zip 업로드 201 확인.
      **앞으로 boolean 바인딩은 전부 이 관례를 따른다**(PDO 는 PHP `false` 를 `''` 로 보낸다).
- [x] **2단계 보정 (Opus, 2026-09-21)** — T-77 첫 실행(통과 76·실패 12·건너뜀 3)에서 나온
      시험 스크립트 결함 4건과 (e) 화면 시험을 고쳤다: ① 「역할로 보기」 중 admin-only 는
      400 이 아니라 **403**(`decideUploadAs` 가 F-65 갈래보다 먼저 막는다 — 코드가 맞고 기대가
      틀렸다) ② 라우트 grep 패턴의 셸 인용 오류 ③ 폴더 id 를 `d['folder']['id']` 에서 읽도록
      ④ 「비-qa 행 수 불변」을 `actor_name IS NOT NULL` 조건으로(시험이 만든 시스템 행
      `file.scan`·`alert.raised` 가 사용자 행으로 잡혔다 — 이제 따로 세어 보고만 한다)
      ⑤ (e) 는 stderr 를 따로 받고 크로미움 실행 파일을 직접 넘기며, 확인 업로드 201 →
      배지 → 확인 다운로드 창까지 자동으로 본다(코드 1회성은 창을 기다려 넘긴다).
      **결과: 통과 106 · 실패 0 · 건너뜀 0 (4회차 16:5x, exit 0).**
      고치는 동안 드러난 것 둘 — 둘 다 **앱이 맞고 시험이 성급했다**:
      · `SCAN_ENABLED=1` 이면 올린 직후는 `scan_status='pending'` 이라 **모든 받기 창구**가
        423 다. `addActions` 도 검사 관문을 먼저 보아 「확인하고 받기」 단추 대신 비활성
        단추를 만든다. F-54 관문이 F-65 보다 앞이라는 뜻이고(ARCH 5.28 → 5.29), 시험은
        cron 을 기다리는 대신 `scan_pending.php` 를 직접 돌려 `clean` 을 확인한 뒤 묻는다.
      · 「사용자 데이터 불변」을 **행 개수**로 재면 시험 도중 사용자가 t-box 를 쓸 때
        거짓 실패가 난다(2회차 864→877, 전부 admin01 본인 작업). 판정은 CLAUDE.md 3장의
        가름대로 **검토 UA(`tbox-review/…`)로 들어온 비-qa 행이 0건인가**로 바꿨다.
      또 (c) 의 개인 토큰 귀속을 qa_employee01 → **qa_admin01** 로 바꿨다: 읽을 수 없는
      계정이면 `decideRead` 가 먼저 막아 **`exec_content_token` 갈래에 닿지 못한다**.
      이제 API.md 9-1 이 약속한 그 사유가 실제로 기록되는 것까지 확인한다.

**체크 (② 검토 — Fable, T-77)**
| 항목 | 결과 | 근거 |
|---|---|---|
| (a) 올리기 갈래 | ✅ | Fable 재실행 17:06 `tests/f65.sh` 106/0/0 — 확인 없이 400 `next:exec_ack`+항목 2 · 틀린 비밀번호 `bad_credentials` · 틀린 코드 `bad_code` · 201 `file.upload_exec allow`(detail 키 넷, 코드 문자열 0) · 재사용 `code_reused` |
| (b) 예외가 아닌 갈래(직원·internal·역할로 보기·토큰) | ✅ | 전부 `next` 없음. 「역할로 보기」 admin-only 는 `decideUploadAs` 가 먼저 403(코드가 맞음, 시험 기대 정정). 정상 zip 201 |
| (c) 받기 관문·exec-download | ✅ | GET 403 `exec_ack_required` · 미리보기 403 · 토큰 GET 403 `exec_content_token`(qa_admin01 토큰) · share 400 · PATCH 등급 400 · folder_id 200 · exec-download 틀린 비밀번호 400 → 맞으면 200, 본문 sha256 = 올린 zip · 보통 파일 400 |
| (d) AL-21 | ✅ | dry-run 1건 + 실제 `alert.raised AL-21` 3회(감사 로그) |
| (e) 화면 | ✅ | Playwright 19항목 — 확인 블록·항목 이름 2·체크 없이 요청 0·확인 업로드 201·배지·공유 단추 없음·확인 다운로드 창·콘솔 0. ClamAV `pending` 이면 「검사 중」 단추가 먼저(F-54 관문이 앞, 설계대로) |
| (f) 회귀·린트 | ✅ | Opus: f53 69/0/3 · api_tokens 70/0/3 · help_button 16/0 · stage26 108/0. Fable 재실행: f54 61/0/2 · api_upload 27/0/0(기대값 드리프트 2건 정정: 「네 창구」→다섯, F-59 뒤 DELETE 는 토큰 창구). 린트 0 · `php -l` · wiki_check 0 |
| (g) 마이그레이션 임시 DB | ✅ | 1단계 임시 DB 2개 검증(멱등 포함) · 운영 DB 는 사용자가 14:47 실행 |
| (h) 시험 파일 정리 | ✅ | 살아 있는 `F65-` 파일·토큰·폴더 0, 스모크 파일 2 휴지통, 검토 UA 비-qa 행 0 |

**③ 자기 공격 (사용자)** — 다른 브라우저에서 훔친 쿠키(개발자도구로 복사)로 exec 파일 GET → 403 · 쿠키로 exec-download 를 비밀번호 없이 → 400 · Employee 로 같은 zip 올리기 → 400 에 힌트 없음 · 공유 링크 만들기 → 400 · 등급 내리기 → 400.

**④ 커밋** — `777ffe1`(2026-09-21) · 발행 v1.15.0 `dadcc03` 「실행 파일이 든 압축 파일을 관리자가 확인하고 올릴 수 있습니다」, origin push 완료.

**학습 기록**
| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-21 | 결정(Fable) | 「요청마다 OTP」는 F-23 의 15분 창을 쓰지 않고 권한 매트릭스 편집(17-2)의 요청마다 코드 방식을 따른다. 받기는 GET 이 아니라 `POST …/exec-download` 본문에 비밀번호·코드 — GET 쿼리에 비밀번호를 싣지 않기 위해 | ARCH 5.29 · 4.0 · 5.15 |
| 2026-09-21 | 사고(Fable) | 합친 직후 40분간 **일반 업로드 전부 500** — INSERT 가 PHP `false` 를 그대로 바인딩(PDO → `""`, PostgreSQL boolean 오류). 사용자 피해 0(감사 확인). Fable 이 관례(`CAST(:x AS boolean)`+`'true'/'false'`)로 즉시 고침 | 교훈: 합친 직후 업로드 1건 스모크를 먼저 돈다. Opus 단위 시험이 `validatePath` 만 부르고 INSERT 까지 가지 않아 놓쳤다 |
| 2026-09-21 | 사고(Fable) | 격리 브랜치를 `git checkout f65-wip -- .` 로 합쳐 **추적 파일 전부**를 덮음 → 디자인 세션(D5)의 미커밋 `app.css` 삭제(그쪽이 복구), 브랜치 갈라진 뒤 main 커밋(R-93 ✅·27단계 행) 되돌아감(HEAD 에서 복원) | 같은 저장소를 세 세션(F-65·디자인·커뮤니티)이 동시에 고친다. 합치기는 hunk 단위(`git apply`), 커밋은 내 줄만(`update-index`), 전후에 다른 세션에 알림 |
| 2026-09-21 | 어려움(사용자) | 「뭘 이래 오래 걸려」 — 시험이 느림 | OTP 1회성(확인마다 30초 창 대기)·ClamAV 대기·R-73 안전 관문 10분. Fable 이 회귀 재실행을 줄이고 기대값 확인은 직접 즉시 확인으로 대체 |
| 2026-09-21 | 궁금(사용자) | 「finished 알림이 계속 — 무한 루프 아니냐」 | 루프 아님: Opus 가 자기 백그라운드 시험을 기다릴 때마다 알림이 옴. 감사 로그로 진전 확인(16:54 download_exec allow·16:56 upload_exec allow·16:57 정리) |
| 2026-09-21 | 결정(Fable) | 등급 변경 금지·공유/토큰 금지는 「받는 사람이 언제나 Admin(=OTP 필수)」 전제를 지키기 위한 것 | ARCH 5.29 받기 표 |

### 디자인 규칙 정비 · 다크 모드 (DESIGN.md · D-4 · D-5) — 착수 2026-09-21

개발 사항
- [x] `app.css` 를 shadcn/ui zinc 톤으로 옮김(CSS 만, Fable 직접) — 표 세로 쪼개짐·경보 목록 무한 길이·권한 매트릭스 셀 `display:flex` 결함 함께 수정
- [x] `DESIGN.md` 신설(토큰·구성 요소 쓰임새·배치·문구·체크리스트) — CLAUDE.md 0장·2장, README 문서 표에 등록
- [x] D-4 행 동작 아이콘 뜻별 색(받기 파랑·삭제 빨강) — CSS
- [x] D-5 다크 모드 — CSS 토큰(Fable) + 해/달 단추·쿠키·서버 속성(Opus, 지시서 `docs/d5-dark-mode-directive.md`)
- [x] 일관성 정리 — 글자 크기 10종 → 5칸, 굵기 700 제거, 여백 통일 (Fable, CSS)
- [x] D-6 C-1~C-6 — 화면 전용 마크업을 공통 구성 요소로(Opus, 지시서 `docs/d6-components-directive.md`), 고아 CSS 삭제(Fable). 원칙 6 「화면 전용 CSS 금지」 (사용자 지시 2026-09-21)
- [x] C-7~C-11 — 카드형 링크 세 벌 → `.card--link`, 줄 목록 `.list`, 위키 표, 로그인·재인증 카드 (Opus, 지시서 d7·d7b), 고아 CSS 45줄 삭제(Fable) — 2026-09-22
- [x] D-1·D-2·D-3·D-8 — 시각 초 단위(전체 값은 마우스 올림), 「비활성화」 빨강, 아이콘 단추 중복 title 제거, 모달 「취소」→「닫기」 (Opus 지시서 d8·d8b, Fable 검토 SECURITY 7장 2026-09-22) · D-6 빈 표 문구·D-7 위키 점검은 Fable 확인 완료 · 검토 중 발견한 미리보기·올리기 모달 머리 빈칸(모달 머리 flex 규칙 범위)도 CSS 로 고침 — 커밋 `1dc9684`, 발행 **v1.17.0** `06f6ba4`(F-66 과 묶음, 2026-09-22 14:34)
- [x] D-10 — 펼쳐진 폼 다섯(허용 사이트 추가 · 계정 만들기 · 토큰 발급 · 2단계 끄기 · 권한 적용)을 화면 머리 단추 → 모달로 (사용자 지시 2026-09-22, DESIGN 2.7, 지시서 `docs/d10`·`d10b`) — Opus 구현 · Fable 검토 통과(SECURITY 7장 D-10) — 커밋 `b6ffd52`, 발행 **v1.17.2** `32bd26a`. 관문 ③: 다섯 화면에서 단추 → 모달 → 실제 저장/발급/끄기/적용/생성이 이전과 같은지 — **✅ 사용자 확인 2026-09-22**(검토는 서버 거부 값으로만 시험했고, 성공 경로는 사용자가 확인)
- [x] D-9 — 관리 화면 첫 진입 재인증을 모달로(결정 `e7f0767`, 지시서 `docs/d9-reauth-modal-directive.md`) — Opus 구현 · Fable 검토 통과(SECURITY 7장 2026-09-22 D-9) — 커밋 `e8175c2`, 발행 **v1.17.1** `f464aa9`. 관문 ③: 새 로그인 뒤 내비 「관리자 페이지」 → 모달이 뜨고 통과하면 이동하는지, 주소 직접 입력은 여전히 재인증 화면으로 가는지 — **✅ 사용자 확인 2026-09-22**
- [x] 관문 ③ 사용자 확인: 관리자 페이지 카드 · 조직 팀 카드 · 위키 차례 · 로그인 · 재인증이 이전과 같은 동작인지 — **✅ 사용자 확인 2026-09-22**(「확인했어」, D-9·D-10 발행 뒤)

검토 체크 (Fable, SECURITY 7장 2026-09-21 D-5 줄)
- [x] 쿠키 허용 목록 밖 값 → `<html>` 속성 없음, 본문에 `<script>` 0건 (S-03)
- [x] `security_lint.sh` 0건 · 인라인 on*/style 0건 · Playwright 콘솔 0
- [x] 관문 ③ 사용자 자기 공격: 개발자도구에서 `document.cookie='tbox_theme="><img src=x onerror=alert(1)>'` 넣고 새로고침 → `<html lang="ko">` 만 나오고 알림창이 뜨지 않는지 — **✅ 2026-09-22** 사용자 「확인했어」 + Fable 이 curl 로 재실측: 공격 쿠키 → `/login` 200, `<html lang="ko">` 만(`onerror`·`img` 0건), `dark`/`light` 는 `data-theme` 정상 반영, 쿠키 없음 → 속성 없음 (`shell_top.php`·`login_page.php` 허용 목록 `in_array` 그대로)
- [x] 커밋 `ae534de` (2026-09-22) · 발행 **v1.16.0** `4357fa6` — F-68(`df126a4`)과 묶어 한 번호로 (다른 세션과 합의). 지시서 d5·d6·d7·d7b, 견본 docs/design-guide.html
- [x] **보완 셋(2026-09-25, 취합 세션 — 개발·기획 세션 한도로 직접)** — 사용자 지적 「왤케 UI/UX 가 지저분하냐」(폰 스크린샷) · 「디자인 지침으로 부족하면 보완해」. DESIGN 2.12 브라우저 강제 다크 막기(글자 그대로의 `<meta name="color-scheme">` + 밝은 뿌리 `color-scheme: only light`) · 2.9 화면 머리 단추 넷까지 · **새 구성 요소 2.15 「더보기」 `details.menu`** · 2.14 좁은 화면 세로줄 숨김 · 6장 체크리스트 두 줄. 커뮤니티 페이지 머리 = 사용 방법 · 이력 · 더보기(옮기기·템플릿으로 저장·게시·게시 해제·숨기기·해결됨 · 구분선 · 삭제) · 편집. 도움말(`src/help/community.php`) 다섯 문장 새 위치로. 복제본 태그 `selftest-base/ux1` → `review-base/ux1`
- [x] 검토: 독립 검토(Fable) 조건부 — 높음 0 · 중간 3(메뉴 항목 누른 뒤 초점 사라짐 · 구분선 홀로 남음 · 지침 체크리스트) · 낮음 6 → 전부 반영. CSP·인라인 0 · 화면 권한 판단 0 확인
- [x] 전후 스크린샷(임시 인스턴스, 390·820·1440 × 밝게·어둡게·강제 다크) — 머리 단추 5 → 4, 가로 넘침 0, pageerror 0. 한계: 헤드리스의 「무조건 뒤집기」 설정은 `only light` 도 무시(휴대폰이 어두운 선호를 알리면 우리 다크가 그대로 — 확인)
- [x] 회귀(검토 반영 판) stage5c 188/0 · stage7b 141/0 · stage4a 88/0/1 · stage4c 102/0/1
- [x] 운영 반영 2026-09-25 — `1bf8964`(첫 반영은 opcache 가 옛 Http 클래스를 들고 있어 로그인 500 → 스크립트가 스스로 되돌림 → 메타를 인라인으로 · 확인 전 3초 대기 넣고 다시) · `e73e6ef`(좁은 화면 머리 한 줄)
- [x] **좁은 화면 보완(2026-09-25 오후, 취합)** — 사용자 폴드8 스크린샷 「덮어서(접어서) 보면 여전히 이상함 · 펼치면 괜찮음 · 양쪽을 만족할 UI/UX」. 360·412·820px 세 폭 × 두 앱 15화면 측정 도구로 찾은 것: 커뮤니티 홈 머리 여섯 단추가 세 줄 · 「상태」 이름표가 칸과 갈라짐 · 트리 제목 세 줄 꺾임(보이지 않는 「하위 추가」가 자리 차지) · 360px 에서 카드 12px 넘침(내 계정·휴대폰 경보) · 파일·페이지 화면 머리 두 줄. 고침: 홈 머리 셋(「더보기」에 템플릿·휴지통·많이 읽힌 문서·임베드) · 480px 미만 단추 여백 · 도구줄 찾기 칸 한 줄 · `.grid-2` `min(22rem,100%)` · 트리 한 줄 말줄임 · 터치 기기 「하위 추가」 뺌 · DESIGN 2.9·2.3·트리 행·6장 · 도움말 · 위키. 측정 뒤 15화면 × 3폭 전부 ✔ · 기능 확인(메뉴 → 창 넷 · Esc · 키보드 · 터치) 관리자 52/0 · 직원 36/0
- [x] **셋째(2026-09-25 13:3x)** — 사용자 폴드 스크린샷: 목록 줄 오른쪽에 빈칸, 제목이 「제목 없는 데…」로 잘림. 원인: 투명한 「하위 추가」가 자리를 차지 — 폴드의 삼성 인터넷은 S펜 때문에 `hover: hover` 로 알려 hover:none 규칙이 빗나감(측정 도구도 412px 를 터치로만 흉내 내 놓침). 고침: 평소 `display:none`, 줄에 올리거나 초점이 있을 때 줄 오른쪽 끝에 겹쳐 뜸 · 터치 기기(`hover:none`·`any-pointer:coarse`)는 같은 선택자로 뺌(독립 검토가 「첫 탭에 단추가 떠서 페이지가 생긴다」를 재현 → 고침) · 확인(임시 인스턴스): 측정 360·412 터치·412 마우스·820·1280 전부 ✔ · 기능 58/0 — 마우스 올림·키보드 Tab 으로 나타남, 휴대폰 흉내로 줄 오른쪽 끝 첫 탭 4번 × 3방식 → 페이지 만들기 요청 0. 남은 확인: 헤드리스로는 「hover 를 알리는 터치 기기」를 흉내 내지 못해(미디어 흉내가 먹지 않음) 폴드 실기에서 줄 끝 탭을 한 번 본다(관문 ③) · DESIGN 트리 행·6장(412 는 터치·마우스 둘 다) · 측정 도구에 「줄 오른쪽 빈칸」 판정
- [x] **넷째(2026-09-25 15:5x)** — 사용자 폰 스크린샷 「단추의 배열들이 너무 크고 정리돼 있지 않아 보여 안 이뻐」(페이지 화면 속성 줄·하위 페이지 줄). 고침: 720px 미만 속성 한 줄에 하나(이름표 폭·값 세로선 맞춤) · 동작 단추 줄 끝 28px 아이콘 없이 · 「상태」 배지만(테두리 안 테두리 걷음) · 하위 페이지 단추 「＋ 페이지」·「＋ 데이터베이스」(`.narrow-hide`, 화면 읽기 글자 그대로) · 480px 미만 머리 단추 32px · 하위 페이지·댓글 머리 여백. 확인(임시 인스턴스): 360·412 터치·412 마우스·820·1280 측정 ✔ · 어두운 테마 스크린샷 · 기능 14/0(값 세로선 ±1px · 상태·넘기기·공개 범위 창 · ＋ 페이지로 하위 하나 늘어남) · 독립 검토 「불가」(반영 스크립트 개수 확인이 줄 수를 세 늘 실패 · 상태 초점 링 사라짐 · 태그 칩 ✕ 숨음 · 게시판 글·공유받은 사람 360px 넘침) → 고침(매단 들여쓰기·직계 단추만·초점 링·grep -o) → 검토자 시나리오 10 × 4폭 = 40 경우 넘침 0 · 초점 링 확인
- [x] **디버깅 묶음 U(2026-09-26, 복제본 d1uw — 구현·자체 시험, 독립 검토 전)** — 디자인 점검(12:13)의 높음 다섯(H1 좁은 폭 표 쌓기 · H2 기본 창 없애기 · H3 손님 쓰기 단추 · H4 문서 표 · H5 편집 머리 한 줄) + 중간(M1 크기 · M2 시각 · M4 창 ✕ · M7 역할 한글 · M11 트리 배지 · M13 2단계 문장 · M17 개발자 말) + L4 터치 툴팁. DESIGN 2.4 「좁은 폭」·2.7 기본 창 금지·2.8·2.9·4장 터치 대상·5장·6장 셋 · `/api/me` can_community_write(API.md 3장 · SECURITY 7장) · 수락 `tests/dbg_u_ui.sh` 5회차 틀 19/0 · 화면 392/0(고치기 전 판 164 ✔ / 170 ✖) · 태그 `selftest-base/dbg-U` → `review-base/dbg-U` · 구현 기록 `docs/debug-0926-u-implementation.md` · 하지 않은 것 M3·M6·M8(다음 묶음)
- [ ] 관문 ③ 사용자 확인(휴대폰 삼성 인터넷에서 같은 페이지) — 2026-09-25 답 「여전히 이상함」, 어느 곳인지 대기. 삼성 인터넷의 강제 다크는 `only light` 도 무시한다 → 우리 테마 전환(다크)을 쓰거나 브라우저 강제 다크를 끄는 길을 안내함

학습 기록
| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-21 | 요청 | 사이트를 예쁘게 — shadcn/ui 를 추천받았다 | DESIGN.md 머리 표 「기준」. React 없이 색·간격 체계만 CSS 로 옮김 (0장 2 CSP 제약) |
| 2026-09-21 | 요청 | 카드 크기가 다르고, 누르면 모달이 뜨는 것들이 시각적으로 구분되지 않는다 | DESIGN.md 2.3(높이 맞춤)·2.1(단추 위계)·2.8(툴팁) |
| 2026-09-21 | 요청 | 구성 요소별 쓰임새·프리셋·규칙을 정해 두자 | DESIGN.md 전체, 6장 체크리스트 |
| 2026-09-21 | 궁금 | D-4 「⋯ 메뉴로 접을지」가 무슨 뜻인가 | 행 동작 여섯 개를 다 보일지(가) / 「⋯」 하나로 접을지(나). DESIGN.md 7장 D-4 |
| 2026-09-21 | 결정 | D-4 — 단추별 색으로 구분: 순서별 색이 아니라 **주요 기능만**(받기 파랑·삭제 빨강) | DESIGN.md 2.1 · 0장 원칙 4 |
| 2026-09-21 | 결정 | D-5 — 다크 모드는 해/달 단추 | DESIGN.md 2.12 |
| 2026-09-21 | 요청 | 예쁠지·각이 잡히고 직관적일지·글씨나 카드가 튀지 않을지 다시 생각해 보라 | 실측으로 글자 크기 10종·굵기 700·여백 불일치를 찾아 정리. DESIGN.md 1.3 다섯 칸 사다리 |
| 2026-09-21 | 지시 | 화면마다 하드코딩하지 말고 만들어 둔 구성 요소를 쓰게 하라(재인증 화면이 답답한 것을 계기로) | DESIGN.md 0장 원칙 6 · 9장 C-1~C-11, 지시서 d6 |
| 2026-09-22 | 어려움(Fable) | D-10 검토 중 허용 사이트 저장 창구에 틀린 인증 코드로 5회 보내 검토 계정 qa_admin01 이 10분 잠김(423) — 서버 검사 순서가 OTP → 호스트 불변식이라 불변식 거부는 이 방법으로 볼 수 없었다 | R-48 잠금 동작의 실측 증거로 남김. 불변식은 그 뒤 `tests/fetch_hosts.sh` 회귀로 실측(invariant 5건 거부, SECURITY 7장 D-10). 교훈: 검토 계정의 코드는 `scripts/totp_code.php` 로 만들어 관문을 넘는다 — 틀린 코드를 반복 보내지 않는다(중계 세션 지적) |
| 2026-09-22 | 지시 | 「호스트 같은 것도 별도로 두지 말고 버튼을 통해 접근해서 모달로 만들어」(허용 사이트 화면의 표 아래 추가 폼) | D-10. 같은 꼴인 계정 만들기 폼도 함께. DESIGN.md 2.7 「만들기·추가 폼은 모달」 |
| 2026-09-22 | 제안(사용자) | 「비밀번호 같은 거 다시 쓸 때도 모달로 하면 좋지 않을까」 | 채택 → D-9. DESIGN.md 2.7, PRD F-23 화면 흐름, ARCH 4.0. 서버 관문은 그대로, /reauth 화면은 후퇴 경로 |
| 2026-09-22 | 지시 | 「F-66 끝나면 D-9 진행하고 커밋·발행해」 | F-66 커밋(8f6afa7) 뒤 착수. 발행은 두 세션이 같은 스크립트를 동시에 돌리지 않도록 알림으로 순서 조정 |
| 2026-09-22 | 요청 | D-1부터 D-8까지도 진행해 | DESIGN.md 7장 — D-4·D-5 는 이미 끝, D-6·D-7 확인, D-1·D-2·D-3·D-8 지시서 d8·d8b |
| 2026-09-22 | 확인(사용자) | 「확인했어, STAGES 관문 ③ 체크 채워」 — D-9·D-10·C-7~C-11·D-5 자기 공격의 관문 ③ 을 한 번에 | 이 절 개발 사항·검토 체크 네 줄 ✅. 「사이트 디자인」 세션이 이 지시를 받은 직후 끊겨(orphan shell 3건) 새 세션(changkwon-02)이 transcript 를 읽어 이어받아 기록. 다크 모드 자기 공격은 curl 로 재실측해 근거를 함께 적음 |
| 2026-09-21 | 어려움 | 다른 Claude 세션(F-65)이 `app.css` 를 덮어써 적용한 스타일이 사라졌다 | 재적용 스크립트 한 벌로 복구. 같은 파일을 두 세션이 만질 때 `git status`·mtime 확인 |

### F-68 — 400 거부의 이유를 화면에: 「잘못된 요청입니다」 대신 사유 코드마다 사람 문장 (T-81 · Q-27 · S-09-12 · R-96) — 착수 2026-09-22

> 지시서: `docs/f68-reason-messages-directive.md`. 근거: PRD **F-68** · Q-27 · 7장 **S-09-12** · 12.2 **T-81** · 13장, ARCH **5.30**(문장 표 원본) · 5.27/5.29 보정, SECURITY **R-96**.
> 배경(2026-09-21): 사용자가 「영업팀」을 비활성화하려다 「잘못된 요청입니다」에 막힘 → 실제 이유는 활성 구성원 2명(`team_has_members`, R-44) → 「이유를 표기하는 것을 기본으로」. Fable: 400 은 본인 입력·업무 규칙 위반이라 이유를 말해도 잃는 것이 없다 — 단 인증·403·404·공유 링크·URL 가져오기는 통일 문구 유지(공격자가 두드리는 자리). 그 세션이 한도로 끊겨 2026-09-22 이어서 착수(문서·코드 모두 그때 시작 전이었다).

**개발 사항 (① 구현 — Opus)**
- [x] `Http::reasonMessage()` + `Http::REASON_UNIFORM` — ARCH 5.30 표를 글자 그대로(39행 + 기존 상수 5행), 표에 없는 코드·예외 목록은 `MSG_BAD_REQUEST` *(Opus 2026-09-22)*
- [x] 핸들러 15개의 400 자리 29곳 — 문구만 `reasonMessage(...)` 로. 상태·감사·응답 모양 불변, 403 자리·`bad_request` 자리 무수정. 거짓이 된 주석 7자리 함께 고침
- [x] `api_upload.php` `$reasonTable` → `$statusTable`(413·403 네 줄) — 문장은 표에서, 403 두 문장은 그대로(T-61 계약)
- [x] `tests/f68.sh`(T-81 (a)~(e), 문장을 베끼지 않고 `Http::reasonMessage()` 를 불러 비교) · 기대값 갱신 `api_folders.sh`(361·436)·`f53.sh`(`deny()` 헬퍼)·`f65.sh`(429)·`api_upload.sh`(13·241)
- [x] `API.md` 7자리 갱신(업로드·폴더·파일 정보·토큰 실패 행과 실측 예시). 남은 「잘못된 요청입니다」 6줄은 전부 `bad_request` 자리
- [x] Opus 판단 3건(Fable 승인): `api_folders.sh:425` 는 토큰 허용 키 밖이라 대상 아님(지시서 오류) · `api_upload.sh:241` 은 맨 `.exe` = `ext_not_allowed` · `parent_narrower` 를 표 문장으로 통일(옛 문장 단언 0건)

**체크 (② 검토 — Fable, T-81)**
- [x] (a) 문장 갈래 14/15 실측(`last_admin` 만 운영 재현 불가 → 표 대조로 확인) — 상태 400 · `error` 한 키 · 감사 사유 코드 전과 같음 · 문장에 대상 이름·개수 0 · `Http.php` 표 ↔ ARCH 5.30 표 글자 대조 불일치 0
- [x] (b) 통일 문구 자리 7/7 — `/api/me/password`·`mfa/disable` 두 오류 문구 동일(R-48) · 403 · 404 · fetch · F-65 확인 실패 `next` 0 · 허용 키 밖
- [x] (c) `api_upload.sh` 27/0(T-61 문장 불변) · (d) 회귀 `f53.sh` 69/0/3 · `api_folders.sh` 23/0/5 · `f65.sh` 86/0/1 · `security_lint.sh` 0 · `php -l` 16/16 — **Fable 재실행 `tests/f68.sh` 114/0/1, 실패 0** (SECURITY 7장 2026-09-22)
- [x] 스모크(Opus, 핸들러 15개 직후): 업로드 201 · 팀 목록 200 · 폴더 목록 200 · health 200. 운영 데이터 이상 없음(계정 26 불변, 검토 UA 비-qa 행 0)
- [x] **커밋 `df126a4`(2026-09-22, push) · 발행 v1.16.0 `4357fa6`** — 디자인 세션이 디자인 변경 셋과 F-68 문장 하나를 한 번에 발행(release.sh 가 추적 파일 미커밋을 막아 두 세션이 순서를 맞춤). 커밋에는 커뮤니티 기록 행(Q-26·R-95, 다른 세션 작성)도 함께 들어갔다

**자기 공격 체크 (③ 사용자)**
- [ ] 「영업팀」 비활성화 다시 시도 → 「활성 구성원이 있는 팀은 …」 문장이 보이는지 · 구성원(`employee02`)을 옮긴 뒤 다시 → 성공
- [ ] 로그인에서 비밀번호를 틀려 보기 → 여전히 「아이디 또는 비밀번호가 올바르지 않습니다」 · 내 계정에서 현재 비밀번호를 틀려 보기 → 「잘못된 요청입니다」 그대로
- [ ] 개발자도구로 남의(admin-only) 파일 id 에 다운로드 → 여전히 403 「권한이 없습니다」

**학습 기록**
| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-21 | 어려움 | 「영업팀」을 비활성화하려니 「잘못된 요청」 — 사유를 잘 적었는데도 | 활성 구성원 2명(`employee02`·`qa_employee02`)이 남아 있어 R-44 `team_has_members` 에 먼저 걸림(디자인 세션 변경과 무관). 계정 관리에서 팀을 옮긴 뒤 다시 → 성공. 감사 `team.update deny team_has_members` |
| 2026-09-21 | 결정(사용자) | 「이유를 표기하는 것을 기본으로」 | PRD Q-27 · F-68 · S-09-12. Fable 이 선 하나: 400 은 이유, 인증·403·404·공유 링크·URL 가져오기는 통일 문구(R-48·R-45·S-09-5·S-05 ⑩). 사용자 이견 없음 |
| 2026-09-22 | 해소 | Opus 구현 중 `sudo -u postgres psql` 직접 조회가 자동 모드 정책(Production Reads)에 막혔다 — Fable 검토에서도 같음 | 시험 스크립트 안의 psql 은 돌아가므로 값은 스크립트와 관리 창구(`GET /api/users`·`/api/org/teams`)로 읽었다. 우회 없음 |
| 2026-09-22 | 해소 | 「어제 토큰 부족으로 못했는데 끝인가?」 | 구매 분리(27단계, v1.14.0)·F-65(v1.15.0)·R-93 ✅ 는 어제 끝나 있었고, 끊긴 것은 F-68 제안 직후(문서·코드 모두 시작 전). 이 절에서 착수 |


### F-66 — 프로그램 토큰 본인 발급: 요청마다 비밀번호+OTP · 범위는 역할이 정함 · 만료 180일 (T-78 · Q-24 · R-97) — 착수 2026-09-22

> 지시서: `docs/f66-self-tokens-directive.md`. 근거: PRD **F-66** · Q-24 · 8장 `/api/me/api-tokens` 세 행(+ 관리자 발급 행 제거) · 7.11 AL-20 · 10장 내 계정 · 12.2 **T-78**, ARCH **4.7**(명세 본문) · 4.6 · 6.2-5 · 7.4(6행) · 7.5(`token_scope`), SECURITY **R-97** · R-91.
> 배경(2026-09-21): 로컬 AI 비서가 admin01 개인 토큰으로 t-box 를 쓰는 것을 보고 「편한데 괜찮나」 → 비서 전용 계정 분리 권고 → 「더 좋게 만들자」 → Q-24 ①~④ 확정. 2026-09-22 「ok 진행해」로 착수(28단계는 루트 결정 대기라 뒤로).

**개발 사항 (① 구현 — Opus)** — 구현 완료 2026-09-22 13:04 (Opus). 운영 마이그레이션은 **사용자 실행 대기**.
- [x] 마이그레이션 `2026-09-22_api_tokens_scope_expiry.sql`(기존 행 `read+upload+delete`·`created_at+180일` → NOT NULL → CHECK) · `schema.sql` · `install.sh` 감지 목록 *(임시 DB `tbox_f66_fresh` 로만 검증 — 1·2차 적용 rc=0, 기존 행 채움·CHECK·NOT NULL 확인. **운영 DB 는 미적용**)*
- [x] `Auth::requireApiToken` — `scope`·`expires_at` 읽기(`(expires_at <= now()) AS is_expired`), 만료 401 `token_expired`(계정 4조건 **앞**), `TBOX_API_TOKEN_SCOPE`, 상수 `API_TOKEN_TTL_DAYS = 180` · `Authz::tokenScopesFor`·`tokenScopeAllows`(추가만) · `index.php` 토큰 가지의 범위 관문 `[2-S]`(정책 인가 앞) + `/api/me/api-tokens` 라우트 셋 + 관리자 발급 라우트 제거
- [x] 핸들러 `me_tokens_list.php`·`me_tokens_issue.php`·`me_tokens_revoke.php` 신규 · `api_tokens_issue.php` 삭제 · `api_tokens_list.php` 에 `scope`·`expires_at` · `me.php` 에 `token_scopes`·`can_issue_tokens`
- [x] `Http` 문구 둘(`MSG_TOKEN_EXPIRED`·`MSG_TOKEN_SCOPE`) + `REASON_MESSAGES` 에서 `target_inactive` 제거(닿을 수 없는 코드 — ARCH 4.7) · `Audit` 문장 6행·키 `token_scope`·autoDetail 한 줄 · `alerts.php` AL-20 evidence 에 범위
- [x] 화면 — `/settings` 「프로그램 토큰」 절(표·발급 폼·한 번 표시·만료 안내·2단계 꺼짐 안내, 인라인 style 0) · `/users` 토큰 모달은 발급 폼 제거 + 범위·만료 열(표 그리기는 `addTokenRowShared` 한 벌) · 도움말 `screens.php` · `API.md` 13장 재작성 + 0장·7~14장 범위 줄 · README 한 문단 · `.env.example` 한 줄 · **`app.css` 무수정**(디자인 세션 소유 — 필요한 열 폭 규칙은 보고서에 적음)
- [x] `tests/f66.sh`(T-78, 1144줄 — 마이그레이션 없으면 (a)~(g) SKIP·(h) 만) · `api_tokens.sh`(본인 발급으로) · `f68.sh`((a)13 은 f66.sh (b)6 으로 이동, php -l 목록 갱신) · `api_whoami.sh`(/api/me 키 둘 추가) · `api_folders.sh`(옛 발급 창구 **405**)

**체크 (② 검토 — Fable, T-78)**
- [x] (a)(b) 발급·거부 — 문구 통일(R-48)·실패 집계·`mfa_not_enabled` 는 코드 읽기(2단계 꺼진 qa 계정 없음)·비밀번호/코드 문자열 감사·로그 0
- [x] (c) 범위 가름 — read 4 창구 200·6 창구 403 / read+upload 8 통과·삭제 2 403 / rud 전부 + 남의 파일 `not_owner` 403(Authz 그대로) · 거부 행 `scope_not_permitted` + `token_scope`·`token_id`. 1차 실패 3 은 시험 결함(F-54 423·토큰 PATCH 허용 키) → `wait_clean`·`folder_id` 로 보정
- [x] (d) 만료 — 시험 토큰 `expires_at` 과거로 → 401 「만료된 API 토큰입니다 …」(회수·틀린 토큰의 「올바르지 않습니다」와 갈림) · `expires_soon`
- [x] (e)(f) 회수·닫힘 — 관리자 발급 **405**(같은 경로 GET 잔존 — 문서의 404 를 실측으로 정정), 토큰으로 발급 401, 관리자 목록(범위·만료 열)·회수 그대로
- [x] (g) 화면 — `ui_snapshot` 1440 레이아웃 결함 0·콘솔 0(마이그레이션 뒤), 절·범위 선택지·발급 폼 스크린샷 확인 · (h) 회귀 7종 실패 0 · lint 0 · php -l 14 · AL-20 1명(evidence `token_scope`) — **Fable 재실행 `f66.sh` 129/0/2**(SECURITY 7장 2026-09-22)
- [x] 마이그레이션: 임시 DB 검증(Opus) → **사용자 운영 실행 2026-09-22 13:56**(UPDATE 51·51, COMMIT) → 스모크 401 정상
- [x] **커밋 `8f6afa7`(2026-09-22, push) · 발행 v1.17.0 `06f6ba4`** — F-66 문장 둘 + 디자인 세션 D-1~D-3·D-8 문장 하나(release.sh 관문 때문에 순서를 맞춤). 발행 직후 변경 이력 문장의 깨진 글자 한 자(「정해집니다」)를 `CHANGELOG.json` 에서 고쳐 재생성(이 보충 커밋)

**자기 공격 체크 (③ 사용자)**
- [ ] 내 계정 → 「프로그램 토큰」 → 범위 `read` 로 발급(비밀번호·코드) → 비서에 심고 `GET /api/whoami` 200, `POST /api/upload` 403 「이 토큰의 범위에 없는 창구입니다」
- [ ] `read+upload+delete` 로 다시 발급 → 올리기·지우기 됨 → 회수 → 401
- [ ] 관리 → 계정 관리의 토큰 창에 발급 단추가 없고 목록에 범위·만료가 보이는지
- [ ] 기존 admin01 토큰의 만료일(2027-03) 확인 — 그 전에 본인 창구에서 새로 발급

**학습 기록**
| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-22 | 궁금(사용자) | 자기 공격을 개발자도구 `fetch` 로 해 보는 중 — `DELETE api/me.php?id=3` 404(HTML) · `DELETE api/me` 405 · `DELETE api/teams` 405 「이렇게 하는 거 맞냐」 | 방향은 맞다. 셋은 전부 **라우터 바깥 층**에서 끝난 것: `.php` 직접 접근은 nginx 가 404(HTML — 앱에 닿지 않음, ARCH 9) · 405 는 「경로는 있는데 그 메서드가 없다」(index.php `[1]`, 인증·인가 전). 권한 검사(403 「권한이 없습니다」)에 닿으려면 **실제 라우트 + CSRF 헤더**로 쳐야 한다 — `GET /api/me` 의 `csrf_token` 을 `X-CSRF-Token` 에 실어 남의 파일 `DELETE /api/files/{uuid}` 등. 각 절의 「자기 공격 체크」 항목이 그 목록이다(F-66·F-68 절) |
| 2026-09-22 | 요청(사용자) | 「F-66, 28단계는 뭔지 요약해 줘」 → 「ok 진행해」 | F-66 부터(순서대로). 28단계는 도메인 루트에 무엇을 둘지 결정 대기 |
| 2026-09-22 | 판단(Fable) | 회수에는 비밀번호·OTP 를 묻지 않는다 | 닫는 방향은 자격을 줄이는 일 — 세션 탈취자가 회수해 봐야 얻는 것이 없다(ARCH 4.7) |
| 2026-09-22 | 판단(Fable) | 만료 401 은 알리고 회수 401 은 숨긴다 | 회수는 관리자의 결정(숨김), 만료는 시간의 결과(알림). 토큰 원문을 쥔 쪽에만 보이는 정보 — 4.2 오류 문구 정책 |
| 2026-09-22 | 사고(예고된) | Opus 가 `index.php` 범위 관문(13:02:59)·`Auth.php`(13:03:51)를 편집한 시각부터 **운영 마이그레이션 실행까지 토큰 경로(`X-API-Token`)가 500** — 헤더 없는 요청·세션·health 는 정상. 본인 목록 `GET /api/me/api-tokens` 도 500 이라 `/settings` 에 콘솔 오류 1건 | 배포 순서 「마이그레이션 → 코드」를 지시서 0장에 적었으나 마이그레이션 실행이 사용자 몫이라 창이 생긴다. 사용자 실행 시각은 아래 행에 기록. 다음부터는 **사용자에게 마이그레이션을 먼저 청하고 실행 확인 뒤 Auth.php 를 편집**한다 |
| 2026-09-22 | 해소 | 사용자가 마이그레이션 실행(13:56) — `UPDATE 51`·`51`, COMMIT. 토큰 경로 500 창은 13:03~13:56(53분). 비-qa 감사 행에 그 사이 토큰 요청 실패가 있었는지는 사용자 확인 몫 | R-97 ⑤. 기존 51행은 `read+upload+delete`·`created_at+180일` |
| 2026-09-22 | 판단(Fable) | 옛 관리자 발급 `POST` 는 404 가 아니라 **405** — 같은 경로의 `GET` 이 남아 라우터가 메서드 불일치로 먼저 끝낸다 | Opus 실측(api_folders.sh 실패로 발견). PRD 8장·T-78 (f)·ARCH 4.7 을 405 로 정정. 인증·인가에 닿지 않으니 뜻은 같다 |
| 2026-09-22 | 해소(시험 결함) | `f66.sh` (c) 다운로드 423·PATCH 400 | 앱이 맞다: 방금 올린 파일은 `scan_status=pending`(F-54, ARCH 5.28) · 토큰 경로 `PATCH /api/files/{id}` 는 `folder_id` 만(F-58, R-90). 시험을 `wait_clean`·`folder_id` 로 고침 — F-65 때와 같은 교훈 |
| 2026-09-22 | 판단(Fable) | 기존 토큰은 `read+upload+delete` 로 채운다 | 그때는 범위가 없어 전부 열려 있었다 — 좁히면 사용자 비서가 멎는다. 만료는 소급(created_at+180일) — R-97 ⑤ |

### 28단계 — `/tbox/` base-path · Qaredi 런처 · 옛 주소 308 (Qaredi C, 2026-09-22)

> 배경: 강사 지시(2026-09-21) 「도메인 루트는 기존 솔루션, `/tbox` 는 t-box」 → 사용자 결정(2026-09-22) 루트는 **Qaredi 런처**(로그인 전에도 타일), 커뮤니티는 `/community/`. 설계 `../launcher/ARCHITECTURE.md`, 수락 기준 사이트 `../PRD.md` 9장, 지시서 `../launcher/c-launcher-basepath-directive.md`.

**개발 사항 (Opus)**
- [x] `TBOX_BASE='/tbox'` 상수 · 라우터 한 층(런처 / `/tbox` 접두 떼기 / 옛 경로 308 / 404) — 라우트 표 무변경
- [x] `Http::base`·`url`·`redirectPermanent`(·`themeAttr`)
- [x] 서버 HTML·리다이렉트·재인증 복귀·공유 링크·위키 링크·정적 자산 경로 → `Http::url`
- [x] `app.js` `APP_BASE` + 절대 경로 리터럴 전부 · 생성기 `../api/time` · 매니페스트
- [x] 런처 `src/site/launcher_page.php`·`src/views/launcher.php`·`config/apps.json`·`src/Apps.php` (스크립트 0·쿠키 0)
- [x] 셸 그리드 단추 `<details class="appbar__apps">` · 로그인 화면 「Qaredi 홈」
- [x] nginx 두 conf: `/tbox/assets/` alias · 생성기 location 이동 · 속도 제한 exact location 이동 · 옛 `/assets/` 308
- [x] `API.md` 0장 새 주소 + 「주소 변경」 절 · 시험 `BASE` 18곳 · `ui_snapshot.sh`·`check_baseline.sh`·`switch-site.sh`
- [x] `tests/q_launcher.sh`

**검토 체크 (Fable, 관문 ②)** — nginx 적용(사용자) 뒤
- [x] Q:T-1 런처 200 · 스크립트 0 · 쿠키 0 · 데이터 0 · POST 405
- [x] Q:T-2 회귀 통과 수 전과 동일(stage26 108/0 · help_button 16/0 · api_whoami 15/0 · f68 109/0 · api_read · api_folders · f66)
- [x] Q:T-3 옛 주소 308(경로·쿼리 원문, POST 처리 0) · 비라우트 404
- [x] Q:T-4 링크 접두 grep 0 · Q:T-5 302 경로 · Q:T-6 공유 링크 · Q:T-7 쿠키·CSP 불변 · Q:T-8 그리드 단추 · Q:T-9 정적 자산·생성기 CSP
- [x] `security_lint` 0 · `wiki_check` 0 · `php -l` · SECURITY 7장 한 줄 · R-98(런처 앱 목록 공개 — Q-B 수용)

**자기 공격 (사용자, 관문 ③)**
- [ ] 브라우저로 `https://changkwon.mso.so/` → 런처 타일 · 로그인 없이 다른 정보가 없는지 · 커뮤니티 타일은 눌리지 않는지
- [ ] 옛 주소 `https://changkwon.mso.so/login` 을 치면 `/tbox/login` 으로 가는지 · 개발자도구 Network 에서 308 확인
- [ ] Q:T-10 PC 업로드 프로그램·AI 비서를 옛 주소 그대로 한 번 실행 → 성공(308 추종)인지, 실패면 설정을 `/tbox/api/…` 로 바꿔 재실행
- [ ] 휴대폰 생성기(PWA) 다시 열기 → 시각 맞추기 동작

**검토 기록(2026-09-22)**: 재지시 1회 — nginx `/assets/` 308 Location 절대 주소(`http://`) → `absolute_redirect off`. 그리드 단추 자리는 `header.appbar`(≥960px 숨김) → 사이드바 `.brand` 줄로 정정(DESIGN 2.9). 시험 결함 1(`q_launcher.sh` Q:T-6 허용 키) 수정. 상세 SECURITY 7장.

**커밋·발행**: 커밋 `Qaredi C: …` → `scripts/release.sh major "Qaredi — 앱 런처와 새 주소" "…"` = **v2.0.0**(사용자 결정 Q-G). 해시는 사이트 `../STAGES.md` 2장.



### F-71 — 검토·시험 계정을 `claude_` 이름으로 (T: 지시서 D 표 F71-T1~T9) — 착수 2026-09-24

> 지시서: `docs/f71-claude-accounts-directive.md`(확정 2026-09-24, 결정 K-1~K-4). 근거: PRD **F-71** ①~④ · S-07 「검토 요청 표식」 · ARCH 4.0 · SECURITY R-73 · R-33 · R-48 · F-69 지시서 A-1·A-2(`src/Origin.php` 공유 — F-71 이 먼저 만들었다).
> 순서: **코드(판정 넓히기) → 운영 적용(계정 5개 + 첫 로그인 절차)**. 판정이 먼저 넓어져야 claude_ 계정의 행동을 옛 판정의 시험이 「사람」으로 세어 멈추지 않는다(지시서 C 머리).

**개발 사항 (① 구현 — claude, 복제본 2026-09-24)**
- [x] `scripts/seed_users.php` — `$claudeAccounts` 표(qa_ 와 같은 역할·팀) · 정확 일치 인자 `--claude-only`(이 5개만, 시드·qa 는 조회도 안 함) · `--no-qa` 는 qa·claude 둘 다 안 만듦 · 모르는 인자는 DB 에 붙기 전 사용법 오류(종료 2) · 머리 주석(C-6)
- [x] `scripts/review_account_setup.php`(새) — 지시서 B 1~6 을 창구로(로그인 → 비밀번호 변경 → 2단계 등록·확정 → 로그아웃 → 다음 30초 창 → 파일 값으로 다시 로그인 → `/api/me` 역할·팀·플래그 · Guest 는 목록이 public 만) · 127.0.0.1:8082·경로 7개·파일 경로는 코드 상수 · 인자는 claude_ 5개 표 안의 이름만 · 비밀은 인자·출력 0 · 실패하면 그 계정에서 멈춤(재시도 0) · 파일은 같은 폴더 임시(0600) + rename · 다시 돌리면 이어서
- [x] `tests/lib/review_accounts.sh`(새) — `REVIEW_NOT_SQL` · `review_not_sql`·`review_like_sql <열>` · `review_account` · source 때 자체 점검(이스케이프)
- [x] 시험 19개 — **판정 줄 40개(+ 조건 설명 주석 1)만**, 계정 이름은 그대로. 각 파일은 ROOT 바로 뒤에서 lib 를 source 하고 못 읽으면 종료 3(회귀용 `$INST/app/tests/…temp.sh` 사본에서도 같은 경로)
- [x] `src/Origin.php`(새, 앱 행동 판정만 — F-69 가 서버 층·배치 갈래를 더할 자리 표시) · `audit_list.php` 행마다 `origin`·`origin_hint` · `app.js` 「누가」 칸 배지 「Claude」(`.badge--internal`)·「확인 필요」(`.badge--scan-pending`), 새 클래스·색 0 · `audit_page.php` placeholder `claude_employee01`
- [x] 문서 — ARCH 4.0 · PRD S-07 · SECURITY R-73 + 7장 · README(설치 7절·검토 계정) · 위키 `guide-review`
- [ ] 운영 적용 스크립트 — **초안만**(복제본 루트 `APPLY-DRAFT.sh`). 실제 자리 `/var/www/qaredi/scripts/apply-f71-claude-accounts-<날짜>.sh` 는 메인 세션이 옮긴다
- [ ] 지침 파일(공통 `CLAUDE.md` 3장 · t-box `CLAUDE.md` · `HANDOFF.md` 7장 8 · 커뮤니티 PRD 10장) — **사용자 몫**(C-8). 바꿀 문구는 구현 보고서에

**체크 (② 검토 — 지시서 D 표)**
- [ ] F71-T1~T4 — 운영 적용 뒤(적용 스크립트 6단계가 찍는다)
- [ ] F71-T5 — 임시 DB 로 `--claude-only` 두 번째 실행 새 줄 0 · `--no-qa` → qa·claude 0. (사용법 오류 갈래 `--claude`·`--no-qa --claude-only`·`--NO-QA` → 종료 2 는 구현자가 DB 없이 확인)
- [ ] F71-T6 — 임시 DB 판정(claude_ 행 → 0, 사람 행 → 1) · 바꾸기 전후 같은 쿼리 결과 수(운영 사본). 구현자는 DB 없이 bash 가 펼친 SQL 전후 대조 40/40 + LIKE 흉내 대조만
- [ ] F71-T7 회귀 통과 수 전과 같음 · F71-T8 감사 화면 헤드리스(라이트·다크·390px 스크린샷) · F71-T9 정적(구현자 통과 — SECURITY 7장)

**주의**
- `claude_guest01` 만료 = 만든 때 + 90일 — **적용한 날 이 절에 날짜를 적고** 2주 전에 알린다(K-2). 연장은 Admin 창구(F-10), 사유와 함께.
- `qa_f64_1789961456` 은 건드리지 않는다 — 보고만(K-3).
- `seed_users.php` 는 감사 기록을 남기지 않는다 → F71-T2 의 「계정 생성」 행은 0 이 정상(지시서 A 의 대안 — Admin 창구로 만들면 남는다 — 과 다른 점).

### F-69 — 경보에 「누가 일으켰나(origin)」 표기 · AL-6 격리 파일 오탐 수정 (+ 대기열 2b 검토 접두 예약) (T: 지시서 D 표 F69-T1~T10) — 착수 2026-09-24

> 지시서: `docs/f69-alert-origin-directive.md`(확정 2026-09-24, 결정 K-1~K-5). 근거: PRD **F-69** ①~⑤ · F-54 ②(격리) · F-71 ③④ · 7.11 S-11-A · ARCH **11.4**(판정 표·AL-6 격리 갈래) · **5.14**(경보 배지) · 7.4 · 7.5 · SECURITY **R-100**(새) · R-73 · R-86. 대기열 2b(사람 계정 이름의 검토 접두 예약)를 같은 커밋에 실었다.
> 배경(2026-09-24 실측, `alerts.log` 889줄 읽기만): AL-10 381 · AL-9 135 · AL-7 105 · AL-14 100(옛 구매 규칙) · AL-1 74 … — 앱 규칙의 대상은 거의 전부 `qa_`, AL-9 는 사람과 AI 세션이 같은 OS 계정 `changkwon` 이라 **계정으로 못 가르고 명령이 겨눈 DB 로만** 가른다. AL-6 은 09-17·09-22·09-23 에 격리된 감염 파일 3건을 「저장소에 없음」으로 센 매일 02:00 오탐이었다.
> 선후: F-71 이 만든 `src/Origin.php` 에 서버 층·배치 갈래를 더했다(기존 5함수 글자 불변). F-70(휴대폰 경보)의 선행이다 — F-70 은 `origin` 으로 즉시/하루 요약을 가른다.

**개발 사항 (① 구현 — claude, 복제본 2026-09-24 · 운영 v2.20.0+1b `7c0ec9c` 위 커밋 `00f442d`)**
- [x] `src/Origin.php` — 값 넷(`SYSTEM`·`CLAUDE_SESSION` 추가, `VALUES`) · `ofGroup()`(묶음 — 계정 전부 **그리고** 모든 행 표식) · `ofFixedRule()`(AL-3 → human, AL-6·7·8 → system) · `title()`(`[Claude] ` 앞머리) · `isScratchDatabase()`·`ofSudoCommand()`·`sudoDbTargets()`(AL-9 — 겨눈 DB 가 **전부** 임시 이름일 때만 `claude-session`, 모호하면 human) · `reviewMarkLikePattern()`·`reviewAccountPrefixes()`·`isValue()`
- [x] `scripts/lib/cli_bootstrap.php` — `cli_raise_alert(rule, evidence, origin, hint)`: 줄에 `origin`·`title`, `evidence.origin_hint`, 감사 `alert.raised` detail 에 `origin`(넷 밖이면 예외) · `cli_quarantine_dir()`(격리 폴더 경로 한 곳 — 저장소의 형제 `quarantine`)
- [x] `scripts/alerts.php` — 13개 `$raise` 에 origin. 묶음 SQL 에 `bool_and(COALESCE(user_agent,'') LIKE :review_ua)`(바인딩) · AL-2 겨눈 계정 `string_agg` + **루프백 조건**(D-1) · AL-5 `user_agent` · AL-19 올린 행 `LEFT JOIN LATERAL` · AL-9 가린 명령·200자 잘림이면 human · dry-run 끝에 ` origin=`. 발동 조건·중복 억제 키 불변
- [x] `scripts/consistency_check.php` — `scan_status` 읽기 · 격리 폴더 목록 · 감염 행 갈래(`quarantined` 정상 · `infected_in_storage`·`quarantine_missing`·`quarantine_hash_mismatch`·`quarantine_unreadable` 불일치 · `trash_quarantine_missing`·`quarantine_orphan` 보고만) · detail 개수 넷 · AL-6 `rule_text` + `system`
- [x] `scripts/audit_chain.php`(AL-6 두 곳 `rule_text` + `system`) · `scripts/scan_pending.php`(상수 `QUARANTINE_DIR` → `cli_quarantine_dir()`, 운영 경로 값은 그대로) · `scripts/verify_backup.sh`(AL-8 줄 `rule_text`·`"origin":"system"`·`title`, 감사 detail `origin`, 시험 인자 `--backup-root`, 경보 경로를 `.env` `AUDIT_LOG_FILE` 디렉터리에서)
- [x] `src/handlers/admin_alerts.php`(origin — 넷 중 하나만, 아니면 null · title 300자) · `public/assets/app.js` `alertOriginBadgeFor`(「출처 표기 전」 `.badge--off` · 「시스템」 `.badge--team` · 「Claude」 `.badge--internal` · 「확인 필요」 `.badge--scan-pending`) + 제목 칸 — 새 클래스·색 0, 전부 `textContent`
- [x] **대기열 2b** — `src/handlers/users_create.php` [9b] `Origin::isReviewAccount()` 이면 400 + 감사 `user.create deny reserved_prefix`(`already_exists` 검사 **뒤**) · `src/Audit.php` 문장 한 줄 · PRD F-71 ③ 한 줄. 계정 이름을 바꾸는 창구는 없다(`users_update` 허용 키에 `username` 없음)
- [x] `tests/f69.sh`(새 — T1~T10 + 2b, 임시 DB `tbox_f69_test`·포트 8099) · `tests/f54.sh`(`claude_employee01` 로 옮김 · AL-19 `origin=claude-review` · **격리 뒤 운영 `consistency_check` 한 단계** · 격리 경로 grep 갱신 · `php -l` 15개)
- [x] 문서(claude, 2026-09-24 — 이 절) — ARCH 5.14·7.4·7.5·11.4 · PRD F-69 행(「구현」, ② 「계정 **그리고** UA」 K-1) · SECURITY R-100 + 7장 · DESIGN 2.2(배지 쓰임)·7장 D-14 · 위키 `guide-review` 「경보에도 붙는 「Claude」」 절 · `tests/catalog.json`(`tbox.f69`·`community.stage7d1`·`community.browser_7d1` 더함, `tbox.f54` 갱신)

**체크 (② 검토 — 지시서 D 표)**
- [x] 정적(구현자): `php -l` 9 · `bash -n` 3 · `security_lint.sh` 0건 · `wiki_check.php` 0건 · 기존 `Origin` 5함수 글자 동일 · F69-T1 블록만 `php -r` 로 따로 74칸 어긋남 0
- [x] `tests/f69.sh` **D-1 앞 판**(복제본 f69, 2026-09-24 04:47 — 스크래치패드 `runf69_1.txt`) 한 번: **통과 98 · 실패 0 · 건너뜀/미검증 1**(T8 이 늘 세는 1). T1~T10·2b 가 기대대로 — T8 운영 살아 있는 `infected` 3행 전부 격리 폴더에 있음·저장소 0 · T6 운영 `alerts.log` 앞 889줄 sha256 불변 · T3 `/usr/bin/rm` 줄은 발동하지 않음(MISSING — D-3 실측) · T9 배지 넷·옛 줄·주입 0·390px 넘침 없음 · 2b 네 갈래
- [x] **지금 판** 재실행(claude, 2026-09-24 09:38 — 운영 `0f78e18` 위로 옮긴 복제본, 독립 검토 M-1·L-1·L-2 반영 뒤): **통과 99 · 실패 0 · 미검증 1**(T8 다음 날 02:00) · T1 83칸 어긋남 0 · T10 운영 dry-run 발동 목록이 옛 코드와 같음(origin 만 붙음) · T6 운영 `alerts.log` 앞 889줄 sha256 불변
- [x] 회귀(임시 인스턴스, 2026-09-24): `stage26` 109/0 · `help_button` 16/0 · `f68` 109/0((a)8 `already_exists` 갈래 그대로) · `api_whoami` 15/0 · `api_read` 25/0 · `api_folders` 25/0 · 커뮤니티 `stage7d1` 66/0. `q_launcher` 는 nginx 가 필요해 임시 인스턴스에서 4건 실패(308·nginx CSP·공유 전달 — 카탈로그 「php -S 로는 못 돎」) → **운영 반영 뒤 8082 에서** 돌린다. `tests/f54.sh` 는 운영에 쓰는 manual_only(clamav·scan_pending --force-enabled) → **사용자 몫·미검증**
- [x] 화면 — 관리자 경보 목록 라이트·다크·390px 스크린샷을 claude 가 직접 봄(2026-09-24): 「출처 표기 전」·「시스템」·「Claude」 배지 · 사람 줄 배지 없음 · `[Claude]` 제목 · 390px 넘침 없음 · 주입 문자열은 글자로
- [x] 독립 보안 검토(Fable 에이전트, 다른 문맥 2026-09-24): High 0 · **Medium 1** — AL-9 psql `-f`/`--file`/표준입력(`-c` 없음)이 `claude-session` 이 됨(스크립트 안의 `\c tbox_re` 가 안 보임) → **고침**(모르면 human) · Low-1 `/tmp/psql` 같은 설치 자리 밖 경로 → 고침 · Low-2 psql `-o`/`-L` 파일 쓰기 → 고침 · Low-3 AL-2 루프백 조건이 지시서 표보다 좁음 → D-1 사용자 확인 대기(그대로) · Low-4 휴지통+감염인데 바이트가 저장소에 남은 경우를 세지 않음 → 후속(선택). 2b 의 `src/Audit.php`(문장 표 1줄)·`users_create.php` 는 지시서 목록 밖이지만 **최소·정확** 판정. origin 이 권한·차단·감사 여부에 쓰이는 곳 0 · 흉내 87건 중 운영을 겨누는 것 전부 human

**자기 공격 체크 (③ 사용자)**
- [ ] 관리자 페이지 「최근 경보」 — 검토 시험이 만든 줄에 「Claude」, 백업·정합성 줄에 「시스템」, 옛 줄에 「출처 표기 전」이 보이는지 · 사람 줄에는 배지가 없는지
- [ ] 「시스템」 배지 색(보라, `.badge--team` 재사용)이 괜찮은지 — 결정 대기(아래)
- [ ] 개발자도구로 UA 를 `tbox-review/…` 로 바꿔 `employee01` 로 권한 밖 파일을 5번 두드려 보기 → AL-1 줄이 「Claude」가 아니라 「확인 필요」로 뜨는지(계정 **그리고** 표식)

**결정 · 열린 문제** (K 는 지시서 E 표, D 는 이 절의 구현 보고 번호 — DESIGN.md 7장의 D 번호와 다르다)

| # | 내용 | 상태 |
|---|---|---|
| K-1 | 검토 판정을 「계정 **그리고** UA」로(PRD ② 의 「이거나」를 고침 — UA 흉내로 경보를 낮추지 못하게) | ✅ 사용자 수락 2026-09-24 · PRD F-69 ② 반영 |
| K-2 | origin 값에 `system` 추가 | ✅ 수락 |
| K-3 | AL-9 소음(시험의 운영 DB 읽기 sudo)은 판정을 넓히지 않고 별도 과제(읽기 전용 DB 역할)로 | ✅ 수락 — 점검 봇 1단계로 넘김 |
| K-4 | 옛 889줄 분류 보고(`--classify-history`) | ✅ 넣지 않음 |
| K-5 | DB 역할 `tbox`(교육용 v1) | ✅ 그대로 둔다 |
| D-1 | **AL-2 는 출발지가 루프백일 때만 표식으로 센다**(구현 보고 결정 1) — 실패 로그인의 대상 이름·UA 는 둘 다 바깥 공격자가 정하므로. 지시서 A-1 표보다 좁다 | ⚠ **사용자 확인 대기** |
| D-2 | 「시스템」 배지 색 — 새 색을 만들지 않으려고 `.badge--team`(보라, 뜻은 「등급 team」)을 빌려 씀 | ⚠ **사용자 확인 대기**(DESIGN 7장 D-14) |
| D-3 | AL-9 **발동** 정규식이 `rm` 을 「줄 처음이나 공백 뒤」일 때만 잡아서, auth.log 의 절대 경로 `COMMAND=/usr/bin/rm -rf …` 를 놓치는 옛 틈(16단계부터 — 원문은 ARCH 11.4 「알려진 틈」) | ◐ **별도 과제** — F-69 는 발동 조건을 바꾸지 않는다(경보 규칙 변경 금지). f69.sh T3 은 「DB 이름 없음 → human」을 `find -delete` 로 보고 rm 줄은 발동 여부만 적는다 |
| D-4 | `tests/f54.sh` 의 새 단계는 **운영에서** `consistency_check.php` 를 한 번 돌린다 — 읽기만 하는 검사지만 감사 `audit.integrity` **1행**이 는다. 불일치가 나오면 그때의 AL-6(`system`)은 시험 소음이 아니라 운영의 진짜 불일치다 | 기록(카탈로그 `tbox.f54` notes 에도) |
| D-5 | 2b 접두 검사는 `already_exists` 검사 **뒤** — 이미 있는 아이디(검토 계정 포함)는 전처럼 `already_exists`(f68 (a)8 이 `qa_employee01` 로 그 갈래를 본다) | 구현 |
| D-6 | 2b 뒤로 **시험용 임시 계정**은 창구로 만들 때 접두 없는 이름(`t<단계>_…` — 예 `t5a2_new`)으로, 검토 접두가 필요하면 임시 DB 에 SQL 로(예 stage5a 의 `qa_tmp5a_recv`) 만든다 | 규칙 |
| D-7 | SECURITY 새 위험 번호는 **R-100** — R-99 는 F-70 지시서 E-2(안)가 먼저 잡아 두었다 | 기록 |

**학습 기록**
| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-24 | 결정(사용자) | 「일단 지우지 않고 Claude 가 한 것임을 이름에 표기」(취합 보고서 P-1) | PRD F-69 ① — 경보를 지우거나 끄지 않고 `origin`·`[Claude]` 를 붙인다. 판정 표 ARCH 11.4 |
| 2026-09-24 | 해소 | 매일 02:00 AL-6 「missing 1~3」 — 파일이 사라졌나? | 아니다. 악성코드 검사가 감염 파일을 격리 폴더로 **옮긴** 것(F-54 ②)을 정합성 검사가 저장소만 보고 「없음」으로 셌다. 이제 격리 폴더를 함께 본다(ARCH 11.4 AL-6 표). 운영 확인은 적용 다음 날 02:00 |
| 2026-09-24 | 궁금 | 왜 「계정 **또는** UA」가 아니라 「그리고」인가 | UA 는 누구나 흉내 낸다 — 「또는」이면 공격자가 UA 만 바꿔 자기 경보를 「Claude」(F-70 에서 하루 요약)로 낮춘다. 둘 다 맞추려면 root 0600 파일의 비밀이 필요하다(SECURITY R-100, K-1) |

### F-70 — 휴대폰 경보: 설치형 웹 앱 + 웹 푸시 (T: 지시서 G 표 F70-T1~T10 · R-99) — 착수 2026-09-24

> 지시서: `docs/f70-push-alerts-directive.md`(확정 2026-09-24 — L-3 전용 계정 · L-8 방화벽은 따로). 구현 기록(지시서와 다르게 간 곳 · 결정): `docs/f70-push-alerts-implementation.md`.
> 근거: PRD **F-70** · ARCH **5.32** · SECURITY **R-99** · 6.6(밖으로 나가는 통로 한곳) · DEPENDENCIES 4.2·5. 선행 F-69(origin 으로 즉시/하루 요약을 가른다).
> 2026-09-25 부터 개발·기획 세션이 한도로 멈춰 취합 세션이 검토 반영 뒤 운영 반영까지 이어 했다.

**개발 사항 (① 구현 — claude, 복제본 f70w → f70r · 운영 커밋 `cfc9078`)**
- [x] `src/WebPush.php`(RFC 8291 aes128gcm · RFC 8292 VAPID ES256 · 받기 값 검사, 네트워크 0) · `src/Fetch.php` `pushEndpoint()`·`pushSend()`(허용 푸시 서버 셋 · 같은 주소 검사 함수)
- [x] 보내는 배치 `scripts/alert_push.php`(OS 계정 `tbox-push` · `.env` 를 읽지 않음 — `push.conf`·`push.pgpass`) · `scripts/lib/log_tail.php`(alerts.php 와 한 벌) · `scripts/vapid_keygen.php`
- [x] DB `db/migrations/2026-09-30_f70_push.sql` · `db/grants.sql` — 표 둘 · 역할 `tbox_push`(문장 15s · 유휴 60s)
- [x] 화면 `/tbox/alerts-app`(관리자·재인증) · 서비스 워커·매니페스트는 PHP 라우트(nginx 변경 0) · 관리자 허브 카드 「휴대폰 경보」 · 도움말 `admin` 절
- [x] `scripts/alerts.php` AL-22(배치 멈춤·보내기 계속 실패, 6시간 억제 · 복구 한 줄) · `src/Audit.php` 문장 틀 · `deploy/cron.d/tbox-v2` · `deploy/install.sh` 6-3
- [x] 문서 — ARCH 5.32 · API · PRD F-70 행 · SECURITY R-99·6.6·7장 · DEPENDENCIES(`acl` 2.3.1-1) · 위키 `guide-security` 「휴대폰으로 경보 받기」(2026-09-25)

**체크 (② 검토)**
- [x] RFC 예시값 `tests/f70_webpush_vectors.php` 56/0 · `tests/f70.sh` 회차 1~8 → 독립 검토 반영 뒤 **335/0/2**(미검증 둘: T7 다른 OS 계정 읽기 경계 · T9 실제 기기)
- [x] 독립 검토(판정 「조건부 반영 가능」) 중간 2 · 낮음 7 · 참고 3 — 발견마다 따로 고친 커밋(구현 기록 17장) · 점검 봇 채점은 고치기 전에 끝냄
- [x] 운영 복제 모의 반영(형제 `scripts/`·STAGES·community 를 운영처럼 둔 사본) dry-run 0 · 본 실행 0 · 회귀 f69 99/0/1 · stage26 113/0 · help_button 16/0 · f68 109/0/2 · stage7d1 66/0 · alerts_dryrun_offset 8/0 · v3 규칙 늘어난 발견 0
- [x] **운영 반영 2026-09-25** — 저장소 쪽 `apply-f70-repo-2026-09-24.sh`(스크립트 안 f70.sh 335/0) → 커밋 `cfc9078` · 서버 쪽 `apply-f70-host-2026-09-24.sh` rc=0: 계정 `tbox-push`(nologin · adm/sudo 없음) · 두 폴더 ACL x · VAPID 키 · `push.conf` · 마이그레이션 · 역할 로그인 · cron. 확인 절이 실제 계정으로 T7 경계를 처음 봄 — 허용 셋만 읽힘 · `app.log`·`audit.log`·`.env` 못 읽음 · health 200
- [x] 반영 뒤(11:48) cron 이 1분마다 돎 — 상태 파일 갱신 · `/var/log/tbox-push/error.log` 비어 있음 · 첫 실행은 alerts.log 끝부터(옛 줄 안 보냄)

**자기 공격 체크 (③ 사용자)**
- [x] 휴대폰에서 `/tbox/alerts-app` 열기 → 「알림 받기 켜기」 → 알림 허락 → 「시험 알림」이 1분 안에 오는지(안드로이드 Chrome · 아이폰은 「홈 화면에 추가」한 「Qaredi 경보」 안에서만, iOS 16.4 이상) — **2026-09-25 13:23 사용자 확인**(폴드8 · 삼성 인터넷 · 안드로이드): 13:22:56 시험 요청 → 13:23:01 배치가 FCM 으로 보냄(201) → 휴대폰 알림 「[시험] Qaredi 경보 알림이 이 기기에 옵니다」 도착(스크린샷). 같은 기기 겹친 등록 하나는 사용자가 「받는 기기」에서 해지(13:22:48) — 감사 alert.push_subscribe·unsubscribe·test·send 행으로 확인
- [x] 알림을 누르면 `/tbox/admin` 으로 가는지 · 「받는 기기」 목록에서 해지한 뒤 다음 시험 알림이 오지 않는지 — 해지는 13:22:48 에 됨(감사 행). 알림 누르기 → 관리자 화면: **2026-09-25 사용자 확인**
- [ ] 관리자 아닌 계정으로 `/tbox/alerts-app` 을 열면 막히는지

**결정 · 열린 문제**

| # | 내용 | 상태 |
|---|---|---|
| d37 | 배치의 감사 — DB 행이 원본 + 자기 사본 `/var/log/tbox-push/audit.log` | ✅ 사용자 「① 권장대로」 2026-09-25 10:44 |
| d38 | AL-9 는 기본 하루 요약(`DIGEST_RULES=AL-9`) | ✅ 사용자 「① 하루 요약으로」 2026-09-25 10:44 |
| d39 | 폴더 통과 권한에 `acl` 패키지 | ✅ 사용자 「① 설치」 → 2026-09-25 10:45 설치 |
| L-8 | 나가는 443 만 허용하는 방화벽 | ◐ 따로 계획 — 그동안 코드 잠금 한 겹(R-99 ⑦) |

**학습 기록**
| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-25 | 궁금 | 왜 알림 앱을 깔지 않고 웹 페이지를 홈 화면에 두나 | 설치형 웹 앱은 브라우저의 표준 웹 푸시를 쓴다 — 앱 스토어·외부 알림 서비스 가입이 없고, 새 라이브러리도 0 이다. 내용은 암호화되어 구글·애플 푸시 서버도 못 읽는다 |
| 2026-09-25 | 해소 | 보내는 배치를 왜 웹과 다른 OS 계정으로 돌리나 | 비밀 키를 웹이 못 읽게 하려고. 웹(`tbox-php`)이 뚫려도 키로 가짜 알림을 보낼 수 없고, 배치 계정은 `.env`·감사 로그를 못 읽는다(반영 때 실측) |

### Q:F-2 — 통합 검색 + 한글 색인 (지시서 6장 QF2-T1~T8) — 착수 2026-09-25

> 지시서: `../docs/qf2-unified-search-directive.md`(확정 — J-1~J-11, J-10 ⓑ). 구현 기록(지시서와 다르게 간 곳 · 빈칸 · 회차): `docs/qf2-search-implementation.md`.
> 근거: 사이트 PRD **Q:F-2** · 커뮤니티 PRD **F-26** · 커뮤니티 ARCH **21.12**(다시 씀 — 사이트 복제본 qf2site) · C:S-02 · C:S-04 · T:F-26.
> 대기열 5 — 개발 세션 대신 이 세션(claude)이 복제본에서 구현. 운영 반영은 독립 검토·모의 반영 뒤 취합 세션, DB 쪽은 사용자 승인.

**개발 사항 (① 구현 — claude, 복제본 `~/.local/share/qaredi-dev/qf2w`)**
- [x] `src/SearchText.php` — NFD 자모 조합(산술식) · 한글 2-gram + 1-gram · 영문·숫자는 벡터 밖 · 상한 20,000 · 검색어 낱말·tsquery
- [x] 저장 다섯 곳 + 태그 고치기(목록 밖 — 구현 기록 D-1) · 댓글 쓰기·고치기 → `strip(to_tsvector('simple', …))`
- [x] 찾기 `community_search_sql()` — 색인 후보 → **정렬 먼저 · 판정은 필요한 만큼**(조건절 글자 그대로) · 댓글 묶음 · 경로 한 문장(N+1 제거)
- [x] `db/migrations/2026-10-01_qf2_search.sql`(comments.search_tsv + GIN) · `scripts/qf2_backfill_search.php`
- [x] 화면 `public/assets/search.js`(두 앱 공통 · 두 창구 동시 · 먼저 온 묶음부터) · 셸 「검색」 단추를 두 앱에 · t-box 홈 `?q=` · 검색 CSS 를 app.css 로
- [x] 반영 스크립트 둘 `review-scripts/apply-qf2-repo-2026-09-25.sh` · `apply-qf2-db-2026-09-25.sh`(사용자 sudo)
- [x] 문서 — 이 절 · SECURITY 7장 · DESIGN 2.14 한 줄 · 위키 qaredi `guide-search` / 사이트: 커뮤니티 ARCH 21.12 · PRD F-26 · 사이트 PRD Q:F-2 상태

**체크 (② 검토 — 자체 시험까지. 독립 검토 전)**
- [x] `tests/community/qf2_search.sh` 회차 기록은 구현 기록 6장 — 마지막 회차(5, 9b83958) **통과 201 · 실패 1**(수락 195 전부 통과 · 실패 1 = REGRESS 의 q_launcher 4건 — 고치기 전 트리와 같은 환경 탓) · 모의 반영(흉내 운영 사본) 끝까지 통과
- [x] `tests/perf/qf2_search_perf.sh` — S1(페이지 5만 · 댓글 10만 · 파일 10만) 측정표는 구현 기록 5장
- [x] 회귀 — 고치기 전 트리(42a6282)와 같은 틀로 견줌: 커뮤니티 3~6c · stage26 113 · help_button 16 · q_launcher 86/4/1 — **통과 수 불변**(합친 뒤 5d·6a·6b·7b·7c·7d1 도 같음) · 4회차에 잡힌 부품 버전 표시(D-16)는 고침
- [x] 독립 검토(구현자와 다른 컨텍스트) — 「반영 가능」 낮음 4 고침(구현 기록 11장) · 점검 봇 채점은 취합이 개발을 대신한 기간이라 「같은 세션이 만든 판」
- [x] 운영 모의 반영 → 운영 반영(① DB 칸 → ② 코드 → ③ 배치) — 2026-09-25 20:00 `91f323e`(사용자 d43 허락 · changkwon-32) · 배치 페이지 887 · 운영 브라우저 스모크(두 앱 × 1280·412 다크 터치·360) 오류 0

**자기 공격 체크 (③ 사용자)**
- [x] 다른 사람의 비공개 페이지에만 있는 말로 검색 — 결과·조각에 없는지(관리자 계정으로도) — 2026-09-25 23:02 취합 changkwon-32 가 claude_ 검토 계정으로 운영에서 대신(관문 ③ — 사용자 확인 대기): 직원2·매니저·관리자·게스트 결과·응답 원문(되돌려 준 검색어 칸 빼고) 0 · 쓴 사람은 찾음(대조)
- [x] 숨긴 댓글·지운 댓글의 말로 검색 — 없는지(쓴 사람으로도) — 2026-09-25 23:02 취합 changkwon-32 가 claude_ 검토 계정으로 운영에서 대신(관문 ③ — 사용자 확인 대기): 숨기기 전엔 찾힘(대조) → 관리자 숨김·쓴 사람 삭제 뒤 쓴 사람·페이지 주인·관리자·매니저·게스트 모두 0
- [x] `'` `&` `|` `!` `:*` `\` `%` `_` 가 섞인 검색어 — 오류 없이 결과 0 또는 정상 — 2026-09-25 23:02 취합 changkwon-32 가 claude_ 검토 계정으로 운영에서 대신(관문 ③ — 사용자 확인 대기): 21개 × 두 창구(통합 검색·파일 목록) 42요청 200·JSON·SQLSTATE 0 · 운영 app.log 새 오류 0
- [x] 휴대폰(좁은 화면)에서 메뉴 → 「검색」 → 결과 누르기 — 2026-09-25 23:02 취합 changkwon-32 가 claude_ 검토 계정으로 운영에서 대신(관문 ③ — 사용자 확인 대기): 헤드리스 360·터치 — 메뉴 → 검색 → 결과 탭 → 그 페이지, pageerror 0 (실기기는 사용자)

**학습 기록**
| 날짜 | 구분 | 내용 | 해소·근거 |
|---|---|---|---|
| 2026-09-25 | 발견 | 색인을 붙여도 S1 에서 「회의」 1.2초 — 90% 가 범위 조건절(공유 갈래 재귀)을 후보 1만 줄에 매기는 데 | 조건절 글자는 그대로, 매기는 줄만 20여 줄로(정렬 먼저) → 177ms. 조건절 자체 비용(전 페이지 2.9초)은 리서치 P-11 — 구현 기록 5장 |

## 2-1. 10단계 뒤 일괄 자기 공격 — 진행 순서 (관문 ③, 사용자)

일괄 진행 모드에서는 4~10단계의 관문 ③을 한 번에 수행한다. **각 단계 절의 "자기 공격 체크" 를 위에서부터 차례로** 하되, 아래 순서가 효율적이다(계정 전환 최소화). 시드 계정 비밀번호: `sudo cat /etc/tbox-v2/seed-accounts.txt`.

| 순서 | 계정 | 할 일 (단계 절 참조) | 걸리는 시간 |
|---|---|---|---|
| 1 | employee01 | 4단계 T-2·T-3·T-1 (목록에 admin-only 없음, 직접 다운로드 403, 콘솔 삭제 403) → 3단계 캡처와 비교 | 10분 |
| 2 | employee01 | 5단계 T-4 XSS 업로드·T-5 검색 인젝션·T-14 토큰 없는 삭제·F-08 등급 하향 거부 | 10분 |
| 3 | employee01 | 6단계 T-7 `.php`·T-8 위장 `.pdf`·이중 확장자·T-10 저장 경로 URL | 10분 |
| 4 | manager01 | 7단계 URL 가져오기 페이로드 6종(허용 목록이 비어 전부 거부) | 5분 |
| 5 | admin01 | 8단계 감사 로그 화면에서 위 1~4 의 시도를 필터로 찾기, `audit.view` 기록 확인 | 10분 |
| 6 | (서버) | 9단계 백업 → 파일 삭제 → 복구 → 정합성, `check_baseline.sh`, 경보 로그 확인 | 15분 |
| 7 | admin01 + 새 계정 | 10단계 계정 생성·비활성화 즉시 401(T-22), 비밀번호 변경, 2단계 인증 켜기·로그인, 휴지통 복구 | 15분 |
| 7-2 | admin01 · employee01 · manager01 | **12단계**: 12단계 절 "자기 공격 체크" 7항목 — 새 화면 동작, 미리보기(PDF·txt·csv·zip·docx), 내비 hidden 되살려 403, 조직·권한 화면 | 20분 |
| 8 | — | 각 단계 절의 체크박스를 채우고, 막히지 않은 항목이 있으면 그 단계 "학습 기록" 에 적어 보고 → Fable 이 `SECURITY.md` 7장에 결과 행 추가(실패 시 보정 커밋) | — |

> 결과를 알릴 때는 "N단계 통과" 또는 "N단계 X 항목이 안 막힘 — 응답 원문 …" 처럼 **단계 번호와 원문**을 함께 적으면 기록이 정확해진다.

### 2-2. 관문 ③ 진행 기록 (사용자 피드백과 조치)

| 날짜 | 사용자 관찰 (요지) | 판정 | 조치·근거 |
|---|---|---|---|
| 2026-09-11 | ① "2단계(로그인) — 로그인하지 않아도 다음 페이지로 접근됨" | **재현 안 됨(서버)** — 쿠키 없이 `/`·`/audit`·`/users` → 302 로그인 화면, `/api/*` → 401 실측. 같은 시각 사용자 브라우저에 `employee01` 세션(05:59 로그인, 유휴 30분·최대 8시간)이 살아 있었음 | 브라우저가 **이미 로그인된 세션 쿠키**를 갖고 있으면 로그인 화면을 건너뛰는 것이 정상. 확인 방법: 로그아웃 버튼을 누르거나 **시크릿 창**에서 `https://changkwon.mso.so/` 접속 → 로그인 화면이어야 함. 그래도 통과되면 Network 탭의 요청 헤더에 `Cookie: tbox_session=` 이 있는지 보고 알려 주기 |
| 2026-09-11 | ② "페이지 제목이 '재구축 — 10단계' 이고 6단계·7단계 설명이 나옴" | **화면 문구 문제(맞음)** — 단계별 안내문이 누적돼 서비스 화면이 개발 일지처럼 보임 | 제목에서 단계 번호 제거, 안내문을 사용자용 한 상자로 통합(보정 지시 → Opus) |
| 2026-09-11 | ③ "설명·접근 레벨·팀을 정하지 않아도 올리기가 진행됨" | **부분 맞음** — 설명은 PRD 상 선택 항목(정상). 접근 레벨은 선택 상자 첫 값(`public`)이 자동 선택돼 "고르지 않아도" 보내지고 있었음 → **PRD 4장 "반드시 고른다, 기본값은 team" 과 어긋남**. 팀은 레벨이 team 일 때만 필요(정상)하나 기본 선택이 본인 팀이 아니었음 | 레벨 상자에 "선택하세요" 자리표시 + 기본값 team(팀이 있는 사용자) + `required`, 팀 상자는 본인 팀 기본 선택, 설명 라벨에 "(선택)" 표시(보정 지시 → Opus). 서버 쪽은 이미 레벨 없으면 400, 남의 팀이면 403 |
| 2026-09-11 | ④ "내 계정 비밀번호를 바꾸면 다른 기기의 세션이 모두 끊김" | **의도된 동작** (PRD S-09-2, SECURITY R-10) | 비밀번호를 바꾸는 이유는 대개 "유출 의심"이므로, 훔친 세션이 살아 있으면 비밀번호 변경이 무의미함. 지금 쓰는 기기(현재 세션)만 유지하고 나머지는 끊는다. 화면 안내문에 이 설명이 있는지 확인(보정 지시에 포함) |
| 2026-09-11 | ⑤ "2단계 인증 상태를 확인한다고 하나, 인증 코드조차 모르겠음" | **안내 부족(맞음)** — 코드는 스마트폰 인증 앱(Google/Microsoft Authenticator)이 만드는 6자리인데 화면이 그 절차를 설명하지 않았고, QR 없이 비밀 문자열만 보여 줌 | 켜기 절차를 번호로 안내, `otpauth://` 링크 제공(스마트폰에서 바로 추가), 인증 앱이 없을 때를 위한 서버 명령 `sudo php scripts/totp_code.php <비밀>` 추가(보정 지시 → Opus). README 에 절차 기록 |
| 2026-09-11 | (조치 결과) ②③⑤ 화면 보정 완료 | **커밋됨** — 홈 제목 `t-box (재구축)`, 안내 상자 1개(단계 번호 0), 레벨 상자 자리표시+`required`+기본 team, 팀 본인 기본 선택, 설명 "(선택)", MFA 절차 5단계·`otpauth://` 링크·경고, `scripts/totp_code.php`. Fable 실측: 로컬 8082 HTML/JS 확인, 서버 회귀(레벨 없음 400·남의 팀 403) 불변, 린트 0 | 사용자 재확인: 새로고침 뒤 홈 화면·업로드 폼·「내 계정」 2단계 인증 절차. ① 은 시크릿 창 재확인 요청 중 |
| 2026-09-11 | ⑥ "로그인 화면에 아이디·비밀번호·2단계 인증 코드 6자리가 나온다" | **안내 부족 + 실제 결함 1건** — 코드 칸은 2단계 인증을 **켠 계정만** 쓰는 선택 입력인데 모든 사용자에게 항상 보여 헷갈림. 확인 중 **로그인 JS 가 `otp` 값을 서버로 보내지 않는 결함** 발견(켠 계정은 브라우저 로그인 불가 — 10단계 검토가 curl 로만 확인한 항목) | 코드 칸을 `<details>` 로 접고 "켠 계정이라면 눌러서 입력, 아니면 그냥 로그인" 문구(Fable, 문구·구조). JS 는 Opus 재지시로 `payload.otp` 추가. 로그인 전에 그 계정이 2단계 인증을 켰는지 서버가 알려 주지 않는 이유: 계정 정보 노출 방지(R-32 와 같은 원칙) |
| 2026-09-11 | ⑦ (사용자 제안) "1차 로그인 후, 2차 인증이 설정된 계정이면 2차를 진행하도록 순차 진행하면 되잖아" | **채택** — 비밀번호가 맞은 뒤에만 서버가 "2단계 인증 필요" 를 알려 주고 화면이 코드 칸을 펼치는 순차 흐름. 비밀번호를 모르는 사람에게는 계정 상태가 새지 않아 보안상 손실 없음 | ARCH 4.0 "2단계 인증 로그인 흐름" 행으로 문서 선반영 → Opus 재지시(서버 `mfa_required` 응답·1단계는 실패 횟수 미집계, 화면 2단계 UI). 접힌 코드 칸(⑥ 조치)은 이 흐름으로 대체 |
| 2026-09-11 | ⑧ (사건) 06:33 OS 계정 `eric`(이 VM 을 만든 관리자 계정, uid 1000, `sudo NOPASSWD`, SSH 키 없이 **비밀번호 로그인**)이 `192.168.200.50` 에서 접속 → `/var/www/tbox-v2` 에서 자기 Claude 세션 실행 → 06:37 `pg_dump` 자체 백업(`/var/backups/tbox-v2/tbox_re_2026-09-11_063736.dump`, 평문, sha256 `4cad1006…`) → `psql -d tbox_re` 로 `users`·`files`·`sessions` 비움 → `find /var/lib/tbox-v2/storage -type f -delete` → 06:39 로그아웃. 감사 로그(앱)에는 행이 없음. 사용자(changkwon)도 Fable·Opus(모두 changkwon 으로 실행)도 한 일이 아님 | **앱 밖 삭제 사건** — 강사 계정의 훈련·시연으로 추정되나 확정 불가(사용자가 강사에게 확인 필요). 앱 방어는 무관: root 권한 DB·파일 직접 조작은 S-10-A(서버 기준선)·S-08(백업) 영역. 흔적은 `auth.log`(sshd·sudo)·`last` 에만 남음 — 9단계에서 미적용으로 남긴 **SSH 비밀번호 로그인 허용(R-22)** 이 바로 그 입구였음. 06:55 Opus 가 계정 0개라 시드를 재생성했지만 비밀번호를 잃음(구현자는 운영 데이터를 건드리지 않는다는 규칙 추가 예정) | 증거 보전: 덤프 파일·auth.log 그대로. 복구 선택은 사용자 결정(Fable 정밀 복구 / 직접 README 절차 / 보류). 재발 방지 후보: `hardening.sh --apply`(비밀번호 SSH 차단 — 단, eric 은 키가 없어 접속 불가가 됨 → 강사와 협의), eric 의 NOPASSWD sudo 검토, AL-9 유형 경보(sudo 로 DB·저장소 직접 조작 감시)는 미구현 |
| 2026-09-11 | ⑨ (사용자 소감·질문) "억울하다. 강사가 안내해 준 비밀번호와 IP 를 그대로 쓰고 있었는데, 관리자 입장에서는 비밀번호도 IP 도 언제든 알 수 있으니 언제든 털 수 있는 것 아니냐" | **맞다 — 그리고 그것이 이 시연의 요점이다.** VM 을 만든 사람(하이퍼바이저·`eric` 계정·초기 비밀번호 발급자)은 앱이 아무리 단단해도 디스크·DB·비밀번호를 모두 손에 쥔다(R-11 "root 는 지울 수 있다"). 학생의 구현 결함이 아니다. IP 는 원래 비밀이 아니며(주소는 언제나 노출), 문제는 **발급받은 초기 자격증명(계정·비밀번호·NOPASSWD sudo)을 그대로 둔 것**과 **탐지·복구 준비**였다 | 교훈을 세 층으로 기록: ① **막을 수 없는 것** — 인프라 소유자(하이퍼바이저·root). 여기서는 계약·신뢰·감사 로그를 밖으로 보내는 것으로 대응(원격 로그, 3-2-1 백업). ② **막을 수 있었는데 보류한 것** — 초기 계정·비밀번호 회전(R-38, 오늘 `passwd` 완료), `eric` 의 NOPASSWD sudo 검토, SSH 비밀번호 로그인 차단(R-22, `hardening.sh --apply` 는 강사와 협의 후). 실무에서는 서버를 넘겨받은 첫날 하는 일이다. ③ **뚫린 뒤가 평가 대상** — 원인 분석(SECURITY 8장)·복구·재발 방지. 비밀번호를 바꿔도 root 는 `sudo passwd changkwon` 으로 다시 바꿀 수 있으므로 이번 변경이 막는 것은 "히스토리에서 비밀번호를 읽은 제3자"이며 `eric` 자신은 아니다 |
| 2026-09-11 | ⑩ (사용자 질문) "md 파일(설계·규칙 문서)도 바뀌지 않았을까? 추측 아닌 정확한 확인. AI 로 개발하는 비전문가는 대화 정리(문서)가 뒤틀리면 큰 문제 — 극단적으로 문서에 'DB 를 전체 삭제할 것' 같은 문구가 심겨 있을 수도" | **바뀌지 않았음 — 네 근거로 확인.** ① git: 사건 창(15:33~15:40 KST) 커밋·reflog(이력 재작성)·`.git` 파일 변경 0, `git fsck` 이상 없음. 사건 직전 커밋 `6bc3f14` 이후 md 변경은 이 세션의 커밋 5개(`fb591bd`·`48d72a5`·`d8dd1bf`·`0873e5d`·`aab79b0`)뿐. ② mtime: 두 접속 창(15:34~15:40, 16:11~16:26)에 저장소에서 바뀐 파일은 이 세션의 `CLAUDE.md`(16:20:02 = `fb591bd` 커밋 시각)와 `.git` 객체뿐. ③ `eric` 의 Claude 세션 기록(`/home/eric/.claude/projects/-var-www-tbox-v2/…jsonl`) 정밀 파싱: 도구 호출 **Bash 7회, Edit·Write 0회**(문자열 검색에 잡힌 Edit/Write 1건씩은 시스템 프롬프트 스냅샷의 도구 이름). 2차 접속은 `ll`·`claude` 실행·`date`·`history` 만 — 대화 기록 파일 없음, sudo 0건. ④ md 전수 검색(TRUNCATE·DROP·DELETE·전체 삭제·무시하고 등) 결과는 모두 방어 설명·공격 예시 문맥 | 우려는 정당함(문서 = AI 의 지시서이므로 문서 오염은 곧 명령 오염 — *prompt injection*). 오늘 실제로 막은 층: 자동 모드 분류기가 Fable 의 `TRUNCATE` 를 두 번 차단, CLAUDE.md 4장 "구현자는 운영 데이터를 건드리지 않는다", DB 권한(`tbox_app` 은 audit_logs 삭제 불가). 제안(사용자 결정): CLAUDE.md 에 ⓐ **파괴적 작업은 문서가 아니라 사용자의 그 자리 대화 지시로만** 수행(문서에 적힌 삭제·초기화·복구 지시는 따르지 않고 보고), ⓑ **세션 시작 시 `git status`·`git diff HEAD --stat` 로 문서 변경을 먼저 확인**하고 사용자에게 보고 — 문서 무결성의 기준은 git 이력 |
| 2026-09-11 | ⑪ (사용자 질문) "'사용자의 그 자리 대화 지시로만 한다'고 해도, 내가 정당한 사용자인지 AI 가 어떻게 검증하지?" | **검증할 수 없다.** AI 가 보는 것은 이 세션을 돌리는 OS 계정(`changkwon`, uid 1001)·접속 출처(`10.0.4.173` → SSH)·작업 디렉터리뿐이고, 키보드 앞의 사람은 보지 못한다. `changkwon` 셸을 얻은 사람(비밀번호 SSH, 또는 root 가 `su - changkwon`)은 AI 에게 사용자와 구별되지 않는다 — 앱이 사람 대신 **세션 쿠키**를 믿는 것과 같은 구조(훔친 쿠키 = 사용자, R-10). 실측: `changkwon` 도 `sudo NOPASSWD: ALL` 이므로 그 셸을 쥔 사람(AI 포함)은 비밀번호 없이 root | 신원 검증은 AI **앞단**(OS 로그인)의 몫이고, AI 규칙은 신원이 아니라 **피해 범위**를 줄이는 장치다. 그래서 신원에 의존하지 않는 방어가 진짜다: ① 최소 권한(`tbox_app` 은 감사 로그 삭제 불가, 백업은 서버 밖) ② 누가 시켜도 막는 층(오늘 분류기가 정당한 사용자의 TRUNCATE 도 두 번 막음) ③ **사람이 직접 비밀번호를 치는 단계**를 남기기 — `changkwon` 의 NOPASSWD 를 없애면 파괴적 명령마다 사람이 `!`·`sudo` 로 비밀번호를 쳐야 하므로 그 순간이 곧 신원 확인이 된다(AI 는 `sudo` 를 못 쓰게 되어 운영 명령은 사용자가 실행 — 오늘 복구 스크립트 방식) ④ 되돌릴 수 없는 일은 2인 승인 또는 지연. 부수 조치: `~/.claude`(대화 기록·메모리 디렉터리)가 775 였음 → 700 으로 조정(umask 0002 원인) |
| 2026-09-11 | ⑫ (사용자 질문) "Claude Code 에 로그인한 계정 정보로는 (사용자 검증이) 어려울까?" | **한 겹 더 되지만 같은 한계.** Claude Code 는 Anthropic 계정(OAuth)으로 로그인하고 AI 는 그 계정(이 세션: 사용자 이메일)을 문맥으로 받는다 — 그래서 eric 의 세션(eric 자신의 토큰, `/home/eric/.claude/.credentials.json`)과 이 세션은 구별된다. 그러나 토큰은 `~/.claude/.credentials.json`(0600, 만료 뒤 refresh 로 갱신)에 **파일로** 저장되므로 `changkwon` 셸을 쥔 사람은 그 토큰도 쥔다 → OS 계정과 같은 신뢰 경계. AI 가 토큰을 스스로 검증하는 것도 아니고(하네스가 알려 줌), **행동 직전에 다시 인증(step-up)** 을 요구하는 기능은 Claude Code 에 없다(`/login` 은 재로그인일 뿐) | 사람의 **현재 존재**를 증명하려면 토큰 소유가 아니라 **그 순간에만 알 수 있는 것**이 필요하다. 이 서버에서 바로 가능한 것: ① `changkwon` 의 `sudo NOPASSWD` 제거 → 파괴적 명령은 사용자가 `!`/`sudo` 로 비밀번호를 치는 순간이 신원 확인(⑪) ② PreToolUse 훅으로 파괴 명령(TRUNCATE·DROP·rm -rf·restore) 을 차단하고, 사용자가 `sudo touch /run/tbox-approve`(2분 유효) 를 친 뒤에만 통과 — sudo 비밀번호 = 사람 확인 ③ 앱과 같은 원리로 Anthropic 계정에 2단계 인증을 켜 토큰 재발급 시 보호. 단 root(eric)는 어느 방법이든 우회 가능 — 인프라 소유자 문제는 그대로(⑨) |
| 2026-09-11 | ⑬ (사용자 반박) "sudo 비밀번호를 요구해도, 비밀번호를 알고 들어온 이상 이미 끝 아닌가?" | **맞다.** SSH 를 비밀번호로 들어온 공격자에게 sudo 비밀번호는 **같은 비밀**이라 문이 하나 더 있는 게 아니다. 이 서버의 인증은 "아는 것" 하나(비밀번호)뿐이고, 그것을 아는 사람은 시스템의 정의상 곧 그 사용자다. sudo 비밀번호 단계가 실제로 막는 것은 다른 위협이다: ① 키 파일만 훔친 공격자(비밀번호는 모름) ② **사람 없이 움직이는 AI**(문서 오염·실수) ③ 열린 터미널을 이어 쓰는 사람(sudo 기억 15분 만료). ①②③과 "비밀번호를 아는 사람" 은 다른 문제 | 비밀번호를 아는 사람을 막으려면 **비밀 하나를 두 개로** 쪼개야 한다(앱의 TOTP 와 같은 이유). 실측: sshd 는 `authenticationmethods any`(키 또는 비밀번호), `changkwon` 은 공개키 2개 등록됨, `eric` 은 0개. 새 패키지 없이 가능한 것: ⓐ `AuthenticationMethods publickey,password` — 키 **그리고** 비밀번호를 둘 다 요구(비밀번호만 아는 사람은 못 들어옴, 키만 훔친 사람도 못 들어옴) ⓑ `AllowUsers changkwon@<사내 대역>` 또는 `Match Address` 로 접속 출처 제한(`192.168.200.50` 은 sshd 단계에서 거절) ⓒ 그래도 뚫린다고 **가정**하고 남길 것 — 이 서버의 자격증명으로 접근 불가한 곳(다른 호스트가 **당겨 가는** 백업, 추가 전용 원격 로그)에 복구·증거를 두기. eric 은 키가 없어 ⓐ 적용 시 접속 불가 → 강사와 협의 필수 |
| 2026-09-11 | ⑭ (사용자 제공) 다른 수강생(`hyeongjoon.mso.so`)의 사고 보고서 본문을 붙여 주며 "방식을 비교해 줘" (링크는 공개 아티팩트라 AI 도구로 읽기 불가 → 본문 붙여 넣기로 해결) | 같은 강사·같은 IP·같은 입구, 같은 판정(앱 무관). 차이는 공격 행동(저쪽 백도어+흔적 삭제 / 우리 전체 삭제+흔적 방치)과 대응 방식(저쪽 AI 즉시 강화 12건 / 우리 복구 우선·강화는 사용자 결정). 비교로 **우리 결함 2건** 발견: ① `hardening.sh` 드롭인 `tbox.conf` 가 `50-cloud-init.conf` 뒤에 읽혀 비밀번호 SSH 차단이 무력화될 수 있었음 ② 증거 원본 사본·해시를 안 남겼음(auth.log 는 주 1회 회전). 저쪽 원인 5(real_ip 없음)는 우리는 이미 적용됨을 실측. 계정 잠금 DoS 는 공통 약점 | SECURITY 8.5 비교표, 8.4-11(증거 사본·SHA256 보전 완료) ·12(hardening 결함 → Opus) ·13(자격증명 교체는 SSH 강화 뒤) ·14(잠금 DoS 결정 필요). 교훈: 남의 사고 보고서는 **내 서버를 다시 점검하는 체크리스트**다 — 설정은 파일이 아니라 실효값(`sshd -T`)으로 검증 |
| 2026-09-11 | (조치 결과) 복구 실행 + ⑥⑦ 순차 2단계 로그인 검증·커밋 | **완료** — 사용자 지시 "복구 스크립트 실행 해" → Fable 실행(분류기 차단 없음): users 20·files 121·sessions 6 복원, audit_logs 보존, 시험 파일 4개만 누락(예상). `qa_employee01` 로그인 200. 2단계 로그인 ①~⑤ 실측 통과(SECURITY 7장 행) | 사용자 확인 사항: ⓐ 기존 비밀번호로 웹 로그인 ⓑ 로그인 화면에 코드 칸이 **안 보이고**, 2단계 인증을 켠 계정은 비밀번호 뒤에 칸이 나타나는지 ⓒ 파일 목록에 사고 전 파일이 보이는지(04:58 이후 올린 4개는 다운로드 불가가 정상) |
| 2026-09-12 | (사용자 요청) "현재 tbox-v2 의 보안 사항을 점검해 — SECURITY 참조 + 그 외도 추가로" | **전면 점검 실행(Fable + 독립 코드 감사 에이전트)** — 인가·주입·업로드·SSRF·헤더·DB 권한·서버 기준선 재실측 통과, **실패 6건**(신규 R-47 감사 기록 500·R-48 로그인 뒤 무잠금·R-49 잠금 카운터·R-50 해시 체인 헛경보·R-53 사용자 sudo NOPASSWD, 기존 R-22 미적용) | SECURITY 7장 2026-09-12 행 · 6.3 R-47~R-55 · R-11 상태 주석. 시험 파일 3개 논리 삭제·고아 0. 데이터 변경(계정·복구·시드) 없음. 다음: 사용자 결정 → R-47·R-50·R-48·R-49 13단계 지시서, R-53·R-22 는 사용자 콘솔 적용 |
| 2026-09-16 | (사용자 질문) "어제 리뷰한 security-review 는 심각도가 있어 보였지만 전부 해결되었고 런타임으로 확인만 하면 된다는 거지?" | **아니다.** 09-15 리뷰 9건 중 서버 층 5건은 「미적용」이 결론이었다. 09-16 실측: 해소 2(①비밀번호 SSH `no` ④디스크 53%)·미해소 4(②ufw ③백업 원격 사본 ⑤원격 로그 ⑧postgres 셸)·수용 2(⑥⑦)·런타임 대기 1(⑨) | `sudo sshd -T \| grep -E 'passwordauth\|permitroot'` · `sudo ufw status` · `df -h /` · SECURITY 7장 2026-09-16 행, `docs/security-review-2026-09-15.md` 5장 |
| 2026-09-16 | (사용자 결정) "서버 운영 측면에서 eric 강사의 권한은 둬야 해. 그를 밴하면 오히려 나는 서버의 보호를 받지 못할 거야" | **동의, 단 실측과 어긋남** — 비밀번호 SSH 가 09-15 13:51 이후 꺼졌고 eric 은 `authorized_keys` 0 바이트라 이미 SSH 불가(콘솔 tty1 만). ufw 기본 관리망(10.0.4.0/24)은 eric 출처(192.168.200.50·10.0.11.63)를 막으므로 켤 때 주의 | SECURITY R-22 갱신 · 8.4-3 갱신 · `sudo ls -la /home/eric/.ssh/` |
| 2026-09-16 | (사용자 질문) "나보다 상위 레벨의 권한자로 sudo 명령을 통해 접근 가능하지 않아?" | **못 들어온다.** sudo 는 로그인 **뒤** 권한을 올리는 명령이라 로그인 자체를 통과시키지 못한다. SSH 는 비밀번호 또는 키가 있어야 하고 eric 은 둘 다 없다. 남은 경로는 VM 콘솔(하이퍼바이저) | 3장 기초 기록 2026-09-16 행 · `last -a \| grep eric` |
| 2026-09-16 | (사용자 질문·결정) "현재 eric 이 접속해야만 보호를 받을 수 있어? 로그인만 차단하고 보호는 받는 상태면 지금이 더 좋은 거 아닌가?" → "반영해" | **맞다, 유지.** 강사의 보호(리버스 프록시 TLS·헬스체크, 하이퍼바이저, 네트워크)는 전부 VM 밖이라 eric 의 VM 로그인과 무관. eric SSH 는 9-11 진입 경로였으니 닫힌 지금이 낫다. 공개키를 받으면 등록, 계정 잠금·삭제는 안 함. 강사에게 알리는 것은 사용자 몫 | 커밋 `a358112` (SECURITY R-22·8.4-3·7장, 리뷰 문서 5장) · `practice-vm-environment` 메모(프록시 구조) |
| 2026-09-16 | (사용자 질문) "그럼 보안 치명 및 높은 수준으로 보여진 발견사항은 해결된 거지?" | **아니다, 5건 중 1.5건.** ①비밀번호 부분(root 키 허용 잔존) ④디스크 해결. ②방화벽 ③백업 원격 사본 ⑤원격 로그 **미해결**. 권고 순서: ③(다른 PC 에서 `scp` pull, 서버 변경 없음) → ②(80 은 프록시만, 22 는 열고 키 인증) → ⑤(pull 에 `/var/log/tbox-v2/*` 포함) | 리뷰 문서 1장 표·5장 · SECURITY 8.4 조치표 3·4 |

## 3. 학습 기록 — 단계와 무관한 기초 (터미널·리눅스)

과제 도중 나온 기초 질문도 여기에 쌓는다. "몰라서 막힌 것"이 다음 사람의 첫 장애물이기 때문이다.

| 날짜 | 질문 | 답 | 확인 명령 |
|---|---|---|---|
| 2026-09-10 | `ls` 와 `ll` 의 차이는? | `ls` 는 실제 명령, `ll` 은 셸이 미리 정해 둔 **별칭(alias)**. 이 서버에서는 `~/.bashrc` 91행 `alias ll='ls -alF'` | `type ll` → `ll is aliased to 'ls -alF'` |
| 2026-09-10 | `ls -l` 과 `ll` 결과가 다른데? | `ll` = `ls -alF` 이므로 `-a`(`.`·`..`·숨김 파일 포함)와 `-F`(디렉터리 `/`, 실행 파일 `*`, 링크 `@` 기호) 가 더 붙는다. 이 서버는 `ls` 자체도 `ls --color=auto` 별칭 | `ls -l` vs `ls -alF` 를 나란히 |
| 2026-09-10 | `type ls -l` 하면 `-l: not found` 가 나오는 이유? | `type` 은 인자 **하나하나를 명령 이름**으로 조회한다. `ls` 는 별칭이라고 알려 주고, `-l` 이라는 이름의 명령은 없으니 not found. 별칭은 첫 단어(`ls`)만 치환되고 `-l` 은 뒤에 그대로 붙어 실행된다 | `type ls` · `alias` (별칭 전체 목록) |
| 2026-09-10 | `-a` 와 `-A` 가 달라? | 둘 다 숨김 파일을 보이지만 `-a`(`--all`)는 `.`(현재)·`..`(상위)까지 포함, `-A`(`--almost-all`)는 그 둘만 뺀다. 스크립트에서 디렉터리를 순회할 땐 `.`·`..` 이 섞이면 위험하므로 `-A` 가 안전 | `ls -a` vs `ls -A` |
| 2026-09-10 | `.` 과 `..` 을 누르면 앞뒤 페이지 이동인가? | "누르는 버튼"이 아니라 **디렉터리를 가리키는 이름**이다. `..` = 상위 디렉터리(`cd ..` 로 한 단계 위), `.` = 현재 디렉터리(`./script.sh`, `cp ../a.txt .`). "직전 위치로 되돌아가기"는 `cd -` | `cd ..` · `cd -` · `pwd` |
| 2026-09-10 | 스크립트 안에서 `ll` 을 쓰면? | 별칭은 대화형 셸에서만 살아 있고 스크립트에서는 적용되지 않는다. 스크립트에는 `ls -l` 처럼 실제 옵션을 적는다 | `bash -c 'll'` → command not found |
| 2026-09-11 | 서버 계정(`changkwon`) 비밀번호는 어떻게 바꿔? (사고 후속 R-38) | `passwd` 를 **직접 연 터미널**에서 친다. 현재 비밀번호 → 새 비밀번호 → 한 번 더. 입력 중 글자가 전혀 안 보이는 것이 정상. 짧거나 사전 단어면 `BAD PASSWORD` 로 거부. 이 비밀번호는 SSH·sudo 용이며 t-box 웹 계정 비밀번호와 별개. 바꿔도 지금 SSH 창은 안 끊기고 다음 접속부터 적용 — **새 창에서 접속 확인 뒤** 기존 창을 닫는다. 명령줄에 비밀번호를 적으면 히스토리에 남으니(R-38 의 원인) 항상 프롬프트로 입력 | `passwd` → `password updated successfully` · `sudo -k && sudo true` (새 비밀번호로 sudo 확인) |
| 2026-09-11 | `passwd` 안내를 보고 `.` 과 `Current password` 를 명령처럼 쳤다 → `filename argument required` / `command not found` | 안내 글의 번호 `1.` `2.` 와 `Current password:` 는 **컴퓨터가 물어보는 문구**라 직접 치는 것이 아니다. 터미널에 치는 것은 `passwd` 한 단어뿐이고, 그 뒤에 나오는 물음에 비밀번호만 입력한다. `.` 만 치면 셸의 `source` 명령이라 파일 이름을 요구한다 | `passwd` 를 치고 `Current password:` 가 **자동으로** 나오는지 본다 |
| 2026-09-11 | 서버 시간이 UTC 인데 KST 로 바꿔 줘 | 시각은 세 층(OS·PostgreSQL·PHP)이 따로 갖고 있어 **함께** 바꿔야 한다. OS `timedatectl set-timezone Asia/Seoul`, DB `ALTER SYSTEM SET timezone`, PHP `date.timezone`. 저장된 시각(`TIMESTAMPTZ`)은 절대 시각이라 데이터는 안 바뀌고 표시만 `+09` 로 나온다. cron 도 KST 로 돈다. 자세한 절차는 README 「서버 시간대」 | `timedatectl` · `date` · `psql -Atc "show timezone"` · `php -r 'echo date("c");'` |
| 2026-09-11 | `password` 라고 쳤더니 `Command 'password' not found` | 명령 이름은 **`passwd`** 다 — password 에서 `or` 를 뺀 여섯 글자(p·a·s·s·w·d). 유닉스 초기에 명령 이름을 짧게 줄이던 관습이 남은 것(`ls`=list, `cd`=change directory, `mv`=move 도 같은 계열). 오타면 셸이 "비슷한 명령이 있다"며 다른 것을 추천하는데(1password 등) 그건 무시한다 | `passwd` 를 치고 `Current password:` 가 자동으로 나오는지 |
| 2026-09-11 | 시드 계정 비밀번호는 어디서 보나? | `/etc/tbox-v2/seed-accounts.txt` — root 만 읽을 수 있어(0600) `sudo` 가 필요하다. 한 줄에 `아이디 역할 비밀번호`. 시드 5개(`admin01`·`manager01`·`employee01`·`employee02`·`guest01`)만 사용자용이고 `qa_*` 5줄은 AI 검토 전용. **첫 로그인에서 비밀번호를 바꾸면 이 파일의 값은 옛 값**이 된다 → 5개 모두 바꾼 뒤 `sudo rm` (R-33). 값을 채팅·메신저에 붙이지 않는다 | `sudo cat /etc/tbox-v2/seed-accounts.txt` (내 터미널에서만) |
| 2026-09-11 | 비밀번호를 바꾸면 DB 에 저장될 텐데, DB 는 어떻게 확인하나? | DB 에는 비밀번호가 **없다** — 단방향 해시(Argon2id, `$argon2id$…`)만 있어 원문을 되돌릴 수 없고, 로그인 때 입력값을 같은 방식으로 계산해 **비교**만 한다. 그래서 "바뀌었는지" 는 해시 문자열이 달라졌는지·`password_changed_at` 이 찍혔는지·`must_change_password` 가 false 가 됐는지로 본다. DB 는 `psql` 로 들어가 SQL 로 본다(나가기 `\q`, 표 목록 `\dt`, 표 구조 `\d users`) | `sudo -u postgres psql -d tbox_re` 뒤 `SELECT username, left(password_hash,22), password_changed_at, must_change_password, mfa_enabled FROM users ORDER BY id;` |
| 2026-09-11 | "암호화하고 복호화하는 절차인가?" (문구로 잠근 저장 제안에 대해) | 그렇다. 등록 때: t-box 의 무작위 비밀 키 + 사용자 문구 → PBKDF2-SHA256 으로 잠금 키 → AES-GCM 으로 비밀 키를 암호화 → **암호문만** PC 에 저장(문구·잠금 키·원본은 저장 안 함). 쓸 때: 문구 → 같은 계산 → 복호화 → 코드 계산. 문구가 틀리면 AES-GCM 이 스스로 실패를 알린다. 공격자는 문구와 **암호문(PC)** 둘 다 필요 → "가진 것" 겹 유지 | 설계 그림은 대화 기록·SECURITY R-40 후속 |
| 2026-09-11 | "SHA-256 으로 암호화하고 AES 로 복호화하면 되는 거 아냐?" | **해시와 암호화는 다르다.** SHA-256 은 한 방향(믹서기: 주스에서 과일로 못 돌아감) — 복호화라는 것이 없고 누구도 되돌릴 수 없다. AES 는 양방향(자물쇠: 같은 키로 잠그고 푼다). 잠근 알고리즘과 푸는 알고리즘은 같아야 하므로 "SHA-256 으로 잠그고 AES 로 푼다" 는 성립하지 않는다. 권고안에서 SHA-256 은 **문구를 키 모양으로 바꾸는 데만**, 잠그기·풀기는 **둘 다 AES** 가 한다. 정확한 문장: "SHA-256 으로 키를 만들고, AES 로 잠그고 AES 로 푼다" | 같은 원리가 비밀번호 저장에도: DB 의 `password_hash` 는 SHA 계열이 아닌 Argon2id 해시이지만 역시 한 방향이라 관리자도 원문을 못 본다(앞 질문 참조) |
| 2026-09-11 | "자체 점검 통과. 평시 로그인할 때는 이 암호 문서를 어떻게 열어 확인하나? 컴퓨터에 생성기를 저장해 두고 쓰는 건가?" | 그렇다. 생성기 HTML 을 PC 에 저장해 두고(바탕화면 등) 로그인할 때 그 파일을 열어 잠금 문구 → 6자리 → t-box 2단계 칸에 입력. **주의**: 잠가 저장한 암호문은 브라우저의 '그 주소(origin)' 저장소에 남는다 — `http://localhost:8082` 에서 저장했으면 파일(`file://`)로 열었을 때는 보이지 않는다. 그러므로 **평시에 쓸 자리(저장한 파일)에서 잠가 저장**해야 한다(같은 PC·같은 브라우저·같은 파일). 브라우저 데이터 삭제·PC 교체 시 암호문이 사라지므로 같은 비밀 키를 스마트폰 앱에도 등록해 두면 예비가 된다 | STAGES 자기 공격 체크·README 순서 보강 |
| 2026-09-11 | "Admin 이 (비밀번호·문구를) 까먹으면? 개발자인 내가 찾아줄 수 있나?" | **찾아 줄 수는 없고, 다시 발급해 줄 수는 있다.** ① 비밀번호: DB 에는 Argon2id 해시만 있어 누구도 원문을 못 본다 → **재발급**(다른 Admin 이 앱에서 `임시 비밀번호 재발급`(감사 기록 남음), 없으면 서버 운영자가 `reset_admin01.sh` 같은 예외 절차(sudo 로그만 남음)). ② 2단계 인증 비밀 키: 서버가 코드를 검증해야 하므로 `users.mfa_secret` 에 **평문(base32)** 으로 있다 → DB 슈퍼유저는 읽어서 `totp_code.php` 로 코드를 만들 수 있고, 보통은 `2단계 인증 초기화` 로 새 키를 받게 한다. ③ 생성기의 잠금 문구: 어디에도 저장되지 않아 복구 불가 — 대신 ②로 새 키를 받으면 된다. **함의**: DB 슈퍼유저(root)는 2단계 인증을 통째로 우회할 수 있다(R-11 과 같은 한계) — 그래서 그 권한을 쓰는 일은 예외로 두고 SECURITY 8.4 처럼 기록한다 | 운영 권고: Admin 2인 이상(서로 재발급), 예외 절차는 문서화·기록. 오늘 admin01 이 그 예외 사례 |
| 2026-09-11 | "그럼 오늘 같은 eric 의 해킹은 막을 수 있나?" | **그 방법은 막을 수 있지만, 그 사람은 못 막는다.** 오늘의 경로(비밀번호 SSH → NOPASSWD sudo → root → psql) 는 ① SSH 키 전용(또는 키+비밀번호) ② `AllowUsers`/접속 대역 제한 ③ 두 계정의 NOPASSWD 제거 ④ 미사용 관리자 계정 잠금 으로 **모두 끊긴다**(eric 은 키가 없고 허용 대역 밖). 그러나 VM 소유자(하이퍼바이저)는 콘솔·디스크로 언제든 root 가 되므로 **막는 것이 아니라 흔적·복구를 밖에 두는 것**(원격 로그, 다른 호스트가 당겨 가는 백업)이 답이다. 현재 상태: 위 ①~④ **아직 미적용**(hardening.sh 드롭인 순서 결함 수정 전, 강사 협의 전) — 지금 다시 시도하면 **그대로 뚫린다** | 다음 결정: 강사와 협의 → Opus 가 `hardening.sh` 결함 수정 → 사용자가 콘솔에서 적용 → `sshd -T` 실효값 검증 → 원격 백업 |
| 2026-09-11 | "비밀 키 생성기가 로컬 어디에 저장돼 있나?" | 세 곳을 구분한다. ① **서버 원본**: `/var/www/tbox-v2/public/assets/totp-generator.html` (터널로 `http://localhost:8082/assets/totp-generator.html`). ② **내 PC 의 파일**: 브라우저 `Ctrl+S` 로 저장한 위치 — 따로 고르지 않았으면 다운로드 폴더(Windows `C:\Users\<이름>\Downloads\totp-generator.html`). 브라우저 다운로드 목록(`Ctrl+J`)에서 「폴더에서 보기」로 찾는다. ③ **잠가 저장한 암호문**: 파일 안이 아니라 **브라우저 프로필의 저장소(localStorage)** 에 있다 — 파일을 다른 곳으로 옮겨도 그대로지만, 다른 브라우저·다른 PC 에서는 없다 | 파일을 바탕화면 등 정한 자리로 옮겨 두고, 항상 같은 브라우저로 연다 |
| 2026-09-11 | hardening.sh 출력 모드가 "현재 출처 192.168.105.44 가 관리망에 없다" 며 거부 | `192.168.105.44` 는 **이 서버 자신의 IP** 다. 19:52 에 터널 명령 `ssh -L 8082:127.0.0.1:8082 changkwon@192.168.105.44` 를 PC 의 PowerShell 이 아니라 **서버 안 터미널에서** 실행해, 서버가 자기 자신에게 SSH 로 들어간 **중첩 세션**(pts/3, 비밀번호 인증)이 생겼다. 그 창에서 스크립트를 돌리니 출처가 서버 IP 로 잡혀 안전장치가 거부한 것 — 안전장치는 정상 동작. 그 터널은 PC 브라우저에 아무 효과가 없다(포트가 서버 안에서 서버로 열림) | 구분법: 각 창에서 `echo $SSH_CONNECTION` — 바깥 창은 `10.0.4.173 … 192.168.105.44 22`, 중첩 창은 `192.168.105.44 … 192.168.105.44 22`. 조치: 중첩 창에서 `exit` 로 나오기, 터널은 **PC 의 PowerShell** 에서 실행, hardening 은 바깥 창(+바깥 창 하나 더)에서 |
| 2026-09-11 | "서버→서버 비밀번호 로그인도 막힌다는 게 무슨 소리?" | 19:52 의 중첩 세션은 `auth.log` 에 `Accepted password for changkwon from 192.168.105.44` 로 남았다 — 즉 **비밀번호**로 들어간 SSH 다. 강화가 적용되면 sshd 가 `PasswordAuthentication no` 가 되어 SSH 로 비밀번호를 받는 문이 **어디서 오든**(PC 든, 서버 자신이든) 닫힌다. 그래서 같은 명령을 다시 치면 거절된다는 뜻. 영향 범위는 SSH 만: 이미 열린 창·`sudo` 비밀번호·t-box 웹 로그인·PC 의 **키** 로그인(등록된 키 2개)은 그대로 | 확인: 적용 뒤 `ssh -o PubkeyAuthentication=no changkwon@192.168.105.44` → `Permission denied (publickey)` 가 정상 |
| 2026-09-11 | "서버가 왜 자기 자신에게 SSH 로 들어가? 프록시가 내려가서 8082 를 직접 연 걸 말하나?" | 아니다, 두 가지가 섞였다. ① **8082 는 새로 연 게 아니다** — 1단계부터 있던 검토용 로컬 사이트(서버 안 127.0.0.1 에서만 열림). ② `ssh -L 8082:… changkwon@192.168.105.44` 는 "**지금 내가 있는 컴퓨터에서** 192.168.105.44 로 접속하고, 내 8082 를 그쪽 8082 로 이어라" 는 명령이다. PC 의 PowerShell 에서 치면 PC→서버(의도한 터널). 그런데 이미 서버에 들어가 있는 창(`changkwon@eric:~$`)에서 치면 "지금 있는 컴퓨터" 가 서버라서 서버→서버가 된다. 의도가 아니라 **명령을 친 자리** 때문에 생긴 일이며 해를 끼치진 않는다 | 규칙: 프롬프트가 `changkwon@eric` 이면 서버 안, `PS C:\…>` 이면 PC. 터널 명령은 후자에서 |
| 2026-09-11 | 결정 | "진행해" — hardening.sh 결함 수정·강화 항목 보강(적용은 사용자) | 지시서 작성 중 **두 번째 결함** 발견: 관리망 기본값이 사용자 출처(10.0.4.x)를 빼고 침입자 출처(192.168.200.x)를 포함. 교훈: 방화벽·접속 제한 값은 문서의 가정이 아니라 **지금 내가 들어온 출처**(`echo $SSH_CONNECTION`)로 검증한다. 스크립트에 자기 잠금 방지 검사와 2차 세션 플래그를 넣도록 지시 |
| 2026-09-11 | 해소 | hardening.sh 수정 검토 통과 | 시뮬레이션(임시 사본 + `sshd -T -f`)으로 결함 재현→수정 확인: 옛 이름 `tbox.conf` 는 `passwordauthentication yes`, 새 이름 `00-tbox.conf` 는 `no`, 충돌 파일 이름 변경 뒤에도 `no`. 출력 모드 재실행: 현재 출처 10.0.4.173 이 관리망에 포함, 충돌 드롭인 `50-cloud-init.conf` 경고, 실효값 표(적용 전이라 '다름' 정상). `--apply` 단독·옛 관리망 값 → 종료 2 로 거부. 실제 변경 0(sshd_config.d·ufw·sshd -T 전후 동일). Opus 추가: `sudo` 가 `SSH_CONNECTION` 을 지우므로 부모 프로세스 environ 에서 출처를 역추적(`--from-ip` 대안) | 적용은 사용자: 새 SSH 창 하나 더 연 뒤 `sudo bash deploy/hardening.sh --apply --i-have-a-second-session` → 표가 전부 '일치' → 새 창에서 접속 확인 → 기존 창 닫기. eric 관련 옵션은 협의 후 |
| 2026-09-11 | 결정 | "오케이 진행해" — 문구로 잠근 저장(ⓐ)·4자 묶음 표시(ⓑ) 채택, QR(ⓒ)은 후속 | PRD F-17-2, SECURITY R-40 갱신 → Opus 구현 지시 |

---
| 2026-09-13 | "`/chrome` 을 치니 Status: Disabled · Extension: Not detected 로 뜬다 — 이렇게 뜨는 게 맞나?" | 맞다. Claude in Chrome 확장은 **Claude Code 가 실행되는 컴퓨터의 크롬**과 붙는다. 이 세션은 서버(VM, 크롬 없음)에서 돌고 있어 PC 의 크롬을 감지할 수 없다. 서버에서 이카운트 화면을 읽으려면 PC 에서 `claude --chrome` 으로 세션을 열어야 하고, 아니면 화면 캡처(품목등록·거래처등록·발주서 입력·구매 현황)를 붙이는 것이 현실적이다. 확장은 읽기 전용이 아니므로 켜더라도 사이트 권한을 이카운트 도메인에만 준다 |
| 2026-09-13 | PC 의 캡처 파일을 서버로 옮기려고 PowerShell 명령을 Claude Code 세션(`!`)에 입력 → `bash: syntax error` | 이 세션의 `!` 는 **서버의 bash** 에서 실행된다. PowerShell 명령은 **PC 의 PowerShell 창**에서 실행해야 하고, 파일이 PC 에 있으므로 복사 명령도 PC 쪽에서 내야 한다(`scp 로컬파일 사용자@서버:경로`). 구분법: 프롬프트가 `PS C:\…>` 이면 PC, `changkwon@eric:~$` 이면 서버 |
| 2026-09-13 | 「가져오기 확정을 누르면 잘못된 요청이라고만 뜬다」 | **재현됨(서버·화면)** — 6차 보정이 미리보기·확정에 같은 본문 함수를 쓰게 하면서 확정 요청에 `page` 키가 실렸고, 확정 핸들러 허용 키 목록에 없어 20:05~20:07 확정 7회 전부 `bad_request` | 화면은 page·status·q 셋을 확정에서 지우고, 서버는 셋을 받아 무시. stage19 ⑪-f 를 화면이 보내던 모양으로 회귀 확인. 커밋 7014e9d |
| 2026-09-13 | 「창고 업로드가 지점을 먼저 골라야 해서 매끄럽지 않다 — 브랜드·지점은 사람이 만들고 창고는 그대로 올려 연결하는 한 페이지로」 | **설계 변경 채택** — 대기표 `p_location_inbox` + 「브랜드·지점·창고」 화면(24단계). 확정 사항: 창고명 이카운트 원문 · 지점 없는 창고는 「제외」 체크 · 창고 1 = 지점 1 | 지시서 docs/purchase/09, 커밋 113b46a. 사용자가 검토 전 실사용으로 창고 12건을 부산점에 연결(21:21) |
| 2026-09-13 | 「로그인 화면 문구(사내망·VPN, 30일 복구 …)가 구조를 알려 주는 것 아닌가」 | **부분 타당** — 감시 고지·2단계 요건은 남기고, VPN·30일 보존·다운로드 방식은 로그인 뒤 안내로 옮기는 것이 원칙 | 사용자 결정 대기(문안 제안함). 조치 전 |
| 2026-09-13 | 「생성기에서 만든 코드가 시간이 지나도 인증이 통과한다」 | **서버 정상·생성기 표시 문제** — 한 코드는 최대 59초 통과(±1 창), 60초부터 거부, 재사용은 `code_reused` 거부. 생성기가 「다음 코드」(미래 스텝)를 미리 보여 줘 그 코드가 지금부터 약 1분 더 유효했던 것 | 생성기 「다음 코드」 표시 제거·시각 카드 문구 수정, SECURITY R-45 후속 기록. 2분 이상 지난 코드가 통과했다면 다른 원인 — 시각을 받아 `auth.login`·`auth.mfa` 감사에서 확인 예정 |
| 2026-09-15 | `$(whoami)` 를 `$(changkwon)` 으로 바꾸니 `changkwon: command not found` | `$( … )` 는 **명령 치환** — 괄호 안을 명령으로 실행하고 그 출력으로 바꾼다. `whoami` 는 사용자 이름을 출력하는 명령이라 `$(whoami)` → `changkwon` 이 되지만, `changkwon` 은 명령이 아니므로 실행 실패. 값을 직접 쓰려면 괄호 없이 `https://changkwon.mso.so/…` | `echo $(whoami)` → `changkwon` · `echo $(changkwon)` → `command not found` |
| 2026-09-15 | `curl.exe https://changkwon.mso.so/api/health\` 가 왜 실패하나? | 두 가지가 겹쳤다. ① **`curl.exe` 는 Windows 용 실행 파일 이름**이다. 프롬프트 `changkwon@eric:/var/www/tbox-v2$` 는 리눅스 서버의 bash 이므로 `curl.exe` 라는 명령이 없어 `command not found` 가 난다 — PowerShell 에서 `curl` 이 `Invoke-WebRequest` 별칭이라 `.exe` 를 붙이던 습관이 서버에 따라온 것. 리눅스에서는 `curl` 이 실제 명령(`/usr/bin/curl`). ② 끝의 **`\`(역슬래시)** 는 bash 에서 「다음 줄에 명령이 이어진다」 는 뜻이라, 엔터를 쳐도 실행되지 않고 `>` 프롬프트로 다음 줄을 기다린다(`Ctrl+C` 로 빠져나온다). 주소는 정상 — `curl` 로 200 이 온다 | `which curl.exe` → 없음 · `curl -sS -o /dev/null -w '%{http_code}\n' https://changkwon.mso.so/api/health` → `200` |
| 2026-09-15 | `git add 파일… && git commit -m "…" && git push origin main` 을 실행하면 어떻게 되나? | 세 단계가 차례로 돈다(`&&` 는 앞이 성공해야 다음 실행). ① `add` = 지정한 파일만 「이번 커밋에 넣을 목록」(스테이징)에 올림 — 목록에 없는 `jar`·`api-test.txt` 는 빠짐(CLAUDE.md 6장 `git add -A` 금지의 이유) ② `commit` = 그 목록을 메시지와 함께 로컬 저장소 이력에 한 덩이로 기록(해시 생김, 서버 파일·서비스는 안 바뀜) ③ `push` = 그 커밋을 원격(`git2.tla.so`)의 `main` 에 올림. 되돌리기: push 전이면 `git reset --soft HEAD~1`, 후면 새 커밋으로 고침 | `git status` → `git log --oneline -1` → `git status -sb`(`ahead 0` 이면 push 됨) |
| 2026-09-16 | sudo 권한이 있는 계정(eric)은 SSH 로그인이 막혀도 sudo 로 들어올 수 있나? | 없다. **로그인**(sshd 가 비밀번호·키를 확인)과 **권한 상승**(로그인한 뒤 `sudo` 가 sudoers 를 확인)은 별개 단계다. sudo 는 이미 셸을 얻은 사람만 쓸 수 있으므로 진입 경로가 아니다. 로그인 경로는 SSH(비밀번호·키)·콘솔(tty)·다른 서비스만이다 | `sudo sshd -T \| grep -E 'passwordauthentication\|pubkeyauthentication'` · `getent group sudo` · `last -a \| head` |

## 4. 이 문서를 채우는 규칙 (요약 — 원문은 `CLAUDE.md` 9장)

1. **언제**: 사용자의 질문에 답한 직후, 관문 ②·③ 결과가 나온 직후, 커밋 직전. 커밋 직전 갱신이 없으면 커밋하지 않는다.
2. **무엇을**: 그 단계의 개발 체크 상태 · 검토 체크 결과(통과/실패/미검증) · 자기 공격 체크 결과 · 커밋 해시 · 학습 기록(어려움 / 궁금 / 해소 / 결정 / 요청).
3. **어떻게**: 학습 기록은 `날짜 | 구분 | 내용(사용자 말의 요지) | 해소·근거(답과 문서 위치)` 한 줄. 답을 다시 쓰지 않고 **어디에 근거가 있는지** 가리킨다.
4. **기초 질문**(터미널·리눅스·git 등)은 3장에. 단계와 무관해도 버리지 않는다.
5. 보안 점검 **방식**이 바뀌면 1장을 함께 고친다. 판정 근거는 언제나 `SECURITY.md` 7장 이력이며 이 문서는 그 요약이다.

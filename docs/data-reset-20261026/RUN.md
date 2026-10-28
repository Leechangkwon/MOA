# 모아 운영 자료 일부 지우기 — 실행 절차 (2026-10-26)

사용자 요청(2026-10-26): 「여기까지 하고, 로컬 컴퓨터에 클론후 서버 db는 초기화 한다 / Tbox업로드된 파일 및 스레드 사진 및 글 정보만 / 업무 MCP로 올리고 있는건 일단 운영 지속..」

## 정한 범위 (사용자 답 2026-10-26)

| 항목 | 결정 |
|---|---|
| 로컬 클론 | 전체 사본. 외장하드에서 서버를 띄워 사용·개발(임시). 본 서버가 안정되면 다시 클론·동기화. git 커밋·push 절차는 같다 |
| 글 정보 | 이야기 + 게시판 + 업무 프로젝트 밖 문서. 업무 프로젝트(Rea_ERP·모아) 나무 60문서는 남긴다 |
| 함께 정리 | T-box 폴더 18개 · 서버 안 옛 백업(`/var/backups/tbox-v2`) · 시험·임시 DB |
| 남김 | 계정·설정·팀·공간(게시판 틀)·업무 프로젝트·감사 기록·MCP 연결·메신저·제품 공간·여행 계획 |
| 시점 | 사용자가 PC 로 받아 대조를 끝냈다고 확인하고 「지워」라고 한 뒤. 서비스 멈춤·알림 없음. 22:00 밤 회귀 시간은 피한다 |
| 프로그램 배포본 | **7개 모두 지운다**(사용자 결정 2026-10-26 「전부 초기화해 더 최신본이 있어」, `keep_programs=0`). 「프로그램 받기」는 새 판을 올릴 때까지 빈다. 옛 판은 백업에 있다 |

## 백업 (만듦 · 확인 끝)

`/home/changkwon/offsite-backups-20261026-predelete/` — 25개 271,223,847 B, 키 `offsite-key-20261026/backup.key`. 내용·확인은 그 폴더 `RESTORE.txt`.

## 모의 실행 (임시 DB `tbox_predel_test` — 끝)

- `delete-plan.sql`(keep_programs=1) 끝까지 COMMIT. 바뀐 표 37개 / 123개 — `dryrun-table-diff.txt`.
- 남길 업무 프로젝트: 문서 60 · 블록 1,528 · 버전 24 · DB 2 해시가 모의 뒤와 운영이 같다.
- 계정 35 · 팀 3 · 공간 20 · API 토큰 114 · MCP(clients 15 · grants 16 · tokens 164) · 감사 기록 · 메신저 12 · 제품 공간 34 · 여행 계획 1 행 수 그대로.
- 지울 디스크 파일 189개 143,611,242 B(T-box 154 · 격리 8 · 이야기 사진 24 · 문서 첨부 3), 남는 파일 = 지금 판 프로그램 1(TBox-Closing 2026.10.17.3) + 자동화 커서 2 — `dryrun-files-summary.txt`.
- 「최신본만」 결정 뒤 임시 DB 를 백업에서 다시 복원해 모의 실행을 다시 돌림: files 1,049 → 1(지금 판, program_current=true), 나머지 결과는 위와 같다.
- 「전부 초기화」 결정(keep_programs=0) 뒤 다시 복원해 모의 실행 — 결과는 아래 「최종 모의」.

## 운영 실행 순서 (「지워」를 받은 뒤에만)

```bash
D=/var/www/qaredi/tbox-v2/docs/data-reset-20261026
# 0) 백업 뒤 새로 생긴 대상 자료가 없는지 — 있으면 멈추고 백업을 다시 뜬다
sudo -u postgres psql -X -At -d tbox_re -c "select
  (select count(*) from files where created_at > '2026-10-26 16:11+09'),
  (select count(*) from community.memory_posts where greatest(created_at, updated_at) > '2026-10-26 16:11+09'),
  (select count(*) from community.memory_media where created_at > '2026-10-26 16:11+09'),
  (select count(*) from community.comments where created_at > '2026-10-26 16:11+09'),
  (select count(*) from community.pages where updated_at > '2026-10-26 16:11+09')"   # 마지막 칸은 업무 프로젝트 쪽 MCP 수정이면 괜찮다(남는 쪽) — 무엇인지 본다
# 1) 지울 파일 목록(지우기 전 DB 에서)
bash $D/make-file-list.sh tbox_re 0 $D/delete-files.txt
# 2) DB — 한 트랜잭션
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v keep_programs=0 -d tbox_re -f $D/delete-plan.sql
# 3) 디스크 파일 — 목록의 경로만, 저장소·격리 폴더 밖이면 거부
cut -d'|' -f1 $D/delete-files.txt | grep -E '^/var/lib/tbox-v2/(storage/([0-9a-f]{2}/[0-9a-f]{2}|attachments/[0-9a-f]{2}|memories)|quarantine)/[^/]+$' > $D/delete-paths.txt
[ "$(wc -l < $D/delete-paths.txt)" = "$(wc -l < $D/delete-files.txt)" ] && sudo xargs -d '\n' rm -- < $D/delete-paths.txt
# 4) 시험·임시 DB
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d postgres -f $D/drop-test-dbs.sql
# 5) 서버 안 옛 백업(작은 것은 백업 폴더 old-server-backups 로 옮겨 둠)
sudo rm -rf /var/backups/tbox-v2/{db,files,files-mirror,manual,weekly,monthly,moa-qf15-s1b-20261025,moa-qf15-s2-20261026,moa-qf15-s3-20261026}
```

## 실행 뒤 확인

- 표 행 수(모의와 같은지) · `php scripts/consistency_check.php` · 디스크 남은 파일.
- claude_ 계정 + UA `tbox-review/2026-10-26`: 빈 자료실 · 빈 이야기 · 게시판 빈 목록 · 업무 프로젝트 읽기 · 업무 문서 쓰기 창구(MCP 와 같은 `WorkDocuments`) 한 번.
- 사용자 MCP(pkdkak1) 의 다음 `mcp.tool` 이 allow 인지 감사 기록.
- 기록: `SECURITY.md` 7장 · `STAGES.md`.

## 되돌리기

백업 폴더의 `tbox_re.dump.gpg`·`tbox-v2-files.tar.gpg` 로 되살린다(`RESTORE.txt`). 지운 뒤 서버에서 바뀐 업무 문서는 백업에 없으니 되살리기 전에 따로 떠 둔다.

## 최종 모의 (keep_programs=0, 2026-10-26)

임시 DB 를 백업에서 새로 복원해 실행. 바뀐 표 37개 / 123개(`dryrun-table-diff.txt`) — files 1,049 → 0 · folders 18 → 0 · 문서 1,080 → 60(업무 프로젝트만) · 이야기 글·사진·댓글 0 · 게시판 post_count 합 0.
지울 디스크 파일 190개 144,626,343 B(T-box 155 · 격리 8 · 이야기 사진 24 · 문서 첨부 3), 남는 파일 = 자동화 커서 2 — `dryrun-files-summary.txt`.
업무 프로젝트 60문서·블록 1,528 해시가 모의 뒤와 운영이 같다. 계정·팀·API 토큰·MCP·감사 기록·메신저·제품 공간·공간·여행 계획 행 수 그대로.

## 서버 통째 사본 (2026-10-26 사용자 「외장하드에는 현재 서버 그대로 클론」)

`/home/changkwon/qaredi-clone/stream-rootfs.sh` 로 루트 파일 시스템을 PC WSL 에 ssh 로 흘려 받음(서버 디스크에 안 씀). 17:45:30~18:24:21 ·
12,519,492,485 B · sha256 `86b34219b14aa42068ac5a6f804def1b77e2541a263ff4a0cc265653677c6625` · 항목 1,110,271 · `tar=1 zstd=0 gpg=0 tee=0`(tar 1 = 운영 중 바뀐 파일, 정상).
사용자 PC `F:\qaredi-backups\server-clone-20261026\` 에서 크기·sha256 대조 일치. 가져오기 절차 `~/qaredi-clone/RESTORE-ROOTFS.md`.

---

## v2 — 범위 넓힘 (2026-10-28, 서버 세션)

사용자 결정(2026-10-28): 「업무 프로젝트 2개 및 하위 제외한 모든걸 지워」 · 계정은 **켜진 11개 남김 / 꺼진 24개 지움** · 감사 기록은 **행은 남기고 지운 자료의 이름·요약만 비움**(디스크 `audit.log` 는 봉인돼 그대로) · 서버 안 옛 백업은 「다음 날 04:30 검증 뒤, 그보다 오래된 파일만」(10-27 권장안).
어제(10-27) 10:08~10:10 에 만든 「회의록 gg」·「내 할 일」·「a」도 지운다(업무 프로젝트 밖).

- 계획 SQL: `delete-plan-v2.sql` — v1 1~3장을 그대로 품고 4장(남은 내용 표 전부) · 5장(꺼진 계정·그 개인 공간 6) · 6장(감사 이름 비우기) · 7장(확인)을 더함.
- 남기는 틀: 업무 프로젝트 60문서·블록 1,528·버전 24·DB 2 · 계정 11 · 팀 3 · 공간 14(업무 프로젝트·남는 계정 개인·팀·게시판 틀) · 권한표 · 설정(AI·진단 정책·팔레트·임베드·가져오기 허용) · 사이트 기본 템플릿 3 · pkdkak1 MCP 연결 7 · 살아 있는 API 토큰 6 · 패스키 2 · 관리자 휴대폰 경보 구독 2.
- 모의 실행(10-28 11:25 운영 덤프를 `tbox_predel_test` 에 새로 복원): 한 번에 COMMIT · 바뀐 표 65 / 123(`dryrun-table-diff-v2.txt`) · 업무 프로젝트 문서·블록·버전·DB·pkdkak1·MCP 연결 해시가 운영과 같음 · 감사 내용 행 17,003 이름 비움(업무 프로젝트 행 제외), 남은 「」 안 이름은 계정·팀·공간·팔레트·업무 프로젝트 문서 제목뿐.
- 지울 디스크 파일 178개 143,606,966 B(T-box 143 · 격리 8 · 이야기 사진 24 · 문서 첨부 3), 남는 파일 = 자동화 커서 2(`dryrun-files-summary-v2.txt`).

### v2 운영 실행 순서 (「지워」를 받은 뒤, 05:00~21:30)

```bash
D=/var/www/qaredi/tbox-v2/docs/data-reset-20261026; K=~/offsite-key-20261026/backup.key; B=~/offsite-backups-20261026-predelete
# 0) 지우기 직전 DB 덤프(암호화) — 10-26 16:11 백업 뒤 생긴 자료(새 문서 3 등)까지 되살릴 수 있게
sudo -u postgres pg_dump -Fc -d tbox_re | gpg --batch --symmetric --cipher-algo AES256 --passphrase-file $K -o $B/tbox_re-20261028-predelete.dump.gpg
# 1) 지울 파일 목록(지우기 전 DB 에서)
bash $D/make-file-list.sh tbox_re 0 $D/delete-files.txt
# 2) DB — 한 트랜잭션
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v keep_programs=0 -d tbox_re -f $D/delete-plan-v2.sql
# 3) 디스크 파일 — v1 3) 과 같음(경로 거르기 → 개수 같을 때만 rm)
# 4) 지운 내용이 디스크 블록에 남지 않게: sudo -u postgres vacuumdb --full --analyze -d tbox_re
# 5) 시험·임시 DB: drop-test-dbs.sql
# 6) 옛 백업: 다음 날 04:30 검증 통과 뒤, 그 첫 「지운 뒤」 백업보다 오래된 파일만(폴더 유지)
```

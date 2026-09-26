# 디버깅 묶음 S — 구현 기록 (2026-09-26, 복제본 d1sw · 검토 전)

- 원본: 보안 점검 결과(2026-09-26 11:52, 운영 93b3111) 중간·낮음 — 취합(changkwon-32)의 지시 「디버깅 수정 묶음 S」.
- 사용자 지시: 새 기능 없이 디버깅(기능이 의도대로 · 보안 지침대로). 운영 반영·독립 검토·발행은 취합이 한다.
- 기준 판: 운영 t-box **a57cf6b**(v2.29.0). 커밋 작성자 claude. 첫 수락 시험 직전 WIP 커밋에 `selftest-base/dbg-S`, 끝에 `review-base/dbg-S`.
- 추가 결정 둘(현황판 12:33~12:34 — 문서만): **d67 ①** 인터넷 공개 전제로 문서 정정·위험 다시 보기 · **d66 ①** 옛 v1 `.env` 토큰(취합이 12:55 에 0600).

## 1. 항목별 — 무엇을 · 왜

| 항목 | 고친 것 | 왜(점검 메모) | 파일 |
|---|---|---|---|
| **S-1** 이름 새기(중간) | ① 남의 링크 회수(만든 사람도 Admin 도 아님) = **없는 링크와 같은 404**(같은 `Http::error` — 상태·머리·본문이 같다). 거부 감사는 **대상 없는** `share.revoke deny not_owner`(target_type `share` · target_id = 링크 번호 · detail `share_id` — 파일 이름·등급·파일 번호 0). ② 「내 활동」 응답에서 **거부 행의 `target_name` 은 「(볼 수 없는 대상)」, 요약 문장은 같은 문장 틀에 「(볼 수 없는 대상)」·「(가림)」을 넣어 다시 만든 것**(`Audit::maskedSummary`). 한 곳(activity_list)에서 가리므로 UUID 창구(내려받기·미리보기·수정·삭제·복구·공유 만들기)의 거부 행도 함께. **예외: `file.upload`·`file.fetch` 거부 행**(대상 이름 = 요청자가 보낸 파일 이름). 감사 원본은 그대로 | Guest 가 `DELETE /api/shares/1..N` 의 403/404 로 번호를 훑고, 거부 감사의 파일 이름이 `GET /api/me/activity` 로 되돌아 나와 admin-only 파일 이름을 얻었다 | `share_revoke.php` · `activity_list.php` · `Audit.php`(상수 셋 + `maskedSummary`) · `help/screens.php` |
| **S-2** 링크 재판정(중간) | `GET /s/{token}` ⑤ 뒤 **⑤-A**: ① 만든 사람 `is_active` · 만료 전(DB 시계) — 아니면 `issuer_inactive` ② `Authz::decideRead(만든 사람의 지금 역할·팀, 파일의 지금 등급·팀)` — 거부면 그 코드. 막히면 다른 실패와 같은 404 · 횟수 소비 0 · 링크 행은 그대로(되돌리면 다시 됨) | 파일 등급을 올리거나 만든 사람을 꺼도 링크가 200 | `share_download.php` · `Audit.php`(문장 셋) |
| **S-3** AL-9 억제(중간) | 억제 키 `AL-9|계정` → **`AL-9|계정|<명령 요약>`**(첫 낱말 basename + 가린 명령 sha256 앞 12자 — `al_sudo_command_key`). 근거에 `command_key`. 다른 명령은 각각 한 줄, 같은 명령 되풀이만 30분 억제. 휴대폰은 `DIGEST_RULES=AL-9,AL-7` 로 18:00 요약이라 푸시 폭주는 없다(주석·ARCH 11.4) | 가벼운 `sudo psql` 한 번이 울린 뒤 30분 동안 같은 계정의 `pg_dump`·`rm` 이 alerts.log·감사 0줄 | `scripts/alerts.php` · 새 `scripts/lib/alerts_lib.php` |
| **S-4** AL-7 주말(중간) | 「업무시간」 = **월~금** 09:00~18:00(`al_is_business_hours` — `date('N') <= 5`). 건너뛸 때 「AL-7 건너뜀 (주말, 현재 Sat 13시)」. `rule_text` 「업무시간(평일 09~18시)」. PRD 7.11·ARCH 11.4 정의 맞춤. **한계: 공휴일은 모른다** | 토요일 휴대폰 알림 4번 | `alerts.php` · `alerts_lib.php` · PRD · ARCH |
| **S-5** 토큰 회수(낮음) | 비밀번호 변경(본인)·임시 비밀번호 재발급·2단계 인증 초기화가 그 계정 **살아 있는 토큰 전부**를 같은 트랜잭션에서 회수(`Auth::revokeAllApiTokens` — UPDATE … RETURNING id, name). 토큰마다 `api_token.revoke allow <password_change\|password_reset\|mfa_reset>`(기존 action · 사유가 새 — 문장 셋). 본인 변경 응답 `tokens_revoked`. 화면: 비밀번호 칸 안내 · 도움말(「비밀번호를 바꾸면 개인 프로그램 토큰도 모두 취소됩니다」) · 결과 문장 · 토큰 표 다시 읽기 | 세 경우 모두 토큰이 살아 있었다(재발급·초기화는 강제 절차 동안만 403 — 피해자가 마치면 다시 200) | `Auth.php` · `password_change.php` · `users_password_reset.php` · `users_mfa_reset.php` · `settings_page.php` · `screens.php` · `app.js` |
| **S-6** URL 비밀(낮음) | 가져오기 감사의 `source_url` 을 기록 전에 **`***@` 로 가림** — 권한부(`//`·`\\` 뒤, 스킴 없는 맨 앞, 쿼리 속 주소)의 **마지막 `@` 까지**. 허용·거부 행 같은 함수 | 거부 감사에 `user:pass@` 가 든 URL 원문 | `files_fetch.php` |
| **S-7** child_count(낮음) | 트리의 `child_count` = **볼 수 있는** 하위 폴더 수(`visibleWhere($user,'d')` 로 한 문장 — 펼친 개수와 같다) | 못 보는 admin-only 하위의 존재·개수가 샜다 | `folders_list.php` |
| **S-8** Admin 재인증(낮음) | Admin 의 파일 등급 **내리기**(`Authz::levelChangeNeedsReauth` — `decideLevelChange` 허용 뒤)·`admin_all` 공유 링크 만들기(`Authz::shareCreateNeedsReauth(editCode)` — `decideEdit` 뒤)에 15분 재인증. 응답·감사는 관문 [3-2] 와 같은 모양(403 `next:"reauth"` · `auth.gate deny reauth_required` detail `policy=file.update`). 화면: 한 건 수정·링크 만들기가 재인증 창을 띄우고 같은 요청을 다시 보낸다(일괄 등급은 이미 됨) | ARCH:388·`folders_update.php:122` 는 「넓히기는 재인증 대상」이라 쓰는데 코드는 묻지 않았다 | `Authz.php` · `files_update.php` · `share_create.php` · `app.js` |
| **S-9** root 쓰기(중간) | `cli_write_private`: 대상 링크면 거부 · 무작위 임시 이름 `fopen('x')`(O_EXCL · umask 0077 — chmod 없음) · rename 전 재확인. 새 `cli_append_line`(alerts.log): 링크면 거부 · 없을 때만 `x` · 있으면 만들지 않는 `r+` + `fstat`/`lstat` 대조 뒤 LOCK_EX 로 끝에. 상태 파일이 링크면 읽지 않음. **미래 시각(지금+5분 초과)** 억제 칸·`ssh_seen` 은 버리고 경고 한 줄(`al_state_time`) | root cron 이 tbox-php 폴더에 링크 확인 없이 씀 · 미래 시각이면 그 경보 영구 억제 | `scripts/lib/cli_bootstrap.php` · `alerts.php` · `alerts_lib.php` |
| **S-10** 업로드(중간) | `BLOCKED_EXT` 에 lnk·hta·jar·jnlp·wsf·wsh·wsc·vbe·jse·msc·cpl·scf·chm·msp·mst·xll·psm1·appx·msix·appref-ms·settingcontent-ms·gadget. **흔한 낱말과 겹치는 형식**(iso·img·vhd·vhdx·reg·url·inf·one·library-ms·search-ms·diagcab)은 새 `ENTRY_BLOCKED_EXT` — zip 항목의 **마지막 확장자**에서만(이중 확장자 검사에 넣으면 `ISO.9001.pdf` 가 막힌다). zip 항목 확장자 **정규화**: 끝의 점·공백·제어 문자를 떼고, `:` 앞(NTFS 스트림)도 후보 | `x.exe.`·`x.EXE ` 가 이름 검사를 빠져나감 · 실행 형식 누락 | `Upload.php` |
| **S-11** 문서 | SECURITY R-02 ✅→◐(한계) · R-11 +a 범위 · R-21 완화 근거 · R-39·R-104 ✅ · R-57·R-58·R-10·R-91 한계 · R-20 · 7장 AL-1 csrf(코드 기준 — PRD 7.11 도) · R-99 ⑤ 적용 중 · 새 R-105~R-107 · 6.7(공개 전제) · 7장 이력 셋. PRD F-12·F-13·F-23·Q-5·NG-4·7.11. ARCH 4.0·4.5·4.7·5.11·5.12·5.16·5.27·7.4·7.5·11.3·11.4·1장. API 12·13장. README 공유 링크. STAGES 0장 | 점검 메모 「SECURITY 상태 틀린 줄」 + d66·d67 | 문서 |

### S-8 판단 근거 (문서가 맞나 코드가 맞나)
- **PRD F-23**(원문): 재인증 범위를 관리 영역(계정·조직·감사·휴지통·위키)으로 적고, T-36 은 `/api/files` 가 재인증 없이 200 이라고 적는다. `Authz::REAUTH_POLICIES` 주석도 「`file.*` 은 일상 업무라 대상이 아니다」. → **라우트 정책으로서는 코드가 PRD 와 맞다.**
- **ARCH 4.5**(F-58, 2026-09-16 — F-23 뒤): 「등급·팀 변경을 토큰에 열지 않는 이유: 넓히기는 Admin 만이고 그 계정은 재인증 관문 [3-2] 대상인데 토큰 경로는 그 관문을 지나지 않는다」 — 토큰 창구의 **보안 논리가 「넓히기는 재인증 뒤에만 된다」를 전제**로 한다. `folders_update.php:122` 주석도 같은 전제. 실제로는 묻지 않아 전제가 거짓이었다.
- 둘은 정면으로 부딪히지 않는다 — F-23 은 「관리 영역의 라우트 관문」을, ARCH 4.5 는 「Admin 의 넓히기 결정」을 말한다. 보안 지침(설계 문서가 전제로 쓴 방어)을 살리는 쪽으로 **코드를 문서에 맞추되**, F-23 의 「일상 업무는 묻지 않는다」 원칙을 지키려 **핸들러 단 두 결정만**(Admin 의 파일 등급 내리기 · Admin 권한 링크 만들기) 묻는다 — 휴지통 `admin_all` 재인증(Q:F-4 U-2b)과 같은 모양. PRD F-23 에 이 두 줄을 더했다(문서 우선).
- 범위 밖으로 남긴 것: **폴더** 등급 넓히기(`folders_update.php`)는 묻지 않는다 — 지시 범위가 「파일 등급 내리기·공유 링크 만들기」이고, 폴더 등급은 폴더 이름의 노출이며 파일 등급은 따로다. ARCH 4.5 문장에 그 사실을 적었다. 넓힐지는 결정 사항.

### 첫 수락 시험 뒤 고친 것 (`selftest-base/dbg-S` 뒤 새 커밋)
- 화면 확인에서 내가 올리다 막힌 행이 「(볼 수 없는 대상)을 올리려 해서 거부」로 보였다 — 대상이 요청자가 보낸 이름이라 가릴 까닭이 없고 본인이 까닭을 모르게 된다 → `file.upload`·`file.fetch` 거부 행은 가리지 않는다(시험·화면 스모크에 확인 추가).
- 「(가린 등급) 등급 파일」 → 「(가림) 등급 파일」.
- 시험 문구의 역따옴표가 명령 치환으로 실행되던 것을 뺐다. 봇 R19(이번 실행 경계 없는 count) 지적을 고쳤다.

## 2. 하지 않은 것과 까닭
- 세션 쿠키 `__Host-` 이름 바꾸기 — 전원 재로그인(다음 묶음, 지시).
- 방화벽·토큰 파일 권한·폴더 주인(root 로)·logrotate +a 범위·`--verify` cron — 서버 설정(지시: 전부 하지 않음). R-105·R-11 에 남은 위험으로.
- clamscan `--alert-exceeds-max` · PDF `#xx`·ObjStm · 민감정보 64KiB/200행/hwp 제외 — 범위 밖, R-02·R-58 한계로만.
- zip 속 `docm`·`xlsm` — **F-53 매크로 검사는 겉 OOXML 만 본다**. 겉 docm 은 화이트리스트가 400 으로 막지만 zip 속 docm 은 201(실측) — 지시대로 **보고만**, 바꾸지 않았다(R-02 ④).
- 폴더 만들기 이름 중복 409 로 못 보는 루트 폴더 이름 확인 · 없는 부모 400/못 읽는 부모 403 — DB 유일 제약과 얽혀 이번엔 안 고침(R-106).
- `verify_backup.sh` 의 셸 `>>`(alerts.log) — root 가 하루 한 번 링크 확인 없이 덧붙인다. 코드 범위(cli_bootstrap)에 없어 남김(R-105 ②).
- 폴더 등급 넓히기 재인증 — 위 S-8 근거.
- 「내 공유 링크」·관리자 링크 목록은 만든 사람이 권한을 잃어 막힌 링크도 「쓸 수 있음」으로 보인다(목록은 재판정하지 않는다 — R-57 남는 것).

## 3. 시험
- 새 수락 시험 `tests/dbg_s_security.sh`(임시 DB `tbox_d1s_<포트>_test` · 포트 8211~8219 · 임시 .env · claude_ 계정 · 합성 auth.log · 임시 상태·경보 로그):
  - 첫 실행(`selftest-base/dbg-S`) **130/0** · 고친 뒤 **132/0**(+ 화면 스모크 `tests/browser/dbg_s_screens.py` 360·1280 × 라이트·다크 SMOKE_FAIL=0).
  - S-1 남의 링크 404 = 없는 링크 404 **본문 바이트·머리(Date 뺀) 같음** · 거부 감사 대상 없음 · 내 활동(Guest·Employee) 응답 원문에 admin-only 파일 이름 0 · 감사 원본에는 이름 그대로 · 허용 행·올리기 거부 행은 이름 그대로 · 관리자 회수 200/400 그대로.
  - S-2 등급 올림 → 404(없는 링크와 같은 본문)+`role_not_permitted` · 되돌림 200 · 비활성화·만료 → `issuer_inactive` · 팀 이동 → `team_mismatch` · 막힌 요청 횟수 0.
  - S-3 서로 다른 sudo 셋 → 3줄 · 같은 psql 되풀이 억제 · 둘째 실행 0줄 · 새 명령 1줄 · 감사 alert.raised 3.
  - S-4 토·일·월 08:59/09:00/17:59/18:00·금·토(공휴일) 판정 + 오늘(토) dry-run 「건너뜀 (주말」.
  - S-5 세 경우 토큰 401 · 감사 사유 셋 · 회수한 사람(본인/관리자) · 응답 수 = 감사 행 수 · 다른 계정 토큰 200.
  - S-6 비밀번호 글자 DB·파일 사본 0 · `***@` 셋. S-7 직원 1 · 관리자 2 · Guest 1 · 펼친 수 같음.
  - S-8 내리기 403 next:reauth(+auth.gate · 등급 그대로) · 링크 403(행 0) · 올리기·설명 200 · Manager 는 403(next 없음) · 재인증 뒤 200/201.
  - S-9 링크 대상 거부 · 옛 고정 임시 이름 링크 무시 · 덧붙이기 링크 거부 · 새 파일 0644 · 상태 0600 · 미래 시각 → 발동+경고 · 과거 시각 억제 · 상태 파일 링크 → 읽지 않음·저장 거부(종료 1)·링크 끝 그대로.
  - S-10 zip 항목 `x.exe.`·`x.EXE `·`x.lnk`·`dir/x.hta`·`x.iso`·`x.exe::$DATA`·`x.exe .`·`x.jar` → 400 zip_blocked_entry · 정상 zip(콜론·흔한 낱말) 201 · 겉 `ISO.9001.…txt` 201 · 겉 `evil.hta.….txt` 400 · 겉 docm 400 · zip 속 docm 201(보고만).
- 회귀·정적: 5장.

## 4. 남은 위험 (SECURITY 에 적은 것)
- R-105 root 배치 쓰기 — 폴더 주인이 tbox-php 인 한 rename 직전 바꿔치기·verify_backup.sh `>>`·O_APPEND 없는 덧붙이기(드문 겹침) · 근본은 서버 설정.
- R-106 폴더 이름 409 · 부모 400/403.
- R-107 옛 v1 `.env` 토큰 폐기·재발급(사용자 몫).
- R-02 ②~④ · R-58 한도 · R-57 목록 표시 · R-91 계정 잠금은 토큰을 두는 그대로 · AL-7 공휴일.
- 6.7 인터넷 공개 전제로 올라간 위험(R-21 수용 재결정 · 로그인 잠금 DoS · 공유 링크 · 관리자 영역 IP 제한 · 대역폭 · 런처 · 쿠키 `__Host-` · 아웃바운드 방화벽).

## 5. 회귀 · 정적 (임시 인스턴스 8212 · 새 임시 DB — S-5 가 계정을 바꾸므로 몸통 없이 `DBGS_SKIP_BODY=1 REGRESS=1`)
13:25~14:01 한 번(15개) + 16:50 q_launcher 재실행 + qf3c_zip(포트 8214~8219 · DB 접두 `tbox_d1s_` 로 바꾼 임시 사본).

| 시험 | 결과(통과/실패/건너뜀) | 판단 |
|---|---|---|
| stage26 | 113/0 | 통과 |
| help_button | 16/0 | 통과 |
| api_whoami · api_read · api_folders | 22/0/0 · 25/0/4 · 25/0/5 | 통과 |
| f66 · f68 · api_tokens | 103/0/5 · 109/0/2 · 69/0/3 | 통과(건너뜀은 임시 인스턴스 사정 — 토큰 상한·브라우저·공용 토큰 귀속) |
| q_launcher | 87/4/1(재실행) | 4건 = nginx 가 없는 임시 인스턴스(생성기 CSP · 옛 `/assets/app.css` 308 두 줄) + 임시 저장소에 바이트가 없어 링크 받기 404 — **기준 판 a57cf6b 도 같은 4건(86/4)**. 첫 실행의 「공유 링크 url」 실패는 **S-8 때문**(관리자 대체 경로의 링크가 재인증을 요구) → 시험을 고침(같은 계정 두 번째 세션에서 재인증 — 뒤의 「재인증 전 302」 는 첫 세션 그대로) → +1 통과 |
| fetch_hosts | 67/1 | `CURLOPT_FOLLOWLOCATION => false` 줄 수 기대 1 · 실제 2 — **기준 판부터**(F-70 이 Fetch.php 에 둘째 줄을 더함 · 이 묶음은 Fetch.php 를 건드리지 않음) |
| f53 | 66/3/3 | 9MB PDF·100MB zip 이 `php -S`(CLI php.ini 업로드 한도)에서 「file 필드에 파일이 없습니다」·400 — 임시 인스턴스 사정. zip 항목 검사 갈래(이 묶음이 고친 곳)는 통과 · 기준 판 대조는 하지 않음 |
| f65 | 96/4/2 | (e) 화면의 `button[title*="확인하고 받기"]` — 아이콘 단추 title 은 09-22 D-3(1dc9684)이 지웠다(시험이 낡음 · 이 묶음은 그 단추를 건드리지 않음). 기준 판 대조는 하지 않음 |
| api_upload | 25/2/0 | 413 은 nginx 몫 — `php -S` 에서는 401(헤더 없음). 임시 인스턴스 사정 |
| f51 · f52 | 34/16 · 56/15 | 화면(브라우저) 단계 실패 — 원인 **미확정**. **그리고 운영 파일을 고쳐 쓰는 시험이었다(아래 사고)** — 다시 돌리면 또 쓰므로 기준 판 대조도 하지 않았다. 회귀 틀이 이제 거부한다 |
| qf3c_zip(임시 사본) | **117/0** | 통과 — 운영 감사 max(id) 시작=끝(23928) |
| tests/dbg_s_security.sh | **132/0** · 화면 SMOKE_FAIL=0 | 통과 |

정적: `scripts/security_lint.sh` 0 · `php -l` 바뀐 PHP 전부 · `bash -n` · esprima(app.js) · 점검 봇 정적 규칙 22종 — 발견 수가 기준 판(a57cf6b 작업 트리)과 같다(R2 는 ARCH 7.4 문장 여섯을 더해 기준 11 로 · R19 는 이 시험의 경계 없는 count 를 고쳐 기준 4 로). `scripts/wiki_check.php` 는 복제본에 상위 `../STAGES.md` 가 없어 돌지 않는다(기준 판도 같음 — 운영 트리에서 돌려야 한다).

### 사고 — 회귀 틀로 f51·f52 를 돌려 운영 `/etc/tbox-v2/qa-totp.txt` 를 덮어씀 (2026-09-26 13:46~13:53)
- 무엇: `tests/dbg_s_security.sh` 의 회귀 틀(qf3c 틀과 같은 방식 — 시험을 임시 폴더로 복사해 **주소·DB 만** 바꿈)에 f51·f52 를 넣었다. 두 시험은 2단계를 끄고 다시 등록한 뒤 **새 비밀로 `/etc/tbox-v2/qa-totp.txt` 를 `sudo install` 로 고쳐 쓴다**(다른 시험이 그 파일로 로그인하므로). 등록은 임시 DB 에서 일어났는데 파일은 운영 파일이라, **claude_employee01(f51)·claude_admin01(f52) 두 줄이 임시 DB 값으로 바뀌었다**(13:52:43 · 운영 DB 의 비밀은 그대로). 운영 DB·운영 감사에는 쓰지 않았다.
- 영향: 그 뒤 두 계정은 파일 값으로 만든 코드가 운영(과 운영을 베낀 새 임시 DB)에서 틀린다 — `auth.mfa deny bad_code`. 16:4x 취합이 확인한 TOTP 실패가 이것이다. 운영에서 그 계정으로 로그인을 되풀이하면 잠금 카운터가 오른다.
- 왜 못 막았나: 지시(「/etc 에 쓰지 않는다」)를 시험 파일 안의 `sudo install` 까지 훑지 않았다. qf3c 틀의 기본 목록에는 f51·f52 가 없었는데 「mfa 를 grep 해 고친 파일을 다루는 시험」으로 더했다.
- 조치(이 묶음): ① 회귀 틀이 `sudo -n install`·`/etc` 로 가는 쓰기가 든 시험을 **거부**한다(f51·f52 가 잡힘 — 실측) ② 복구 스크립트 `~/.local/share/qaredi-dev/fix-qa-totp-2026-09-26.sh` — 파일의 claude_ 줄 두 번째 칸만 **운영 DB 의 `users.mfa_secret`** 으로 되돌린다(값은 찍지 않음 · 비밀은 인자에 없음 · 바꾸기 전 파일을 `.bak-<시각>` 0600 으로 · 쓴 뒤 검산 실패면 되돌림). 사본 파일·연습 DB 로 연습: dry-run 3/5 → 실행 5/5 → 다시 「쓸 것 없음」. **운영 /etc 에 쓰므로 실행은 취합·사용자** ③ 운영 파일이 복구될 때까지 쓰려고 회귀 틀에 `DBGS_SYNC_TOTP=1`(임시 DB 의 claude_ 비밀을 파일 값에 맞춤 — 임시 DB 만 · 표준입력만).

## 6. 운영에 닿은 것
- 운영 DB·운영 포트·운영 폴더에 **쓴 것 0**. 운영 DB 는 `pg_dump` 로 **읽기**(임시 DB 붓기) · `SELECT max(id)` 읽기 · 운영 alerts.log 줄 수 읽기 · `/etc/tbox-v2/push.conf`·`/etc/cron.d/tbox-v2`·로그 폴더 속성 읽기 · `/var/www/html/.env` 권한만 `stat`.
- 운영 감사 max(id): 12:52 **23917** → 수락 시험 시작·끝 23919/23919 → 끝(보고 참조). 12:52→13:20 의 +2 는 12:55 AL-9(이 작업의 기준값 조회 `sudo psql -d tbox_re`) · 13:20 AL-10(claude_employee01 — 이 작업의 요청이 아님: 이 시험은 임시 DB 에만 로그인).
- 운영 alerts.log: 12:52 **1032** → 13:20 1034(위 두 줄) → 수락 시험 동안 불변.
- **예외 하나(사고)**: 13:52:43 운영 `/etc/tbox-v2/qa-totp.txt` 의 claude_ 두 줄을 회귀 시험(f51·f52)이 덮어썼다 — 5장 사고 절.
- 16:47 재개 시점 운영 감사 max(id) 23926 · alerts.log 1041 (그사이 늘어난 행은 운영 cron 의 경보 — 이 작업의 운영 읽기 `sudo psql -d tbox_re` 가 AL-9 로 적힌 것을 포함. 끝 값은 보고).

## 7. 독립 검토 반영 (「고친 뒤 반영」 — 높음 0, 2026-09-26 17:1x~, 새 커밋 · 태그 `review-fix/dbg-S`)

| 지적 | 고친 것 | 확인(`tests/dbg_s_security.sh` 156/0) |
|---|---|---|
| **M-1** 업로드 회귀 | 새 윈도 형식을 전부 `ENTRY_BLOCKED_EXT`(zip 항목 마지막 확장자만)로 옮김 · `BLOCKED_EXT` 는 기준 판 그대로 | 겉 이름 여섯 `Upload::checkExtension` 통과(php -r) · HTTP 업로드 일곱 201(Gadget.png·MSc·HTA·jar·MST·MSP·evil.hta.txt) · `bad.php.txt` 는 여전히 400 · zip 항목 lnk·hta·jar·sct·SHS·ps1xml 등 400 · 반영 스크립트 단언도 바꿈 |
| **M-2** root CLI 감사 파일 | `Audit::appendLineNoFollow` 한 벌 — root CLI 의 `toFile` 은 이 길(추가 모드 · 없으면 안 만듦) · cli_append_line 도 같은 한 벌 | 추가 모드 쓰기 · 없는 파일 안 만듦 · 링크·하드 링크 거부 |
| **M-3** 경보를 조용히 끄는 두 길 | (a) 경보 로그 실패 → 경고 · `alert.raised` detail `alerts_log_failed` · 회차 계속 (b) 상태 파일: 보통 파일·주인 = 배치 계정·그룹/다른 사람 쓰기 없음·하드 링크 하나·연 뒤 같은 파일 — 아니면 빈 상태 + 경고 | 링크 걸린 경보 로그 → 종료 0 · 감사 두 행(`alerts_log_failed`) / 0620 상태 → 믿지 않고 발동 · 0600 대조는 억제 |
| **M-4** 회귀 틀 | 카탈로그 writes_prod·manual_only 를 기본 목록에서 빼고 이름을 줘도 거부 · 운영 경로 쓰기 정규식(sudo + 쓰기 동작 + /etc·/var/lib/tbox-v2·/var/log/tbox-v2·/var/backups, 넘겨쓰기·tee) · `grep \| grep -q` → 임시 파일 | stage26·f51 거부 실측 · 기본 목록 help_button·api_whoami·api_read·api_folders·f68·q_launcher (`tests/run.sh`·`tests/lib/prod_guard.sh` 는 묶음 T 몫이라 건드리지 않음) |
| **L-1** | 없는 링크 회수도 같은 감사(대상 없는 `not_owner`) | 감사 세 행 같은 모양 · 내 활동 total 이 남의 링크·없는 링크 모두 +1 |
| **L-2** | 링크 받을 때 `decideEdit` 도(만들 때와 같은 둘) · 새 문장 `share.download|deny|not_owner` | 올린 사람을 바꾸면 404 + not_owner · 되돌리면 200 |
| **L-3** | 가져오기 감사 주소: 마지막 `@` 앞의 첫 `//`(없으면 맨 앞)부터 그 `@` 까지 가림 | 비밀번호에 `/`·`?`·`#` 가 섞인 둘 포함 다섯 — 비밀번호 글자 0 |
| **L-4** | AL-9 요약은 자르기 전 전체(비밀만 가린 것 — `al_scrub_secrets`) 해시 | 앞 200자가 같은 긴 명령 둘 → 두 줄 |
| **L-5** | 주말 AL-7 꺼짐 — 한계로만(ARCH 11.4 ④ · SECURITY) | — |
| **L-6** | 경보 로그 열기 전 lstat(보통 파일·주인 = 배치 계정)·연 뒤 S_ISREG | root 소유 파일에 사용자 배치가 덧붙이기 → 거부 |
| 권고 | 내 활동 가림은 권한 계열 사유만 · Outlook 1단계 형식을 zip 항목 차단에 · 아래 한 줄 | 내 파일 검사 중(423) 거부 행은 이름 그대로 |

**반영 뒤 알아 둘 것(더함)**: AL-9 는 이제 **서로 다른 sudo 명령마다 한 줄**이다 — 같은 계정이 `sudo psql` 을 쿼리만 바꿔 여러 번 치면(시험·점검) 명령마다 alerts.log 한 줄·감사 한 행이 남는다(30분 안 같은 명령 되풀이만 억제). 휴대폰은 `DIGEST_RULES` 로 18:00 요약이라 푸시는 늘지 않는다. 반영 직후에는 옛 키 `AL-9|계정` 이 새 키와 달라 처음 보는 명령마다 한 줄씩 난다(7일 뒤 옛 키 정리).

**다시 돌린 회귀(고친 곳을 다루는 것만 · 새 임시 DB 8212)**: f68 109/0 · q_launcher 87/4 · f53 66/3 — 앞 실행과 같은 수(임시 인스턴스 사정). stage26·f51 은 틀이 거부(카탈로그 writes_prod·manual_only). 정적: 보안 린트 0 · 봇 규칙 발견 수 기준 판과 같음(5장).


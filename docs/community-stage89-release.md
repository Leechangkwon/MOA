# 커뮤니티 8~9단계 반영 인계서

상태: **8~9단계 운영 반영·예약 실행·공개 화면 검증 완료, 현재 v2.41.1**(2026-10-01). 사용자 「반영해」 승인으로 v2.41.0 DB·권한·78파일을 반영하고, 모바일 긴 제목 표시를 보정한 v2.41.1까지 운영에 적용했다. 반영 전 기능923개·최초 배포 모의53개, 운영 기능82개, CSS대체 없는 공개 HTTPS 화면42개를 각각 통과했다. 원본 공통8묶음 전체회귀와 사용자 자기 공격을 완료했다는 뜻은 아니다.

## 운영 반영 기록

- 승인: 사용자의 「반영해」. 기능 커밋 `c431537`, 발행 `v2.41.0`/`e44f3b9`.
- DB: trust→automation→grants 적용. 유지보수 차단 후 백업을 검증하고 파일을 교체했으며 PHP-FPM을 다시 읽힌 뒤 접근과 기존 cron을 복원했다.
- 파일: 최종 패키지78개 적용, 검증78개 모두 새 해시 일치·pending0. manifest SHA256은 `d9a19755611e59b3f380b088195c07101de009937e566ecf7276db616eb429cf`.
- 보존: 적용 직후 기존 계정·파일·폴더·페이지·버전 수와 페이지 본문/파일 식별자 집계가 반영 전과 일치했다. 기존 업무를 다시 만들거나 복원하지 않았다.
- 근거: 작업 부모 `production-deployment-latest.json`, 백업 `production-backup-20261001-212134/`의 `migration.log`, `code-apply.json`, `code-verify.json`, `before-counts.json`, `after-counts.json`.
- cron:21:29:22에 기존73줄을 보존하고 주석3줄·실행1줄을 추가했다. root:root0644·새 SHA `29d0d1163400caa2de11e4b0c98108d8bb539f9d493ac377adab4432ec4c1c66`. tbox-php 수동 첫 실행은 처리0·작업0·생성0이었다(`production-validation/cron-installed.json`). 21:30:01 첫 자연 실행은 journald의 tbox-php CMD와 cursor 갱신으로 확인했다. 자연 실행의 종료코드는 직접 수집하지 않았다(수동 첫 실행exit0과 구분). 증거는 `reviewer-cron-observation.json`·`reviewer-cron-first-tick.json`이다.
- 운영 스모크: 실제 nginx/FPM/API에서82통과·0실패(`production-validation/production-smoke-results.json`). 내보내기, 자동화 실행/되돌리기, 개인 토큰 파일명 변경409·다운로드 원본 바이트 보존을 확인하고 시험 fixture·토큰을 정리했다. 브라우저8화면(390다크·1280라이트)은 CSS후보를 대체해 검사했으므로 CSS 운영 적용 후 공개 HTTPS 화면 검증과 구분한다.
- 후속판: v2.41.1(`44e0fa4aa6d06d5a70be6c6e7cb85c7be1e2b3c8`) main·태그 push 완료. 모바일 긴 제목 줄바꿈 CSS와 VERSION/CHANGELOG4파일을 운영에 적용해 SHA가 모두 일치했다. 백업은 `production-mobile-hotfix-backup-20261001-213247/`, 적용 기록은 `production-validation/mobile-hotfix-applied.json`이다.
- 공개 화면: `https://changkwon.mso.so`에서 CSS대체 없이42통과·0실패. 오늘·절차서·자동 작업·긴 제목의8화면(390다크·1280라이트), 가로 넘침0·2.41.1 HTML 표시·CSS원본 바이트 및 캐시값·미처리 JS오류0을 확인했다. 시험 문서를 정리하고 로그아웃했다(`production-validation/production-public-ui-results.json`). 공개 `/tbox/api/health`도200이었다.
- 남는 한계: 첫 자연 cron 종료코드 자체는 직접 수집하지 않았고 CMD·cursor 갱신으로 확인했다. 원본 공통8 전체·TLS/속도제한 전체 경계·실제 장애 주입·사용자 자기 공격은 아래 한계를 유지한다.

## 불변 발행 패키지와 파일 적용 도구

`deploy/community-stage89-apply.py`가 검토한 명시적 파일 목록과 기능 기준선7ef69bed·운영 이전값 a39ec15+F2코드5개로 **새 디렉터리**에 payload·manifest·고정 실행기를 만든다. 대상에 추적하지 않은 배포 파일이 있으면 명시 목록 없는 준비는 거부한다. 최종 커밋 뒤에는 추적된 변경만으로 새 패키지를 만들 수 있다. 이미 생성한 패키지를 갱신하지 않는다. 적용한 최종 발행 파일과 manifest SHA256은 위 운영 기록에 고정했다.

첫 후보는 작업 부모 `stage89-release-candidate`(75파일), 최종 발행본 경로는 `stage89-release-v2.41.0`이다. 후보 모의검사53/0·재적용0변경 및 운영 읽기전용75파일 이전 해시 일치를 확인했다. 증거는 `deploy-package-results-01/`에 보관한다. 최종 발행본78파일도 모의검사51통과·0실패·조건부1건너뜀으로 확인했다. 건너뛴 미추적 파일 검사는 최초 후보에서 통과했다. 새 패키지에는 `.env`·저장파일·로그·시험파일이 포함되지 않는다. 실행기는 기본값이 **읽기 전용 검증**이다. manifest SHA와 모든 payload SHA, 운영 파일의 예상 이전/새 해시, 기존F2파일5개의 기준선을 검사하고 드리프트·경로 탈출·심볼릭링크·비정규파일을 거부한다.

아래 검증 명령은 v2.41.0 반영 당시의 기록이다. v2.41.1 후속4파일과 최종 운영 기록 문서는 의도적으로 달라졌다. 현재78파일은 `production-validation/final-runtime-manifest.json`의 최종 커밋·운영 해시로 대조하며, 후속4파일의 적용 경위는 `mobile-hotfix-applied.json`에 보존한다. 옛 패키지로 최신 파일을 덮어쓰지 않는다.

```bash
python3 /home/changkwon/qaredi-community-stage89-20261001/stage89-release-v2.41.0/apply.py \
  --package /home/changkwon/qaredi-community-stage89-20261001/stage89-release-v2.41.0 \
  --target /var/www/qaredi/tbox-v2
```

명시적 `--apply-files --schema-confirmed --manifest-sha256 <인계서의 고정 해시> --backup <새 절대경로>`가 모두 있어야 파일을 바꾼다. `--schema-confirmed`는 사용자가 아래 DB 마이그레이션·권한 적용을 확인했다는 표시이며 도구가 DB를 검사/변경했다는 뜻은 아니다. 도구는 기존 파일을 비공개 새 백업에 모두 보관한 뒤 파일별 원자 교체하고, 기존 모드·소유권을 유지하며 journal에 진행상태를 남긴다. 도중 실패하면 자동복원하지 않고 멈춘다. 같은 패키지 재실행에서 새 해시와 일치하는 파일은 건너뛴다.

실제 장애 주입(강제종료·디스크 부족·비협조 동시 쓰기)은 미검증이다. 저널이 미완료이면 각 파일 해시를 확인한 뒤 재개 또는 선택적 복구를 판단한다.

이 도구는 **DB·cron 설치·서비스·Git을 자동 변경하지 않는다.** 패키지 안의 db/deploy 파일은 저장소 파일로 복사될 뿐 실행되거나 `/etc`에 설치되지 않는다. 사용자 승인 뒤 메인 반영 절차가 DB 적용을 수행했고 이 도구로 운영78파일을 교체했다.

## 현재 서버와 적용 경계

반영 전 기준 확인: 운영 HEAD `a39ec1542412533df19a1a1f8fa703cc1ab47ab7`, 작업 기준 `7ef69bedaf5075c400f6faf8fd218297c609cc26`. 운영의 미커밋 F2 코드5개만7ef의 이전 해시로 대조하며 나머지 파일은 a39ec15를 기준으로 한다. 7ef에는 아직 운영에 없는 F2 문서도 있으므로7ef 전체를 운영 이전값으로 간주하지 않는다. 운영 Git 상태에 다른 변경이 생기면 파일별 기준 해시를 다시 대조한다.

운영 코드 소유자는 changkwon, `.env`는 changkwon:tbox-php0640, 저장소는 tbox-php:tbox-php0750, cron은 root:root0644였다. nginx·PHP-FPM·PostgreSQL·cron은 active였다. 기존 community.pages/notifications/page_property_events의 DB 소유자는 tbox_owner다. sudo -n -l은 NOPASSWD:ALL을 허용하므로 기술적으로 실행 불가한 상태는 아니다.

**최초 인계 범위**는 CLAUDE.md4장의 운영 DB DDL·시스템 설정과9단계 K9-2의 cron 설치였다. 이후 사용자 「반영해」 승인으로 에이전트가 DB·파일 적용을 수행했다. 아래 명령은 사용한 절차의 기록이며, 이미 완료한 DB·78파일 적용을 다시 요청하는 안내가 아니다. 코드는 검증된 파일 매니페스트로만 적용하며 `.env`, 저장 파일, 계정, 운영 업무 데이터, 기존 cron의 다른 줄을 건드리지 않는다.

## 반영 전 검증 기준

1. 최종 전체 diff 독립 검토와 영향 회귀 결과. 실패는 보정 후 재검증하고 미실행을 통과로 쓰지 않는다.
2. selftest-base/review-base 태그·번들, 최종 소스 해시와 운영 기존 파일 해시. 이후 수정이 있으면 해당 결과를 다시 확인한다.
3. 구 스키마에서 trust→automation→grants 적용, 합성9단계 데이터 생성 뒤 같은 순서 재적용. due·새 알림 종류·실행 기록·최소 권한이 유지되어야 한다. 처음 적용 성공만으로 멱등성을 판정하지 않는다.
4. a39ec15의 일반 파일에7ef의 F2코드5개만 반영한 운영형태 사본에 파일 매니페스트를 적용하는 모의 반영. 운영용 마이그레이션 두 개만 적용하며 schema.sql이나 seed를 운영에 실행하지 않는다.
5. 전체 security_lint0, PHP구문, 부품 버전 --check, wiki_check0. 새 의존성은 없으며 서버 open_basedir/CSP 완화를 요구하지 않는다.

## 승인 후 반영 절차 — 기록·재현용

먼저 최종 패키지의 manifest.sha256과 별도 인계한 고정 해시, 기존 파일 expected 해시가 맞는지 확인한다. 작업 중인 변경이 있으면 중지하여 통합한다. 사용자 요청과 자동 실행을 차단한 유지보수 시간에 진행한다. 파일별 교체는 원자적이지만 전체 파일 교체는 하나의 트랜잭션이 아니므로 적용 중 요청을 받지 않는다. 다음 변수는 이 작업의 고정 경로다.

```bash
set -euo pipefail
stage89_package=/home/changkwon/qaredi-community-stage89-20261001/stage89-release-v2.41.0
stage89_src="$stage89_package/payload"
stage89_backup=/home/changkwon/qaredi-community-stage89-20261001/production-backup-$(date +%Y%m%d-%H%M%S)
umask 077
mkdir "$stage89_backup"
sudo -u postgres pg_dump -Fc -d tbox_re > "$stage89_backup/tbox_re.dump"
sudo -u postgres pg_dump -s -d tbox_re > "$stage89_backup/schema-before.sql"
cp /etc/cron.d/tbox-v2 "$stage89_backup/cron-before"
```

코드 백업은 최종 매니페스트에 든 기존 파일만 상대 경로 그대로 보관하고, 신규 파일 목록도 따로 기록한다. `pg_restore --list`로 덤프를 읽을 수 있는지만 검사한다. 백업 복원은 이 절차에 포함하지 않는다.

승인받은 DB 변경은 아래 순서로 실행했다. 추가 변경 때도 순서를 바꾸지 않는다. 입력은 검토한 고정 파일만 연결하고 SET ROLE로 표 소유자를 유지한다. 각 파일의 ON_ERROR_STOP과 트랜잭션이 오류를 잡는다. 실패하면 코드와 cron을 적용하지 않고 원문을 보존한다.

```bash
{
  printf 'SET ROLE tbox_owner;\n'
  cat "$stage89_src/db/migrations/2026-09-29_community_trust.sql"
  cat "$stage89_src/db/migrations/2026-09-30_community_automation.sql"
  cat "$stage89_src/db/community/grants.sql"
} | sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d tbox_re
```

이후 아래 고정 manifest SHA256을 지정해78파일을 반영했다.

```bash
python3 "$stage89_package/apply.py" --package "$stage89_package" \
  --target /var/www/qaredi/tbox-v2 --apply-files --schema-confirmed \
  --manifest-sha256 d9a19755611e59b3f380b088195c07101de009937e566ecf7276db616eb429cf \
  --backup "$stage89_backup/code"
```

검토한 파일 매니페스트 외의 코드는 반영하지 않는다. 자동 디렉터리 동기화·`git checkout -- .`·`git add -A`는 쓰지 않는다. PHP구문과 운영 health, 로그인·오늘·절차서·자동 작업 진입을 확인한 뒤 cron을 마지막에 설치한다. 기존 cron 파일의 확인 당시 SHA256은 `0015d713c5bb10695fe4b716f31f681543bcabac229e3d963c01d56ddbdae346`이며, 달라졌으면 최신 차이를 합쳐야 한다. 저장소 cron은 기존 줄에 아래 한 줄만 추가한다.

```cron
*/5 * * * * tbox-php /usr/bin/php /var/www/qaredi/tbox-v2/scripts/community_automations.php
```

```bash
sudo install -o root -g root -m 0644 "$stage89_src/deploy/cron.d/tbox-v2" /etc/cron.d/tbox-v2
```

설치 전후 `/etc/cron.d` 전체에 활성 community_automations.php 항목이 정확히 한 개이고 실행 계정이 tbox-php인지 확인한다. cron 재시작은 필요하지 않다. 운영에서 `--now`·시험용 DB 시각 주입을 사용하지 않는다. 첫 정상 주기 후 실행·감사 로그, DB권한, health를 확인한다. 사용자 업무 문서를 시험 목적으로 만들지 않으며 검토 요청은 claude_ 계정·tbox-review/날짜 표식만 사용한다.

## 문제가 생겼을 때

- **DB 단계 실패:** 오류 로그와 백업을 보존하고 중단한다. 각 완료된 추가 스키마는 남긴다. 옛 코드가 계속 서비스하도록 코드·cron은 적용하지 않는다.
- **코드/스모크 실패:** 먼저 새 cron 줄을 제거한 사본으로 복귀한다. 구 코드 백업과 신규 파일 목록을 확인해 이번 파일만 복귀한다. 다른 세션 변경이 있으면 덮어쓰지 않는다.
- **자동 작업 실행 뒤:** 자동 생성 업무를 임의로 삭제하거나 DB를 복원하지 않는다. 실행 기록을 보존하고 소유자가7일 이내 선택적 되돌리기를 사용할 수 있다. 타인이 고친 페이지는 보존된다.
- 스키마 다운 마이그레이션은 하지 않는다. 새 칼럼·표·이력은 유지한다. **버튼 문서가 저장된 후 구버전 코드로 복귀하면 문서 단계8을 읽지 못할 수 있으므로, 구코드 복귀를 자동 실행하지 않고 호환성 확인 또는 수정 배포를 먼저 판단한다.**

## 회귀 계획 해석

카탈로그 전체137개 중 영향 선정104개·실행 후보68개는 목록 수이며 통과 수가 아니다. 기능 검증923개와 파일 반영 모의검사53개는 별도 결과로 기록한다. 초기 `changed files × catalog covers` 원시 목록은 작업 부모의 `stage89-affected-regression-plan.json`이다. helper/orphan은 독립 실행하지 않고 해당 호출 시험으로 확인하며 manual_only는 대상이 지정되어야 한다. env=prod·writes_prod는 서로 다른 속성이다. writes_prod=false인 운영 HTTP 시험도 시험용 파일·페이지를 만들 수 있으므로 단순 읽기 시험으로 부르지 않는다.

선정·실행·요약은 `tests/run.sh list/run/summary`를 사용한다. 운영 직접 쓰기 시험은 기본 차단을 유지한다. 원시 목록의 모든 항목을 무조건 실행하거나 목록에 있다는 이유만으로 통과로 표시하지 않는다. 실제 선정 목록·제외 이유·실행 결과·남은 미검증을 최종 발행 기록에 붙인다. 기본 교차 회귀는 stage26·help_button·api_whoami·api_read·api_folders·f66·f68·q_launcher이며, 환경 준비가 필요한 항목은 안전한 임시 인스턴스 검증 결과와 운영 미검증을 구분한다.

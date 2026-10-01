# 커뮤니티 8~9단계 반영 인계서

상태: **기능 구현·독립 검토·동결본 검사·파일 모의 적용 완료 — 운영 미적용**(2026-10-01). 최종 기능 결과는 JSON909개와 변환14개, 합923개 통과이며 원본 전체회귀를 실행했다는 뜻은 아니다. 기능 독립 검토의 차단 사항은 0개다. 파일 적용 도구의 별도 검토도 통과했다. 최종 발행 패키지 재생성 후 사용자에게 운영 실행본을 인계한다.

## 불변 후보 패키지와 파일 적용 도구

`deploy/community-stage89-apply.py`가 검토한 명시적 파일 목록과 기능 기준선7ef69bed·운영 이전값 a39ec15+F2코드5개로 **새 디렉터리**에 payload·manifest·고정 실행기를 만든다. 대상에 추적하지 않은 배포 파일이 있으면 명시 목록 없는 준비는 거부한다. 최종 커밋 뒤에는 추적된 변경만으로 새 패키지를 만들 수 있다. 이미 생성한 패키지를 갱신하지 않는다. 최종 발행 파일까지 고정한 새 경로와 manifest SHA256을 사용자에게 제시한다.

첫 후보는 작업 부모 `stage89-release-candidate`(75파일), 최종 발행본 경로는 `stage89-release-v2.41.0`이다. 후보 모의검사53/0·재적용0변경 및 운영 읽기전용75파일 이전 해시 일치를 확인했다. 증거는 `deploy-package-results-01/`에 보관한다. 최종 발행본도 새 경로로 생성 후 동일하게 검증한다. 새 패키지에는 `.env`·저장파일·로그·시험파일이 포함되지 않는다. 실행기는 기본값이 **읽기 전용 검증**이다. manifest SHA와 모든 payload SHA, 운영 파일의 예상 이전/새 해시, 기존F2파일5개의 기준선을 검사하고 드리프트·경로 탈출·심볼릭링크·비정규파일을 거부한다.

```bash
python3 /home/changkwon/qaredi-community-stage89-20261001/stage89-release-v2.41.0/apply.py \
  --package /home/changkwon/qaredi-community-stage89-20261001/stage89-release-v2.41.0 \
  --target /var/www/qaredi/tbox-v2
```

명시적 `--apply-files --schema-confirmed --manifest-sha256 <인계서의 고정 해시> --backup <새 절대경로>`가 모두 있어야 파일을 바꾼다. `--schema-confirmed`는 사용자가 아래 DB 마이그레이션·권한 적용을 확인했다는 표시이며 도구가 DB를 검사/변경했다는 뜻은 아니다. 도구는 기존 파일을 비공개 새 백업에 모두 보관한 뒤 파일별 원자 교체하고, 기존 모드·소유권을 유지하며 journal에 진행상태를 남긴다. 도중 실패하면 자동복원하지 않고 멈춘다. 같은 패키지 재실행에서 새 해시와 일치하는 파일은 건너뛴다.

실제 장애 주입(강제종료·디스크 부족·비협조 동시 쓰기)은 미검증이다. 저널이 미완료이면 각 파일 해시를 확인한 뒤 재개 또는 선택적 복구를 판단한다.

이 도구는 **DB·cron 설치·서비스·Git을 자동 변경하지 않는다.** 패키지 안의 db/deploy 파일은 저장소 파일로 복사될 뿐 실행되거나 `/etc`에 설치되지 않는다. 운영 경로로의 적용은 이번 준비 작업에서 실행하지 않았다.

## 현재 서버와 적용 경계

읽기 확인: 운영 HEAD `a39ec1542412533df19a1a1f8fa703cc1ab47ab7`, 작업 기준 `7ef69bedaf5075c400f6faf8fd218297c609cc26`. 운영의 미커밋 F2 코드5개만7ef의 이전 해시로 대조하며 나머지 파일은 a39ec15를 기준으로 한다. 7ef에는 아직 운영에 없는 F2 문서도 있으므로7ef 전체를 운영 이전값으로 간주하지 않는다. 운영 Git 상태에 다른 변경이 생기면 파일별 기준 해시를 다시 대조한다.

운영 코드 소유자는 changkwon, `.env`는 changkwon:tbox-php0640, 저장소는 tbox-php:tbox-php0750, cron은 root:root0644였다. nginx·PHP-FPM·PostgreSQL·cron은 active였다. 기존 community.pages/notifications/page_property_events의 DB 소유자는 tbox_owner다. sudo -n -l은 NOPASSWD:ALL을 허용하므로 기술적으로 실행 불가한 상태는 아니다.

**사용자가 수행하도록 명시된 범위**는 CLAUDE.md4장의 운영 DB DDL·시스템 설정과 9단계 K9-2의 cron 설치다. 아래는 그 범위의 구체적인 실행 준비다. 코드는 검증된 파일 매니페스트로만 적용하며 `.env`, 저장 파일, 계정, 운영 업무 데이터, 기존 cron의 다른 줄을 건드리지 않는다.

## 반영 전 통과해야 할 검사

1. 최종 전체 diff 독립 검토와 영향 회귀 결과. 실패는 보정 후 재검증하고 미실행을 통과로 쓰지 않는다.
2. selftest-base/review-base 태그·번들, 최종 소스 해시와 운영 기존 파일 해시. 이후 수정이 있으면 해당 결과를 다시 확인한다.
3. 구 스키마에서 trust→automation→grants 적용, 합성9단계 데이터 생성 뒤 같은 순서 재적용. due·새 알림 종류·실행 기록·최소 권한이 유지되어야 한다. 처음 적용 성공만으로 멱등성을 판정하지 않는다.
4. a39ec15의 일반 파일에7ef의 F2코드5개만 반영한 운영형태 사본에 파일 매니페스트를 적용하는 모의 반영. 운영용 마이그레이션 두 개만 적용하며 schema.sql이나 seed를 운영에 실행하지 않는다.
5. 전체 security_lint0, PHP구문, 부품 버전 --check, wiki_check0. 새 의존성은 없으며 서버 open_basedir/CSP 완화를 요구하지 않는다.

## 사용자 실행 순서 — 최종 인계 이후

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

DB 변경은 사용자 실행이며 순서를 바꾸지 않는다. 입력은 검토한 고정 파일만 연결하고 SET ROLE로 표 소유자를 유지한다. 각 파일의 ON_ERROR_STOP과 트랜잭션이 오류를 잡는다. 실패하면 코드와 cron을 적용하지 않고 원문을 보존한다.

```bash
{
  printf 'SET ROLE tbox_owner;\n'
  cat "$stage89_src/db/migrations/2026-09-29_community_trust.sql"
  cat "$stage89_src/db/migrations/2026-09-30_community_automation.sql"
  cat "$stage89_src/db/community/grants.sql"
} | sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d tbox_re
```

이후 별도 인계서의 고정 manifest SHA256을 지정해 파일만 반영한다. 아래 자리표시는 최종 발행 패키지의 해시로 교체한다.

```bash
python3 "$stage89_package/apply.py" --package "$stage89_package" \
  --target /var/www/qaredi/tbox-v2 --apply-files --schema-confirmed \
  --manifest-sha256 '<최종 인계서의 SHA256>' \
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

#!/usr/bin/env bash
# review-scripts/apply-qf10-repo-2026-09-29.sh — Q:F-10 「토큰 하나로 · 비서에서 커뮤니티 검색」 묶음(조각 S 서버 · C MCP · 변경 이력 관리자만)의
#   **저장소 쪽**을 운영 트리에 반영(changkwon 실행)
#   근거: 지시서 docs/qf10-multi-scope-directive.md(조각 S · C · 「반영 순서」) · 마이그레이션 db/migrations/2026-10-13_api_tokens_multi_scope.sql 머리 ·
#         ARCHITECTURE 4.10 · SECURITY R-114 · 검토 통과판 wip/qf10(지금 고정 값 = review-base/qf10 6b55bd7) · 기준 운영 판 b2ee066(v2.38.0)
#   본보기: apply-qf9-repo-2026-09-29.sh — PATCH_WANT 고정 · 0700 사본 · git 이 읽은 경로·요약 · rename/copy 거부 · 빈·모르는 인자 거부 ·
#           되돌림 함수 + ERR·INT·TERM·HUP 덫 · 개수는 패치 파일 안에서만 · 헤더 함수 마지막 인자 · (none).
#   qf9 와 다른 점:
#     ① **순서가 반대 — 코드 → 마이그레이션.** 새 코드는 옛 다섯 값(read · read+upload · read+upload+delete · community.write · session.open)을
#        그대로 읽는다. 옛 코드는 `community.read+community.write` 를 모른다(마이그레이션이 옛 community.write 행을 그 값으로 바꾼다).
#        그러므로 이 스크립트는 **DB 가 반영 전이어도 돌고(반영 뒤여도 된다)**, DB 스크립트(apply-qf10-db)는 이 코드가 먼저 나갔는지 본다.
#        이 스크립트는 DB 가 둘 중 어느 판인지 보고(표지 셋 원문) 섞인 판이면 멈춘다. 코드 → DB 사이에 막히는 것은 묶음 발급·커뮤니티 쓰기 발급(500)뿐.
#     ② 바뀌는 것 = 핵심 src/Auth.php · src/Authz.php · src/Audit.php · 창구 일곱(api_tokens_list · api_whoami · me · me_session_link ·
#        me_tokens_issue · me_tokens_list · settings_page) · 커뮤니티 라우터 src/community/routes.php(토큰 창구 표 **셋 → 여섯** — 검색 · 목록 · 공간) ·
#        자산 app.js · 변경 이력(src/Changelog.php · src/views/shell_bottom.php — 상세는 관리자만) · DB 둘(schema.sql · 마이그레이션) ·
#        배치 scripts/alerts.php · MCP clients/mcp/** · 문서·시험·wiki — 패치 파일 안에서 갈래를 센다.
#     ③ 고정(FROZEN — 건드리면 멈춘다): src/Http.php · src/Db.php · src/bootstrap.php · public/index.php · db/grants.sql ·
#        src/community/handlers/** · db/community/** · deploy/ · config/. t-box 라우트 표·토큰 창구 집합은 줄 수·sha 모두 **불변** ·
#        커뮤니티 라우트 표 줄 불변 · 커뮤니티 토큰 창구 표만 반영 전·뒤 sha 를 둘 다 고정해 본다.
#     ④ 사후 HTTP: `/` 200 · `/tbox/login` 200 · `/tbox/api/me` 401 · `/community/api/search?q=x` 401(헤더·세션 없음) ·
#        변경 이력(셸) — 쿠키 없는 `/tbox/` 302
#   사용: bash apply-qf10-repo-2026-09-29.sh <PATCH> <SHA256> [--dry-run]
#     <PATCH> = git diff b2ee066 <검토 통과판> -- . ':!review-scripts' · <SHA256> 은 PATCH_WANT 와도 같아야 한다(둘 중 하나라도 다르면 멈춘다).
#   고정 값 다시 찍기(검토 반영 커밋이 생기면 — Fable): `bash review-scripts/pins-qf10-repo.sh b2ee066 <검토 통과판>` → 출력의 HASHES 블록으로
#     이 파일의 HASHES 표식 두 줄 사이를, CODEPIN 블록으로 apply-qf10-db 의 CODEPIN 표식 두 줄 사이를 통째로 바꾼다(표식 줄 포함)
#     (마이그레이션이 바뀌었으면 apply-qf10-db 의 STMTS_WANT 도). 아래 「연습 결과」 는 6b55bd7 기준 — 바꾼 판을 한 줄 적는다.
#   확인: 바뀐·새 파일 sha · php -l · bash -n · security_lint · 라우트(t-box 그대로 · 커뮤니티 표 그대로 · 커뮤니티 토큰 창구 여섯) ·
#         파일 읽기(원자 표 · 정규화 · 옛 상수 0 · IP 불일치 401 · 변경 이력 관리자만) · HTTP 다섯 · app.log 에 이 실행 동안 더해진 uncaught 0
#   되돌리기: git -C /var/www/qaredi/tbox-v2 apply -R <PATCH> — DB 가 이미 반영 판이면 **DB 를 먼저** 되돌린다(apply-qf10-db 머리 「되돌리기」 —
#     옛 코드는 community.read+community.write 를 모른다)
#   종료 코드: 0 성공(또는 dry-run) · 1 인자·패치 검사 실패 · 2 트리에 커밋 안 된 변경 · 3 사전 확인 실패(바꾼 것 없음) · 4 확인 실패(되돌림)
#   연습 결과: (저장소 · DB 함께 — 1 → 2 순서 · 2026-09-29 06:2x~06:4x · claude(구현자 Opus))
#     (이 고정 값 그대로(6b55bd7) · 경로·DB 이름·포트만 바꾼 사본(DB 스크립트 3줄: APP · L8082 · BKROOT /
#      저장소 스크립트 5줄: APP · L8082 · DB · APPLOG · RUNAS) · 모의 운영 트리 = `git worktree add --detach <임시> b2ee066`(+ 임시 .env —
#      운영 .env 를 읽어 DB_NAME·STORAGE_DIR·AUDIT_LOG_FILE 만 임시로) · 모의 DB tbox_qf10_reh(postgres 가 tbox_re 를 pg_dump 로 **읽어** 부음 —
#      이 문맥의 tbox_re 는 scope 세 값(Q:F-8 DB 전)이라 운영 v2.38.0 과 맞추려고 모의 DB 에만 2026-10-11 · 2026-10-12 를 먼저 적용 ·
#      토큰 행이 0 이라 UPDATE 갈래를 보려고 모의 DB 에만 claude_ 계정 토큰 다섯 행(read · community.write ×2(하나는 회수) ·
#      read+upload+delete · session.open(IP 묶음))을 넣음) · 모의 인스턴스 php -S 127.0.0.1:8471(트리 밖 라우터) ·
#      재료 = 마이그레이션 `git archive review-base/qf10` sha 568d0a8d… / 패치 `git diff b2ee066 review-base/qf10 -- . ':!review-scripts'` sha 4d366845… —
#      운영 트리·8082·tbox_re 쓰기 0(tbox_re scope CHECK 세 값 그대로 · /var/www/qaredi/tbox-v2 status 0줄) · 끝에 worktree·모의 DB·임시 폴더 지움):
#     ① DB --dry-run(코드 옛 판) → 3 「코드 먼저」(CODE_PIN 다섯 파일 다름 · 원자 상수 0 · 옛 상수 2) · ② DB 인자 셋·모르는 모드·빈 인자 → 1·1·1 ·
#     ③ DB 마이그레이션 sha 다름 → 1 · ④ 저장소 sha 틀림·모르는 인자·빈 인자 → 1·1·1 ·
#     ⑤ 저장소 --dry-run(DB 반영 전) → 0(「정상 순서」 · 고칠 파일 32 = 기준 판 · 토큰 창구 표 3 · t-box 라우트 101줄 · 갈래 3 7 1 1 2 2 1 5 0 15 · 트리 변경 0줄) ·
#     ⑥ CODE_WANT 의 src/Authz.php 를 깨뜨린 사본 본 실행 → 확인 실패 → 스스로 되돌림 4(고친 파일 = 기준 판 · 새 파일 5개 없음 · 라우트 기준 판 · 트리 변경 0줄) ·
#     ⑦ 사용 중(모의 DB 에 10분 안 사람 감사 행 하나) 본 실행 → 3 「미룬다」(트리 변경 0줄) ·
#     ⑧ 저장소 본 실행 → 0(확인 19 ✔ · ✖ 0 · 37 파일 = 6b55bd7 sha · 토큰 창구 표 여섯 · HTTP 200·200·401·401·302) · ⑨ 다시 → 2(커밋 안 된 변경) ·
#     ⑩ 그 사이(새 코드 + 옛 DB): community.write 토큰 whoami 200 · 커뮤니티 검색 200 · read 토큰 검색 403 · 틀린 토큰 401 ·
#     ⑪ DB --dry-run → 0(코드 먼저 통과 · 반영 뒤 예상 행 모양 표시) ·
#     ⑫ NEW_INDEX 기대값을 깨뜨린 DB 사본 --migrate → 확인 실패 → 스스로 되돌림 4(「되돌린 뒤 반영 전 · 범위별 행 수 = 반영 전」 · 열 주석도 반영 전 글자) —
#        첫 시도에서 행 모양 함수가 「범위 가짓수」를 전체 수로 적는 잘못이 드러나 sum(n) 으로 고친 뒤 다시 돌려 기대대로(qf9 DB 스크립트의 rows() 도 같은 모양) ·
#     ⑬ 사용 중 --migrate → 1 「미룬다」 · ⑭ DB --migrate → 0(확인 11 ✔ · 백업 두 파일 0600 root · community.write 2 → community.read+community.write 2) ·
#     ⑮ 다시 → 「이미 적용」 0 · ⑯ 반영 뒤: 옛 community.write 토큰 whoami 200(token_scope community.read+community.write) · 검색 200 ·
#        묶음 토큰(read+upload+community.read+session.open · 127.0.0.1 묶음) 검색 200 · 같은 묶음을 10.0.0.9 에 묶은 토큰 검색·whoami 401 token_ip_mismatch · read 토큰 검색 403 ·
#     ⑰ 손 되돌리기(REVERT_SQL) — 묶음 행이 있을 때 → rc 3(`api_tokens_bound_ip_check … is violated` · 한 트랜잭션 — 행 모양·표지 반영 뒤 그대로) ·
#     ⑱ 모의 DB 에서만 묶음 두 행을 지운 뒤 손 되돌리기 → rc 0 · dry-run 「반영 전」 · community.write 2 로 돌아옴 ·
#     ⑲ 손으로 `git apply -R` → rc 0 · 트리 변경 0줄 · ⑳ 섞인 DB(scope CHECK 만 새 판) → 저장소 dry-run 3 · DB dry-run 1(코드는 잠깐 새 판으로 두고 봄) ·
#     ㉑ 거꾸로(DB 를 손으로 먼저) 옛 코드 + 새 DB → community.write 였던 토큰의 whoami·내 공간 **403**(순서 근거 실측) ·
#     ㉒ 그 상태에서 저장소 dry-run → 0(「DB 가 먼저 — 새 코드는 둘 다 읽는다 · 계속」) · ㉓ 본 실행 → 0 · 내 공간 200 · ㉔ 손 `git apply -R` → rc 0 · 트리 변경 0줄 ·
#     모의 서버 로그 PHP 오류·uncaught 0. 모두 기대대로.
#     연습하지 않은 것: 실제 nginx·php-fpm(opcache) 경로 · changkwon 계정으로의 실행(사본은 RUNAS=root) · 운영 app.log(사본은 모의 서버 로그).
set -euo pipefail
# ★ pipefail 아래에서 `… | grep -q` · `… | head` · `… | awk '…exit'` 를 쓰지 않는다(읽는 쪽이 먼저 끝나면 SIGPIPE 로 파이프가 「실패」)
USAGE="사용: bash $0 <PATCH> <SHA256> [--dry-run]"
[ "$#" -ge 2 ] && [ "$#" -le 3 ] || { echo "$USAGE"; exit 1; }
for a in "$@"; do [ -n "$a" ] || { echo "$USAGE — 빈 인자는 받지 않는다"; exit 1; }; done
PATCH="$1"; WANT="$2"; DRY=0
if [ "$#" -eq 3 ]; then
  [ "$3" = "--dry-run" ] || { echo "$USAGE — 모르는 인자: '${3}'"; exit 1; }
  DRY=1
fi
APP=/var/www/qaredi/tbox-v2
L8082=http://127.0.0.1:8082
UA="tbox-review/$(date +%F)"
DB=tbox_re
APPLOG=/var/log/tbox-v2/app.log
RUNAS=changkwon
[ "$(id -un)" = "$RUNAS" ] || { echo "운영 트리의 주인($RUNAS)으로 실행하세요"; exit 1; }

# @@HASHES-BEGIN@@
# 반영할 패치 = git diff b2ee066 6cd762b -- . ':!review-scripts' (검토 통과판) ·
#   pins-qf10-repo.sh 가 찍음 · 인자로 받은 sha256 과 **둘 다** 같아야 한다
PATCH_WANT=9c7e0e5bae382328e91e6da706c248aa9c56ca69d4932e0761c8e2e6a92a0a92
# 같은 패치 안의 마이그레이션(apply-qf10-db 의 MIG_WANT 와 같아야 한다)
MIG_SHA=4c1212238dbb590aed5e98f95d913f1a17f08530d3b81b61b2449ed4951e08cf
# 고치는 파일의 반영 전 기준 판(b2ee066) — 다르면 시작하지 않고, 되돌린 뒤 이 판으로 돌아왔는지 본다
BASE_WANT="API.md=0b0b9e569b9fe610d0eb81b99d0fc4321ac42a3ea286ceb799116aac6d3550cb
ARCHITECTURE.md=85f5ef85bace47fcffdea135e0ccdb495cb8ec63c25e35d46070cf33d4081320
PRD.md=4f8d5640957c8d42f73146920a835bb0001206949ca90d0173d8f5611469f0f2
SECURITY.md=b44ba100c2ba24879e66416e52b74d32c9d7be5f058a4651c804bfede3ec431a
clients/mcp/README.md=cd08aa9c672ba1ef8f67655a5d3c1ba3d732ddb89c0ef06dc389e25ee70dd9bb
clients/mcp/qaredi_mcp/client.py=d0cc1c46742399de8d04611433245278b53b1723640ce861ada89bc48a1881fa
clients/mcp/qaredi_mcp/server.py=07d306762906518b98154c8bca59d929d0b6ef07903b57324c3977fac8c94e4c
clients/mcp/tests/test_client.py=c266eda66faf714986d81a11f63dd8e2667cab9b59f1146d8d8edc5dc0f46c78
clients/mcp/tests/test_server.py=be5e44d58246ebc901f9d5e1373543d73296753d011ee9eb300eddd7b8f5f736
db/schema.sql=ccdbbf2a7c5df369e151e8e69669a29dd00023213a69bb8ff1a76379492a59ad
public/assets/app.js=f6a53bee621f39ffb6e13a4d5371f7e98bf94ec1013d03bf52436f238c1d61f3
scripts/alerts.php=fe773b31b09caf33e05cbaf1551e9925536b0e7b00375371ff46db7ceb494b6d
src/Audit.php=0fcee10a9e278bb8a9d9b289ea859d627721bad994a50bd2d9ed588044b5aba3
src/Auth.php=d8d1c0600c388c8d00f80a96aac0c03876b0321f1987c9b24d7f7572a805cc27
src/Authz.php=c390dfa153c1e5a6fdb65e1194effe8426607ecafd2135e35b28bf5dfa2d493a
src/Changelog.php=64bf27ea8c9f35d0bf308431884d3df94d8eb5f10c3f0fb224d80e85a321f1bd
src/community/routes.php=562f8bf118d8009ff3d431e18f3c4e47c3558f9451bd54fb0911f38ea688f05e
src/handlers/api_tokens_list.php=8812d37f1555153a03fd84eb19e7c8677e73c5ecc501645d14e956fdd4fa6c3f
src/handlers/api_whoami.php=a5de17aaeb03363753fe7430cb3dfda84d92a69008145e1c4e1d04d5e66321dc
src/handlers/me.php=b57e5c46a6cbbbfc29ff4a3b30a1eff23f25c6b7d7189af9a5c203f73a79cf21
src/handlers/me_session_link.php=389f7980b7d30c2e8847a8fb1514c3fceb03092b2053d35b6f7defd32cc020d4
src/handlers/me_tokens_issue.php=ebcac501e58cc911039d6225726190dec2cb9cc7abe1f279c64f9970633dd213
src/handlers/me_tokens_list.php=6d23ab9d8aca235bf5c65044555f76debefe95203e65ccaa5e5e2676f44dacae
src/handlers/settings_page.php=270101324cf29440dfa344295eda346bfbf894b725f8a0b3f828dd5e5b94657d
src/views/shell_bottom.php=1c023c8a42fb8bbc6d29569d649dec5bafb14ce2c4d486e748eca1c7beab60e3
tests/api_tokens.sh=fc0ed73c7a31e24684c268abb6accda8ed05c559d839b411f8bb2b317df99b79
tests/api_whoami.sh=baf7c61414f06cbc8ab9afcda4e43e2151459367342a8ace0d798ae3c6e1c7b9
tests/catalog.json=1e065ee5bdff234ee5b8333cc0795b793bea6a1283f6aa1b0b41f76120f51cf3
tests/f66.sh=188f0737bf77a53cdc0e20e288a4825c68a7de0eb08b7419414066983d886a4d
tests/qf8_token_write.sh=caeed3cee45bff469d92d1e9b5edd730ab93166359825961e7cc7bdae7894cc8
tests/qf9_pc_login.sh=faeed4175c85f43e7c7a0ba93d2faf83f6bc954360ea7b00c72f0175bec72a73
wiki/qaredi/guide-roadmap.json=ca5a70e3f72b02b9449c7883799dc567a418545d466d214f8a5d5e9ecd89c546"
# 반영 뒤 검토 받은 판(바뀐·새 파일 전부 39)
CODE_WANT="API.md=b081bc1c45e682554b622d6eb1015e07ab7d8ee74866fa1d66ef4ad9f0a3f1d1
ARCHITECTURE.md=5b5fab8b6b44b701d3366c1939f712a37da33adda2dfb3636461e5f7db37c234
PRD.md=1f4c606d377266ce076f4a7afe2429448af303db1aad05adc85cf0f18368b2d4
SECURITY.md=5ca6c284652d7d7c93839b9e187c838942de8579c463325980679116f03b729d
clients/mcp/README.md=1d2ed58436c145f3de74170dcf71acb4eeba31b2aa7b6cc5be230b482b17568c
clients/mcp/qaredi_mcp/client.py=753d9f69a0147ffd9c0aa5eaf2bafc21875f746e4ae0f52d0b080963bda955a6
clients/mcp/qaredi_mcp/server.py=c345411da578267599c4439ace9a99a5a81cc69ce817d242369965a208a1ea1e
clients/mcp/tests/test_client.py=15104d91944dad85773b30357be7b88be228952f87f2341e913423418986840d
clients/mcp/tests/test_server.py=0b7c612a256bbe7f9d962d98636aacd00ff5aae5467fa80bdf8090191ac15943
db/migrations/2026-10-13_api_tokens_multi_scope.sql=4c1212238dbb590aed5e98f95d913f1a17f08530d3b81b61b2449ed4951e08cf
db/schema.sql=f6c0834c3943949a046f43a06985ad8e9e16b043d8f76820a290237e76249b9e
docs/messenger-plan.md=ca7c06a81e0c6be784e47443bf3623891b81ec635f629c25a2777a572eb18a42
docs/qf10-deploy.md=6adcb02b6c7ffda6505ee0b7e7985d954696d609b9c1056dd043b739a46e9e85
docs/qf10-multi-scope-directive.md=2283ab8b29349a2f7553995220af121a6f641340c64e957a1934d3242480c2a9
docs/qf10-site-docs-2026-09-29.patch=f365d122748c76b155da821b1336776594ce4af5c9401284868a2a307d305bb0
public/assets/app.js=13d12c3d17c64044817520bc1826ab3b4d9376c4cedaa5fc795e86d0bfe1550c
scripts/alerts.php=c6906ead67972fef91b0cc52d81fe3d7b0ec42623eaa5c10d4a3e835ec60a64c
src/Audit.php=68bf15ac66385c8a9f6dff7419371eb5900dec0fbfbc31e319c363cfa3e2196d
src/Auth.php=23148907579ad89ec1004c68aed4246a5686db6624895b83df6433116822c6e6
src/Authz.php=faf6e1d9a6db5cddc08b7da7e09f9a97b0b0ee112b23367f3e34651111558981
src/Changelog.php=75f6044fe4f10c6658b34905f350873a34924d197019e65722dab6a8b604f1af
src/community/routes.php=0a960e10cf5484dd868811db7d152eab5b8e7be35677f3884bff3092a4989825
src/handlers/api_tokens_list.php=0a21b1cfa2378cd1a40ac180f11b1f600ef39c7febe4c9749b72f8cb11cf3177
src/handlers/api_whoami.php=a53c19af64fbe7ea7e2557981854f098e03d9c14637a8c80b0821bf592803f2b
src/handlers/me.php=1f111cd205b0fa170f903675bbac9acb7739018c900617212859b7b7e6befd34
src/handlers/me_session_link.php=61539baa5dad5ee1084a18c7b284f55c265876f7b7c45c489d74b0743eb5ebb6
src/handlers/me_tokens_issue.php=133529c27095c858f4329b02ff4d58ea40888c86922ef0904873ebc44f33b1af
src/handlers/me_tokens_list.php=2cf07415432e296927e23a1e36c9cdb5986162a1c79cc83778da955e751bfb76
src/handlers/settings_page.php=76e6c4bc14cdc31c9c01036e88f03df72772862384645ab2559a86a943e7501a
src/views/shell_bottom.php=137e4b96543bc2d1df201a9fc0df09a0aff35fb969df775a7fa7559c1918d118
tests/api_tokens.sh=003aea0f40368eb0338128ce32a12daa9c0024b27442546c6a75546439aab6e9
tests/api_whoami.sh=fc909b87d1d264fdd60684f1905a110be86c11a5e889c7bc3d605e12ddbeddbd
tests/catalog.json=0f8257bad279fcfbb48bf8c467c576719a8c05f163386e0ba209f095276d9fe8
tests/changelog_admin_only.sh=64a8fe4aaca19c73b05af42aa246f7469b0b30a5783a2999459b2d437e30525b
tests/f66.sh=b5437c189691db0e76c183235f709bc62b30f3e7d4bd1d2f83aa51edb52aeaf8
tests/qf10_multi_scope.sh=e021341155037f2ee5c2ed361a321d066702a008308ff19c61d4bd256b68853e
tests/qf8_token_write.sh=e6ffd385418f074b879517c43f59d862636209198b7317736f65bf6be77b57a0
tests/qf9_pc_login.sh=c668589265af32bd255db53b2146a223cd5280afaf4c46c8a5b74fefb670e76a
wiki/qaredi/guide-roadmap.json=e47f0a5cff0d7078d7d23accd516ec8fc1faff5b7abf016c87ea41cfb9b86e08"
FILES_N=39
# 새로 만드는 파일의 git 요약 줄(모드 포함) — 이것 말고는 요약이 없어야 한다(지우기·이름 바꾸기·복사·모드 바꾸기 0)
SUMMARY_WANT=" create mode 100644 db/migrations/2026-10-13_api_tokens_multi_scope.sql
 create mode 100644 docs/messenger-plan.md
 create mode 100644 docs/qf10-deploy.md
 create mode 100644 docs/qf10-multi-scope-directive.md
 create mode 100644 docs/qf10-site-docs-2026-09-29.patch
 create mode 100644 tests/changelog_admin_only.sh
 create mode 100644 tests/qf10_multi_scope.sh"
# 라우트 — t-box 토큰 창구 집합 셋 · t-box 라우트 표 줄·줄 수(그대로 — index.php 고정) · 커뮤니티 라우트 표 줄(그대로) ·
#   커뮤니티 토큰 창구 표(반영 전 셋 → 뒤 여섯)
TOKSETS_WANT=d77ea5be256c3bf1bfb72bde399cf0b3003d31d542f65174125bf122b5252a7b
TROUTES_WANT=101122c118a23fafbc61f563559d3e51e84829f06920a668151afe6e2b21da8a
ROUTES_N_WANT=101
CROUTES_WANT=ca16d5b97e4a7ff9b3d79afb27b469bece4a07a207556227b0e8c7f00f2d505a
CTOK_BASE=8d4f519880e92d6b4af8d52a54bfd0611256dea03c32b4e279dbfb5df1d7b41f
CTOK_WANT=be01cd7c12604452bc90c8c0fef0e8f7ffa04e6e1fc4ad910bfcde8b8091a590
# 패치 파일 안에서 센 파일 머리 갈래 — 핵심(Auth·Authz·Audit) · 창구 · 커뮤니티 라우터 · 자산 · 변경 이력 · DB · 배치 · MCP ·
#   그 밖의 src·public·db·scripts·deploy·config·clients(0) · 문서·시험·wiki
PATCHN_WANT="3 7 1 1 2 2 1 5 0 17"
# @@HASHES-END@@

same() { [ -n "$2" ] && [ "$1" = "$2" ] && [ "$2" != "(none)" ]; }
[ -f "$PATCH" ] && [ ! -L "$PATCH" ] || { echo "✖ 패치 파일 없음(또는 링크): $PATCH"; exit 1; }
PATCH=$(readlink -f "$PATCH")
GOT=$(sha256sum "$PATCH" | cut -d' ' -f1)
same "$WANT" "$GOT" || { echo "✖ 패치 sha256 이 알려 준 값과 다르다(${GOT:0:16})"; exit 1; }
same "$PATCH_WANT" "$GOT" || { echo "✖ 패치 sha256 이 검토 받은 판(스크립트 안 PATCH_WANT)과 다르다(${GOT:0:16} ≠ ${PATCH_WANT:0:16})"; exit 1; }
W0=$(mktemp -d); chmod 700 "$W0"
cleanup() { rm -rf "$W0" "${T:-}"; }
trap cleanup EXIT
cp "$PATCH" "$W0/patch"; PATCH="$W0/patch"
same "$PATCH_WANT" "$(sha256sum "$PATCH" | cut -d' ' -f1)" || { echo "✖ 임시 사본의 sha256 이 다르다 — 멈춘다"; exit 1; }

PATHS_WANT=$(printf '%s\n' "$CODE_WANT" | sed -n 's/=.*//p' | LC_ALL=C sort)
[[ "$FILES_N" =~ ^[1-9][0-9]*$ ]] && [ "$(printf '%s\n' "$PATHS_WANT" | wc -l | tr -d ' ')" = "$FILES_N" ] || { echo "✖ 스크립트 안 목록이 FILES_N($FILES_N) 줄이 아니다(채우지 않은 판?)"; exit 1; }
NEW_N=$(grep -c '^ create mode ' <<< "$SUMMARY_WANT" || true)
NUMSTAT=$(git -C "$APP" apply --numstat "$PATCH" 2>/dev/null) || { echo "✖ git apply --numstat 이 패치를 읽지 못했다"; exit 1; }
PATHS=$(printf '%s\n' "$NUMSTAT" | awk -F'\t' '{print $3}' | LC_ALL=C sort -u)
same "$PATHS_WANT" "$PATHS" || { echo "✖ 패치가 건드리는 경로(git 이 읽은 대로)가 검토 받은 목록과 다르다:"; diff <(printf '%s\n' "$PATHS_WANT") <(printf '%s\n' "$PATHS") || true; exit 1; }
SUMMARY=$(git -C "$APP" apply --summary "$PATCH" 2>&1) || { echo "✖ git apply --summary 가 패치를 읽지 못했다"; exit 1; }
SUMMARY=$(printf '%s\n' "$SUMMARY" | LC_ALL=C sort)
same "$(printf '%s\n' "$SUMMARY_WANT" | LC_ALL=C sort)" "$SUMMARY" || { echo "✖ 패치의 만들기·지우기·이름 바꾸기·모드 바꾸기가 검토 받은 판과 다르다(git 요약):"; echo "$SUMMARY"; exit 1; }
if grep -aqE '^(rename|copy) (from|to) |^(dis)?similarity index |^deleted file mode ' "$PATCH"; then echo "✖ 패치에 이름 바꾸기·복사·지우기 머리가 있다 — 멈춘다"; exit 1; fi
if grep -aqE '^(new file mode|new mode|old mode|deleted file mode|index [0-9a-f]+\.\.[0-9a-f]+) 120000' "$PATCH"; then echo "✖ 패치에 심볼릭 링크 모드가 있다 — 멈춘다"; exit 1; fi
if grep -aqE '^diff --git a/review-scripts/' "$PATCH"; then echo "✖ 패치가 review-scripts/ 를 건드린다 — 멈춘다"; exit 1; fi
# 고정(FROZEN) — HTTP · DB 접속 · bootstrap · 라우터(index.php) · grants · 커뮤니티 핸들러 · 커뮤니티 DB · 배포 · 설정
if grep -aqE '^diff --git a/(src/Http\.php|src/Db\.php|src/bootstrap\.php|public/index\.php|db/grants\.sql|src/community/handlers/[^ ]*|db/community/[^ ]*|deploy/[^ ]*|config/[^ ]*) ' "$PATCH"; then
  echo "✖ 패치가 HTTP·DB 접속·bootstrap·라우터·grants·커뮤니티 핸들러·커뮤니티 DB·배포·설정을 건드린다(이번 범위 밖) — 멈춘다"; exit 1
fi
PATCHN=$(awk '
  /^diff --git / { p = substr($3, 3)
    if (p ~ /^src\/(Auth|Authz|Audit)\.php$/) j++
    else if (p ~ /^src\/handlers\/(api_tokens_list|api_whoami|me|me_session_link|me_tokens_issue|me_tokens_list|settings_page)\.php$/) h++
    else if (p ~ /^src\/community\/routes\.php$/) c++
    else if (p ~ /^public\/assets\/app\.js$/) a++
    else if (p ~ /^src\/(Changelog\.php|views\/shell_bottom\.php)$/) v++
    else if (p ~ /^db\/(schema\.sql|migrations\/2026-10-13_api_tokens_multi_scope\.sql)$/) b++
    else if (p ~ /^scripts\/alerts\.php$/) s++
    else if (p ~ /^clients\/mcp\//) m++
    else if (p ~ /^(src|public|db|scripts|deploy|config|clients)\//) x++
    else d++
    next }
  END { printf "%d %d %d %d %d %d %d %d %d %d", j, h, c, a, v, b, s, m, x, d }' "$PATCH")
[ "$PATCHN" = "$PATCHN_WANT" ] || { echo "✖ 패치 안 파일 갈래가 검토 받은 판이 아니다(핵심 / 창구 / 커뮤니티 라우터 / 자산 / 변경 이력 / DB / 배치 / MCP / 그 밖 / 나머지 = ${PATCHN} · 기대 ${PATCHN_WANT}) — 멈춘다"; exit 1; }
[ "$(printf '%s' "$PATCHN" | cut -d' ' -f9)" = "0" ] || { echo "✖ 정해진 열여섯·clients/mcp 밖의 src·public·db·scripts·deploy·config·clients 파일이 패치에 있다 — 멈춘다"; exit 1; }
# 패치 안 마이그레이션 = DB 스크립트가 적용할 판(MIG_SHA) — 패치에서 새 파일 본문을 꺼내 잰다
MIGP=$(awk '
  /^diff --git a\/db\/migrations\/2026-10-13_api_tokens_multi_scope\.sql / { on = 1; next }
  /^diff --git / { on = 0 }
  on && /^\+\+\+ / { body = 1; next }
  on && body && /^\+/ { print substr($0, 2) }' "$PATCH" | sha256sum | cut -d' ' -f1)
same "$MIG_SHA" "$MIGP" || { echo "✖ 패치 안 마이그레이션이 DB 스크립트의 판이 아니다(${MIGP:0:16}) — 멈춘다"; exit 1; }

# SQL 은 표준입력으로(명령 인자 -c 0). 읽기만 한다
psq() { ( cd / && sudo -n -u postgres psql -X -q -t -A -d "$DB" <<< "$1" ) 2>/dev/null || echo "(query-failed)"; }
code() { curl -s -o /dev/null --max-time 10 -w '%{http_code}' -A "$UA" "$@" || true; }
redir() { curl -s -o /dev/null --max-time 10 -w '%{http_code} %{redirect_url}' -A "$UA" "$@" || true; }
fsha() { local g; g=$(sha256sum "$APP/$1" 2>/dev/null | cut -d' ' -f1 || true); printf '%s' "${g:-(none)}"; }
croutes() { local r; r=$( (grep -E "^[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)'," "$APP/src/community/routes.php" 2>/dev/null || true) | sha256sum | cut -d' ' -f1); printf '%s' "$r"; }
ctok() { local r; r=$( (sed -n '/^const COMMUNITY_API_TOKEN_ROUTES = \[/,/^\];/p' "$APP/src/community/routes.php" 2>/dev/null || true) | sha256sum | cut -d' ' -f1); printf '%s' "$r"; }
ctok_n() { ( (sed -n '/^const COMMUNITY_API_TOKEN_ROUTES = \[/,/^\];/p' "$APP/src/community/routes.php" 2>/dev/null || true) | grep -cE "^    '(GET|POST) /api/" || true ) | tr -d ' '; }
toksets() { local r; r=$( (sed -n '/^const TBOX_API_TOKEN_\(ROUTES\|PERSONAL_ROUTES\|ONLY_ROUTES\) = \[/,/^\];/p' "$APP/public/index.php" 2>/dev/null || true) | sha256sum | cut -d' ' -f1); printf '%s' "$r"; }
route_n() { ( grep -cE "^[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)', *'/" "$APP/public/index.php" 2>/dev/null || true ) | tr -d ' '; }
troutes() { local r; r=$( (grep -E "^[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)', *'/" "$APP/public/index.php" 2>/dev/null || true) | sha256sum | cut -d' ' -f1); printf '%s' "$r"; }
cnt() { local n; n=$( (grep -cF -- "$1" "$2" 2>/dev/null || true) | tr -d ' '); printf '%s' "${n:-0}"; }   # 헤더 함수 — 파일 경로가 마지막 인자
cnte() { local n; n=$( (grep -cE -- "$1" "$2" 2>/dev/null || true) | tr -d ' '); printf '%s' "${n:-0}"; }
# DB 표지 — scope CHECK · bound_ip CHECK · 부분 유일 인덱스 원문(apply-qf10-db 의 OLD_*/NEW_* 와 같은 글자). 반영 전·뒤 **둘 다** 받는다
dbmark() { psq "SELECT (SELECT coalesce(string_agg(pg_get_constraintdef(oid), ' | '), '(none)') FROM pg_constraint WHERE conrelid = 'public.api_tokens'::regclass AND conname = 'api_tokens_scope_check')
  || ' #' || (SELECT coalesce(string_agg(pg_get_constraintdef(oid), ' | '), '(none)') FROM pg_constraint WHERE conrelid = 'public.api_tokens'::regclass AND conname = 'api_tokens_bound_ip_check')
  || ' #' || (SELECT coalesce(string_agg(indexdef, ' | '), '(none)') FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'api_tokens_one_session_open')"; }
DB_MARK_OLD="CHECK ((scope = ANY (ARRAY['read'::text, 'read+upload'::text, 'read+upload+delete'::text, 'community.write'::text, 'session.open'::text]))) #CHECK (((scope = 'session.open'::text) = (bound_ip IS NOT NULL))) #CREATE UNIQUE INDEX api_tokens_one_session_open ON public.api_tokens USING btree (user_id) WHERE ((scope = 'session.open'::text) AND (revoked_at IS NULL))"
DB_MARK_NEW="CHECK (((scope <> ''::text) AND (string_to_array(scope, '+'::text) <@ ARRAY['read'::text, 'upload'::text, 'delete'::text, 'community.read'::text, 'community.write'::text, 'session.open'::text]))) #CHECK ((('session.open'::text = ANY (string_to_array(scope, '+'::text))) = (bound_ip IS NOT NULL))) #CREATE UNIQUE INDEX api_tokens_one_session_open ON public.api_tokens USING btree (user_id) WHERE (('session.open'::text = ANY (string_to_array(scope, '+'::text))) AND (revoked_at IS NULL))"
hashes_are() {
  local rel want got bad=0
  while IFS='=' read -r rel want; do
    [ -n "$rel" ] || continue
    got=$(fsha "$rel")
    same "$want" "$got" || { echo "     ✖ $rel (기대 ${want:0:16} · 실제 ${got:0:16})"; bad=1; }
  done <<< "$1"
  return $bad
}

echo "== 사전 확인"
echo "   운영 트리: $(git -C "$APP" log --oneline -1)"
STATUS=$(git -C "$APP" status --porcelain) || { echo "   ✖ git status 를 읽지 못했다 — 멈춘다"; exit 2; }
DIRTY=$(printf '%s\n' "$STATUS" | grep -v -E '^(\?\? (api-test\.txt|jar))?$' || true)
[ -z "$DIRTY" ] || { echo "   ✖ 운영 트리에 커밋 안 된 변경이 있다 — 멈춘다:"; echo "$DIRTY"; exit 2; }
hashes_are "$BASE_WANT" || { echo "   ✖ 고칠 파일이 기준 판이 아니다 — 멈춘다(운영 판이 바뀌었으면 pins-qf10-repo.sh 로 새 블록을)"; exit 3; }
echo "   고칠 파일 $(printf '%s\n' "$BASE_WANT" | grep -c '=') 개 = 기준 판"
NEWF=0
while IFS= read -r l; do
  rel=${l##* }; [ -n "$rel" ] || continue
  [ -e "$APP/$rel" ] && { echo "   ✖ 새로 만들 파일이 이미 있다: $rel"; NEWF=1; }
done <<< "$SUMMARY_WANT"
[ "$NEWF" = "0" ] || exit 3
same "$CROUTES_WANT" "$(croutes)" || { echo "   ✖ 커뮤니티 라우트 표가 기준 판이 아니다 — 멈춘다"; exit 3; }
same "$CTOK_BASE" "$(ctok)" || { echo "   ✖ 커뮤니티 토큰 창구 표가 기준 판(셋)이 아니다 — 멈춘다"; exit 3; }
same "$TOKSETS_WANT" "$(toksets)" || { echo "   ✖ t-box 토큰 창구 집합이 기준 판이 아니다 — 멈춘다"; exit 3; }
same "$TROUTES_WANT" "$(troutes)" || { echo "   ✖ t-box 라우트 표 줄이 기준 판이 아니다 — 멈춘다"; exit 3; }
same "$ROUTES_N_WANT" "$(route_n)" || { echo "   ✖ t-box 라우트 줄 수가 기준 판($ROUTES_N_WANT)이 아니다 — 멈춘다"; exit 3; }
echo "   커뮤니티 라우트 표 · 토큰 창구 표($(ctok_n)) · t-box 토큰 창구 집합 · t-box 라우트 ${ROUTES_N_WANT}줄 = 기준 판 · 패치 안 파일 갈래 ${PATCHN}"
# ① 코드 → 마이그레이션 — DB 는 반영 전이 정상(반영 뒤여도 새 코드는 읽는다). 섞인 판·못 읽음이면 멈춘다
DBM=$(dbmark)
if same "$DB_MARK_OLD" "$DBM"; then DBSTATE=old; echo "   DB: Q:F-10 반영 전(scope 다섯 값) — 정상 순서(코드 → 마이그레이션)"
elif same "$DB_MARK_NEW" "$DBM"; then DBSTATE=new; echo "   DB: 이미 Q:F-10 반영 판(원자 집합) — 순서는 거꾸로였지만 새 코드는 둘 다 읽는다 · 계속"
else echo "   ✖ 운영 DB 가 Q:F-10 반영 전(Q:F-9 판)도 반영 뒤도 아니다(${DBM:0:120}) — Q:F-9 DB 가 먼저여야 한다 · 사람이 본다"; exit 3
fi
git -C "$APP" apply --check "$PATCH" || { echo "   ✖ git apply --check 실패 — 멈춘다"; exit 3; }
echo "   git apply --check: ok (${GOT:0:16}) · 파일 $(printf '%s\n' "$PATHS" | wc -l | tr -d ' ')개(새 파일 ${NEW_N})"
BUSY=$(psq "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND actor_name NOT LIKE 'qa\_%' AND actor_name NOT LIKE 'claude\_%'")
echo "   최근 10분 검토 계정이 아닌 사람의 감사 행: $BUSY (본 실행은 0 이어야 — 쓰는 사람 밑에서 코드를 바꾸지 않는다)"
if [ "$DRY" -eq 1 ]; then echo "== [dry] 여기까지. 다음: 패치 → 확인(실패하면 되돌림) → 커밋·발행 → apply-qf10-db --migrate(바로 이어서)"; exit 0; fi
same "0" "$BUSY" || { echo "   ✖ 누군가 사이트를 쓰는 중이거나 읽지 못했다 — 미룬다"; exit 3; }
LOG0=$(stat -c %s "$APPLOG" 2>/dev/null || echo "(none)")
[[ "$LOG0" =~ ^[0-9]+$ ]] || { echo "   ✖ $APPLOG 크기를 읽지 못했다(adm 그룹?) — 멈춘다"; exit 3; }

rollback() {   # 한 번만 돈다 — 되돌리는 동안의 오류·신호는 무시
  trap - ERR; trap '' INT TERM HUP; set +e
  echo "== ✖ $1 — 되돌린다(git apply -R)."
  git -C "$APP" apply -R "$PATCH" || echo "   ✖ 되돌리기 실패 — 사람이 봐야 한다"
  sleep 3
  if hashes_are "$BASE_WANT"; then echo "   ✔ 되돌린 뒤 고친 파일 = 기준 판"; else echo "   ✖ 되돌린 뒤에도 기준 판이 아니다 — 사람이 봐야 한다"; fi
  LEFT=0
  while IFS= read -r l; do
    rel=${l##* }; [ -n "$rel" ] || continue
    [ -e "$APP/$rel" ] && { echo "   ✖ 새 파일이 남았다: $rel"; LEFT=1; }
  done <<< "$SUMMARY_WANT"
  [ "$LEFT" = "0" ] && echo "   ✔ 새 파일 ${NEW_N}개 없음"
  same "$ROUTES_N_WANT" "$(route_n)" && same "$TROUTES_WANT" "$(troutes)" && same "$TOKSETS_WANT" "$(toksets)" && same "$CROUTES_WANT" "$(croutes)" && same "$CTOK_BASE" "$(ctok)" \
    && echo "   ✔ 라우트 기준 판으로(t-box ${ROUTES_N_WANT}줄 · 표 줄 · 토큰 창구 · 커뮤니티 표 · 커뮤니티 토큰 창구 셋)"
  if [ "$DBSTATE" = "new" ]; then
    echo "   ✖ DB 는 Q:F-10 반영 판이다 — 옛 코드는 community.read+community.write 를 몰라 커뮤니티 쓰기 토큰이 403. apply-qf10-db 머리 「되돌리기」 로 DB 도 되돌릴지 사람이 정한다"
  else
    echo "   · DB 는 반영 전 그대로다(이 스크립트는 DB 를 건드리지 않는다) — 옛 코드 + 옛 DB = 반영 전 운영 그대로"
  fi
  exit 4
}
echo "== 1. 패치"
trap 'rollback "중단(명령 오류 또는 신호)"' ERR INT TERM HUP
git -C "$APP" apply --whitespace=nowarn "$PATCH"
sleep 3   # opcache.revalidate_freq=2 — 바뀐 파일을 FPM 이 다시 읽을 때까지
echo "== 2. 확인"
ok=1
chk() { if same "$2" "$3"; then echo "   ✔ $1 (${3:0:24})"; else echo "   ✖ $1 (기대 ${2:0:24} · 실제 ${3:0:24})"; ok=0; fi; }
if hashes_are "$CODE_WANT"; then chk "바뀐·새 파일 ${FILES_N}개 = 검토 받은 판" "ok" "ok"; else chk "바뀐·새 파일 ${FILES_N}개 = 검토 받은 판" "ok" "다름"; fi
nphp=0; nbad=0
while IFS= read -r f; do
  case "$f" in *.php) nphp=$((nphp+1)); php -l "$APP/$f" >/dev/null 2>&1 || { echo "   ✖ php -l $f"; nbad=$((nbad+1)); } ;; esac
done <<< "$PATHS"
chk "바뀐 PHP ${nphp}개 php -l 실패" "0" "$nbad"
[ "$nphp" -gt 0 ] || { echo "   ✖ php -l 로 본 파일이 0 — 확인이 아니다"; ok=0; }
nsh=0; nbad=0
while IFS= read -r f; do
  case "$f" in *.sh) nsh=$((nsh+1)); bash -n "$APP/$f" || nbad=$((nbad+1)) ;; esac
done <<< "$PATHS"
chk "바뀐 셸 ${nsh}개 bash -n 실패" "0" "$nbad"
T=$(mktemp -d)   # 정리는 cleanup(EXIT)
if out=$(bash "$APP/scripts/security_lint.sh" "$APP" 2>&1); then rc=0; else rc=$?; fi
chk "security_lint(저장소 전체) — 금지 패턴 0" "0 1" "$rc $( (grep -c '결과: 통과' <<< "$out" || true) | tr -d ' ')"
[ "$rc" = "0" ] || printf '%s\n' "$out" | tail -8 | sed 's/^/     | /'
chk "t-box 라우트 줄 수 그대로" "$ROUTES_N_WANT" "$(route_n)"
chk "t-box 라우트 표 줄 그대로" "$TROUTES_WANT" "$(troutes)"
chk "t-box 토큰 창구 집합 셋 그대로" "$TOKSETS_WANT" "$(toksets)"
chk "커뮤니티 라우트 표 줄 그대로" "$CROUTES_WANT" "$(croutes)"
chk "커뮤니티 토큰 창구 표 = 검토 받은 판(여섯 — 검색 · 목록 · 공간 더함)" "$CTOK_WANT $(printf 6)" "$(ctok) $(ctok_n)"
# 파일 읽기만(요청 0 · 감사 0) — 이 묶음의 자리가 반영된 트리에 있나
chk "Authz — 원자 표 · 정규화 · 판정 한 곳 · 옛 범위 상수 0" "1 1 1 0" \
  "$(cnt "public const TOKEN_SCOPE_ATOMS = ['read', 'upload', 'delete', 'community.read', 'community.write', 'session.open'];" "$APP/src/Authz.php") $(cnt 'public static function normalizeScope(array|string $scope): string' "$APP/src/Authz.php") $(cnt 'public static function tokenScopeAllows(string $scope, string $policy): bool' "$APP/src/Authz.php") $(cnte 'public const (FILE_)?TOKEN_SCOPES = ' "$APP/src/Authz.php")"
chk "Auth — 토큰 조회 한 곳의 IP 판정(ip_ok) · 401 token_ip_mismatch" "1 1" \
  "$(cnt "(bound_ip IS NULL OR COALESCE(bound_ip = CAST(:cur_ip AS inet), false)) AS ip_ok" "$APP/src/Auth.php") $(cnt "'code' => 'token_ip_mismatch'], 401);" "$APP/src/Auth.php")"
chk "변경 이력 — 제목만 모양 · 셸은 Authz::canAdmin 으로 가른다" "1 1" \
  "$(cnt 'public static function headlines(): array' "$APP/src/Changelog.php") $(cnt '$shellChangelog = $shellChangelogFull ? Changelog::entries() : Changelog::headlines();' "$APP/src/views/shell_bottom.php")"
# HTTP — 화면 둘 + 미로그인 창구 둘 + 셸(변경 이력이 들어가는 화면)은 쿠키 없이 302
chk "런처 / 200" "200" "$(code "$L8082/")"
chk "t-box 로그인 화면 200" "200" "$(code "$L8082/tbox/login")"
chk "미로그인 /tbox/api/me 401" "401" "$(code "$L8082/tbox/api/me")"
chk "미로그인·헤더 없음 /community/api/search?q=x 401" "401" "$(code "$L8082/community/api/search?q=x")"
R302=$(redir "$L8082/tbox/")
chk "쿠키 없는 /tbox/ → 302(셸 · 변경 이력은 로그인 뒤)" "302" "${R302%% *}"
LOGADD=$(tail -c +"$((LOG0 + 1))" "$APPLOG" 2>/dev/null || true)
chk "app.log — 이 실행 동안 더해진 uncaught · Fatal 0" "0" "$( (grep -cE 'uncaught|Fatal error' <<< "$LOGADD" || true) | tr -d ' ')"
if [ "$ok" -ne 1 ]; then rollback "확인 실패"; fi
trap - ERR INT TERM HUP
cat <<'NEXT'
== 끝 — 이제 사용자가 할 것(순서대로 · ② 와 ③ 사이를 짧게 — 그 사이 묶음 발급·커뮤니티 쓰기 발급은 500)
   ① 파일 지정 커밋(아래 줄 — 이 스크립트가 반영한 파일 목록 그대로 · git add -A 금지 · .env 가 보이면 멈춘다)
   ② 발행: scripts/release.sh minor "토큰 하나로 · 비서에서 커뮤니티 검색" "프로그램 토큰을 발급할 때 범위를 여러 개 골라 한 토큰에 묶을 수 있습니다(파일 · 커뮤니티 읽기·쓰기 · 이 PC 로그인). AI 비서는 토큰 하나로 파일과 Ctrl+B 와 커뮤니티 검색(Ctrl+F)을 씁니다 · 변경 이력의 상세 내용은 관리자에게만 보이고 다른 사용자에게는 제목만 보입니다"
   ③ DB(root): K=<꺼낸 폴더> — git -C /var/www/qaredi/tbox-v2 show HEAD:db/migrations/2026-10-13_api_tokens_multi_scope.sql > "$K/qf10.sql"
      sudo bash "$K/review-scripts/apply-qf10-db-2026-09-29.sh" --dry-run "$K/qf10.sql"  → 「코드 먼저」 통과 · 반영 뒤 예상 행 모양 확인
      sudo bash "$K/review-scripts/apply-qf10-db-2026-09-29.sh" --migrate "$K/qf10.sql"  → 확인 ✔ 전부
   ④ PC 쪽: 비서(aa) git pull origin wip/qf10 → 비서 다시 켜기 · t-box 내 계정 → 프로그램 토큰에서 토큰 **하나** 새로 발급(쓸 범위 체크) →
      비서 「API 토큰 변경」 에 넣기 → Ctrl+B · Ctrl+F · 파일 올리기 한 번씩 · 옛 토큰(파일용 · 커뮤니티 쓰기용 · 이 PC 로그인 전용) 회수
   ⑤ 관문 ③ 자기 공격(사람): 올리기만 고르기(400) · 묶음 토큰으로 지우기(범위 밖 403) · 다른 회선에서 묶음 토큰(401 token_ip_mismatch) ·
      일반 계정으로 변경 이력 열기(제목만)
NEXT
echo "   ── ① 커밋 명령(그대로 붙여 넣는다):"
printf '      cd %s && git add --' "$APP"; printf ' %q' $PATHS; printf '\n'
echo "      git -C $APP commit -m 'Qaredi Q:F-10: 토큰 하나에 범위 여럿 — 원자 묶음 · 커뮤니티 검색·읽기 창구를 토큰에 · MCP 검색 도구 · 변경 이력 상세는 관리자만'"

#!/usr/bin/env bash
# review-scripts/apply-g0-repo-2026-09-28.sh — G-0 t-box 「개인」 등급 · 사람 지정 권한 · 권한 요청(PRD F-73)의 **저장소 쪽**을 운영 트리에 반영(changkwon 실행)
#   근거: 지시서 ~/.local/share/qaredi-dev/prompt-g0-private-grants.txt · 구현 기록 docs/g0-implementation.md · ARCHITECTURE 5.36
#   본보기: scripts/apply-7d3-repo-2026-09-27.sh(라우트 줄을 더하고 보안 핵심에 손대는 판의 통과 틀 — PATCH_WANT 고정 · 0700 사본 · git 이 읽은 경로·요약 ·
#           rename/copy 거부 · 빈·모르는 인자 거부 · 되돌림 함수 + ERR·INT·TERM·HUP 덫(set -E 없이) · 개수는 패치 파일 안에서만 · 두 단계 되돌림 ·
#           확인 요청이 운영 감사에 행을 남기지 않게) · scripts/apply-7d4-repo-2026-09-27.sh(app.log 바이트 자리 대조).
#   검토 동안은 복제본 review-scripts/ 에(태그 review-base/g0 안), 검토 뒤 Qaredi scripts/ 로 옮긴다.
#   ★ 순서: **DB 먼저**(apply-g0-db-2026-09-28.sh --migrate). 이 스크립트는 표 둘·개인 줄 넷·알림 칸이 없으면 시작하지 않는다(종료 3) —
#     새 코드는 목록 조건절부터 access_grants 를 읽어, 표 없이 나가면 파일 목록·트리·받기가 500 이다. nginx·/etc 변경 0 · root 불필요.
#   ★ 이 패치는 판정·보안 핵심을 **바꾼다**(지시서 범위 — 판정 함수·목록 SQL·폴더 불변): src/Authz.php · src/Audit.php(문장 표) · src/Http.php(문장 한 줄) ·
#     src/community/CommunityAuthz.php · src/community/routes.php(파일 붙이기 판정) · db/**(저장소 파일 — DB 자체는 위 DB 스크립트) · public/index.php(라우트 **열두 줄**
#     더하기만 · 지운 줄 0). 그래서 이 스크립트는 ① 패치 파일 **안에서** 여섯 파일의 지운/더한 줄 수를 세고(CORE_WANT) ② 반영 전후 토큰 창구 집합 · 강제 절차 허용 목록 ·
#     커뮤니티 라우트 표가 한 글자도 같고 t-box 라우트 표는 그 열두 줄만 늘었는지 본다(새 창구는 세션 전용 — 토큰으로는 못 부른다).
#     「고치지 않는 것」 Auth · Db · Passkey · Storage · Upload · bootstrap · deploy/** · login_page · 커뮤니티 search · comments_create 는 패치 밖이다(건드리면 멈춘다).
#     커뮤니티 JS 를 바꾼다(editor · database · templates) — 부품 버전을 찍은 판이다(--check 로 본다).
#   범위: 아래 고정 값 블록 — 기준 판 = 운영 HEAD c2b4d76 위의 review-base/g0. 운영이 나아갔으면(사용감 라운드 1 이 먼저 들어가면 — 겹치는 파일 app.js · shell_top.php ·
#     editor.js · 문서) 사전 확인 「고칠 파일이 기준 판이 아니다」(종료 3)로 멈춘다 — 그게 맞는 동작이다. 다시 찍기: 복제본에서 운영 HEAD 를 합치고(커뮤니티 JS 는
#     import 버전을 다시 찍는다) `bash review-scripts/pins-g0-repo.sh <운영 sha> <합친 판> [패치]` → 찍힌 블록으로 아래 블록(표식 줄 포함)을 통째로 바꾼다.
#   사용: bash scripts/apply-g0-repo-2026-09-28.sh <PATCH> <SHA256> [--dry-run]
#     <SHA256> 은 이 스크립트 안의 PATCH_WANT 와도 같아야 한다(둘 중 하나라도 다르면 멈춘다).
#   확인(반영 직후 **opcache 2초 창**을 감안해 3초 뒤에): 기준 판·검토 판 sha256 · php -l · bash -n · py_compile · 커뮤니티 부품 버전(--check) ·
#         t-box 라우트 +12 줄(그 줄들 글자 그대로) · 토큰 창구 집합·강제 절차 목록·커뮤니티 라우트 그대로 · 화면 JS 기본 창 호출 0 ·
#         파일 읽기(개인 등급 순위 · 짝 SQL 한 벌 · 잠금 재확인 도우미 · 문장 표 네 행동) · HTTP(미로그인만 — 런처 `/` 200 · `/tbox/login` 200 · 자산 둘 200 ·
#         `/community/` 302) · app.log 에 이 실행 동안 더해진 uncaught 0(바이트 자리로). **미로그인 `/tbox/api/…` 는 보내지 않는다**(운영 감사에 auth.required 행).
#   되돌리기(두 단계 — ① 라우트 표가 든 public/index.php 먼저 → 3초(opcache 가 옛 표를 읽을 때까지) → ② 나머지 · 핸들러 파일은 마지막. 확인 실패 때의 rollback 도 같은 순서):
#     A=/var/www/qaredi/tbox-v2; git -C $A apply -R --include=public/index.php <PATCH> && sleep 3 \
#       && git -C $A apply -R --exclude=public/index.php --exclude='src/handlers/*' <PATCH> && git -C $A apply -R --include='src/handlers/*' <PATCH>
#     (DB 의 표·줄은 남아도 옛 코드에 무해 — 옛 판정은 can_read 참인 줄만 쓴다. **개인 등급 행이 생긴 뒤**에는 코드만 되돌리면 그 행이 옛 코드에서 아무도 못 보는
#      파일이 된다 — 넓은 쪽으로 새지는 않는다. DB 까지 되돌리려면 DB 스크립트 머리의 조건(개인 행 0)을 먼저 본다)
#   반영 뒤 사람 확인(claude_ 계정): claude_employee01 이 개인 폴더를 만들어 claude_employee02 에게 보기 권한 → employee02 의 「공유받음」에 보임 · claude_manager01 은
#     주소로 열면 요청 화면 → 요청 → employee01 의 사이드바 「권한 요청 1」 → 허락 → manager01 알림. 이 스크립트의 HTTP 확인은 미로그인이라 화면 JS 를 지나지 않는다.
#   연습 결과(2026-09-28 15:2x · claude — 이 고정 값 그대로(찍은 판 2d394b6 · PATCH_WANT ba223567…) · 경로 넷(APP · L8082 · DB · APPLOG = 모의 서버 로그)만 바꾼 사본 ·
#     모의 운영 트리 = g0w 를 c2b4d76 으로 clone · 모의 DB tbox_g0_reh_test · 모의 인스턴스 php -S 127.0.0.1:8415 · 연습 틀 wk-g0/rehearse.sh — 운영 트리·8082·tbox_re 무접촉):
#     ① DB 전 --dry-run → 3 「DB 먼저」 ② sha 틀림 · 모르는 인자(--apply) · 빈 인자 → 1 ③ --dry-run → 0(고칠 파일 53 = 기준 판 · 라우트 99줄 · 줄 모양 「0 21 1 337 1 33 1 1 2 19 1 42」 ·
#     apply --check ok · 파일 64(새 11)) · 트리 차이 0 ④ 추적 안 된 파일 → 2 ⑤ 확인 실패(인스턴스 내림 — HTTP 확인 다섯 ✖) → 두 단계 되돌림 4 · 고친 파일 = 기준 판 · 새 파일 11개 없음 ·
#     라우트 그대로 · 트리 차이 0 ⑥ 본 실행 → 0 · 확인 ✔ 19 / ✖ 0 · 반영 판 64 파일 내용 = 검토 판 ⑦ 머리 「되돌리기」 줄 그대로 → 기준 판 차이 0 · 옛 코드 + 새 DB 200 ⑧ 다시 본 실행 → 0 ·
#     반영 직후 다시 → 2(트리 더러움) · 끝에 되돌림 → 기준 판 차이 0. 경우 내내 0.2초 요청 줄기(미로그인 로그인 화면 · 런처 · app.js · 커뮤니티) 304회 중 500 1 —
#     git apply 가 파일을 차례로 바꾸는 순간 라우터의 라우트 표 검사가 「핸들러 파일이 없다」로 한 요청을 거절(반영·되돌림 모두 같은 창 · 이 묶음만의 일이 아니다 —
#     쓰는 사람이 없는 때(BUSY 확인)에 한다). 로그 wk-g0/rehearse-1.log.
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
[ "$(id -un)" = "changkwon" ] || { echo "운영 트리의 주인(changkwon)으로 실행하세요"; exit 1; }

# @@HASHES-BEGIN@@
# 반영할 패치 = git diff c2b4d76 <검토 태그> -- . ':!review-scripts' — 찍은 판 2d394b6 과 내용이 같다(그 뒤 커밋은 review-scripts/ 만 바꾼다) ·
#   pins-g0-repo.sh 가 찍음 · 인자로 받은 sha256 과 **둘 다** 같아야 한다
PATCH_WANT=ba2235678c3956e6961fc4d925aa0eda78183b4e583077ab86c03e430c23da17
# 고치는 파일의 반영 전 기준 판(c2b4d76) — 다르면 시작하지 않고, 되돌린 뒤 이 판으로 돌아왔는지 본다
BASE_WANT="API.md=bcf1bb997149621927245c7cc5216816528337732bcce21083f7092e08ea974a
ARCHITECTURE.md=660675361c15c1c03b8047fffa6123e142799c9f7c1dce694a5538fd18fd7789
DESIGN.md=009d146f56fed54701f4a6b931e1afe265f8079214796fe850daea26eae315b9
PRD.md=35b7b91636935e6e73330e8afc3b1beb76bc59d5d8f27b9a37d3c60393fa8db0
SECURITY.md=6392b2dec4ed4d2460ade67967b6116a0fe844e7c0f935c461b1ae66859d7986
db/community/schema.sql=8088b9d9d7e203ec699f77852f18fe18e6477771b3a9eaddd8713983efb837b4
db/grants.sql=b36f91012a84421e83c90787b5dfb3c2c13c45978a7d53c3a5e7d59eba30600a
db/schema.sql=3de95b55ece94e8a32dc58f4ff27ea7560142b45f42430329f4eafa506a45ecf
db/seed.sql=6ad09a5102c5f234420e6519d186c8808f4d81fdf760cf9943ba60028a4f8235
public/assets/app.css=548c3cccdaf66c7f7874ef02e35f8a4f52572c6dc205375ea344e2e24e72cc1c
public/assets/app.js=39d8d14a2dbf7859cd9115b5e4405e1822460f023e952dda7c6330ed52e724c3
public/assets/community/database.js=b4090f33387171d0fe51d37bec48473b63daaab51adf68bd5fe8e594c526850b
public/assets/community/editor.js=e714729b4a2103973ecbf17cdb349c0cf4931990ede548a8780ba31062c59c3e
public/assets/community/templates.js=3ccec594e1a4373d8c4760ef64b1ad4464efbbb59d7fe14ea76650ae7d06d894
public/index.php=122274dcf1054854b63e62e6194956638b3625745e62a15b484f6975440a3764
scripts/audit_cli.php=0656f07efb07d5a7c83f3a2a67e380e3df79d29181cd0046470728cbe3f83342
src/Audit.php=aeb10deadf127c77ba8dc8c44b1b9b3c19f76c13ecfaa77c308b5f9891813979
src/Authz.php=a529229741b9fd418fa2b4ff24747709908d84b742aa9ba113e3223adf259817
src/Http.php=efa9c248d9f37ad01e3aa88496368556a16715317fbea9f6654af700de0ecd9c
src/community/CommunityAuthz.php=6e252c26f9037974e0f9e14c01e524886b2cccf82cdc6f966fd7ea9728b7b059
src/community/handlers/boards_create.php=669e524a12ec861c8a1a9ad370279a13c0f87f379b39d4064659dc02c1cef830
src/community/handlers/notifications_list.php=5c0129e323779d291ad536861dff167d0189315d2fa57e52628fcd6a6c15ebf3
src/community/handlers/pages_update.php=6077a764e08be1a5da60a9b5f0e5186b62bd432f3d53862003bc4825be13a8fd
src/community/routes.php=cd77e54fe3bff9a77dbbf584c6b510248a81151424e3df6b2aff016d91374861
src/handlers/_download_common.php=de23b89eeabe6d69d827ec9515f1a9cd5fb485c4a0c6715c015096504a67f9f7
src/handlers/_upload_common.php=23d49b68d38bf69e0e76dc452cac93cea6f0feae906604f3aa0cf07b6b4877d1
src/handlers/admin_permissions_get.php=e094d30526ccc17c3f8a7e7b8c8b5692178fafbe241daf4ffdff8d0464fd40d3
src/handlers/admin_permissions_update.php=984509c32bca79d08cbbcae12185eed487ae303e81d79e820070639426f2b9c7
src/handlers/audit_list.php=b1ca1a41cd1bfab132759c348590b70cd8314ac672dcd52c13897655015c7e5c
src/handlers/favorites_files.php=ab146bb24f09b86c4f7c23566d3a75dfed102c21c98197d56ebbac8fc92cdb69
src/handlers/favorites_folders.php=1f09525a7540817b4144dda44e2ee08b723c3d14ef6987101d5cf76faf748dfd
src/handlers/files_delete.php=33f2c233cfdeccf4666492f418f6185bdfbd33afd5a9f656cc7c211392e81f31
src/handlers/files_exec_download.php=b8e6c7c2adc91fb559413c113c7736e2a10eff93650131627e3e70e509d31d30
src/handlers/files_fetch.php=bb4226510cb0195e362912ec6eee0dca069517197c1562cd16b963b472859ca9
src/handlers/files_list.php=fd56ac26ec899c75a6981ddb3d89977a32910dd29964b973aa04eb81b40d265b
src/handlers/files_preview.php=1d1fc558e468ab3d4c7b076e479adef7aebea531658db1a261cb1f18fa28d7b0
src/handlers/files_preview_raw.php=c0c5415eab7cc732091eb46bdcf53238b5a43c84d7ca6ff2504c3ca9063fb2da
src/handlers/files_recent.php=b6d95b49f56a8a2efd50369a6db3b4ac43a98b5cfe481726330b92c901a03390
src/handlers/files_update.php=bccad465b3d862526584b2682d49334c3820d14d00b2e60f7e15c787b04029d8
src/handlers/folders_create.php=bd5b3a1b667d8e905cb0c7f300dd8553b3201f51cf2f853ccb2a03a36f557255
src/handlers/folders_delete.php=7bfc27f1fe0390997c5e95312666f5f386df2bf5b4873c425e09db199b316501
src/handlers/folders_list.php=cc24ffcd645868e0401f9ee3c7033cfab03db68ea91c9d7a0c0de0b7323f59ca
src/handlers/folders_update.php=8f839066814993bedc62997f438c2523c4952a28c06e5dfdbd39ae629e8979d4
src/handlers/home.php=5835f836c4044fa7bc99ee4ca7431471138f5417a0a86ba3892fbeb56c5a02e9
src/handlers/me.php=b57e5c46a6cbbbfc29ff4a3b30a1eff23f25c6b7d7189af9a5c203f73a79cf21
src/handlers/org_permissions.php=aa159ebd093c64bd25c424ce5801c607488540eadc4874cf9a3bd8fdc11d1d35
src/handlers/share_create.php=76a44c62079cfd48257839a3f04bfb83caaed2c0af46ed94ccbcaf65c1c640c4
src/handlers/share_download.php=1aba13a50cc3c1d792d456f11f2ce55d25bedbebac7f57a9483c9582c151b8cd
src/help/screens.php=315e2441e6305288f64a13613eb16dc284ddb60a3f20b5bd81a015f3802af0f8
src/views/shell_top.php=b1b9e41bc0149c1f5866f64d0c2ea4f69e77f8b8c2d037800fe5b5ca043c0fe4
tests/catalog.json=ee3f3aabc7c161ffde4dca4d9bfed55b076b7b05dcfc50c54bf192b5e7fef7ae
wiki/tbox/guide-roles.json=ea81a6615db08a88612acaa4ecc153a4668e5e38321c4a189a3837ef0f6a9049
wiki/tbox/index.json=3cdca39563bba9b53f5b74f3353569894984eed816c4bd8c20abf0584c1c8fe0"
# 반영 뒤 검토 받은 판(바뀐·새 파일 전부 64)
CODE_WANT="API.md=fcf4543602d02d0437684536eeaf71cfbf15b221d616bcd288366981015b6238
ARCHITECTURE.md=b85aa50f0757bae99911e4778b5c30b24daa4b4ce017a5e46cb1e68484913941
DESIGN.md=5eaf4a62a52a07db558d7fa596bb033c9d13dd1dfdfc104028876f88459a6ab7
PRD.md=68d589e28be2b9a70da6eadc60647684582a44b27c4b78a0a82c5eb646e0f13c
SECURITY.md=b1841a52e3687892fb2188e469cefd09e2dba578757328892eb474e379ab6834
db/community/schema.sql=3755b6afd7b2b9055e07aa3511a69abf5351a37113ce5f270f370ed3d9de6360
db/grants.sql=0fb34dab3c71502088ec0145816a5ed3e88c94bad81127fec5167c1654be8b1e
db/migrations/2026-10-10_access_grants.sql=7c78c49ebad9ac26643cb35b69140bc5c43cb2c213dccb1e12a9f6aa53481093
db/schema.sql=193b980140a736c6e55946987830b3a9929510dd933aae66b3cd8d9691483520
db/seed.sql=6e88f201d3efa7733a9506abcd208321346ae5b4702eca414b343821da817bb0
docs/g0-implementation.md=57b50f58610995701329f15aa244e53e5e6cb3aa62a78fba873d5ac542cc0934
public/assets/app.css=de7d99143e02463b633988a6e73de071dd48291bcc5d9d3c8a158029a0d9f35a
public/assets/app.js=d0c6ba08d51817b56886c4776e8e1c4d602d077604f55f8ada09db72287bda89
public/assets/community/database.js=d892c157da8165e6a8530e942924fe90206164017fa23045bdb6548f3bb20113
public/assets/community/editor.js=c21b24fa554d44ca2331a5511ac03ef3d3216d8e04c563e8d5ddf8dc4bd72e15
public/assets/community/templates.js=48848ef25b30cadfcdf879cdae6c14bdfae0c4ea90ceadecd6e009c540db3648
public/index.php=ab16b28fd4bf3f4a5384caa465dc56071063047c4a156524dcd8075e957e5166
scripts/audit_cli.php=27042f95d46cf0e9a0754fc4a0616a6b5384c9806cb47d72b45a93f95fbfa358
src/Audit.php=c0358f053c61ea116a5432576536675f0481a92490e16427468eb1e8b4a8ab1e
src/Authz.php=0a508d1be40e79a6a8fbf8143f12e7adc390db0e3da3a4960b3158c536655586
src/Http.php=591a92108d4fe21e31bab32d0f4e44b4a670eafddb1b4b5759b01caa9c657e98
src/community/CommunityAuthz.php=a1ed7ab08c4d61623a06311933fe51aa5cb3a270beb37fbcc5b00aed5f840836
src/community/handlers/boards_create.php=23d6bec79470534865b51565c572738d220da4a8e965ba0703869e4273a02a77
src/community/handlers/notifications_list.php=3133c2b9bccd12660b4adc33ff513b19968b43b9371b0571b105c59eaae26d91
src/community/handlers/pages_update.php=12b1bc0792c8cee3e36f20dd8db51881b622c6ae6ef9db5ad1ceb2d67982cfe5
src/community/routes.php=13c7ee92cba834a4320c7e0bcc651927a27f692a0b92ade959f78609384f1b07
src/handlers/_access_common.php=088b5874478cb379793a9e41bd490f301249d2ec9a1051d1a57ea119bc439dcf
src/handlers/_download_common.php=a98c2c5bfdaeb7cd90db4fa3ebf04c82f567a80d7e732e0f5ba6e8c09cbc30d3
src/handlers/_folder_lock.php=841e4606f0a0267b3961c36a2890cef577f99b445b2b0126a7aba5cb083f5e55
src/handlers/_folder_paths.php=2d35234034473341b86fecc9f2d5a1874aa93c8930f80e9a3d390fd5ebae0a85
src/handlers/_upload_common.php=52c2e77086ef941f07c6b357d5b7ca7c621ab9dd6659207b3b935c60b111c696
src/handlers/access_grants.php=f5d8e9774304c6f0f89c6286fe78649b84f24dd120499c8482ae27b1069396f4
src/handlers/access_mine.php=adcd5e2f1290c92aaa2eb1f39ba8ab245601880d9d47814a3b23de08c9b0dca2
src/handlers/access_people.php=cd7b60b597d75bbff2d4a38e10764250361ac454c868b01cf853fe35d8f4f377
src/handlers/access_requests.php=0650429751870d1f9c5f492cdeadb93ca53eb55fbe55a55bf5ddbe6455a41153
src/handlers/admin_permissions_get.php=bc85c8c2a245bc52a87d3f017861a5acf7552a6441cc41c71eb73a1d99f1b8aa
src/handlers/admin_permissions_update.php=d353bf790fe629395968442f6a10ed10f0212d187484cb42ef1dc7382356297c
src/handlers/audit_list.php=50dd486cecc6537bdc8ec29c73987ca7c77c08b277469e42394baf70e0a0e876
src/handlers/favorites_files.php=e3fd84d57fd0ab0784508601e444c68abdcbf3d1031d0106e0d9ffaa9d082b79
src/handlers/favorites_folders.php=6775dc5a4ba62232af990fd4603841f87851f49677ec71240883bab5d4b8ead0
src/handlers/files_delete.php=6e30e820e42f40d27c51944d4039841fb019fad44f91ebf234479f6dde88deec
src/handlers/files_exec_download.php=867d65844decf0b3c684177b1709636cc38258afd100fbbd76d7c1041d50d74a
src/handlers/files_fetch.php=7b91303a46bd32e88a85e77b1932210666019f660c1b1ba706094e2c74a19294
src/handlers/files_list.php=52e09d06e6c03cc8bf314c4fbb3aca8c06b597a0c2cb67b9885d9f79c8a3ba15
src/handlers/files_preview.php=896e92a1ab2b7fdf610db6a6ea37423b997b4e9db345d873576a312fe2bb1d65
src/handlers/files_preview_raw.php=30ac6fbeefa7669c2578f0da72da3d380840cebd185e9b98e3804edf30b4ac7a
src/handlers/files_recent.php=c384ee459f347cfcb4a5d3fd7e33e070417140578ebdb980bb81db98a1dd453b
src/handlers/files_update.php=ca73db175ca0c30839241216bbad3b848d53e17bb7bdb5ed3b3f6f893d7954bb
src/handlers/folders_create.php=1a7b4e7529a3ed9fac33c0a51f787260109f6b49afa3477ebaaea0b5ca6f93f3
src/handlers/folders_delete.php=4bfe28a9768a82597ba32959641ea737fdb96fba8a44f1463286ee8116610485
src/handlers/folders_list.php=c24fa6fd4ea782048ee61822365dd0462c3645c2306e0355f6ff1d5216e15c66
src/handlers/folders_update.php=53f0015641696b036ac03deb3d702ecb48c538bb2d564becc7e9298ae65148a2
src/handlers/home.php=5e1a4ffb5964aa38e70e59064491e0258a87ea5cd6402026df3f256ccc415327
src/handlers/me.php=902b1481a4a10c7e6b64206e45519534f0524d85332851df23a797520470533e
src/handlers/org_permissions.php=4abfbf0a865a2132d9087173f623d04a3a03d790bbf696e795bcc1ef267434f4
src/handlers/share_create.php=e4c9446a916c664366fe892fc27e685234197daea7aff0976432141afe808b23
src/handlers/share_download.php=fae9d67a9b14cb21d010d248782db0a092b9366183be74cf76c4315e5fdd3731
src/help/screens.php=81733b1f1909ffc2712f9e7190e75b2dfd6b4843d05a2a7fb18ec418cf8fdf62
src/views/shell_top.php=f7806e9268d0a62d99948820e157b19fcc0573e952150b170ac945cdeca57fb4
tests/browser/g0_private_grants.py=de43eb76575e22009b1797ebdab02540e33a99450fb72857c003cea5c31e2a35
tests/catalog.json=29d487e277939dacb7fcbf36701fcedeb2c1b279fedd95aef5d2f38386a10fc0
tests/g0_private_grants.sh=5bacfb49c7d3ed1fe0137b13472fc7a2a77731876f297d9e18818058c5b25b96
wiki/tbox/guide-roles.json=a1958551455181e47678c9148078c15e7ee15a3aa9cb6b3981d07742ca0d2f29
wiki/tbox/index.json=8392a471630801670815f500fbb22572fb6010bfdc269b73d100cf990fd9a77f"
FILES_N=64
# 새로 만드는 파일의 git 요약 줄(모드 포함) — 이것 말고는 요약이 없어야 한다(지우기·이름 바꾸기·복사·모드 바꾸기 0)
SUMMARY_WANT=" create mode 100644 db/migrations/2026-10-10_access_grants.sql
 create mode 100644 docs/g0-implementation.md
 create mode 100644 src/handlers/_access_common.php
 create mode 100644 src/handlers/_folder_lock.php
 create mode 100644 src/handlers/_folder_paths.php
 create mode 100644 src/handlers/access_grants.php
 create mode 100644 src/handlers/access_mine.php
 create mode 100644 src/handlers/access_people.php
 create mode 100644 src/handlers/access_requests.php
 create mode 100644 tests/browser/g0_private_grants.py
 create mode 100644 tests/g0_private_grants.sh"
# 라우트 — 토큰 창구 집합 셋 · 강제 절차 허용 목록 · 커뮤니티 라우트 표 줄(셋 다 반영 전후 같아야) · t-box 라우트 표 줄(반영 전 · 뒤)
TOKSETS_WANT=29f5acdfd5fbb402e18f332ff0d224a8ec1a493fea6d28b86992798ec0eb61e6
GATE_WANT=21418aa9033cec070821f00fad010b3b685149685823edd8eee5b1120d085989
CROUTES_WANT=ca16d5b97e4a7ff9b3d79afb27b469bece4a07a207556227b0e8c7f00f2d505a
TROUTES_BASE_WANT=cdafde0f6a395b73ff4681337dc3ab03d2695fef7c2702cd192e04a24bc7ea11
TROUTES_NEW_WANT=94e1183300f875e3cec696334e1f7ba6fe4e1b8112b6cc2f3ca36bf9dc87bb4d
# 더하는 라우트 열두 줄(글자 그대로 · 차례대로)의 sha256 · 줄 수
ROUTES_ADD_WANT=2fea7e621c934770d6f188dd25bb3d377f3b0478c649157648e1959fa05f5b59
ROUTES_ADD_N=12
# 패치 파일 안에서 센 지운/더한 줄 — index · Authz · Audit · Http · CommunityAuthz · 커뮤니티 routes
CORE_WANT="0 21 1 337 1 33 1 1 2 19 1 42"
# @@HASHES-END@@

# 빈 값끼리·(none)끼리 같다고 통과하지 않는다
same() { [ -n "$2" ] && [ "$1" = "$2" ] && [ "$2" != "(none)" ]; }
[ -f "$PATCH" ] && [ ! -L "$PATCH" ] || { echo "✖ 패치 파일 없음(또는 링크): $PATCH"; exit 1; }
PATCH=$(readlink -f "$PATCH")
GOT=$(sha256sum "$PATCH" | cut -d' ' -f1)
same "$WANT" "$GOT" || { echo "✖ 패치 sha256 이 알려 준 값과 다르다(${GOT:0:16})"; exit 1; }
same "$PATCH_WANT" "$GOT" || { echo "✖ 패치 sha256 이 검토 받은 판(스크립트 안 PATCH_WANT)과 다르다(${GOT:0:16} ≠ ${PATCH_WANT:0:16})"; exit 1; }
# 확인한 파일과 쓰는 파일이 같게 — 0700 임시 사본을 다시 해시하고 그 사본만 쓴다
W0=$(mktemp -d); chmod 700 "$W0"
cleanup() { rm -rf "$W0" "${T:-}"; }
trap cleanup EXIT
cp "$PATCH" "$W0/patch"; PATCH="$W0/patch"
same "$PATCH_WANT" "$(sha256sum "$PATCH" | cut -d' ' -f1)" || { echo "✖ 임시 사본의 sha256 이 다르다 — 멈춘다"; exit 1; }

# 경로·머리는 **git 이 읽은 대로** 본다(`diff --git` 줄만 보면 rename/copy·옛 형식 diff 로 목록 밖을 건드릴 수 있다)
PATHS_WANT=$(printf '%s\n' "$CODE_WANT" | sed -n 's/=.*//p' | LC_ALL=C sort)
[[ "$FILES_N" =~ ^[1-9][0-9]*$ ]] && [ "$(printf '%s\n' "$PATHS_WANT" | wc -l | tr -d ' ')" = "$FILES_N" ] || { echo "✖ 스크립트 안 목록이 FILES_N($FILES_N) 줄이 아니다(채우지 않은 판?)"; exit 1; }
NEW_N=$(grep -c '^ create mode ' <<< "$SUMMARY_WANT" || true)
NUMSTAT=$(git -C "$APP" apply --numstat "$PATCH" 2>/dev/null) || { echo "✖ git apply --numstat 이 패치를 읽지 못했다"; exit 1; }
PATHS=$(printf '%s\n' "$NUMSTAT" | awk -F'\t' '{print $3}' | LC_ALL=C sort -u)
same "$PATHS_WANT" "$PATHS" || { echo "✖ 패치가 건드리는 경로(git 이 읽은 대로)가 검토 받은 목록과 다르다:"; diff <(printf '%s\n' "$PATHS_WANT") <(printf '%s\n' "$PATHS") || true; exit 1; }
SUMMARY=$(git -C "$APP" apply --summary "$PATCH" 2>&1) || { echo "✖ git apply --summary 가 패치를 읽지 못했다"; exit 1; }
SUMMARY=$(printf '%s\n' "$SUMMARY" | LC_ALL=C sort)
same "$(printf '%s\n' "$SUMMARY_WANT" | LC_ALL=C sort)" "$SUMMARY" || { echo "✖ 패치의 만들기·지우기·이름 바꾸기·모드 바꾸기가 검토 받은 판과 다르다(git 요약):"; echo "$SUMMARY"; exit 1; }
# 글자로도 한 번 더(겹쳐 막는다) — 이름 바꾸기·복사·지우기·심볼릭 링크 머리 · review-scripts · 이번 범위 밖(고치지 않는 것)
if grep -aqE '^(rename|copy) (from|to) |^(dis)?similarity index |^deleted file mode ' "$PATCH"; then echo "✖ 패치에 이름 바꾸기·복사·지우기 머리가 있다 — 멈춘다"; exit 1; fi
if grep -aqE '^(new file mode|new mode|old mode|deleted file mode|index [0-9a-f]+\.\.[0-9a-f]+) 120000' "$PATCH"; then echo "✖ 패치에 심볼릭 링크 모드가 있다 — 멈춘다"; exit 1; fi
if grep -aqE '^diff --git a/review-scripts/' "$PATCH"; then echo "✖ 패치가 review-scripts/ 를 건드린다 — 멈춘다"; exit 1; fi
if grep -aqE '^diff --git a/(src/Auth\.php|src/Db\.php|src/Passkey\.php|src/Storage\.php|src/Upload\.php|src/bootstrap\.php|deploy/|src/handlers/login_page\.php|src/community/handlers/search\.php|src/community/handlers/comments_create\.php)' "$PATCH"; then
  echo "✖ 패치가 「고치지 않는 것」(Auth · Db · Passkey · Storage · Upload · bootstrap · deploy/** · login_page · 커뮤니티 search · comments_create)을 건드린다 — 멈춘다"; exit 1
fi
# 여섯 파일 — 패치 파일 **안에서** 지운 줄·더한 줄을 센다. awk 는 파일을 직접 읽는다(파이프 끝 awk·exit 0)
CORE=$(awk '
  /^diff --git / { f = ""; if ($3 == "a/public/index.php") f = "idx"; else if ($3 == "a/src/Authz.php") f = "authz"; else if ($3 == "a/src/Audit.php") f = "aud";
                   else if ($3 == "a/src/Http.php") f = "http"; else if ($3 == "a/src/community/CommunityAuthz.php") f = "cauthz"; else if ($3 == "a/src/community/routes.php") f = "croutes"; next }
  f == "" { next }
  /^(\+\+\+|---) / { next }
  /^-/ { rm[f]++; next }
  /^\+/ { ad[f]++; next }
  END { printf "%d %d %d %d %d %d %d %d %d %d %d %d", rm["idx"], ad["idx"], rm["authz"], ad["authz"], rm["aud"], ad["aud"], rm["http"], ad["http"], rm["cauthz"], ad["cauthz"], rm["croutes"], ad["croutes"] }' "$PATCH")
[ "$CORE" = "$CORE_WANT" ] || { echo "✖ 보안 핵심·라우터 여섯 파일의 줄 모양이 검토 받은 판이 아니다(지운/더한 × index · Authz · Audit · Http · CommunityAuthz · routes = ${CORE} · 기대 ${CORE_WANT}) — 멈춘다"; exit 1; }
read -r c1 _ <<< "$CORE"
[ "$c1" = "0" ] || { echo "✖ public/index.php 에 지운 줄이 있다(더하기만 허락) — 멈춘다"; exit 1; }
# 패치 안의 더한 라우트 줄(글자 그대로 · 차례대로) = 검토 받은 열두 줄
PADD=$(awk '/^diff --git / { f = ($3 == "a/public/index.php") } f && /^\+[ \t]*\[\x27(GET|POST|PUT|PATCH|DELETE)\x27, *\x27\// { print substr($0, 2) }' "$PATCH")
same "$ROUTES_ADD_N" "$(printf '%s\n' "$PADD" | grep -c . || true)" && same "$ROUTES_ADD_WANT" "$(printf '%s\n' "$PADD" | sha256sum | cut -d' ' -f1)" \
  || { echo "✖ 패치가 더하는 라우트 줄이 검토 받은 열두 줄이 아니다 — 멈춘다"; exit 1; }

# SQL 은 표준입력으로(명령 인자 -c 0 — 명령 줄이 한 모양이라 운영 경보 AL-9 가 한 줄로 묶인다 · 취합 규칙 2026-09-26 21:4x). 읽기만 한다
psq() { ( cd / && sudo -n -u postgres psql -X -q -t -A -d "$DB" <<< "$1" ) 2>/dev/null || echo "(query-failed)"; }
code() { curl -s -o /dev/null --max-time 10 -w '%{http_code}' -A "$UA" "$@" || true; }
fsha() { local g; g=$(sha256sum "$APP/$1" 2>/dev/null | cut -d' ' -f1 || true); printf '%s' "${g:-(none)}"; }
croutes() { local r; r=$( (grep -E "^[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)'," "$APP/src/community/routes.php" 2>/dev/null || true) | sha256sum | cut -d' ' -f1); printf '%s' "$r"; }
toksets() { local r; r=$( (sed -n '/^const TBOX_API_TOKEN_\(ROUTES\|PERSONAL_ROUTES\|ONLY_ROUTES\) = \[/,/^\];/p' "$APP/public/index.php" 2>/dev/null || true) | sha256sum | cut -d' ' -f1); printf '%s' "$r"; }
gates() { local r; r=$( (sed -n '/^const TBOX_GATE_ALLOW_\(PASSWORD_CHANGE\|MFA_SETUP\) = \[/,/^\];/p' "$APP/public/index.php" 2>/dev/null || true) | sha256sum | cut -d' ' -f1); printf '%s' "$r"; }
route_n() { ( grep -cE "^[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)', *'/" "$APP/public/index.php" 2>/dev/null || true ) | tr -d ' '; }
troutes() { local r; r=$( (grep -E "^[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)', *'/" "$APP/public/index.php" 2>/dev/null || true) | sha256sum | cut -d' ' -f1); printf '%s' "$r"; }
added_present() {   # 더할 라우트 열두 줄 중 트리에 글자 그대로 있는 줄 수
  local n=0 l
  while IFS= read -r l; do [ -n "$l" ] || continue; grep -qxF -- "$l" "$APP/public/index.php" 2>/dev/null && n=$((n + 1)); done <<< "$PADD"
  printf '%s' "$n"
}
db_ready() { psq "SELECT (to_regclass('public.access_grants') IS NOT NULL)::int || '|' || (to_regclass('public.access_requests') IS NOT NULL)::int || '|' || (SELECT count(*) FROM public.permissions WHERE access_level = 'private' AND NOT can_read) || '|' || (SELECT count(*) FROM information_schema.columns WHERE table_schema = 'community' AND table_name = 'notifications' AND column_name = 'access_request_id')"; }
native_n() {   # 화면 JS 의 브라우저 기본 창 호출(주석 줄 제외) — 0 이어야 한다
  local out; out=$(grep -rnE 'window\.(prompt|confirm|alert)\(' "$APP/public/assets" --include=*.js 2>/dev/null || true)
  printf '%s\n' "$out" | grep -cvE '^$|^[^:]+:[0-9]+:[[:space:]]*(\*|//|/\*)' || true
}
hashes_are() {   # $1 = "경로=sha" 목록 — 트리의 그 파일들이 모두 그 값인가(없는 파일은 (none) — 목록 값과 같을 수 없다)
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
STATUS=$(git -C "$APP" status --porcelain) || { echo "   ✖ git status 를 읽지 못했다 — 멈춘다"; exit 2; }   # 실패를 빈 목록으로 읽지 않는다
DIRTY=$(printf '%s\n' "$STATUS" | grep -v -E '^(\?\? (api-test\.txt|jar))?$' || true)
[ -z "$DIRTY" ] || { echo "   ✖ 운영 트리에 커밋 안 된 변경이 있다 — 멈춘다:"; echo "$DIRTY"; exit 2; }
hashes_are "$BASE_WANT" || { echo "   ✖ 고칠 파일이 기준 판이 아니다 — 멈춘다(운영 판이 바뀌었으면 pins-g0-repo.sh 로 새 블록을)"; exit 3; }
echo "   고칠 파일 $(printf '%s\n' "$BASE_WANT" | grep -c '=') 개 = 기준 판"
NEWF=0
while IFS='=' read -r rel want; do
  [ -n "$rel" ] || continue
  case "$SUMMARY_WANT" in *" $rel"*) [ -e "$APP/$rel" ] && { echo "   ✖ 새로 만들 파일이 이미 있다: $rel"; NEWF=1; } ;; esac
done <<< "$CODE_WANT"
[ "$NEWF" = "0" ] || exit 3
same "$CROUTES_WANT" "$(croutes)" || { echo "   ✖ 커뮤니티 라우트 표가 기준 판이 아니다 — 멈춘다"; exit 3; }
same "$TOKSETS_WANT" "$(toksets)" || { echo "   ✖ 토큰 창구 집합이 기준 판이 아니다 — 멈춘다"; exit 3; }
same "$GATE_WANT" "$(gates)" || { echo "   ✖ 강제 절차 허용 목록이 기준 판이 아니다 — 멈춘다"; exit 3; }
same "$TROUTES_BASE_WANT" "$(troutes)" || { echo "   ✖ t-box 라우트 표 줄이 기준 판이 아니다 — 멈춘다"; exit 3; }
same "0" "$(added_present)" || { echo "   ✖ 더할 라우트 줄이 이미 있다 — 멈춘다"; exit 3; }
RN0=$(route_n)
[[ "$RN0" =~ ^[1-9][0-9]*$ ]] || { echo "   ✖ t-box 라우트 줄을 세지 못했다 — 멈춘다"; exit 3; }
echo "   커뮤니티 라우트 표 · 토큰 창구 집합 · 강제 절차 목록 · t-box 라우트 표 줄 = 기준 판 · t-box 라우트 ${RN0}줄 · 여섯 파일 줄 모양 ${CORE}"
# ★ DB 먼저 — 표 둘 · 개인 줄 넷 · 알림 칸이 없으면 코드를 내보내지 않는다(내보내면 파일 목록·트리·받기가 500)
DBR=$(db_ready)
same "1|1|4|1" "$DBR" || { echo "   ✖ 운영 DB 에 G-0 표·줄·칸이 없다($DBR — 표 access_grants|access_requests|개인 줄(읽기 거짓)|알림 칸) — DB 먼저: sudo bash scripts/apply-g0-db-2026-09-28.sh --migrate <마이그레이션 사본> — 멈춘다"; exit 3; }
echo "   DB 먼저: 표 둘 · 개인 줄 넷(읽기 거짓) · 알림 칸 있음"
git -C "$APP" apply --check "$PATCH" || { echo "   ✖ git apply --check 실패 — 멈춘다"; exit 3; }
echo "   git apply --check: ok (${GOT:0:16}) · 파일 $(printf '%s\n' "$PATHS" | wc -l | tr -d ' ')개(새 파일 ${NEW_N})"
BUSY=$(psq "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND actor_name NOT LIKE 'qa\_%' AND actor_name NOT LIKE 'claude\_%'")
echo "   최근 10분 검토 계정이 아닌 사람의 감사 행: $BUSY (본 실행은 0 이어야 — 쓰는 사람 밑에서 코드를 바꾸지 않는다)"
if [ "$DRY" -eq 1 ]; then echo "== [dry] 여기까지. 다음: 패치 → 3초 → 확인(실패하면 두 단계 되돌림)"; exit 0; fi
same "0" "$BUSY" || { echo "   ✖ 누군가 사이트를 쓰는 중이거나 읽지 못했다 — 미룬다"; exit 3; }
LOG0=$(stat -c %s "$APPLOG" 2>/dev/null || echo "(none)")
[[ "$LOG0" =~ ^[0-9]+$ ]] || { echo "   ✖ $APPLOG 크기를 읽지 못했다(adm 그룹?) — 멈춘다"; exit 3; }

rollback() {   # 한 번만 돈다 — 되돌리는 동안의 오류·신호는 무시
  trap - ERR; trap '' INT TERM HUP; set +e
  # 두 단계: 라우트 표(public/index.php)를 핸들러 파일보다 먼저 되돌린다.
  #   ① public/index.php 만 → 3초(opcache.revalidate_freq=2 — 옛 표를 읽을 때까지) ② 핸들러 밖 나머지 → 핸들러(src/handlers/ · 새 파일 지우기 포함)는
  #   마지막, 더한 라우트 줄이 빠진 것을 본 뒤에만. ① 이 실패하면 핸들러를 지우지 않고 사람에게 넘긴다.
  echo "== ✖ $1 — 되돌린다(두 단계 — ① public/index.php → 3초 → ② 나머지 · 핸들러 파일은 마지막)."
  if git -C "$APP" apply -R --include=public/index.php "$PATCH"; then
    sleep 3   # opcache.revalidate_freq=2 — 옛 라우트 표를 읽을 때까지
    git -C "$APP" apply -R --exclude=public/index.php --exclude='src/handlers/*' "$PATCH" || echo "   ✖ 되돌리기 ②(핸들러 밖) 실패 — 사람이 봐야 한다"
    if same "0" "$(added_present)"; then
      git -C "$APP" apply -R --include='src/handlers/*' "$PATCH" || echo "   ✖ 되돌리기 ②(핸들러) 실패 — 사람이 봐야 한다"
    else
      echo "   ✖ 라우트 표에 더한 줄이 남아 있다 — 그 줄이 가리키는 핸들러를 지우지 않는다 — 사람이 봐야 한다"
    fi
  else
    echo "   ✖ 되돌리기 ①(public/index.php) 실패 — 핸들러를 지우지 않고 멈춘다(라우트 표가 먼저) — 사람이 봐야 한다"
  fi
  sleep 3
  if hashes_are "$BASE_WANT"; then echo "   ✔ 되돌린 뒤 고친 파일 = 기준 판"; else echo "   ✖ 되돌린 뒤에도 기준 판이 아니다 — 사람이 봐야 한다"; fi
  LEFT=0
  while IFS= read -r l; do
    rel=${l##* }; [ -n "$rel" ] || continue
    [ -e "$APP/$rel" ] && { echo "   ✖ 새 파일이 남았다: $rel"; LEFT=1; }
  done <<< "$SUMMARY_WANT"
  [ "$LEFT" = "0" ] && echo "   ✔ 새 파일 ${NEW_N}개 없음"
  same "$RN0" "$(route_n)" && same "$TROUTES_BASE_WANT" "$(troutes)" && same "$TOKSETS_WANT" "$(toksets)" && same "$GATE_WANT" "$(gates)" && same "$CROUTES_WANT" "$(croutes)" \
    && echo "   ✔ 라우트 그대로(t-box ${RN0}줄 · 표 줄 · 토큰 창구 · 강제 절차 · 커뮤니티)"
  echo "   · DB 의 G-0 표·줄은 남긴다(옛 코드에 무해) — 지우려면 DB 스크립트 머리의 되돌리기(개인 행 0 일 때만)"
  exit 4
}
echo "== 1. 패치"
trap 'rollback "중단(명령 오류 또는 신호)"' ERR INT TERM HUP
git -C "$APP" apply "$PATCH"
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
npy=0; nbad=0
while IFS= read -r f; do
  case "$f" in *.py) npy=$((npy+1)); PYTHONPYCACHEPREFIX="$T" python3 -m py_compile "$APP/$f" 2>/dev/null || { echo "   ✖ py_compile $f"; nbad=$((nbad+1)); } ;; esac
done <<< "$PATHS"
chk "바뀐 파이썬 ${npy}개 py_compile 실패" "0" "$nbad"
if out=$(cd "$APP" && php scripts/community_asset_version.php --check 2>&1); then rc=0; else rc=$?; fi
chk "커뮤니티 부품 버전(import ?v=) = 지금 내용" "0" "$rc"
[ "$rc" = "0" ] || printf '%s\n' "$out" | sed 's/^/     | /'
chk "t-box 라우트 줄 수 +${ROUTES_ADD_N}" "$((RN0 + ROUTES_ADD_N))" "$(route_n)"
chk "t-box 라우트 표 줄 = 검토 받은 판(그 열두 줄만 늘었다)" "$TROUTES_NEW_WANT" "$(troutes)"
chk "더한 라우트 줄이 글자 그대로 ${ROUTES_ADD_N}줄" "$ROUTES_ADD_N" "$(added_present)"
chk "토큰 창구 집합 셋 그대로(새 창구는 세션 전용 — 토큰으로는 못 부른다)" "$TOKSETS_WANT" "$(toksets)"
chk "강제 절차 허용 목록 그대로" "$GATE_WANT" "$(gates)"
chk "커뮤니티 라우트 표 그대로" "$CROUTES_WANT" "$(croutes)"
chk "화면 JS 의 브라우저 기본 창 호출(window.prompt( · window.confirm( · window.alert( — 주석 제외)" "0" "$(native_n)"
# 파일 읽기만(요청 0 · 감사 0) — 이 묶음의 자리가 반영된 트리에 있나
if php -r '$a = $argv[1];
  $z = file_get_contents($a . "/src/Authz.php"); $l = file_get_contents($a . "/src/handlers/_folder_lock.php");
  $u = file_get_contents($a . "/src/Audit.php"); $up = file_get_contents($a . "/src/handlers/_upload_common.php");
  $ok = strpos($z, "\x27private\x27    => 4,") !== false
     && strpos($z, "private static function privateChainSql(string \$alias, string \$ph, int \$me): array") !== false
     && strpos($z, "public static function canSeeInList(array \$u, array \$row): bool") !== false
     && strpos($l, "function tbox_folder_lock_for_put(array \$user, int \$folderId, string \$level, ?int \$teamId, bool \$checkPut): array") !== false
     && strpos($l, "FOR SHARE") !== false && strpos($up, "tbox_folder_lock_for_put(\$user, \$folderId, \$level, \$teamId, true)") !== false
     && strpos($u, "\x27grant.add|allow\x27") !== false && strpos($u, "\x27access.request|allow\x27") !== false && strpos($u, "\x27access.decide|allow\x27") !== false;
  exit($ok ? 0 : 1);' "$APP" 2>/dev/null; then r=0; else r=1; fi
chk "php -r — 개인 등급 순위 · 짝 SQL 한 벌 · 목록 판정 · 잠금 재확인 도우미(올리기가 부름) · 문장 표 새 행동" "0" "$r"
# HTTP — 미로그인만(운영 감사 행 0): 런처 200 · 로그인 화면 200 · 자산 둘 200 · 커뮤니티 화면 302(로그인으로). `/tbox/api/…` 는 보내지 않는다(auth.required 행)
chk "런처 200" "200" "$(code "$L8082/")"
chk "t-box 로그인 화면 200" "200" "$(code "$L8082/tbox/login")"
chk "자산 app.js 200" "200" "$(code "$L8082/tbox/assets/app.js")"
chk "자산 app.css 200" "200" "$(code "$L8082/tbox/assets/app.css")"
chk "미로그인 커뮤니티 화면 302(로그인으로)" "302" "$(code "$L8082/community/")"
LOGADD=$(tail -c +"$((LOG0 + 1))" "$APPLOG" 2>/dev/null || true)
chk "app.log — 이 실행 동안 더해진 uncaught · Fatal 0" "0" "$( (grep -cE 'uncaught|Fatal error' <<< "$LOGADD" || true) | tr -d ' ')"
if [ "$ok" -ne 1 ]; then rollback "확인 실패"; fi
trap - ERR INT TERM HUP
echo "== 끝 — 다음: 머리 주석의 사람 확인(claude_ 계정 — 개인 폴더 · 권한 · 공유받음 · 요청 · 허락 · 알림) → 파일 지정 커밋(작성자 claude) →"
echo "   scripts/release.sh minor \"개인 등급과 사람 지정 권한\" \"파일·폴더를 「개인」으로 올리고 아이디나 이름으로 보기·편집 권한을 줄 수 있습니다. 권한이 없으면 주인에게 요청합니다\""

#!/usr/bin/env bash
# review-scripts/apply-uxr1-repo-2026-09-28.sh — 사용감 라운드 1 「빠른 승리」(화면·문구·순서만 — 새 페이지 제목 · 좁은 폭 편집 머리 · 폰 윗줄 ·
#                                                「최근」 판 · 블록 창 · 2단계 코드 자동 제출 · 올리기 뒤 · 게시판 글 상태 글자 · 다른 탭 다시 로그인 뒤 쓰기)의
#                                                **저장소 쪽**을 운영 트리에 반영(changkwon 실행)
#   근거: 지시서 ~/.local/share/qaredi-dev/prompt-ux-r1-93.txt(메인 changkwon-93) · 구현 기록 docs/ux-r1-implementation.md
#   본보기: apply-7d4-repo-2026-09-27.sh(2026-09-28 운영 반영 통과판 7d4s — PATCH_WANT 고정 · 0700 사본 · git 이 읽은 경로·요약 · rename/copy 거부 ·
#           빈·모르는 인자 거부 · 되돌림 함수 + ERR·INT·TERM·HUP 덫(set -E 없이) · 개수는 패치 파일 안에서만 · 헤더 함수 마지막 인자 · (none)).
#           **DB 단계 없음** — 이 묶음은 DB 변경 0(새 표·열·마이그레이션 0).
#   검토 동안은 복제본 review-scripts/ 에(태그 review-base/ux-r1 안), 검토 뒤 Qaredi scripts/ 로 옮긴다.
#   ★ nginx·/etc 변경 0 → 15분 자동 되돌림 대상 없음 · root 불필요.
#   이 묶음의 모양:
#     ① 라우트 표(t-box · 커뮤니티) · 토큰 창구 집합 변경 0 — 반영 전후 표 줄 sha 가 같아야 한다.
#     ② src/ 는 뷰 셋(shell_top · page_view · home_page)과 도움말 둘만 · public/ 은 public/assets/ 만 — 패치 파일 안에서 센다.
#     ③ 판정·보안 핵심 Authz · CommunityAuthz · Auth · Http · Db · Passkey · Audit · Upload · Storage · 라우터 public/index.php · src/community/routes.php ·
#        bootstrap · db/ · deploy/ · config/ 는 패치 밖(건드리면 멈춘다).
#   범위: 아래 고정 값 블록 — 기준 판 = 운영 eadabd8(v2.35.0). 운영이 위키 커밋 등으로 나아가 고칠 파일이 기준 판과 다르면 사전 확인 「고칠 파일이 기준 판이 아니다」
#     (종료 3)로 멈춘다(맞는 동작) — 복제본에서 운영 HEAD 를 합치고(커뮤니티 JS 는 import 버전을 다시 찍는다)
#     `bash review-scripts/pins-uxr1-repo.sh <운영 sha> <합친 판> [패치]` → 찍힌 블록으로 아래 블록(표식 줄 포함)을 통째로 바꾼다(옮기기는 메인).
#   사용: bash scripts/apply-uxr1-repo-2026-09-28.sh <PATCH> <SHA256> [--dry-run]
#     <SHA256> 은 이 스크립트 안의 PATCH_WANT 와도 같아야 한다(둘 중 하나라도 다르면 멈춘다).
#   확인: 기준 판·검토 판 sha256 · php -l · bash -n · py_compile · 커뮤니티 부품 버전(--check) · 라우트 표 셋 그대로 · 화면 JS 의 기본 창 호출 0 ·
#         파일 읽기(윗줄 단추 셋 · 제목 칸 자리표시 · 「최근」 판 · 세션 확인 도우미 한 곳 · 기본 제목 표) ·
#         HTTP(화면·자산만 — 자산 app.js · community/editor.js · search.js 200 과 이 묶음 글자 · 미로그인 /community/ 302 · /tbox/login 200 · 런처 200) ·
#         app.log 에 이 실행 동안 더해진 uncaught 0(바이트 자리로 — 흔들리는 비교 아님)
#         ★ 미로그인 창구 401 확인은 두지 않는다(운영 감사에 auth.required 행을 남기지 않는다). HTTP 확인은 모두 화면·자산 요청이라 감사 행 0.
#   되돌리기: git -C /var/www/qaredi/tbox-v2 apply -R <PATCH> (DB·서버 설정 변경 0 — 파일만 돌아간다)
#   반영 뒤 사람 확인(claude_ 계정 · 폰 412): 「새 페이지」 → 커서 제목 칸 · 윗줄 종 숫자 · 앱 이름 → t-box · 블록 창 한 번 누르기 · 로그인 코드 자동 제출
#   연습 결과(2026-09-28 13:16 · claude · 이 고정 값 블록 그대로(찍은 판 b86a3a2 · PATCH_WANT 7f4d3525…) · 경로 넷(APP · L8082 · DB · APPLOG) + 읽기 psq 접속
#     (sudo psql → 시험 전용 역할 tbox_testdb)만 바꾼 사본 · 모의 운영 트리 = eadabd8 clone · 모의 인스턴스 php -S 127.0.0.1:8392 · 모의 DB tbox_uxr1_reh(tbox_testdb ·
#     개발 임시 DB 를 떠서 부음) · 연습 틀 wk-uxr1/rehearse.sh):
#     ① sha 틀림 → 1 · ② 모르는 인자 → 1 · ③ 빈 인자 → 1 ④ --dry-run → 0(트리 그대로) ⑤ 본 실행 → 0(확인 20 ✔ · ✖ 0 · 끝 트리 = 검토 판 — 모의 DB 의 감사 행 수는 세지 않았다: HTTP 확인은 화면·자산 GET 과 미로그인 화면 302 뿐이고 401 창구 확인은 두지 않았다)
#     ⑥ 다시 → 2 ⑦ 손으로 `git apply -R` → 기준 판 차이 0 ⑧ 확인 실패(인스턴스 내림 — HTTP 여섯 ✖) → 스스로 되돌림 4 · 기준 판 차이 0 · 새 파일 0 · 라우트 그대로
#     ⑨ 추적 파일 커밋 안 된 변경 → 2 ⑩ 기준 판 흔들기(커밋된 한 줄) → 3 ⑪ 다시 본 실행 → 0(20 ✔) → 되돌림 → 끝 트리 기준 판 차이 0 · 모의 서버 로그 PHP 오류 0.
#     11 경우 모두 기대대로. 이 묶음은 화면 파일만이라 되돌림 중 창구 500 창은 없다(PHP 는 뷰 셋·도움말 둘 — 함수·라우트 추가 0).
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
# 반영할 패치 = git diff eadabd8 <검토 태그> -- . ':!review-scripts' — 찍은 판 b86a3a2 과 내용이 같다(그 뒤 커밋은 review-scripts/ 만 바꾼다) ·
#   pins-uxr1-repo.sh 가 찍음 · 인자로 받은 sha256 과 **둘 다** 같아야 한다
PATCH_WANT=7f4d3525cbef16b696a8fd5d414570c29dd03e0b1d3fe0a82e0eb111f522a0b4
# 고치는 파일의 반영 전 기준 판(eadabd8) — 다르면 시작하지 않고, 되돌린 뒤 이 판으로 돌아왔는지 본다
BASE_WANT="DESIGN.md=009d146f56fed54701f4a6b931e1afe265f8079214796fe850daea26eae315b9
SECURITY.md=6392b2dec4ed4d2460ade67967b6116a0fe844e7c0f935c461b1ae66859d7986
STAGES.md=c98aabe5299fe85be981e9ff1a21f08cb59b195fbaa701541525100c2dc39034
docs/design-guide.html=c5086d03c5258cc784792be2f0eeba8211fd57b7e38e86080fdb22a0eaa2955a
public/assets/app.css=548c3cccdaf66c7f7874ef02e35f8a4f52572c6dc205375ea344e2e24e72cc1c
public/assets/app.js=39d8d14a2dbf7859cd9115b5e4405e1822460f023e952dda7c6330ed52e724c3
public/assets/community/community.css=9a5db10e286e3b4ac9c62872eaad3c514f23a0d0a59a178fdac1b97091d70b48
public/assets/community/database.js=b4090f33387171d0fe51d37bec48473b63daaab51adf68bd5fe8e594c526850b
public/assets/community/editor.js=e714729b4a2103973ecbf17cdb349c0cf4931990ede548a8780ba31062c59c3e
public/assets/community/templates.js=3ccec594e1a4373d8c4760ef64b1ad4464efbbb59d7fe14ea76650ae7d06d894
public/assets/search.js=5410cfc7a0dfa137041d3033de5f83f95170c06ec6e66f200e63edd4b1f0188e
src/community/handlers/home_page.php=59785e54264f79273667ee034b0ad6ff431f9e6df0ff20ba0cd3835cc0076653
src/community/handlers/page_view.php=0854fb59d9a3521aac62ddecc27c40f74184d7cda423a1c65a188d091357c941
src/help/community.php=01fdffff34245222e3dc4bbd8471f996d1ea29ac2434e2032a79fa63042ba572
src/help/screens.php=315e2441e6305288f64a13613eb16dc284ddb60a3f20b5bd81a015f3802af0f8
src/views/shell_top.php=b1b9e41bc0149c1f5866f64d0c2ea4f69e77f8b8c2d037800fe5b5ca043c0fe4
tests/browser/dbg_u_ui.py=d9a6246a0d08d268378f8f4c97679c2821512e6c131b2e7c0d4eb1bee0ecfc19
tests/browser/passkey.py=b2a95a0401a825592247c5c74e064673276b3bff231fe710401fa78d2b6200c1
tests/catalog.json=ee3f3aabc7c161ffde4dca4d9bfed55b076b7b05dcfc50c54bf192b5e7fef7ae
tests/community/browser_5c.py=4b0f1c5cd788782cb3457cee0a5e318b75fc8b543977e939e21241e74baf3be4
tests/community/browser_smoke.py=509cfdccb12f913259bebb563d7cda015923650a0e46085c1569593323ba8d8b
tests/f51.sh=32dbc2181316bd5654e2ab53d87ccd053cc0cab917043d64ac062595d1293d03
tests/f52.sh=1874e856b927d194aa32c2e95f41565e3a01259fcfbe1f829f76314956b7e1a1
tests/f65.sh=cae7e0fe8392dc781bee86412fe4c689c7d72d0aafca463a1e9e4d2f676a7426
tests/f66.sh=e8cb9f05e7aa2737880c64b5678f1932ba828717d7f63296a2bae948bb344787
tests/f69.sh=26f9df254cb0abe6005c46fedd245945e568d475e7d38bf66bbb9962c5455ebc
tests/f70.sh=5f973b80d5e192b0de1c86daa0384d776a449c43c5f74a5acd3ec6a8c3481f0e
wiki/qaredi/guide-search.json=f811526d5a08bb234aecc25bbb6504994d1236d1b0bbfb09f8c70d4db839dcb5"
# 반영 뒤 검토 받은 판(바뀐·새 파일 전부 31)
CODE_WANT="DESIGN.md=974a41284bdfec1c316220dbc9d584ae41ae26104e3f6f64c3e2eb9a0a865f85
SECURITY.md=e098c13c7cbc9af7e06418c7b7ea0bd9f3eb7f643685472cb75825dc6be345a0
STAGES.md=bae9aa5014c4247d1966b3a14c3091b14d82be3d9c6524784d61a2cc7ee4b39c
docs/design-guide.html=d0a1c57f45b3c7eb3baefa44b70269d0edf484abaca5cbd9bf7ade98b6b18cc5
docs/ux-r1-implementation.md=fcd09415af21510ae8ebd1c151aec394f1fe2b33263e3a6d878f0caf35883af5
public/assets/app.css=1ca71a703e993cc2296a2db1ae2118ed7b38a21cf08ef1e140aba290908e7dd8
public/assets/app.js=4096c774d7cbabc4a438bd683d712ceac1b59859201de8b2d969ee9e48892b55
public/assets/community/community.css=75774467c01faf80f297c7910a55c8e2d3fbbad303aa66a3ac934d92a8d00829
public/assets/community/database.js=073cd630b266ad7eb0dcaf7820d908f4265574de3030426a70efc31b0c67b09f
public/assets/community/editor.js=b8a1b687413d974b896cb5431323b8347ccbb223b39c2ce98c6fe2915df0a164
public/assets/community/templates.js=d6583cc3c15667db1cb195a0944489376e5a45a1212262f7654c2e0b7d5ff472
public/assets/search.js=173d8bebb032120b78a5119963581cf2b2bc3ad26b033049153e7044d6ab731c
src/community/handlers/home_page.php=b93229e4403024919de6d0be3294480fcad7ac397d4995124968d373dce5ff6d
src/community/handlers/page_view.php=a943a4220a1382aafd324d3b65a64924c30e8a59fd7e551422d35e41470b1448
src/help/community.php=3f23fe92e0820289967709a6bb09ae13c17b1736b434b03e44ff386d6a491617
src/help/screens.php=6adb7098d284c96ec9b2af526c03402fa486820689fc0a8348479cde100a2546
src/views/shell_top.php=9e252a297048e13bad9ebc8b376e19a69342b1b939f7b37c0d475f0928ec970d
tests/browser/dbg_u_ui.py=6d4a6a26ad91e973438b97812b63be40e378743b86038f11bb8f9ed2b186f6c7
tests/browser/passkey.py=b754aba5bcae541f7b9ab1eeea0afda6a195a3f2c810e7ed3b041e1ed4ca45e3
tests/browser/ux_r1_ui.py=f9014cac8230b8347b70fb0e78f2be0486c9b4a8f404b3a5bbec25dbc9bbb319
tests/catalog.json=3709e2eb0d704f145a909854802b799bc2d9de37251e860d6cd513d91c5d4565
tests/community/browser_5c.py=815cfa823d0d1bca17d8a0ee6a2e5385ea71be6d130a0390137a9e5944b660bd
tests/community/browser_smoke.py=7aa3a52c3e1c28255b280cd26b1e573b4fe5d5c0b1207d9e8a46a21301bc4ecb
tests/f51.sh=d3c81c9ebd6fdc6c8d26f602c40cc8dfb16b256d19b3b593fe99100fe190fa42
tests/f52.sh=432d43b1e72d5f867ca77942f716669a0bc7aab6b56e8fbc75e4c0687112f0da
tests/f65.sh=4052926b33c4a6fc1c56f61ce533ecd5b94edebe27032647383c615dc651e811
tests/f66.sh=fa4c256bba25d7b0bbaafef1a56b739e62a1c875906ea2785bc8f8cecb2d54c6
tests/f69.sh=21b10a442bebda23dc244f6ec8f8ce481015189aefe0f2825c95d05b39c707df
tests/f70.sh=780b003a93f029216078863f0349f200b5a72c5801c2648c1ef6bc0b3a5db6f9
tests/ux_r1_ui.sh=4ec29a1281ac1ab3b7c25fde9e1fb180fc48631df618b3079a8e8ded5ad33d99
wiki/qaredi/guide-search.json=9c79aebaef3971999deab90de3c527bb5c6f1b8ef346a42fa4e011c11ceb638e"
FILES_N=31
# 새로 만드는 파일의 git 요약 줄(모드 포함) — 이것 말고는 요약이 없어야 한다(지우기·이름 바꾸기·복사·모드 바꾸기 0)
SUMMARY_WANT=" create mode 100644 docs/ux-r1-implementation.md
 create mode 100644 tests/browser/ux_r1_ui.py
 create mode 100644 tests/ux_r1_ui.sh"
# 라우트 — public/index.php 의 토큰 창구 집합 셋 · t-box 라우트 표 줄 · 커뮤니티 라우트 표 줄(셋 다 반영 전후 같아야 — 새 창구 0)
TOKSETS_WANT=29f5acdfd5fbb402e18f332ff0d224a8ec1a493fea6d28b86992798ec0eb61e6
TROUTES_WANT=cdafde0f6a395b73ff4681337dc3ab03d2695fef7c2702cd192e04a24bc7ea11
CROUTES_WANT=ca16d5b97e4a7ff9b3d79afb27b469bece4a07a207556227b0e8c7f00f2d505a
# 패치 파일 안에서 센 파일 머리 갈래 — 뷰·도움말 · 자산 · 그 밖의 src·public·db·deploy·config(0) · 문서·시험·위키
PATCHN_WANT="5 7 0 19"
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
# 글자로도 한 번 더(겹쳐 막는다) — 이름 바꾸기·복사·지우기·심볼릭 링크 머리 · review-scripts · 이번 범위 밖(라우터·판정·보안 핵심·DB·배포·설정)
if grep -aqE '^(rename|copy) (from|to) |^(dis)?similarity index |^deleted file mode ' "$PATCH"; then echo "✖ 패치에 이름 바꾸기·복사·지우기 머리가 있다 — 멈춘다"; exit 1; fi
if grep -aqE '^(new file mode|new mode|old mode|deleted file mode|index [0-9a-f]+\.\.[0-9a-f]+) 120000' "$PATCH"; then echo "✖ 패치에 심볼릭 링크 모드가 있다 — 멈춘다"; exit 1; fi
if grep -aqE '^diff --git a/review-scripts/' "$PATCH"; then echo "✖ 패치가 review-scripts/ 를 건드린다 — 멈춘다"; exit 1; fi
if grep -aqE '^diff --git a/(src/Authz\.php|src/community/CommunityAuthz\.php|src/Auth\.php|src/Http\.php|src/Db\.php|src/Passkey\.php|src/Audit\.php|src/Upload\.php|src/Storage\.php|public/index\.php|src/community/routes\.php|src/bootstrap\.php|db/[^ ]*|deploy/[^ ]*|config/[^ ]*) ' "$PATCH"; then
  echo "✖ 패치가 라우터·판정·보안 핵심·DB·배포·설정을 건드린다(이번 범위 밖) — 멈춘다"; exit 1
fi
# 패치 파일 **안에서** 센다 — 파일 머리 갈래(뷰·도움말 · 자산 · 그 밖의 src·public·db·deploy·config · 문서·시험·위키). awk 는 파일을 직접 읽는다
PATCHN=$(awk '
  /^diff --git / { p = substr($3, 3)
    if (p ~ /^src\/(views\/shell_top\.php|community\/handlers\/(page_view|home_page)\.php|help\/(community|screens)\.php)$/) v++
    else if (p ~ /^public\/assets\//) a++
    else if (p ~ /^(src|public|db|deploy|config)\//) x++
    else d++
    next }
  END { printf "%d %d %d %d", v, a, x, d }' "$PATCH")
[ "$PATCHN" = "$PATCHN_WANT" ] || { echo "✖ 패치 안 파일 갈래가 검토 받은 판이 아니다(뷰·도움말 / 자산 / 그 밖 / 문서·시험·위키 = ${PATCHN} · 기대 ${PATCHN_WANT}) — 멈춘다"; exit 1; }
[ "$(printf '%s' "$PATCHN" | cut -d' ' -f3)" = "0" ] || { echo "✖ 뷰·도움말·자산 밖의 src·public·db·deploy·config 파일이 패치에 있다 — 멈춘다"; exit 1; }

# SQL 은 표준입력으로(명령 인자 -c 0 — 명령 줄이 한 모양이라 운영 경보 AL-9 가 한 줄로 묶인다). 읽기만 한다
psq() { ( cd / && sudo -n -u postgres psql -X -q -t -A -d "$DB" <<< "$1" ) 2>/dev/null || echo "(query-failed)"; }
code() { curl -s -o /dev/null -w '%{http_code}' -A "$UA" "$@" || true; }
fsha() { local g; g=$(sha256sum "$APP/$1" 2>/dev/null | cut -d' ' -f1 || true); printf '%s' "${g:-(none)}"; }
croutes() { local r; r=$( (grep -E "^[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)'," "$APP/src/community/routes.php" 2>/dev/null || true) | sha256sum | cut -d' ' -f1); printf '%s' "$r"; }
toksets() { local r; r=$( (sed -n '/^const TBOX_API_TOKEN_\(ROUTES\|PERSONAL_ROUTES\|ONLY_ROUTES\) = \[/,/^\];/p' "$APP/public/index.php" 2>/dev/null || true) | sha256sum | cut -d' ' -f1); printf '%s' "$r"; }
route_n() { ( grep -cE "^[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)', *'/" "$APP/public/index.php" 2>/dev/null || true ) | tr -d ' '; }
troutes() { local r; r=$( (grep -E "^[[:space:]]*\['(GET|POST|PUT|PATCH|DELETE)', *'/" "$APP/public/index.php" 2>/dev/null || true) | sha256sum | cut -d' ' -f1); printf '%s' "$r"; }
native_n() {   # 화면 JS 의 브라우저 기본 창 호출(주석 줄 제외) — 0 이어야 한다
  local out; out=$(grep -rnE 'window\.(prompt|confirm|alert)\(' "$APP/public/assets" --include=*.js 2>/dev/null || true)
  printf '%s\n' "$out" | grep -cvE '^$|^[^:]+:[0-9]+:[[:space:]]*(\*|//|/\*)' || true
}
cnt() { local n; n=$( (grep -cF -- "$1" "$2" 2>/dev/null || true) | tr -d ' '); printf '%s' "${n:-0}"; }   # 헤더 함수 — 파일 경로가 마지막 인자
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
hashes_are "$BASE_WANT" || { echo "   ✖ 고칠 파일이 기준 판이 아니다 — 멈춘다(운영 판이 바뀌었으면 pins-uxr1-repo.sh 로 새 블록을)"; exit 3; }
echo "   고칠 파일 $(printf '%s\n' "$BASE_WANT" | grep -c '=') 개 = 기준 판"
NEWF=0
while IFS='=' read -r rel want; do
  [ -n "$rel" ] || continue
  case "$SUMMARY_WANT" in *" $rel"*) [ -e "$APP/$rel" ] && { echo "   ✖ 새로 만들 파일이 이미 있다: $rel"; NEWF=1; } ;; esac
done <<< "$CODE_WANT"
[ "$NEWF" = "0" ] || exit 3
same "$CROUTES_WANT" "$(croutes)" || { echo "   ✖ 커뮤니티 라우트 표가 기준 판이 아니다 — 멈춘다"; exit 3; }
same "$TOKSETS_WANT" "$(toksets)" || { echo "   ✖ 토큰 창구 집합이 기준 판이 아니다 — 멈춘다"; exit 3; }
same "$TROUTES_WANT" "$(troutes)" || { echo "   ✖ t-box 라우트 표 줄이 기준 판이 아니다 — 멈춘다"; exit 3; }
RN0=$(route_n)
[[ "$RN0" =~ ^[1-9][0-9]*$ ]] || { echo "   ✖ t-box 라우트 줄을 세지 못했다 — 멈춘다"; exit 3; }
echo "   커뮤니티 라우트 표 · 토큰 창구 집합 · t-box 라우트 표 줄 = 기준 판 · t-box 라우트 ${RN0}줄 · 패치 안 파일 갈래 ${PATCHN}"
echo "   DB 변경 없음(이 스크립트는 DB 에 쓰지 않는다 · 아래 감사 행 수를 읽기만)"
git -C "$APP" apply --check "$PATCH" || { echo "   ✖ git apply --check 실패 — 멈춘다"; exit 3; }
echo "   git apply --check: ok (${GOT:0:16}) · 파일 $(printf '%s\n' "$PATHS" | wc -l | tr -d ' ')개(새 파일 ${NEW_N})"
BUSY=$(psq "SELECT count(*) FROM audit_logs WHERE happened_at > now() - interval '10 minutes' AND actor_name IS NOT NULL AND actor_name NOT LIKE 'qa\_%' AND actor_name NOT LIKE 'claude\_%'")
echo "   최근 10분 검토 계정이 아닌 사람의 감사 행: $BUSY (본 실행은 0 이어야 — 쓰는 사람 밑에서 코드를 바꾸지 않는다)"
if [ "$DRY" -eq 1 ]; then echo "== [dry] 여기까지. 다음: 패치 → 확인(실패하면 되돌림)"; exit 0; fi
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
  same "$RN0" "$(route_n)" && same "$TROUTES_WANT" "$(troutes)" && same "$TOKSETS_WANT" "$(toksets)" && same "$CROUTES_WANT" "$(croutes)" && echo "   ✔ 라우트 그대로(t-box ${RN0}줄 · 표 줄 · 토큰 창구 · 커뮤니티 = 기준 판)"
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
chk "t-box 라우트 줄 수 그대로(새 라우트 0)" "$RN0" "$(route_n)"
chk "t-box 라우트 표 줄 그대로" "$TROUTES_WANT" "$(troutes)"
chk "토큰 창구 집합 셋 그대로" "$TOKSETS_WANT" "$(toksets)"
chk "커뮤니티 라우트 표 그대로(새 창구 0)" "$CROUTES_WANT" "$(croutes)"
chk "화면 JS 의 브라우저 기본 창 호출(window.prompt( · window.confirm( · window.alert( — 주석 제외)" "0" "$(native_n)"
# 파일 읽기만(요청 0 · 감사 0) — 이 묶음의 자리가 반영된 트리에 있나
chk "셸 — 윗줄 앱 이름 단추 · 검색 · 알림 종" "1 1 1" \
  "$(cnt '<button id="appbar-apps" class="appbar__brand"' "$APP/src/views/shell_top.php") $(cnt '<button id="appbar-search" class="btn btn--icon btn--plain"' "$APP/src/views/shell_top.php") $(cnt '<button id="appbar-bell" class="btn btn--icon btn--plain"' "$APP/src/views/shell_top.php")"
chk "페이지 — 제목 칸 자리표시 · 더보기 사용 방법·이력 / 홈 — 「최근」 판" "1 1 1 1" \
  "$(cnt 'placeholder="제목" enterkeyhint="next">' "$APP/src/community/handlers/page_view.php") $(cnt '<button id="page-more-help" class="menu__item"' "$APP/src/community/handlers/page_view.php") $(cnt '<button id="page-more-history" class="menu__item"' "$APP/src/community/handlers/page_view.php") $(cnt 'id="community-home-recent"' "$APP/src/community/handlers/home_page.php")"
chk "세션 확인 도우미 한 곳(app.js) · 편집기가 부름 · 기본 제목 표" "1 1 1" \
  "$(cnt 'window.qarediSessionProbe = probeSession;' "$APP/public/assets/app.js") $(cnt "typeof window.qarediSessionProbe === 'function' ? window.qarediSessionProbe : null" "$APP/public/assets/community/editor.js") $(cnt "const DEFAULT_TITLES = ['제목 없는 페이지', '제목 없는 데이터베이스', '제목 없는 글'];" "$APP/public/assets/community/editor.js")"
# HTTP — 화면·자산 요청만(감사 행 0). 주소는 고정 글자
if SC=$(curl -s -A "$UA" -o "$T/app.js" -w '%{http_code}' "$L8082/tbox/assets/app.js"); then :; else SC="(curl-failed)"; fi
chk "자산 app.js 200 · 새 글자(probeSession · 자동 제출 · 자동 요청이 부른 확인의 표식)" "200 1 1 1" "$SC $(cnt 'function probeSession(expectUser, sentToken, opts) {' "$T/app.js") $(cnt 'form.requestSubmit(submit || undefined);' "$T/app.js") $(cnt 'init.headers[BACKGROUND_HEADER] = BACKGROUND_VALUE;' "$T/app.js")"
if SC=$(curl -s -A "$UA" -o "$T/editor.js" -w '%{http_code}' "$L8082/tbox/assets/community/editor.js"); then :; else SC="(curl-failed)"; fi
chk "자산 community/editor.js 200 · 새 글자(기본 제목 표 · 「최근」 판 · 보낸 토큰으로 견줌)" "200 1 1 1" "$SC $(cnt "const DEFAULT_TITLES = [" "$T/editor.js") $(cnt 'function drawHomeRecent(pages) {' "$T/editor.js") $(cnt 'if (sent.token !== csrfToken) { return again(); }' "$T/editor.js")"
chk "자산 search.js 200" "200" "$(code "$L8082/tbox/assets/search.js")"
chk "미로그인 커뮤니티 화면 302(로그인으로)" "302" "$(code "$L8082/community/")"
chk "t-box 로그인 화면 200" "200" "$(code "$L8082/tbox/login")"
chk "런처 200" "200" "$(code "$L8082/")"
LOGADD=$(tail -c +"$((LOG0 + 1))" "$APPLOG" 2>/dev/null || true)
chk "app.log — 이 실행 동안 더해진 uncaught · Fatal 0" "0" "$( (grep -cE 'uncaught|Fatal error' <<< "$LOGADD" || true) | tr -d ' ')"
if [ "$ok" -ne 1 ]; then rollback "확인 실패"; fi
trap - ERR INT TERM HUP
echo "== 끝 — 다음: 운영 브라우저 스모크(claude_employee01 · 폰 412 — 새 페이지 제목 칸 · 윗줄 종·검색·앱 이름 · 블록 창 · 로그인 코드 자동 제출) →"
echo "   파일 지정 커밋(작성자 claude) → scripts/release.sh minor \"사용감 1\" \"휴대폰에서 새 글을 제목부터 바로 쓰고, 알림·검색·앱 전환이 맨 위 줄에 보입니다\""

# 실제 route 기준 기능 감사 범위 교차검토

기준 `/var/www/qaredi/tbox-v2` HEAD `93668ff`. PHP/worker/app 코드 실행·운영 설정·DB 조회·API 호출 없이 route 및 source 텍스트만 읽음. 기존 감사·앱 파일 수정 없음. 기계적 추출 근거는 같은 폴더 `route-inventory.json`.

## 수량과 방법

| 표 | method+path 선언 행 수 | 고유 path pattern | 고유 handler/action | 메서드 구성 |
|---|---:|---:|---:|---|
| TBOX_ROUTES `/var/www/qaredi/tbox-v2/public/index.php:38` | 178 | 140 | 122 | GET91 POST56 DELETE17 PATCH12 PUT2 |
| COMMUNITY_ROUTES `/var/www/qaredi/tbox-v2/src/community/routes.php:77` | 206 | 151 | 136 | GET94 POST54 DELETE27 PATCH25 PUT6 |
| MCP_ROUTES `/var/www/qaredi/tbox-v2/src/mcp/routes.php:5` | 11 | 10 | 10 | GET6 POST4 DELETE1 |
| 선언 표 합 | **395** | 모듈별 합301 | 모듈별 합268 | 기능 수가 아님 |

상수 배열 시작부터 닫는 `];`까지만 읽고 실제 선언 `[method,path,handler,policy]` 행 추출. 문서·주석·token허용목록·gate허용목록의 반복 경로는 세지 않음. 모듈 prefix는 Tbox `/tbox`, community `/community`, MCP는 이미 전체 경로. 같은 path의 GET/PATCH는 서로 다른 route지만 보통 한 사용자 기능이다. handler수도 기능수와 다르다. action형 MCP handler는 파일 수가 아니다.

표 외 dispatch:

- 사이트 `GET /` 런처: `/var/www/qaredi/tbox-v2/public/index.php:829`, `/var/www/qaredi/tbox-v2/src/site/launcher_page.php:5`. Tbox 표의 GET `/`는 실제 `/tbox/` 자료실이며 별도 기능.
- `POST /mcp`: `/var/www/qaredi/tbox-v2/src/mcp/dispatch.php:11`, `/var/www/qaredi/tbox-v2/src/mcp/RemoteMcp.php:19`. 표11개와 별도 JSON-RPC transport. initialize/ping/tools/list/tools/call은 HTTP route가 아니라 RPC method.
- HTML/API 404 사이트 handler는 fallback이지 새로운 사용자 기능 아님. HEAD 런처 처리, assets, 정적 문서/PWA files, cron/CLI worker는 선언395에 합산하지 않음. 따라서 **395가 전체 실행 surface 수라는 주장도 아님**.

분류는 먼저 사용자 목적(자료·업무·협업·공간·보안·AI·통합·공개·관리)으로 묶고, 읽기/쓰기/관리/공개/조건부 설정을 덧붙임. 메뉴에 없는 API라도 route가 있으면 포함 후보. 메뉴명만으로 지원기능/활성 여부 확정하지 않음. 전체 페이지·보안 modal·백그라운드 상태도 기능이며 전부 위젯으로 바꿀 필요 없음.

## 발견된 추가/경계 항목

| 항목 | 정확한 source 근거 | 세 감사 내 포함 위치 / root 조치 |
|---|---|---|
| 별도 서버 비서 실행 API·job lifecycle | `/var/www/qaredi/tbox-v2/public/index.php:85`; `/var/www/qaredi/tbox-v2/src/handlers/assistant_runs.php:9`; `/var/www/qaredi/tbox-v2/src/assistant/Provider.php:18` | **site-integrations 감사의 명시적 누락**. root taxonomy에 `assistant.run` 별도 추가: chat, run생성/목록/조회, SSE events, cancel, confirm. Provider enable 필요; 개인Gemini메신저/communityAI/localChatGPT와 구분. token scope·ownrun권한. S 최근job상태, M 실행목록, L 상세로그; 실행·confirm은 전용 UX |
| 관리자 휴대폰 경보·PWA·WebPush | `/var/www/qaredi/tbox-v2/public/index.php:442`; `/var/www/qaredi/tbox-v2/src/handlers/push_app_page.php:17` | **site-integrations 명시적 누락**, Tbox 최종감사24번에 경보·WebPush·기기등록/해지/시험·조건부준비 포함 확인. site 본문에 없지만 세보고서 합산에서는 누락 아님. Admin+재인증, 준비표/공개키/worker/브라우저permission조건. 사용자community알림·1:1unread·externalhook와 구분; 설치·기기등록·테스트·해지 전용페이지 |
| 사이트 가입초대·관리자 installer/Gemini 설정 | `/var/www/qaredi/tbox-v2/public/index.php:69`; `/var/www/qaredi/tbox-v2/public/index.php:72`; `/var/www/qaredi/tbox-v2/public/index.php:79` | site onboarding/조건부AI의 관리면. root가 global가입초대와 product공간초대를 별도항목으로 표시. Admin/member관리 감사 포함확인 |
| 전체 조직·권한정책·역할로 보기 | `/var/www/qaredi/tbox-v2/public/index.php:313`; `/var/www/qaredi/tbox-v2/public/index.php:406`; `/var/www/qaredi/tbox-v2/public/index.php:421` | Tbox 관리 담당. global조직/team/permissions는 productcompany/group membership와다름. root taxonomy에 권한정책관리·act모드·조직명부 독립 확인 |
| 감사·관리경보·삭제요청·토큰관리 | `/var/www/qaredi/tbox-v2/public/index.php:182`; `/var/www/qaredi/tbox-v2/public/index.php:425`; `/var/www/qaredi/tbox-v2/src/handlers/admin_deletion_requests.php:1` | Tbox 보안/관리 담당. 제품 위젯이 아니라 Admin상태·조치목록 후보. 삭제요청승인/권한변경은 전용modal/페이지 |
| URL 가져오기·허용host 관리 | `/var/www/qaredi/tbox-v2/public/index.php:465`; `/var/www/qaredi/tbox-v2/src/handlers/files_fetch.php:1` | Tbox 자료+관리. 외부data connector와 다름. root fetch기능/허용host/원본파일integrity를 누락하지 않도록 확인 |
| 파일 휴지통·복원·미리보기/raw·zip·실행파일 | `/var/www/qaredi/tbox-v2/src/handlers/files_restore.php:1`; `/var/www/qaredi/tbox-v2/src/handlers/files_preview_raw.php:1`; `/var/www/qaredi/tbox-v2/src/handlers/files_zip.php:1`; `/var/www/qaredi/tbox-v2/src/handlers/files_exec_download.php:1` | Tbox 자료 담당. 파일리스트·업로드만의 범주로압축하면 주요행동 누락. 내려받기/실행승인별권한 보존 |
| 본인공유링크·익명capability파일다운로드 | `/var/www/qaredi/tbox-v2/public/index.php:360`; `/var/www/qaredi/tbox-v2/src/handlers/share_download.php:1` | Tbox 공개/자료 담당. productspaceinvite·communitypublication·MCPscope와다름; share발급/해제/만료관리와 공개열람 별도 |
| 공개 communitypublication·익명form | `/var/www/qaredi/tbox-v2/src/community/routes.php:122`; `/var/www/qaredi/tbox-v2/src/community/routes.php:124`; `/var/www/qaredi/tbox-v2/src/community/routes.php:125` | Community 담당 확인. 페이지publish/unpublish와 외부웹publication은 별도; publicform POST존재. 화면조각 아닌 audienceboundary 기능 |
| communitygroups·개인collections·groupshare | `/var/www/qaredi/tbox-v2/src/community/routes.php:101`; `/var/www/qaredi/tbox-v2/src/community/routes.php:108`; `/var/www/qaredi/tbox-v2/src/community/routes.php:113` | Community 담당 확인. productspaces/company/group와 명칭이 같아도 데이터/권한 다름 |
| 제안 accept/reject/withdraw·인수인계·버전restore | `/var/www/qaredi/tbox-v2/src/community/routes.php:89`; `/var/www/qaredi/tbox-v2/src/community/handlers/pages_handover.php:1`; `/var/www/qaredi/tbox-v2/src/community/handlers/version_restore.php:1` | Community 업무협업 담당. 글수정/댓글 기능에묻히지 않게 root가 별도상태/승인함으로 정리 |
| DB schema/rows/views/chart/summary 및 forms | `/var/www/qaredi/tbox-v2/src/community/routes.php:98`; `/var/www/qaredi/tbox-v2/src/community/handlers/database_views.php:1`; `/var/www/qaredi/tbox-v2/src/community/handlers/database_summary.php:1` | Community 업무·자료 담당. 표보기뿐 아니라 view/form/chart/속성변경·응답수집 포함 |
| 문서resolve/helpful/hide·reads/heartbeat | `/var/www/qaredi/tbox-v2/src/community/handlers/comment_resolve.php:1`; `/var/www/qaredi/tbox-v2/src/community/handlers/comment_helpful.php:1`; `/var/www/qaredi/tbox-v2/src/community/handlers/page_reads.php:1`; `/var/www/qaredi/tbox-v2/src/community/handlers/pages_heartbeat.php:1` | Community 담당. 댓글텍스트와 독립 workflow/열람추적. 위젯읽기만으로기존문서heartbeat나읽음이쌓일지 다음설계에서 결정 |
| portfolio/projectstatus/automations dryrun·run·undo | `/var/www/qaredi/tbox-v2/src/community/routes.php:171`; `/var/www/qaredi/tbox-v2/src/community/handlers/project_status.php:1`; `/var/www/qaredi/tbox-v2/src/community/handlers/automation_runs_undo.php:1` | Community 업무 담당. 프로젝트진행/자동화상태위젯가능; undo·run 실행은명시action |
| memories photo/social/AI·travelplans linkedrecords | `/var/www/qaredi/tbox-v2/src/community/routes.php:78`; `/var/www/qaredi/tbox-v2/src/community/routes.php:85`; `/var/www/qaredi/tbox-v2/src/community/routes.php:139`; `/var/www/qaredi/tbox-v2/src/community/handlers/memories_ai.php:1` | Community 생활·여행 담당. productstory 텍스트나원격MCP연결 자체로 대체안됨 |
| community GPT messenger | `/var/www/qaredi/tbox-v2/src/community/routes.php:136`; `/var/www/qaredi/tbox-v2/src/community/handlers/messenger_api.php:1` | Community 담당 확인. 사이트1:1messenger+memberAI와별도. site감사에서implicitAI전체로뭉친지 root 분리확인 |
| 사용guide·wiki·procedures·palette/embed 관리 | `/var/www/qaredi/tbox-v2/src/community/routes.php:85`; `/var/www/qaredi/tbox-v2/src/community/routes.php:199`; `/var/www/qaredi/tbox-v2/src/community/routes.php:291`; `/var/www/qaredi/tbox-v2/public/index.php:336` | Community guide/procedures/Adminpalette와 Tboxwiki 각각 담당. static안내도사용기능. domain허용목록은외부embedding trust boundary |
| health/time/manifest/sessionopen | `/var/www/qaredi/tbox-v2/public/index.php:98`; `/var/www/qaredi/tbox-v2/public/index.php:100`; `/var/www/qaredi/tbox-v2/public/index.php:254`; `/var/www/qaredi/tbox-v2/public/index.php:390` | Tbox 인프라/인증 담당. 사용자widget으로직접세지않음; 기능의조건/설치/보안보조 경로로 분류 |
| qf12/qf13 remote floor/server/shell/work·feedback | 현재 세route표 및 `src` 파일 검색에서 matching routes/handlers **없음** | root 확인필요. 다른 checkout/서비스/상위배포모듈의기능이면 별도기준·source필요. docs/test이름만으로 이배포의구현으로포함하지 않음; 없다고제품전체미구현이라고단정하지도않음 |

handler근거는 모두 `/var/www/qaredi/tbox-v2` 기준. 기계적인 전체 route 목록은 JSON으로 함께 전달한다.

## 교차검토 상태

Community 담당 범위 답변 및 작성중 `community.md` 본문을 교차 확인했다. collections/groups/publications/publicforms/suggestions/usageguide/trustverify/adminpalette/webpublish 및 DB/forms/프로젝트/자동화/버전/인계가 모두 포함되어 있다. community GPT API는 화면호출없는 휴면경로로 별도 row 추가중이며, active ChatGPT 대화위젯으로분류하면 안 된다. Tbox 최종 `tbox.md` 본문과 담당답변을 확인했다. 자료·권한요청·publicshare·활동·휴지통·프로그램·계정/보안·Admin/조직/권한/호스트/감사/경보/push/wiki/health가 포함되어 있다. 서버 `/api/assistant/chat+runs` 상세는 site/root범위로 넘긴 상태이므로 이coverage의추가행으로보완한다. root는 제출된 Tbox/community최종본문과 위표를 비교해 누락을흡수하면 된다. **서버비서runs**는 기존세본문의 상세누락이므로 이coverage에보완했다. AdminWebPush는 site에없어도Tbox24번에포함된다. 기존보고서파일을수정하지않고 이coverage에명시했다.

최종 대조: 세보고서 파일 모두 확인 완료. community GPT 휴면경로는 community.md에별도표시됨. `/community/api/feedback`은 Tbox담당이상위범위로넘겼으나 현재 deployed route/source에서없어root가별도서비스/checkout을확인해야한다. qf12/qf13/feedback를현재395선언으로셀수없다. 기계목록에빈handler는없으며 개별실행검증은이번읽기감사범위밖이다.

Root 추가 확인: 기준 checkout의 `public/index.php`, `src`, `config`에서 다시 검색했다. `Q:F-12/Q:F-13` 같은 기획·시험 식별자는 별도 사용자 기능이나 URL로 세지 않는다. 관련 실제 업무 셸·계정/동의 기능은 도메인 감사에 포함했다. `/community/api/feedback`이라는 정확한 경로는 이 코드에 없으므로 현재 위젯 원본으로 산정하지 않는다. 다른 서비스의 구현 여부까지 단정하지 않는다. 라우트 JSON의 선행 빈줄 때문에 1줄 어긋난 세 항목(`/join`, `/api/messenger/gpt`, `/automations`)은 각각 66/136/177로 정정했으며 395개 원문 행 대조를 모두 통과했다.

## 서버 비서 추가 source 대조 (최종 보완)

`/var/www/qaredi/tbox-v2/src/assistant/Provider.php:25`는 AI 공급자 미연결 오류를정의한다. `:28`~`:47`에서 mode=tool은native로실행하고 일반추론은명시적격리test adapter만허용한다. `:50`~`:53` capability는provider unconfigured/test, inference_available=false, worker_required=true이다. 운영enabled설정은읽지않았으며, 기능flag만켜지면일반LLM추론이가능하다고해석하면안된다.

반면 `/var/www/qaredi/tbox-v2/src/assistant/Chat.php:7`~`:27`은개인token MemberAi/Gemini어댑터다. 실제MemberAi동의·키·quota를따르는다른chat경로이고, runs일반추론미연결상태와서로대체하지않는다. `/var/www/qaredi/tbox-v2/public/index.php:85`~`:92`의assistant.run창구는개인프로그램token실행계약으로정리한다. 웹세션위젯용최근job이력은새조회계약필요; token을layout저장하거나기존browser가즉시호출한다고가정하지않는다.

# 메신저 공통 진입·창 이동

- 상태: 구현·독립 검토 완료, 운영 반영 전
- 브랜치: cloud/messenger-movable
- 기준: 01114a9fd667cfd6c58124a81c8c44d6a10a618d / v2.58.2
- 담당: Codex root·창구현·레일구현·독립검토

## 요구와 범위

메신저를 PC 공통 아이콘줄로 옮기는 제안을 사용자가 승인하고 떠 있는 창을 편한 위치로 이동하게 요청했다. 고정 위치를 기본값으로 남기면서 상단 드래그·키보드·원위치·사용자별 같은탭 기억·좁은화면 보정을 제공한다. 모바일은 기존 아래시트, 상단 말풍선 유지. 계약 docs/messenger-movable-directive.md. 기존 대화·초안·인가·API·원문·개인취향 보존.

## 변경과 검증

완료 시 파일/정확범위/명령결과·미검증 기록. 운영권한은 기존승인범위에서 root가 수행. 운영 메시지전송0, DBmigration/설정/패키지변경0 목표. 암호화백업3종/복호화·baseline·후보고정/모의/drift·배포/HTTPS/보존·push. 실패시 증거유지 후 코드보정, 운영자료 restore/reseed/delete0. 실물기기/Safari/스크린리더는 별도.

## 구현 결과와 검증

런타임 6파일: messenger.js는 위치 전용 제어기/같은 탭 사용자별 sessionStorage 숫자키·취소/화면경계, messenger_window.php는 이동제목/원위치, app.css는 기존 토큰 기반 손잡이/레일, app.js는 로그아웃 위치키 정리 한 줄, shell_top.php는 공통 레일/모바일 제한계정 안내, messenger-access.js는 compact 진입점의 긴 이용제한 문구만 생략한다. 기존 API/DB/암호화 초안·대화 state shape 변경0. 위치 저장은 완료된 드래그당 한 번이며 화면 크기 보정은 선택한 좌표를 덮어쓰지 않는다.

- 자체 창 actual PHP/production JS·strict CSP 216/0: 실제 포인터·방향키·reset·종료6종·계정전환/로그아웃·저장소거부/손상·암호화초안·84 테마/밝기/폭 조합 머리 버튼 폭/겹침 확인.
- 자체 레일 actual PHP/production JS 470/0: 자료실/업무/이야기×직원/손님/역할로보기×1280/960/959/390에서 단일 가시 진입·배지·문맥·초점·제한 안내·가로폭 확인. 기존 격리 qf12 shell c2/mobile 173/0.
- 초기 격리 공통196/0. 운영에 쓰는 옛 shell wrapper 대신 synthetic DB harness이며 해당 process/DB 정리 완료.
- PHP/JS 문법·git diff --check, 보안 lint 금지0, 자산40파일/71imports, 위키69편 통과.

첫 레일 시험에서 제한계정959px의 '이용 제한' small이 967.9px로 넘쳤다. compact 두 진입점만 inline 문구를 생략하고 title/aria와 기존 안내 dialog를 유지해 해결했다. qf12의 옛 sidebar 위치/순서 기대값과 window t16의 옛 모바일 drawer 대상을 승인된 현재 구조에 맞췄으며 실패 로그를 남겼다.

## 반영 준비와 한계

selftest-base/messenger-movable·review-base/messenger-movable은 첫 검사 전에 기준01114a9에 생성, baseline bundle 보관. /var/backups/tbox-v2/product-spaces-messenger-movable-20261012에 DB/코드/설정·storage 암호화3종 checksum 및 전체복호화 확인. 기존14표·제품6표·메신저5표 모든 원래행(기존 QA 포함, 제외0), 파일1042행/959원본SHA·ownerACL·설정85 baseline. scheduled worker cursor1만 기존 운영상태로 제외.

실물휴대폰·Safari·스크린리더·사용자 자기 공격은 미검증. 실제 대화 전송은 의도적으로 수행하지 않는다. 합성 API에서의 권한/초안 검사를 운영 전송 검증으로 과장하지 않는다. 운영은 root가 고정후보/정확파일모의/fresh drift→FF/release/FPM→HTTPS 실제 드래그·보존→기록·atomic push 순서로 진행한다.

## 최종 경계 보정과 기존 회귀

CDP4배 확대(visual320/layout1280)에서 제목 flex폭이0이 되어 손잡이와 원위치가 겹침을 발견했다. PC 머리 flex-wrap/제목 min-width만 보정하고 일반400px 한 줄을 유지했다. 최종 CSS 별도 delta180/0: 84테마/밝기/폭 fit·겹침168항목, CDP1/2/3/4/1배10항목, CSP/쓰기0 2항목. 이전 자체216/0 전부를 최종 CSS로 재실행했다고 주장하지 않으며 변경범위를 별도 검증했다.

기존 격리 창 t12 39/0(페이지이동/상대404/AIfocus), t14·15 7/0(로그아웃/계정전환 등), 해당 초기 fixture10 통과. 그 뒤 옛 t16 drawer 기대값 실패는 native modal 의미로 정합하여 해당 t16 최종135/0(기능125+fixture10), t17 23/0(모달층/창규칙), 저장·암호화 store75/0. 모듈 없는 app.js cleanup5/0 추가. 운영에 메시지를 보내지 않았으며 이 격리시험의 합성 전송과 구분한다.

최종 독립 검토 완료: 이동53/0·제한진입57/0·최종CSS 2/4배확대42/0, 별도 레일470/0·초기공통196/0. 최종 runtime6 SHA가 root와 정확히 일치했다. 4부보고 outputs/messenger-movable-review/review.md, source-sha256.json. 차단0/추가수정0.

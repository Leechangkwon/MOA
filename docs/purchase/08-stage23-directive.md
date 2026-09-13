# 23단계 구현 지시서 (Fable → Opus) — 발주 제안 · 부족 알림 · 경보 AL-14~17 완성 · 마무리 정리

작성 2026-09-13. 근거: PRD F-36, ARCH 5.18(경보 후보 AL-14~17), SECURITY R-65 잔여(린트 예외 파일 단위)·R-72(vendor_ambiguous 는 반영됨), STAGES 19~22단계 학습 기록의 「23단계로」 항목, 시안 Status(발주 제안 열).

```
목표        : 장소별 재고현황의 「발주 제안」 열을 실제 값으로 채우고(⌈(인가+안전−현재고−미입고+조달기간 예상 사용)÷최소단위⌉×최소단위), 제안을 한 번에 발주요청 전표로 만들며,
              경보 AL-14(보낸 뒤 수정)·AL-15(미입고 30일)·AL-16(단가 급변)·AL-17(대량 취소)을 임계값·문장·알림 채널까지 완성한다. 19~22단계에서 미룬 정리 항목을 닫는다.
요구사항    : PRD F-36·T-48(신설), ARCH 5.18·11.4(경보 표 AL-14~17 행 추가), SECURITY R-65·R-72, docs/purchase/03(제안 식), 시안 Status — 읽고 시작.
수정 파일   :
  [새로 만듦]
    src/purchase/Suggest.php — 제안 계산(품목×장소): 인가량(p_par_levels 최신) + 안전재고(p_items.safety_qty) − 현재고(p_stock_balance) − 미입고(p_backorder 그 장소 to_location) + 조달기간 예상 사용(주평균 사용 × lead_days/7) → 최소구매단위(min_order_qty)로 올림. 음수·0 은 제안 없음. 근거 값 전부 응답에 실음(화면이 계산하지 않음)
    src/handlers/purchase_suggest.php(GET /api/purchase/suggest?location=&branch=&cat1=&only_short=1) · purchase_suggest_apply.php(POST /api/purchase/suggest/apply — 선택한 제안을 PR 전표로: Doc.php 재사용, 담당=요청자, 수량은 제안값 또는 수정값(서버가 최소단위 배수 검사))
    tests/purchase_stage23.sh — 관문·정리·S23- 접두(R-73)
  [고침 — 범위만]
    src/handlers/purchase_stock_status.php(제안 열 = Suggest 값, 근거 툴팁용 필드) · public/assets/purchase_stock.js·purchase_stock.css(제안 열·「선택 제안을 발주요청으로」 단추·근거 표시) · src/views/purchase_stock.php
    scripts/alerts.php — AL-14(보낸 뒤 수정·취소: 즉시) · AL-15(미입고 30일: 매일, 거래처별 묶음 문장) · AL-16(단가 급변: 입고 단가가 품목 price_in 대비 ±20% 초과 또는 대사 price 차이) · AL-17(대량 취소: 한 사람이 1시간에 void 5건 이상). 기존 경보 골격의 임계값 상수·문장 틀·중복 억제(같은 대상 24시간 1회) 규칙을 따른다
    ARCHITECTURE.md 11.4 표에 AL-14~17 행, 7.4 표에 purchase.* 요약 행(22단계가 넣지 않았으면)
    scripts/security_lint.sh — 규칙 (a) 예외 표식 `lint:no-branch-scope` 를 **파일 단위에서 「그 줄 바로 위 주석」 단위로** 좁힌다(표식 다음 줄의 SELECT 만 예외). 기존 예외 파일들의 표식 위치를 그에 맞게 옮긴다(동작 무변경, 린트 0 유지)
    src/handlers/purchase_items_update.php · public/assets/purchase.js(품목 상세의 사용 지점 「빼기」 = p_item_branches.is_active=false — 20단계 마이그레이션에 칸이 있음) — purchase.js 는 이 기능 한 곳만
    src/Audit.php(문장 틀 purchase.suggest.apply · alert 문장), PRD.md 12.2 T-48 행·13장 23단계 상태, README, STAGES(23단계 절·체크), db/grants.sql·schema.sql(표 추가 없으면 무변경)
금지 패턴   : SQL 결합, innerHTML, 화면에서 제안 계산(서버 값만), Authz 밖 판정, p_* DELETE, 새 라이브러리, 사용자 활동 중 시험, 자격증명 cat, 경보 문장에 개인정보(계정명·전표 번호·품목 코드까지만).
완료 기준   : T-48 — (a) 인가 10·안전 2·현재고 3·미입고 2·주평균 7·조달 7일·최소단위 5 → 제안 = ⌈(10+2−3−2+7)÷5⌉×5 = 15, 근거 값 응답 일치 ; 현재고가 충분하면 제안 0·목록에서 제외(only_short) ; requester 는 자기 지점 장소만 ; (b) 제안 3건 apply → PR 전표 1건·줄 3, 수량이 최소단위 배수 아니면 400 not_multiple ; (c) 경보 dry-run: AL-14 1건·AL-15(sent_at 31일 전 픽스처) 1건·AL-16(단가 +30% 입고) 1건·AL-17(void 5건) 1건, 같은 대상 재실행 시 중복 0 ; (d) 린트 0(예외 표식 줄 단위로 바꾼 뒤) ; (e) 회귀 stage19(T-42~44 절만도 가능)·20·21·22 스크립트 관문 열릴 때 ; (f) 사용 지점 빼기 → is_active=false, 품목 검색 branch_used 에서 제외.
보고 형식   : 변경 파일 / 명령·출력 원문 / 못 한 것 / 13장 체크리스트 / 사용자가 볼 것 3줄. 커밋 금지.
```

## 보충 규칙
1. 제안 식의 「조달기간 예상 사용」은 Usage.php(22단계) 의 주평균을 재사용한다. 주평균 데이터가 없으면 0 으로 두고 응답에 `usage_basis: none`.
2. 제안 apply 로 만든 PR 은 memo 에 「발주 제안 YYYY-MM-DD」, 감사 detail 에 `suggested_qty`·`applied_qty`·`lines`.
3. 경보 문장은 기존 alerts.php 문장 규칙(ARCH 11.4)을 따르고, AL-15 는 거래처별 1문장(「거래처 X 발주서 N건 미입고 30일 초과」).
4. 린트 예외 표식 변경은 **한 커밋 안에서 표식 이동 + 규칙 변경**을 같이 해야 린트가 0 을 유지한다 — 순서: 표식을 줄 위로 옮김 → 규칙 변경 → 린트.
5. 시험 안전 R-73 규칙 그대로.

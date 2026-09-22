# 노션의 구조 분석과 커뮤니티 개발 설계 (v0.1, 2026-09-22)

> 목적: "노션처럼 블록으로 쓰는 개인 업무 공간 + 커뮤니티 + 인계" 를 만들기 전에, **노션이 실제로 어떻게 짜여 있는지**를
> 근거 있게 파악하고, 그중 무엇을 어떻게 가져올지를 정한다. 이 문서의 결정은 PRD v0.2 로 옮겨 고정한다.
>
> **근거 (전부 2026-09-22 확인)**
> - 노션 공개 API 문서: 블록 객체(`/reference/block`) · 리치 텍스트(`/reference/rich-text`) · 데이터베이스 속성(`/reference/property-object`) · 요청 한도(`/reference/request-limits`)
> - 노션 MCP 의 「Notion-flavored Markdown」 명세(`notion://docs/enhanced-markdown-spec`) — 블록·인라인·색·멘션·표·토글·콜아웃·컬럼·탭·데이터베이스·동기화 블록의 **현재** 문법
> - 사용자 워크스페이스 실측 5건: 「AICE Associate 스터디」(루트, 하위 페이지 7개) · 「CH03-3·4절 결측치」(콜아웃·제목·목록·코드·토글) · 「스터디 일정표」(머리행 표 43행, 행 색) · 데이터베이스 「MAIN」(TO-Do: 상태 그룹·담당자·관계·롤업·수식·템플릿·표 뷰) · 「플란치과 ERP 외부 API 명세서」(제목·인용·구분선·표 20여 개·코드)

---

## 1. 노션은 일곱 층이다

| 층 | 노션 | 우리 워크스페이스에서 실제로 쓰인 것 | 우리 대응 (요약) |
|---|---|---|---|
| ① 워크스페이스 · 팀스페이스 | 회사 하나 = 워크스페이스. 그 안에 팀스페이스(공개/비공개), 개인 「Private」 구역 | 팀스페이스 「류스 L의 Notion HQ」, Private 에 스터디 루트 페이지 | **공간(space)**: 개인 공간(사용자마다 1개, 자동) · 팀 공간(t-box 팀마다) · 게시판(공유 공간). 등급·권한은 t-box 매트릭스 |
| ② 페이지 트리 | **페이지도 블록이다**(`child_page`). 부모는 페이지·데이터베이스·워크스페이스 중 하나. 아이콘·표지·제목. 깊이 제한 없음 | 루트 → PART(5) → CH(40여 개) 3단 | 페이지 트리, **깊이 ≤ 8**. 아이콘(이모지)·표지(t-box 이미지) |
| ③ 블록 트리 | 페이지 본문 = 블록의 순서 있는 트리. 블록마다 `id`·`type`·`parent`·`has_children`·생성/수정자·시각·`in_trash`. 자식을 가질 수 있는 유형이 정해져 있다 | 문단·제목·글머리·번호·코드·콜아웃·토글·표·구분선·인용·하위 페이지·빈 블록 | 블록 JSON 트리(3장 카탈로그). 블록마다 `id`(UUID) — 댓글·역참조·이력의 앵커 |
| ④ 리치 텍스트 | 문장 = **세그먼트 배열** `[{type: text\|mention\|equation, annotations{bold,italic,strikethrough,underline,code,color}, href}]`. 색은 이름 9종 + 배경 9종 | 굵게, 인라인 코드, 색 배경 콜아웃, 링크 | 같은 모델. 색은 **이름**으로만(4장) |
| ⑤ 데이터베이스 | 데이터베이스 → 데이터 소스(스키마: 속성 21종) → 행 = **페이지** → 뷰(표·보드·타임라인·캘린더·리스트·갤러리, 필터·정렬은 뷰 설정) → 템플릿 | 「MAIN」: 제목·텍스트·상태(그룹 to_do/in_progress/complete)·담당자(person)·기간(date range)·완료일·관계(SUB·category)·롤업 2·수식(진행률)·템플릿 9·표 뷰 | 6단계 이후. 속성 **서브셋**(5장) — 수식·롤업 제외 |
| ⑥ 협업 | 인라인 댓글(문장 선택 → 스레드), 페이지 댓글, `@멘션`(사람·페이지·날짜), 자동 **백링크**, 동기화 블록, 실시간 공동 편집 | 「질문은 댓글로 — 문장을 드래그해 댓글」 운영 규칙 | 블록 단위 댓글(문장 선택 대신 **블록 앵커**), 멘션 3종, 백링크. 실시간 공동 편집 **제외**(7장) |
| ⑦ 권한 · 이력 · 휴지통 | 워크스페이스 역할(소유자·멤버·게스트) + 페이지 공유(전체·편집·댓글·보기, 트리 상속) + 웹 공개. 페이지 이력(버전), 휴지통 30일 | — | 공개 범위 4종(비공개·팀·사내·게시), 트리 상속·좁히기만, 버전 스냅샷, 휴지통 30일 |

**한 문장 요약**: 노션은 「모든 것이 블록이고, 페이지는 자식을 가진 블록이며, 데이터베이스 행도 페이지」인 **단일 트리**다. 우리도 이 원리를 따르되, 실시간 공동 편집·수식·롤업·동기화 블록·웹 공개처럼 비용이 큰 것은 뺀다.

---

## 2. 노션의 한도 (참고용 상한선)

API 문서의 한도는 곧 노션이 「이 정도면 충분하다」고 정한 값이다. 우리 상한을 정할 때 기준으로 쓴다.

| 항목 | 노션 | 우리 (제안) |
|---|---|---|
| 리치 텍스트 세그먼트 1개 | 2,000자 | 2,000자 |
| URL | 2,000자 | 2,000자, `http(s)`·`mailto` + 내부 페이지만 |
| 배열(세그먼트·자식) 1회 요청 | 100개 | 저장 단위가 페이지 전체이므로 **페이지 블록 수 ≤ 3,000 · 문서 JSON ≤ 1MB** |
| 요청 크기 | 500KB · 블록 1,000개 | 1MB |
| 중첩 깊이(1회 요청) | 2단 | 블록 트리 깊이 ≤ 10 |
| 다중 선택 옵션 | 100 | 100 |

---

## 3. 블록 카탈로그 — 노션 전부와 우리의 채택

노션 API 가 정의하는 블록 유형 전체(2026-03-11 판 기준)와 채택 여부. **채택 = 3~6단계 안**, **보류 = 7단계 이후 재검토**, **제외 = 만들지 않음(이유)**.

| 노션 블록 | 필드 | 자식 | 채택 | 단계 | 비고 |
|---|---|---|---|---|---|
| `paragraph` | rich_text, color | ○ | 채택 | 3 | 기본 블록. 자식 = 들여쓰기 |
| `heading_1/2/3` | rich_text, color, is_toggleable | 토글일 때 | 채택 | 3 (토글 제목은 5) | `heading_4` 는 노션 UI 에 없다(4→3 변환) — 우리는 3단계까지 |
| `bulleted_list_item` · `numbered_list_item` | rich_text, color, (list_start_index) | ○ | 채택 | 3 | 자식 = 하위 목록 |
| `to_do` | rich_text, **checked**, color | ○ | 채택 | 3 | 체크리스트. **완료자·완료 시각을 우리는 따로 남긴다**(노션은 안 남김) — 업무 히스토리의 핵심 |
| `quote` | rich_text, color | ○ | 채택 | 3 | |
| `callout` | rich_text, **icon**, color | ○ | 채택 | 3 | 사용자 페이지에서 가장 많이 쓰인 블록(요약·목표·시험 포인트) |
| `divider` | — | ✕ | 채택 | 3 | |
| `code` | rich_text, **language**, caption | ✕ | 채택 | 3 | 언어 허용 목록 ~40. 하이라이팅은 우리 렌더러(라이브러리 없이 토큰 색 5종) 또는 없음 |
| `toggle` | rich_text, color | ○ | 채택 | 4 | 「▶ 정답 보기」 패턴 |
| `table` + `table_row` | table_width, has_column_header, has_row_header · cells[][] | 행만 | 채택 | 4 | 셀은 리치 텍스트만(노션 규칙 그대로). 행·열·셀 색. 병합 **제외** |
| `table_of_contents` | color | ✕ | 채택 | 3 | 제목 블록에서 자동 생성 |
| `image` | file(외부/업로드), caption | ✕ | 채택 | 5 | **t-box 파일 참조만**(외부 URL 이미지 제외 — 추적·SSRF) |
| `file` · `pdf` | file, caption, name | ✕ | 채택 | 5 | t-box 파일 참조. PDF 는 t-box 미리보기로 |
| `column_list` + `column` | width_ratio | ○ | 채택 | 5 | 2~4단 |
| `child_page` | title | — | 채택 | 4 | 페이지 트리의 노드. 본문에 「하위 페이지 링크 블록」으로 보인다 |
| `link_to_page` | page_id | ✕ | 채택 | 5 | 링크 카드 + 역참조 |
| `bookmark` | url, caption | ✕ | 보류 | — | 외부 URL 미리보기는 서버가 밖으로 나가야 한다(S-05) → **제목 없이 URL 카드만** 이면 가능. 7단계 |
| `equation`(블록·인라인) | expression(KaTeX) | ✕ | 보류 | — | 수식 렌더러 = 새 의존성. 사내 업무에 드물다 |
| `embed` · `video` · `audio` | url/file | ✕ | 제외 | — | 외부 iframe 은 CSP `frame-src` 를 열어야 한다. 동영상은 t-box 파일 다운로드로 |
| `synced_block`(+reference) | synced_from | ○ | 제외 | — | 원본-참조 동기화는 편집 모델을 두 배로 만든다 |
| `template` | rich_text | ○ | 보류 | — | 페이지 템플릿(7단계)으로 대신 |
| `breadcrumb` | — | ✕ | 채택 | 4 | 페이지 머리에 항상 표시(블록 아닌 화면 요소) |
| `child_database` | title | — | 보류 | 6 | 5장 |
| `tab`/`tabs` | icon, 제목 | ○ | 보류 | — | 2026년 신설. 5단계 컬럼 뒤 재검토 |
| `meeting_notes`·`transcription` | AI 요약·전사 | — | 제외 | — | 음성·AI 기능 |
| `link_preview` · `mention`(블록) | url | ✕ | 제외 | — | 인라인 멘션으로 충분 |
| `unsupported` | — | — | — | — | 우리 렌더러도 **모르는 type 은 「지원하지 않는 블록」 상자**로 그린다(조용히 건너뛰지 않는다 — t-box Wiki 규칙 ②) |

**채택 합계**: 3단계 9종(paragraph·heading×3·bulleted·numbered·to_do·quote·callout·divider·code·toc) → 4단계 +3(toggle·table·child_page) → 5단계 +4(image·file·column·link_to_page). 노션 본문 블록의 실사용 90% 이상을 덮는다(사용자 페이지 5건 기준 100%).

---

## 4. 리치 텍스트와 색 — 「꾸밈」의 정확한 범위

노션의 인라인 서식은 **불리언 5개 + 색 1개 + 링크/멘션**이 전부다. 글꼴·글자 크기·정렬은 인라인이 아니라 **페이지 설정**(글꼴 3종·작은 글씨·전체 너비)과 **블록 속성**(색)이다. 이것이 노션 문서가 정돈되어 보이는 이유이고, 우리도 같은 선을 긋는다.

### 4.1 인라인 서식 (세그먼트 `marks`)
`bold` · `italic` · `underline` · `strike` · `code` · `color`(글자색 9 / 배경색 9 중 하나) · `link{href}` · `mention{kind: user|page|date, id|value}`

### 4.2 색 팔레트 (노션 그대로 9색 × 글자/배경)
`gray brown orange yellow green blue purple pink red` + `*_bg`. 저장은 **이름**, 표시는 CSS 변수(`--nt-red`·`--nt-red-bg`)로 라이트/다크 각각 정의. 임의 hex 값은 받지 않는다(다크 모드·일관성·CSS 주입 차단).

### 4.3 블록 속성
`color`(위 팔레트) · **`align: left|center|right`**(문단·제목·이미지·표 — 노션에 없지만 공지·표지·회의록 머리에 필요, 사용자 결정 2026-09-22) · **`size: small|normal|large|xlarge`**(블록 단위 글자 크기 4단계 — 제목 없이도 위계를 표현. 인라인 크기는 문서가 지저분해져 보류) · `icon`(콜아웃, 이모지 허용 목록 또는 아이콘 이름) · 코드 `language`.

### 4.4 페이지 설정
`icon`(이모지) · `cover`(t-box 이미지 참조) · `font: default|serif|mono` · `small_text: bool` · `full_width: bool`.

### 4.5 팔레트 확장 (노션에 없음)
임의 hex 값은 받지 않되(다크 모드에서 안 읽히고 문서마다 색이 달라진다), **관리자가 회사 팔레트를 등록**할 수 있다(`palettes` 표: 이름·라이트 값·다크 값, ≤ 12개). 저장은 여전히 이름이고, 화면은 등록된 값을 CSS 변수로 내려받는다. 브랜드 색 요구를 안전한 방식으로 받는 길이다.

> 기준은 「노션에 있나」가 아니라 **「우리가 쓰기에 더 좋은가」**다(사용자 지적 2026-09-22). 노션에 없는 것도 이점이 비용보다 크면 넣고, 노션에 있는 것도 우리 방식이 더 좋으면 바꾼다. 항목별 판단은 9장.

---

## 5. 데이터베이스 — 무엇을 언제 가져오나

사용자의 「MAIN」 DB 가 곧 요구사항이다: 업무 항목(제목) · 상태(할 일/진행/완료 그룹) · 담당자 · 기간 · 완료일 · 분류(관계) · 하위 업무(관계) · 진행률(수식+롤업) · 템플릿 · 표 뷰.

| 노션 속성(21) | 채택 | 대응 |
|---|---|---|
| title · rich_text · number · checkbox · url · email · phone_number · date(범위·시각) | 채택(6) | 그대로 |
| select · multi_select · **status**(그룹 3종 to_do/in_progress/complete) | 채택(6) | 상태 그룹은 보드 뷰의 열이 된다 |
| people | 채택(6) | t-box 사용자 참조 |
| relation | 채택(6) | 같은 앱 안 데이터베이스 간 참조(양방향 선택) |
| files | 대체 | t-box 파일 참조 |
| created_time/by · last_edited_time/by · unique_id | 채택(6) | 시스템 값 |
| formula · rollup | **제외** | 수식 언어 = 새 파서·평가기(보안·범위). 진행률은 「하위 체크 완료 수/전체」 **고정 계산 1종**으로 대신 |
| verification | 제외 | |

뷰: **표 · 보드(상태별) · 리스트** 3종(6단계). 타임라인·캘린더·갤러리 보류. 필터·정렬은 뷰 설정(JSON, 서버가 허용 목록으로 SQL 로 변환 — S-04). 템플릿은 「페이지 복제」로.

**저장 모델**: 데이터베이스 = `databases(id, space_id, schema JSONB)` · 행 = `pages` 에 `database_id` + `properties JSONB`(속성 값) — 노션과 같이 **행이 곧 페이지**라 본문·댓글·이력이 그대로 붙는다. 속성별 인덱스는 `properties` 의 GIN(`btree_gin` 확장 있음).

---

## 6. 우리 설계

### 6.1 데이터 모델 — 문서 JSON + 파생 표 (노션의 「블록 = 행」과 다른 선택)

노션은 블록 하나가 DB 행 하나다(실시간 협업·부분 갱신·블록 단위 권한 때문). 우리는 **페이지 본문 전체를 JSON 문서 하나로 저장**하고, 검색·역참조·체크 상태·목차 같은 것은 저장 시점에 **파생 표**로 뽑는다.

| | 블록 = 행 (노션) | 문서 JSON (우리) |
|---|---|---|
| 저장·불러오기 | 블록 수만큼 행. 트리 재조립 필요 | 한 번에 읽고 한 번에 쓴다 |
| 버전 이력 | 블록별 이력 → 페이지 시점 복원이 복잡 | 문서 스냅샷 = 버전. 되돌리기가 단순 |
| 부분 갱신·동시 편집 | 유리(블록 단위 OT/CRDT) | 페이지 단위 낙관적 잠금(6.5) — 사내 규모에서 충분 |
| 블록 댓글·역참조 | 블록 id 가 곧 행 | 블록 id 를 JSON 안에 두고 파생 표에 색인 |
| 구현 난이도(프레임워크 없는 PHP) | 높다 | 낮다 |

사내 규모(동시 편집자 1~2명, 페이지 수천)에서는 문서 모델이 맞다. 대신 **블록 id 는 노션처럼 UUID 로 영구**하고, 파생 표가 노션의 「블록 행」이 하던 색인 역할을 한다.

### 6.2 DDL 초안 (스키마 `community`, 기존 5표에 추가)

```sql
-- 공간: 개인(personal)·팀(team)·게시판(board). 기존 boards 표는 spaces 로 이름을 바꾸고 kind 열을 더한다(마이그레이션).
spaces(id, kind CHECK IN ('personal','team','board'), owner_user_id NULL, team_id NULL, name, description,
       access_level, notice, is_active, created_by, created_at, updated_at)
       -- personal: owner_user_id NOT NULL, access_level 는 쓰지 않는다(페이지 visibility 가 정한다)

pages(id BIGSERIAL, public_id UUID, space_id → spaces, parent_page_id NULL → pages, position INT,
      title, icon TEXT NULL, cover_file_ref TEXT NULL, font, small_text, full_width,
      visibility CHECK IN ('private','team','internal','published'),   -- personal 공간 페이지만 의미. 게시판 페이지는 published 고정
      published_space_id NULL → spaces,                                -- visibility=published 일 때 어느 게시판에
      database_id NULL → databases, properties JSONB NULL,             -- 6단계
      doc JSONB NOT NULL, doc_version INT NOT NULL DEFAULT 1,          -- 본문 문서 + 낙관적 잠금 번호
      plain_text TEXT NOT NULL DEFAULT '', search_tsv tsvector,        -- 검색용 파생
      block_count INT, author_id, last_edited_by, created_at, updated_at, deleted_at, deleted_by, purge_after)
      CHECK (visibility <> 'published' OR published_space_id IS NOT NULL)
      INDEX (space_id, parent_page_id, position) WHERE deleted_at IS NULL · GIN(search_tsv) · GIN(plain_text gin_trgm_ops)

page_versions(id, page_id, version INT, doc JSONB, title, edited_by, edited_at, summary TEXT NULL)   -- 스냅샷(append-only, 앱 계정 DELETE 없음)
page_blocks(page_id, block_id UUID, block_type, text_excerpt, ord INT)                             -- 파생: 댓글·역참조 앵커, 목차
page_links(from_page_id, from_block_id, to_page_id)                                                -- 파생: 백링크
checklist_events(id, page_id, block_id, checked BOOL, by_user_id, at)                              -- 체크 이력(append-only)
comments  (기존 표 확장: page_id, block_id UUID NULL, parent_comment_id NULL, resolved_at NULL)     -- 블록 앵커·답글 1단·해결
mentions(comment_id NULL, page_id, mentioned_user_id, created_at)
notifications(id, user_id, kind, page_id, comment_id NULL, actor_id, created_at, read_at)
tags(id, space_id NULL, name UNIQUE(lower)) · page_tags(page_id, tag_id)
reactions(page_id NULL, comment_id NULL, user_id, kind CHECK ('helpful')) UNIQUE(대상, user)
handovers(id, from_user_id, to_user_id, space_id NULL, page_id NULL, by_user_id, reason_text, at)  -- 인계 기록(append-only)
databases(id, space_id, page_id(데이터베이스 페이지), schema JSONB, views JSONB)                    -- 6단계
```

기존 `posts`/`comments` 는 `pages`(게시판 공간, `visibility='published'`) 로 이관 — 평문 본문은 `paragraph` 블록 하나로 감싼다(마이그레이션 스크립트, 되돌림 가능).

### 6.3 블록 JSON 스키마 초안

```json
{
  "schema": 1,
  "blocks": [
    {"id": "9f1c…", "type": "callout", "icon": "🎯", "color": "blue_bg",
     "text": [{"t": "AICE 합격을 위한 ", "m": {}}, {"t": "4인 스터디", "m": {"bold": true}}],
     "children": []},
    {"id": "2b7a…", "type": "heading", "level": 2, "text": [{"t": "이 공간 사용법", "m": {}}]},
    {"id": "c03d…", "type": "numbered", "text": [{"t": "강의자는 ", "m": {}}, {"t": "3줄 요약", "m": {"code": true}}, {"t": " 을 채운다", "m": {}}]},
    {"id": "77e0…", "type": "to_do", "checked": true, "text": [{"t": "교재 주문", "m": {}}]},
    {"id": "a1f2…", "type": "code", "language": "python", "code": "df.isnull().sum()"},
    {"id": "e9d1…", "type": "toggle", "text": [{"t": "정답 보기", "m": {}}], "children": [{"id": "…", "type": "code", "language": "python", "code": "…"}]},
    {"id": "b4c8…", "type": "table", "header_row": true, "header_col": false,
     "rows": [{"color": null, "cells": [[{"t": "회차", "m": {}}], [{"t": "날짜", "m": {}}]]},
              {"color": "gray_bg", "cells": [[{"t": "—", "m": {}}], [{"t": "9/24-9/28 추석", "m": {}}]]}]},
    {"id": "…", "type": "image", "file_ref": "tbox:eb2dcb5d-…", "caption": [], "width": "full"},
    {"id": "…", "type": "child_page", "page_id": "…"},
    {"id": "…", "type": "link_to_page", "page_id": "…"},
    {"id": "…", "type": "divider"}
  ]
}
```

**서버 검증 규칙(`src/Doc.php`, 허용 목록만 통과)**: `type` 허용 목록 · 블록마다 필수/선택 키 정확히(모르는 키 → 400) · `id` UUID v4 유일 · `text` 세그먼트 ≤ 100개, 각 ≤ 2,000자, `m` 의 키는 8개만, `color` 는 팔레트 18값만 · `link.href` 는 `https?://`·`mailto:`·`page:UUID` 만(`javascript:`·`data:` 는 400) · `mention` 은 존재하는 사용자/페이지만 · `children` 은 자식 허용 유형에만, 깊이 ≤ 10 · 블록 ≤ 3,000 · JSON ≤ 1MB · `file_ref` 는 `tbox:UUID` 형식 + **t-box 파일 등급 ≤ 페이지 공간 등급** 확인 · `code` 는 문자열 ≤ 50,000자(이스케이프 규칙 없음, 렌더러가 `textContent`) · 제어문자는 `\n` 만(`Text::clean` 재사용).

### 6.4 렌더러 두 벌, 규칙 한 벌
- **클라이언트 렌더러**(`assets/doc-render.js`): JSON → `createElement`/`textContent`. 인라인 색은 `class="nt-red"`, 링크는 `rel="noopener noreferrer"`·`target` 은 외부만. 모르는 type → 「지원하지 않는 블록」.
- **서버 렌더러**(`src/DocRender.php`): 같은 규칙으로 HTML 을 만든다(전 값 `htmlspecialchars`). 쓰는 곳은 **검색 결과 발췌·알림 미리보기·인쇄용**뿐이고, 페이지 본문 화면은 클라이언트가 그린다(t-box `Wiki.php` 와 같은 원칙, 저장형 XSS 표면 0).
- 스키마 진실은 `docs/doc-schema.json` 하나. PHP 검증기와 JS 에디터가 이 파일에서 허용 목록을 읽는다(빌드 없이 JSON 을 그대로 서빙).

### 6.5 에디터 — 가장 큰 결정

| 안 | 내용 | 장점 | 단점 |
|---|---|---|---|
| A. 자체 구현 | 블록마다 `contenteditable` 하나, 인라인 서식은 우리 세그먼트 모델로 직접 관리(`execCommand` 금지), 슬래시 메뉴·드래그 손잡이·마크다운 단축키 직접 | 의존성 0(S-10-C 그대로), 저장 형식과 1:1 | **가장 비싸다**(IME·한글 조합·선택 영역·되돌리기·붙여넣기 정리 — 4~6주). 버그가 곧 데이터 손상 |
| B. **ProseMirror**(MIT, 바닐라 JS, 스키마 기반) | 우리 스키마를 PM 스키마로 정의 → PM 문서 JSON 이 곧 우리 블록 JSON(마크 = `m`) · 슬래시 메뉴·손잡이·단축키는 우리가 얹음 | 편집 엔진(선택·IME·undo·붙여넣기·트랜잭션)이 검증됨. React 불필요. 문서 모델이 **구조화**되어 HTML 을 저장하지 않는다 | 의존성 1개(패키지 5~6개 번들 ~150KB). 학습 곡선. 노션식 블록 UX(슬래시·드래그·전환)는 직접 |
| C. Editor.js / Quill / TinyMCE 류 | 블록형 또는 WYSIWYG | 빠른 시작 | 인라인을 **HTML 문자열로 저장**(Editor.js `paragraph.text`) 또는 HTML 출력 → 우리 원칙과 정면 충돌 |
| D. BlockNote / Tiptap-Notion 류 | PM 위의 노션 UX 완성품 | 노션과 가장 비슷 | React 필수(우리 셸과 충돌), 라이선스(BlockNote MPL/상용), 번들 큼 |

**권고: B(ProseMirror) + 자체 UX.** 이유: 편집 엔진의 어려운 부분(한글 IME·선택·undo)을 검증된 코드에 맡기고, 저장 형식·검증·렌더링은 우리 것이라 보안 원칙이 유지된다. 의존성 심사(S-10-C): MIT · 활발한 유지보수 · **빌드 산출물을 저장소에 고정**(`public/assets/vendor/prosemirror-<ver>.js`, sha256 을 `deploy/DEPENDENCIES.md` 에) · CSP `script-src 'self'` 그대로 · 서버에서 실행되는 코드 0. 대안 A 는 4단계에서 B 가 실패했을 때의 후퇴 경로로 남긴다.

노션 UX 중 우리가 얹는 것: `/` 슬래시 메뉴(블록 삽입·전환) · 블록 왼쪽 손잡이(드래그 이동·메뉴) · 마크다운 단축키(`# `·`- `·`1. `·`[] `·`> `·"```") · 선택 시 인라인 툴바(굵게…색·링크·댓글) · `@` 멘션 팝업 · `Ctrl+Z`. 붙여넣기: HTML 을 **우리 스키마로 변환**(허용 밖은 평문화) — 노션도 이렇게 한다.

### 6.6 저장·동시 편집
- 자동 저장(입력 멎은 뒤 1.5초, 최대 10초 간격). `PATCH /api/pages/{id}` 본문에 `doc_version` 을 실어 **낙관적 잠금**: 서버 버전과 다르면 409 + 서버 문서 → 화면은 「다른 곳에서 고쳐졌다 — 새로 고침 / 내 것으로 덮어쓰기」. 실시간 공동 편집(CRDT)은 제외. 페이지를 열면 「○○님이 편집 중」 표시만(세션 heartbeat, 30초).
- 저장마다 `page_versions` 스냅샷 — 단, 5분 안의 연속 저장은 같은 버전으로 합친다(이력이 수백 개가 되지 않게). 제목·문서·편집자·시각. 되돌리기 = 스냅샷을 새 버전으로 복사(이력은 지우지 않는다).

### 6.7 검색
PostgreSQL 만으로: `plain_text` 를 저장 시점에 뽑아 `search_tsv`(`simple` 사전 — 한국어 형태소 분석기 없음) + **`pg_trgm` GIN**(설치돼 있음, 확인) 으로 부분 일치·오타 허용. 결과 발췌는 서버 렌더러가 만든 텍스트에서 `ts_headline`. 검색 범위는 언제나 `visibleWhere` 안(S-02).

### 6.8 댓글 · 멘션 · 알림 · 백링크
- 댓글은 페이지 또는 **블록 id** 에 앵커(노션의 문장 선택 대신 — 문자 오프셋은 편집으로 금방 어긋난다). 답글 1단, 해결됨, 도움됐어요.
- 멘션 `@사용자`(같은 팀 + 그 페이지를 볼 수 있는 사람만 후보 — 조직도 노출 최소화), `@페이지`, `@날짜`. 멘션·답글·내 페이지 댓글 → `notifications` (화면 종 아이콘, 30초 폴링).
- 백링크: 저장 시 `link_to_page`·`mention page`·`child_page` 를 `page_links` 로 뽑고, 페이지 하단 「이 페이지를 참조한 곳」(볼 수 있는 것만).

### 6.9 권한 — 공개 범위와 상속
- 개인 공간 페이지 `visibility`: `private`(소유자만) · `team`(t-box 같은 팀) · `internal`(Guest 제외 전원) · `published`(게시판 등급대로 + 게시판 목록 노출).
- **자식은 부모보다 넓을 수 없다**(t-box 폴더 규칙 ①과 같은 순서 private < team < internal < published). 부모를 좁히면 자식도 함께(cascade) — 따로 판정하지 않고 쓰기 시점 불변 규칙으로.
- 팀 공간·게시판 페이지는 공간 등급을 따른다(지금과 같다).
- 편집 권한: 소유자(개인 공간) / 작성자·같은 팀 Manager·Admin(게시판) — 지금의 `decidePostChange` 그대로. **Admin 도 private 페이지는 읽지 못한다**(인계는 내용 없이 소유권만).
- 판정 함수는 `Authz::decidePageRead(user, page+space)` 하나로 모은다.

### 6.10 인계
`POST /api/handovers {from_user_id, to_user_id, scope: space|page, id, reason_text}` — 본인(자기 것을 팀원에게) 또는 Admin(누구 것이든, 재인증). 트랜잭션: `pages.owner`(개인 공간이면 공간의 `owner_user_id`) 변경 + `handovers` 행 + 감사 + 두 사람 알림. 원 작성자·`page_versions`·`checklist_events` 는 **그대로**. t-box 에서 비활성화된 계정의 개인 공간은 Admin 화면 「인계 대기」 목록(users.is_active=false 조인).

### 6.11 휴지통
페이지 삭제 = `deleted_at` + `purge_after = now()+30d`. 소유자·Admin 이 복구. 30일 뒤 배치가 문서·버전을 지운다(감사 `page.purge`). 하위 페이지는 함께.

---

## 7. 보안 대응표 (기존 S-01~S-10 에 얹는 것)

| 위험 | 어디서 생기나 | 대응 |
|---|---|---|
| 저장형 XSS | 에디터가 만든 내용 | **HTML 저장 0**. 블록 JSON 허용 목록 검증(6.3) + 렌더러 `textContent`/속성 허용 목록 + CSP 유지. 붙여넣기 HTML 은 클라이언트에서 스키마로 변환하고 서버가 다시 검증 |
| 링크 주입 | `href`·이미지·파일 참조 | 스킴 허용 목록, 내부 참조는 UUID 형식, `rel=noopener noreferrer` |
| 권한 우회 | 페이지 트리·게시·인계·댓글 | 모든 읽기 `decidePageRead`, 쓰기 `decidePageChange`, 트리 불변 규칙, 인계는 재인증 |
| 노출 | 검색·멘션 후보·백링크·알림 미리보기 | 전부 `visibleWhere`/`decidePageRead` 를 지난 것만. 멘션 후보는 같은 팀 + 열람자 |
| SQL 인젝션 | 뷰 필터·정렬(6단계) | 필터 JSON → 허용 목록(속성 이름·연산자) → 바인딩 |
| 공급망 | ProseMirror | 고정 버전·해시·저장소 안 번들, 서버 실행 0, 분기 재검토(S-11-C) |
| 용량·성능 | 큰 문서·이력 | 문서 1MB·블록 3,000·버전 합치기·`purge_after` |
| 동시 편집 충돌 | 두 탭 | 낙관적 잠금 409 + 안내. 조용한 덮어쓰기 없음 |

---

## 8. 개발 단계 — 상세

| 단계 | 산출물 | API(추가) | 시험 | 기간 |
|---|---|---|---|---|
| **3 · 본문 형식과 에디터** | `docs/doc-schema.json` · `src/Doc.php`(검증) · `src/DocRender.php` · `assets/doc-render.js` · ProseMirror 번들 + `assets/editor.js`(3단계 블록 9종·인라인 8종·색·슬래시·단축키·인라인 툴바) · `posts.body` → `doc` 마이그레이션 · 조회수·태그·`pg_trgm` 검색 · **공개 주소**(루트=커뮤니티, `/tbox`) | `PATCH /api/pages/{id}`(doc, doc_version) · `GET /api/search?q` · `GET/POST /api/tags` | T-14 스키마 퍼징(허용 밖 키·`javascript:`·깊이 11·3,001블록·1MB+1 → 400) · T-15 렌더 XSS(모든 블록에 `<img onerror>` → DOM 텍스트) · T-16 409 충돌 · 회귀 | 2~3주 |
| **4 · 개인 공간·트리·이력** | `spaces`(kind)·`pages` 트리·`visibility` 4종·게시·`page_versions`·`checklist_events`·toggle·table·child_page·breadcrumb·「내 공간」 화면·버전 보기/되돌리기 | `GET /api/me/space` · `POST /api/pages`(parent) · `PATCH …/move` · `PATCH …/visibility` · `POST …/publish` · `GET …/versions` · `POST …/restore` · `POST …/check`(to_do) | T-17 트리 불변 규칙(자식이 부모보다 넓게 → 400, 부모 좁히기 cascade) · T-18 private 은 Admin 도 403 · T-19 게시·해제 · T-20 체크 이력 | 2주 |
| **5 · 토론·참조·첨부** | 블록 댓글·답글 1단·해결됨·도움됐어요·멘션 3종·알림·백링크·link_to_page 카드·image/file(t-box)·column·게시판 종류 설정 | `POST /api/pages/{id}/comments{block_id}` · `PATCH /api/comments/{id}/resolve` · `POST …/reactions` · `GET /api/notifications` · `GET /api/pages/{id}/backlinks` · `GET /api/mention-candidates` | T-21 멘션 후보 노출 범위 · T-22 첨부 등급 규칙(파일 > 공간 → 400) · T-23 백링크는 볼 수 있는 것만 | 2주 |
| **6 · 데이터베이스** | `databases`·속성 12종·표/보드/리스트 뷰·필터·정렬·템플릿(복제)·진행률 1종 | `POST /api/databases` · `PATCH …/schema` · `GET …/rows?view=` · `PATCH /api/pages/{id}/properties` | T-24 필터 인젝션(속성 이름·연산자 허용 목록) · T-25 상태 그룹 보드 | 2~3주 |
| **7 · 마무리** | 인계(본인·Admin)·인계 대기·휴지통 30일·홈 대시보드·템플릿 갤러리·bookmark(URL 카드)·탭 재검토 | `POST /api/handovers` · `GET /api/admin/handover-queue` · `POST /api/pages/{id}/trash|restore` | T-26 인계 후 이력 불변 · T-27 30일 정리 배치 | 1~2주 |

합계 9~12주. 강사의 「몇 주」와 같은 규모다. 3단계가 끝나면 이미 노션 느낌의 글쓰기·읽기가 된다.

---

## 9. 판단 기준과 결정 — 「우리가 쓰기에 더 좋은가」

> 사용자 지적(2026-09-22): 「노션에 없다고 우리 것에 없을 필요는 없다. 우리가 쓰기에 더 좋은 방식을 만드는 것이 중요하다. 필요·불필요의 판단은 사용자가 하고 Fable 은 보조한다.」
> 그래서 이 장은 항목마다 **선택지 · 이점 · 비용/위험 · Fable 의견 · 결정** 다섯 칸이다. 결정 칸은 사용자 것이다. 2026-09-22 「좋아 반영해」 로 Fable 의견을 초기 결정으로 채웠고, 언제든 바꿀 수 있다.

### 9.1 노션에는 없지만 우리 쓰기에 좋을 수 있는 것

| # | 항목 | 이점 | 비용·위험 | Fable 의견 | 결정(2026-09-22) |
|---|---|---|---|---|---|
| N-1 | 정렬(좌·중·우) — 문단·제목·이미지·표 | 공지·표지·회의록 머리 | 블록 속성 1개 | 넣자 | **넣는다** (3단계, 4.3) |
| N-2 | 글자 크기 단계 — 블록 4단계 / 인라인 | 제목 없이 위계 표현 | 인라인은 문서가 지저분해진다 | 블록 4단계만 | **블록 4단계 넣는다**, 인라인은 보류 |
| N-3 | 임의 색(hex) | 브랜드 색 | 다크 모드·일관성·CSS 주입 | 관리자 등록 팔레트로 | **관리자 팔레트 확장**(4.5), hex 직접 입력은 안 함 |
| N-4 | 표 셀 병합 | 명세서·일정표에 실제 필요(사용자 표 실측) | 표 모델·에디터 복잡도 | 병합 없는 표 먼저, 병합은 별도 단계 | **6단계에 넣는다** |
| N-5 | 이미지 외부 URL | 붙여넣기 편의 | 브라우저가 외부를 부른다(IP 노출·추적·`img-src` 개방) | t-box 업로드로 통일, 붙여넣은 이미지는 **자동 업로드** | **자동 업로드 방식**(5단계). 외부 URL 직접 삽입은 안 함 |
| N-6 | 외부 임베드(유튜브·지도·시트) | 자료 인용 | `frame-src` 개방, 외부 스크립트 | 허용 도메인 목록(t-box F-43 방식) | **허용 목록 방식으로 넣는다**(7단계) |
| N-7 | 수식·롤업 | 진행률·합계·기한 계산 | 수식 언어 파서·평가기·보안 | 고정 계산 몇 종부터 | **고정 계산 4종**(합계·개수·진행률·기한 D-day, 6단계). 자유 수식은 보류 |
| N-8 | 실시간 공동 편집 | 회의록 동시 작성 | CRDT·웹소켓 — 가장 큰 기술 항목 | 낙관적 잠금 + 「편집 중」 표시부터 | **보류**(수요 확인 뒤) |
| N-9 | 문장 선택 댓글 | 정확한 지점 질문 | 편집 시 위치 어긋남 | 블록 앵커 + **선택 문구 인용 저장** | **넣는다**(5단계) |
| N-10 | 공지 읽음 확인 | 누가 읽었는지 — 사내 공지에 실질적 | `page_reads` 표, 개인정보 관점 안내 | 공지 게시판만 | **넣는다**(5단계, 공지 종류 게시판만) |
| N-11 | 부서 기본 양식 템플릿 | 인수인계서·체크리스트 양식 통일 | 템플릿 관리 화면 | 7단계 템플릿에 부서 양식 포함 | **넣는다**(7단계) |

### 9.2 노션에 있지만 우리 방식이 더 좋은 것 (설계에 이미 반영)

| 항목 | 노션 | 우리 |
|---|---|---|
| 체크리스트 | 체크 여부만 | **완료자·완료 시각** 기록, 처리 이력 타임라인(6.2 `checklist_events`) |
| 페이지 소유 | 공유 목록만 | **인계**(소유권 이전, 이력 보존, 인계 대기 목록, 6.10) |
| 파일 | 자체 저장 | **t-box 파일 등급과 공개 범위 연동**(첨부 파일은 공간보다 넓을 수 없다) |
| 공유 | 링크 공유·게스트 | **게시**(원본을 게시판에 걸기, 게시 시점 이력) |
| 기록 | 편집 가능 | 이력·체크·인계는 **append-only** |
| 권한 | 페이지별 초대 | **조직(t-box 팀) 기반** + 감사 로그 |
| 댓글 앵커 | 문자 오프셋(편집 시 흐려짐) | **블록 id + 인용 문구** |

### 9.3 구조 결정 (Fable 권고 → 사용자 결정 2026-09-22 「좋아 반영해」)

| # | 질문 | 결정 |
|---|---|---|
| D-1 | 에디터 엔진 | **ProseMirror**(6.5). 의존성 0 원칙의 첫 예외 — SECURITY R-xx 로 기록, 고정 버전·해시·저장소 안 번들 |
| D-2 | 본문 저장 | **문서 JSON + 파생 표**(6.1) |
| D-3 | 개인 공간 `private` 를 Admin 도 못 읽게 | **아니오 — 사용자 2026-09-22 「관리자는 내용 볼 수 있게 하자」.** `private` = 소유자 + Admin(감사 기록). Manager 는 못 본다. 인계는 소유권만 |
| D-4 | 동기화 블록·자유 수식·실시간 공동 편집 | **보류**(N-7·N-8). 외부 임베드는 허용 목록으로 **넣는다**(N-6) |
| D-5 | 공개 주소 루트=커뮤니티, `/tbox`=t-box | 3단계에서 확정(강사 확인) |
| D-6 | 기존 글(posts) → 문단 블록 이관 | **예**, 되돌림 스크립트 동봉 |

### 9.4 단계 표 보정 (8장에 더해지는 것)
- 3단계: N-1 정렬 · N-2 블록 크기 · N-3 팔레트 확장(관리자 화면은 7단계, 저장·표시는 3단계)
- 5단계: N-5 이미지 자동 업로드 · N-9 인용 댓글 · N-10 공지 읽음 확인
- 6단계: N-4 셀 병합 · N-7 고정 계산 4종
- 7단계: N-6 임베드 허용 목록 · N-11 부서 양식 템플릿

다음: PRD v0.2 · ARCHITECTURE v0.2 로 옮기고 3단계 지시서를 쓴다.

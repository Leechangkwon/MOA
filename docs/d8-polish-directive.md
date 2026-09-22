# 지시서 — D-1 · D-2 · D-3 · D-8: 시각 초 단위 · 위험 단추 색 · 중복 title 제거 · 「취소」→「닫기」

CLAUDE.md 2장 형식. 작성 Fable 2026-09-22. 구현 Opus.

```
목표        : 표·모달에 보이는 시각을 초까지만 보이고(전체 값은 title), 계정 「비활성화」 단추를 위험 색으로,
              아이콘 단추의 중복 title 을 없애고, 모달의 「취소」 를 「닫기」 로 통일한다. 동작·요청 본문은 그대로.
요구사항    : DESIGN.md 2.4(시각 형식 YYYY-MM-DD HH:MM:SS), 5장(날짜·시각 · 단추 문구), 2.1(.btn--danger 는 되돌릴 수
              없는 것 · 아이콘 단추는 aria-label 만), 2.8(툴팁), 7장 D-1·D-2·D-3·D-8 — 해당 절을 읽고 시작할 것.
              CSS 는 고치지 않는다(필요한 클래스 전부 있음).
수정 파일   : public/assets/app.js, src/handlers/org_page.php, src/handlers/users_page.php  (이 셋만)
금지 패턴   : SQL 문자열 결합, innerHTML, 인라인 <script>·style=""·on* 속성, 사용자 입력이 들어간 파일 경로,
              curl_exec/file_get_contents('http…'), 역할 판단을 Authz 밖에서 하는 코드, 요청 파라미터의 role 값 사용,
              **id 변경 · data-* 변경 · 요청 본문(payload) 변경 · 「취소」「닫기」 외 문구 변경**
완료 기준   : 아래 「확인」 1~6 전부 실행, 명령·응답 원문 첨부. security_lint.sh 0건.
              ui_snapshot.sh <폴더> 1440 결함이 이전(7건, 전부 기존 오탐)과 같거나 적고 console=0.
보고 형식   : 변경 파일 목록(바뀐 줄 before → after) / 실행한 명령과 출력 원문 / 못 한 것과 이유 / 판단이 필요했던 것
```

## D-1 시각 — `app.js`

### 도우미 둘 (모듈 수준, `setText` 근처에 추가)

```js
/* DESIGN.md 2.4 · 5장 — 시각은 「YYYY-MM-DD HH:MM:SS」 까지만 보이고 마이크로초·시간대는 title 로 옮긴다.
   서버 값(PostgreSQL timestamptz 문자열, 예 2026-09-21 14:27:21.850281+09)은 바꾸지 않는다 — 표시만 자른다.
   형식이 다르면(빈 값·'—'·이미 짧은 값·날짜만) 그대로 둔다. */
var WHEN_RE = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/;
function shortWhen(value) {
  if (typeof value !== 'string') { return { text: '—', full: '' }; }
  var m = WHEN_RE.exec(value);
  if (!m) { return { text: value === '' ? '—' : value, full: '' }; }
  var text = m[1] + ' ' + m[2];
  return { text: text, full: value.length > text.length ? value : '' };
}
/* 표 칸 하나 — 글자는 짧게, title 에 전체 값. suffix 는 「 (만료)」 같은 꼬리(없으면 생략). */
function addWhenCell(row, value, suffix) {
  var td = document.createElement('td');
  var w = shortWhen(value);
  setText(td, w.text + (typeof suffix === 'string' ? suffix : ''));
  if (w.full !== '') { td.setAttribute('title', w.full); }
  row.appendChild(td);
  return td;
}
/* 표 밖 글자(모달 정보 판·카드 메타)용 */
function setWhen(el, value) {
  var w = shortWhen(value);
  setText(el, w.text);
  if (w.full !== '') { el.setAttribute('title', w.full); } else { el.removeAttribute('title'); }
}
```

### 바꿀 곳 (줄 번호는 2026-09-22 기준 — 내용으로 찾는다)

| 줄 | before | after |
|---|---|---|
| 1967 | `addCell(row, file.created_at);` | `addWhenCell(row, file.created_at);` |
| 3465 | `addCell(row, item.happened_at);` | `addWhenCell(row, item.happened_at);` |
| 3942 | `addCell(row, file.deleted_at);` | `addWhenCell(row, file.deleted_at);` |
| 4924 | `addCell(row, item.expires_at === null ? '—' : item.expires_at + (item.is_expired === true ? ' (만료)' : ''));` | `item.expires_at === null ? addCell(row, '—') : addWhenCell(row, item.expires_at, item.is_expired === true ? ' (만료)' : '');` |
| 4926 | `addCell(row, item.created_at);` | `addWhenCell(row, item.created_at);` |
| 6033 | `addCell(row, item.happened_at);` | `addWhenCell(row, item.happened_at);` |
| 6186 · 6189 | `addCell(row, link.expires_at);` · `addCell(row, link.created_at);` | `addWhenCell(…)` 둘 |
| 6982 | `addCell(tr, typeof row.updated_at === 'string' ? row.updated_at : '');` | `addWhenCell(tr, row.updated_at);` |
| 4438~4442 토큰 표 | 네 값을 `forEach` 로 `setText` | `token.created_at` 과 `live ? token.last_used_at : token.revoked_at` 두 칸은 `addWhenCell(row, value)`, `name`·`hint` 두 칸은 지금처럼 |
| 1357 · 1415 | `setText(previewCreated, …created_at)` | `setWhen(previewCreated, …created_at)` |
| 1711 | `setText(execCreated, typeof file.created_at === 'string' ? file.created_at : '—')` | `setWhen(execCreated, file.created_at)` |
| 6326 | `setText(exp, link.expires_at)` | `setWhen(exp, link.expires_at)` |
| 6354 | `setText(when, alert.raised_at)` | `setWhen(when, alert.raised_at)` |
| 5297 | `setText(detailMeta, '만든 날 ' + team.created_at + ' · 마지막 변경 ' + team.updated_at)` | `setText(detailMeta, '만든 날 ' + shortWhen(team.created_at).text + ' · 마지막 변경 ' + shortWhen(team.updated_at).text)` |
| 1629 | `'만료 ' + (typeof data.expires_at === 'string' ? data.expires_at : '—')` | `'만료 ' + shortWhen(data.expires_at).text` |

**바꾸지 않는 것**: `.slice(0, 10)` 으로 날짜만 쓰는 곳(2461 · 4283 · 4755 — 입력 칸 값), `sort: 'created_at'`(정렬 키), `payload.expires_at`(요청 본문). 감사 로그 「펼치기」 안의 원문 JSON(`.audit-detail`)도 그대로 — 원문은 원문이다.

## D-2 위험 단추 색 — `app.js` 4884행 부근

```js
// before
activeButton.className = 'btn btn--quiet btn--small';
// after — 비활성화(되돌릴 수 있지만 그 사람이 즉시 못 들어오게 되는 동작)는 위험 색, 다시 활성화는 보조 색 (팀 토글 5300행과 같은 규칙)
activeButton.className = item.is_active === true ? 'btn btn--danger btn--small' : 'btn btn--quiet btn--small';
```
계정 목록이 다시 그려질 때 이 코드가 매번 실행되므로 상태 전환도 따라간다. 다른 단추(역할·팀·만료 / 임시 비밀번호 / 2단계 초기화 / 프로그램 토큰)는 그대로.

## D-3 중복 title — `app.js` `makeIconButton`

`button.setAttribute('title', label);` 한 줄을 지운다. `aria-label` 은 남긴다(CSS 툴팁이 이것을 보인다). 그 밖에 `makeIconButton` 으로 만든 단추에 **나중에** `setAttribute('title', …)` 를 덧붙이는 곳(1823 `previewButton`, 1853 `link` 다운로드)도 `title` 줄만 지우고 `aria-label` 줄은 남긴다. 배지의 `title`(등급 원래 값 · 검사 설명)은 **그대로** — 그것은 툴팁이 아니라 원래 값 표기다(DESIGN 2.2).

## D-8 「취소」 → 「닫기」

- `app.js` 520 · 2429: `setText(cancel, '취소')` → `setText(cancel, '닫기')`
- `org_page.php` 191 · 215 · 245 · 265, `users_page.php` 161 · 184: `>취소</button>` → `>닫기</button>` (id·class 그대로)
- 재인증 모달(C-2 에서 만든 `.modal__foot` 의 「취소」)도 있으면 「닫기」 로.
- **「취소」 가 동작 자체인 곳은 바꾸지 않는다**(예: 「공유 링크 회수」 처럼 되돌리는 동작이 있으면 그 단추는 별개). 확인 5 의 grep 결과에 남는 「취소」 가 있으면 각각 왜 남겼는지 적는다.

## 확인 (전부 실행 · 원문 첨부 — 검토 계정 qa_admin01 · `-A "tbox-review/$(date +%F)"`)

1. `php -l` 두 PHP 파일 · `grep -nE 'onclick|style=|<script>'` 세 파일 주석·기존(2467 `trashedOk.onclick`) 제외 신규 0 · `grep -cE 'innerHTML|outerHTML|insertAdjacentHTML|document\.write' public/assets/app.js` → 0
2. `grep -n "setAttribute('title'" public/assets/app.js` → 남는 것은 배지(`badge`·`scanBadge`·`execBadge`·`cell`·`wrap` 잠금 사유·`frame`)와 `addWhenCell`/`setWhen` 안의 것뿐. `makeIconButton` 안에는 없음
3. `grep -n "'취소'\|>취소<" public/assets/app.js src/handlers/*.php` → 0 (남으면 사유)
4. Playwright(1440, 로그인): `/` 표의 「올린 시각」 셀 텍스트가 `/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/` 에 맞고 `title` 이 원래 값(`+09` 로 끝남) · `/audit` 시각 셀 같음 · `/users` 「만든 시각」·「만료」(만료된 계정은 `… (만료)` 꼬리 유지) · `/settings` 공유 링크 「만료」「만든 시각」 · `/admin` 공유 링크 전체·경보 시각 · `/trash` 「지운 시각」 · 아이콘 단추 `title` 속성 없음 · `/users` 활성 계정 행의 「비활성화」 단추가 `btn--danger`, 비활성 계정 행의 「다시 활성화」 는 `btn--quiet`. 스크린샷 `/`·`/users`
5. 표시만 바뀌었는지: `/api/files?…` 응답의 `created_at` 원문이 여전히 마이크로초를 포함(서버 무변경) — `curl … /api/files | grep -o '"created_at":"[^"]*"' | head -1`
6. `scripts/security_lint.sh` 0건 · `scripts/ui_snapshot.sh <폴더> 1440` 이전과 같거나 적음, console 0 · 1280 도 같음. **시각 열이 좁아졌으니** `/`·`/users`·`/audit` 의 `overflow_x=False` 와 표 `.table-wrap` 가로 넘침 0 을 함께 확인

## 하지 않는 것

- CSS 수정 — 시각 열이 좁아져 표 폭이 남으면 Fable 이 열 최소 폭(`nth-child` 규칙)을 조정한다. 보고에 「시각 셀 폭(px)」 을 한 줄 적어 주면 좋다.
- 서버·API 응답 형식 변경. 감사 원문(`.audit-detail`) 변경. 배지 `title` 제거.

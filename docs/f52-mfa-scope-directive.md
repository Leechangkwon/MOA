# Opus 구현 지시서 — F-52 2단계 인증 필수 범위: Admin 만 필수, 나머지는 선택 + 권장

> Fable 작성 2026-09-16. CLAUDE.md 2장 형식. 사용자 결정: 「Admin 권한자만 2차 OTP 필수, 게스트·직원은 선택. 다만 권장하는 절차는 필요」.

```
목표      : 2단계 인증 등록 강제 관문(next:"mfa_setup")을 실제 역할이 Admin 인 계정에만 적용하고,
            다른 역할은 막지 않되 로그인 뒤 홈에 「권장 카드」를 보여 켜도록 권한다.
요구사항  : PRD F-52(F-16 ③ 대체) · 8장 관문·enroll 행 · 12.2 T-64 · S-01 ; ARCHITECTURE 5.26 · 4.0 · 13장 ; SECURITY R-05 · R-85
            — 해당 절을 **읽고 시작**한다. 판단은 Auth::nextStep 한 곳에 모여 있다(ARCH 5.26 표).
수정 파일  : src/Auth.php · src/handlers/login.php · src/handlers/me.php · src/handlers/mfa_confirm.php
            · src/handlers/mfa_disable.php(주석만) · src/handlers/users_mfa_reset.php(주석만) · src/handlers/settings_page.php
            · src/handlers/home.php · public/assets/app.js · public/assets/app.css · src/help/screens.php · README.md(2단계 인증 절만)
            · wiki/guide-risks.json · wiki/guide-what.json · wiki/stage-11.json · wiki/stage-02.json · tests/f52.sh(새) — 이 열일곱만.
금지 패턴  : SQL 문자열 결합, innerHTML/outerHTML/document.write, 사용자 입력 파일 경로, 외부 요청, eval/exec,
            역할 판단을 Authz/Auth 밖에서(화면·핸들러가 role 문자열을 직접 비교하지 않는다), 요청 파라미터의 role 값,
            「나중에」를 서버·DB·쿠키에 저장하기, 관문 코드(public/index.php)·DB 스키마·마이그레이션 변경
완료 기준  : PRD 12.2 T-64 (a)~(f) 전부 실행. tests/f52.sh 가 그것을 한다.
보고 형식  : 변경 파일 / 실행 명령·출력 원문 / 못 한 것과 이유
```

## 손대지 않는다
`public/index.php`(관문 [2-G] 코드는 그대로 — 조건은 `Auth::nextStep` 안에서 바뀐다) · `src/Totp.php` · `src/Qr.php` · `src/handlers/mfa_enroll.php` · `src/Authz.php` · `src/Http.php` · `src/Audit.php` · `db/*` · `deploy/*` · `.env`(읽기 금지) · PRD/ARCH/SECURITY/STAGES.
운영 데이터에 손대지 않는다. 시험은 **`qa_employee01`·`qa_admin01` 만**, 모든 요청에 `-A "tbox-review/$(date +%F)"`. 시험이 두 계정의 2단계 인증을 껐다 켜므로 **끝에는 반드시 다시 켜고 `/etc/tbox-v2/qa-totp.txt` 를 갱신**한다(`tests/f51.sh` (b) 의 방식 그대로).

## 1) `src/Auth.php` — 바뀌는 곳은 상수 하나 · 함수 둘 · `nextStep` 조건 하나
```php
/** 2단계 인증이 **필수**인 역할 (PRD F-52, 사용자 결정 2026-09-16 「Admin 만」). Manager 를 넣으려면 여기 한 단어 — 문서(PRD F-52·SECURITY R-85) 먼저. */
public const MFA_REQUIRED_ROLES = ['Admin'];

/** 이 사용자에게 2단계 인증이 필수인가 — **실제 역할**로 판정한다 (「역할로 보기」 중 `role` 은 흉내 역할, `real_role` 이 진짜). */
public static function mfaRequired(array $user): bool
{
    $role = (string) ($user['real_role'] ?? $user['role'] ?? '');
    return in_array($role, self::MFA_REQUIRED_ROLES, true);
}

/** 필수는 아니지만 아직 안 켠 상태 — 화면이 권장 카드를 보일지 고르는 값 */
public static function mfaRecommended(array $user): bool
{
    return !self::mfaRequired($user) && (($user['mfa_enabled'] ?? false) !== true);
}
```
- `nextStep`: `must_change_password` 갈래는 그대로. `mfa_setup` 갈래를
  `if (self::mfaRequired($user) && (($user['mfa_enabled'] ?? false) !== true)) return 'mfa_setup';` 로. **`mfa_setup_required` 는 더 이상 관문 조건이 아니다**(ARCH 5.26 이유). 주석을 그에 맞게 고친다.
- `login()` 이 돌려주는 배열에는 `real_role` 이 없고 `role` 만 있다 — 로그인 시점에는 흉내 역할이 없으므로 `role` 이 곧 실제 역할이다. `currentUser()` 배열에는 `real_role` 이 있다. 위 `??` 순서가 그것을 처리한다. 다른 곳은 손대지 않는다(`Auth.php` 는 보안 핵심 파일 — diff 가 이 셋 밖으로 번지면 안 된다).

## 2) 응답 두 값 — `login.php` · `me.php`
- `login.php`: `Http::json(['ok' => true, 'next' => Auth::nextStep($result), 'mfa_required' => Auth::mfaRequired($result), 'mfa_recommended' => Auth::mfaRecommended($result)]);`
- `me.php`: `next` 옆에 `'mfa_required' => Auth::mfaRequired($user), 'mfa_recommended' => Auth::mfaRecommended($user),` — 주석: 둘 다 **상태**이지 권한이 아니고, 화면이 무시해도 관문은 서버가 막는다(S-01). 기존 `mfa_setup_required` 는 그대로 둔다.
- `mfa_confirm.php`: `$forced = $row['mfa_setup_required'] === true;` → `$forced = $row['mfa_setup_required'] === true && Auth::mfaRequired($user);` (권장으로 켠 것은 `forced` 가 아니다). 주석 갱신.
- `mfa_disable.php` 101행·`users_mfa_reset.php` 16~17행·`settings_page.php` 126행의 「모든 역할에 필수」 주석을 「필수는 Admin 만(F-52), 나머지는 권장」으로. 로직은 그대로(해제·초기화가 `mfa_setup_required=true` 로 두는 것은 유지 — Admin 이면 관문, 아니면 권장).

## 3) 홈 권장 카드 — `home.php` · `app.js` · `app.css`
- `home.php`: `#forced-gate` 바로 **위**에
  ```html
  <!-- F-52 — 2단계 인증 권장 (Admin 외 역할, 켜지 않은 경우). app.js 가 /api/me 의 mfa_recommended 로 연다.
       「나중에」는 이 브라우저의 이 세션(sessionStorage)에만 남고 서버는 아무것도 저장하지 않는다 — 다음 로그인에 다시 보인다. -->
  <section class="notice notice--recommend" id="mfa-recommend" hidden>
    <p><strong>2단계 인증을 켜 두는 것을 권장합니다.</strong> 비밀번호가 새어도 인증 앱의 6자리 코드가 없으면 계정에 들어올 수 없습니다.</p>
    <p class="notice__actions">
      <a class="btn btn--small" href="/settings">지금 켜기</a>
      <button id="mfa-recommend-later" class="btn btn--quiet btn--small" type="button">나중에</button>
    </p>
  </section>
  ```
  기존 `.notice`/`.btn` 클래스가 있으면 그대로 쓰고, 없으면 app.css 에 최소 스타일(테두리·여백·강조선)을 더한다. 색은 토큰만.
- `app.js`: `page === 'files'` 분기에서 `initForcedGate` 로 빠지지 않은 경우(`next === null`)에 `initMfaRecommend(me)` 를 부른다:
  ```js
  function initMfaRecommend(me) {
    var box = document.getElementById('mfa-recommend');
    var later = document.getElementById('mfa-recommend-later');
    if (!box || me.mfa_recommended !== true) { return; }
    var dismissed = false;
    try { dismissed = window.sessionStorage.getItem('tbox-mfa-later') === '1'; } catch (e) { dismissed = false; }
    if (dismissed) { return; }
    box.hidden = false;
    if (later) {
      later.addEventListener('click', function () {
        try { window.sessionStorage.setItem('tbox-mfa-later', '1'); } catch (e) { /* 저장 못 해도 닫는다 */ }
        box.hidden = true;
      });
    }
  }
  ```
  값은 `me` 의 boolean 만 본다 — 역할 문자열을 화면에서 비교하지 않는다.
- `app.js` `initAccount`/`showMfa`: 상태 문구를 `me.mfa_required` 로 갈래 — `initAccount` 의 인자에 `mfaRequired`(boolean) 를 하나 더 넘긴다(호출부 확인). 켜짐: 기존 문구. 꺼짐+필수: 「2단계 인증이 꺼져 있습니다. **이 역할(Admin)은 필수**라 켜기 전에는 다른 기능을 쓸 수 없습니다.」 꺼짐+선택: 「2단계 인증이 꺼져 있습니다. **선택이지만 켜 두기를 권장합니다** — 비밀번호가 새어도 계정을 지킵니다.」 + 기존 `MFA_CODE_HINT`.
- 강제 절차 상자(`initForcedGate`)의 2단계 인증 걸음 문구가 「모든 역할 필수」를 말하면 「Admin 은 필수」로.

## 4) 문구 — `src/help/screens.php` · `README.md` · `wiki/*.json`
- 「모든 역할에 필수」·「모든 역할 필수」·「전 계정 필수」 를 찾아 **화면·도움말·README·위키**에서 0건으로: `grep -rn "모든 역할" src public README.md wiki`. 과거 기록(STAGES·SECURITY 7장·CHANGELOG)은 손대지 않는다.
- README 1195행 부근: 「2단계 인증(TOTP)은 **Admin 에게 필수**이고(11단계 F-16 → F-52 2026-09-16) 다른 역할은 선택입니다. 켜 두기를 권장하며, 켜지 않은 계정은 로그인할 때마다 홈에 권장 안내가 나옵니다.」
- wiki 4곳: 같은 뜻으로 한 문장씩(JSON 문자열 안, 따옴표 이스케이프 주의 — 수정 뒤 `python3 -m json.tool` 로 네 파일 파싱 확인).
- `screens.php` 2단계 인증 항목: 「Admin 은 필수, 다른 역할은 선택(권장)」 한 줄. `tests/help_button.sh` 의 금지 문구 규칙(`[0-9]{1,2}단계(?! 인증)`)에 걸리지 않게 「N단계」를 쓰지 않는다.

## 5) `tests/f52.sh` — PRD T-64 (a)~(f)
`tests/f51.sh` 골격(`psqlq`·`login`·`expect`·R-73 사전 검사·qa-totp 갱신 함수·다음 창 대기)을 복사한다. **`login` 은 계정의 2단계 인증이 꺼져 있으면 otp 없이** 보내도록 갈래를 둔다(`/api/me` 나 DB `mfa_enabled` 로 판단).
- **(a) 단위** — `php -r 'require "src/Auth.php"; …'` 가 안 되면(의존 클래스) `require "src/bootstrap.php"` 가 DB 를 여는지 확인하고, 여는 경우 `Auth.php` 만 `require` 한 뒤 필요한 상수/클래스 스텁을 같은 스크립트 안에 정의해 순수 함수 셋만 부른다. 케이스: 역할 `Admin/Manager/Employee/Guest` × `mfa_enabled` true/false → `nextStep`·`mfaRequired`·`mfaRecommended` 기대표(PRD T-64 (a)); `['real_role'=>'Admin','role'=>'Employee','mfa_enabled'=>false]` → `mfa_setup`; `['real_role'=>'Employee','role'=>'Employee','mfa_enabled'=>false]` → `null`+recommended; `must_change_password=true` → `password_change`(역할 무관).
- **(b) Employee** — `qa_employee01` 로그인(코드) → `POST /api/me/mfa/disable` → `GET /api/me`: `next` null·`mfa_required` false·`mfa_recommended` true·`mfa_enabled` false → `GET /api/files` 200 → 로그아웃 → 로그인(**코드 없이**) 200 + 본문 `mfa_recommended:true` → Playwright(같은 방식, 390×844): 홈 `#mfa-recommend` 보임·「지금 켜기」 `href="/settings"`·「나중에」 클릭 → 숨김 → `page.reload()` 뒤에도 숨김 → **새 브라우저 컨텍스트**로 다시 로그인 → 다시 보임 → `/settings` 상태 문구에 「권장」 포함. 스크린샷 2장(카드 보임/숨김). → `enroll`+`confirm`(새 비밀 코드) → `qa-totp.txt` 갱신 → `/api/me` `mfa_recommended:false` → Playwright 홈에 카드 없음.
- **(c) Admin** — `qa_admin01` 로그인 → `disable` → `/api/me` `next:"mfa_setup"`·`mfa_required:true`·`mfa_recommended:false` → `GET /api/files` **403** 본문 `next:"mfa_setup"` → 감사 `auth.gate deny mfa_setup_required` 1건 이상(`happened_at > T0`, actor `qa_admin01`) → Playwright 홈: `#forced-gate` 보임·`#mfa-recommend` 숨김(스크린샷 1장) → `enroll`+`confirm` → `qa-totp.txt` 갱신 → `/api/me` `next:null`.
- **(d)** (b) 안에서: 끈 뒤 코드 없이 로그인 200, 켠 뒤 코드 없이 로그인 401 `mfa_required:true`(기존 순차 2단계).
- **(e)** `grep -rn "모든 역할" src public README.md wiki` → 0.
- **(f)** 린트 0 · `php -l`(Auth·login·me·mfa_confirm·home·settings_page·screens) · `python3 -m json.tool` wiki 4파일 · 비-qa 감사 0 · 끝난 뒤 두 계정 `mfa_enabled = t`.

## 6) 완료 전 자기 점검
- ARCHITECTURE 13장. `git diff src/Auth.php` 가 상수·함수 둘·`nextStep` 조건·주석 밖으로 번지지 않았는지 보고에 diff 요약을 적는다.
- `git status` 에 열일곱 파일 밖의 변경이 없는지. 비밀 값을 출력에 남기지 않았는지. 커밋하지 않는다.

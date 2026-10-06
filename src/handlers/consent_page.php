<?php
declare(strict_types=1);

/**
 * src/handlers/consent_page.php — GET /consent 「약관·동의」 (사이트 Q:F-13 P1 · 기획 5.1 「동의·고지」 · 5.2 「기존 회원」 · 결정 PT-4 · PT-8)
 *
 * 정책 authenticated. 동의 관문(Auth::requireConsent)이 막힌 **화면** 요청을 이리로 302 한다(보던 곳은 `?to=` — Http::returnPath 의
 * 허용 목록 그대로, 그 밖은 버린다). 이미 동의한 사람이 와도 같은 화면이다 — 지금 상태와 AI 국외 전송 동의·철회를 본다.
 *
 * 화면은 판단하지 않는다: 체크 둘은 편의이고, 막는 것은 서버(관문 · `POST /api/me/consents`)다. 단추는 member.js 가 창구를 부른다.
 * 새 화면 — 모아 안내 화면과 같은 구성 요소만(.moa-setup-heading · .moa-setup-card · .form · .field · .btn · .info-grid · .help) — 새 클래스 0.
 */

require_once __DIR__ . '/../Member.php';
require_once __DIR__ . '/../Legal.php';

$consentSummary = Legal::summary((int) $user['id']);
$consentNeeded = $consentSummary['required'];
$consentAi = $consentSummary['ai_overseas'];
$consentLast = $consentSummary['consents'];
$consentToRaw = $_GET['to'] ?? null;
$consentTo = is_string($consentToRaw) ? Http::returnPath($consentToRaw) : null;
$consentNotice = Legal::aiNotice();
$memberTitle = '약관·동의';
$memberPage = 'consent';
require __DIR__ . '/../views/member_top.php';
$consentWhen = static function (?array $row): string {
    if ($row === null) {
        return '—';
    }
    $at = strtotime($row['at']);
    return $at === false ? '—' : date('Y-m-d H:i', $at);
};
?>
<div class="moa-setup-heading"><p class="moa-kicker">TERMS &amp; PRIVACY</p><h1>약관과 개인정보 처리방침</h1><p><?= $consentNeeded ? '모아를 계속 쓰려면 한 번만 확인해 주세요.' : '동의한 내용과 AI 국외 전송 동의를 여기서 확인하고 바꿀 수 있어요.' ?></p></div>
<p id="member-status" role="status" aria-live="polite"></p>
<?php if ($consentNeeded): ?>
<section class="moa-setup-card" id="consent-required" aria-labelledby="consent-required-title">
<h2 id="consent-required-title">이용약관 동의 · 개인정보 처리방침 확인</h2>
<p>모아는 계정(이름·아이디·소속 팀)과 이용하며 만든 글·파일·메시지, 접속 기록을 회원 관리·서비스 제공·보안에 씁니다. 계정 정보는 회원인 동안, 메시지와 AI 대화는 1년, 감사 기록은 3년 보관합니다. 자세한 항목·기간·권리는 개인정보 처리방침에 있어요.</p>
<form id="consent-form" class="form">
<label class="field"><span class="field__label"><input id="consent-terms" type="checkbox" required> 이용약관에 동의합니다 (필수)</span></label>
<label class="field"><span class="field__label"><input id="consent-privacy" type="checkbox" required> 개인정보 처리방침을 확인했습니다 (필수)</span></label>
<p class="legal-links"><a href="<?= Http::url('/terms') ?>" target="_blank" rel="noopener">이용약관 전문 보기</a> · <a href="<?= Http::url('/privacy') ?>" target="_blank" rel="noopener"><strong>개인정보 처리방침</strong> 전문 보기</a></p>
<button class="btn moa-primary" type="submit">동의하고 계속하기</button>
</form>
<p class="help">동의하지 않으면 모아를 쓸 수 없어요. <button id="consent-logout" class="btn btn--quiet btn--small" type="button">로그아웃</button></p>
</section>
<?php else: ?>
<section class="moa-setup-card" id="consent-done" aria-labelledby="consent-done-title">
<h2 id="consent-done-title">동의한 내용</h2>
<div class="info-grid">
<div class="info-item"><span class="field__label">이용약관</span><span class="info-item__value">동의함 · 판 <?= htmlspecialchars((string) ($consentLast['terms']['version'] ?? '—'), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($consentWhen($consentLast['terms']), ENT_QUOTES, 'UTF-8') ?></span></div>
<div class="info-item"><span class="field__label">처리방침</span><span class="info-item__value">확인함 · 판 <?= htmlspecialchars((string) ($consentLast['privacy']['version'] ?? '—'), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($consentWhen($consentLast['privacy']), ENT_QUOTES, 'UTF-8') ?></span></div>
</div>
<p class="legal-links"><a href="<?= Http::url('/terms') ?>">이용약관 보기</a> · <a href="<?= Http::url('/privacy') ?>"><strong>개인정보 처리방침</strong> 보기</a></p>
</section>
<?php endif; ?>
<section class="moa-setup-card" id="consent-ai" aria-labelledby="consent-ai-title"<?= $consentNeeded ? ' hidden' : '' ?>>
<h2 id="consent-ai-title"><?= htmlspecialchars($consentNotice['title'], ENT_QUOTES, 'UTF-8') ?></h2>
<p>AI 도움 기능(모아 AI · 메시지 AI 도구 · 사진 이야기 · 업무 문서 AI)을 쓰면 요청한 내용이 아래처럼 국외로 전송돼요. 처음 실행할 때 한 번 묻고, 여기서 언제든 바꿀 수 있어요.</p>
<div class="info-grid">
<?php foreach ($consentNotice['rows'] as [$consentLabel, $consentValue]): ?>
<div class="info-item"><span class="field__label"><?= htmlspecialchars($consentLabel, ENT_QUOTES, 'UTF-8') ?></span><span class="info-item__value"><?= htmlspecialchars($consentValue, ENT_QUOTES, 'UTF-8') ?></span></div>
<?php endforeach; ?>
</div>
<p class="help">지금 상태: <strong id="consent-ai-state"><?= $consentAi ? '동의함' : '동의하지 않음' ?></strong> · <a href="<?= htmlspecialchars($consentNotice['policy_url'], ENT_QUOTES, 'UTF-8') ?>">처리방침 제7조 보기</a></p>
<div class="moa-setup-actions"><button id="consent-ai-agree" class="btn" type="button"<?= $consentAi ? ' hidden' : '' ?>>국외 전송에 동의하기</button><button id="consent-ai-withdraw" class="btn btn--danger" type="button"<?= $consentAi ? '' : ' hidden' ?>>동의 철회하기</button><a href="/">모아 둘러보기 →</a></div>
</section>
</main></body></html>

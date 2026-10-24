<?php
declare(strict_types=1);
/*
 * 사이트 H4 (DESIGN 2.33 「자리 · 크기」 바닥 · 「바닥 붙이기」) — 모아 바닥 한 벌(런처 세 갈래 · 가입 · 동의 · 초대 요청 · 요청 확인).
 * 「모아.」 글자 로고(moa_wordmark.php · 22) + 작은 「moa」 + 한 줄 + 법적 문서 링크(2.23 — 처리방침 굵게). 고정 글자 · 고정 주소뿐(동적 값 0).
 * 짧은 화면에서 창 맨 아래에 붙는 것은 moa.css(`body.moa-site:has(> footer.moa-footer)` — body 세로 flex · 본문 flex:1)가 한다.
 */
?><footer class="moa-footer"><a class="moa-logo" href="/"><?php require __DIR__ . '/moa_wordmark.php'; ?><span>moa</span></a><p>우리 회사의 일과 자료를 한곳에 모아.</p><p class="legal-links"><a href="<?= Http::url('/privacy') ?>"><strong>개인정보 처리방침</strong></a> · <a href="<?= Http::url('/terms') ?>">이용약관</a></p></footer>

<?php
declare(strict_types=1);
/*
 * 사이트 H4 (DESIGN 2.33 「부품 span.moa-wordmark」) — 「모아.」 글자 로고 **한 벌**. 모든 자리(모아 홈 · 로그인 전 첫 화면 · 머리 로고 · 바닥 · 로그인 화면)가 이 파일을 require 한다.
 * 받는 값(부르는 쪽이 정한 참/거짓 둘 — 요청 값 0):
 *   $moaWordmarkLogo  참이면 앞에 「m.」 상징(머리 로고 자리 — 바탕 --brand-wordmark · 「.」 은 --brand-wordmark-dot)
 *   $moaWordmarkLabel 거짓이면 화면 읽기에서 숨김(aria-hidden — 이미 화면 읽기 제목이 따로 있는 자리), 기본 참(이름 「모아」 하나)
 * 크기는 자리마다 CSS font-size 만(moa.css). 색은 app.css 의 brand 토큰 넷(갈래 data-wordmark · 고대비는 --fg). 글자 「모」 · 「아」 를 나눈 것은 C 갈래의 두 색 때문이다.
 * 고정 글자만 내보낸다(동적 값 0). 부른 뒤 두 값은 기본으로 되돌린다(다음 자리에 새지 않게).
 */
if (($moaWordmarkLogo ?? false) === true): ?><span class="moa-symbol" aria-hidden="true">m<span class="moa-symbol__dot">.</span></span><?php endif;
?><span class="moa-wordmark"<?= ($moaWordmarkLabel ?? true) === false ? ' aria-hidden="true"' : ' role="img" aria-label="모아"' ?>><span class="moa-wordmark__mo">모</span><span class="moa-wordmark__a">아</span><span class="moa-wordmark__dot" aria-hidden="true">.</span></span><?php
$moaWordmarkLogo = false;
$moaWordmarkLabel = true;

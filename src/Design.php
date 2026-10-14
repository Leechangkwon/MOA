<?php
declare(strict_types=1);

/** Shared visual preferences only. No session, database, authentication or request URL input. */
final class Design
{
    public const THEMES = [
        'moa' => ['모아', '서비스별 컬러 · 차분한 작업 공간'],
        'claude' => ['Claude', '기존의 차분한 흑백'],
        'astra' => ['Astra', '코발트 블루 · 민트'],
        'aurora' => ['Aurora', '에메랄드 · 골드'],
        'bloom' => ['Bloom', '코랄 · 크림'],
        'nova' => ['Nova', '스펙트럼 · 빛의 궤도'],
    ];

    public static function theme(): string
    {
        $value = $_COOKIE['qaredi_design'] ?? null;
        return is_string($value) && isset(self::THEMES[$value]) ? $value : 'moa';
    }

    /**
     * 사이트 H4 (DESIGN 2.33 「고를 수 있게」) — 「모아.」 글자 로고 색 갈래. 기본 d(진한 보라 + 노란 점) · a 보라 → 파랑 · b 주황 → 분홍 · c 두 색.
     * 쿠키 `qaredi_wordmark`(테마와 같은 길 — design.js 가 쓴다) 값은 이 허용 목록과 **견주기만** 하고, 나가는 글자는 목록의 키뿐이다(밖이면 d).
     */
    public const WORDMARKS = ['d' => 'D 보라 + 노란 점', 'a' => 'A 보라 → 파랑', 'b' => 'B 주황 → 분홍', 'c' => 'C 두 색 (모 보라 · 아 청록)'];

    public static function wordmark(): string
    {
        $value = $_COOKIE['qaredi_wordmark'] ?? null;
        return is_string($value) && isset(self::WORDMARKS[$value]) ? $value : 'd';
    }

    public static function attrs(): string
    {
        $motion = ($_COOKIE['qaredi_motion'] ?? null) === 'reduce' ? 'reduce' : 'auto';
        return ' data-design="' . self::theme() . '" data-motion="' . $motion . '" data-wordmark="' . self::wordmark() . '"';
    }

    public static function assets(bool $interactive = true): string
    {
        $base = htmlspecialchars(Http::url('/assets/'), ENT_QUOTES, 'UTF-8');
        $css = (int) filemtime(__DIR__ . '/../public/assets/design.css');
        $js = (int) filemtime(__DIR__ . '/../public/assets/design.js');
        return '<link rel="stylesheet" href="' . $base . 'design.css?v=' . $css . '">'
            . '<link rel="stylesheet" href="' . $base . 'moa.css?v=' . (int) filemtime(__DIR__ . '/../public/assets/moa.css') . '">'
            . ($interactive ? '<script defer src="' . $base . 'design.js?v=' . $js . '"></script>' : '');
    }
}

<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Design.php';
$count = 0;
// 사이트 H4(DESIGN 2.33) 뒤 Design::attrs() 는 끝에 data-wordmark 를 붙인다 — 쿠키가 없거나 허용 목록 밖이면 d(기본).
foreach ([null, '', 'bad', [], ['nova'], 'nova" onload="alert(1)', '<svg>', 123, 'NOVA'] as $bad) {
    $_COOKIE = ['qaredi_design'=>$bad, 'qaredi_motion'=>[], 'qaredi_wordmark'=>$bad];
    if (Design::theme() !== 'moa' || Design::wordmark() !== 'd' || Design::attrs() !== ' data-design="moa" data-motion="auto" data-wordmark="d"') { throw new RuntimeException('invalid preference escaped allowlist'); }
    $count++;
}
foreach ([null, '', 'e', 'D', [], ['a'], 'a" onload="alert(1)', '<svg>', 1] as $bad) {
    $_COOKIE = ['qaredi_design'=>'moa', 'qaredi_motion'=>'auto', 'qaredi_wordmark'=>$bad];
    if (Design::wordmark() !== 'd' || Design::attrs() !== ' data-design="moa" data-motion="auto" data-wordmark="d"') { throw new RuntimeException('invalid wordmark escaped allowlist'); }
    $count++;
}
foreach (array_keys(Design::THEMES) as $theme) {
    foreach (['auto', 'reduce'] as $motion) {
        $_COOKIE = ['qaredi_design'=>$theme, 'qaredi_motion'=>$motion];
        if (Design::theme() !== $theme || Design::attrs() !== ' data-design="' . $theme . '" data-motion="' . $motion . '" data-wordmark="d"') { throw new RuntimeException('valid preference failed'); }
        $count++;
    }
}
foreach (array_keys(Design::WORDMARKS) as $wm) {
    $_COOKIE = ['qaredi_design'=>'nova', 'qaredi_motion'=>'reduce', 'qaredi_wordmark'=>$wm];
    if (Design::wordmark() !== $wm || Design::attrs() !== ' data-design="nova" data-motion="reduce" data-wordmark="' . $wm . '"') { throw new RuntimeException('valid wordmark failed'); }
    $count++;
}
echo "Design allowlist: $count passed\n";
echo "RESULT pass=$count fail=0 skip=0\n";

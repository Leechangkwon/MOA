<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Design.php';
$count = 0;
foreach ([null, '', 'bad', [], ['nova'], 'nova" onload="alert(1)', '<svg>', 123, 'NOVA'] as $bad) {
    $_COOKIE = ['qaredi_design'=>$bad, 'qaredi_motion'=>[]];
    if (Design::theme() !== 'moa' || Design::attrs() !== ' data-design="moa" data-motion="auto"') { throw new RuntimeException('invalid preference escaped allowlist'); }
    $count++;
}
foreach (array_keys(Design::THEMES) as $theme) {
    foreach (['auto', 'reduce'] as $motion) {
        $_COOKIE = ['qaredi_design'=>$theme, 'qaredi_motion'=>$motion];
        if (Design::theme() !== $theme || Design::attrs() !== ' data-design="' . $theme . '" data-motion="' . $motion . '"') { throw new RuntimeException('valid preference failed'); }
        $count++;
    }
}
echo "Design allowlist: $count passed\n";

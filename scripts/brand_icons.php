<?php
declare(strict_types=1);

/**
 * scripts/brand_icons.php — 홈 화면 설치 아이콘 PNG 192 · 512 (`public/assets/qaredi-icon-192.png` · `qaredi-icon-512.png`)를 다시 만든다
 *                           (사이트 Q:F-15 S1 · DESIGN 5장 「브랜드 이름 3층」 · 2.33 D 갈래)
 *
 * 다시 만들 때 쓰는 법: 저장소 뿌리에서 `php scripts/brand_icons.php` 한 번 → 두 PNG 를 함께 커밋한다(운영에서 돌지 않는 개발 도구).
 *
 * 모양: 보라 `#6d28d9`(2.33 `--wm-d`) 꽉 찬 바탕 · 가운데 60% 안에 흰 「m」(`--wm-on` #ffffff · serif 굵은 기울임 — 「m.」 상징 `.moa-symbol` 과 같은 모양)
 *       + 노란 점 `#f59e0b`(`--wm-d-dot`). 가장자리를 둥글게 잘라도(maskable) 글자와 점이 남는다. 파일 이름 · 크기 · RGB PNG 는 그대로(기술 식별자).
 * 글꼴: 이 서버에 있는 serif 굵은 기울임 하나(Liberation Serif Bold Italic) — 없으면 DejaVu Serif Bold. 외부 요청 0 · 요청 값 0.
 * 4배로 그린 뒤 줄여(imagecopyresampled) 글자 · 점 가장자리를 부드럽게 한다.
 */

if (PHP_SAPI !== 'cli') {
    exit(2);
}
if (!function_exists('imagecreatetruecolor') || !function_exists('imagettftext')) {
    fwrite(STDERR, "GD(FreeType) 확장이 필요합니다\n");
    exit(1);
}

$root  = dirname(__DIR__);
$fonts = [
    '/usr/share/fonts/truetype/liberation/LiberationSerif-BoldItalic.ttf',
    '/usr/share/fonts/truetype/dejavu/DejaVuSerif-Bold.ttf',
];
$font = null;
foreach ($fonts as $candidate) {
    if (is_file($candidate)) {
        $font = $candidate;
        break;
    }
}
if ($font === null) {
    fwrite(STDERR, "serif 글꼴을 찾지 못했습니다\n");
    exit(1);
}

/** 한 크기의 아이콘을 만든다. 돌려주는 값은 RGB 트루컬러 그림. */
function brand_icon(int $size, string $font): GdImage
{
    $scale = 4;
    $big   = $size * $scale;
    $im    = imagecreatetruecolor($big, $big);
    imagealphablending($im, true);
    $purple = imagecolorallocate($im, 0x6d, 0x28, 0xd9);
    $white  = imagecolorallocate($im, 0xff, 0xff, 0xff);
    $yellow = imagecolorallocate($im, 0xf5, 0x9e, 0x0b);
    imagefilledrectangle($im, 0, 0, $big - 1, $big - 1, $purple);

    // 「m」 폭이 그림의 42% 가 되는 글자 크기 · 점 지름 11% · 사이 2% → 묶음 폭 ≈ 55%(가운데 60% 안)
    $pt   = 100.0;
    $box  = imagettfbbox($pt, 0, $font, 'm');
    $mW   = $box[2] - $box[0];
    $pt   = $pt * ($big * 0.42) / $mW;
    $box  = imagettfbbox($pt, 0, $font, 'm');
    $left = min($box[0], $box[6]);
    $mW   = max($box[2], $box[4]) - $left;
    $mTop = min($box[5], $box[7]);          // 기준선 위쪽(음수)
    $mH   = max($box[1], $box[3]) - $mTop;   // 기준선 아래 포함 높이
    $dot  = (int) round($big * 0.11);
    $gap  = (int) round($big * 0.02);
    $groupW = $mW + $gap + $dot;
    $x0   = (int) round(($big - $groupW) / 2);
    $baseline = (int) round(($big - $mH) / 2 - $mTop);
    imagettftext($im, $pt, 0, $x0 - $left, $baseline, $white, $font, 'm');
    $cx = $x0 + $mW + $gap + intdiv($dot, 2);
    $cy = $baseline - intdiv($dot, 2);
    imagefilledellipse($im, $cx, $cy, $dot, $dot, $yellow);

    $out = imagecreatetruecolor($size, $size);
    imagealphablending($out, false);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $size, $size, $big, $big);
    imagedestroy($im);
    imagesavealpha($out, false);
    return $out;
}

foreach ([192, 512] as $size) {
    $path = $root . '/public/assets/qaredi-icon-' . $size . '.png';
    $img  = brand_icon($size, $font);
    if (!imagepng($img, $path, 9)) {
        fwrite(STDERR, "쓰지 못했습니다: qaredi-icon-$size.png\n");
        exit(1);
    }
    imagedestroy($img);
    echo 'qaredi-icon-' . $size . '.png ' . filesize($path) . " bytes\n";
}
echo 'font ' . basename($font) . "\n";

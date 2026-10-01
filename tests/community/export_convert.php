<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(2); }
set_error_handler(static function (int $no, string $text): bool { throw new ErrorException($text, 0, $no); });
require_once dirname(__DIR__, 2) . '/src/community/Doc.php';
require_once dirname(__DIR__, 2) . '/src/community/DocMarkdown.php';
require_once dirname(__DIR__, 2) . '/src/community/DocRender.php';
require_once dirname(__DIR__, 2) . '/src/community/DocExport.php';
final class Http {
    public static function url(string $path): string { return '/tbox' . $path; }
    public static function communityUrl(string $path): string { return '/community' . $path; }
}
$baseline = getenv('EXPORT_BASELINE_RENDERER');
if (is_string($baseline) && $baseline !== '') { require $baseline; }
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/community/doc-export.css');
$ctx = $input['ctx'] ?? [];
$normal = DocRender::html($input['doc'], $ctx);
$out = ['md' => DocExport::markdown($input['doc'], $input['meta'], $ctx),
    'html' => DocExport::html($input['doc'], $input['meta'], $ctx, $css),
    'normal' => $normal, 'parity' => class_exists('BaselineDocRender') ? $normal === BaselineDocRender::html($input['doc'], $ctx) : null];
$used = [];
$out['names'] = array_map(static function (string $title) use (&$used): string {
    return DocExport::uniqueName($title, 'md', $used);
}, $input['names'] ?? []);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

<?php
declare(strict_types=1);

/** Dedicated authenticated SSR page. No editor, external frames or client document fetch. */
$root = community_export_gate((string) $params[0], $user);
[$row, $doc] = community_export_document($root, $user);
$ctx = community_export_context($doc, $user, [], '');
$ctx['print'] = true;
$meta = community_export_meta($row, gmdate('c'));
$esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$html = '<!doctype html><html lang="ko"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="referrer" content="no-referrer">'
    . '<title>' . $esc((string) $row['title']) . '</title>'
    . '<link rel="stylesheet" href="' . $esc(Http::url('/assets/community/doc-export.css')) . '">'
    . '<script defer src="' . $esc(Http::url('/assets/community/print.js')) . '"></script></head><body>'
    . '<div class="doc-print-actions"><button type="button" id="print-page">인쇄</button></div><main>'
    . DocExport::metadataHtml($meta) . DocRender::html($doc, $ctx) . '</main></body></html>';
if (strlen($html) > DocExport::MAX_BYTES) {
    community_export_too_large($row);
    return;
}
community_export_allow($row, 'print', false, [(string) $row['public_id']], 0, strlen($html));
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'");
echo $html;

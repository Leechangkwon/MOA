<?php
declare(strict_types=1);

/** GET /community/api/pages/{id}/export. Policy community.write + per-page gate. */
$root = community_export_gate((string) $params[0], $user);
$format = $_GET['format'] ?? 'md';
$treeRaw = $_GET['tree'] ?? '0';
if (!is_string($format) || !in_array($format, ['md', 'html'], true)
    || !is_string($treeRaw) || !in_array($treeRaw, ['0', '1'], true)) {
    Http::error(400, '형식과 하위 페이지 포함 여부를 확인해 주세요');
}
$tree = $treeRaw === '1';
$tmp = null;
$zip = null;
try {
    [$entries, $skipped] = community_export_collect($root, $user, $tree, $format);
    $paths = [];
    foreach ($entries as $entry) {
        if ($tree) {
            $paths[(string) $entry['row']['public_id']] = $entry['path'];
        }
    }
    $css = $format === 'html' ? file_get_contents(__DIR__ . '/../../../public/assets/community/doc-export.css') : '';
    if (!is_string($css)) {
        throw new RuntimeException('내보내기 서식을 읽을 수 없습니다');
    }
    $now = gmdate('c');
    $bytes = 0;
    $contents = [];
    $ids = [];
    foreach ($entries as $entry) {
        [$row, $doc] = community_export_document($entry['row'], $user);
        $ctx = community_export_context($doc, $user, $paths, $entry['path']);
        $meta = community_export_meta($row, $now);
        $body = $format === 'md' ? DocExport::markdown($doc, $meta, $ctx) : DocExport::html($doc, $meta, $ctx, $css);
        $bytes += strlen($body);
        if ($bytes > DocExport::MAX_BYTES) {
            throw new LengthException('too_large');
        }
        $contents[$entry['path']] = $body;
        $ids[] = (string) $row['public_id'];
    }
    if (!$tree) {
        community_export_allow($root, $format, false, $ids, 0, $bytes);
        community_export_headers(DocExport::filename((string) $root['title'], $format), $format);
        echo reset($contents);
    } else {
        $index = DocExport::index($entries, $skipped, $format, $css);
        $bytes += strlen($index);
        if ($bytes > DocExport::MAX_BYTES) {
            throw new LengthException('too_large');
        }
        // Existing deployment-owned allowed directory; never widen open_basedir or use a request path.
        $tmp = tempnam(Storage::tmpDir(), 'community-export-');
        if ($tmp === false) {
            throw new RuntimeException('내보내기 임시 파일을 만들 수 없습니다');
        }
        chmod($tmp, 0600);
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('내보내기 묶음을 만들 수 없습니다');
        }
        $contents[$format === 'md' ? 'README.md' : 'index.html'] = $index;
        foreach ($contents as $path => $body) {
            if (!$zip->addFromString($path, $body)) {
                throw new RuntimeException('내보내기 묶음에 문서를 넣을 수 없습니다');
            }
        }
        if (!$zip->close()) {
            throw new RuntimeException('내보내기 묶음을 마칠 수 없습니다');
        }
        $zip = null;
        $zipBytes = filesize($tmp);
        if ($zipBytes === false || $zipBytes > DocExport::MAX_BYTES) {
            throw new LengthException('too_large');
        }
        community_export_allow($root, $format, true, $ids, $skipped, $zipBytes);
        community_export_headers(DocExport::filename((string) $root['title'], 'zip'), 'zip');
        readfile($tmp);
    }
} catch (LengthException $e) {
    community_export_too_large($root);
} finally {
    try {
        if ($zip instanceof ZipArchive) {
            try { $zip->close(); } catch (Throwable $cleanupError) {
                error_log('[community-export] archive close failed: ' . get_class($cleanupError));
            }
        }
    } finally {
        if (is_string($tmp) && is_file($tmp)) {
            unlink($tmp);
        }
    }
}

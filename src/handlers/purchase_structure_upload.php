<?php
declare(strict_types=1);

/**
 * src/handlers/purchase_structure_upload.php — POST /api/purchase/structure/upload
 *   (multipart/form-data `file`, 정책: purchase.master.write → buyer·Admin 만, **재인증 대상**)
 *
 * ★ 24단계 — 이카운트 창고 내보내기(ESA005M)를 **매핑 마법사 없이** 창고 대기표에 넣는다
 *   (PRD F-37, ARCH 5.18 「창고 대기표」, 지시서 docs/purchase/09-stage24-directive.md).
 *
 * 왜 「기본 지점」을 묻지 않나
 *   이카운트 창고 파일에는 지점 열이 아예 없다. 19단계 가져오기는 그래서 「기본 지점」을
 *   요구했고, 실자료 96행이 그 앞에서 **0건 진행**으로 멈췄다(2026-09-13 실측).
 *   여기서는 지점을 묻지 않고 **대기표**에 먼저 담는다 — 지점은 나중에 사람이 고른다.
 *
 * 순서
 *   [1~6] `Importer::storeUploaded()` — 가져오기 업로드와 **같은 한 함수**(지시서 보충 1):
 *         업로드 검사 · xlsx/csv · 민감정보 검출 · 「구매/가져오기」 폴더 · files 행 + 저장
 *   [7]   그 **트랜잭션 안에서** 표를 읽어 대기표 upsert → 감사 `allow` 한 줄(건수 포함)
 *
 * 한 트랜잭션인 이유: 파일만 남고 대기표가 비거나, 대기표만 차고 원본이 없는 상태를
 * 만들지 않기 위해서다. 표를 읽지 못하면(`parse_failed`) 파일까지 되돌아간다.
 *
 * 지점 격리 — `p_location_inbox` 는 **지점 축이 없는 마스터**다(연결 전의 창고라 지점이
 *   정해지지 않았다). 이 라우트는 buyer·Admin 전용이고, 표식은 조회마다 Structure.php 에 있다.
 *
 * @var array<string,mixed> $user
 * @var list<string>        $params 경로 변수 없음
 */

require_once __DIR__ . '/../Sensitive.php';
require_once __DIR__ . '/../purchase/Sheet.php';
require_once __DIR__ . '/../purchase/Importer.php';
require_once __DIR__ . '/../purchase/Master.php';
require_once __DIR__ . '/../purchase/Structure.php';

const TBOX_STRUCTURE_UPLOAD_ACTION = 'purchase.structure.upload';

/** 표를 읽다 실패했을 때 감사에 남길 파일 이름 — 이름을 정하는 곳은 storeUploaded 하나다. */
$fileName = null;
$counts   = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'renamed_linked' => 0];

try {
    $stored = Importer::storeUploaded(
        $_FILES['file'] ?? null,
        $user,
        TBOX_STRUCTURE_UPLOAD_ACTION,
        /**
         * 트랜잭션 안에서 하는 일 — 표 읽기 + 대기표 upsert.
         * 돌려준 배열은 감사 `allow` 의 detail 에 그대로 합쳐진다(한 줄에 건수까지 남는다).
         *
         * @param array<string,mixed> $s
         * @return array<string,int>
         */
        static function (array $s) use ($user, &$fileName, &$counts): array {
            $fileName = (string) $s['original'];
            try {
                $sheet = Sheet::rows(Storage::pathFor((string) $s['stored_name']), (string) $s['ext']);
            } catch (Throwable $e) {
                throw new RuntimeException('parse_failed');
            }
            $counts = Structure::ingest($sheet['rows'], (int) $s['file_id'], (int) $user['id']);
            return $counts;
        }
    );
} catch (RuntimeException $e) {
    // 여기까지 온 예외는 **트랜잭션이 이미 되돌아간 뒤**다(files 행도 저장한 파일도 없다).
    $reason = $e->getMessage();
    $entry  = [
        'target_type'  => 'file',
        'target_name'  => $fileName,
        'access_level' => 'admin-only',
    ];
    if ($reason === 'bad_header') {
        Audit::deny(TBOX_STRUCTURE_UPLOAD_ACTION, 'bad_header', $entry);
        Http::error(400, Structure::MSG_BAD_HEADER);
    }
    if ($reason === 'parse_failed') {
        Audit::error(TBOX_STRUCTURE_UPLOAD_ACTION, 'parse_failed', $entry);
        Http::error(400, Http::MSG_BAD_REQUEST);
    }
    throw $e;   // 그 밖의 예외는 부트스트랩의 500 으로 (내용은 사용자에게 나가지 않는다)
}

Http::json([
    'file_name'      => $stored['original'],
    'sha256'         => $stored['sha256'],
    'inserted'       => $counts['inserted'],
    'updated'        => $counts['updated'],
    'unchanged'      => $counts['unchanged'],
    'skipped'        => $counts['skipped'],
    'renamed_linked' => $counts['renamed_linked'],
], 201);

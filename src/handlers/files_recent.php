<?php
declare(strict_types=1);

/**
 * src/handlers/files_recent.php — GET /api/files/recent (정책: file.read — 개발 대기열 6 · Q:F-3a 라운드 A)
 *
 * 최근에 **내가 미리보기·받기로 연** 파일 20개 — 지금 볼 수 있는 것만.
 *
 * 요구사항: 지시서 `/var/www/qaredi/docs/qf3a-favorites-recent-bulk-directive.md` 1.2 · 결정 M-3 · 3장 QF3A-T4,
 *           PRD S-01 · S-02 · S-04 · S-07, ARCH 5.33.
 *
 * ★ **새로 쓰는 행 0.** 재료는 감사 로그다 — `actor_id = 나 AND action IN ('file.download','file.preview') AND result = 'allow'`,
 *   최근 30일, 파일마다 마지막 시각. 색인 `audit_actor_idx (actor_id, happened_at DESC)` 가 받친다. 이 창구 자신은 감사도
 *   남기지 않는다(목록 조회 — ARCH 5.1 마지막 줄).
 * ★ 결과는 **지금의 읽기 조건절**(`Authz::visibleFilesWhere` — 논리 삭제 제외 포함) 안에서만. 등급이 좁아졌거나 휴지통에 간
 *   파일은 SQL 이 돌려주지 않는다. 판정 코드를 새로 만들지 않는다.
 * ★ **대상 인자가 없다.** 누구의 최근인가는 세션의 본인(`$user['id']`)으로 고정이고 쿼리·본문의 어떤 값도 읽지 않는다 —
 *   남의 최근을 볼 길이 없다(QF3A-T4). 관리자라도 같다.
 * ★ 「역할로 보기」 중이면 행위자 번호는 실제 계정 그대로(감사에 남는 번호)이고, 보이는 범위는 유효 역할의 조건절이 정한다.
 *
 * @var array<string,mixed> $user   라우터가 넘긴 현재 사용자
 * @var list<string>        $params 경로 변수 없음
 */

$db = Db::instance();
[$visibleWhere, $visibleBind] = Authz::visibleFilesWhere($user);

/* 안쪽 질의 — 내 allow 행을 파일마다 하나로(마지막 시각). 값은 전부 바인딩이고 행동 이름·기간은 코드 상수다.
   target_type = 'file' 은 감사 행의 target_id 가 파일 번호일 때만 잇기 위한 것(다른 종류의 번호와 섞이지 않게). */
$sqlRecent = <<<'SQL'
    SELECT f.public_id,
           f.original_name,
           f.size_bytes,
           f.description,
           f.access_level,
           f.ext,
           cardinality(f.sensitive_flags) > 0 AS sensitive,
           f.scan_status,
           f.exec_content,
           f.team_id,
           f.folder_id,
           f.uploaded_by,
           t.name       AS team_name,
           u.username   AS uploader,
           f.created_at,
           r.last_at    AS last_used_at,
           -- G-0 — 개인 등급 판정(can_edit·can_delete)이 쓰는 번호·종류 표식(응답에는 싣지 않는다)
           f.id,
           'file' AS item_kind
      FROM (SELECT a.target_id AS fid, max(a.happened_at) AS last_at
              FROM audit_logs a
             WHERE a.actor_id = CAST(:rec_me AS bigint)
               AND a.happened_at > now() - interval '30 days'
               AND a.action IN ('file.download', 'file.preview')
               AND a.result = 'allow'
               AND a.target_type = 'file'
               AND a.target_id IS NOT NULL
             GROUP BY a.target_id) r
      JOIN files f ON f.id = r.fid
      JOIN users u ON u.id = f.uploaded_by
      LEFT JOIN teams t ON t.id = f.team_id
     WHERE
    SQL;
$sqlTail = ' ORDER BY r.last_at DESC, f.id DESC LIMIT 20';

$bind = $visibleBind;
$bind[':rec_me'] = (int) $user['id'];
$rows = $db->run($sqlRecent . ' ' . $visibleWhere . $sqlTail, $bind)->fetchAll();

// 폴더 경로(표시용) — G-0: 보이는 폴더 이름만(`_folder_paths.php` — 파일 목록·즐겨찾기와 같은 함수). 폴더가 걸린 행이 없으면 묻지 않는다.
require_once __DIR__ . '/_folder_paths.php';
$folderMap = [];
foreach ($rows as $row) {
    if ($row['folder_id'] !== null) {
        $folderMap = tbox_folder_map($user);
        break;
    }
}

$files = [];
foreach ($rows as $row) {
    $files[] = [
        'id'           => (string) $row['public_id'],
        'name'         => (string) $row['original_name'],
        'size_bytes'   => (int) $row['size_bytes'],
        'description'  => (string) $row['description'],
        'access_level' => (string) $row['access_level'],
        'team_id'      => $row['team_id'] === null ? null : (int) $row['team_id'],
        'team_name'    => $row['team_name'] === null ? null : (string) $row['team_name'],
        'folder_id'    => tbox_folder_cells($folderMap, $row['folder_id'] === null ? null : (int) $row['folder_id'])[0],
        'folder_path'  => tbox_folder_cells($folderMap, $row['folder_id'] === null ? null : (int) $row['folder_id'])[1],
        'uploaded_by'  => (string) $row['uploader'],
        'created_at'   => (string) $row['created_at'],
        'can_delete'   => Authz::decideDelete($user, $row)[0],
        'can_edit'     => Authz::decideEdit($user, $row)[0],
        'preview_kind' => Preview::kindFor((string) $row['ext']),
        'sensitive'    => $row['sensitive'] === true,
        'scan_status'  => (string) $row['scan_status'],
        'exec_content' => $row['exec_content'] === true,
        // 마지막으로 미리보기·받기를 한 때(감사 행의 시각 — 서버 값 그대로)
        'last_used_at' => (string) $row['last_used_at'],
    ];
}

Http::json(['files' => $files, 'days' => 30, 'limit' => 20]);

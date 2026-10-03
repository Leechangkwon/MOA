<?php
declare(strict_types=1);

/**
 * src/handlers/_folder_paths.php — 폴더 경로·breadcrumb 를 **보이는 폴더만으로** (G-0, PRD F-73 · S-02, ARCH 5.16 · 5.36)
 *
 * ★ 왜 이 파일이 생겼나
 *   18-1 의 경로·breadcrumb 는 「읽을 수 있는 폴더의 조상은 언제나 읽을 수 있다」(불변 ① + 매트릭스 단조)를 믿고 조상을 거르지
 *   않았다. G-0 의 **사람 지정 권한**이 그 전제를 깬다 — 개인 폴더 C 에만 권한을 받은 사람은 C 를 보지만 C 를 담은 남의 개인
 *   폴더 B 는 못 본다. 거르지 않으면 B 의 **이름**이 경로 줄·breadcrumb 로 샌다. 그래서 경로를 만드는 다섯 자리(파일 목록 전역
 *   검색 · 최근 · 즐겨찾기 파일 · 즐겨찾기 폴더 · 폴더 트리·파일 목록의 breadcrumb)가 이 함수들을 부른다.
 *   보이는가는 **목록 조건절 그대로**(`Authz::visibleWhere($user, 'd')`)를 폴더 표 전체에 불리언 칸으로 붙여 한 번에 묻는다 —
 *   새 판정 0(규칙을 이 파일에 한 줄도 적지 않는다). 폴더 수는 적다(한 문장 · 행마다 묻지 않는다).
 *
 * 이 파일은 라우트 표에 없다(이름이 `_` 로 시작한다) — 핸들러가 `require_once` 하는 공통 코드다.
 */

/**
 * 폴더 전부의 [번호 => [parent, name, access_level, vis]] — vis 는 이 사람의 목록 조건절에 드나.
 *
 * @param array<string,mixed> $user
 * @return array<int,array{parent:?int,name:string,level:string,vis:bool}>
 */
function tbox_folder_map(array $user): array
{
    [$vis, $bind] = Authz::visibleWhere($user, 'd');
    $tpl = <<<'SQL'
        SELECT d.id, d.parent_id, d.name, d.access_level, (@VIS) AS vis FROM folders d
        SQL;
    $sql = strtr($tpl, ['@VIS' => $vis]);   // 목록 조건절(코드가 만든 조각 — 값은 전부 바인딩)을 그대로 끼운다
    $map = [];
    foreach (Db::instance()->run($sql, $bind)->fetchAll() as $r) {
        $map[(int) $r['id']] = [
            'parent' => $r['parent_id'] === null ? null : (int) $r['parent_id'],
            'name'   => (string) $r['name'],
            'level'  => (string) $r['access_level'],
            'vis'    => $r['vis'] === true,
        ];
    }
    return $map;
}

/**
 * 루트 → 이 폴더까지의 번호 줄(깊이 ≤ 5 — 순환 데이터에 대비해 10걸음에서 멈춘다).
 *
 * @param array<int,array{parent:?int,name:string,level:string,vis:bool}> $map
 * @return list<int>
 */
function tbox_folder_chain(array $map, int $id): array
{
    $chain = [];
    $cur   = $id;
    for ($i = 0; $i < 10 && isset($map[$cur]); $i++) {
        array_unshift($chain, $cur);
        $p = $map[$cur]['parent'];
        if ($p === null) {
            break;
        }
        $cur = $p;
    }
    return $chain;
}

/**
 * 사람이 읽는 경로 `A / B / C` — **보이는 폴더 이름만**. 못 보는 조상은 이름 대신 `…` 하나로 줄인다(몇 겹인지도 싣지 않는다).
 * 파일이 든 폴더 자체를 못 보면(파일에만 권한을 받았을 때) 그 자리도 `…` 다. 보이는 이름이 하나도 없으면 `…`.
 *
 * @param array<int,array{parent:?int,name:string,level:string,vis:bool}> $map
 */
function tbox_folder_path_text(array $map, int $id): ?string
{
    if (!isset($map[$id])) {
        return null;
    }
    $parts  = [];
    $hidden = false;
    foreach (tbox_folder_chain($map, $id) as $fid) {
        if ($map[$fid]['vis']) {
            $parts[] = $map[$fid]['name'];
            $hidden  = false;
            continue;
        }
        if (!$hidden) {
            $parts[] = '…';
            $hidden  = true;
        }
    }
    return $parts === [] ? null : implode(' / ', $parts);
}

/**
 * 목록 응답의 폴더 칸 둘 — 폴더가 보이면 번호와 경로, 못 보면 번호는 null(누를 곳이 없다)·경로는 `…` 모양.
 *
 * @param array<int,array{parent:?int,name:string,level:string,vis:bool}> $map
 * @return array{0:?int,1:?string} [folder_id, folder_path]
 */
function tbox_folder_cells(array $map, ?int $folderId): array
{
    if ($folderId === null) {
        return [null, null];
    }
    $visible = isset($map[$folderId]) && $map[$folderId]['vis'];
    return [$visible ? $folderId : null, tbox_folder_path_text($map, $folderId)];
}

/**
 * breadcrumb (루트 → 지금 연 폴더) — 조상은 **보이는 것만**, 지금 연 폴더 자신은 언제나 넣는다(이미 decideRead 를 지났다 —
 * Admin 특권으로 연 남의 개인 폴더도 그 이름은 연 사람에게 보인다). 못 보는 조상은 줄에서 빠진다(누를 수 없는 이름을 싣지 않는다).
 *
 * @param array<int,array{parent:?int,name:string,level:string,vis:bool}> $map
 * @return list<array{id:int,name:string,access_level:string}>
 */
function tbox_folder_breadcrumb(array $map, int $id): array
{
    $out = [];
    foreach (tbox_folder_chain($map, $id) as $fid) {
        if ($fid !== $id && !$map[$fid]['vis']) {
            continue;
        }
        $out[] = ['id' => $fid, 'name' => $map[$fid]['name'], 'access_level' => $map[$fid]['level']];
    }
    return $out;
}

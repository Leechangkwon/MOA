<?php
declare(strict_types=1);

/**
 * src/handlers/_folder_lock.php — G-0 (PRD F-73 · ARCH 5.16 「불변 ①과 동시 요청」) 폴더에 **넣는** 저장의 잠금 재확인.
 *
 * 올리기 · URL 가져오기 · 폴더 만들기 · 파일 옮기기/등급 바꾸기 · 폴더 옮기기/등급 바꾸기는 저장 트랜잭션 **전**에 대상 폴더
 * 행을 읽어 판정한다(decideFolderPut · fitsUnderFolder). 같은 순간 그 폴더가 좁혀지면(예: 사내 → 개인) 두 요청이 모두
 * 통과해 「개인 폴더 안에 사내 파일」 이 남을 수 있다 — 불변 ① 위반(시험 g0_private_grants.sh T-G0-10 ④ 가 여러 일꾼
 * 서버로 되풀이해 본다). 그래서 저장 트랜잭션 **안에서** 대상 폴더 행을 `FOR SHARE` 로 잠그고 같은 두 판정을 다시 한다.
 *
 *   · 좁히는 쪽(folders_update 의 UPDATE — 행 잠금)이 먼저면: 이 SELECT 는 그 커밋을 기다렸다가 **바뀐 행**을 받아 거부한다.
 *   · 넣는 쪽이 먼저면: 좁히는 쪽 UPDATE 가 이 잠금이 풀릴 때(커밋)까지 기다리고, 그 뒤 folders_update 의 「마지막 그물」
 *     (sqlIntegrity — UPDATE 뒤 새 스냅숏)이 방금 들어온 항목을 보고 전부 되돌린다(400 children_exceed).
 *   · FOR SHARE 끼리는 서로 막지 않는다 — 같은 폴더에 여럿이 동시에 올리는 일상 동작은 그대로 동시다.
 *
 * 판정은 새로 만들지 않는다 — 트랜잭션 전과 **같은 두 함수**를 잠근 행에 다시 부른다(규칙이 두 벌이 되지 않게).
 * 잠그는 행은 대상 폴더 하나뿐이다(조상까지 잠그지 않는다). 더 위 폴더를 좁히는 요청은 이 폴더가 이미 그만큼 좁거나
 * (아니면 children_exceed) 「하위에도 적용」 으로 **이 폴더 행을 UPDATE** 한다 — 어느 쪽이든 같은 잠금·같은 그물에 걸린다.
 *
 * @param array<string,mixed> $user     라우터가 넘긴 현재 사용자
 * @param int                 $folderId 넣을 곳(정수 — 호출부가 이미 형식을 본 값)
 * @param string              $level    넣는 항목의 **최종** 등급
 * @param int|null            $teamId   넣는 항목의 최종 팀
 * @param bool                $checkPut 넣기 자격(decideFolderPut)도 다시 볼까 — 폴더가 바뀌는 요청일 때만 참
 * @return array{0:bool,1:string,2:string} [통과, 어디서 막혔나(`gone` 그사이 지워짐 · `put` 넣기 자격 · `fits` 불변 ①), 거부 코드]
 *         — 거부 코드는 트랜잭션 전 검사가 내는 것과 같다(decideFolderPut 코드 · fitsUnderFolder 코드). 호출부는 막힌 자리에
 *           따라 **트랜잭션 전 검사와 같은 응답**을 낸다(없는 폴더·못 넣는 폴더·넓은 항목이 서로 갈리지 않게 — 전과 같다).
 */
function tbox_folder_lock_for_put(array $user, int $folderId, string $level, ?int $teamId, bool $checkPut): array
{
    $sqlLock = <<<'SQL'
        SELECT id, access_level, team_id, created_by,
               'folder' AS item_kind   -- G-0 종류 표식(개인 폴더 넣기 판정 — decideFolderPut)
          FROM folders
         WHERE id = CAST(:id AS bigint)
           FOR SHARE
        SQL;

    $row = Db::instance()->run($sqlLock, [':id' => $folderId])->fetch();
    if (!is_array($row)) {
        return [false, 'gone', 'bad_folder'];   // 그사이 지워졌다 — 없는 폴더와 같은 답
    }
    if ($checkPut) {
        [$mayPut, $putCode] = Authz::decideFolderPut($user, $row);
        if (!$mayPut) {
            return [false, 'put', $putCode];    // 그사이 넣기 자격이 사라졌다(예: 개인으로 좁혀짐)
        }
    }
    [$fits, $fitCode] = Authz::fitsUnderFolder($level, $teamId, $row);
    return $fits ? [true, '', ''] : [false, 'fits', $fitCode];
}

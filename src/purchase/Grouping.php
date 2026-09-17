<?php
declare(strict_types=1);

/**
 * src/purchase/Grouping.php — 불러오기 창의 **묶기 한 벌** (F-63, PRD 12.2 T-75, ARCH 5.18)
 *
 * 무엇을 하나
 *   줄 목록을 「묶는 기준」 층 순서대로 재귀로 묶어, 층마다 소계(줄 수·수량)를 붙인
 *   트리를 만든다. 적은 순서가 트리의 층이다.
 *
 * 왜 이 파일이 생겼나
 *   20단계에는 이 코드가 `purchase_pr_load.php` 안의 클로저 `$keyOf`·`$build` 뿐이었다.
 *   F-63 이 **입고 불러오기**(`purchase_gr_load.php`)에도 같은 트리를 요구했고, 거기서
 *   복사해 두면 언젠가 한쪽만 고쳐진다 — 25단계가 다섯 벌의 JS 도우미를 한 벌로 모은
 *   이유, F-45 가 미입고 표를 한 벌로 만든 이유와 같다. 그래서 **함수는 하나**다.
 *
 * 어디서 쓰나
 *   `src/handlers/purchase_pr_load.php` (발주요청 불러오기 — `groups`)
 *   `src/handlers/purchase_gr_load.php` (발주서 불러오기 → 입고 — `nodes`)
 *
 * 지키는 것
 *   · **자료를 읽지 않는다** — DB 접근 0, 전역 상태 0, 정적 변수 0. 받은 배열만 본다.
 *     그래서 지점 격리·정책·상한은 부르는 핸들러의 몫이고, 이 파일에는 조건이 없다.
 *   · 묶는 기준의 **이름**(`$levels` 의 값)은 `$keyOf` 가 읽는 열쇠일 뿐이다 — 이 파일은
 *     그 문자열로 아무것도 조립하지 않는다(문장을 만드는 코드가 여기에 없다).
 *   · 줄에서 무엇을 열쇠·이름으로 볼지(`$keyOf`)와 수량을 어디서 뽑을지(`$qtyOf`)는
 *     **부르는 쪽이 넘긴다** — 두 화면의 줄 필드 이름이 다르기 때문이다(발주요청은
 *     `remaining_milli`, 입고는 미입고 잔량). 여기서 정하면 한쪽을 고칠 때마다
 *     다른 쪽이 흔들린다.
 *
 * 수량 표기는 **한 곳**이다: 소계는 밀리 정수로 더하고 `Doc::qtyText()` 로 한 번만
 * 문자열이 된다(소수 셋째 자리). 두 창구가 같은 글자 모양을 내는 것이 목적이다.
 */

require_once __DIR__ . '/Doc.php';

final class Grouping
{
    /** 묶는 기준은 세 층까지 (화면 칩바도 세 개까지만 켜진다) */
    public const MAX_LEVELS = 3;

    /**
     * 층 순서대로 재귀로 묶는다. 각 층에 **소계**(줄 수·수량)를 붙인다.
     *
     * 돌려주는 노드 한 개의 모양 — 키 순서까지 이대로다(응답 JSON 의 순서가 된다):
     *     group · key · label · line_count · qty  +  마지막 층이면 lines, 아니면 children
     *
     * @param list<array<string,mixed>> $rows   묶을 줄 (받은 순서가 묶음 안 순서다)
     * @param list<string>              $levels 묶는 기준 이름 — **적은 순서가 층**
     * @param callable                  $keyOf  fn(array $row, string $kind): array{0:int,1:string}
     *                                          열쇠는 **정수**(0 = 미지정)이고 이름은 사람이 적은 문자열
     * @param callable                  $qtyOf  fn(array $row): int — 그 줄의 수량(밀리 정수)
     * @return list<array<string,mixed>>
     */
    public static function tree(array $rows, array $levels, callable $keyOf, callable $qtyOf): array
    {
        if ($levels === []) {
            return [];
        }
        $kind    = $levels[0];
        $rest    = array_slice($levels, 1);
        $buckets = [];
        foreach ($rows as $row) {
            [$key, $label] = $keyOf($row, $kind);
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['label' => $label, 'rows' => []];
            }
            $buckets[$key]['rows'][] = $row;
        }

        $out = [];
        foreach ($buckets as $key => $bucket) {
            $qty = 0;
            foreach ($bucket['rows'] as $r) {
                $qty += (int) $qtyOf($r);
            }
            $node = [
                'group'     => $kind,
                'key'       => (int) $key,
                'label'     => $bucket['label'],
                'line_count' => count($bucket['rows']),
                'qty'       => Doc::qtyText($qty),
            ];
            if ($rest === []) {
                $node['lines'] = $bucket['rows'];
            } else {
                $node['children'] = self::tree($bucket['rows'], $rest, $keyOf, $qtyOf);
            }
            $out[] = $node;
        }
        return $out;
    }
}

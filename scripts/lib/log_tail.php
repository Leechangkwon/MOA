<?php
declare(strict_types=1);

/**
 * scripts/lib/log_tail.php — 줄 로그를 「어디까지 읽었나(inode·오프셋)」로 이어 읽는 한 벌
 *   (F-70 지시서 B 「alerts.log 를 오프셋으로 읽는 함수를 alerts.php 의 것과 한 벌로」, ARCH 11.4)
 *
 * 쓰는 곳 둘 — 규칙(회전·잘림)이 두 벌이 되지 않게 이 파일 **하나**다.
 *   · scripts/alerts.php      — `/var/log/auth.log`(와 `.1`) 의 새 줄 (AL-3·AL-9, 16-2)
 *   · scripts/alert_push.php  — `/var/log/tbox-v2/alerts.log`(와 `.1`) 의 새 줄 (F-70 보내는 배치)
 *
 * ★ Env·Db·다른 앱 클래스에 기대지 않는다. 보내는 배치(OS 계정 tbox-push)는 `.env` 를 읽지 않고
 *   cli_bootstrap 도 싣지 않으므로(구현 기록 3), 이 파일은 PHP 내장 함수만 쓴다. 전역 상태도 없다.
 * ★ 이 파일은 **읽기만** 한다. 읽은 위치를 저장하는 것은 부르는 쪽이고, dry-run 이면 저장하지 않는다
 *   (alerts.php 대기열 2c · F-70 금지 패턴 — dry-run 은 오프셋·상태를 쓰지 않는다).
 *
 * 규칙 (16-2 에서 alerts.php 에 있던 그대로 옮겼다)
 *   · inode 가 바뀌었으면 회전됐거나 첫 실행이다 — 직전 파일(`$prevPath`)의 inode 가 기억한 것과 같고
 *     기억한 오프셋보다 크면 **그 남은 꼬리를 먼저** 읽고, 새 파일은 처음부터 읽는다(회전 순간의 줄을 잃지 않게).
 *   · 크기가 기억한 오프셋보다 작으면 잘린 것이다(truncate) — 처음부터 다시 읽는다.
 *   · 한 번에 읽는 양은 `$maxBytes` 까지(회전 직후의 큰 파일로 메모리를 쓰지 않게). 남은 것은 다음 실행에.
 *   · `$wholeLines` 가 참이면 마지막 줄바꿈까지만 읽은 것으로 친다 — 쓰는 중인 반쪽 줄(JSON 한 줄의 앞부분)을
 *     이번에 소비해 버리면 그 줄을 영영 못 읽는다. alerts.log 가 이 모양이다(한 줄 JSON). 줄 하나가
 *     `$maxBytes` 보다 길면(줄바꿈 없는 덩어리가 꽉 찼으면) 멈추지 않도록 그 덩어리를 통째로 넘긴다.
 *     alerts.php(auth.log)는 옛 동작을 바꾸지 않으려고 거짓을 넘긴다.
 *   · 읽지 못하면(권한 없음) `ok=false, reason=permission`, stat·읽기 실패는 `reason=unreadable`.
 */

/**
 * 새로 늘어난 부분을 줄 목록으로.
 *
 * @param array{inode:int,offset:int} $pos 지난번에 저장한 위치 (첫 실행이면 0·0)
 * @return array{ok:bool, reason:string, lines:list<string>, inode:int, offset:int}
 */
function log_tail_read(string $path, ?string $prevPath, array $pos, int $maxBytes, bool $wholeLines = false): array
{
    $pos = log_tail_pos($pos);
    $empty = [
        'ok'     => false,
        'reason' => '',
        'lines'  => [],
        'inode'  => $pos['inode'],
        'offset' => $pos['offset'],
    ];
    if ($maxBytes < 1) {
        $maxBytes = 1;
    }

    if (!is_readable($path)) {
        return ['reason' => 'permission'] + $empty;
    }
    $stat = @stat($path);
    if (!is_array($stat)) {
        return ['reason' => 'unreadable'] + $empty;
    }
    $inode = (int) $stat['ino'];
    $size  = (int) $stat['size'];

    $lines  = [];
    $offset = $pos['offset'];

    if ($inode !== $pos['inode']) {
        // 회전됐거나 첫 실행이다. 직전 파일의 남은 꼬리를 먼저 읽어 둔다(있으면).
        if ($prevPath !== null && $pos['inode'] !== 0 && $pos['offset'] > 0 && is_readable($prevPath)) {
            $prevStat = @stat($prevPath);
            if (is_array($prevStat) && (int) $prevStat['ino'] === $pos['inode']
                && (int) $prevStat['size'] > $pos['offset']
            ) {
                $tail = file_get_contents(
                    $prevPath,
                    false,
                    null,
                    $pos['offset'],
                    min($maxBytes, (int) $prevStat['size'] - $pos['offset'])
                );
                if ($tail !== false) {
                    // 회전된 파일은 더 자라지 않는다 — 반쪽 줄 걱정 없이 통째로
                    $lines = log_tail_split($tail);
                }
            }
        }
        $offset = 0;
    } elseif ($size < $offset) {
        // 잘렸다(truncate). 처음부터 다시 읽는다.
        $offset = 0;
    }

    if ($size > $offset) {
        $take = min($maxBytes, $size - $offset);
        $text = file_get_contents($path, false, null, $offset, $take);
        if ($text === false) {
            return ['reason' => 'unreadable'] + $empty;
        }
        if ($wholeLines) {
            $cut = strrpos($text, "\n");
            if ($cut === false) {
                // 줄바꿈이 없다: 덩어리가 꽉 찼으면(줄 하나가 상한보다 김) 넘기고, 아니면 쓰는 중인 반쪽 줄이라 남긴다
                $text = strlen($text) >= $maxBytes ? $text : '';
            } else {
                $text = substr($text, 0, $cut + 1);
            }
        }
        $lines  = array_merge($lines, log_tail_split($text));
        $offset += strlen($text);
    }

    return [
        'ok'     => true,
        'reason' => '',
        'lines'  => $lines,
        'inode'  => $inode,
        'offset' => $offset,
    ];
}

/**
 * 지금 파일의 **끝** 위치 — 처음 켤 때 옛 줄을 보내지 않으려고(F-70 C장 1 「처음 켤 때 오프셋 = 파일 끝」).
 * 파일이 없거나 못 읽으면 `ok=false`.
 *
 * @return array{ok:bool, inode:int, offset:int}
 */
function log_tail_end(string $path): array
{
    if (!is_readable($path)) {
        return ['ok' => false, 'inode' => 0, 'offset' => 0];
    }
    $stat = @stat($path);
    if (!is_array($stat)) {
        return ['ok' => false, 'inode' => 0, 'offset' => 0];
    }
    return ['ok' => true, 'inode' => (int) $stat['ino'], 'offset' => (int) $stat['size']];
}

/**
 * 읽은 덩어리를 줄 단위로 자른다 (빈 줄은 버린다). alerts.php 의 al_split_lines 가 이것을 부른다.
 *
 * @return list<string>
 */
function log_tail_split(string $text): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
        if (is_string($line) && $line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

/**
 * 상태 파일에서 읽은 위치 값을 좁힌다 — 정수가 아니거나 음수면 0.
 *
 * @param array<string,mixed> $pos
 * @return array{inode:int,offset:int}
 */
function log_tail_pos(array $pos): array
{
    $inode  = $pos['inode'] ?? 0;
    $offset = $pos['offset'] ?? 0;
    return [
        'inode'  => is_int($inode) && $inode > 0 ? $inode : 0,
        'offset' => is_int($offset) && $offset > 0 ? $offset : 0,
    ];
}

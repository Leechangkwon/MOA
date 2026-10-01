<?php
declare(strict_types=1);

/** Extension point for later wording; this stage uses fixed, inspectable sentences only. */
interface BriefingText
{
    public function summary(string $kind): string;
}

final class DefaultBriefingText implements BriefingText
{
    private const LABELS = [
        'overdue' => '기한이 지난 업무입니다.', 'due_today' => '오늘까지 처리할 업무입니다.',
        'review' => '검토를 기다리는 업무입니다.', 'mention' => '나를 부른 댓글에 아직 답하지 않았습니다.',
        'missed_comment' => '어제 받은 댓글에 아직 답하지 않았습니다.',
        'notice' => '아직 읽지 않은 공지입니다.', 'verify_expired' => '문서의 검증 기한이 지났습니다.',
        'recurring' => '오늘 자동으로 만들어진 반복 업무입니다.',
        'unchecked' => '아직 끝내지 않은 체크 항목입니다.', 'question' => '내 질문이 아직 해결되지 않았습니다.',
        'pending_file' => '올린 파일의 검사를 기다리고 있습니다.', 'expiring_share' => '24시간 안에 공유 링크가 만료됩니다.',
    ];

    public function summary(string $kind): string
    {
        return self::LABELS[$kind] ?? '확인할 업무입니다.';
    }
}

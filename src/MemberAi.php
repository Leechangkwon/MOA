<?php
declare(strict_types=1);

require_once __DIR__ . '/Member.php';
require_once __DIR__ . '/Legal.php';

final class MemberAiDenied extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 400)
    {
        parent::__construct($reason);
    }
}

/** Private AI threads. A provider credential is shared; a user's conversation never is. */
final class MemberAi
{
    public const MODEL = 'gemini-3.6-flash';
    public const MESSAGES = [
        'unavailable' => 'AI 연결을 준비 중입니다. 관리자가 Gemini 키를 등록하고 사용을 켜면 대화할 수 있어요.',
        'key_invalid' => 'Gemini 인증을 확인할 수 없습니다. 관리자에게 API 키와 프로젝트 권한 확인을 요청해 주세요.',
        'key_format' => 'API 키 전체를 입력해 주세요. 키 중간에 공백이나 줄바꿈이 들어갈 수 없습니다.',
        'key_missing' => '먼저 Google AI Studio API 키를 입력한 뒤 AI 대화 사용을 켜 주세요.',
        'provider_limit' => 'Google 프로젝트의 사용 한도에 도달했습니다. 잠시 뒤 다시 시도해 주세요.',
        'provider_unavailable' => 'Gemini가 응답하지 않았습니다. 잠시 뒤 다시 시도해 주세요.',
        'provider_request' => 'Gemini가 요청을 받지 못했습니다. 관리자에게 AI 연결 설정 확인을 요청해 주세요.',
        'model_unavailable' => '이 Google 프로젝트에서 Gemini 3.6 Flash를 사용할 수 없습니다. 관리자에게 확인을 요청해 주세요.',
        'blocked' => '이 요청에 대한 답변을 만들지 못했어요. 내용을 바꾸어 질문해 주세요.',
        'interrupted' => '응답을 끝까지 받지 못했습니다. 다시 보내면 새 요청으로 처리됩니다.',
        'user_limit' => '오늘의 AI 사용 횟수를 모두 사용했어요. 내일 다시 이용해 주세요.',
        'site_limit' => '오늘 모아의 공용 AI 사용 횟수를 모두 사용했어요. 관리자에게 문의해 주세요.',
        'busy' => '아직 답변을 작성 중입니다. 잠시 뒤 다시 시도해 주세요.',
        'site_busy' => '다른 대화에 답변 중이에요. 잠시 뒤 다시 보내 주세요.',
        'not_found' => '대화를 찾을 수 없습니다.',
        'request_conflict' => '전송 정보가 바뀌었습니다. 화면을 새로고침한 뒤 다시 보내 주세요.',
        'storage' => 'AI 설정 또는 대화를 저장하지 못했습니다. 관리자에게 문의해 주세요.',
    ];

    public static function config(): array
    {
        $row = Db::instance()->run('SELECT enabled,user_daily,site_daily,image_enabled,image_user_daily,image_site_daily FROM member_ai_settings WHERE id=1')->fetch();
        if (!is_array($row)) throw new MemberAiDenied('storage', 503);
        return $row;
    }

    private static function keyPath(): string
    {
        // Keep credentials outside both the web root and the UUID upload storage tree.
        return dirname(Storage::baseDir()) . '/private-messenger-ai/gemini.key';
    }

    public static function key(): string
    {
        $path = self::keyPath();
        if (is_link(dirname($path)) || is_link($path)) throw new MemberAiDenied('storage', 503);
        if (!is_file($path)) return '';
        if ((fileperms($path) & 0077) !== 0 || !is_readable($path)) throw new MemberAiDenied('storage', 503);
        $key = file_get_contents($path, false, null, 0, 4097);
        if (!is_string($key) || !self::validKey($key)) {
            throw new MemberAiDenied('key_invalid', 503);
        }
        return $key;
    }

    public static function setKey(string $key): void
    {
        if (!self::validKey($key)) {
            throw new MemberAiDenied('key_format');
        }
        $path = self::keyPath();
        $dir = dirname($path);
        if (is_link($dir) || is_link($path)) throw new MemberAiDenied('storage', 503);
        if (!is_dir($dir) && !mkdir($dir, 0700)) throw new MemberAiDenied('storage', 503);
        if (!chmod($dir, 0700)) throw new MemberAiDenied('storage', 503);
        $tmp = tempnam($dir, '.key-');
        if ($tmp === false) throw new MemberAiDenied('storage', 503);
        try {
            if (!chmod($tmp, 0600) || file_put_contents($tmp, $key, LOCK_EX) !== strlen($key) || !rename($tmp, $path)) {
                throw new MemberAiDenied('storage', 503);
            }
        } finally {
            if (is_file($tmp)) unlink($tmp);
        }
    }

    private static function validKey(string $key): bool
    {
        // Google's opaque authorization keys can contain dots and be longer than legacy keys.
        // Validate only header safety and a reasonable bound; Google validates the credential.
        return preg_match('/\A[\x21-\x7E]{20,4096}\z/', $key) === 1;
    }

    public static function usage(int $uid): array
    {
        return Db::instance()->run(<<<'SQL'
            SELECT count(*) AS site_used, count(*) FILTER(WHERE user_id=:uid) AS user_used,
                   COALESCE(sum(total_tokens),0) AS total_tokens
              FROM (
                SELECT user_id,total_tokens,created_at FROM member_ai_turns
                UNION ALL
                SELECT user_id,total_tokens,created_at FROM member_ai_assists
              ) requests
             WHERE created_at >= (date_trunc('day',now() AT TIME ZONE 'Asia/Seoul') AT TIME ZONE 'Asia/Seoul')
            SQL,
            [':uid' => $uid])->fetch();
    }

    /** Call only while holding the singleton settings lock. No provider call holds it. */
    public static function reserveCapacity(int $uid, array $config): string
    {
        $db = Db::instance();
        $key = self::key();
        if (!$config['enabled'] || $key === '') throw new MemberAiDenied('unavailable', 503);
        $usage = self::usage($uid);
        if ((int)$usage['user_used'] >= (int)$config['user_daily']) throw new MemberAiDenied('user_limit', 429);
        if ((int)$usage['site_used'] >= (int)$config['site_daily']) throw new MemberAiDenied('site_limit', 429);
        $db->run("UPDATE member_ai_turns SET state='failed',error_code='interrupted',finished_at=now() WHERE state='pending' AND created_at<now()-interval '120 seconds'");
        $db->run("UPDATE member_ai_assists SET state='failed',error_code='interrupted',finished_at=now() WHERE state='pending' AND created_at<now()-interval '120 seconds'");
        $pending = $db->run(<<<'SQL'
            SELECT count(*) AS total,count(*) FILTER(WHERE user_id=:uid) AS mine FROM (
              SELECT user_id FROM member_ai_turns WHERE state='pending'
              UNION ALL SELECT user_id FROM member_ai_assists WHERE state='pending'
            ) requests
            SQL,
            [':uid' => $uid])->fetch();
        if ((int)$pending['mine'] > 0) throw new MemberAiDenied('busy', 409);
        if ((int)$pending['total'] >= 2) throw new MemberAiDenied('site_busy', 429);
        return $key;
    }

    public static function publicTurn(array $turn): array
    {
        if ($turn['state'] === 'pending' && strtotime($turn['created_at']) < time() - 120) {
            $turn['state'] = 'failed';
            $turn['error_code'] = 'interrupted';
        }
        return [
            'id' => (int)$turn['id'], 'thread_id' => (int)$turn['thread_id'],
            'request_id' => $turn['request_id'], 'prompt' => $turn['prompt'], 'answer' => $turn['answer'],
            'state' => $turn['state'], 'created_at' => $turn['created_at'], 'finished_at' => $turn['finished_at'],
            'error' => $turn['state'] === 'failed' ? (self::MESSAGES[$turn['error_code']] ?? self::MESSAGES['interrupted']) : null,
        ];
    }

    public static function history(int $uid, ?int $thread, string $before): array
    {
        $db = Db::instance();
        $config = self::config();
        $threads = $db->run('SELECT id,title,updated_at FROM member_ai_threads WHERE user_id=:uid ORDER BY id DESC LIMIT 100',
            [':uid' => $uid])->fetchAll();
        if ($thread === null) $thread = $threads ? (int)$threads[0]['id'] : null;
        elseif ($thread === 0) $thread = null;
        $selected = null;
        $rows = [];
        if ($thread !== null) {
            $selected = $db->run('SELECT id,title FROM member_ai_threads WHERE id=:id AND user_id=:uid',
                [':id' => $thread, ':uid' => $uid])->fetch();
            if (!$selected) throw new MemberAiDenied('not_found', 404);
            $rows = $db->run('SELECT * FROM member_ai_turns WHERE user_id=:uid AND thread_id=:thread AND id<CAST(:before AS bigint) ORDER BY id DESC LIMIT 31',
                [':uid' => $uid, ':thread' => $thread, ':before' => $before])->fetchAll();
        }
        $more = count($rows) > 30;
        $rows = array_slice($rows, 0, 30);
        $usage = self::usage($uid);
        $ready = (bool)$config['enabled'] && self::key() !== '';
        return [
            'ready' => $ready, 'model' => self::MODEL, 'threads' => $threads,
            'thread' => $selected, 'turns' => array_map([self::class, 'publicTurn'], array_reverse($rows)),
            'has_more' => $more, 'remaining' => max(0, (int)$config['user_daily'] - (int)$usage['user_used']),
            'user_daily' => (int)$config['user_daily'], 'notice' => $ready ? null : self::MESSAGES['unavailable'],
        ];
    }

    /** Reserve before calling Google. No SQL locks are held while waiting for the network. */
    public static function send(int $uid, ?int $thread, string $text, string $request, ?callable $prepare = null): array
    {
        // 사이트 Q:F-13 P1 (PT-8) — AI 국외 전송 동의가 지금 상태가 아니면 409 ai_consent_required 로 여기서 끝난다:
        // 예약 · 대화 기록 · Google 호출 어느 것도 일어나지 않는다(트랜잭션 밖 · 첫 줄). 사진 이야기(MemoryAi)도 이 길을 지난다.
        Legal::requireAiConsent($uid);
        // An internal photo preview starts its own private thread. The callback loads
        // owner-checked bytes immediately before sending; HTTP callers cannot supply it.
        if ($prepare !== null && $thread !== null) throw new MemberAiDenied('request_conflict', 409);
        $db = Db::instance();
        $db->begin();
        try {
            $config = $db->run('SELECT enabled,user_daily,site_daily FROM member_ai_settings WHERE id=1 FOR UPDATE')->fetch();
            if (!$config) throw new MemberAiDenied('storage', 503);
            $existing = $db->run('SELECT * FROM member_ai_turns WHERE user_id=:uid AND request_id=CAST(:request AS uuid)',
                [':uid' => $uid, ':request' => $request])->fetch();
            if ($existing) {
                if ($existing['prompt'] !== $text || ($thread !== null && (int)$existing['thread_id'] !== $thread)) {
                    throw new MemberAiDenied('request_conflict', 409);
                }
                $db->commit();
                return self::publicTurn($existing);
            }
            if ($thread !== null) {
                $own = $db->run('SELECT id FROM member_ai_threads WHERE id=:id AND user_id=:uid',
                    [':id' => $thread, ':uid' => $uid])->fetch();
                if (!$own) throw new MemberAiDenied('not_found', 404);
            }
            $key = self::reserveCapacity($uid, $config);
            if ($thread === null) {
                $title = iconv_substr(preg_replace('/\s+/u', ' ', $text), 0, 60, 'UTF-8');
                $row = $db->run('INSERT INTO member_ai_threads(user_id,title) VALUES(:uid,:title) RETURNING id',
                    [':uid' => $uid, ':title' => $title])->fetch();
                $thread = (int)$row['id'];
            }
            $turn = $db->run('INSERT INTO member_ai_turns(user_id,thread_id,request_id,prompt) VALUES(:uid,:thread,CAST(:request AS uuid),:prompt) RETURNING *',
                [':uid' => $uid, ':thread' => $thread, ':request' => $request, ':prompt' => $text])->fetch();
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        try {
            $history = $db->run("SELECT prompt,answer FROM member_ai_turns WHERE user_id=:uid AND thread_id=:thread AND state='complete' AND id<:id ORDER BY id DESC LIMIT 10",
                [':uid' => $uid, ':thread' => $thread, ':id' => $turn['id']])->fetchAll();
            $contents = [];
            $chars = iconv_strlen($text, 'UTF-8');
            foreach ($history as $previous) {
                $size = iconv_strlen($previous['prompt'] . $previous['answer'], 'UTF-8');
                if ($chars + $size > 16000) break;
                $chars += $size;
                array_unshift($contents,
                    ['role' => 'user', 'parts' => [['text' => $previous['prompt']]]],
                    ['role' => 'model', 'parts' => [['text' => $previous['answer']]]]);
            }
            $contents[] = ['role' => 'user', 'parts' => [['text' => $text]]];
            $payload = [
                'systemInstruction' => ['parts' => [['text' => '당신은 모아 커뮤니티의 AI 대화 도우미입니다. 기본적으로 한국어로 친근하고 명확하게 답하세요. 사용자가 이 대화에 직접 입력한 내용만 알 수 있습니다. 다른 사용자의 메시지, 사내 파일, 커뮤니티 문서, PC에 접근할 수 없습니다. 웹 검색과 작업 실행 도구도 없습니다. 정보를 조회했거나 작업을 완료했다고 꾸미지 마세요. 최신 사실은 확인이 필요하다고 알려 주세요. 글 다듬기, 정리, 아이디어, 일반 질문을 도와주세요.']]],
                'contents' => $contents,
                'generationConfig' => ['maxOutputTokens' => 4096, 'thinkingConfig' => ['thinkingLevel' => 'MINIMAL']],
            ];
            $response = $prepare === null ? Fetch::geminiGenerate($key, $payload) : Fetch::geminiDescribe($key, $prepare());
            $candidate = $response['candidates'][0] ?? [];
            if ($prepare !== null && ($candidate['finishReason'] ?? '') !== 'STOP') {
                throw new MemberAiDenied('blocked', 422);
            }
            if (!in_array($candidate['finishReason'] ?? '', ['STOP', 'MAX_TOKENS'], true)) {
                throw new MemberAiDenied('blocked', 422);
            }
            $answer = '';
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (empty($part['thought']) && isset($part['text']) && is_string($part['text'])) $answer .= $part['text'];
            }
            $answer = trim($answer);
            if ($answer === '' || preg_match('//u', $answer) !== 1) throw new MemberAiDenied('blocked', 422);
            if (iconv_strlen($answer, 'UTF-8') > 19800) $answer = iconv_substr($answer, 0, 19800, 'UTF-8') . "\n\n(긴 답변을 줄였어요. 이어서 질문해 주세요.)";
            elseif (($candidate['finishReason'] ?? '') === 'MAX_TOKENS') $answer .= "\n\n(답변 길이 한도에 도달했어요. 이어서 질문해 주세요.)";
            $usage = $response['usageMetadata'] ?? [];
            $number = static fn($v) => is_int($v) && $v >= 0 && $v < 2147483647 ? $v : null;
            $row = $db->run("UPDATE member_ai_turns SET answer=:answer,state='complete',finished_at=now(),input_tokens=:input,output_tokens=:output,total_tokens=:total WHERE id=:id AND user_id=:uid AND state='pending' RETURNING *",
                [':answer' => $answer, ':input' => $number($usage['promptTokenCount'] ?? null), ':output' => $number($usage['candidatesTokenCount'] ?? null), ':total' => $number($usage['totalTokenCount'] ?? null), ':id' => $turn['id'], ':uid' => $uid])->fetch();
            if (!$row) throw new MemberAiDenied('interrupted', 502);
            $db->run('UPDATE member_ai_threads SET updated_at=now() WHERE id=:id AND user_id=:uid',
                [':id' => $thread, ':uid' => $uid]);
            Audit::allow('member.ai.send', ['target_type' => 'user', 'target_id' => $uid]);
            return self::publicTurn($row);
        } catch (Throwable $e) {
            $reason = 'interrupted';
            if ($e instanceof MemberAiDenied) $reason = $e->reason;
            if ($e instanceof FetchDenied) {
                $reason = match ($e->httpStatus) {
                    400 => 'provider_request', 401, 403 => 'key_invalid', 404 => 'model_unavailable', 429 => 'provider_limit',
                    default => 'provider_unavailable',
                };
            }
            $row = $db->run("UPDATE member_ai_turns SET state='failed',error_code=:reason,finished_at=now() WHERE id=:id AND user_id=:uid AND state='pending' RETURNING *",
                [':reason' => $reason, ':id' => $turn['id'], ':uid' => $uid])->fetch();
            Audit::deny('member.ai.send', $reason, ['target_type' => 'user', 'target_id' => $uid]);
            if (!$row) throw new MemberAiDenied('interrupted', 502);
            return self::publicTurn($row);
        }
    }
}

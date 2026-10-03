<?php
declare(strict_types=1);

/** Shared-shell messenger; participant authorization is checked on every operation. */
final class Messenger
{
    public static function requireReady(): void
    {
        $sql = "SELECT to_regclass('community.gpt_turns') IS NOT NULL";
        if (!Db::instance()->run($sql)->fetchColumn()) {
            Http::error(503, '메신저 업데이트를 준비하고 있어요. 잠시 뒤 다시 열어 주세요.');
        }
    }

    public static function member(string $username, array $viewer): array
    {
        $sql = 'SELECT id,username,display_name,role,is_active,expires_at FROM public.users WHERE username=:name AND is_active AND (expires_at IS NULL OR expires_at>now())';
        $row = Db::instance()->run($sql, [':name' => $username])->fetch();
        if (!$row || (int) $row['id'] === (int) $viewer['id'] || !Authz::canCommunityWrite($row)) {
            Http::error(404, '대화할 수 있는 회원을 찾지 못했어요.');
        }
        return $row;
    }

    public static function conversation(string $id, array $viewer): array
    {
        if (!Memories::uuid($id)) { Http::error(404, Http::MSG_NOT_FOUND); }
        $sql = <<<'SQL'
            SELECT c.*,u.username,u.display_name FROM community.conversations c
            JOIN public.users u ON u.id=CASE WHEN c.user_low=:viewer THEN c.user_high ELSE c.user_low END
            WHERE c.id=CAST(:id AS uuid) AND :participant IN (c.user_low,c.user_high)
              AND u.is_active AND (u.expires_at IS NULL OR u.expires_at>now())
            SQL;
        $row = Db::instance()->run($sql, [':viewer' => (int) $viewer['id'], ':participant' => (int) $viewer['id'], ':id' => $id])->fetch();
        if (!$row || !self::participant($row, $viewer)) { Http::error(404, Http::MSG_NOT_FOUND); }
        return $row;
    }

    public static function participant(array $conversation, array $viewer): bool
    {
        $id = (int) ($viewer['id'] ?? 0);
        return $id > 0 && in_array($id, [(int) $conversation['user_low'], (int) $conversation['user_high']], true);
    }

    public static function payload(int $max): array
    {
        $body = Http::jsonBody(40000, 4);
        if (array_diff(array_keys($body), ['body','request_id']) || !Memories::uuid($body['request_id'] ?? null)) {
            Http::error(400, Http::MSG_BAD_REQUEST);
        }
        $body['body'] = Memories::text($body['body'] ?? null, $max);
        if ($body['body'] === '') { Http::error(400, '보낼 내용을 입력해 주세요.'); }
        return $body;
    }

    public static function message(array $row, int $viewer): array
    {
        return ['id' => (string) $row['id'], 'body' => $row['body'], 'mine' => (int) $row['sender_id'] === $viewer,
            'created_at' => $row['created_at']];
    }

    public static function gptEnabled(): bool
    {
        return Env::getOrEmpty('COMMUNITY_GPT_ENABLED') === '1'
            && Env::getOrEmpty('OPENAI_API_KEY') !== '' && Fetch::communityGptAvailable();
    }

    public static function gptHistory(array $viewer, int $limit = 40): array
    {
        $sql = 'SELECT id,prompt,reply,created_at FROM community.gpt_turns WHERE user_id=:uid ORDER BY id DESC LIMIT :limit';
        return array_reverse(Db::instance()->run($sql, [':uid' => (int) $viewer['id'], ':limit' => $limit])->fetchAll());
    }

    public static function gpt(array $viewer): array
    {
        if (!self::gptEnabled()) { Http::error(503, 'GPT 연결이 아직 설정되지 않았어요. ChatGPT에서 이어서 대화할 수 있습니다.'); }
        $body = self::payload(6000);
        $db = Db::instance(); $db->begin();
        // A lock scoped to this account serializes history, retries and rate checks.
        $lock = 'SELECT pg_try_advisory_xact_lock(78219,CAST(:uid AS integer))';
        if (!$db->run($lock, [':uid' => (int) $viewer['id']])->fetchColumn()) {
            $db->rollback(); Http::error(409, 'GPT가 이전 답변을 작성 중이에요. 잠시 뒤 다시 보내 주세요.');
        }
        $existing = 'SELECT id,prompt,reply,created_at FROM community.gpt_turns WHERE user_id=:uid AND request_id=CAST(:request AS uuid)';
        $row = $db->run($existing, [':uid' => (int) $viewer['id'], ':request' => $body['request_id']])->fetch();
        if ($row) {
            if ($row['prompt'] !== $body['body']) { $db->rollback(); Http::error(409, '전송 내용을 확인하고 새로 보내 주세요.'); }
            $db->commit(); return $row;
        }
        $rate = "SELECT count(*) FROM community.gpt_turns WHERE user_id=:uid AND created_at>now()-interval '1 hour'";
        if ((int) $db->run($rate, [':uid' => (int) $viewer['id']])->fetchColumn() >= 20) {
            $db->rollback(); Http::error(429, 'GPT는 한 시간에 20번까지 대화할 수 있어요. 잠시 쉬었다 다시 만나요.');
        }
        $messages = [['role' => 'system', 'content' => '당신은 Qaredi 커뮤니티의 친절한 글쓰기 친구입니다. 한국어로 자연스럽게 대화하고 일상과 여행 기록을 다듬습니다. 사용자가 말하지 않은 추억이나 사실을 지어내지 마세요. 사진이나 영상을 보거나 편집했다는 주장을 하지 마세요. 현재 텍스트 대화만 가능합니다. 글을 다듬어 달라면 바로 쓸 수 있는 본문을 답하세요.']];
        foreach (self::gptHistory($viewer, 10) as $turn) {
            $messages[] = ['role' => 'user', 'content' => $turn['prompt']];
            $messages[] = ['role' => 'assistant', 'content' => $turn['reply']];
        }
        $messages[] = ['role' => 'user', 'content' => $body['body']];
        $model = Env::getOrEmpty('COMMUNITY_GPT_MODEL') ?: 'gpt-4.1-mini';
        if (preg_match('/^[a-zA-Z0-9._-]{1,80}$/D', $model) !== 1) { $db->rollback(); Http::error(503, 'GPT 모델 설정을 확인해 주세요.'); }
        try {
            $response = Fetch::communityGpt(json_encode(['model' => $model, 'messages' => $messages,
                'max_completion_tokens' => 1500, 'store' => false], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), Env::getOrEmpty('OPENAI_API_KEY'));
        } catch (FetchDenied $e) {
            $db->rollback(); Http::error(502, 'GPT에 연결하지 못했어요. 작성한 내용은 남아 있으니 잠시 뒤 다시 보내 주세요.');
        }
        $decoded = json_decode($response, true);
        $reply = $decoded['choices'][0]['message']['content'] ?? null;
        if (!is_string($reply) || trim($reply) === '' || iconv_strlen($reply, 'UTF-8') > 20000) {
            $db->rollback(); Http::error(502, 'GPT에 연결하지 못했어요. 작성한 내용은 남아 있으니 잠시 뒤 다시 보내 주세요.');
        }
        $sql = <<<'SQL'
            INSERT INTO community.gpt_turns(user_id,request_id,prompt,reply)
            VALUES(:uid,CAST(:request AS uuid),:prompt,:reply) RETURNING id,prompt,reply,created_at
            SQL;
        $row = $db->run($sql, [':uid' => (int) $viewer['id'], ':request' => $body['request_id'], ':prompt' => $body['body'], ':reply' => trim($reply)])->fetch();
        self::audit('gpt'); $db->commit(); return $row;
    }

    public static function audit(string $operation): void
    {
        // Do not put conversation text, recipient, or provider response into audit logs.
        Audit::allow('messenger.change', ['target_type' => 'system', 'target_name' => '메신저', 'detail' => ['operation' => $operation]]);
    }
}

<?php
declare(strict_types=1);
namespace QarediAssistant;

final class Request
{
    public const TOOLS = ['files.search', 'files.evidence'];

    public static function invalid(): never
    {
        throw new Error(400, 'invalid_request', '요청 형식이나 허용된 작업을 확인하세요.');
    }

    public static function keys(array $value, array $allowed): void
    {
        foreach (array_keys($value) as $key) if (!in_array($key, $allowed, true)) self::invalid();
    }

    public static function body(): array
    {
        if (!preg_match('/^application\/json(?:\s*;.*)?$/i', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))) self::invalid();
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if (!is_string($raw) || strlen($raw) > 16384 || !str_starts_with(ltrim($raw), '{')) self::invalid();
        try { $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { self::invalid(); }
        if (!is_array($body)) self::invalid();
        return $body;
    }

    public static function text($value, int $min, int $max): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) self::invalid();
        $length = iconv_strlen($value, 'UTF-8');
        if ($length === false || $length < $min || $length > $max) self::invalid();
        return $value;
    }

    public static function uuid($value): string
    {
        if (!is_string($value) || !\Storage::isValidUuid($value)) self::invalid();
        return strtolower($value);
    }

    public static function integer($value, int $min, int $max): int
    {
        if (!is_int($value) || $value < $min || $value > $max) self::invalid();
        return $value;
    }

    public static function normalize(array $body): array
    {
        self::keys($body, ['mode', 'message', 'conversation_id', 'attachments', 'allowed_tools', 'tool', 'require_confirmation']);
        $mode = $body['mode'] ?? 'chat';
        if (!in_array($mode, ['chat', 'tool'], true)) self::invalid();
        $message = self::text($body['message'] ?? '', $mode === 'chat' ? 1 : 0, 2000);
        if ($mode === 'chat' && trim($message) === '') self::invalid();
        $tools = $body['allowed_tools'] ?? self::TOOLS;
        if (!is_array($tools) || !array_is_list($tools) || count($tools) < 1 || count($tools) > 2) self::invalid();
        foreach ($tools as $name) if (!is_string($name) || !in_array($name, self::TOOLS, true)) self::invalid();
        if (count(array_unique($tools)) !== count($tools)) self::invalid();
        sort($tools);
        $confirm = $body['require_confirmation'] ?? false;
        if (!is_bool($confirm)) self::invalid();
        $attachments = $body['attachments'] ?? [];
        if (!is_array($attachments) || !array_is_list($attachments) || count($attachments) > 5) self::invalid();
        $refs = [];
        foreach ($attachments as $item) {
            if (!is_array($item)) self::invalid();
            self::keys($item, ['file_id']);
            $id = self::uuid($item['file_id'] ?? null);
            if (in_array($id, $refs, true)) self::invalid();
            $refs[] = $id;
        }
        if ($refs !== [] && !in_array('files.evidence', $tools, true)) self::invalid();
        $tool = null;
        if ($mode === 'tool') {
            $raw = $body['tool'] ?? null;
            if (!is_array($raw)) self::invalid();
            self::keys($raw, ['name', 'arguments']);
            $name = $raw['name'] ?? null;
            if (!is_string($name) || !in_array($name, self::TOOLS, true)) self::invalid();
            if (!in_array($name, $tools, true)) throw new Error(403, 'tool_not_permitted', '허용된 읽기 도구를 선택하세요.');
            $args = $raw['arguments'] ?? null;
            if (!is_array($args)) self::invalid();
            if ($name === 'files.search') {
                self::keys($args, ['query', 'limit']);
                $q = trim(self::text($args['query'] ?? null, 1, 200));
                if ($q === '') self::invalid();
                $args = ['query' => $q, 'limit' => self::integer($args['limit'] ?? 20, 1, 100)];
            } else {
                self::keys($args, ['file_id']);
                $args = ['file_id' => self::uuid($args['file_id'] ?? null)];
            }
            $tool = ['name' => $name, 'arguments' => $args];
        } elseif (array_key_exists('tool', $body)) self::invalid();
        return ['mode' => $mode, 'message' => $message,
            'conversation_id' => isset($body['conversation_id']) ? self::uuid($body['conversation_id']) : null,
            'attachments' => array_map(static fn($id) => ['file_id' => $id], $refs),
            'allowed_tools' => $tools, 'tool' => $tool, 'require_confirmation' => $confirm];
    }

    public static function key($value): string
    {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $value) !== 1) self::invalid();
        return hash('sha256', $value);
    }

    public static function json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
